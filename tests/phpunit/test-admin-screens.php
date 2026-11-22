<?php
/**
 * The Fixes screen, its admin-post handler (SEC-06, docs/05-TEST-PLAN.md
 * section 5), the admin menu and the admin-bar node.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Admin\Admin_Bar;
use SoftGlaze\AccessibilityRepair\Admin\Screen_Fixes;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Request_Gate;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/helpers/class-wiring-test-case.php';

/**
 * Admin screen tests.
 */
class Test_Admin_Screens extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * Saved defaults before every test.
	 */
	public function set_up() {
		parent::set_up();
		Plugin::instance()->settings()->activate();
	}

	/**
	 * A user of a role, made current.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	private static function login( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Runs the save handler and returns the wp_die() code, or 0 when it did not die.
	 *
	 * @return int
	 */
	private static function save_and_get_die_code() {
		try {
			Plugin::instance()->fixes_screen()->process_save();
		} catch ( WPDieException $exception ) {
			return (int) $exception->getCode();
		}
		return 0;
	}

	/**
	 * SEC-06: no nonce, a wrong nonce, no capability (subscriber, editor, logged out):
	 * refused with 403 and nothing saved.
	 */
	public function test_sec06_save_refused_without_nonce_or_capability() {
		$before = get_option( Settings::OPTION );

		self::login( 'administrator' );
		$_POST = array( Screen_Fixes::FIELD => array( 'iframe-title' => 'live' ) );
		$this->assertSame( 403, self::save_and_get_die_code(), 'administrator, no nonce' );

		$_POST[ Screen_Fixes::NONCE_FIELD ] = wp_create_nonce( 'softglaze_a11y_view' );
		$this->assertSame( 403, self::save_and_get_die_code(), 'administrator, nonce for another action' );

		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			self::login( $role );
			$_POST[ Screen_Fixes::NONCE_FIELD ] = wp_create_nonce( Screen_Fixes::ACTION );
			$this->assertSame( 403, self::save_and_get_die_code(), "$role with own nonce" );
		}

		wp_set_current_user( 0 );
		$_POST[ Screen_Fixes::NONCE_FIELD ] = wp_create_nonce( Screen_Fixes::ACTION );
		$this->assertSame( 403, self::save_and_get_die_code(), 'logged out' );

		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	/**
	 * A valid save changes the submitted states only; unknown ids are never read;
	 * an invalid value is refused and reported on the screen.
	 */
	public function test_save_with_capability_and_nonce() {
		self::login( 'administrator' );
		$_POST = array(
			Screen_Fixes::NONCE_FIELD => wp_create_nonce( Screen_Fixes::ACTION ),
			Screen_Fixes::FIELD       => array(
				'iframe-title' => 'live',
				'html-lang'    => 'off',
				'made-up'      => 'live',
			),
		);
		$url = Plugin::instance()->fixes_screen()->process_save();
		$this->assertStringContainsString( 'page=' . Screen_Fixes::PAGE, $url );
		$this->assertStringContainsString( Screen_Fixes::STATUS_ARG . '=saved', $url );
		$states = Plugin::instance()->settings()->fix_states();
		$this->assertSame( 'live', $states['iframe-title'] );
		$this->assertSame( 'off', $states['html-lang'] );
		$this->assertSame( 'preview', $states['viewport-zoom'] );
		$this->assertArrayNotHasKey( 'made-up', get_option( Settings::OPTION )['fixes'] );

		$_POST[ Screen_Fixes::FIELD ] = array( 'iframe-title' => '<b>preview</b>' );
		$url                          = Plugin::instance()->fixes_screen()->process_save();
		$this->assertStringContainsString( Screen_Fixes::STATUS_ARG . '=rejected', $url );
		$this->assertSame( 'live', Plugin::instance()->settings()->fix_states()['iframe-title'] );
	}

	/**
	 * The screen: a fieldset per fix whose legend is the fix name, three
	 * radios, the saved state checked, Live disabled with the reason in text
	 * where the fix cannot run.
	 */
	public function test_fixes_screen_markup() {
		self::login( 'administrator' );
		$html = get_echo( array( Plugin::instance()->fixes_screen(), 'render' ) );

		$this->assertStringContainsString( '<h1>Fixes</h1>', $html );
		$this->assertSame( 22, substr_count( $html, '<fieldset class="softglaze-a11y-fix-card"' ) );
		$this->assertSame( 22, substr_count( $html, '</fieldset>' ) );
		$this->assertStringContainsString( '<legend><h3 class="softglaze-a11y-fix-title">Titles for embedded frames</h3></legend>', $html, 'A5: each fix name is a heading inside its legend.' );
		$this->assertSame( 22, substr_count( $html, '<legend><h3 class="softglaze-a11y-fix-title">' ) );
		$this->assertSame( 2, substr_count( $html, 'value="Save fix states"' ), 'A5: Save above and below the fixes.' );
		$this->assertStringContainsString( 'id="softglaze-a11y-save-top"', $html );
		$this->assertLessThan( strpos( $html, '<fieldset' ), strpos( $html, 'id="softglaze-a11y-save-top"' ), 'A5: the first Save button comes before the first fix.' );
		$this->assertSame( 66, substr_count( $html, 'type="radio"' ) );
		$this->assertMatchesRegularExpression( '/name="softglaze_a11y_fix\[iframe-title\]" value="preview"\s+checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'name="action" value="softglaze_a11y_save_fixes"', $html );
		$this->assertStringContainsString( 'name="' . Screen_Fixes::NONCE_FIELD . '"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<style', $html );
		$this->assertStringNotContainsString( 'role="status"', $html );

		// WooCommerce is not loaded in the tests, so F04's Live is disabled with the reason in text.
		$this->assertMatchesRegularExpression( '/name="softglaze_a11y_fix\[product-img-alt-name\]" value="live"\s+disabled=\'disabled\' aria-describedby="softglaze-a11y-fix-product-img-alt-name-reason"/', $html );
		$this->assertStringContainsString( '<p class="description" id="softglaze-a11y-fix-product-img-alt-name-reason">Needs WooCommerce</p>', $html );
		$this->assertStringContainsString( '<legend><h3 class="softglaze-a11y-fix-title">Missing image alt text from the Media Library</h3></legend>', $html );

		if ( self::has_page_pass() ) {
			$this->assertSame( 1, substr_count( $html, "disabled='disabled'" ) );
			$this->assertStringNotContainsString( 'Needs WordPress 6.9 or later', $html );
		} else {
			// F04 and the four page-scope fixes F11, F12, F13 and F17.
			$this->assertSame( 5, substr_count( $html, "disabled='disabled'" ) );
			foreach ( array( 'skip-link', 'skip-link-target', 'viewport-zoom', 'duplicate-field-ids' ) as $id ) {
				$this->assertMatchesRegularExpression( '/name="softglaze_a11y_fix\[' . $id . '\]" value="live"\s+disabled=\'disabled\' aria-describedby="softglaze-a11y-fix-' . $id . '-reason"/', $html );
				$this->assertStringContainsString( '<p class="description" id="softglaze-a11y-fix-' . $id . '-reason">Needs WordPress 6.9 or later</p>', $html );
			}
		}
	}

	/**
	 * After a save the screen shows an inline status message; unknown status values show nothing.
	 */
	public function test_fixes_screen_status_message() {
		self::login( 'administrator' );
		$_GET[ Screen_Fixes::STATUS_ARG ] = 'saved';
		$html                             = get_echo( array( Plugin::instance()->fixes_screen(), 'render' ) );
		$this->assertStringContainsString( '<div class="notice notice-success inline softglaze-a11y-status" role="status" tabindex="-1"><p>Fix states saved.</p></div>', $html );

		$_GET[ Screen_Fixes::STATUS_ARG ] = '<script>';
		$html                             = get_echo( array( Plugin::instance()->fixes_screen(), 'render' ) );
		$this->assertStringNotContainsString( 'role="status"', $html );
	}

	/**
	 * Users without manage_options get nothing.
	 */
	public function test_fixes_screen_needs_capability() {
		self::login( 'editor' );
		$this->assertSame( '', get_echo( array( Plugin::instance()->fixes_screen(), 'render' ) ) );
	}

	/**
	 * The menu has Overview, Fixes, Reports and Activity log; no admin notice hook anywhere.
	 */
	public function test_menu_has_fixes_page() {
		global $submenu;
		if ( ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		self::login( 'administrator' );
		unset( $submenu[ Plugin::MENU_SLUG ] );
		Plugin::instance()->register_menu();
		$slugs = wp_list_pluck( $submenu[ Plugin::MENU_SLUG ], 2 );
		$this->assertSame( array( Plugin::MENU_SLUG, Screen_Fixes::PAGE, 'softglaze-a11y-reports', 'softglaze-a11y-log', 'softglaze-a11y-settings' ), array_values( $slugs ) );
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices' ) as $hook ) {
			$this->assertFalse( self::hooked_by_plugin( $hook ), $hook );
		}
	}

	/**
	 * Whether any callback on a hook belongs to the plugin's namespace.
	 *
	 * @param string $hook Hook.
	 * @return bool
	 */
	private static function hooked_by_plugin( $hook ) {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && 0 === strpos( get_class( $callback['function'][0] ), 'SoftGlaze\\' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The admin-bar node: View original and View repaired for the current page with a valid nonce.
	 */
	public function test_admin_bar_node() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$post_id = self::post_with_iframe();
		$this->go_to( add_query_arg( Request_Gate::VIEW_ARG, 'original', get_permalink( $post_id ) ) . '&softglaze_a11y_view_nonce=old' );
		$plugin = Plugin::instance();
		$node   = new Admin_Bar( $plugin->settings(), $plugin->gate(), $plugin->scanner() );

		self::login( 'administrator' );
		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertSame( 'Tagmend', $bar->get_node( 'softglaze-a11y' )->title );
		$this->assertStringContainsString( 'page=' . Screen_Fixes::PAGE, $bar->get_node( 'softglaze-a11y' )->href );

		foreach ( array( 'original', 'repaired' ) as $view ) {
			$item = $bar->get_node( 'softglaze-a11y-view-' . $view );
			$this->assertNotNull( $item, $view );
			wp_parse_str( (string) wp_parse_url( $item->href, PHP_URL_QUERY ), $args );
			$this->assertSame( $view, $args[ Request_Gate::VIEW_ARG ] );
			$this->assertSame( (string) $post_id, $args['p'] );
			$this->assertSame( 1, wp_verify_nonce( $args[ Request_Gate::VIEW_NONCE_ARG ], Request_Gate::view_nonce_action( Request_Gate::url_hash_of( get_permalink( $post_id ) ) ) ) );
			$this->assertSame( 1, substr_count( $item->href, Request_Gate::VIEW_NONCE_ARG . '=' ), 'The old nonce is replaced.' );
		}
		$this->assertSame( 'View repaired (Live + Preview)', $bar->get_node( 'softglaze-a11y-view-repaired' )->title );

		self::login( 'editor' );
		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertNull( $bar->get_node( 'softglaze-a11y' ), 'Editors get no node.' );

		self::login( 'administrator' );
		update_option( Settings::OPTION, array( 'admin_bar' => false ) );
		$bar = new WP_Admin_Bar();
		$node->add_nodes( $bar );
		$this->assertNull( $bar->get_node( 'softglaze-a11y' ), 'The node can be switched off.' );
	}
}
