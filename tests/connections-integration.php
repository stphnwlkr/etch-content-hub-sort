<?php
/** Run with WP-CLI eval-file on an isolated test site. Creates and removes fixtures. */
$original_user = get_current_user_id();
$ids = array(); $users = array();
$assert = static function ( $condition, $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: $message\n";
};
$create = static function ( $type, $title, $content = '', $status = 'publish' ) use ( &$ids ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_content' => wp_slash( $content ), 'post_status' => $status, 'post_author' => get_current_user_id() ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$ids[] = $id; return $id;
};
$connections = static function ( $id ) {
	$request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/' . $id );
	$request['id'] = $id;
	return Etch_Hub_Connections::connections( $request );
};
try {
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
	$target = $create( 'page', 'Map destination' );
	$check_etch_url = Etch_Hub_Connections::node( $target )['etch_edit_url'];
	$assert( str_contains( $check_etch_url, 'etch=magic' ) && str_contains( $check_etch_url, 'post_id=' . $target ), 'editable pages offer the official Etch builder URL' );
	$editable_list = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/posts' );
	$editable_list->set_query_params( array( 'type' => 'page', 'search' => 'Map destination' ) );
	$editable_items = rest_do_request( $editable_list )->get_data()['items'];
	$assert( (bool) array_filter( $editable_items, static fn( $item ) => $item['id'] === $target && ! empty( $item['etch_edit_url'] ) ), 'List includes Etch edit eligibility for supported records' );
	$navigation = $create( 'wp_navigation', 'Map navigation' );
	$assert( '' === Etch_Hub_Connections::node( $navigation )['etch_edit_url'], 'navigation resources do not offer an Etch document editor' );
	$assert( '' === Etch_Hub_Connections::node( $navigation )['view_url'], 'navigation resources do not advertise a public page' );
	$part = $create( 'wp_block', 'Map component' );
	$assert( '' === Etch_Hub_Connections::node( $part )['view_url'] && '' === Etch_Hub_Connections::node( $part )['path'], 'shared patterns identify that they have no public URL' );
	$list_request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/posts' );
	$list_request->set_query_params( array( 'type' => 'wp_navigation' ) );
	$list_items = rest_do_request( $list_request )->get_data()['items'];
	$assert( ! array_filter( $list_items, static fn( $item ) => ! empty( $item['etch_edit_url'] ) ), 'List and Map consistently omit unsupported Etch edit actions' );
	$template = $create( 'wp_template_part', 'Map footer' );
	wp_update_post( array( 'ID' => $template, 'post_name' => 'map-footer' ) );
	wp_set_object_terms( $template, get_stylesheet(), 'wp_theme' );
	$markup = '<!-- wp:etch/element {"tag":"a","attributes":{"href":"' . get_permalink( $target ) . '"}} /-->' .
		'<!-- wp:etch/component {"ref":' . $part . '} /-->' .
		'<!-- wp:template-part {"slug":"map-footer"} /-->' .
		'<a href="https://elsewhere.test/?page_id=' . $target . '">External</a>' .
		'<a href="#section">Anchor</a><a href="{props.url}">Dynamic</a>';
	$source = $create( 'page', 'Map source', $markup );
	$extracted = Etch_Hub_Connections::extract( $markup );
	$assert( array( $target ) === $extracted['links'], 'Etch attribute links resolve; external, anchor and dynamic links are ignored' );
	$assert( in_array( $part, $extracted['uses'], true ) && in_array( $template, $extracted['uses'], true ), 'components and saved theme parts resolve to real IDs' );
	$response = $connections( $source )->get_data();
	while ( $response['indexing'] ) { $response = $connections( $source )->get_data(); }
	$assert( in_array( $target, array_column( $response['to'], 'id' ), true ), 'outgoing links appear in page map' );
	$assert( in_array( $part, array_column( $response['uses'], 'id' ), true ), 'shared parts appear in Uses' );
	$assert( in_array( $source, array_column( $connections( $target )->get_data()['from'], 'id' ), true ), 'reverse links appear in Linked from' );
	$assert( in_array( $source, array_column( $connections( $part )->get_data()['used_by'], 'id' ), true ), 'reverse component references appear in Used by' );
	$assert( 2 === (int) get_option( 'etch_hub_map_index_version' ), 'map index migrates to the template-aware schema' );
	// More than one site-map batch, including an edge whose target is in a later batch.
	$first = $create( 'page', 'Site scan first' );
	for ( $i = 0; $i < 101; $i++ ) { $create( 'page', 'Site scan item ' . $i ); }
	$last = $create( 'page', 'Site scan last' );
	wp_update_post( array( 'ID' => $first, 'post_content' => '<a href="' . get_permalink( $last ) . '">Later batch</a>' ) );
	$site_nodes = array(); $site_edges = array(); $after = 0; $batches = 0;
	for ( $attempt = 0; $attempt < 30; $attempt++ ) {
		$site_request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/site' );
		$site_request->set_query_params( array( 'after' => $after ) );
		$site_response = rest_do_request( $site_request );
		$assert( 200 === $site_response->get_status(), 'site endpoint accepts authorized editors' );
		$site = $site_response->get_data();
		if ( $site['indexing'] ) { continue; }
		$batches++; $site_nodes = array_merge( $site_nodes, $site['nodes'] ); $site_edges = array_merge( $site_edges, $site['edges'] );
		if ( $site['done'] ) { break; }
		$assert( $site['next_cursor'] > $after, 'site cursor advances between batches' );
		$after = $site['next_cursor'];
	}
	$assert( $site['done'] && $batches > 1, 'whole site loads across multiple batches' );
	$site_ids = array_column( $site_nodes, 'id' );
	$assert( count( $site_ids ) === count( array_unique( $site_ids ) ) && in_array( $first, $site_ids, true ) && in_array( $last, $site_ids, true ), 'site graph includes each eligible item once including unconnected items' );
	$assert( (bool) array_filter( $site_edges, static fn( $edge ) => $edge['from'] === $first && $edge['to'] === $last && 'link' === $edge['kind'] ), 'site graph preserves links across batch boundaries' );
	$assert( (bool) array_filter( $site_edges, static fn( $edge ) => $edge['from'] === $source && $edge['to'] === $part && 'uses' === $edge['kind'] ), 'site graph distinguishes shared-part usage edges' );
	wp_update_post( array( 'ID' => $source, 'post_content' => '' ) );
	$assert( array() === $connections( $source )->get_data()['to'], 'saving removed links clears the index' );
	wp_update_post( array( 'ID' => $source, 'post_content' => wp_slash( $markup ) ) );
	wp_trash_post( $source );
	$assert( ! in_array( $source, array_column( $connections( $target )->get_data()['from'], 'id' ), true ), 'trashed sources are excluded' );
	$editor = wp_insert_user( array( 'user_login' => 'map-editor-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$users[] = $editor;
	wp_set_current_user( $editor );
	$assert( '' === Etch_Hub_Connections::node( $target )['etch_edit_url'], 'WordPress editors without Etch builder access do not get its edit action' );
	$hidden_draft = $create( 'post', 'Private author draft', '', 'draft' );
	$author = wp_insert_user( array( 'user_login' => 'map-author-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	$users[] = $author; wp_set_current_user( $author );
	$author_post = $create( 'post', 'Author map document', '<a href="' . get_permalink( $target ) . '">Restricted target</a>' );
	update_option( 'etch_hub_map_cursor_v1', -1, false );
	$author_site = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/site' );
	$author_site->set_query_params( array( 'after' => $hidden_draft - 1 ) );
	$author_data = rest_do_request( $author_site )->get_data();
	$assert( in_array( $author_post, array_column( $author_data['nodes'], 'id' ), true ) && ! in_array( $hidden_draft, array_column( $author_data['nodes'], 'id' ), true ) && empty( $author_data['edges'] ), 'site graph hides inaccessible nodes and edge endpoints' );
	$subscriber = wp_insert_user( array( 'user_login' => 'map-subscriber-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$users[] = $subscriber;
	wp_set_current_user( $subscriber );
	$assert( null === Etch_Hub_Connections::node( $target ), 'non-editors cannot read node metadata' );
	$request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/' . $target );
	$assert( 403 === rest_do_request( $request )->get_status(), 'REST route rejects non-editors' );
	$assert( 403 === rest_do_request( new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/site' ) )->get_status(), 'site graph rejects non-editors' );
	wp_set_current_user( 0 );
	$assert( 401 === rest_do_request( $request )->get_status(), 'REST route rejects anonymous requests' );
} finally {
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user ) { wp_delete_user( $user ); }
	wp_set_current_user( $original_user );
}
