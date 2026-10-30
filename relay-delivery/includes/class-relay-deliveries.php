<?php
/**
 * Order <-> Relay package: create, cancel, refresh, statuses.
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delivery logic. Order meta (HPOS-safe, through the WC_Order API only):
 *   _relay_platform_order_id  id sent to Relay (idempotency key)
 *   _relay_attempt            bumped after a cancellation, so a new delivery gets a new id
 *   _relay_package_id, _relay_tracking_code, _relay_tracking_url, _relay_status,
 *   _relay_fee, _relay_driver, _relay_cod_amount, _relay_test, _relay_error (array code/message),
 *   _relay_events (processed webhook ids)
 */
class Relay_Delivery_Deliveries {

	/** Statuses before pickup: the delivery can still be cancelled for free. */
	const PRE_PICKUP = array( 'pending', 'registered', 'awaiting_driver', 'awaiting_pickup', 'driver_assigned', 'en_route_to_pickup', 'on_hold', 'test' );

	/** Quote refusals: the order is retried once at today's price. */
	const QUOTE_ERRORS = array( 'QUOTE_EXPIRED', 'QUOTE_USED', 'QUOTE_MISMATCH', 'QUOTE_INVALID' );

	/**
	 * Hooks.
	 */
	public static function init() {
		// Before the customer "processing" e-mail (sent on pending_to_processing), so it carries the link.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_processing' ), 5, 2 );
	}

	/**
	 * Order went "En cours": create the delivery when the mode is automatic.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_processing( $order_id, $order = null ) {
		if ( 'auto' !== Relay_Delivery_Settings::get( 'create_mode' ) ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! self::uses_relay( $order ) || self::has_live_delivery( $order ) ) {
			return;
		}
		self::create( $order, 'auto' );
	}

	/**
	 * The order's shipping line is Relay.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function uses_relay( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( 'relay_delivery' === $item->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The Relay shipping line, if any.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Shipping|null
	 */
	public static function shipping_item( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( 'relay_delivery' === $item->get_method_id() ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * A delivery exists and is not cancelled.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function has_live_delivery( WC_Order $order ) {
		$code = (string) $order->get_meta( '_relay_tracking_code' );
		return '' !== $code && 'cancelled' !== $order->get_meta( '_relay_status' );
	}

	/**
	 * Id sent to Relay: stable per order and per attempt, so retries never create two.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function platform_order_id( WC_Order $order ) {
		$attempt = (int) $order->get_meta( '_relay_attempt' );
		$id      = 'WC-' . Relay_Delivery_Settings::site_tag() . '-' . $order->get_id();
		return $attempt > 0 ? $id . '-R' . $attempt : $id;
	}

	/**
	 * Order id from a platform_order_id we built. The caller must still check that the order
	 * holds exactly this platform_order_id (see the webhook).
	 *
	 * @param string $platform_order_id Id.
	 * @return int 0 when it is not ours.
	 */
	public static function order_id_from_platform_id( $platform_order_id ) {
		return preg_match( '/^WC-[0-9a-f]{6,12}-(\d+)(?:-R\d+)?$/', (string) $platform_order_id, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Cash the driver collects, when cash on delivery applies to this order.
	 *
	 * @param WC_Order $order Order.
	 * @return int 0 when nothing to collect.
	 */
	public static function cod_amount( WC_Order $order ) {
		if ( ! Relay_Delivery_Settings::is_on( 'cod' ) || 'cod' !== $order->get_payment_method() ) {
			return 0;
		}
		return (int) round( (float) $order->get_total() );
	}

	/**
	 * Create the delivery for an order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $via   auto|manual.
	 * @return array API result.
	 */
	public static function create( WC_Order $order, $via = 'manual' ) {
		// Same request twice at once (double click, two workers): one only.
		$lock = 'relay_delivery_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			return array(
				'ok'      => false,
				'code'    => 'IN_PROGRESS',
				'message' => __( 'Création déjà en cours pour cette commande.', 'relay-delivery' ),
			);
		}
		set_transient( $lock, 1, 60 );

		for ( $try = 0; $try < 3; $try++ ) {
			$body = self::package_body( $order );
			$res  = Relay_Delivery_API::create_package( $body, $body['platform_order_id'] );
			if ( ! $res['ok'] && isset( $body['quote_id'] ) && in_array( $res['code'], self::QUOTE_ERRORS, true ) ) {
				unset( $body['quote_id'] );
				$res = Relay_Delivery_API::create_package( $body, $body['platform_order_id'] );
			}
			// Relay already had a delivery for this id, but it was cancelled: make a new attempt.
			if ( $res['ok'] && ! empty( $res['data']['idempotent'] ) && isset( $res['data']['status'] ) && in_array( $res['data']['status'], array( 'cancelled', 'failed' ), true ) ) {
				$order->update_meta_data( '_relay_attempt', (int) $order->get_meta( '_relay_attempt' ) + 1 );
				continue;
			}
			break;
		}
		delete_transient( $lock );

		$order->update_meta_data( '_relay_platform_order_id', $body['platform_order_id'] );
		if ( $res['ok'] ) {
			$order->delete_meta_data( '_relay_error' );
			self::apply_package( $order, $res['data'] );
			$order->update_meta_data( '_relay_test', ! empty( $res['data']['test'] ) ? 'yes' : 'no' );
			$order->update_meta_data( '_relay_cod_amount', self::cod_amount( $order ) );
			$order->save();
			$fee  = isset( $res['data']['delivery_fee_fcfa'] ) ? self::money( $res['data']['delivery_fee_fcfa'] ) : '';
			$note = ! empty( $res['data']['test'] )
				/* translators: %s: test tracking code */
				? sprintf( __( 'Relay (mode test) : livraison validée sans être créée (%s). Aucun livreur n\'a été appelé.', 'relay-delivery' ), $res['data']['tracking_code'] )
				: ( ! empty( $res['data']['idempotent'] )
					/* translators: %s: tracking code */
					? sprintf( __( 'Relay : cette commande avait déjà une livraison (%s), elle a été reprise.', 'relay-delivery' ), $res['data']['tracking_code'] )
					/* translators: 1: tracking code, 2: fee */
					: sprintf( __( 'Livraison Relay créée : %1$s (frais Relay %2$s).', 'relay-delivery' ), $res['data']['tracking_code'], $fee ) );
			if ( 'auto' === $via ) {
				$note .= ' ' . __( '(automatique, commande en cours)', 'relay-delivery' );
			}
			$order->add_order_note( $note );
			if ( 'manual' === $via && empty( $res['data']['test'] ) && empty( $res['data']['idempotent'] ) ) {
				Relay_Delivery_Emails::maybe_send_tracking_note( $order );
			}
		} else {
			$order->update_meta_data(
				'_relay_error',
				array(
					'code'    => $res['code'],
					'message' => $res['message'],
					'at'      => time(),
				)
			);
			$order->save();
			$order->add_order_note(
				/* translators: %s: reason */
				sprintf( __( 'Livraison Relay refusée : %s', 'relay-delivery' ), self::error_text( $res ) )
			);
		}
		return $res;
	}

	/**
	 * Request body for POST /packages.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function package_body( WC_Order $order ) {
		$address = self::delivery_address( $order );
		$name    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}
		$phone = $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone();

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_quantity() . ' x ' . $item->get_name();
		}
		$cod          = self::cod_amount( $order );
		$instructions = array();
		if ( $cod > 0 ) {
			/* translators: %s: amount */
			$instructions[] = sprintf( __( 'Paiement à la livraison : encaisser %s (total de la commande).', 'relay-delivery' ), self::money( $cod ) );
		}
		if ( $order->get_customer_note() ) {
			$instructions[] = wp_strip_all_tags( $order->get_customer_note() );
		}

		$body = array(
			'platform_order_id' => self::platform_order_id( $order ),
			'client_name'       => '' !== $name ? $name : __( 'Client', 'relay-delivery' ),
			'client_phone'      => (string) $phone,
			'client_email'      => (string) $order->get_billing_email(),
			'client_address'    => $address,
			'package_type'      => (string) Relay_Delivery_Settings::get( 'package_type' ),
			'description'       => mb_substr( implode( ', ', $items ), 0, 480 ),
			'value_fcfa'        => (int) round( (float) $order->get_total() ),
			'delivery_payer'    => 'recipient' === Relay_Delivery_Settings::get( 'payer' ) ? 'recipient' : 'merchant',
			'metadata'          => array(
				'source'               => 'woocommerce',
				'site'                 => home_url( '/' ),
				'order_id'             => $order->get_id(),
				'order_number'         => $order->get_order_number(),
				'payment_method'       => $order->get_payment_method(),
				'cash_to_collect_fcfa' => $cod,
			),
			'test'              => Relay_Delivery_Settings::is_on( 'test_mode' ),
		);
		if ( $instructions ) {
			$body['special_instructions'] = mb_substr( implode( ' ', $instructions ), 0, 480 );
		}

		// Point + quote found at checkout (shipping line meta).
		$item = self::shipping_item( $order );
		if ( $item ) {
			$lat = $item->get_meta( 'relay_lat' );
			$lng = $item->get_meta( 'relay_lng' );
			if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
				$body['delivery_latitude']  = (float) $lat;
				$body['delivery_longitude'] = (float) $lng;
				$quote                      = (string) $item->get_meta( 'relay_quote_id' );
				if ( preg_match( '/^[0-9a-f-]{36}$/i', $quote ) ) {
					$body['quote_id'] = $quote;
				}
			}
		}
		// No point yet: ask Relay to find the address now (best effort).
		if ( ! isset( $body['delivery_latitude'] ) && '' !== $address ) {
			$est = Relay_Delivery_API::estimate( array( 'delivery_address' => $address ) );
			if ( $est['ok'] && ! empty( $est['data']['geocoded'] ) && isset( $est['data']['delivery_latitude'] ) ) {
				$body['delivery_latitude']  = (float) $est['data']['delivery_latitude'];
				$body['delivery_longitude'] = (float) $est['data']['delivery_longitude'];
			}
		}
		return $body;
	}

	/**
	 * Delivery address as one line.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function delivery_address( WC_Order $order ) {
		$parts = $order->has_shipping_address()
			? array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), $order->get_shipping_state(), self::country_name( $order->get_shipping_country() ) )
			: array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_state(), self::country_name( $order->get_billing_country() ) );
		return trim( implode( ', ', array_filter( array_map( 'trim', array_map( 'strval', $parts ) ) ) ) );
	}

	/**
	 * Country name from code.
	 *
	 * @param string $code ISO-2.
	 * @return string
	 */
	public static function country_name( $code ) {
		$countries = WC()->countries ? WC()->countries->get_countries() : array();
		return isset( $countries[ $code ] ) ? html_entity_decode( $countries[ $code ] ) : (string) $code;
	}

	/**
	 * Store package fields on the order (no save).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $pkg   Package data from Relay.
	 */
	public static function apply_package( WC_Order $order, array $pkg ) {
		$map = array(
			'package_id'           => '_relay_package_id',
			'tracking_code'        => '_relay_tracking_code',
			'tracking_url'         => '_relay_tracking_url',
			'status'               => '_relay_status',
			'delivery_fee_fcfa'    => '_relay_fee',
			'assigned_driver_name' => '_relay_driver',
		);
		foreach ( $map as $field => $meta ) {
			if ( array_key_exists( $field, $pkg ) ) {
				$value = $pkg[ $field ];
				if ( 'tracking_url' === $field ) {
					$value = esc_url_raw( (string) $value );
				}
				$order->update_meta_data( $meta, is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '' );
			}
		}
	}

	/**
	 * Cancel the delivery (free before pickup).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function cancel( WC_Order $order ) {
		$code = (string) $order->get_meta( '_relay_tracking_code' );
		if ( '' === $code || 'yes' === $order->get_meta( '_relay_test' ) ) {
			// Test mode: nothing exists at Relay, forget it locally.
			self::forget( $order );
			$order->add_order_note( __( 'Relay (mode test) : livraison retirée de la commande.', 'relay-delivery' ) );
			return array( 'ok' => true );
		}
		/* translators: %s: order number */
		$res = Relay_Delivery_API::cancel_package( $code, sprintf( __( 'Annulée depuis WooCommerce (commande %s)', 'relay-delivery' ), $order->get_order_number() ) );
		if ( $res['ok'] ) {
			self::apply_package( $order, $res['data'] );
			$order->update_meta_data( '_relay_status', 'cancelled' );
			$order->update_meta_data( '_relay_attempt', (int) $order->get_meta( '_relay_attempt' ) + 1 );
			$order->save();
			/* translators: %s: tracking code */
			$order->add_order_note( sprintf( __( 'Livraison Relay %s annulée (gratuit avant la récupération).', 'relay-delivery' ), $code ) );
		} else {
			/* translators: %s: reason */
			$order->add_order_note( sprintf( __( 'Annulation Relay refusée : %s', 'relay-delivery' ), self::error_text( $res ) ) );
		}
		return $res;
	}

	/**
	 * Forget a test delivery.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function forget( WC_Order $order ) {
		foreach ( array( '_relay_package_id', '_relay_tracking_code', '_relay_tracking_url', '_relay_status', '_relay_fee', '_relay_driver', '_relay_test', '_relay_error' ) as $k ) {
			$order->delete_meta_data( $k );
		}
		$order->update_meta_data( '_relay_attempt', (int) $order->get_meta( '_relay_attempt' ) + 1 );
		$order->save();
	}

	/**
	 * Refresh from Relay.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function refresh( WC_Order $order ) {
		$code = (string) $order->get_meta( '_relay_tracking_code' );
		if ( '' === $code || 'yes' === $order->get_meta( '_relay_test' ) ) {
			return array( 'ok' => true );
		}
		$res = Relay_Delivery_API::get_package( $code );
		if ( $res['ok'] ) {
			$before = (string) $order->get_meta( '_relay_status' );
			self::apply_package( $order, $res['data'] );
			$order->save();
			if ( isset( $res['data']['status'] ) && $before !== $res['data']['status'] ) {
				self::status_changed( $order, (string) $res['data']['status'] );
			}
		}
		return $res;
	}

	/**
	 * Relay status changed: order note + order status.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status New Relay status.
	 */
	public static function status_changed( WC_Order $order, $status ) {
		$label = self::status_label( $status );
		/* translators: %s: status label */
		$note = sprintf( __( 'Relay : %s.', 'relay-delivery' ), $label );
		if ( 'delivered' === $status ) {
			if ( ! $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
				$order->update_status( 'completed', $note );
				return;
			}
		} elseif ( in_array( $status, array( 'problem', 'problem_request', 'returning', 'returned', 'failed' ), true ) ) {
			if ( $order->has_status( 'processing' ) ) {
				$order->update_status( 'on-hold', $note );
				return;
			}
		}
		$order->add_order_note( $note );
	}

	/**
	 * Step of the progress bar (0 created, 1 driver, 2 picked up, 3 delivered).
	 *
	 * @param string $status Relay status.
	 * @return int
	 */
	public static function step( $status ) {
		if ( 'delivered' === $status ) {
			return 3;
		}
		if ( in_array( $status, array( 'picked_up', 'at_hub', 'in_transit', 'out_for_delivery', 'dispatched_to_relay', 'available_at_relay' ), true ) ) {
			return 2;
		}
		if ( in_array( $status, array( 'driver_assigned', 'en_route_to_pickup', 'awaiting_pickup' ), true ) ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Human label.
	 *
	 * @param string $status Relay status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'test'               => __( 'test, rien créé', 'relay-delivery' ),
			'pending'            => __( 'en attente d\'un livreur', 'relay-delivery' ),
			'registered'         => __( 'en attente d\'un livreur', 'relay-delivery' ),
			'awaiting_driver'    => __( 'en attente d\'un livreur', 'relay-delivery' ),
			'driver_assigned'    => __( 'livreur trouvé', 'relay-delivery' ),
			'awaiting_pickup'    => __( 'livreur trouvé', 'relay-delivery' ),
			'en_route_to_pickup' => __( 'livreur en route vers la boutique', 'relay-delivery' ),
			'picked_up'          => __( 'colis récupéré', 'relay-delivery' ),
			'in_transit'         => __( 'en route vers le client', 'relay-delivery' ),
			'out_for_delivery'   => __( 'en route vers le client', 'relay-delivery' ),
			'delivered'          => __( 'livré', 'relay-delivery' ),
			'cancelled'          => __( 'annulée', 'relay-delivery' ),
			'problem'            => __( 'problème signalé', 'relay-delivery' ),
			'problem_request'    => __( 'problème signalé', 'relay-delivery' ),
			'returning'          => __( 'retour en cours', 'relay-delivery' ),
			'returned'           => __( 'retourné', 'relay-delivery' ),
			'failed'             => __( 'échec de livraison', 'relay-delivery' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * Readable refusal reason.
	 *
	 * @param array $res API result.
	 * @return string
	 */
	public static function error_text( array $res ) {
		$known = array(
			'COUNTRY_NOT_SERVED'  => __( 'Relay ne livre pas encore à cette adresse.', 'relay-delivery' ),
			'INVALID_PHONE'       => __( 'Le numéro de téléphone du client n\'est pas valide.', 'relay-delivery' ),
			'DAILY_LIMIT'         => __( 'Limite de livraisons du jour atteinte pour votre boutique.', 'relay-delivery' ),
			'PENDING_LIMIT'       => __( 'Trop de colis attendent déjà un livreur.', 'relay-delivery' ),
			'SHOP_PAUSED'         => __( 'L\'accès API de votre boutique est en pause. Contactez Relay.', 'relay-delivery' ),
			'API_DISABLED'        => __( 'Le service Relay pour les sites est en pause. Réessayez plus tard.', 'relay-delivery' ),
			'INVALID_API_KEY'     => __( 'Clé API Relay invalide ou révoquée.', 'relay-delivery' ),
			'NETWORK_ERROR'       => __( 'Relay est injoignable pour le moment.', 'relay-delivery' ),
			'PRICING_UNAVAILABLE' => __( 'Aucun prix Relay pour ce trajet.', 'relay-delivery' ),
		);
		$code  = isset( $res['code'] ) ? $res['code'] : '';
		$text  = isset( $known[ $code ] ) ? $known[ $code ] : ( isset( $res['message'] ) ? $res['message'] : '' );
		return trim( $text . ( $code ? ' (' . $code . ')' : '' ) );
	}

	/**
	 * Amount in F CFA.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		return number_format_i18n( (float) $amount, 0 ) . ' F';
	}
}
