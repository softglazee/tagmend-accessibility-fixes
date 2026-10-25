<?php
/**
 * R10: media that plays by itself.
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
 * Lists video and audio elements with autoplay that are not muted (sound a
 * visitor did not ask for, 1.4.2) or have no controls (nothing to pause or
 * stop them with, 2.2.2).
 */
final class Check_Autoplay_Media extends Check_Base {

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
		return 'R10';
	}

	/**
	 * WCAG success criterion (the finding names 2.2.2 when only the controls are missing).
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '1.4.2';
	}

	/**
	 * Video and audio openers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'VIDEO', 'AUDIO' );
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
	 * Notes autoplaying media without muted or without controls.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( $token->is_closer || null === $token->attr( 'autoplay' ) ) {
			return;
		}
		$muted    = null !== $token->attr( 'muted' );
		$controls = null !== $token->attr( 'controls' );
		if ( $muted && $controls ) {
			return;
		}
		if ( ! $muted && ! $controls ) {
			$sc     = '1.4.2';
			$detail = __( 'This media plays by itself, is not muted and has no controls to pause or stop it.', 'tagmend-accessibility-fixes' );
		} elseif ( ! $muted ) {
			$sc     = '1.4.2';
			$detail = __( 'This media plays by itself and is not muted: start it muted, or let visitors start it.', 'tagmend-accessibility-fixes' );
		} else {
			$sc     = '2.2.2';
			$detail = __( 'This media plays by itself and has no controls to pause or stop it.', 'tagmend-accessibility-fixes' );
		}
		$this->found[] = array( $recorder->is_recording() ? $token->signature() : '', $sc, $detail );
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
