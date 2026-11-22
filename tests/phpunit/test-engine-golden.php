<?php
/**
 * Golden-file tests: the engine output for every captured fixture equals the
 * reviewed file in tests/fixtures/expected/, only the intended attributes
 * change, both passes count the same openers, and scan mode returns the input.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Golden-file tests.
 */
class Test_Engine_Golden extends Softglaze_A11y_Engine_TestCase {

	/**
	 * The attribute changes each fixture is expected to receive with every
	 * bundled fix Live: F01, F02, F03 and F09 as in Phase 2; F05 names the
	 * three icon-only links and F07 then hides their icons (that edit depends
	 * on F05's aria-label); F19 adds the article titles to the two "Read more"
	 * links; F06 names the search button and the empty submit input; F08
	 * names the placeholder-only and unnamed fields (the unlabelled select
	 * has no label source and is left for R05); F10 and F13 on the structure
	 * page. F21's new-tab link is not described here because the captured
	 * page has no note element (Front_End prints it on wp_footer). Phase 4A:
	 * F14 resets tabindex="3" on the block-editor page; F12 points both broken
	 * skip links of the structure page at the main element's id. F20 leaves
	 * the block-editor page's empty h2 alone: it sits between the h1 and three
	 * h3 headings, so hiding it would make them skip a level (R02 lists it).
	 * F11 changes nothing here: Twenty Twenty-Five's core skip link is the
	 * first focusable element (and our link is not printed into captured pages).
	 * Phase 4B: F15 takes the block-editor card's aria-hidden image link out
	 * of the tab order (its visible title link goes to the same address);
	 * F17 gives the second "Call me back" form's phone field and its label
	 * their own id. F16 finds no focusable element inside an aria-hidden
	 * container on these pages.
	 * Phase 5 (captured with WooCommerce 11.2.1 and Elementor 4.3.5 active, so
	 * the Twenty Twenty-Five header holds WooCommerce's Customer Account link
	 * and Mini-Cart button): F07 hides their unlabelled SVG icons (and, on the
	 * product page, the one in the store notice's "Dismiss this notice"
	 * button); on the Elementor page F05 names four icon-only Icon and Icon
	 * Box links (tel:, wa.me, the WooCommerce cart page, mailto:) and F08 the
	 * newsletter field Contact Form 7 left without an id the second time the
	 * form is placed (CF7's default name your-email). The Opening hours icon
	 * link and the lightbox image link have no label source (R05).
	 *
	 * @var array
	 */
	const EXPECTED_DIFFS = array(
		'a11y-fx-block-editor' => array(
			"IMG.alt: NULL -> 'Butter croissant on a wooden board'",
			"IMG.alt: NULL -> 'Three baguettes cooling on a rack'",
			"IMG.alt: NULL -> ''",
			"IMG.alt: '' -> 'Three-layer chocolate cake'",
			"A.aria-label: NULL -> 'Facebook'",
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"A.aria-label: NULL -> 'Instagram'",
			"I.aria-hidden: NULL -> 'true'",
			"A.aria-label: NULL -> 'Email hello@example.com'",
			"SPAN.aria-hidden: NULL -> 'true'",
			"A.tabindex: '3' -> '0'",
			"IFRAME.title: NULL -> 'YouTube video'",
			"A.aria-label: NULL -> 'Read more: Autumn menu'",
			"A.aria-label: NULL -> 'Read more: Christmas orders'",
			"A.tabindex: NULL -> '-1'",
		),
		'a11y-fx-forms'        => array(
			"INPUT.aria-label: NULL -> 'Your name'",
			"INPUT.aria-label: NULL -> 'Email address'",
			"INPUT.aria-label: NULL -> 'Phone'",
			"TEXTAREA.aria-label: NULL -> 'Your message'",
			"INPUT.value: '' -> 'Submit'",
			"INPUT.aria-label: NULL -> 'Search the menu'",
			"BUTTON.aria-label: NULL -> 'Search'",
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"LABEL.for: 'callback-phone' -> 'callback-phone-softglaze-a11y-2'",
			"INPUT.id: 'callback-phone' -> 'callback-phone-softglaze-a11y-2'",
			"INPUT.aria-label: NULL -> 'Your name'",
			"INPUT.aria-label: NULL -> 'Email address'",
			"TEXTAREA.aria-label: NULL -> 'Tell us about the occasion'",
		),
		'a11y-fx-structure'    => array(
			"HTML.lang: NULL -> 'en-US'",
			"META.content: 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' -> 'width=device-width, initial-scale=1'",
			"A.href: '#bakery-missing-target' -> '#wp--skip-link--target'",
			"A.href: '#bakery-main-content' -> '#wp--skip-link--target'",
		),
		'home'                 => array(),
		'a11y-fx-elementor'    => array(
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"A.aria-label: NULL -> 'Call +441234567890'",
			"A.aria-label: NULL -> 'WhatsApp'",
			"A.aria-label: NULL -> 'Cart'",
			"A.aria-label: NULL -> 'Email orders@example.com'",
			"INPUT.aria-label: NULL -> 'Email'",
		),
		'a11y-fx-product'      => array(
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
			"SVG.aria-hidden: NULL -> 'true'",
			"SVG.focusable: NULL -> 'false'",
		),
	);

	/**
	 * Changes a scan lists beyond the visitor-mode diff: in a scan F21 always
	 * shows what it would change, note element or not.
	 *
	 * @var array
	 */
	const SCAN_EXTRA = array(
		'a11y-fx-block-editor' => 1,
	);

	/**
	 * The Phase 5 pages were captured with WooCommerce active: its cart and
	 * account pages are answered as on the dev site (stand-ins when
	 * WooCommerce is not loaded, as in these tests).
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			require_once __DIR__ . '/helpers/woocommerce-url-stubs.php';
		}
		$GLOBALS['softglaze_a11y_test_wc_urls'] = array(
			'cart'      => self::DEV_HOME . '/cart/',
			'myaccount' => self::DEV_HOME . '/my-account/',
		);
	}

	/**
	 * Forgets the shop pages.
	 */
	public function tear_down() {
		unset( $GLOBALS['softglaze_a11y_test_wc_urls'] );
		parent::tear_down();
	}

	/**
	 * Every fixture has an expectation file and an entry in the diff table.
	 */
	public function test_every_fixture_has_an_expectation() {
		$this->assertNotEmpty( self::fixture_files() );
		foreach ( self::fixture_files() as $slug => $path ) {
			$this->assertFileExists( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/expected/' . $slug . '.html' );
			$this->assertArrayHasKey( $slug, self::EXPECTED_DIFFS );
		}
	}

	/**
	 * Output equals the reviewed expectation byte for byte.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_output_matches_expected_file( $slug, $path ) {
		$html     = self::read( $path );
		$expected = self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/expected/' . $slug . '.html' );
		$engine   = self::engine( 'live' );
		$output   = $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_PAGE );

		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( $expected, $output );
	}

	/**
	 * Only the intended attributes of the intended elements change; every other token is identical.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_only_intended_attributes_change( $slug, $path ) {
		$html   = self::read( $path );
		$output = self::engine( 'live' )->run( $html );
		$this->assertSame( self::EXPECTED_DIFFS[ $slug ], self::token_diff( $html, $output ) );
	}

	/**
	 * Unchanged fixtures come back as the very same string; changed ones keep their < and > counts.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_bytes_and_angle_brackets( $slug, $path ) {
		$html   = self::read( $path );
		$output = self::engine( 'live' )->run( $html );
		if ( empty( self::EXPECTED_DIFFS[ $slug ] ) ) {
			$this->assertSame( $html, $output );
		}
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
	}

	/**
	 * Both passes count the same openers on every fixture (a test fix forces the apply pass).
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_opener_counts_equal_in_both_passes( $slug, $path ) {
		$html   = self::read( $path );
		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Title_All( true, 'tagmend-test' ) ) );
		$engine->run( $html );
		$facts = $engine->recorder()->engine();

		$this->assertNull( $engine->recorder()->get_fallback(), $slug );
		$this->assertGreaterThan( 50, $facts['openers'], $slug );
		$this->assertSame( $facts['openers'], $facts['openers_apply'], $slug );
		$this->assertSame( self::opener_count( $html ), $facts['openers'], $slug );
	}

	/**
	 * Scan mode analyses every fix, applies none and returns the input.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_scan_mode_returns_input_and_lists_changes( $slug, $path ) {
		$html   = self::read( $path );
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN ) );

		$changes = $engine->recorder()->changes();
		$this->assertCount( count( self::EXPECTED_DIFFS[ $slug ] ) + ( isset( self::SCAN_EXTRA[ $slug ] ) ? self::SCAN_EXTRA[ $slug ] : 0 ), $changes );
		foreach ( $changes as $change ) {
			$this->assertFalse( $change['applied'] );
			$this->assertSame( 'off', $change['state'] );
		}
	}

	/**
	 * The CLI analysis of the block-editor fixture: three images from the
	 * library, the image in the named link emptied, the decorative cake image
	 * (F02), the new-tab note a scan always shows, the three icon-only links
	 * and their icons, tabindex="3" (F14), the untitled iframe, the two "Read
	 * more" links, the card's hidden duplicate image link (F15); one finding: F20's R02 for the empty h2 it leaves in place
	 * (the remaining links and fields are named, the core skip link comes
	 * first, the landmarks are in order).
	 */
	public function test_block_editor_change_list() {
		$engine = self::engine( 'preview' );
		$engine->run( self::read( self::fixture_files()['a11y-fx-block-editor'] ), Engine::MODE_SCAN );
		$this->assertSame(
			array(
				array( 'img-alt-library', 'img.wp-image-6[src=/wp-content/uploads/2026/10/a11y-fx-croissant.jpg]', 'alt', null, 'Butter croissant on a wooden board' ),
				array( 'img-alt-library', 'img.wp-image-7[src=/wp-content/uploads/2026/10/a11y-fx-baguette.jpg]', 'alt', null, 'Three baguettes cooling on a rack' ),
				array( 'img-in-named-control', 'img.wp-image-8[src=/wp-content/uploads/2026/10/a11y-fx-sourdough.jpg]', 'alt', null, '' ),
				array( 'img-alt-empty-library', 'img.wp-image-9[src=/wp-content/uploads/2026/10/a11y-fx-cake.png]', 'alt', '', 'Three-layer chocolate cake' ),
				array( 'new-tab-note', 'a[href=/reviews/]', 'aria-describedby', null, 'softglaze-a11y-newtab-note' ),
				array( 'link-name', 'a[href=/examplebakery]', 'aria-label', null, 'Facebook' ),
				array( 'decorative-icons', 'svg', 'aria-hidden', null, 'true' ),
				array( 'decorative-icons', 'svg', 'focusable', null, 'false' ),
				array( 'link-name', 'a[href=/examplebakery]', 'aria-label', null, 'Instagram' ),
				array( 'decorative-icons', 'i.fa.fa-instagram', 'aria-hidden', null, 'true' ),
				array( 'link-name', 'a[href=hello@example.com]', 'aria-label', null, 'Email hello@example.com' ),
				array( 'decorative-icons', 'span.dashicons.dashicons-email', 'aria-hidden', null, 'true' ),
				array( 'positive-tabindex', 'a[href=/order/]', 'tabindex', '3', '0' ),
				array( 'iframe-title', 'iframe[src=/embed/a11yfxTour]', 'title', null, 'YouTube video' ),
				array( 'read-more-context', 'a[href=/autumn-menu/]', 'aria-label', null, 'Read more: Autumn menu' ),
				array( 'read-more-context', 'a[href=/christmas-orders/]', 'aria-label', null, 'Read more: Christmas orders' ),
				array( 'hidden-duplicate-links', 'a[href=/menu/cinnamon-rolls/]', 'tabindex', null, '-1' ),
			),
			array_map(
				static function ( $change ) {
					return array( $change['fix'], $change['sig'], $change['attr'], $change['before'], $change['after'] );
				},
				$engine->recorder()->changes()
			)
		);
		foreach ( $engine->recorder()->changes() as $change ) {
			$this->assertSame( 'preview', $change['state'] );
			$this->assertFalse( $change['applied'] );
		}
		$this->assertSame(
			array(
				array(
					'check'  => 'R02',
					'sc'     => '1.3.1',
					'sig'    => 'h2.wp-block-heading',
					'detail' => 'Empty h2 heading left in place: hiding it would make the next heading, h3, skip a level. Add text to it or remove it.',
				),
			),
			$engine->recorder()->findings()
		);
	}

	/**
	 * Acceptance: no image that had alt="" gains text while F02 is Off; with
	 * every other fix Live the block-editor page differs from the golden file
	 * only by the F02 line.
	 */
	public function test_empty_alt_kept_while_f02_is_off() {
		$html   = self::read( self::fixture_files()['a11y-fx-block-editor'] );
		$states = array_fill_keys( array_map( static function ( $fix ) {
			return $fix->id();
		}, self::bundled_fixes() ), 'live' );
		$states['img-alt-empty-library'] = 'off';
		$engine = new Engine( self::bundled_fixes(), $states, array( 'attachments' => new Softglaze_A11y_Test_Attachment_Lookup() ) );
		$output = $engine->run( $html );

		$expected = array_values( array_diff( self::EXPECTED_DIFFS['a11y-fx-block-editor'], array( "IMG.alt: '' -> 'Three-layer chocolate cake'" ) ) );
		$this->assertSame( $expected, self::token_diff( $html, $output ) );
		$this->assertStringContainsString( 'alt="" class="wp-image-9"', $output );
		$this->assertStringContainsString( 'alt="" class="wp-image-10"', $output );
	}

	/**
	 * Content scope: a fragment gets F09 only; the page-scope fixes do not run.
	 */
	public function test_content_scope_runs_content_fixes_only() {
		$fragment = '<html><meta name="viewport" content="user-scalable=no"><p>Tour</p><iframe src="https://calendly.com/bakery"></iframe>';
		$engine   = self::engine( 'live' );
		$output   = $engine->run( $fragment, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( '<html><meta name="viewport" content="user-scalable=no"><p>Tour</p><iframe title="Calendly booking" src="https://calendly.com/bakery"></iframe>', $output );
		$this->assertSame( 'content', $engine->recorder()->engine()['scope'] );
	}
}
