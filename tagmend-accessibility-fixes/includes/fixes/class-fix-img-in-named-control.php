<?php
/**
 * F03: images without alt inside a named link or button get alt="".
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
 * An img with no alt inside a link (with href) or button that already has a
 * name from its text or its own attributes gets alt="", so the name is not
 * read twice. Runs before F01 and wins over it for the same image.
 */
final class Fix_Img_In_Named_Control extends Fix_Base {

	/**
	 * Images seen in this run: Token::snapshot() and the control around each.
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
		return 'img-in-named-control';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Images inside named links and buttons', 'tagmend-accessibility-fixes' );
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
		return array( 'image-alt', 'image-redundant-alt' );
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
	 * Wins over F01 (priority 10) for the same image.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 5;
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
	 * Collects images without alt that sit inside a link or button.
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
		if ( null !== $token->attr( 'alt' ) || $token->is_presentational() ) {
			return;
		}
		$control = $token->closest_control();
		if ( null !== $control ) {
			$this->images[] = array(
				'element' => $token->snapshot( $recorder->is_recording() ),
				'control' => $control,
			);
		}
	}

	/**
	 * Plans alt="" for the images whose control turned out to be named.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $this->images as $image ) {
			if ( $image['control']->is_named_control() ) {
				$this->plan_at( $plan, $image['element'], 'alt', '', null );
			}
		}
		$this->images = array();
	}
}
