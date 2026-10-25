<?php
/**
 * R12: controls built from elements that are not controls.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists elements with role="button", "link" or "tab" that the keyboard
 * cannot reach (not a native control, no tabindex, not aria-disabled), and
 * elements with an onclick attribute that are neither native controls nor
 * given a control role. Sees every opener: role and onclick can be on any
 * element (checks never run for visitors).
 */
final class Check_Non_Native_Controls extends Check_Base {

	/**
	 * Roles that need keyboard focus and are often put on a div or span.
	 */
	const FOCUS_ROLES = array(
		'button' => true,
		'link'   => true,
		'tab'    => true,
	);

	/**
	 * Roles that make an element with a click handler a control.
	 */
	const CONTROL_ROLES = array(
		'button'           => true,
		'checkbox'         => true,
		'combobox'         => true,
		'link'             => true,
		'menuitem'         => true,
		'menuitemcheckbox' => true,
		'menuitemradio'    => true,
		'option'           => true,
		'radio'            => true,
		'searchbox'        => true,
		'slider'           => true,
		'spinbutton'       => true,
		'switch'           => true,
		'tab'              => true,
		'textbox'          => true,
		'treeitem'         => true,
	);

	/**
	 * Elements a click handler may sit on without a role: native controls and
	 * the document elements (handlers there usually delegate).
	 */
	const CLICKABLE = array(
		'BODY'     => true,
		'BUTTON'   => true,
		'HTML'     => true,
		'INPUT'    => true,
		'LABEL'    => true,
		'OPTION'   => true,
		'SELECT'   => true,
		'SUMMARY'  => true,
		'TEXTAREA' => true,
	);

	/**
	 * Findings waiting for finish(): signature, success criterion, detail.
	 *
	 * @var array[]
	 */
	private $found = array();

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R12';
	}

	/**
	 * WCAG success criterion (onclick findings name 4.1.2).
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '2.1.1';
	}

	/**
	 * Every opener that carries a role or a click handler (both can be on any element).
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( Fix_Base::WITH_ATTRIBUTE . 'role', Fix_Base::WITH_ATTRIBUTE . 'onclick' );
	}

	/**
	 * Forgets the previous document.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->found = array();
	}

	/**
	 * Notes control roles the keyboard cannot reach and click handlers on non-controls.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( $token->is_closer ) {
			return;
		}
		$role    = $token->attr( 'role' );
		$onclick = $token->attr( 'onclick' );
		if ( null === $role && null === $onclick ) {
			return;
		}
		$roles  = is_string( $role ) ? (array) preg_split( '/\s+/', strtolower( trim( $role ) ), -1, PREG_SPLIT_NO_EMPTY ) : array();
		$first  = empty( $roles ) ? '' : (string) $roles[0];
		$native = isset( self::CLICKABLE[ $token->name ] ) || ( ( 'A' === $token->name || 'AREA' === $token->name ) && null !== $token->attr( 'href' ) );

		if ( isset( self::FOCUS_ROLES[ $first ] ) && ! $native && null === $token->attr( 'tabindex' ) && 'true' !== strtolower( $token->attr_text( 'aria-disabled' ) ) ) {
			$this->found[] = array(
				$recorder->is_recording() ? $token->signature() : '',
				'2.1.1',
				sprintf(
					/* translators: %s: an ARIA role, for example "button" */
					__( 'Element with role="%s" cannot be reached with the keyboard: use a real button or link, or add tabindex="0" and key handling.', 'tagmend-accessibility-fixes' ),
					$first
				),
			);
			return;
		}
		if ( null !== $onclick && ! $native && ! isset( self::CONTROL_ROLES[ $first ] ) ) {
			$this->found[] = array(
				$recorder->is_recording() ? $token->signature() : '',
				'4.1.2',
				__( 'Element has a click handler but is not a control: keyboard and screen-reader users may not be able to use it.', 'tagmend-accessibility-fixes' ),
			);
		}
	}

	/**
	 * Reports what was found, in document order.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		foreach ( $this->found as $found ) {
			$recorder->add_finding( $this->id(), $found[1], $found[0], $found[2] );
		}
	}
}
