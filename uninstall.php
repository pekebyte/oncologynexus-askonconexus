<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen (or with
 * `wp plugin uninstall`). Deactivation keeps the index and settings intact.
 *
 * Data is preserved when either:
 *  - the admin disabled "Delete tables and settings on uninstall", or
 *  - `ONX_ASK_PRESERVE_DATA` is defined as true in wp-config.php.
 *
 * Multisite: the routine loops every site so a network-activated plugin leaves
 * no orphaned tables or options behind.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( defined( 'ONX_ASK_PRESERVE_DATA' ) && ONX_ASK_PRESERVE_DATA ) {
	return;
}

/**
 * Remove the plugin's data for the current site.
 *
 * Self-contained on purpose: uninstall.php runs without the plugin classes.
 */
function onx_ask_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'onx_ask_settings', array() );
	$delete   = true;

	if ( is_array( $settings ) && array_key_exists( 'delete_data', $settings ) ) {
		$delete = (bool) $settings['delete_data'];
	}

	// Cron events first, so nothing fires while tables are being dropped.
	wp_clear_scheduled_hook( 'onx_ask_daily_sync' );
	wp_clear_scheduled_hook( 'onx_ask_index_batch' );
	wp_clear_scheduled_hook( 'onx_ask_index_post' );

	if ( ! $delete ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'onx_ask_chunks',
		$wpdb->prefix . 'onx_ask_documents',
		$wpdb->prefix . 'onx_ask_logs',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from $wpdb->prefix.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	// Options (including the index queue, version markers and error state).
	// The underscore is escaped so `_` does not act as a LIKE wildcard.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'onx_ask_' ) . '%',
			$wpdb->esc_like( '_transient_onx_ask_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_timeout_onx_ask_' ) . '%',
			$wpdb->esc_like( '_site_transient_onx_ask_' ) . '%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_site_transient_timeout_onx_ask_' ) . '%'
		)
	);

	wp_cache_flush();
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		onx_ask_uninstall_site();
		restore_current_blog();
	}
} else {
	onx_ask_uninstall_site();
}
