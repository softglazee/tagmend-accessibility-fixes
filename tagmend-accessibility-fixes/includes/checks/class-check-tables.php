<?php
/**
 * R13: data tables without header cells.
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
 * Lists tables with two or more rows and no th cell. Rows and header cells
 * count for the innermost open table, so nested tables are judged on their
 * own. Layout tables (role="presentation" or "none") and hidden tables are
 * left out.
 */
final class Check_Tables extends Check_Base {

	/**
	 * Key in Element::$data for the table's counts.
	 */
	const DATA_KEY = 'tables';

	/**
	 * Findings by table ordinal: signature and row count.
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
		return 'R13';
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
	 * Table openers and closers, rows and header cells.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'TABLE', 'TR', 'TH' );
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
	 * Counts rows and header cells per table; judges the table when it closes.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Recorder $recorder ): void {
		if ( 'TABLE' === $token->name ) {
			$frame = $token->element;
			if ( null === $frame ) {
				return;
			}
			if ( ! $token->is_closer ) {
				$frame->data[ self::DATA_KEY ] = array(
					'rows'    => 0,
					'headers' => 0,
					'skip'    => $token->is_presentational(),
					'sig'     => $recorder->is_recording() ? $token->signature() : '',
				);
				return;
			}
			if ( isset( $frame->data[ self::DATA_KEY ] ) ) {
				$table = $frame->data[ self::DATA_KEY ];
				if ( ! $table['skip'] && $table['rows'] >= 2 && 0 === $table['headers'] ) {
					$this->found[ $frame->ordinal ] = array( $table['sig'], $table['rows'] );
				}
			}
			return;
		}
		if ( $token->is_closer ) {
			return;
		}
		$table = $token->closest( 'TABLE' );
		if ( null === $table || ! isset( $table->data[ self::DATA_KEY ] ) ) {
			return;
		}
		++$table->data[ self::DATA_KEY ][ 'TR' === $token->name ? 'rows' : 'headers' ];
	}

	/**
	 * Reports the tables found, in document order.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function finish( Document_Index $index, Recorder $recorder ): void {
		ksort( $this->found );
		foreach ( $this->found as $found ) {
			$this->report(
				$recorder,
				$found[0],
				sprintf(
					/* translators: %d: number of table rows, 2 or more */
					__( 'Table with %d rows has no header cells: if it holds data, mark its header row or column with th elements.', 'tagmend-accessibility-fixes' ),
					$found[1]
				)
			);
		}
	}
}
