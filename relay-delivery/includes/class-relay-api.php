<?php
/**
 * Relay shop API client (/api/v1, Bearer rb_live_ / rb_test_ key, or rb_sbx_ for the API sandbox).
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client. Never logs or returns the API key.
 */
class Relay_Delivery_API {

	/**
	 * Base URL of the API, without trailing slash. API sandbox keys (rb_sbx_) always use
	 * relaysandbox.didavie.com: they are refused everywhere else.
	 *
	 * @param string|null $key API key used for the call (null: the saved key).
	 * @return string
	 */
	public static function base( $key = null ) {
		$key = null === $key ? (string) Relay_Delivery_Settings::get( 'api_key' ) : (string) $key;
		if ( 0 === strpos( $key, 'rb_sbx_' ) ) {
			return Relay_Delivery_Settings::SANDBOX_BASE;
		}
		$base = trim( (string) Relay_Delivery_Settings::get( 'api_base' ) );
		if ( '' === $base || 0 !== strpos( $base, 'https://' ) ) {
			$base = Relay_Delivery_Settings::DEFAULT_BASE;
		}
		return untrailingslashit( $base );
	}

	/**
	 * Call the API.
	 *
	 * @param string     $method  GET|POST|PATCH.
	 * @param string     $path    Path starting with "/".
	 * @param array|null $body    JSON body.
	 * @param array      $args    timeout, idempotency_key, api_key (override, for the connection test).
	 * @return array { ok, status, data, code, message, network }
	 */
	public static function request( $method, $path, $body = null, array $args = array() ) {
		$key = isset( $args['api_key'] ) ? (string) $args['api_key'] : (string) Relay_Delivery_Settings::get( 'api_key' );
		if ( '' === $key ) {
			return self::result( false, 0, array(), 'NO_API_KEY', __( 'Aucune clé API Relay n\'est enregistrée.', 'relay-delivery' ), false );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => 'application/json',
			'User-Agent'    => 'RelayWooCommerce/' . RELAY_DELIVERY_VERSION . '; ' . home_url( '/' ),
		);
		$params  = array(
			'method'  => $method,
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 15,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$params['headers']['Content-Type'] = 'application/json';
			$params['body']                    = wp_json_encode( $body );
		}
		if ( ! empty( $args['idempotency_key'] ) ) {
			$params['headers']['Idempotency-Key'] = (string) $args['idempotency_key'];
		}

		$url      = self::base( $key ) . $path;
		$response = wp_remote_request( $url, $params );

		if ( is_wp_error( $response ) ) {
			$res = self::result( false, 0, array(), 'NETWORK_ERROR', $response->get_error_message(), true );
		} else {
			$status = (int) wp_remote_retrieve_response_code( $response );
			$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $json ) ) {
				$res = self::result( false, $status, array(), 'BAD_RESPONSE', __( 'Réponse illisible de Relay.', 'relay-delivery' ), $status >= 500 || 0 === $status );
				self::log( 'debug', 'unreadable answer: ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ), 0, 200 ) );
			} elseif ( ! empty( $json['success'] ) ) {
				$res = self::result( true, $status, isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array(), '', isset( $json['message'] ) ? (string) $json['message'] : '', false );
			} else {
				$res = self::result(
					false,
					$status,
					array(),
					isset( $json['code'] ) ? (string) $json['code'] : 'ERROR',
					isset( $json['error'] ) ? (string) $json['error'] : __( 'Demande refusée par Relay.', 'relay-delivery' ),
					$status >= 500
				);
			}
		}

		Relay_Delivery_Settings::set_state(
			array(
				'last_call' => array(
					'at'     => time(),
					'method' => $method,
					'path'   => strtok( $path, '?' ),
					'status' => $res['status'],
					'ok'     => $res['ok'],
					'code'   => $res['code'],
				),
			)
		);
		self::log(
			$res['ok'] ? 'info' : 'warning',
			sprintf( '%s %s -> %s %s %s', $method, strtok( $path, '?' ), $res['status'] ? $res['status'] : 'network', $res['code'], $res['ok'] ? '' : $res['message'] )
		);
		return $res;
	}

	/**
	 * Build a result array.
	 *
	 * @param bool   $ok      Success.
	 * @param int    $status  HTTP status.
	 * @param array  $data    Data.
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param bool   $network Relay could not be reached (or answered 5xx).
	 * @return array
	 */
	private static function result( $ok, $status, $data, $code, $message, $network ) {
		return array(
			'ok'      => (bool) $ok,
			'status'  => (int) $status,
			'data'    => $data,
			'code'    => (string) $code,
			'message' => (string) $message,
			'network' => (bool) $network,
		);
	}

	/**
	 * Write to the WooCommerce log (source relay-delivery).
	 *
	 * @param string $level   Level.
	 * @param string $message Message (never contains the key).
	 */
	public static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'relay-delivery' ) );
		}
	}

	/**
	 * GET /me.
	 *
	 * @param string|null $key Key to test (else the saved one).
	 * @return array
	 */
	public static function me( $key = null ) {
		$args = array( 'timeout' => 10 );
		if ( null !== $key ) {
			$args['api_key'] = $key;
		}
		return self::request( 'GET', '/me', null, $args );
	}

	/**
	 * GET /pricing/estimate by address text (or coordinates).
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public static function estimate( array $query ) {
		return self::request( 'GET', '/pricing/estimate?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), null, array( 'timeout' => 8 ) );
	}

	/**
	 * POST /packages.
	 *
	 * @param array  $body            Body.
	 * @param string $idempotency_key Idempotency key (= platform_order_id).
	 * @return array
	 */
	public static function create_package( array $body, $idempotency_key ) {
		return self::request(
			'POST',
			'/packages',
			$body,
			array(
				'idempotency_key' => $idempotency_key,
				'timeout'         => 20,
			)
		);
	}

	/**
	 * GET /packages/{tracking}.
	 *
	 * @param string $tracking Tracking code.
	 * @return array
	 */
	public static function get_package( $tracking ) {
		return self::request( 'GET', '/packages/' . rawurlencode( $tracking ) );
	}

	/**
	 * PATCH /packages/{tracking}/cancel.
	 *
	 * @param string $tracking Tracking code.
	 * @param string $reason   Reason.
	 * @return array
	 */
	public static function cancel_package( $tracking, $reason ) {
		return self::request( 'PATCH', '/packages/' . rawurlencode( $tracking ) . '/cancel', array( 'reason' => $reason ) );
	}
}
