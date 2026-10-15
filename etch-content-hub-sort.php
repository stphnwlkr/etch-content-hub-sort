<?php
/**
 * Plugin Name: Etch Content Hub Sort
 * Description: Adds search, full-result sorting, pagination, and hierarchy browsing to Etch's Content Hub.
 * Version: 1.7.8
 * Requires at least: 5.9
 * Requires PHP: 8.1
 * Update URI: https://github.com/stphnwlkr/etch-content-hub-sort
 * Author: Stephen Walker
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: etch-content-hub-sort
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/github-updates.php';

/** Return the saved layout, including the earlier start/end switch. */
function etch_hub_sort_actions_layout(): string {
	$layout = get_option( 'etch_hub_sort_actions_layout', '' );
	if ( in_array( $layout, array( 'end', 'start', 'split', 'open_start' ), true ) ) {
		return $layout;
	}
	return '1' === get_option( 'etch_hub_sort_actions_first', '0' ) ? 'start' : 'end';
}

/** Keep the active heading's text readable against its chosen background. */
function etch_hub_sort_color_contrast( string $background, string $foreground ): float {
	$luminance = static function ( string $hex ): float {
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$channel = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $channel <= 0.04045 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	};
	$light = max( $luminance( $background ), $luminance( $foreground ) );
	$dark  = min( $luminance( $background ), $luminance( $foreground ) );
	return ( $light + 0.05 ) / ( $dark + 0.05 );
}

/** Return validated colors even if saved options were changed outside this plugin. */
function etch_hub_sort_heading_colors(): array {
	$background = get_option( 'etch_hub_sort_active_bg', '#292929' );
	$foreground = get_option( 'etch_hub_sort_active_text', '#f7f7f8' );
	if ( ! is_string( $background ) || ! is_string( $foreground ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $background ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $foreground ) || etch_hub_sort_color_contrast( $background, $foreground ) < 4.5 ) {
		return array( '#292929', '#f7f7f8' );
	}
	return array( strtolower( $background ), strtolower( $foreground ) );
}

/** Use only columns accepted by the posts endpoint. */
function etch_hub_sort_default_column(): string {
	$column = get_option( 'etch_hub_sort_default_column', 'created' );
	return in_array( $column, array( 'id', 'title', 'slug', 'status', 'created', 'modified' ), true ) ? $column : 'created';
}

function etch_hub_sort_default_order(): string {
	return 'asc' === get_option( 'etch_hub_sort_default_order', 'desc' ) ? 'asc' : 'desc';
}

function etch_hub_sort_term_default_column(): string {
	$column = get_option( 'etch_hub_sort_term_default_column', 'title' );
	return in_array( $column, array( 'id', 'title', 'slug', 'description', 'count' ), true ) ? $column : 'title';
}

function etch_hub_sort_term_default_order(): string {
	return 'desc' === get_option( 'etch_hub_sort_term_default_order', 'asc' ) ? 'desc' : 'asc';
}

/** Choose the table shown when a content type is opened for the first time. */
function etch_hub_sort_default_view(): string {
	return 'etch' === get_option( 'etch_hub_sort_default_view', 'browse' ) ? 'etch' : 'browse';
}

/** Shared defaults for the buttons in the browse view. */
function etch_hub_sort_button_defaults(): array {
	$groups = array( 'new', 'view', 'settings', 'hierarchy', 'bulk', 'expand', 'load_more', 'quick_edit', 'open', 'trash', 'pagination', 'notice' );
	$defaults = array();
	foreach ( $groups as $group ) {
		$defaults[ $group ] = array( 'background' => '#292929', 'text' => '#f7f7f8', 'display' => 'icon', 'custom_colors' => false );
	}
	return $defaults;
}

/** Ignore corrupt option data and keep every saved pair readable. */
function etch_hub_sort_button_styles(): array {
	$defaults = etch_hub_sort_button_defaults();
	$saved    = get_option( 'etch_hub_sort_button_styles', array() );
	if ( ! is_array( $saved ) ) {
		return $defaults;
	}
	foreach ( $defaults as $group => $fallback ) {
		$style = $saved[ $group ] ?? null;
		if ( ! is_array( $style ) ) {
			continue;
		}
		$background = $style['background'] ?? '';
		$text       = $style['text'] ?? '';
		$display    = $style['display'] ?? '';
		$custom_colors = $style['custom_colors'] ?? true;
		if ( is_string( $background ) && is_string( $text ) && is_bool( $custom_colors ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $background ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $text ) && ( ! $custom_colors || etch_hub_sort_color_contrast( $background, $text ) >= 4.5 ) && in_array( $display, array( 'icon', 'both', 'text' ), true ) ) {
			$defaults[ $group ] = array( 'background' => strtolower( $background ), 'text' => strtolower( $text ), 'display' => $display, 'custom_colors' => $custom_colors );
		}
	}
	return $defaults;
}

/** Return readable custom colors for the parent and child indicators. */
function etch_hub_sort_hierarchy_style(): array {
	$defaults = array(
		'enabled'       => false,
		'parent_bg'     => '#2b302d',
		'parent_text'   => '#f7f7f8',
		'parent_border' => '#5ccc86',
		'parent_caret'  => '#5ccc86',
		'child_bg'      => '#2f3431',
		'child_text'    => '#f7f7f8',
		'child_border'  => '#70bf93',
		'child_caret'   => '#f7f7f8',
		'border_width'  => 3,
	);
	$saved = get_option( 'etch_hub_sort_hierarchy_style', array() );
	if ( ! is_array( $saved ) || ! isset( $saved['enabled'], $saved['border_width'] ) || ! is_bool( $saved['enabled'] ) || ! is_int( $saved['border_width'] ) || $saved['border_width'] < 1 || $saved['border_width'] > 6 ) {
		return $defaults;
	}
	foreach ( array( 'parent_bg', 'parent_text', 'parent_border', 'parent_caret', 'child_bg', 'child_text', 'child_border', 'child_caret' ) as $key ) {
		if ( ! isset( $saved[ $key ] ) || ! is_string( $saved[ $key ] ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $saved[ $key ] ) ) {
			return $defaults;
		}
		$defaults[ $key ] = strtolower( $saved[ $key ] );
	}
	foreach ( array( 'parent', 'child' ) as $level ) {
		if ( etch_hub_sort_color_contrast( $defaults[ "{$level}_bg" ], $defaults[ "{$level}_text" ] ) < 4.5 || etch_hub_sort_color_contrast( $defaults[ "{$level}_bg" ], $defaults[ "{$level}_border" ] ) < 3 || etch_hub_sort_color_contrast( $defaults[ "{$level}_bg" ], $defaults[ "{$level}_caret" ] ) < 3 ) {
			return $defaults;
		}
	}
	$defaults['enabled']      = $saved['enabled'];
	$defaults['border_width'] = $saved['border_width'];
	return $defaults;
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

/** Check term parents before calling WordPress's term write functions. */
function etch_hub_sort_term_parent_error( WP_Taxonomy $taxonomy, int $parent_id, int $term_id = 0 ): ?WP_Error {
	if ( 0 === $parent_id ) {
		return null;
	}
	$parent = get_term( $parent_id, $taxonomy->name );
	$unchanged = $term_id && $parent_id === (int) get_term( $term_id, $taxonomy->name )->parent;
	if ( ! $taxonomy->hierarchical || ! $parent instanceof WP_Term || ( ! $unchanged && ! current_user_can( 'edit_term', $parent_id ) ) ) {
		return new WP_Error( 'etch_hub_sort_invalid_term_parent', __( 'Choose a parent from this taxonomy that you can edit.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
	}
	if ( $term_id && ( $parent_id === $term_id || in_array( $term_id, array_map( 'intval', get_ancestors( $parent_id, $taxonomy->name, 'taxonomy' ) ), true ) ) ) {
		return new WP_Error( 'etch_hub_sort_term_parent_cycle', __( 'A term cannot be its own parent or a child of its descendant.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
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
					if ( ! $request->has_param( 'enabled' ) && ! $request->has_param( 'show_sidebar_icon' ) && ! $request->has_param( 'default_view' ) && ! $request->has_param( 'actions_first' ) && ! $request->has_param( 'actions_layout' ) && ! $request->has_param( 'default_column' ) && ! $request->has_param( 'default_order' ) && ! $request->has_param( 'term_default_column' ) && ! $request->has_param( 'term_default_order' ) && ! $request->has_param( 'active_bg' ) && ! $request->has_param( 'active_text' ) && ! $request->has_param( 'button_style' ) && ! $request->has_param( 'hierarchy_style' ) ) {
						return new WP_Error( 'etch_hub_sort_missing_setting', __( 'Choose a setting to update.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$colors = etch_hub_sort_heading_colors();
					$background = $request->has_param( 'active_bg' ) ? $request->get_param( 'active_bg' ) : $colors[0];
					$foreground = $request->has_param( 'active_text' ) ? $request->get_param( 'active_text' ) : $colors[1];
					if ( ! is_string( $background ) || ! is_string( $foreground ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $background ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $foreground ) ) {
						return new WP_Error( 'etch_hub_sort_invalid_color', __( 'Choose valid six-digit hex colors.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					if ( etch_hub_sort_color_contrast( $background, $foreground ) < 4.5 ) {
						return new WP_Error( 'etch_hub_sort_low_contrast', __( 'Choose colors with at least 4.5:1 text contrast.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$button_style = $request->get_param( 'button_style' );
					if ( $request->has_param( 'button_style' ) && ! is_array( $button_style ) ) {
						return new WP_Error( 'etch_hub_sort_invalid_button_style', __( 'Choose a valid button style.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					if ( $request->has_param( 'button_style' ) ) {
						$group = $button_style['group'] ?? '';
						$button_background = $button_style['background'] ?? '';
						$button_text = $button_style['text'] ?? '';
						$button_display = $button_style['display'] ?? '';
						$custom_colors = $button_style['custom_colors'] ?? true;
						if ( ! is_string( $group ) || ! array_key_exists( $group, etch_hub_sort_button_defaults() ) || ! is_string( $button_background ) || ! is_string( $button_text ) || ! is_bool( $custom_colors ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $button_background ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $button_text ) || ! in_array( $button_display, array( 'icon', 'both', 'text' ), true ) ) {
							return new WP_Error( 'etch_hub_sort_invalid_button_style', __( 'Choose a valid button style.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
						}
						if ( $custom_colors && etch_hub_sort_color_contrast( $button_background, $button_text ) < 4.5 ) {
							return new WP_Error( 'etch_hub_sort_low_contrast', __( 'Choose colors with at least 4.5:1 text contrast.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
						}
					}
					$hierarchy_style = $request->get_param( 'hierarchy_style' );
					if ( $request->has_param( 'hierarchy_style' ) ) {
						if ( ! is_array( $hierarchy_style ) || ! isset( $hierarchy_style['enabled'], $hierarchy_style['border_width'] ) || ! is_bool( $hierarchy_style['enabled'] ) || ! is_int( $hierarchy_style['border_width'] ) || $hierarchy_style['border_width'] < 1 || $hierarchy_style['border_width'] > 6 ) {
							return new WP_Error( 'etch_hub_sort_invalid_hierarchy_style', __( 'Choose valid hierarchy indicator settings.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
						}
						foreach ( array( 'parent_bg', 'parent_text', 'parent_border', 'parent_caret', 'child_bg', 'child_text', 'child_border', 'child_caret' ) as $key ) {
							if ( ! isset( $hierarchy_style[ $key ] ) || ! is_string( $hierarchy_style[ $key ] ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $hierarchy_style[ $key ] ) ) {
								return new WP_Error( 'etch_hub_sort_invalid_hierarchy_style', __( 'Choose valid six-digit hierarchy colors.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
							}
							$hierarchy_style[ $key ] = strtolower( $hierarchy_style[ $key ] );
						}
						foreach ( array( 'parent', 'child' ) as $level ) {
							if ( etch_hub_sort_color_contrast( $hierarchy_style[ "{$level}_bg" ], $hierarchy_style[ "{$level}_text" ] ) < 4.5 || etch_hub_sort_color_contrast( $hierarchy_style[ "{$level}_bg" ], $hierarchy_style[ "{$level}_border" ] ) < 3 || etch_hub_sort_color_contrast( $hierarchy_style[ "{$level}_bg" ], $hierarchy_style[ "{$level}_caret" ] ) < 3 ) {
								return new WP_Error( 'etch_hub_sort_low_hierarchy_contrast', __( 'Use at least 4.5:1 contrast for row text and 3:1 for hierarchy borders and carets.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
							}
						}
					}
					if ( $request->has_param( 'enabled' ) ) {
						update_option( 'etch_hub_sort_enabled', $request->get_param( 'enabled' ) ? '1' : '0' );
					}
					if ( $request->has_param( 'show_sidebar_icon' ) ) {
						update_option( 'etch_hub_sort_show_sidebar_icon', $request->get_param( 'show_sidebar_icon' ) ? '1' : '0' );
					}
					if ( $request->has_param( 'default_view' ) ) {
						update_option( 'etch_hub_sort_default_view', $request->get_param( 'default_view' ) );
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
					if ( $request->has_param( 'default_column' ) ) {
						update_option( 'etch_hub_sort_default_column', $request->get_param( 'default_column' ) );
					}
					if ( $request->has_param( 'default_order' ) ) {
						update_option( 'etch_hub_sort_default_order', $request->get_param( 'default_order' ) );
					}
					if ( $request->has_param( 'term_default_column' ) ) {
						update_option( 'etch_hub_sort_term_default_column', $request->get_param( 'term_default_column' ) );
					}
					if ( $request->has_param( 'term_default_order' ) ) {
						update_option( 'etch_hub_sort_term_default_order', $request->get_param( 'term_default_order' ) );
					}
					if ( $request->has_param( 'active_bg' ) ) {
						update_option( 'etch_hub_sort_active_bg', strtolower( $background ) );
					}
					if ( $request->has_param( 'active_text' ) ) {
						update_option( 'etch_hub_sort_active_text', strtolower( $foreground ) );
					}
					if ( $request->has_param( 'button_style' ) ) {
						$styles = etch_hub_sort_button_styles();
						$styles[ $group ] = array( 'background' => strtolower( $button_background ), 'text' => strtolower( $button_text ), 'display' => $button_display, 'custom_colors' => $custom_colors );
						update_option( 'etch_hub_sort_button_styles', $styles );
					}
					if ( $request->has_param( 'hierarchy_style' ) ) {
						update_option( 'etch_hub_sort_hierarchy_style', $hierarchy_style );
					}
					$colors = etch_hub_sort_heading_colors();
					return rest_ensure_response(
						array(
							'enabled'       => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
							'show_sidebar_icon' => '0' !== get_option( 'etch_hub_sort_show_sidebar_icon', '1' ),
							'default_view'  => etch_hub_sort_default_view(),
							'actions_first'  => 'start' === etch_hub_sort_actions_layout(),
							'actions_layout' => etch_hub_sort_actions_layout(),
							'default_column' => etch_hub_sort_default_column(),
							'default_order'  => etch_hub_sort_default_order(),
							'term_default_column' => etch_hub_sort_term_default_column(),
							'term_default_order' => etch_hub_sort_term_default_order(),
							'active_bg'      => $colors[0],
							'active_text'    => $colors[1],
							'button_styles'  => etch_hub_sort_button_styles(),
							'hierarchy_style' => etch_hub_sort_hierarchy_style(),
						)
					);
				},
				'args'                => array(
					'enabled'       => array( 'type' => 'boolean' ),
					'show_sidebar_icon' => array( 'type' => 'boolean' ),
					'default_view'  => array( 'type' => 'string', 'enum' => array( 'etch', 'browse' ) ),
					'actions_first' => array( 'type' => 'boolean' ),
					'actions_layout' => array( 'type' => 'string', 'enum' => array( 'end', 'start', 'split', 'open_start' ) ),
					'default_column' => array( 'type' => 'string', 'enum' => array( 'id', 'title', 'slug', 'status', 'created', 'modified' ) ),
					'default_order'  => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
					'term_default_column' => array( 'type' => 'string', 'enum' => array( 'id', 'title', 'slug', 'description', 'count' ) ),
					'term_default_order' => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
					'active_bg'      => array( 'type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$' ),
					'active_text'    => array( 'type' => 'string', 'pattern' => '^#[0-9a-fA-F]{6}$' ),
					'button_style'   => array( 'type' => 'object' ),
					'hierarchy_style' => array( 'type' => 'object' ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$slug = sanitize_key( (string) $request->get_param( 'type' ) );
					$taxonomy = get_taxonomy( $slug );
					if ( ! $taxonomy || ! $taxonomy->show_ui || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
						return new WP_Error( 'etch_hub_sort_forbidden', __( 'This taxonomy is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}
					$sorts = array( 'id' => 'term_id', 'title' => 'name', 'slug' => 'slug', 'description' => 'description', 'count' => 'count', 'parent' => 'parent' );
					$sort = sanitize_key( (string) $request->get_param( 'sort' ) );
					if ( ! isset( $sorts[ $sort ] ) ) {
						$sort = 'title';
					}
					$page = max( 1, absint( $request->get_param( 'page' ) ) );
					$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 25 ) );
					$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
					$parent_picker = (bool) $request->get_param( 'parent_picker' );
					$hierarchy = $taxonomy->hierarchical && (bool) $request->get_param( 'hierarchy' ) && '' === trim( $search );
					$parent = $hierarchy ? absint( $request->get_param( 'parent' ) ) : 0;
					$args = array(
						'taxonomy' => $slug,
						'hide_empty' => false,
						'orderby' => $sorts[ $sort ],
						'order' => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
						'search' => $search,
						'update_term_meta_cache' => false,
					);
					if ( $hierarchy ) {
						$args['parent'] = $parent;
					}
					$count = get_terms( array_merge( $args, array( 'fields' => 'count' ) ) );
					if ( is_wp_error( $count ) ) {
						return $count;
					}
					$terms = get_terms( array_merge( $args, array( 'number' => $per_page, 'offset' => ( $page - 1 ) * $per_page ) ) );
					if ( is_wp_error( $terms ) ) {
						return $terms;
					}
					$parents_with_children = array();
					$default_term_id = 'category' === $slug ? (int) get_option( 'default_category' ) : ( ! empty( $taxonomy->default_term ) ? (int) get_option( 'default_term_' . $slug ) : 0 );
					if ( $taxonomy->hierarchical && $terms ) {
						global $wpdb;
						$ids = array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) );
						$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
						$sql = $wpdb->prepare(
							// The interpolated tokens are only %d placeholders, with each ID bound below.
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT DISTINCT parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND parent IN ({$placeholders})",
							array_merge( array( $slug ), $ids )
						);
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
						$parents_with_children = array_map( 'intval', $wpdb->get_col( $sql ) );
					}
					$items = array_map(
						static function ( WP_Term $term ) use ( $slug, $taxonomy, $parents_with_children, $parent_picker, $default_term_id ): array {
							$parent_term = $term->parent ? get_term( $term->parent, $slug ) : null;
							return array(
								'id' => $term->term_id,
								'title' => $term->name,
								'slug' => $term->slug,
								'description' => $term->description,
								'count' => (int) $term->count,
								'parent' => (int) $term->parent,
								'parent_title' => $parent_term instanceof WP_Term ? $parent_term->name : '',
								'has_children' => in_array( $term->term_id, $parents_with_children, true ),
								'ancestors' => $parent_picker && $taxonomy->hierarchical ? array_map( 'intval', get_ancestors( $term->term_id, $slug, 'taxonomy' ) ) : array(),
								'can_edit' => current_user_can( 'edit_term', $term->term_id ),
								'can_delete' => $term->term_id !== $default_term_id && current_user_can( 'delete_term', $term->term_id ),
							);
						},
						$terms
					);
					$total = (int) $count;
					return rest_ensure_response( array( 'items' => $items, 'total' => $total, 'pages' => (int) ceil( $total / $per_page ), 'page' => $page, 'per_page' => $per_page, 'hierarchy' => $hierarchy, 'parent' => $parent, 'can_create' => current_user_can( $taxonomy->cap->edit_terms ) ) );
				},
				'args' => array(
					'type' => array( 'type' => 'string', 'required' => true ),
					'sort' => array( 'type' => 'string', 'default' => 'title', 'enum' => array( 'id', 'title', 'slug', 'description', 'count', 'parent' ) ),
					'order' => array( 'type' => 'string', 'default' => 'asc', 'enum' => array( 'asc', 'desc' ) ),
					'page' => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
					'search' => array( 'type' => 'string', 'default' => '' ),
					'hierarchy' => array( 'type' => 'boolean', 'default' => false ),
					'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
					'parent_picker' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/terms/create',
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback' => static function ( WP_REST_Request $request ) {
					$slug = sanitize_key( (string) $request->get_param( 'type' ) );
					$taxonomy = get_taxonomy( $slug );
					if ( ! $taxonomy || ! $taxonomy->show_ui || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
						return new WP_Error( 'etch_hub_sort_forbidden', __( 'You cannot create terms in this taxonomy.', 'etch-content-hub-sort' ), array( 'status' => 403 ) );
					}
					$name = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
					if ( '' === $name ) {
						return new WP_Error( 'etch_hub_sort_missing_name', __( 'Enter a term name.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$parent = absint( $request->get_param( 'parent' ) );
					$parent_error = etch_hub_sort_term_parent_error( $taxonomy, $parent );
					if ( $parent_error ) {
						return $parent_error;
					}
					$result = wp_insert_term( $name, $slug, array(
						'slug' => sanitize_title( (string) $request->get_param( 'slug' ) ),
						'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
						'parent' => $parent,
					) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$response = rest_ensure_response( array( 'id' => (int) $result['term_id'], 'parent' => $parent ) );
					$response->set_status( 201 );
					return $response;
				},
				'args' => array(
					'type' => array( 'type' => 'string', 'required' => true ),
					'name' => array( 'type' => 'string', 'required' => true ),
					'slug' => array( 'type' => 'string', 'default' => '' ),
					'description' => array( 'type' => 'string', 'default' => '' ),
					'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/terms/(?P<id>\d+)',
			array(
				'methods' => WP_REST_Server::EDITABLE,
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					return current_user_can( 'edit_posts' ) && current_user_can( 'edit_term', absint( $request['id'] ) );
				},
				'callback' => static function ( WP_REST_Request $request ) {
					$id = absint( $request['id'] );
					$slug = sanitize_key( (string) $request->get_param( 'type' ) );
					$taxonomy = get_taxonomy( $slug );
					$term = get_term( $id, $slug );
					if ( ! $taxonomy || ! $taxonomy->show_ui || ! $term instanceof WP_Term || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
						return new WP_Error( 'etch_hub_sort_missing_term', __( 'This term is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 404 ) );
					}
					$name = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
					if ( '' === $name ) {
						return new WP_Error( 'etch_hub_sort_missing_name', __( 'Enter a term name.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$parent = absint( $request->get_param( 'parent' ) );
					$parent_error = etch_hub_sort_term_parent_error( $taxonomy, $parent, $id );
					if ( $parent_error ) {
						return $parent_error;
					}
					$result = wp_update_term( $id, $slug, array(
						'name' => $name,
						'slug' => sanitize_title( (string) $request->get_param( 'slug' ) ),
						'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
						'parent' => $parent,
					) );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return rest_ensure_response( array( 'id' => $id, 'parent' => $parent ) );
				},
				'args' => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'type' => array( 'type' => 'string', 'required' => true ),
					'name' => array( 'type' => 'string', 'required' => true ),
					'slug' => array( 'type' => 'string', 'default' => '' ),
					'description' => array( 'type' => 'string', 'default' => '' ),
					'parent' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
			)
		);
		register_rest_route(
			'etch-hub-sort/v1',
			'/terms/(?P<id>\d+)',
			array(
				'methods' => WP_REST_Server::DELETABLE,
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					return current_user_can( 'edit_posts' ) && current_user_can( 'delete_term', absint( $request['id'] ) );
				},
				'callback' => static function ( WP_REST_Request $request ) {
					$id = absint( $request['id'] );
					$slug = sanitize_key( (string) $request->get_param( 'type' ) );
					$taxonomy = get_taxonomy( $slug );
					$term = get_term( $id, $slug );
					if ( ! $taxonomy || ! $taxonomy->show_ui || ! $term instanceof WP_Term || ! current_user_can( $taxonomy->cap->delete_terms ) ) {
						return new WP_Error( 'etch_hub_sort_missing_term', __( 'This term is unavailable.', 'etch-content-hub-sort' ), array( 'status' => 404 ) );
					}
					$deleted = wp_delete_term( $id, $slug );
					if ( is_wp_error( $deleted ) ) {
						return $deleted;
					}
					if ( ! $deleted ) {
						return new WP_Error( 'etch_hub_sort_term_delete_failed', __( 'Could not delete this term.', 'etch-content-hub-sort' ), array( 'status' => 409 ) );
					}
					return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
				},
				'args' => array(
					'id' => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'type' => array( 'type' => 'string', 'required' => true ),
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
					$changes = array( 'ID' => $id, 'post_status' => $status, 'post_parent' => $parent_id );
					if ( $request->has_param( 'title' ) ) {
						$title = trim( sanitize_text_field( (string) $request->get_param( 'title' ) ) );
						$changes['post_title'] = wp_slash( $title );
					}
					if ( $request->has_param( 'slug' ) ) {
						$raw_slug = trim( (string) $request->get_param( 'slug' ) );
						$slug = sanitize_title( $raw_slug );
						if ( '' !== $raw_slug && '' === $slug ) {
							return new WP_Error( 'etch_hub_sort_invalid_slug', __( 'Enter a valid slug or leave it blank.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
						}
						$changes['post_name'] = $slug;
					}
					if ( ! $request->has_param( 'slug' ) && isset( $title ) && '' !== $title && '' === trim( $post->post_title ) && '' === $post->post_name ) {
						$changes['post_name'] = sanitize_title( $title );
					}
					if ( $request->has_param( 'date' ) ) {
						$raw_date = (string) $request->get_param( 'date' );
						$site_date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $raw_date, wp_timezone() );
						$date_errors = DateTimeImmutable::getLastErrors();
						if ( ! $site_date || ( false !== $date_errors && ( $date_errors['warning_count'] || $date_errors['error_count'] ) ) || $site_date->format( 'Y-m-d\TH:i' ) !== $raw_date ) {
							return new WP_Error( 'etch_hub_sort_invalid_date', __( 'Enter a valid date and time in the site timezone.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
						}
						$changes['post_date'] = $site_date->format( 'Y-m-d H:i:s' );
						$changes['post_date_gmt'] = get_gmt_from_date( $changes['post_date'] );
						$changes['edit_date'] = true;
					}
					$effective_date = $changes['post_date'] ?? $post->post_date;
					$effective_datetime = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $effective_date, wp_timezone() );
					if ( 'publish' === $status && $effective_datetime && $effective_datetime->getTimestamp() > time() ) {
						return new WP_Error( 'etch_hub_sort_future_date', __( 'Published items need a date that is not in the future. Choose Draft for a future date.', 'etch-content-hub-sort' ), array( 'status' => 400 ) );
					}
					$updated = wp_update_post( $changes, true );
					if ( is_wp_error( $updated ) || ! $updated ) {
						return is_wp_error( $updated ) ? $updated : new WP_Error( 'etch_hub_sort_update_failed', __( 'Could not update the item.', 'etch-content-hub-sort' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( array( 'id' => $id, 'parent' => $parent_id, 'status' => $status ) );
				},
				'args'                => array(
					'id'     => array( 'type' => 'integer', 'minimum' => 1, 'required' => true ),
					'type'   => array( 'type' => 'string', 'required' => true ),
					'title'  => array( 'type' => 'string' ),
					'slug'   => array( 'type' => 'string' ),
					'date'   => array( 'type' => 'string' ),
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
								'edit_title' => $post->post_title,
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
		$version = '1.7.8';
		$types   = array();
		$hierarchical_types = array();
		$taxonomies = array();
		$hierarchical_taxonomies = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
			$types[ $post_type->labels->name ] = $post_type->name;
			if ( $post_type->hierarchical ) {
				$hierarchical_types[] = $post_type->name;
			}
		}
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			if ( ! current_user_can( $taxonomy->cap->edit_terms ) ) {
				continue;
			}
			$taxonomies[ $taxonomy->labels->name ] = $taxonomy->name;
			if ( $taxonomy->hierarchical ) {
				$hierarchical_taxonomies[] = $taxonomy->name;
			}
		}

		wp_enqueue_style( 'etch-content-hub-sort', $url . 'assets/sort.css', array(), $version );
		wp_enqueue_script( 'etch-content-hub-sort', $url . 'assets/sort.js', array( 'wp-i18n' ), $version, true );
		wp_set_script_translations( 'etch-content-hub-sort', 'etch-content-hub-sort', plugin_dir_path( __FILE__ ) . 'languages' );
		$heading_colors = etch_hub_sort_heading_colors();
		wp_add_inline_script(
			'etch-content-hub-sort',
			'window.etchHubSortConfig = ' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/posts' ) ),
					'createEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/posts/create' ) ),
					'termsEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/terms' ) ),
					'termCreateEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/terms/create' ) ),
					'settingsEndpoint' => esc_url_raw( rest_url( 'etch-hub-sort/v1/settings' ) ),
					'assetUrl' => esc_url_raw( $url . 'assets/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'siteTimezone' => wp_timezone_string(),
					'types'    => $types,
					'taxonomies' => $taxonomies,
					'hierarchicalTypes' => $hierarchical_types,
					'hierarchicalTaxonomies' => $hierarchical_taxonomies,
					'enabled'  => '0' !== get_option( 'etch_hub_sort_enabled', '1' ),
					'showSidebarIcon' => '0' !== get_option( 'etch_hub_sort_show_sidebar_icon', '1' ),
					'defaultView' => etch_hub_sort_default_view(),
					'actionsLayout' => etch_hub_sort_actions_layout(),
					'defaultColumn' => etch_hub_sort_default_column(),
					'defaultOrder' => etch_hub_sort_default_order(),
					'termDefaultColumn' => etch_hub_sort_term_default_column(),
					'termDefaultOrder' => etch_hub_sort_term_default_order(),
					'activeBg' => $heading_colors[0],
					'activeText' => $heading_colors[1],
					'buttonStyles' => etch_hub_sort_button_styles(),
					'hierarchyStyle' => etch_hub_sort_hierarchy_style(),
					'canManage' => current_user_can( 'manage_options' ),
				)
			) . ';',
			'before'
		);
	},
	100
);
