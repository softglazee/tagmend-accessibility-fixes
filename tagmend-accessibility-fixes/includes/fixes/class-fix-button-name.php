<?php
/**
 * F06: buttons without a name get one from a reliable source.
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
 * A button, input[type=submit|button|reset] or role="button" element that
 * is not hidden and has no accessible name in the original markup gets one
 * from the first source that fits: inside a search form → "Search";
 * aria-controls pointing at a nav element or menu list, or a menu-toggle
 * class → "Menu"; a close or dismiss class → "Close"; the icon-class map of
 * F05; a plain submit input → "Submit". The repair is an aria-label, or the
 * value of an input[type=submit] whose value attribute is empty. Otherwise
 * the button is listed as R05.
 */
final class Fix_Button_Name extends Fix_Base {

	/**
	 * Menu-toggle class names (substring of one class name).
	 */
	const MENU_CLASS = '/(?:menu-toggle|hamburger|navbar-toggler|mobile-menu)/';

	/**
	 * Close-button class names (a whole class name, or a part between hyphens or underscores).
	 */
	const CLOSE_CLASS = '/(?:^|[-_])(?:close|dismiss)(?:[-_]|$)/';

	/**
	 * F05, for its icon-class map (one per run).
	 *
	 * @var Fix_Link_Name|null
	 */
	private $icons = null;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'button-name';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Names for empty buttons', 'tagmend-accessibility-fixes' );
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
		return array( 'button-name', 'input-button-name' );
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
		return 'medium';
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
	 * Before F07, which hides icons only in buttons that end up named.
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
	 * Forgets the icon map of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->icons = null;
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
	 * Names every unnamed button a source fits, lists the rest.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $index->names() as $button ) {
			if ( Accessible_Name::KIND_BUTTON !== $button->kind || 'image' === $button->type || $button->hidden || $button->is_named() ) {
				continue;
			}
			// An empty value on a submit input is repaired in place, so the button shows its label too.
			$value_repair = 'INPUT' === $button->tag && 'submit' === $button->type && $button->has( 'value' );
			$label        = $this->label_for( $button, $index );
			if ( '' === $label && $value_repair ) {
				$label = __( 'Submit', 'tagmend-accessibility-fixes' );
			}
			if ( '' !== $label ) {
				$attribute = $value_repair ? 'value' : 'aria-label';
				$this->plan_at( $plan, $button->snapshot(), $attribute, self::plain_text( $label ), $button->raw( $attribute ) );
				continue;
			}
			if ( $recorder->is_recording() ) {
				$recorder->add_finding( 'R05', '4.1.2', $button->sig, __( 'Button has no text, and no label source fits: add visible text or an aria-label.', 'tagmend-accessibility-fixes' ) );
			}
		}
	}

	/**
	 * The label for an unnamed button, or ''.
	 *
	 * @param Accessible_Name $button Naming facts of the button.
	 * @param Document_Index  $index  The complete index.
	 * @return string
	 */
	public function label_for( Accessible_Name $button, Document_Index $index ): string {
		if ( null !== $button->form && $button->form->search ) {
			return __( 'Search', 'tagmend-accessibility-fixes' );
		}
		foreach ( (array) preg_split( '/\s+/', $button->attr( 'aria-controls' ), -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
			if ( $index->is_menu( (string) $id ) ) {
				return __( 'Menu', 'tagmend-accessibility-fixes' );
			}
		}
		$classes = (array) preg_split( '/\s+/', strtolower( $button->attr( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $classes as $class ) {
			if ( 1 === preg_match( self::MENU_CLASS, (string) $class ) ) {
				return __( 'Menu', 'tagmend-accessibility-fixes' );
			}
		}
		foreach ( $classes as $class ) {
			if ( 1 === preg_match( self::CLOSE_CLASS, (string) $class ) ) {
				return __( 'Close', 'tagmend-accessibility-fixes' );
			}
		}
		if ( null === $this->icons ) {
			$this->icons = new Fix_Link_Name();
		}
		return $this->icons->icon_label( $button->icon_classes );
	}
}
