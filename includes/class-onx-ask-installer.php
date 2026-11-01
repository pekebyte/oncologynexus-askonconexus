<?php
/**
 * Activation, schema upgrades and cron lifecycle.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Installer {

	/**
	 * Activation: create tables, seed settings, schedule the daily sync.
	 */
	public static function activate() {
		$settings = new OncologyNexus_Ask_Settings();

		$existing = get_option( ONX_ASK_OPTION, null );
		if ( ! is_array( $existing ) ) {
			add_option( ONX_ASK_OPTION, $settings->defaults(), '', 'no' );
		} else {
			update_option( ONX_ASK_OPTION, array_merge( $settings->defaults(), $existing ), false );
		}

		$store = new OncologyNexus_Ask_Vector_Store();
		$store->install();

		if ( false === get_option( 'onx_ask_index_version', false ) ) {
			add_option( 'onx_ask_index_version', '0', '', 'no' );
		}

		if ( ! wp_next_scheduled( 'onx_ask_daily_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'onx_ask_daily_sync' );
		}
	}

	/**
	 * Deactivation: stop cron work but keep the index and settings.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'onx_ask_daily_sync' );
		wp_clear_scheduled_hook( 'onx_ask_index_batch' );
		wp_clear_scheduled_hook( 'onx_ask_index_post' );
	}

	/**
	 * Run on every request; cheap because the option is autoloaded.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'onx_ask_db_version' ) === ONX_ASK_DB_VERSION ) {
			return;
		}

		$store = new OncologyNexus_Ask_Vector_Store();
		$store->install();
	}
}
