<?php
/** Run with WP-CLI eval-file on an isolated site with Etch and this plugin active. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
ob_start();
$original_user = get_current_user_id();
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$users = array(); $posts = array(); $terms = array();
$assert = static function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo "PASS: $message\n"; };
$call = static function ( $method, $route, $params = array() ) {
 $r = new WP_REST_Request( $method, '/etch-hub-sort/v1/' . $route );
 $r->set_query_params( $params ); $r->set_body_params( $params ); return rest_do_request( $r );
};
try {
 wp_set_current_user( $admin );
 $post = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Security fixture', 'post_status' => 'draft' ) ); $posts[] = $post;
 $term = wp_insert_term( 'Security fixture ' . wp_generate_password( 6, false ), 'category' ); $terms[] = $term['term_id'];
 $subscriber = wp_insert_user( array( 'user_login' => 'ce-security-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) ); $users[] = $subscriber;
 $routes = array(
  array( 'POST', 'settings', array( 'hide_native_content_hub' => true ) ),
  array( 'GET', 'settings/transfer', array() ),
  array( 'POST', 'settings/transfer', array( 'document' => array( 'invalid' => true ) ) ),
  array( 'GET', 'posts', array( 'type' => 'page' ) ),
  array( 'POST', 'posts/create', array( 'type' => 'page', 'title' => 'Denied', 'status' => 'draft' ) ),
  array( 'POST', 'posts/' . $post, array( 'type' => 'page', 'title' => 'Denied', 'status' => 'draft' ) ),
  array( 'DELETE', 'posts/' . $post, array( 'type' => 'page' ) ),
  array( 'GET', 'terms', array( 'type' => 'category' ) ),
  array( 'POST', 'terms/create', array( 'type' => 'category', 'name' => 'Denied' ) ),
  array( 'POST', 'terms/' . $term['term_id'], array( 'type' => 'category', 'name' => 'Denied' ) ),
  array( 'DELETE', 'terms/' . $term['term_id'], array( 'type' => 'category' ) ),
  array( 'GET', 'connections/' . $post, array() ),
  array( 'GET', 'connections/site', array() ),
  array( 'GET', 'connections/terms', array() ),
 );
 foreach ( array( 0, $subscriber ) as $user ) {
  wp_set_current_user( $user );
  foreach ( $routes as [ $method, $route, $params ] ) {
   $result = $call( $method, $route, $params );
   $assert( ( $user ? 403 : 401 ) === $result->get_status(), ( $user ? 'subscriber' : 'anonymous' ) . " denied $method $route" );
  }
 }
 wp_set_current_user( $admin );
 foreach ( array( array( 'posts', array( 'type' => 'page', 'sort' => 'ID; DROP TABLE posts' ) ), array( 'posts', array( 'type' => 'page', 'per_page' => 10000 ) ), array( 'connections/site', array( 'after' => '-1 OR 1=1' ) ), array( 'connections/terms', array( 'after' => -1 ) ) ) as [ $route, $params ] ) {
  $assert( 400 === $call( 'GET', $route, $params )->get_status(), "invalid query rejected: $route" );
 }
 $parent = wp_insert_term( 'Security parent ' . wp_generate_password( 6, false ), 'category' ); $terms[] = $parent['term_id'];
 $child = wp_insert_term( 'Security child ' . wp_generate_password( 6, false ), 'category', array( 'parent' => $parent['term_id'] ) ); $terms[] = $child['term_id'];
 $assert( 400 === $call( 'POST', 'terms/' . $parent['term_id'], array( 'type' => 'category', 'name' => 'Parent', 'parent' => $child['term_id'] ) )->get_status(), 'taxonomy parent cycles rejected' );
 $assert( 'Security fixture' === get_post( $post )->post_title && 'draft' === get_post_status( $post ), 'denied mutations leave the item unchanged' );
 // Exercise WordPress cookie authentication used by the plugin's nonce-bearing requests.
 $GLOBALS['wp_rest_auth_cookie'] = true;
 unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'] );
 rest_cookie_check_errors( null );
 $assert( 0 === get_current_user_id(), 'missing REST nonce removes cookie-authenticated identity' );
 wp_set_current_user( $admin ); $_SERVER['HTTP_X_WP_NONCE'] = 'invalid';
 $result = rest_cookie_check_errors( null );
 $assert( is_wp_error( $result ) && 'rest_cookie_invalid_nonce' === $result->get_error_code(), 'invalid REST nonce rejected' );
 wp_set_current_user( $admin ); $_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
 $assert( true === rest_cookie_check_errors( null ), 'valid REST nonce accepted' );
} finally {
 unset( $_SERVER['HTTP_X_WP_NONCE'] );
 wp_set_current_user( $admin );
 foreach ( array_reverse( $terms ) as $id ) { wp_delete_term( $id, 'category' ); }
 foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
 require_once ABSPATH . 'wp-admin/includes/user.php';
 foreach ( $users as $id ) { wp_delete_user( $id ); }
 wp_set_current_user( $original_user );
 ob_end_flush();
}
