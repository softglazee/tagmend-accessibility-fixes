<?php
/**
 * The activity log list on the Activity log screen.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Report\Log_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * One row per log entry, newest first by default: date, event, user, fix,
 * state change, page and detail, with a filter by event above the list.
 */
final class Log_List_Table extends \WP_List_Table {

	const PER_PAGE = 50;

	/**
	 * The screen (log access and row descriptions).
	 *
	 * @var Screen_Log
	 */
	private $screen_log;

	/**
	 * Plain-text values of the rows on this page, by log id.
	 *
	 * @var array[]
	 */
	private $described = array();

	/**
	 * Creates the table.
	 *
	 * @param Screen_Log $screen_log The screen.
	 * @param array      $args       WP_List_Table arguments (tests pass a screen).
	 */
	public function __construct( Screen_Log $screen_log, array $args = array() ) {
		$this->screen_log = $screen_log;
		parent::__construct(
			array_merge(
				array(
					'singular' => 'softglaze-a11y-log-entry',
					'plural'   => 'softglaze-a11y-log-entries',
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
			'created' => __( 'Date', 'tagmend-accessibility-fixes' ),
			'event'   => __( 'Event', 'tagmend-accessibility-fixes' ),
			'user'    => __( 'User', 'tagmend-accessibility-fixes' ),
			'fix'     => __( 'Fix', 'tagmend-accessibility-fixes' ),
			'change'  => __( 'Change', 'tagmend-accessibility-fixes' ),
			'page'    => __( 'Page', 'tagmend-accessibility-fixes' ),
			'detail'  => __( 'Detail', 'tagmend-accessibility-fixes' ),
		);
	}

	/**
	 * Sortable columns: the request key is one of Log_Store::SORT_COLUMNS.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created' => array( 'date', true ),
			'event'   => array( 'event', false ),
		);
	}

	/**
	 * The primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'created';
	}

	/**
	 * Requested sort key, allow-listed. List-table sorting only changes how
	 * the administrator's own list is shown; nothing is saved, so the read
	 * needs no nonce.
	 *
	 * @return string A key of Log_Store::SORT_COLUMNS.
	 */
	public static function orderby(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list view state; allow-listed below.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		return isset( Log_Store::SORT_COLUMNS[ $orderby ] ) ? $orderby : 'date';
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
		return 'event' === self::orderby() ? 'asc' : 'desc';
	}

	/**
	 * Loads one page of log rows for the event filter.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$log   = $this->screen_log->log();
		$event = Screen_Log::requested_event();
		$this->set_pagination_args(
			array(
				'total_items' => $log->count_event( $event ),
				'per_page'    => self::PER_PAGE,
			)
		);
		$this->items           = $log->page( $event, self::orderby(), self::order(), self::PER_PAGE, ( $this->get_pagenum() - 1 ) * self::PER_PAGE );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created' );
	}

	/**
	 * Text shown when the log is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html__( 'No entries yet.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * The event filter above the list.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = Screen_Log::requested_event();
		?>
		<div class="alignleft actions">
			<label for="softglaze-a11y-event-filter" class="screen-reader-text"><?php echo esc_html__( 'Show entries of', 'tagmend-accessibility-fixes' ); ?></label>
			<select name="<?php echo esc_attr( Screen_Log::EVENT_ARG ); ?>" id="softglaze-a11y-event-filter">
				<option value=""><?php echo esc_html__( 'All events', 'tagmend-accessibility-fixes' ); ?></option>
				<?php foreach ( Log_Store::EVENTS as $event ) : ?>
					<option value="<?php echo esc_attr( $event ); ?>"<?php selected( $current, $event ); ?>><?php echo esc_html( Screen_Log::event_label( $event ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'tagmend-accessibility-fixes' ), '', 'softglaze_a11y_filter', false ); ?>
		</div>
		<?php
	}

	/**
	 * Every column, as plain text from Screen_Log::describe().
	 *
	 * @param array  $item        Log row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( ! isset( $this->described[ $id ] ) ) {
			$this->described[ $id ] = $this->screen_log->describe( $item );
		}
		$values = $this->described[ $id ];
		switch ( $column_name ) {
			case 'created':
				return esc_html( $values['date'] );
			case 'event':
			case 'user':
			case 'fix':
			case 'change':
			case 'detail':
				return esc_html( $values[ $column_name ] );
			case 'page':
				return '' === $values['page'] ? '' : '<a href="' . esc_url( $values['page'] ) . '">' . esc_html( $values['page'] ) . '</a>';
			default:
				return '';
		}
	}
}
