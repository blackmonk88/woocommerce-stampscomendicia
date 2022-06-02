<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WC_StampscomEndicia_API_Shipnotify Class
 */
class WC_StampscomEndicia_API_Shipnotify extends WC_StampscomEndicia_API_Request {

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( ! WC_StampscomEndicia_API::authenticated() ) {
			exit;
		}
	}

	/**
	 * See how many items in the order need shipping
	 * @return int
	 */
	private function order_items_to_ship_count( $order ) {
		$needs_shipping = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $order->get_product_from_item( $item );

			if ( $product->needs_shipping() ) {
				$needs_shipping += $item['qty'];
			}
		}

		return $needs_shipping;
	}

	/**
	 * Get the order ID from the order number
	 *
	 * @param string $order_number
	 * @return integer
	 */
	private function get_order_id( $order_number ) {
		if ( class_exists( 'WC_Seq_Order_Number' ) ) {

			global $wc_seq_order_number;

			$order_id = $wc_seq_order_number->find_order_by_order_number( $order_number );

		} elseif ( class_exists( 'WC_Seq_Order_Number_Pro' ) ) {

			global $wc_seq_order_number_pro;

			if ( isset( $wc_seq_order_number_pro ) ) {
				$order_id = $wc_seq_order_number_pro->find_order_by_order_number( $order_number );
			} else {
				$order_id = WC_Seq_Order_Number_Pro::instance()->find_order_by_order_number( $order_number );
			}

		} else {
			$order_id = $order_number;
		}

		if ( 0 === $order_id ) {
			$order_id = $order_number;
		}

		return absint( $order_id );
	}

	/**
	 * Get Parsed XML response
	 * @param  string $xml
	 * @return string|bool
	 */
	private function get_parsed_xml( $xml ) {
		if ( ! class_exists( 'WC_Safe_DOMDocument' ) ) {
			include_once( 'class-wc-safe-domdocument.php' );
		}

		libxml_use_internal_errors( true );

		$dom     = new WC_Safe_DOMDocument;
		$success = $dom->loadXML( $xml );

		if ( ! $success ) {
			$this->log( 'wpcom_safe_simplexml_load_string(): Error loading XML string' );
			return false;
		}

		if ( isset( $dom->doctype ) ) {
			$this->log( 'wpcom_safe_simplexml_import_dom(): Unsafe DOCTYPE Detected' );
			return false;
		}

		return simplexml_import_dom( $dom, 'SimpleXMLElement' );
	}

	/**
	 * Do the request
	 */
	public function request() {
		global $wpdb;

		$this->validate_input( array( "order_number", "carrier" ) );

		$order_number       = $this->get_order_id( $_GET['order_number'] );
		$tracking_number    = empty( $_GET['tracking_number'] ) ? '' : wc_clean( $_GET['tracking_number'] );
		$carrier            = empty( $_GET['carrier'] ) ? '' : wc_clean( $_GET['carrier'] );
		$order              = wc_get_order( $order_number );
		$timestamp          = current_time( 'timestamp' );
		$stampscomendicia_xml    = file_get_contents( 'php://input' );
		$shipped_items      = array();
		$shipped_item_count = 0;
		$order_shipped      = false;
		$order_note         = '';

		if ( empty( $order->id ) ) {
			exit;
		}

		if ( ! empty( $stampscomendicia_xml ) && function_exists( 'simplexml_import_dom' ) ) {
			$this->log( __( "ShipNotify XML: ", 'woocommerce-stampscomendicia' ) . print_r( $stampscomendicia_xml, true ) );

			$xml = $this->get_parsed_xml( $stampscomendicia_xml );

			if ( ! $xml ) {
				$this->log( __( "Cannot parse XML", 'woocommerce-stampscomendicia' ) );
				status_header( 500 );
			}

			if ( isset( $xml->ShipDate ) ) {
				$timestamp = strtotime( (string) $xml->ShipDate );
			}

			if ( isset( $xml->Items ) ) {
				$items = $xml->Items;
				if ( $items ) {
					foreach ( $items->Item as $item ) {
						$this->log( __( "ShipNotify Item: ", 'woocommerce-stampscomendicia' ) . print_r( $item, true ) );

						$item_sku    = wc_clean( (string) $item->SKU );
						$item_name   = wc_clean( (string) $item->Name );
						$qty_shipped = absint( $item->Quantity );

						if ( $item_sku ) {
							$item_sku = ' (' . $item_sku . ')';
						}

						$shipped_item_count += $qty_shipped;
						$shipped_items[] = $item_name . $item_sku . ' x ' . $qty_shipped;
					}
				}
			}
		}

		// If we have a list of shipped items, we can customise the note + see if the order is not yet complete
		if ( sizeof( $shipped_items ) > 0 ) {
			$order_note            = sprintf( __( '%s shipped via %s on %s with tracking number %s.', 'woocommerce' ), esc_html( implode( ', ', $shipped_items ) ), esc_html( $carrier ), date_i18n( get_option( 'date_format' ), $timestamp ), $tracking_number );
			$current_shipped_items = max( get_post_meta( $order->id, '_stampscomendicia_shipped_item_count', true ), 0 );
			$total_item_count      = $this->order_items_to_ship_count( $order );

			if ( ( $current_shipped_items + $shipped_item_count ) >= $total_item_count ) {
				$order_shipped = true;
			}

			$this->log( sprintf( __( "Shipped %d out of %d items in order %s", 'woocommerce-stampscomendicia' ), $shipped_item_count, $total_item_count, $order->id ) );

			update_post_meta( $order->id, '_stampscomendicia_shipped_item_count', $current_shipped_items + $shipped_item_count );

		// If we don't have items, just complete the order as a whole
		} else {
			$order_shipped = true;
			$order_note    = sprintf( __( 'Items shipped via %s on %s with tracking number %s.', 'woocommerce' ), esc_html( $carrier ), date_i18n( get_option( 'date_format' ), $timestamp ), $tracking_number );
			$this->log( sprintf( __( "No items found - shipping entire order %d.", 'woocommerce-stampscomendicia' ), $order->id ) );
		}

		// Tracking information - WC Shipment Tracking extension
		if ( class_exists( 'WC_Shipment_Tracking' ) ) {
			update_post_meta( $order->id, '_tracking_provider', strtolower( $carrier ) );
			update_post_meta( $order->id, '_tracking_number', $tracking_number );
			update_post_meta( $order->id, '_date_shipped', $timestamp );
			$is_customer_note = 0;
		} else {
			$is_customer_note = 1;
		}
		
		// PBD modify to also update YITH Order Tracking start here
		switch(strtolower( $carrier )) {
			case 'ups':
				update_post_meta( $order->id, 'ywot_carrier_id', 'UPS_US' );
				break;
			case 'usps':
				update_post_meta( $order->id, 'ywot_carrier_id', 'USPS' );
				break;
		}
		update_post_meta( $order->id, 'ywot_tracking_code', $tracking_number );
		update_post_meta( $order->id, 'ywot_pick_up_date', date('Y-m-d', $timestamp ));
		update_post_meta( $order->id, 'ywot_tracking_postcode', '' );
		update_post_meta( $order->id, 'ywot_picked_up', '1' );
		// PBD modify to also update YITH Order Tracking end here
		
		$order->add_order_note( $order_note, $is_customer_note );

		// Update order status
		if ( $order_shipped ) {
			$order->update_status( WC_StampscomEndicia_Integration::$shipped_status );
			$this->log( sprintf( __( "Updated order %s to status %s", 'woocommerce-stampscomendicia' ), $order->id, WC_StampscomEndicia_Integration::$shipped_status ) );
		}

		// Trigger action for other integrations
		do_action( 'woocommerce_stampscomendicia_shipnotify', $order, array( 'tracking_number' => $tracking_number, 'carrier' => $carrier, 'ship_date' => $timestamp, 'xml' => $stampscomendicia_xml ) );

		status_header( 200 );
	}
}

return new WC_StampscomEndicia_API_Shipnotify();
