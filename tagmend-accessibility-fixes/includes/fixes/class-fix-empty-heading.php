<?php
/**
 * F20: empty headings are hidden from assistive technology.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An h1-h6 that holds no text (whitespace and no-break spaces count as
 * empty) and no element at all (br and wbr aside) gets aria-hidden="true",
 * unless that would change the rest of the heading outline:
 *
 * - the next heading may not end up skipping a level (an empty h2 between
 *   an h1 and an h3 stays, and R02 lists it); at the start of a fragment,
 *   where the heading before is unknown, the next heading may not be
 *   deeper than the empty one;
 * - an h1 is hidden only on a whole page that has another h1.
 *
 * Left alone as well: headings with aria-label, aria-labelledby, title,
 * role, hidden or any aria-hidden value, headings inside an aria-hidden
 * element, and headings with an id (something may reference it, and
 * references are not collected for every element).
 */
final class Fix_Empty_Heading extends Fix_Base {

	/**
	 * Key in Element::$data for the heading's position in the outline.
	 */
	const DATA_KEY = 'empty-heading';

	/**
	 * Headings in the outline, in order: level, snapshot, may_hide, empty.
	 *
	 * @var array[]
	 */
	private $outline = array();

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'empty-heading';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Hide empty headings', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Hides headings that have no text from screen readers. A heading whose removal would leave a gap in the heading levels stays and is listed in the report.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.3.1', '2.4.6' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'empty-heading' );
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
	 * Risk: a script may fill the heading later.
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
	 * Heading openers and closers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );
	}

	/**
	 * Forgets the previous outline.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->outline = array();
	}

	/**
	 * Adds headings that screen readers list to the outline; notes on the
	 * closer whether the heading is empty.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$frame = $token->element;
		if ( null === $frame ) {
			return;
		}
		if ( $token->is_closer ) {
			if ( isset( $frame->data[ self::DATA_KEY ] ) ) {
				$this->outline[ $frame->data[ self::DATA_KEY ] ]['empty'] = 0 === $frame->elements && '' === $frame->normalized_text();
			}
			return;
		}
		$role = strtolower( $token->attr_text( 'role' ) );
		if ( $token->is_aria_hidden() || $token->inside_aria_hidden() || null !== $token->attr( 'hidden' ) || ( '' !== $role && 'heading' !== $role ) ) {
			return;
		}
		$frame->data[ self::DATA_KEY ] = count( $this->outline );
		$this->outline[]               = array(
			'level'    => (int) substr( $token->name, 1 ),
			'snapshot' => $token->snapshot( $recorder->is_recording() ),
			'may_hide' => self::may_hide( $token ),
			'empty'    => false,
		);
	}

	/**
	 * Hides the empty headings whose removal keeps the outline as it is.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$h1 = 0;
		foreach ( $this->outline as $heading ) {
			$h1 += 1 === $heading['level'] ? 1 : 0;
		}
		$candidates = array();
		foreach ( $this->outline as $i => $heading ) {
			$candidates[ $i ] = $heading['empty'] && $heading['may_hide']
				&& ( 1 !== $heading['level'] || ( Engine::SCOPE_PAGE === $index->scope && $h1 > 1 ) );
		}

		$previous = null;
		foreach ( $this->outline as $i => $heading ) {
			if ( ! $candidates[ $i ] ) {
				$previous = $heading['level'];
				continue;
			}
			$next = null;
			for ( $j = $i + 1, $count = count( $this->outline ); $j < $count; $j++ ) {
				if ( ! $candidates[ $j ] ) {
					$next = $this->outline[ $j ]['level'];
					break;
				}
			}
			if ( null === $next || $next <= ( null === $previous ? $heading['level'] : $previous + 1 ) ) {
				$this->plan_at( $plan, $heading['snapshot'], 'aria-hidden', 'true', null );
				continue;
			}
			$previous = $heading['level'];
			$recorder->add_finding(
				'R02',
				'1.3.1',
				$heading['snapshot']['sig'],
				sprintf(
					/* translators: 1: level of the empty heading, for example 2; 2: level of the next heading, for example 3 */
					__( 'Empty h%1$d heading left in place: hiding it would make the next heading, h%2$d, skip a level. Add text to it or remove it.', 'tagmend-accessibility-fixes' ),
					$heading['level'],
					$next
				)
			);
		}
	}

	/**
	 * Whether nothing on the heading's own opener rules hiding out.
	 *
	 * @param Token $token Heading opener.
	 * @return bool
	 */
	private static function may_hide( Token $token ): bool {
		foreach ( array( 'aria-label', 'aria-labelledby', 'title', 'id' ) as $attribute ) {
			if ( '' !== $token->attr_text( $attribute ) ) {
				return false;
			}
		}
		foreach ( array( 'role', 'aria-hidden' ) as $attribute ) {
			if ( null !== $token->attr( $attribute ) ) {
				return false;
			}
		}
		return true;
	}
}
