<?php
/**
 * Shared helpers for the gate, wiring, settings and admin suites.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Request_Gate;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/class-engine-test-doubles.php';
require_once __DIR__ . '/class-engine-test-case.php';

/**
 * A gate whose request-type constants can be faked (they cannot be undefined
 * once defined in the test process).
 */
class Softglaze_A11y_Test_Gate extends Request_Gate {

	/**
	 * Constant names treated as defined and true.
	 *
	 * @var string[]
	 */
	public $fake_constants = array();

	/**
	 * Fakes constants, then asks PHP.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function constant_is_true( string $name ): bool {
		return in_array( $name, $this->fake_constants, true ) || parent::constant_is_true( $name );
	}
}

/**
 * Wiring whose F10 helper throws (FS-14) or whose engine factory throws (FS-18).
 */
class Softglaze_A11y_Test_Front_End extends Front_End {

	/**
	 * Throw from html_lang_value().
	 *
	 * @var bool
	 */
	public $throw_in_lang = false;

	/**
	 * Throw from engine().
	 *
	 * @var bool
	 */
	public $throw_in_engine = false;

	/**
	 * Calls of send_no_cache().
	 *
	 * @var int
	 */
	public $no_cache_calls = 0;

	/**
	 * F04 object used instead of the registry's (WooCommerce is not loaded in tests).
	 *
	 * @var mixed
	 */
	public $product_fix = null;

	/**
	 * States used instead of the saved ones.
	 *
	 * @var array|null
	 */
	public $states_override = null;

	/**
	 * The F04 double when set.
	 *
	 * @return mixed
	 */
	protected function product_image_fix() {
		return null !== $this->product_fix ? $this->product_fix : parent::product_image_fix();
	}

	/**
	 * The overriding states when set.
	 *
	 * @return array
	 */
	public function states(): array {
		return null !== $this->states_override ? $this->states_override : parent::states();
	}

	/**
	 * F10 helper.
	 *
	 * @param string|true|null $current Current lang.
	 * @return string|null
	 * @throws RuntimeException When asked to.
	 */
	protected function html_lang_value( $current ) {
		if ( $this->throw_in_lang ) {
			throw new RuntimeException( 'lang' );
		}
		return parent::html_lang_value( $current );
	}

	/**
	 * Engine factory.
	 *
	 * @return \SoftGlaze\AccessibilityRepair\Engine
	 * @throws LogicException When asked to.
	 */
	protected function engine(): \SoftGlaze\AccessibilityRepair\Engine {
		if ( $this->throw_in_engine ) {
			throw new LogicException( 'engine' );
		}
		return parent::engine();
	}

	/**
	 * Counts instead of sending headers twice.
	 *
	 * @return void
	 */
	protected function send_no_cache(): void {
		++$this->no_cache_calls;
		parent::send_no_cache();
	}
}

/**
 * Base class: resets the plugin's per-request state around every test and
 * simulates front-end requests.
 */
abstract class Softglaze_A11y_Wiring_TestCase extends WP_UnitTestCase {

	/**
	 * An iframe F09 titles "YouTube video".
	 */
	const IFRAME = '<iframe src="https://www.youtube.com/embed/abc"></iframe>';

	/**
	 * A small page for the page pass.
	 */
	const PAGE = '<!DOCTYPE html><html lang="en-US"><head><meta name="viewport" content="width=device-width, user-scalable=no"><title>T</title></head><body><p>Hi</p><iframe src="https://www.youtube.com/embed/abc"></iframe></body></html>';

	/**
	 * Prepares a clean front-end GET request.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'softglaze_a11y_max_ms', array( 'Softglaze_A11y_Engine_TestCase', 'time_headroom' ), 1 );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'] );
		kses_remove_filters();
		delete_option( Settings::OPTION );
		self::reset_plugin();
	}

	/**
	 * Restores the request.
	 */
	public function tear_down() {
		self::reset_plugin();
		$_GET                      = array();
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * Clears the cached registry, gate result and wiring state.
	 *
	 * @return void
	 */
	protected static function reset_plugin() {
		$plugin = Plugin::instance();
		$plugin->registry()->reset();
		$plugin->gate()->reset();
		$plugin->front_end()->reset();
	}

	/**
	 * Saves fix states (one state for every fix, or id => state) and other settings.
	 *
	 * @param string|array $states State for all fixes, or id => state.
	 * @param array        $extra  Other settings keys.
	 * @return void
	 */
	protected static function set_states( $states, array $extra = array() ) {
		$plugin = Plugin::instance();
		$plugin->registry()->reset();
		if ( is_string( $states ) ) {
			$states = array_fill_keys( $plugin->registry()->ids(), $states );
		}
		$settings          = $plugin->settings()->get();
		$settings['fixes'] = array_merge( $settings['fixes'], $states );
		update_option( Settings::OPTION, array_merge( $settings, $extra ) );
		self::reset_plugin();
	}

	/**
	 * Simulates a front-end request up to template_redirect (only the plugin's own callback runs).
	 *
	 * @param string $url URL.
	 * @return Front_End
	 */
	protected function visit( $url ) {
		self::reset_plugin();
		$this->go_to( $url );
		Plugin::instance()->front_end()->on_template_redirect();
		return Plugin::instance()->front_end();
	}

	/**
	 * Hooks on which a wiring object has a callback.
	 *
	 * @param Front_End $front Wiring.
	 * @return string[]
	 */
	protected static function registered_hooks( $front ) {
		$hooks = array_merge( array_keys( Front_End::CONTENT_FILTERS ), array( Front_End::PAGE_FILTER, 'language_attributes', 'wp_get_attachment_image_attributes', 'wp_nav_menu_args', 'wp_body_open', 'wp_enqueue_scripts', 'shutdown' ) );
		$found = array();
		foreach ( $hooks as $hook ) {
			foreach ( array( 'filter_content', 'filter_page', 'filter_language_attributes', 'filter_attachment_image_attributes', 'filter_nav_menu_args', 'print_skip_link', 'enqueue_focus_visible', 'record_fallbacks' ) as $method ) {
				if ( false !== has_filter( $hook, array( $front, $method ) ) ) {
					$found[] = $hook;
				}
			}
		}
		return $found;
	}

	/**
	 * A published post whose content holds an untitled iframe.
	 *
	 * @return int
	 */
	protected static function post_with_iframe() {
		return self::factory()->post->create(
			array(
				'post_content' => '<p>Tour</p>' . self::IFRAME,
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Whether the WordPress under test has the template enhancement buffer.
	 *
	 * @return bool
	 */
	protected static function has_page_pass() {
		return Registry::page_pass_exists();
	}

	/**
	 * Runs core's template enhancement finaliser as an HTML response (WordPress 6.9+).
	 *
	 * @param string $html     Buffered output.
	 * @param string $mimetype Response type core sees (headers_list() is empty on the CLI).
	 * @return string
	 */
	protected static function finalize_buffer( $html, $mimetype = 'text/html' ) {
		$previous = ini_get( 'default_mimetype' );
		ini_set( 'default_mimetype', $mimetype ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only.
		$result = wp_finalize_template_enhancement_output_buffer( $html, PHP_OUTPUT_HANDLER_FINAL );
		ini_set( 'default_mimetype', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test only.
		return $result;
	}
}
