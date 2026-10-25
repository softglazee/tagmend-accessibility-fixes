<?php
/**
 * The details of one stored report.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Settings;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows one report (blueprint sections 10.2 and 12): what it covers, the
 * changes by fix, what needs a person (R02-R13) and what the plugin does not
 * check (R14). Each fix can be moved to Live, or skipped on this page when
 * the page is a single post; both go through admin-post.php with the
 * capability checked first, then the nonce. Every stored value is printed
 * as text.
 */
final class Screen_Report_Details {

	const GO_LIVE_ACTION = 'softglaze_a11y_go_live';
	const SKIP_ACTION    = 'softglaze_a11y_skip_fix';
	const FIX_FIELD      = 'softglaze_a11y_fix';
	const SKIP_FIELD     = 'softglaze_a11y_skip';
	const SKIP_META      = '_softglaze_a11y_skip_fixes';

	/**
	 * Fixes that work only through WordPress hooks while a page is built
	 * (F04 product image alt text, F18 menu labels), so scans never list them.
	 */
	const HOOK_ONLY_FIXES = array( 'product-img-alt-name', 'nav-labels' );

	/**
	 * Fix list.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Scan links and the report table.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Activity log.
	 *
	 * @var Log_Store
	 */
	private $log;

	/**
	 * Creates the view.
	 *
	 * @param Registry  $registry Fix list.
	 * @param Settings  $settings Settings.
	 * @param Scanner   $scanner  Scan links and the report table.
	 * @param Log_Store $log      Activity log.
	 */
	public function __construct( Registry $registry, Settings $settings, Scanner $scanner, Log_Store $log ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->scanner  = $scanner;
		$this->log      = $log;
	}

	/**
	 * Hooks the two admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::GO_LIVE_ACTION, array( $this, 'handle_go_live' ) );
		add_action( 'admin_post_' . self::SKIP_ACTION, array( $this, 'handle_skip' ) );
	}

	/**
	 * Fix ids skipped on a post (the gate reads the same meta).
	 *
	 * @param int $post_id Post id.
	 * @return string[]
	 */
	public static function skipped_fixes( int $post_id ): array {
		$ids = get_post_meta( $post_id, self::SKIP_META, true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_filter( $ids, 'is_string' ) ) ) ) );
	}

	/**
	 * Moves a fix to Live, then returns to the report.
	 *
	 * @return void
	 */
	public function handle_go_live() {
		wp_safe_redirect( $this->process_go_live() );
		exit;
	}

	/**
	 * Skips a fix on the report's page, or stops skipping it, then returns to the report.
	 *
	 * @return void
	 */
	public function handle_skip() {
		wp_safe_redirect( $this->process_skip() );
		exit;
	}

	/**
	 * Checks the capability, then the nonce, then saves Live for one
	 * registered fix through the settings sanitizer (which refuses Live for a
	 * fix that cannot run here). The state change is logged by the
	 * softglaze_a11y_fix_state_changed listener. Returns the report URL.
	 *
	 * @return string
	 */
	public function process_go_live(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::refuse( __( 'Sorry, you are not allowed to change fix states.', 'tagmend-accessibility-fixes' ) );
		}
		$nonce = isset( $_POST[ Screen_Reports::NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Screen_Reports::NONCE_ARG ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::GO_LIVE_ACTION ) ) {
			self::refuse( __( 'This form has expired. Open the report again and try once more.', 'tagmend-accessibility-fixes' ) );
		}

		$hash = isset( $_POST[ Screen_Reports::REPORT_ARG ] ) ? sanitize_key( wp_unslash( $_POST[ Screen_Reports::REPORT_ARG ] ) ) : '';
		$fix  = isset( $_POST[ self::FIX_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIX_FIELD ] ) ) : '';

		$status = 'live_refused';
		if ( null !== $this->registry->get( $fix ) ) {
			$rejected = $this->settings->save_fix_states( array( $fix => 'live' ), get_current_user_id() );
			$states   = $this->settings->fix_states();
			$status   = empty( $rejected ) && isset( $states[ $fix ] ) && 'live' === $states[ $fix ] ? 'live' : 'live_refused';
		}
		// TODO(Phase 7): name the detected page-cache plugin and remind the admin to purge it (decision D-05: reminder only).
		return $this->back( $hash, $status );
	}

	/**
	 * Checks the capability, then the nonce, then adds a registered fix to
	 * (or removes it from) the skip list of the post the report belongs to.
	 * The post comes from the stored report, never from the request. Logs
	 * fix_skip_added or fix_skip_removed. Returns the report URL.
	 *
	 * @return string
	 */
	public function process_skip(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::refuse( __( 'Sorry, you are not allowed to change where fixes run.', 'tagmend-accessibility-fixes' ) );
		}
		$nonce = isset( $_POST[ Screen_Reports::NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_POST[ Screen_Reports::NONCE_ARG ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::SKIP_ACTION ) ) {
			self::refuse( __( 'This form has expired. Open the report again and try once more.', 'tagmend-accessibility-fixes' ) );
		}

		$hash = isset( $_POST[ Screen_Reports::REPORT_ARG ] ) ? sanitize_key( wp_unslash( $_POST[ Screen_Reports::REPORT_ARG ] ) ) : '';
		$fix  = isset( $_POST[ self::FIX_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FIX_FIELD ] ) ) : '';
		$skip = isset( $_POST[ self::SKIP_FIELD ] ) && '1' === sanitize_key( wp_unslash( $_POST[ self::SKIP_FIELD ] ) );

		$row = $this->scanner->pages()->get_by_hash( $hash );
		if ( null === $row ) {
			return Screen_Reports::url( array( Screen_Reports::STATUS_ARG => 'not_found' ) );
		}
		$post_id = Report_View::post_id( $row );
		if ( 0 === $post_id || null === $this->registry->get( $fix ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->back( $hash, 'skip_refused' );
		}

		$skipped = self::skipped_fixes( $post_id );
		$present = in_array( $fix, $skipped, true );
		if ( $skip && ! $present ) {
			$skipped[] = $fix;
		} elseif ( ! $skip && $present ) {
			$skipped = array_values( array_diff( $skipped, array( $fix ) ) );
		}
		if ( $skip !== $present ) {
			if ( empty( $skipped ) ) {
				delete_post_meta( $post_id, self::SKIP_META );
			} else {
				update_post_meta( $post_id, self::SKIP_META, $skipped );
			}
			$this->log->add(
				$skip ? 'fix_skip_added' : 'fix_skip_removed',
				array(
					'fix_id'   => $fix,
					'url_hash' => $hash,
					'detail'   => 'post=' . $post_id,
				)
			);
		}
		return $this->back( $hash, $skip ? 'skipped' : 'unskipped' );
	}

	/**
	 * Prints the details of one report.
	 *
	 * @param string $hash   URL hash.
	 * @param string $status Result of the last action, or ''.
	 * @return void
	 */
	public function render( string $hash, string $status ): void {
		$row = $this->scanner->pages()->get_by_hash( $hash );
		?>
		<div class="wrap softglaze-a11y-screen softglaze-a11y-details">
			<p class="softglaze-a11y-back"><a href="<?php echo esc_url( Screen_Reports::url() ); ?>"><?php echo esc_html__( 'Back to Reports', 'tagmend-accessibility-fixes' ); ?></a></p>
			<h1><?php echo esc_html__( 'Report details', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php
			Screen_Reports::print_status( $status );
			if ( null === $row ) {
				echo '<p>' . esc_html__( 'This report no longer exists. It may have been deleted, or replaced when the list reached 500 pages.', 'tagmend-accessibility-fixes' ) . '</p></div>';
				return;
			}
			$result  = is_array( $row['result'] ) ? $row['result'] : array();
			$engine  = isset( $result['engine'] ) && is_array( $result['engine'] ) ? $result['engine'] : array();
			$url     = (string) $row['url'];
			$post_id = Report_View::post_id( $row );
			$this->render_header( $row, $engine, $post_id );
			?>
			<div class="softglaze-a11y-rescan-panel" data-softglaze-a11y-scanner="1" data-reload-url="<?php echo esc_url( Screen_Reports::details_url( $hash ) ); ?>">
				<p><a class="button softglaze-a11y-rescan" href="<?php echo esc_url( $this->scanner->scan_url( $url ) ); ?>" data-url="<?php echo esc_attr( $url ); ?>"><?php echo esc_html__( 'Rescan this page', 'tagmend-accessibility-fixes' ); ?></a></p>
				<div class="softglaze-a11y-scan-status" role="status" aria-live="polite" aria-atomic="true"></div>
				<ul class="softglaze-a11y-scan-results" hidden></ul>
			</div>
			<?php
			$this->render_changes( $hash, $result, (string) $row['source'], $post_id );
			$this->render_findings( $result );
			$this->render_not_checked();
			?>
		</div>
		<?php
	}

	/**
	 * The report header: page, date, source, counts, scope, notes and links.
	 *
	 * @param array $row     Report row.
	 * @param array $engine  Engine facts of the result.
	 * @param int   $post_id Post the report belongs to, 0 when none.
	 * @return void
	 */
	private function render_header( array $row, array $engine, int $post_id ): void {
		$url          = (string) $row['url'];
		$source       = (string) $row['source'];
		$scope        = isset( $engine['scope'] ) ? (string) $engine['scope'] : '';
		$scope_reason = isset( $engine['scope_reason'] ) ? $engine['scope_reason'] : null;
		$fallbacks    = isset( $engine['fallbacks'] ) && is_array( $engine['fallbacks'] ) ? $engine['fallbacks'] : array();
		$changed      = Report_View::changed_since_scan( $row );
		?>
		<p class="softglaze-a11y-report-url"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a></p>
		<dl class="softglaze-a11y-facts">
			<dt><?php echo esc_html__( 'Last scanned', 'tagmend-accessibility-fixes' ); ?></dt>
			<dd><?php echo esc_html( Report_View::date( (string) $row['scanned_gmt'] ) ); ?></dd>
			<dt><?php echo esc_html__( 'Source', 'tagmend-accessibility-fixes' ); ?></dt>
			<dd><?php echo esc_html( Report_View::source_label( $source ) ); ?></dd>
			<dt><?php echo esc_html__( 'Live changes', 'tagmend-accessibility-fixes' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $row['changes_live'] ) ); ?></dd>
			<dt><?php echo esc_html__( 'Would change (Preview, Off)', 'tagmend-accessibility-fixes' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of changes of fixes in Preview, 2: number of changes fixes that are Off would make */
						__( '%1$s Preview, %2$s Off', 'tagmend-accessibility-fixes' ),
						number_format_i18n( (int) $row['changes_preview'] ),
						number_format_i18n( (int) $row['changes_off'] )
					)
				);
				?>
			</dd>
			<dt><?php echo esc_html__( 'Needs a person', 'tagmend-accessibility-fixes' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $row['findings'] ) ); ?></dd>
		</dl>
		<div class="softglaze-a11y-notes">
			<p><?php echo esc_html( Report_View::scope_note( $scope, $scope_reason ) ); ?></p>
			<?php if ( 'cli' === $source ) : ?>
				<p><?php echo esc_html__( 'This report was made with WP-CLI from a saved copy of the page.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html__( 'Scans see the page as a logged-in administrator. The page can differ for visitors who are not logged in, for example in cart and account links, or in edit links that some themes add.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php endif; ?>
			<?php if ( true === $changed ) : ?>
				<p><strong><?php echo esc_html__( 'Changed since last scan:', 'tagmend-accessibility-fixes' ); ?></strong> <?php echo esc_html__( 'the page was edited after this report was made. Rescan it to see the current state.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $fallbacks as $fallback ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: why the repair engine stopped, for example "the repair took longer than the time limit" */
							__( 'The repair engine left part of this page unchanged: %s. Changes and findings from that part are not in this report.', 'tagmend-accessibility-fixes' ),
							Report_View::fallback_label( (string) $fallback )
						)
					);
					?>
				</p>
			<?php endforeach; ?>
		</div>
		<p class="softglaze-a11y-links">
			<a class="button" href="<?php echo esc_url( Report_View::view_url( $url, 'original' ) ); ?>"><?php echo esc_html__( 'View original', 'tagmend-accessibility-fixes' ); ?></a>
			<a class="button" href="<?php echo esc_url( Report_View::view_url( $url, 'repaired' ) ); ?>"><?php echo esc_html__( 'View repaired (Live + Preview)', 'tagmend-accessibility-fixes' ); ?></a>
			<?php if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) : ?>
				<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php echo esc_html__( 'Edit this page', 'tagmend-accessibility-fixes' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Changes by fix, in catalogue order, with Move to Live and skip buttons.
	 *
	 * @param string $hash    URL hash.
	 * @param array  $result  Stored result.
	 * @param string $source  Report source.
	 * @param int    $post_id Post the report belongs to, 0 when none.
	 * @return void
	 */
	private function render_changes( string $hash, array $result, string $source, int $post_id ): void {
		$changes = isset( $result['changes'] ) && is_array( $result['changes'] ) ? $result['changes'] : array();
		$by_fix  = array();
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && isset( $change['fix'] ) ) {
				$by_fix[ (string) $change['fix'] ][] = $change;
			}
		}
		$order = array_values( array_unique( array_merge( array_intersect( $this->registry->ids(), array_keys( $by_fix ) ), array_keys( $by_fix ) ) ) );
		$total = isset( $result['totals']['changes'] ) ? (int) $result['totals']['changes'] : count( $changes );
		$saved = $this->settings->fix_states();
		$skips = $post_id > 0 ? self::skipped_fixes( $post_id ) : array();
		?>
		<h2><?php echo esc_html__( 'Changes by fix', 'tagmend-accessibility-fixes' ); ?></h2>
		<p><?php echo esc_html__( 'Live: applied for every visitor. Preview: applied only in "View repaired". Off (would change): what the fix would do if you turned it on.', 'tagmend-accessibility-fixes' ); ?></p>
		<?php if ( 'admin_view' !== $source ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: name of the product image fix, 2: name of the menu label fix */
						__( 'Two fixes never appear in scan reports, because they work only through WordPress hooks while the page is built: "%1$s" and "%2$s". Open the page with "View repaired" to see their changes.', 'tagmend-accessibility-fixes' ),
						$this->fix_label( self::HOOK_ONLY_FIXES[0] ),
						$this->fix_label( self::HOOK_ONLY_FIXES[1] )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $total > count( $changes ) ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of changes listed, 2: number of changes found */
						__( 'Showing the first %1$s of %2$s changes.', 'tagmend-accessibility-fixes' ),
						number_format_i18n( count( $changes ) ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		if ( empty( $order ) ) {
			echo '<p>' . esc_html__( 'No fix changes anything on this page.', 'tagmend-accessibility-fixes' ) . '</p>';
			return;
		}
		foreach ( $order as $fix_id ) {
			$this->render_fix( $hash, $fix_id, $by_fix[ $fix_id ], isset( $saved[ $fix_id ] ) ? $saved[ $fix_id ] : '', $post_id, in_array( $fix_id, $skips, true ) );
		}
	}

	/**
	 * One fix: its changes as a table and its actions.
	 *
	 * @param string $hash    URL hash.
	 * @param string $fix_id  Fix id.
	 * @param array  $changes Changes of this fix.
	 * @param string $saved   Saved state of the fix ('' when not registered).
	 * @param int    $post_id Post the report belongs to, 0 when none.
	 * @param bool   $skipped Whether the fix is skipped on this post.
	 * @return void
	 */
	private function render_fix( string $hash, string $fix_id, array $changes, string $saved, int $post_id, bool $skipped ): void {
		$fix   = $this->registry->get( $fix_id );
		$label = $this->fix_label( $fix_id );
		$wcag  = '';
		if ( null !== $fix ) {
			try {
				$wcag = implode( ', ', array_map( 'strval', (array) $fix->wcag() ) );
			} catch ( Throwable $throwable ) {
				$wcag = '';
			}
		}
		$heading_id = 'softglaze-a11y-fix-' . $fix_id;
		?>
		<section class="softglaze-a11y-fix" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<h3 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $label ); ?></h3>
			<p>
				<?php
				if ( '' === $saved ) {
					echo esc_html__( 'This fix is not registered on the site now.', 'tagmend-accessibility-fixes' );
				} else {
					echo esc_html(
						sprintf(
							/* translators: 1: fix state now, for example Preview; 2: WCAG success criterion numbers */
							__( 'State now: %1$s. WCAG %2$s.', 'tagmend-accessibility-fixes' ),
							'live' === $saved ? __( 'Live', 'tagmend-accessibility-fixes' ) : ( 'preview' === $saved ? __( 'Preview', 'tagmend-accessibility-fixes' ) : __( 'Off', 'tagmend-accessibility-fixes' ) ),
							$wcag
						)
					);
				}
				if ( $skipped ) {
					echo ' ' . esc_html__( 'Skipped on this page.', 'tagmend-accessibility-fixes' );
				}
				?>
			</p>
			<table class="widefat striped softglaze-a11y-changes">
				<caption class="screen-reader-text">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: fix name */
							__( 'Changes by "%s"', 'tagmend-accessibility-fixes' ),
							$label
						)
					);
					?>
				</caption>
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Element', 'tagmend-accessibility-fixes' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Attribute', 'tagmend-accessibility-fixes' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Before', 'tagmend-accessibility-fixes' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'After', 'tagmend-accessibility-fixes' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'State in this report', 'tagmend-accessibility-fixes' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $changes as $change ) : ?>
					<tr>
						<td><code><?php echo esc_html( isset( $change['sig'] ) ? (string) $change['sig'] : '' ); ?></code></td>
						<td><code><?php echo esc_html( isset( $change['attr'] ) ? (string) $change['attr'] : '' ); ?></code></td>
						<td><?php echo isset( $change['before'] ) && null !== $change['before'] ? esc_html( '' === (string) $change['before'] ? __( '(empty)', 'tagmend-accessibility-fixes' ) : (string) $change['before'] ) : esc_html__( '(none)', 'tagmend-accessibility-fixes' ); ?></td>
						<td><?php echo esc_html( isset( $change['after'] ) && '' !== (string) $change['after'] ? (string) $change['after'] : __( '(empty)', 'tagmend-accessibility-fixes' ) ); ?></td>
						<td><?php echo esc_html( Report_View::state_label( isset( $change['state'] ) ? (string) $change['state'] : 'off' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( null !== $fix ) : ?>
				<div class="softglaze-a11y-fix-actions">
					<?php if ( 'live' !== $saved && $this->registry->is_available( $fix ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::GO_LIVE_ACTION ); ?>">
							<input type="hidden" name="<?php echo esc_attr( Screen_Reports::REPORT_ARG ); ?>" value="<?php echo esc_attr( $hash ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::FIX_FIELD ); ?>" value="<?php echo esc_attr( $fix_id ); ?>">
							<?php wp_nonce_field( self::GO_LIVE_ACTION, Screen_Reports::NONCE_ARG ); ?>
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Move to Live', 'tagmend-accessibility-fixes' ); ?><span class="screen-reader-text">: <?php echo esc_html( $label ); ?></span></button>
						</form>
					<?php elseif ( 'live' !== $saved ) : ?>
						<p class="description"><?php echo esc_html( $this->registry->unavailable_reason( $fix ) ); ?></p>
					<?php endif; ?>
					<?php if ( $post_id > 0 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::SKIP_ACTION ); ?>">
							<input type="hidden" name="<?php echo esc_attr( Screen_Reports::REPORT_ARG ); ?>" value="<?php echo esc_attr( $hash ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::FIX_FIELD ); ?>" value="<?php echo esc_attr( $fix_id ); ?>">
							<input type="hidden" name="<?php echo esc_attr( self::SKIP_FIELD ); ?>" value="<?php echo $skipped ? '0' : '1'; ?>">
							<?php wp_nonce_field( self::SKIP_ACTION, Screen_Reports::NONCE_ARG ); ?>
							<button type="submit" class="button"><?php echo $skipped ? esc_html__( 'Stop skipping on this page', 'tagmend-accessibility-fixes' ) : esc_html__( 'Skip on this page', 'tagmend-accessibility-fixes' ); ?><span class="screen-reader-text">: <?php echo esc_html( $label ); ?></span></button>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Needs a person: findings grouped by check (R02-R13).
	 *
	 * @param array $result Stored result.
	 * @return void
	 */
	private function render_findings( array $result ): void {
		$findings = isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array();
		$by_check = array();
		foreach ( $findings as $finding ) {
			if ( is_array( $finding ) && isset( $finding['check'] ) ) {
				$by_check[ (string) $finding['check'] ][] = $finding;
			}
		}
		ksort( $by_check, SORT_NATURAL );
		$total = isset( $result['totals']['findings'] ) ? (int) $result['totals']['findings'] : count( $findings );
		?>
		<h2><?php echo esc_html__( 'Needs a person', 'tagmend-accessibility-fixes' ); ?></h2>
		<p><?php echo esc_html__( 'The plugin does not change these. Each one needs a decision by someone who knows the page.', 'tagmend-accessibility-fixes' ); ?></p>
		<?php if ( $total > count( $findings ) ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of items listed, 2: number of items found */
						__( 'Showing the first %1$s of %2$s items.', 'tagmend-accessibility-fixes' ),
						number_format_i18n( count( $findings ) ),
						number_format_i18n( $total )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		if ( empty( $by_check ) ) {
			echo '<p>' . esc_html__( 'Nothing was found for a person to review on this page.', 'tagmend-accessibility-fixes' ) . '</p>';
			return;
		}
		foreach ( $by_check as $check => $items ) {
			$criteria = array();
			foreach ( $items as $item ) {
				if ( isset( $item['sc'] ) && '' !== (string) $item['sc'] ) {
					$criteria[] = (string) $item['sc'];
				}
			}
			$sc         = implode( ', ', array_unique( $criteria ) );
			$heading_id = 'softglaze-a11y-check-' . sanitize_key( $check );
			?>
			<section class="softglaze-a11y-check" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
				<h3 id="<?php echo esc_attr( $heading_id ); ?>">
					<?php
					echo esc_html(
						'' === $sc
							? Report_View::check_label( $check )
							: sprintf(
								/* translators: 1: check name, 2: WCAG success criterion number */
								__( '%1$s (WCAG %2$s)', 'tagmend-accessibility-fixes' ),
								Report_View::check_label( $check ),
								$sc
							)
					);
					?>
				</h3>
				<table class="widefat striped softglaze-a11y-findings">
					<caption class="screen-reader-text"><?php echo esc_html( Report_View::check_label( $check ) ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Element', 'tagmend-accessibility-fixes' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'What to check', 'tagmend-accessibility-fixes' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $attachment = isset( $item['attachment'] ) ? absint( $item['attachment'] ) : 0; ?>
						<tr>
							<td><code><?php echo esc_html( isset( $item['sig'] ) ? (string) $item['sig'] : '' ); ?></code></td>
							<td>
								<?php echo esc_html( isset( $item['detail'] ) ? (string) $item['detail'] : '' ); ?>
								<?php if ( $attachment > 0 && current_user_can( 'edit_post', $attachment ) ) : ?>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $attachment, 'raw' ) ); ?>"><?php echo esc_html__( 'Edit in the Media Library', 'tagmend-accessibility-fixes' ); ?><span class="screen-reader-text">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: attachment id */
												__( '(attachment %d)', 'tagmend-accessibility-fixes' ),
												$attachment
											)
										);
										?>
									</span></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<?php
		}
	}

	/**
	 * Not checked by this plugin (R14).
	 *
	 * @return void
	 */
	private function render_not_checked(): void {
		$items = array(
			__( 'Text contrast: it needs a check in the browser.', 'tagmend-accessibility-fixes' ),
			__( 'Keyboard traps and the focus order of widgets.', 'tagmend-accessibility-fixes' ),
			__( 'Captions and audio description.', 'tagmend-accessibility-fixes' ),
			__( 'Reflow and zoom behaviour.', 'tagmend-accessibility-fixes' ),
			__( 'Motion and timing.', 'tagmend-accessibility-fixes' ),
			__( 'How forms identify and describe errors.', 'tagmend-accessibility-fixes' ),
			__( 'Content added by JavaScript, for example the WooCommerce Cart and Checkout blocks and the mini-cart.', 'tagmend-accessibility-fixes' ),
			__( 'The language of parts of a page.', 'tagmend-accessibility-fixes' ),
			__( 'How easy the content is to read and understand.', 'tagmend-accessibility-fixes' ),
		);
		?>
		<h2><?php echo esc_html__( 'Not checked by this plugin', 'tagmend-accessibility-fixes' ); ?></h2>
		<ul class="softglaze-a11y-not-checked">
			<?php foreach ( $items as $item ) : ?>
				<li><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
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
	 * Stops a refused request with a 403 and a translated message.
	 *
	 * @param string $message Translated message.
	 * @return void
	 */
	private static function refuse( string $message ): void {
		wp_die(
			esc_html( $message ),
			'',
			array(
				'response'  => 403,
				'back_link' => true,
			)
		);
	}

	/**
	 * The report URL with a status, or the list when the hash is not valid.
	 *
	 * @param string $hash   URL hash.
	 * @param string $status Status.
	 * @return string
	 */
	private function back( string $hash, string $status ): string {
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			return Screen_Reports::url( array( Screen_Reports::STATUS_ARG => $status ) );
		}
		return Screen_Reports::url(
			array(
				Screen_Reports::REPORT_ARG => $hash,
				Screen_Reports::STATUS_ARG => $status,
			)
		);
	}
}
