<?php
/**
 * R02: heading structure.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Element;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the headings of the whole page in order (those hidden with
 * aria-hidden or hidden, and those with a role other than heading, are not
 * in the outline) and lists: a page without an h1, every h1 after the
 * first, and every heading that skips one or more levels below the one
 * before it. Whole pages only: a content fragment has no h1 of its own.
 */
final class Check_Headings extends Check_Base {

	/**
	 * Characters of heading text quoted in a finding (report privacy rule).
	 */
	const QUOTE_LENGTH = 80;

	/**
	 * Key in Element::$data for the heading's position in the outline.
	 */
	const DATA_KEY = 'R02';

	/**
	 * The outline: list of array( 'level' => int, 'sig' => string, 'text' => string ).
	 *
	 * @var array[]
	 */
	private $headings = array();

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R02';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '1.3.1';
	}

	/**
	 * Heading openers and closers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );
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
	 * Forgets the previous outline.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->headings = array();
	}

	/**
	 * Adds a heading to the outline on its opener; takes its text on the closer.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		$frame = $token->element;
		if ( null === $frame ) {
			return;
		}
		if ( $token->is_closer ) {
			if ( isset( $frame->data[ self::DATA_KEY ] ) ) {
				$this->headings[ $frame->data[ self::DATA_KEY ] ]['text'] = Element::without_private_use( $frame->text );
			}
			return;
		}
		$role = strtolower( $token->attr_text( 'role' ) );
		if ( $token->is_aria_hidden() || $token->inside_aria_hidden() || null !== $token->attr( 'hidden' ) || ( '' !== $role && 'heading' !== $role ) ) {
			return;
		}
		$frame->data[ self::DATA_KEY ] = count( $this->headings );
		$this->headings[]              = array(
			'level' => (int) substr( $token->name, 1 ),
			'sig'   => $recorder->is_recording() ? $token->signature() : '',
			'text'  => '',
		);
	}

	/**
	 * Lists the problems of the outline.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		$h1 = 0;
		foreach ( $this->headings as $heading ) {
			if ( 1 === $heading['level'] && ++$h1 > 1 ) {
				$this->report(
					$recorder,
					$heading['sig'],
					sprintf(
						/* translators: %s: the heading text */
						__( 'More than one h1 heading on this page: "%s".', 'tagmend-accessibility-fixes' ),
						self::quote( $heading['text'] )
					)
				);
			}
		}
		if ( 0 === $h1 ) {
			$this->report( $recorder, 'body', __( 'This page has no h1 heading.', 'tagmend-accessibility-fixes' ) );
		}

		$previous = 0;
		foreach ( $this->headings as $heading ) {
			if ( $previous > 0 && $heading['level'] > $previous + 1 ) {
				$this->report(
					$recorder,
					$heading['sig'],
					sprintf(
						/* translators: 1: level of the heading before, for example 2; 2: level of this heading, for example 4; 3: the heading text */
						__( 'Heading level jumps from h%1$d to h%2$d: "%3$s".', 'tagmend-accessibility-fixes' ),
						$previous,
						$heading['level'],
						self::quote( $heading['text'] )
					)
				);
			}
			$previous = $heading['level'];
		}
		// TODO(Phase 6B): show the whole outline in the report details, with the jumps marked (blueprint section 7).
	}

	/**
	 * Heading text cut for a finding, or a marker for an empty heading.
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private static function quote( string $text ): string {
		return '' === $text ? __( '(empty)', 'tagmend-accessibility-fixes' ) : mb_substr( $text, 0, self::QUOTE_LENGTH );
	}
}
