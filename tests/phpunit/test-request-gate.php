<?php
/**
 * Gate tests (GT, docs/05-TEST-PLAN.md section 4) and SEC-07 (section 5): the
 * engine must not run and no repair layer may be registered outside a
 * front-end HTML GET request; the view switcher needs manage_options and a
 * valid nonce.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Request_Gate;

require_once __DIR__ . '/helpers/class-wiring-test-case.php';

if ( ! function_exists( 'amp_is_request' ) ) {
	/**
	 * Stand-in for the AMP plugin's function (absent otherwise).
	 *
	 * @return bool
	 */
	function amp_is_request() {
		return ! empty( $GLOBALS['softglaze_a11y_test_amp'] );
	}
}

/**
 * Request gate tests.
 */
class Test_Request_Gate extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * Post with an untitled iframe.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Every fix Live, so any registered layer would repair something.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = self::post_with_iframe();
		self::set_states( 'live' );
	}

	/**
	 * Clears test globals.
	 */
	public function tear_down() {
		unset( $GLOBALS['softglaze_a11y_test_amp'], $GLOBALS['wp_customize'] );
		parent::tear_down();
	}

	/**
	 * Re-runs the gate and the wiring for the current globals and asserts a refusal.
	 *
	 * @param string $reason Expected reason.
	 * @return void
	 */
	private function assert_refused( $reason ) {
		$plugin = Plugin::instance();
		$plugin->gate()->reset();
		$plugin->front_end()->reset();
		$plugin->front_end()->on_template_redirect();

		$this->assertFalse( $plugin->gate()->passes(), "gate refuses ($reason)" );
		$this->assertSame( $reason, $plugin->gate()->reason() );
		$this->assertSame( Engine::MODE_VISITOR, $plugin->gate()->mode() );
		$this->assertSame( array(), $plugin->front_end()->layers(), "no layer ($reason)" );
		$this->assertSame( array(), self::registered_hooks( $plugin->front_end() ), "no hook ($reason)" );
		$this->assertSame( self::IFRAME, $plugin->front_end()->filter_content( self::IFRAME ), "Layer B callback is inert ($reason)" );
		$this->assertSame( self::PAGE, $plugin->front_end()->filter_page( self::PAGE, '' ), "Layer C callback is inert ($reason)" );
		$this->assertSame( 'dir="ltr"', $plugin->front_end()->filter_language_attributes( 'dir="ltr"' ), "Layer A callback is inert ($reason)" );
	}

	/**
	 * Asserts a refusal through a gate with faked request-type constants.
	 *
	 * @param string $constant Constant to fake.
	 * @param string $reason   Expected reason.
	 * @return void
	 */
	private function assert_refused_by_constant( $constant, $reason ) {
		$this->go_to( get_permalink( $this->post_id ) );
		$plugin               = Plugin::instance();
		$gate                 = new Softglaze_A11y_Test_Gate( $plugin->settings() );
		$gate->fake_constants = array( $constant );
		$front                = new Front_End( $gate, $plugin->registry(), $plugin->settings() );
		$front->on_template_redirect();

		$this->assertFalse( $gate->passes() );
		$this->assertSame( $reason, $gate->reason() );
		$this->assertSame( array(), $front->layers() );
		$this->assertSame( array(), self::registered_hooks( $front ) );
		$this->assertSame( self::IFRAME, $front->filter_content( self::IFRAME ) );
	}

	/**
	 * Control: a front-end GET of a post passes and registers layers.
	 */
	public function test_gt_control_front_end_get_passes() {
		$front = $this->visit( get_permalink( $this->post_id ) );
		$gate  = Plugin::instance()->gate();
		$this->assertTrue( $gate->passes() );
		$this->assertSame( '', $gate->reason() );
		$this->assertSame( Engine::MODE_VISITOR, $gate->mode() );
		$this->assertNotEmpty( $front->layers() );
		$this->assertContains( 'language_attributes', self::registered_hooks( $front ) );
	}

	/**
	 * The gate is never computed before the wp action, and an early answer is not cached.
	 */
	public function test_gt_not_computed_before_wp_action() {
		$this->assertSame( 0, did_action( 'wp' ) );
		$gate = Plugin::instance()->gate();
		$this->assertFalse( $gate->passes() );
		$this->assertSame( '', $gate->reason() );

		$this->go_to( get_permalink( $this->post_id ) );
		$this->assertTrue( $gate->passes(), 'Computed once the wp action has run.' );
	}

	/**
	 * GT: is_admin().
	 */
	public function test_gt_admin() {
		$this->go_to( get_permalink( $this->post_id ) );
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assert_refused( 'admin' );
	}

	/**
	 * GT: admin-ajax.php.
	 */
	public function test_gt_ajax() {
		$this->go_to( get_permalink( $this->post_id ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assert_refused( 'ajax' );
	}

	/**
	 * GT: ?wc-ajax= (WooCommerce AJAX endpoint).
	 */
	public function test_gt_wc_ajax_arg() {
		$this->go_to( add_query_arg( 'wc-ajax', 'get_refreshed_fragments', get_permalink( $this->post_id ) ) );
		$this->assert_refused( 'builder' );
	}

	/**
	 * GT: WP-Cron.
	 */
	public function test_gt_cron() {
		$this->go_to( get_permalink( $this->post_id ) );
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assert_refused( 'cron' );
	}

	/**
	 * GT: REST request (REST_REQUEST constant).
	 */
	public function test_gt_rest_constant() {
		$this->assert_refused_by_constant( 'REST_REQUEST', 'rest' );
	}

	/**
	 * GT: XML-RPC.
	 */
	public function test_gt_xmlrpc() {
		$this->assert_refused_by_constant( 'XMLRPC_REQUEST', 'xmlrpc' );
	}

	/**
	 * GT: WP-CLI.
	 */
	public function test_gt_wp_cli() {
		$this->assert_refused_by_constant( 'WP_CLI', 'cli' );
	}

	/**
	 * GT: the kill switch, through the gate (FS-17 with the real constant is in test-zz-kill-switch.php).
	 */
	public function test_gt_kill_switch() {
		$this->assert_refused_by_constant( 'SOFTGLAZE_A11Y_DISABLE', 'disabled' );
	}

	/**
	 * GT: a JSON request (wp_is_json_request()).
	 */
	public function test_gt_json_request() {
		$this->go_to( get_permalink( $this->post_id ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/json';
		$this->assert_refused( 'json' );
	}

	/**
	 * GT: POST.
	 */
	public function test_gt_post() {
		$this->go_to( get_permalink( $this->post_id ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assert_refused( 'method' );
	}

	/**
	 * GT: HEAD.
	 */
	public function test_gt_head() {
		$this->go_to( get_permalink( $this->post_id ) );
		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$this->assert_refused( 'method' );
	}

	/**
	 * GT: a method the gate does not know.
	 */
	public function test_gt_unknown_method() {
		$this->go_to( get_permalink( $this->post_id ) );
		$_SERVER['REQUEST_METHOD'] = '<get>';
		$this->assert_refused( 'method' );
	}

	/**
	 * GT: RSS feed.
	 */
	public function test_gt_feed() {
		$this->go_to( get_feed_link() );
		$this->assertTrue( is_feed() );
		$this->assert_refused( 'feed' );
	}

	/**
	 * GT: ?embed.
	 */
	public function test_gt_embed() {
		$this->go_to( get_post_embed_url( $this->post_id ) );
		$this->assertTrue( is_embed() );
		$this->assert_refused( 'embed' );
	}

	/**
	 * GT: robots.txt.
	 */
	public function test_gt_robots() {
		$this->go_to( home_url( '/?robots=1' ) );
		$this->assertTrue( is_robots() );
		$this->assert_refused( 'robots' );
	}

	/**
	 * GT: favicon.ico.
	 */
	public function test_gt_favicon() {
		$this->go_to( home_url( '/?favicon=1' ) );
		$this->assertTrue( is_favicon() );
		$this->assert_refused( 'favicon' );
	}

	/**
	 * GT: trackback.
	 */
	public function test_gt_trackback() {
		$this->go_to( add_query_arg( 'tb', '1', get_permalink( $this->post_id ) ) );
		$this->assertTrue( is_trackback() );
		$this->assert_refused( 'trackback' );
	}

	/**
	 * GT: Customizer preview.
	 */
	public function test_gt_customize_preview() {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		$this->go_to( get_permalink( $this->post_id ) );
		$GLOBALS['wp_customize'] = new WP_Customize_Manager();
		$GLOBALS['wp_customize']->start_previewing_theme();
		$this->assertTrue( is_customize_preview() );
		$this->assert_refused( 'customize' );
	}

	/**
	 * GT: AMP request (amp_is_request() when it exists).
	 */
	public function test_gt_amp() {
		$this->go_to( get_permalink( $this->post_id ) );
		$GLOBALS['softglaze_a11y_test_amp'] = true;
		$this->assert_refused( 'amp' );
	}

	/**
	 * Builder-editor query args.
	 *
	 * @return array
	 */
	public function data_builder_args() {
		$rows = array();
		foreach ( Request_Gate::BUILDER_ARGS as $arg ) {
			$rows[ $arg ] = array( $arg );
		}
		return $rows;
	}

	/**
	 * GT: each builder-editor query arg, with any value.
	 *
	 * @dataProvider data_builder_args
	 *
	 * @param string $arg Query arg.
	 */
	public function test_gt_builder_editor_arg( $arg ) {
		$this->go_to( add_query_arg( $arg, '', get_permalink( $this->post_id ) ) );
		$this->assert_refused( 'builder' );
	}

	/**
	 * GT: excluded path (string prefix, relative to the home path).
	 */
	public function test_gt_excluded_path() {
		self::set_states( 'live', array( 'exclude_paths' => "/members\n/shop/cart" ) );
		$this->go_to( home_url( '/members/area/?p=' . $this->post_id ) );
		$this->assertTrue( is_singular() );
		$this->assert_refused( 'path' );

		$this->visit( home_url( '/news/?p=' . $this->post_id ) );
		$this->assertTrue( Plugin::instance()->gate()->passes(), 'Other paths pass.' );
	}

	/**
	 * GT: a fix skipped on this post (_softglaze_a11y_skip_fixes) is not applied; the gate still passes.
	 */
	public function test_gt_skipped_fix_on_post() {
		self::set_states( 'off' );
		self::set_states( array( 'iframe-title' => 'live' ), array( 'page_scope' => false ) );
		update_post_meta( $this->post_id, '_softglaze_a11y_skip_fixes', array( 'iframe-title' ) );

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertTrue( Plugin::instance()->gate()->passes() );
		$this->assertSame( 'off', $front->states()['iframe-title'] );
		$this->assertSame( array(), $front->layers() );
		$this->assertStringNotContainsString( 'title="', apply_filters( 'the_content', self::IFRAME ) );

		$other = self::post_with_iframe();
		$front = $this->visit( get_permalink( $other ) );
		$this->assertSame( 'live', $front->states()['iframe-title'], 'Other posts keep the fix.' );
		$this->assertStringContainsString( 'title="YouTube video"', apply_filters( 'the_content', self::IFRAME ) );
	}

	/**
	 * GT: softglaze_a11y_should_process returning false.
	 */
	public function test_gt_should_process_filter() {
		$this->go_to( get_permalink( $this->post_id ) );
		add_filter( 'softglaze_a11y_should_process', '__return_false' );
		$this->assert_refused( 'filter' );
	}

	/**
	 * GT: REST GET /wp/v2/posts/<id>: content.rendered with every fix Live is
	 * byte-identical to the plugin doing nothing, both for a REST request
	 * (template_redirect never runs) and for an internal dispatch while a
	 * front-end page with Layer B registered is being served.
	 */
	public function test_gt_rest_content_rendered_untouched() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		$route = '/wp/v2/posts/' . $this->post_id;

		$baseline = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_data()['content']['rendered'];
		$this->assertStringContainsString( '<iframe src="https://www.youtube.com/embed/abc"></iframe>', $baseline );

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertArrayHasKey( 'content', $front->layers() );
		$this->assertStringContainsString( 'title="YouTube video"', apply_filters( 'the_content', self::IFRAME ), 'Layer B repairs the page itself.' );

		$rendered = rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_data()['content']['rendered'];
		$this->assertSame( $baseline, $rendered );
	}

	/**
	 * SEC-07: the view arg without a nonce, as an administrator, is a visitor request.
	 */
	public function test_sec07_view_without_nonce_is_visitor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$front = $this->visit( add_query_arg( Request_Gate::VIEW_ARG, 'repaired', get_permalink( $this->post_id ) ) );
		$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode() );
		$this->assertFalse( $front->no_cache_sent() );
	}

	/**
	 * SEC-07: a wrong nonce is a visitor request.
	 */
	public function test_sec07_view_with_wrong_nonce_is_visitor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$url   = add_query_arg(
			array(
				Request_Gate::VIEW_ARG       => 'original',
				Request_Gate::VIEW_NONCE_ARG => wp_create_nonce( 'another_action' ),
			),
			get_permalink( $this->post_id )
		);
		$front = $this->visit( $url );
		$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode() );
		$this->assertFalse( $front->no_cache_sent() );
		$this->assertNotEmpty( $front->layers(), 'Visitor mode keeps the Live fixes.' );
	}

	/**
	 * SEC-07: a subscriber and an editor with nonces minted for their own sessions are visitors (capability first).
	 */
	public function test_sec07_non_admin_with_own_nonce_is_visitor() {
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$url   = add_query_arg(
				array(
					Request_Gate::VIEW_ARG       => 'repaired',
					Request_Gate::VIEW_NONCE_ARG => wp_create_nonce( Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) ) ),
				),
				get_permalink( $this->post_id )
			);
			$front = $this->visit( $url );
			$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode(), $role );
			$this->assertFalse( $front->no_cache_sent(), $role );
		}
	}

	/**
	 * SEC-07: an administrator's view link opened logged out is a visitor request.
	 */
	public function test_sec07_admin_link_logged_out_is_visitor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$url = add_query_arg(
			array(
				Request_Gate::VIEW_ARG       => 'repaired',
				Request_Gate::VIEW_NONCE_ARG => wp_create_nonce( Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) ) ),
			),
			get_permalink( $this->post_id )
		);
		wp_set_current_user( 0 );
		$front = $this->visit( $url );
		$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode() );
		$this->assertFalse( $front->no_cache_sent() );
	}

	/**
	 * SEC-07: array-shaped and unknown view values are visitor requests, without notices.
	 */
	public function test_sec07_malformed_view_values_are_visitor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce = wp_create_nonce( Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) ) );
		$base  = get_permalink( $this->post_id );
		foreach ( array(
			$base . '&softglaze_a11y_view[]=repaired&softglaze_a11y_view_nonce=' . $nonce,
			$base . '&softglaze_a11y_view=repaired&softglaze_a11y_view_nonce[]=' . $nonce,
			$base . '&softglaze_a11y_view=everything&softglaze_a11y_view_nonce=' . $nonce,
		) as $url ) {
			$this->visit( $url );
			$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode(), $url );
		}
	}

	/**
	 * View modes with a valid nonce: original applies nothing, repaired applies
	 * Live and Preview; both are marked not cacheable.
	 */
	public function test_admin_views_with_valid_nonce() {
		self::set_states( 'preview', array( 'page_scope' => false ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce = wp_create_nonce( Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $this->post_id ) ) ) );

		$front = $this->visit( add_query_arg( Request_Gate::VIEW_ARG, 'original', get_permalink( $this->post_id ) ) . '&' . Request_Gate::VIEW_NONCE_ARG . '=' . $nonce );
		$this->assertSame( Engine::MODE_ADMIN_ORIGINAL, Plugin::instance()->gate()->mode() );
		$this->assertTrue( $front->no_cache_sent() );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
		$this->assertSame( array(), $front->layers() );

		$front = $this->visit( add_query_arg( Request_Gate::VIEW_ARG, 'repaired', get_permalink( $this->post_id ) ) . '&' . Request_Gate::VIEW_NONCE_ARG . '=' . $nonce );
		$this->assertSame( Engine::MODE_ADMIN_REPAIRED, Plugin::instance()->gate()->mode() );
		$this->assertTrue( $front->no_cache_sent() );
		$this->assertArrayHasKey( 'content', $front->layers() );
		$this->assertStringContainsString( 'title="YouTube video"', apply_filters( 'the_content', self::IFRAME ), 'Preview fixes apply in View repaired.' );

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( Engine::MODE_VISITOR, Plugin::instance()->gate()->mode() );
		$this->assertSame( array(), $front->layers(), 'Preview fixes do nothing for visitors.' );
	}

	/**
	 * The URL hash keeps only scheme, host, path and the p, page_id, paged and product args.
	 */
	public function test_url_hash_normalisation() {
		$gate = Plugin::instance()->gate();
		$this->go_to( home_url( '/?p=' . $this->post_id . '&utm_source=x&softglaze_a11y_view=repaired' ) );
		$with_noise = $gate->url_hash();
		$this->go_to( home_url( '/?p=' . $this->post_id ) );
		$this->assertSame( $with_noise, $gate->url_hash() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $with_noise );
		$this->go_to( home_url( '/?p=' . ( $this->post_id + 1 ) ) );
		$this->assertNotSame( $with_noise, $gate->url_hash() );
	}
}
