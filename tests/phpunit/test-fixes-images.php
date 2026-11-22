<?php
/**
 * Images and icons (Phase 2): F01, F02, F03, F07 and the checks R03, R04 on
 * the hand-written edge-case page tests/fixtures/edge/images-icons.html,
 * whose expected output was made on the dev site with the real Media Library
 * (tools/fixtures/make-expected.php) and is compared here with the lookup
 * double holding the same attachments.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Decorative_Icons;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_Alt_Library;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_In_Named_Control;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Image and icon fixes and checks.
 */
class Test_Fixes_Images extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Every attribute change on the edge page with every fix Live, in document order.
	 */
	const EDGE_DIFF = array(
		"IMG.alt: NULL -> 'Butter croissant on a wooden board'",
		"IMG.alt: NULL -> ''",
		"IMG.alt: '' -> 'Three-layer chocolate cake'",
		"A.aria-label: NULL -> 'Example Bakery home page'",
		"IMG.alt: NULL -> 'Three baguettes cooling on a rack'",
		"IMG.alt: NULL -> 'Round sourdough loaf with a floured crust'",
		"IMG.alt: NULL -> 'Cinnamon rolls in a baking tin'",
		"IMG.alt: NULL -> 'Three baguettes cooling on a rack'",
		"IMG.alt: NULL -> ''",
		"IMG.alt: NULL -> ''",
		"IMG.alt: NULL -> 'Butter croissant on a wooden board'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"I.aria-hidden: NULL -> 'true'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'Instagram'",
		"I.aria-hidden: NULL -> 'true'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: 'true' -> 'false'",
		"A.aria-label: NULL -> 'Facebook'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
	);

	/**
	 * The edge page.
	 *
	 * @return string
	 */
	private static function edge() {
		return self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/images-icons.html' );
	}

	/**
	 * Output equals the file made on the dev site, byte for byte.
	 */
	public function test_edge_output_matches_expected_file() {
		$engine = self::engine( 'live' );
		$output = $engine->run( self::edge() );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/images-icons.expected.html' ), $output );
	}

	/**
	 * Only the intended attributes change: library alts (never a file name or
	 * title), alt="" in named controls, aria-hidden on icons in named controls,
	 * and since Phase 3 F05's names for the home, Instagram and Facebook links
	 * with their icons hidden. Left alone: aria-hidden and role="presentation"
	 * images, a data-id that does not match the file, an attachment without
	 * alt, other hosts, SVGs with a title or role="img", text in icon-classed spans.
	 */
	public function test_edge_only_intended_attributes_change() {
		$html   = self::edge();
		$output = self::engine( 'live' )->run( $html );
		$this->assertSame( self::EDGE_DIFF, self::token_diff( $html, $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
		$this->assertStringNotContainsString( 'Bakery oven', $output, 'An attachment title is never used as alt.' );
		$this->assertStringNotContainsString( 'alt="a11y-fx', $output, 'A file name is never used as alt.' );
	}

	/**
	 * Running the engine on its own output changes nothing; both passes count the same openers.
	 */
	public function test_edge_idempotent_and_aligned() {
		$engine = self::engine( 'live' );
		$once   = $engine->run( self::edge() );
		$facts  = $engine->recorder()->engine();
		$this->assertSame( $facts['openers'], $facts['openers_apply'] );
		$this->assertSame( $once, self::engine( 'live' )->run( $once ) );
	}

	/**
	 * Scan mode: nothing applied; R05 for the unnamed role="img" SVGs, R03 for
	 * suspicious alt texts, R04 for the images no fix can describe; F11's R08
	 * (the page has a main element but no skip link).
	 */
	public function test_edge_scan_findings() {
		$html   = self::edge();
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN ) );

		$this->assertSame(
			array(
				array( 'R05', '1.1.1', 'svg[role=img]', 'SVG image with role="img" has no name: add a title element or an aria-label.', 0 ),
				array( 'R05', '1.1.1', 'svg[role=img]', 'SVG image with role="img" has no name: add a title element or an aria-label.', 0 ),
				array( 'R08', '2.4.1', 'body', 'This page has no skip link among its first focusable elements.', 0 ),
				array( 'R03', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-cake.png]', 'Alt text looks like a file name: "IMG_2041.JPG".', 0 ),
				array( 'R03', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-cake.png]', 'Alt text starts with "Image of"; screen readers already say it is an image.', 0 ),
				array( 'R03', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-cinnamon-rolls.jpg]', 'Alt text repeats the link or button text, so it is read twice.', 0 ),
				array( 'R03', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-baguette.jpg]', 'Alt text repeats the caption, so it is read twice.', 0 ),
				array( 'R03', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-cake.png]', 'Alt text looks like a file name: "20241031".', 0 ),
				array( 'R04', '1.1.1', 'img[src=/wp-content/uploads/2026/10/a11y-fx-croissant.jpg]', 'No alt text, and the image was not found in the Media Library: add alt text where the image is inserted.', 0 ),
				array( 'R04', '1.1.1', 'img.wp-image-11[src=/wp-content/uploads/2026/10/bakery-oven.webm]', 'No alt text, and its Media Library entry has none: add it in the Media Library, or alt="" where it is inserted if it is decorative.', 11 ),
				array( 'R04', '1.1.1', 'img[src=/photos/bread-basket.jpg]', 'No alt text, and the image was not found in the Media Library: add alt text where the image is inserted.', 0 ),
				array( 'R04', '1.1.1', 'img[src=/wp-content/uploads/2026/10/not-in-the-library.jpg]', 'No alt text, and the image was not found in the Media Library: add alt text where the image is inserted.', 0 ),
			),
			array_map(
				static function ( $finding ) {
					return array( $finding['check'], $finding['sc'], $finding['sig'], $finding['detail'], isset( $finding['attachment'] ) ? $finding['attachment'] : 0 );
				},
				$engine->recorder()->findings()
			)
		);
	}

	/**
	 * One batch per run, however many fixes and checks ask; the URL lookups
	 * stay in the uploads folder, and the next run on the same engine asks nothing new.
	 */
	public function test_edge_lookups_are_batched() {
		$lookup = new Softglaze_A11y_Test_Attachment_Lookup();
		$engine = self::engine( 'live', array(), Engine::class, array( 'attachments' => $lookup ) );
		$engine->run( self::edge(), Engine::MODE_ADMIN_REPAIRED );

		$this->assertCount( 1, $lookup->batches );
		$this->assertEqualsCanonicalizing( array( 6, 7, 8, 9, 10, 11 ), $lookup->batches[0] );
		$this->assertSame(
			array(
				'/2026/10/a11y-fx-sourdough.jpg',
				'/2026/10/a11y-fx-cinnamon-rolls.jpg',
				'/2026/10/not-in-the-library.jpg',
				'/2026/10/not-in-the-library-scaled.jpg',
			),
			$lookup->urls
		);
		$this->assertSame( 4, $lookup->url_lookups() );

		$engine->run( self::edge(), Engine::MODE_ADMIN_REPAIRED );
		$this->assertCount( 1, $lookup->batches, 'A second run on the same engine is answered from memory.' );
		$this->assertSame( 4, $lookup->url_lookups() );
	}

	/**
	 * Visitor runs keep no findings and run no checks.
	 */
	public function test_visitor_mode_runs_no_checks() {
		$engine = self::engine( 'live' );
		$engine->run( self::edge() );
		$this->assertSame( array(), $engine->recorder()->findings() );
	}

	/**
	 * F03 beats F01 for an image inside a named link; with F03 Off, F01 fills
	 * it; with F01 Off, F03 still empties it.
	 */
	public function test_f03_beats_f01() {
		$html  = '<p><a href="/order/">Order a loaf <img class="wp-image-8" src="http://localhost:8730/wp-content/uploads/2026/10/a11y-fx-sourdough.jpg"></a></p>';
		$fixes = array( new Fix_Img_Alt_Library(), new Fix_Img_In_Named_Control() );
		$run   = static function ( $f01, $f03, $mode = Engine::MODE_VISITOR ) use ( $html, $fixes ) {
			$engine = new Engine(
				$fixes,
				array(
					'img-alt-library'      => $f01,
					'img-in-named-control' => $f03,
				),
				array( 'attachments' => new Softglaze_A11y_Test_Attachment_Lookup() )
			);
			return array( $engine->run( $html, $mode, Engine::SCOPE_CONTENT ), $engine );
		};

		list( $both ) = $run( 'live', 'live' );
		$this->assertStringContainsString( '<img alt="" class="wp-image-8"', $both );
		list( $f01_only ) = $run( 'live', 'off' );
		$this->assertStringContainsString( '<img alt="Round sourdough loaf with a floured crust" class="wp-image-8"', $f01_only );
		list( $f03_only ) = $run( 'off', 'live' );
		$this->assertStringContainsString( '<img alt="" class="wp-image-8"', $f03_only );

		list( $scanned, $engine ) = $run( 'preview', 'preview', Engine::MODE_SCAN );
		$this->assertSame( $html, $scanned );
		$changes = $engine->recorder()->changes();
		$this->assertCount( 1, $changes, 'One change per attribute: the winner.' );
		$this->assertSame( 'img-in-named-control', $changes[0]['fix'] );
		$this->assertSame( 2, $engine->recorder()->engine()['edits_planned'] );
	}

	/**
	 * F03: what names a control (text, image alt, aria-label, title) and what does not (icon glyphs, nothing).
	 */
	public function test_f03_named_controls() {
		$cases = array(
			'<a href="/a" title="Shop"><img src="/x.jpg"></a>'                         => '<a href="/a" title="Shop"><img alt="" src="/x.jpg"></a>',
			'<a href="/a"><img src="/y.jpg" alt="Logo"><img src="/x.jpg"></a>'          => '<a href="/a"><img src="/y.jpg" alt="Logo"><img alt="" src="/x.jpg"></a>',
			'<button>Buy <img src="/x.jpg"></button>'                                     => '<button>Buy <img alt="" src="/x.jpg"></button>',
			'<a href="/a"><img src="/x.jpg"><span class="fa fa-star">&#xf005;</span></a>' => '<a href="/a"><img src="/x.jpg"><span class="fa fa-star">&#xf005;</span></a>',
			'<a href="/a"><img src="/x.jpg"><img src="/z.jpg"></a>'                       => '<a href="/a"><img src="/x.jpg"><img src="/z.jpg"></a>',
			'<a name="top">Top <img src="/x.jpg"></a>'                                    => '<a name="top">Top <img src="/x.jpg"></a>',
			'<a href="/a" aria-hidden="true">Hidden <img src="/x.jpg"></a>'              => '<a href="/a" aria-hidden="true">Hidden <img src="/x.jpg"></a>',
			'<a href="/a">Go <img src="/x.jpg" role="none"></a>'                         => '<a href="/a">Go <img src="/x.jpg" role="none"></a>',
		);
		foreach ( $cases as $html => $expected ) {
			$engine = new Engine( array( new Fix_Img_In_Named_Control() ), array( 'img-in-named-control' => 'live' ) );
			$this->assertSame( $expected, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ), $html );
		}
	}

	/**
	 * F07 cases beyond the edge page: implicit link closes, an icon next to an
	 * image alt, nested icon wrappers, buttons, and nothing outside controls.
	 */
	public function test_f07_icons() {
		$cases = array(
			'<a href="/a"><i class="fa fa-x"></i> One<a href="/b"><i class="fa fa-y"></i></a>'               => '<a href="/a"><i aria-hidden="true" class="fa fa-x"></i> One<a href="/b"><i class="fa fa-y"></i></a>',
			'<a href="/"><img src="/l.png" alt="Home"><span class="dashicons dashicons-admin-home"></span></a>' => '<a href="/"><img src="/l.png" alt="Home"><span aria-hidden="true" class="dashicons dashicons-admin-home"></span></a>',
			'<button type="submit"><span class="btn-icon"><i class="bi bi-search"></i></span>Search</button>'  => '<button type="submit"><span class="btn-icon"><i aria-hidden="true" class="bi bi-search"></i></span>Search</button>',
			'<p><i class="fa fa-star"></i> Rated</p><span class="et-pb-icon">&#xe076;</span>'                  => '<p><i class="fa fa-star"></i> Rated</p><span class="et-pb-icon">&#xe076;</span>',
			'<a href="/a"><svg viewBox="0 0 1 1"><path d="M0 0"/></svg><span class="screen-reader-text">Facebook</span></a>' => '<a href="/a"><svg aria-hidden="true" focusable="false" viewBox="0 0 1 1"><path d="M0 0"/></svg><span class="screen-reader-text">Facebook</span></a>',
			'<a href="/a"><svg role="presentation"><path d="M0 0"/></svg> Next</a>'                          => '<a href="/a"><svg role="presentation"><path d="M0 0"/></svg> Next</a>',
			'<a href="/a"><svg aria-label="Next"><path d="M0 0"/></svg> page</a>'                            => '<a href="/a"><svg aria-label="Next"><path d="M0 0"/></svg> page</a>',
			'<a href="/a"><i class="fa fa-x" aria-hidden="false"></i> Close</a>'                             => '<a href="/a"><i class="fa fa-x" aria-hidden="false"></i> Close</a>',
		);
		foreach ( $cases as $html => $expected ) {
			$engine = new Engine( array( new Fix_Decorative_Icons() ), array( 'decorative-icons' => 'live' ) );
			$this->assertSame( $expected, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ), $html );
			$this->assertNull( $engine->recorder()->get_fallback() );
		}
	}

	/**
	 * F07 in admin views: Preview applies, Off is listed as "would change".
	 */
	public function test_f07_modes() {
		$html = '<a href="/cart/"><i class="fa fa-shopping-cart"></i> Cart</a>';
		foreach ( array( 'preview' => true, 'off' => false ) as $state => $applied ) {
			$engine = new Engine( array( new Fix_Decorative_Icons() ), array( 'decorative-icons' => $state ) );
			$output = $engine->run( $html, Engine::MODE_ADMIN_REPAIRED, Engine::SCOPE_CONTENT );
			$this->assertSame( $applied ? '<a href="/cart/"><i aria-hidden="true" class="fa fa-shopping-cart"></i> Cart</a>' : $html, $output, $state );
			$changes = $engine->recorder()->changes();
			$this->assertCount( 1, $changes, $state );
			$this->assertSame( array( 'decorative-icons', $state, 'i.fa.fa-shopping-cart', 'aria-hidden', null, 'true', $applied ), array_values( $changes[0] ) );
		}
	}
}
