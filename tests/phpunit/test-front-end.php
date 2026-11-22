<?php
/**
 * Wiring of layers A, B and C, and FS-10 (with the plugin's own callback),
 * FS-14, FS-15, FS-16 and FS-18 (docs/05-TEST-PLAN.md section 4).
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Fixes\Fix_Focus_Visible;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link;
use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Plugin;

require_once __DIR__ . '/helpers/class-wiring-test-case.php';

/**
 * Front-end wiring tests.
 */
class Test_Front_End extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * Post with an untitled iframe.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Creates the post.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_id = self::post_with_iframe();
	}

	/**
	 * With every fix Live: Layer C on 6.9+, Layer B below; Layer A for F10 always.
	 */
	public function test_layers_registered_per_version() {
		self::set_states( 'live' );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$hooks = self::registered_hooks( $front );

		$this->assertContains( 'language_attributes', $hooks );
		$this->assertSame( Front_End::LANGUAGE_PRIORITY, has_filter( 'language_attributes', array( $front, 'filter_language_attributes' ) ) );
		$this->assertSame( Front_End::SHUTDOWN_PRIORITY, has_action( 'shutdown', array( $front, 'record_fallbacks' ) ) );

		if ( self::has_page_pass() ) {
			$this->assertSame(
				array(
					'attribute' => true,
					'page'      => true,
				),
				$front->layers()
			);
			$this->assertSame( Front_End::PAGE_PRIORITY, has_filter( Front_End::PAGE_FILTER, array( $front, 'filter_page' ) ) );
			$this->assertFalse( has_filter( 'the_content', array( $front, 'filter_content' ) ), 'Layer B is not registered when Layer C is.' );
		} else {
			$this->assertSame(
				array(
					'attribute' => true,
					'content'   => true,
				),
				$front->layers()
			);
			$this->assertFalse( has_filter( Front_End::PAGE_FILTER, array( $front, 'filter_page' ) ), 'No Layer C below WordPress 6.9.' );
			$this->assertSame( 'off', $front->states()['viewport-zoom'], 'A page-only fix cannot run below 6.9.' );
		}
	}

	/**
	 * Layer B hooks and priorities, used when the page pass is switched off (or missing).
	 */
	public function test_layer_b_hooks_when_page_pass_off() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		$front = $this->visit( get_permalink( $this->post_id ) );

		$this->assertArrayHasKey( 'content', $front->layers() );
		$this->assertArrayNotHasKey( 'page', $front->layers() );
		foreach ( Front_End::CONTENT_FILTERS as $hook => $priority ) {
			$this->assertSame( $priority, has_filter( $hook, array( $front, 'filter_content' ) ), $hook );
		}
		$this->assertSame( 999, has_filter( 'the_content', array( $front, 'filter_content' ) ) );
	}

	/**
	 * Layer B repairs post content, widget text and template parts.
	 */
	public function test_layer_b_repairs_fragments() {
		self::set_states(
			array(
				'iframe-title'  => 'live',
				'html-lang'     => 'off',
				'viewport-zoom' => 'off',
			),
			array( 'page_scope' => false )
		);
		$this->visit( get_permalink( $this->post_id ) );

		$this->assertStringContainsString( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc">', apply_filters( 'the_content', self::IFRAME ) );
		$this->assertSame( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc"></iframe>', apply_filters( 'widget_text', self::IFRAME, array(), null ) );
		$this->assertSame( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc"></iframe>', apply_filters( 'widget_block_content', self::IFRAME, array(), null ) );
		$this->assertSame( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc"></iframe>', apply_filters( 'render_block_core/template-part', self::IFRAME, array(), null ) );
	}

	/**
	 * Layer A: lang added when another filter removed it, corrected when malformed, kept when valid.
	 */
	public function test_layer_a_language_attributes() {
		self::set_states(
			array(
				'iframe-title'  => 'off',
				'html-lang'     => 'live',
				'viewport-zoom' => 'off',
			),
			array( 'page_scope' => false )
		);
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( array( 'attribute' => true ), $front->layers(), 'F10 has no content scope, so only Layer A runs without the page pass.' );

		$strip = static function () {
			return 'dir="ltr"';
		};
		add_filter( 'language_attributes', $strip, 10 );
		$this->assertSame( 'lang="en-US" dir="ltr"', get_language_attributes() );
		remove_filter( 'language_attributes', $strip, 10 );

		$this->assertSame( 'lang="en-GB"', $front->filter_language_attributes( 'lang="en_GB"' ) );
		$this->assertSame( 'lang="fr-FR" dir="ltr"', $front->filter_language_attributes( 'lang="fr-FR" dir="ltr"' ) );
		$this->assertSame( 'lang="en-US"', $front->filter_language_attributes( '' ) );
		$this->assertSame( 'lang="en-US" xmlns:og="http://ogp.me/ns#"', $front->filter_language_attributes( 'xmlns:og="http://ogp.me/ns#"' ) );
		$this->assertNull( $front->filter_language_attributes( null ) );
	}

	/**
	 * FS-14: a throwable inside an attribute callback returns its input unchanged
	 * (language_attributes for F10, wp_get_attachment_image_attributes for F04).
	 */
	public function test_fs14_throwable_in_attribute_callback_returns_input() {
		self::set_states( 'live' );
		$this->go_to( get_permalink( $this->post_id ) );
		$plugin               = Plugin::instance();
		$front                = new Softglaze_A11y_Test_Front_End( $plugin->gate(), $plugin->registry(), $plugin->settings() );
		$front->throw_in_lang = true;

		$this->assertSame( 'dir="ltr"', $front->filter_language_attributes( 'dir="ltr"' ) );
		$this->assertSame( '', $front->filter_language_attributes( '' ) );

		$front->product_fix = new Softglaze_A11y_Test_Throwing_Product_Fix();
		$attr               = array(
			'src'   => 'http://example.org/wp-content/uploads/bread.jpg',
			'class' => 'attachment-full size-full',
			'alt'   => '',
		);
		$this->assertSame( $attr, $front->filter_attachment_image_attributes( $attr, get_post( $this->post_id ) ) );
		$this->assertSame( 'not an array', $front->filter_attachment_image_attributes( 'not an array', null ) );
	}

	/**
	 * Layer A for F04: registered only when F04 applies in this mode (and WooCommerce
	 * makes it available); it hands the attributes to the fix and returns its result.
	 */
	public function test_layer_a_attachment_image_attributes() {
		$plugin = Plugin::instance();
		$this->go_to( get_permalink( $this->post_id ) );

		self::set_states( 'live' );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( 'off', $front->states()['product-img-alt-name'], 'Without WooCommerce F04 counts as Off.' );
		$this->assertFalse( has_filter( 'wp_get_attachment_image_attributes', array( $front, 'filter_attachment_image_attributes' ) ) );

		$double                 = new Softglaze_A11y_Test_Front_End( $plugin->gate(), $plugin->registry(), $plugin->settings() );
		$double->states_override = array( 'product-img-alt-name' => 'live' );
		$double->product_fix     = new Softglaze_A11y_Test_Product_Fix(
			array(
				'id'        => $this->post_id,
				'name'      => 'Sourdough loaf',
				'image_ids' => array( 5001 ),
			)
		);
		$double->on_template_redirect();
		$this->assertSame( Front_End::ATTACHMENT_PRIORITY, has_filter( 'wp_get_attachment_image_attributes', array( $double, 'filter_attachment_image_attributes' ) ) );
		$this->assertSame( array( 'attribute' => true ), $double->layers() );

		$attachment     = new stdClass();
		$attachment->ID = 5001;
		$this->assertSame( array( 'alt' => 'Sourdough loaf' ), $double->filter_attachment_image_attributes( array( 'alt' => '' ), $attachment ) );
		$this->assertSame( array( 'alt' => 'Loaf' ), $double->filter_attachment_image_attributes( array( 'alt' => 'Loaf' ), $attachment ) );
		remove_filter( 'wp_get_attachment_image_attributes', array( $double, 'filter_attachment_image_attributes' ), Front_End::ATTACHMENT_PRIORITY );

		$double->states_override = array( 'product-img-alt-name' => 'preview' );
		$double->reset();
		$double->on_template_redirect();
		$this->assertFalse( has_filter( 'wp_get_attachment_image_attributes', array( $double, 'filter_attachment_image_attributes' ) ), 'Preview is not applied for visitors.' );
	}

	/**
	 * Layer B also covers featured images and WooCommerce gallery images.
	 */
	public function test_layer_b_image_filters() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		$this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc"></iframe>', apply_filters( 'post_thumbnail_html', self::IFRAME, $this->post_id, 0, 'full', array() ) );
		$this->assertSame( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc"></iframe>', apply_filters( 'woocommerce_single_product_image_thumbnail_html', self::IFRAME, 0 ) );
	}

	/**
	 * Layer C on 6.9+: the whole page, including the head, is repaired through core's buffer finaliser.
	 */
	public function test_layer_c_repairs_whole_page() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		self::set_states( 'live' );
		$this->visit( get_permalink( $this->post_id ) );

		$result = self::finalize_buffer( self::PAGE );
		$this->assertStringContainsString( '<meta name="viewport" content="width=device-width">', $result );
		$this->assertStringContainsString( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc">', $result );
	}

	/**
	 * FS-10 with the plugin's own callback: a JSON template response never reaches it.
	 */
	public function test_fs10_json_response_never_reaches_our_callback() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		self::set_states( 'live' );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertArrayHasKey( 'page', $front->layers() );

		$calls = 0;
		$spy   = static function ( $filtered_output ) use ( &$calls ) {
			++$calls;
			return $filtered_output;
		};
		add_filter( Front_End::PAGE_FILTER, $spy, Front_End::PAGE_PRIORITY + 1 );

		$json = '{"html":"<iframe src=\"https://youtube.com/embed/x\"></iframe>"}';
		$this->assertSame( $json, self::finalize_buffer( $json, 'application/json' ) );
		$this->assertSame( 0, $calls );

		$this->assertStringContainsString( 'title="YouTube video"', self::finalize_buffer( self::PAGE ) );
		$this->assertSame( 1, $calls );
		remove_filter( Front_End::PAGE_FILTER, $spy, Front_End::PAGE_PRIORITY + 1 );
	}

	/**
	 * FS-18: a callback at priority 10 changes the page, then our callback falls
	 * back; the result is that callback's output ($filtered_output), never core's $output.
	 */
	public function test_fs18_fallback_returns_filtered_output() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		$earlier = static function ( $filtered_output ) {
			return str_replace( '<p>Hi</p>', '<p>Changed by an earlier callback</p>', $filtered_output );
		};
		$expected = str_replace( '<p>Hi</p>', '<p>Changed by an earlier callback</p>', self::PAGE );
		add_filter( Front_End::PAGE_FILTER, $earlier, 10 );

		// (a) A fix throws inside the engine.
		$add_throwing_fix = static function ( $fixes ) {
			$fixes[] = new Softglaze_A11y_Test_Fix_Throws( 'runtime' );
			return $fixes;
		};
		add_filter( 'softglaze_a11y_register_fixes', $add_throwing_fix );
		self::set_states( 'live' );
		$this->assertSame( 'live', Plugin::instance()->settings()->fix_states()['test-throws'] );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertArrayHasKey( 'page', $front->layers() );
		$this->assertSame( $expected, self::finalize_buffer( self::PAGE ) );
		$this->assertSame( array( 'exception' ), $front->pending_fallbacks() );
		remove_filter( 'softglaze_a11y_register_fixes', $add_throwing_fix );
		remove_filter( Front_End::PAGE_FILTER, array( $front, 'filter_page' ), Front_End::PAGE_PRIORITY );

		// (b) The wiring itself throws.
		self::set_states( 'live' );
		$this->go_to( get_permalink( $this->post_id ) );
		$plugin                 = Plugin::instance();
		$broken                 = new Softglaze_A11y_Test_Front_End( $plugin->gate(), $plugin->registry(), $plugin->settings() );
		$broken->throw_in_engine = true;
		$broken->on_template_redirect();
		$this->assertArrayHasKey( 'page', $broken->layers() );
		$this->assertSame( $expected, self::finalize_buffer( self::PAGE ) );
		$this->assertSame( array( 'exception' ), $broken->pending_fallbacks() );

		remove_filter( Front_End::PAGE_FILTER, $earlier, 10 );
	}

	/**
	 * FS-15: five identical fallbacks within an hour give one record (throttle transient, one action).
	 */
	public function test_fs15_fallbacks_are_throttled() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		add_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		$events = array();
		add_action(
			'softglaze_a11y_engine_fallback',
			static function ( $reason, $url_hash ) use ( &$events ) {
				$events[] = array( $reason, $url_hash );
			},
			10,
			2
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$front = $this->visit( get_permalink( $this->post_id ) );
			$this->assertStringNotContainsString( 'title="', apply_filters( 'the_content', self::IFRAME ), "request $i served unrepaired" );
			$this->assertSame( array( 'time' ), $front->pending_fallbacks(), "request $i" );
			$front->record_fallbacks();
			$this->assertSame( array(), $front->pending_fallbacks() );
		}

		$this->assertCount( 1, $events );
		$this->assertSame( 'time', $events[0][0] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $events[0][1] );
		$this->assertNotFalse( get_transient( 'softglaze_a11y_fallback_time' ) );

		// A different reason is recorded on its own.
		remove_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		$limit = static function () {
			return 10;
		};
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$front = $this->visit( get_permalink( $this->post_id ) );
		apply_filters( 'the_content', self::IFRAME );
		$front->record_fallbacks();
		$this->assertCount( 2, $events );
		$this->assertSame( 'size', $events[1][0] );
	}

	/**
	 * FS-16: with every fix Off or Preview, visitor mode registers nothing, so
	 * the output is what WordPress produces without the plugin.
	 */
	public function test_fs16_off_and_preview_register_nothing_for_visitors() {
		foreach ( array( 'off', 'preview' ) as $state ) {
			self::set_states( $state );
			$front = $this->visit( get_permalink( $this->post_id ) );
			$this->assertTrue( Plugin::instance()->gate()->passes(), $state );
			$this->assertSame( array(), $front->layers(), $state );
			$this->assertSame( array(), self::registered_hooks( $front ), $state );
			$this->assertStringNotContainsString( 'title="', apply_filters( 'the_content', self::IFRAME ), $state );
			$this->assertFalse( $front->no_cache_sent(), $state );
		}
	}

	/**
	 * Every callback returns non-string input untouched.
	 */
	public function test_callbacks_ignore_non_string_input() {
		self::set_states( 'live' );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertNull( $front->filter_content( null ) );
		$this->assertSame( '', $front->filter_content( '' ) );
		$this->assertSame( array( 1 ), $front->filter_page( array( 1 ), '' ) );
		$this->assertNull( $front->filter_more_link( null ) );
		$this->assertSame( array( 1 ), $front->filter_excerpt_more( array( 1 ) ) );
	}

	/**
	 * Layer A for F19: the more link and a theme's excerpt-more link get the
	 * current post's title; only while F19 applies, and never for a refused request.
	 */
	public function test_layer_a_more_links() {
		self::set_states(
			array(
				'read-more-context' => 'live',
				'iframe-title'      => 'off',
				'html-lang'         => 'off',
				'viewport-zoom'     => 'off',
			),
			array( 'page_scope' => false )
		);
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Tom & Jerry’s cake',
				'post_content' => 'Intro <!--more--> rest',
				'post_status'  => 'publish',
			)
		);
		self::set_states( array( 'read-more-context' => 'preview' ), array( 'page_scope' => false ) );
		$front = $this->visit( get_permalink( $post_id ) );
		$this->assertFalse( has_filter( 'the_content_more_link', array( $front, 'filter_more_link' ) ), 'Preview does not apply for visitors.' );

		self::set_states( array( 'read-more-context' => 'live' ), array( 'page_scope' => false ) );
		$front = $this->visit( get_permalink( $post_id ) );
		$this->assertSame( Front_End::MORE_LINK_PRIORITY, has_filter( 'the_content_more_link', array( $front, 'filter_more_link' ) ) );
		$this->assertSame( Front_End::MORE_LINK_PRIORITY, has_filter( 'excerpt_more', array( $front, 'filter_excerpt_more' ) ) );

		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test: the loop's current post.
		$title           = html_entity_decode( get_the_title(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$link            = ' <a href="' . get_permalink( $post_id ) . '#more-' . $post_id . '" class="more-link">(more&hellip;)</a>';
		$this->assertSame( "(more\u{2026}): " . $title, self::attribute_of( $front->filter_more_link( $link, '(more&hellip;)' ), 'aria-label' ) );
		$this->assertSame( ' [&hellip;]', $front->filter_excerpt_more( ' [&hellip;]' ) );
		$this->assertSame( 'Continue reading: ' . $title, self::attribute_of( $front->filter_excerpt_more( '&hellip; <a href="/p/">Continue reading</a>' ), 'aria-label' ) );
	}

	/**
	 * F21's note on wp_footer: printed once, escaped; with the page pass always
	 * (it sees header and footer links later), otherwise only after a content
	 * run used it, and when skipped later content runs stop referencing it.
	 */
	public function test_new_tab_note_in_footer() {
		$link = '<p><a href="https://partner.example.org/" target="_blank">Partner</a></p>';
		$note = '<span id="softglaze-a11y-newtab-note" hidden>opens in a new tab</span>';

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertFalse( has_action( 'wp_footer', array( $front, 'print_new_tab_note' ) ), 'F21 starts Off.' );

		self::set_states( array( 'new-tab-note' => 'live' ), array( 'page_scope' => false ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( Front_End::FOOTER_PRIORITY, has_action( 'wp_footer', array( $front, 'print_new_tab_note' ) ) );
		$this->assertSame( '', get_echo( array( $front, 'print_new_tab_note' ) ), 'No content run used the note yet.' );
		$this->assertStringNotContainsString( 'aria-describedby', apply_filters( 'the_content', $link ), 'After wp_footer, no reference to a note that is not printed.' );

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertStringContainsString( 'aria-describedby="softglaze-a11y-newtab-note"', apply_filters( 'the_content', $link ) );
		$this->assertSame( $note, get_echo( array( $front, 'print_new_tab_note' ) ) );
		$this->assertSame( '', get_echo( array( $front, 'print_new_tab_note' ) ), 'Printed once.' );

		if ( self::has_page_pass() ) {
			self::set_states( array( 'new-tab-note' => 'live' ), array( 'page_scope' => true ) );
			$front = $this->visit( get_permalink( $this->post_id ) );
			$this->assertArrayHasKey( 'page', $front->layers() );
			$this->assertSame( $note, get_echo( array( $front, 'print_new_tab_note' ) ), 'With the page pass the note is printed before the links are seen.' );
		}
	}

	/**
	 * F11's printer is hooked on wp_body_open only when F11 applies and the
	 * page pass will settle the link; it prints one escaped link, enqueues
	 * its stylesheet, and prints once.
	 */
	public function test_skip_link_printer() {
		$url  = get_permalink( $this->post_id );
		$link = '<a class="softglaze-a11y-skip-link screen-reader-text" href="#softglaze-a11y-main">Skip to content</a>';

		self::set_states( array( 'skip-link' => 'preview' ), array( 'page_scope' => true ) );
		$front = $this->visit( $url );
		$this->assertFalse( has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ), 'Preview does not apply for visitors.' );

		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => false ) );
		$front = $this->visit( $url );
		$this->assertFalse( has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ), 'Without the page pass nothing would settle the link.' );

		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front = $this->visit( $url );
		if ( ! self::has_page_pass() ) {
			$this->assertFalse( has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ), 'F11 needs WordPress 6.9.' );
			$this->assertSame( 'off', $front->states()['skip-link'] );
			return;
		}
		$this->assertSame( Front_End::SKIP_LINK_PRIORITY, has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ) );
		$this->assertFalse( wp_style_is( Fix_Skip_Link::STYLE_HANDLE, 'enqueued' ), 'Nothing enqueued before the link is printed.' );
		$this->assertSame( $link, get_echo( array( $front, 'print_skip_link' ) ) );
		$this->assertTrue( wp_style_is( Fix_Skip_Link::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringEndsWith( '/tagmend-accessibility-fixes/assets/css/skip-link.css', wp_styles()->registered[ Fix_Skip_Link::STYLE_HANDLE ]->src );
		$this->assertSame( '', get_echo( array( $front, 'print_skip_link' ) ), 'Printed once, even when wp_body_open fires twice.' );
		$this->assertSame( Fix_Skip_Link::PRINTER_PRINTED, Plugin::instance()->registry()->get( 'skip-link' )->printer() );
		wp_dequeue_style( Fix_Skip_Link::STYLE_HANDLE );
	}

	/**
	 * F11 prints nothing while core's block-theme skip link is active.
	 */
	public function test_skip_link_stands_back_for_core() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'F11 needs WordPress 6.9.' );
		}
		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertFalse( Fix_Skip_Link::core_skip_link_active(), 'The test theme is a classic theme.' );

		add_theme_support( 'block-templates' );
		$GLOBALS['_wp_current_template_content'] = '<!-- wp:post-content /-->';
		wp_enqueue_style( Fix_Skip_Link::CORE_STYLE );
		try {
			$this->assertTrue( Fix_Skip_Link::core_skip_link_active() );
			$this->assertSame( '', get_echo( array( $front, 'print_skip_link' ) ) );
			$this->assertSame( Fix_Skip_Link::PRINTER_CORE, Plugin::instance()->registry()->get( 'skip-link' )->printer() );
			$this->assertFalse( wp_style_is( Fix_Skip_Link::STYLE_HANDLE, 'enqueued' ) );
		} finally {
			wp_dequeue_style( Fix_Skip_Link::CORE_STYLE );
			remove_theme_support( 'block-templates' );
			unset( $GLOBALS['_wp_current_template_content'] );
		}
	}

	/**
	 * When the page pass runs and wp_body_open never fired, F11 knows the theme lacks the hook.
	 */
	public function test_skip_link_without_wp_body_open() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'F11 needs WordPress 6.9.' );
		}
		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( 0, did_action( 'wp_body_open' ) );
		$front->filter_page( self::PAGE, self::PAGE );
		$this->assertSame( Fix_Skip_Link::PRINTER_NO_HOOK, Plugin::instance()->registry()->get( 'skip-link' )->printer() );

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( Fix_Skip_Link::PRINTER_UNKNOWN, Plugin::instance()->registry()->get( 'skip-link' )->printer(), 'A new request starts unknown.' );
	}

	/**
	 * The printed link text is escaped as it is built.
	 */
	public function test_skip_link_text_is_escaped() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'F11 needs WordPress 6.9.' );
		}
		$hostile = static function ( $translation, $text, $domain ) {
			return 'Skip to content' === $text && 'tagmend-accessibility-fixes' === $domain ? '"><script>alert(1)</script> & more' : $translation;
		};
		add_filter( 'gettext', $hostile, 10, 3 );
		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front  = $this->visit( get_permalink( $this->post_id ) );
		$output = get_echo( array( $front, 'print_skip_link' ) );
		remove_filter( 'gettext', $hostile, 10 );
		wp_dequeue_style( Fix_Skip_Link::STYLE_HANDLE );

		$this->assertStringNotContainsString( '<script', $output );
		$this->assertSame( 1, substr_count( $output, '<' ) - substr_count( $output, '</' ) );
		$this->assertStringContainsString( '>&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; &amp; more</a>', $output );
	}

	/**
	 * When the page pass falls back, the skip link F11 printed would point at
	 * an id nobody added: only that link gets hidden="", the rest of the page
	 * stays byte for byte; with the link missing, twice, or not printed by us,
	 * the page is returned as it came.
	 */
	public function test_printed_skip_link_hidden_when_page_pass_falls_back() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'F11 needs WordPress 6.9.' );
		}
		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$link  = get_echo( array( $front, 'print_skip_link' ) );
		wp_dequeue_style( Fix_Skip_Link::STYLE_HANDLE );
		$this->assertStringStartsWith( Fix_Skip_Link::printed_opener(), $link, 'The printer and printed_opener() agree.' );
		$page = str_replace( '<body>', '<body>' . $link, self::PAGE );

		$limit = static function () {
			return 10;
		};
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$output = $front->filter_page( $page, $page );
		$this->assertSame( array( 'size' ), $front->pending_fallbacks() );
		$this->assertSame( 1, substr_count( $output, 'hidden=""' ) );
		$this->assertMatchesRegularExpression( '#<a (hidden="" )?class="softglaze-a11y-skip-link screen-reader-text" href="\#softglaze-a11y-main"( hidden="")?>Skip to content</a>#', $output );
		$this->assertSame( $page, str_replace( ' hidden=""', '', $output ), 'Nothing else changed.' );

		$twice = str_replace( '<body>', '<body>' . $link, $page );
		$this->assertSame( $twice, $front->filter_page( $twice, $twice ), 'Two copies: not ours to choose.' );
		$this->assertSame( self::PAGE, $front->filter_page( self::PAGE, self::PAGE ), 'No link: nothing to hide.' );
		remove_filter( 'softglaze_a11y_max_bytes', $limit );

		$with_main = str_replace( '<p>Hi</p>', '<main><p>Hi</p></main>', $page );
		$repaired  = $front->filter_page( $with_main, $with_main );
		$this->assertStringNotContainsString( 'hidden=""', $repaired, 'Without a fallback F11 settles the link itself.' );
		$this->assertStringContainsString( '<main id="softglaze-a11y-main">', $repaired );

		Plugin::instance()->registry()->get( 'skip-link' )->set_printer( Fix_Skip_Link::PRINTER_CORE );
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$this->assertSame( $page, $front->filter_page( $page, $page ), 'A link we did not print is left alone.' );
		remove_filter( 'softglaze_a11y_max_bytes', $limit );
	}

	/**
	 * F22: the fixed stylesheet is enqueued only while the fix applies; it is not a layer.
	 */
	public function test_focus_visible_stylesheet() {
		$url   = get_permalink( $this->post_id );
		$front = $this->visit( $url );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $front, 'enqueue_focus_visible' ) ), 'F22 starts Off.' );

		self::set_states( array( 'focus-visible' => 'live' ) );
		$front = $this->visit( $url );
		$this->assertSame( Front_End::STYLES_PRIORITY, has_action( 'wp_enqueue_scripts', array( $front, 'enqueue_focus_visible' ) ) );
		$this->assertSame( array(), $front->layers(), 'No markup layer for a stylesheet.' );
		$front->enqueue_focus_visible();
		$this->assertTrue( wp_style_is( Fix_Focus_Visible::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringEndsWith( '/tagmend-accessibility-fixes/assets/css/focus-visible.css', wp_styles()->registered[ Fix_Focus_Visible::STYLE_HANDLE ]->src );
		wp_dequeue_style( Fix_Focus_Visible::STYLE_HANDLE );
	}

	/**
	 * F18 through core's wp_nav_menu(): a nav container without a label gets
	 * the location's description; labelled navs and div containers are left alone.
	 */
	public function test_nav_labels_through_wp_nav_menu() {
		register_nav_menus( array( 'footer' => 'Footer Menu' ) );
		$menu_id = wp_create_nav_menu( 'Bakery links' );
		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'  => 'Visit',
				'menu-item-url'    => 'https://example.org/visit/',
				'menu-item-status' => 'publish',
			)
		);
		set_theme_mod( 'nav_menu_locations', array( 'footer' => $menu_id ) );
		$args = array(
			'theme_location' => 'footer',
			'container'      => 'nav',
			'echo'           => false,
		);

		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertFalse( has_filter( 'wp_nav_menu_args', array( $front, 'filter_nav_menu_args' ) ), 'Preview does not apply for visitors.' );
		$this->assertStringContainsString( '<nav class="menu-bakery-links-container">', wp_nav_menu( $args ) );

		self::set_states( array( 'nav-labels' => 'live' ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$this->assertSame( Front_End::NAV_MENU_PRIORITY, has_filter( 'wp_nav_menu_args', array( $front, 'filter_nav_menu_args' ) ) );
		$this->assertArrayHasKey( 'attribute', $front->layers() );
		$this->assertStringContainsString( '<nav class="menu-bakery-links-container" aria-label="Footer Menu">', wp_nav_menu( $args ) );
		$this->assertStringContainsString( '<nav class="menu-bakery-links-container" aria-label="Main">', wp_nav_menu( array_merge( $args, array( 'container_aria_label' => 'Main' ) ) ) );
		$this->assertStringNotContainsString( 'aria-label', wp_nav_menu( array_merge( $args, array( 'container' => 'div' ) ) ) );
		$this->assertSame( 'not args', $front->filter_nav_menu_args( 'not args' ) );
		unregister_nav_menu( 'footer' );
	}

	/**
	 * Phase 5: the buffer check is hooked right after core's buffer start, and
	 * leaves Layer C in place when core started its buffer.
	 */
	public function test_page_pass_kept_when_core_started_its_buffer() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		self::set_states( 'live' );
		$front = $this->visit( get_permalink( $this->post_id ) );

		$this->assertSame( 1000, has_action( Front_End::BUFFER_CHECK_HOOK, 'wp_start_template_enhancement_output_buffer' ), 'Core starts its buffer here.' );
		$this->assertSame( Front_End::BUFFER_CHECK_PRIORITY, has_action( Front_End::BUFFER_CHECK_HOOK, array( $front, 'confirm_page_pass' ) ) );

		do_action( Front_End::BUFFER_STARTED_ACTION );
		$front->confirm_page_pass();

		$this->assertSame(
			array(
				'attribute' => true,
				'page'      => true,
			),
			$front->layers()
		);
		$this->assertFalse( $front->page_pass_vetoed() );
		$this->assertSame( Front_End::PAGE_PRIORITY, has_filter( Front_End::PAGE_FILTER, array( $front, 'filter_page' ) ) );
		$this->assertFalse( has_filter( 'the_content', array( $front, 'filter_content' ) ) );
		$this->assertSame( Front_End::SKIP_LINK_PRIORITY, has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ) );
	}

	/**
	 * Phase 5: a plugin that stops core from starting its template buffer
	 * (Elementor 4.3.5 returns false from
	 * wp_should_output_buffer_template_for_enhancement on every front-end
	 * request) leaves the request on Layer B: content is still repaired,
	 * page-only fixes do not run and our skip link is not printed. Core's own
	 * start function runs here, so the veto is the real one.
	 */
	public function test_page_pass_vetoed_falls_back_to_content_filters() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}
		self::set_states( 'live' );
		add_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );
		$front = $this->visit( get_permalink( $this->post_id ) );
		$level = ob_get_level();

		do_action( Front_End::BUFFER_CHECK_HOOK, ABSPATH . 'wp-includes/template-canvas.php' );

		$this->assertSame( $level, ob_get_level(), 'Core started no buffer.' );
		$this->assertSame( 0, did_action( Front_End::BUFFER_STARTED_ACTION ) );
		$this->assertTrue( $front->page_pass_vetoed() );
		$this->assertSame(
			array(
				'attribute' => true,
				'content'   => true,
			),
			$front->layers()
		);
		$this->assertFalse( has_filter( Front_End::PAGE_FILTER, array( $front, 'filter_page' ) ) );
		foreach ( Front_End::CONTENT_FILTERS as $hook => $priority ) {
			$this->assertSame( $priority, has_filter( $hook, array( $front, 'filter_content' ) ), $hook );
		}
		$this->assertFalse( has_action( 'wp_body_open', array( $front, 'print_skip_link' ) ), 'Nothing would settle our skip link.' );
		$this->assertStringContainsString( '<iframe title="YouTube video" src="https://www.youtube.com/embed/abc">', apply_filters( 'the_content', self::IFRAME ) );

		$front->confirm_page_pass();
		$this->assertSame( 999, has_filter( 'the_content', array( $front, 'filter_content' ) ), 'A second check changes nothing.' );
		remove_filter( 'wp_should_output_buffer_template_for_enhancement', '__return_false', 1 );
	}

	/**
	 * Phase 5: without Layer C (6.5-6.8, or the page pass switched off) the check is not hooked and does nothing.
	 */
	public function test_buffer_check_only_with_layer_c() {
		self::set_states( 'live', array( 'page_scope' => false ) );
		$front = $this->visit( get_permalink( $this->post_id ) );

		$this->assertFalse( has_action( Front_End::BUFFER_CHECK_HOOK, array( $front, 'confirm_page_pass' ) ) );
		$before = $front->layers();
		$front->confirm_page_pass();
		$this->assertSame( $before, $front->layers() );
		$this->assertFalse( $front->page_pass_vetoed() );
	}

	/**
	 * The value of an attribute on the first tag of a fragment.
	 *
	 * @param string $html      Fragment.
	 * @param string $attribute Attribute name.
	 * @return string|null
	 */
	private static function attribute_of( $html, $attribute ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag( 'a' );
		$value = $processor->get_attribute( $attribute );
		return is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
	}
}
