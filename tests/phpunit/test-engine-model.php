<?php
/**
 * Engine model tests: modes and states, the plan's rules, the analyse-pass
 * stack, signatures, and the detection rules of F09, F10 and F13.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Html_Lang;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Viewport_Zoom;
use SoftGlaze\AccessibilityRepair\Plan;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Engine model tests.
 */
class Test_Engine_Model extends Softglaze_A11y_Engine_TestCase {

	/**
	 * A page each bundled fix changes once.
	 */
	const PAGE = '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, user-scalable=0"></head><body><iframe src="https://www.youtube.com/embed/x"></iframe></body></html>';

	/**
	 * The fix classes implement the interface and carry the catalogue metadata.
	 */
	public function test_fix_metadata() {
		// Scopes, axe rules, requirements, risk, recommended, WCAG: blueprint section 6.
		$expected = array(
			'img-alt-library'       => array( array( 'content', 'page' ), array( 'image-alt' ), array(), 'low', true, array( '1.1.1' ) ),
			'img-alt-empty-library' => array( array( 'content', 'page' ), array(), array(), 'medium', false, array( '1.1.1' ) ),
			'img-in-named-control'  => array( array( 'content', 'page' ), array( 'image-alt', 'image-redundant-alt' ), array(), 'low', true, array( '1.1.1' ) ),
			'product-img-alt-name'  => array( array( 'attribute' ), array( 'image-alt' ), array( 'woocommerce' ), 'medium', false, array( '1.1.1' ) ),
			'link-name'             => array( array( 'content', 'page' ), array( 'link-name' ), array(), 'medium', true, array( '2.4.4', '4.1.2' ) ),
			'button-name'           => array( array( 'content', 'page' ), array( 'button-name', 'input-button-name' ), array(), 'medium', true, array( '4.1.2' ) ),
			'decorative-icons'      => array( array( 'content', 'page' ), array( 'svg-img-alt' ), array(), 'low', true, array( '1.1.1' ) ),
			'field-label'           => array( array( 'content', 'page' ), array( 'label', 'select-name' ), array(), 'low', true, array( '1.3.1', '4.1.2' ) ),
			'iframe-title'          => array( array( 'content', 'page' ), array( 'frame-title' ), array(), 'low', true, array( '4.1.2' ) ),
			'html-lang'             => array( array( 'attribute', 'page' ), array( 'html-has-lang', 'html-lang-valid' ), array(), 'low', true, array( '3.1.1' ) ),
			'skip-link'             => array( array( 'attribute', 'page' ), array( 'bypass', 'skip-link' ), array( 'wp69' ), 'medium', true, array( '2.4.1' ) ),
			'skip-link-target'      => array( array( 'page' ), array( 'skip-link' ), array( 'wp69' ), 'low', true, array( '2.4.1' ) ),
			'viewport-zoom'         => array( array( 'page' ), array( 'meta-viewport' ), array( 'wp69' ), 'low', true, array( '1.4.4' ) ),
			'positive-tabindex'     => array( array( 'content', 'page' ), array( 'tabindex' ), array(), 'low', true, array( '2.4.3' ) ),
			'hidden-duplicate-links' => array( array( 'content', 'page' ), array( 'aria-hidden-focus' ), array(), 'low', true, array( '4.1.2' ) ),
			'aria-hidden-focus'     => array( array( 'content', 'page' ), array( 'aria-hidden-focus' ), array(), 'high', false, array( '4.1.2' ) ),
			'duplicate-field-ids'   => array( array( 'page' ), array( 'duplicate-id-aria', 'label' ), array( 'wp69' ), 'high', false, array( '1.3.1', '4.1.2' ) ),
			'nav-labels'            => array( array( 'attribute' ), array( 'landmark-unique' ), array(), 'low', true, array( '1.3.1', '2.4.1' ) ),
			'read-more-context'     => array( array( 'attribute', 'content', 'page' ), array(), array(), 'low', true, array( '2.4.4' ) ),
			'empty-heading'         => array( array( 'content', 'page' ), array( 'empty-heading' ), array(), 'low', true, array( '1.3.1', '2.4.6' ) ),
			'new-tab-note'          => array( array( 'content', 'page' ), array(), array(), 'low', false, array( '3.2.5' ) ),
			'focus-visible'         => array( array(), array(), array(), 'medium', false, array( '2.4.7' ) ),
		);
		$ids = array();
		foreach ( self::bundled_fixes() as $fix ) {
			$ids[] = $fix->id();
			$this->assertInstanceOf( Fix::class, $fix );
			$this->assertInstanceOf( Fix_Base::class, $fix );
			$this->assertArrayHasKey( $fix->id(), $expected );
			$this->assertSame( $expected[ $fix->id() ][0], $fix->scopes(), $fix->id() );
			$this->assertSame( $expected[ $fix->id() ][1], $fix->axe_rules(), $fix->id() );
			$this->assertSame( $expected[ $fix->id() ][2], $fix->requires(), $fix->id() );
			$this->assertSame( $expected[ $fix->id() ][3], $fix->risk(), $fix->id() );
			$this->assertSame( $expected[ $fix->id() ][4], $fix->recommended(), $fix->id() );
			$this->assertSame( $expected[ $fix->id() ][5], $fix->wcag(), $fix->id() );
			$this->assertNotSame( '', $fix->label() );
		}
		$this->assertSame( array_keys( $expected ), $ids );
		$this->assertSame(
			array(
				'img-alt-library'       => 'preview',
				'img-alt-empty-library' => 'off',
				'img-in-named-control'  => 'preview',
				'product-img-alt-name'  => 'off',
				'link-name'             => 'preview',
				'button-name'           => 'preview',
				'decorative-icons'      => 'preview',
				'field-label'           => 'preview',
				'iframe-title'          => 'preview',
				'html-lang'             => 'preview',
				'skip-link'             => 'preview',
				'skip-link-target'      => 'preview',
				'viewport-zoom'         => 'preview',
				'positive-tabindex'     => 'preview',
				'hidden-duplicate-links' => 'preview',
				'aria-hidden-focus'     => 'off',
				'duplicate-field-ids'   => 'off',
				'nav-labels'            => 'preview',
				'read-more-context'     => 'preview',
				'empty-heading'         => 'preview',
				'new-tab-note'          => 'off',
				'focus-visible'         => 'off',
			),
			Engine::default_states( self::bundled_fixes() )
		);
	}

	/**
	 * Visitor mode applies Live fixes only; Preview and Off are not even analysed.
	 */
	public function test_visitor_mode_applies_live_only() {
		$engine = new Engine(
			self::bundled_fixes(),
			array(
				'iframe-title'  => 'live',
				'html-lang'     => 'preview',
				'viewport-zoom' => 'off',
			)
		);
		$output = $engine->run( self::PAGE, Engine::MODE_VISITOR );
		$this->assertSame( array( "IFRAME.title: NULL -> 'YouTube video'" ), self::token_diff( self::PAGE, $output ) );
		$this->assertFalse( $engine->recorder()->is_recording() );
		$this->assertSame( array(), $engine->recorder()->changes(), 'Visitor runs keep no details.' );
		$this->assertSame( array( 'live' => 1, 'preview' => 0, 'off' => 0 ), $engine->recorder()->counts()['iframe-title'] );
	}

	/**
	 * Admin repaired applies Live and Preview and lists Off as "would change".
	 */
	public function test_admin_repaired_applies_live_and_preview() {
		$engine = new Engine(
			self::bundled_fixes(),
			array(
				'iframe-title'  => 'live',
				'html-lang'     => 'preview',
				'viewport-zoom' => 'off',
			)
		);
		$output = $engine->run( self::PAGE, Engine::MODE_ADMIN_REPAIRED );
		$this->assertSame( array( "HTML.lang: NULL -> 'en-US'", "IFRAME.title: NULL -> 'YouTube video'" ), self::token_diff( self::PAGE, $output ) );

		$applied = array();
		foreach ( $engine->recorder()->changes() as $change ) {
			$applied[ $change['fix'] ] = array( $change['state'], $change['applied'] );
		}
		$this->assertSame(
			array(
				'html-lang'     => array( 'preview', true ),
				'viewport-zoom' => array( 'off', false ),
				'iframe-title'  => array( 'live', true ),
			),
			$applied
		);
	}

	/**
	 * Admin original changes nothing and records nothing; unknown modes and scopes do the same.
	 */
	public function test_admin_original_and_unknown_arguments_return_input() {
		$engine = self::engine( 'live' );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE, Engine::MODE_ADMIN_ORIGINAL ) );
		$this->assertSame( array(), $engine->recorder()->changes() );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE, 'everything' ) );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE, Engine::MODE_VISITOR, 'attribute' ) );
	}

	/**
	 * Missing or unknown states count as Off.
	 */
	public function test_unknown_states_are_off() {
		$engine = new Engine( self::bundled_fixes(), array( 'iframe-title' => 'LIVE' ) );
		$this->assertSame( 'off', $engine->state_of( 'iframe-title' ) );
		$this->assertSame( 'off', $engine->state_of( 'html-lang' ) );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE, Engine::MODE_VISITOR ) );
	}

	/**
	 * The plan refuses attributes outside its allow-list and stray br tags.
	 */
	public function test_plan_refuses_unsafe_attributes() {
		$plan = new Plan();
		foreach ( array( 'onclick', 'style', 'src', 'srcdoc', 'data-x', 'formaction' ) as $attribute ) {
			$this->assertFalse( $plan->add( 0, 'A', $attribute, 'x', 'test' ), $attribute );
		}
		$this->assertFalse( $plan->add( 0, 'BR', 'title', 'x', 'test' ) );
		$this->assertFalse( $plan->add( -1, 'A', 'title', 'x', 'test' ) );
		$this->assertTrue( $plan->is_empty() );
		$this->assertTrue( $plan->add( 3, 'A', 'ARIA-LABEL', 'x', 'test' ) );
		$this->assertTrue( $plan->has( 3, 'aria-label' ) );
	}

	/**
	 * When fixes propose the same attribute, the lower priority number wins.
	 */
	public function test_priority_resolves_conflicts() {
		$html   = '<iframe src="https://player.vimeo.com/video/1"></iframe>';
		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Title_All() ) );
		$this->assertSame( '<iframe title="Vimeo video" src="https://player.vimeo.com/video/1"></iframe>', $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertSame( 2, $engine->recorder()->engine()['edits_planned'] );
		$this->assertSame( 1, $engine->recorder()->engine()['edits_applied'] );
	}

	/**
	 * The stack collects link text, image alt and SVG titles, follows aria-hidden
	 * containers through untracked same-name children, and knows the form.
	 */
	public function test_stack_and_text_collection() {
		$spy  = new Softglaze_A11y_Test_Fix_Spy();
		$html = '<form><a href="/a">Order <img src="x.jpg" alt="a loaf"> <svg><title>now</title><path/></svg></a></form>'
			. '<div aria-hidden="true"><div>inner</div><a href="/b">Hidden &amp; focusable</a></div>'
			. '<a href="/c"><span>Link <a href="/d">nested</a></span></a>'
			. '<h2>Heading <h3>implicit</h3></h2>'
			. '<a href="/e">unclosed';
		$engine = new Engine( array( $spy ), array( 'test-spy' => 'live' ) );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_SCAN, Engine::SCOPE_CONTENT ) );
		$this->assertNull( $engine->recorder()->get_fallback() );

		$this->assertSame(
			array(
				array( 'A', 'Order a loaf now', false, true ),
				array( 'FORM', '', false, false ),
				array( 'A', 'Hidden & focusable', true, false ),
				array( 'A', 'Link', false, false ),
				array( 'A', 'nested', false, false ),
				array( 'H2', 'Heading', false, false ),
				array( 'A', 'unclosed', false, false ),
			),
			array_map(
				static function ( $seen ) {
					return array( $seen['tag'], $seen['text'], $seen['hidden'], $seen['in_form'] );
				},
				$spy->seen
			)
		);
	}

	/**
	 * Signatures keep tag, id, classes, role, type, name and link path only.
	 */
	public function test_signatures_are_privacy_stripped() {
		$html   = '<iframe id="tour" class="a b c d" name="player" data-user="jane@example.org" value="secret" src="https://www.youtube.com/embed/x?token=abc&user=jane#t"></iframe>';
		$engine = self::engine( 'preview' );
		$engine->run( $html, Engine::MODE_SCAN, Engine::SCOPE_CONTENT );
		$changes = $engine->recorder()->changes();
		$this->assertSame( 'iframe#tour.a.b.c[name=player][src=/embed/x]', $changes[0]['sig'] );
		$this->assertStringNotContainsString( 'jane', wp_json_encode( $engine->recorder()->to_array() ) );
		$this->assertStringNotContainsString( 'token=abc', wp_json_encode( $engine->recorder()->to_array() ) );
	}

	/**
	 * F09 host map and fallbacks; iframes with a name are left alone.
	 */
	public function test_f09_labels() {
		$fix   = new Fix_Iframe_Title();
		$cases = array(
			'https://www.youtube.com/embed/x'              => 'YouTube video',
			'//www.youtube-nocookie.com/embed/x'           => 'YouTube video',
			'https://m.youtube.com/embed/x'                => 'YouTube video',
			'https://player.vimeo.com/video/1'             => 'Vimeo video',
			'https://www.google.com/maps/embed?pb=1'       => 'Map',
			'https://maps.google.com/maps?q=x'             => 'Map',
			'https://www.google.com/search?q=maps'         => 'Embedded content from www.google.com',
			'https://open.spotify.com/embed/track/1'       => 'Spotify player',
			'https://w.soundcloud.com/player/?url=x'       => 'SoundCloud player',
			'https://calendly.com/bakery/30min'            => 'Calendly booking',
			'https://notyoutube.com/embed'                 => 'Embedded content from notyoutube.com',
			'https://Example.ORG/widget'                   => 'Embedded content from example.org',
			'/local/page'                                  => 'Embedded content',
			''                                             => 'Embedded content',
			'about:blank'                                  => 'Embedded content',
			'javascript:alert(1)'                          => 'Embedded content',
		);
		foreach ( $cases as $src => $label ) {
			$this->assertSame( $label, $fix->label_for( $src ), $src );
		}

		$named = '<iframe title="Shop map" src="https://maps.google.com/x"></iframe><iframe aria-label="Tour" src="https://youtube.com/embed/x"></iframe><iframe aria-labelledby="h" src="https://youtube.com/embed/x"></iframe>';
		$this->assertSame( $named, self::engine( 'live' )->run( $named, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );

		$blank = '<iframe title="  " src="https://youtube.com/embed/x"></iframe><iframe title src="https://youtube.com/embed/x"></iframe>';
		$this->assertSame(
			'<iframe title="YouTube video" src="https://youtube.com/embed/x"></iframe><iframe title="YouTube video" src="https://youtube.com/embed/x"></iframe>',
			self::engine( 'live' )->run( $blank, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT )
		);
	}

	/**
	 * F10: missing, empty and underscore values are repaired; valid values stay.
	 */
	public function test_f10_values() {
		$fix = new Fix_Html_Lang();
		$this->assertSame( 'en-US', get_bloginfo( 'language' ) );
		$this->assertSame( 'en-US', $fix->repaired_value( null ) );
		$this->assertSame( 'en-US', $fix->repaired_value( '' ) );
		$this->assertSame( 'en-US', $fix->repaired_value( '   ' ) );
		$this->assertSame( 'en-US', $fix->repaired_value( true ) );
		$this->assertSame( 'en-GB', $fix->repaired_value( 'en_GB' ) );
		$this->assertSame( 'de-DE-formal', $fix->repaired_value( 'de_DE_formal' ) );
		$this->assertSame( 'en-US', $fix->repaired_value( 'english!' ) );
		$this->assertNull( $fix->repaired_value( 'fr' ) );
		$this->assertNull( $fix->repaired_value( 'pt-BR' ) );
		$this->assertNull( $fix->repaired_value( 'zh-Hant-TW' ) );

		$engine = self::engine( 'live' );
		$this->assertSame( '<html lang="fr"><body></body></html>', $engine->run( '<html lang="fr"><body></body></html>' ) );
		$this->assertSame( '<html lang="en-GB"><body></body></html>', $engine->run( '<html lang="en_GB"><body></body></html>' ) );
		$this->assertSame( '<html lang="en-US" class="x"><body></body></html>', $engine->run( '<html class="x"><body></body></html>' ) );
	}

	/**
	 * F13: zoom-blocking keys are removed, everything else kept in order.
	 */
	public function test_f13_content() {
		$fix   = new Fix_Viewport_Zoom();
		$cases = array(
			'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no' => 'width=device-width, initial-scale=1',
			'width=device-width,initial-scale=1,maximum-scale=1.0'                  => 'width=device-width, initial-scale=1',
			'user-scalable=0, width=device-width'                                  => 'width=device-width',
			'width=device-width; user-scalable=NO; viewport-fit=cover'             => 'width=device-width, viewport-fit=cover',
			'maximum-scale=1.9'                                                    => '',
			'user-scalable=no'                                                     => '',
		);
		foreach ( $cases as $content => $repaired ) {
			$this->assertSame( $repaired, $fix->repaired_content( $content ), $content );
		}
		foreach ( array( 'width=device-width, initial-scale=1', 'width=device-width, maximum-scale=2', 'user-scalable=yes, maximum-scale=5', 'maximum-scale=device', '' ) as $fine ) {
			$this->assertNull( $fix->repaired_content( $fine ), $fine );
		}

		$engine = self::engine( 'live' );
		$html   = '<html lang="en"><head><meta name="Viewport" content="width=device-width, user-scalable=no"><meta name="description" content="user-scalable=no"></head></html>';
		$this->assertSame( '<html lang="en"><head><meta name="Viewport" content="width=device-width"><meta name="description" content="user-scalable=no"></head></html>', $engine->run( $html ) );
	}
}
