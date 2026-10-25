<?php
/**
 * F15: hidden duplicate links are taken out of the tab order.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cards often repeat their title link around the image and hide that copy
 * with aria-hidden="true", which leaves an unnamed stop in the tab order.
 * A link with href that itself carries aria-hidden="true" and no tabindex
 * gets tabindex="-1" when a visible, named link with the same href that
 * the keyboard can reach is in the same article, or within 10 tags before
 * or after it. The copy stays clickable; keyboard users reach the same
 * destination through the visible link. Reads the shared accessible-name
 * records only; links inside an aria-hidden container are left to F16.
 */
final class Fix_Hidden_Duplicate_Links extends Fix_Base {

	/**
	 * How many tag openers apart the two links may be outside a shared article.
	 */
	const WINDOW = 10;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'hidden-duplicate-links';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Take hidden duplicate links out of the tab order', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '4.1.2' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'aria-hidden-focus' );
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
	 * Risk: the copy stays clickable and the destination stays reachable.
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
	 * Decides from the accessible-name records in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Reads the shared accessible-name records.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * Not used: the fix decides in finish().
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}

	/**
	 * Pairs each hidden link with a visible one to the same address nearby.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$hidden  = array();
		$visible = array();
		foreach ( $index->names() as $link ) {
			if ( Accessible_Name::KIND_LINK !== $link->kind || 'A' !== $link->tag || ! $link->has( 'href' ) ) {
				continue;
			}
			$href = $link->attr( 'href' );
			if ( '' === $href || '#' === $href ) {
				continue;
			}
			if ( 'true' === strtolower( $link->attr( 'aria-hidden' ) ) ) {
				if ( ! $link->in_hidden && ! $link->has( 'tabindex' ) && ! $link->has( 'hidden' ) ) {
					$hidden[] = $link;
				}
				continue;
			}
			if ( ! $link->hidden && $link->is_named() && self::reachable( $link ) ) {
				$visible[ $href ][] = $link;
			}
		}

		foreach ( $hidden as $link ) {
			$href = $link->attr( 'href' );
			if ( isset( $visible[ $href ] ) && self::has_partner( $link, $visible[ $href ] ) ) {
				$this->plan_at( $plan, $link->snapshot(), 'tabindex', '-1', null );
			}
		}
	}

	/**
	 * Whether a visible link is in the same article or within the window.
	 *
	 * @param Accessible_Name   $link     The hidden link.
	 * @param Accessible_Name[] $partners Visible links with the same href.
	 * @return bool
	 */
	private static function has_partner( Accessible_Name $link, array $partners ): bool {
		foreach ( $partners as $partner ) {
			if ( abs( $partner->ordinal - $link->ordinal ) <= self::WINDOW ) {
				return true;
			}
			if ( null !== $link->article && $link->article === $partner->article ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the keyboard can reach a link: no negative tabindex (HTML integer rules).
	 *
	 * @param Accessible_Name $link Link record.
	 * @return bool
	 */
	private static function reachable( Accessible_Name $link ): bool {
		return ! $link->has( 'tabindex' ) || 1 !== preg_match( '/^[\t\n\f\r ]*-[0-9]/', (string) $link->raw( 'tabindex' ) );
	}
}
