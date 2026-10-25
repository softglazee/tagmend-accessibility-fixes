<?php
/**
 * F21: links that open a new tab say so in their description.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
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
 * An a[href][target=_blank] that is not hidden and whose name and
 * description do not already mention a new tab or window gets the id of a
 * hidden note appended to its aria-describedby (existing ids are kept). The
 * note itself, "opens in a new tab", is printed once by Front_End on
 * wp_footer. Outside scans, the fix plans the reference only when the note
 * will exist: on a whole page when the note is in it, in content fragments
 * until wp_footer has passed without printing it. Off by default: advisory
 * (3.2.5 is AAA).
 */
final class Fix_New_Tab_Note extends Fix_Base {

	/**
	 * Id of the hidden note element.
	 */
	const NOTE_ID = 'softglaze-a11y-newtab-note';

	/**
	 * Wording already in a link's name or description that makes the note unnecessary (English).
	 */
	const WORDING = '/\bnew\s+(?:tab|window)\b/iu';

	/**
	 * Whether content runs may still reference the note (Front_End sets it).
	 *
	 * @var bool
	 */
	private $note_available = true;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'new-tab-note';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Say when a link opens a new tab', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Tells screen-reader users that a link opens in a new tab, through a hidden note connected with aria-describedby. Starts Off because WCAG places this at its highest level, which most sites do not aim for.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '3.2.5' );
	}

	/**
	 * No axe rule (advisory).
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array();
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
	 * Risk.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'low';
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
	 * Same group as F05 and F06.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * Decides from the naming facts in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Reads the naming facts.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * The text of the hidden note.
	 *
	 * @return string
	 */
	public static function note_text(): string {
		return __( 'opens in a new tab', 'tagmend-accessibility-fixes' );
	}

	/**
	 * Tells content runs whether the note will be on the page (false once
	 * wp_footer passed without printing it).
	 *
	 * @param bool $available Whether references may be added.
	 * @return void
	 */
	public function set_note_available( bool $available ): void {
		$this->note_available = $available;
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
	 * Appends the note's id to the description of every new-tab link that needs it.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( ! $this->may_describe( $index ) ) {
			return;
		}
		$note = self::plain_text( self::note_text() );
		foreach ( $index->names() as $link ) {
			if ( Accessible_Name::KIND_LINK !== $link->kind || 'A' !== $link->tag || ! $link->has( 'href' ) || $link->hidden || '_blank' !== strtolower( $link->attr( 'target' ) ) ) {
				continue;
			}
			$ids = (array) preg_split( '/\s+/', $link->attr( 'aria-describedby' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( in_array( self::NOTE_ID, $ids, true ) ) {
				continue;
			}
			$words = array( $link->name() );
			foreach ( $ids as $id ) {
				$words[] = (string) $index->id_text( (string) $id );
			}
			if ( 'title' !== $link->source() ) {
				$words[] = $link->attr( 'title' );
			}
			$words = implode( ' ', $words );
			if ( 1 === preg_match( self::WORDING, $words ) || ( '' !== $note && false !== mb_stripos( $words, $note ) ) ) {
				continue;
			}
			$ids[] = self::NOTE_ID;
			$this->plan_at( $plan, $link->snapshot(), 'aria-describedby', implode( ' ', $ids ), $link->raw( 'aria-describedby' ) );
		}
	}

	/**
	 * Whether references to the note may be planned in this run: always in a
	 * scan (nothing is applied, the report shows what would change); on a
	 * whole page only when the note is in it; in fragments until wp_footer
	 * passed without printing it.
	 *
	 * @param Document_Index $index The complete index.
	 * @return bool
	 */
	private function may_describe( Document_Index $index ): bool {
		if ( Engine::MODE_SCAN === $index->mode ) {
			return true;
		}
		if ( Engine::SCOPE_PAGE === $index->scope ) {
			return $index->id_count( self::NOTE_ID ) > 0;
		}
		return $this->note_available;
	}
}
