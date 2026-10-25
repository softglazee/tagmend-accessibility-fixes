<?php
/**
 * The per-page report table.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Report;

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per URL in {prefix}softglaze_a11y_pages (blueprint section 11):
 * the latest scan, admin view or WP-CLI analysis of that URL. Every result
 * passes through privacy_strip() before it is stored, the table is capped on
 * insert (oldest rows trimmed), and nothing is read or written while the
 * tables do not match this version of the plugin.
 */
final class Pages_Store {

	const TABLE = 'softglaze_a11y_pages';
	const CAP   = 500;

	/**
	 * Report sources.
	 */
	const SOURCES = array( 'admin_scan', 'admin_view', 'cli' );

	/**
	 * Entries kept in each stored list.
	 */
	const LIST_LIMIT = 200;

	/**
	 * Attributes that may appear in a stored element signature (AGENTS.md section 3).
	 */
	const SIGNATURE_ATTRIBUTES = array( 'role', 'type', 'name', 'href', 'src' );

	/**
	 * Longest quoted value from the page, and longest text the plugin wrote itself.
	 */
	const PAGE_TEXT_MAX   = 80;
	const PLUGIN_TEXT_MAX = 150;

	/**
	 * Engine facts kept with a report.
	 */
	const ENGINE_KEYS = array( 'scope', 'scope_reason', 'mode', 'ms', 'bytes', 'runs', 'fallback', 'fallbacks', 'wp', 'version' );

	/**
	 * Sort keys of the Reports screen and the column each one orders by.
	 */
	const SORT_COLUMNS = array(
		'url'      => 'url',
		'scanned'  => 'id',
		'live'     => 'changes_live',
		'findings' => 'findings',
	);

	/**
	 * Rows kept.
	 *
	 * @var int
	 */
	private $cap;

	/**
	 * Creates the store.
	 *
	 * @param int $cap Rows kept; the oldest are trimmed on insert.
	 */
	public function __construct( int $cap = self::CAP ) {
		$this->cap = max( 1, $cap );
	}

	/**
	 * The table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Stores the report of one URL, replacing an earlier one, then trims the
	 * oldest rows above the cap.
	 *
	 * @param array $report 'url' (normalised), 'url_hash', 'source', 'result' (array);
	 *                      optional 'object_id', 'object_type'.
	 * @return bool Whether the row was written.
	 */
	public function save( array $report ): bool {
		global $wpdb;
		$hash   = isset( $report['url_hash'] ) && is_string( $report['url_hash'] ) ? $report['url_hash'] : '';
		$source = isset( $report['source'] ) && is_string( $report['source'] ) ? $report['source'] : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! in_array( $source, self::SOURCES, true ) || ! Install::ready() ) {
			return false;
		}

		$result = self::privacy_strip( isset( $report['result'] ) && is_array( $report['result'] ) ? $report['result'] : array() );
		$totals = array(
			'live'    => 0,
			'preview' => 0,
			'off'     => 0,
		);
		foreach ( $result['counts'] as $states ) {
			foreach ( $totals as $state => $sum ) {
				$totals[ $state ] = $sum + $states[ $state ];
			}
		}
		$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		$object_id = isset( $report['object_id'] ) ? absint( $report['object_id'] ) : 0;
		$row       = array(
			'url_hash'        => $hash,
			'url'             => isset( $report['url'] ) && is_string( $report['url'] ) ? substr( esc_url_raw( $report['url'] ), 0, 2048 ) : '',
			'object_id'       => $object_id > 0 ? $object_id : null,
			'object_type'     => isset( $report['object_type'] ) && is_string( $report['object_type'] ) ? substr( sanitize_key( $report['object_type'] ), 0, 20 ) : '',
			'source'          => $source,
			'scanned_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			'html_bytes'      => min( 4294967295, absint( $result['engine']['bytes'] ) ),
			'engine_us'       => min( 4294967295, (int) round( (float) $result['engine']['ms'] * 1000 ) ),
			'changes_live'    => min( 65535, $totals['live'] ),
			'changes_preview' => min( 65535, $totals['preview'] ),
			'changes_off'     => min( 65535, $totals['off'] ),
			'findings'        => min( 65535, $result['totals']['findings'] ),
			'result'          => false === $json ? '{}' : $json,
		);
		$formats   = array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' );

		// REPLACE keeps one row per URL (url_hash is unique) and gives it a new id, so ids follow the save order.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table; nothing to cache on write.
		$written = $wpdb->replace( self::table_name(), $row, $formats );
		if ( false === $written ) {
			return false;
		}
		$this->trim();
		return true;
	}

	/**
	 * The stored report of one URL, with its result decoded, or null.
	 *
	 * @param string $hash URL hash.
	 * @return array|null
	 */
	public function get_by_hash( string $hash ) {
		global $wpdb;
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! Install::ready() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One row of the plugin's own report table, read once per request.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE url_hash = %s', self::table_name(), $hash ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$result        = json_decode( (string) $row['result'], true );
		$row['result'] = is_array( $result ) ? $result : array();
		return $row;
	}

	/**
	 * Newest reports first, without their result column.
	 *
	 * @param int $limit Rows.
	 * @return array[]
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		if ( ! Install::ready() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table, read on demand.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url_hash, url, object_id, object_type, source, scanned_gmt, html_bytes, engine_us, changes_live, changes_preview, changes_off, findings FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), max( 1, $limit ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One page of reports for the Reports screen, without their result
	 * column. The sort key is looked up in SORT_COLUMNS (anything else sorts
	 * by date) and the direction is one of two literals, so no request value
	 * reaches the query.
	 *
	 * @param string $orderby  Key of SORT_COLUMNS.
	 * @param string $order    'asc' or 'desc'.
	 * @param int    $per_page Rows per page.
	 * @param int    $offset   Rows to skip.
	 * @return array[]
	 */
	public function page( string $orderby, string $order, int $per_page, int $offset ): array {
		global $wpdb;
		if ( ! Install::ready() ) {
			return array();
		}
		$column   = isset( self::SORT_COLUMNS[ $orderby ] ) ? self::SORT_COLUMNS[ $orderby ] : self::SORT_COLUMNS['scanned'];
		$per_page = max( 1, $per_page );
		$offset   = max( 0, $offset );
		if ( 'asc' === $order ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table, read on demand.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url_hash, url, object_id, object_type, source, scanned_gmt, html_bytes, engine_us, changes_live, changes_preview, changes_off, findings FROM %i ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d', self::table_name(), $column, $per_page, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table, read on demand.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, url_hash, url, object_id, object_type, source, scanned_gmt, html_bytes, engine_us, changes_live, changes_preview, changes_off, findings FROM %i ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d', self::table_name(), $column, $per_page, $offset ), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes the report of one URL.
	 *
	 * @param string $hash URL hash.
	 * @return bool Whether a row was deleted.
	 */
	public function delete_by_hash( string $hash ): bool {
		global $wpdb;
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! Install::ready() ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table.
		return 1 === (int) $wpdb->delete( self::table_name(), array( 'url_hash' => $hash ), array( '%s' ) );
	}

	/**
	 * Number of rows.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		if ( ! Install::ready() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own report table, read on demand.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table_name() ) );
	}

	/**
	 * Rows kept by this store.
	 *
	 * @return int
	 */
	public function cap(): int {
		return $this->cap;
	}

	/**
	 * The stored shape of a result (blueprint section 11), privacy-stripped
	 * (AGENTS.md section 3): element signatures keep only the tag, id,
	 * classes, role, type, name and link path; no value or data-* attribute
	 * value is kept; text quoted from the page is cut to 80 characters and
	 * text the plugin wrote to 150; control characters are removed; each list
	 * holds at most 200 entries.
	 *
	 * @param array $result Result as built by the Scanner.
	 * @return array
	 */
	public static function privacy_strip( array $result ): array {
		$engine = array_fill_keys( self::ENGINE_KEYS, null );
		if ( isset( $result['engine'] ) && is_array( $result['engine'] ) ) {
			foreach ( self::ENGINE_KEYS as $key ) {
				if ( ! isset( $result['engine'][ $key ] ) ) {
					continue;
				}
				$value = $result['engine'][ $key ];
				if ( 'fallbacks' === $key ) {
					$engine[ $key ] = is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', array_filter( $value, 'is_string' ) ) ) ) ) : array();
				} elseif ( 'ms' === $key ) {
					$engine[ $key ] = round( max( 0.0, (float) $value ), 3 );
				} elseif ( in_array( $key, array( 'bytes', 'runs' ), true ) ) {
					$engine[ $key ] = absint( $value );
				} elseif ( is_scalar( $value ) ) {
					$engine[ $key ] = substr( preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value ), 0, 40 );
				}
			}
		}
		$engine['ms']    = null === $engine['ms'] ? 0.0 : $engine['ms'];
		$engine['bytes'] = null === $engine['bytes'] ? 0 : $engine['bytes'];

		$counts = array();
		if ( isset( $result['counts'] ) && is_array( $result['counts'] ) ) {
			foreach ( $result['counts'] as $fix => $states ) {
				$fix = is_string( $fix ) ? substr( sanitize_key( $fix ), 0, 40 ) : '';
				if ( '' === $fix || ! is_array( $states ) ) {
					continue;
				}
				foreach ( Engine::STATES as $state ) {
					$counts[ $fix ][ $state ] = isset( $states[ $state ] ) ? absint( $states[ $state ] ) : 0;
				}
			}
		}

		$changes = array();
		foreach ( self::list_of( $result, 'changes' ) as $change ) {
			$attr   = isset( $change['attr'] ) && is_string( $change['attr'] ) ? substr( preg_replace( '/[^a-z0-9_:-]/', '', strtolower( $change['attr'] ) ), 0, 40 ) : '';
			$before = isset( $change['before'] ) && is_scalar( $change['before'] ) ? self::text( (string) $change['before'], self::PAGE_TEXT_MAX ) : null;
			if ( null !== $before && '' !== $before && ( 'value' === $attr || 0 === strpos( $attr, 'data-' ) ) ) {
				$before = null;
			}
			$changes[] = array(
				'fix'     => isset( $change['fix'] ) && is_string( $change['fix'] ) ? substr( sanitize_key( $change['fix'] ), 0, 40 ) : '',
				'state'   => isset( $change['state'] ) && in_array( $change['state'], Engine::STATES, true ) ? $change['state'] : 'off',
				'sig'     => self::signature( isset( $change['sig'] ) && is_string( $change['sig'] ) ? $change['sig'] : '' ),
				'attr'    => $attr,
				'before'  => $before,
				'after'   => isset( $change['after'] ) && is_scalar( $change['after'] ) ? self::text( (string) $change['after'], self::PLUGIN_TEXT_MAX ) : '',
				'applied' => ! empty( $change['applied'] ),
			);
		}

		$findings = array();
		foreach ( self::list_of( $result, 'findings' ) as $finding ) {
			$entry = array(
				'check'  => isset( $finding['check'] ) && is_string( $finding['check'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $finding['check'] ), 0, 40 ) : '',
				'sc'     => isset( $finding['sc'] ) && is_string( $finding['sc'] ) ? substr( preg_replace( '/[^0-9., ]/', '', $finding['sc'] ), 0, 20 ) : '',
				'sig'    => self::signature( isset( $finding['sig'] ) && is_string( $finding['sig'] ) ? $finding['sig'] : '' ),
				'detail' => isset( $finding['detail'] ) && is_scalar( $finding['detail'] ) ? self::text( (string) $finding['detail'], self::PLUGIN_TEXT_MAX ) : '',
			);
			if ( ! empty( $finding['attachment'] ) ) {
				$entry['attachment'] = absint( $finding['attachment'] );
			}
			$findings[] = $entry;
		}

		$total_changes  = isset( $result['totals']['changes'] ) ? absint( $result['totals']['changes'] ) : ( isset( $result['changes'] ) && is_array( $result['changes'] ) ? count( $result['changes'] ) : 0 );
		$total_findings = isset( $result['totals']['findings'] ) ? absint( $result['totals']['findings'] ) : ( isset( $result['findings'] ) && is_array( $result['findings'] ) ? count( $result['findings'] ) : 0 );

		return array(
			'engine'   => $engine,
			'counts'   => $counts,
			'changes'  => array_slice( $changes, 0, self::LIST_LIMIT ),
			'findings' => array_slice( $findings, 0, self::LIST_LIMIT ),
			'totals'   => array(
				'changes'  => max( $total_changes, count( $changes ) ),
				'findings' => max( $total_findings, count( $findings ) ),
			),
			'not_seen' => array( 'R14' ),
		);
	}

	/**
	 * Entries of one list in a result that are arrays.
	 *
	 * @param array  $result Result.
	 * @param string $key    'changes' or 'findings'.
	 * @return array[]
	 */
	private static function list_of( array $result, string $key ): array {
		if ( ! isset( $result[ $key ] ) || ! is_array( $result[ $key ] ) ) {
			return array();
		}
		return array_slice( array_values( array_filter( $result[ $key ], 'is_array' ) ), 0, self::LIST_LIMIT );
	}

	/**
	 * An element signature with attribute parts outside the allowed list removed.
	 *
	 * @param string $signature Signature such as a.icon[href=/contact/].
	 * @return string
	 */
	private static function signature( string $signature ): string {
		$signature = (string) preg_replace_callback(
			'/\[([A-Za-z0-9_:.-]+)=[^\]]*\]?/',
			static function ( $part ) {
				return in_array( strtolower( $part[1] ), self::SIGNATURE_ATTRIBUTES, true ) ? $part[0] : '';
			},
			$signature
		);
		return self::text( $signature, 200 );
	}

	/**
	 * Text without control characters, cut to a length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function text( string $text, int $max ): string {
		return mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_check_invalid_utf8( $text, true ) ), 0, $max );
	}

	/**
	 * Deletes the oldest rows above the cap (each save gives its row a new id).
	 *
	 * @return void
	 */
	private function trim(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cap enforcement on the plugin's own table.
		$oldest_kept = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, $this->cap - 1 ) );
		if ( null === $oldest_kept ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cap enforcement on the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', $table, (int) $oldest_kept ) );
	}
}
