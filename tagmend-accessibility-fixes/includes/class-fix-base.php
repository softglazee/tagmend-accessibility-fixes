<?php
/**
 * Shared behaviour of the bundled fixes.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for fixes. Adds what the engine needs beyond the interface
 * (which tags to dispatch, per-run reset, a deferred finish step, priority)
 * and the helpers that keep every planned value plain text.
 */
abstract class Fix_Base implements Fix {

	/**
	 * Labels and other planned text are capped at this many characters.
	 */
	const MAX_TEXT = 150;

	/**
	 * Returned alone by tags() when a fix needs no tokens: it decides in
	 * finish() from the index (for example from Document_Index::names()).
	 */
	const FINISH_ONLY = '#finish';

	/**
	 * Listed in tags() (alone or with tag names): the fix also sees every
	 * opener that carries aria-hidden="true" or sits inside such an element,
	 * whatever its tag (openers only, each one once).
	 */
	const IN_HIDDEN = '#aria-hidden';

	/**
	 * Prefix of an attribute entry in tags(): '@tabindex' means every opener
	 * that carries a tabindex attribute, whatever its tag (openers only, each
	 * one once). Cheaper than seeing every opener when an attribute can sit on
	 * any element.
	 */
	const WITH_ATTRIBUTE = '@';

	/**
	 * What the fix changes, in one or two translated sentences for the Fixes
	 * screen. Fixes added through softglaze_a11y_register_fixes may leave it empty.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Upper-case tag names whose openers (and tracked closers) this fix sees.
	 * An empty array means every opener; array( self::FINISH_ONLY ) means none;
	 * self::IN_HIDDEN adds the openers in aria-hidden content; an entry
	 * starting with self::WITH_ATTRIBUTE adds the openers carrying that attribute.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array();
	}

	/**
	 * Whether the fix reads naming facts (Element::$accessible,
	 * Token::closest_control(), Document_Index::names()). The engine computes
	 * them once per element, and only when an active fix or check asks.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return false;
	}

	/**
	 * Whether the fix reads the main and navigation landmarks of the document
	 * (Document_Index::landmarks()). The engine reads every opener's role for
	 * them only when an active fix or check asks.
	 *
	 * @return bool
	 */
	public function uses_landmarks(): bool {
		return false;
	}

	/**
	 * Whether the fix reads which ids are named by an aria-controls attribute
	 * anywhere in the document (Document_Index::is_controlled()). The engine
	 * reads every opener's aria-controls only when an active fix or check asks.
	 *
	 * @return bool
	 */
	public function uses_controls(): bool {
		return false;
	}

	/**
	 * Lower runs earlier and wins when two fixes propose the same attribute.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 10;
	}

	/**
	 * Clears per-document state before a run.
	 *
	 * @return void
	 */
	public function reset(): void {
	}

	/**
	 * Runs after the last token, when the index is complete.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}

	/**
	 * The fix needs nothing beyond core by default.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array();
	}

	/**
	 * Proposes an attribute value for the current opener unless it already holds.
	 *
	 * @param Token    $token     Current opener.
	 * @param Plan     $plan      Planned edits.
	 * @param Recorder $recorder  Recorder (signatures are built only when it keeps details).
	 * @param string   $attribute Attribute name.
	 * @param string   $value     Plain text value.
	 * @return bool Whether an edit was planned.
	 */
	protected function plan_edit( Token $token, Plan $plan, Recorder $recorder, string $attribute, string $value ): bool {
		if ( $token->is_closer ) {
			return false;
		}
		$before = $token->attr( $attribute );
		if ( is_string( $before ) && $before === $value ) {
			return false;
		}
		return $plan->add(
			$token->ordinal,
			$token->name,
			$attribute,
			$value,
			$this->id(),
			$this->priority(),
			is_string( $before ) ? $before : null,
			$recorder->is_recording() ? $token->signature() : ''
		);
	}

	/**
	 * Proposes an attribute value for an element seen earlier (fixes that
	 * decide in finish() or on a closer) unless it already holds.
	 *
	 * @param Plan        $plan      Planned edits.
	 * @param array       $element   Token::snapshot() of the element's opener.
	 * @param string      $attribute Attribute name.
	 * @param string      $value     Plain text value.
	 * @param string|null $before    Current value, null when absent.
	 * @param array       $requires  Optional array( ordinal, attribute ): applied only when that edit is.
	 * @return bool Whether an edit was planned.
	 */
	protected function plan_at( Plan $plan, array $element, string $attribute, string $value, $before, array $requires = array() ): bool {
		if ( is_string( $before ) && $before === $value ) {
			return false;
		}
		return $plan->add( (int) $element['ordinal'], (string) $element['tag'], $attribute, $value, $this->id(), $this->priority(), $before, (string) $element['sig'], $requires );
	}

	/**
	 * Lower-case text. WordPress polyfills only mb_substr() and mb_strlen(),
	 * so without the mbstring extension only ASCII letters are lowered; every
	 * caller lowers both sides of a comparison the same way.
	 *
	 * @param string $text UTF-8 text.
	 * @return string
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Whether a text contains another, ignoring case (UTF-8 aware without mbstring).
	 *
	 * @param string $haystack Text searched.
	 * @param string $needle   Text looked for; '' never matches.
	 * @return bool
	 */
	public static function contains_text( string $haystack, string $needle ): bool {
		return '' !== $needle && 1 === preg_match( '/' . preg_quote( $needle, '/' ) . '/iu', $haystack );
	}

	/**
	 * A text with the first occurrence of another removed, ignoring case
	 * (UTF-8 aware without mbstring).
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Text to remove once.
	 * @return string
	 */
	public static function without_first( string $haystack, string $needle ): string {
		if ( '' === $needle ) {
			return $haystack;
		}
		$result = preg_replace( '/' . preg_quote( $needle, '/' ) . '/iu', '', $haystack, 1 );
		return is_string( $result ) ? $result : $haystack;
	}

	/**
	 * Turns text from a trusted source into plain text for set_attribute():
	 * tags stripped from the raw text first, then character references
	 * resolved, whitespace collapsed, trimmed and capped. Stripping first keeps
	 * an encoded "&lt;" as text ("Kids 3&lt;6 years" stays "Kids 3<6 years")
	 * instead of turning it into a tag that would be stripped. Never pass
	 * esc_attr() output here or onward: set_attribute() encodes the value itself.
	 *
	 * @param string $raw Raw text (may contain entities or markup).
	 * @param int    $max Maximum characters.
	 * @return string
	 */
	public static function plain_text( string $raw, int $max = self::MAX_TEXT ): string {
		$text = wp_strip_all_tags( $raw );
		// Resolve references until none are left, so a doubly encoded "&amp;amp;" reads as "&".
		for ( $round = 0; $round < 3; $round++ ) {
			$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		$text = (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );
		$text = trim( $text );
		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max ) );
		}
		return $text;
	}
}
