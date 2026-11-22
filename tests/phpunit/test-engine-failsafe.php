<?php
/**
 * Fail-safe suite FS-01 to FS-13 (docs/05-TEST-PLAN.md section 4): on every
 * error, guard or mismatch the engine returns its input byte for byte.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Engine;

require_once __DIR__ . '/helpers/class-engine-test-case.php';

/**
 * Engine fail-safe tests.
 */
class Test_Engine_Failsafe extends Softglaze_A11y_Engine_TestCase {

	/**
	 * A small page with one fixable iframe.
	 */
	const PAGE = '<!DOCTYPE html><html lang="en-US"><head><title>T</title></head><body><p>Hi</p><iframe src="https://www.youtube.com/embed/abc"></iframe></body></html>';

	/**
	 * FS-01: a fix throws RuntimeException during analyse.
	 */
	public function test_fs01_runtime_exception_returns_original() {
		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Throws( 'runtime' ) ) );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE ) );
		$this->assertSame( 'exception', $engine->recorder()->get_fallback() );
		$this->assertSame( 'RuntimeException', $engine->recorder()->get_fallback_detail() );
	}

	/**
	 * FS-02: a fix triggers TypeError, or Error.
	 */
	public function test_fs02_type_error_and_error_return_original() {
		foreach ( array(
			'type'  => 'TypeError',
			'error' => 'Error',
		) as $kind => $class ) {
			$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Throws( $kind ) ) );
			$this->assertSame( self::PAGE, $engine->run( self::PAGE ), $kind );
			$this->assertSame( 'exception', $engine->recorder()->get_fallback(), $kind );
			$this->assertSame( $class, $engine->recorder()->get_fallback_detail(), $kind );
		}
	}

	/**
	 * FS-03: softglaze_a11y_max_ms filtered to 0.
	 */
	public function test_fs03_time_guard_returns_original() {
		$html = self::read( self::fixture_files()['a11y-fx-block-editor'] );
		add_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		$engine = self::engine();
		$this->assertSame( $html, $engine->run( $html ) );
		$this->assertSame( 'time', $engine->recorder()->get_fallback() );
		$this->assertSame( array(), $engine->recorder()->changes() );

		remove_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		$this->assertNotSame( $html, $engine->run( $html ), 'Without the filter the page is repaired.' );
		$this->assertNull( $engine->recorder()->get_fallback() );
	}

	/**
	 * FS-03, small input: the clock is also read after the last token.
	 */
	public function test_fs03_time_guard_applies_to_short_input() {
		add_filter( 'softglaze_a11y_max_ms', '__return_zero' );
		$engine = self::engine();
		$this->assertSame( self::PAGE, $engine->run( self::PAGE ) );
		$this->assertSame( 'time', $engine->recorder()->get_fallback() );
	}

	/**
	 * FS-04: softglaze_a11y_max_bytes below the page size.
	 */
	public function test_fs04_size_guard_returns_original() {
		$limit = static function () {
			return 100;
		};
		add_filter( 'softglaze_a11y_max_bytes', $limit );
		$engine = self::engine();
		$this->assertGreaterThan( 100, strlen( self::PAGE ) );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE ) );
		$this->assertSame( 'size', $engine->recorder()->get_fallback() );
		remove_filter( 'softglaze_a11y_max_bytes', $limit );

		$this->assertNotSame( self::PAGE, $engine->run( self::PAGE ) );
	}

	/**
	 * FS-05: output whose count of < or > changed.
	 */
	public function test_fs05_sanity_check_returns_original() {
		foreach ( array( '<', '>' ) as $suffix ) {
			$engine                = self::engine( 'live', array(), Softglaze_A11y_Test_Engine::class );
			$engine->apply_factory = static function ( $html ) use ( $suffix ) {
				$processor         = new Softglaze_A11y_Test_Tampering_Processor( $html );
				$processor->suffix = $suffix;
				return $processor;
			};
			$this->assertSame( self::PAGE, $engine->run( self::PAGE ), $suffix );
			$this->assertSame( 'sanity', $engine->recorder()->get_fallback(), $suffix );
			$this->assertSame( array( 'analyse', 'apply' ), $engine->passes );
		}
	}

	/**
	 * FS-05, real case: replacing an existing value that holds a raw ">" changes the count.
	 */
	public function test_fs05_replacing_value_with_raw_gt_falls_back() {
		$html   = '<p title="a>b">x</p><iframe src="https://vimeo.com/1"></iframe>';
		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Title_All( true ) ) );
		$this->assertSame( $html, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ) );
		$this->assertSame( 'sanity', $engine->recorder()->get_fallback() );
	}

	/**
	 * FS-06: input truncated mid-tag or mid-attribute.
	 */
	public function test_fs06_truncated_input_returns_original() {
		$cases = array(
			'mid-attribute' => '<p>a</p><iframe src="https://player.vimeo.com/video/1"></iframe><div class="ab',
			'mid-tag'       => '<p>a</p><iframe src="https://player.vimeo.com/video/1"></iframe><div',
			'mid-comment'   => '<p>a</p><iframe src="https://player.vimeo.com/video/1"></iframe><!-- open',
			'mid-iframe'    => '<p>a</p><iframe src="https://player.vimeo.com/video/1">',
		);
		foreach ( $cases as $label => $html ) {
			$engine = self::engine();
			$this->assertSame( $html, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ), $label );
			$this->assertSame( 'incomplete', $engine->recorder()->get_fallback(), $label );
		}
	}

	/**
	 * FS-07: malformed but common markup. Edits land only on real elements;
	 * nothing inside script, style, comments or textarea changes; both passes
	 * count the same openers.
	 */
	public function test_fs07_malformed_markup() {
		$html = implode(
			"\n",
			array(
				'<!DOCTYPE html>',
				'<html><head><title>Bakery <b> & co</title>',
				'<style>iframe[src*="<iframe"] { color: red; } /* <iframe src="https://vimeo.com/2"></iframe> */</style>',
				'<script>var s = "<a href=\'/x\'>x</a><iframe src=\'https://vimeo.com/3\'></iframe>"; if (a < b && c > d) {}</script>',
				'<!--[if lt IE 9]><iframe src="https://vimeo.com/4"></iframe><![endif]-->',
				'<!-- <iframe src="https://vimeo.com/5"></iframe> -->',
				'</head><body>',
				'<p class=unquoted id=dup id=dup2>Stray < sign and 3 > 2</br></p></p>',
				'<form><form><input name=q></form></form>',
				'<template><iframe src="https://vimeo.com/6"></iframe></template>',
				'<svg viewBox="0 0 1 1"><title>Logo</title><path d="M0 0"/></svg>',
				'<textarea><iframe src="https://vimeo.com/7"></iframe></textarea>',
				'<div data-x="a>b" title=existing>raw gt in another attribute</div>',
				'<iframe src=https://player.vimeo.com/video/8 width=1></iframe>',
				'<iframe src="https://player.vimeo.com/video/9" title=""></iframe>',
				'</body></html>',
			)
		);

		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Title_All() ) );
		$output = $engine->run( $html );
		$facts  = $engine->recorder()->engine();

		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertNotSame( $html, $output );
		$this->assertSame( $facts['openers'], $facts['openers_apply'], 'Opener counts of both passes are equal.' );
		$this->assertSame( self::opener_count( $html ), $facts['openers'] );
		$this->assertSame( substr_count( $html, '<' ), substr_count( $output, '<' ) );
		$this->assertSame( substr_count( $html, '>' ), substr_count( $output, '>' ) );

		foreach ( array(
			'iframe[src*="<iframe"] { color: red; } /* <iframe src="https://vimeo.com/2"></iframe> */</style>',
			'"<a href=\'/x\'>x</a><iframe src=\'https://vimeo.com/3\'></iframe>"; if (a < b && c > d) {}</script>',
			'<!--[if lt IE 9]><iframe src="https://vimeo.com/4"></iframe><![endif]-->',
			'<!-- <iframe src="https://vimeo.com/5"></iframe> -->',
			'><iframe src="https://vimeo.com/7"></iframe></textarea>',
			'Bakery <b> & co</title>',
		) as $untouched ) {
			$this->assertStringContainsString( $untouched, $output );
		}

		foreach ( self::token_diff( $html, $output ) as $line ) {
			$this->assertMatchesRegularExpression( '/^([A-Z0-9]+\.title|HTML\.lang): /', $line, 'Only title attributes and the html lang change.' );
		}
		$this->assertStringContainsString( '<iframe title="Vimeo video" src=https://player.vimeo.com/video/8 width=1>', $output );
		$this->assertStringContainsString( '<iframe src="https://player.vimeo.com/video/9" title="Vimeo video">', $output );
		$this->assertStringContainsString( '<iframe title="Embedded content from vimeo.com" src="https://vimeo.com/6">', $output, 'Template contents are markup and are repaired.' );
	}

	/**
	 * FS-07, version difference: a stray </br> is an opener from 6.7 and a closer before; both passes agree either way.
	 */
	public function test_fs07_stray_br_keeps_ordinals_aligned() {
		$html   = '<p>a</br>b</p><iframe src="https://w.soundcloud.com/player/"></iframe></br><iframe src="https://open.spotify.com/embed/x"></iframe>';
		$engine = self::engine( 'live', array( new Softglaze_A11y_Test_Fix_Title_All( true ) ) );
		$output = $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT );
		$facts  = $engine->recorder()->engine();

		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( $facts['openers'], $facts['openers_apply'] );
		$this->assertSame( version_compare( get_bloginfo( 'version' ), '6.7', '>=' ) ? 5 : 3, $facts['openers'] );
		$this->assertStringContainsString( '<iframe title="SoundCloud player" src="https://w.soundcloud.com/player/">', $output );
		$this->assertStringContainsString( '<iframe title="Spotify player" src="https://open.spotify.com/embed/x">', $output );
	}

	/**
	 * FS-08: invalid UTF-8 bytes and binary junk.
	 */
	public function test_fs08_invalid_utf8_returns_original() {
		$cases = array(
			'invalid sequence' => '<p>caf' . "\xC3\x28" . '</p><iframe src="https://youtube.com/embed/x"></iframe>',
			'binary junk'      => "\x00\xFF\xFE<iframe src=\"https://youtube.com/embed/x\">\x81\x00</iframe>\x9F",
		);
		foreach ( $cases as $label => $html ) {
			$engine = self::engine();
			$this->assertSame( $html, $engine->run( $html, Engine::MODE_VISITOR, Engine::SCOPE_CONTENT ), $label );
			$this->assertSame( 'encoding', $engine->recorder()->get_fallback(), $label );
		}
	}

	/**
	 * FS-09: empty string.
	 */
	public function test_fs09_empty_string() {
		$engine = self::engine();
		$this->assertSame( '', $engine->run( '' ) );
		$this->assertNull( $engine->recorder()->get_fallback() );
	}

	/**
	 * FS-10: core passes only text/html and application/xhtml+xml responses to
	 * the template enhancement filter, so a JSON template response never
	 * reaches a callback. The plugin's own Layer C callback: test-front-end.php.
	 */
	public function test_fs10_json_response_never_reaches_the_filter() {
		if ( ! function_exists( 'wp_finalize_template_enhancement_output_buffer' ) ) {
			$this->markTestSkipped( 'The template enhancement output buffer exists from WordPress 6.9.' );
		}

		$calls    = 0;
		$engine   = self::engine();
		$callback = static function ( $filtered_output ) use ( &$calls, $engine ) {
			++$calls;
			return $engine->run( $filtered_output );
		};
		add_filter( 'wp_template_enhancement_output_buffer', $callback, 20, 2 );

		$mimetype = ini_get( 'default_mimetype' );
		ini_set( 'default_mimetype', 'application/json' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only: headers_list() is empty on the CLI, so core reads the default type.
		$json   = '{"html":"<iframe src=\"https://youtube.com/embed/x\"></iframe>"}';
		$result = wp_finalize_template_enhancement_output_buffer( $json, PHP_OUTPUT_HANDLER_FINAL );
		$this->assertSame( $json, $result );
		$this->assertSame( 0, $calls );

		ini_set( 'default_mimetype', 'text/html' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only.
		$result = wp_finalize_template_enhancement_output_buffer( self::PAGE, PHP_OUTPUT_HANDLER_FINAL );
		ini_set( 'default_mimetype', (string) $mimetype ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only.
		remove_filter( 'wp_template_enhancement_output_buffer', $callback, 20 );

		$this->assertSame( 1, $calls );
		$this->assertStringContainsString( '<iframe title="YouTube video"', $result );
	}

	/**
	 * FS-11: the apply pass counts a different number of openers.
	 */
	public function test_fs11_opener_count_mismatch_returns_original() {
		$doctored = array(
			'extra opener at the end'   => static function ( $html ) {
				return new WP_HTML_Tag_Processor( $html . '<i>' );
			},
			'extra opener at the start' => static function ( $html ) {
				return new WP_HTML_Tag_Processor( '<b>' . $html );
			},
		);
		foreach ( $doctored as $label => $factory ) {
			$engine                = self::engine( 'live', array(), Softglaze_A11y_Test_Engine::class );
			$engine->apply_factory = $factory;
			$this->assertSame( self::PAGE, $engine->run( self::PAGE ), $label );
			$this->assertSame( 'ordinal', $engine->recorder()->get_fallback(), $label );
		}
	}

	/**
	 * FS-12: nothing to fix returns the same string and never runs the apply pass.
	 */
	public function test_fs12_nothing_to_fix_skips_apply_pass() {
		$html   = '<html lang="en"><head><meta name="viewport" content="width=device-width"></head><body><iframe title="Map of the shop" src="https://maps.google.com/x"></iframe></body></html>';
		$engine = self::engine( 'live', array(), Softglaze_A11y_Test_Engine::class );
		$this->assertSame( $html, $engine->run( $html ) );
		$this->assertSame( array( 'analyse' ), $engine->passes );
		$this->assertNull( $engine->recorder()->get_fallback() );
		$this->assertSame( 0, $engine->recorder()->engine()['edits_planned'] );
	}

	/**
	 * FS-12, no fix active in the mode: the input is returned without parsing.
	 */
	public function test_fs12_no_active_fix_skips_both_passes() {
		$engine = self::engine( 'preview', array(), Softglaze_A11y_Test_Engine::class );
		$this->assertSame( self::PAGE, $engine->run( self::PAGE, Engine::MODE_VISITOR ) );
		$this->assertSame( array(), $engine->passes );

		$engine->run( self::PAGE, Engine::MODE_ADMIN_ORIGINAL );
		$this->assertSame( array(), $engine->passes );
	}

	/**
	 * FS-13: engine( engine( x ) ) === engine( x ) on every fixture, in visitor and admin_repaired modes.
	 *
	 * @dataProvider data_fixtures
	 *
	 * @param string $slug Fixture slug.
	 * @param string $path Fixture path.
	 */
	public function test_fs13_idempotent_on_fixtures( $slug, $path ) {
		$html = self::read( $path );
		foreach ( array(
			Engine::MODE_VISITOR        => 'live',
			Engine::MODE_ADMIN_REPAIRED => 'preview',
		) as $mode => $state ) {
			$engine = self::engine( $state );
			$once   = $engine->run( $html, $mode );
			$this->assertNull( $engine->recorder()->get_fallback(), "$slug $mode first run" );
			$twice = $engine->run( $once, $mode );
			$this->assertNull( $engine->recorder()->get_fallback(), "$slug $mode second run" );
			$this->assertSame( $once, $twice, "$slug $mode" );
			$this->assertSame( 0, $engine->recorder()->engine()['edits_planned'], "$slug $mode plans nothing on its own output" );
		}
	}
}
