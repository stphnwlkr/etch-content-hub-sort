<?php
/**
 * Remove the plugin setting when uninstalled.
 *
 * @package EtchContentHubSort
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'etch_hub_sort_enabled' );
delete_option( 'etch_hub_sort_show_sidebar_icon' );
delete_option( 'etch_hub_sort_default_view' );
delete_option( 'etch_hub_sort_actions_first' );
delete_option( 'etch_hub_sort_actions_layout' );
delete_option( 'etch_hub_sort_default_column' );
delete_option( 'etch_hub_sort_default_order' );
delete_option( 'etch_hub_sort_term_default_column' );
delete_option( 'etch_hub_sort_term_default_order' );
delete_option( 'etch_hub_sort_active_bg' );
delete_option( 'etch_hub_sort_active_text' );
delete_option( 'etch_hub_sort_button_styles' );
delete_option( 'etch_hub_sort_hierarchy_style' );
