<?php
/**
 * R11: duplicate ids that F17 does not fix.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Field_Ids;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every id that stays on more than one element after F17's safe case
 * (repeated form fields with their own label in their own form), with the
 * number of elements carrying it. Labels and ARIA references reach only
 * the first of them. Reads the same form-field facts as F17
 * (Document_Index::field_ids()), whatever F17's state. Whole pages only.
 */
final class Check_Duplicate_Ids extends Check_Base {

	/**
	 * Characters of an id quoted in a finding.
	 */
	const QUOTE_LENGTH = 80;

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R11';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '4.1.2';
	}

	/**
	 * The tags F17 reads.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return Field_Ids::TAGS;
	}

	/**
	 * Whole pages only: every use of an id must be known.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'page' );
	}

	/**
	 * Hands the opener to the shared form-field facts.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		$index->field_ids()->observe( $token, $recorder->is_recording() );
	}

	/**
	 * Lists the ids that stay duplicated.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		foreach ( $index->field_ids()->unfixed( $index ) as $id => $found ) {
			$id    = (string) $id;
			$quote = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', mb_substr( $id, 0, self::QUOTE_LENGTH ) );
			$this->report(
				$recorder,
				'' !== $found[1] ? $found[1] : '#' . $quote,
				sprintf(
					/* translators: 1: an id attribute value, 2: number of elements carrying it, 2 or more */
					__( 'The id "%1$s" is used by %2$d elements; labels and ARIA references reach only the first one.', 'tagmend-accessibility-fixes' ),
					$quote,
					$found[0]
				)
			);
		}
	}
}
