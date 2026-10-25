<?php
/**
 * R08: landmarks.
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
 * Lists a page without a main landmark, every main landmark when there are
 * several (hidden ones do not count), and every navigation landmark without
 * a label when two or more have none (screen-reader users cannot tell them
 * apart). F11 records its own R08 finding when no skip link could be added.
 * Whole pages only.
 */
final class Check_Landmarks extends Check_Base {

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R08';
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
	 * Decides from the landmarks in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( Fix_Base::FINISH_ONLY );
	}

	/**
	 * Reads the landmarks.
	 *
	 * @return bool
	 */
	public function uses_landmarks(): bool {
		return true;
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
	 * Lists missing, repeated and unlabelled landmarks.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		$mains = $index->landmarks( 'main' );
		if ( empty( $mains ) ) {
			$this->report( $recorder, 'body', __( 'This page has no main landmark: put the main content in a main element.', 'tagmend-accessibility-fixes' ) );
		} elseif ( count( $mains ) > 1 ) {
			foreach ( $mains as $main ) {
				$this->report(
					$recorder,
					$main['sig'],
					sprintf(
						/* translators: %d: number of main landmarks, 2 or more */
						__( 'This page has %d main landmarks; a page should have one.', 'tagmend-accessibility-fixes' ),
						count( $mains )
					)
				);
			}
		}

		$unlabelled = array();
		foreach ( $index->landmarks( 'navigation' ) as $nav ) {
			if ( '' === $nav['label'] && '' === $nav['labelledby'] ) {
				$unlabelled[] = $nav;
			}
		}
		if ( count( $unlabelled ) < 2 ) {
			return;
		}
		foreach ( $unlabelled as $nav ) {
			$this->report(
				$recorder,
				$nav['sig'],
				sprintf(
					/* translators: %d: number of navigation landmarks without a label, 2 or more */
					__( '%d navigation landmarks have no label: give each menu a name, for example with aria-label.', 'tagmend-accessibility-fixes' ),
					count( $unlabelled )
				)
			);
		}
	}
}
