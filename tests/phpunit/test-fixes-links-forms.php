<?php
/**
 * Links, buttons and forms (Phase 3): F05, F06, F07's dependency on them,
 * F08, F19, F21 and the findings R05, R06, R07, on the hand-written edge page
 * tests/fixtures/edge/links-forms.html (expected output made on the dev site
 * with tools/fixtures/make-expected.php) and on small cases. Also the
 * acceptance check across every fixture: no element that had a non-empty
 * accessible name gets a different one, except F19's deliberate context.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Button_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Decorative_Icons;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Field_Label;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Link_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_New_Tab_Note;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Read_More_Context;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Link, button and form fixes.
 */
class Test_Fixes_Links_Forms extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Every attribute change on the edge page with every fix Live, in document order.
	 */
	const EDGE_DIFF = array(
		"A.aria-label: NULL -> 'Example Bakery home page'",
		"IMG.alt: '' -> 'Three-layer chocolate cake'",
		"A.aria-label: NULL -> 'Example Bakery home page'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
		"BUTTON.aria-label: NULL -> 'Menu'",
		"BUTTON.aria-label: NULL -> 'Menu'",
		"I.aria-hidden: NULL -> 'true'",
		"BUTTON.aria-label: NULL -> 'Close'",
		"BUTTON.aria-label: NULL -> 'Close'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
		"BUTTON.aria-label: NULL -> 'Close'",
		"I.aria-hidden: NULL -> 'true'",
		"I.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'Facebook'",
		"A.aria-label: NULL -> 'Instagram'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
		"A.aria-label: NULL -> 'X (Twitter)'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'LinkedIn'",
		"I.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'WhatsApp'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'YouTube'",
		"I.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'Email orders@example.com'",
		"SPAN.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'Call +44 1234 567890'",
		"I.aria-hidden: NULL -> 'true'",
		"A.aria-label: NULL -> 'Cart'",
		"IMG.alt: NULL -> 'Butter croissant on a wooden board'",
		"I.aria-hidden: NULL -> 'true'",
		"INPUT.aria-label: NULL -> 'Search products'",
		"BUTTON.aria-label: NULL -> 'Search'",
		"SVG.aria-hidden: NULL -> 'true'",
		"SVG.focusable: NULL -> 'false'",
		"BUTTON.aria-label: NULL -> 'Search'",
		"I.aria-hidden: NULL -> 'true'",
		"INPUT.aria-label: NULL -> 'Search'",
		"INPUT.value: '' -> 'Search'",
		"INPUT.aria-label: NULL -> 'Your name'",
		"INPUT.aria-label: NULL -> 'Email'",
		"TEXTAREA.aria-label: NULL -> 'Message'",
		"INPUT.aria-label: NULL -> 'Quantity'",
		"INPUT.aria-label: NULL -> '\"> Tom & Jerry'",
		"A.aria-label: NULL -> 'Read more »: Autumn & winter menu'",
		"A.aria-label: NULL -> 'Continue reading: \"> Stollen & more'",
		"A.aria-describedby: NULL -> 'softglaze-a11y-newtab-note'",
		"A.aria-describedby: 'partner-note' -> 'partner-note softglaze-a11y-newtab-note'",
	);

	/**
	 * The edge page.
	 *
	 * @return string
	 */
	private static function edge() {
		return self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/links-forms.html' );
	}

	/**
	 * Every page with expected output: the captured fixtures and three edge pages.
	 *
	 * @return array Name => HTML.
	 */
	private static function all_pages() {
		$pages = array();
		foreach ( self::fixture_files() as $slug => $path ) {
			$pages[ $slug ] = self::read( $path );
		}
		$pages['edge/images-icons'] = self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/images-icons.html' );
		$pages['edge/links-forms']  = self::edge();
		$pages['edge/focus-ids']    = self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/focus-ids.html' );
		return $pages;
	}

	/**
	 * Naming facts of a document, by opener ordinal.
	 *
	 * @param string $html Document.
	 * @return array Ordinal => Accessible_Name.
	 */
	private static function names_of( $html ) {
		$probe  = new Softglaze_A11y_Test_Name_Probe();
		$engine = new Engine( array( $probe ), array( 'test-name-probe' => 'live' ) );
		$engine->run( $html, Engine::MODE_SCAN, Engine::SCOPE_PAGE );
		$names = array();
		foreach ( $probe->names as $record ) {
			$names[ $record->ordinal ] = $record;
		}
		return $names;
	}

	/**
	 * Runs the bundled fixes in one state over a fragment.
	 *
	 * @param string $html  Fragment.
	 * @param string $state State for every fix.
	 * @param string $mode  Mode.
	 * @return array Output and engine.
	 */
	private static function run_content( $html, $state = 'live', $mode = Engine::MODE_VISITOR ) {
		$engine = self::engine( $state );
		return array( $engine->run( $html, $mode, Engine::SCOPE_CONTENT ), $engine );
	}

	/**
	 * Findings of a scan as "check sig: detail".
	 *
	 * @param Engine $engine Engine after a scan run.
	 * @param string $check  Only this check id ('' for all).
	 * @return string[]
	 */
	private static function findings( $engine, $check = '' ) {
		$list = array();
		foreach ( $engine->recorder()->findings() as $finding ) {
			if ( '' === $check || $check === $finding['check'] ) {
				$list[] = $finding['check'] . ' ' . $finding['sig'];
			}
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
		$this->assertSame( self::read( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/edge/links-forms.expected.html' ), $output );
	}

	/**
	 * Only the intended attributes change; nothing is added or removed.
	 * Left alone: the site-title link with text, the link named by title, the
	 * aria-hidden duplicate link, the field with a label and the one with an
	 * aria-label, the "Learn more" link with its own aria-label, the
	 * new-tab links that already say so, the unnamed controls no source fits.
	 */
	public function test_edge_only_intended_attributes_change() {
		$html   = self::edge();
		$output = self::engine( 'live' )->run( $html );
		$this->assertSame( self::EDGE_DIFF, self::token_diff( $html, $output ) );
		$this->assertSame( self::opener_count( $html ), self::opener_count( $output ) );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );
		$this->assertStringNotContainsString( '<script', $output );
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
	 * Scan of the edge page: R05 for every control no source fits, R06 for
	 * every field without a visible label (including those F08 names), R07
	 * for the ambiguous links F19 cannot reach; F11's R08 first (the page has
	 * a main element but no skip link).
	 */
	public function test_edge_scan_findings() {
		$html   = self::edge();
		$engine = self::engine( 'off' );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN ) );
		$this->assertSame(
			array(
				'R08 body',
				'R05 a[href=/my-account/]',
				'R05 div.toggle-panel[role=button]',
				'R05 input[type=button]',
				'R05 select[name=flavour]',
				'R05 input[type=checkbox][name=consent]',
				'R05 input[type=text][name=custom-17]',
				'R06 input[type=search][name=s]',
				'R06 input[type=text][name=s]',
				'R06 input[type=text][name=your-name]',
				'R06 input[type=email][name=your-email]',
				'R06 textarea[name=your-message]',
				'R06 input[type=number][name=quantity]',
				'R06 select[name=flavour]',
				'R06 input[type=checkbox][name=consent]',
				'R06 input[type=text][name=custom-17]',
				'R06 input[type=text][name=note]',
				'R06 input[type=text][name=allergies]',
				'R07 a[href=/news/]',
				'R07 a[href=/contact/]',
				'R12 div.toggle-panel[role=button]',
			),
			self::findings( $engine )
		);
		$sc = array();
		foreach ( $engine->recorder()->findings() as $finding ) {
			$sc[ $finding['check'] ][ $finding['sc'] ] = true;
		}
		$this->assertSame( array( '2.4.4', '4.1.2', '1.3.1' ), array_keys( $sc['R05'] ) );
		$this->assertSame( array( '3.3.2' ), array_keys( $sc['R06'] ) );
		$this->assertSame( 'Link text "click here" does not say where the link goes: use words that name the destination.', $engine->recorder()->findings()[19]['detail'] );
	}

	/**
	 * Acceptance: on every fixture, no element that had a non-empty accessible
	 * name gets a different one (F19's links keep their visible text at the
	 * start of the new name; fields of a repeated form keep only their own
	 * label once F17 gives them their own id), and every link, button and field that had none
	 * is named afterwards, or listed in R05, or holds an image F01/R04 deal with.
	 */
	public function test_named_elements_keep_their_names() {
		$checked = 0;
		foreach ( self::all_pages() as $page => $html ) {
			$output = self::engine( 'live' )->run( $html );
			$before = self::names_of( $html );
			$after  = self::names_of( $output );
			$this->assertSame( array_keys( $before ), array_keys( $after ), $page );

			$scan = self::engine( 'off' );
			$scan->run( $html, Engine::MODE_SCAN );
			$r05 = self::findings( $scan, 'R05' );

			$ids = array();
			foreach ( $before as $record ) {
				$id         = $record->attr( 'id' );
				$ids[ $id ] = isset( $ids[ $id ] ) ? $ids[ $id ] + 1 : 1;
			}

			foreach ( $before as $ordinal => $record ) {
				$now = $after[ $ordinal ];
				if ( $record->is_named() ) {
					++$checked;
					if ( ( new Fix_Read_More_Context() )->in_reach( $record ) ) {
						// F19's purpose: the visible text stays at the start of the name.
						$this->assertStringStartsWith( $record->name() . ': ', $now->name(), $page . ' ' . $record->sig );
					} elseif ( 'label' === $record->source() && '' !== $record->attr( 'id' ) && $ids[ $record->attr( 'id' ) ] > 1 ) {
						// F17: every label of a repeated form named the fields sharing the id; now each field keeps its own label.
						$this->assertSame( 'label', $now->source(), $page . ' ' . $record->sig );
						$this->assertNotSame( '', $now->name(), $page . ' ' . $record->sig );
						$this->assertStringStartsWith( $now->name(), $record->name(), $page . ' ' . $record->sig );
					} elseif ( 'placeholder' === $record->source() ) {
						// F08 repeats the placeholder as an aria-label, as plain text.
						$this->assertSame( Fix_Base::plain_text( $record->name() ), $now->name(), $page . ' ' . $record->sig );
						$this->assertSame( 'aria-label', $now->source(), $page . ' ' . $record->sig );
					} else {
						$this->assertSame( $record->name(), $now->name(), $page . ' ' . $record->sig );
						$this->assertSame( $record->source(), $now->source(), $page . ' ' . $record->sig );
					}
					continue;
				}
				if ( $record->hidden || ! in_array( $record->kind, array( 'link', 'button', 'field' ), true ) || ( 'link' === $record->kind && ! $record->has( 'href' ) ) ) {
					continue;
				}
				$this->assertTrue(
					$now->is_named() || in_array( 'R05 ' . $record->sig, $r05, true ) || $record->images_without_alt > 0,
					$page . ': ' . $record->sig . ' is neither named nor listed in R05'
				);
			}
		}
		$this->assertGreaterThan( 140, $checked, 'Named elements compared across the fixtures.' );
	}

	/**
	 * F07 hides icons in a link F05 names only when F05's aria-label is applied in the same run.
	 */
	public function test_f07_waits_for_f05() {
		$html  = '<a href="https://www.instagram.com/bakery"><i class="fa fa-instagram"></i></a>';
		$named = '<a aria-label="Instagram" href="https://www.instagram.com/bakery"><i aria-hidden="true" class="fa fa-instagram"></i></a>';
		$run   = static function ( $f05, $f07, $mode ) use ( $html ) {
			$engine = new Engine(
				array( new Fix_Link_Name(), new Fix_Decorative_Icons() ),
				array(
					'link-name'        => $f05,
					'decorative-icons' => $f07,
				)
			);
			return array( $engine->run( $html, $mode, Engine::SCOPE_CONTENT ), $engine );
		};

		list( $both ) = $run( 'live', 'live', Engine::MODE_VISITOR );
		$this->assertSame( $named, $both );
		list( $f07_only ) = $run( 'preview', 'live', Engine::MODE_VISITOR );
		$this->assertSame( $html, $f07_only, 'F05 is not analysed for visitors while in Preview, so the icon stays.' );
		list( $preview ) = $run( 'preview', 'live', Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( $named, $preview );

		list( $off, $engine ) = $run( 'off', 'live', Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( $html, $off, 'F05 Off: its name is only "would change", so the icon is not hidden.' );
		$this->assertSame(
			array(
				array( 'link-name', 'off', false ),
				array( 'decorative-icons', 'live', false ),
			),
			array_map(
				static function ( $change ) {
					return array( $change['fix'], $change['state'], $change['applied'] );
				},
				$engine->recorder()->changes()
			)
		);
	}

	/**
	 * F05 label sources in order, and what it never touches.
	 */
	public function test_f05_sources() {
		$cases = array(
			'<a href="http://localhost:8730/"><img src="/l.png" alt=""></a>'                  => 'Example Bakery home page',
			'<a href="/"><svg viewBox="0 0 1 1"></svg></a>'                                   => 'Example Bakery home page',
			'<a href="http://www.localhost:8730"><img src="/l.png"></a>'                      => 'Example Bakery home page',
			'<a href="http://localhost:8730/?ref=logo"><img src="/l.png" alt=""></a>'         => null,
			'<a href="http://localhost:8730/shop/"><img src="/l.png" alt=""></a>'             => null,
			'<a href="mailto:hello@example.com?subject=Hi"><i class="x"></i></a>'             => 'Email hello@example.com',
			'<a href="MAILTO:"><i class="x"></i></a>'                                         => 'Email',
			'<a href="tel:+44%2020%201234"><i class="x"></i></a>'                             => 'Call +44 20 1234',
			'<a href="https://m.facebook.com/bakery"><i class="x"></i></a>'                   => 'Facebook',
			'<a href="https://notfacebook.com/bakery"><i class="fa fa-pinterest-p"></i></a>'  => 'Pinterest',
			'<a href="https://youtu.be/abc"></a>'                                             => 'YouTube',
			'<a href="https://example.net/"><span class="dashicons dashicons-search"></span></a>' => 'Search',
			'<a href="https://example.net/"><svg class="icon fa-user"></svg></a>'             => 'My account',
			'<a href="https://www.facebook.com/b" aria-labelledby="gone"></a>'                => 'Facebook',
			'<a href="https://www.facebook.com/b" title="Our page"><i class="x"></i></a>'      => false,
			'<a href="https://www.facebook.com/b">Facebook</a>'                               => false,
			'<a href="https://www.facebook.com/b"><span class="screen-reader-text">Follow</span></a>' => false,
			'<a href="https://www.facebook.com/b" aria-hidden="true"><i class="x"></i></a>'    => false,
			'<a href="https://www.facebook.com/b" hidden><i class="x"></i></a>'               => false,
			'<a name="https://www.facebook.com/b"></a>'                                       => false,
		);
		foreach ( $cases as $html => $label ) {
			list( $output, $engine ) = self::run_content( $html, 'live', Engine::MODE_ADMIN_REPAIRED );
			$aria = self::browser_value( $output, 'a', 'aria-label' );
			$r05  = self::findings( $engine, 'R05' );
			if ( is_string( $label ) ) {
				$this->assertSame( $label, $aria, $html );
				$this->assertSame( array(), $r05, $html );
			} else {
				$this->assertNull( $aria, $html );
				$this->assertCount( null === $label ? 1 : 0, $r05, $html );
			}
		}
	}

	/**
	 * The label maps are filterable; filtered labels are plain text.
	 */
	public function test_f05_maps_are_filterable() {
		$hosts = static function ( $map ) {
			$map['mastodon.social'] = 'Mastodon';
			$map[42]                = 'ignored';
			return $map;
		};
		$icons = static function ( $map ) {
			$map['icon-bread'] = '<b>Bread</b> &amp; rolls';
			return $map;
		};
		add_filter( 'softglaze_a11y_host_label_map', $hosts );
		add_filter( 'softglaze_a11y_icon_label_map', $icons );
		list( $output ) = self::run_content( '<a href="https://mastodon.social/@b"></a><a href="/bread/"><i class="icon-bread"></i></a>' );
		remove_filter( 'softglaze_a11y_host_label_map', $hosts );
		remove_filter( 'softglaze_a11y_icon_label_map', $icons );
		$this->assertStringContainsString( '<a aria-label="Mastodon" href="https://mastodon.social/@b">', $output );
		$this->assertSame( 'Bread & rolls', self::browser_value( substr( $output, strpos( $output, '</a>' ) ), 'a', 'aria-label' ) );
	}

	/**
	 * F05's WooCommerce sources are used only when WooCommerce's functions
	 * exist, and pages that are not set up (home URL fallback) are ignored.
	 */
	public function test_f05_woocommerce_sources() {
		$html = '<a href="http://localhost:8730/basket/"><svg class="x"></svg></a><a href="http://localhost:8730/account/"><svg class="x"></svg></a>';
		// Without WooCommerce, or (once the stand-ins are loaded by an earlier test) without its cart and account pages.
		unset( $GLOBALS['softglaze_a11y_test_wc_urls'] );
		list( $output, $engine ) = self::run_content( $html, 'live', Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( $html, $output, 'Without WooCommerce pages no shop source is used.' );
		$this->assertCount( 2, self::findings( $engine, 'R05' ) );
		if ( ! function_exists( 'wc_get_cart_url' ) ) {
			require_once __DIR__ . '/helpers/woocommerce-url-stubs.php';
		}

		$GLOBALS['softglaze_a11y_test_wc_urls'] = array(
			'cart'      => 'http://localhost:8730/basket/',
			'myaccount' => 'http://localhost:8730/account',
		);
		list( $output ) = self::run_content( $html );
		$this->assertStringContainsString( '<a aria-label="Cart" href="http://localhost:8730/basket/">', $output );
		$this->assertStringContainsString( '<a aria-label="My account" href="http://localhost:8730/account/">', $output );

		$GLOBALS['softglaze_a11y_test_wc_urls'] = array();
		list( $output ) = self::run_content( '<a href="http://localhost:8730/"><i class="fa fa-cog"></i></a>' );
		$this->assertSame( '<a href="http://localhost:8730/"><i class="fa fa-cog"></i></a>', $output, 'An unset cart page falls back to the home URL and is ignored.' );
		unset( $GLOBALS['softglaze_a11y_test_wc_urls'] );
	}

	/**
	 * F06 label sources in order, the value repair, and what it never touches.
	 */
	public function test_f06_sources() {
		$cases = array(
			'<form role="search"><button></button></form>'                                     => array( 'aria-label', 'Search' ),
			'<search><form><button><svg></svg></button></form></search>'                       => array( 'aria-label', 'Search' ),
			'<form><button></button><input type="search" name="q"></form>'                     => array( 'aria-label', 'Search' ),
			'<form><input name="s"><input type="submit" value=""></form>'                       => array( 'value', 'Search' ),
			'<form><input type="submit" value="  "></form>'                                     => array( 'value', 'Submit' ),
			'<button aria-controls="m"></button><nav id="m"></nav>'                             => array( 'aria-label', 'Menu' ),
			'<button aria-controls="x m2"></button><ul id="m2" class="primary-menu"></ul>'      => array( 'aria-label', 'Menu' ),
			'<button aria-controls="p"></button><div id="p" class="panel"></div>'               => null,
			'<button class="ast-mobile-menu-trigger"></button>'                                 => array( 'aria-label', 'Menu' ),
			'<button class="c-hamburger"></button>'                                             => array( 'aria-label', 'Menu' ),
			'<button class="btn-close"></button>'                                               => array( 'aria-label', 'Close' ),
			'<button class="modal__dismiss"></button>'                                          => array( 'aria-label', 'Close' ),
			'<button class="closed"></button>'                                                  => null,
			'<button><i class="fa fa-magnifying-glass"></i></button>'                           => array( 'aria-label', 'Search' ),
			'<div role="button" tabindex="0"><span class="dashicons dashicons-no-alt"></span></div>' => array( 'aria-label', 'Close' ),
			'<input type="button" value="">'                                                    => null,
			'<input type="reset" value="">'                                                     => null,
			'<button>Subscribe</button>'                                                        => false,
			'<input type="submit">'                                                             => false,
			'<input type="image" src="/go.png">'                                                => false,
			'<button aria-hidden="true"></button>'                                              => false,
		);
		foreach ( $cases as $html => $expected ) {
			list( $output, $engine ) = self::run_content( $html, 'live', Engine::MODE_ADMIN_REPAIRED );
			$diff = self::token_diff( $html, $output );
			$r05  = self::findings( $engine, 'R05' );
			if ( is_array( $expected ) ) {
				$this->assertContains( self::diff_line( $html, $expected ), $diff, $html );
				$this->assertSame( array(), $r05, $html );
			} elseif ( null === $expected ) {
				$this->assertSame( array(), $diff, $html );
				$this->assertCount( 1, $r05, $html );
			} else {
				$this->assertSame( array(), $diff, $html );
				$this->assertSame( array(), $r05, $html );
			}
		}
	}

	/**
	 * The token_diff() line expected for a button repair.
	 *
	 * @param string $html     Input.
	 * @param array  $expected Attribute and value.
	 * @return string
	 */
	private static function diff_line( $html, array $expected ) {
		$tag    = false !== strpos( $html, 'role="button"' ) ? 'DIV' : ( 'value' === $expected[0] ? 'INPUT' : 'BUTTON' );
		$before = 'value' === $expected[0] ? ( false !== strpos( $html, 'value="  "' ) ? "'  '" : "''" ) : 'NULL';
		return $tag . '.' . $expected[0] . ': ' . $before . " -> '" . $expected[1] . "'";
	}

	/**
	 * F08 label sources in order, the filterable map, and what it never touches.
	 */
	public function test_f08_sources() {
		$cases = array(
			'<input type="text" name="n" placeholder=" Your  name ">'               => 'Your name',
			'<textarea name="m" placeholder="Message"></textarea>'                  => 'Message',
			'<input type="text" name="s">'                                          => 'Search',
			'<input type="search" name="q">'                                        => 'Search',
			'<input type="email" name="user_email">'                                => 'Email',
			'<input type="text" name="Your-Name">'                                  => 'Name',
			'<input type="tel" name="tel">'                                         => 'Phone',
			'<input type="number" name="qty">'                                      => 'Quantity',
			'<input type="text" name="coupon_code">'                                => 'Coupon code',
			'<input type="text" name="postcode">'                                   => 'Postcode',
			'<input type="text" name="your-message">'                               => 'Message',
			'<select name="size"><option>S</option></select>'                       => null,
			'<input type="checkbox" name="terms">'                                  => null,
			'<input type="text" name="field_3">'                                    => null,
			'<label for="f">Name</label><input id="f" name="n" placeholder="Jo">'   => false,
			'<label>Name <input name="n" placeholder="Jo"></label>'                 => false,
			'<span id="l">Name</span><input name="n" aria-labelledby="l">'          => false,
			'<input name="n" title="Name">'                                         => false,
			'<input name="n" aria-label="Name">'                                    => false,
			'<input type="hidden" name="s">'                                        => false,
			'<input name="s" aria-hidden="true">'                                   => false,
		);
		foreach ( $cases as $html => $label ) {
			list( $output, $engine ) = self::run_content( $html, 'live', Engine::MODE_ADMIN_REPAIRED );
			$tag = false !== strpos( $html, '<textarea' ) ? 'textarea' : ( false !== strpos( $html, '<select' ) ? 'select' : 'input' );
			$r05 = self::findings( $engine, 'R05' );
			if ( is_string( $label ) ) {
				$this->assertSame( $label, self::browser_value( $output, $tag, 'aria-label' ), $html );
				$this->assertSame( array(), $r05, $html );
			} else {
				$this->assertSame( $html, $output, $html );
				$this->assertCount( null === $label ? 1 : 0, $r05, $html );
			}
		}

		$filter = static function ( $map ) {
			$map['field_3'] = 'Order number';
			return $map;
		};
		add_filter( 'softglaze_a11y_field_label_map', $filter );
		list( $output ) = self::run_content( '<input type="text" name="field_3">' );
		remove_filter( 'softglaze_a11y_field_label_map', $filter );
		$this->assertSame( '<input aria-label="Order number" type="text" name="field_3">', $output );
	}

	/**
	 * R06 lists every field without a visible label, also those F08 names
	 * and those with an author's aria-label; a label element or a valid
	 * aria-labelledby is a visible label.
	 */
	public function test_r06() {
		$html = '<form><input name="a" placeholder="A"><input name="b" aria-label="B"><input name="c"><label for="d">D</label><input id="d" name="d"><span id="e-l">E</span><input name="e" aria-labelledby="e-l"><label><input type="checkbox" name="f"> F</label></form>';
		list( , $engine ) = self::run_content( $html, 'off', Engine::MODE_SCAN );
		$this->assertSame( array( 'R06 input[name=a]', 'R06 input[name=b]', 'R06 input[name=c]' ), self::findings( $engine, 'R06' ) );
	}

	/**
	 * F19 in content and page scope: inside an article with a heading only,
	 * never over an aria-label; R07 takes the rest.
	 */
	public function test_f19_reach_and_r07() {
		$html = '<article><h2>Hello <em>world</em></h2><a href="/h">Read More…</a></article>'
			. '<article><a href="/n">Learn more</a></article>'
			. '<article><h2>Bread</h2><a href="/b" aria-label="Read about bread">Read more</a><a href="/c">More</a><a href="/d">Click here</a></article>'
			. '<p><a href="/e">here</a> <a href="/f">More details</a></p>';
		list( $output ) = self::run_content( $html );
		$this->assertStringContainsString( '<a aria-label="Read More…: Hello world" href="/h">', $output );
		$this->assertStringContainsString( '<a aria-label="More: Bread" href="/c">', $output );
		$this->assertStringContainsString( '<a href="/n">Learn more</a>', $output );
		$this->assertStringContainsString( '<a href="/b" aria-label="Read about bread">', $output );

		list( , $engine ) = self::run_content( $html, 'off', Engine::MODE_SCAN );
		$this->assertSame( array( 'R07 a[href=/n]', 'R07 a[href=/d]', 'R07 a[href=/e]' ), self::findings( $engine, 'R07' ) );
	}

	/**
	 * F19 Layer A: the more link of the_content and a theme's excerpt-more link.
	 */
	public function test_f19_more_link_strings() {
		$fix  = new Fix_Read_More_Context();
		$more = ' <a href="http://localhost:8730/hello-world/#more-1" class="more-link">(more&hellip;)</a>';
		$this->assertSame( ' <a aria-label="(more…): Hello world!" href="http://localhost:8730/hello-world/#more-1" class="more-link">(more&hellip;)</a>', $fix->label_more_links( $more, 'Hello world!' ) );
		$this->assertSame( "… <a aria-label=\"Continue reading: Tom &amp; Jerry\u{2019}s cake\" href=\"/p/\">Continue reading</a>", $fix->label_more_links( '… <a href="/p/">Continue reading</a>', 'Tom &amp; Jerry&#8217;s cake' ) );

		$unchanged = array(
			' [&hellip;]',
			'<a href="/p/">Continue reading<span class="screen-reader-text"> Hello world!</span></a>',
			'<a href="/p/" aria-label="Read the post">Read more</a>',
			'<a href="/p/"><img src="/r.png" alt="">Read more</a>',
			'<a>Read more</a>',
			'<a href="/p/">Read more',
		);
		foreach ( $unchanged as $html ) {
			$this->assertSame( $html, $fix->label_more_links( $html, 'Hello world!' ), $html );
		}
		$this->assertSame( $more, $fix->label_more_links( $more, '  ' ), 'No title, no label.' );
	}

	/**
	 * F21: appended to an existing description, skipped where the wording or
	 * the note is already there; on a whole page only when the note exists,
	 * in a scan always, in fragments while the note can still be printed.
	 */
	public function test_f21() {
		$note  = '<span id="softglaze-a11y-newtab-note" hidden>opens in a new tab</span>';
		$links = '<a href="https://a.example/" target="_blank" aria-describedby="x  y">A</a>'
			. '<a href="https://b.example/" target="_blank">B, new window</a>'
			. '<a href="https://c.example/" target="_blank" aria-describedby="softglaze-a11y-newtab-note">C</a>'
			. '<a href="https://d.example/" target="_blank" title="Opens in a new tab">D</a>'
			. '<a href="https://e.example/" target="_self">E</a>';
		$page   = '<!DOCTYPE html><html><body>' . $links . '%s</body></html>';
		$fix    = new Fix_New_Tab_Note();
		$engine = new Engine( array( $fix ), array( 'new-tab-note' => 'live' ) );

		$with = $engine->run( sprintf( $page, $note ), Engine::MODE_VISITOR, Engine::SCOPE_PAGE );
		$this->assertStringContainsString( '<a href="https://a.example/" target="_blank" aria-describedby="x y softglaze-a11y-newtab-note">', $with );
		$this->assertSame( 1, $engine->recorder()->counts()['new-tab-note']['live'] );

		$without = sprintf( $page, '' );
		$this->assertSame( $without, $engine->run( $without, Engine::MODE_VISITOR, Engine::SCOPE_PAGE ), 'No note on the page, no reference to it.' );
		$engine->run( $without, Engine::MODE_SCAN, Engine::SCOPE_PAGE );
		$this->assertCount( 1, $engine->recorder()->changes(), 'A scan shows what would change.' );

		$this->assertStringContainsString( 'aria-describedby="x y softglaze-a11y-newtab-note"', $engine->run( $links, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$fix->set_note_available( false );
		$this->assertSame( $links, $engine->run( $links, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertSame( 'opens in a new tab', Fix_New_Tab_Note::note_text() );
	}

	/**
	 * The new fixes take part in the engine's mode rules: Off is "would change" in admin views.
	 */
	public function test_new_fixes_off_are_would_change() {
		$html = '<a href="https://www.facebook.com/b"></a><button class="btn-close"></button><input name="s"><article><h2>T</h2><a href="/r">Read more</a></article>';
		list( $output, $engine ) = self::run_content( $html, 'off', Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( $html, $output );
		$fixes = array();
		foreach ( $engine->recorder()->changes() as $change ) {
			$fixes[] = $change['fix'] . ':' . ( $change['applied'] ? 'applied' : 'off' );
		}
		$this->assertSame( array( 'link-name:off', 'button-name:off', 'field-label:off', 'read-more-context:off' ), $fixes );
		$this->assertInstanceOf( Fix_Button_Name::class, self::bundled_fixes()[5] );
		$this->assertInstanceOf( Fix_Field_Label::class, self::bundled_fixes()[7] );
	}
}
