<?php
/**
 * R04: images the plugin could not describe.
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
 * Lists every img without an alt attribute that F01 cannot fill (no alt text
 * in the Media Library, or not found there) and F03 does not cover (not in a
 * named link or button). Images already out of the accessibility tree are
 * not listed.
 */
final class Check_Undescribed_Images extends Check_Base {

	/**
	 * Images seen in this run.
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Report id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'R04';
	}

	/**
	 * WCAG success criterion.
	 *
	 * @return string
	 */
	public function wcag(): string {
		return '1.1.1';
	}

	/**
	 * Images, and the links and buttons around them.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'IMG', 'A', 'BUTTON' );
	}

	/**
	 * Clears the images of the previous run.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->images = array();
	}

	/**
	 * Collects images without alt.
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
		if ( 'IMG' !== $token->name ) {
			$token->remember_control();
			return;
		}
		if ( null !== $token->attr( 'alt' ) || $token->is_presentational() ) {
			return;
		}
		$this->images[] = array(
			'sig'     => $token->signature(),
			'image'   => $index->attachments()->request_image( $token ),
			'control' => $token->closest_control(),
		);
	}

	/**
	 * Reports the images no fix can describe.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		if ( empty( $this->images ) ) {
			return;
		}
		$lookup = $index->attachments();
		$lookup->load();
		foreach ( $this->images as $image ) {
			if ( null !== $image['control'] && $image['control']->is_named_control() ) {
				continue;
			}
			$id = $lookup->attachment_id( $image['image'] );
			if ( $id > 0 && '' !== Fix_Base::plain_text( $lookup->alt( $id ) ) ) {
				continue;
			}

			if ( $id > 0 ) {
				$detail = __( 'No alt text, and its Media Library entry has none: add it in the Media Library, or alt="" where it is inserted if it is decorative.', 'tagmend-accessibility-fixes' );
			} elseif ( '' !== $image['image']['path'] && $lookup->was_skipped( $image['image']['path'] ) ) {
				$detail = __( 'No alt text. Not looked up in the Media Library: the limit of lookups per page was reached.', 'tagmend-accessibility-fixes' );
			} else {
				$detail = __( 'No alt text, and the image was not found in the Media Library: add alt text where the image is inserted.', 'tagmend-accessibility-fixes' );
			}
			$this->report( $recorder, $image['sig'], $detail, $id );
		}
		$this->images = array();
	}
}
