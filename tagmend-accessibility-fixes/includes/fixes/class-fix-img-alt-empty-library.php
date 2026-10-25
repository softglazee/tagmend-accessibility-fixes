<?php
/**
 * F02: images with an empty alt get their Media Library alt text.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An img with alt="" gets the alt text stored for its attachment in the
 * Media Library, unless it sits in a named link or button or is already out
 * of the accessibility tree. An empty alt is often a deliberate "decorative"
 * choice (the block editor writes it when the alt field was left empty), so
 * this fix starts Off.
 */
final class Fix_Img_Alt_Empty_Library extends Fix_Base {

	/**
	 * Images seen in this run.
	 *
	 * @var array
	 */
	private $images = array();

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'img-alt-empty-library';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Empty image alt text from the Media Library', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Replaces an empty alt attribute (alt="") with the alt text saved in the Media Library. Starts Off: the block editor writes alt="" for images whose alt text was left empty when they were inserted, which is also how an author marks an image as decorative, so this fix can undo a deliberate choice. Check what it would change in a report before you turn it on.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.1.1' );
	}

	/**
	 * No axe rule: an empty alt passes axe, this is about quality.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array();
	}

	/**
	 * Scopes.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'content', 'page' );
	}

	/**
	 * Risk.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'medium';
	}

	/**
	 * Starts Off.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return false;
	}

	/**
	 * Images.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'IMG' );
	}

	/**
	 * Reads whether the link or button around an image is named.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
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
	 * Collects images with an empty alt and the control around each one.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || 'IMG' !== $token->name ) {
			return;
		}
		$alt = $token->attr( 'alt' );
		if ( ! ( true === $alt || ( is_string( $alt ) && '' === trim( $alt ) ) ) || $token->is_presentational() ) {
			return;
		}
		$this->images[] = array(
			'element' => $token->snapshot( $recorder->is_recording() ),
			'image'   => $index->attachments()->request_image( $token ),
			'control' => $token->closest_control(),
			'before'  => is_string( $alt ) ? $alt : '',
		);
	}

	/**
	 * Plans the library alt text for images outside named controls.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
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
			if ( $id <= 0 ) {
				continue;
			}
			$alt = self::plain_text( $lookup->alt( $id ) );
			if ( '' !== $alt ) {
				$this->plan_at( $plan, $image['element'], 'alt', $alt, $image['before'] );
			}
		}
		$this->images = array();
	}
}
