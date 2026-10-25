<?php
/**
 * The Overview screen.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The top-level screen (blueprint sections 10.1 and 12): the three
 * first-run steps until a fix is Live, how many fixes are Live, in Preview
 * and Off, whether the whole-page repair can run, the newest reports, pages
 * served unrepaired in the last 7 days, the page-cache reminder and the
 * approved disclaimer sentence. Read-only: nothing on it changes a setting.
 */
final class Screen_Overview {

	/**
	 * Newest reports listed.
	 */
	const RECENT = 5;

	/**
	 * Days of engine fallbacks counted.
	 */
	const FALLBACK_DAYS = 7;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Report table and activity log.
	 *
	 * @var Scanner
	 */
	private $scanner;

	/**
	 * Creates the screen.
	 *
	 * @param Settings $settings Settings.
	 * @param Scanner  $scanner  Report table and activity log.
	 */
	public function __construct( Settings $settings, Scanner $scanner ) {
		$this->settings = $settings;
		$this->scanner  = $scanner;
	}

	/**
	 * The approved disclaimer sentence (AGENTS.md section 5), word for word.
	 *
	 * @return string
	 */
	public static function disclaimer(): string {
		/* translators: A fixed disclaimer sentence. The percent sign belongs to the text; it is not a placeholder. */
		return __( 'No plugin can provide 100% compliance with accessibility laws or standards such as the ADA, the European Accessibility Act or WCAG.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * Text about the whole-page repair, from Pages_Store::page_pass_status().
	 *
	 * @param array $status Page-pass status.
	 * @return string
	 */
	public static function page_pass_text( array $status ): string {
		$date = isset( $status['scanned_gmt'] ) && '' !== $status['scanned_gmt'] ? Report_View::date( (string) $status['scanned_gmt'] ) : '';
		$url  = isset( $status['url'] ) ? (string) $status['url'] : '';
		switch ( isset( $status['status'] ) ? $status['status'] : '' ) {
			case Pages_Store::PAGE_PASS_NEEDS_WP69:
				return __( 'Needs WordPress 6.9 or later. Until then, repairs run on post content, template parts, widgets and menus only.', 'tagmend-accessibility-fixes' );
			case Pages_Store::PAGE_PASS_SWITCHED_OFF:
				return __( 'Switched off in Settings. Repairs run on post content, template parts, widgets and menus only.', 'tagmend-accessibility-fixes' );
			case Pages_Store::PAGE_PASS_VETOED:
				return sprintf(
					/* translators: 1: page address, 2: date and time of its report */
					__( 'Switched off by another plugin on the last scanned pages. The report of %1$s (%2$s) covers post content, template parts, widgets and menus only, because another plugin switched off the WordPress page buffer (some page builders do). Page-wide repairs such as the skip link, the viewport and repeated ids do not run while it is switched off.', 'tagmend-accessibility-fixes' ),
					$url,
					$date
				);
			default:
				if ( ! empty( $status['confirmed'] ) ) {
					return sprintf(
						/* translators: 1: page address, 2: date and time of its report */
						__( 'Available. The newest report, of %1$s (%2$s), covers the whole page.', 'tagmend-accessibility-fixes' ),
						$url,
						$date
					);
				}
				return __( 'Available on this WordPress version. Scan a page to confirm that no other plugin switches it off.', 'tagmend-accessibility-fixes' );
		}
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
		$states = $this->settings->fix_states();
		$counts = array_merge(
			array(
				'live'    => 0,
				'preview' => 0,
				'off'     => 0,
			),
			array_count_values( $states )
		);
		$pages  = $this->scanner->pages();
		$home   = $this->scanner->validate_target( home_url( '/' ) );
		$report = '' === $home ? null : $this->scanner->report_for( $home );
		?>
		<div class="wrap softglaze-a11y-screen softglaze-a11y-overview">
			<h1><?php echo esc_html__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ); ?></h1>
			<p class="softglaze-a11y-lead"><?php echo esc_html__( 'Tagmend repairs common, machine-fixable accessibility errors in the HTML that your theme, page builder and WooCommerce print, on your own server, and lists what still needs a person.', 'tagmend-accessibility-fixes' ); ?></p>

			<?php
			if ( 0 === (int) $counts['live'] ) {
				$this->render_steps( $report );
			}
			$this->render_status( $counts, $pages );
			$this->render_recent( $pages );
			?>

			<section class="softglaze-a11y-panel" aria-labelledby="softglaze-a11y-cache-title">
				<h2 id="softglaze-a11y-cache-title"><?php echo esc_html__( 'Page caches', 'tagmend-accessibility-fixes' ); ?></h2>
				<p><?php echo esc_html( Cache_Reminder::overview() ); ?></p>
			</section>

			<section class="softglaze-a11y-panel" aria-labelledby="softglaze-a11y-limits-title">
				<h2 id="softglaze-a11y-limits-title"><?php echo esc_html__( 'What Tagmend cannot do', 'tagmend-accessibility-fixes' ); ?></h2>
				<p><?php echo esc_html__( 'Tagmend changes attributes in the HTML; it never adds, removes or moves your content. Alt text that describes an image well, clear link and button wording, colour choices and keyboard use of custom widgets still need a person: the reports list them.', 'tagmend-accessibility-fixes' ); ?></p>
				<p class="softglaze-a11y-disclaimer"><?php echo esc_html( self::disclaimer() ); ?></p>
			</section>
		</div>
		<?php
	}

	/**
	 * The three first-run steps (blueprint section 10.1), shown until a fix is Live.
	 *
	 * @param array|null $report Stored report of the home page, or null.
	 * @return void
	 */
	private function render_steps( $report ) {
		$review_url = null === $report ? Screen_Reports::url() : Screen_Reports::details_url( (string) $report['url_hash'] );
		?>
		<section class="softglaze-a11y-panel softglaze-a11y-steps" aria-labelledby="softglaze-a11y-steps-title">
			<h2 id="softglaze-a11y-steps-title"><?php echo esc_html__( 'Get started', 'tagmend-accessibility-fixes' ); ?></h2>
			<p><?php echo esc_html__( 'Nothing changes for your visitors until a fix is Live.', 'tagmend-accessibility-fixes' ); ?></p>
			<ol>
				<li>
					<strong><?php echo esc_html__( 'Scan your home page.', 'tagmend-accessibility-fixes' ); ?></strong>
					<?php echo esc_html__( 'A scan runs in your own browser and changes nothing on the page.', 'tagmend-accessibility-fixes' ); ?>
					<?php if ( null !== $report ) : ?>
						<span class="softglaze-a11y-step-done">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: date and time of the report */
									__( 'Done: scanned %s.', 'tagmend-accessibility-fixes' ),
									Report_View::date( (string) $report['scanned_gmt'] )
								)
							);
							?>
						</span>
					<?php endif; ?>
					<br><a href="<?php echo esc_url( Screen_Reports::url() ); ?>"><?php echo esc_html__( 'Open Reports to scan the home page', 'tagmend-accessibility-fixes' ); ?></a>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Review the changes.', 'tagmend-accessibility-fixes' ); ?></strong>
					<?php echo esc_html__( 'The report lists what each fix would change and what still needs a person. "View repaired" in the admin bar shows the page with the Live and Preview fixes applied.', 'tagmend-accessibility-fixes' ); ?>
					<br><a href="<?php echo esc_url( $review_url ); ?>"><?php echo esc_html( null === $report ? __( 'Open Reports', 'tagmend-accessibility-fixes' ) : __( 'Open the report of the home page', 'tagmend-accessibility-fixes' ) ); ?></a>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Go live.', 'tagmend-accessibility-fixes' ); ?></strong>
					<?php echo esc_html__( 'Set the fixes you checked to Live, one by one or all Preview fixes at once.', 'tagmend-accessibility-fixes' ); ?>
					<br><a href="<?php echo esc_url( Screen_Fixes::url() ); ?>"><?php echo esc_html__( 'Open Fixes', 'tagmend-accessibility-fixes' ); ?></a>
				</li>
			</ol>
		</section>
		<?php
	}

	/**
	 * Fix counts, the whole-page repair and the pages served unrepaired.
	 *
	 * @param int[]       $counts State => number of fixes.
	 * @param Pages_Store $pages  Report table.
	 * @return void
	 */
	private function render_status( array $counts, Pages_Store $pages ) {
		$since     = gmdate( 'Y-m-d H:i:s', time() - self::FALLBACK_DAYS * DAY_IN_SECONDS );
		$fallbacks = $this->scanner->log()->details_since( 'engine_fallback', $since );
		$total     = array_sum( $fallbacks );
		$page_pass = $pages->page_pass_status( $this->settings->page_scope_enabled() );
		$states    = array(
			'live'    => __( 'Live', 'tagmend-accessibility-fixes' ),
			'preview' => __( 'Preview', 'tagmend-accessibility-fixes' ),
			'off'     => __( 'Off', 'tagmend-accessibility-fixes' ),
		);
		?>
		<section class="softglaze-a11y-panel" aria-labelledby="softglaze-a11y-status-title">
			<h2 id="softglaze-a11y-status-title"><?php echo esc_html__( 'Status', 'tagmend-accessibility-fixes' ); ?></h2>
			<dl class="softglaze-a11y-facts">
				<?php foreach ( $states as $state => $label ) : ?>
					<dt><?php echo esc_html( $label ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of fixes */
								_n( '%s fix', '%s fixes', (int) $counts[ $state ], 'tagmend-accessibility-fixes' ),
								number_format_i18n( (int) $counts[ $state ] )
							)
						);
						?>
					</dd>
				<?php endforeach; ?>
				<dt><?php echo esc_html__( 'Whole-page repair', 'tagmend-accessibility-fixes' ); ?></dt>
				<dd><?php echo esc_html( self::page_pass_text( $page_pass ) ); ?></dd>
				<dt><?php echo esc_html__( 'Pages served unrepaired', 'tagmend-accessibility-fixes' ); ?></dt>
				<dd>
					<?php if ( 0 === $total ) : ?>
						<?php echo esc_html__( 'None recorded in the last 7 days.', 'tagmend-accessibility-fixes' ); ?>
					<?php else : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of log entries */
								_n( '%s entry in the last 7 days (each reason is recorded at most once an hour):', '%s entries in the last 7 days (each reason is recorded at most once an hour):', $total, 'tagmend-accessibility-fixes' ),
								number_format_i18n( $total )
							)
						);
						?>
						<ul class="softglaze-a11y-reasons">
							<?php foreach ( $fallbacks as $reason => $rows ) : ?>
								<li>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of entries, 2: why the page was served unrepaired */
											_n( '%1$s entry: %2$s', '%1$s entries: %2$s', $rows, 'tagmend-accessibility-fixes' ),
											number_format_i18n( $rows ),
											Report_View::fallback_label( (string) $reason )
										)
									);
									?>
								</li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( self::log_url() ); ?>"><?php echo esc_html__( 'Show them in the Activity log', 'tagmend-accessibility-fixes' ); ?></a>
					<?php endif; ?>
				</dd>
			</dl>
			<p class="softglaze-a11y-links">
				<a class="button" href="<?php echo esc_url( Screen_Fixes::url() ); ?>"><?php echo esc_html__( 'Fixes', 'tagmend-accessibility-fixes' ); ?></a>
				<a class="button" href="<?php echo esc_url( Screen_Settings::url() ); ?>"><?php echo esc_html__( 'Settings', 'tagmend-accessibility-fixes' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * The Activity log filtered to pages served unrepaired.
	 *
	 * @return string
	 */
	private static function log_url(): string {
		return add_query_arg(
			array(
				'page'                => Screen_Log::PAGE,
				Screen_Log::EVENT_ARG => 'engine_fallback',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The newest reports.
	 *
	 * @param Pages_Store $pages Report table.
	 * @return void
	 */
	private function render_recent( Pages_Store $pages ) {
		$rows = $pages->recent( self::RECENT );
		?>
		<section class="softglaze-a11y-panel" aria-labelledby="softglaze-a11y-recent-title">
			<h2 id="softglaze-a11y-recent-title"><?php echo esc_html__( 'Recent reports', 'tagmend-accessibility-fixes' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php echo esc_html__( 'No page has been scanned yet. Scan your home page from the Reports screen, or open any page of your site and use "Scan this page" in the admin bar.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php else : ?>
				<ul class="softglaze-a11y-recent">
					<?php foreach ( $rows as $row ) : ?>
						<li>
							<a class="softglaze-a11y-report-url" href="<?php echo esc_url( Screen_Reports::details_url( (string) $row['url_hash'] ) ); ?>"><?php echo esc_html( (string) $row['url'] ); ?></a>
							<br>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: report source, for example "Scan"; 2: Live changes; 3: Preview changes; 4: changes of fixes that are Off; 5: items that need a person */
									__( '%1$s: %2$s Live, %3$s Preview, %4$s Off, %5$s need a person', 'tagmend-accessibility-fixes' ),
									Report_View::source_label( (string) $row['source'] ),
									number_format_i18n( (int) $row['changes_live'] ),
									number_format_i18n( (int) $row['changes_preview'] ),
									number_format_i18n( (int) $row['changes_off'] ),
									number_format_i18n( (int) $row['findings'] )
								)
							);
							?>
							<span class="softglaze-a11y-date"><?php echo esc_html( Report_View::date( (string) $row['scanned_gmt'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( Screen_Reports::url() ); ?>"><?php echo esc_html__( 'All reports', 'tagmend-accessibility-fixes' ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}
}
