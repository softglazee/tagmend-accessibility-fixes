<?php
/**
 * F01: images without alt get their Media Library alt text.
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
 * An img with no alt attribute gets the alt text stored for its attachment
 * in the Media Library. Never a file name, title or caption; nothing when the
 * library has no alt text (report R04 lists those images). Images inside a
 * named link or button are left to F03, which wins by priority.
 */
final class Fix_Img_Alt_Library extends Fix_Base {

	/**
	 * Images seen in this run: Token::snapshot() and the lookup reference.
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
		return 'img-alt-library';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Missing image alt text from the Media Library', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Gives images that have no alt attribute the alt text saved for them in the Media Library. Images whose Media Library alt text is empty are listed in the report instead. An image the page names only by its address, without the image id the editor adds, is repaired once a scan or "View repaired" has looked it up.', 'tagmend-accessibility-fixes' );
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
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'image-alt' );
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
		return 'low';
	}

	/**
	 * Starts in Preview.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
	}

	/**
	 * Only img openers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'IMG' );
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
	 * Collects images without alt and asks the lookup for their attachments.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || 'IMG' !== $token->name || null !== $token->attr( 'alt' ) || $token->is_presentational() ) {
			return;
		}
		$this->images[] = array(
			'element' => $token->snapshot( $recorder->is_recording() ),
			'image'   => $index->attachments()->request_image( $token ),
		);
	}

	/**
	 * Loads the library alt texts in one batch and plans them.
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
			$id = $lookup->attachment_id( $image['image'] );
			if ( $id <= 0 ) {
				continue;
			}
			$alt = self::plain_text( $lookup->alt( $id ) );
			if ( '' !== $alt ) {
				$this->plan_at( $plan, $image['element'], 'alt', $alt, null );
			}
		}
		$this->images = array();
	}
}
