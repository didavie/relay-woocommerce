<?php
/**
 * Relay tracking link in the customer's e-mails.
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * E-mails.
 */
class Relay_Delivery_Emails {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'order_meta' ), 20, 3 );
	}

	/**
	 * Tracking block in customer e-mails.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin e-mail.
	 * @param bool     $plain_text    Plain text.
	 */
	public static function order_meta( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! Relay_Delivery_Settings::is_on( 'tracking_email' ) ) {
			return;
		}
		$url  = (string) $order->get_meta( '_relay_tracking_url' );
		$code = (string) $order->get_meta( '_relay_tracking_code' );
		if ( '' === $url || 'cancelled' === $order->get_meta( '_relay_status' ) ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Suivi de votre livraison Relay', 'relay-delivery' ) . "\n";
			/* translators: %s: tracking code */
			echo esc_html( sprintf( __( 'Code de suivi : %s', 'relay-delivery' ), $code ) ) . "\n";
			echo esc_url_raw( $url ) . "\n\n";
			return;
		}
		echo '<h2>' . esc_html__( 'Suivi de votre livraison Relay', 'relay-delivery' ) . '</h2>';
		echo '<p>';
		/* translators: %s: tracking code */
		echo esc_html( sprintf( __( 'Code de suivi : %s', 'relay-delivery' ), $code ) ) . '<br>';
		echo '<a href="' . esc_url( $url ) . '" style="color:#0E2A5C;font-weight:bold;">' . esc_html__( 'Suivre ma livraison', 'relay-delivery' ) . '</a>';
		echo '</p>';
	}

	/**
	 * Delivery created after the order e-mail (manual button): send the link as a customer note.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function maybe_send_tracking_note( WC_Order $order ) {
		$url = (string) $order->get_meta( '_relay_tracking_url' );
		if ( '' === $url || ! Relay_Delivery_Settings::is_on( 'tracking_email' ) ) {
			return;
		}
		$order->add_order_note(
			/* translators: %s: tracking URL */
			sprintf( __( 'Votre commande part avec Relay. Suivez la livraison ici : %s', 'relay-delivery' ), esc_url_raw( $url ) ),
			true
		);
	}
}
