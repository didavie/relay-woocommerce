<?php
/**
 * Signed Relay webhooks: POST /wp-json/relay-delivery/v1/webhook.
 *
 * Signature (Relay shop API): X-Relay-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>.
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Webhook receiver.
 */
class Relay_Delivery_Webhook {

	/** Signatures older than this are refused (replay protection). */
	const TOLERANCE = 300;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Route.
	 */
	public static function routes() {
		register_rest_route(
			'relay-delivery/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public on purpose: Relay calls it. Authentication = the HMAC signature, checked first in handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Check the signature header against the raw body.
	 *
	 * @param string $header X-Relay-Signature.
	 * @param string $body   Raw body.
	 * @param string $secret Secret.
	 * @param int    $now    Current time.
	 * @return bool
	 */
	public static function verify( $header, $body, $secret, $now ) {
		if ( '' === (string) $secret || '' === (string) $header ) {
			return false;
		}
		$t   = null;
		$sig = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] && ctype_digit( $kv[1] ) ) {
				$t = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = strtolower( $kv[1] );
			}
		}
		if ( null === $t || ! $sig || abs( $now - $t ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $body, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handle a webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body   = (string) $request->get_body();
		$secret = (string) Relay_Delivery_Settings::get( 'webhook_secret' );
		if ( ! self::verify( (string) $request->get_header( 'x_relay_signature' ), $body, $secret, time() ) ) {
			Relay_Delivery_API::log( 'warning', 'webhook refused: bad or missing signature' );
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'invalid_signature',
				),
				401
			);
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['event'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'invalid_body',
				),
				400
			);
		}
		$type = sanitize_text_field( (string) $event['event'] );
		$id   = isset( $event['id'] ) ? sanitize_text_field( (string) $event['id'] ) : '';
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		Relay_Delivery_API::log( 'info', 'webhook ' . $type . ' ' . $id );

		if ( 'webhook.test' === $type ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$order_id = Relay_Delivery_Deliveries::order_id_from_platform_id( isset( $data['platform_order_id'] ) ? (string) $data['platform_order_id'] : '' );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$code     = isset( $data['tracking_code'] ) ? (string) $data['tracking_code'] : '';
		// Only a package this order really has (a webhook for another site / an old attempt is ignored).
		$pid = isset( $data['platform_order_id'] ) ? (string) $data['platform_order_id'] : '';
		if ( ! $order || '' === $code || $code !== (string) $order->get_meta( '_relay_tracking_code' ) || $pid !== (string) $order->get_meta( '_relay_platform_order_id' ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'ignored' => 'unknown_package',
				),
				200
			);
		}
		$seen = $order->get_meta( '_relay_events' );
		$seen = is_array( $seen ) ? $seen : array();
		if ( '' !== $id && in_array( $id, $seen, true ) ) {
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'duplicate' => true,
				),
				200
			);
		}
		if ( '' !== $id ) {
			$seen[] = $id;
			$order->update_meta_data( '_relay_events', array_slice( $seen, -30 ) );
		}

		$before = (string) $order->get_meta( '_relay_status' );
		Relay_Delivery_Deliveries::apply_package( $order, $data );
		$order->save();
		$status = isset( $data['status'] ) ? (string) $data['status'] : $before;
		if ( 'package.failed' === $type && ! in_array( $status, array( 'problem', 'problem_request', 'returning', 'returned' ), true ) ) {
			$status = 'failed';
		}
		Relay_Delivery_Deliveries::status_changed( $order, $status );
		if ( 'cancelled' === $status && 'cancelled' !== $before ) {
			$order->update_meta_data( '_relay_attempt', (int) $order->get_meta( '_relay_attempt' ) + 1 );
			$order->save();
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
