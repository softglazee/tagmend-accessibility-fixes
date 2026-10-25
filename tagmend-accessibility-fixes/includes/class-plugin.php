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
		$this->fixes_screen = new Admin\Screen_Fixes( $this->registry, $this->settings );
		$this->install      = new Install( $this->settings, $this->log );

		register_activation_hook( SOFTGLAZE_A11Y_FILE, array( $this->install, 'activate' ) );
		add_action( 'init', array( $this->settings, 'register' ) );
		add_action( 'softglaze_a11y_fix_state_changed', array( $this->log, 'record_state_change' ), 10, 4 );
		( new Rest_Controller( $this->scanner ) )->register();

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			$this->fixes_screen->register();
			$this->install->register();
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
	 * Adds the top-level admin menu, its Overview page and the Fixes page.
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
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Overview', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_overview' )
		);
		$this->fixes_screen->add_page( self::MENU_SLUG );
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
