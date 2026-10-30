<?php
/**
 * Settings storage (one option, autoload off for the secrets' sake is not needed: the
 * values are only read server side and never printed back in full).
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper.
 */
class Relay_Delivery_Settings {

	const OPTION       = 'relay_delivery_settings';
	const STATE_OPTION = 'relay_delivery_state';
	const DEFAULT_BASE = 'https://relaypartner.didavie.com/api/v1';

	/** Public API sandbox: rb_sbx_ keys (fake deliveries) only work there. */
	const SANDBOX_BASE = 'https://relaysandbox.didavie.com/api/v1';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'        => '',
			'api_base'       => self::DEFAULT_BASE,
			'test_mode'      => 'yes',
			'create_mode'    => 'auto',
			'cod'            => 'yes',
			'package_type'   => 'standard',
			'live_price'     => 'yes',
			'fallback_price' => 1500,
			'payer'          => 'customer',
			'tracking_email' => 'yes',
			'webhook_secret' => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Yes/no setting as bool.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Save settings (already sanitized).
	 *
	 * @param array $values Values.
	 */
	public static function save( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * Masked secret for display: prefix + dots, never the full value.
	 *
	 * @param string $secret Secret.
	 * @param int    $keep   Characters kept.
	 * @return string
	 */
	public static function mask( $secret, $keep = 12 ) {
		$secret = (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		return substr( $secret, 0, min( $keep, (int) floor( strlen( $secret ) / 3 ) ) ) . str_repeat( '•', 8 );
	}

	/**
	 * Runtime state (connected shop, last call).
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::STATE_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Update runtime state keys.
	 *
	 * @param array $values Values.
	 */
	public static function set_state( array $values ) {
		update_option( self::STATE_OPTION, array_merge( self::state(), $values ), false );
	}

	/**
	 * Random id of this install, made once, so two shops (or a reinstalled shop whose order
	 * numbers start again) never send the same order id to Relay.
	 *
	 * @return string
	 */
	public static function site_tag() {
		$tag = get_option( 'relay_delivery_site_id' );
		if ( ! is_string( $tag ) || ! preg_match( '/^[0-9a-f]{8}$/', $tag ) ) {
			$tag = bin2hex( random_bytes( 4 ) );
			update_option( 'relay_delivery_site_id', $tag, true );
		}
		return $tag;
	}

	/**
	 * Package types Relay knows.
	 *
	 * @return array
	 */
	public static function package_types() {
		return array(
			'standard'    => __( 'Standard', 'relay-delivery' ),
			'documents'   => __( 'Documents', 'relay-delivery' ),
			'clothing'    => __( 'Vêtements', 'relay-delivery' ),
			'electronics' => __( 'Électronique', 'relay-delivery' ),
			'food'        => __( 'Alimentation', 'relay-delivery' ),
			'medications' => __( 'Médicaments', 'relay-delivery' ),
			'fragile'     => __( 'Fragile', 'relay-delivery' ),
			'other'       => __( 'Autre', 'relay-delivery' ),
		);
	}
}
