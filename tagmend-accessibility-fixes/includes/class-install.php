<?php
/**
 * Database tables: creation on activation and lazy upgrades.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the two tables of blueprint section 11 with dbDelta(),
 * and records the schema version in softglaze_a11y_db_version. Activation
 * also writes the default settings (only when absent) and one
 * plugin_activated log row. Upgrades after an update run lazily on
 * admin_init for users who can manage options; scans and admin views, which
 * are already admin-verified, run the same upgrade before they write.
 */
final class Install {

	const DB_VERSION = '1';
	const OPTION     = 'softglaze_a11y_db_version';

	/**
	 * Settings (default options on activation).
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Activity log.
	 *
	 * @var Report\Log_Store
	 */
	private $log;

	/**
	 * Creates the installer.
	 *
	 * @param Settings         $settings Settings.
	 * @param Report\Log_Store $log      Activity log.
	 */
	public function __construct( Settings $settings, Report\Log_Store $log ) {
		$this->settings = $settings;
		$this->log      = $log;
	}

	/**
	 * Hooks the lazy upgrade.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Activation: default settings when absent, both tables, one log row. No redirect.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->settings->activate();
		self::create_tables();
		$this->log->add( 'plugin_activated', array( 'detail' => SOFTGLAZE_A11Y_VERSION ) );
		// TODO(Phase 7): refuse network activation with a translated message.
	}

	/**
	 * Runs dbDelta() when the stored schema version differs, for users who can manage options.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( self::ready() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::create_tables();
	}

	/**
	 * Whether the tables match this version of the plugin.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return self::DB_VERSION === get_option( self::OPTION );
	}

	/**
	 * Creates or upgrades both tables and stores the schema version.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * The CREATE TABLE statements, in the form dbDelta() expects.
	 *
	 * @return string[]
	 */
	public static function schema(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$pages   = Report\Pages_Store::table_name();
		$log     = Report\Log_Store::table_name();

		return array(
			"CREATE TABLE {$pages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(64) NOT NULL,
  url varchar(2048) NOT NULL,
  object_id bigint(20) unsigned DEFAULT NULL,
  object_type varchar(20) NOT NULL DEFAULT '',
  source varchar(20) NOT NULL DEFAULT '',
  scanned_gmt datetime NOT NULL,
  html_bytes int(10) unsigned NOT NULL DEFAULT 0,
  engine_us int(10) unsigned NOT NULL DEFAULT 0,
  changes_live smallint(5) unsigned NOT NULL DEFAULT 0,
  changes_preview smallint(5) unsigned NOT NULL DEFAULT 0,
  changes_off smallint(5) unsigned NOT NULL DEFAULT 0,
  findings smallint(5) unsigned NOT NULL DEFAULT 0,
  result longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY scanned_gmt (scanned_gmt)
) {$collate};",
			"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_gmt datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(40) NOT NULL DEFAULT '',
  fix_id varchar(40) NOT NULL DEFAULT '',
  from_state varchar(10) NOT NULL DEFAULT '',
  to_state varchar(10) NOT NULL DEFAULT '',
  url_hash char(64) NOT NULL DEFAULT '',
  detail varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_gmt (created_gmt)
) {$collate};",
		);
	}
}
