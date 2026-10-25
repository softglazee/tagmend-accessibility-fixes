<?php
/**
 * Shared behaviour of the report-only checks.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A report-only check (blueprint section 7). Checks see the same tokens as
 * fixes but receive no Plan, so they can only record findings. The engine
 * runs them in scan and admin_repaired modes, never for visitors.
 */
abstract class Check_Base {

	/**
	 * Report id, for example 'R03'.
	 *
	 * @return string
	 */
	abstract public function id(): string;

	/**
	 * WCAG success criterion recorded with each finding, for example '1.1.1'.
	 *
	 * @return string
	 */
	abstract public function wcag(): string;

	/**
	 * Looks at one token of the analyse pass.
	 *
	 * @param Token          $token    The current token (valid only during this call).
	 * @param Document_Index $index    What the analyse pass has learned so far.
	 * @param Recorder       $recorder Changes and findings of this run.
	 * @return void
	 */
	abstract public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void;

	/**
	 * Upper-case tag names whose openers (and tracked closers) this check sees.
	 * An empty array means every opener.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array();
	}

	/**
	 * Subset of array( 'content', 'page' ).
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'content', 'page' );
	}

	/**
	 * Clears per-document state before a run.
	 *
	 * @return void
	 */
	public function reset(): void {
	}

	/**
	 * Runs after the last token and after every fix's finish().
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
	}

	/**
	 * Records one finding under this check's id and success criterion.
	 *
	 * @param Recorder $recorder      Recorder.
	 * @param string   $signature     Privacy-stripped element signature.
	 * @param string   $detail        Short translated explanation.
	 * @param int      $attachment_id Media Library attachment, 0 when unknown.
	 * @return void
	 */
	protected function report( Recorder $recorder, string $signature, string $detail, int $attachment_id = 0 ): void {
		$recorder->add_finding( $this->id(), $this->wcag(), $signature, $detail, $attachment_id );
	}
}
