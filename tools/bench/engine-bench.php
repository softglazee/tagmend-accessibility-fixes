<?php
/**
 * Engine benchmark (docs/05-TEST-PLAN.md section 6, blueprint section 13).
 *
 * Usage (wp eval-file takes positional arguments):
 *   wp eval-file engine-bench.php <fixtures-dir> [runs=50] [plugin-dir]
 *
 * <fixtures-dir>  folder with the captured fixture pages (*.html)
 * [runs]          timed runs per case (3 untimed warm-up runs come first)
 * [plugin-dir]    a copy of the plugin folder, loaded when the plugin is not
 *                 active on the site (the WordPress 6.5 floor site)
 *
 * Cases: every fixture as a whole page and its <main> element as a content
 * fragment, a token-dense synthetic page of about 200 KB (the <main> elements
 * of the four Phase 0 fixtures concatenated, as in every phase since 1A), the
 * same page with nothing left to fix (analyse pass only), a realistic 200 KB
 * page (the WooCommerce product page with the Elementor page's body added,
 * when both captures are present), and a 1 MB page for peak memory, with
 * every bundled fix Live. Then the 200 KB pages with only the recommended set
 * Live, the block-editor page with a cold Media Library lookup on every run
 * (a new engine and the runtime cache flushed, so the batched query is paid
 * each time, as on an uncached request), and the block-editor page in scan
 * mode (every fix analysed, report checks on).
 * Prints bytes, p50, p95, max in ms and the peak memory added per case.
 *
 * @package SoftGlaze\AccessibilityRepair\Tools
 */

use SoftGlaze\AccessibilityRepair\Checks\Check_Alt_Quality;
use SoftGlaze\AccessibilityRepair\Checks\Check_Ambiguous_Links;
use SoftGlaze\AccessibilityRepair\Checks\Check_Contrast;
use SoftGlaze\AccessibilityRepair\Checks\Check_Autoplay_Media;
use SoftGlaze\AccessibilityRepair\Checks\Check_Duplicate_Ids;
use SoftGlaze\AccessibilityRepair\Checks\Check_Field_Visible_Label;
use SoftGlaze\AccessibilityRepair\Checks\Check_Headings;
use SoftGlaze\AccessibilityRepair\Checks\Check_Landmarks;
use SoftGlaze\AccessibilityRepair\Checks\Check_Non_Native_Controls;
use SoftGlaze\AccessibilityRepair\Checks\Check_Page_Title;
use SoftGlaze\AccessibilityRepair\Checks\Check_Tables;
use SoftGlaze\AccessibilityRepair\Checks\Check_Undescribed_Images;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Aria_Hidden_Focus;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Button_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Decorative_Icons;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Duplicate_Field_Ids;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Empty_Heading;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Field_Label;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Focus_Visible;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Hidden_Duplicate_Links;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Html_Lang;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_Alt_Empty_Library;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_Alt_Library;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Img_In_Named_Control;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Link_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Nav_Labels;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_New_Tab_Note;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Positive_Tabindex;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Product_Img_Alt_Name;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Read_More_Context;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Skip_Link_Target;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Viewport_Zoom;

$sgb_dir    = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
$sgb_runs   = isset( $args[1] ) ? max( 5, (int) $args[1] ) : 50;
$sgb_plugin = isset( $args[2] ) ? rtrim( $args[2], '/' ) : '';

if ( '' === $sgb_dir || ! is_dir( $sgb_dir ) ) {
	WP_CLI::error( 'Usage: wp eval-file engine-bench.php <fixtures-dir> [runs] [plugin-dir]' );
}

if ( ! class_exists( Engine::class ) ) {
	if ( '' === $sgb_plugin || ! is_dir( $sgb_plugin . '/includes' ) ) {
		WP_CLI::error( 'The plugin is not active here; pass a copy of the plugin folder as the third argument.' );
	}
	spl_autoload_register(
		static function ( $class_name ) use ( $sgb_plugin ) {
			$prefix = 'SoftGlaze\\AccessibilityRepair\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
			$class = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
			$path  = $sgb_plugin . '/includes/';
			foreach ( $parts as $part ) {
				$path .= strtolower( str_replace( '_', '-', $part ) ) . '/';
			}
			foreach ( array( 'class-', 'interface-' ) as $file_prefix ) {
				if ( is_readable( $path . $file_prefix . $class . '.php' ) ) {
					require_once $path . $file_prefix . $class . '.php';
					return;
				}
			}
		}
	);
}

/**
 * The <main> element of a page, or the body when there is none.
 *
 * @param string $html Page.
 * @return string
 */
function sgb_main( $html ) {
	$start = strpos( $html, '<main' );
	$end   = strrpos( $html, '</main>' );
	if ( false === $start || false === $end ) {
		$start = (int) strpos( $html, '<body' );
		$end   = (int) strrpos( $html, '</body>' );
		return substr( $html, $start, $end - $start + 7 );
	}
	return substr( $html, $start, $end - $start + 7 );
}

/**
 * A page of at least $bytes: the first page with other pages' main elements inserted before </body>.
 *
 * @param string   $page   Base page.
 * @param string[] $bodies Fragments to insert.
 * @param int      $bytes  Target size.
 * @return string
 */
function sgb_synthetic( $page, array $bodies, $bytes ) {
	$insert = '';
	$i      = 0;
	while ( strlen( $page ) + strlen( $insert ) < $bytes ) {
		$insert .= $bodies[ $i % count( $bodies ) ] . "\n";
		++$i;
	}
	return str_replace( '</body>', $insert . '</body>', $page );
}

/**
 * Percentile of a sorted list.
 *
 * @param float[] $sorted Sorted values.
 * @param float   $p      0..1.
 * @return float
 */
function sgb_percentile( array $sorted, $p ) {
	$index = (int) ceil( $p * count( $sorted ) ) - 1;
	return $sorted[ max( 0, min( count( $sorted ) - 1, $index ) ) ];
}

/**
 * Times one case.
 *
 * @param Engine|callable $engine Engine, or a factory called before every run (cold cases).
 * @param string          $html   Input.
 * @param string          $scope  Scope.
 * @param int             $runs   Timed runs.
 * @param string          $mode   Engine mode.
 * @return array
 */
function sgb_case( $engine, $html, $scope, $runs, $mode = Engine::MODE_VISITOR ) {
	$factory = is_callable( $engine ) ? $engine : null;
	for ( $i = 0; $i < 3; $i++ ) {
		$engine = null === $factory ? $engine : $factory();
		$engine->run( $html, $mode, $scope );
	}
	$times = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$engine  = null === $factory ? $engine : $factory();
		$start   = hrtime( true );
		$output  = $engine->run( $html, $mode, $scope );
		$times[] = ( hrtime( true ) - $start ) / 1e6;
	}
	sort( $times );

	$peak = null;
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		$base = memory_get_usage();
		memory_reset_peak_usage();
		$engine->run( $html, $mode, $scope );
		$peak = ( memory_get_peak_usage() - $base ) / 1048576;
	}

	$facts = $engine->recorder()->engine();
	return array(
		'bytes'    => strlen( $html ),
		'tokens'   => $facts['tokens'],
		'edits'    => $facts['edits_applied'],
		'fallback' => $engine->recorder()->get_fallback(),
		'changed'  => $output !== $html,
		'p50'      => sgb_percentile( $times, 0.50 ),
		'p95'      => sgb_percentile( $times, 0.95 ),
		'max'      => end( $times ),
		'peak_mb'  => $peak,
	);
}

$sgb_fixes  = array(
	new Fix_Img_Alt_Library(),
	new Fix_Img_Alt_Empty_Library(),
	new Fix_Img_In_Named_Control(),
	new Fix_Product_Img_Alt_Name(),
	new Fix_Link_Name(),
	new Fix_Button_Name(),
	new Fix_Decorative_Icons(),
	new Fix_Field_Label(),
	new Fix_Iframe_Title(),
	new Fix_Html_Lang(),
	new Fix_Skip_Link(),
	new Fix_Skip_Link_Target(),
	new Fix_Viewport_Zoom(),
	new Fix_Positive_Tabindex(),
	new Fix_Hidden_Duplicate_Links(),
	new Fix_Aria_Hidden_Focus(),
	new Fix_Duplicate_Field_Ids(),
	new Fix_Nav_Labels(),
	new Fix_Read_More_Context(),
	new Fix_Empty_Heading(),
	new Fix_New_Tab_Note(),
	new Fix_Focus_Visible(),
);
$sgb_checks = array( new Check_Contrast(), new Check_Headings(), new Check_Alt_Quality(), new Check_Undescribed_Images(), new Check_Field_Visible_Label(), new Check_Ambiguous_Links(), new Check_Landmarks(), new Check_Page_Title(), new Check_Autoplay_Media(), new Check_Duplicate_Ids(), new Check_Non_Native_Controls(), new Check_Tables() );
$sgb_states = array();
foreach ( $sgb_fixes as $sgb_fix ) {
	$sgb_states[ $sgb_fix->id() ] = 'live';
}
$sgb_recommended = array();
foreach ( Engine::default_states( $sgb_fixes ) as $sgb_id => $sgb_state ) {
	$sgb_recommended[ $sgb_id ] = 'preview' === $sgb_state ? 'live' : 'off';
}
$sgb_engine = new Engine(
	$sgb_fixes,
	$sgb_states,
	array(
		'max_ms' => 10000,
		'checks' => $sgb_checks,
	)
);

$sgb_pages = array();
foreach ( glob( $sgb_dir . '/*.html' ) as $sgb_path ) {
	$sgb_pages[ basename( $sgb_path, '.html' ) ] = file_get_contents( $sgb_path );
}
ksort( $sgb_pages );
if ( empty( $sgb_pages['a11y-fx-block-editor'] ) ) {
	WP_CLI::error( 'a11y-fx-block-editor.html is missing from ' . $sgb_dir );
}

// The dense page keeps the composition of every earlier phase: the four Phase 0 fixtures only.
$sgb_dense_slugs = array( 'a11y-fx-block-editor', 'a11y-fx-forms', 'a11y-fx-structure', 'home' );
$sgb_dense_pages = array_intersect_key( $sgb_pages, array_flip( $sgb_dense_slugs ) );
$sgb_bodies      = array_values( array_map( 'sgb_main', $sgb_dense_pages ) );
$sgb_cases  = array();
foreach ( $sgb_pages as $sgb_slug => $sgb_html ) {
	$sgb_cases[] = array( $sgb_slug, 'page', $sgb_html );
}
foreach ( $sgb_pages as $sgb_slug => $sgb_html ) {
	$sgb_cases[] = array( $sgb_slug . ' <main>', 'content', sgb_main( $sgb_html ) );
}
$sgb_200     = sgb_synthetic( $sgb_pages['a11y-fx-block-editor'], $sgb_bodies, 200 * 1024 );
$sgb_others  = array_values( array_diff_key( $sgb_dense_pages, array( 'a11y-fx-block-editor' => true ) ) );
$sgb_cases[] = array( 'synthetic 200 KB', 'page', $sgb_200 );
$sgb_cases[] = array( 'synthetic 200 KB, fixture density', 'page', sgb_synthetic( $sgb_pages['a11y-fx-block-editor'], $sgb_others, 200 * 1024 ) );
$sgb_200_clean = $sgb_engine->run( $sgb_200, Engine::MODE_VISITOR, 'page' );
$sgb_cases[]   = array( 'synthetic 200 KB, nothing to fix', 'page', $sgb_200_clean );
$sgb_cases[]   = array( 'synthetic 1 MB', 'page', sgb_synthetic( $sgb_pages['a11y-fx-block-editor'], $sgb_bodies, 1024 * 1024 ) );
$sgb_cases[]   = array( 'synthetic 200 KB, recommended set', 'page', $sgb_200, new Engine( $sgb_fixes, $sgb_recommended, array( 'max_ms' => 10000 ) ) );
if ( isset( $sgb_pages['a11y-fx-product'], $sgb_pages['a11y-fx-elementor'] ) ) {
	// A real WooCommerce product page with a real Elementor page body added before </body>.
	$sgb_el_html = $sgb_pages['a11y-fx-elementor'];
	$sgb_el_from = (int) strpos( $sgb_el_html, '>', (int) strpos( $sgb_el_html, '<body' ) ) + 1;
	$sgb_el_body = substr( $sgb_el_html, $sgb_el_from, (int) strrpos( $sgb_el_html, '</body>' ) - $sgb_el_from );
	$sgb_real    = sgb_synthetic( $sgb_pages['a11y-fx-product'], array( $sgb_el_body ), 200 * 1024 );
	$sgb_cases[] = array( 'realistic 200 KB (product+Elementor)', 'page', $sgb_real );
	$sgb_cases[] = array( 'realistic 200 KB, recommended set', 'page', $sgb_real, new Engine( $sgb_fixes, $sgb_recommended, array( 'max_ms' => 10000 ) ) );
}
$sgb_cases[]   = array(
	'block-editor, cold library lookup',
	'page',
	$sgb_pages['a11y-fx-block-editor'],
	static function () use ( $sgb_fixes, $sgb_states ) {
		wp_cache_flush_runtime();
		return new Engine( $sgb_fixes, $sgb_states, array( 'max_ms' => 10000 ) );
	},
);
$sgb_cases[]   = array( 'block-editor, scan mode + checks', 'page', $sgb_pages['a11y-fx-block-editor'], $sgb_engine, Engine::MODE_SCAN );

WP_CLI::line( sprintf( 'Engine bench: PHP %s, WordPress %s, opcache %s, %d runs per case (3 warm-up runs), every bundled fix Live, visitor mode unless the case says otherwise', PHP_VERSION, get_bloginfo( 'version' ), ( function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ) ) ? 'on' : 'off', $sgb_runs ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Bench tool, not shipped.
WP_CLI::line( sprintf( '%-38s %-7s %8s %6s %5s %8s %8s %8s %8s  %s', 'case', 'scope', 'bytes', 'tokens', 'edits', 'p50 ms', 'p95 ms', 'max ms', 'peak MB', 'result' ) );

$sgb_results = array();
foreach ( $sgb_cases as $sgb_case ) {
	list( $sgb_label, $sgb_scope, $sgb_html ) = $sgb_case;
	$sgb_r                     = sgb_case( isset( $sgb_case[3] ) ? $sgb_case[3] : $sgb_engine, $sgb_html, $sgb_scope, $sgb_runs, isset( $sgb_case[4] ) ? $sgb_case[4] : Engine::MODE_VISITOR );
	$sgb_results[ $sgb_label ] = $sgb_r;
	WP_CLI::line(
		sprintf(
			'%-38s %-7s %8d %6d %5d %8.2f %8.2f %8.2f %8s  %s',
			$sgb_label,
			$sgb_scope,
			$sgb_r['bytes'],
			$sgb_r['tokens'],
			$sgb_r['edits'],
			$sgb_r['p50'],
			$sgb_r['p95'],
			$sgb_r['max'],
			null === $sgb_r['peak_mb'] ? 'n/a' : sprintf( '%.2f', $sgb_r['peak_mb'] ),
			null !== $sgb_r['fallback'] ? 'FALLBACK ' . $sgb_r['fallback'] : ( $sgb_r['changed'] ? 'repaired' : 'unchanged' )
		)
	);
}

// Reference: one bare next_token() loop over the dense 200 KB page, the floor of each engine pass.
$sgb_raw = array();
for ( $sgb_i = 0; $sgb_i < $sgb_runs; $sgb_i++ ) {
	$sgb_start = hrtime( true );
	$sgb_p     = new WP_HTML_Tag_Processor( $sgb_200 );
	while ( $sgb_p->next_token() ) {
		if ( '#tag' === $sgb_p->get_token_type() ) {
			$sgb_p->is_tag_closer();
		}
	}
	$sgb_raw[] = ( hrtime( true ) - $sgb_start ) / 1e6;
}
sort( $sgb_raw );
WP_CLI::line( sprintf( '%-38s %-7s %8d %6s %5s %8.2f %8.2f %8.2f %8s  %s', 'bare next_token() loop, 200 KB', 'page', strlen( $sgb_200 ), '', '', sgb_percentile( $sgb_raw, 0.5 ), sgb_percentile( $sgb_raw, 0.95 ), end( $sgb_raw ), '', 'one pass, reference' ) );

$sgb_full  = $sgb_results['synthetic 200 KB'];
$sgb_empty = $sgb_results['synthetic 200 KB, nothing to fix'];
WP_CLI::line( '' );
WP_CLI::line( sprintf( 'Budget, 200 KB page: p50 %.2f ms (<= 10), p95 %.2f ms (<= 25): %s', $sgb_full['p50'], $sgb_full['p95'], ( $sgb_full['p50'] <= 10 && $sgb_full['p95'] <= 25 ) ? 'within' : 'OVER' ) );
$sgb_content_p95 = 0.0;
foreach ( $sgb_results as $sgb_label => $sgb_r ) {
	if ( false !== strpos( $sgb_label, '<main>' ) ) {
		$sgb_content_p95 = max( $sgb_content_p95, $sgb_r['p95'] );
	}
}
WP_CLI::line( sprintf( 'Budget, content fragments: worst p95 %.2f ms (<= 5): %s', $sgb_content_p95, $sgb_content_p95 <= 5 ? 'within' : 'OVER' ) );
WP_CLI::line( sprintf( 'Budget, nothing to fix vs full on the 200 KB page: %.0f%% (<= 60%%): %s', 100 * $sgb_empty['p50'] / $sgb_full['p50'], $sgb_empty['p50'] <= 0.6 * $sgb_full['p50'] ? 'within' : 'OVER' ) );
$sgb_mb = $sgb_results['synthetic 1 MB']['peak_mb'];
WP_CLI::line( null === $sgb_mb ? 'Budget, peak memory on a 1 MB page: not measured (memory_reset_peak_usage() needs PHP 8.2)' : sprintf( 'Budget, peak memory on a 1 MB page: %.2f MB (<= 8): %s', $sgb_mb, $sgb_mb <= 8 ? 'within' : 'OVER' ) );
