<?php
/**
 * The Reports screen: scan controls and the list of stored reports.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Rest_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the stored reports (blueprint section 12) and offers three batch
 * scans: the home page, one address, and the suggested pages. Scanning is
 * done by admin.js in the administrator's own browser (blueprint section
 * 10.3); the controls receive their data through data-* attributes only.
 * With a report hash in the URL the screen shows that report's details
 * instead (Screen_Report_Details), so the menu needs no hidden page.
 */
final class Screen_Reports {

	const PAGE          = 'softglaze-a11y-reports';
	const REPORT_ARG    = 'softglaze_a11y_report';
	const STATUS_ARG    = 'softglaze_a11y_done';
	const NONCE_ARG     = 'softglaze_a11y_nonce';
	const DELETE_ACTION = 'softglaze_a11y_delete_report';

	/**
	 * Status values shown after a redirect back to this screen.
	 */
	const STATUSES = array( 'deleted', 'not_found', 'live', 'live_refused', 'skipped', 'unskipped', 'skip_refused' );

	/**
	 * Scan links and the report table.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Report details view.
	 *
	 * @var Screen_Report_Details
	 */
	private $details;

	/**
	 * Creates the screen.
	 *
	 * @param Scanner               $scanner Scan links and the report table.
	 * @param Screen_Report_Details $details Report details view.
	 */
	public function __construct( Scanner $scanner, Screen_Report_Details $details ) {
		$this->scanner = $scanner;
		$this->details = $details;
	}

	/**
	 * The report details view.
	 *
	 * @return Screen_Report_Details
	 */
	public function details(): Screen_Report_Details {
		return $this->details;
	}

	/**
	 * Hooks the delete handler and the details handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		$this->details->register();
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
			__( 'Reports', 'tagmend-accessibility-fixes' ),
			__( 'Reports', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		if ( ! is_string( $hook ) ) {
			return '';
		}
		add_action( 'load-' . $hook, array( $this, 'on_load' ) );
		return $hook;
	}

	/**
	 * Before the screen is printed: the browser title says "Report details"
	 * when a report is shown.
	 *
	 * @return void
	 */
	public function on_load(): void {
		if ( '' !== self::requested_report() ) {
			add_filter( 'admin_title', array( $this, 'filter_admin_title' ), 10, 2 );
		}
	}

	/**
	 * Replaces the screen title at the start of the browser title.
	 *
	 * @param string $admin_title Browser title.
	 * @param string $title       Screen title (already escaped by core).
	 * @return string
	 */
	public function filter_admin_title( $admin_title, $title ) {
		if ( ! is_string( $admin_title ) || ! is_string( $title ) || '' === $title || 0 !== strpos( $admin_title, $title ) ) {
			return $admin_title;
		}
		return esc_html__( 'Report details', 'tagmend-accessibility-fixes' ) . substr( $admin_title, strlen( $title ) );
	}

	/**
	 * URL of this screen with extra args.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of the details of one report.
	 *
	 * @param string $hash URL hash.
	 * @return string
	 */
	public static function details_url( string $hash ): string {
		return self::url( array( self::REPORT_ARG => $hash ) );
	}

	/**
	 * Delete link of one report, with a nonce.
	 *
	 * @param string $hash URL hash.
	 * @return string
	 */
	public static function delete_url( string $hash ): string {
		return add_query_arg(
			array(
				'action'         => self::DELETE_ACTION,
				self::REPORT_ARG => $hash,
				self::NONCE_ARG  => wp_create_nonce( self::DELETE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The report hash in the URL of this screen, or ''. Choosing which report
	 * to show changes nothing, so the read needs no nonce.
	 *
	 * @return string
	 */
	public static function requested_report(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: selects which stored report is shown; validated below.
		$hash = isset( $_GET[ self::REPORT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::REPORT_ARG ] ) ) : '';
		return 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) ? $hash : '';
	}

	/**
	 * The result of the last action, from the redirect back to this screen.
	 * Display-only and allow-listed, so no nonce is needed to read it.
	 *
	 * @return string One of STATUSES, or ''.
	 */
	public static function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status after a redirect; allow-listed below.
		$status = isset( $_GET[ self::STATUS_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_ARG ] ) ) : '';
		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	/**
	 * Handles the delete link: deletes, then returns to the screen.
	 *
	 * @return void
	 */
	public function handle_delete() {
		wp_safe_redirect( $this->process_delete() );
		exit;
	}

	/**
	 * Checks the capability, then the nonce, then deletes one report row.
	 * Returns the screen URL with the result.
	 *
	 * @return string
	 */
	public function process_delete(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to delete reports.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$nonce = isset( $_GET[ self::NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE_ARG ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::DELETE_ACTION ) ) {
			wp_die(
				esc_html__( 'This link has expired. Open the Reports screen again and try once more.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
		$hash    = isset( $_GET[ self::REPORT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::REPORT_ARG ] ) ) : '';
		$deleted = $this->scanner->pages()->delete_by_hash( $hash );
		return self::url( array( self::STATUS_ARG => $deleted ? 'deleted' : 'not_found' ) );
	}

	/**
	 * Pages offered by "Scan suggested pages" (blueprint section 10.3): the
	 * home page, the 5 latest posts and pages, and with WooCommerce the shop,
	 * one product, the cart and the account page. Only addresses the scan
	 * targets route accepts, each once.
	 *
	 * @return string[]
	 */
	public function suggested_urls(): array {
		$candidates = array( home_url( '/' ) );
		$latest     = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => 5,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( $latest as $post_id ) {
			$candidates[] = get_permalink( $post_id );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$candidates[] = wc_get_page_permalink( 'shop', '' );
			$product      = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'has_password'     => false,
					'numberposts'      => 1,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'suppress_filters' => false,
				)
			);
			if ( ! empty( $product ) ) {
				$candidates[] = get_permalink( $product[0] );
			}
			$candidates[] = wc_get_page_permalink( 'cart', '' );
			$candidates[] = wc_get_page_permalink( 'myaccount', '' );
		}

		$urls = array();
		foreach ( $candidates as $candidate ) {
			$clean = $this->scanner->validate_target( is_string( $candidate ) ? $candidate : '' );
			if ( '' !== $clean && ! in_array( $clean, $urls, true ) ) {
				$urls[] = $clean;
			}
		}
		return array_slice( $urls, 0, Rest_Controller::MAX_TARGETS );
	}

	/**
	 * Prints the screen, or the details of one report.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hash   = self::requested_report();
		$status = self::status();
		if ( '' !== $hash ) {
			$this->details->render( $hash, $status );
			return;
		}

		$table = new Pages_List_Table( $this->scanner );
		$table->prepare_items();
		$suggested = $this->suggested_urls();
		$home      = $this->scanner->validate_target( home_url( '/' ) );
		?>
		<div class="wrap softglaze-a11y-screen">
			<h1><?php echo esc_html__( 'Reports', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php self::print_status( $status ); ?>
			<p><?php echo esc_html__( 'Each report lists what the fixes change on one page and what still needs a person. A scan runs in your own browser, changes nothing on the page, and sees the page as a logged-in administrator.', 'tagmend-accessibility-fixes' ); ?></p>

			<section class="softglaze-a11y-scan" aria-labelledby="softglaze-a11y-scan-title" data-softglaze-a11y-scanner="1" data-home-url="<?php echo esc_attr( $home ); ?>" data-suggested="<?php echo esc_attr( (string) wp_json_encode( $suggested ) ); ?>" data-reload-url="<?php echo esc_url( self::url() ); ?>">
				<h2 id="softglaze-a11y-scan-title"><?php echo esc_html__( 'Scan pages', 'tagmend-accessibility-fixes' ); ?></h2>
				<p class="softglaze-a11y-nojs"><?php echo esc_html__( 'Scanning from this screen needs JavaScript. You can also open any page of your site and use "Scan this page" in the admin bar.', 'tagmend-accessibility-fixes' ); ?></p>
				<div class="softglaze-a11y-scan-controls" hidden>
					<p class="softglaze-a11y-scan-buttons">
						<button type="button" class="button button-primary" data-softglaze-a11y-scan="suggested" aria-describedby="softglaze-a11y-suggested-note"><?php echo esc_html__( 'Scan suggested pages', 'tagmend-accessibility-fixes' ); ?></button>
						<button type="button" class="button" data-softglaze-a11y-scan="home"><?php echo esc_html__( 'Scan home page', 'tagmend-accessibility-fixes' ); ?></button>
					</p>
					<p class="description" id="softglaze-a11y-suggested-note">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of pages */
								_n(
									'%s page: the home page, the five latest posts and pages, and with WooCommerce the shop, one product, the cart and the account page.',
									'%s pages: the home page, the five latest posts and pages, and with WooCommerce the shop, one product, the cart and the account page.',
									count( $suggested ),
									'tagmend-accessibility-fixes'
								),
								number_format_i18n( count( $suggested ) )
							)
						);
						?>
					</p>
					<form class="softglaze-a11y-scan-url" data-softglaze-a11y-scan-form="1">
						<label for="softglaze-a11y-scan-address"><?php echo esc_html__( 'Address of a page on this site', 'tagmend-accessibility-fixes' ); ?></label>
						<span class="softglaze-a11y-scan-url-row">
							<input type="url" id="softglaze-a11y-scan-address" class="regular-text" required autocomplete="url" spellcheck="false">
							<button type="submit" class="button"><?php echo esc_html__( 'Scan this address', 'tagmend-accessibility-fixes' ); ?></button>
						</span>
					</form>
				</div>
				<div class="softglaze-a11y-scan-status" role="status" aria-live="polite" aria-atomic="true"></div>
				<ul class="softglaze-a11y-scan-results" hidden></ul>
			</section>

			<h2><?php echo esc_html__( 'Scanned pages', 'tagmend-accessibility-fixes' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the inline message for a status from the redirect (no admin notice hook).
	 *
	 * @param string $status One of STATUSES, or ''.
	 * @return void
	 */
	public static function print_status( string $status ): void {
		$messages = array(
			'deleted'      => array( 'success', __( 'Report deleted.', 'tagmend-accessibility-fixes' ) ),
			'not_found'    => array( 'warning', __( 'That report no longer exists.', 'tagmend-accessibility-fixes' ) ),
			'live'         => array( 'success', __( 'The fix is Live now: visitors get its changes.', 'tagmend-accessibility-fixes' ) ),
			'live_refused' => array( 'warning', __( 'The fix could not be moved to Live on this site.', 'tagmend-accessibility-fixes' ) ),
			'skipped'      => array( 'success', __( 'The fix is skipped on this page now.', 'tagmend-accessibility-fixes' ) ),
			'unskipped'    => array( 'success', __( 'The fix runs on this page again.', 'tagmend-accessibility-fixes' ) ),
			'skip_refused' => array( 'warning', __( 'Fixes can be skipped only on single posts and pages.', 'tagmend-accessibility-fixes' ) ),
		);
		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s inline softglaze-a11y-status" role="status" tabindex="-1"><p>%2$s</p></div>',
			esc_attr( $messages[ $status ][0] ),
			esc_html( $messages[ $status ][1] )
		);
	}
}
