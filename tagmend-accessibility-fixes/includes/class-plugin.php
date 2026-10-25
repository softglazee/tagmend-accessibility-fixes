<?php
/**
 * Plugin bootstrap.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the plugin and wires its hooks.
 */
final class Plugin {

	/**
	 * Admin page slug of the top-level Overview screen.
	 */
	const MENU_SLUG = 'softglaze-a11y';

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance, creating it on first use.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'softglaze-a11y', Cli\Command::class );
		}
		// TODO(Phase 1B): request gate, settings, registry and the layer A, B and C wiring.
	}

	/**
	 * Adds the top-level admin menu and its Overview page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' ),
			'dashicons-universal-access-alt'
		);
	}

	/**
	 * Prints the Overview page.
	 *
	 * @return void
	 */
	public function render_overview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// TODO(Phase 7): status counts, first-run steps, page-pass availability, recent scans, fallbacks and the disclaimer (blueprint section 12).
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ); ?></h1>
		</div>
		<?php
	}
}
