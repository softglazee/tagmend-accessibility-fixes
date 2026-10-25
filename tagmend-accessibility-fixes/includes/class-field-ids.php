<?php
/**
 * Form fields, labels and id references of one document.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What F17 (duplicate field ids) and R11 (duplicate ids it does not fix)
 * both need, gathered once per run: form elements with an id, labels with
 * a for attribute and the aria-describedby / aria-labelledby references on
 * form elements, each with the form it sits in. Document_Index::field_ids()
 * hands the same object to both; every token is taken once.
 *
 * The safe case: an id used more than once where a later use is a form
 * control inside a form that holds no other use of that id and at least one
 * label for it. That control gets "{id}-softglaze-a11y-{n}", and the label
 * for attributes and ARIA references inside the same form follow it. An id
 * is handled only when every element carrying it was seen here, so nothing
 * outside the observed tags can be changed by surprise.
 *
 * Entries are small numbered arrays (see the E_ and L_ constants): a page
 * that repeats a form many times holds many of them.
 */
final class Field_Ids {

	/**
	 * Tags whose openers are observed.
	 */
	const TAGS = array( 'BUTTON', 'FIELDSET', 'INPUT', 'LABEL', 'OUTPUT', 'SELECT', 'TEXTAREA' );

	/**
	 * Form controls a label can point at.
	 */
	const CONTROLS = array(
		'BUTTON'   => true,
		'INPUT'    => true,
		'SELECT'   => true,
		'TEXTAREA' => true,
	);

	/**
	 * Attributes that hold a list of ids and are rewritten inside the form.
	 */
	const REFERENCES = array( 'aria-describedby', 'aria-labelledby' );

	/**
	 * Inserted between the id and the number of a renamed control.
	 */
	const SUFFIX = '-softglaze-a11y-';

	/**
	 * Positions in an element entry: ordinal, tag, signature, trimmed id
	 * ('' when none), id as found (null when none), control, form ordinal
	 * (-1 outside a form), references (attribute => value, or null).
	 */
	const E_ORDINAL    = 0;
	const E_TAG        = 1;
	const E_SIG        = 2;
	const E_ID         = 3;
	const E_RAW        = 4;
	const E_CONTROL    = 5;
	const E_FORM       = 6;
	const E_REFERENCES = 7;

	/**
	 * Positions in a label entry: ordinal, signature, trimmed for, for as found, form ordinal.
	 */
	const L_ORDINAL = 0;
	const L_SIG     = 1;
	const L_FOR     = 2;
	const L_RAW     = 3;
	const L_FORM    = 4;

	/**
	 * Ordinal of the last opener taken (openers arrive in document order).
	 *
	 * @var int
	 */
	private $last = -1;

	/**
	 * Observed elements with an id or a reference, in document order.
	 *
	 * @var array[]
	 */
	private $elements = array();

	/**
	 * Labels with a for attribute, in document order.
	 *
	 * @var array[]
	 */
	private $labels = array();

	/**
	 * Computed edits and remaining duplicates, or null before the first call.
	 *
	 * @var array|null
	 */
	private $result = null;

	/**
	 * Takes one opener of an observed tag (a second call for the same opener is ignored).
	 *
	 * @param Token $token          Current token.
	 * @param bool  $with_signature Whether signatures are kept.
	 * @return void
	 */
	public function observe( Token $token, bool $with_signature ): void {
		if ( $token->is_closer || $token->ordinal <= $this->last ) {
			return;
		}
		$this->last = $token->ordinal;
		$form       = $token->closest( 'FORM' );
		$form       = null === $form ? -1 : $form->ordinal;

		if ( 'LABEL' === $token->name ) {
			$for = $token->attr( 'for' );
			if ( is_string( $for ) && '' !== trim( $for ) ) {
				$this->labels[] = array( $token->ordinal, $with_signature ? $token->signature() : '', trim( $for ), $for, $form );
			}
			return;
		}

		$id         = $token->attr( 'id' );
		$id         = is_string( $id ) ? $id : null;
		$references = null;
		foreach ( self::REFERENCES as $attribute ) {
			$value = $token->attr( $attribute );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$references[ $attribute ] = $value;
			}
		}
		if ( ( null === $id || '' === trim( $id ) ) && null === $references ) {
			return;
		}
		$this->elements[] = array(
			$token->ordinal,
			$token->name,
			$with_signature ? $token->signature() : '',
			null === $id ? '' : trim( $id ),
			$id,
			isset( self::CONTROLS[ $token->name ] ) && ! ( 'INPUT' === $token->name && 'hidden' === strtolower( $token->attr_text( 'type' ) ) ),
			$form,
			$references,
		);
	}

	/**
	 * The attribute edits of the safe case, each as array( element snapshot,
	 * attribute, value, value before ). Complete only once the analyse pass
	 * has ended (in finish()).
	 *
	 * @param Document_Index $index The complete index.
	 * @return array[]
	 */
	public function edits( Document_Index $index ): array {
		return $this->compute( $index )['edits'];
	}

	/**
	 * Ids still used more than once after the safe case: id => array( count, sig ),
	 * where sig is the signature of the first observed element carrying it ('' if none).
	 *
	 * @param Document_Index $index The complete index.
	 * @return array[]
	 */
	public function unfixed( Document_Index $index ): array {
		return $this->compute( $index )['unfixed'];
	}

	/**
	 * Works out both lists once.
	 *
	 * @param Document_Index $index The complete index.
	 * @return array
	 */
	private function compute( Document_Index $index ): array {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$duplicates = $index->duplicate_ids();
		$uses       = array();
		foreach ( $this->elements as $position => $element ) {
			if ( '' !== $element[ self::E_ID ] && isset( $duplicates[ $element[ self::E_ID ] ] ) ) {
				$uses[ $element[ self::E_ID ] ][] = $position;
			}
		}
		$labels = array();
		foreach ( $this->labels as $position => $label ) {
			$labels[ $label[ self::L_FORM ] ][ $label[ self::L_FOR ] ][] = $position;
		}

		$renamed = array();
		$fixed   = array();
		$taken   = array();
		foreach ( $duplicates as $id => $count ) {
			$id = (string) $id;
			// Every element with the id must be known, and an id with spaces cannot be referenced.
			if ( ! isset( $uses[ $id ] ) || count( $uses[ $id ] ) !== $count || 1 === preg_match( '/[\t\n\f\r ]/', $id ) ) {
				continue;
			}
			$per_form = array();
			foreach ( $uses[ $id ] as $position ) {
				$form              = $this->elements[ $position ][ self::E_FORM ];
				$per_form[ $form ] = isset( $per_form[ $form ] ) ? $per_form[ $form ] + 1 : 1;
			}
			foreach ( $uses[ $id ] as $n => $position ) {
				$element = $this->elements[ $position ];
				$form    = $element[ self::E_FORM ];
				if ( 0 === $n || ! $element[ self::E_CONTROL ] || $form < 0 || $per_form[ $form ] > 1 || empty( $labels[ $form ][ $id ] ) ) {
					continue;
				}
				$new_id = $id . self::SUFFIX . ( $n + 1 );
				if ( $index->id_count( $new_id ) > 0 || isset( $taken[ $new_id ] ) ) {
					continue;
				}
				$taken[ $new_id ]     = true;
				$renamed[ $position ] = array( $id, $new_id );
				$fixed[ $id ]         = isset( $fixed[ $id ] ) ? $fixed[ $id ] + 1 : 1;
			}
		}

		$this->result = array(
			'edits'   => $this->plan_edits( $renamed, $labels ),
			'unfixed' => $this->remaining( $duplicates, $fixed, $uses ),
		);
		return $this->result;
	}

	/**
	 * Turns the renamed controls into edits: the control's id, the labels and
	 * the references inside the same form (one edit per element and
	 * attribute, even when several of its ids change).
	 *
	 * @param array $renamed Element position => array( old id, new id ).
	 * @param array $labels  Form ordinal => for value => label positions.
	 * @return array[]
	 */
	private function plan_edits( array $renamed, array $labels ): array {
		if ( empty( $renamed ) ) {
			return array();
		}
		$referring = array();
		foreach ( $this->elements as $position => $element ) {
			if ( null !== $element[ self::E_REFERENCES ] && $element[ self::E_FORM ] >= 0 ) {
				$referring[ $element[ self::E_FORM ] ][] = $position;
			}
		}

		$edits = array();
		$by    = array();
		foreach ( $renamed as $position => $ids ) {
			list( $old, $new ) = $ids;
			$element           = $this->elements[ $position ];
			$form              = $element[ self::E_FORM ];
			$edits[]           = array( self::snapshot( $element[ self::E_ORDINAL ], $element[ self::E_TAG ], $element[ self::E_SIG ] ), 'id', $new, $element[ self::E_RAW ] );
			foreach ( $labels[ $form ][ $old ] as $label_position ) {
				$label   = $this->labels[ $label_position ];
				$edits[] = array( self::snapshot( $label[ self::L_ORDINAL ], 'LABEL', $label[ self::L_SIG ] ), 'for', $new, $label[ self::L_RAW ] );
			}
			foreach ( isset( $referring[ $form ] ) ? $referring[ $form ] : array() as $other ) {
				foreach ( $this->elements[ $other ][ self::E_REFERENCES ] as $attribute => $value ) {
					$current = isset( $by[ $other ][ $attribute ] ) ? $by[ $other ][ $attribute ] : $value;
					$tokens  = preg_split( '/[\t\n\f\r ]+/', trim( $current ), -1, PREG_SPLIT_NO_EMPTY );
					if ( ! is_array( $tokens ) || ! in_array( $old, $tokens, true ) ) {
						continue;
					}
					foreach ( $tokens as $i => $token ) {
						if ( $old === $token ) {
							$tokens[ $i ] = $new;
						}
					}
					$by[ $other ][ $attribute ] = implode( ' ', $tokens );
				}
			}
		}
		foreach ( $by as $other => $attributes ) {
			$element = $this->elements[ $other ];
			foreach ( $attributes as $attribute => $value ) {
				$edits[] = array( self::snapshot( $element[ self::E_ORDINAL ], $element[ self::E_TAG ], $element[ self::E_SIG ] ), $attribute, $value, $element[ self::E_REFERENCES ][ $attribute ] );
			}
		}
		return $edits;
	}

	/**
	 * Ids that stay duplicated.
	 *
	 * @param int[]   $duplicates Id => number of elements carrying it.
	 * @param int[]   $fixed      Id => number of its uses the safe case renames.
	 * @param array[] $uses       Id => positions of the observed elements carrying it.
	 * @return array[]
	 */
	private function remaining( array $duplicates, array $fixed, array $uses ): array {
		$unfixed = array();
		foreach ( $duplicates as $id => $count ) {
			$id = (string) $id;
			if ( $count - ( isset( $fixed[ $id ] ) ? $fixed[ $id ] : 0 ) < 2 ) {
				continue;
			}
			$unfixed[ $id ] = array( $count, isset( $uses[ $id ] ) ? $this->elements[ $uses[ $id ][0] ][ self::E_SIG ] : '' );
		}
		return $unfixed;
	}

	/**
	 * What Fix_Base::plan_at() needs.
	 *
	 * @param int    $ordinal   Opener ordinal.
	 * @param string $tag       Upper-case tag name.
	 * @param string $signature Signature.
	 * @return array
	 */
	private static function snapshot( int $ordinal, string $tag, string $signature ): array {
		return array(
			'ordinal' => $ordinal,
			'tag'     => $tag,
			'sig'     => $signature,
		);
	}
}
