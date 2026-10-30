<?php
/**
 * Plain-PHP test of the plugin's API routing (no WordPress needed): php tests/test-sandbox-routing.php
 *
 * - base(): rb_sbx_ keys always go to the API sandbox, whatever api_base says; other keys use api_base.
 * - request(): the URL actually called, the Bearer header, and the parsed answer.
 * - verify(): X-Relay-Signature check with a whsec_ secret (the sandbox format).
 * Optional live part: SBX_KEY_FILE=<json file with {"key": "rb_sbx_..."}> SBX_LOCAL=https://relaysandbox.didavie.com
 * also sends the calls for real (SBX_LOCAL may point to another copy of the sandbox).
 */

define( 'ABSPATH', __DIR__ );
define( 'RELAY_DELIVERY_VERSION', 'test' );
$GLOBALS['opts']  = array();
$GLOBALS['calls'] = array();

function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, $a ); }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function __( $s, $d = null ) { return $s; }
function home_url( $p = '' ) { return 'https://shop.example' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
class WP_Error { public $m; public function __construct( $m = '' ) { $this->m = $m; } public function get_error_message() { return $this->m; } }

function wp_remote_request( $url, $params ) {
	$GLOBALS['calls'][] = array( 'url' => $url, 'auth' => $params['headers']['Authorization'] ?? '' );
	$local = getenv( 'SBX_LOCAL' );
	if ( ! $local ) {
		return array( 'code' => 200, 'body' => '{"success":true,"data":{"ok":1}}' );
	}
	$real = str_replace( 'https://relaysandbox.didavie.com', rtrim( $local, '/' ), $url );
	$ch   = curl_init( $real );
	$h    = array();
	foreach ( $params['headers'] as $k => $v ) { $h[] = "$k: $v"; }
	curl_setopt_array( $ch, array( CURLOPT_CUSTOMREQUEST => $params['method'], CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20 ) );
	if ( isset( $params['body'] ) ) { curl_setopt( $ch, CURLOPT_POSTFIELDS, $params['body'] ); }
	$body = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	return false === $body ? new WP_Error( curl_error( $ch ) ) : array( 'code' => $code, 'body' => $body );
}

require __DIR__ . '/../relay-delivery/includes/class-relay-settings.php';
require __DIR__ . '/../relay-delivery/includes/class-relay-api.php';
// The webhook class registers REST routes on load; only its static verify() is needed here.
if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
require __DIR__ . '/../relay-delivery/includes/class-relay-webhook.php';

$fail = 0;
function check( $name, $ok, $info = '' ) { global $fail; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : "  -> $info" ) . "\n"; if ( ! $ok ) { $fail++; } }

$sbx  = 'rb_sbx_' . str_repeat( 'a', 48 );
$live = 'rb_live_' . str_repeat( 'b', 48 );
$test = 'rb_test_' . str_repeat( 'c', 48 );

check( 'sandbox key -> sandbox (default base)', Relay_Delivery_API::base( $sbx ) === 'https://relaysandbox.didavie.com/api/v1' );
$GLOBALS['opts']['relay_delivery_settings'] = array( 'api_base' => 'https://api.example.test/api/v1' );
check( 'sandbox key -> sandbox even with a custom api_base', Relay_Delivery_API::base( $sbx ) === 'https://relaysandbox.didavie.com/api/v1' );
check( 'test key -> custom api_base', Relay_Delivery_API::base( $test ) === 'https://api.example.test/api/v1' );
$GLOBALS['opts']['relay_delivery_settings'] = array();
check( 'live key -> production', Relay_Delivery_API::base( $live ) === 'https://relaypartner.didavie.com/api/v1' );
$GLOBALS['opts']['relay_delivery_settings'] = array( 'api_key' => $sbx );
check( 'saved sandbox key used when no key passed', Relay_Delivery_API::base() === 'https://relaysandbox.didavie.com/api/v1' );

$GLOBALS['calls'] = array();
Relay_Delivery_API::request( 'GET', '/me', null, array( 'api_key' => $sbx ) );
check( 'request() calls the sandbox URL', $GLOBALS['calls'][0]['url'] === 'https://relaysandbox.didavie.com/api/v1/me', $GLOBALS['calls'][0]['url'] ?? '' );
check( 'request() sends the key as Bearer', $GLOBALS['calls'][0]['auth'] === 'Bearer ' . $sbx );

$secret = 'whsec_' . str_repeat( '1f', 24 );
$body   = '{"id":"evt_1","event":"package.picked_up","data":{"tracking_code":"SB123456"}}';
$t      = time();
$sig    = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret );
check( 'webhook signature (sandbox whsec_ secret) accepted', Relay_Delivery_Webhook::verify( $sig, $body, $secret, $t ) );
check( 'tampered body refused', ! Relay_Delivery_Webhook::verify( $sig, $body . ' ', $secret, $t ) );

// Address sent to Relay: the state name, not the WooCommerce state code (BJ "LI" -> "Littoral").
if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return (object) array( 'countries' => new class() { public function get_states( $c ) { return 'BJ' === $c ? array( 'LI' => 'Littoral', 'OU' => 'Ou&eacute;m&eacute;' ) : array(); } } );
	}
}
require_once __DIR__ . '/../relay-delivery/includes/class-relay-deliveries.php';
check( 'state code -> name (BJ LI)', 'Littoral' === Relay_Delivery_Deliveries::state_name( 'BJ', 'LI' ) );
check( 'state name decoded (BJ OU)', 'Ouémé' === Relay_Delivery_Deliveries::state_name( 'BJ', 'OU' ) );
check( 'unknown state kept as typed', 'Atlantique Sud' === Relay_Delivery_Deliveries::state_name( 'BJ', 'Atlantique Sud' ) );
check( 'no states for country: kept', 'CA' === Relay_Delivery_Deliveries::state_name( 'XX', 'CA' ) );
check( 'empty state stays empty', '' === Relay_Delivery_Deliveries::state_name( 'BJ', '' ) );

if ( getenv( 'SBX_LOCAL' ) && getenv( 'SBX_KEY_FILE' ) ) {
	$key = json_decode( file_get_contents( getenv( 'SBX_KEY_FILE' ) ), true )['key'];
	$GLOBALS['opts']['relay_delivery_settings'] = array( 'api_key' => $key );
	$me = Relay_Delivery_API::request( 'GET', '/me' );
	check( 'live: GET /me through the plugin client', $me['ok'] && ! empty( $me['data']['sandbox'] ) && 'test' === $me['data']['mode'], json_encode( $me ) );
	$est = Relay_Delivery_API::request( 'GET', '/pricing/estimate?delivery_address=' . rawurlencode( 'Fidjrosse, Cotonou' ) );
	check( 'live: estimate by address', $est['ok'] && $est['data']['price_fcfa'] > 0, json_encode( $est ) );
	$oid = 'WC-PHP-' . bin2hex( random_bytes( 3 ) );
	$cr  = Relay_Delivery_API::request( 'POST', '/packages', array( 'platform_order_id' => $oid, 'quote_id' => $est['data']['quote_id'], 'delivery_latitude' => $est['data']['delivery_latitude'], 'delivery_longitude' => $est['data']['delivery_longitude'], 'client_name' => 'Plugin test', 'client_phone' => '+2290196000003', 'client_address' => 'Fidjrosse, Cotonou' ) );
	check( 'live: create delivery', $cr['ok'] && 201 === $cr['status'] && 0 === strpos( $cr['data']['tracking_code'], 'SB' ), json_encode( $cr ) );
	$cx = Relay_Delivery_API::request( 'PATCH', '/packages/' . $cr['data']['tracking_code'] . '/cancel', array( 'reason' => 'php test' ) );
	check( 'live: cancel delivery', $cx['ok'] && 'cancelled' === $cx['data']['status'], json_encode( $cx ) );
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
