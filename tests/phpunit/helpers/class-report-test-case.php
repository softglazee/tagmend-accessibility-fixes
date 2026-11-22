<?php
/**
 * Shared helpers for the data-layer and scan-security suites.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Request_Gate;

require_once __DIR__ . '/class-wiring-test-case.php';

/**
 * Base class: both tables exist (core's test case turns CREATE TABLE into
 * temporary tables, created once per test class), the schema version is
 * current, and requests can be simulated up to shutdown.
 */
abstract class Softglaze_A11y_Report_TestCase extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * Test class whose connection holds the temporary tables (core's test
	 * case reconnects to the database before each class, which drops them).
	 *
	 * @var string
	 */
	private static $tables_for = '';

	/**
	 * Tables and schema version for every test.
	 */
	public function set_up() {
		parent::set_up();
		if ( static::class !== self::$tables_for ) {
			Install::create_tables();
			self::$tables_for = static::class;
		}
		update_option( Install::OPTION, Install::DB_VERSION );
	}

	/**
	 * Rows in the report table.
	 *
	 * @return int
	 */
	protected static function pages_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Pages_Store::table_name() ) );
	}

	/**
	 * Rows in the log table.
	 *
	 * @return int
	 */
	protected static function log_count() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Log_Store::table_name() ) );
	}

	/**
	 * Every report row, oldest first.
	 *
	 * @return array[]
	 */
	protected static function pages_rows() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Pages_Store::table_name() ), ARRAY_A );
	}

	/**
	 * Every log row, oldest first.
	 *
	 * @return array[]
	 */
	protected static function log_rows() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', Log_Store::table_name() ), ARRAY_A );
	}

	/**
	 * A user of a role, made current.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected static function login( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * A URL with the scan arg and, unless false, a scan nonce minted for the
	 * current user and this page (the nonce action holds the page's URL hash).
	 *
	 * @param string      $url   Page URL.
	 * @param string|bool $nonce Nonce to use; true mints one; false leaves it out.
	 * @return string
	 */
	protected static function scan_link( $url, $nonce = true ) {
		$args = array( Request_Gate::SCAN_ARG => '1' );
		if ( true === $nonce ) {
			$args[ Request_Gate::SCAN_NONCE_ARG ] = wp_create_nonce( Request_Gate::scan_nonce_action( Request_Gate::url_hash_of( $url ) ) );
		} elseif ( is_string( $nonce ) ) {
			$args[ Request_Gate::SCAN_NONCE_ARG ] = $nonce;
		}
		return add_query_arg( $args, $url );
	}

	/**
	 * Renders a page through whichever layer the request registered and
	 * returns the response body: the page pass through core's finaliser, or
	 * the_content for the content filters.
	 *
	 * @param Front_End $front Wiring.
	 * @param string    $page  Whole page for the page pass.
	 * @param string    $body  Fragment for the content filters.
	 * @return string
	 */
	protected static function render( $front, $page = self::PAGE, $body = self::IFRAME ) {
		if ( isset( $front->layers()['page'] ) ) {
			return self::finalize_buffer( $page );
		}
		return (string) apply_filters( 'the_content', $body );
	}

	/**
	 * Runs the plugin's shutdown callbacks.
	 *
	 * @param Front_End $front Wiring.
	 * @return void
	 */
	protected static function shutdown( $front ) {
		$front->record_fallbacks();
		$front->record_report();
	}

	/**
	 * The plugin instance's wiring.
	 *
	 * @return Front_End
	 */
	protected static function front() {
		return Plugin::instance()->front_end();
	}
}
