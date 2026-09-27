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
