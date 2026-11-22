<?php
/**
 * Phase 7B screens: the Overview (first-run steps, counts, whole-page
 * repair status, recent reports, fallbacks, cache reminder, disclaimer), the
 * Settings screen and its log row, the Fixes screen groups, descriptions,
 * Understanding links, scanned-page counts and "Move all Preview fixes to
 * Live" (SEC-06 style refusals), the page-cache reminder, the privacy text
 * and the network-activation message.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Admin\Cache_Reminder;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Fixes;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Overview;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Settings;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Privacy;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * Overview, Settings and Fixes screen tests.
 */
class Test_Overview_Settings extends Softglaze_A11y_Report_TestCase {

	/**
	 * The wording grep of the submission preflight (step 5).
	 */
	const BANNED = '/complian|conforman|conforms|lawsuit|certif|100%|guarantee|\bscores?\b|\bgrade[sd]?\b|AA badge|WCAG AA|Level AA/i';

	/**
	 * Admin includes the screens need outside wp-admin.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	/**
	 * Saved defaults, an empty request and no cached sums before every test.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::instance()->settings()->activate();
		$_GET                          = array();
		$_POST                         = array();
		$_REQUEST                      = array();
		$GLOBALS['wp_settings_errors'] = array();
		delete_transient( Pages_Store::TOTALS_TRANSIENT );
	}

	/**
	 * Stores a report and returns its hash.
	 *
	 * @param string $url    Normalised URL.
	 * @param array  $counts Fix id => array( state => count ).
	 * @param string $scope  Scope.
	 * @param mixed  $reason Scope reason.
	 * @param string $source Report source.
	 * @return string
	 */
	private static function store( $url, array $counts = array(), $scope = 'page', $reason = null, $source = 'admin_scan' ) {
		$hash = hash( 'sha256', $url );
		Plugin::instance()->pages()->save(
			array(
				'url'      => $url,
				'url_hash' => $hash,
				'source'   => $source,
				'result'   => array(
					'engine' => array(
						'scope'        => $scope,
						'scope_reason' => $reason,
						'ms'           => 1.0,
						'bytes'        => 1000,
					),
					'counts' => $counts,
				),
			)
		);
		return $hash;
	}

	/**
	 * Runs a handler and returns the wp_die() code, or 0 when it did not die.
	 *
	 * @param callable $handler Handler.
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
	 * The Overview.
	 *
	 * @return string
	 */
	private static function overview_html() {
		return get_echo( array( Plugin::instance(), 'render_overview' ) );
	}

	/**
	 * The Fixes screen.
	 *
	 * @return string
	 */
	private static function fixes_html() {
		return get_echo( array( Plugin::instance()->fixes_screen(), 'render' ) );
	}

	/**
	 * First run: the h1, the three steps with links to the real screens, the
	 * counts and the disclaimer word for word; the steps go once a fix is
	 * Live; other users get nothing.
	 */
	public function test_overview_first_run_steps_counts_and_disclaimer() {
		self::login( 'administrator' );
		$html = self::overview_html();

		$this->assertStringContainsString( "<h1>Tagmend \u{2013} Accessibility Fixes</h1>", $html );
		$this->assertStringContainsString( 'Get started', $html );
		foreach ( array( 'Scan your home page.', 'Review the changes.', 'Go live.' ) as $step ) {
			$this->assertStringContainsString( '<strong>' . $step . '</strong>', $html );
		}
		$this->assertStringContainsString( 'href="' . esc_url( Screen_Fixes::url() ) . '"', $html );
		$this->assertStringContainsString( 'page=softglaze-a11y-reports', $html );
		$this->assertStringContainsString( 'page=' . Screen_Settings::PAGE, $html );

		$approved = 'No plugin can provide 100% compliance with accessibility laws or standards such as the ADA, the European Accessibility Act or WCAG.';
		$this->assertSame( $approved, Screen_Overview::disclaimer() );
		$this->assertSame( 1, substr_count( $html, '<p class="softglaze-a11y-disclaimer">' . $approved . '</p>' ) );

		$states  = Plugin::instance()->settings()->fix_states();
		$counts  = array_count_values( $states );
		$preview = isset( $counts['preview'] ) ? $counts['preview'] : 0;
		$this->assertStringContainsString( '<dt>Live</dt>', $html );
		$this->assertMatchesRegularExpression( '#<dt>Live</dt>\s*<dd>\s*0 fixes\s*</dd>#', $html );
		$this->assertMatchesRegularExpression( '#<dt>Preview</dt>\s*<dd>\s*' . $preview . ' fixes\s*</dd>#', $html );
		$this->assertStringContainsString( 'No page has been scanned yet.', $html );
		$this->assertStringContainsString( 'None recorded in the last 7 days.', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );

		// Text outside the one approved sentence carries no banned word.
		$this->assertSame( 0, preg_match( self::BANNED, str_replace( $approved, '', wp_strip_all_tags( $html ) ) ) );

		Plugin::instance()->settings()->save_fix_states( array( 'iframe-title' => 'live' ), get_current_user_id() );
		$html = self::overview_html();
		$this->assertStringNotContainsString( 'Get started', $html, 'The steps stay until something is Live.' );
		$this->assertMatchesRegularExpression( '#<dt>Live</dt>\s*<dd>\s*1 fix\s*</dd>#', $html );

		self::login( 'editor' );
		$this->assertSame( '', self::overview_html() );
	}

	/**
	 * Step 1 says when the home page was scanned and step 2 links to its report.
	 */
	public function test_overview_step_one_done_after_home_scan() {
		self::login( 'administrator' );
		$home = Plugin::instance()->scanner()->validate_target( home_url( '/' ) );
		$hash = hash( 'sha256', Plugin::instance()->scanner()->normalise_target( $home ) );
		self::store( Plugin::instance()->scanner()->normalise_target( $home ) );
		$html = self::overview_html();
		$this->assertStringContainsString( 'Done: scanned ', $html );
		$this->assertStringContainsString( 'softglaze_a11y_report=' . $hash, $html );
		$this->assertStringContainsString( 'Open the report of the home page', $html );
	}

	/**
	 * Whole-page repair: needs 6.9, switched off in Settings, switched off by
	 * another plugin according to the newest scan or view report (WP-CLI
	 * reports and reports without a page fix do not count), or available.
	 */
	public function test_page_pass_status_from_reports() {
		self::login( 'administrator' );
		$pages = Plugin::instance()->pages();

		if ( ! self::has_page_pass() ) {
			self::store( 'http://example.org/vetoed/', array(), 'content', 'page_buffer_off' );
			$this->assertSame( Pages_Store::PAGE_PASS_NEEDS_WP69, $pages->page_pass_status( true )['status'] );
			$this->assertStringContainsString( 'Needs WordPress 6.9 or later. Until then', self::overview_html() );
			return;
		}

		$status = $pages->page_pass_status( true );
		$this->assertSame( Pages_Store::PAGE_PASS_AVAILABLE, $status['status'] );
		$this->assertFalse( $status['confirmed'] );
		$this->assertStringContainsString( 'Available on this WordPress version. Scan a page to confirm', self::overview_html() );

		self::store( 'http://example.org/vetoed/', array(), 'content', 'page_buffer_off' );
		self::store( 'http://example.org/cli/', array(), 'page', null, 'cli' );
		self::store( 'http://example.org/view/', array(), 'content', 'no_page_fix', 'admin_view' );
		$status = $pages->page_pass_status( true );
		$this->assertSame( Pages_Store::PAGE_PASS_VETOED, $status['status'] );
		$this->assertSame( 'http://example.org/vetoed/', $status['url'] );
		$html = self::overview_html();
		$this->assertStringContainsString( 'Switched off by another plugin on the last scanned pages. The report of http://example.org/vetoed/ (', $html );

		$this->assertSame( Pages_Store::PAGE_PASS_SWITCHED_OFF, $pages->page_pass_status( false )['status'] );

		self::store( 'http://example.org/whole/', array(), 'page', null, 'admin_scan' );
		$status = $pages->page_pass_status( true );
		$this->assertSame( Pages_Store::PAGE_PASS_AVAILABLE, $status['status'] );
		$this->assertTrue( $status['confirmed'] );
		$this->assertStringContainsString( 'Available. The newest report, of http://example.org/whole/ (', self::overview_html() );
	}

	/**
	 * Pages served unrepaired in the last 7 days, by reason, with a link to
	 * the filtered Activity log; older rows are not counted. The newest
	 * reports are listed with their counts.
	 */
	public function test_overview_fallbacks_and_recent_reports() {
		global $wpdb;
		self::login( 'administrator' );
		$log = Plugin::instance()->log();
		$log->add( 'engine_fallback', array( 'detail' => 'size' ) );
		$log->add( 'engine_fallback', array( 'detail' => 'size' ) );
		$log->add( 'engine_fallback', array( 'detail' => 'time' ) );
		$wpdb->insert(
			Log_Store::table_name(),
			array(
				'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ),
				'user_id'     => 0,
				'event'       => 'engine_fallback',
				'detail'      => 'size',
			)
		);
		$this->assertSame(
			array(
				'size' => 2,
				'time' => 1,
			),
			$log->details_since( 'engine_fallback', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) )
		);
		$this->assertSame( array(), $log->details_since( 'engine_fallback', 'yesterday' ), 'Dates are validated.' );

		$hash = self::store(
			'http://example.org/recent/',
			array(
				'iframe-title' => array(
					'live'    => 0,
					'preview' => 2,
					'off'     => 1,
				),
			)
		);
		$html = self::overview_html();
		$this->assertStringContainsString( '3 entries in the last 7 days', $html );
		$this->assertStringContainsString( '2 entries: the page is larger than the size limit', $html );
		$this->assertStringContainsString( '1 entry: the repair took longer than the time limit', $html );
		$this->assertStringContainsString( 'softglaze_a11y_event=engine_fallback', $html );
		$this->assertStringContainsString( 'softglaze_a11y_report=' . $hash, $html );
		$this->assertStringContainsString( 'Scan: 0 Live, 2 Preview, 1 Off, 0 need a person', $html );
	}

	/**
	 * The page-cache reminder names the first detected plugin, by constant
	 * only, and falls back to a general sentence.
	 */
	public function test_cache_reminder() {
		$this->assertSame( array( 'WPCACHEHOME', 'LSCWP_V', 'W3TC', 'WP_ROCKET_VERSION' ), array_keys( Cache_Reminder::PLUGINS ) );
		$none = static function () {
			return false;
		};
		$this->assertSame( '', Cache_Reminder::detected( $none ) );
		$this->assertStringContainsString( 'page cache or a CDN cache', Cache_Reminder::after_change( $none ) );
		$this->assertStringContainsString( 'No page-cache plugin that Tagmend recognises is active.', Cache_Reminder::overview( $none ) );

		$litespeed = static function ( $name ) {
			return 'LSCWP_V' === $name;
		};
		$this->assertSame( 'LiteSpeed Cache', Cache_Reminder::detected( $litespeed ) );
		$this->assertStringStartsWith( 'LiteSpeed Cache is active on this site', Cache_Reminder::after_change( $litespeed ) );
		$this->assertStringContainsString( 'stored by LiteSpeed Cache', Cache_Reminder::overview( $litespeed ) );

		$two = static function ( $name ) {
			return in_array( $name, array( 'W3TC', 'WPCACHEHOME' ), true );
		};
		$this->assertSame( 'WP Super Cache', Cache_Reminder::detected( $two ), 'The first match in list order wins.' );
		foreach ( Cache_Reminder::PLUGINS as $constant => $plugin ) {
			$only = static function ( $name ) use ( $constant ) {
				return $constant === $name;
			};
			$this->assertSame( $plugin, Cache_Reminder::detected( $only ), $constant );
		}
	}

	/**
	 * Fixes screen: six groups in order, every fix in exactly one, a
	 * description for each (F02 says why it starts Off, F16 asks for a
	 * keyboard test), and a "Learn more" link to the W3C Understanding page
	 * of every criterion.
	 */
	public function test_fixes_groups_descriptions_and_links() {
		self::login( 'administrator' );
		$registry = Plugin::instance()->registry();
		$html     = self::fixes_html();

		preg_match_all( '#<h2 id="softglaze-a11y-group-([a-z]+)">([^<]+)</h2>#', $html, $groups );
		$this->assertSame( array( 'images', 'links', 'forms', 'structure', 'keyboard', 'embeds' ), $groups[1] );
		$this->assertSame( array( 'Images', 'Links and buttons', 'Forms', 'Page structure', 'Keyboard', 'Embeds and media' ), $groups[2] );
		$grouped = call_user_func_array( 'array_merge', array_values( Screen_Fixes::GROUPS ) );
		sort( $grouped );
		$ids = $registry->ids();
		sort( $ids );
		$this->assertSame( $ids, $grouped, 'Every bundled fix is in exactly one group.' );

		$links = 0;
		foreach ( $registry->fixes() as $id => $fix ) {
			$description = $fix->description();
			$this->assertNotSame( '', $description, $id );
			$this->assertSame( 0, preg_match( self::BANNED, $description ), $id );
			$this->assertStringContainsString( '<p class="softglaze-a11y-fix-what" id="softglaze-a11y-fix-' . $id . '-what">' . esc_html( $description ) . '</p>', $html );
			$this->assertStringContainsString( '<fieldset class="softglaze-a11y-fix-card" id="softglaze-a11y-fix-' . $id . '" aria-describedby="softglaze-a11y-fix-' . $id . '-what">', $html );
			foreach ( $fix->wcag() as $criterion ) {
				$this->assertArrayHasKey( $criterion, Screen_Fixes::UNDERSTANDING, $id );
				$this->assertNotSame( '', Screen_Fixes::criterion_name( $criterion ) );
				++$links;
			}
		}
		$this->assertSame( $links, substr_count( $html, '>Learn more<span class="screen-reader-text"> about ' ) );
		foreach ( Screen_Fixes::UNDERSTANDING as $criterion => $slug ) {
			$this->assertStringContainsString( 'href="https://www.w3.org/WAI/WCAG22/Understanding/' . $slug . '.html"', $html, $criterion );
		}
		$this->assertStringContainsString( 'Learn more<span class="screen-reader-text"> about 4.1.2 Name, Role, Value on the W3C website</span></a>', $html );

		$f02 = $registry->get( 'img-alt-empty-library' )->description();
		$this->assertStringContainsString( 'Starts Off', $f02 );
		$this->assertStringContainsString( 'block editor writes alt=""', $f02 );
		$this->assertStringContainsString( 'with the keyboard', $registry->get( 'aria-hidden-focus' )->description() );
		$this->assertSame( 2, substr_count( $html, 'Risk: High.' ) );
		$this->assertSame( 20, substr_count( $html, 'No page has been scanned yet.' ), 'F04 and F18 never appear in scan reports.' );
		$this->assertSame( 2, substr_count( $html, 'Scan reports do not list this fix' ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( 'softglaze.com', $html );
	}

	/**
	 * "Would change N on scanned pages" adds up every stored report (all
	 * three states), says "Changes" for Live fixes, and is recomputed after a
	 * new report.
	 */
	public function test_fixes_scanned_page_counts() {
		self::login( 'administrator' );
		self::store(
			'http://example.org/a/',
			array(
				'iframe-title' => array(
					'live'    => 0,
					'preview' => 2,
					'off'     => 0,
				),
				'link-name'    => array(
					'live'    => 3,
					'preview' => 0,
					'off'     => 0,
				),
			)
		);
		self::store(
			'http://example.org/b/',
			array(
				'iframe-title' => array(
					'live'    => 0,
					'preview' => 0,
					'off'     => 1,
				),
			)
		);
		Plugin::instance()->settings()->save_fix_states( array( 'link-name' => 'live' ), get_current_user_id() );

		$totals = Plugin::instance()->pages()->fix_totals();
		$this->assertSame( 2, $totals['reports'] );
		$this->assertSame(
			array(
				'changes' => 3,
				'pages'   => 2,
			),
			$totals['fixes']['iframe-title']
		);
		$this->assertIsArray( get_transient( Pages_Store::TOTALS_TRANSIENT ) );

		$html = self::fixes_html();
		$this->assertStringContainsString( 'Would change 3 elements on 2 of the 2 scanned pages.', $html );
		$this->assertStringContainsString( 'Changes 3 elements on 1 of the 2 scanned pages.', $html );
		$this->assertStringContainsString( 'No changes on the 2 scanned pages.', $html );

		self::store(
			'http://example.org/c/',
			array(
				'iframe-title' => array(
					'live'    => 0,
					'preview' => 1,
					'off'     => 0,
				),
			)
		);
		$this->assertSame( 3, Plugin::instance()->pages()->fix_totals()['reports'], 'A new report invalidates the cached sums.' );
		$this->assertStringContainsString( 'Would change 4 elements on 3 of the 3 scanned pages.', self::fixes_html() );
	}

	/**
	 * A save that moves a fix to or from Live carries the cache reminder; other saves do not.
	 */
	public function test_fixes_save_cache_reminder() {
		self::login( 'administrator' );
		$_POST = array(
			Screen_Fixes::NONCE_FIELD => wp_create_nonce( Screen_Fixes::ACTION ),
			Screen_Fixes::FIELD       => array( 'html-lang' => 'off' ),
		);
		$url = Plugin::instance()->fixes_screen()->process_save();
		$this->assertStringNotContainsString( Screen_Fixes::CACHE_ARG, $url );

		$_POST[ Screen_Fixes::FIELD ] = array( 'iframe-title' => 'live' );
		$url                          = Plugin::instance()->fixes_screen()->process_save();
		$this->assertStringContainsString( Screen_Fixes::CACHE_ARG . '=1', $url );

		$_GET[ Screen_Fixes::STATUS_ARG ] = 'saved';
		$_GET[ Screen_Fixes::CACHE_ARG ]  = '1';
		$html                             = self::fixes_html();
		$this->assertStringContainsString( '<div class="notice notice-success inline softglaze-a11y-status" role="status" tabindex="-1"><p>Fix states saved.</p><p>' . esc_html( Cache_Reminder::after_change() ) . '</p></div>', $html );
	}

	/**
	 * "Move all Preview fixes to Live": the confirmation lists the Preview
	 * fixes (and those that cannot run here), the handler refuses without
	 * capability or nonce, then moves only listed fixes still in Preview.
	 */
	public function test_bulk_live_confirmation_and_handler() {
		self::login( 'administrator' );
		$registry = Plugin::instance()->registry();
		$settings = Plugin::instance()->settings();

		$_GET[ Screen_Fixes::STEP_ARG ] = Screen_Fixes::STEP_CONFIRM;
		$html                           = self::fixes_html();
		$this->assertStringContainsString( '<h1>Move all Preview fixes to Live</h1>', $html );
		$ready = 0;
		foreach ( $settings->fix_states() as $id => $state ) {
			if ( 'preview' !== $state ) {
				continue;
			}
			if ( $registry->is_available( $registry->get( $id ) ) ) {
				++$ready;
				$this->assertStringContainsString( 'name="' . Screen_Fixes::BULK_FIELD . '[]" value="' . $id . '"', $html );
			} else {
				$this->assertStringNotContainsString( 'value="' . $id . '"', $html, $id );
			}
		}
		$this->assertSame( $ready, substr_count( $html, 'name="' . Screen_Fixes::BULK_FIELD . '[]"' ) );
		$this->assertStringContainsString( 'Move these ' . $ready . ' fixes to Live', $html );
		$this->assertStringContainsString( 'name="action" value="' . Screen_Fixes::BULK_ACTION . '"', $html );
		if ( ! self::has_page_pass() ) {
			$this->assertStringContainsString( 'These stay in Preview because they cannot run on this site:', $html );
			$this->assertStringContainsString( 'Needs WordPress 6.9 or later', $html );
		}
		$_GET = array();

		$before = get_option( Settings::OPTION );
		$_POST  = array( Screen_Fixes::BULK_FIELD => array( 'iframe-title' ) );
		$this->assertSame( 403, self::die_code( array( Plugin::instance()->fixes_screen(), 'process_bulk_live' ) ), 'no nonce' );
		$_POST[ Screen_Fixes::NONCE_FIELD ] = wp_create_nonce( Screen_Fixes::ACTION );
		$this->assertSame( 403, self::die_code( array( Plugin::instance()->fixes_screen(), 'process_bulk_live' ) ), 'nonce for another action' );
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			self::login( $role );
			$_POST[ Screen_Fixes::NONCE_FIELD ] = wp_create_nonce( Screen_Fixes::BULK_ACTION );
			$this->assertSame( 403, self::die_code( array( Plugin::instance()->fixes_screen(), 'process_bulk_live' ) ), $role );
		}
		$this->assertSame( $before, get_option( Settings::OPTION ) );

		self::login( 'administrator' );
		$log_before = self::log_count();
		$_POST      = array(
			Screen_Fixes::NONCE_FIELD => wp_create_nonce( Screen_Fixes::BULK_ACTION ),
			Screen_Fixes::BULK_FIELD  => array( 'iframe-title', 'html-lang', 'made-up', 'img-alt-empty-library', array( 'nested' ) ),
		);
		$url        = Plugin::instance()->fixes_screen()->process_bulk_live();
		$states     = $settings->fix_states();
		$this->assertSame( 'live', $states['iframe-title'] );
		$this->assertSame( 'live', $states['html-lang'] );
		$this->assertSame( 'off', $states['img-alt-empty-library'], 'Off fixes are not moved.' );
		$this->assertSame( 'preview', $states['link-name'], 'Fixes not listed are not moved.' );
		$this->assertStringContainsString( Screen_Fixes::STATUS_ARG . '=bulk', $url );
		$this->assertStringContainsString( Screen_Fixes::COUNT_ARG . '=2', $url );
		$this->assertStringContainsString( Screen_Fixes::CACHE_ARG . '=1', $url );
		$this->assertSame( $log_before + 2, self::log_count(), 'One fix_state_changed row per moved fix.' );

		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );
		$_GET = $args;
		$html = self::fixes_html();
		$this->assertStringContainsString( '<p>2 fixes moved to Live: visitors get their changes now.</p><p>' . esc_html( Cache_Reminder::after_change() ) . '</p>', $html );
	}

	/**
	 * Settings screen: the Settings API form with core's nonce, the six
	 * fields, no field for code and no footer link; the status after a save.
	 */
	public function test_settings_screen_markup_and_status() {
		self::login( 'administrator' );
		$screen = Plugin::instance()->settings_screen();
		$html   = get_echo( array( $screen, 'render' ) );

		$this->assertStringContainsString( '<h1>Tagmend Settings</h1>', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'options.php' ) ) . '"', $html );
		$this->assertStringContainsString( "name='option_page' value='" . Settings::GROUP . "'", $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$name = Settings::OPTION;
		foreach ( array( 'admin_bar', 'delete_data_on_uninstall' ) as $key ) {
			$this->assertStringContainsString( 'type="hidden" name="' . $name . '[' . $key . ']" value="0"', $html, $key );
			$this->assertStringContainsString( 'type="checkbox" id="softglaze-a11y-setting-', $html );
		}
		$this->assertMatchesRegularExpression( '#name="' . preg_quote( $name, '#' ) . '\[admin_bar\]" value="1" aria-describedby="[^"]+"\s+checked=\'checked\'#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="' . preg_quote( $name, '#' ) . '\[delete_data_on_uninstall\]" value="1" aria-describedby="[^"]+"\s+checked#', $html, 'Delete all data starts off.' );
		$this->assertStringContainsString( 'name="' . $name . '[max_kb]" value="2048" min="64" max="8192"', $html );
		$this->assertStringContainsString( 'name="' . $name . '[max_ms]" value="100" min="10" max="1000"', $html );
		$this->assertSame( 1, substr_count( $html, '<textarea' ) );
		$this->assertStringContainsString( 'name="' . $name . '[exclude_paths]"', $html );
		$this->assertStringNotContainsString( 'softglaze.com', $html, 'No footer link (decision D-04).' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( 'role="status"', $html );
		if ( self::has_page_pass() ) {
			$this->assertStringContainsString( 'type="hidden" name="' . $name . '[page_scope]" value="0"', $html );
		} else {
			$this->assertStringNotContainsString( 'name="' . $name . '[page_scope]" value="0"', $html, 'A disabled box must not switch the setting off.' );
			$this->assertMatchesRegularExpression( '#name="' . preg_quote( $name, '#' ) . '\[page_scope\]" value="1"[^>]*disabled=\'disabled\'#', $html );
			$this->assertStringContainsString( 'Needs WordPress 6.9 or later.', $html );
		}

		$_GET['settings-updated'] = 'true';
		add_settings_error( Settings::OPTION, Settings::PATHS_ERROR, 'Warning text', 'warning' );
		$html = get_echo( array( $screen, 'render' ) );
		$this->assertStringContainsString( '<div class="notice notice-warning inline softglaze-a11y-status" role="status" tabindex="-1"><p>Settings saved.</p><p>Warning text</p></div>', $html );

		self::login( 'editor' );
		$this->assertSame( '', get_echo( array( $screen, 'render' ) ) );
	}

	/**
	 * A Settings API save that changes something writes one settings_saved
	 * row naming the changed keys; path lines that do not start with "/" are
	 * reported; unchanged saves, fix saves, a wrong nonce and users without
	 * the capability write no such row.
	 */
	public function test_settings_save_writes_one_log_row() {
		$screen = Plugin::instance()->settings_screen();
		$screen->register();
		try {
			self::login( 'administrator' );
			$_POST = array(
				'option_page' => Settings::GROUP,
				'_wpnonce'    => wp_create_nonce( Settings::GROUP . '-options' ),
			);
			$before = self::settings_rows();
			update_option(
				Settings::OPTION,
				array(
					'admin_bar'     => '0',
					'max_kb'        => '512',
					'max_ms'        => '100',
					'exclude_paths' => "/checkout/\nnot-a-path\n\n/cart/",
				)
			);
			$rows = self::settings_rows();
			$this->assertCount( count( $before ) + 1, $rows );
			$this->assertSame( 'changed=admin_bar,max_kb,exclude_paths', end( $rows )['detail'] );
			$this->assertSame( array( '/checkout/', '/cart/' ), Plugin::instance()->settings()->exclude_paths() );
			$this->assertFalse( Plugin::instance()->settings()->admin_bar_enabled() );
			$this->assertSame( 512 * 1024, Plugin::instance()->settings()->max_bytes() );
			$errors = get_settings_errors( Settings::OPTION );
			$this->assertCount( 1, $errors );
			$this->assertSame( Settings::PATHS_ERROR, $errors[0]['code'] );
			$this->assertStringStartsWith( '1 line of "Never repair these paths" was not saved', $errors[0]['message'] );

			$described = Plugin::instance()->log_screen()->describe( end( $rows ) );
			$this->assertSame( 'Settings saved', $described['event'] );
			$this->assertSame( 'Changed: Admin bar menu, Size limit, Never repair these paths', $described['detail'] );

			update_option( Settings::OPTION, array_merge( get_option( Settings::OPTION ), array( 'max_kb' => 512 ) ) );
			$this->assertCount( count( $rows ), self::settings_rows(), 'No change, no row.' );

			$_POST = array();
			Plugin::instance()->settings()->save_fix_states( array( 'iframe-title' => 'live' ), get_current_user_id() );
			update_option( Settings::OPTION, array( 'max_ms' => 200 ) );
			$this->assertCount( count( $rows ), self::settings_rows(), 'Saves outside the Settings screen write no settings row.' );

			$_POST = array(
				'option_page' => Settings::GROUP,
				'_wpnonce'    => wp_create_nonce( 'softglaze_a11y_view' ),
			);
			update_option( Settings::OPTION, array( 'max_ms' => 300 ) );
			$this->assertCount( count( $rows ), self::settings_rows(), 'Wrong nonce.' );

			self::login( 'editor' );
			$_POST['_wpnonce'] = wp_create_nonce( Settings::GROUP . '-options' );
			update_option( Settings::OPTION, array( 'max_ms' => 400 ) );
			$this->assertCount( count( $rows ), self::settings_rows(), 'No capability.' );
		} finally {
			remove_action( 'update_option_' . Settings::OPTION, array( $screen, 'record_save' ), 10 );
			$_POST = array();
		}
	}

	/**
	 * Values a settings save can never set: code, unknown keys, out-of-range numbers.
	 */
	public function test_settings_values_are_clamped_and_plain() {
		self::login( 'administrator' );
		update_option(
			Settings::OPTION,
			array(
				'max_kb'                   => '999999',
				'max_ms'                   => '-5',
				'exclude_paths'            => "/ok/\n/<script>alert(1)</script>/",
				'delete_data_on_uninstall' => '1',
				'custom_css'               => 'body{display:none}',
			)
		);
		$saved = get_option( Settings::OPTION );
		$this->assertSame( Settings::MAX_KB_MAX, $saved['max_kb'] );
		$this->assertSame( Settings::MAX_MS_MIN, $saved['max_ms'] );
		$this->assertTrue( $saved['delete_data_on_uninstall'] );
		$this->assertTrue( Plugin::instance()->settings()->delete_data_on_uninstall() );
		$this->assertArrayNotHasKey( 'custom_css', $saved );
		foreach ( $saved['exclude_paths'] as $path ) {
			$this->assertStringNotContainsString( '<', $path );
		}
	}

	/**
	 * Privacy text: the blueprint statements, escaped paragraphs, no banned word;
	 * handed to core's privacy guide on admin_init.
	 */
	public function test_privacy_policy_text() {
		$text = Privacy::policy_text();
		$this->assertStringStartsWith( '<p>', $text );
		$this->assertSame( 3, substr_count( $text, '<p>' ) );
		$this->assertStringContainsString( 'never form values', $text );
		$this->assertStringContainsString( 'WordPress user IDs of the administrators', $text );
		$this->assertStringContainsString( 'sets no cookies, collects nothing about visitors and sends nothing off this site', $text );
		$this->assertSame( 0, preg_match( self::BANNED, $text ) );

		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		self::login( 'administrator' );
		set_current_screen( 'dashboard' );
		// Core accepts the text only during or after admin_init; the action itself is not fired here (its core callbacks check for updates).
		$fired                               = isset( $GLOBALS['wp_actions']['admin_init'] ) ? $GLOBALS['wp_actions']['admin_init'] : null;
		$GLOBALS['wp_actions']['admin_init'] = 1;
		try {
			( new Privacy() )->add_policy_content();
		} finally {
			if ( null === $fired ) {
				unset( $GLOBALS['wp_actions']['admin_init'] );
			} else {
				$GLOBALS['wp_actions']['admin_init'] = $fired;
			}
		}
		$names = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( "Tagmend \u{2013} Accessibility Fixes", $names );
	}

	/**
	 * Network activation: a translated reason; on a single site the
	 * activation runs normally even when a caller passes true.
	 */
	public function test_network_activation_message() {
		$this->assertStringContainsString( 'cannot be activated for the whole network', Install::network_activation_message() );
		$this->assertSame( 0, preg_match( self::BANNED, Install::network_activation_message() ) );
		if ( is_multisite() ) {
			$this->markTestSkipped( 'The test site is a single site.' );
		}
		delete_option( Settings::OPTION );
		Plugin::instance()->install()->activate( true );
		$this->assertIsArray( get_option( Settings::OPTION ) );
	}

	/**
	 * Every settings_saved row, oldest first.
	 *
	 * @return array[]
	 */
	private static function settings_rows() {
		return array_values(
			array_filter(
				self::log_rows(),
				static function ( $row ) {
					return 'settings_saved' === $row['event'];
				}
			)
		);
	}
}
