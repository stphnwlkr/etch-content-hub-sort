<?php
/** Saved-content connections for the Content Hub. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Etch_Hub_Connections {
	private const LINK = '_etch_hub_map_link';
	private const USES = '_etch_hub_map_uses';
	private const CURSOR = 'etch_hub_map_cursor_v1';

	public static function types(): array {
		return array_values( array_unique( array_merge( get_post_types( array( 'public' => true ) ), get_post_types( array( 'show_in_rest' => true ) ), array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'nav_menu_item' ) ) ) );
	}

	/** Resolve only literal local URLs. Dynamic expressions are deliberately excluded. */
	public static function target( string $url ): int {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( '' === $url || preg_match( '/[{}<>]/', $url ) || '#' === $url[0] ) { return 0; }
		$home = home_url( '/' );
		if ( str_starts_with( $url, '//' ) ) { $url = wp_parse_url( $home, PHP_URL_SCHEME ) . ':' . $url; }
		elseif ( '/' === $url[0] ) { $url = wp_parse_url( $home, PHP_URL_SCHEME ) . '://' . wp_parse_url( $home, PHP_URL_HOST ) . ( wp_parse_url( $home, PHP_URL_PORT ) ? ':' . wp_parse_url( $home, PHP_URL_PORT ) : '' ) . $url; }
		elseif ( ! preg_match( '#^https?://#i', $url ) ) { return 0; }
		if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ) ) { return 0; }
		return (int) url_to_postid( $url );
	}

	/** Read the block tree without executing blocks, loops, shortcodes or custom code. */
	public static function extract( string $content ): array {
		$links = array(); $uses = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$links, &$uses ): void {
			foreach ( $blocks as $block ) {
				$name = $block['blockName'] ?? ''; $attrs = $block['attrs'] ?? array();
				if ( in_array( $name, array( 'etch/component', 'core/block', 'core/navigation' ), true ) && ! empty( $attrs['ref'] ) ) { $uses[] = absint( $attrs['ref'] ); }
				if ( 'core/template-part' === $name && ! empty( $attrs['slug'] ) ) {
					$part = get_block_template( ( $attrs['theme'] ?? get_stylesheet() ) . '//' . $attrs['slug'], 'wp_template_part' );
					if ( $part && ! empty( $part->wp_id ) ) { $uses[] = (int) $part->wp_id; }
				}
				$read = static function ( $values ) use ( &$read, &$links ): void {
					foreach ( (array) $values as $key => $value ) {
						if ( is_array( $value ) ) { $read( $value ); }
						elseif ( is_string( $value ) && in_array( strtolower( (string) $key ), array( 'href', 'url' ), true ) ) { $links[] = self::target( $value ); }
					}
				};
				// Navigation blocks may store a post ID without a literal URL.
				if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && 'post-type' === ( $attrs['kind'] ?? '' ) ) {
					$target_id = absint( $attrs['id'] ?? 0 );
					$target = $target_id ? get_post( $target_id ) : null;
					if ( $target && $target->post_type === ( $attrs['type'] ?? '' ) ) { $links[] = $target->ID; }
				}
				$read( $attrs );
				$walk( $block['innerBlocks'] ?? array() );
			}
		};
		$walk( parse_blocks( $content ) );
		// Raw HTML and ordinary block markup also contain fixed links.
		if ( preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $content, $matches ) ) {
			foreach ( $matches[2] as $url ) { $links[] = self::target( $url ); }
		}
		return array( 'links' => array_values( array_unique( array_filter( $links ) ) ), 'uses' => array_values( array_unique( array_filter( $uses ) ) ) );
	}

	public static function index( int $id ): void {
		$post = get_post( $id );
		if ( ! $post || wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) || ! in_array( $post->post_type, self::types(), true ) ) { return; }
		$relations = self::extract( $post->post_content );
		if ( 'nav_menu_item' === $post->post_type ) {
			$kind = get_post_meta( $id, '_menu_item_type', true );
			if ( 'post_type' === $kind ) {
				$target_id = (int) get_post_meta( $id, '_menu_item_object_id', true );
				$target = $target_id > 0 ? get_post( $target_id ) : null;
				if ( $target && $target->post_type === get_post_meta( $id, '_menu_item_object', true ) ) { $relations['links'][] = $target->ID; }
			} elseif ( 'custom' === $kind ) { $relations['links'][] = self::target( (string) get_post_meta( $id, '_menu_item_url', true ) ); }
		}

		$template = self::template_for( $post );
		if ( $template ) { $relations['uses'][] = $template; }
		foreach ( array( self::LINK => 'links', self::USES => 'uses' ) as $key => $kind ) {
			delete_post_meta( $id, $key );
			foreach ( array_unique( array_diff( array_filter( $relations[ $kind ] ), array( $id ) ) ) as $target ) { add_post_meta( $id, $key, $target ); }
		}
	}

	/** Keyset batches keep large sites out of a single request. Safe to repeat after interruption. */
	private static function batch(): bool {
		global $wpdb;
		if ( 3 !== (int) get_option( 'etch_hub_map_index_version', 0 ) ) {
			delete_option( self::CURSOR );
			update_option( 'etch_hub_map_index_version', 3, false );
		}
		$cursor = (int) get_option( self::CURSOR, 0 );
		if ( -1 === $cursor ) { return true; }
		$lock = 'etch_hub_map_lock';
		if ( (int) get_option( $lock, 0 ) < time() - 60 ) { delete_option( $lock ); }
		if ( ! add_option( $lock, time(), '', false ) ) { return false; }
		try {
			$types = array_diff( self::types(), array( 'attachment', 'revision' ) );
			$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($placeholders) ORDER BY ID ASC LIMIT 50", array_merge( array( $cursor ), array_values( $types ) ) ) );
			foreach ( $ids as $id ) { self::index( (int) $id ); }
			$done = count( $ids ) < 50;
			update_option( self::CURSOR, $done ? -1 : (int) end( $ids ), false );
			return $done;
		} finally { delete_option( $lock ); }
	}

	/** Match Etch's builder access and document types; menus and media use other editors. */
	public static function supports_etch_type( string $name ): bool {
		$type = get_post_type_object( $name );
		$owned = array_map( 'sanitize_key', array_keys( (array) get_option( 'etch_cpts', array() ) ) );
		return ! in_array( $name, array( 'attachment', 'wp_navigation', 'nav_menu_item' ), true ) && ( in_array( $name, ETCH_HUB_SORT_SHARED_EDITOR_TYPES, true ) || ( $type && $type->public ) || in_array( $name, $owned, true ) );
	}

	public static function etch_edit_url( WP_Post $post ): string {
		if ( ! etch_hub_sort_can_open_etch( $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) ) { return ''; }
		return add_query_arg( array( 'etch' => 'magic', 'post_id' => $post->ID ), home_url( '/' ) );
	}

	/** Keep formatting separate from the plain text used by graph labels and sorting. */
	public static function title_data( string $title ): array {
		$title = html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$tags = array_fill_keys( array( 'em', 'strong', 'b', 'i', 'mark', 's', 'del', 'sub', 'sup', 'span' ), array() );
		return array(
			'title' => wp_strip_all_tags( $title ),
			'title_html' => wp_kses( $title, $tags ),
		);
	}

	public static function node( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) || ! current_user_can( 'edit_post', $id ) ) { return null; }
		$type = get_post_type_object( $post->post_type );
		$title = self::title_data( get_the_title( $id ) );
		$menu_url = '';
		if ( 'nav_menu_item' === $post->post_type ) {
			$menus = get_the_terms( $id, 'nav_menu' );
			if ( ! is_wp_error( $menus ) && $menus ) {
				$label = $title['title'];
				if ( '' === $label && 'post_type' === get_post_meta( $id, '_menu_item_type', true ) ) { $label = self::title_data( get_the_title( (int) get_post_meta( $id, '_menu_item_object_id', true ) ) )['title']; }
				$title = self::title_data( implode( ', ', wp_list_pluck( $menus, 'name' ) ) . ' · ' . ( $label ?: '#' . $id ) );
				$menu_url = current_user_can( 'edit_theme_options' ) ? admin_url( 'nav-menus.php?action=edit&menu=' . $menus[0]->term_id ) : '';
			}
		}

		return array( 'id' => $id, 'title' => $title['title'] ?: '#' . $id, 'title_html' => $title['title_html'], 'created' => $post->post_date, 'author' => html_entity_decode( wp_strip_all_tags( (string) get_the_author_meta( 'display_name', $post->post_author ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'post_type' => $post->post_type, 'type' => $type ? ( $type->labels->singular_name ?: $type->label ?: $post->post_type ) : $post->post_type, 'kind' => in_array( $post->post_type, array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'nav_menu_item' ), true ) ? 'shared' : 'page', 'status' => $post->post_status, 'path' => $type && is_post_type_viewable( $type ) ? wp_make_link_relative( (string) get_permalink( $id ) ) : '', 'edit_url' => 'nav_menu_item' === $post->post_type ? $menu_url : etch_hub_sort_wordpress_edit_url( $post ), 'etch_edit_url' => self::etch_edit_url( $post ), 'parent' => (int) $post->post_parent, 'public' => (bool) ( $type && $type->public ), 'type_label' => $type ? ( $type->labels->name ?: $post->post_type ) : $post->post_type, 'view_url' => is_post_publicly_viewable( $post ) ? (string) get_permalink( $post ) : '', 'type_manage_url' => $type && $type->show_ui ? admin_url( 'edit.php?post_type=' . $post->post_type ) : '', 'type_add_url' => $type && current_user_can( $type->cap->create_posts ) ? admin_url( 'post-new.php?post_type=' . $post->post_type ) : '', 'type_archive_url' => get_post_type_archive_link( $post->post_type ) ?: '', 'modified' => mysql2date( 'j M Y', $post->post_modified ), 'thumb' => get_the_post_thumbnail_url( $post, 'medium' ) ?: '' );
	}

	/** Paginated site inventory with permission-filtered edges, including disconnected content. */
	public static function site( WP_REST_Request $request ) {
		if ( ! self::batch() ) {
			return rest_ensure_response( array( 'indexing' => true, 'nodes' => array(), 'edges' => array(), 'next_cursor' => 0, 'done' => false ) );
		}
		global $wpdb;
		$after = absint( $request->get_param( 'after' ) );
		$types = array_values( array_diff( self::types(), array( 'attachment', 'revision', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT 100",
			array_merge( array( $after ), $types )
		) ) );
		_prime_post_caches( $ids, false, true );
		update_post_author_caches( array_filter( array_map( 'get_post', $ids ) ) );
		$nodes = array(); $edges = array(); $visible = array();
		foreach ( $ids as $id ) {
			$node = self::node( $id );
			if ( ! $node ) { continue; }
			$nodes[] = $node;
			$outgoing = $node['public'] ? self::outgoing( $id ) : null;
			foreach ( array( self::LINK => 'link', self::USES => 'uses' ) as $key => $kind ) {
				$targets = $outgoing ? $outgoing[ 'link' === $kind ? 'to' : 'uses' ] : array_map( 'intval', get_post_meta( $id, $key ) );
				foreach ( array_unique( $targets ) as $target ) {
					if ( ! array_key_exists( $target, $visible ) ) {
						$other = self::node( $target );
						$visible[ $target ] = $other && in_array( $other['post_type'], $types, true ) && 'inherit' !== $other['status'];
					}
					if ( $visible[ $target ] && $target !== $id ) { $edges[] = array( 'from' => $id, 'to' => $target, 'kind' => $kind ); }
				}
			}
		}
		$term_edges = array();
		$schema = self::taxonomy_schema();
		$public_ids = array();
		foreach ( $nodes as $node ) {
			if ( $node['public'] ) {
				$public_ids[ $node['post_type'] ][] = $node['id'];
			}
		}
		$editable_terms = array();
		foreach ( $public_ids as $post_type => $object_ids ) {
			$taxes = array_keys( array_filter( $schema, static fn( $tax ) => in_array( $post_type, $tax['types'], true ) ) );
			if ( ! $taxes ) { continue; }
			$terms = wp_get_object_terms( $object_ids, $taxes, array( 'fields' => 'all_with_object_id' ) );
			if ( is_wp_error( $terms ) ) { continue; }
			foreach ( $terms as $term ) {
				$editable_terms[ $term->term_id ] ??= current_user_can( 'edit_term', $term->term_id );
				if ( $editable_terms[ $term->term_id ] ) {
					$term_edges[] = array( 'source' => 'post:' . $term->object_id, 'target' => 'term:' . $term->term_id, 'kind' => 'in' );
				}
			}
		}
		return rest_ensure_response( array( 'taxonomies' => $schema, 'site_name' => get_bloginfo( 'name' ), 'home' => home_url( '/' ), 'front' => (int) get_option( 'page_on_front' ), 'term_edges' => $term_edges, 'indexing' => false, 'nodes' => $nodes, 'edges' => $edges, 'next_cursor' => $ids ? end( $ids ) : $after, 'done' => count( $ids ) < 100 ) );
	}

	/** Taxonomy hubs use the same public-content scope as Delta's Site graph. */
	public static function taxonomy_schema(): array {
		$out = array();
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$objects = array_values( array_intersect( $tax->object_type, $types ) );
			if ( ! $objects || ! current_user_can( $tax->cap->edit_terms ) ) { continue; }
			$out[ $tax->name ] = array( 'label' => $tax->labels->name, 'types' => $objects, 'edit' => admin_url( 'edit-tags.php?taxonomy=' . $tax->name ) );
		}
		return $out;
	}

	/** Paginated terms; invisible taxonomies and terms never become graph endpoints. */
	public static function graph_terms( WP_REST_Request $request ) {
		global $wpdb;
		$after = (int) $request->get_param( 'after' ); $schema = self::taxonomy_schema();
		if ( ! $schema ) { return rest_ensure_response( array( 'nodes' => array(), 'next_cursor' => $after, 'done' => true ) ); }
		$placeholders = implode( ',', array_fill( 0, count( $schema ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND taxonomy IN ($placeholders) ORDER BY term_taxonomy_id ASC LIMIT 100", array_merge( array( $after ), array_keys( $schema ) ) ) );
		$nodes = array();
		foreach ( $rows as $row ) {
			$term = get_term( (int) $row->term_id, $row->taxonomy );
			if ( ! $term || is_wp_error( $term ) || ! current_user_can( 'edit_term', $term->term_id ) ) { continue; }
			$view = get_term_link( $term );
			$nodes[] = array( 'id' => 'term:' . $term->term_id, 'kind' => 'term', 'group' => $term->taxonomy, 'label' => self::title_data( $term->name )['title'], 'parent' => $term->parent ? 'term:' . $term->parent : '', 'description' => wp_strip_all_tags( $term->description ), 'meta' => array( 'Taxonomy' => $schema[ $term->taxonomy ]['label'], 'Entries' => (int) $term->count, 'Slug' => $term->slug ), 'edit' => get_edit_term_link( $term->term_id, $term->taxonomy ), 'view' => is_wp_error( $view ) || ! is_taxonomy_viewable( $term->taxonomy ) ? '' : $view );
		}
		return rest_ensure_response( array( 'nodes' => $nodes, 'next_cursor' => $rows ? (int) end( $rows )->term_taxonomy_id : $after, 'done' => count( $rows ) < 100 ) );
	}

	/** Resolve the same saved template Etch supplies to its builder. */
	public static function template_for( WP_Post $post ): int {
		if ( in_array( $post->post_type, array( 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation', 'nav_menu_item' ), true ) || ! trait_exists( '\Etch\Traits\DynamicData' ) ) { return 0; }
		$resolver = new class {
			use \Etch\Traits\DynamicData;
			public function resolve( WP_Post $post ): ?array { return $this->get_template_data( $post ); }
		};
		$resolved = $resolver->resolve( $post );
		return (int) ( $resolved['id'] ?? 0 );
	}

	/** Follow only saved shared references; never render arbitrary blocks or queries. */
	private static function outgoing( int $id ): array {
		$to = array(); $uses = array(); $seen = array();
		$body = self::template_for( get_post( $id ) );
		$queue = array( $id );
		if ( $body && $body !== $id && self::node( $body ) ) { $uses[] = $body; $queue[] = $body; }
		for ( $cursor = 0; $cursor < count( $queue ) && $cursor < 100; $cursor++ ) {
			$owner = (int) $queue[ $cursor ];
			if ( isset( $seen[ $owner ] ) || ! self::node( $owner ) ) { continue; }
			$seen[ $owner ] = true;
			$to = array_merge( $to, array_map( 'intval', get_post_meta( $owner, self::LINK ) ) );
			foreach ( get_post_meta( $owner, self::USES ) as $part ) {
				$part = (int) $part;
				if ( $part === $id || isset( $seen[ $part ] ) || ! self::node( $part ) ) { continue; }
				$uses[] = $part;
				if ( ! in_array( $part, $queue, true ) ) { $queue[] = $part; }
			}
		}
		return array( 'to' => array_values( array_unique( array_diff( $to, array( $id ) ) ) ), 'uses' => array_values( array_unique( $uses ) ), 'truncated' => count( $queue ) > 100 );
	}

	/** Mirror inherited links in reverse, without traversing inaccessible shared resources. */
	private static function incoming_links( int $id, bool &$truncated ): array {
		$query = static fn( string $key, int $target ): array => get_posts( array( 'post_type' => self::types(), 'post_status' => array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft' ) ), 'posts_per_page' => 501, 'fields' => 'ids', 'meta_key' => $key, 'meta_value' => $target, 'no_found_rows' => true ) );
		$queue = $query( self::LINK, $id ); $sources = array(); $visited = array(); $expanded = 0;
		$shared = array( 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
		for ( $cursor = 0; $cursor < count( $queue ) && count( $sources ) <= 500; $cursor++ ) {
			$source = (int) $queue[ $cursor ];
			if ( $source === $id || isset( $visited[ $source ] ) ) { continue; }
			$visited[ $source ] = true;
			$node = self::node( $source );
			if ( ! $node ) { continue; }
			$sources[] = $source;
			if ( ! in_array( $node['post_type'], $shared, true ) ) { continue; }
			if ( ++$expanded > 100 ) { $truncated = true; continue; }
			$users = $query( self::USES, $source );
			if ( count( $users ) > 500 ) { $truncated = true; }
			$queue = array_values( array_unique( array_merge( $queue, $users ) ) );
		}
		$truncated = $truncated || count( $sources ) > 500;
		return array_slice( $sources, 0, 500 );
	}

	public static function connections( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$center = self::node( $id );
		if ( ! $center ) { return new WP_Error( 'map_unavailable', __( 'This item is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 404 ) ); }
		$done = self::batch(); self::index( $id );
		$nodes = static function ( array $ids ): array {
			return array_values( array_filter( array_map( array( self::class, 'node' ), array_unique( array_map( 'intval', $ids ) ) ) ) );
		};
		$incoming = static function ( string $key ) use ( $id ): array {
			return get_posts( array( 'post_type' => self::types(), 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 501, 'fields' => 'ids', 'meta_key' => $key, 'meta_value' => $id, 'no_found_rows' => true ) );
		};
		$outgoing = self::outgoing( $id );
		$uses = $outgoing['uses']; $to = $outgoing['to'];
		$incoming_truncated = false;
		$from = self::incoming_links( $id, $incoming_truncated );
		$used_by = $incoming( self::USES );
		$parents = array(); $children = array();
		$post = get_post( $id ); $type = get_post_type_object( $post->post_type );
		if ( $type && $type->hierarchical ) {
			if ( $post->post_parent && $post->post_parent !== $id && get_post_type( $post->post_parent ) === $post->post_type ) { $parents[] = (int) $post->post_parent; }
			$children = get_posts( array( 'post_type' => $post->post_type, 'post_parent' => $id, 'post_status' => array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft' ) ), 'posts_per_page' => 501, 'fields' => 'ids', 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ), 'no_found_rows' => true ) );
		}
		$relationships_done = Etch_Hub_Relationships::batch();
		$relationships = $relationships_done ? Etch_Hub_Relationships::for_post( get_post( $id ) ) : array();
		return rest_ensure_response( array_merge( array( 'truncated' => $incoming_truncated || count( $used_by ) > 500 || count( $children ) > 500 || $outgoing['truncated'], 'center' => $center, 'parents' => $nodes( $parents ), 'children' => $nodes( array_slice( $children, 0, 500 ) ), 'from' => $nodes( array_slice( $from, 0, 500 ) ), 'to' => $nodes( array_diff( $to, array( $id ) ) ), 'uses' => $nodes( array_diff( $uses, array( $id ) ) ), 'used_by' => $nodes( array_slice( $used_by, 0, 500 ) ), 'indexing' => ! $done, 'relationships_indexing' => ! $relationships_done ), $relationships ) );
	}
}
add_action( 'save_post', array( 'Etch_Hub_Connections', 'index' ), 30 );
add_action( 'rest_api_init', static function (): void {
	if ( ! defined( 'ETCH_PLUGIN_FILE' ) ) { return; }
	register_rest_route( 'etch-hub-sort/v1', '/connections/graph-terms', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ), 'callback' => array( 'Etch_Hub_Connections', 'graph_terms' ), 'args' => array( 'after' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) ) ) );
	register_rest_route( 'etch-hub-sort/v1', '/connections/terms', array( 'methods' => WP_REST_Server::READABLE, 'permission_callback' => static function (): bool { return current_user_can( 'edit_posts' ); }, 'callback' => array( 'Etch_Hub_Connections', 'graph_terms' ), 'args' => array( 'after' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) ) ) );
	register_rest_route( 'etch-hub-sort/v1', '/connections/site', array(
		'methods' => WP_REST_Server::READABLE,
		'permission_callback' => static function (): bool { return current_user_can( 'edit_posts' ); },
		'callback' => array( 'Etch_Hub_Connections', 'site' ),
		'args' => array( 'after' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ) ),
	) );
	register_rest_route( 'etch-hub-sort/v1', '/connections/(?P<id>\d+)', array(
		'methods' => WP_REST_Server::READABLE,
		'permission_callback' => static function ( WP_REST_Request $request ): bool { return current_user_can( 'edit_posts' ) && null !== Etch_Hub_Connections::node( (int) $request['id'] ); },
		'callback' => array( 'Etch_Hub_Connections', 'connections' ),
	) );
} );
// URL or shared-part identity changes require resolving older incoming links again.
add_action( 'post_updated', static function ( $id, $after, $before ): void {
	if ( $after->post_name !== $before->post_name || $after->post_parent !== $before->post_parent ) { delete_option( 'etch_hub_map_cursor_v1' ); }
}, 10, 3 );
foreach ( array( 'switch_theme', 'update_option_permalink_structure', 'deleted_post' ) as $etch_hub_map_hook ) {
	add_action( $etch_hub_map_hook, static function (): void { delete_option( 'etch_hub_map_cursor_v1' ); } );
}
add_action( 'transition_post_status', static function ( $new, $old ): void {
	if ( $new !== $old ) { delete_option( 'etch_hub_map_cursor_v1' ); }
}, 10, 2 );

// Template assignments and template hierarchy changes affect reverse usage throughout the site.
add_action( 'save_post_wp_template', static function (): void { delete_option( 'etch_hub_map_cursor_v1' ); }, 40 );
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $etch_hub_map_hook ) {
  add_action( $etch_hub_map_hook, static function ( $meta_id, $object_id, $key ): void {
    if ( '_wp_page_template' === $key ) { delete_option( 'etch_hub_map_cursor_v1' ); }
  }, 10, 3 );
}

unset( $etch_hub_map_hook );

// Menu destinations are stored in post meta after the initial post save.
add_action( 'wp_update_nav_menu_item', static function ( $menu_id, $item_id ): void {
	Etch_Hub_Connections::index( (int) $item_id );
	delete_option( 'etch_hub_map_cursor_v1' );
}, 20, 2 );
add_action( 'updated_post_meta', static function ( $meta_id, $post_id, $key ): void {
	if ( str_starts_with( $key, '_menu_item_' ) ) { delete_option( 'etch_hub_map_cursor_v1' ); }
}, 20, 3 );
