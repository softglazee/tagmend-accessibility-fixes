<?php
/**
 * Scan and report security (docs/05-TEST-PLAN.md section 5): SEC-01 to
 * SEC-05 and SEC-12, plus the admin-view report, the D8 rule (visitor
 * requests write nothing), the report scope when the page pass cannot run,
 * and the admin-bar node.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Admin\Admin_Bar;
use SoftGlaze\AccessibilityRepair\Admin\Report_View;
use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Request_Gate;
use SoftGlaze\AccessibilityRepair\Rest_Controller;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * Scan security tests.
 */
class Test_Scan_Security extends Softglaze_A11y_Report_TestCase {

	/**
	 * Post with an untitled iframe.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Every fix Live, so any write or repair would show.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = self::post_with_iframe();
		self::set_states( 'live' );
	}

	/**
	 * Clears the REST server between tests.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Asserts that a request was an ordinary visitor request that wrote nothing.
	 *
	 * @param Front_End $front       Wiring after the visit.
	 * @param int       $pages_count Rows before.
	 * @param int       $log_count   Log rows before.
	 * @param string    $label       Case.
	 * @return void
	 */
	private function assert_ordinary_request( $front, $pages_count, $log_count, $label ) {
		$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode(), $label );
		$this->assertFalse( $front->no_cache_sent(), "$label: not marked as an admin response" );
		$this->assertNotSame( Front_End::ADMIN_BAR_PRIORITY, has_filter( 'show_admin_bar', '__return_false' ), "$label: admin bar untouched" );
		$this->assertSame( 0, $front->scanner()->run_count(), "$label: nothing kept for a report" );
		self::shutdown( $front );
		$this->assertSame( $pages_count, self::pages_count(), "$label: no report row" );
		$this->assertSame( $log_count, self::log_count(), "$label: no log row" );
	}

	/**
	 * SEC-01: ?softglaze_a11y_scan=1 with no nonce, logged out: an ordinary request, no row.
	 */
	public function test_sec01_scan_arg_without_nonce_logged_out() {
		$pages = self::pages_count();
		$log   = self::log_count();
		wp_set_current_user( 0 );
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ), false ) );
		$this->assertStringContainsString( 'title="YouTube video"', self::render( $front ), 'Visitor mode: Live fixes apply as usual.' );
		$this->assert_ordinary_request( $front, $pages, $log, 'SEC-01' );

		// The same as an administrator without a nonce, and with a malformed value.
		self::login( 'administrator' );
		foreach ( array( self::scan_link( get_permalink( $this->post_id ), false ), self::scan_link( get_permalink( $this->post_id ), '' ), add_query_arg( Request_Gate::SCAN_ARG, 'yes', get_permalink( $this->post_id ) ) ) as $url ) {
			$front = $this->visit( $url );
			self::render( $front );
			$this->assert_ordinary_request( $front, $pages, $log, "admin: $url" );
		}
	}

	/**
	 * SEC-02: a scan link copied from an administrator's session, opened logged out: the nonce fails for user 0.
	 */
	public function test_sec02_admin_link_opened_logged_out() {
		$pages = self::pages_count();
		$log   = self::log_count();
		self::login( 'administrator' );
		$link = self::scan_link( get_permalink( $this->post_id ) );
		wp_set_current_user( 0 );

		$front = $this->visit( $link );
		$this->assertStringContainsString( 'title="YouTube video"', self::render( $front ) );
		$this->assert_ordinary_request( $front, $pages, $log, 'SEC-02' );
	}

	/**
	 * SEC-03: a subscriber and an editor, each with a scan nonce minted for
	 * their own session: the capability check fails first (no nonce is ever
	 * verified), no row is written, and the response is the visitor response.
	 */
	public function test_sec03_subscriber_and_editor_with_own_nonce() {
		$pages    = self::pages_count();
		$log      = self::log_count();
		$verified = 0;
		$spy      = static function ( $life ) use ( &$verified ) {
			++$verified;
			return $life;
		};

		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			self::login( $role );
			$action   = Request_Gate::scan_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) );
			$nonce    = wp_create_nonce( $action );
			$verified = 0;
			add_filter( 'nonce_life', $spy );
			$front = $this->visit( self::scan_link( get_permalink( $this->post_id ), $nonce ) );
			remove_filter( 'nonce_life', $spy );
			$this->assertSame( 0, $verified, "$role: the nonce is never looked at" );
			$this->assertSame( 1, wp_verify_nonce( $nonce, $action ), "$role: control, the nonce itself is valid for this user and page" );
			$body = self::render( $front );
			$this->assertStringContainsString( 'title="YouTube video"', $body, "$role: visitor response" );
			$this->assertStringNotContainsString( 'softglaze_a11y_view', $body, "$role: no admin links" );
			$this->assert_ordinary_request( $front, $pages, $log, "SEC-03 $role" );
		}
	}

	/**
	 * SEC-12: an administrator's scan with a valid nonce writes one row
	 * (source admin_scan); the response is the original HTML byte for byte
	 * even with every fix Live, the admin bar is off, and the response is not
	 * cacheable. No attribute hook or printed element is registered.
	 */
	public function test_sec12_admin_scan_writes_one_row_and_returns_original() {
		$pages   = self::pages_count();
		$log     = self::log_count();
		$user_id = self::login( 'administrator' );
		$front   = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );

		$this->assertSame( Engine::MODE_SCAN, Plugin::instance()->gate()->mode() );
		$this->assertTrue( $front->no_cache_sent() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertSame( Front_End::ADMIN_BAR_PRIORITY, has_filter( 'show_admin_bar', '__return_false' ) );
		$this->assertFalse( apply_filters( 'show_admin_bar', true ), 'The admin bar is off for the scan.' );
		foreach ( array( 'language_attributes', 'wp_get_attachment_image_attributes', 'wp_nav_menu_args', 'wp_body_open', 'wp_enqueue_scripts' ) as $hook ) {
			$this->assertNotContains( $hook, self::registered_hooks( $front ), "no $hook callback in a scan" );
		}
		$this->assertFalse( has_action( 'wp_footer', array( $front, 'print_new_tab_note' ) ) );
		$this->assertFalse( has_filter( 'the_content_more_link', array( $front, 'filter_more_link' ) ) );

		if ( self::has_page_pass() ) {
			$this->assertArrayHasKey( 'page', $front->layers() );
			$this->assertSame( self::PAGE, self::finalize_buffer( self::PAGE ), 'The page pass returns the original bytes.' );
		} else {
			$this->assertArrayHasKey( 'content', $front->layers() );
			$scanned = apply_filters( 'the_content', self::IFRAME );
			remove_filter( 'the_content', array( $front, 'filter_content' ), 999 );
			$this->assertSame( apply_filters( 'the_content', self::IFRAME ), $scanned, 'The content filters return the original bytes.' );
			$this->assertStringNotContainsString( 'title=', $scanned );
		}

		self::shutdown( $front );
		$this->assertSame( $pages + 1, self::pages_count(), 'One row.' );
		$rows = self::pages_rows();
		$row  = end( $rows );
		$this->assertSame( 'admin_scan', $row['source'] );
		$this->assertSame( (string) $this->post_id, $row['object_id'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( hash( 'sha256', Plugin::instance()->gate()->normalised_url() ), $row['url_hash'] );
		$this->assertGreaterThan( 0, (int) $row['changes_live'] );
		$result = json_decode( $row['result'], true );
		$this->assertSame( 'scan', $result['engine']['mode'] );
		$this->assertSame( self::has_page_pass() ? 'page' : 'content', $result['engine']['scope'] );
		$this->assertSame( self::has_page_pass() ? null : Scanner::REASON_NEEDS_WP69, $result['engine']['scope_reason'] );
		$this->assertNotEmpty( $result['changes'] );
		foreach ( $result['changes'] as $change ) {
			$this->assertFalse( $change['applied'], 'A scan applies nothing.' );
		}
		$this->assertContains( 'iframe-title', wp_list_pluck( $result['changes'], 'fix' ) );
		$this->assertSame( array( 'R14' ), $result['not_seen'] );

		$this->assertSame( $log + 1, self::log_count() );
		$logs = self::log_rows();
		$last = end( $logs );
		$this->assertSame( 'scan', $last['event'] );
		$this->assertSame( (string) $user_id, $last['user_id'] );
		$this->assertSame( $row['url_hash'], $last['url_hash'] );
		$this->assertStringStartsWith( 'source=admin_scan live=', $last['detail'] );

		// A second scan of the same URL replaces the row.
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );
		self::render( $front );
		self::shutdown( $front );
		$this->assertSame( $pages + 1, self::pages_count(), 'Still one row for this URL.' );
	}

	/**
	 * softglaze_a11y_scan_completed fires once after a stored scan, with counts.
	 */
	public function test_scan_completed_action() {
		self::login( 'administrator' );
		$events = array();
		add_action(
			'softglaze_a11y_scan_completed',
			static function ( $url, $summary ) use ( &$events ) {
				$events[] = array( $url, $summary );
			},
			10,
			2
		);
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );
		self::render( $front );
		self::shutdown( $front );
		$this->assertCount( 1, $events );
		$this->assertSame( Plugin::instance()->gate()->normalised_url(), $events[0][0] );
		$this->assertSame( 'admin_scan', $events[0][1]['source'] );
		$this->assertGreaterThan( 0, $events[0][1]['changes']['live'] );
	}

	/**
	 * D8: visitor requests never write a report or a log row, with every fix Live and when the page pass is vetoed.
	 */
	public function test_visitor_requests_write_nothing() {
		$pages = self::pages_count();
		$log   = self::log_count();
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertStringContainsString( 'title="YouTube video"', self::render( $front ) );
		$this->assertFalse( has_action( 'shutdown', array( $front, 'record_report' ) ) );
		$this->assert_ordinary_request( $front, $pages, $log, 'visitor' );

		if ( self::has_page_pass() ) {
			add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );
			$front = $this->visit( get_permalink( $this->post_id ) );
			do_action( Front_End::BUFFER_CHECK_HOOK, ABSPATH . 'wp-includes/template-canvas.php' );
			$this->assertTrue( $front->page_pass_vetoed() );
			$this->assertStringContainsString( 'title="YouTube video"', apply_filters( 'the_content', self::IFRAME ) );
			$this->assert_ordinary_request( $front, $pages, $log, 'visitor, page pass vetoed' );
			remove_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );
		}
	}

	/**
	 * A scan on a site where another plugin stops core's page buffer
	 * (Elementor 4.3.5) sees content fragments only, and its report says so.
	 */
	public function test_vetoed_page_pass_report_scope() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		self::login( 'administrator' );
		add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );
		$this->assertSame( array( 'scope' => 'page', 'reason' => '' ), $front->scanner()->scope() );

		do_action( Front_End::BUFFER_CHECK_HOOK, ABSPATH . 'wp-includes/template-canvas.php' );
		$this->assertTrue( $front->page_pass_vetoed() );
		$this->assertSame( 999, has_filter( 'the_content', array( $front, 'filter_content' ) ), 'A scan falls back to the content filters.' );
		$this->assertSame( self::IFRAME, $front->filter_content( self::IFRAME ), 'Nothing applied.' );
		self::shutdown( $front );
		remove_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );

		$rows   = self::pages_rows();
		$result = json_decode( end( $rows )['result'], true );
		$this->assertSame( 'content', $result['engine']['scope'] );
		$this->assertSame( Scanner::REASON_PAGE_BUFFER_OFF, $result['engine']['scope_reason'] );
		$this->assertSame( 1, $result['engine']['runs'] );
		$this->assertSame( 1, $result['counts']['iframe-title']['live'] );

		$logs = self::log_rows();
		$this->assertStringEndsWith( 'scope=content reason=page_buffer_off', end( $logs )['detail'] );
	}

	/**
	 * Page pass switched off in Settings: content scope with its own reason.
	 */
	public function test_page_pass_off_report_scope() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		self::login( 'administrator' );
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );
		$this->assertArrayHasKey( 'content', $front->layers() );
		apply_filters( 'the_content', self::IFRAME );
		self::shutdown( $front );
		$rows   = self::pages_rows();
		$result = json_decode( end( $rows )['result'], true );
		$this->assertSame( 'content', $result['engine']['scope'] );
		$this->assertSame( self::has_page_pass() ? Scanner::REASON_PAGE_PASS_OFF : Scanner::REASON_NEEDS_WP69, $result['engine']['scope_reason'] );
	}

	/**
	 * Content scope: a fragment found again inside a later fragment is not
	 * counted twice; two separate identical fragments are both counted.
	 */
	public function test_nested_fragments_counted_once() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		self::login( 'administrator' );
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );

		$inner = $front->filter_content( self::IFRAME );
		$front->filter_content( '<div class="wp-block-template-part">' . $inner . '<p>Footer</p></div>' );
		$front->filter_content( '<nav>' . self::IFRAME . '</nav>' );
		$front->filter_content( '<aside>' . self::IFRAME . '</aside>' );
		$this->assertSame( 4, $front->scanner()->run_count() );
		self::shutdown( $front );

		$rows   = self::pages_rows();
		$result = json_decode( end( $rows )['result'], true );
		$this->assertSame( 3, $result['counts']['iframe-title']['live'], 'The nested iframe once, the two separate ones twice.' );
		$this->assertSame( 4, $result['engine']['runs'] );
	}

	/**
	 * View repaired records a report (source admin_view) with the applied
	 * changes, including attribute-hook changes; View original records nothing.
	 */
	public function test_admin_views_record_reports() {
		self::set_states( 'preview', array( 'page_scope' => false ) );
		self::login( 'administrator' );
		$nonce = wp_create_nonce( Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) ) );
		$pages = self::pages_count();
		$log   = self::log_count();

		$front = $this->visit( add_query_arg( array( Request_Gate::VIEW_ARG => 'original', Request_Gate::VIEW_NONCE_ARG => $nonce ), get_permalink( $this->post_id ) ) );
		$this->assertSame( Engine::MODE_ADMIN_ORIGINAL, Plugin::instance()->gate()->mode() );
		self::shutdown( $front );
		$this->assertSame( $pages, self::pages_count(), 'View original writes nothing.' );

		$front = $this->visit( add_query_arg( array( Request_Gate::VIEW_ARG => 'repaired', Request_Gate::VIEW_NONCE_ARG => $nonce ), get_permalink( $this->post_id ) ) );
		$this->assertSame( Engine::MODE_ADMIN_REPAIRED, Plugin::instance()->gate()->mode() );
		$this->assertStringContainsString( 'title="YouTube video"', apply_filters( 'the_content', self::IFRAME ) );
		$this->assertStringContainsString( 'lang=', $front->filter_language_attributes( 'dir="ltr"' ), 'Layer A repairs the missing lang.' );
		self::shutdown( $front );

		$this->assertSame( $pages + 1, self::pages_count() );
		$rows = self::pages_rows();
		$row  = end( $rows );
		$this->assertSame( 'admin_view', $row['source'] );
		$this->assertGreaterThanOrEqual( 2, (int) $row['changes_preview'] );
		$result = json_decode( $row['result'], true );
		$fixes  = array();
		foreach ( $result['changes'] as $change ) {
			$fixes[ $change['fix'] ] = $change;
		}
		$this->assertTrue( $fixes['iframe-title']['applied'] );
		$this->assertSame( 'preview', $fixes['iframe-title']['state'] );
		$this->assertSame( 'html', $fixes['html-lang']['sig'] );
		$this->assertSame( 'lang', $fixes['html-lang']['attr'] );
		$this->assertNull( $fixes['html-lang']['before'] );
		$this->assertTrue( $fixes['html-lang']['applied'] );
		$this->assertSame( $log, self::log_count(), 'Views add no log row.' );
	}

	/**
	 * The store checks the capability again at write time.
	 */
	public function test_store_rechecks_capability() {
		self::login( 'administrator' );
		$front = $this->visit( self::scan_link( get_permalink( $this->post_id ) ) );
		self::render( $front );
		$pages = self::pages_count();
		self::login( 'editor' );
		$this->assertFalse( $front->scanner()->store_request_report( 'admin_scan' ) );
		$this->assertSame( $pages, self::pages_count() );
	}

	/**
	 * A REST request as a user of a role (0: logged out).
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Parameters.
	 * @return WP_REST_Response
	 */
	private static function rest( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . Rest_Controller::ROUTE_NAMESPACE . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * SEC-05: both routes refuse anonymous (401), subscriber (403) and editor (403) callers before looking at parameters.
	 */
	public function test_sec05_rest_refuses_non_admins() {
		$cases = array(
			array( 'POST', '/scan-targets', array( 'urls' => array( home_url( '/' ) ) ) ),
			array( 'POST', '/scan-targets', array( 'urls' => array( 'javascript:alert(1)' ) ) ),
			array( 'POST', '/scan-targets', array() ),
			array( 'GET', '/report', array( 'url' => home_url( '/' ) ) ),
			array( 'GET', '/report', array() ),
		);
		foreach ( array(
			0            => 401,
			'subscriber' => 403,
			'editor'     => 403,
		) as $role => $status ) {
			if ( 0 === $role ) {
				wp_set_current_user( 0 );
			} else {
				self::login( $role );
			}
			foreach ( $cases as $case ) {
				$response = self::rest( $case[0], $case[1], $case[2] );
				$label    = ( 0 === $role ? 'anonymous' : $role ) . ' ' . $case[0] . ' ' . $case[1] . ' ' . wp_json_encode( $case[2] );
				$this->assertSame( $status, $response->get_status(), $label );
				$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code(), $label );
				$this->assertArrayNotHasKey( 'targets', (array) $response->get_data(), $label );
			}
		}
	}

	/**
	 * SEC-04: scan-targets refuses a foreign host, another scheme, a
	 * protocol-relative URL, javascript:, user information, a backslash and
	 * admin paths with 400, and returns nothing for them.
	 */
	public function test_sec04_scan_targets_refuses_other_origins() {
		self::login( 'administrator' );
		$home    = home_url( '/' );
		$parts   = wp_parse_url( $home );
		$other   = 'https' === $parts['scheme'] ? 'http' : 'https';
		$refused = array(
			'foreign host'      => 'http://evil.example/page/',
			'other scheme'      => $other . '://' . $parts['host'] . '/',
			'protocol-relative' => '//' . $parts['host'] . '/',
			'javascript'        => 'javascript:alert(1)',
			'javascript host'   => 'javascript://' . $parts['host'] . '/%0aalert(1)',
			'user information'  => $parts['scheme'] . '://' . $parts['host'] . '@evil.example/',
			'backslash'         => $parts['scheme'] . '://evil.example\\@' . $parts['host'] . '/',
			'relative'          => '/sample-page/',
			'other port'        => $parts['scheme'] . '://' . $parts['host'] . ':8999/',
			'admin'             => admin_url( 'options.php' ),
			'login'             => site_url( 'wp-login.php' ),
			'data'              => 'data:text/html,hello',
			'empty'             => '',
		);
		foreach ( $refused as $label => $url ) {
			$response = self::rest( 'POST', '/scan-targets', array( 'urls' => array( $url ) ) );
			$this->assertSame( 400, $response->get_status(), $label );
			$this->assertSame( 'softglaze_a11y_invalid_url', $response->as_error()->get_error_code(), $label );
			$this->assertSame( array( 0 ), $response->as_error()->get_error_data()['rejected'], $label );
			$this->assertStringNotContainsString( 'evil', wp_json_encode( $response->get_data() ), $label );
		}

		// One bad URL among good ones refuses the whole request; nothing is returned for any of them.
		$response = self::rest( 'POST', '/scan-targets', array( 'urls' => array( $home, 'http://evil.example/' ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 1 ), $response->as_error()->get_error_data()['rejected'] );
		$this->assertArrayNotHasKey( 'targets', (array) $response->get_data() );

		// Shape problems are 400s too.
		$this->assertSame( 400, self::rest( 'POST', '/scan-targets', array() )->get_status() );
		$this->assertSame( 400, self::rest( 'POST', '/scan-targets', array( 'urls' => 'http://example.org/' ) )->get_status() );
		$this->assertSame( 400, self::rest( 'POST', '/scan-targets', array( 'urls' => array_fill( 0, Rest_Controller::MAX_TARGETS + 1, $home ) ) )->get_status() );
	}

	/**
	 * scan-targets returns each URL on this site with the scan arg and a nonce valid for the administrator.
	 */
	public function test_scan_targets_returns_nonced_links() {
		self::login( 'administrator' );
		$page     = get_permalink( $this->post_id );
		$response = self::rest( 'POST', '/scan-targets', array( 'urls' => array( home_url( '/' ), $page . '#comments', add_query_arg( Request_Gate::SCAN_NONCE_ARG, 'stale', $page ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$targets = $response->get_data()['targets'];
		$this->assertCount( 3, $targets );
		$this->assertSame( $page, $targets[1]['url'], 'Fragment removed.' );
		$this->assertSame( $page, $targets[2]['url'], 'Our own args removed.' );
		foreach ( $targets as $target ) {
			$this->assertSame( '1', self::query_arg( $target['scan_url'], Request_Gate::SCAN_ARG ) );
			$this->assertSame( 1, wp_verify_nonce( self::query_arg( $target['scan_url'], Request_Gate::SCAN_NONCE_ARG ), Request_Gate::scan_nonce_action( Request_Gate::url_hash_of( $target['url'] ) ) ), 'Bound to the target page.' );
			$this->assertFalse( wp_verify_nonce( self::query_arg( $target['scan_url'], Request_Gate::SCAN_NONCE_ARG ), Request_Gate::SCAN_ACTION ), 'Not valid for every page.' );
			$this->assertSame( 1, substr_count( $target['scan_url'], Request_Gate::SCAN_NONCE_ARG . '=' ) );
		}
	}

	/**
	 * GET report: 404 before a scan, the summary after it (counts only), 400 for another origin.
	 */
	public function test_report_route() {
		self::login( 'administrator' );
		$page = get_permalink( $this->post_id );
		$this->assertSame( 404, self::rest( 'GET', '/report', array( 'url' => $page ) )->get_status() );
		$this->assertSame( 400, self::rest( 'GET', '/report', array( 'url' => 'http://evil.example/' ) )->get_status() );
		$this->assertSame( 400, self::rest( 'GET', '/report', array() )->get_status() );

		$front = $this->visit( self::scan_link( $page ) );
		self::render( $front );
		self::shutdown( $front );

		$response = self::rest( 'GET', '/report', array( 'url' => $page . '&utm_source=x' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'url', 'source', 'scanned_gmt', 'changes', 'findings', 'scope', 'scope_reason', 'fallback' ), array_keys( $data ) );
		$this->assertSame( 'admin_scan', $data['source'] );
		$this->assertGreaterThan( 0, $data['changes']['live'] );
		$this->assertStringNotContainsString( 'iframe', wp_json_encode( $data ), 'No element signatures in the summary.' );
	}

	/**
	 * The admin-bar node: Changes on this page (N) from the last report, and a nonce'd Scan this page link.
	 */
	public function test_admin_bar_changes_and_scan_nodes() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		self::login( 'administrator' );
		$page   = get_permalink( $this->post_id );
		$plugin = Plugin::instance();
		$node   = new Admin_Bar( $plugin->settings(), $plugin->gate(), $plugin->scanner() );

		$this->go_to( add_query_arg( array( Request_Gate::SCAN_ARG => '1', Request_Gate::SCAN_NONCE_ARG => 'old' ), $page ) );
		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertSame( 'Changes on this page (not scanned yet)', $bar->get_node( 'softglaze-a11y-changes' )->title );
		$this->assertFalse( $bar->get_node( 'softglaze-a11y-changes' )->href, 'Text only until the Report details screen exists.' );
		$scan = $bar->get_node( 'softglaze-a11y-scan' );
		$this->assertSame( 'Scan this page', $scan->title );
		$this->assertSame( (string) $this->post_id, self::query_arg( $scan->href, 'p' ) );
		$this->assertSame( '1', self::query_arg( $scan->href, Request_Gate::SCAN_ARG ) );
		$this->assertSame( 1, wp_verify_nonce( self::query_arg( $scan->href, Request_Gate::SCAN_NONCE_ARG ), Request_Gate::scan_nonce_action( Request_Gate::url_hash_of( $page ) ) ) );
		$this->assertStringStartsWith( home_url( '/' ), $scan->href, 'An absolute link on this site.' );
		$this->assertSame( 1, substr_count( $scan->href, Request_Gate::SCAN_NONCE_ARG . '=' ), 'The old nonce is replaced.' );
		foreach ( array( 'original', 'repaired' ) as $view ) {
			$this->assertStringNotContainsString( Request_Gate::SCAN_ARG, $bar->get_node( 'softglaze-a11y-view-' . $view )->href, "$view link carries no scan arg" );
		}

		$front = $this->visit( self::scan_link( $page ) );
		self::render( $front );
		self::shutdown( $front );
		$row = self::pages_rows()[0];

		$this->go_to( $page );
		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertSame( 'Changes on this page (' . ( (int) $row['changes_live'] + (int) $row['changes_preview'] ) . ')', $bar->get_node( 'softglaze-a11y-changes' )->title );
	}

	/**
	 * S2 (Phase 8B): scan and view nonces are bound to one page. A nonce from
	 * an administrator's link for one page (copied from a log), used on
	 * another page, and a nonce for the old page-independent action, give an
	 * ordinary visitor request that writes nothing; the right page works.
	 */
	public function test_s2_nonces_bound_to_one_page() {
		self::login( 'administrator' );
		$page  = get_permalink( $this->post_id );
		$other = get_permalink( self::post_with_iframe() );
		$pages = self::pages_count();
		$log   = self::log_count();

		$scan_nonce = self::query_arg( self::scan_link( $page ), Request_Gate::SCAN_NONCE_ARG );
		$view_nonce = self::query_arg( Report_View::view_url( $page, 'repaired' ), Request_Gate::VIEW_NONCE_ARG );
		$refused    = array(
			'scan nonce of another page'  => add_query_arg( array( Request_Gate::SCAN_ARG => '1', Request_Gate::SCAN_NONCE_ARG => $scan_nonce ), $other ),
			'unbound scan nonce'          => add_query_arg( array( Request_Gate::SCAN_ARG => '1', Request_Gate::SCAN_NONCE_ARG => wp_create_nonce( Request_Gate::SCAN_ACTION ) ), $page ),
			'view nonce of another page'  => add_query_arg( array( Request_Gate::VIEW_ARG => 'repaired', Request_Gate::VIEW_NONCE_ARG => $view_nonce ), $other ),
			'unbound view nonce'          => add_query_arg( array( Request_Gate::VIEW_ARG => 'repaired', Request_Gate::VIEW_NONCE_ARG => wp_create_nonce( Request_Gate::VIEW_ACTION ) ), $page ),
		);
		foreach ( $refused as $label => $url ) {
			$front = $this->visit( $url );
			self::render( $front );
			$this->assert_ordinary_request( $front, $pages, $log, "S2 $label" );
		}

		$this->visit( add_query_arg( array( Request_Gate::SCAN_ARG => '1', Request_Gate::SCAN_NONCE_ARG => $scan_nonce ), $page ) );
		$this->assertSame( Engine::MODE_SCAN, Plugin::instance()->gate()->mode(), 'control: the scan nonce works on its own page' );
		$this->visit( add_query_arg( array( Request_Gate::VIEW_ARG => 'repaired', Request_Gate::VIEW_NONCE_ARG => $view_nonce ), $page ) );
		$this->assertSame( Engine::MODE_ADMIN_REPAIRED, Plugin::instance()->gate()->mode(), 'control: the view nonce works on its own page' );
	}

	/**
	 * S1 (Phase 8B): the admin-bar links are absolute URLs on the site's own
	 * origin, also when the request path starts with two slashes (a
	 * protocol-relative link to another host before); a request address that
	 * is not a page of this site gets no node.
	 */
	public function test_s1_admin_bar_links_stay_on_this_origin() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		self::login( 'administrator' );
		$plugin = Plugin::instance();
		$node   = new Admin_Bar( $plugin->settings(), $plugin->gate(), $plugin->scanner() );

		$this->go_to( home_url( '/?s=a' ) );
		$_SERVER['REQUEST_URI'] = '//evil.example/x?s=a';
		$bar                    = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		foreach ( array( 'view-original', 'view-repaired', 'scan' ) as $id ) {
			$item = $bar->get_node( 'softglaze-a11y-' . $id );
			$this->assertNotNull( $item, $id );
			$this->assertStringStartsWith( home_url( '/' ), $item->href, $id );
			$this->assertSame( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), wp_parse_url( $item->href, PHP_URL_HOST ), $id );
		}

		$_SERVER['REQUEST_URI'] = '/wp-login.php?s=a';
		$bar                    = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertNull( $bar->get_node( 'softglaze-a11y' ), 'No node for an address that is not a page of this site.' );
	}

	/**
	 * C3 (Phase 8B): a visitor request runs no attachment_url_to_postid()
	 * query and writes nothing; an administrator's scan looks the image up
	 * and saves the answer; the next visitor request repairs the image from
	 * that saved answer, still without a query.
	 */
	public function test_c3_url_lookups_only_in_authenticated_requests() {
		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/c3-loaf.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Loaf on a board' );
		self::set_states( 'off' );
		self::set_states( array( 'img-alt-library' => 'live' ) );
		delete_option( Attachment_Lookup::MAP_OPTION );
		$img   = '<img src="' . esc_url( wp_get_upload_dir()['baseurl'] . '/2026/10/c3-loaf-300x200.jpg' ) . '">';
		$page  = '<!DOCTYPE html><html lang="en"><head><title>T</title></head><body><p>' . $img . '</p></body></html>';
		$url   = get_permalink( $this->post_id );
		$calls = 0;
		$count = static function ( $post_id ) use ( &$calls ) {
			++$calls;
			return $post_id;
		};
		add_filter( 'attachment_url_to_postid', $count );

		wp_set_current_user( 0 );
		$front = $this->visit( $url );
		$body  = self::render( $front, $page, $img );
		self::shutdown( $front );
		$this->assertStringNotContainsString( 'alt=', $body, 'visitor: not repaired before any scan' );
		$this->assertSame( 0, $calls, 'visitor: no URL query' );
		$this->assertFalse( get_option( Attachment_Lookup::MAP_OPTION ), 'visitor: nothing written' );

		self::login( 'administrator' );
		$front = $this->visit( self::scan_link( $url ) );
		$this->assertSame( Engine::MODE_SCAN, Plugin::instance()->gate()->mode() );
		self::render( $front, $page, $img );
		self::shutdown( $front );
		$this->assertSame( 1, $calls, 'scan: one URL query' );
		$this->assertSame( array( '2026/10/c3-loaf.jpg' => $attachment ), get_option( Attachment_Lookup::MAP_OPTION ) );

		wp_set_current_user( 0 );
		$front = $this->visit( $url );
		$body  = self::render( $front, $page, $img );
		remove_filter( 'attachment_url_to_postid', $count );
		$this->assertStringContainsString( 'alt="Loaf on a board"', $body, 'visitor: repaired from the saved answer' );
		$this->assertSame( 1, $calls, 'visitor: still no URL query' );
	}

	/**
	 * One query arg of a URL.
	 *
	 * @param string $url URL.
	 * @param string $key Arg.
	 * @return string|null
	 */
	private static function query_arg( $url, $key ) {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		return isset( $args[ $key ] ) ? $args[ $key ] : null;
	}
}
