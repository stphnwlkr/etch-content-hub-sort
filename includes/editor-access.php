<?php
/** Editor shortcuts for saved content. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ETCH_HUB_SORT_SHARED_EDITOR_TYPES = array( 'wp_block', 'wp_template', 'wp_template_part' );

function etch_hub_sort_editor_access(): array {
	return (array) get_option( 'etch_hub_sort_editor_access', array() );
}

/** Valid slugs can be carried between sites before their post types are registered. */
function etch_hub_sort_validate_editor_access( $access ): bool {
	if ( ! is_array( $access ) ) {
		return false;
	}
	foreach ( $access as $type => $mode ) {
		if ( ! is_string( $type ) || ! preg_match( '/^[a-z0-9_-]{1,20}$/D', $type ) || in_array( $type, ETCH_HUB_SORT_SHARED_EDITOR_TYPES, true ) || ! in_array( $mode, array( 'both', 'wordpress', 'etch' ), true ) ) {
			return false;
		}
	}
	return true;
}

function etch_hub_sort_editor_mode( string $type ): string {
	if ( in_array( $type, ETCH_HUB_SORT_SHARED_EDITOR_TYPES, true ) ) {
		return 'both';
	}
	if ( ! Etch_Hub_Connections::supports_etch_type( $type ) ) {
		return 'wordpress';
	}
	$mode = etch_hub_sort_editor_access()[ $type ] ?? '';
	return in_array( $mode, array( 'wordpress', 'etch' ), true ) ? $mode : 'both';
}

function etch_hub_sort_can_open_etch( string $type ): bool {
	return defined( 'ETCH_PLUGIN_FILE' ) && current_user_can( 'manage_options' ) && 'wordpress' !== etch_hub_sort_editor_mode( $type );
}

function etch_hub_sort_wordpress_edit_url( WP_Post $post ): ?string {
	if ( 'etch' === etch_hub_sort_editor_mode( $post->post_type ) ) {
		return null;
	}
	return esc_url_raw( (string) get_edit_post_link( $post, 'raw' ) ) ?: null;
}

function etch_hub_sort_editor_types(): array {
	$types = array();
	foreach ( array_diff( Etch_Hub_Connections::types(), array( 'attachment', 'revision', 'wp_global_styles', 'wp_font_family', 'wp_font_face' ) ) as $name ) {
		$type = get_post_type_object( $name );
		if ( $type ) {
			$types[] = array(
				'name' => $name,
				'label' => 'wp_block' === $name ? __( 'Patterns and components', 'etch-content-hub-sort' ) : $type->labels->name,
				'supportsEtch' => Etch_Hub_Connections::supports_etch_type( $name ),
				'shared' => in_array( $name, ETCH_HUB_SORT_SHARED_EDITOR_TYPES, true ),
				'mode' => etch_hub_sort_editor_mode( $name ),
			);
		}
	}
	return $types;
}
