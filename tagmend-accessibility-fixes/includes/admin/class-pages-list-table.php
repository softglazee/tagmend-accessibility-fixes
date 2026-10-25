<?php
/**
 * The list of stored reports on the Reports screen.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per stored report: URL, last scanned, source, Live changes,
 * Preview and Off "would change" counts, findings and whether the page
 * changed since its report was made. Row actions: View original, View
 * repaired, Details, Rescan, Delete.
 */
final class Pages_List_Table extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Scan links and the report table.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Creates the table.
	 *
	 * @param Scanner $scanner Scan links and the report table.
	 * @param array   $args    WP_List_Table arguments (tests pass a screen).
	 */
	public function __construct( Scanner $scanner, array $args = array() ) {
		$this->scanner = $scanner;
		parent::__construct(
			array_merge(
				array(
					'singular' => 'softglaze-a11y-report',
					'plural'   => 'softglaze-a11y-reports',
					'ajax'     => false,
				),
				$args
			)
		);
	}

	/**
	 * Column headers.
	 *
	 * @return string[]
	 */
	public function get_columns() {
		return array(
			'url'      => __( 'Page', 'tagmend-accessibility-fixes' ),
			'scanned'  => __( 'Last scanned', 'tagmend-accessibility-fixes' ),
			'source'   => __( 'Source', 'tagmend-accessibility-fixes' ),
			'live'     => __( 'Live changes', 'tagmend-accessibility-fixes' ),
			'would'    => __( 'Would change (Preview, Off)', 'tagmend-accessibility-fixes' ),
			'findings' => __( 'Needs a person', 'tagmend-accessibility-fixes' ),
			'changed'  => __( 'Changed since scan', 'tagmend-accessibility-fixes' ),
		);
	}

	/**
	 * Sortable columns: the request key is one of Pages_Store::SORT_COLUMNS.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'url'      => array( 'url', false ),
			'scanned'  => array( 'scanned', true ),
			'live'     => array( 'live', true ),
			'findings' => array( 'findings', true ),
		);
	}

	/**
	 * The primary column (holds the row actions).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'url';
	}

	/**
	 * Requested sort key, allow-listed. List-table sorting only changes how
	 * the administrator's own list is shown; nothing is saved, so the read
	 * needs no nonce.
	 *
	 * @return string A key of Pages_Store::SORT_COLUMNS.
	 */
	public static function orderby(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list view state; allow-listed below.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		return isset( Pages_Store::SORT_COLUMNS[ $orderby ] ) ? $orderby : 'scanned';
	}

	/**
	 * Requested sort direction, allow-listed (read-only view state, as above).
	 *
	 * @return string 'asc' or 'desc'.
	 */
	public static function order(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list view state; allow-listed below.
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		if ( 'asc' === $order || 'desc' === $order ) {
			return $order;
		}
		return 'url' === self::orderby() ? 'asc' : 'desc';
	}

	/**
	 * Loads one page of reports and primes the posts they belong to.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$pages = $this->scanner->pages();
		$total = $pages->count();
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
		$this->items           = $pages->page( self::orderby(), self::order(), self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url' );

		$ids = array();
		foreach ( $this->items as $item ) {
			if ( ! empty( $item['object_id'] ) && ! in_array( (string) $item['object_type'], Report_View::NOT_POSTS, true ) ) {
				$ids[] = absint( $item['object_id'] );
			}
		}
		if ( ! empty( $ids ) ) {
			_prime_post_caches( array_unique( $ids ), false, false );
		}
	}

	/**
	 * Text shown when no report exists.
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html__( 'No reports yet. Scan your home page above, or open a page and use "Scan this page" in the admin bar.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * The page column: the URL, linked to the report details.
	 *
	 * @param array $item Report row.
	 * @return string
	 */
	protected function column_url( $item ) {
		return sprintf(
			'<strong><a class="row-title" href="%1$s">%2$s</a></strong>',
			esc_url( Screen_Reports::details_url( (string) $item['url_hash'] ) ),
			esc_html( (string) $item['url'] )
		);
	}

	/**
	 * Other columns.
	 *
	 * @param array  $item        Report row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'scanned':
				return esc_html( Report_View::date( (string) $item['scanned_gmt'] ) );
			case 'source':
				return esc_html( Report_View::source_label( (string) $item['source'] ) );
			case 'live':
				return esc_html( number_format_i18n( (int) $item['changes_live'] ) );
			case 'would':
				return esc_html(
					sprintf(
						/* translators: 1: number of changes of fixes in Preview, 2: number of changes fixes that are Off would make */
						__( '%1$s Preview, %2$s Off', 'tagmend-accessibility-fixes' ),
						number_format_i18n( (int) $item['changes_preview'] ),
						number_format_i18n( (int) $item['changes_off'] )
					)
				);
			case 'findings':
				return esc_html( number_format_i18n( (int) $item['findings'] ) );
			case 'changed':
				$changed = Report_View::changed_since_scan( $item );
				if ( true === $changed ) {
					return '<strong>' . esc_html__( 'Changed since last scan', 'tagmend-accessibility-fixes' ) . '</strong>';
				}
				if ( false === $changed ) {
					return esc_html__( 'No', 'tagmend-accessibility-fixes' );
				}
				return esc_html__( 'Not known', 'tagmend-accessibility-fixes' );
			default:
				return '';
		}
	}

	/**
	 * Row actions under the URL.
	 *
	 * @param array  $item        Report row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$url     = (string) $item['url'];
		$label   = esc_html( $url );
		$actions = array(
			'view_original' => sprintf(
				'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Report_View::view_url( $url, 'original' ) ),
				esc_html__( 'View original', 'tagmend-accessibility-fixes' ),
				$label
			),
			'view_repaired' => sprintf(
				'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Report_View::view_url( $url, 'repaired' ) ),
				esc_html__( 'View repaired', 'tagmend-accessibility-fixes' ),
				$label
			),
			'details'       => sprintf(
				'<a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Screen_Reports::details_url( (string) $item['url_hash'] ) ),
				esc_html__( 'Details', 'tagmend-accessibility-fixes' ),
				$label
			),
			'rescan'        => sprintf(
				'<a class="softglaze-a11y-rescan" href="%1$s" data-url="%2$s">%3$s<span class="screen-reader-text"> %4$s</span></a>',
				esc_url( $this->scanner->scan_url( $url ) ),
				esc_attr( $url ),
				esc_html__( 'Rescan', 'tagmend-accessibility-fixes' ),
				$label
			),
			'delete'        => sprintf(
				'<a class="submitdelete" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
				esc_url( Screen_Reports::delete_url( (string) $item['url_hash'] ) ),
				esc_html__( 'Delete report', 'tagmend-accessibility-fixes' ),
				$label
			),
		);
		return $this->row_actions( $actions );
	}
}
