<?php
/**
 * FS-17 (docs/05-TEST-PLAN.md section 4) with the real constant: when
 * SOFTGLAZE_A11Y_DISABLE is true, no front-end hook is added and no repair
 * layer is registered.
 *
 * A constant cannot be undefined, so this file defines it for the rest of the
 * process. PHPUnit sorts test files by path, which makes "test-zz-" the last
 * file of the run; keep every other test file name sorting before it.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Front_End;
use SoftGlaze\AccessibilityRepair\Plugin;

require_once __DIR__ . '/helpers/class-wiring-test-case.php';

/**
 * Kill switch tests (run last).
 */
class Test_ZZ_Kill_Switch extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * FS-17: a freshly booted plugin adds no front-end hook, and the gate refuses every request.
	 */
	public function test_fs17_kill_switch_registers_nothing() {
		$post_id = self::post_with_iframe();
		self::set_states( 'live' );

		$front = $this->visit( get_permalink( $post_id ) );
		$this->assertNotEmpty( $front->layers(), 'Control: without the constant the layers are registered.' );
		self::reset_plugin();

		if ( ! defined( 'SOFTGLAZE_A11Y_DISABLE' ) ) {
			define( 'SOFTGLAZE_A11Y_DISABLE', true );
		}

		// A second boot, as on the next request with the constant in wp-config.php.
		$admin_bar_callbacks = self::count_callbacks( 'admin_bar_menu', 'SoftGlaze\\AccessibilityRepair\\Admin\\Admin_Bar' );
		$reflection          = new ReflectionClass( Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();
		$boot       = $reflection->getConstructor();
		$boot->setAccessible( true );
		$boot->invoke( $plugin );

		$this->assertTrue( $plugin->gate()->kill_switch() );
		$this->assertFalse( has_action( 'template_redirect', array( $plugin->front_end(), 'on_template_redirect' ) ) );
		$this->assertFalse( has_action( 'wp', array( $plugin->front_end(), 'on_wp' ) ), 'No scan check either.' );
		$this->assertSame( $admin_bar_callbacks, self::count_callbacks( 'admin_bar_menu', 'SoftGlaze\\AccessibilityRepair\\Admin\\Admin_Bar' ), 'No admin-bar node added.' );

		$this->go_to( get_permalink( $post_id ) );
		$plugin->front_end()->on_template_redirect();
		$this->assertFalse( $plugin->gate()->passes() );
		$this->assertSame( 'disabled', $plugin->gate()->reason() );
		$this->assertSame( array(), $plugin->front_end()->layers() );
		$this->assertSame( array(), self::registered_hooks( $plugin->front_end() ) );
		foreach ( array_merge( array_keys( Front_End::CONTENT_FILTERS ), array( Front_End::PAGE_FILTER, 'language_attributes', 'wp_get_attachment_image_attributes', 'the_content_more_link', 'excerpt_more', 'wp_footer', 'wp_body_open', 'wp_nav_menu_args', 'wp_enqueue_scripts', 'wp', 'show_admin_bar' ) ) as $hook ) {
			$this->assertSame( 0, self::count_callbacks( $hook, 'SoftGlaze\\' ), $hook );
		}

		// The running instance refuses as well.
		$this->visit( get_permalink( $post_id ) );
		$this->assertSame( 'disabled', Plugin::instance()->gate()->reason() );
		$this->assertSame( array(), Plugin::instance()->front_end()->layers() );
	}

	/**
	 * Number of callbacks on a hook whose object belongs to a class prefix,
	 * leaving out the running instance's own wiring (registered by the control visit).
	 *
	 * @param string $hook   Hook.
	 * @param string $prefix Class name prefix.
	 * @return int
	 */
	private static function count_callbacks( $hook, $prefix ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}
		$running = Plugin::instance()->front_end();
		$count   = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && is_object( $function[0] ) && $function[0] !== $running && 0 === strpos( get_class( $function[0] ), $prefix ) ) {
					++$count;
				}
			}
		}
		return $count;
	}
}
