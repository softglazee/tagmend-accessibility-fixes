<?php
/**
 * F10: the html element gets a valid lang attribute.
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
 * Sets lang on an html element that has none, an empty one, or one written
 * with underscores (en_US). A well-formed value set by the theme or a
 * multilingual plugin is never replaced.
 */
final class Fix_Html_Lang extends Fix_Base {

	/**
	 * Well-formed language tag: a 2-3 letter primary subtag, then subtags of 1-8 letters or digits.
	 */
	const TAG_PATTERN = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/';

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'html-lang';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Page language', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Sets the language of the page from the site language when the html element has no lang attribute or a malformed one (en_US becomes en-US). A valid language set by the theme or a translation plugin is never changed.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '3.1.1' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'html-has-lang', 'html-lang-valid' );
	}

	/**
	 * Scopes: the language_attributes hook and the page pass.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'attribute', 'page' );
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
	 * Only the html opener.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'HTML' );
	}

	/**
	 * Plans lang on the html element when it is missing or malformed.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || 'HTML' !== $token->name || 'page' !== $index->scope ) {
			return;
		}

		$value = $this->repaired_value( $token->attr( 'lang' ) );
		if ( null === $value ) {
			return;
		}
		if ( '' === $value ) {
			$recorder->add_finding( $this->id(), '3.1.1', $recorder->is_recording() ? $token->signature() : '', __( 'The site language is not a valid language tag, so lang was not set.', 'tagmend-accessibility-fixes' ) );
			return;
		}
		$this->plan_edit( $token, $plan, $recorder, 'lang', $value );
	}

	/**
	 * The lang value to set: null when the current value is fine, '' when no
	 * valid value is available.
	 *
	 * @param string|true|null $current Current attribute value.
	 * @return string|null
	 */
	public function repaired_value( $current ) {
		$current = is_string( $current ) ? trim( $current ) : '';

		if ( '' !== $current ) {
			if ( preg_match( self::TAG_PATTERN, $current ) ) {
				return null;
			}
			$corrected = str_replace( '_', '-', $current );
			if ( preg_match( self::TAG_PATTERN, $corrected ) ) {
				return $corrected;
			}
		}

		$site = trim( (string) get_bloginfo( 'language' ) );
		return preg_match( self::TAG_PATTERN, $site ) ? $site : '';
	}
}
