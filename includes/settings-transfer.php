<?php
/** Portable settings only. Never transfer credentials, content, or update caches. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function etch_hub_sort_export_settings(): array {
	$colors = etch_hub_sort_heading_colors();
	$statuses = array();
	foreach ( etch_hub_sort_status_styles() as $group => $style ) {
		$statuses[ $group ] = array_intersect_key( $style, array_flip( array( 'background', 'text' ) ) );
	}
	return array(
		'enabled' => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
		'show_sidebar_icon' => '0' !== get_option( 'etch_hub_sort_show_sidebar_icon', '1' ),
		'show_wordpress_edit' => '0' !== get_option( 'etch_hub_sort_show_wordpress_edit', '1' ),
		'default_view' => etch_hub_sort_default_view(),
		'actions_layout' => etch_hub_sort_actions_layout(),
		'default_column' => etch_hub_sort_default_column(),
		'default_order' => etch_hub_sort_default_order(),
		'term_default_column' => etch_hub_sort_term_default_column(),
		'term_default_order' => etch_hub_sort_term_default_order(),
		'active_bg' => $colors[0],
		'active_text' => $colors[1],
		'button_styles' => etch_hub_sort_button_styles(),
		'status_styles' => $statuses,
		'hierarchy_style' => etch_hub_sort_hierarchy_style(),
	);
}

function etch_hub_sort_transfer_keys_match( array $values, array $expected ): bool {
	return ! array_diff_key( $values, $expected ) && ! array_diff_key( $expected, $values );
}

/** Build requests for the same validators used by the settings interface. */
function etch_hub_sort_transfer_requests( array $document ) {
	$invalid = new WP_Error( 'etch_hub_sort_invalid_transfer', __( 'Choose a complete Etch Content Hub Sort settings export in a supported format.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
	if ( ( $document['plugin'] ?? null ) !== 'etch-content-hub-sort' || ( $document['format_version'] ?? null ) !== 1 || ! isset( $document['settings'] ) || ! is_array( $document['settings'] ) ) {
		return $invalid;
	}
	$settings = $document['settings'];
	$expected = etch_hub_sort_export_settings();
	if ( ! etch_hub_sort_transfer_keys_match( $settings, $expected ) ) {
		return $invalid;
	}
	$requests = array();
	foreach ( array( 'button_styles' => 'button_style', 'status_styles' => 'status_style' ) as $plural => $singular ) {
		if ( ! is_array( $settings[ $plural ] ) || ! etch_hub_sort_transfer_keys_match( $settings[ $plural ], $expected[ $plural ] ) ) {
			return $invalid;
		}
		foreach ( $settings[ $plural ] as $group => $style ) {
			if ( ! is_array( $style ) || ! etch_hub_sort_transfer_keys_match( $style, $expected[ $plural ][ $group ] ) ) {
				return $invalid;
			}
			$requests[] = array( $singular => array_merge( $style, array( 'group' => $group ) ) );
		}
		unset( $settings[ $plural ] );
	}
	if ( ! is_array( $settings['hierarchy_style'] ) || ! etch_hub_sort_transfer_keys_match( $settings['hierarchy_style'], $expected['hierarchy_style'] ) ) {
		return $invalid;
	}
	$requests[] = $settings;
	return $requests;
}

add_action( 'rest_api_init', static function (): void {
	if ( ! defined( 'ETCH_PLUGIN_FILE' ) ) {
		return;
	}
	register_rest_route( 'etch-hub-sort/v1', '/settings/transfer', array(
		array(
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
			'callback' => static function () {
				$header = get_file_data( dirname( __DIR__ ) . '/etch-content-hub-sort.php', array( 'version' => 'Version' ) );
				$response = rest_ensure_response( array(
					'plugin' => 'etch-content-hub-sort',
					'format_version' => 1,
					'plugin_version' => $header['version'],
					'settings' => etch_hub_sort_export_settings(),
				) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
		),
		array(
			'methods' => WP_REST_Server::CREATABLE,
			'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
			'args' => array( 'document' => array( 'type' => 'object', 'required' => true ) ),
			'callback' => static function ( WP_REST_Request $request ) {
				$document = $request->get_param( 'document' );
				if ( strlen( wp_json_encode( $document ) ) > 262144 ) {
					return new WP_Error( 'etch_hub_sort_large_transfer', __( 'Settings files must be smaller than 256 KB.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
				}
				$payloads = etch_hub_sort_transfer_requests( $document );
				if ( is_wp_error( $payloads ) ) {
					return $payloads;
				}
				// Validate every group before the first setting is changed.
				foreach ( $payloads as $payload ) {
					$validation = new WP_REST_Request( 'POST', '/etch-hub-sort/v1/settings' );
					$validation->set_body_params( array_merge( $payload, array( 'validate_only' => true ) ) );
					$result = rest_do_request( $validation );
					if ( $result->get_status() >= 400 ) {
						return $result;
					}
				}
				foreach ( $payloads as $payload ) {
					$update = new WP_REST_Request( 'POST', '/etch-hub-sort/v1/settings' );
					$update->set_body_params( $payload );
					$result = rest_do_request( $update );
					if ( $result->get_status() >= 400 ) {
						return $result;
					}
				}
				return rest_ensure_response( array( 'imported' => true ) );
			},
		),
	) );
}, 20 );
