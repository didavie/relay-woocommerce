<?php
/**
 * « Livraison Relay » box on the order edit screen (classic and HPOS screens).
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order meta box + its actions.
 */
class Relay_Delivery_Order_Box {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 30 );
		add_action( 'wp_ajax_relay_delivery_order', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * Register the box.
	 */
	public static function register() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'relay-delivery', __( 'Livraison Relay', 'relay-delivery' ), array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Render.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post (classic) or order (HPOS).
	 */
	public static function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$code    = (string) $order->get_meta( '_relay_tracking_code' );
		$status  = (string) $order->get_meta( '_relay_status' );
		$error   = $order->get_meta( '_relay_error' );
		$is_test = 'yes' === $order->get_meta( '_relay_test' );
		echo '<div class="relay-box" data-order="' . esc_attr( (string) $order->get_id() ) . '">';

		if ( ! Relay_Delivery_Deliveries::uses_relay( $order ) && '' === $code ) {
			echo '<p class="relay-muted">' . esc_html__( 'Cette commande n\'utilise pas la livraison Relay.', 'relay-delivery' ) . '</p>';
			self::button( 'create', __( 'Créer quand même la livraison Relay', 'relay-delivery' ), 'button' );
			echo '</div>';
			return;
		}

		if ( '' === $code || 'cancelled' === $status ) {
			if ( is_array( $error ) && ! empty( $error['message'] ) ) {
				echo '<p><span class="relay-badge relay-badge--ko">' . esc_html__( 'Refusée', 'relay-delivery' ) . '</span></p>';
				echo '<p class="relay-reason">' . esc_html( Relay_Delivery_Deliveries::error_text( $error ) ) . '</p>';
				self::button( 'create', __( 'Réessayer', 'relay-delivery' ), 'button button-primary relay-btn-flame' );
			} else {
				if ( 'cancelled' === $status ) {
					/* translators: %s: tracking code */
					echo '<p class="relay-muted">' . esc_html( sprintf( __( 'Livraison %s annulée.', 'relay-delivery' ), $code ) ) . '</p>';
				}
				echo '<p><span class="relay-badge relay-badge--todo">' . esc_html__( 'Pas encore créée', 'relay-delivery' ) . '</span></p>';
				$item = Relay_Delivery_Deliveries::shipping_item( $order );
				if ( $item && is_numeric( $item->get_meta( 'relay_price' ) ) ) {
					echo '<p class="relay-row"><span>' . esc_html__( 'Estimation Relay', 'relay-delivery' ) . '</span><strong>' . esc_html( Relay_Delivery_Deliveries::money( $item->get_meta( 'relay_price' ) ) ) . '</strong></p>';
				}
				self::button( 'create', __( 'Créer la livraison Relay', 'relay-delivery' ), 'button button-primary relay-btn-flame' );
			}
			if ( Relay_Delivery_Settings::is_on( 'test_mode' ) ) {
				echo '<p class="relay-muted">' . esc_html__( 'Mode test actif : rien ne sera créé chez Relay.', 'relay-delivery' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		$step = Relay_Delivery_Deliveries::step( $status );
		echo '<p class="relay-row"><span>' . esc_html__( 'Suivi', 'relay-delivery' ) . '</span><code>' . esc_html( $code ) . '</code></p>';
		echo '<p class="relay-row"><span>' . esc_html__( 'Statut', 'relay-delivery' ) . '</span><span class="relay-badge relay-badge--' . esc_attr( 'delivered' === $status ? 'ok' : ( $is_test ? 'test' : 'run' ) ) . '">' . esc_html( Relay_Delivery_Deliveries::status_label( $status ) ) . '</span></p>';
		$driver = (string) $order->get_meta( '_relay_driver' );
		echo '<p class="relay-row"><span>' . esc_html__( 'Livreur', 'relay-delivery' ) . '</span><span>' . esc_html( '' !== $driver ? $driver : __( 'pas encore', 'relay-delivery' ) ) . '</span></p>';
		$fee = $order->get_meta( '_relay_fee' );
		if ( '' !== $fee ) {
			echo '<p class="relay-row"><span>' . esc_html__( 'Frais Relay', 'relay-delivery' ) . '</span><strong>' . esc_html( Relay_Delivery_Deliveries::money( $fee ) ) . '</strong></p>';
		}
		$cod = (int) $order->get_meta( '_relay_cod_amount' );
		echo '<p class="relay-row"><span>' . esc_html__( 'À encaisser', 'relay-delivery' ) . '</span><strong>' . esc_html( $cod > 0 ? Relay_Delivery_Deliveries::money( $cod ) : __( 'rien', 'relay-delivery' ) ) . '</strong></p>';

		$steps = array( __( 'Créée', 'relay-delivery' ), __( 'Livreur', 'relay-delivery' ), __( 'Récupéré', 'relay-delivery' ), __( 'Livré', 'relay-delivery' ) );
		echo '<ol class="relay-steps">';
		foreach ( $steps as $i => $label ) {
			echo '<li class="' . esc_attr( $i <= $step ? 'is-done' : '' ) . '">' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';

		$url = (string) $order->get_meta( '_relay_tracking_url' );
		echo '<p class="relay-actions">';
		if ( '' !== $url ) {
			echo '<a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Voir le suivi', 'relay-delivery' ) . '</a> ';
		}
		if ( ! $is_test ) {
			self::button( 'refresh', __( 'Actualiser', 'relay-delivery' ), 'button', false );
		}
		echo '</p>';
		if ( in_array( $status, Relay_Delivery_Deliveries::PRE_PICKUP, true ) ) {
			self::button( 'cancel', __( 'Annuler la livraison', 'relay-delivery' ), 'button-link relay-cancel' );
			echo '<p class="relay-muted">' . esc_html__( 'Gratuit tant que le colis n\'est pas récupéré.', 'relay-delivery' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Action button (handled in admin.js).
	 *
	 * @param string $action create|cancel|refresh.
	 * @param string $label  Label.
	 * @param string $classes CSS classes.
	 * @param bool   $wrap   Wrap in <p>.
	 */
	private static function button( $action, $label, $classes, $wrap = true ) {
		$html = '<button type="button" class="' . esc_attr( $class ) . ' relay-action" data-relay-action="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $wrap ? '<p>' . $html . '</p>' : $html;
	}

	/**
	 * Ajax: create / cancel / refresh.
	 */
	public static function ajax() {
		check_ajax_referer( 'relay_delivery_admin', 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$action   = isset( $_POST['relay_action'] ) ? sanitize_key( wp_unslash( $_POST['relay_action'] ) ) : '';
		if ( ! $order_id || ! current_user_can( 'edit_shop_orders' ) || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'relay-delivery' ) ), 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'relay-delivery' ) ), 404 );
		}
		switch ( $action ) {
			case 'create':
				if ( Relay_Delivery_Deliveries::has_live_delivery( $order ) ) {
					wp_send_json_success( array( 'message' => __( 'La livraison existe déjà.', 'relay-delivery' ) ) );
				}
				$res = Relay_Delivery_Deliveries::create( $order, 'manual' );
				break;
			case 'cancel':
				$res = Relay_Delivery_Deliveries::cancel( $order );
				break;
			case 'refresh':
				$res = Relay_Delivery_Deliveries::refresh( $order );
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'Action inconnue.', 'relay-delivery' ) ), 400 );
		}
		if ( empty( $res['ok'] ) ) {
			wp_send_json_error( array( 'message' => Relay_Delivery_Deliveries::error_text( $res ) ) );
		}
		wp_send_json_success();
	}
}
