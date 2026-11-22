<?php
/**
 * Data layer (blueprint sections 10.4 and 11): table install and upgrade,
 * caps trimmed on insert, the privacy pass on stored results, activity log
 * events (state changes, activation, the throttled engine_fallback row) and
 * one normalised URL per page across the front end, REST and WP-CLI.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Request_Gate;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * Data layer tests.
 */
class Test_Data_Layer extends Softglaze_A11y_Report_TestCase {

	/**
	 * A minimal report for a URL.
	 *
	 * @param string $url    Normalised URL.
	 * @param array  $result Result.
	 * @return array
	 */
	private static function report( $url, array $result = array() ) {
		return array(
			'url'      => $url,
			'url_hash' => hash( 'sha256', $url ),
			'source'   => 'admin_scan',
			'result'   => $result,
		);
	}

	/**
	 * Both tables exist with the blueprint columns; a second dbDelta() changes nothing; the version is recorded.
	 */
	public function test_tables_and_schema_version() {
		global $wpdb;
		$this->assertTrue( Install::ready() );
		$this->assertSame( '1', get_option( Install::OPTION ) );

		$pages = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', Pages_Store::table_name() ) ), 'Type', 'Field' );
		$this->assertSame( array( 'id', 'url_hash', 'url', 'object_id', 'object_type', 'source', 'scanned_gmt', 'html_bytes', 'engine_us', 'changes_live', 'changes_preview', 'changes_off', 'findings', 'result' ), array_keys( $pages ) );
		$this->assertSame( 'char(64)', $pages['url_hash'] );
		$this->assertSame( 'varchar(2048)', $pages['url'] );
		$this->assertSame( 'longtext', $pages['result'] );
		$log = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', Log_Store::table_name() ) ), 'Type', 'Field' );
		$this->assertSame( array( 'id', 'created_gmt', 'user_id', 'event', 'fix_id', 'from_state', 'to_state', 'url_hash', 'detail' ), array_keys( $log ) );
		$this->assertSame( 'varchar(255)', $log['detail'] );

		$keys = wp_list_pluck( $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', Pages_Store::table_name() ) ), 'Non_unique', 'Key_name' );
		$this->assertSame( '0', (string) $keys['url_hash'], 'url_hash is unique.' );
		$this->assertArrayHasKey( 'scanned_gmt', $keys );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->assertSame( array(), dbDelta( Install::schema(), false ), 'The schema matches; dbDelta() has nothing to change.' );
	}

	/**
	 * Without a current schema version nothing is read or written (no database error on a stale install).
	 */
	public function test_nothing_happens_before_install() {
		delete_option( Install::OPTION );
		$this->assertFalse( Install::ready() );
		$pages = new Pages_Store();
		$log   = new Log_Store();
		$this->assertFalse( $pages->save( self::report( 'http://example.org/a/' ) ) );
		$this->assertNull( $pages->get_by_hash( hash( 'sha256', 'http://example.org/a/' ) ) );
		$this->assertSame( 0, $pages->count() );
		$this->assertFalse( $log->add( 'scan' ) );
		$this->assertSame( array(), $log->recent() );
	}

	/**
	 * The lazy upgrade runs on admin_init only for users who can manage options.
	 */
	public function test_lazy_upgrade_needs_manage_options() {
		$install = Plugin::instance()->install();
		delete_option( Install::OPTION );

		self::login( 'editor' );
		$install->maybe_upgrade();
		$this->assertFalse( Install::ready(), 'Editors never trigger the upgrade.' );

		wp_set_current_user( 0 );
		$install->maybe_upgrade();
		$this->assertFalse( Install::ready(), 'Nor do logged-out requests (admin-ajax.php also fires admin_init).' );

		self::login( 'administrator' );
		$install->maybe_upgrade();
		$this->assertTrue( Install::ready() );
	}

	/**
	 * Activation writes the defaults when absent, creates the tables and logs plugin_activated.
	 */
	public function test_activation() {
		delete_option( Install::OPTION );
		$this->assertNull( get_option( 'softglaze_a11y_settings', null ) );
		Plugin::instance()->install()->activate();
		$this->assertTrue( Install::ready() );
		$this->assertSame( 'preview', get_option( 'softglaze_a11y_settings' )['fixes']['iframe-title'] );
		$rows = self::log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'plugin_activated', $rows[0]['event'] );
		$this->assertSame( SOFTGLAZE_A11Y_VERSION, $rows[0]['detail'] );
	}

	/**
	 * Caps: the report table keeps the newest rows (oldest trimmed on insert); one row per URL.
	 */
	public function test_pages_cap_trims_oldest() {
		$store = new Pages_Store( 3 );
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $slug ) {
			$this->assertTrue( $store->save( self::report( 'http://example.org/' . $slug . '/' ) ) );
		}
		$this->assertSame( 3, $store->count() );
		$this->assertSame( array( 'http://example.org/c/', 'http://example.org/d/', 'http://example.org/e/' ), wp_list_pluck( self::pages_rows(), 'url' ) );
		$this->assertNull( $store->get_by_hash( hash( 'sha256', 'http://example.org/a/' ) ) );

		// Saving an existing URL again moves it to the newest place; the oldest other row goes next.
		$store->save( self::report( 'http://example.org/c/' ) );
		$store->save( self::report( 'http://example.org/f/' ) );
		$this->assertSame( array( 'http://example.org/e/', 'http://example.org/c/', 'http://example.org/f/' ), wp_list_pluck( self::pages_rows(), 'url' ) );
		$this->assertSame( 500, ( new Pages_Store() )->cap() );
	}

	/**
	 * Caps: the log keeps the newest rows.
	 */
	public function test_log_cap_trims_oldest() {
		$store = new Log_Store( 3 );
		foreach ( array( 'one', 'two', 'three', 'four', 'five' ) as $detail ) {
			$this->assertTrue( $store->add( 'scan', array( 'detail' => $detail ) ) );
		}
		$this->assertSame( 3, $store->count() );
		$this->assertSame( array( 'three', 'four', 'five' ), wp_list_pluck( self::log_rows(), 'detail' ) );
		$this->assertSame( 5000, ( new Log_Store() )->cap() );
	}

	/**
	 * Log values are validated: unknown events are refused, states and hashes allow-listed, text sanitized and capped.
	 */
	public function test_log_values_validated() {
		$store = new Log_Store();
		$this->assertFalse( $store->add( 'made_up' ) );
		$this->assertTrue(
			$store->add(
				'fix_state_changed',
				array(
					'user_id'  => 3,
					'fix_id'   => 'Iframe-Title<b>',
					'from'     => 'preview',
					'to'       => 'everything',
					'url_hash' => 'not-a-hash',
					'detail'   => '<script>x</script>' . str_repeat( 'a', 300 ),
				)
			)
		);
		$row = self::log_rows()[0];
		$this->assertSame( '3', $row['user_id'] );
		$this->assertSame( 'iframe-titleb', $row['fix_id'] );
		$this->assertSame( 'preview', $row['from_state'] );
		$this->assertSame( '', $row['to_state'] );
		$this->assertSame( '', $row['url_hash'] );
		$this->assertStringNotContainsString( '<', $row['detail'] );
		$this->assertLessThanOrEqual( 255, mb_strlen( $row['detail'] ) );
	}

	/**
	 * Fix state changes become log rows (user id, from, to); user 0 is WP-CLI.
	 */
	public function test_state_changes_are_logged() {
		$settings = Plugin::instance()->settings();
		$settings->activate();
		$admin = self::login( 'administrator' );
		$settings->save_fix_states( array( 'iframe-title' => 'live' ), $admin );
		$settings->save_fix_states( array( 'iframe-title' => 'off' ), 0 );
		$settings->save_fix_states( array( 'iframe-title' => 'off' ), 0 );

		$rows = self::log_rows();
		$this->assertCount( 2, $rows, 'An unchanged state writes nothing.' );
		$this->assertSame( array( 'fix_state_changed', (string) $admin, 'iframe-title', 'preview', 'live', '' ), array( $rows[0]['event'], $rows[0]['user_id'], $rows[0]['fix_id'], $rows[0]['from_state'], $rows[0]['to_state'], $rows[0]['detail'] ) );
		$this->assertSame( array( '0', 'live', 'off', 'WP-CLI' ), array( $rows[1]['user_id'], $rows[1]['from_state'], $rows[1]['to_state'], $rows[1]['detail'] ) );
	}

	/**
	 * FS-15 with the log row: five identical fallbacks within an hour give one engine_fallback row; another reason gives its own.
	 */
	public function test_fallback_log_row_is_throttled() {
		$post_id = self::post_with_iframe();
		self::set_states( 'live', array( 'page_scope' => false ) );
		add_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		for ( $i = 0; $i < 5; $i++ ) {
			$front = $this->visit( get_permalink( $post_id ) );
			apply_filters( 'the_content', self::IFRAME );
			self::shutdown( $front );
		}
		remove_filter( 'softglaze_a11y_max_ms', '__return_zero' );

		$rows = self::log_rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'engine_fallback', '0', 'time' ), array( $rows[0]['event'], $rows[0]['user_id'], $rows[0]['detail'] ) );
		$this->assertSame( Plugin::instance()->gate()->url_hash(), $rows[0]['url_hash'] );
		$this->assertSame( 0, self::pages_count(), 'A visitor fallback writes no report.' );

		$limit = static function () {
			return 10;
		};
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$front = $this->visit( get_permalink( $post_id ) );
		apply_filters( 'the_content', self::IFRAME );
		self::shutdown( $front );
		$this->assertSame( array( 'time', 'size' ), wp_list_pluck( self::log_rows(), 'detail' ) );
	}

	/**
	 * The fallback row of a logged-in visitor (customer, subscriber, editor)
	 * keeps user 0: the log holds the user ids of administrators only, as the
	 * privacy text says. An administrator's own page view keeps their id.
	 */
	public function test_fallback_log_row_keeps_no_visitor_id() {
		$post_id = self::post_with_iframe();
		self::set_states( 'live', array( 'page_scope' => false ) );
		add_filter( 'softglaze_a11y_max_ms', '__return_zero' );

		foreach ( array( 'subscriber', 'editor' ) as $i => $role ) {
			self::login( $role );
			delete_transient( 'softglaze_a11y_fallback_time' );
			$front = $this->visit( get_permalink( $post_id ) );
			apply_filters( 'the_content', self::IFRAME );
			self::shutdown( $front );
			$rows = self::log_rows();
			$this->assertCount( $i + 1, $rows );
			$this->assertSame( array( 'engine_fallback', '0', 'time' ), array( $rows[ $i ]['event'], $rows[ $i ]['user_id'], $rows[ $i ]['detail'] ), $role );
		}

		$admin = self::login( 'administrator' );
		delete_transient( 'softglaze_a11y_fallback_time' );
		$front = $this->visit( get_permalink( $post_id ) );
		apply_filters( 'the_content', self::IFRAME );
		self::shutdown( $front );
		remove_filter( 'softglaze_a11y_max_ms', '__return_zero' );

		$rows = self::log_rows();
		$this->assertCount( 3, $rows );
		$this->assertSame( (string) $admin, $rows[2]['user_id'] );
	}

	/**
	 * Privacy pass: signatures keep only the allowed attributes; value and
	 * data-* values are dropped; page text is cut to 80 characters and
	 * plugin text to 150; control characters go; lists hold at most 200.
	 */
	public function test_privacy_strip() {
		$changes = array();
		for ( $i = 0; $i < 230; $i++ ) {
			$changes[] = array(
				'fix'     => 'link-name',
				'state'   => 'live',
				'sig'     => 'a.icon[href=/contact/]',
				'attr'    => 'aria-label',
				'before'  => null,
				'after'   => 'Contact',
				'applied' => false,
			);
		}
		$changes[0] = array(
			'fix'     => 'field-label',
			'state'   => 'bogus',
			'sig'     => "input#email.field[type=email][name=email][value=me@example.org][data-secret=x][onclick=steal()][href=/a/]\x07",
			'attr'    => 'value',
			'before'  => 'me@example.org',
			'after'   => str_repeat( 'b', 200 ),
			'applied' => 1,
		);
		$changes[1] = array(
			'fix'    => 'html-lang',
			'state'  => 'preview',
			'sig'    => 'html',
			'attr'   => 'lang',
			'before' => str_repeat( 'x', 120 ),
			'after'  => 'en-GB',
		);
		$changes[2] = array(
			'fix'    => 'link-name',
			'state'  => 'live',
			'sig'    => 'a',
			'attr'   => 'data-id',
			'before' => '42',
			'after'  => '43',
		);
		$result = Pages_Store::privacy_strip(
			array(
				'engine'   => array(
					'scope'    => 'page',
					'ms'       => '3.14159',
					'bytes'    => '1200',
					'fallback' => '<b>time</b>',
					'secret'   => 'dropped',
				),
				'counts'   => array( 'link-name' => array( 'live' => '5', 'evil' => 9 ) ),
				'changes'  => $changes,
				'findings' => array(
					array(
						'check'      => 'R05',
						'sc'         => '4.1.2<x>',
						'sig'        => '"><img src=x onerror=alert(1)>',
						'detail'     => "Line\nbreak",
						'attachment' => '7',
					),
				),
			)
		);

		$this->assertCount( 200, $result['changes'] );
		$this->assertSame( 230, $result['totals']['changes'] );
		$first = $result['changes'][0];
		$this->assertSame( 'input#email.field[type=email][name=email][href=/a/] ', $first['sig'] );
		$this->assertSame( 'off', $first['state'], 'Unknown states become off.' );
		$this->assertNull( $first['before'], 'A value attribute value is never stored.' );
		$this->assertSame( 150, mb_strlen( $first['after'] ) );
		$this->assertTrue( $first['applied'] );
		$this->assertSame( 80, mb_strlen( $result['changes'][1]['before'] ), 'Page text is cut to 80 characters.' );
		$this->assertNull( $result['changes'][2]['before'], 'data-* values are never stored.' );
		$this->assertSame( array( 'off' => 0, 'preview' => 0, 'live' => 5 ), $result['counts']['link-name'] );
		$this->assertSame( 3.142, $result['engine']['ms'] );
		$this->assertSame( 1200, $result['engine']['bytes'] );
		$this->assertSame( 'btimeb', $result['engine']['fallback'] );
		$this->assertArrayNotHasKey( 'secret', $result['engine'] );
		$this->assertSame( '4.1.2', $result['findings'][0]['sc'] );
		$this->assertSame( "Line break", $result['findings'][0]['detail'] );
		$this->assertSame( 7, $result['findings'][0]['attachment'] );
		$this->assertSame( '"><img src=x onerror=alert(1)>', $result['findings'][0]['sig'], 'Stored as text; screens escape it on output (SEC-09, Phase 6B).' );
		$this->assertSame( array( 'R14' ), $result['not_seen'] );
	}

	/**
	 * A stored result is the privacy-stripped one, and the count columns come from the counts.
	 */
	public function test_save_stores_stripped_result_and_counts() {
		$store = new Pages_Store();
		$store->save(
			self::report(
				'http://example.org/x/',
				array(
					'engine'   => array(
						'scope' => 'content',
						'ms'    => 2.5,
						'bytes' => 300,
					),
					'counts'   => array(
						'link-name'    => array(
							'live'    => 2,
							'preview' => 1,
							'off'     => 0,
						),
						'iframe-title' => array(
							'live'    => 0,
							'preview' => 0,
							'off'     => 4,
						),
					),
					'changes'  => array(
						array(
							'fix'    => 'link-name',
							'state'  => 'live',
							'sig'    => 'a[value=secret]',
							'attr'   => 'aria-label',
							'before' => null,
							'after'  => 'Home',
						),
					),
					'findings' => array( array( 'check' => 'R09' ), array( 'check' => 'R02' ) ),
				)
			)
		);
		$row = $store->get_by_hash( hash( 'sha256', 'http://example.org/x/' ) );
		$this->assertSame( array( '2', '1', '4', '2', '300', '2500' ), array( $row['changes_live'], $row['changes_preview'], $row['changes_off'], $row['findings'], $row['html_bytes'], $row['engine_us'] ) );
		$this->assertSame( 'a', $row['result']['changes'][0]['sig'] );
		$this->assertNull( $row['object_id'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $row['scanned_gmt'] );

		$this->assertFalse( $store->save( array_merge( self::report( 'http://example.org/y/' ), array( 'source' => 'visitor' ) ) ), 'Unknown sources are refused.' );
		$this->assertFalse( $store->save( array_merge( self::report( 'http://example.org/y/' ), array( 'url_hash' => 'abc' ) ) ), 'Malformed hashes are refused.' );
	}

	/**
	 * One page, one hash: the front-end request, a REST or WP-CLI URL and the report lookup agree.
	 */
	public function test_one_normalised_url_per_page() {
		$post_id = self::post_with_iframe();
		$scanner = Plugin::instance()->scanner();
		$gate    = Plugin::instance()->gate();

		$this->go_to( home_url( '/?p=' . $post_id . '&utm_source=x&' . Request_Gate::SCAN_ARG . '=1&' . Request_Gate::SCAN_NONCE_ARG . '=abc' ) );
		$front_end = $gate->normalised_url();
		$this->assertSame( 'http://example.org/?p=' . $post_id, $front_end );

		$target = $scanner->validate_target( home_url( '/?utm_source=y&p=' . $post_id . '#top' ) );
		$this->assertSame( $front_end, $scanner->normalise_target( $target ) );
		$this->assertSame( hash( 'sha256', $front_end ), $gate->url_hash() );

		$this->assertSame( 'http://example.org/shop/?paged=2&product=fresh-loaf', Request_Gate::normalise_url( '/shop/', array( 'product' => 'Fresh Loaf', 'paged' => '2', 'page_id' => 'abc', 'other' => '1' ) ) );
		$this->assertSame( 'http://example.org/', Request_Gate::normalise_url( '', array() ) );
	}

	/**
	 * WP-CLI: a single run in the stored shape, stored with source cli and a scan log row by user 0.
	 */
	public function test_cli_report() {
		$post_id = self::post_with_iframe();
		$engine  = new Engine( array( new Fix_Iframe_Title() ), array( 'iframe-title' => 'preview' ) );
		$engine->run( '<p>Tour</p>' . self::IFRAME, Engine::MODE_SCAN, Engine::SCOPE_CONTENT );
		$result = Scanner::result_of_run( $engine->recorder(), Engine::SCOPE_CONTENT );
		$this->assertSame( array( 'iframe-title' => array( 'off' => 0, 'preview' => 1, 'live' => 0 ) ), $result['counts'] );

		$scanner = Plugin::instance()->scanner();
		$url     = $scanner->validate_target( get_permalink( $post_id ) );
		$this->assertTrue( $scanner->store_cli_report( $url, $result ) );
		$row = $scanner->report_for( $url );
		$this->assertSame( 'cli', $row['source'] );
		$this->assertSame( (string) $post_id, $row['object_id'] );
		$this->assertSame( '1', $row['changes_preview'] );
		$this->assertSame( 'content', $row['result']['engine']['scope'] );
		$this->assertSame( 'scan', $row['result']['engine']['mode'] );

		$log = self::log_rows();
		$this->assertSame( array( 'scan', '0' ), array( $log[0]['event'], $log[0]['user_id'] ) );
		$this->assertStringStartsWith( 'source=cli live=0 preview=1 off=0', $log[0]['detail'] );
	}
}
