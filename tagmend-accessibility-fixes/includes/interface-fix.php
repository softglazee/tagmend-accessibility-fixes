<?php
/**
 * The contract every repair implements.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One repair. Fixes never edit markup themselves: they read tokens and add
 * attribute edits to the Plan, which the engine applies in its second pass.
 */
interface Fix {

	/**
	 * Stable slug, for example 'iframe-title'. Never renamed once released.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Translated name shown in the admin.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * WCAG success criteria the fix addresses, for example array( '4.1.2' ).
	 *
	 * @return string[]
	 */
	public function wcag(): array;

	/**
	 * The axe-core rule ids the fix is expected to reduce (used by tests).
	 *
	 * @return string[]
	 */
	public function axe_rules(): array;

	/**
	 * Subset of array( 'attribute', 'content', 'page' ).
	 *
	 * @return string[]
	 */
	public function scopes(): array;

	/**
	 * 'low', 'medium' or 'high'.
	 *
	 * @return string
	 */
	public function risk(): string;

	/**
	 * True when the fix starts in Preview on first activation; false starts Off.
	 *
	 * @return bool
	 */
	public function recommended(): bool;

	/**
	 * Requirements, for example array( 'wp69' ) or array( 'woocommerce' ).
	 *
	 * @return string[]
	 */
	public function requires(): array;

	/**
	 * Looks at one token of the analyse pass and may plan edits or record findings.
	 *
	 * @param Token          $token    The current token (valid only during this call).
	 * @param Document_Index $index    What the analyse pass has learned so far.
	 * @param Plan           $plan     Planned attribute edits, keyed by opener ordinal.
	 * @param Recorder       $recorder Changes and findings of this run.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void;
}
