<?php
/**
 * Phase 6B screens: Reports, Report details, Activity log and their
 * admin-post handlers. SEC-08 (CSV export without capability), SEC-09
 * (stored markup shown as text) and SEC-10 (list-table sort injection) from
 * docs/05-TEST-PLAN.md section 5, plus capability-then-nonce refusals for
 * Move to Live, skip a fix on a page and delete a report.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Admin\Admin_Bar;
use SoftGlaze\AccessibilityRepair\Admin\Log_List_Table;
use SoftGlaze\AccessibilityRepair\Admin\Pages_List_Table;
use SoftGlaze\AccessibilityRepair\Admin\Report_View;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Fixes;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Log;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Report_Details;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Reports;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Settings;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Rest_Controller;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * Report, details and log screen tests.
 */
class Test_Report_Screens extends Softglaze_A11y_Report_TestCase {

	/**
	 * Admin includes the list tables and screens need outside wp-admin.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	/**
	 * Saved defaults and an empty request before every test.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::instance()->settings()->activate();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Stores a report and returns its hash.
	 *
	 * @param string $url    Normalised URL.
	 * @param array  $result Result.
	 * @param array  $extra  Other row fields (object_id, object_type, source).
	 * @return string
	 */
	private static function store( $url, array $result = array(), array $extra = array() ) {
		$hash = hash( 'sha256', $url );
		Plugin::instance()->pages()->save(
			array_merge(
				array(
					'url'      => $url,
					'url_hash' => $hash,
					'source'   => 'admin_scan',
					'result'   => $result,
				),
				$extra
			)
		);
		return $hash;
	}

	/**
	 * A result in the stored shape.
	 *
	 * @param array  $changes  Changes.
	 * @param array  $findings Findings.
	 * @param string $scope    Scope.
	 * @param mixed  $reason   Scope reason.
	 * @return array
	 */
	private static function result( array $changes = array(), array $findings = array(), $scope = 'page', $reason = null ) {
		$counts = array();
		foreach ( $changes as $change ) {
			if ( ! isset( $counts[ $change['fix'] ] ) ) {
				$counts[ $change['fix'] ] = array(
					'live'    => 0,
					'preview' => 0,
					'off'     => 0,
				);
			}
			++$counts[ $change['fix'] ][ $change['state'] ];
		}
		return array(
			'engine'   => array(
				'scope'        => $scope,
				'scope_reason' => $reason,
				'ms'           => 1.5,
				'bytes'        => 1000,
			),
			'counts'   => $counts,
			'changes'  => $changes,
			'findings' => $findings,
		);
	}

	/**
	 * Runs a handler and returns the wp_die() code, or 0 when it did not die.
	 *
	 * @param callable $handler Handler returning a URL.
	 * @return int
	 */
	private static function die_code( $handler ) {
		try {
			call_user_func( $handler );
		} catch ( WPDieException $exception ) {
			return (int) $exception->getCode();
		}
		return 0;
	}

	/**
	 * Renders the details of one report.
	 *
	 * @param string $hash   URL hash.
	 * @param string $status Status.
	 * @return string
	 */
	private static function details_html( $hash, $status = '' ) {
		return get_echo( array( Plugin::instance()->reports_screen()->details(), 'render' ), array( $hash, $status ) );
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below the namespace.
	 * @param array  $params Parameters.
	 * @return WP_REST_Response
	 */
	private static function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Rest_Controller::ROUTE_NAMESPACE . $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The menu has Overview, Fixes, Reports, Activity log and Settings;
	 * admin.css and admin.js load on those screens only, admin.js with
	 * wp-api-fetch and wp-i18n and no inline data of ours.
	 */
	public function test_menu_and_assets() {
		global $submenu;
		self::login( 'administrator' );
		unset( $submenu[ Plugin::MENU_SLUG ] );
		Plugin::instance()->register_menu();
		$slugs = array_values( wp_list_pluck( $submenu[ Plugin::MENU_SLUG ], 2 ) );
		$this->assertSame( array( Plugin::MENU_SLUG, Screen_Fixes::PAGE, Screen_Reports::PAGE, Screen_Log::PAGE, Screen_Settings::PAGE ), $slugs );

		wp_dequeue_script( 'softglaze-a11y-admin' );
		wp_dequeue_style( 'softglaze-a11y-admin' );
		foreach ( array( 'index.php', 'options-general.php', 'toplevel_page_other', 'settings_page_softglaze-a11y' ) as $other ) {
			Plugin::instance()->enqueue_admin_assets( $other );
		}
		$this->assertFalse( wp_script_is( 'softglaze-a11y-admin', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'softglaze-a11y-admin', 'enqueued' ) );

		$hooks = array( 'toplevel_page_' . Plugin::MENU_SLUG );
		foreach ( array( Screen_Fixes::PAGE, Screen_Reports::PAGE, Screen_Log::PAGE, Screen_Settings::PAGE ) as $page ) {
			$hooks[] = get_plugin_page_hookname( $page, Plugin::MENU_SLUG );
		}
		foreach ( $hooks as $hook ) {
			Plugin::instance()->enqueue_admin_assets( $hook );
			$this->assertTrue( wp_style_is( 'softglaze-a11y-admin', 'enqueued' ), $hook );
			$this->assertTrue( wp_script_is( 'softglaze-a11y-admin', 'enqueued' ), $hook );
			wp_dequeue_script( 'softglaze-a11y-admin' );
			wp_dequeue_style( 'softglaze-a11y-admin' );
		}

		Plugin::instance()->enqueue_admin_assets( get_plugin_page_hookname( Screen_Reports::PAGE, Plugin::MENU_SLUG ) );
		$this->assertTrue( wp_script_is( 'softglaze-a11y-admin', 'enqueued' ) );
		$script = wp_scripts()->registered['softglaze-a11y-admin'];
		$this->assertSame( array( 'wp-api-fetch', 'wp-i18n' ), $script->deps );
		$this->assertTrue( wp_scripts()->registered['wp-api-fetch'] instanceof _WP_Dependency, 'wp-api-fetch is registered by core.' );
		$this->assertTrue( wp_scripts()->registered['wp-i18n'] instanceof _WP_Dependency, 'wp-i18n is registered by core.' );
		$this->assertFalse( wp_scripts()->get_data( 'softglaze-a11y-admin', 'after' ), 'No inline script after ours.' );
		$this->assertFalse( wp_scripts()->get_data( 'softglaze-a11y-admin', 'before' ), 'No inline script before ours.' );
		$this->assertFalse( wp_scripts()->get_data( 'softglaze-a11y-admin', 'data' ), 'No localized data.' );
		$this->assertSame( 'tagmend-accessibility-fixes', $script->textdomain );
		wp_dequeue_script( 'softglaze-a11y-admin' );
		wp_dequeue_style( 'softglaze-a11y-admin' );
	}

	/**
	 * SEC-08: the CSV export refuses users without manage_options (subscriber
	 * and editor with a nonce for their own session, logged out) and an
	 * administrator without a valid nonce, with 403 and no output.
	 */
	public function test_sec08_csv_export_refused_without_capability_or_nonce() {
		$screen = Plugin::instance()->log_screen();
		Plugin::instance()->log()->add( 'scan', array( 'detail' => 'x' ) );

		self::login( 'administrator' );
		$_POST = array();
		$this->assertSame( 403, self::die_code( array( $screen, 'process_export' ) ), 'administrator, no nonce' );
		$_POST[ Screen_Log::NONCE_ARG ] = wp_create_nonce( Screen_Fixes::ACTION );
		$this->assertSame( 403, self::die_code( array( $screen, 'process_export' ) ), 'administrator, nonce for another action' );

		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			self::login( $role );
			$_POST[ Screen_Log::NONCE_ARG ] = wp_create_nonce( Screen_Log::EXPORT_ACTION );
			$this->assertSame( 403, self::die_code( array( $screen, 'process_export' ) ), "$role with own nonce" );
		}
		wp_set_current_user( 0 );
		$_POST[ Screen_Log::NONCE_ARG ] = wp_create_nonce( Screen_Log::EXPORT_ACTION );
		$this->assertSame( 403, self::die_code( array( $screen, 'process_export' ) ), 'logged out' );

		ob_start();
		self::die_code( array( $screen, 'handle_export' ) );
		$this->assertSame( '', ob_get_clean(), 'A refused export prints nothing.' );
	}

	/**
	 * The export streams the log as CSV, newest first, with the event filter
	 * from the form (allow-listed), and every cell that a spreadsheet could
	 * run as a formula starts with an apostrophe.
	 */
	public function test_csv_export_streams_neutralised_cells() {
		$log   = Plugin::instance()->log();
		$admin = self::login( 'administrator' );
		wp_update_user(
			array(
				'ID'           => $admin,
				'display_name' => '=cmd|\' /C calc\'!A0',
			)
		);
		foreach ( array( '=HYPERLINK("http://example.org","x")', '+1+1', '-2', '@SUM(A1)', 'plain' ) as $detail ) {
			$log->add(
				'fix_skip_added',
				array(
					'fix_id' => 'iframe-title',
					'detail' => $detail,
				)
			);
		}
		$log->add( 'scan', array( 'detail' => 'source=admin_scan live=1 preview=2 off=3 findings=4 scope=page' ) );

		$screen                         = Plugin::instance()->log_screen();
		$_POST[ Screen_Log::NONCE_ARG ] = wp_create_nonce( Screen_Log::EXPORT_ACTION );
		$_POST[ Screen_Log::EVENT_ARG ] = '';
		$this->assertSame( '', $screen->process_export() );

		$csv  = get_echo( array( $screen, 'stream_csv' ), array( '' ) );
		$rows = array_map(
			static function ( $line ) {
				return str_getcsv( $line, ',', '"', '' );
			},
			array_values( array_filter( explode( "\n", $csv ) ) )
		);
		$this->assertSame( array( 'Date (UTC)', 'Event', 'User', 'Fix', 'From', 'To', 'Page', 'Detail' ), $rows[0] );
		$this->assertCount( 7, $rows, 'Header and six rows.' );
		$this->assertSame( 'Page scanned', $rows[1][1], 'Newest first.' );
		$this->assertSame( 'Scan: 1 Live, 2 Preview, 3 Off, 4 need a person; whole page', $rows[1][7] );
		$this->assertSame( "'=cmd|' /C calc'!A0", $rows[1][2], 'User names are decoded (core stores entities) and neutralised.' );
		$details = wp_list_pluck( array_slice( $rows, 2 ), 7 );
		$this->assertSame( array( 'plain', "'@SUM(A1)", "'-2", "'+1+1", "'=HYPERLINK(\"http://example.org\",\"x\")" ), $details );
		foreach ( array_slice( $rows, 1 ) as $row ) {
			foreach ( $row as $cell ) {
				$this->assertNotContains( substr( $cell, 0, 1 ), array( '=', '+', '-', '@', "\t", "\r" ), $cell );
			}
		}

		$_POST[ Screen_Log::EVENT_ARG ] = 'scan';
		$this->assertSame( 'scan', $screen->process_export() );
		$rows = array_values( array_filter( explode( "\n", get_echo( array( $screen, 'stream_csv' ), array( 'scan' ) ) ) ) );
		$this->assertCount( 2, $rows, 'Header and the one scan row.' );

		$_POST[ Screen_Log::EVENT_ARG ] = "scan' OR '1'='1";
		$this->assertSame( '', $screen->process_export(), 'An unknown event is ignored.' );

		$this->assertSame( "'\tx", Screen_Log::csv_cell( "\tx" ) );
		$this->assertSame( "'\rx", Screen_Log::csv_cell( "\rx" ) );
		$this->assertSame( 'a=b', Screen_Log::csv_cell( 'a=b' ) );
		$this->assertSame( '12', Screen_Log::csv_cell( 12 ) );
		$this->assertSame( '', Screen_Log::csv_cell( array( 'x' ) ) );
	}

	/**
	 * SEC-09: markup stored in a report (a signature holding a script tag,
	 * hostile before/after text, a finding detail) is shown as text on the
	 * details screen and in the list.
	 */
	public function test_sec09_stored_markup_shown_as_text() {
		self::login( 'administrator' );
		$hostile = '"><script>alert(1)</script>';
		$hash    = self::store(
			'http://example.org/hostile/',
			self::result(
				array(
					array(
						'fix'    => 'link-name',
						'state'  => 'preview',
						'sig'    => 'a.x' . $hostile,
						'attr'   => 'aria-label',
						'before' => '<img src=x onerror=alert(2)>',
						'after'  => $hostile,
					),
				),
				array(
					array(
						'check'  => 'R05',
						'sc'     => '4.1.2',
						'sig'    => '<svg onload=alert(5)>',
						'detail' => '<script>alert(4)</script>',
					),
				)
			)
		);
		$row = Plugin::instance()->pages()->get_by_hash( $hash );
		$this->assertStringContainsString( '<script>alert(1)</script>', $row['result']['changes'][0]['sig'], 'The stored signature still holds the markup.' );

		$html = self::details_html( $hash );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<svg onload', $html );
		$this->assertStringContainsString( 'a.x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(2)&gt;', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(4)&lt;/script&gt;', $html );
		$this->assertStringContainsString( '&lt;svg onload=alert(5)&gt;', $html );

		$table = new Pages_List_Table( Plugin::instance()->scanner(), array( 'screen' => 'softglaze-a11y-test' ) );
		$table->prepare_items();
		$list = get_echo( array( $table, 'display' ) );
		$this->assertStringNotContainsString( '<script', $list );
		$this->assertStringContainsString( 'http://example.org/hostile/', $list );
	}

	/**
	 * SEC-10: hostile orderby/order values fall back to the allow-listed
	 * defaults and never reach the query; allowed keys map to their columns.
	 */
	public function test_sec10_sort_injection_is_allow_listed() {
		global $wpdb;
		self::login( 'administrator' );
		self::store( 'http://example.org/a/' );
		self::store( 'http://example.org/b/' );

		$hostile = array(
			array( 'url; DROP TABLE wp_users; --', 'DESC; DELETE FROM wp_options' ),
			array( 'changes_live`, (SELECT 1)', 'asc,id' ),
			array( 'scanned_gmt', '1=1' ),
			array( array( 'url' ), array( 'asc' ) ),
		);
		foreach ( $hostile as $case ) {
			$_GET['orderby'] = $case[0];
			$_GET['order']   = $case[1];
			$this->assertContains( Pages_List_Table::orderby(), array_keys( Pages_Store::SORT_COLUMNS ) );
			$this->assertContains( Pages_List_Table::order(), array( 'asc', 'desc' ) );
			$table = new Pages_List_Table( Plugin::instance()->scanner(), array( 'screen' => 'softglaze-a11y-test' ) );
			$table->prepare_items();
			$this->assertMatchesRegularExpression( '/ORDER BY `id` DESC, id DESC LIMIT 20 OFFSET 0$/', $wpdb->last_query, wp_json_encode( $case ) );
			$this->assertStringNotContainsStringIgnoringCase( 'drop', $wpdb->last_query );
			$this->assertStringNotContainsStringIgnoringCase( 'delete', $wpdb->last_query );
			$this->assertCount( 2, $table->items );
		}

		$map = array(
			'url'      => 'url',
			'scanned'  => 'id',
			'live'     => 'changes_live',
			'findings' => 'findings',
		);
		foreach ( $map as $key => $column ) {
			$_GET = array(
				'orderby' => $key,
				'order'   => 'asc',
			);
			$table = new Pages_List_Table( Plugin::instance()->scanner(), array( 'screen' => 'softglaze-a11y-test' ) );
			$table->prepare_items();
			$this->assertStringContainsString( 'ORDER BY `' . $column . '` ASC, id ASC', $wpdb->last_query );
		}

		Plugin::instance()->log()->add( 'scan', array( 'detail' => 'x' ) );
		$_GET = array(
			'orderby'              => 'user_id',
			'order'                => 'up',
			Screen_Log::EVENT_ARG  => "scan' OR '1'='1",
			'paged'                => '1; DROP TABLE wp_users',
		);
		$this->assertSame( 'date', Log_List_Table::orderby() );
		$this->assertSame( 'desc', Log_List_Table::order() );
		$this->assertSame( '', Screen_Log::requested_event() );
		$table = new Log_List_Table( Plugin::instance()->log_screen(), array( 'screen' => 'softglaze-a11y-test' ) );
		$table->prepare_items();
		$this->assertMatchesRegularExpression( "/WHERE \\( '' = '' OR l\\.event = '' \\) ORDER BY l\\.`id` DESC, l\\.id DESC LIMIT 50 OFFSET 0$/", $wpdb->last_query );
		$this->assertStringNotContainsStringIgnoringCase( 'drop', $wpdb->last_query );

		$_GET = array(
			'orderby'             => 'event',
			Screen_Log::EVENT_ARG => 'scan',
		);
		$table = new Log_List_Table( Plugin::instance()->log_screen(), array( 'screen' => 'softglaze-a11y-test' ) );
		$table->prepare_items();
		$this->assertStringContainsString( "WHERE ( 'scan' = '' OR l.event = 'scan' ) ORDER BY l.`event` ASC", $wpdb->last_query );
		$this->assertNotEmpty( $table->items );
		foreach ( $table->items as $item ) {
			$this->assertSame( 'scan', $item['event'] );
		}
	}

	/**
	 * Move to Live: capability first, then the nonce; a registered fix that
	 * can run here goes Live and is logged; unknown and unavailable fixes are refused.
	 */
	public function test_go_live_handler() {
		$details = Plugin::instance()->reports_screen()->details();
		$hash    = self::store( 'http://example.org/page/' );
		$before  = get_option( Settings::OPTION );

		self::login( 'administrator' );
		$_POST = array(
			Screen_Reports::REPORT_ARG       => $hash,
			Screen_Report_Details::FIX_FIELD => 'iframe-title',
		);
		$this->assertSame( 403, self::die_code( array( $details, 'process_go_live' ) ), 'no nonce' );
		$_POST[ Screen_Reports::NONCE_ARG ] = wp_create_nonce( Screen_Report_Details::SKIP_ACTION );
		$this->assertSame( 403, self::die_code( array( $details, 'process_go_live' ) ), 'nonce for another action' );
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			self::login( $role );
			$_POST[ Screen_Reports::NONCE_ARG ] = wp_create_nonce( Screen_Report_Details::GO_LIVE_ACTION );
			$this->assertSame( 403, self::die_code( array( $details, 'process_go_live' ) ), $role );
		}
		$this->assertSame( $before, get_option( Settings::OPTION ) );

		$admin                              = self::login( 'administrator' );
		$_POST[ Screen_Reports::NONCE_ARG ] = wp_create_nonce( Screen_Report_Details::GO_LIVE_ACTION );
		$logs                               = self::log_count();
		$url                                = $details->process_go_live();
		$this->assertStringContainsString( Screen_Reports::REPORT_ARG . '=' . $hash, $url );
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=live', $url );
		$this->assertSame( 'live', Plugin::instance()->settings()->fix_states()['iframe-title'] );
		$rows = self::log_rows();
		$this->assertSame( $logs + 1, count( $rows ) );
		$last = end( $rows );
		$this->assertSame( array( 'fix_state_changed', 'iframe-title', 'preview', 'live', (string) $admin ), array( $last['event'], $last['fix_id'], $last['from_state'], $last['to_state'], (string) $last['user_id'] ) );

		$_POST[ Screen_Report_Details::FIX_FIELD ] = 'made-up';
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=live_refused', $details->process_go_live() );
		$this->assertArrayNotHasKey( 'made-up', get_option( Settings::OPTION )['fixes'] );

		// WooCommerce is not loaded in the tests, so F04 cannot go Live.
		$_POST[ Screen_Report_Details::FIX_FIELD ] = 'product-img-alt-name';
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=live_refused', $details->process_go_live() );
		$this->assertSame( 'off', Plugin::instance()->settings()->fix_states()['product-img-alt-name'] );
	}

	/**
	 * Skip on this page: the post comes from the stored report; the meta the
	 * gate reads is written and removed; both changes are logged; pages that
	 * are not single posts and missing reports are refused.
	 */
	public function test_skip_fix_handler() {
		$details = Plugin::instance()->reports_screen()->details();
		$post_id = self::post_with_iframe();
		$url     = Plugin::instance()->scanner()->normalise_target( get_permalink( $post_id ) );
		$hash    = self::store(
			$url,
			array(),
			array(
				'object_id'   => $post_id,
				'object_type' => 'post',
			)
		);

		self::login( 'editor' );
		$_POST = array(
			Screen_Reports::REPORT_ARG        => $hash,
			Screen_Report_Details::FIX_FIELD  => 'iframe-title',
			Screen_Report_Details::SKIP_FIELD => '1',
			Screen_Reports::NONCE_ARG         => wp_create_nonce( Screen_Report_Details::SKIP_ACTION ),
		);
		$this->assertSame( 403, self::die_code( array( $details, 'process_skip' ) ), 'editor' );
		self::login( 'administrator' );
		$_POST[ Screen_Reports::NONCE_ARG ] = 'stale';
		$this->assertSame( 403, self::die_code( array( $details, 'process_skip' ) ), 'stale nonce' );
		$this->assertSame( '', get_post_meta( $post_id, Screen_Report_Details::SKIP_META, true ) );

		$_POST[ Screen_Reports::NONCE_ARG ] = wp_create_nonce( Screen_Report_Details::SKIP_ACTION );
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=skipped', $details->process_skip() );
		$this->assertSame( array( 'iframe-title' ), get_post_meta( $post_id, Screen_Report_Details::SKIP_META, true ) );
		$posted = $_POST;
		$this->go_to( get_permalink( $post_id ) );
		Plugin::instance()->gate()->reset();
		$this->assertSame( array( 'iframe-title' ), Plugin::instance()->gate()->skipped_fixes(), 'The gate reads the same meta.' );
		$_POST = $posted;
		$this->assertStringContainsString( 'Skipped on this page.', self::details_html_with_change( $hash ) );
		$this->assertStringContainsString( 'Stop skipping on this page<span class="screen-reader-text">: Titles for embedded frames</span>', self::details_html( $hash ) );

		$_POST[ Screen_Report_Details::SKIP_FIELD ] = '0';
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=unskipped', $details->process_skip() );
		$this->assertSame( '', get_post_meta( $post_id, Screen_Report_Details::SKIP_META, true ) );
		$events = wp_list_pluck( self::log_rows(), 'event' );
		$this->assertSame( array( 'fix_skip_added', 'fix_skip_removed' ), array_values( array_intersect( $events, array( 'fix_skip_added', 'fix_skip_removed' ) ) ) );
		$rows = self::log_rows();
		$last = end( $rows );
		$this->assertSame( array( 'iframe-title', $hash, 'post=' . $post_id ), array( $last['fix_id'], $last['url_hash'], $last['detail'] ) );

		$home                                = self::store( 'http://example.org/', array(), array( 'object_type' => 'home' ) );
		$_POST[ Screen_Reports::REPORT_ARG ] = $home;
		$_POST[ Screen_Report_Details::SKIP_FIELD ] = '1';
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=skip_refused', $details->process_skip() );
		$_POST[ Screen_Reports::REPORT_ARG ] = str_repeat( 'a', 64 );
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=not_found', $details->process_skip() );
		$_POST[ Screen_Reports::REPORT_ARG ]       = $hash;
		$_POST[ Screen_Report_Details::FIX_FIELD ] = 'made-up';
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=skip_refused', $details->process_skip() );
	}

	/**
	 * Details of a report with one change of the given fix (for the skip marker).
	 *
	 * @param string $hash URL hash.
	 * @return string
	 */
	private static function details_html_with_change( $hash ) {
		$row = Plugin::instance()->pages()->get_by_hash( $hash );
		self::store(
			$row['url'],
			self::result(
				array(
					array(
						'fix'    => 'iframe-title',
						'state'  => 'off',
						'sig'    => 'iframe',
						'attr'   => 'title',
						'before' => null,
						'after'  => 'YouTube video',
					),
				)
			),
			array(
				'object_id'   => (int) $row['object_id'],
				'object_type' => (string) $row['object_type'],
			)
		);
		return self::details_html( $hash );
	}

	/**
	 * Delete: capability first, then the nonce (POST only); the row goes; a second delete says not found.
	 */
	public function test_delete_report_handler() {
		$screen = Plugin::instance()->reports_screen();
		$hash   = self::store( 'http://example.org/delete-me/' );

		self::login( 'editor' );
		$_POST = array(
			Screen_Reports::REPORT_ARG => $hash,
			Screen_Reports::NONCE_ARG  => wp_create_nonce( Screen_Reports::DELETE_ACTION ),
		);
		$this->assertSame( 403, self::die_code( array( $screen, 'process_delete' ) ) );
		self::login( 'administrator' );
		$_POST[ Screen_Reports::NONCE_ARG ] = wp_create_nonce( Screen_Report_Details::GO_LIVE_ACTION );
		$this->assertSame( 403, self::die_code( array( $screen, 'process_delete' ) ) );
		$this->assertSame( 1, self::pages_count() );

		// A2: a GET link with a valid nonce deletes nothing; only the confirmation form's POST does.
		$_POST = array();
		$_GET  = array(
			Screen_Reports::REPORT_ARG => $hash,
			Screen_Reports::NONCE_ARG  => wp_create_nonce( Screen_Reports::DELETE_ACTION ),
		);
		$this->assertSame( 403, self::die_code( array( $screen, 'process_delete' ) ) );
		$this->assertSame( 1, self::pages_count() );
		$_GET = array();

		$_POST = array(
			Screen_Reports::REPORT_ARG => $hash,
			Screen_Reports::NONCE_ARG  => wp_create_nonce( Screen_Reports::DELETE_ACTION ),
		);
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=deleted', $screen->process_delete() );
		$this->assertSame( 0, self::pages_count() );
		$this->assertStringContainsString( Screen_Reports::STATUS_ARG . '=not_found', $screen->process_delete() );
	}

	/**
	 * A2 (Phase 8B): the "Delete report" row action opens a confirmation view
	 * that names the page, deletes nothing on its own, and offers a POST
	 * button with the nonce and a Cancel link.
	 */
	public function test_delete_report_confirmation() {
		$hash = self::store( 'http://example.org/delete-me/' );
		self::login( 'administrator' );

		$url = Screen_Reports::delete_url( $hash );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$this->assertStringStartsWith( admin_url( 'admin.php' ), $url );
		$this->assertSame( Screen_Reports::STEP_DELETE, $args[ Screen_Reports::STEP_ARG ] );
		$this->assertArrayNotHasKey( Screen_Reports::NONCE_ARG, $args, 'The link itself carries no nonce and deletes nothing.' );

		$_GET = $args;
		$html = get_echo( array( Plugin::instance()->reports_screen(), 'render' ) );
		$_GET = array();
		$this->assertSame( 1, self::pages_count(), 'Opening the confirmation deletes nothing.' );
		$this->assertStringContainsString( '<h1>Delete report</h1>', $html );
		$this->assertStringContainsString( 'http://example.org/delete-me/', $html );
		$this->assertMatchesRegularExpression( '/<form method="post" action="[^"]*admin-post\.php">/', $html );
		$this->assertStringContainsString( 'name="action" value="' . Screen_Reports::DELETE_ACTION . '"', $html );
		$this->assertMatchesRegularExpression( '/name="' . Screen_Reports::NONCE_ARG . '" value="([0-9a-f]+)"/', $html );
		preg_match( '/name="' . Screen_Reports::NONCE_ARG . '" value="([0-9a-f]+)"/', $html, $match );
		$this->assertSame( 1, wp_verify_nonce( $match[1], Screen_Reports::DELETE_ACTION ) );
		$this->assertStringContainsString( '<button type="submit" class="button button-primary">Delete report</button>', $html );
		$this->assertStringContainsString( '>Cancel</a>', $html );
		$this->assertStringNotContainsString( '<h1>Report details</h1>', $html );
	}

	/**
	 * The details header: scope note with its reason (blueprint section
	 * 10.3), the logged-in administrator note, the WP-CLI note, the note on
	 * the two hook-only fixes, the stale marker and the fallback note.
	 */
	public function test_details_notes() {
		global $wpdb;
		self::login( 'administrator' );

		$hash = self::store( 'http://example.org/one/', self::result( array(), array(), 'content', Scanner::REASON_PAGE_BUFFER_OFF ) );
		$html = self::details_html( $hash );
		$this->assertStringContainsString( '<h1>Report details</h1>', $html );
		$this->assertStringContainsString( 'This report covers post content, template parts, widgets and menus only. Another plugin switched off the WordPress page buffer on this site (some page builders do)', $html );
		$this->assertStringContainsString( 'Scans see the page as a logged-in administrator.', $html );
		$this->assertStringContainsString( 'Two fixes never appear in scan reports, because they work only through WordPress hooks while the page is built: &quot;Product name as alt text for product images&quot; and &quot;Menu location names for theme menus&quot;.', $html );
		$this->assertStringContainsString( 'No fix changes anything on this page.', $html );
		$this->assertStringContainsString( 'Not checked by this plugin', $html );
		$this->assertStringContainsString( 'data-softglaze-a11y-scanner="1"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );

		$hash = self::store( 'http://example.org/two/', self::result( array(), array(), 'content', Scanner::REASON_NEEDS_WP69 ), array( 'source' => 'cli' ) );
		$html = self::details_html( $hash );
		$this->assertStringContainsString( 'Page-level checks (head, theme header and footer, skip links, repeated ids) need WordPress 6.9 or later.', $html );
		$this->assertStringContainsString( 'This report was made with WP-CLI from a saved copy of the page.', $html );
		$this->assertStringNotContainsString( 'Scans see the page as a logged-in administrator.', $html );

		$hash = self::store( 'http://example.org/three/', self::result(), array( 'source' => 'admin_view' ) );
		$html = self::details_html( $hash );
		$this->assertStringContainsString( 'This report covers the whole page.', $html );
		$this->assertStringNotContainsString( 'Two fixes never appear', $html, 'A view records the hook-only changes itself.' );

		$result                       = self::result();
		$result['engine']['fallback'] = 'time';
		$result['engine']['fallbacks'] = array( 'time' );
		$hash                         = self::store( 'http://example.org/four/', $result );
		$this->assertStringContainsString( 'The repair engine left part of this page unchanged: the repair took longer than the time limit.', self::details_html( $hash ) );

		$post_id = self::post_with_iframe();
		$hash    = self::store(
			'http://example.org/?p=' . $post_id,
			self::result(),
			array(
				'object_id'   => $post_id,
				'object_type' => 'post',
			)
		);
		$this->assertStringNotContainsString( 'Changed since last scan', self::details_html( $hash ) );
		$this->assertFalse( Report_View::changed_since_scan( Plugin::instance()->pages()->get_by_hash( $hash ) ) );
		$wpdb->update( Pages_Store::table_name(), array( 'scanned_gmt' => '2001-01-01 00:00:00' ), array( 'url_hash' => $hash ) );
		$this->assertTrue( Report_View::changed_since_scan( Plugin::instance()->pages()->get_by_hash( $hash ) ) );
		$this->assertStringContainsString( 'Changed since last scan:', self::details_html( $hash ) );
		$this->assertStringContainsString( 'Edit this page', self::details_html( $hash ) );
		$this->assertNull( Report_View::changed_since_scan( Plugin::instance()->pages()->get_by_hash( hash( 'sha256', 'http://example.org/one/' ) ) ) );

		$this->assertStringContainsString( 'This report no longer exists.', self::details_html( str_repeat( 'b', 64 ) ) );
	}

	/**
	 * Changes are grouped by fix in catalogue order, with Move to Live only
	 * for fixes that are not Live and can run here; findings are grouped by
	 * check with a Media Library link when the attachment is known.
	 */
	public function test_details_changes_and_findings() {
		self::login( 'administrator' );
		$attachment = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		self::set_states( array( 'img-alt-library' => 'live' ) );
		$hash = self::store(
			'http://example.org/changes/',
			self::result(
				array(
					array(
						'fix'    => 'iframe-title',
						'state'  => 'preview',
						'sig'    => 'iframe[src=https://www.youtube.com/embed/abc]',
						'attr'   => 'title',
						'before' => null,
						'after'  => 'YouTube video',
					),
					array(
						'fix'    => 'img-alt-library',
						'state'  => 'live',
						'sig'    => 'img.wp-image-6',
						'attr'   => 'alt',
						'before' => '',
						'after'  => 'Butter croissant',
					),
				),
				array(
					array(
						'check'      => 'R03',
						'sc'         => '1.1.1',
						'sig'        => 'img.wp-image-7',
						'detail'     => 'Alt text looks like a file name',
						'attachment' => $attachment,
					),
				)
			)
		);
		$html = self::details_html( $hash );
		$this->assertLessThan( strpos( $html, 'Titles for embedded frames' ), strpos( $html, 'Missing image alt text from the Media Library' ), 'Catalogue order.' );
		$this->assertStringContainsString( '<th scope="col">Before</th>', $html );
		$this->assertStringContainsString( '<td>(empty)</td>', $html );
		$this->assertStringContainsString( '<td>(none)</td>', $html );
		$this->assertSame( 1, substr_count( $html, 'value="' . Screen_Report_Details::GO_LIVE_ACTION . '"' ), 'Only the Preview fix offers Move to Live.' );
		$this->assertStringContainsString( 'Move to Live<span class="screen-reader-text">: Titles for embedded frames</span>', $html );
		$this->assertStringNotContainsString( 'Skip on this page', $html, 'Not a single post.' );
		$this->assertStringContainsString( 'Alt text to review (WCAG 1.1.1)', $html );
		$this->assertStringContainsString( 'post.php?post=' . $attachment . '&#038;action=edit', $html );
		$this->assertStringContainsString( 'Edit in the Media Library', $html );
		$this->assertStringContainsString( '<caption class="screen-reader-text">', $html );
	}

	/**
	 * The Reports screen: list and scan controls with their data in data-*
	 * attributes (no inline script), the polite live region, the status
	 * message after a redirect and the suggested pages.
	 */
	public function test_reports_screen_markup_and_suggested_pages() {
		self::login( 'administrator' );
		foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $old ) {
			wp_delete_post( $old, true );
		}
		$page_ids = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$page_ids[] = self::factory()->post->create(
				array(
					'post_type'   => 0 === $i % 2 ? 'page' : 'post',
					'post_status' => 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', time() - ( 10 - $i ) * HOUR_IN_SECONDS ),
				)
			);
		}
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_date'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$suggested = Plugin::instance()->reports_screen()->suggested_urls();
		$expected  = array( Plugin::instance()->scanner()->validate_target( home_url( '/' ) ) );
		foreach ( array_reverse( array_slice( $page_ids, 1 ) ) as $id ) {
			$expected[] = Plugin::instance()->scanner()->validate_target( get_permalink( $id ) );
		}
		$this->assertSame( $expected, $suggested, 'Home, then the five latest published posts and pages; no draft, no password.' );

		self::store( 'http://example.org/listed/' );
		$_GET[ Screen_Reports::STATUS_ARG ] = 'deleted';
		$html                               = get_echo( array( Plugin::instance()->reports_screen(), 'render' ) );
		$this->assertStringContainsString( '<h1>Reports</h1>', $html );
		$this->assertStringContainsString( 'role="status" tabindex="-1"><p>Report deleted.</p>', $html );
		$this->assertStringContainsString( 'class="softglaze-a11y-scan-status" role="status" aria-live="polite" aria-atomic="true"', $html );
		$this->assertStringContainsString( 'class="softglaze-a11y-scan-controls" hidden', $html );
		$this->assertStringContainsString( 'data-home-url="' . esc_attr( $expected[0] ) . '"', $html );
		$this->assertMatchesRegularExpression( '/data-suggested="([^"]*)"/', $html );
		preg_match( '/data-suggested="([^"]*)"/', $html, $match );
		$this->assertSame( $suggested, json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true ) );
		$this->assertStringContainsString( 'http://example.org/listed/', $html );
		$this->assertStringContainsString( 'softglaze-a11y-rescan', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );

		$_GET[ Screen_Reports::STATUS_ARG ] = '<b>x</b>';
		$this->assertStringNotContainsString( 'tabindex="-1"><p>', get_echo( array( Plugin::instance()->reports_screen(), 'render' ) ) );

		self::login( 'editor' );
		$this->assertSame( '', get_echo( array( Plugin::instance()->reports_screen(), 'render' ) ) );
		$this->assertSame( '', get_echo( array( Plugin::instance()->log_screen(), 'render' ) ) );
	}

	/**
	 * Phase 8B admin accessibility: both lists expose their first sort order
	 * (aria-sort and a caption, A4); the address field has a described hint,
	 * no autocomplete and an error slot (A7); the Delete row action opens the
	 * confirmation view (A2); Report details has no repeated id (A10) and
	 * keeps attribute names whole (A9).
	 */
	public function test_review_8b_screen_markup() {
		self::login( 'administrator' );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$hash    = self::store( get_permalink( $post_id ), array(), array( 'object_id' => $post_id, 'object_type' => 'post' ) );
		Plugin::instance()->log()->add( 'scan', array( 'detail' => 'source=admin_scan live=0 preview=0 off=0 findings=0 scope=page' ) );

		$html = get_echo( array( Plugin::instance()->reports_screen(), 'render' ) );
		$this->assertMatchesRegularExpression( "/<th scope=\"col\" id='scanned' class='[^']*sorted desc[^']*'\\s+aria-sort=\"descending\"/", $html, 'A4: the first sort column is marked.' );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Table ordered by Last scanned. Descending.</caption>', $html );
		$this->assertStringNotContainsString( 'autocomplete=', $html, 'A7: no autocomplete on the address field.' );
		$this->assertStringContainsString( 'aria-describedby="softglaze-a11y-scan-address-hint"', $html );
		$this->assertStringContainsString( 'id="softglaze-a11y-scan-address-hint"', $html );
		$this->assertStringContainsString( 'The full address of a page on this site, for example: ' . home_url( '/' ), $html );
		$this->assertStringContainsString( '<p class="softglaze-a11y-field-error" id="softglaze-a11y-scan-address-error" hidden></p>', $html );
		$this->assertMatchesRegularExpression( '/class="submitdelete" href="[^"]*softglaze_a11y_step=confirm-delete[^"]*"/', $html, 'A2: Delete opens the confirmation.' );
		$this->assertStringNotContainsString( 'action=' . Screen_Reports::DELETE_ACTION, $html, 'A2: no deleting link.' );

		$log = get_echo( array( Plugin::instance()->log_screen(), 'render' ) );
		$this->assertMatchesRegularExpression( "/<th scope=\"col\" id='created' class='[^']*sorted desc[^']*'\\s+aria-sort=\"descending\"/", $log );
		$this->assertStringContainsString( '<caption class="screen-reader-text">Table ordered by Date. Descending.</caption>', $log );

		$details = self::details_html_with_change( $hash );
		preg_match_all( '/\sid="([^"]+)"/', $details, $ids );
		$this->assertSame( array(), array_values( array_unique( array_diff_assoc( $ids[1], array_unique( $ids[1] ) ) ) ), 'A10: no id twice on Report details.' );
		$this->assertSame( 2, substr_count( $details, 'name="' . Screen_Reports::NONCE_ARG . '"' ), 'Move to Live and Skip each carry a nonce.' );
		$this->assertStringContainsString( '<code class="softglaze-a11y-attr">title</code>', $details );
	}

	/**
	 * The Activity log screen: rows by date with readable details, the event
	 * filter and the export form with its nonce.
	 */
	public function test_log_screen() {
		$admin = self::login( 'administrator' );
		$log   = Plugin::instance()->log();
		$log->add( 'scan', array( 'detail' => 'source=admin_scan live=0 preview=2 off=0 findings=2 scope=content reason=page_buffer_off' ) );
		$log->add(
			'engine_fallback',
			array(
				'user_id' => 0,
				'detail'  => 'time',
			)
		);
		Plugin::instance()->settings()->save_fix_states( array( 'iframe-title' => 'live' ), 0 );

		$screen = Plugin::instance()->log_screen();
		$html   = get_echo( array( $screen, 'render' ) );
		$this->assertStringContainsString( '<h1>Activity log</h1>', $html );
		$this->assertStringContainsString( 'A dated record of the fixes you turned on and what each scan found.', $html );
		$this->assertStringContainsString( 'Scan: 0 Live, 2 Preview, 0 Off, 2 need a person; content only: another plugin switched off the page buffer', $html );
		$this->assertStringContainsString( 'Page served unrepaired', $html );
		$this->assertStringContainsString( '>Visitor</td>', $html );
		$this->assertStringContainsString( 'the repair took longer than the time limit', $html );
		$this->assertStringContainsString( 'Preview to Live', $html );
		$this->assertStringContainsString( 'WP-CLI', $html );
		$this->assertStringContainsString( get_userdata( $admin )->display_name, $html );
		$this->assertStringContainsString( 'name="action" value="' . Screen_Log::EXPORT_ACTION . '"', $html );
		$this->assertStringContainsString( 'name="' . Screen_Log::NONCE_ARG . '"', $html );
		$this->assertStringContainsString( '<label for="softglaze-a11y-event-filter" class="screen-reader-text">', $html );
		$this->assertStringNotContainsString( '<script', $html );

		$_GET[ Screen_Log::EVENT_ARG ] = 'engine_fallback';
		$html                          = get_echo( array( $screen, 'render' ) );
		$this->assertStringContainsString( 'Page served unrepaired', $html );
		$this->assertStringNotContainsString( 'Preview to Live', $html );
		$this->assertStringContainsString( 'Download &quot;Page served unrepaired&quot; entries as CSV', $html );
		$this->assertStringContainsString( 'name="' . Screen_Log::EVENT_ARG . '" value="engine_fallback"', $html );
	}

	/**
	 * scan-targets marks targets on a "Never repair these paths" prefix as
	 * excluded, so admin.js reports "not scanned (excluded path)".
	 */
	public function test_scan_targets_mark_excluded_paths() {
		self::login( 'administrator' );
		self::set_states( array(), array( 'exclude_paths' => array( '/private/' ) ) );
		$response = self::rest( 'POST', '/scan-targets', array( 'urls' => array( home_url( '/' ), home_url( '/private/page/' ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$targets = $response->get_data()['targets'];
		$this->assertFalse( $targets[0]['excluded'] );
		$this->assertTrue( $targets[1]['excluded'] );
	}

	/**
	 * The admin-bar item "Changes on this page (N)" links to the report details once a report exists.
	 */
	public function test_admin_bar_changes_item_links_to_details() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		self::login( 'administrator' );
		$post_id = self::post_with_iframe();
		$this->go_to( get_permalink( $post_id ) );
		$plugin = Plugin::instance();
		$plugin->gate()->reset();
		$node = new Admin_Bar( $plugin->settings(), $plugin->gate(), $plugin->scanner() );

		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertSame( 'Changes on this page (not scanned yet)', $bar->get_node( 'softglaze-a11y-changes' )->title );
		$this->assertFalse( $bar->get_node( 'softglaze-a11y-changes' )->href );

		$hash = self::store( $plugin->gate()->normalised_url() );
		$bar  = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertSame( Screen_Reports::details_url( $hash ), $bar->get_node( 'softglaze-a11y-changes' )->href );
	}
}
