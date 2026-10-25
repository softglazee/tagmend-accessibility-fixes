<?php
/**
 * F17: repeated form-field ids are made unique together with their labels.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Field_Ids;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the same form is printed twice, its fields repeat their ids and the
 * second form's labels point at the first form's fields. A later use of an
 * id that is a form control inside a form holding a label for it (and no
 * other use of that id) gets "{id}-softglaze-a11y-{n}"; the label for
 * attributes and the aria-describedby / aria-labelledby references inside
 * the same form follow it. References elsewhere keep pointing at the first
 * use, as browsers already resolve them. The rules live in Field_Ids, which
 * R11 reads too. Needs the whole page (every use of the id must be known).
 */
final class Fix_Duplicate_Field_Ids extends Fix_Base {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'duplicate-field-ids';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Unique ids for repeated form fields', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Gives a form field whose id is repeated on the page an id of its own, and updates the label and ARIA references in the same form. Starts Off because scripts and styles that select the old id no longer match the renamed field.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.3.1', '4.1.2' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'duplicate-id-aria', 'label' );
	}

	/**
	 * Scopes: every use of an id must be known.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'page' );
	}

	/**
	 * Risk: scripts and styles that select the field by its id.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'high';
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
	 * The page pass exists from WordPress 6.9.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'wp69' );
	}

	/**
	 * Form controls, labels and fieldsets.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return Field_Ids::TAGS;
	}

	/**
	 * Hands the opener to the shared form-field facts.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		$index->field_ids()->observe( $token, $recorder->is_recording() );
	}

	/**
	 * Plans the renamed ids and the references that follow them.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( Engine::SCOPE_PAGE !== $index->scope ) {
			return;
		}
		foreach ( $index->field_ids()->edits( $index ) as $edit ) {
			$this->plan_at( $plan, $edit[0], $edit[1], $edit[2], $edit[3] );
		}
	}
}
