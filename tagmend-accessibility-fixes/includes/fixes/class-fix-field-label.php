<?php
/**
 * F08: form fields without a label get an accessible name.
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
 * An input (not hidden or a button), select or textarea that is not hidden
 * and has no label[for] or wrapping label with text, no aria-label, no valid
 * aria-labelledby and no title gets an aria-label from its placeholder; a
 * field named "s" or of type search gets "Search"; a small map of
 * unambiguous field names gives the rest. Otherwise the field is listed as
 * R05. The R06 check lists every field without a visible label, including
 * those named here, because an aria-label does not help sighted users.
 */
final class Fix_Field_Label extends Fix_Base {

	/**
	 * Field-name map of this run, built when first needed.
	 *
	 * @var string[]|null
	 */
	private $field_map = null;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'field-label';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Names for form fields without a label', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Names form fields that have no label with an aria-label from their placeholder, from a search field or from a known field name such as email or phone. The report still lists these fields, because the name is not visible.', 'tagmend-accessibility-fixes' );
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
		return array( 'label', 'select-name' );
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
	 * Starts in Preview.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
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
	 * Forgets the map of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->field_map = null;
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
	 * Names every unlabelled field a source fits, lists the rest.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $index->names() as $field ) {
			if ( ! self::is_unlabelled( $field ) ) {
				continue;
			}
			$label = $this->label_for( $field );
			if ( '' !== $label ) {
				$this->plan_at( $plan, $field->snapshot(), 'aria-label', $label, $field->raw( 'aria-label' ) );
				continue;
			}
			if ( $recorder->is_recording() ) {
				$recorder->add_finding( 'R05', '1.3.1', $field->sig, __( 'Form field has no label, and no label source fits: add a visible label in the form settings.', 'tagmend-accessibility-fixes' ) );
			}
		}
	}

	/**
	 * Whether a field has nothing but (at most) a placeholder to name it.
	 *
	 * @param Accessible_Name $field Naming facts.
	 * @return bool
	 */
	public static function is_unlabelled( Accessible_Name $field ): bool {
		if ( Accessible_Name::KIND_FIELD !== $field->kind || $field->hidden || $field->has_label() ) {
			return false;
		}
		return in_array( $field->source(), array( '', 'placeholder' ), true );
	}

	/**
	 * The label for an unlabelled field, or ''.
	 *
	 * @param Accessible_Name $field Naming facts.
	 * @return string
	 */
	public function label_for( Accessible_Name $field ): string {
		$placeholder = self::plain_text( $field->attr( 'placeholder' ) );
		if ( '' !== $placeholder && 'SELECT' !== $field->tag ) {
			return $placeholder;
		}
		$name = strtolower( $field->attr( 'name' ) );
		if ( 's' === $name || 'search' === $field->type ) {
			return __( 'Search', 'tagmend-accessibility-fixes' );
		}
		if ( null === $this->field_map ) {
			/**
			 * Filters the field-name map of F08: lower-case name attribute of an
			 * unlabelled field => label (plain text).
			 *
			 * @param string[] $map Field name => label.
			 */
			$map             = apply_filters( 'softglaze_a11y_field_label_map', self::field_label_map() );
			$this->field_map = array();
			foreach ( is_array( $map ) ? $map : array() as $key => $label ) {
				if ( is_string( $key ) && is_string( $label ) && '' !== trim( $key ) && '' !== self::plain_text( $label ) ) {
					$this->field_map[ strtolower( trim( $key ) ) ] = self::plain_text( $label );
				}
			}
		}
		return '' !== $name && isset( $this->field_map[ $name ] ) ? $this->field_map[ $name ] : '';
	}

	/**
	 * The bundled field-name map: name attribute => label.
	 *
	 * @return string[]
	 */
	public static function field_label_map(): array {
		$email    = __( 'Email', 'tagmend-accessibility-fixes' );
		$phone    = __( 'Phone', 'tagmend-accessibility-fixes' );
		$message  = __( 'Message', 'tagmend-accessibility-fixes' );
		$quantity = __( 'Quantity', 'tagmend-accessibility-fixes' );
		return array(
			'email'        => $email,
			'your-email'   => $email,
			'user_email'   => $email,
			'your-name'    => __( 'Name', 'tagmend-accessibility-fixes' ),
			'phone'        => $phone,
			'tel'          => $phone,
			'your-message' => $message,
			'message'      => $message,
			'quantity'     => $quantity,
			'qty'          => $quantity,
			'coupon_code'  => __( 'Coupon code', 'tagmend-accessibility-fixes' ),
			'postcode'     => __( 'Postcode', 'tagmend-accessibility-fixes' ),
		);
	}
}
