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
 * Boots the plugin and wires its hooks. Front-end hooks are not added at all
 * when the SOFTGLAZE_A11Y_DISABLE kill switch is on.
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
	 * Request gate.
	 *
	 * @var Request_Gate
	 */
	private $gate;

	/**
	 * Front-end wiring.
	 *
	 * @var Front_End
	 */
	private $front_end;

	/**
	 * Fixes screen.
	 *
	 * @var Admin\Screen_Fixes
	 */
	private $fixes_screen;

	/**
	 * Report table.
	 *
	 * @var Report\Pages_Store
	 */
	private $pages;

	/**
	 * Activity log.
	 *
	 * @var Report\Log_Store
	 */
	private $log;

	/**
	 * Scan links and report collector.
	 *
	 * @var Report\Scanner
	 */
	private $scanner;

	/**
	 * Table installer.
	 *
	 * @var Install
	 */
	private $install;

	/**
	 * Reports screen (with the report details view).
	 *
	 * @var Admin\Screen_Reports
	 */
	private $reports_screen;

	/**
	 * Activity log screen.
	 *
	 * @var Admin\Screen_Log
	 */
	private $log_screen;

	/**
	 * Overview screen.
	 *
	 * @var Admin\Screen_Overview
	 */
	private $overview_screen;

	/**
	 * Settings screen.
	 *
	 * @var Admin\Screen_Settings
	 */
	private $settings_screen;

	/**
	 * Hook suffixes of the plugin's own screens, which load admin.css and admin.js.
	 *
	 * @var string[]
	 */
	private $asset_hooks = array();

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
	 * Builds the components and registers hooks.
	 */
	private function __construct() {
		$this->registry     = new Registry();
		$this->settings     = new Settings( $this->registry );
		$this->gate         = new Request_Gate( $this->settings );
		$this->pages        = new Report\Pages_Store();
		$this->log          = new Report\Log_Store();
		$this->scanner      = new Report\Scanner( $this->gate, $this->pages, $this->log );
		$this->front_end    = new Front_End( $this->gate, $this->registry, $this->settings, $this->scanner );
		$this->fixes_screen = new Admin\Screen_Fixes( $this->registry, $this->settings, $this->pages );
		$this->install      = new Install( $this->settings, $this->log );

		$details               = new Admin\Screen_Report_Details( $this->registry, $this->settings, $this->scanner, $this->log );
		$this->reports_screen  = new Admin\Screen_Reports( $this->scanner, $details );
		$this->log_screen      = new Admin\Screen_Log( $this->registry, $this->log );
		$this->overview_screen = new Admin\Screen_Overview( $this->settings, $this->scanner );
		$this->settings_screen = new Admin\Screen_Settings( $this->settings, $this->log, $this->pages );

		register_activation_hook( SOFTGLAZE_A11Y_FILE, array( $this->install, 'activate' ) );
		add_action( 'init', array( $this->settings, 'register' ) );
		add_action( 'softglaze_a11y_fix_state_changed', array( $this->log, 'record_state_change' ), 10, 4 );
		( new Rest_Controller( $this->scanner ) )->register();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			$this->fixes_screen->register();
			$this->reports_screen->register();
			$this->log_screen->register();
			$this->settings_screen->register();
			$this->install->register();
			( new Privacy() )->register();
		} elseif ( ! $this->gate->kill_switch() ) {
			$this->front_end->register();
			( new Admin\Admin_Bar( $this->settings, $this->gate, $this->scanner ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'softglaze-a11y', Cli\Command::class );
		}
	}

	/**
	 * Fix list.
	 *
	 * @return Registry
	 */
	public function registry(): Registry {
		return $this->registry;
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Request gate.
	 *
	 * @return Request_Gate
	 */
	public function gate(): Request_Gate {
		return $this->gate;
	}

	/**
	 * Front-end wiring.
	 *
	 * @return Front_End
	 */
	public function front_end(): Front_End {
		return $this->front_end;
	}

	/**
	 * Fixes screen.
	 *
	 * @return Admin\Screen_Fixes
	 */
	public function fixes_screen(): Admin\Screen_Fixes {
		return $this->fixes_screen;
	}

	/**
	 * Report table.
	 *
	 * @return Report\Pages_Store
	 */
	public function pages(): Report\Pages_Store {
		return $this->pages;
	}

	/**
	 * Activity log.
	 *
	 * @return Report\Log_Store
	 */
	public function log(): Report\Log_Store {
		return $this->log;
	}

	/**
	 * Scan links and report collector.
	 *
	 * @return Report\Scanner
	 */
	public function scanner(): Report\Scanner {
		return $this->scanner;
	}

	/**
	 * Table installer.
	 *
	 * @return Install
	 */
	public function install(): Install {
		return $this->install;
	}

	/**
	 * Reports screen.
	 *
	 * @return Admin\Screen_Reports
	 */
	public function reports_screen(): Admin\Screen_Reports {
		return $this->reports_screen;
	}

	/**
	 * Activity log screen.
	 *
	 * @return Admin\Screen_Log
	 */
	public function log_screen(): Admin\Screen_Log {
		return $this->log_screen;
	}

	/**
	 * Overview screen.
	 *
	 * @return Admin\Screen_Overview
	 */
	public function overview_screen(): Admin\Screen_Overview {
		return $this->overview_screen;
	}

	/**
	 * Settings screen.
	 *
	 * @return Admin\Screen_Settings
	 */
	public function settings_screen(): Admin\Screen_Settings {
		return $this->settings_screen;
	}

	/**
	 * Adds the top-level admin menu and its Overview, Fixes, Reports,
	 * Activity log and Settings pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$overview = add_menu_page(
			__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' ),
			'dashicons-universal-access-alt'
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Overview', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' )
		);
		$fixes    = $this->fixes_screen->add_page( self::MENU_SLUG );
		$reports  = $this->reports_screen->add_page( self::MENU_SLUG );
		$log      = $this->log_screen->add_page( self::MENU_SLUG );
		$settings = $this->settings_screen->add_page( self::MENU_SLUG );

		$this->asset_hooks = array_values( array_filter( array( is_string( $overview ) ? $overview : '', $fixes, $reports, $log, $settings ) ) );
	}

	/**
	 * Loads admin.css and admin.js on the plugin's own screens only.
	 * admin.js moves focus to the inline status message after a save and
	 * runs scans (through wp-api-fetch; translations through wp-i18n) where
	 * the Reports screen shows its scan panel. Nothing is printed inline:
	 * admin.js reads its data from data-* attributes.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! is_string( $hook_suffix ) || ! in_array( $hook_suffix, $this->asset_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'softglaze-a11y-admin', plugins_url( 'assets/css/admin.css', SOFTGLAZE_A11Y_FILE ), array(), SOFTGLAZE_A11Y_VERSION );
		wp_enqueue_script( 'softglaze-a11y-admin', plugins_url( 'assets/js/admin.js', SOFTGLAZE_A11Y_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), SOFTGLAZE_A11Y_VERSION, true );
		wp_set_script_translations( 'softglaze-a11y-admin', 'tagmend-accessibility-fixes' );
	}

	/**
	 * Prints the Overview page.
	 *
	 * @return void
	 */
	public function render_overview() {
		$this->overview_screen->render();
	}
}
