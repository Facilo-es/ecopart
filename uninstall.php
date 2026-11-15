<?php
/**
 * Uninstall: remove the settings. Product amounts and order records stay unless
 * "Remove all data on uninstall" is on, because orders are accounting records.
 *
 * @package Ecopart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ecopart_settings = get_option( 'ecopart_settings', array() );

if ( is_array( $ecopart_settings ) && ! empty( $ecopart_settings['delete_data'] ) ) {
	foreach ( array( '_ecopart_amount', '_ecopart_type', '_ecopart_ref', '_ecopart_source' ) as $ecopart_key ) {
		delete_post_meta_by_key( $ecopart_key );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup; WooCommerce has no API to delete item meta by key.
	$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key LIKE '\\_ecopart\\_%'" );
}

delete_option( 'ecopart_settings' );
