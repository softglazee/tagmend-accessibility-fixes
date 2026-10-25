<?php
/**
 * The activity log table.
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
 * Dated rows in {prefix}softglaze_a11y_log (blueprint sections 10.4 and 11):
 * fix state changes, scans, engine fallbacks and activation. Every value is
 * validated before the insert, the table is capped on insert (oldest rows
 * trimmed), and nothing is written while the tables do not match this
 * version of the plugin.
 */
final class Log_Store {

	const TABLE = 'softglaze_a11y_log';
	const CAP   = 5000;

	/**
	 * Events this version writes. Others are refused.
	 */
	const EVENTS = array( 'plugin_activated', 'fix_state_changed', 'scan', 'engine_fallback', 'fix_skip_added', 'fix_skip_removed' );

	/**
	 * Sort keys of the Activity log screen and the column each one orders by.
	 */
	const SORT_COLUMNS = array(
		'date'  => 'id',
		'event' => 'event',
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
	 * Adds one row, then trims the oldest rows above the cap.
	 *
	 * @param string $event  One of EVENTS.
	 * @param array  $fields Optional 'user_id', 'fix_id', 'from', 'to', 'url_hash', 'detail'.
	 * @return bool Whether a row was written.
	 */
	public function add( string $event, array $fields = array() ): bool {
		global $wpdb;
		if ( ! in_array( $event, self::EVENTS, true ) || ! Install::ready() ) {
			return false;
		}

		$row = array(
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'user_id'     => isset( $fields['user_id'] ) ? absint( $fields['user_id'] ) : get_current_user_id(),
			'event'       => $event,
			'fix_id'      => isset( $fields['fix_id'] ) ? substr( sanitize_key( (string) $fields['fix_id'] ), 0, 40 ) : '',
			'from_state'  => isset( $fields['from'] ) && in_array( $fields['from'], Engine::STATES, true ) ? $fields['from'] : '',
			'to_state'    => isset( $fields['to'] ) && in_array( $fields['to'], Engine::STATES, true ) ? $fields['to'] : '',
			'url_hash'    => isset( $fields['url_hash'] ) && is_string( $fields['url_hash'] ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $fields['url_hash'] ) ? $fields['url_hash'] : '',
			'detail'      => isset( $fields['detail'] ) && is_scalar( $fields['detail'] ) ? mb_substr( sanitize_text_field( (string) $fields['detail'] ), 0, 255 ) : '',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own log table; nothing to cache on write.
		$written = $wpdb->insert( self::table_name(), $row, array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $written ) {
			return false;
		}
		$this->trim();
		return true;
	}

	/**
	 * Logs a fix state change (softglaze_a11y_fix_state_changed). User 0 is WP-CLI.
	 *
	 * @param string $fix_id  Fix id.
	 * @param string $from    Previous state.
	 * @param string $to      New state.
	 * @param int    $user_id User id.
	 * @return void
	 */
	public function record_state_change( $fix_id, $from, $to, $user_id ): void {
		$user_id = absint( $user_id );
		$this->add(
			'fix_state_changed',
			array(
				'user_id' => $user_id,
				'fix_id'  => (string) $fix_id,
				'from'    => $from,
				'to'      => $to,
				'detail'  => 0 === $user_id ? 'WP-CLI' : '',
			)
		);
	}

	/**
	 * Newest rows first.
	 *
	 * @param int $limit Rows.
	 * @return array[] Rows as associative arrays.
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		if ( ! Install::ready() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own log table, read on demand.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table_name(), max( 1, $limit ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One page of log rows, each with the URL of the page its URL hash
	 * belongs to while that page still has a report ('page_url', or null).
	 * The event is compared as a value; the sort key is looked up in
	 * SORT_COLUMNS (anything else sorts by date) and the direction is one of
	 * two literals, so no request value becomes part of the query text.
	 *
	 * @param string $event    One of EVENTS, or '' for every event.
	 * @param string $orderby  Key of SORT_COLUMNS.
	 * @param string $order    'asc' or 'desc'.
	 * @param int    $per_page Rows per page.
	 * @param int    $offset   Rows to skip.
	 * @return array[]
	 */
	public function page( string $event, string $orderby, string $order, int $per_page, int $offset ): array {
		global $wpdb;
		if ( ! Install::ready() ) {
			return array();
		}
		$event    = in_array( $event, self::EVENTS, true ) ? $event : '';
		$column   = isset( self::SORT_COLUMNS[ $orderby ] ) ? self::SORT_COLUMNS[ $orderby ] : self::SORT_COLUMNS['date'];
		$per_page = max( 1, $per_page );
		$offset   = max( 0, $offset );
		$pages    = Pages_Store::table_name();
		if ( 'asc' === $order ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables, read on demand.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, p.url AS page_url FROM %i AS l LEFT JOIN %i AS p ON p.url_hash = l.url_hash AND l.url_hash <> '' WHERE ( %s = '' OR l.event = %s ) ORDER BY l.%i ASC, l.id ASC LIMIT %d OFFSET %d", self::table_name(), $pages, $event, $event, $column, $per_page, $offset ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables, read on demand.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, p.url AS page_url FROM %i AS l LEFT JOIN %i AS p ON p.url_hash = l.url_hash AND l.url_hash <> '' WHERE ( %s = '' OR l.event = %s ) ORDER BY l.%i DESC, l.id DESC LIMIT %d OFFSET %d", self::table_name(), $pages, $event, $event, $column, $per_page, $offset ), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of rows of one event, or of every event.
	 *
	 * @param string $event One of EVENTS, or '' for every event.
	 * @return int
	 */
	public function count_event( string $event ): int {
		global $wpdb;
		if ( ! Install::ready() ) {
			return 0;
		}
		$event = in_array( $event, self::EVENTS, true ) ? $event : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own log table, read on demand.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR event = %s )", self::table_name(), $event, $event ) );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own log table, read on demand.
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
	 * Deletes the oldest rows above the cap (ids grow with every insert).
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
