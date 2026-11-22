<?php
/**
 * Registry and settings: first-activation defaults, saved states never
 * changed by updates, and SEC-11 (docs/05-TEST-PLAN.md section 5): unknown
 * ids, unknown states and Live for a fix that cannot run here are refused.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Settings;

require_once __DIR__ . '/helpers/class-wiring-test-case.php';

/**
 * A third-party fix with a valid id.
 */
class Softglaze_A11y_Test_Fix_Extra extends Softglaze_A11y_Test_Fix {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'extra-fix';
	}

	/**
	 * Does nothing.
	 *
	 * @param \SoftGlaze\AccessibilityRepair\Token          $token    Token.
	 * @param \SoftGlaze\AccessibilityRepair\Document_Index $index    Index.
	 * @param \SoftGlaze\AccessibilityRepair\Plan           $plan     Plan.
	 * @param \SoftGlaze\AccessibilityRepair\Recorder       $recorder Recorder.
	 */
	public function analyse( \SoftGlaze\AccessibilityRepair\Token $token, \SoftGlaze\AccessibilityRepair\Document_Index $index, \SoftGlaze\AccessibilityRepair\Plan $plan, \SoftGlaze\AccessibilityRepair\Recorder $recorder ): void {
	}
}

/**
 * A third-party fix whose id is not a valid slug.
 */
class Softglaze_A11y_Test_Fix_Bad_Id extends Softglaze_A11y_Test_Fix_Extra {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'Bad Id<script>';
	}
}

/**
 * Settings and registry tests.
 */
class Test_Settings extends Softglaze_A11y_Wiring_TestCase {

	/**
	 * Shortcut.
	 *
	 * @return Settings
	 */
	private static function settings() {
		return Plugin::instance()->settings();
	}

	/**
	 * Bundled fixes come first; a filter can add a valid fix but cannot remove,
	 * replace or add an invalid one.
	 */
	public function test_registry_filter_adds_but_never_replaces() {
		$registry = Plugin::instance()->registry();
		$this->assertSame( array( 'img-alt-library', 'img-alt-empty-library', 'img-in-named-control', 'product-img-alt-name', 'link-name', 'button-name', 'decorative-icons', 'field-label', 'iframe-title', 'html-lang', 'skip-link', 'skip-link-target', 'viewport-zoom', 'positive-tabindex', 'hidden-duplicate-links', 'aria-hidden-focus', 'duplicate-field-ids', 'nav-labels', 'read-more-context', 'empty-heading', 'new-tab-note', 'focus-visible' ), $registry->ids() );

		$filter = static function ( $fixes ) {
			$first = $fixes[0];
			return array(
				new Softglaze_A11y_Test_Fix_Extra(),
				new Softglaze_A11y_Test_Fix_Bad_Id(),
				new Softglaze_A11y_Test_Fix_Throws(),
				'not a fix',
				$first,
			);
		};
		add_filter( 'softglaze_a11y_register_fixes', $filter );
		$registry->reset();
		$this->assertSame( array( 'img-alt-library', 'img-alt-empty-library', 'img-in-named-control', 'product-img-alt-name', 'link-name', 'button-name', 'decorative-icons', 'field-label', 'iframe-title', 'html-lang', 'skip-link', 'skip-link-target', 'viewport-zoom', 'positive-tabindex', 'hidden-duplicate-links', 'aria-hidden-focus', 'duplicate-field-ids', 'nav-labels', 'read-more-context', 'empty-heading', 'new-tab-note', 'focus-visible', 'extra-fix', 'test-throws' ), $registry->ids() );
		$this->assertInstanceOf( Fix_Iframe_Title::class, $registry->get( 'iframe-title' ) );
		$this->assertNull( $registry->get( 'Bad Id<script>' ) );
		remove_filter( 'softglaze_a11y_register_fixes', $filter );
	}

	/**
	 * Availability: the page-scope fixes (F11, F12, F13) need WordPress 6.9;
	 * the Phase 4A fixes that work on fragments, menus or stylesheets do not.
	 */
	public function test_availability_by_version() {
		$registry = Plugin::instance()->registry();
		foreach ( array( 'iframe-title', 'html-lang', 'positive-tabindex', 'hidden-duplicate-links', 'aria-hidden-focus', 'nav-labels', 'empty-heading', 'focus-visible' ) as $id ) {
			$this->assertTrue( $registry->is_available( $registry->get( $id ) ), $id );
		}
		foreach ( array( 'skip-link', 'skip-link-target', 'viewport-zoom', 'duplicate-field-ids' ) as $id ) {
			$fix = $registry->get( $id );
			if ( self::has_page_pass() ) {
				$this->assertTrue( $registry->is_available( $fix ), $id );
				$this->assertSame( '', $registry->unavailable_reason( $fix ), $id );
			} else {
				$this->assertFalse( $registry->is_available( $fix ), $id );
				$this->assertSame( 'Needs WordPress 6.9 or later', $registry->unavailable_reason( $fix ), $id );
			}
		}
	}

	/**
	 * First activation writes recommended fixes in Preview; later activations change nothing.
	 */
	public function test_activation_writes_defaults_once() {
		$this->assertNull( get_option( Settings::OPTION, null ) );
		self::settings()->activate();
		$saved = get_option( Settings::OPTION );
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
			$saved['fixes']
		);
		$this->assertTrue( $saved['page_scope'] );
		$this->assertTrue( $saved['admin_bar'] );
		$this->assertSame( 2048, $saved['max_kb'] );
		$this->assertSame( 100, $saved['max_ms'] );
		$this->assertSame( array(), $saved['exclude_paths'] );
		$this->assertFalse( $saved['delete_data_on_uninstall'] );

		self::settings()->save_fix_states( array( 'iframe-title' => 'live' ), 1 );
		self::settings()->activate();
		$this->assertSame( 'live', self::settings()->fix_states()['iframe-title'], 'Reactivation keeps saved states.' );
	}

	/**
	 * Without a saved option the defaults apply; a fix registered after the
	 * option was written starts Off (updates never change saved states).
	 */
	public function test_missing_option_and_new_fix_states() {
		$this->assertSame( 'preview', self::settings()->fix_states()['iframe-title'] );

		self::settings()->activate();
		$filter = static function ( $fixes ) {
			$fixes[] = new Softglaze_A11y_Test_Fix_Extra();
			return $fixes;
		};
		add_filter( 'softglaze_a11y_register_fixes', $filter );
		Plugin::instance()->registry()->reset();
		$this->assertSame( 'off', self::settings()->fix_states()['extra-fix'] );
		$this->assertSame( 'preview', self::settings()->fix_states()['iframe-title'] );
		remove_filter( 'softglaze_a11y_register_fixes', $filter );
	}

	/**
	 * SEC-11: an unknown fix id is refused and saved states stay as they were.
	 */
	public function test_sec11_unknown_fix_id_refused() {
		self::settings()->activate();
		$before   = get_option( Settings::OPTION );
		$rejected = self::settings()->save_fix_states( array( 'made-up-fix' => 'live' ), 1 );

		$this->assertSame(
			array(
				array(
					'id'     => 'made-up-fix',
					'reason' => 'unknown_fix',
				),
			),
			$rejected
		);
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}

	/**
	 * SEC-11: unknown and hostile states are refused, valid ones in the same save are kept.
	 */
	public function test_sec11_unknown_state_refused() {
		self::settings()->activate();
		$rejected = self::settings()->save_fix_states(
			array(
				'iframe-title' => 'on',
				'html-lang'    => '<script>alert(1)</script>',
			),
			1
		);
		$this->assertCount( 2, $rejected );
		$this->assertSame( 'unknown_state', $rejected[0]['reason'] );
		$this->assertSame( 'unknown_state', $rejected[1]['reason'] );
		$this->assertSame( 'preview', self::settings()->fix_states()['iframe-title'] );
		$this->assertSame( 'preview', self::settings()->fix_states()['html-lang'] );

		$rejected = self::settings()->save_fix_states(
			array(
				'iframe-title' => 'live',
				'html-lang'    => 'bogus',
			),
			1
		);
		$this->assertCount( 1, $rejected );
		$this->assertSame( 'live', self::settings()->fix_states()['iframe-title'] );
		$this->assertSame( 'preview', self::settings()->fix_states()['html-lang'] );
	}

	/**
	 * SEC-11 for F11 and F12: Live refused below 6.9 (both entries listed, saved
	 * states kept), accepted from 6.9.
	 */
	public function test_sec11_live_for_skip_link_fixes_by_version() {
		self::settings()->activate();
		$rejected = self::settings()->save_fix_states(
			array(
				'skip-link'        => 'live',
				'skip-link-target' => 'live',
			),
			1
		);
		$states   = self::settings()->fix_states();
		if ( self::has_page_pass() ) {
			$this->assertSame( array(), $rejected );
			$this->assertSame( array( 'live', 'live' ), array( $states['skip-link'], $states['skip-link-target'] ) );
		} else {
			$this->assertSame( array( 'skip-link', 'skip-link-target' ), wp_list_pluck( $rejected, 'id' ) );
			$this->assertSame( array( 'unavailable', 'unavailable' ), wp_list_pluck( $rejected, 'reason' ) );
			$this->assertSame( array( 'preview', 'preview' ), array( $states['skip-link'], $states['skip-link-target'] ) );
		}
	}

	/**
	 * SEC-11 for F17 (page scope only): Live refused below 6.9 with its saved
	 * state kept; F15 and F16 work on fragments and are accepted everywhere.
	 */
	public function test_sec11_live_for_phase_4b_fixes_by_version() {
		self::settings()->activate();
		$rejected = self::settings()->save_fix_states(
			array(
				'hidden-duplicate-links' => 'live',
				'aria-hidden-focus'      => 'live',
				'duplicate-field-ids'    => 'live',
			),
			1
		);
		$states   = self::settings()->fix_states();
		$this->assertSame( array( 'live', 'live' ), array( $states['hidden-duplicate-links'], $states['aria-hidden-focus'] ) );
		if ( self::has_page_pass() ) {
			$this->assertSame( array(), $rejected );
			$this->assertSame( 'live', $states['duplicate-field-ids'] );
		} else {
			$this->assertSame( array( 'duplicate-field-ids' ), wp_list_pluck( $rejected, 'id' ) );
			$this->assertSame( 'off', $states['duplicate-field-ids'] );
		}
	}

	/**
	 * SEC-11: Live for the page-only fix is refused below 6.9 and accepted from 6.9.
	 */
	public function test_sec11_live_for_page_fix_by_version() {
		self::settings()->activate();
		$rejected = self::settings()->save_fix_states( array( 'viewport-zoom' => 'live' ), 1 );
		if ( self::has_page_pass() ) {
			$this->assertSame( array(), $rejected );
			$this->assertSame( 'live', self::settings()->fix_states()['viewport-zoom'] );
		} else {
			$this->assertSame(
				array(
					array(
						'id'     => 'viewport-zoom',
						'reason' => 'unavailable',
					),
				),
				$rejected
			);
			$this->assertSame( 'preview', self::settings()->fix_states()['viewport-zoom'] );
			$this->assertSame( array(), self::settings()->save_fix_states( array( 'viewport-zoom' => 'off' ), 1 ), 'Off is still allowed.' );
			$this->assertSame( 'off', self::settings()->fix_states()['viewport-zoom'] );
		}
	}

	/**
	 * SEC-11 through the Settings API path: update_option() with a whole invalid value keeps the saved settings.
	 */
	public function test_sec11_update_option_runs_the_sanitizer() {
		self::settings()->activate();
		$before = get_option( Settings::OPTION );

		update_option( Settings::OPTION, 'not an array' );
		$this->assertSame( $before, get_option( Settings::OPTION ) );

		update_option(
			Settings::OPTION,
			array(
				'fixes'      => array(
					'iframe-title' => 'live',
					'nope'         => 'live',
				),
				'unexpected' => '<script>',
			)
		);
		$saved = get_option( Settings::OPTION );
		$this->assertSame( 'live', $saved['fixes']['iframe-title'] );
		$this->assertArrayNotHasKey( 'nope', $saved['fixes'] );
		$this->assertArrayNotHasKey( 'unexpected', $saved );
	}

	/**
	 * Other keys: numbers clamped, booleans, path prefixes as plain text starting with "/".
	 */
	public function test_other_settings_sanitized() {
		self::settings()->activate();
		update_option(
			Settings::OPTION,
			array(
				'page_scope'               => '0',
				'admin_bar'                => 'false',
				'delete_data_on_uninstall' => '1',
				'max_kb'                   => '999999',
				'max_ms'                   => '-5',
				'exclude_paths'            => "/members\njavascript:alert(1)\n/shop/<b>cart</b>\n\nno-slash\n/members",
			)
		);
		$saved = get_option( Settings::OPTION );
		$this->assertFalse( $saved['page_scope'] );
		$this->assertFalse( $saved['admin_bar'] );
		$this->assertTrue( $saved['delete_data_on_uninstall'] );
		$this->assertSame( Settings::MAX_KB_MAX, $saved['max_kb'] );
		$this->assertSame( Settings::MAX_MS_MIN, $saved['max_ms'] );
		$this->assertSame( array( '/members', '/shop/cart' ), $saved['exclude_paths'] );
		$this->assertSame( 8192 * 1024, self::settings()->max_bytes() );
	}

	/**
	 * A stored state for a fix whose add-on is inactive survives a save.
	 */
	public function test_unregistered_stored_state_is_carried_over() {
		$filter = static function ( $fixes ) {
			$fixes[] = new Softglaze_A11y_Test_Fix_Extra();
			return $fixes;
		};
		add_filter( 'softglaze_a11y_register_fixes', $filter );
		Plugin::instance()->registry()->reset();
		self::settings()->activate();
		self::settings()->save_fix_states( array( 'extra-fix' => 'live' ), 1 );
		remove_filter( 'softglaze_a11y_register_fixes', $filter );
		Plugin::instance()->registry()->reset();

		$this->assertSame( array(), self::settings()->save_fix_states( array( 'iframe-title' => 'live' ), 1 ) );
		$this->assertSame( 'live', get_option( Settings::OPTION )['fixes']['extra-fix'] );
	}

	/**
	 * Each changed state fires softglaze_a11y_fix_state_changed once.
	 */
	public function test_state_change_action() {
		self::settings()->activate();
		$events = array();
		add_action(
			'softglaze_a11y_fix_state_changed',
			static function ( $id, $from, $to, $user_id ) use ( &$events ) {
				$events[] = array( $id, $from, $to, $user_id );
			},
			10,
			4
		);
		self::settings()->save_fix_states(
			array(
				'iframe-title' => 'live',
				'html-lang'    => 'preview',
			),
			7
		);
		$this->assertSame( array( array( 'iframe-title', 'preview', 'live', 7 ) ), $events );
	}
}
