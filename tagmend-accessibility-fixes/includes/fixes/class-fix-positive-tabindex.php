<?php
/**
 * F14: positive tabindex values become 0.
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
 * Any element whose tabindex parses as an integer above 0 (HTML integer
 * rules: leading whitespace, an optional "+", digits) gets tabindex="0",
 * so focus follows the reading order. "0", negative values and values that
 * are not numbers are left alone, and so is the "Skip to toolbar" shortcut
 * of the logged-in toolbar.
 */
final class Fix_Positive_Tabindex extends Fix_Base {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'positive-tabindex';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Remove positive tabindex values', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.3' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'tabindex' );
	}

	/**
	 * Scopes.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'content', 'page' );
	}

	/**
	 * Risk: a hand-built tab order in a form becomes document order.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'low';
	}

	/**
	 * Starts in Preview.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
	}

	/**
	 * Every opener: tabindex can be on any element.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array();
	}

	/**
	 * Plans tabindex="0" for a positive value.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer ) {
			return;
		}
		$value = $token->attr( 'tabindex' );
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[\t\n\f\r ]*\+?0*[1-9]/', $value ) ) {
			return;
		}
		if ( '#wp-toolbar' === $token->attr_text( 'href' ) && in_array( 'screen-reader-shortcut', Fix_Skip_Link::classes( $token ), true ) ) {
			return;
		}
		$this->plan_edit( $token, $plan, $recorder, 'tabindex', '0' );
	}
}
