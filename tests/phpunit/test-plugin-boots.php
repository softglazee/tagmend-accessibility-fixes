<?php
/**
 * Smoke test: the plugin loads, its constants and autoloader work, and the
 * admin menu and Overview page carry the agreed names.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Plugin;

/**
 * Plugin boot smoke test.
 */
class Test_Plugin_Boots extends WP_UnitTestCase {

	/**
	 * Runs on the WordPress version the container was started for.
	 */
	public function test_runs_on_requested_wordpress_version() {
		$this->assertSame( SOFTGLAZE_A11Y_TESTS_WP_VERSION, get_bloginfo( 'version' ) );
	}

	/**
	 * The main file defined its constants.
	 */
	public function test_constants_are_defined() {
		$this->assertSame( '1.0.0', SOFTGLAZE_A11Y_VERSION );
		$this->assertFileExists( SOFTGLAZE_A11Y_FILE );
		$this->assertSame( 'tagmend-accessibility-fixes.php', basename( SOFTGLAZE_A11Y_FILE ) );
		$this->assertFileExists( SOFTGLAZE_A11Y_DIR . 'includes/class-plugin.php' );
	}

	/**
	 * The header Version equals the constant, and the name keeps its en dash.
	 */
	public function test_header_matches_constant() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( SOFTGLAZE_A11Y_FILE, false, false );
		$this->assertSame( SOFTGLAZE_A11Y_VERSION, $data['Version'] );
		$this->assertSame( "Tagmend \u{2013} Accessibility Fixes", $data['Name'] );
		$this->assertSame( 'tagmend-accessibility-fixes', $data['TextDomain'] );
	}

	/**
	 * The autoloader resolves the Plugin class and the instance is a singleton.
	 */
	public function test_plugin_instance_is_singleton() {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	/**
	 * The top-level menu is registered under the code-prefixed slug.
	 */
	public function test_menu_page_is_registered() {
		global $admin_page_hooks;

		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Plugin::instance()->register_menu();

		$this->assertArrayHasKey( Plugin::MENU_SLUG, $admin_page_hooks );
		$this->assertNotFalse( has_action( 'toplevel_page_' . Plugin::MENU_SLUG, array( Plugin::instance(), 'render_overview' ) ) );
	}

	/**
	 * The Overview page prints its h1 for administrators and nothing for others.
	 */
	public function test_overview_heading() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = get_echo( array( Plugin::instance(), 'render_overview' ) );
		$this->assertStringContainsString( "<h1>Tagmend \u{2013} Accessibility Fixes</h1>", $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', get_echo( array( Plugin::instance(), 'render_overview' ) ) );
	}
}
