<?php
/**
 * Phase 8B: regression tests for the findings of the three independent
 * reviews that are not covered in their own suites (docs/REVIEW-FINDINGS-8B.md):
 * C5 mbstring, C6 plain text, C7 schema version, C8 Layer A and throwables,
 * C9 builder frames, C10 our skip link after a rewrite, S4 named submit
 * buttons, S7 tables of a deleted site.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Button_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Read_More_Context;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;

require_once __DIR__ . '/helpers/class-report-test-case.php';

/**
 * A REST server that says a dispatch is running (an internal rest_do_request() during a page).
 */
class Softglaze_A11y_Test_Dispatching_Server extends WP_REST_Server {

	/**
	 * Always dispatching.
	 *
	 * @return bool
	 */
	public function is_dispatching() {
		return true;
	}
}

/**
 * Review 8B regression tests.
 */
class Test_Review_8B extends Softglaze_A11y_Report_TestCase {

	/**
	 * A published post.
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
	 * Restores the REST server.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * C5: no mbstring function other than the two WordPress polyfills is
	 * called without a guard, and the helpers that replace them compare
	 * multibyte text without mbstring-only calls.
	 */
	public function test_c5_no_unguarded_mbstring_calls() {
		$found = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SOFTGLAZE_A11Y_DIR, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( (array) file( $file->getPathname() ) as $number => $line ) {
				if ( preg_match_all( '/\bmb_([a-z_]+)\s*\(/', (string) $line, $calls ) ) {
					foreach ( $calls[1] as $call ) {
						if ( 'substr' === $call || 'strlen' === $call ) {
							continue;
						}
						$found[] = basename( $file->getPathname() ) . ' mb_' . $call . ( false !== strpos( (string) $line, "function_exists( 'mb_" . $call . "' )" ) ? ' (guarded)' : '' );
					}
				}
			}
		}
		$this->assertSame( array( 'class-fix-base.php mb_strtolower (guarded)' ), $found );

		$this->assertSame( 'école', Fix_Base::lower( 'ÉCOLE' ) );
		$this->assertTrue( Fix_Base::contains_text( 'Öffnet in einem NEUEN Tab', 'neuen tab' ) );
		$this->assertFalse( Fix_Base::contains_text( 'Opens a page', '' ) );
		$this->assertSame( ' Read more', Fix_Base::without_first( 'Read more Read more', 'READ more' ) );
		$this->assertSame( 'a (b) c', Fix_Base::without_first( 'a (b) c', 'x.' ), 'Pattern characters are quoted.' );
		$this->assertSame( 'weiterlesen', Fix_Read_More_Context::normalise( '» WEITERLESEN »' ) );
	}

	/**
	 * C6: tags are stripped before references are resolved, so an encoded
	 * "<" stays text instead of starting a tag that would be stripped.
	 */
	public function test_c6_plain_text_keeps_encoded_angle_brackets() {
		$this->assertSame( 'Kids 3<6 years', Fix_Base::plain_text( 'Kids 3&lt;6 years' ) );
		$this->assertSame( 'Kids 3<6 years > 2', Fix_Base::plain_text( 'Kids 3&amp;lt;6 years &gt; 2' ) );
		$this->assertSame( 'Bold & text', Fix_Base::plain_text( '<b>Bold</b> &amp; text' ) );
		$this->assertSame( '', Fix_Base::plain_text( '<script>alert(1)</script>' ) );
		$this->assertSame( '<script>alert(1)</script>', Fix_Base::plain_text( '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'Plain text; set_attribute() encodes it.' );
	}

	/**
	 * C7: the schema version is saved only when both tables exist after
	 * dbDelta(), so a site without CREATE rights is not marked ready.
	 */
	public function test_c7_schema_version_only_with_both_tables() {
		global $wpdb;
		delete_option( Install::OPTION );
		foreach ( array( Pages_Store::table_name(), Log_Store::table_name() ) as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		add_filter( 'dbdelta_create_queries', '__return_empty_array' );
		Install::create_tables();
		remove_filter( 'dbdelta_create_queries', '__return_empty_array' );
		$this->assertFalse( Install::tables_exist() );
		$this->assertFalse( get_option( Install::OPTION ), 'No version without the tables.' );
		$this->assertFalse( Install::ready() );

		Install::create_tables();
		$this->assertTrue( Install::tables_exist() );
		$this->assertTrue( Install::ready() );
	}

	/**
	 * C8: the attribute hooks change nothing while a REST dispatch runs inside
	 * a page, as the content filters already did.
	 */
	public function test_c8_attribute_hooks_refuse_rest_dispatch() {
		self::set_states(
			array(
				'html-lang'         => 'live',
				'read-more-context' => 'live',
				'nav-labels'        => 'live',
			)
		);
		$front = $this->visit( get_permalink( $this->post_id ) );
		$more  = '<a href="/hello/" class="more-link">Read more</a>';
		$this->assertSame( 'lang="en-GB"', $front->filter_language_attributes( 'lang="en_GB"' ), 'control' );

		$GLOBALS['wp_rest_server'] = new Softglaze_A11y_Test_Dispatching_Server();
		$this->assertTrue( Plugin::instance()->gate()->is_rest() );
		$this->assertSame( 'lang="en_GB"', $front->filter_language_attributes( 'lang="en_GB"' ) );
		$this->assertSame( $more, $front->filter_more_link( $more ) );
		$this->assertSame( $more, $front->filter_excerpt_more( $more ) );
		$args = array(
			'container'      => 'nav',
			'theme_location' => 'primary',
		);
		$this->assertSame( $args, $front->filter_nav_menu_args( $args ) );
		$this->assertSame( array( 'alt' => '' ), $front->filter_attachment_image_attributes( array( 'alt' => '' ) ) );
	}

	/**
	 * C8: a throwable from other code inside our wiring (a filter on our
	 * public hook, an escaping or URL filter) leaves the request untouched.
	 */
	public function test_c8_wiring_catches_throwables() {
		self::set_states( 'live', array( 'page_scope' => true ) );
		$throw = static function () {
			throw new RuntimeException( 'third party' );
		};

		add_filter( 'softglaze_a11y_should_process', $throw );
		$_GET[ 'softglaze_a11y_scan' ] = '1';
		self::reset_plugin();
		$this->go_to( get_permalink( $this->post_id ) );
		$front = Plugin::instance()->front_end();
		$front->on_wp();
		$front->on_template_redirect();
		remove_filter( 'softglaze_a11y_should_process', $throw );
		$_GET = array();
		$this->assertSame( array(), $front->layers(), 'Nothing registered.' );
		$this->assertSame( array(), self::registered_hooks( $front ) );

		$front = $this->visit( get_permalink( $this->post_id ) );
		add_filter( 'esc_html', $throw );
		$printed = get_echo( array( $front, 'print_skip_link' ) );
		remove_filter( 'esc_html', $throw );
		$this->assertSame( '', $printed );
		$this->assertNotSame( Fix_Skip_Link::PRINTER_PRINTED, Plugin::instance()->registry()->get( 'skip-link' )->printer(), 'No printed link is claimed.' );

		add_filter( 'plugins_url', $throw );
		$front->enqueue_focus_visible();
		remove_filter( 'plugins_url', $throw );
		$this->assertFalse( wp_style_is( 'softglaze-a11y-focus-visible', 'enqueued' ) );
	}

	/**
	 * C9: the editable frame of Visual Composer Website Builder is a builder request.
	 */
	public function test_c9_visual_composer_frame_is_refused() {
		$this->visit( add_query_arg( 'vcv-editable', '1', get_permalink( $this->post_id ) ) );
		$this->assertFalse( Plugin::instance()->gate()->passes() );
		$this->assertSame( 'builder', Plugin::instance()->gate()->reason() );
	}

	/**
	 * C10: when an earlier callback on the page filter rewrote our printed
	 * skip link (quotes, attribute order) and the page pass falls back, the
	 * link is still found by its class and hidden.
	 */
	public function test_c10_rewritten_skip_link_hidden_on_fallback() {
		if ( ! self::has_page_pass() ) {
			$this->markTestSkipped( 'F11 needs WordPress 6.9.' );
		}
		self::set_states( array( 'skip-link' => 'live' ), array( 'page_scope' => true ) );
		$front = $this->visit( get_permalink( $this->post_id ) );
		get_echo( array( $front, 'print_skip_link' ) );
		wp_dequeue_style( Fix_Skip_Link::STYLE_HANDLE );
		$rewritten = "<a href='#softglaze-a11y-main' class='softglaze-a11y-skip-link screen-reader-text'>Skip to content</a>";
		$page      = str_replace( '<body>', '<body>' . $rewritten, self::PAGE );

		$limit = static function () {
			return 10;
		};
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$output = $front->filter_page( $page, $page );
		remove_filter( 'softglaze_a11y_max_bytes', $limit );
		$this->assertSame( array( 'size' ), $front->pending_fallbacks() );
		$this->assertSame( str_replace( '<a href=', '<a hidden="" href=', $page ), $output, 'Only our link changed.' );
	}

	/**
	 * S4: an empty submit input with a name gets an aria-label (its value is
	 * sent with the form); without a name it still gets a value.
	 */
	public function test_s4_named_submit_input_keeps_its_value() {
		$fix    = new Fix_Button_Name();
		$engine = new Engine( array( $fix ), array( $fix->id() => 'live' ) );
		$cases  = array(
			'<form method="post"><input type="submit" name="add-to-cart" value=""></form>' => array( 'INPUT.aria-label: NULL -> \'Submit\'' ),
			'<form method="post"><input type="submit" value=""></form>'                    => array( 'INPUT.value: \'\' -> \'Submit\'' ),
			'<form role="search"><input type="search" name="s"><input type="submit" name="go" value=""></form>' => array( 'INPUT.aria-label: NULL -> \'Search\'' ),
			'<form method="post"><input type="submit" name="" value=""></form>'            => array( 'INPUT.value: \'\' -> \'Submit\'' ),
		);
		foreach ( $cases as $html => $expected ) {
			$this->assertSame( $expected, Softglaze_A11y_Test_Token_Diff::diff( $html, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) ), $html );
		}
	}

	/**
	 * S7: deleting a site of a network drops its two tables too.
	 */
	public function test_s7_site_tables_dropped_with_the_site() {
		global $wpdb;
		$this->assertSame( 10, has_filter( 'wpmu_drop_tables', array( Install::class, 'drop_site_tables' ) ) );
		$tables = Install::drop_site_tables( array( 'posts' => $wpdb->get_blog_prefix( 3 ) . 'posts' ), 3 );
		$this->assertSame( $wpdb->get_blog_prefix( 3 ) . 'softglaze_a11y_pages', $tables['softglaze_a11y_pages'] );
		$this->assertSame( $wpdb->get_blog_prefix( 3 ) . 'softglaze_a11y_log', $tables['softglaze_a11y_log'] );
		$this->assertArrayHasKey( 'posts', $tables, 'Core tables stay in the list.' );
		$this->assertSame( 'not an array', Install::drop_site_tables( 'not an array', 3 ) );
	}
}

/**
 * Token diff outside an engine test case (the static helper of Softglaze_A11y_Engine_TestCase).
 */
abstract class Softglaze_A11y_Test_Token_Diff extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Attribute changes between two documents.
	 *
	 * @param string $before Original.
	 * @param string $after  Output.
	 * @return string[]
	 */
	public static function diff( $before, $after ) {
		return self::token_diff( $before, $after );
	}
}
