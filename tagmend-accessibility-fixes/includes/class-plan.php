<?php
/**
 * Planned attribute edits.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attribute edits keyed by opener ordinal and attribute name. Several fixes
 * may propose a value for the same attribute; the engine picks one per mode
 * by priority (lower number wins, then the earlier proposal).
 */
final class Plan {

	/**
	 * The only attributes a fix may set. Event handlers, style, src and
	 * anything else that can run code or load resources are refused.
	 */
	const ATTRIBUTES = array(
		'alt',
		'aria-describedby',
		'aria-hidden',
		'aria-label',
		'aria-labelledby',
		'content',
		'focusable',
		'for',
		'hidden',
		'href',
		'id',
		'lang',
		'tabindex',
		'title',
		'value',
	);

	/**
	 * Ordinal => attribute => list of proposals.
	 *
	 * @var array
	 */
	private $edits = array();

	/**
	 * Number of proposals.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Adds a proposal. Returns false when the attribute is not allowed.
	 *
	 * @param int         $ordinal   Opener ordinal of the element.
	 * @param string      $tag       Upper-case tag name, checked again in the apply pass.
	 * @param string      $attribute Attribute name.
	 * @param string      $value     Plain, decoded text; set_attribute() encodes it.
	 * @param string      $fix_id    Id of the proposing fix.
	 * @param int         $priority  Lower wins when fixes propose the same attribute.
	 * @param string|null $before    Current value, null when absent.
	 * @param string      $signature Privacy-stripped element signature.
	 * @param array       $requires  Optional array( ordinal, attribute ) of an earlier element's
	 *                               edit that must be applied in the same run for this one to be.
	 * @return bool
	 */
	public function add( int $ordinal, string $tag, string $attribute, string $value, string $fix_id, int $priority = 10, $before = null, string $signature = '', array $requires = array() ): bool {
		$attribute = strtolower( $attribute );
		// A stray </br> counts as an opener from WordPress 6.7 but cannot take attributes.
		if ( $ordinal < 0 || 'BR' === $tag || ! in_array( $attribute, self::ATTRIBUTES, true ) ) {
			return false;
		}

		// Ordinal and attribute are the keys; seven entries keep each proposal in the smallest hash.
		$this->edits[ $ordinal ][ $attribute ][] = array(
			'tag'       => $tag,
			'value'     => $value,
			'fix'       => $fix_id,
			'priority'  => $priority,
			'before'    => is_string( $before ) ? $before : null,
			'signature' => $signature,
			'requires'  => 2 === count( $requires ) ? array( (int) $requires[0], strtolower( (string) $requires[1] ) ) : null,
		);
		++$this->count;
		return true;
	}

	/**
	 * Whether nothing was proposed.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return 0 === $this->count;
	}

	/**
	 * Number of proposals.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Whether an element already has a proposal for an attribute.
	 *
	 * @param int    $ordinal   Opener ordinal.
	 * @param string $attribute Attribute name.
	 * @return bool
	 */
	public function has( int $ordinal, string $attribute ): bool {
		return isset( $this->edits[ $ordinal ][ strtolower( $attribute ) ] );
	}

	/**
	 * Proposals grouped by ordinal (ascending) and attribute.
	 *
	 * @return array
	 */
	public function grouped(): array {
		ksort( $this->edits );
		return $this->edits;
	}

	/**
	 * The winning proposal among those the filter accepts.
	 *
	 * @param array    $proposals Proposals for one attribute of one element.
	 * @param callable $accept    Receives a proposal, returns bool.
	 * @return array|null
	 */
	public static function winner( array $proposals, callable $accept ) {
		$best = null;
		foreach ( $proposals as $proposal ) {
			if ( ! $accept( $proposal ) ) {
				continue;
			}
			if ( null === $best || $proposal['priority'] < $best['priority'] ) {
				$best = $proposal;
			}
		}
		return $best;
	}
}
