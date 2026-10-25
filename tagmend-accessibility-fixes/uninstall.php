<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * By default nothing is deleted, so a reinstall finds its settings, reports
 * and activity log again. Only when "Delete all data when the plugin is
 * deleted" is on in Settings (blueprint section 15) are the settings, the
 * schema version, the plugin's transients, the per-page skip lists, the
 * object cache group and both tables removed. On a multisite network each
 * site decides for itself.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the plugin's data on the current site when its settings ask for it.
 *
 * @return void
 */
function softglaze_a11y_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'softglaze_a11y_settings' );
	if ( ! is_array( $settings ) || ! isset( $settings['delete_data_on_uninstall'] ) || true !== $settings['delete_data_on_uninstall'] ) {
		return;
	}

	delete_option( 'softglaze_a11y_settings' );
	delete_option( 'softglaze_a11y_db_version' );

	// Known transients first (also covers a persistent object cache), then any other with the prefix.
	foreach ( array( 'size', 'encoding', 'time', 'incomplete', 'ordinal', 'apply', 'sanity', 'exception' ) as $reason ) {
		delete_transient( 'softglaze_a11y_fallback_' . $reason );
	}
	delete_transient( 'softglaze_a11y_fix_totals' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of the plugin's own transients on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_softglaze_a11y_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_softglaze_a11y_' ) . '%'
		)
	);

	delete_post_meta_by_key( '_softglaze_a11y_skip_fixes' );

	if ( function_exists( 'wp_cache_supports' ) && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'softglaze_a11y' );
	}

	foreach ( array( 'softglaze_a11y_pages', 'softglaze_a11y_log' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the plugin's own tables on uninstall, when the administrator chose so.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $softglaze_a11y_site_id ) {
		switch_to_blog( (int) $softglaze_a11y_site_id );
		softglaze_a11y_uninstall_site();
		restore_current_blog();
	}
} else {
	softglaze_a11y_uninstall_site();
}
