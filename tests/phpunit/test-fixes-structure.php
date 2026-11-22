<?php
/**
 * Page structure (Phase 4A): F11, F12, F14, F18 (its argument filter), F20
 * and the findings R02, R08, R09, on the hand-written edge page
 * tests/fixtures/edge/structure.html (expected output made on the dev site
 * with tools/fixtures/make-expected.php) and on small cases. F11's printer,
 * F18's wiring and F22's stylesheet are covered in test-front-end.php.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Empty_Heading;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Nav_Labels;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Positive_Tabindex;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link_Target;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Page structure fixes and checks.
 */
class Test_Fixes_Structure extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Our own link as Front_End prints it.
	 */
	const OWN = '<a class="softglaze-a11y-skip-link screen-reader-text" href="#softglaze-a11y-main">Skip to content</a>';

	/**
	 * Every attribute change on the edge page with every fix Live, in document order.
	 *
	 * @var string[]
	 */
	const EDGE_DIFF = array(
		"A.tabindex: '2' -> '0'",
		"A.href: '#site-content' -> '#softglaze-a11y-main'",
		"A.href: '#missing-nav' -> '#softglaze-a11y-main'",
		"MAIN.id: NULL -> 'softglaze-a11y-main'",
		"H2.aria-hidden: NULL -> 'true'",
		"H2.aria-hidden: NULL -> 'true'",
		"H3.aria-hidden: NULL -> 'true'",
		"DIV.tabindex: ' +4' -> '0'",
		"SPAN.tabindex: '12' -> '0'",
	);

	/**
	 * The edge page.
	 *
	 * @return string
	 */
	private static function edge() {
		return self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/structure.html' );
	}

	/**
	 * A small whole document.
	 *
	 * @param string $body Body markup.
	 * @param string $head Head markup.
	 * @return string
	 */
	private static function page( $body, $head = '<title>Page</title>' ) {
		return '<!DOCTYPE html><html lang="en"><head>' . $head . '</head><body>' . $body . '</body></html>';
	}

	/**
	 * Runs some fixes, all in one state, without checks.
	 *
	 * @param array  $fixes Fix objects.
	 * @param string $html  Document.
	 * @param string $mode  Mode.
	 * @param string $scope Scope.
	 * @return array Output and engine.
	 */
	private static function run_fixes( array $fixes, $html, $mode = Engine::MODE_VISITOR, $scope = Engine::SCOPE_PAGE ) {
		$states = array();
		foreach ( $fixes as $fix ) {
			$states[ $fix->id() ] = 'live';
		}
		$engine = new Engine( $fixes, $states );
		return array( $engine->run( $html, $mode, $scope ), $engine );
	}

	/**
	 * Token diff of F11 and F12 on a document.
	 *
	 * @param string $html Document.
	 * @return string[]
	 */
	private static function skip_diff( $html ) {
		list( $output ) = self::run_fixes( array( new Fix_Skip_Link_Target(), new Fix_Skip_Link() ), $html );
		return self::token_diff( $html, $output );
	}

	/**
	 * Findings of a run as "check sig: detail".
	 *
	 * @param Engine $engine Engine after a run that records details.
	 * @return string[]
	 */
	private static function findings( $engine ) {
		$list = array();
		foreach ( $engine->recorder()->findings() as $finding ) {
			$list[] = $finding['check'] . ' ' . $finding['sig'] . ': ' . $finding['detail'];
		}
		return $list;
	}

	/**
	 * Output equals the file made on the dev site, byte for byte.
	 */
	public function test_edge_output_matches_expected_file() {
		$engine = self::engine( 'live' );
		$output = $engine->run( self::edge() );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/structure.expected.html' ), $output );
	}

	/**
	 * Only the intended attributes change. F11 gives the main element its id
	 * (our link already points there); F12 points the broken class and text
	 * skip links at it and leaves #top, the named anchor, the existing id,
	 * the percent-encoded id and the ordinary in-page link alone; F14 keeps
	 * "0", "-1" and "abc"; F20 hides the three empty headings and keeps those
	 * with an image, aria-label, id, child element or aria-hidden.
	 */
	public function test_edge_only_intended_attributes_change() {
		$html   = self::edge();
		$output = self::engine( 'live' )->run( $html );
		$this->assertSame( self::EDGE_DIFF, self::token_diff( $html, $output ) );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
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
	 * Scan of the edge page: nothing applied, every edit listed as "would
	 * change", R02 for the second h1 and the jump to h4, R08 for the two
	 * unlabelled navs (the labelled one is fine), no R09.
	 */
	public function test_edge_scan() {
		$html   = self::edge();
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN ) );
		$changes = $engine->recorder()->changes();
		$this->assertCount( count( self::EDGE_DIFF ), $changes );
		foreach ( $changes as $change ) {
			$this->assertFalse( $change['applied'] );
		}
		$this->assertSame(
			array(
				'R02 h1: More than one h1 heading on this page: "A second page title".',
				'R02 h4: Heading level jumps from h2 to h4: "Bank holidays".',
				'R08 nav.primary-menu: 2 navigation landmarks have no label: give each menu a name, for example with aria-label.',
				'R08 nav: 2 navigation landmarks have no label: give each menu a name, for example with aria-label.',
			),
			self::findings( $engine )
		);
	}

	/**
	 * F11 settles our own link: the main element's id, an id for a main
	 * without one, a fallback id, or hidden; hidden too when the page already
	 * starts with a skip link. The logged-in toolbar does not count.
	 */
	public function test_f11_settles_our_link() {
		$cases = array(
			'main with id'              => array(
				self::OWN . '<a href="/">Home</a><main id="site-main"><p>x</p></main>',
				array( "A.href: '#softglaze-a11y-main' -> '#site-main'" ),
			),
			'main without id'           => array(
				self::OWN . '<main class="site-main"><p>x</p></main>',
				array( "MAIN.id: NULL -> 'softglaze-a11y-main'" ),
			),
			'role main'                 => array(
				self::OWN . '<div role="main" id="primary-area"><p>x</p></div>',
				array( "A.href: '#softglaze-a11y-main' -> '#primary-area'" ),
			),
			'fallback id'               => array(
				self::OWN . '<div id="content"><p>x</p></div>',
				array( "A.href: '#softglaze-a11y-main' -> '#content'" ),
			),
			'two mains, fallback id'    => array(
				self::OWN . '<main><p>a</p></main><main><p>b</p></main><div id="primary"></div>',
				array( "A.href: '#softglaze-a11y-main' -> '#primary'" ),
			),
			'hidden main does not count' => array(
				self::OWN . '<main hidden id="old"></main><main id="new"><p>x</p></main>',
				array( "A.href: '#softglaze-a11y-main' -> '#new'" ),
			),
			'unusable main id'          => array(
				self::OWN . '<main id="main!content"><p>x</p></main>',
				array( "A.hidden: NULL -> ''" ),
			),
			'no target'                 => array(
				self::OWN . '<div class="page"><p>x</p></div>',
				array( "A.hidden: NULL -> ''" ),
			),
			'theme skip link first'     => array(
				self::OWN . '<a class="skip-link" href="#content">Skip to content</a><main id="content"></main>',
				array( "A.hidden: NULL -> ''" ),
			),
			'theme skip link fourth'    => array(
				self::OWN . '<a href="/a">A</a><button>B</button><input name="q"><a class="skip-link" href="#content">Skip</a><main id="content"></main>',
				array( "A.href: '#softglaze-a11y-main' -> '#content'" ),
			),
			'hidden controls skipped'   => array(
				self::OWN . '<a href="/a" hidden>A</a><input type="hidden" name="h"><button disabled>B</button><a href="/c" tabindex="-1">C</a><a href="#content">Skip</a><main id="content"></main>',
				array( "A.hidden: NULL -> ''" ),
			),
			'toolbar does not count'    => array(
				'<div id="wpadminbar"><div class="quicklinks" id="wp-toolbar" role="navigation" aria-label="Toolbar"><a class="ab-item" href="/wp-admin/">Dashboard</a><a class="ab-item" href="/">Site</a><a class="ab-item" href="/new">New</a></div></div>' . self::OWN . '<a class="skip-link screen-reader-text" href="#main">Skip to content</a><main id="main"></main>',
				array( "A.hidden: NULL -> ''" ),
			),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( $case[1], self::skip_diff( self::page( $case[0] ) ), $name );
		}
	}

	/**
	 * F11 and F12 on their own output change nothing more.
	 */
	public function test_f11_idempotent() {
		foreach ( array( '<main><p>x</p></main>', '<main id="m"></main>', '<p>x</p>', '<a class="skip-link" href="#nowhere">Skip</a><main></main>' ) as $body ) {
			$html            = self::page( self::OWN . $body );
			list( $once )    = self::run_fixes( array( new Fix_Skip_Link_Target(), new Fix_Skip_Link() ), $html );
			list( $twice )   = self::run_fixes( array( new Fix_Skip_Link_Target(), new Fix_Skip_Link() ), $once );
			$this->assertSame( $once, $twice, $body );
		}
	}

	/**
	 * Without our link: R08 when the page has a target but no skip link at its
	 * start, worded for a theme without wp_body_open(); nothing when core's
	 * link is active, when a skip link exists or when there is no target.
	 */
	public function test_f11_reports_a_page_without_skip_link() {
		$fix  = new Fix_Skip_Link();
		$page = self::page( '<a href="/">Home</a><main id="content"></main>' );

		list( , $engine ) = self::run_fixes( array( $fix ), $page, Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array( 'R08 body: This page has no skip link among its first focusable elements.' ), self::findings( $engine ) );
		$this->assertSame( '2.4.1', $engine->recorder()->findings()[0]['sc'] );

		$fix->set_printer( Fix_Skip_Link::PRINTER_NO_HOOK );
		list( , $engine ) = self::run_fixes( array( $fix ), $page, Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array( 'R08 body: No skip link could be added: the theme does not call wp_body_open().' ), self::findings( $engine ) );

		$fix->set_printer( Fix_Skip_Link::PRINTER_CORE );
		list( , $engine ) = self::run_fixes( array( $fix ), $page, Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array(), self::findings( $engine ) );

		$fix->set_printer( Fix_Skip_Link::PRINTER_UNKNOWN );
		foreach ( array( '<a href="#content">Skip</a><main id="content"></main>', '<a href="/">Home</a><div class="page"></div>' ) as $body ) {
			list( $output, $engine ) = self::run_fixes( array( $fix ), self::page( $body ), Engine::MODE_ADMIN_REPAIRED );
			$this->assertSame( array(), self::findings( $engine ), $body );
			$this->assertSame( self::page( $body ), $output );
		}
	}

	/**
	 * F12 with zero, one and two main elements.
	 */
	public function test_f12_main_counts() {
		$link = '<a class="skip-link screen-reader-text" href="#gone">Skip to content</a>';
		$fix  = static function () {
			return array( new Fix_Skip_Link_Target() );
		};

		$html           = self::page( $link . '<div class="page"></div>' );
		list( $output ) = self::run_fixes( $fix(), $html );
		$this->assertSame( $html, $output, 'No main: nothing to point at.' );

		$html           = self::page( $link . '<main id="site-main"></main>' );
		list( $output ) = self::run_fixes( $fix(), $html );
		$this->assertSame( array( "A.href: '#gone' -> '#site-main'" ), self::token_diff( $html, $output ), 'One main with an id.' );

		$html           = self::page( $link . '<main class="site-main"></main>' );
		list( $output ) = self::run_fixes( $fix(), $html );
		$this->assertSame( array( "A.href: '#gone' -> '#softglaze-a11y-main'", "MAIN.id: NULL -> 'softglaze-a11y-main'" ), self::token_diff( $html, $output ), 'One main without an id.' );

		$html           = self::page( $link . '<main id="a"></main><main id="b"></main>' );
		list( $output ) = self::run_fixes( $fix(), $html );
		$this->assertSame( $html, $output, 'Two mains: which one is meant is unknown.' );

		$html           = self::page( $link . '<div role="main" id="primary"></div>' );
		list( $output ) = self::run_fixes( $fix(), $html );
		$this->assertSame( array( "A.href: '#gone' -> '#primary'" ), self::token_diff( $html, $output ), 'role="main" counts as the main element.' );
	}

	/**
	 * F12 leaves working links, ordinary in-page links, our own link and
	 * unusable main ids alone; a skip link is recognised by its text too.
	 */
	public function test_f12_scope() {
		$untouched = array(
			'<a class="skip-link" href="#top">Skip</a><main id="m"></main>',
			'<a class="skip-link" href="#TOP">Skip</a><main id="m"></main>',
			'<a class="skip-link" href="#old">Skip</a><a name="old"></a><main id="m"></main>',
			'<a class="skip-link" href="#here">Skip</a><p id="here"></p><main id="m"></main>',
			'<a class="skip-link" href="#caf%C3%A9">Skip</a><p id="café"></p><main id="m"></main>',
			'<a href="#faq-2">Question 2</a><main id="m"></main>',
			self::OWN . '<main id="m"></main>',
			'<a class="skip-link" href="#gone">Skip</a><main id="has space"></main>',
			'<a class="skip-link" href="#gone">Skip</a><main id="m"></main><p id="m"></p>',
			'<a class="skip-link" href="#">Skip</a><main id="m"></main>',
		);
		foreach ( $untouched as $body ) {
			$html           = self::page( $body );
			list( $output ) = self::run_fixes( array( new Fix_Skip_Link_Target() ), $html );
			$this->assertSame( $html, $output, $body );
		}

		$html           = self::page( '<a href="#nav-gone">Skip to navigation</a><main id="m"></main>' );
		list( $output ) = self::run_fixes( array( new Fix_Skip_Link_Target() ), $html );
		$this->assertSame( array( "A.href: '#nav-gone' -> '#m'" ), self::token_diff( $html, $output ) );
	}

	/**
	 * F12 with F11 on a main without an id: both propose the same id, so the
	 * main gets it once and every link points there, in any combination of states.
	 */
	public function test_f11_and_f12_agree_on_the_main_id() {
		$html  = self::page( self::OWN . '<a href="/">Home</a><a href="/a">A</a><a href="/b">B</a><p><a class="skip-link" href="#gone">Skip to content</a></p><main></main>' );
		$fixes = array( new Fix_Skip_Link_Target(), new Fix_Skip_Link() );
		foreach ( array( array( 'live', 'live' ), array( 'live', 'off' ), array( 'off', 'live' ) ) as $states ) {
			$engine = new Engine(
				$fixes,
				array(
					'skip-link-target' => $states[0],
					'skip-link'        => $states[1],
				)
			);
			$output = $engine->run( $html );
			$diff   = self::token_diff( $html, $output );
			$this->assertContains( "MAIN.id: NULL -> 'softglaze-a11y-main'", $diff, implode( '/', $states ) );
			$this->assertSame( 'live' === $states[0], in_array( "A.href: '#gone' -> '#softglaze-a11y-main'", $diff, true ), implode( '/', $states ) );
		}
	}

	/**
	 * F14 resets positive values only; "0" and "-1" stay.
	 */
	public function test_f14_values() {
		$cases = array(
			'3'    => '0',
			' +4'  => '0',
			'007'  => '0',
			'12px' => '0',
			'0'    => null,
			'-1'   => null,
			'-5'   => null,
			'+0'   => null,
			'abc'  => null,
			''     => null,
		);
		foreach ( $cases as $value => $expected ) {
			$html           = '<p><a href="/x" tabindex="' . $value . '">X</a></p>';
			list( $output ) = self::run_fixes( array( new Fix_Positive_Tabindex() ), $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
			$this->assertSame( null === $expected ? $html : '<p><a href="/x" tabindex="0">X</a></p>', $output, "tabindex=\"$value\"" );
		}

		$toolbar        = '<a class="screen-reader-shortcut" href="#wp-toolbar" tabindex="1">Skip to toolbar</a>';
		list( $output ) = self::run_fixes( array( new Fix_Positive_Tabindex() ), $toolbar, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$this->assertSame( $toolbar, $output, 'The toolbar shortcut is not part of the visitor page.' );
	}

	/**
	 * F20 hides headings with nothing in them, in fragments too (each case is
	 * the only heading, so the outline cannot change).
	 */
	public function test_f20_cases() {
		$hidden = array( '<h2></h2>', '<h3> </h3>', '<h4>&nbsp;</h4>', '<h2><br></h2>', '<h5>' . "\n\t" . '</h5>' );
		$kept   = array(
			'<h2>Hours</h2>',
			'<h2><img src="/a.png" alt="Cakes"></h2>',
			'<h2><img src="/a.png" alt=""></h2>',
			'<h2><span></span></h2>',
			'<h2><svg><title>Cakes</title></svg></h2>',
			'<h2 aria-label="Cakes"></h2>',
			'<h2 aria-labelledby="x"></h2>',
			'<h2 title="Cakes"></h2>',
			'<h2 id="cakes"></h2>',
			'<h2 role="presentation"></h2>',
			'<h2 hidden></h2>',
			'<h2 aria-hidden="true"></h2>',
			'<h2 aria-hidden="false"></h2>',
			'<div aria-hidden="true"><h2></h2></div>',
			'<h2>' . "\u{E001}" . '</h2>',
		);
		foreach ( $hidden as $heading ) {
			list( $output ) = self::run_fixes( array( new Fix_Empty_Heading() ), $heading, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
			$this->assertSame( 1, substr_count( $output, ' aria-hidden="true"' ), $heading );
			$this->assertSame( 0, strpos( $output, substr( $heading, 0, 3 ) . ' aria-hidden="true">' ), $heading );
		}
		foreach ( $kept as $heading ) {
			list( $output ) = self::run_fixes( array( new Fix_Empty_Heading() ), $heading, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
			$this->assertSame( $heading, $output, $heading );
		}
	}

	/**
	 * F20 never makes the next heading skip a level, and never hides the only
	 * h1 of a page or an h1 in a fragment; a heading it leaves for that reason
	 * is listed under R02.
	 */
	public function test_f20_keeps_the_outline() {
		$cases = array(
			'between h1 and h3'            => array( self::page( '<h1>T</h1><h2></h2><h3>Sub</h3>' ), Engine::SCOPE_PAGE, false ),
			'between h1 and h2'            => array( self::page( '<h1>T</h1><h2></h2><h2>Next</h2>' ), Engine::SCOPE_PAGE, true ),
			'last heading'                 => array( self::page( '<h1>T</h1><h2>A</h2><h3></h3>' ), Engine::SCOPE_PAGE, true ),
			'two empty in a row'           => array( self::page( '<h1>T</h1><h2></h2><h2></h2><h2>Next</h2>' ), Engine::SCOPE_PAGE, true ),
			'only h1'                      => array( self::page( '<h1></h1><h2>A</h2>' ), Engine::SCOPE_PAGE, false ),
			'second h1'                    => array( self::page( '<h1>T</h1><h2>A</h2><h1></h1>' ), Engine::SCOPE_PAGE, true ),
			'fragment start, deeper next'  => array( '<h2></h2><h3>Sub</h3>', Engine::SCOPE_CONTENT, false ),
			'fragment start, same next'    => array( '<h3></h3><h3>Next</h3>', Engine::SCOPE_CONTENT, true ),
			'fragment start, higher next'  => array( '<h3></h3><h2>Next</h2>', Engine::SCOPE_CONTENT, true ),
			'fragment h1'                  => array( '<h1></h1>', Engine::SCOPE_CONTENT, false ),
			'hidden heading does not count' => array( self::page( '<h1>T</h1><h2></h2><h2 aria-hidden="true">x</h2><h3>Sub</h3>' ), Engine::SCOPE_PAGE, false ),
		);
		foreach ( $cases as $name => $case ) {
			list( $output, $engine ) = self::run_fixes( array( new Fix_Empty_Heading() ), $case[0], Engine::MODE_ADMIN_REPAIRED, $case[1] );
			$hidden                  = substr_count( $output, ' aria-hidden="true">' ) - substr_count( $case[0], ' aria-hidden="true">' );
			$this->assertSame( $case[2], $hidden > 0, $name );
		}

		list( , $engine ) = self::run_fixes( array( new Fix_Empty_Heading() ), self::page( '<h1>T</h1><h2></h2><h3>Sub</h3>' ), Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array( 'R02 h2: Empty h2 heading left in place: hiding it would make the next heading, h3, skip a level. Add text to it or remove it.' ), self::findings( $engine ) );
		list( , $engine ) = self::run_fixes( array( new Fix_Empty_Heading() ), self::page( '<h1>T</h1><h2></h2><h2>Next</h2>' ), Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array(), self::findings( $engine ) );
	}

	/**
	 * R02: no h1, several h1, skipped levels; headings out of the outline are ignored.
	 */
	public function test_r02_outline() {
		$engine = self::engine( 'off' );
		$engine->run( self::page( '<main><h2>Intro</h2><h4>Detail</h4><h2 aria-hidden="true">x</h2><div aria-hidden="true"><h6>y</h6></div><h3 role="presentation">z</h3></main>' ), Engine::MODE_SCAN );
		$this->assertSame(
			array(
				'R02 body: This page has no h1 heading.',
				'R02 h4: Heading level jumps from h2 to h4: "Detail".',
			),
			array_values( preg_grep( '/^R02 /', self::findings( $engine ) ) )
		);

		$engine->run( self::page( '<main><h1>One</h1><h2>A</h2><h3>B</h3><h2>C</h2><h1></h1></main>' ), Engine::MODE_SCAN );
		$this->assertSame( array( 'R02 h1: More than one h1 heading on this page: "(empty)".' ), array_values( preg_grep( '/^R02 /', self::findings( $engine ) ) ) );
	}

	/**
	 * R08: missing or repeated main, two or more unlabelled navigation landmarks.
	 */
	public function test_r08_landmarks() {
		$engine = self::engine( 'off' );
		$cases  = array(
			'<div><p>x</p></div>'                                                  => array( 'R08 body: This page has no main landmark: put the main content in a main element.' ),
			'<main id="a"></main><main id="b"></main>'                             => array( 'R08 main#a: This page has 2 main landmarks; a page should have one.', 'R08 main#b: This page has 2 main landmarks; a page should have one.' ),
			'<main></main><main hidden></main>'                                    => array(),
			'<main></main><nav></nav><nav aria-label="Footer"></nav>'              => array(),
			'<main></main><nav></nav><div role="navigation"></div>'                => array( 'R08 nav: 2 navigation landmarks have no label: give each menu a name, for example with aria-label.', 'R08 div[role=navigation]: 2 navigation landmarks have no label: give each menu a name, for example with aria-label.' ),
			'<main></main><nav aria-labelledby="h"></nav><nav role="none"></nav>' => array(),
		);
		foreach ( $cases as $body => $expected ) {
			$engine->run( self::page( '<a href="#x">Skip</a>' . $body ), Engine::MODE_SCAN );
			$this->assertSame( $expected, array_values( preg_grep( '/^R08 /', self::findings( $engine ) ) ), $body );
		}
	}

	/**
	 * R09: a missing or empty document title; an SVG title is not one.
	 */
	public function test_r09_title() {
		$engine = self::engine( 'off' );
		$cases  = array(
			'<title>Bakery</title>'          => array(),
			'<title></title>'                => array( 'R09 title: The page title is empty.' ),
			'<title> &nbsp; </title>'        => array( 'R09 title: The page title is empty.' ),
			''                               => array( 'R09 head: This page has no title element.' ),
			'<meta name="description" content="x">' => array( 'R09 head: This page has no title element.' ),
		);
		foreach ( $cases as $head => $expected ) {
			$engine->run( self::page( '<main><svg><title>Logo</title></svg></main>', $head ), Engine::MODE_SCAN );
			$this->assertSame( $expected, array_values( preg_grep( '/^R09 /', self::findings( $engine ) ) ), $head );
		}
	}

	/**
	 * The page-level checks and F11/F12 do not run on content fragments.
	 */
	public function test_page_level_work_skips_fragments() {
		$fragment = '<a class="skip-link" href="#gone">Skip</a><h3>Intro</h3><h5>x</h5><nav></nav><nav></nav>';
		$engine   = self::engine( 'off' );
		$this->assertSame( $fragment, $engine->run( $fragment, Engine::MODE_SCAN, Engine::SCOPE_CONTENT ) );
		$this->assertSame( array(), self::findings( $engine ) );
		$this->assertSame( array(), $engine->recorder()->changes() );
	}

	/**
	 * F18's argument filter: the location description, else the menu name;
	 * only for nav containers without a label; plain text.
	 */
	public function test_f18_arguments() {
		register_nav_menus( array( 'primary' => 'Primary Menu' ) );
		$menu_id = wp_create_nav_menu( 'Footer & legal' );
		$fix     = new Fix_Nav_Labels();

		$this->assertSame( 'Primary Menu', $fix->filter_args( array( 'container' => 'nav', 'theme_location' => 'primary', 'container_aria_label' => '' ) )['container_aria_label'] );
		$this->assertSame( 'Footer & legal', $fix->filter_args( array( 'container' => 'nav', 'menu' => $menu_id ) )['container_aria_label'] );
		$this->assertSame( 'Footer & legal', $fix->filter_args( array( 'container' => 'nav', 'menu' => wp_get_nav_menu_object( $menu_id ) ) )['container_aria_label'] );
		$this->assertSame( 'Footer & legal', $fix->filter_args( array( 'container' => 'nav', 'menu' => 'footer-legal' ) )['container_aria_label'] );

		$kept = array(
			array( 'container' => 'div', 'theme_location' => 'primary' ),
			array( 'container' => 'nav', 'theme_location' => 'primary', 'container_aria_label' => 'Main' ),
			array( 'container' => 'nav', 'theme_location' => 'unregistered' ),
			array( 'container' => 'nav' ),
			array( 'container' => false, 'menu' => $menu_id ),
		);
		foreach ( $kept as $args ) {
			$this->assertSame( $args, $fix->filter_args( $args ) );
		}

		set_theme_mod( 'nav_menu_locations', array( 'social' => $menu_id ) );
		$this->assertSame( 'Footer & legal', $fix->filter_args( array( 'container' => 'nav', 'theme_location' => 'social' ) )['container_aria_label'], 'An unregistered location falls back to its assigned menu.' );
		remove_theme_mod( 'nav_menu_locations' );
		unregister_nav_menu( 'primary' );
	}

	/**
	 * The new fixes take part in the engine's mode rules: Off is "would change" in admin views.
	 */
	public function test_new_fixes_off_are_would_change() {
		$html   = self::page( '<a class="skip-link" href="#gone">Skip to content</a><main id="m"><h2></h2><a href="/x" tabindex="4">X</a></main>' );
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_ADMIN_REPAIRED ) );
		$fixes = array();
		foreach ( $engine->recorder()->changes() as $change ) {
			$fixes[] = $change['fix'] . ':' . ( $change['applied'] ? 'applied' : $change['state'] );
		}
		$this->assertSame( array( 'skip-link-target:off', 'empty-heading:off', 'positive-tabindex:off' ), $fixes );
	}
}
