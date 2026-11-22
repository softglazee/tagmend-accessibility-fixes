<?php
/**
 * uninstall.php in both directions (blueprint section 15; docs/05-TEST-PLAN.md
 * FI-8 and FI-9, which are also run live with ZIP installs): with "Delete all
 * data" off nothing is deleted; with it on, no option, transient or post
 * meta of the plugin is left and both tables are gone.
 *
 * Its own class: the uninstall drops the test class's tables.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * Uninstall tests.
 */
class Test_Uninstall extends Softglaze_A11y_Report_TestCase {

	/**
	 * Toggle off: everything survives. Toggle on: everything goes.
	 */
	public function test_uninstall_both_ways() {
		global $wpdb;
		Plugin::instance()->settings()->activate();
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_softglaze_a11y_skip_fixes', array( 'link-name' ) );
		set_transient( 'softglaze_a11y_fallback_size', 1, HOUR_IN_SECONDS );
		set_transient( 'softglaze_a11y_fix_totals', array( 'key' => '1:1' ), HOUR_IN_SECONDS );
		update_option( Attachment_Lookup::MAP_OPTION, array( '2026/10/a.jpg' => 6 ), false );
		Plugin::instance()->log()->add( 'plugin_activated', array( 'detail' => 'test' ) );
		$hash = hash( 'sha256', 'http://example.org/' );
		Plugin::instance()->pages()->save(
			array(
				'url'      => 'http://example.org/',
				'url_hash' => $hash,
				'source'   => 'cli',
				'result'   => array(),
			)
		);
		$this->assertSame( 1, self::pages_count() );
		$this->assertGreaterThan( 0, self::log_count() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'tagmend-accessibility-fixes/tagmend-accessibility-fixes.php' );
		}

		// Off (the default): uninstall.php deletes nothing.
		$this->assertFalse( Plugin::instance()->settings()->delete_data_on_uninstall() );
		include SOFTGLAZE_A11Y_DIR . 'uninstall.php';
		$this->assertIsArray( get_option( Settings::OPTION ) );
		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION ) );
		$this->assertSame( array( 'link-name' ), get_post_meta( $post_id, '_softglaze_a11y_skip_fixes', true ) );
		$this->assertSame( 1, get_transient( 'softglaze_a11y_fallback_size' ) );
		$this->assertSame( array( '2026/10/a.jpg' => 6 ), get_option( Attachment_Lookup::MAP_OPTION ) );
		$this->assertSame( 1, self::pages_count() );
		$this->assertGreaterThan( 0, self::log_count() );

		// On: every option, transient and post meta of the plugin is gone, and both tables.
		update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => '1' ) );
		$this->assertTrue( Plugin::instance()->settings()->delete_data_on_uninstall() );
		softglaze_a11y_uninstall_site();
		wp_cache_flush();

		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, '%' . $wpdb->esc_like( 'softglaze_a11y' ) . '%' ) ) );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, '%' . $wpdb->esc_like( 'softglaze_a11y' ) . '%' ) ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Attachment_Lookup::MAP_OPTION ), 'The saved image address map is gone (Phase 8B C3).' );
		$this->assertFalse( get_transient( 'softglaze_a11y_fallback_size' ) );
		$wpdb->suppress_errors( true );
		foreach ( array( Pages_Store::table_name(), Log_Store::table_name() ) as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), $table . ' is gone' );
		}
		$wpdb->suppress_errors( false );
	}
}
