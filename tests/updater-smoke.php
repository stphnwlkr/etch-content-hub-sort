<?php
/** Run with php tests/updater-smoke.php; no WordPress installation required. */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$hooks    = array();
$cache    = array();
$response = array();
$requests = 0;

function add_filter( $name, $callback ) {
	global $hooks;
	$hooks[ $name ] = $callback;
}
function get_site_transient( $key ) {
	global $cache;
	return $cache[ $key ] ?? false;
}
function set_site_transient( $key, $value, $expiry ) {
	global $cache;
	$cache[ $key ] = $value;
}
function wp_safe_remote_get( $url, $args ) {
	global $response, $requests;
	++$requests;
	assert( 'https://api.github.com/repos/stphnwlkr/etch-content-hub-sort/releases/latest' === $url );
	assert( $args['timeout'] <= 5 );
	return $response;
}
function is_wp_error( $value ) {
	return false;
}
function wp_remote_retrieve_response_code( $value ) {
	return $value['code'];
}
function wp_remote_retrieve_body( $value ) {
	return $value['body'];
}
function plugin_basename( $path ) {
	return basename( dirname( $path ) ) . '/' . basename( $path );
}
function esc_html( $value ) {
	return htmlspecialchars( $value, ENT_QUOTES );
}
function wpautop( $value ) {
	return '<p>' . $value . '</p>';
}

require dirname( __DIR__ ) . '/includes/github-updates.php';

function assert_same( $expected, $actual ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

$release = array(
	'tag_name'   => 'v1.7.7',
	'draft'      => false,
	'prerelease' => false,
	'body'       => 'Release notes <script>alert(1)</script>',
	'assets'     => array( array( 'name' => 'etch-content-hub-sort-1.7.7.zip', 'state' => 'uploaded' ) ),
);
$response = array( 'code' => 200, 'body' => json_encode( $release ) );
$update   = $hooks['update_plugins_github.com']( false, array( 'Version' => '1.7.6' ), 'etch-content-hub-sort/etch-content-hub-sort.php' );
assert_same( '1.7.7', $update['version'] );
assert_same( 'https://github.com/stphnwlkr/etch-content-hub-sort/releases/download/v1.7.7/etch-content-hub-sort-1.7.7.zip', $update['package'] );
assert_same( 1, $requests );
$hooks['update_plugins_github.com']( false, array(), 'other/other.php' );
assert_same( 1, $requests );
$hooks['update_plugins_github.com']( false, array(), 'etch-content-hub-sort/etch-content-hub-sort.php' );
assert_same( 1, $requests );
$details = $hooks['plugins_api']( false, 'plugin_information', (object) array( 'slug' => 'etch-content-hub-sort' ) );
assert_same( '1.7.7', $details->version );
assert_same( false, str_contains( $details->sections['changelog'], '<script>' ) );

foreach ( array( 'prerelease', 'missing_asset', 'wrong_tag' ) as $case ) {
	$cache = array();
	$bad   = $release;
	if ( 'prerelease' === $case ) {
		$bad['prerelease'] = true;
	} elseif ( 'missing_asset' === $case ) {
		$bad['assets'][0]['name'] = 'source.zip';
	} else {
		$bad['tag_name'] = 'v1.7.7-beta';
	}
	$response = array( 'code' => 200, 'body' => json_encode( $bad ) );
	assert_same( false, etch_hub_sort_latest_release() );
}

echo "Updater smoke checks passed.\n";
