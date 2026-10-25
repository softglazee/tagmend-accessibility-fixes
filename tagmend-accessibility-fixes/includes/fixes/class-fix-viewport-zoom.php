<?php
/**
 * F13: the viewport meta stops blocking zoom.
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
 * Rewrites the content of meta name="viewport" without user-scalable=no|0
 * and without a maximum-scale below 2, keeping every other key in order.
 */
final class Fix_Viewport_Zoom extends Fix_Base {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'viewport-zoom';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Allow zooming', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Removes user-scalable=no and a maximum-scale below 2 from the viewport tag, so visitors can zoom the page on phones and tablets.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.4.4' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'meta-viewport' );
	}

	/**
	 * Scopes: the head is reached only by the page pass.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'page' );
	}

	/**
	 * Risk.
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
	 * The page pass exists from WordPress 6.9.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'wp69' );
	}

	/**
	 * Only meta openers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'META' );
	}

	/**
	 * Plans a new content value for a zoom-blocking viewport meta.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || 'META' !== $token->name || 'viewport' !== strtolower( $token->attr_text( 'name' ) ) ) {
			return;
		}

		$content = $token->attr( 'content' );
		if ( ! is_string( $content ) ) {
			return;
		}

		$repaired = $this->repaired_content( $content );
		if ( null !== $repaired ) {
			$this->plan_edit( $token, $plan, $recorder, 'content', $repaired );
		}
	}

	/**
	 * The content without the zoom-blocking keys, or null when nothing blocks zoom.
	 *
	 * @param string $content Decoded content attribute.
	 * @return string|null
	 */
	public function repaired_content( string $content ) {
		$items   = preg_split( '/[,;]/', $content );
		$kept    = array();
		$changed = false;

		foreach ( (array) $items as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			$pair  = explode( '=', $item, 2 );
			$key   = strtolower( trim( $pair[0] ) );
			$value = isset( $pair[1] ) ? strtolower( trim( $pair[1] ) ) : '';

			if ( 'user-scalable' === $key && ( 'no' === $value || '0' === $value ) ) {
				$changed = true;
				continue;
			}
			if ( 'maximum-scale' === $key && is_numeric( $value ) && (float) $value < 2 ) {
				$changed = true;
				continue;
			}
			$kept[] = $item;
		}

		return $changed ? self::plain_text( implode( ', ', $kept ), 500 ) : null;
	}
}
