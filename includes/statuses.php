<?php
/** Post statuses shared by the Hub list and Quick edit. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exclude WordPress housekeeping states and hidden internal plugin states. */
function etch_hub_sort_post_statuses(): array {
	$statuses = array();
	foreach ( get_post_stati( array(), 'objects' ) as $name => $status ) {
		if ( in_array( $name, array( 'trash', 'auto-draft', 'inherit' ), true ) || ( $status->internal && ! $status->show_in_admin_status_list ) ) {
			continue;
		}
		$statuses[ $name ] = $status;
	}
	return $statuses;
}

/** Preserve the existing badge and picker colors until a pair is customized. */
function etch_hub_sort_status_defaults(): array {
	return array(
		'publish' => array( 'background' => '#244332', 'text' => '#5ccc86', 'picker_text' => '#8de2ad' ),
		'draft' => array( 'background' => '#4a3c24', 'text' => '#e9b74d', 'picker_text' => '#f2d07d' ),
		'future' => array( 'background' => '#233d56', 'text' => '#a6d5ff', 'picker_text' => '#a6d5ff' ),
		'pending' => array( 'background' => '#50351f', 'text' => '#ffcc91', 'picker_text' => '#ffcc91' ),
		'private' => array( 'background' => '#363b48', 'text' => '#cbd4e8', 'picker_text' => '#cbd4e8' ),
		'custom' => array( 'background' => '#3b2b56', 'text' => '#d3b8ff', 'picker_text' => '#d3b8ff' ),
	);
}

function etch_hub_sort_status_styles(): array {
	$defaults = etch_hub_sort_status_defaults();
	$saved = get_option( 'etch_hub_sort_status_styles', array() );
	foreach ( $defaults as $group => $fallback ) {
		$style = is_array( $saved ) ? ( $saved[ $group ] ?? null ) : null;
		if ( ! is_array( $style ) ) {
			continue;
		}
		$background = $style['background'] ?? '';
		$text = $style['text'] ?? '';
		if ( ! is_string( $background ) || ! is_string( $text ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $background ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $text ) || etch_hub_sort_color_contrast( $background, $text ) < 4.5 ) {
			continue;
		}
		$background = strtolower( $background );
		$text = strtolower( $text );
		if ( $background !== $fallback['background'] || $text !== $fallback['text'] ) {
			$defaults[ $group ] = array( 'background' => $background, 'text' => $text, 'picker_text' => $text );
		}
	}
	return $defaults;
}

/** Match core Quick Edit labels and its extension filter, plus registered workflows. */
function etch_hub_sort_quick_edit_statuses( WP_Post_Type $post_type ): array {
	$can_publish = current_user_can( $post_type->cap->publish_posts );
	$registered = etch_hub_sort_post_statuses();
	$statuses = array();
	if ( $can_publish ) {
		$statuses['publish'] = $registered['publish']->label;
		$statuses['future'] = $registered['future']->label;
		// Core uses a separate Private checkbox. The Hub uses one status picker.
		$statuses['private'] = $registered['private']->label;
	}
	$statuses['pending'] = __( 'Pending Review' );
	$statuses['draft'] = $registered['draft']->label;
	foreach ( $registered as $name => $status ) {
		if ( ! $status->_builtin ) {
			$statuses[ $name ] = $status->label;
		}
	}
	$statuses = apply_filters( 'quick_edit_statuses', $statuses, $post_type->name, false, $can_publish );
	$allowed = array();
	foreach ( (array) $statuses as $name => $label ) {
		$status = $registered[ $name ] ?? null;
		if ( ! $status || ! is_string( $label ) || ( ! $can_publish && ( $status->public || $status->private || 'future' === $name ) ) ) {
			continue;
		}
		$allowed[ $name ] = $label;
	}
	return $allowed;
}
