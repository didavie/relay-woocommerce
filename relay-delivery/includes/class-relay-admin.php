<?php
/**
 * WooCommerce > Réglages > Livraison > Relay.
 *
 * @package RelayDelivery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen + connection test.
 */
class Relay_Delivery_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'section' ) );
		add_action( 'woocommerce_settings_shipping', array( __CLASS__, 'render' ), 5 );
		add_action( 'woocommerce_settings_save_shipping', array( __CLASS__, 'save' ), 5 );
		add_action( 'wp_ajax_relay_delivery_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_item_meta' ) );
	}

	/**
	 * Current screen is our section.
	 *
	 * @return bool
	 */
	private static function is_section() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		return isset( $_GET['tab'], $_GET['section'] ) && 'shipping' === $_GET['tab'] && 'relay' === $_GET['section'];
	}

	/**
	 * Section tab.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public static function section( $sections ) {
		$sections['relay'] = __( 'Relay', 'relay-delivery' );
		return $sections;
	}

	/**
	 * Admin CSS/JS (settings + order screens only).
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_order = $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $is_order && ! ( 'woocommerce_page_wc-settings' === $hook && self::is_section() ) ) {
			return;
		}
		wp_enqueue_style( 'relay-delivery-admin', RELAY_DELIVERY_URL . 'assets/admin.css', array(), RELAY_DELIVERY_VERSION );
		wp_enqueue_script( 'relay-delivery-admin', RELAY_DELIVERY_URL . 'assets/admin.js', array(), RELAY_DELIVERY_VERSION, true );
		wp_localize_script(
			'relay-delivery-admin',
			'RelayDelivery',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'relay_delivery_admin' ),
				'i18n'  => array(
					'testing'       => __( 'Test en cours…', 'relay-delivery' ),
					'working'       => __( 'Un instant…', 'relay-delivery' ),
					'confirmCancel' => __( 'Annuler la livraison Relay ? C\'est gratuit tant que le colis n\'est pas récupéré.', 'relay-delivery' ),
					'error'         => __( 'Erreur, réessayez.', 'relay-delivery' ),
				),
			)
		);
	}

	/**
	 * Hide our technical shipping-line meta in the order items table.
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public static function hidden_item_meta( $keys ) {
		return array_merge( $keys, array( 'relay_lat', 'relay_lng', 'relay_quote_id', 'relay_price_type', 'relay_price' ) );
	}

	/**
	 * Render the section.
	 */
	public static function render() {
		if ( ! self::is_section() ) {
			return;
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce settings flag: keep its Save button.
		$GLOBALS['hide_save_button'] = false;

		$s       = Relay_Delivery_Settings::all();
		$state   = Relay_Delivery_Settings::state();
		$shop    = isset( $state['shop'] ) && is_array( $state['shop'] ) ? $state['shop'] : null;
		$last    = isset( $state['last_call'] ) && is_array( $state['last_call'] ) ? $state['last_call'] : null;
		$hook    = rest_url( 'relay-delivery/v1/webhook' );
		$log_url = admin_url( 'admin.php?page=wc-status&tab=logs&source=relay-delivery' );
		$radio   = static function ( $name, $value, $current, $label ) {
			printf(
				'<label class="relay-radio"><input type="radio" name="relay[%1$s]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		};
		$check   = static function ( $name, $current, $label, $help = '' ) {
			printf(
				'<label class="relay-check"><input type="checkbox" name="relay[%1$s]" value="yes" %2$s> %3$s</label>%4$s',
				esc_attr( $name ),
				checked( $current, 'yes', false ),
				esc_html( $label ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		?>
		<div class="relay-settings">
			<h2 class="relay-title"><span class="relay-mark" aria-hidden="true"></span><?php esc_html_e( 'Livraison Relay', 'relay-delivery' ); ?></h2>
			<p class="relay-intro"><?php esc_html_e( 'Les livreurs Relay livrent vos commandes. La clé API se crée dans Relay Partenaires : Boutique > Connecter mon site.', 'relay-delivery' ); ?></p>
			<?php wp_nonce_field( 'relay_delivery_save', 'relay_delivery_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="relay-api-key"><?php esc_html_e( 'Clé API de la boutique', 'relay-delivery' ); ?></label></th>
					<td>
						<input type="password" id="relay-api-key" name="relay[api_key]" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( $s['api_key'] ? Relay_Delivery_Settings::mask( $s['api_key'] ) : 'rb_live_…' ); ?>">
						<button type="button" class="button" id="relay-test"><?php esc_html_e( 'Tester la connexion', 'relay-delivery' ); ?></button>
						<span id="relay-connection" class="relay-connection">
						<?php if ( $shop && $s['api_key'] ) : ?>
							<span class="relay-badge relay-badge--ok">
							<?php
							/* translators: %s: shop name */
							echo esc_html( sprintf( __( 'Connecté : %s', 'relay-delivery' ), $shop['name'] ) );
							?>
							</span>
							<?php if ( 'test' === $shop['mode'] ) : ?>
								<span class="relay-badge relay-badge--test"><?php esc_html_e( 'clé de test', 'relay-delivery' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
						</span>
						<p class="description"><?php esc_html_e( 'La clé enregistrée n\'est jamais réaffichée. Laissez vide pour la garder.', 'relay-delivery' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode test', 'relay-delivery' ); ?></th>
					<td><?php $check( 'test_mode', $s['test_mode'], __( 'Valider les livraisons sans les créer (aucun livreur appelé)', 'relay-delivery' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Création de la livraison', 'relay-delivery' ); ?></th>
					<td>
						<?php $radio( 'create_mode', 'auto', $s['create_mode'], __( 'Automatique quand la commande passe « En cours »', 'relay-delivery' ) ); ?><br>
						<?php $radio( 'create_mode', 'manual', $s['create_mode'], __( 'Manuelle, avec le bouton dans la commande', 'relay-delivery' ) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Paiement à la livraison', 'relay-delivery' ); ?></th>
					<td><?php $check( 'cod', $s['cod'], __( 'Le livreur encaisse le total de la commande (commandes « Paiement à la livraison »)', 'relay-delivery' ), __( 'Le montant est donné au livreur dans les instructions du colis.', 'relay-delivery' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="relay-type"><?php esc_html_e( 'Type de colis par défaut', 'relay-delivery' ); ?></label></th>
					<td>
						<select id="relay-type" name="relay[package_type]">
							<?php foreach ( Relay_Delivery_Settings::package_types() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['package_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Prix au paiement', 'relay-delivery' ); ?></th>
					<td>
						<?php $check( 'live_price', $s['live_price'], __( 'Prix Relay en direct selon l\'adresse du client', 'relay-delivery' ) ); ?>
						<p>
							<label for="relay-fallback"><?php esc_html_e( 'Prix fixe de secours', 'relay-delivery' ); ?></label>
							<input type="number" min="0" step="1" id="relay-fallback" name="relay[fallback_price]" class="small-text" value="<?php echo esc_attr( (string) (int) $s['fallback_price'] ); ?>"> F
						</p>
						<p class="description"><?php esc_html_e( 'Utilisé quand Relay ne répond pas ou quand le prix en direct est coupé.', 'relay-delivery' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Qui paie la livraison', 'relay-delivery' ); ?></th>
					<td>
						<?php $radio( 'payer', 'customer', $s['payer'], __( 'Le client, ajouté à sa commande', 'relay-delivery' ) ); ?><br>
						<?php $radio( 'payer', 'shop', $s['payer'], __( 'La boutique (livraison offerte)', 'relay-delivery' ) ); ?><br>
						<?php $radio( 'payer', 'recipient', $s['payer'], __( 'Le client paie le livreur à la livraison', 'relay-delivery' ) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'E-mail au client', 'relay-delivery' ); ?></th>
					<td><?php $check( 'tracking_email', $s['tracking_email'], __( 'Ajouter le lien de suivi Relay dans l\'e-mail de commande', 'relay-delivery' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="relay-whsec"><?php esc_html_e( 'Statuts de commande', 'relay-delivery' ); ?></label></th>
					<td>
						<p><?php esc_html_e( 'Relay met à jour vos commandes par des webhooks signés. Dans Relay Partners, collez cette adresse comme URL de webhook :', 'relay-delivery' ); ?></p>
						<p><code class="relay-code"><?php echo esc_html( $hook ); ?></code></p>
						<input type="password" id="relay-whsec" name="relay[webhook_secret]" class="regular-text code" autocomplete="off" value="" placeholder="<?php echo esc_attr( $s['webhook_secret'] ? Relay_Delivery_Settings::mask( $s['webhook_secret'], 10 ) : 'whsec_…' ); ?>">
						<p class="description"><?php esc_html_e( 'Secret du webhook (whsec_…), affiché une fois dans Relay Partners. Laissez vide pour le garder.', 'relay-delivery' ); ?></p>
						<p class="description"><?php esc_html_e( 'Livré : la commande passe « Terminée ». Problème ou retour : « En attente ». Récupéré et annulé : note dans la commande.', 'relay-delivery' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="relay-base"><?php esc_html_e( 'Adresse de l\'API', 'relay-delivery' ); ?></label></th>
					<td>
						<input type="url" id="relay-base" name="relay[api_base]" class="regular-text code" value="<?php echo esc_attr( $s['api_base'] ); ?>">
						<p class="description"><?php esc_html_e( 'À ne changer que si Relay vous le demande.', 'relay-delivery' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="relay-last">
				<?php
				if ( $last ) {
					printf(
						/* translators: 1: date, 2: request, 3: result */
						esc_html__( 'Dernier appel : %1$s, %2$s, %3$s.', 'relay-delivery' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $last['at'] ) ),
						esc_html( $last['method'] . ' ' . $last['path'] ),
						$last['ok'] ? '<span class="relay-ok">' . esc_html__( 'réussi', 'relay-delivery' ) . '</span>' : '<span class="relay-ko">' . esc_html( trim( $last['status'] . ' ' . $last['code'] ) ) . '</span>'
					);
				} else {
					esc_html_e( 'Dernier appel : aucun pour le moment.', 'relay-delivery' );
				}
				?>
				<a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Voir le journal', 'relay-delivery' ); ?></a>
			</p>
		</div>
		<?php
		// WooCommerce prints its own Save button and closes the form.
	}

	/**
	 * Save.
	 */
	public static function save() {
		if ( ! self::is_section() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_POST['relay_delivery_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['relay_delivery_nonce'] ) ), 'relay_delivery_save' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field below.
		$in              = isset( $_POST['relay'] ) && is_array( $_POST['relay'] ) ? wp_unslash( $_POST['relay'] ) : array();
		$prev            = Relay_Delivery_Settings::all();
		$out             = array(
			'test_mode'      => empty( $in['test_mode'] ) ? 'no' : 'yes',
			'cod'            => empty( $in['cod'] ) ? 'no' : 'yes',
			'live_price'     => empty( $in['live_price'] ) ? 'no' : 'yes',
			'tracking_email' => empty( $in['tracking_email'] ) ? 'no' : 'yes',
			'create_mode'    => isset( $in['create_mode'] ) && 'manual' === $in['create_mode'] ? 'manual' : 'auto',
			'payer'          => isset( $in['payer'] ) && in_array( $in['payer'], array( 'customer', 'shop', 'recipient' ), true ) ? $in['payer'] : 'customer',
			'fallback_price' => isset( $in['fallback_price'] ) ? max( 0, absint( $in['fallback_price'] ) ) : $prev['fallback_price'],
			'package_type'   => isset( $in['package_type'] ) && array_key_exists( $in['package_type'], Relay_Delivery_Settings::package_types() ) ? $in['package_type'] : 'standard',
		);
		$base            = isset( $in['api_base'] ) ? esc_url_raw( trim( (string) $in['api_base'] ), array( 'https' ) ) : '';
		$out['api_base'] = '' !== $base ? untrailingslashit( $base ) : Relay_Delivery_Settings::DEFAULT_BASE;

		$key = isset( $in['api_key'] ) ? trim( sanitize_text_field( (string) $in['api_key'] ) ) : '';
		if ( '' !== $key ) {
			if ( preg_match( '/^rb_(live|test|sbx)_[0-9a-f]{16,128}$/', $key ) ) {
				$out['api_key'] = $key;
				Relay_Delivery_Settings::set_state( array( 'shop' => null ) );
			} else {
				WC_Admin_Settings::add_error( __( 'Clé API non enregistrée : elle doit commencer par rb_live_, rb_test_ ou rb_sbx_ (sandbox API).', 'relay-delivery' ) );
			}
		}
		$secret = isset( $in['webhook_secret'] ) ? trim( sanitize_text_field( (string) $in['webhook_secret'] ) ) : '';
		if ( '' !== $secret ) {
			if ( preg_match( '/^whsec_[0-9A-Za-z]{16,128}$/', $secret ) ) {
				$out['webhook_secret'] = $secret;
			} else {
				WC_Admin_Settings::add_error( __( 'Secret du webhook non enregistré : il commence par whsec_.', 'relay-delivery' ) );
			}
		}
		Relay_Delivery_Settings::save( $out );
		if ( isset( $out['api_key'] ) ) {
			// New key: check it now so the « Connecté » badge is right after saving.
			$res = Relay_Delivery_API::me();
			if ( $res['ok'] ) {
				Relay_Delivery_Settings::set_state(
					array(
						'shop' => array(
							'name' => isset( $res['data']['shop']['name'] ) ? sanitize_text_field( $res['data']['shop']['name'] ) : '',
							'mode' => isset( $res['data']['mode'] ) && 'test' === $res['data']['mode'] ? 'test' : 'live',
						),
					)
				);
			} else {
				WC_Admin_Settings::add_error( Relay_Delivery_Deliveries::error_text( $res ) );
			}
		}
		// Prices depend on these settings: drop cached quotes.
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	/**
	 * « Tester la connexion »: GET /me with the typed key (or the saved one).
	 */
	public static function ajax_test() {
		check_ajax_referer( 'relay_delivery_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'relay-delivery' ) ), 403 );
		}
		$typed = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		$res   = Relay_Delivery_API::me( '' !== $typed ? $typed : null );
		if ( ! $res['ok'] ) {
			wp_send_json_error( array( 'message' => Relay_Delivery_Deliveries::error_text( $res ) ) );
		}
		$shop = array(
			'name' => isset( $res['data']['shop']['name'] ) ? sanitize_text_field( $res['data']['shop']['name'] ) : '',
			'mode' => isset( $res['data']['mode'] ) && 'test' === $res['data']['mode'] ? 'test' : 'live',
		);
		if ( '' === $typed ) {
			Relay_Delivery_Settings::set_state( array( 'shop' => $shop ) );
		}
		wp_send_json_success(
			array(
				/* translators: %s: shop name */
				'label' => sprintf( __( 'Connecté : %s', 'relay-delivery' ), $shop['name'] ),
				'test'  => 'test' === $shop['mode'] ? __( 'clé de test', 'relay-delivery' ) : '',
				'saved' => '' === $typed,
			)
		);
	}
}
