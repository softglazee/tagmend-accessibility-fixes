<?php
/**
 * R07: link text that does not say where the link goes.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Checks;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Check_Base;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Read_More_Context;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists visible links whose whole accessible name is "click here", "here" or
 * one of F19's phrases ("Read more", "Continue reading", "Learn more",
 * "More"), except the links F19 labels (text-named, inside an article with a
 * heading). Comparison ignores case and surrounding punctuation.
 */
final class Check_Ambiguous_Links extends Check_Base {

	/**
	 * Characters of the link text quoted in a finding (report privacy rule).
	 */
	const QUOTE_LENGTH = 80;

	/**
	 * F19, to tell which links it labels (one per run).
	 *
	 * @var Fix_Read_More_Context|null
	 */
	private $read_more = null;

	/**
	 * Normalised ambiguous phrases of this run.
	 *
	 * @var string[]|null
	 */
	private $phrases = null;

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R07';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '2.4.4';
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
	 * Forgets the phrases of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->read_more = null;
		$this->phrases   = null;
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
	 * Lists the ambiguous links F19 does not label.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		foreach ( $index->names() as $link ) {
			if ( Accessible_Name::KIND_LINK !== $link->kind || $link->hidden || ! $link->is_named() || ! $link->is_exact() ) {
				continue;
			}
			if ( ! in_array( Fix_Read_More_Context::normalise( $link->name() ), $this->phrases(), true ) || $this->read_more()->in_reach( $link ) ) {
				continue;
			}
			$this->report(
				$recorder,
				$link->sig,
				sprintf(
					/* translators: %s: the link text */
					__( 'Link text "%s" does not say where the link goes: use words that name the destination.', 'tagmend-accessibility-fixes' ),
					mb_substr( $link->name(), 0, self::QUOTE_LENGTH )
				)
			);
		}
	}

	/**
	 * The normalised phrases.
	 *
	 * @return string[]
	 */
	private function phrases(): array {
		if ( null === $this->phrases ) {
			$phrases       = array_merge(
				array(
					__( 'Click here', 'tagmend-accessibility-fixes' ),
					__( 'Here', 'tagmend-accessibility-fixes' ),
				),
				Fix_Read_More_Context::phrases()
			);
			$this->phrases = array_map( array( Fix_Read_More_Context::class, 'normalise' ), $phrases );
		}
		return $this->phrases;
	}

	/**
	 * F19, for its reach.
	 *
	 * @return Fix_Read_More_Context
	 */
	private function read_more(): Fix_Read_More_Context {
		if ( null === $this->read_more ) {
			$this->read_more = new Fix_Read_More_Context();
		}
		return $this->read_more;
	}
}
