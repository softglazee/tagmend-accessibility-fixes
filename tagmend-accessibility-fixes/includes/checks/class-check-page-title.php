<?php
/**
 * R09: page title.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists a page whose document title is missing or empty (whitespace only).
 * The first title element outside inline SVG is the document title. Whole
 * pages only.
 */
final class Check_Page_Title extends Check_Base {

	/**
	 * Whether the document title was seen.
	 *
	 * @var bool
	 */
	private $seen = false;

	/**
	 * Whether it holds no text.
	 *
	 * @var bool
	 */
	private $empty = false;

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R09';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '2.4.2';
	}

	/**
	 * Title openers (the text belongs to the opener token).
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'TITLE' );
	}

	/**
	 * Whole pages only.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'page' );
	}

	/**
	 * Forgets the previous document.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->seen  = false;
		$this->empty = false;
	}

	/**
	 * Notes the first title outside SVG and whether it has text.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( $this->seen || $token->is_closer || null !== $token->closest( 'SVG' ) ) {
			return;
		}
		$this->seen  = true;
		$this->empty = '' === trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $token->modifiable_text() ) );
	}

	/**
	 * Reports a missing or empty title.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		if ( ! $this->seen ) {
			$this->report( $recorder, 'head', __( 'This page has no title element.', 'tagmend-accessibility-fixes' ) );
		} elseif ( $this->empty ) {
			$this->report( $recorder, 'title', __( 'The page title is empty.', 'tagmend-accessibility-fixes' ) );
		}
	}
}
