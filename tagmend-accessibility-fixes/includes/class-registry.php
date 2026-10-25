<?php
/**
 * The list of fixes and what each one needs to run.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the fix list once per request: the bundled fixes first, then any
 * fix another plugin adds through softglaze_a11y_register_fixes. A filter can
 * add fixes but never remove or replace a bundled one.
 */
final class Registry {

	/**
	 * Fix ids: lower-case words joined by single hyphens, at most 40 characters (the log column width).
	 */
	const ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * Longest accepted fix id.
	 */
	const ID_MAX = 40;

	/**
	 * Fixes by id, built on first use.
	 *
	 * @var Fix[]|null
	 */
	private $fixes = null;

	/**
	 * Report-only checks, built on first use.
	 *
	 * @var Check_Base[]|null
	 */
	private $checks = null;

	/**
	 * All registered fixes, by id, bundled ones first.
	 *
	 * @return Fix[]
	 */
	public function fixes(): array {
		if ( null !== $this->fixes ) {
			return $this->fixes;
		}

		// Catalogue order (blueprint section 6). TODO(Phase 3): F05, F06, F08, F19, F21. TODO(Phase 4A): F11, F12, F14, F18, F20, F22. TODO(Phase 4B): F15, F16, F17.
		$bundled = array(
			new Fixes\Fix_Img_Alt_Library(),
			new Fixes\Fix_Img_Alt_Empty_Library(),
			new Fixes\Fix_Img_In_Named_Control(),
			new Fixes\Fix_Product_Img_Alt_Name(),
			new Fixes\Fix_Decorative_Icons(),
			new Fixes\Fix_Iframe_Title(),
			new Fixes\Fix_Html_Lang(),
			new Fixes\Fix_Viewport_Zoom(),
		);

		$fixes = array();
		foreach ( $bundled as $fix ) {
			$fixes[ $fix->id() ] = $fix;
		}

		/**
		 * Filters the fix list. Fix objects may be added; bundled fixes are
		 * always kept and cannot be replaced, and invalid entries are dropped.
		 *
		 * @param Fix[] $fixes The bundled fixes.
		 */
		$filtered = apply_filters( 'softglaze_a11y_register_fixes', $bundled );
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $fix ) {
				$id = self::valid_id( $fix );
				if ( '' !== $id && ! isset( $fixes[ $id ] ) ) {
					$fixes[ $id ] = $fix;
				}
			}
		}

		$this->fixes = $fixes;
		return $this->fixes;
	}

	/**
	 * The report-only checks (blueprint section 7), run in scans and admin views.
	 * Not filterable: section 17 defines no hook for checks.
	 *
	 * @return Check_Base[]
	 */
	public function checks(): array {
		if ( null === $this->checks ) {
			// TODO(Phase 3): R05-R07. TODO(Phase 4A): R02, R08, R09. TODO(Phase 4B): R10-R13.
			$this->checks = array(
				new Checks\Check_Alt_Quality(),
				new Checks\Check_Undescribed_Images(),
			);
		}
		return $this->checks;
	}

	/**
	 * One fix by id.
	 *
	 * @param string $id Fix id.
	 * @return Fix|null
	 */
	public function get( string $id ) {
		$fixes = $this->fixes();
		return isset( $fixes[ $id ] ) ? $fixes[ $id ] : null;
	}

	/**
	 * Registered fix ids.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_keys( $this->fixes() );
	}

	/**
	 * Whether everything a fix requires is present on this site.
	 *
	 * @param Fix $fix Fix.
	 * @return bool
	 */
	public function is_available( Fix $fix ): bool {
		return '' === $this->unavailable_reason( $fix );
	}

	/**
	 * Why a fix cannot run on this site, or '' when it can.
	 *
	 * @param Fix $fix Fix.
	 * @return string Translated reason.
	 */
	public function unavailable_reason( Fix $fix ): string {
		try {
			$requires = (array) $fix->requires();
		} catch ( Throwable $throwable ) {
			return __( 'Not available on this site', 'tagmend-accessibility-fixes' );
		}
		foreach ( $requires as $requirement ) {
			if ( 'wp69' === $requirement ) {
				if ( ! self::page_pass_exists() ) {
					return __( 'Needs WordPress 6.9 or later', 'tagmend-accessibility-fixes' );
				}
			} elseif ( 'woocommerce' === $requirement ) {
				if ( ! class_exists( 'WooCommerce' ) ) {
					return __( 'Needs WooCommerce', 'tagmend-accessibility-fixes' );
				}
			} else {
				return __( 'Not available on this site', 'tagmend-accessibility-fixes' );
			}
		}
		return '';
	}

	/**
	 * Whether core's template enhancement output buffer exists (WordPress 6.9+).
	 * The function name is only tested, never called.
	 *
	 * @return bool
	 */
	public static function page_pass_exists(): bool {
		return function_exists( 'wp_should_output_buffer_template_for_enhancement' );
	}

	/**
	 * Forgets the built list (tests, and after the filter's callbacks change).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->fixes = null;
	}

	/**
	 * The id of a valid fix object, or '' when the entry is not usable.
	 *
	 * @param mixed $fix Candidate.
	 * @return string
	 */
	private static function valid_id( $fix ): string {
		if ( ! $fix instanceof Fix ) {
			return '';
		}
		try {
			$id = $fix->id();
		} catch ( Throwable $throwable ) {
			return '';
		}
		if ( strlen( $id ) > self::ID_MAX || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return '';
		}
		return $id;
	}
}
