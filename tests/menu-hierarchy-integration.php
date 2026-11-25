<?php
/** Menu and hierarchy regression checks. Run on an isolated WordPress database. */
$original_user = get_current_user_id();
$ids = array(); $menus = array(); $users = array();
$check = static function ( $pass, $message ): void { if ( ! $pass ) { throw new RuntimeException( $message ); } echo "PASS: {$message}\n"; };
$create = static function ( $title, $content = '', $type = 'page', $parent = 0 ) use ( &$ids ): int {
 $id = wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_content' => wp_slash( $content ), 'post_parent' => $parent, 'post_status' => 'publish' ), true );
 if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
 $ids[] = $id; return $id;
};
$map = static function ( $id ): array {
 for ( $i = 0; $i < 200; $i++ ) {
  $request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/' . $id ); $request['id'] = $id;
  $response = Etch_Hub_Connections::connections( $request );
  if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); }
  $data = $response->get_data();
  if ( empty( $data['indexing'] ) && empty( $data['relationships_indexing'] ) ) { return $data; }
 }
 throw new RuntimeException( 'Connection indexing did not complete.' );
};
$has = static fn( $data, $group, $id ): bool => in_array( $id, array_column( $data[ $group ] ?? array(), 'id' ), true );
try {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
 $parent = $create( 'Connection audit parent' );
 $child = $create( 'Connection audit child', '', 'page', $parent );
 $other = $create( 'Connection audit other parent' );
 $check( $has( $map( $parent ), 'children', $child ), 'parent page lists its direct child without a hyperlink' );
 $check( $has( $map( $child ), 'parents', $parent ), 'child page lists its parent without a hyperlink' );
 $check( ! $has( $map( $parent ), 'to', $child ), 'structural children are not misreported as hyperlinks' );
 wp_update_post( array( 'ID' => $child, 'post_parent' => $other ) );
 $check( ! $has( $map( $parent ), 'children', $child ) && $has( $map( $other ), 'children', $child ), 'reparenting removes the old connection and adds the new one immediately' );
 $check( ! $has( $map( $child ), 'parents', $parent ) && $has( $map( $child ), 'parents', $other ), 'child map follows the new parent immediately' );
 $target = $create( 'Connection audit menu destination' );
 $block_menu = $create( 'Connection audit block menu', '<!-- wp:navigation-link {"id":' . $target . ',"kind":"post-type","type":"page","label":"Destination"} /-->', 'wp_navigation' );
 $header = $create( 'Connection audit header', '<!-- wp:navigation {"ref":' . $block_menu . '} /-->', 'wp_block' );
 $page = $create( 'Connection audit menu page', '<!-- wp:etch/component {"ref":' . $header . '} /-->' );
 $check( $has( $map( $block_menu ), 'to', $target ), 'navigation-link block resolves a post ID even when URL is omitted' );
 $check( $has( $map( $page ), 'to', $target ), 'page inherits menu links through nested Etch components and navigation references' );
 $check( $has( $map( $target ), 'from', $page ), 'menu destination lists the page using its shared navigation' );
 $check( $has( $map( $target ), 'from', $block_menu ), 'menu destination also lists the navigation resource containing its link' );
 $request = new WP_REST_Request( 'GET', '/etch-hub-sort/v1/connections/site' ); $request->set_query_params( array( 'after' => $parent - 1 ) );
 $site = Etch_Hub_Connections::site( $request )->get_data();
 $site_nodes = array_column( $site['nodes'], null, 'id' );
 $check( isset( $site_nodes[ $child ] ) && $site_nodes[ $child ]['parent'] === $other, 'Site map retains the saved page hierarchy' );
 $check( (bool) array_filter( $site['edges'], static fn( $edge ) => $edge['from'] === $page && $edge['to'] === $target && 'link' === $edge['kind'] ), 'Site map includes inherited navigation links between public pages' );

 // A shared-resource cycle must not cause duplicate connections or recursion.
 wp_update_post( array( 'ID' => $block_menu, 'post_content' => wp_slash( get_post( $block_menu )->post_content . '<!-- wp:block {"ref":' . $header . '} /-->' ) ) );
 $cycle = $map( $page );
 $check( $has( $cycle, 'to', $target ) && count( array_column( $cycle['to'], 'id' ) ) === count( array_unique( array_column( $cycle['to'], 'id' ) ) ), 'cyclic shared navigation terminates and does not duplicate links' );
 $menu = wp_create_nav_menu( 'Connection audit classic ' . wp_generate_password( 6, false ) );
 if ( is_wp_error( $menu ) ) { throw new RuntimeException( $menu->get_error_message() ); }
 $menus[] = $menu;
 $item = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $target, 'menu-item-status' => 'publish' ) ); $ids[] = $item;
 $custom = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'custom', 'menu-item-title' => 'Custom local', 'menu-item-url' => get_permalink( $child ), 'menu-item-status' => 'publish', 'menu-item-parent-id' => $item ) ); $ids[] = $custom;
 $external = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-type' => 'custom', 'menu-item-title' => 'External', 'menu-item-url' => 'https://outside.example/?page_id=' . $target, 'menu-item-status' => 'publish' ) ); $ids[] = $external;
 $check( $has( $map( $item ), 'to', $target ), 'classic menu post-type item connects to its linked page' );
 $check( $has( $map( $target ), 'from', $item ), 'linked page lists its classic menu entry as an incoming connection' );
 $check( $has( $map( $custom ), 'to', $child ), 'custom URL menu item resolves a local page' );
 $check( ! $has( $map( $target ), 'from', $external ), 'external menu URLs never become local page connections' );
 $node = Etch_Hub_Connections::node( $item );
 $check( str_contains( $node['title'], 'Connection audit classic' ) && str_contains( $node['title'], 'destination' ) && str_contains( $node['edit_url'], 'nav-menus.php' ), 'classic menu entry identifies its menu and opens the menu editor' );
 wp_update_nav_menu_item( $menu, $item, array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $other, 'menu-item-status' => 'publish' ) );
 $check( ! $has( $map( $target ), 'from', $item ) && $has( $map( $other ), 'from', $item ), 'changing a menu destination updates reverse connections' );
 wp_delete_nav_menu( $menu );
 $check( ! $has( $map( $other ), 'from', $item ), 'deleting a classic menu removes its incoming page connection' );
 wp_trash_post( $child );
 $check( ! $has( $map( $other ), 'children', $child ), 'trashed child pages are excluded from hierarchy connections' );
 wp_update_post( array( 'ID' => $header, 'post_content' => '' ) );
 $check( ! $has( $map( $page ), 'to', $target ) && ! $has( $map( $target ), 'from', $page ), 'removing shared navigation clears inherited connections in both directions' );
 // Permission filtering applies to hierarchy and inherited shared navigation too.
 $limited = wp_insert_user( array( 'user_login' => 'ce-menu-audit-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'author' ) ); $users[] = $limited;
 $user = get_user_by( 'id', $limited ); $user->add_cap( 'edit_pages' ); $user->add_cap( 'edit_published_pages' );
 wp_update_post( array( 'ID' => $child, 'post_status' => 'publish', 'post_author' => $limited ) );
 wp_update_post( array( 'ID' => $page, 'post_author' => $limited ) );
 wp_update_post( array( 'ID' => $target, 'post_author' => $limited ) );
 wp_update_post( array( 'ID' => $header, 'post_status' => 'private', 'post_content' => '<a href="' . get_permalink( $target ) . '">Hidden navigation</a>' ) );
 wp_set_current_user( $limited );
 $check( ! $has( $map( $child ), 'parents', $other ), 'inaccessible parents are omitted from child maps' );
 $check( ! $has( $map( $page ), 'to', $target ) && ! $has( $map( $target ), 'from', $page ), 'inaccessible shared navigation cannot leak inherited links in either direction' );

} finally {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
 foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
 foreach ( $menus as $id ) { wp_delete_nav_menu( $id ); }
 require_once ABSPATH . 'wp-admin/includes/user.php';
 foreach ( $users as $id ) { wp_delete_user( $id ); }
 wp_set_current_user( $original_user );
}
