<?php
/**
 * F22: a visible focus outline from a fixed local stylesheet.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes no markup: when the fix applies, Front_End enqueues
 * assets/css/focus-visible.css (an outline in the current text colour on
 * :focus-visible). The stylesheet is a fixed file; nothing about it is a
 * setting. Off by default because it changes how the site looks.
 */
final class Fix_Focus_Visible extends Fix_Base {

	/**
	 * Our stylesheet.
	 */
	const STYLE_HANDLE = 'softglaze-a11y-focus-visible';
	const STYLE_FILE   = 'assets/css/focus-visible.css';

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'focus-visible';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Visible focus outline', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Adds a small stylesheet that draws a 2-pixel outline in the text colour around the element that has keyboard focus. Starts Off because it changes how the site looks: set it to Preview, open a page with "View repaired" in the admin bar and press Tab to see the outline before you set it Live.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.7' );
	}

	/**
	 * No axe rule (checked visually).
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array();
	}

	/**
	 * No markup scope: the engine never runs this fix.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array();
	}

	/**
	 * Risk: a visible design change.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'medium';
	}

	/**
	 * Starts Off.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return false;
	}

	/**
	 * Takes no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Not used: the fix only enqueues a stylesheet.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}
}
