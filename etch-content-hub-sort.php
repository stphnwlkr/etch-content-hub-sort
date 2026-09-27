<?php
/**
 * Plugin Name: Etch Content Hub Sort
 * Description: Adds search, full-result sorting, pagination, and trash actions to Etch's Content Hub.
 * Version: 1.0.0
 * Requires at least: 5.9
 * Requires PHP: 8.1
 * Author: Stephen Walker
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: etch-content-hub-sort
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function (): void {
		if ( ! defined( 'ETCH_PLUGIN_FILE' ) ) {
			return;
		}
		register_rest_route(
			'etch-hub-sort/v1',
			'/settings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$enabled = (bool) $request->get_param( 'enabled' );
					update_option( 'etch_hub_sort_enabled', $enabled ? '1' : '0' );
					return rest_ensure_response( array( 'enabled' => $enabled ) );
				},
				'args'                => array(
					'enabled' => array( 'type' => 'boolean', 'required' => true ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					return current_user_can( 'delete_post', absint( $request['id'] ) );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$id   = absint( $request['id'] );
					$post = get_post( $id );
					$type = sanitize_key( (string) $request->get_param( 'type' ) );
					if ( ! $post || $post->post_type !== $type || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
						return new WP_Error( 'etch_hub_sort_missing_post', __( 'This item is no longer in the list.', 'etch-content-hub-sort' ), array( 'status' => 404 ) );
					}
					if ( ! EMPTY_TRASH_DAYS ) {
						return new WP_Error( 'etch_hub_sort_trash_unavailable', __( 'WordPress Trash is disabled for this site.', 'etch-content-hub-sort' ), array( 'status' => 409 ) );
					}
					if ( ! wp_trash_post( $id ) ) {
						return new WP_Error( 'etch_hub_sort_trash_failed', __( 'Could not move this item to Trash.', 'etch-content-hub-sort' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( array( 'trashed' => true, 'id' => $id ) );
				},
				'args'                => array(
					'id'   => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'type' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$type = sanitize_key( (string) $request->get_param( 'type' ) );
					$post_type = get_post_type_object( $type );
					if ( ! $post_type || ! $post_type->show_in_rest || ! current_user_can( $post_type->cap->edit_others_posts ) ) {
						return new WP_Error( 'etch_hub_sort_forbidden', __( 'This content type is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}

					$sort = sanitize_key( (string) $request->get_param( 'sort' ) );
					$sorts = array(
						'id'       => 'ID',
						'title'    => 'title',
						'slug'     => 'name',
						'status'   => 'date',
						'created'  => 'date',
						'modified' => 'modified',
					);
					if ( ! isset( $sorts[ $sort ] ) ) {
						$sort = 'created';
					}

					$order    = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';
					$page     = max( 1, absint( $request->get_param( 'page' ) ) );
					$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 25 ) );
					$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
					$args     = array(
						'post_type'           => $type,
						'post_status'         => array( 'publish', 'draft' ),
						'posts_per_page'      => $per_page,
						'paged'               => $page,
						'orderby'             => $sorts[ $sort ],
						'order'               => $order,
						's'                   => $search,
						'ignore_sticky_posts' => true,
						'etch_hub_sort_status' => 'status' === $sort,
					);

					$status_order = static function ( string $orderby, WP_Query $query ) use ( $order ): string {
						if ( ! $query->get( 'etch_hub_sort_status' ) ) {
							return $orderby;
						}
						global $wpdb;
						return "{$wpdb->posts}.post_status {$order}, {$wpdb->posts}.post_date DESC, {$wpdb->posts}.ID DESC";
					};
					add_filter( 'posts_orderby', $status_order, 10, 2 );
					$query = new WP_Query( $args );
					remove_filter( 'posts_orderby', $status_order, 10 );

					$items = array_map(
						static function ( WP_Post $post ): array {
							return array(
								'id'       => $post->ID,
								'title'    => get_the_title( $post ),
								'slug'     => $post->post_name,
								'status'   => $post->post_status,
								'created'  => $post->post_date,
								'modified' => $post->post_modified,
								'can_delete' => current_user_can( 'delete_post', $post->ID ),
							);
						},
						$query->posts
					);

					return rest_ensure_response(
						array(
							'items'    => $items,
							'total'    => (int) $query->found_posts,
							'pages'    => (int) $query->max_num_pages,
							'page'     => $page,
							'per_page' => $per_page,
						)
					);
				},
				'args'                => array(
					'type'     => array( 'type' => 'string', 'required' => true ),
					'sort'     => array( 'type' => 'string', 'enum' => array( 'id', 'title', 'slug', 'status', 'created', 'modified' ), 'default' => 'created' ),
					'order'    => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
					'search'   => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		// Etch renders its builder on the front end with this query parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! defined( 'ETCH_PLUGIN_FILE' ) || ! isset( $_GET['etch'] ) || 'magic' !== $_GET['etch'] || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$url     = plugin_dir_url( __FILE__ );
		$version = '1.0.0';
		$types   = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$types[ $post_type->labels->name ] = $post_type->name;
		}

		wp_enqueue_style( 'etch-content-hub-sort', $url . 'assets/sort.css', array(), $version );
		wp_enqueue_script( 'etch-content-hub-sort', $url . 'assets/sort.js', array( 'wp-i18n' ), $version, true );
		wp_set_script_translations( 'etch-content-hub-sort', 'etch-content-hub-sort', plugin_dir_path( __FILE__ ) . 'languages' );
		wp_add_inline_script(
			'etch-content-hub-sort',
			'window.etchHubSortConfig = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/posts' ) ),
					'settingsEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/settings' ) ),
					'assetUrl' => esc_url_raw( $url . 'assets/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'types'    => $types,
					'enabled'  => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
					'canManage' => current_user_can( 'manage_options' ),
				)
			) . ';',
			'before'
		);
	},
	100
);
