<?php
/**
 * The Activity log screen and its CSV export.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the activity log by date with an event filter (blueprint sections
 * 10.4 and 12), and exports it as CSV through admin-post.php: capability
 * first, then the nonce, streamed to php://output (no file is written), and
 * every cell that a spreadsheet could read as a formula is prefixed with an
 * apostrophe.
 */
final class Screen_Log {

	const PAGE          = 'softglaze-a11y-log';
	const EVENT_ARG     = 'softglaze_a11y_event';
	const NONCE_ARG     = 'softglaze_a11y_nonce';
	const EXPORT_ACTION = 'softglaze_a11y_export_log';

	/**
	 * Rows read per query while exporting.
	 */
	const EXPORT_CHUNK = 500;

	/**
	 * First characters that make a spreadsheet treat a cell as a formula.
	 */
	const FORMULA_START = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Fix list (names).
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Activity log.
	 *
	 * @var Log_Store
	 */
	private $log;

	/**
	 * User names already looked up.
	 *
	 * @var string[]
	 */
	private $users = array();

	/**
	 * Creates the screen.
	 *
	 * @param Registry  $registry Fix list.
	 * @param Log_Store $log      Activity log.
	 */
	public function __construct( Registry $registry, Log_Store $log ) {
		$this->registry = $registry;
		$this->log      = $log;
	}

	/**
	 * Hooks the export handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
	}

	/**
	 * Adds the submenu page.
	 *
	 * @param string $parent_slug Top-level menu slug.
	 * @return string Hook suffix of the page ('' when it was not added).
	 */
	public function add_page( string $parent_slug ): string {
		$hook = add_submenu_page(
			$parent_slug,
			__( 'Activity log', 'tagmend-accessibility-fixes' ),
			__( 'Activity log', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		return is_string( $hook ) ? $hook : '';
	}

	/**
	 * The activity log.
	 *
	 * @return Log_Store
	 */
	public function log(): Log_Store {
		return $this->log;
	}

	/**
	 * The event filter of the list, allow-listed. Filtering only changes how
	 * the administrator's own list is shown; nothing is saved, so the read
	 * needs no nonce.
	 *
	 * @return string One of Log_Store::EVENTS, or '' for every event.
	 */
	public static function requested_event(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; allow-listed below.
		$event = isset( $_GET[ self::EVENT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::EVENT_ARG ] ) ) : '';
		return in_array( $event, Log_Store::EVENTS, true ) ? $event : '';
	}

	/**
	 * Name of an event.
	 *
	 * @param string $event Event id.
	 * @return string
	 */
	public static function event_label( string $event ): string {
		switch ( $event ) {
			case 'plugin_activated':
				return __( 'Plugin activated', 'tagmend-accessibility-fixes' );
			case 'fix_state_changed':
				return __( 'Fix state changed', 'tagmend-accessibility-fixes' );
			case 'scan':
				return __( 'Page scanned', 'tagmend-accessibility-fixes' );
			case 'engine_fallback':
				return __( 'Page served unrepaired', 'tagmend-accessibility-fixes' );
			case 'fix_skip_added':
				return __( 'Fix skipped on a page', 'tagmend-accessibility-fixes' );
			case 'fix_skip_removed':
				return __( 'Fix runs on a page again', 'tagmend-accessibility-fixes' );
			case 'settings_saved':
				return __( 'Settings saved', 'tagmend-accessibility-fixes' );
			default:
				return $event;
		}
	}

	/**
	 * Plain-text values of one log row, as shown on the screen and exported.
	 *
	 * @param array $row Log row (with page_url from Log_Store::page()).
	 * @return string[] Keys: date, date_gmt, event, user, fix, from, to, change, page, detail.
	 */
	public function describe( array $row ): array {
		$event = isset( $row['event'] ) ? (string) $row['event'] : '';
		$from  = isset( $row['from_state'] ) ? (string) $row['from_state'] : '';
		$to    = isset( $row['to_state'] ) ? (string) $row['to_state'] : '';
		$gmt   = isset( $row['created_gmt'] ) ? (string) $row['created_gmt'] : '';
		$fix   = isset( $row['fix_id'] ) ? (string) $row['fix_id'] : '';
		return array(
			'date'     => Report_View::date( $gmt ),
			'date_gmt' => $gmt,
			'event'    => self::event_label( $event ),
			'user'     => $this->user_label( isset( $row['user_id'] ) ? (int) $row['user_id'] : 0, $event ),
			'fix'      => '' === $fix ? '' : $this->fix_label( $fix ),
			'from'     => self::plain_state( $from ),
			'to'       => self::plain_state( $to ),
			'change'   => '' === $from && '' === $to ? '' : sprintf(
				/* translators: 1: previous fix state, 2: new fix state */
				__( '%1$s to %2$s', 'tagmend-accessibility-fixes' ),
				self::plain_state( $from ),
				self::plain_state( $to )
			),
			'page'     => isset( $row['page_url'] ) && is_string( $row['page_url'] ) ? $row['page_url'] : '',
			'detail'   => self::detail( $event, isset( $row['detail'] ) ? (string) $row['detail'] : '' ),
		);
	}

	/**
	 * A cell value that a spreadsheet will not run as a formula: values
	 * starting with =, +, -, @, a tab or a carriage return get a leading
	 * apostrophe (blueprint section 12).
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' !== $value && in_array( $value[0], self::FORMULA_START, true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Handles the export request: streams the CSV, then stops.
	 *
	 * @return void
	 */
	public function handle_export() {
		$event = $this->process_export();
		$this->stream_csv( $event );
		exit;
	}

	/**
	 * Checks the capability, then the nonce, and returns the event filter
	 * (read by name, allow-listed). Dies with 403 otherwise.
	 *
	 * @return string One of Log_Store::EVENTS, or ''.
	 */
	public function process_export(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to export the activity log.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$nonce = isset( $_POST[ self::NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_ARG ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::EXPORT_ACTION ) ) {
			wp_die(
				esc_html__( 'This form has expired. Open the Activity log again and export once more.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$event = isset( $_POST[ self::EVENT_ARG ] ) ? sanitize_key( wp_unslash( $_POST[ self::EVENT_ARG ] ) ) : '';
		return in_array( $event, Log_Store::EVENTS, true ) ? $event : '';
	}

	/**
	 * Streams the log, newest first, as CSV to php://output. Headers are sent
	 * only while that is still possible.
	 *
	 * @param string $event One of Log_Store::EVENTS, or '' for every event.
	 * @return void
	 */
	public function stream_csv( string $event ): void {
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tagmend-activity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The response body stream; no file is written.
		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			return;
		}
		fputcsv(
			$out,
			array_map(
				array( __CLASS__, 'csv_cell' ),
				array(
					__( 'Date (UTC)', 'tagmend-accessibility-fixes' ),
					__( 'Event', 'tagmend-accessibility-fixes' ),
					__( 'User', 'tagmend-accessibility-fixes' ),
					__( 'Fix', 'tagmend-accessibility-fixes' ),
					__( 'From', 'tagmend-accessibility-fixes' ),
					__( 'To', 'tagmend-accessibility-fixes' ),
					__( 'Page', 'tagmend-accessibility-fixes' ),
					__( 'Detail', 'tagmend-accessibility-fixes' ),
				)
			),
			',',
			'"',
			''
		);
		$offset = 0;
		do {
			$rows  = $this->log->page( $event, 'date', 'desc', self::EXPORT_CHUNK, $offset );
			$count = count( $rows );
			foreach ( $rows as $row ) {
				$values = $this->describe( $row );
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'csv_cell' ),
						array( $values['date_gmt'], $values['event'], $values['user'], $values['fix'], $values['from'], $values['to'], $values['page'], $values['detail'] )
					),
					',',
					'"',
					''
				);
			}
			$offset += self::EXPORT_CHUNK;
		} while ( self::EXPORT_CHUNK === $count );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the response body stream opened above.
		fclose( $out );
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$table = new Log_List_Table( $this );
		$table->prepare_items();
		$event = self::requested_event();
		?>
		<div class="wrap softglaze-a11y-screen">
			<h1><?php echo esc_html__( 'Activity log', 'tagmend-accessibility-fixes' ); ?></h1>
			<p><?php echo esc_html__( 'A dated record of the fixes you turned on and what each scan found. The latest 5,000 entries are kept.', 'tagmend-accessibility-fixes' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="softglaze-a11y-export">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT_ACTION ); ?>">
				<input type="hidden" name="<?php echo esc_attr( self::EVENT_ARG ); ?>" value="<?php echo esc_attr( $event ); ?>">
				<?php wp_nonce_field( self::EXPORT_ACTION, self::NONCE_ARG ); ?>
				<button type="submit" class="button">
					<?php
					echo '' === $event
						? esc_html__( 'Download as CSV', 'tagmend-accessibility-fixes' )
						: esc_html(
							sprintf(
								/* translators: %s: event name, for example "Page scanned" */
								__( 'Download "%s" entries as CSV', 'tagmend-accessibility-fixes' ),
								self::event_label( $event )
							)
						);
					?>
				</button>
			</form>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Who made a change.
	 *
	 * @param int    $user_id User id (0 for WP-CLI and visitors).
	 * @param string $event   Event.
	 * @return string
	 */
	private function user_label( int $user_id, string $event ): string {
		if ( 0 === $user_id ) {
			return 'engine_fallback' === $event ? __( 'Not logged in', 'tagmend-accessibility-fixes' ) : __( 'WP-CLI', 'tagmend-accessibility-fixes' );
		}
		if ( ! isset( $this->users[ $user_id ] ) ) {
			$user                    = get_userdata( $user_id );
			$this->users[ $user_id ] = $user ? wp_specialchars_decode( (string) $user->display_name, ENT_QUOTES ) : sprintf(
				/* translators: %d: user id */
				__( 'User %d (deleted)', 'tagmend-accessibility-fixes' ),
				$user_id
			);
		}
		return $this->users[ $user_id ];
	}

	/**
	 * Name of a fix, or its id when it is not registered now.
	 *
	 * @param string $fix_id Fix id.
	 * @return string
	 */
	private function fix_label( string $fix_id ): string {
		$fix = $this->registry->get( $fix_id );
		if ( null === $fix ) {
			return $fix_id;
		}
		try {
			return $fix->label();
		} catch ( Throwable $throwable ) {
			return $fix_id;
		}
	}

	/**
	 * Name of a fix state without the report wording.
	 *
	 * @param string $state 'live', 'preview', 'off' or ''.
	 * @return string
	 */
	private static function plain_state( string $state ): string {
		switch ( $state ) {
			case 'live':
				return __( 'Live', 'tagmend-accessibility-fixes' );
			case 'preview':
				return __( 'Preview', 'tagmend-accessibility-fixes' );
			case 'off':
				return __( 'Off', 'tagmend-accessibility-fixes' );
			default:
				return '';
		}
	}

	/**
	 * Readable detail of a row. The stored detail is written by the plugin in
	 * a fixed form (for example "source=admin_scan live=0 ... scope=page").
	 *
	 * @param string $event  Event.
	 * @param string $detail Stored detail.
	 * @return string
	 */
	private static function detail( string $event, string $detail ): string {
		switch ( $event ) {
			case 'plugin_activated':
				return '' === $detail ? '' : sprintf(
					/* translators: %s: plugin version */
					__( 'Version %s', 'tagmend-accessibility-fixes' ),
					$detail
				);
			case 'engine_fallback':
				return Report_View::fallback_label( $detail );
			case 'fix_skip_added':
			case 'fix_skip_removed':
				return 1 === preg_match( '/^post=(\d+)$/', $detail, $match ) ? sprintf(
					/* translators: %d: post id */
					__( 'Post %d', 'tagmend-accessibility-fixes' ),
					(int) $match[1]
				) : $detail;
			case 'fix_state_changed':
				return '';
			case 'scan':
				return self::scan_detail( $detail );
			case 'settings_saved':
				return self::settings_detail( $detail );
			default:
				return $detail;
		}
	}

	/**
	 * Readable detail of a settings_saved row (stored as changed=key,key).
	 *
	 * @param string $detail Stored detail.
	 * @return string
	 */
	private static function settings_detail( string $detail ): string {
		if ( 1 !== preg_match( '/^changed=([a-z_,]+)$/', $detail, $match ) ) {
			return $detail;
		}
		$labels = array_map( array( Screen_Settings::class, 'key_label' ), explode( ',', $match[1] ) );
		return sprintf(
			/* translators: %s: names of the changed settings, separated by commas */
			__( 'Changed: %s', 'tagmend-accessibility-fixes' ),
			implode( ', ', $labels )
		);
	}

	/**
	 * Readable detail of a scan row.
	 *
	 * @param string $detail Stored detail.
	 * @return string
	 */
	private static function scan_detail( string $detail ): string {
		if ( 1 !== preg_match( '/^source=([a-z_]+) live=(\d+) preview=(\d+) off=(\d+) findings=(\d+) scope=([a-z]*)(?: reason=([a-z0-9_]+))?$/', $detail, $match ) ) {
			return $detail;
		}
		$counts = sprintf(
			/* translators: 1: report source, for example "Scan"; 2: Live changes; 3: Preview changes; 4: changes of fixes that are Off; 5: items that need a person */
			__( '%1$s: %2$s Live, %3$s Preview, %4$s Off, %5$s need a person', 'tagmend-accessibility-fixes' ),
			Report_View::source_label( $match[1] ),
			number_format_i18n( (int) $match[2] ),
			number_format_i18n( (int) $match[3] ),
			number_format_i18n( (int) $match[4] ),
			number_format_i18n( (int) $match[5] )
		);
		if ( 'page' === $match[6] ) {
			return $counts . '; ' . __( 'whole page', 'tagmend-accessibility-fixes' );
		}
		$reason = isset( $match[7] ) ? $match[7] : '';
		switch ( $reason ) {
			case Scanner::REASON_PAGE_BUFFER_OFF:
				$scope = __( 'content only: another plugin switched off the page buffer', 'tagmend-accessibility-fixes' );
				break;
			case Scanner::REASON_NEEDS_WP69:
				$scope = __( 'content only: page-level checks need WordPress 6.9 or later', 'tagmend-accessibility-fixes' );
				break;
			case Scanner::REASON_PAGE_PASS_OFF:
				$scope = __( 'content only: the whole-page repair is switched off', 'tagmend-accessibility-fixes' );
				break;
			default:
				$scope = __( 'content only', 'tagmend-accessibility-fixes' );
		}
		return $counts . '; ' . $scope;
	}
}
