<?php
/**
 * Changes, findings and engine facts of one run.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-memory record of a run. Nothing here is stored: the scan and admin-view
 * code decides what to persist (TODO(Phase 6A): Pages_Store). In visitor mode
 * the engine creates it without details, so only the engine facts and a
 * fallback reason are kept.
 */
final class Recorder {

	/**
	 * Each list in the exported result holds at most this many entries.
	 */
	const LIST_LIMIT = 200;

	/**
	 * Whether changes and findings are kept.
	 *
	 * @var bool
	 */
	private $details;

	/**
	 * Recorded changes.
	 *
	 * @var array
	 */
	private $changes = array();

	/**
	 * Recorded findings.
	 *
	 * @var array
	 */
	private $findings = array();

	/**
	 * Fix id => state => number of changes, counted even past the list limit.
	 *
	 * @var array
	 */
	private $counts = array();

	/**
	 * First fallback reason of the run, or null.
	 *
	 * @var string|null
	 */
	private $fallback = null;

	/**
	 * Detail for the fallback (an exception class name, never a message).
	 *
	 * @var string
	 */
	private $fallback_detail = '';

	/**
	 * Engine facts: scope, mode, ms, bytes, tokens, opener counts, edits.
	 *
	 * @var array
	 */
	private $engine = array();

	/**
	 * Creates a recorder.
	 *
	 * @param bool $details Whether changes and findings are kept.
	 */
	public function __construct( bool $details = true ) {
		$this->details = $details;
	}

	/**
	 * Whether changes and findings are kept (fixes skip building signatures otherwise).
	 *
	 * @return bool
	 */
	public function is_recording(): bool {
		return $this->details;
	}

	/**
	 * Records a change the engine applied or would apply.
	 *
	 * @param string      $fix       Fix id.
	 * @param string      $state     'live', 'preview' or 'off'.
	 * @param string      $signature Privacy-stripped element signature.
	 * @param string      $attribute Attribute name.
	 * @param string|null $before    Value before, null when absent.
	 * @param string      $after     Value after.
	 * @param bool        $applied   Whether this run applied it.
	 * @return void
	 */
	public function add_change( string $fix, string $state, string $signature, string $attribute, $before, string $after, bool $applied ): void {
		if ( ! isset( $this->counts[ $fix ] ) ) {
			$this->counts[ $fix ] = array(
				'live'    => 0,
				'preview' => 0,
				'off'     => 0,
			);
		}
		if ( isset( $this->counts[ $fix ][ $state ] ) ) {
			++$this->counts[ $fix ][ $state ];
		}

		if ( ! $this->details ) {
			return;
		}
		$this->changes[] = array(
			'fix'     => $fix,
			'state'   => $state,
			'sig'     => $signature,
			'attr'    => $attribute,
			'before'  => null === $before ? null : self::clip( $before ),
			'after'   => self::clip( $after ),
			'applied' => $applied,
		);
	}

	/**
	 * Records something a person needs to look at.
	 *
	 * @param string $check     Check or fix id, for example 'R05'.
	 * @param string $sc        WCAG success criterion.
	 * @param string $signature Privacy-stripped element signature.
	 * @param string $detail    Short explanation.
	 * @return void
	 */
	public function add_finding( string $check, string $sc, string $signature, string $detail ): void {
		if ( ! $this->details ) {
			return;
		}
		$this->findings[] = array(
			'check'  => $check,
			'sc'     => $sc,
			'sig'    => $signature,
			'detail' => self::clip( $detail ),
		);
	}

	/**
	 * Records why the engine returned the original HTML. The first reason wins.
	 *
	 * @param string $reason Short reason: size, time, incomplete, encoding, ordinal, apply, sanity, exception.
	 * @param string $detail Optional detail.
	 * @return void
	 */
	public function fallback( string $reason, string $detail = '' ): void {
		if ( null === $this->fallback ) {
			$this->fallback        = $reason;
			$this->fallback_detail = $detail;
		}
	}

	/**
	 * The fallback reason, or null when the run did not fall back.
	 *
	 * @return string|null
	 */
	public function get_fallback() {
		return $this->fallback;
	}

	/**
	 * Detail of the fallback.
	 *
	 * @return string
	 */
	public function get_fallback_detail(): string {
		return $this->fallback_detail;
	}

	/**
	 * Merges engine facts.
	 *
	 * @param array $facts Facts to merge.
	 * @return void
	 */
	public function set_engine( array $facts ): void {
		$this->engine = array_merge( $this->engine, $facts );
	}

	/**
	 * Engine facts.
	 *
	 * @return array
	 */
	public function engine(): array {
		return $this->engine;
	}

	/**
	 * Recorded changes.
	 *
	 * @return array
	 */
	public function changes(): array {
		return $this->changes;
	}

	/**
	 * Recorded findings.
	 *
	 * @return array
	 */
	public function findings(): array {
		return $this->findings;
	}

	/**
	 * Change counts per fix and state.
	 *
	 * @return array
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Drops recorded changes (used when the run falls back, so nothing claims to be applied).
	 *
	 * @return void
	 */
	public function discard_changes(): void {
		$this->changes = array();
		$this->counts  = array();
	}

	/**
	 * The result in the shape stored with a report (blueprint section 11).
	 *
	 * @return array
	 */
	public function to_array(): array {
		$engine             = $this->engine;
		$engine['fallback'] = $this->fallback;
		return array(
			'engine'   => $engine,
			'counts'   => $this->counts,
			'changes'  => array_slice( $this->changes, 0, self::LIST_LIMIT ),
			'findings' => array_slice( $this->findings, 0, self::LIST_LIMIT ),
		);
	}

	/**
	 * Caps stored text at 150 characters.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	private static function clip( string $value ): string {
		return mb_substr( $value, 0, 150 );
	}
}
