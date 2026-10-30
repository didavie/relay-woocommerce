<?php
/**
 * Plugin Name:       Relay Livraison pour WooCommerce
 * Plugin URI:        https://relaypartner.didavie.com/fr/developers#shop
 * Description:       Livraison Relay pour votre boutique WooCommerce : prix en direct au paiement, création de la livraison, suivi client et statuts mis à jour automatiquement.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Didavie
 * Author URI:        https://relay.didavie.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       relay-delivery
 * Domain Path:       /languages
 * WC requires at least: 7.6
 * WC tested up to:   10.3
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

define( 'RELAY_DELIVERY_VERSION', '1.0.0' );
define( 'RELAY_DELIVERY_FILE', __FILE__ );
define( 'RELAY_DELIVERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'RELAY_DELIVERY_URL', plugin_dir_url( __FILE__ ) );

// HPOS (custom order tables) and cart/checkout blocks: declared compatible.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'relay-delivery', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Relay Livraison a besoin de WooCommerce. Activez WooCommerce pour l\'utiliser.', 'relay-delivery' ) . '</p></div>';
				}
			);
			return;
		}

		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-settings.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-api.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-deliveries.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-admin.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-order-box.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-webhook.php';
		require_once RELAY_DELIVERY_DIR . 'includes/class-relay-emails.php';

		Relay_Delivery_Admin::init();
		Relay_Delivery_Deliveries::init();
		Relay_Delivery_Order_Box::init();
		Relay_Delivery_Webhook::init();
		Relay_Delivery_Emails::init();

		add_action(
			'woocommerce_shipping_init',
			static function () {
				require_once RELAY_DELIVERY_DIR . 'includes/class-relay-shipping-method.php';
			}
		);
		add_filter(
			'woocommerce_shipping_methods',
			static function ( $methods ) {
				$methods['relay_delivery'] = 'Relay_Delivery_Shipping_Method';
				return $methods;
			}
		);
		add_filter(
			'plugin_action_links_' . plugin_basename( RELAY_DELIVERY_FILE ),
			static function ( $links ) {
				$url = admin_url( 'admin.php?page=wc-settings&tab=shipping&section=relay' );
				array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Réglages', 'relay-delivery' ) . '</a>' );
				return $links;
			}
		);
	}
);
