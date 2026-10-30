<?php
/**
 * Checkout shipping method « Livraison Relay »: live Relay price for the customer's
 * address, fixed fallback price when Relay cannot be reached.
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shipping method (shipping zones).
 */
class Relay_Delivery_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Instance id.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'relay_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Livraison Relay', 'relay-delivery' );
		$this->method_description = __( 'Livraison par les livreurs Relay, au prix Relay calculé pour l\'adresse du client. Réglages généraux : WooCommerce > Réglages > Livraison > Relay.', 'relay-delivery' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->init();
	}

	/**
	 * Settings.
	 */
	public function init() {
		$this->instance_form_fields = array(
			'title' => array(
				'title'   => __( 'Nom affiché au client', 'relay-delivery' ),
				'type'    => 'text',
				'default' => __( 'Livraison Relay', 'relay-delivery' ),
			),
		);
		$this->title                = $this->get_option( 'title', __( 'Livraison Relay', 'relay-delivery' ) );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Price for the cart's destination.
	 *
	 * @param array $package Cart package.
	 */
	public function calculate_shipping( $package = array() ) {
		$dest    = isset( $package['destination'] ) ? $package['destination'] : array();
		$address = self::address_line( $dest );
		$quote   = $this->quote( $address, $package );
		if ( null === $quote ) {
			return; // Refused (address out of zone): Relay is not offered.
		}
		$payer = Relay_Delivery_Settings::get( 'payer' );
		$cost  = 'customer' === $payer ? $quote['price'] : 0;

		$meta = array(
			'relay_price'      => $quote['price'],
			'relay_price_type' => $quote['type'],
		);
		if ( null !== $quote['lat'] ) {
			$meta['relay_lat'] = $quote['lat'];
			$meta['relay_lng'] = $quote['lng'];
		}
		if ( $quote['quote_id'] ) {
			$meta['relay_quote_id'] = $quote['quote_id'];
		}
		$label = $this->title;
		if ( 'recipient' === $payer ) {
			/* translators: %s: price */
			$label .= ' ' . sprintf( __( '(%s à payer au livreur)', 'relay-delivery' ), Relay_Delivery_Deliveries::money( $quote['price'] ) );
		} elseif ( 'shop' === $payer ) {
			$label .= ' ' . __( '(offerte)', 'relay-delivery' );
		} elseif ( 'from' === $quote['type'] ) {
			$label .= ' ' . __( '(à partir de)', 'relay-delivery' );
		}
		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => $label,
				'cost'      => $cost,
				'taxes'     => false,
				'package'   => $package,
				'meta_data' => $meta,
			)
		);
	}

	/**
	 * Relay quote for an address (cached 10 min per address), or the fallback price.
	 *
	 * @param string $address Address line.
	 * @param array  $package Cart package.
	 * @return array|null { price, type, lat, lng, quote_id } or null when Relay refuses the address.
	 */
	private function quote( $address, $package ) {
		$fallback = array(
			'price'    => max( 0, (int) Relay_Delivery_Settings::get( 'fallback_price' ) ),
			'type'     => 'fallback',
			'lat'      => null,
			'lng'      => null,
			'quote_id' => '',
		);
		if ( ! Relay_Delivery_Settings::is_on( 'live_price' ) || '' === (string) Relay_Delivery_Settings::get( 'api_key' ) || '' === $address ) {
			return $fallback;
		}
		$weight = 0.0;
		if ( ! empty( $package['contents'] ) && function_exists( 'wc_get_weight' ) ) {
			foreach ( $package['contents'] as $line ) {
				if ( isset( $line['data'] ) && $line['data'] instanceof WC_Product && $line['data']->get_weight() ) {
					$weight += wc_get_weight( (float) $line['data']->get_weight(), 'kg' ) * (int) $line['quantity'];
				}
			}
		}
		$query = array( 'delivery_address' => $address );
		if ( $weight > 0 ) {
			$query['weight_kg'] = round( min( $weight, 30 ), 2 );
		}
		$cache_key = 'relay_q_' . md5( wp_json_encode( $query ) . Relay_Delivery_API::base() );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return 'refused' === $cached['type'] ? null : $cached;
		}

		$res = Relay_Delivery_API::estimate( $query );
		if ( ! $res['ok'] ) {
			if ( $res['network'] || in_array( $res['code'], array( 'NETWORK_ERROR', 'BAD_RESPONSE', 'INTERNAL_ERROR', 'API_DISABLED', 'RATE_LIMIT_EXCEEDED', 'INVALID_API_KEY', 'NO_API_KEY' ), true ) ) {
				return $fallback; // Relay down or not set up: keep selling at the fixed price.
			}
			set_transient( $cache_key, array( 'type' => 'refused' ), 10 * MINUTE_IN_SECONDS );
			return null;
		}
		$d     = $res['data'];
		$quote = array(
			'price'    => isset( $d['price_fcfa'] ) ? (int) $d['price_fcfa'] : $fallback['price'],
			'type'     => isset( $d['price_type'] ) ? (string) $d['price_type'] : 'fixed',
			'lat'      => ! empty( $d['geocoded'] ) && isset( $d['delivery_latitude'] ) ? (float) $d['delivery_latitude'] : null,
			'lng'      => ! empty( $d['geocoded'] ) && isset( $d['delivery_longitude'] ) ? (float) $d['delivery_longitude'] : null,
			'quote_id' => isset( $d['quote_id'] ) ? (string) $d['quote_id'] : '',
		);
		set_transient( $cache_key, $quote, 10 * MINUTE_IN_SECONDS );
		return $quote;
	}

	/**
	 * Destination as one line.
	 *
	 * @param array $dest Destination.
	 * @return string
	 */
	public static function address_line( array $dest ) {
		$parts = array();
		foreach ( array( 'address', 'address_1', 'address_2', 'city', 'state' ) as $k ) {
			if ( ! empty( $dest[ $k ] ) && ! in_array( $dest[ $k ], $parts, true ) ) {
				$parts[] = trim( (string) $dest[ $k ] );
			}
		}
		if ( ! $parts ) {
			return '';
		}
		if ( ! empty( $dest['country'] ) ) {
			$parts[] = Relay_Delivery_Deliveries::country_name( $dest['country'] );
		}
		return implode( ', ', array_filter( $parts ) );
	}
}
