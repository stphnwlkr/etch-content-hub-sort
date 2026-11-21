<?php
/** WP-CLI integration checks for map taxonomy data and default-view persistence. */
$user = get_current_user_id();
$previous = get_option( 'etch_hub_sort_panel_default_view', null );
$previous_hide = get_option( 'etch_hub_sort_hide_native_content_hub', null );
$post_id = 0; $term_id = 0;
$check = static function ( $pass, $message ) { if ( ! $pass ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; };
$call = static function ( $method, $route, $params = array() ) { $r = new WP_REST_Request( $method, $route ); if ( 'GET' === $method ) { $r->set_query_params( $params ); } else { $r->set_body_params( $params ); } return rest_do_request( $r ); };
try {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
 foreach ( array( true, false ) as $hidden ) {
  $r = $call( 'POST', '/etch-hub-sort/v1/settings', array( 'hide_native_content_hub' => $hidden ) );
  $check( 200 === $r->get_status() && $hidden === $r->get_data()['hide_native_content_hub'], 'native Content Hub visibility saves and returns its boolean state' );
 }
 foreach ( array( 'list', 'page', 'site' ) as $view ) {
  $response = $call( 'POST', '/etch-hub-sort/v1/settings', array( 'panel_default_view' => $view ) );
  $check( 200 === $response->get_status() && $view === get_option( 'etch_hub_sort_panel_default_view' ), 'default view persists: ' . $view );
 }
 $check( 400 === $call( 'POST', '/etch-hub-sort/v1/settings', array( 'panel_default_view' => 'invalid' ) )->get_status() && 'site' === get_option( 'etch_hub_sort_panel_default_view' ), 'invalid default rejected without changing preference' );
 $document = $call( 'GET', '/etch-hub-sort/v1/settings/transfer' )->get_data();
 unset( $document['settings']['panel_default_view'], $document['settings']['hide_native_content_hub'] );
 $check( 200 === $call( 'POST', '/etch-hub-sort/v1/settings/transfer', array( 'document' => $document ) )->get_status() && 'site' === get_option( 'etch_hub_sort_panel_default_view' ), 'older settings exports remain compatible and preserve default view' );
 $term = wp_insert_term( 'Map taxonomy fixture ' . wp_generate_password( 6, false ), 'category' );
 $term_id = $term['term_id'];
 $term_list = $call( 'GET', '/etch-hub-sort/v1/terms', array( 'type' => 'category', 'search' => 'Map taxonomy fixture' ) );
 $check( 200 === $term_list->get_status() && in_array( $term_id, array_column( $term_list->get_data()['items'], 'id' ), true ), 'taxonomy List endpoint returns editable term records without post editor fields' );
 $post_id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Taxonomy graph fixture', 'post_status' => 'publish' ) );
 wp_set_post_terms( $post_id, array( $term_id ), 'category' );
 $terms = $call( 'GET', '/etch-hub-sort/v1/connections/terms' )->get_data();
 $check( in_array( 'term:' . $term_id, array_column( $terms['nodes'], 'id' ), true ), 'term node is loaded with correct taxonomy group' );
 for ( $i = 0; $i < 20; $i++ ) { $site = $call( 'GET', '/etch-hub-sort/v1/connections/site', array( 'after' => $post_id - 1 ) )->get_data(); if ( ! $site['indexing'] ) { break; } }
 $check( isset( $site['taxonomies']['category'] ) && in_array( 'post', $site['taxonomies']['category']['types'], true ), 'taxonomy hubs identify associated post types' );
 $check( (bool) array_filter( $site['term_edges'], static fn( $edge ) => $edge['source'] === 'post:' . $post_id && $edge['target'] === 'term:' . $term_id ), 'post-to-term membership edges are returned' );
 wp_set_current_user( 0 );
 $check( 401 === $call( 'GET', '/etch-hub-sort/v1/connections/terms' )->get_status(), 'anonymous users cannot inspect taxonomy graph' );
} finally {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
 if ( $post_id ) { wp_delete_post( $post_id, true ); }
 if ( $term_id ) { wp_delete_term( $term_id, 'category' ); }
 if ( null === $previous ) { delete_option( 'etch_hub_sort_panel_default_view' ); } else { update_option( 'etch_hub_sort_panel_default_view', $previous ); }
 if ( null === $previous_hide ) { delete_option( 'etch_hub_sort_hide_native_content_hub' ); } else { update_option( 'etch_hub_sort_hide_native_content_hub', $previous_hide ); }
 wp_set_current_user( $user );
}
