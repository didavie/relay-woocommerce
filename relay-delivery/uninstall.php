<?php
/**
 * Uninstall: remove the plugin's settings and cached quotes. Orders keep their Relay
 * meta (tracking code, notes) as part of the order history.
 *
 * @package RelayDelivery
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'relay_delivery_settings' );
delete_option( 'relay_delivery_state' );
delete_option( 'relay_delivery_site_id' );
delete_option( 'woocommerce_relay_delivery_settings' );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our own transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_relay\\_q\\_%' OR option_name LIKE '\\_transient\\_timeout\\_relay\\_q\\_%' OR option_name LIKE '\\_transient\\_relay\\_delivery\\_lock\\_%' OR option_name LIKE '\\_transient\\_timeout\\_relay\\_delivery\\_lock\\_%'" );
