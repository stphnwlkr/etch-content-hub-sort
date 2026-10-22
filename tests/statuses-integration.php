<?php
/** Run with Studio/WP-CLI: wp eval-file /absolute/path/to/this/file.php */
$check = static function ( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	echo "PASS: {$message}\n";
};
$request = static function ( string $method, string $route, array $params ) {
	$request = new WP_REST_Request( $method, $route );
	$request->set_query_params( $params );
	$request->set_body_params( $params );
	return rest_do_request( $request );
};
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$original_user = get_current_user_id();
$post_id = 0;
$user_id = 0;
$filter = static function ( $statuses, $type, $bulk, $can_publish ) {
	if ( 'page' === $type ) {
		$statuses['hub-review'] = 'Ready for review';
		unset( $statuses['pending'] );
		$statuses['trash'] = 'Invalid trash choice';
		$statuses['nonexistent'] = 'Invalid unregistered choice';
	}
	return $statuses;
};
try {
	wp_set_current_user( $admin->ID );
	register_post_status( 'hub-review', array( 'label' => 'Editorial review', 'protected' => true, 'show_in_admin_status_list' => true ) );
	register_post_status( 'hub-public', array( 'label' => 'Public workflow', 'public' => true ) );
	register_post_status( 'hub-internal', array( 'label' => 'Hidden internal', 'internal' => true, 'show_in_admin_status_list' => false ) );
	$options = etch_hub_sort_quick_edit_statuses( get_post_type_object( 'page' ) );
	$check( ! array_diff( array( 'publish', 'future', 'pending', 'draft', 'private', 'hub-review' ), array_keys( $options ) ), 'core and registered custom statuses available' );
	$check( 'Pending Review' === $options['pending'] && ! isset( $options['hub-internal'] ) && ! isset( $options['trash'] ), 'core Quick Edit label and internal exclusions' );
	add_filter( 'quick_edit_statuses', $filter, 10, 4 );
	$options = etch_hub_sort_quick_edit_statuses( get_post_type_object( 'page' ) );
	$check( 'Ready for review' === $options['hub-review'] && ! isset( $options['pending'] ) && ! isset( $options['trash'] ) && ! isset( $options['nonexistent'] ), 'third-party Quick Edit labels and removals honored; invalid states rejected' );
	$post_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Disposable Hub status integration test', 'post_status' => 'draft', 'post_author' => $admin->ID ) );
	$check( $post_id > 0, 'disposable test page created' );
	foreach ( array( 'hub-review', 'private', 'pending', 'draft', 'future', 'publish' ) as $status ) {
		remove_filter( 'quick_edit_statuses', $filter, 10 );
		$params = array( 'type' => 'page', 'status' => $status, 'parent' => 0, 'date' => wp_date( 'Y-m-d\TH:i', time() + ( 'future' === $status ? DAY_IN_SECONDS : -DAY_IN_SECONDS ) ) );
		$saved = $request( 'PATCH', '/etch-hub-sort/v1/posts/' . $post_id, $params );
		$check( 200 === $saved->get_status() && $status === get_post_status( $post_id ) && $status === $saved->get_data()['status'], "Quick edit saves {$status} and reports persisted status" );
		$list = $request( 'GET', '/etch-hub-sort/v1/posts', array( 'type' => 'page', 'search' => 'Disposable Hub status integration test' ) );
		$check( in_array( $post_id, wp_list_pluck( $list->get_data()['items'], 'id' ), true ), "{$status} remains visible in list" );
	}
	foreach ( array( 'trash', 'auto-draft', 'inherit', 'hub-internal', 'nonexistent' ) as $status ) {
		$denied = $request( 'PATCH', '/etch-hub-sort/v1/posts/' . $post_id, array( 'type' => 'page', 'status' => $status ) );
		$check( 403 === $denied->get_status() && 'publish' === get_post_status( $post_id ), "reject {$status} without changing post" );
	}
	$user_id = wp_insert_user( array( 'user_login' => 'hub_status_test_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$check( ! is_wp_error( $user_id ), 'temporary restricted editor created' );
	$user = new WP_User( $user_id );
	$user->add_cap( 'publish_pages', false );
	$user->add_cap( 'read_private_pages', false );
	wp_set_current_user( $user_id );
	$options = etch_hub_sort_quick_edit_statuses( get_post_type_object( 'page' ) );
	$check( ! array_intersect( array( 'publish', 'future', 'private', 'hub-public' ), array_keys( $options ) ), 'publishing choices excluded for restricted editor' );
	wp_set_current_user( $admin->ID );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
	wp_set_current_user( $user_id );
	foreach ( array( 'publish', 'future', 'private', 'hub-public' ) as $status ) {
		$denied = $request( 'PATCH', '/etch-hub-sort/v1/posts/' . $post_id, array( 'type' => 'page', 'status' => $status ) );
		$check( 403 === $denied->get_status() && 'draft' === get_post_status( $post_id ), "server denies restricted transition to {$status}" );
	}
	wp_set_current_user( $admin->ID );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'private' ) );
	wp_set_current_user( $user_id );
	$list = $request( 'GET', '/etch-hub-sort/v1/posts', array( 'type' => 'page', 'search' => 'Disposable Hub status integration test' ) );
	$check( ! in_array( $post_id, wp_list_pluck( $list->get_data()['items'], 'id' ), true ), 'private content hidden without read-private permission' );
} finally {
	wp_set_current_user( $admin->ID );
	remove_filter( 'quick_edit_statuses', $filter, 10 );
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $user_id && ! is_wp_error( $user_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
	foreach ( array( 'hub-review', 'hub-public', 'hub-internal' ) as $status ) {
		unset( $GLOBALS['wp_post_statuses'][ $status ] );
	}
	wp_set_current_user( $original_user );
}
