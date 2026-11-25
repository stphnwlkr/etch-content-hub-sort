<?php
/** Run with WP-CLI eval-file on an isolated test site. Creates and removes fixtures. */
$original_user = get_current_user_id();
$ids = array(); $users = array(); $registered_types = array();
$original_editor_access = get_option( 'etch_hub_sort_editor_access', null );
$original_wordpress_edit = get_option( 'etch_hub_sort_show_wordpress_edit', null );
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
	$formatted_title = Etch_Hub_Connections::title_data( '<em>Artist</em> &#8211; Rock &amp; Roll &#x1F3B5;' );
	$assert( 'Artist – Rock & Roll 🎵' === $formatted_title['title'], 'map labels decode numeric, named and hexadecimal entities' );
	$assert( str_contains( $formatted_title['title_html'], '<em>Artist</em>' ), 'connection titles retain safe inline formatting' );
	$safe_title = Etch_Hub_Connections::title_data( '<a href="javascript:alert(1)"><strong onclick="alert(1)">Rock</strong></a><img src=x onerror=alert(1)>' );
	$assert( '<strong>Rock</strong>' === $safe_title['title_html'], 'formatted titles exclude links, images and event attributes' );
	delete_option( 'etch_hub_sort_editor_access' );
	$target = $create( 'page', 'Map destination' );
	$target_node = Etch_Hub_Connections::node( $target );
	$assert( get_post( $target )->post_date === $target_node['created'] && get_the_author_meta( 'display_name', get_current_user_id() ) === $target_node['author'], 'map records include the creation date and author for connection sorting' );
	$check_etch_url = Etch_Hub_Connections::node( $target )['etch_edit_url'];
	$assert( str_contains( $check_etch_url, 'etch=magic' ) && str_contains( $check_etch_url, 'post_id=' . $target ), 'editable pages offer the official Etch builder URL' );
	$editable_list = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/posts' );
	$editable_list->set_query_params( array( 'type' => 'page', 'search' => 'Map destination' ) );
	$editable_items = rest_do_request( $editable_list )->get_data()['items'];
	$assert( (bool) array_filter( $editable_items, static fn( $item ) => $item['id'] === $target && ! empty( $item['etch_edit_url'] ) ), 'List includes Etch edit eligibility for supported records' );
	$set_editors = static function ( $access, $show_wordpress ) {
		$request = new WP_REST_Request( 'POST', '/etch-hub-sort/v1/settings' );
		$request->set_body_params( array( 'editor_access' => $access, 'show_wordpress_edit' => $show_wordpress ) );
		return rest_do_request( $request )->get_status();
	};
	foreach ( array( 'wordpress' => array( false, true ), 'etch' => array( true, false ), 'both' => array( true, true ) ) as $mode => list( $etch, $wordpress ) ) {
		$assert( 200 === $set_editors( array( 'page' => $mode ), false ), "editor settings accept {$mode}" );
		$list = rest_do_request( $editable_list )->get_data();
		$item = array_values( array_filter( $list['items'], static fn( $item ) => $item['id'] === $target ) )[0];
		$map = Etch_Hub_Connections::node( $target );
		$assert( $etch === (bool) $item['etch_edit_url'] && $etch === $list['can_etch_edit'] && $wordpress === (bool) $item['wordpress_edit_url'] && $item['can_edit'], "List filters {$mode} editor shortcuts and preserves Quick edit" );
		$assert( $etch === (bool) $map['etch_edit_url'] && $wordpress === (bool) $map['edit_url'], "map nodes filter {$mode} editor shortcuts" );
	}
	$assert( 400 === $set_editors( array( 'wp_block' => 'wordpress' ), false ), 'shared editor mode cannot be overridden' );
	$assert( 200 === $set_editors( array(), false ), 'post types without saved overrides use the starting editor choices' );
	$list = rest_do_request( $editable_list )->get_data();
	$item = array_values( array_filter( $list['items'], static fn( $item ) => $item['id'] === $target ) )[0];
	$page_node = Etch_Hub_Connections::node( $target );
	$editor_types = array_column( etch_hub_sort_editor_types(), null, 'name' );
	$assert( 'both' === $editor_types['page']['mode'] && $item['wordpress_edit_url'] && $item['etch_edit_url'] && $page_node['edit_url'] && $page_node['etch_edit_url'], 'Editors, List, and Map agree on both shortcuts for an unconfigured page when the term toggle is off' );
	$assert( 'wordpress' === $editor_types['wp_navigation']['mode'] && 'both' === $editor_types['wp_block']['mode'], 'Editors reports the active fixed choices for navigation and shared patterns' );
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
	foreach ( array( 'wp_block' => $part, 'wp_template_part' => $template, 'wp_template' => $create( 'wp_template', 'Map template' ) ) as $shared_type => $shared_id ) {
		$node = Etch_Hub_Connections::node( $shared_id );
		$shared_request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/posts' );
		$shared_request->set_query_params( array( 'type' => $shared_type ) );
		$shared_list = rest_do_request( $shared_request )->get_data();
		$shared_item = array_values( array_filter( $shared_list['items'], static fn( $item ) => $item['id'] === $shared_id ) )[0];
		$assert( $node['etch_edit_url'] && $node['edit_url'] && $shared_item['etch_edit_url'] && $shared_item['wordpress_edit_url'], "{$shared_type} keeps both editors in List and maps with the legacy toggle off" );
	}
	$markup = '<!-- wp:etch/element {"tag":"a","attributes":{"href":"' . get_permalink( $target ) . '"}} /-->' .
		'<!-- wp:etch/component {"ref":' . $part . '} /-->' .
		'<!-- wp:template-part {"slug":"map-footer"} /-->' .
		'<a href="https://elsewhere.test/?page_id=' . $target . '">External</a>' .
		'<a href="#section">Anchor</a><a href="{props.url}">Dynamic</a>';
	$source = $create( 'page', 'Map source', $markup );
	$editor_fixture_ids = array();
	foreach ( array( 'articles', 'events' ) as $type ) {
		if ( ! post_type_exists( $type ) ) {
			register_post_type( $type, array( 'public' => true, 'show_in_rest' => true ) );
			$registered_types[] = $type;
		}
		$editor_fixture_ids[ $type ] = $create( $type, 'Map editor fixture ' . $type );
	}
	$assert( 200 === $set_editors( array( 'page' => 'both', 'articles' => 'wordpress', 'events' => 'wordpress' ), false ), 'different post types can save different editor choices together' );
	foreach ( $editor_fixture_ids as $type => $id ) {
		$request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/posts' );
		$request->set_query_params( array( 'type' => $type, 'search' => 'Map editor fixture ' . $type ) );
		$items = array_column( rest_do_request( $request )->get_data()['items'], null, 'id' );
		$assert( ! empty( $items[ $id ]['wordpress_edit_url'] ) && empty( $items[ $id ]['etch_edit_url'] ), "List shows only WordPress for {$type}" );
		$map = rest_do_request( new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/' . $id ) )->get_data();
		while ( ! empty( $map['indexing'] ) ) {
			$map = rest_do_request( new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/' . $id ) )->get_data();
		}
		$assert( ! empty( $map['center']['edit_url'] ) && empty( $map['center']['etch_edit_url'] ), "item map shows only WordPress for {$type}" );
	}
	$extracted = Etch_Hub_Connections::extract( $markup );
	$assert( array( $target ) === $extracted['links'], 'Etch attribute links resolve; external, anchor and dynamic links are ignored' );
	$assert( in_array( $part, $extracted['uses'], true ) && in_array( $template, $extracted['uses'], true ), 'components and saved theme parts resolve to real IDs' );
	$response = $connections( $source )->get_data();
	while ( $response['indexing'] ) { $response = $connections( $source )->get_data(); }
	$assert( in_array( $target, array_column( $response['to'], 'id' ), true ), 'outgoing links appear in page map' );
	$assert( in_array( $part, array_column( $response['uses'], 'id' ), true ), 'shared parts appear in Uses' );
	$assert( in_array( $source, array_column( $connections( $target )->get_data()['from'], 'id' ), true ), 'reverse links appear in Linked from' );
	$assert( in_array( $source, array_column( $connections( $part )->get_data()['used_by'], 'id' ), true ), 'reverse component references appear in Used by' );
	$assert( 3 === (int) get_option( 'etch_hub_map_index_version' ), 'map index migrates to the menu-aware schema' );
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
	$site_by_id = array_column( $site_nodes, null, 'id' );
	foreach ( $editor_fixture_ids as $type => $id ) {
		$assert( ! empty( $site_by_id[ $id ]['edit_url'] ) && empty( $site_by_id[ $id ]['etch_edit_url'] ), "site map shows only WordPress for {$type}" );
	}
	foreach ( array( $target, $part, $template ) as $id ) {
		$assert( ! empty( $site_by_id[ $id ]['edit_url'] ) && ! empty( $site_by_id[ $id ]['etch_edit_url'] ), 'both shortcuts remain available for configured pages and shared resources in the same site map' );
	}
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
	foreach ( array( 'etch_hub_sort_editor_access' => $original_editor_access, 'etch_hub_sort_show_wordpress_edit' => $original_wordpress_edit ) as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
	wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
	foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
	foreach ( $registered_types as $type ) { unregister_post_type( $type ); }
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user ) { wp_delete_user( $user ); }
	wp_set_current_user( $original_user );
}
