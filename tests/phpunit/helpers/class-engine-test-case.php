<?php
/**
 * Shared helpers for the engine suites.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Checks\Check_Alt_Quality;
use SoftGlaze\AccessibilityRepair\Checks\Check_Contrast;
use SoftGlaze\AccessibilityRepair\Checks\Check_Ambiguous_Links;
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

require_once __DIR__ . '/class-engine-test-doubles.php';

/**
 * Base class: fixture access, engine factories and comparison helpers.
 */
abstract class Softglaze_A11y_Engine_TestCase extends WP_UnitTestCase {

	/**
	 * The dev site's home URL and name (tests/fixtures/staging-pages.md), which
	 * the captured fixtures link to and F05 names home links after.
	 */
	const DEV_HOME = 'http://localhost:8730';
	const DEV_NAME = 'Example Bakery';

	/**
	 * Answers home_url() and the site name as the dev site does, so golden
	 * files made there match (filters are restored after each test).
	 */
	public function set_up() {
		parent::set_up();
		self::add_time_headroom();
		add_filter(
			'pre_option_home',
			static function () {
				return self::DEV_HOME;
			}
		);
		add_filter(
			'pre_option_blogname',
			static function () {
				return self::DEV_NAME;
			}
		);
	}

	/**
	 * Raises the engine's time guard to 10 s for this test, at priority 1, so
	 * host load cannot make a test that is not about the guard fall back. The
	 * time-guard tests filter softglaze_a11y_max_ms at the default priority,
	 * which runs later and still wins. Shipped code is unchanged.
	 *
	 * @return void
	 */
	public static function add_time_headroom() {
		add_filter( 'softglaze_a11y_max_ms', array( __CLASS__, 'time_headroom' ), 1 );
	}

	/**
	 * At least 10 seconds.
	 *
	 * @param mixed $max_ms Guard in milliseconds.
	 * @return float
	 */
	public static function time_headroom( $max_ms ) {
		return max( (float) $max_ms, 10000.0 );
	}

	/**
	 * The bundled fixes, in registry order.
	 *
	 * @return array
	 */
	protected static function bundled_fixes() {
		return array(
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
	}

	/**
	 * The bundled report-only checks.
	 *
	 * @return array
	 */
	protected static function bundled_checks() {
		return array( new Check_Contrast(), new Check_Headings(), new Check_Alt_Quality(), new Check_Undescribed_Images(), new Check_Field_Visible_Label(), new Check_Ambiguous_Links(), new Check_Landmarks(), new Check_Page_Title(), new Check_Autoplay_Media(), new Check_Duplicate_Ids(), new Check_Non_Native_Controls(), new Check_Tables() );
	}

	/**
	 * An engine with the bundled fixes (plus extras), every fix in one state.
	 * Unless the options say otherwise, the Media Library is the lookup double
	 * holding the dev site's fixture attachments, and the checks are on.
	 *
	 * @param string $state   State for every fix.
	 * @param array  $extra   Extra fixes.
	 * @param string $class   Engine class.
	 * @param array  $options Engine options.
	 * @return Engine
	 */
	protected static function engine( $state = 'live', array $extra = array(), $class = Engine::class, array $options = array() ) {
		$fixes  = array_merge( self::bundled_fixes(), $extra );
		$states = array();
		foreach ( $fixes as $fix ) {
			$states[ $fix->id() ] = $state;
		}
		$options += array(
			'attachments' => new Softglaze_A11y_Test_Attachment_Lookup(),
			'checks'      => self::bundled_checks(),
		);
		return new $class( $fixes, $states, $options );
	}

	/**
	 * Captured fixture files: slug => path.
	 *
	 * @return array
	 */
	protected static function fixture_files() {
		$files = array();
		foreach ( glob( SOFTGLAZE_A11Y_TESTS_ROOT . '/tests/fixtures/html/*.html' ) as $path ) {
			$files[ basename( $path, '.html' ) ] = $path;
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Data provider: one row per fixture.
	 *
	 * @return array
	 */
	public function data_fixtures() {
		$rows = array();
		foreach ( self::fixture_files() as $slug => $path ) {
			$rows[ $slug ] = array( $slug, $path );
		}
		return $rows;
	}

	/**
	 * Reads a file byte for byte.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function read( $path ) {
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture.
		return false === $contents ? '' : $contents;
	}

	/**
	 * Walks two documents in lockstep and lists every difference: attribute
	 * changes as "TAG.attr: before -> after", anything else as a structural note.
	 *
	 * @param string $before Original.
	 * @param string $after  Output.
	 * @return string[]
	 */
	protected static function token_diff( $before, $after ) {
		$a     = new WP_HTML_Tag_Processor( $before );
		$b     = new WP_HTML_Tag_Processor( $after );
		$diff  = array();
		$count = 0;
		while ( true ) {
			$more_a = $a->next_token();
			$more_b = $b->next_token();
			if ( $more_a !== $more_b ) {
				$diff[] = "token stream length differs after token $count";
				break;
			}
			if ( ! $more_a ) {
				break;
			}
			$type = $a->get_token_type();
			if ( $type !== $b->get_token_type() || $a->get_token_name() !== $b->get_token_name() ) {
				$diff[] = "token $count differs: " . $a->get_token_name() . ' / ' . $b->get_token_name();
				break;
			}
			if ( '#tag' === $type ) {
				if ( $a->is_tag_closer() !== $b->is_tag_closer() ) {
					$diff[] = "token $count closer flag differs";
					break;
				}
				if ( ! $a->is_tag_closer() ) {
					$names = array_unique( array_merge( (array) $a->get_attribute_names_with_prefix( '' ), (array) $b->get_attribute_names_with_prefix( '' ) ) );
					sort( $names );
					foreach ( $names as $name ) {
						$value_a = $a->get_attribute( $name );
						$value_b = $b->get_attribute( $name );
						if ( $value_a !== $value_b ) {
							$diff[] = $a->get_token_name() . '.' . $name . ': ' . var_export( $value_a, true ) . ' -> ' . var_export( $value_b, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Test output.
						}
					}
				}
			}
			if ( $a->get_modifiable_text() !== $b->get_modifiable_text() ) {
				$diff[] = "token $count text differs (" . $a->get_token_name() . ')';
			}
			++$count;
		}
		if ( $a->paused_at_incomplete_token() !== $b->paused_at_incomplete_token() ) {
			$diff[] = 'incomplete-token state differs';
		}
		return $diff;
	}

	/**
	 * Number of tag openers, with the engine's predicate.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	protected static function opener_count( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$count     = 0;
		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * What a browser reads for an attribute: the raw double-quoted value of the
	 * first matching tag, decoded with HTML5 rules. Independent of the
	 * WordPress version's own decoder.
	 *
	 * @param string $html      HTML.
	 * @param string $tag       Tag name.
	 * @param string $attribute Attribute name.
	 * @return string|null
	 */
	protected static function browser_value( $html, $tag, $attribute ) {
		if ( ! preg_match( '/<' . $tag . '\b[^>]*?\s' . preg_quote( $attribute, '/' ) . '="([^"]*)"/i', $html, $match ) ) {
			return null;
		}
		return html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Whether the WordPress under test has the 6.9 set_attribute() encoding.
	 *
	 * @return bool
	 */
	protected static function wp_at_least_69() {
		return version_compare( get_bloginfo( 'version' ), '6.9', '>=' );
	}
}
