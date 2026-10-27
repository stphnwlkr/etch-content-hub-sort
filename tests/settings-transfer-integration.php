<?php
/** WP-CLI eval-file integration checks. Restores every plugin option afterward. */
$original_user = get_current_user_id();
$keys = array(
	'enabled', 'show_sidebar_icon', 'show_wordpress_edit', 'default_view', 'actions_layout', 'actions_first',
	'default_column', 'default_order', 'term_default_column', 'term_default_order', 'active_bg', 'active_text',
	'button_styles', 'status_styles', 'hierarchy_style',
);
$snapshot = array();
foreach ( $keys as $key ) {
	$snapshot[ $key ] = get_option( 'etch_hub_sort_' . $key, null );
}
$check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: {$message}\n";
};
$request = static function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_body_params( $params );
	return rest_do_request( $request );
};
try {
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
	$export = $request( 'GET', '/etch-hub-sort/v1/settings/transfer' );
	$document = $export->get_data();
	$check( 200 === $export->get_status() && 1 === $document['format_version'] && 'etch-content-hub-sort' === $document['plugin'], 'export uses a versioned plugin format' );
	$check( 14 === count( $document['settings'] ) && count( etch_hub_sort_button_defaults() ) === count( $document['settings']['button_styles'] ) && 6 === count( $document['settings']['status_styles'] ), 'export contains general, sorting, button, hierarchy, and status settings' );
	$check( ! str_contains( wp_json_encode( $document ), 'nonce' ) && ! str_contains( wp_json_encode( $document ), home_url() ), 'export contains no site URLs or nonces' );
	// Create a source configuration distinct from the destination for every group.
	$document['settings']['enabled'] = false;
	$document['settings']['show_sidebar_icon'] = false;
	$document['settings']['show_wordpress_edit'] = false;
	$document['settings']['default_view'] = 'etch';
	$document['settings']['actions_layout'] = 'split';
	$document['settings']['default_column'] = 'status';
	$document['settings']['default_order'] = 'asc';
	$document['settings']['term_default_column'] = 'count';
	$document['settings']['term_default_order'] = 'desc';
	$document['settings']['active_bg'] = '#102030';
	$document['settings']['active_text'] = '#ffffff';
	$document['settings']['button_styles']['new'] = array( 'background' => '#102030', 'text' => '#ffffff', 'display' => 'both', 'custom_colors' => true );
	$document['settings']['status_styles']['custom'] = array( 'background' => '#102030', 'text' => '#ffffff' );
	$document['settings']['hierarchy_style']['border_width'] = 5;
	$import = $request( 'POST', '/etch-hub-sort/v1/settings/transfer', array( 'document' => $document ) );
	$check( 200 === $import->get_status() && ! empty( $import->get_data()['imported'] ), 'complete export imports successfully' );
	$roundtrip = $request( 'GET', '/etch-hub-sort/v1/settings/transfer' )->get_data();
	$check( $roundtrip['settings'] === $document['settings'], 're-export exactly matches all imported settings' );
	$before = etch_hub_sort_export_settings();
	$invalids = array();
	$bad = $document;
	$bad['format_version'] = 99;
	$invalids[] = $bad;
	$bad = $document;
	$bad['plugin'] = 'unrelated-plugin';
	$invalids[] = $bad;
	$bad = $document;
	$bad['settings']['unrelated_option'] = 'do not write';
	$invalids[] = $bad;
	$bad = $document;
	unset( $bad['settings']['status_styles']['private'] );
	$invalids[] = $bad;
	$bad = $document;
	$bad['settings']['button_styles']['new']['display'] = 'unsupported';
	$invalids[] = $bad;
	$bad = $document;
	$bad['settings']['enabled'] = true;
	$bad['settings']['hierarchy_style']['parent_bg'] = '#ffffff';
	$bad['settings']['hierarchy_style']['parent_text'] = '#ffffff';
	$invalids[] = $bad;
	$bad = $document;
	$bad['settings']['status_styles']['custom']['background'] = '#ffffff';
	$invalids[] = $bad;
	foreach ( $invalids as $bad ) {
		$response = $request( 'POST', '/etch-hub-sort/v1/settings/transfer', array( 'document' => $bad ) );
		$check( $response->get_status() >= 400 && $before === etch_hub_sort_export_settings(), 'invalid import rejected before any settings change' );
	}
	wp_set_current_user( 0 );
	foreach ( array( 'GET', 'POST' ) as $method ) {
		$response = $request( $method, '/etch-hub-sort/v1/settings/transfer', array( 'document' => $document ) );
		$check( in_array( $response->get_status(), array( 401, 403 ), true ), "unauthorized {$method} denied" );
	}
} finally {
	foreach ( $snapshot as $key => $value ) {
		if ( null === $value ) {
			delete_option( 'etch_hub_sort_' . $key );
		} else {
			update_option( 'etch_hub_sort_' . $key, $value );
		}
	}
	wp_set_current_user( $original_user );
}
