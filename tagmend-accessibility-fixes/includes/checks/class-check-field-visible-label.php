<?php
/**
 * R06: form fields without a visible label.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every field (input other than hidden and buttons, select, textarea)
 * that is not hidden and has no label element with text and no valid
 * aria-labelledby: fields named only by a placeholder, an aria-label or a
 * title, including those F08 names, and fields with no name at all. An
 * accessible name does not help sighted users, and a placeholder disappears
 * once someone types.
 */
final class Check_Field_Visible_Label extends Check_Base {

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R06';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '3.3.2';
	}

	/**
	 * Decides from the naming facts in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( Fix_Base::FINISH_ONLY );
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
	 * Not used: the check decides in finish().
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
	}

	/**
	 * Lists the fields without a visible label.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		foreach ( $index->names() as $field ) {
			if ( Accessible_Name::KIND_FIELD !== $field->kind || $field->hidden || $field->has_label() || 'aria-labelledby' === $field->source() ) {
				continue;
			}
			$this->report( $recorder, $field->sig, __( 'Field has no visible label: add a visible label in the form settings.', 'tagmend-accessibility-fixes' ) );
		}
	}
}
