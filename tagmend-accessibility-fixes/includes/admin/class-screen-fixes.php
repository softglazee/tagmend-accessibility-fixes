<?php
/**
 * The Fixes screen: one Off / Preview / Live choice per fix.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Fix;
use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Settings;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every registered fix in groups (blueprint section 12), each as a
 * fieldset whose legend is the fix name, with three radio buttons, what the
 * fix changes, its WCAG success criteria with a link to the W3C
 * Understanding page, its risk and how many changes it lists on the scanned
 * pages. Saves go through admin-post.php (capability first, then the
 * nonce). "Move all Preview fixes to Live" shows a confirmation screen that
 * lists the fixes first. Results are shown inline on this screen; no admin
 * notice hook is used.
 */
final class Screen_Fixes {

	const PAGE        = 'softglaze-a11y-fixes';
	const ACTION      = 'softglaze_a11y_save_fixes';
	const NONCE_FIELD = 'softglaze_a11y_fixes_nonce';
	const FIELD       = 'softglaze_a11y_fix';
	const STATUS_ARG  = 'softglaze_a11y_saved';

	/**
	 * "Move all Preview fixes to Live": the admin-post action, the list of
	 * fix ids the confirmation showed, and the view arg of the confirmation.
	 */
	const BULK_ACTION  = 'softglaze_a11y_bulk_live';
	const BULK_FIELD   = 'softglaze_a11y_fix_ids';
	const STEP_ARG     = 'softglaze_a11y_step';
	const STEP_CONFIRM = 'confirm-live';

	/**
	 * Display-only values carried by the redirect back to this screen.
	 */
	const COUNT_ARG = 'softglaze_a11y_moved';
	const CACHE_ARG = 'softglaze_a11y_cache';
	const STATUSES  = array( 'saved', 'rejected', 'bulk' );

	/**
	 * Groups in screen order and the fixes in each (blueprint section 12).
	 * Fixes added by other plugins are listed under "Other fixes".
	 */
	const GROUPS = array(
		'images'    => array( 'img-alt-library', 'img-alt-empty-library', 'img-in-named-control', 'product-img-alt-name', 'decorative-icons' ),
		'links'     => array( 'link-name', 'button-name', 'read-more-context', 'new-tab-note' ),
		'forms'     => array( 'field-label', 'duplicate-field-ids' ),
		'structure' => array( 'html-lang', 'viewport-zoom', 'nav-labels', 'empty-heading' ),
		'keyboard'  => array( 'skip-link', 'skip-link-target', 'positive-tabindex', 'hidden-duplicate-links', 'aria-hidden-focus', 'focus-visible' ),
		'embeds'    => array( 'iframe-title' ),
	);

	/**
	 * Understanding WCAG 2.2 page of each success criterion the bundled fixes name.
	 */
	const UNDERSTANDING_URL = 'https://www.w3.org/WAI/WCAG22/Understanding/';
	const UNDERSTANDING     = array(
		'1.1.1' => 'non-text-content',
		'1.3.1' => 'info-and-relationships',
		'1.4.4' => 'resize-text',
		'2.4.1' => 'bypass-blocks',
		'2.4.3' => 'focus-order',
		'2.4.4' => 'link-purpose-in-context',
		'2.4.6' => 'headings-and-labels',
		'2.4.7' => 'focus-visible',
		'3.1.1' => 'language-of-page',
		'3.2.5' => 'change-on-request',
		'4.1.2' => 'name-role-value',
	);

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
	 * Report table (what each fix lists on the scanned pages).
	 *
	 * @var Pages_Store
	 */
	private $pages;

	/**
	 * Creates the screen.
	 *
	 * @param Registry         $registry Fix list.
	 * @param Settings         $settings Settings.
	 * @param Pages_Store|null $pages    Report table; a default one when null.
	 */
	public function __construct( Registry $registry, Settings $settings, ?Pages_Store $pages = null ) {
		$this->registry = $registry;
		$this->settings = $settings;
		$this->pages    = null !== $pages ? $pages : new Pages_Store();
	}

	/**
	 * Hooks the save handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::BULK_ACTION, array( $this, 'handle_bulk_live' ) );
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
			__( 'Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Fixes', 'tagmend-accessibility-fixes' ),
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
	 * Before the screen is printed: the browser title names the confirmation step when it is shown.
	 *
	 * @return void
	 */
	public function on_load(): void {
		if ( self::confirm_requested() ) {
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
		return esc_html__( 'Move all Preview fixes to Live', 'tagmend-accessibility-fixes' ) . substr( $admin_title, strlen( $title ) );
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
	 * Handles the admin-post.php request: saves, then returns to the screen.
	 *
	 * @return void
	 */
	public function handle_save() {
		wp_safe_redirect( $this->process_save() );
		exit;
	}

	/**
	 * Handles "Move all Preview fixes to Live" after its confirmation.
	 *
	 * @return void
	 */
	public function handle_bulk_live() {
		wp_safe_redirect( $this->process_bulk_live() );
		exit;
	}

	/**
	 * Checks the capability, then the nonce, then saves the submitted states.
	 * Each state is read by fix id from the request and sanitized at the read;
	 * the sanitize callback validates it again. Returns the screen URL; the
	 * page-cache reminder is shown when a fix moved to or from Live.
	 *
	 * @return string
	 */
	public function process_save(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::refuse( __( 'Sorry, you are not allowed to change fix states.', 'tagmend-accessibility-fixes' ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			self::refuse( __( 'This form has expired. Open the Fixes screen again and save once more.', 'tagmend-accessibility-fixes' ) );
		}

		$states = array();
		foreach ( $this->registry->ids() as $id ) {
			if ( isset( $_POST[ self::FIELD ][ $id ] ) ) {
				$states[ $id ] = sanitize_key( wp_unslash( $_POST[ self::FIELD ][ $id ] ) );
			}
		}

		$before   = $this->settings->fix_states();
		$rejected = empty( $states ) ? array() : $this->settings->save_fix_states( $states, get_current_user_id() );

		return self::url(
			array(
				self::STATUS_ARG => empty( $rejected ) ? 'saved' : 'rejected',
				self::CACHE_ARG  => self::live_changed( $before, $this->settings->fix_states() ) ? '1' : false,
			)
		);
	}

	/**
	 * Checks the capability, then the nonce, then moves to Live every fix
	 * that the confirmation listed and that is still in Preview and can run
	 * here. Ids are read from one named field and allow-listed against the
	 * registry. Returns the screen URL with the number moved.
	 *
	 * @return string
	 */
	public function process_bulk_live(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			self::refuse( __( 'Sorry, you are not allowed to change fix states.', 'tagmend-accessibility-fixes' ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::BULK_ACTION ) ) {
			self::refuse( __( 'This form has expired. Open the Fixes screen again and try once more.', 'tagmend-accessibility-fixes' ) );
		}

		$listed = isset( $_POST[ self::BULK_FIELD ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ self::BULK_FIELD ] ) ) : array();
		$before = $this->settings->fix_states();
		$wanted = array();
		foreach ( $this->registry->fixes() as $id => $fix ) {
			if ( in_array( $id, $listed, true ) && isset( $before[ $id ] ) && 'preview' === $before[ $id ] && $this->registry->is_available( $fix ) ) {
				$wanted[ $id ] = 'live';
			}
		}

		$rejected = empty( $wanted ) ? array() : $this->settings->save_fix_states( $wanted, get_current_user_id() );
		$after    = $this->settings->fix_states();
		$moved    = 0;
		foreach ( array_keys( $wanted ) as $id ) {
			if ( isset( $after[ $id ] ) && 'live' === $after[ $id ] ) {
				++$moved;
			}
		}

		return self::url(
			array(
				self::STATUS_ARG => empty( $rejected ) ? 'bulk' : 'rejected',
				self::COUNT_ARG  => (string) $moved,
				self::CACHE_ARG  => $moved > 0 ? '1' : false,
			)
		);
	}

	/**
	 * Prints the screen, or the confirmation of "Move all Preview fixes to Live".
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::confirm_requested() ) {
			$this->render_confirm();
			return;
		}

		$states  = $this->settings->fix_states();
		$totals  = $this->pages->fix_totals();
		$counts  = array_count_values( $states );
		$preview = $this->preview_fixes( $states );
		?>
		<div class="wrap softglaze-a11y-screen softglaze-a11y-fixes">
			<h1><?php echo esc_html__( 'Fixes', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php $this->print_status(); ?>
			<p><?php echo esc_html__( 'Off: never applied. Preview: applied only when you open a page with "View repaired" in the admin bar. Live: applied for every visitor.', 'tagmend-accessibility-fixes' ); ?></p>
			<p class="softglaze-a11y-counts">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of Live fixes, 2: number of Preview fixes, 3: number of fixes that are Off */
						__( 'Now: %1$s Live, %2$s Preview, %3$s Off.', 'tagmend-accessibility-fixes' ),
						number_format_i18n( isset( $counts['live'] ) ? $counts['live'] : 0 ),
						number_format_i18n( isset( $counts['preview'] ) ? $counts['preview'] : 0 ),
						number_format_i18n( isset( $counts['off'] ) ? $counts['off'] : 0 )
					)
				);
				?>
			</p>

			<section class="softglaze-a11y-panel softglaze-a11y-bulk" aria-labelledby="softglaze-a11y-bulk-title">
				<h2 id="softglaze-a11y-bulk-title"><?php echo esc_html__( 'Move all Preview fixes to Live', 'tagmend-accessibility-fixes' ); ?></h2>
				<?php if ( empty( $preview['ready'] ) ) : ?>
					<p><?php echo esc_html__( 'No fix that can run on this site is in Preview.', 'tagmend-accessibility-fixes' ); ?></p>
				<?php else : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: number of fixes */
								_n(
									'%s fix is in Preview. The next step lists it and asks you to confirm before visitors get its changes.',
									'%s fixes are in Preview. The next step lists them and asks you to confirm before visitors get their changes.',
									count( $preview['ready'] ),
									'tagmend-accessibility-fixes'
								),
								number_format_i18n( count( $preview['ready'] ) )
							)
						);
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( self::url( array( self::STEP_ARG => self::STEP_CONFIRM ) ) ); ?>"><?php echo esc_html__( 'Review the Preview fixes', 'tagmend-accessibility-fixes' ); ?></a></p>
				<?php endif; ?>
			</section>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION, self::NONCE_FIELD ); ?>
				<?php
				// A Save button above the 22 fixes as well as below them, so keyboard users need not tab through every card.
				submit_button( __( 'Save fix states', 'tagmend-accessibility-fixes' ), 'primary', 'submit', true, array( 'id' => 'softglaze-a11y-save-top' ) );
				foreach ( $this->grouped_fixes() as $group => $fixes ) {
					$heading_id = 'softglaze-a11y-group-' . $group;
					?>
					<section class="softglaze-a11y-fix-group" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
						<h2 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( self::group_label( $group ) ); ?></h2>
						<?php
						foreach ( $fixes as $id => $fix ) {
							$this->render_fix( $id, $fix, isset( $states[ $id ] ) ? $states[ $id ] : 'off', $totals );
						}
						?>
					</section>
					<?php
				}
				submit_button( __( 'Save fix states', 'tagmend-accessibility-fixes' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one fix as a fieldset: the legend names the fix, three radio
	 * buttons, what it changes, its success criteria, risk, first state and
	 * what it lists on the scanned pages. Live is disabled, with the reason in
	 * text, when the fix cannot run here.
	 *
	 * @param string $id     Fix id.
	 * @param Fix    $fix    Fix.
	 * @param string $state  Saved state.
	 * @param array  $totals Pages_Store::fix_totals().
	 * @return void
	 */
	private function render_fix( string $id, Fix $fix, string $state, array $totals ) {
		try {
			$label       = $fix->label();
			$criteria    = array_map( 'strval', (array) $fix->wcag() );
			$risk        = $this->risk_label( $fix->risk() );
			$recommended = $fix->recommended();
			$description = method_exists( $fix, 'description' ) ? (string) $fix->description() : '';
		} catch ( Throwable $throwable ) {
			return;
		}
		$reason   = $this->registry->unavailable_reason( $fix );
		$field_id = 'softglaze-a11y-fix-' . $id;
		$labels   = array(
			'off'     => __( 'Off', 'tagmend-accessibility-fixes' ),
			'preview' => __( 'Preview', 'tagmend-accessibility-fixes' ),
			'live'    => __( 'Live', 'tagmend-accessibility-fixes' ),
		);
		?>
		<fieldset class="softglaze-a11y-fix-card" id="<?php echo esc_attr( $field_id ); ?>"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $field_id . '-what' ) . '"' : ''; ?>>
			<legend><h3 class="softglaze-a11y-fix-title"><?php echo esc_html( $label ); ?></h3></legend>
			<div class="softglaze-a11y-fix-states">
				<?php foreach ( $labels as $value => $text ) : ?>
					<?php $disabled = 'live' === $value && '' !== $reason && 'live' !== $state; ?>
					<label>
						<input type="radio" name="<?php echo esc_attr( self::FIELD . '[' . $id . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php checked( $state, $value ); ?><?php disabled( $disabled ); ?><?php echo $disabled ? ' aria-describedby="' . esc_attr( $field_id . '-reason' ) . '"' : ''; ?>>
						<?php echo esc_html( $text ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<?php if ( '' !== $reason ) : ?>
				<p class="description" id="<?php echo esc_attr( $field_id . '-reason' ); ?>"><?php echo esc_html( $reason ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $description ) : ?>
				<p class="softglaze-a11y-fix-what" id="<?php echo esc_attr( $field_id . '-what' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
			<ul class="softglaze-a11y-fix-meta">
				<?php foreach ( $criteria as $criterion ) : ?>
					<li><?php $this->print_criterion( $criterion ); ?></li>
				<?php endforeach; ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: risk level, for example Low */
							__( 'Risk: %s.', 'tagmend-accessibility-fixes' ),
							$risk
						)
					);
					?>
				</li>
				<li><?php echo esc_html( $recommended ? __( 'Recommended: starts in Preview after activation.', 'tagmend-accessibility-fixes' ) : __( 'Starts Off after activation.', 'tagmend-accessibility-fixes' ) ); ?></li>
				<li><?php echo esc_html( $this->scanned_line( $id, $state, $totals ) ); ?></li>
			</ul>
		</fieldset>
		<?php
	}

	/**
	 * Prints a success criterion with its name and a "Learn more" link to its
	 * W3C Understanding page (only for criteria in UNDERSTANDING).
	 *
	 * @param string $criterion Success criterion number, for example 4.1.2.
	 * @return void
	 */
	private function print_criterion( string $criterion ) {
		$name = self::criterion_name( $criterion );
		$full = '' === $name ? $criterion : $criterion . ' ' . $name;
		echo esc_html(
			sprintf(
				/* translators: %s: success criterion number and name, for example 4.1.2 Name, Role, Value */
				__( 'WCAG %s.', 'tagmend-accessibility-fixes' ),
				$full
			)
		);
		if ( ! isset( self::UNDERSTANDING[ $criterion ] ) ) {
			return;
		}
		printf(
			' <a href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( self::UNDERSTANDING_URL . self::UNDERSTANDING[ $criterion ] . '.html' ),
			esc_html__( 'Learn more', 'tagmend-accessibility-fixes' ),
			esc_html(
				sprintf(
					/* translators: %s: success criterion number and name, for example 4.1.2 Name, Role, Value */
					__( 'about %s on the W3C website', 'tagmend-accessibility-fixes' ),
					$full
				)
			)
		);
	}

	/**
	 * What a fix lists on the scanned pages, from the stored reports.
	 *
	 * @param string $id     Fix id.
	 * @param string $state  Saved state.
	 * @param array  $totals Pages_Store::fix_totals().
	 * @return string
	 */
	private function scanned_line( string $id, string $state, array $totals ): string {
		if ( in_array( $id, Screen_Report_Details::HOOK_ONLY_FIXES, true ) ) {
			return __( 'Scan reports do not list this fix: it works through WordPress hooks while a page is built. Open a page with "View repaired" to see its changes.', 'tagmend-accessibility-fixes' );
		}
		$reports = isset( $totals['reports'] ) ? (int) $totals['reports'] : 0;
		if ( 0 === $reports ) {
			return __( 'No page has been scanned yet.', 'tagmend-accessibility-fixes' );
		}
		$changes = isset( $totals['fixes'][ $id ]['changes'] ) ? (int) $totals['fixes'][ $id ]['changes'] : 0;
		$pages   = isset( $totals['fixes'][ $id ]['pages'] ) ? (int) $totals['fixes'][ $id ]['pages'] : 0;
		if ( 0 === $changes ) {
			return sprintf(
				/* translators: %s: number of scanned pages */
				_n( 'No changes on the %s scanned page.', 'No changes on the %s scanned pages.', $reports, 'tagmend-accessibility-fixes' ),
				number_format_i18n( $reports )
			);
		}
		if ( 'live' === $state ) {
			return sprintf(
				/* translators: 1: number of changed elements, 2: number of pages with changes, 3: number of scanned pages */
				_n( 'Changes %1$s element on %2$s of the %3$s scanned pages.', 'Changes %1$s elements on %2$s of the %3$s scanned pages.', $changes, 'tagmend-accessibility-fixes' ),
				number_format_i18n( $changes ),
				number_format_i18n( $pages ),
				number_format_i18n( $reports )
			);
		}
		return sprintf(
			/* translators: 1: number of elements, 2: number of pages with changes, 3: number of scanned pages */
			_n( 'Would change %1$s element on %2$s of the %3$s scanned pages.', 'Would change %1$s elements on %2$s of the %3$s scanned pages.', $changes, 'tagmend-accessibility-fixes' ),
			number_format_i18n( $changes ),
			number_format_i18n( $pages ),
			number_format_i18n( $reports )
		);
	}

	/**
	 * The confirmation step of "Move all Preview fixes to Live": the fixes
	 * that will move, the ones that stay in Preview and why, and a form that
	 * posts exactly the listed ids.
	 *
	 * @return void
	 */
	private function render_confirm() {
		$preview = $this->preview_fixes( $this->settings->fix_states() );
		$ready   = $preview['ready'];
		?>
		<div class="wrap softglaze-a11y-screen softglaze-a11y-fixes">
			<p class="softglaze-a11y-back"><a href="<?php echo esc_url( self::url() ); ?>"><?php echo esc_html__( 'Back to Fixes', 'tagmend-accessibility-fixes' ); ?></a></p>
			<h1><?php echo esc_html__( 'Move all Preview fixes to Live', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php if ( empty( $ready ) ) : ?>
				<p><?php echo esc_html__( 'No fix that can run on this site is in Preview, so there is nothing to move.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of fixes */
							_n(
								'%s fix is in Preview. After you confirm, it is Live and every visitor gets its changes:',
								'These %s fixes are in Preview. After you confirm, they are Live and every visitor gets their changes:',
								count( $ready ),
								'tagmend-accessibility-fixes'
							),
							number_format_i18n( count( $ready ) )
						)
					);
					?>
				</p>
				<ul class="softglaze-a11y-confirm-list">
					<?php foreach ( $ready as $id => $label ) : ?>
						<li><a href="<?php echo esc_url( self::url() . '#softglaze-a11y-fix-' . $id ); ?>"><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $preview['blocked'] ) ) : ?>
				<p><?php echo esc_html__( 'These stay in Preview because they cannot run on this site:', 'tagmend-accessibility-fixes' ); ?></p>
				<ul class="softglaze-a11y-confirm-list">
					<?php foreach ( $preview['blocked'] as $entry ) : ?>
						<li><strong><?php echo esc_html( $entry['label'] ); ?></strong><br><?php echo esc_html( $entry['reason'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $ready ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::BULK_ACTION ); ?>">
					<?php wp_nonce_field( self::BULK_ACTION, self::NONCE_FIELD ); ?>
					<?php foreach ( array_keys( $ready ) as $id ) : ?>
						<input type="hidden" name="<?php echo esc_attr( self::BULK_FIELD ); ?>[]" value="<?php echo esc_attr( $id ); ?>">
					<?php endforeach; ?>
					<p class="softglaze-a11y-confirm-actions">
						<button type="submit" class="button button-primary">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of fixes */
									_n( 'Move %s fix to Live', 'Move these %s fixes to Live', count( $ready ), 'tagmend-accessibility-fixes' ),
									number_format_i18n( count( $ready ) )
								)
							);
							?>
						</button>
						<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php echo esc_html__( 'Cancel', 'tagmend-accessibility-fixes' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Fixes in Preview: those that can move to Live (id => label) and those
	 * that cannot run here (list of label and reason).
	 *
	 * @param string[] $states Saved states.
	 * @return array 'ready' => string[], 'blocked' => array[].
	 */
	private function preview_fixes( array $states ): array {
		$ready   = array();
		$blocked = array();
		foreach ( $this->registry->fixes() as $id => $fix ) {
			if ( ! isset( $states[ $id ] ) || 'preview' !== $states[ $id ] ) {
				continue;
			}
			try {
				$label = $fix->label();
			} catch ( Throwable $throwable ) {
				continue;
			}
			$reason = $this->registry->unavailable_reason( $fix );
			if ( '' === $reason ) {
				$ready[ $id ] = $label;
			} else {
				$blocked[] = array(
					'label'  => $label,
					'reason' => $reason,
				);
			}
		}
		return array(
			'ready'   => $ready,
			'blocked' => $blocked,
		);
	}

	/**
	 * Registered fixes by group, in screen order; registry order inside a group.
	 *
	 * @return array Group => array( fix id => Fix ).
	 */
	private function grouped_fixes(): array {
		$fixes   = $this->registry->fixes();
		$grouped = array();
		$placed  = array();
		foreach ( self::GROUPS as $group => $ids ) {
			foreach ( $fixes as $id => $fix ) {
				if ( in_array( $id, $ids, true ) ) {
					$grouped[ $group ][ $id ] = $fix;
					$placed[ $id ]            = true;
				}
			}
		}
		foreach ( $fixes as $id => $fix ) {
			if ( ! isset( $placed[ $id ] ) ) {
				$grouped['other'][ $id ] = $fix;
			}
		}
		return $grouped;
	}

	/**
	 * Name of a group.
	 *
	 * @param string $group Group key.
	 * @return string
	 */
	private static function group_label( string $group ): string {
		switch ( $group ) {
			case 'images':
				return __( 'Images', 'tagmend-accessibility-fixes' );
			case 'links':
				return __( 'Links and buttons', 'tagmend-accessibility-fixes' );
			case 'forms':
				return __( 'Forms', 'tagmend-accessibility-fixes' );
			case 'structure':
				return __( 'Page structure', 'tagmend-accessibility-fixes' );
			case 'keyboard':
				return __( 'Keyboard', 'tagmend-accessibility-fixes' );
			case 'embeds':
				return __( 'Embeds and media', 'tagmend-accessibility-fixes' );
			default:
				return __( 'Other fixes', 'tagmend-accessibility-fixes' );
		}
	}

	/**
	 * Name of a success criterion the bundled fixes name, or ''.
	 *
	 * @param string $criterion Number, for example 4.1.2.
	 * @return string
	 */
	public static function criterion_name( string $criterion ): string {
		$names = array(
			'1.1.1' => __( 'Non-text Content', 'tagmend-accessibility-fixes' ),
			'1.3.1' => __( 'Info and Relationships', 'tagmend-accessibility-fixes' ),
			'1.4.4' => __( 'Resize Text', 'tagmend-accessibility-fixes' ),
			'2.4.1' => __( 'Bypass Blocks', 'tagmend-accessibility-fixes' ),
			'2.4.3' => __( 'Focus Order', 'tagmend-accessibility-fixes' ),
			'2.4.4' => __( 'Link Purpose (In Context)', 'tagmend-accessibility-fixes' ),
			'2.4.6' => __( 'Headings and Labels', 'tagmend-accessibility-fixes' ),
			'2.4.7' => __( 'Focus Visible', 'tagmend-accessibility-fixes' ),
			'3.1.1' => __( 'Language of Page', 'tagmend-accessibility-fixes' ),
			'3.2.5' => __( 'Change on Request', 'tagmend-accessibility-fixes' ),
			'4.1.2' => __( 'Name, Role, Value', 'tagmend-accessibility-fixes' ),
		);
		return isset( $names[ $criterion ] ) ? $names[ $criterion ] : '';
	}

	/**
	 * Prints the inline message for the redirect back to this screen (no
	 * admin notice hook), with the page-cache reminder when a fix moved to or
	 * from Live. admin.js moves focus to it.
	 *
	 * @return void
	 */
	private function print_status() {
		$status = self::status();
		if ( '' === $status ) {
			return;
		}
		$type = 'success';
		if ( 'rejected' === $status ) {
			$type     = 'warning';
			$messages = array( __( 'Some fix states could not be saved and kept their previous value.', 'tagmend-accessibility-fixes' ) );
		} elseif ( 'bulk' === $status ) {
			$moved    = self::display_number( self::COUNT_ARG );
			$messages = array(
				0 === $moved
					? __( 'No fix was moved: none of the listed fixes was still in Preview.', 'tagmend-accessibility-fixes' )
					: sprintf(
						/* translators: %s: number of fixes */
						_n( '%s fix moved to Live: visitors get its changes now.', '%s fixes moved to Live: visitors get their changes now.', $moved, 'tagmend-accessibility-fixes' ),
						number_format_i18n( $moved )
					),
			);
		} else {
			$messages = array( __( 'Fix states saved.', 'tagmend-accessibility-fixes' ) );
		}
		if ( 1 === self::display_number( self::CACHE_ARG ) ) {
			$messages[] = Cache_Reminder::after_change();
		}
		printf( '<div class="notice notice-%s inline softglaze-a11y-status" role="status" tabindex="-1">', esc_attr( $type ) );
		foreach ( $messages as $message ) {
			printf( '<p>%s</p>', esc_html( $message ) );
		}
		echo '</div>';
	}

	/**
	 * Stops the request with 403 and a message.
	 *
	 * @param string $message Translated message.
	 * @return void
	 */
	private static function refuse( string $message ) {
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
	 * Whether any fix moved to or from Live, the change visitors (and page caches) see.
	 *
	 * @param string[] $before States before.
	 * @param string[] $after  States after.
	 * @return bool
	 */
	private static function live_changed( array $before, array $after ): bool {
		foreach ( $after as $id => $state ) {
			$was = isset( $before[ $id ] ) ? $before[ $id ] : 'off';
			if ( ( 'live' === $was ) !== ( 'live' === $state ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the confirmation step is requested. Showing it changes nothing,
	 * so the read needs no nonce; the form it shows carries one.
	 *
	 * @return bool
	 */
	private static function confirm_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch; compared with one literal.
		$step = isset( $_GET[ self::STEP_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STEP_ARG ] ) ) : '';
		return self::STEP_CONFIRM === $step;
	}

	/**
	 * The result of the last save, from the redirect back to this screen.
	 * Display-only and allow-listed, so no nonce is needed to read it.
	 *
	 * @return string One of STATUSES, or ''.
	 */
	private static function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status after a redirect; allow-listed below.
		$status = isset( $_GET[ self::STATUS_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_ARG ] ) ) : '';
		return in_array( $status, self::STATUSES, true ) ? $status : '';
	}

	/**
	 * A display-only number from the redirect back to this screen.
	 *
	 * @param string $arg Query arg.
	 * @return int
	 */
	private static function display_number( string $arg ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only number after a redirect; absint below.
		return isset( $_GET[ $arg ] ) ? absint( wp_unslash( $_GET[ $arg ] ) ) : 0;
	}

	/**
	 * Translated risk level.
	 *
	 * @param string $risk 'low', 'medium' or 'high'.
	 * @return string
	 */
	private function risk_label( string $risk ): string {
		switch ( $risk ) {
			case 'low':
				return __( 'Low', 'tagmend-accessibility-fixes' );
			case 'medium':
				return __( 'Medium', 'tagmend-accessibility-fixes' );
			case 'high':
				return __( 'High', 'tagmend-accessibility-fixes' );
			default:
				return __( 'Unknown', 'tagmend-accessibility-fixes' );
		}
	}
}
