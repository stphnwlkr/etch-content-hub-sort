<?php
/**
 * Plugin Name: Etch Content Hub Sort
 * Description: Adds search, full-result sorting, pagination, and trash actions to Etch's Content Hub.
 * Version: 1.2.2
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

/** Return the saved layout, including the earlier start/end switch. */
function etch_hub_sort_actions_layout(): string {
	$layout = get_option( 'etch_hub_sort_actions_layout', '' );
	if ( in_array( $layout, array( 'end', 'start', 'split', 'open_start' ), true ) ) {
		return $layout;
	}
	return '1' === get_option( 'etch_hub_sort_actions_first', '0' ) ? 'start' : 'end';
}

/** Validate a parent assignment for a hierarchical post type. */
function etch_hub_sort_parent_error( WP_Post_Type $post_type, int $parent_id, int $post_id = 0 ): ?WP_Error {
	if ( 0 === $parent_id ) {
		return null;
	}
	$parent = get_post( $parent_id );
	$unchanged = $post_id && $parent_id === (int) get_post_field( 'post_parent', $post_id );
	if ( ! $post_type->hierarchical || ! $parent || $parent->post_type !== $post_type->name || ! in_array( $parent->post_status, array( 'publish', 'draft' ), true ) || ( ! $unchanged && ! current_user_can( 'edit_post', $parent_id ) ) ) {
		return new WP_Error( 'etch_hub_sort_invalid_parent', __( 'Choose an editable parent from this content type.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
	}
	if ( $post_id && ( $parent_id === $post_id || in_array( $post_id, array_map( 'intval', get_post_ancestors( $parent_id ) ), true ) ) ) {
		return new WP_Error( 'etch_hub_sort_parent_cycle', __( 'An item cannot be its own parent or a child of its descendant.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
	}
	return null;
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
					if ( ! $request->has_param( 'enabled' ) && ! $request->has_param( 'actions_first' ) && ! $request->has_param( 'actions_layout' ) ) {
						return new WP_Error( 'etch_hub_sort_missing_setting', __( 'Choose a setting to update.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					if ( $request->has_param( 'enabled' ) ) {
						update_option( 'etch_hub_sort_enabled', $request->get_param( 'enabled' ) ? '1' : '0' );
					}
					if ( $request->has_param( 'actions_first' ) ) {
						update_option( 'etch_hub_sort_actions_first', $request->get_param( 'actions_first' ) ? '1' : '0' );
						if ( ! $request->has_param( 'actions_layout' ) ) {
							delete_option( 'etch_hub_sort_actions_layout' );
						}
					}
					if ( $request->has_param( 'actions_layout' ) ) {
						update_option( 'etch_hub_sort_actions_layout', $request->get_param( 'actions_layout' ) );
					}
					return rest_ensure_response(
						array(
							'enabled'       => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
							'actions_first'  => 'start' === etch_hub_sort_actions_layout(),
							'actions_layout' => etch_hub_sort_actions_layout(),
						)
					);
				},
				'args'                => array(
					'enabled'       => array( 'type' => 'boolean' ),
					'actions_first' => array( 'type' => 'boolean' ),
					'actions_layout' => array( 'type' => 'string', 'enum' => array( 'end', 'start', 'split', 'open_start' ) ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/posts/create',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$type = sanitize_key( (string) $request->get_param( 'type' ) );
					$post_type = get_post_type_object( $type );
					if ( ! $post_type || ! $post_type->show_in_rest || ! current_user_can( $post_type->cap->create_posts ) ) {
						return new WP_Error( 'etch_hub_sort_forbidden', __( 'This content type is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					if ( 'publish' === $status && ! current_user_can( $post_type->cap->publish_posts ) ) {
						return new WP_Error( 'etch_hub_sort_cannot_publish', __( 'You cannot publish this content type.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}
					$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
					if ( '' === $title ) {
						return new WP_Error( 'etch_hub_sort_missing_title', __( 'Enter a title.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$parent_id = absint( $request->get_param( 'parent' ) );
					$parent_error = etch_hub_sort_parent_error( $post_type, $parent_id );
					if ( $parent_error ) {
						return $parent_error;
					}
					$id = wp_insert_post(
						array(
							'post_type'   => $type,
							'post_status' => $status,
							'post_title'  => $title,
							'post_parent' => $parent_id,
							'post_author' => get_current_user_id(),
						),
						true
					);
					if ( is_wp_error( $id ) || ! $id ) {
						return is_wp_error( $id ) ? $id : new WP_Error( 'etch_hub_sort_create_failed', __( 'Could not create the item.', 'etch-content-hub-sort' ), array( 'status' => 500 ) );
					}
					$response = rest_ensure_response( array( 'id' => $id, 'parent' => $parent_id, 'status' => $status ) );
					$response->set_status( 201 );
					return $response;
				},
				'args'                => array(
					'type'   => array( 'type' => 'string', 'required' => true ),
					'title'  => array( 'type' => 'string', 'required' => true ),
					'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ), 'required' => true ),
					'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					return current_user_can( 'edit_post', absint( $request['id'] ) );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = absint( $request['id'] );
					$post = get_post( $id );
					$type = sanitize_key( (string) $request->get_param( 'type' ) );
					$post_type = get_post_type_object( $type );
					if ( ! $post || ! $post_type || $post->post_type !== $type || ! $post_type->show_in_rest || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
						return new WP_Error( 'etch_hub_sort_missing_post', __( 'This item is no longer in the list.', 'etch-content-hub-sort' ), array( 'status' => 404 ) );
					}
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					if ( 'publish' === $status && 'publish' !== $post->post_status && ! current_user_can( $post_type->cap->publish_posts ) ) {
						return new WP_Error( 'etch_hub_sort_cannot_publish', __( 'You cannot publish this content type.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}
					$parent_id = absint( $request->get_param( 'parent' ) );
					$parent_error = etch_hub_sort_parent_error( $post_type, $parent_id, $id );
					if ( $parent_error ) {
						return $parent_error;
					}
					$updated = wp_update_post( array( 'ID' => $id, 'post_status' => $status, 'post_parent' => $parent_id ), true );
					if ( is_wp_error( $updated ) || ! $updated ) {
						return is_wp_error( $updated ) ? $updated : new WP_Error( 'etch_hub_sort_update_failed', __( 'Could not update the item.', 'etch-content-hub-sort' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( array( 'id' => $id, 'parent' => $parent_id, 'status' => $status ) );
				},
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'type'   => array( 'type' => 'string', 'required' => true ),
					'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ), 'required' => true ),
					'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
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
					$hierarchy = $post_type->hierarchical && (bool) $request->get_param( 'hierarchy' ) && '' === trim( $search );
					$parent    = $hierarchy ? absint( $request->get_param( 'parent' ) ) : 0;
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
					if ( $hierarchy ) {
						$args['post_parent'] = $parent;
					}

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
					$parents_with_children = array();
					if ( $hierarchy && $query->posts ) {
						global $wpdb;
						$parent_ids = array_map( 'intval', wp_list_pluck( $query->posts, 'ID' ) );
						$placeholders = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
						$parent_query = $wpdb->prepare(
							// The interpolated tokens are only %d placeholders, with each ID bound below.
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft') AND post_parent IN ({$placeholders})",
							array_merge( array( $type ), $parent_ids )
						);
						// One bounded query avoids querying once per row. Results change with post edits, so cross-request caching risks stale hierarchy indicators.
						$parents_with_children = array_map(
							'intval',
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
							$wpdb->get_col( $parent_query )
						);
					}

					$items = array_map(
						static function ( WP_Post $post ) use ( $parents_with_children, $post_type ): array {
							return array(
								'id'       => $post->ID,
								'title'    => get_the_title( $post ),
								'slug'     => $post->post_name,
								'status'   => $post->post_status,
								'created'  => $post->post_date,
								'modified' => $post->post_modified,
								'parent'   => (int) $post->post_parent,
								'parent_title' => $post->post_parent ? get_the_title( $post->post_parent ) : '',
								'has_children' => in_array( $post->ID, $parents_with_children, true ),
								'ancestors' => $post_type->hierarchical ? array_map( 'intval', get_post_ancestors( $post ) ) : array(),
								'can_edit' => current_user_can( 'edit_post', $post->ID ),
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
							'hierarchy' => $hierarchy,
							'parent'   => $parent,
							'can_create' => current_user_can( $post_type->cap->create_posts ),
							'can_publish' => current_user_can( $post_type->cap->publish_posts ),
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
					'hierarchy' => array( 'type' => 'boolean', 'default' => false ),
					'parent'   => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
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
		$version = '1.2.2';
		$types   = array();
		$hierarchical_types = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$types[ $post_type->labels->name ] = $post_type->name;
			if ( $post_type->hierarchical ) {
				$hierarchical_types[] = $post_type->name;
			}
		}

		wp_enqueue_style( 'etch-content-hub-sort', $url . 'assets/sort.css', array(), $version );
		wp_enqueue_script( 'etch-content-hub-sort', $url . 'assets/sort.js', array( 'wp-i18n' ), $version, true );
		wp_set_script_translations( 'etch-content-hub-sort', 'etch-content-hub-sort', plugin_dir_path( __FILE__ ) . 'languages' );
		wp_add_inline_script(
			'etch-content-hub-sort',
			'window.etchHubSortConfig = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/posts' ) ),
					'createEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/posts/create' ) ),
					'settingsEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/settings' ) ),
					'assetUrl' => esc_url_raw( $url . 'assets/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'types'    => $types,
					'hierarchicalTypes' => $hierarchical_types,
					'enabled'  => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
					'actionsLayout' => etch_hub_sort_actions_layout(),
					'canManage' => current_user_can( 'manage_options' ),
				)
			) . ';',
			'before'
		);
	},
	100
);
