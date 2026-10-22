<?php
/** Run with WP-CLI eval-file against the test site. Restores the saved option. */
$original_user = get_current_user_id();
$original = get_option( 'etch_hub_sort_status_styles', null );
$check = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: {$message}\n";
};
$save = static function ( $style ) {
	$request = new WP_REST_Request( 'POST', '/etch-hub-sort/v1/settings' );
	$request->set_body_params( array( 'status_style' => $style ) );
	return rest_do_request( $request );
};
try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	wp_set_current_user( $admin->ID );
	delete_option( 'etch_hub_sort_status_styles' );
	$defaults = etch_hub_sort_status_defaults();
	$check( $defaults === etch_hub_sort_status_styles(), 'existing colors are the defaults' );
	foreach ( array_keys( $defaults ) as $group ) {
		$result = $save( array( 'group' => $group, 'background' => '#102030', 'text' => '#FFFFFF' ) );
		$check( 200 === $result->get_status(), "save {$group} colors" );
		$style = etch_hub_sort_status_styles()[ $group ];
		$check( '#102030' === $style['background'] && '#ffffff' === $style['text'] && '#ffffff' === $style['picker_text'], "{$group} persists and updates picker colors" );
		$reset = $save( array( 'group' => $group, 'background' => $defaults[ $group ]['background'], 'text' => $defaults[ $group ]['text'] ) );
		$check( 200 === $reset->get_status() && $defaults[ $group ] === etch_hub_sort_status_styles()[ $group ], "{$group} resets to original badge and picker colors" );
	}
	$before = get_option( 'etch_hub_sort_status_styles' );
	foreach ( array(
		array( 'group' => 'unknown', 'background' => '#102030', 'text' => '#ffffff' ),
		array( 'group' => 'custom', 'background' => 'invalid', 'text' => '#ffffff' ),
		array( 'group' => 'custom', 'background' => '#ffffff', 'text' => '#ffffff' ),
	) as $invalid ) {
		$result = $save( $invalid );
		$check( 400 === $result->get_status() && $before === get_option( 'etch_hub_sort_status_styles' ), 'invalid colors rejected without changing settings' );
	}
	wp_set_current_user( 0 );
	$denied = $save( array( 'group' => 'custom', 'background' => '#102030', 'text' => '#ffffff' ) );
	$check( in_array( $denied->get_status(), array( 401, 403 ), true ), 'anonymous settings changes denied' );
	wp_set_current_user( $admin->ID );
	update_option( 'etch_hub_sort_status_styles', array( 'custom' => array( 'background' => 'broken', 'text' => '#ffffff' ) ) );
	$check( $defaults === etch_hub_sort_status_styles(), 'corrupt saved colors fall back to defaults' );
} finally {
	if ( null === $original ) {
		delete_option( 'etch_hub_sort_status_styles' );
	} else {
		update_option( 'etch_hub_sort_status_styles', $original );
	}
	wp_set_current_user( $original_user );
}
