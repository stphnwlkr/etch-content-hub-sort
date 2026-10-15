<?php
/** GitHub Release updates for Etch Content Hub Sort. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read the latest published release. Only the named installable ZIP is accepted.
 * GitHub's source archives do not have the plugin's required directory layout.
 *
 * @return array<string, string>|false
 */
function etch_hub_sort_latest_release() {
	$cache_key = 'etch_hub_sort_github_release';
	$cached    = get_site_transient( $cache_key );
	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : false;
	}

	$response = wp_safe_remote_get(
		'https://api.github.com/repos/stphnwlkr/etch-content-hub-sort/releases/latest',
		array(
			'timeout'             => 5,
			'limit_response_size' => 131072,
			'headers'             => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'etch-content-hub-sort',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		set_site_transient( $cache_key, 'unavailable', 15 * MINUTE_IN_SECONDS );
		return false;
	}

	$release = json_decode( wp_remote_retrieve_body( $response ), true );
	$tag     = is_array( $release ) ? ( $release['tag_name'] ?? '' ) : '';
	if ( ! is_string( $tag ) || ! preg_match( '/^v?([0-9]+\.[0-9]+\.[0-9]+)$/', $tag, $match ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) || empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
		set_site_transient( $cache_key, 'unavailable', 15 * MINUTE_IN_SECONDS );
		return false;
	}

	$version  = $match[1];
	$zip_name = 'etch-content-hub-sort-' . $version . '.zip';
	foreach ( $release['assets'] as $asset ) {
		if ( is_array( $asset ) && ( $asset['name'] ?? null ) === $zip_name && ( $asset['state'] ?? null ) === 'uploaded' ) {
			$release_data = array(
				'version' => $version,
				'package' => 'https://github.com/stphnwlkr/etch-content-hub-sort/releases/download/' . rawurlencode( $tag ) . '/' . $zip_name,
				'url'     => 'https://github.com/stphnwlkr/etch-content-hub-sort/releases/tag/' . rawurlencode( $tag ),
				'notes'   => is_string( $release['body'] ?? null ) ? substr( $release['body'], 0, 10000 ) : '',
			);
			set_site_transient( $cache_key, $release_data, HOUR_IN_SECONDS );
			return $release_data;
		}
	}

	set_site_transient( $cache_key, 'unavailable', 15 * MINUTE_IN_SECONDS );
	return false;
}

/** Supply WordPress' native plugin updater with the published ZIP. */
add_filter(
	'update_plugins_github.com',
	static function ( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( dirname( __DIR__ ) . '/etch-content-hub-sort.php' ) !== $plugin_file ) {
			return $update;
		}

		$release = etch_hub_sort_latest_release();
		if ( ! $release ) {
			return false;
		}

		return array(
			'slug'         => 'etch-content-hub-sort',
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '5.9',
			'requires_php' => '8.1',
		);
	},
	10,
	3
);

/** Show the GitHub release in WordPress' plugin details dialog. */
add_filter(
	'plugins_api',
	static function ( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== 'etch-content-hub-sort' ) {
			return $result;
		}

		$release = etch_hub_sort_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Etch Content Hub Sort',
			'slug'          => 'etch-content-hub-sort',
			'version'       => $release['version'],
			'author'        => 'Stephen Walker',
			'homepage'      => 'https://github.com/stphnwlkr/etch-content-hub-sort',
			'download_link' => $release['package'],
			'requires'      => '5.9',
			'requires_php'  => '8.1',
			'sections'      => array(
				'description' => '<p>Search, sort, and manage content in the Etch Content Hub.</p>',
				'changelog'   => '' !== $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>See the GitHub release for changes.</p>',
			),
		);
	},
	10,
	3
);
