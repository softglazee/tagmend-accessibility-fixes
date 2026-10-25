<?php
/**
 * The Settings screen.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Report\Log_Store;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin settings (blueprint section 12) through the Settings API:
 * the form posts to options.php, core checks the capability and the
 * softglaze_a11y_settings-options nonce, and the one sanitize callback
 * registered with register_setting() validates every value. Fields hold
 * on/off choices, two clamped numbers and plain path prefixes; none accepts
 * CSS, JavaScript, PHP or HTML. A save that changes something writes one
 * settings_saved row to the activity log. The result is shown inline on
 * this screen; no admin notice hook is used.
 */
final class Screen_Settings {

	const PAGE = 'softglaze-a11y-settings';

	/**
	 * Field ids on the screen.
	 */
	const ID_PREFIX = 'softglaze-a11y-setting-';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Activity log.
	 *
	 * @var Log_Store
	 */
	private $log;

	/**
	 * Report table (whether another plugin switched off the page buffer on the last scanned pages).
	 *
	 * @var Pages_Store
	 */
	private $pages;

	/**
	 * Creates the screen.
	 *
	 * @param Settings         $settings Settings.
	 * @param Log_Store        $log      Activity log.
	 * @param Pages_Store|null $pages    Report table; a default one when null.
	 */
	public function __construct( Settings $settings, Log_Store $log, ?Pages_Store $pages = null ) {
		$this->settings = $settings;
		$this->log      = $log;
		$this->pages    = null !== $pages ? $pages : new Pages_Store();
	}

	/**
	 * Hooks the log row for settings saves.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'record_save' ), 10, 2 );
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
			__( 'Tagmend Settings', 'tagmend-accessibility-fixes' ),
			__( 'Settings', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
		return is_string( $hook ) ? $hook : '';
	}

	/**
	 * URL of this screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}

	/**
	 * After options.php saved a changed value: one settings_saved log row
	 * naming the changed keys. Only for the Settings API request of this
	 * screen (capability first, then core's nonce for the group, checked
	 * again here); fix states saved on the Fixes screen, by WP-CLI or by
	 * other code are logged as fix_state_changed rows instead.
	 *
	 * @param mixed $old_value Settings before.
	 * @param mixed $value     Settings after.
	 * @return void
	 */
	public function record_save( $old_value, $value ) {
		if ( ! $this->is_settings_request() || ! is_array( $value ) ) {
			return;
		}
		$old_value = is_array( $old_value ) ? $old_value : array();
		$changed   = array();
		foreach ( Settings::SCREEN_KEYS as $key ) {
			$before = array_key_exists( $key, $old_value ) ? $old_value[ $key ] : null;
			$after  = array_key_exists( $key, $value ) ? $value[ $key ] : null;
			if ( $before !== $after ) {
				$changed[] = $key;
			}
		}
		if ( empty( $changed ) ) {
			return;
		}
		$this->log->add( 'settings_saved', array( 'detail' => 'changed=' . implode( ',', $changed ) ) );
	}

	/**
	 * Label of a setting key (activity log details).
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	public static function key_label( string $key ): string {
		switch ( $key ) {
			case 'page_scope':
				return __( 'Whole-page repair', 'tagmend-accessibility-fixes' );
			case 'admin_bar':
				return __( 'Admin bar menu', 'tagmend-accessibility-fixes' );
			case 'max_kb':
				return __( 'Size limit', 'tagmend-accessibility-fixes' );
			case 'max_ms':
				return __( 'Time limit', 'tagmend-accessibility-fixes' );
			case 'exclude_paths':
				return __( 'Never repair these paths', 'tagmend-accessibility-fixes' );
			case 'delete_data_on_uninstall':
				return __( 'Delete all data when the plugin is deleted', 'tagmend-accessibility-fixes' );
			default:
				return $key;
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
		$values    = $this->settings->get();
		$page_pass = Registry::page_pass_exists();
		$vetoed    = $page_pass && Pages_Store::PAGE_PASS_VETOED === $this->pages->page_pass_status( true === $values['page_scope'] )['status'];
		$name      = Settings::OPTION;
		$paths     = is_array( $values['exclude_paths'] ) ? implode( "\n", array_filter( $values['exclude_paths'], 'is_string' ) ) : '';
		?>
		<div class="wrap softglaze-a11y-screen softglaze-a11y-settings">
			<h1><?php echo esc_html__( 'Tagmend Settings', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php $this->print_status(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( Settings::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Whole-page repair', 'tagmend-accessibility-fixes' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php echo esc_html__( 'Whole-page repair', 'tagmend-accessibility-fixes' ); ?></legend>
									<label for="<?php echo esc_attr( self::ID_PREFIX . 'page-scope' ); ?>">
										<?php if ( $page_pass ) : ?>
											<input type="hidden" name="<?php echo esc_attr( $name . '[page_scope]' ); ?>" value="0">
										<?php endif; ?>
										<input type="checkbox" id="<?php echo esc_attr( self::ID_PREFIX . 'page-scope' ); ?>" name="<?php echo esc_attr( $name . '[page_scope]' ); ?>" value="1" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'page-scope-note' . ( $vetoed ? ' ' . self::ID_PREFIX . 'page-scope-vetoed' : '' ) ); ?>"<?php checked( true === $values['page_scope'] ); ?><?php disabled( ! $page_pass ); ?>>
										<?php echo esc_html__( 'Repair the whole page, including the head, the theme header and footer, skip links and repeated form ids', 'tagmend-accessibility-fixes' ); ?>
									</label>
									<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'page-scope-note' ); ?>">
										<?php
										echo esc_html(
											$page_pass
												? __( 'Uses the WordPress page buffer. When it is off, repairs run on post content, template parts, widgets and menus only, and the fixes that need the whole page do not run.', 'tagmend-accessibility-fixes' )
												: __( 'Needs WordPress 6.9 or later. On this version, repairs run on post content, template parts, widgets and menus only.', 'tagmend-accessibility-fixes' )
										);
										?>
									</p>
									<?php if ( $vetoed ) : ?>
										<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'page-scope-vetoed' ); ?>"><?php echo esc_html__( 'On the last scanned pages another plugin switched off the WordPress page buffer (some page builders do), so the whole-page repair did not run there. The Overview names the report.', 'tagmend-accessibility-fixes' ); ?></p>
									<?php endif; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Admin bar menu', 'tagmend-accessibility-fixes' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php echo esc_html__( 'Admin bar menu', 'tagmend-accessibility-fixes' ); ?></legend>
									<label for="<?php echo esc_attr( self::ID_PREFIX . 'admin-bar' ); ?>">
										<input type="hidden" name="<?php echo esc_attr( $name . '[admin_bar]' ); ?>" value="0">
										<input type="checkbox" id="<?php echo esc_attr( self::ID_PREFIX . 'admin-bar' ); ?>" name="<?php echo esc_attr( $name . '[admin_bar]' ); ?>" value="1" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'admin-bar-note' ); ?>"<?php checked( true === $values['admin_bar'] ); ?>>
										<?php echo esc_html__( 'Show the Tagmend menu in the admin bar on your site', 'tagmend-accessibility-fixes' ); ?>
									</label>
									<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'admin-bar-note' ); ?>"><?php echo esc_html__( 'It holds View original, View repaired, the changes on the page and Scan this page. Only administrators see it.', 'tagmend-accessibility-fixes' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::ID_PREFIX . 'max-kb' ); ?>"><?php echo esc_html__( 'Size limit', 'tagmend-accessibility-fixes' ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="<?php echo esc_attr( self::ID_PREFIX . 'max-kb' ); ?>" name="<?php echo esc_attr( $name . '[max_kb]' ); ?>" value="<?php echo esc_attr( (string) absint( $values['max_kb'] ) ); ?>" min="<?php echo esc_attr( (string) Settings::MAX_KB_MIN ); ?>" max="<?php echo esc_attr( (string) Settings::MAX_KB_MAX ); ?>" step="1" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'max-kb-note' ); ?>">
								<?php echo esc_html__( 'KB', 'tagmend-accessibility-fixes' ); ?>
								<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'max-kb-note' ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: smallest value, 2: largest value */
											__( 'Pages larger than this are served unrepaired. From %1$s to %2$s KB.', 'tagmend-accessibility-fixes' ),
											number_format_i18n( Settings::MAX_KB_MIN ),
											number_format_i18n( Settings::MAX_KB_MAX )
										)
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::ID_PREFIX . 'max-ms' ); ?>"><?php echo esc_html__( 'Time limit', 'tagmend-accessibility-fixes' ); ?></label></th>
							<td>
								<input type="number" class="small-text" id="<?php echo esc_attr( self::ID_PREFIX . 'max-ms' ); ?>" name="<?php echo esc_attr( $name . '[max_ms]' ); ?>" value="<?php echo esc_attr( (string) absint( $values['max_ms'] ) ); ?>" min="<?php echo esc_attr( (string) Settings::MAX_MS_MIN ); ?>" max="<?php echo esc_attr( (string) Settings::MAX_MS_MAX ); ?>" step="1" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'max-ms-note' ); ?>">
								<?php echo esc_html__( 'milliseconds', 'tagmend-accessibility-fixes' ); ?>
								<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'max-ms-note' ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: smallest value, 2: largest value */
											__( 'When repairing a page takes longer than this, the page is served unrepaired. From %1$s to %2$s milliseconds.', 'tagmend-accessibility-fixes' ),
											number_format_i18n( Settings::MAX_MS_MIN ),
											number_format_i18n( Settings::MAX_MS_MAX )
										)
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::ID_PREFIX . 'exclude-paths' ); ?>"><?php echo esc_html__( 'Never repair these paths', 'tagmend-accessibility-fixes' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="5" id="<?php echo esc_attr( self::ID_PREFIX . 'exclude-paths' ); ?>" name="<?php echo esc_attr( $name . '[exclude_paths]' ); ?>" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'exclude-paths-note' ); ?>" spellcheck="false"><?php echo esc_textarea( $paths ); ?></textarea>
								<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'exclude-paths-note' ); ?>"><?php echo esc_html__( 'One path per line, starting with /, relative to your site address, for example /checkout/. Pages whose path starts with one of these are never repaired and cannot be scanned.', 'tagmend-accessibility-fixes' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Uninstall', 'tagmend-accessibility-fixes' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php echo esc_html__( 'Uninstall', 'tagmend-accessibility-fixes' ); ?></legend>
									<label for="<?php echo esc_attr( self::ID_PREFIX . 'delete-data' ); ?>">
										<input type="hidden" name="<?php echo esc_attr( $name . '[delete_data_on_uninstall]' ); ?>" value="0">
										<input type="checkbox" id="<?php echo esc_attr( self::ID_PREFIX . 'delete-data' ); ?>" name="<?php echo esc_attr( $name . '[delete_data_on_uninstall]' ); ?>" value="1" aria-describedby="<?php echo esc_attr( self::ID_PREFIX . 'delete-data-note' ); ?>"<?php checked( true === $values['delete_data_on_uninstall'] ); ?>>
										<?php echo esc_html__( 'Delete all data when the plugin is deleted', 'tagmend-accessibility-fixes' ); ?>
									</label>
									<p class="description" id="<?php echo esc_attr( self::ID_PREFIX . 'delete-data-note' ); ?>"><?php echo esc_html__( 'When this is on, deleting the plugin from the Plugins screen also deletes its settings, reports, activity log and per-page skips. When it is off, they stay for a later reinstall. Deactivating never deletes anything.', 'tagmend-accessibility-fixes' ); ?></p>
								</fieldset>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save settings', 'tagmend-accessibility-fixes' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * After options.php redirected back here: "Settings saved." and any
	 * warning the sanitize callback added, inline with role="status" (no
	 * admin notice hook). admin.js moves focus to it.
	 *
	 * @return void
	 */
	private function print_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag core adds after options.php saved; compared with one literal.
		$updated = isset( $_GET['settings-updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['settings-updated'] ) );
		if ( ! $updated ) {
			return;
		}
		$warnings = array();
		foreach ( get_settings_errors( Settings::OPTION ) as $error ) {
			if ( isset( $error['type'], $error['message'] ) && 'success' !== $error['type'] && is_string( $error['message'] ) ) {
				$warnings[] = $error['message'];
			}
		}
		printf( '<div class="notice notice-%s inline softglaze-a11y-status" role="status" tabindex="-1">', esc_attr( empty( $warnings ) ? 'success' : 'warning' ) );
		printf( '<p>%s</p>', esc_html__( 'Settings saved.', 'tagmend-accessibility-fixes' ) );
		foreach ( $warnings as $warning ) {
			printf( '<p>%s</p>', esc_html( $warning ) );
		}
		echo '</div>';
	}

	/**
	 * Whether this request is the options.php save of this screen's settings
	 * group by a user who can manage options, with core's nonce for the group.
	 *
	 * @return bool
	 */
	private function is_settings_request(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$group = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		return Settings::GROUP === $group && '' !== $nonce && false !== wp_verify_nonce( $nonce, Settings::GROUP . '-options' );
	}
}
