<?php
/**
 * R01 text contrast (Phase 7A): the WCAG relative-luminance formula on known
 * pairs, colour and style parsing, the declared-pair rules (inline colours,
 * preset classes resolved through the palette, the large-text threshold),
 * what is left to a browser, the modes it runs in, and the palette read from
 * the global settings.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Checks\Check_Contrast;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Contrast check.
 */
class Test_Check_Contrast extends Softglaze_A11y_Engine_TestCase {

	/**
	 * Presets injected instead of the global settings.
	 */
	const PRESETS = array(
		'colors' => array(
			'white'    => '#ffffff',
			'black'    => '#000000',
			'grey'     => '#777777',
			'pale'     => '#f78da7',
			'accent-6' => 'color-mix(in srgb, currentColor 20%, transparent)',
		),
		'sizes'  => array(
			'small'   => 13,
			'large'   => 36,
			'x-large' => 42,
			'fluid'   => null,
		),
	);

	/**
	 * A check with the test presets.
	 *
	 * @return Check_Contrast
	 */
	private static function check() {
		return new Check_Contrast( self::PRESETS );
	}

	/**
	 * Findings of a scan of a fragment with only the contrast check.
	 *
	 * @param string              $html  Fragment.
	 * @param Check_Contrast|null $check Check, the test presets by default.
	 * @param string              $mode  Engine mode.
	 * @return array[]
	 */
	private static function findings( $html, $check = null, $mode = Engine::MODE_SCAN ) {
		$engine = new Engine(
			array( new Fix_Iframe_Title() ),
			array( 'iframe-title' => 'off' ),
			array(
				'checks' => array( null === $check ? self::check() : $check ),
				'max_ms' => 10000,
			)
		);
		$output = $engine->run( $html, $mode, Engine::SCOPE_CONTENT );
		self::assertSame( $html, $output, 'The contrast check never changes markup.' );
		$found = array();
		foreach ( $engine->recorder()->findings() as $finding ) {
			if ( 'R01' === $finding['check'] ) {
				$found[] = $finding;
			}
		}
		return $found;
	}

	/**
	 * Known pairs computed with the WCAG 2 relative-luminance formula.
	 */
	public function test_ratio_of_known_pairs() {
		$white = array( 255, 255, 255 );
		$this->assertEqualsWithDelta( 21.0, Check_Contrast::ratio( array( 0, 0, 0 ), $white ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, Check_Contrast::ratio( $white, $white ), 0.0001 );
		$this->assertEqualsWithDelta( 0.0, Check_Contrast::luminance( array( 0, 0, 0 ) ), 0.0001 );
		$this->assertEqualsWithDelta( 1.0, Check_Contrast::luminance( $white ), 0.0001 );

		// #777 on #fff: 4.48:1, below 4.5:1 for normal text, above 3:1 for large text.
		$grey = Check_Contrast::ratio( array( 0x77, 0x77, 0x77 ), $white );
		$this->assertSame( 4.48, round( $grey, 2 ) );
		$this->assertLessThan( 4.5, $grey );
		$this->assertGreaterThan( 3.0, $grey );

		// The order of the colours does not matter.
		$this->assertEqualsWithDelta( $grey, Check_Contrast::ratio( $white, array( 0x77, 0x77, 0x77 ) ), 0.0000001 );

		$this->assertSame( 4.54, round( Check_Contrast::ratio( array( 0x76, 0x76, 0x76 ), $white ), 2 ), '#767676 is the lightest grey that passes 4.5:1 on white.' );
		$this->assertSame( 3.03, round( Check_Contrast::ratio( array( 0x94, 0x94, 0x94 ), $white ), 2 ), '#949494 passes 3:1 on white.' );
		$this->assertLessThan( 3.0, Check_Contrast::ratio( array( 0x96, 0x96, 0x96 ), $white ), '#969696 fails 3:1 on white.' );
		$this->assertSame( 4.0, round( Check_Contrast::ratio( array( 255, 0, 0 ), $white ), 2 ), 'Pure red on white (3.998:1).' );
		$this->assertLessThan( 4.5, Check_Contrast::ratio( array( 255, 0, 0 ), $white ) );
		$this->assertSame( 5.25, round( Check_Contrast::ratio( array( 255, 0, 0 ), array( 0, 0, 0 ) ), 2 ), 'Pure red on black.' );
		$this->assertSame( 8.59, round( Check_Contrast::ratio( array( 0, 0, 255 ), $white ), 2 ), 'Pure blue on white.' );
	}

	/**
	 * Opaque colours parse; transparency, keywords and unknown functions do not.
	 */
	public function test_colour_parsing() {
		$cases = array(
			'#fff'                    => array( 255, 255, 255 ),
			'#ffff'                   => array( 255, 255, 255 ),
			'#777777'                 => array( 119, 119, 119 ),
			'#777777ff'               => array( 119, 119, 119 ),
			'red'                     => array( 255, 0, 0 ),
			'rebeccapurple'           => array( 102, 51, 153 ),
			'rgb(255, 0, 0)'          => array( 255, 0, 0 ),
			'rgb(100% 0% 0%)'         => array( 255, 0, 0 ),
			'rgb(0 128 0 / 1)'        => array( 0, 128, 0 ),
			'rgba(0, 0, 255, 100%)'   => array( 0, 0, 255 ),
			'hsl(0, 100%, 50%)'       => array( 255, 0, 0 ),
			'hsl(120deg 100% 25%)'    => array( 0, 128, 0 ),
			'hsl(0.5turn, 100%, 50%)' => array( 0, 255, 255 ),
			'#fff8'                   => null,
			'#00000080'               => null,
			'rgba(0, 0, 0, 0.5)'      => null,
			'rgb(0 0 0 / 50%)'        => null,
			'hsla(0, 100%, 50%, .2)'  => null,
			'transparent'             => null,
			'currentcolor'            => null,
			'inherit'                 => null,
			'var(--text)'             => null,
			'color-mix(in srgb, red 50%, blue)' => null,
			'#ggg'                    => null,
			'rgb(1, 2)'               => null,
			''                        => null,
		);
		foreach ( $cases as $value => $expected ) {
			$this->assertSame( $expected, Check_Contrast::parse_plain_color( (string) $value ), (string) $value );
		}

		$check = self::check();
		$this->assertSame( array( 119, 119, 119 ), $check->parse_color( 'var(--wp--preset--color--grey)' ) );
		$this->assertSame( array( 255, 0, 0 ), $check->parse_color( ' RED !important' ) );
		$this->assertNull( $check->parse_color( 'var(--wp--preset--color--unknown)' ) );
		$this->assertNull( $check->parse_color( 'var(--wp--preset--color--accent-6)' ), 'A preset that is not a plain colour is not known.' );
	}

	/**
	 * Style attributes are split outside quotes and parentheses; the last declaration wins.
	 */
	public function test_declarations() {
		$this->assertSame(
			array(
				'background' => 'url("a;b.png") #000',
				'font-size'  => '24px',
				'color'      => '#111',
			),
			Check_Contrast::declarations( 'color: #fff; background:url("a;b.png") #000 ;FONT-SIZE:24px;color:#111;broken;:x' )
		);
		$this->assertSame( array(), Check_Contrast::declarations( '' ) );
		$this->assertSame( 24.0, Check_Contrast::font_size_px( '24px' ) );
		$this->assertSame( 24.0, Check_Contrast::font_size_px( '1.5rem' ) );
		$this->assertEqualsWithDelta( 18.6667, Check_Contrast::font_size_px( '14pt' ), 0.001 );
		$this->assertNull( Check_Contrast::font_size_px( '1.5em' ), 'em depends on the parent.' );
		$this->assertNull( Check_Contrast::font_size_px( 'clamp(1rem, 2vw, 2rem)' ) );
		$this->assertNull( Check_Contrast::font_size_px( 'large' ) );
		$this->assertTrue( Check_Contrast::is_bold( '700' ) );
		$this->assertTrue( Check_Contrast::is_bold( 'bold' ) );
		$this->assertFalse( Check_Contrast::is_bold( '600' ) );
		$this->assertFalse( Check_Contrast::is_bold( 'normal' ) );
	}

	/**
	 * Which elements have a declared pair, and what is known about their size.
	 */
	public function test_declared_pairs() {
		$check = self::check();
		$grey  = array( 119, 119, 119 );
		$white = array( 255, 255, 255 );
		$black = array( 0, 0, 0 );

		$pair = $check->declared_pair( 'has-grey-color has-white-background-color has-text-color has-background', '' );
		$this->assertSame( array( 'color' => $grey, 'background' => $white, 'large' => null ), $pair, 'Preset classes, size unknown.' );

		$this->assertSame( array( 'color' => $grey, 'background' => $white, 'large' => null ), $check->declared_pair( 'has-text-color has-background', 'color:#777;background-color:#fff' ), 'Inline colours.' );
		$this->assertSame( array( 'color' => $grey, 'background' => $black, 'large' => null ), $check->declared_pair( 'has-black-background-color', 'color:#777777' ), 'A class background with an inline text colour.' );
		$this->assertSame( $grey, $check->declared_pair( 'has-grey-color', 'color:#000;background:#fff' )['color'], 'A preset class (!important in core) wins over an inline colour.' );
		$this->assertSame( $black, $check->declared_pair( '', 'color:#777;background:#fff;background-color:#000' )['background'], 'The later background declaration wins.' );
		$this->assertSame( $white, $check->declared_pair( '', 'color:#777;background-color:#000;background:#fff' )['background'] );
		$this->assertSame( $white, $check->declared_pair( '', 'color:#777;background:white none repeat' )['background'], 'A colour inside the shorthand.' );
		$this->assertSame( $white, $check->declared_pair( '', 'color:var(--wp--preset--color--grey);background-color:var(--wp--preset--color--white)' )['background'] );

		$this->assertTrue( $check->declared_pair( 'has-large-font-size', 'color:#777;background:#fff' )['large'], 'Preset font size of 36px.' );
		$this->assertFalse( $check->declared_pair( 'has-small-font-size', 'color:#777;background:#fff' )['large'] );
		$this->assertNull( $check->declared_pair( 'has-fluid-font-size', 'color:#777;background:#fff' )['large'], 'A fluid size without a minimum is not known.' );
		$this->assertTrue( $check->declared_pair( '', 'color:#777;background:#fff;font-size:24px' )['large'] );
		$this->assertTrue( $check->declared_pair( '', 'color:#777;background:#fff;font-size:19px;font-weight:700' )['large'], '14pt and bold.' );
		$this->assertFalse( $check->declared_pair( '', 'color:#777;background:#fff;font-size:18px;font-weight:bold' )['large'], 'Bold, but under 14pt.' );
		$this->assertTrue( $check->declared_pair( 'has-large-font-size', 'color:#777;background:#fff;font-size:16px' )['large'], 'A preset size class (!important in core) wins over an inline size.' );

		$not_checked = array(
			array( 'has-grey-color', '' ),
			array( 'has-white-background-color', '' ),
			array( '', 'color:#777' ),
			array( '', 'background:#fff' ),
			array( '', 'color:#777;background:url(x.png) #fff' ),
			array( '', 'color:#777;background-color:#fff;background-image:linear-gradient(#000,#fff)' ),
			array( 'has-grey-color has-vivid-gradient-background', 'background-color:#fff' ),
			array( '', 'color:#777;background:#fff;opacity:.5' ),
			array( '', 'color:#777;background:#fff;mix-blend-mode:multiply' ),
			array( '', 'color:rgba(0,0,0,.5);background:#fff' ),
			array( '', 'color:currentColor;background:#fff' ),
			array( '', 'color:var(--text);background:#fff' ),
			array( 'has-accent-6-color has-white-background-color', '' ),
			array( 'has-unknown-color has-white-background-color', '' ),
			array( '', 'color:#777;background-color:#fff;background:none' ),
			array( '', 'color:#777;background:transparent' ),
		);
		foreach ( $not_checked as $case ) {
			$this->assertNull( $check->declared_pair( $case[0], $case[1] ), $case[0] . ' | ' . $case[1] );
		}
	}

	/**
	 * Each failing pair is listed with its ratio and threshold; passing pairs and everything else are not.
	 */
	public function test_findings_in_a_scan() {
		$html = '<div class="has-grey-color has-white-background-color has-text-color has-background"><p>Unknown size</p></div>'
			. '<p style="color:#777;background-color:#fff;font-size:16px">Normal size</p>'
			. '<h2 style="color:#999;background:#fff;font-size:30px">Large and failing</h2>'
			. '<h2 style="color:#777;background:#fff;font-size:30px">Large and passing</h2>'
			. '<p style="color:#999;background:#fff">Fails both</p>'
			. '<p class="has-black-color has-white-background-color">Passes</p>'
			. '<p style="color:#777">Only a text colour</p>'
			. '<hr class="has-grey-color has-white-background-color" style="color:#eee;background-color:#eee">'
			. '<p hidden style="color:#eee;background:#fff">Hidden</p>'
			. '<input type="checkbox" style="color:#eee;background:#fff">'
			. '<input type="text" value="Typed" style="color:#eee;background:#fff">';
		$found = self::findings( $html );
		$this->assertSame( array( 'R01' ), array_values( array_unique( array_column( $found, 'check' ) ) ) );
		$this->assertSame( array( '1.4.3' ), array_values( array_unique( array_column( $found, 'sc' ) ) ) );
		$this->assertSame(
			array(
				'Contrast 4.48:1 (#777777 / #ffffff) is below 4.5:1 for normal text; large text needs 3:1, and no size is set here.',
				'Contrast 4.48:1 (#777777 / #ffffff) is below 4.5:1 for normal-size text.',
				'Contrast 2.85:1 (#999999 / #ffffff) is below 3:1 for large text.',
				'Contrast 2.85:1 (#999999 / #ffffff) is below 4.5:1 for normal text and 3:1 for large text.',
				'Contrast 1.16:1 (#eeeeee / #ffffff) is below 4.5:1 for normal text and 3:1 for large text.',
			),
			array_column( $found, 'detail' )
		);
		$this->assertSame( 'div.has-grey-color.has-white-background-color.has-text-color', $found[0]['sig'], 'Signatures keep the classes, never the style values.' );
		$this->assertSame( 'input[type=text]', $found[4]['sig'] );
	}

	/**
	 * A failing ratio is never shown rounded up to its threshold.
	 */
	public function test_ratio_shown_never_reaches_the_threshold() {
		// #6d6d6d on #efefef is 4.49995:1 and #959595 on #fff 2.9954:1: rounding would show 4.50 and 3.00.
		$found = self::findings( '<p style="color:#6d6d6d;background:#efefef;font-size:16px">x</p><p style="color:#959595;background:#fff;font-size:32px">y</p>' );
		$this->assertCount( 2, $found );
		$this->assertStringStartsWith( 'Contrast 4.49:1', $found[0]['detail'] );
		$this->assertStringStartsWith( 'Contrast 2.99:1', $found[1]['detail'] );
	}

	/**
	 * Report only: the check runs in scans and admin views, never for visitors.
	 */
	public function test_modes() {
		$html = '<p style="color:#999;background:#fff">x</p>';
		$this->assertCount( 1, self::findings( $html, null, Engine::MODE_SCAN ) );
		$this->assertCount( 1, self::findings( $html, null, Engine::MODE_ADMIN_REPAIRED ) );
		$this->assertCount( 0, self::findings( $html, null, Engine::MODE_VISITOR ) );
		$this->assertCount( 0, self::findings( $html, null, Engine::MODE_ADMIN_ORIGINAL ) );
		$this->assertCount( 1, self::findings( '<div><section><span style="color:#999;background:#fff">y</span></section></div>' ), 'Any element can carry the pair.' );
	}

	/**
	 * The registry lists R01 first among the checks.
	 */
	public function test_registered() {
		$checks = \SoftGlaze\AccessibilityRepair\Plugin::instance()->registry()->checks();
		$this->assertInstanceOf( Check_Contrast::class, $checks[0] );
		$this->assertSame( 'R01', $checks[0]->id() );
		$this->assertSame( '1.4.3', $checks[0]->wcag() );
	}

	/**
	 * Without injected presets the palette and font sizes come from the global
	 * settings: custom over theme over default, fluid sizes only with a minimum.
	 */
	public function test_palette_from_global_settings() {
		$add = static function ( $theme_json ) {
			return $theme_json->update_with(
				array(
					'version'  => 2,
					'settings' => array(
						'color'      => array(
							'palette' => array(
								array(
									'slug'  => 'sgtest-grey',
									'color' => '#777777',
									'name'  => 'Test grey',
								),
								array(
									'slug'  => 'sgtest-paper',
									'color' => '#FFFFFF',
									'name'  => 'Test paper',
								),
								array(
									'slug'  => 'sgtestMixed1',
									'color' => '#999999',
									'name'  => 'Camel case slug',
								),
							),
						),
						'typography' => array(
							'fontSizes' => array(
								array(
									'slug'  => 'sgtest-huge',
									'size'  => '30px',
									'name'  => 'Huge',
									'fluid' => false,
								),
								array(
									'slug'  => 'sgtest-fluid',
									'size'  => '2rem',
									'name'  => 'Fluid with a minimum',
									'fluid' => array(
										'min' => '1.5rem',
										'max' => '3rem',
									),
								),
							),
						),
					),
				)
			);
		};
		add_filter( 'wp_theme_json_data_theme', $add );
		wp_clean_theme_json_cache();
		try {
			$found = self::findings(
				'<p class="has-sgtest-grey-color has-sgtest-paper-background-color">a</p>'
				. '<p class="has-sgtest-grey-color has-sgtest-paper-background-color has-sgtest-huge-font-size">b</p>'
				. '<p class="has-sgtest-mixed-1-color has-sgtest-paper-background-color has-sgtest-fluid-font-size">c</p>',
				new Check_Contrast()
			);
		} finally {
			remove_filter( 'wp_theme_json_data_theme', $add );
			wp_clean_theme_json_cache();
		}
		$this->assertSame(
			array(
				'Contrast 4.48:1 (#777777 / #ffffff) is below 4.5:1 for normal text; large text needs 3:1, and no size is set here.',
				'Contrast 2.85:1 (#999999 / #ffffff) is below 3:1 for large text.',
			),
			array_column( $found, 'detail' ),
			'The 30px preset makes the second paragraph large; the fluid preset counts with its 24px minimum.'
		);
	}
}
