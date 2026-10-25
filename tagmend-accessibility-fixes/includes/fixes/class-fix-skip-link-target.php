<?php
/**
 * F12: skip links whose target is missing are repaired.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Element;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An in-page link that is a skip link (class skip-link or
 * screen-reader-text, or text starting "Skip to") and whose #id matches no
 * element (nor a named anchor, nor "#top") is pointed at the page's only
 * main element: at its id when it has a usable one; otherwise the main gets
 * id="softglaze-a11y-main" (the id F11 uses, so the two fixes never
 * disagree about it) and the link points there. Nothing happens without
 * exactly one visible main element. Needs the whole-page pass.
 */
final class Fix_Skip_Link_Target extends Fix_Base {

	/**
	 * Classes that mark a link as a skip link.
	 */
	const SKIP_CLASSES = array( 'skip-link', 'screen-reader-text' );

	/**
	 * In-page links that may be skip links, by ordinal: snapshot plus
	 * 'href' (raw), 'fragment' and 'pending' (true until the text decides).
	 *
	 * @var array[]
	 */
	private $candidates = array();

	/**
	 * Values of name attributes on links (legacy named anchors are fragment targets too).
	 *
	 * @var bool[]
	 */
	private $anchors = array();

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'skip-link-target';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Skip links whose target is missing', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.1' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'skip-link' );
	}

	/**
	 * Scopes: the target may be anywhere in the document.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'page' );
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
	 * The page pass exists from WordPress 6.9.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'wp69' );
	}

	/**
	 * Link openers and closers (the closer carries the link text).
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'A' );
	}

	/**
	 * Reads the main landmark.
	 *
	 * @return bool
	 */
	public function uses_landmarks(): bool {
		return true;
	}

	/**
	 * Clears per-document state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->candidates = array();
		$this->anchors    = array();
	}

	/**
	 * Collects in-page links and named anchors; a link without a skip-link
	 * class is kept once its text reads like a skip link.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( 'A' !== $token->name ) {
			return;
		}
		if ( $token->is_closer ) {
			if ( isset( $this->candidates[ $token->ordinal ] ) && $this->candidates[ $token->ordinal ]['pending'] ) {
				if ( null !== $token->element && self::reads_as_skip_link( $token->element->text ) ) {
					$this->candidates[ $token->ordinal ]['pending'] = false;
				} else {
					unset( $this->candidates[ $token->ordinal ] );
				}
			}
			return;
		}

		$name = $token->attr_text( 'name' );
		if ( '' !== $name ) {
			$this->anchors[ $name ] = true;
		}
		$raw  = $token->attr( 'href' );
		$href = is_string( $raw ) ? trim( $raw ) : '';
		if ( strlen( $href ) < 2 || '#' !== $href[0] ) {
			return;
		}
		$classes = Fix_Skip_Link::classes( $token );
		if ( in_array( Fix_Skip_Link::LINK_CLASS, $classes, true ) ) {
			return;
		}
		$this->candidates[ $token->ordinal ] = $token->snapshot( $recorder->is_recording() ) + array(
			'href'     => $raw,
			'fragment' => substr( $href, 1 ),
			'pending'  => empty( array_intersect( self::SKIP_CLASSES, $classes ) ),
		);
	}

	/**
	 * Points every broken skip link at the main element.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( Engine::SCOPE_PAGE !== $index->scope || empty( $this->candidates ) ) {
			return;
		}
		$main = $index->single_main();
		if ( null === $main ) {
			return;
		}
		if ( '' === $main['id'] ) {
			if ( 0 !== $index->id_count( Fix_Skip_Link::TARGET_ID ) ) {
				return;
			}
			$target = Fix_Skip_Link::TARGET_ID;
		} elseif ( Fix_Skip_Link::is_usable_id( $main['id'] ) && 1 === $index->id_count( $main['id'] ) ) {
			$target = $main['id'];
		} else {
			return;
		}

		$main_planned = false;
		foreach ( $this->candidates as $link ) {
			if ( $link['pending'] || $this->target_exists( $index, $link['fragment'] ) ) {
				continue;
			}
			if ( '' === $main['id'] && ! $main_planned ) {
				$this->plan_at( $plan, $main, 'id', $target, null );
				$main_planned = true;
			}
			$this->plan_at( $plan, $link, 'href', '#' . $target, $link['href'] );
		}
	}

	/**
	 * Whether a fragment leads somewhere: an element with that id, a named
	 * anchor, or "top" (the top of the document); percent-encoded fragments
	 * are compared decoded as well.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param string         $fragment Fragment without "#".
	 * @return bool
	 */
	private function target_exists( Document_Index $index, string $fragment ): bool {
		foreach ( array_unique( array( $fragment, rawurldecode( $fragment ) ) ) as $candidate ) {
			if ( 'top' === strtolower( $candidate ) || $index->id_count( $candidate ) > 0 || isset( $this->anchors[ $candidate ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether link text reads like a skip link: "Skip to…", or the translated "Skip to content".
	 *
	 * @param string $text Collected link text.
	 * @return bool
	 */
	private static function reads_as_skip_link( string $text ): bool {
		$text   = Element::without_private_use( $text );
		$phrase = Fix_Skip_Link::link_text();
		return 0 === stripos( $text, 'skip to' ) || ( '' !== $phrase && 0 === stripos( $text, $phrase ) );
	}
}
