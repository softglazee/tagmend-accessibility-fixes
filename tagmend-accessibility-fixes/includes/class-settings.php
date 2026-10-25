<?php
/**
 * The softglaze_a11y_settings option: one array, one sanitize callback.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, validates and saves the plugin settings (blueprint section 11).
 * Every write, from the Fixes screen, the Settings API or WP-CLI, passes
 * through sanitize(), which accepts only registered fix ids, the three
 * states, Live only for fixes that can run here, and plain numbers, booleans
 * and path prefixes for the rest. No setting holds CSS, JavaScript, PHP or HTML.
 */
final class Settings {

	const OPTION = 'softglaze_a11y_settings';

	/**
	 * Settings API group (core builds the options.php nonce action from it).
	 */
	const GROUP = 'softglaze_a11y_settings';

	const SCHEMA_VERSION = 1;

	const MAX_KB_MIN = 64;
	const MAX_KB_MAX = 8192;
	const MAX_MS_MIN = 10;
	const MAX_MS_MAX = 1000;

	/**
	 * Path prefixes kept, and the longest prefix accepted.
	 */
	const EXCLUDE_MAX_ITEMS  = 100;
	const EXCLUDE_MAX_LENGTH = 200;

	/**
	 * Settings error code for path lines that were not kept.
	 */
	const PATHS_ERROR = 'softglaze_a11y_paths';

	/**
	 * Keys an administrator changes on the Settings screen (the fix states are saved on the Fixes screen).
	 */
	const SCREEN_KEYS = array( 'page_scope', 'admin_bar', 'max_kb', 'max_ms', 'exclude_paths', 'delete_data_on_uninstall' );

	/**
	 * The fix list.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Entries the last sanitize() call refused: list of array( 'id' => string, 'reason' => string ).
	 *
	 * @var array
	 */
	private $rejected = array();

	/**
	 * Creates the settings reader.
	 *
	 * @param Registry $registry The fix list.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Registers the option on init, so every update_option() call, whatever its
	 * origin, runs the sanitize callback.
	 *
	 * @return void
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * First activation: writes the defaults (recommended fixes in Preview, the
	 * rest Off). An existing option is never touched, so reactivating or
	 * updating keeps every saved state.
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, $this->defaults() );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public function defaults(): array {
		return array(
			'version'                  => self::SCHEMA_VERSION,
			'fixes'                    => Engine::default_states( $this->registry->fixes() ),
			'page_scope'               => true,
			'admin_bar'                => true,
			'max_kb'                   => (int) ( Engine::DEFAULT_MAX_BYTES / 1024 ),
			'max_ms'                   => Engine::DEFAULT_MAX_MS,
			'exclude_paths'            => array(),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Saved settings merged over the defaults. Fixes registered after the
	 * option was first written (a later version, another plugin) count as Off.
	 *
	 * @return array
	 */
	public function get(): array {
		$defaults = $this->defaults();
		$saved    = get_option( self::OPTION, null );
		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$settings = $defaults;
		foreach ( $defaults as $key => $default ) {
			if ( 'fixes' !== $key && array_key_exists( $key, $saved ) ) {
				$settings[ $key ] = $saved[ $key ];
			}
		}

		$fixes = array();
		if ( isset( $saved['fixes'] ) && is_array( $saved['fixes'] ) ) {
			foreach ( $saved['fixes'] as $id => $state ) {
				if ( is_string( $id ) && in_array( $state, Engine::STATES, true ) ) {
					$fixes[ $id ] = $state;
				}
			}
		}
		foreach ( $this->registry->ids() as $id ) {
			if ( ! isset( $fixes[ $id ] ) ) {
				$fixes[ $id ] = 'off';
			}
		}
		$settings['fixes'] = $fixes;

		return $settings;
	}

	/**
	 * Saved state of every registered fix.
	 *
	 * @return string[] Fix id => 'off' | 'preview' | 'live'.
	 */
	public function fix_states(): array {
		$saved  = $this->get()['fixes'];
		$states = array();
		foreach ( $this->registry->ids() as $id ) {
			$states[ $id ] = isset( $saved[ $id ] ) ? $saved[ $id ] : 'off';
		}
		return $states;
	}

	/**
	 * Saves new states for some fixes through the sanitize callback and fires
	 * softglaze_a11y_fix_state_changed for each state that changed.
	 *
	 * @param string[] $states  Fix id => requested state.
	 * @param int      $user_id User who made the change (0 for WP-CLI).
	 * @return array Refused entries, as returned by last_rejected().
	 */
	public function save_fix_states( array $states, int $user_id ): array {
		$before            = $this->fix_states();
		$settings          = $this->get();
		$settings['fixes'] = array_merge( $settings['fixes'], $states );

		update_option( self::OPTION, $settings );
		$rejected = $this->rejected;

		foreach ( $this->fix_states() as $id => $to ) {
			$from = isset( $before[ $id ] ) ? $before[ $id ] : 'off';
			if ( $from !== $to ) {
				/**
				 * Fires after a fix state was saved.
				 *
				 * @param string $id      Fix id.
				 * @param string $from    Previous state.
				 * @param string $to      New state.
				 * @param int    $user_id User id, 0 for WP-CLI.
				 */
				do_action( 'softglaze_a11y_fix_state_changed', $id, $from, $to, $user_id );
			}
		}

		return $rejected;
	}

	/**
	 * Entries refused by the last sanitize() call.
	 *
	 * @return array List of array( 'id' => string, 'reason' => 'unknown_fix' | 'unknown_state' | 'unavailable' | 'not_an_array' ).
	 */
	public function last_rejected(): array {
		return $this->rejected;
	}

	/**
	 * The register_setting() sanitize callback. Starts from the saved settings
	 * and accepts each submitted value only when it is valid, so a refused
	 * entry keeps its saved value.
	 *
	 * @param mixed $value Submitted settings.
	 * @return array
	 */
	public function sanitize( $value ): array {
		$this->rejected = array();
		$clean          = $this->get();

		if ( ! is_array( $value ) ) {
			$this->rejected[] = array(
				'id'     => '',
				'reason' => 'not_an_array',
			);
			return $clean;
		}

		$clean['version'] = self::SCHEMA_VERSION;

		if ( isset( $value['fixes'] ) && is_array( $value['fixes'] ) ) {
			$clean['fixes'] = $this->sanitize_fixes( $value['fixes'], $clean['fixes'] );
		}

		foreach ( array( 'page_scope', 'admin_bar', 'delete_data_on_uninstall' ) as $key ) {
			if ( array_key_exists( $key, $value ) ) {
				$clean[ $key ] = is_scalar( $value[ $key ] ) && wp_validate_boolean( $value[ $key ] );
			}
		}

		if ( isset( $value['max_kb'] ) && is_scalar( $value['max_kb'] ) ) {
			$clean['max_kb'] = min( self::MAX_KB_MAX, max( self::MAX_KB_MIN, absint( $value['max_kb'] ) ) );
		}
		if ( isset( $value['max_ms'] ) && is_scalar( $value['max_ms'] ) ) {
			$clean['max_ms'] = min( self::MAX_MS_MAX, max( self::MAX_MS_MIN, absint( $value['max_ms'] ) ) );
		}

		if ( array_key_exists( 'exclude_paths', $value ) ) {
			$dropped                = 0;
			$clean['exclude_paths'] = self::sanitize_paths( $value['exclude_paths'], $dropped );
			if ( $dropped > 0 ) {
				$this->rejected[] = array(
					'id'     => 'exclude_paths',
					'reason' => 'invalid_path',
				);
				self::report_dropped_paths( $dropped );
			}
		}

		return $clean;
	}

	/**
	 * Tells the Settings screen how many path lines were not kept. The
	 * Settings API carries the message across the redirect after options.php;
	 * outside wp-admin (WP-CLI) there is no screen to tell.
	 *
	 * @param int $dropped Lines not kept.
	 * @return void
	 */
	private static function report_dropped_paths( int $dropped ): void {
		if ( ! function_exists( 'add_settings_error' ) || ! function_exists( 'get_settings_errors' ) ) {
			return;
		}
		foreach ( get_settings_errors( self::OPTION ) as $error ) {
			if ( isset( $error['code'] ) && self::PATHS_ERROR === $error['code'] ) {
				return;
			}
		}
		add_settings_error(
			self::OPTION,
			self::PATHS_ERROR,
			sprintf(
				/* translators: 1: number of lines, 2: longest allowed path in characters, 3: most paths kept */
				_n(
					'%1$s line of "Never repair these paths" was not saved: each path must start with / and be at most %2$s characters long, and at most %3$s paths are kept.',
					'%1$s lines of "Never repair these paths" were not saved: each path must start with / and be at most %2$s characters long, and at most %3$s paths are kept.',
					$dropped,
					'tagmend-accessibility-fixes'
				),
				number_format_i18n( $dropped ),
				number_format_i18n( self::EXCLUDE_MAX_LENGTH ),
				number_format_i18n( self::EXCLUDE_MAX_ITEMS )
			),
			'warning'
		);
	}

	/**
	 * Size guard in bytes.
	 *
	 * @return int
	 */
	public function max_bytes(): int {
		return absint( $this->get()['max_kb'] ) * 1024;
	}

	/**
	 * Time guard in milliseconds.
	 *
	 * @return int
	 */
	public function max_ms(): int {
		return absint( $this->get()['max_ms'] );
	}

	/**
	 * Whether the whole-page pass is switched on (it also needs WordPress 6.9).
	 *
	 * @return bool
	 */
	public function page_scope_enabled(): bool {
		return true === $this->get()['page_scope'];
	}

	/**
	 * Whether the admin-bar node is shown.
	 *
	 * @return bool
	 */
	public function admin_bar_enabled(): bool {
		return true === $this->get()['admin_bar'];
	}

	/**
	 * Whether deleting the plugin also deletes its data (uninstall.php reads the option itself).
	 *
	 * @return bool
	 */
	public function delete_data_on_uninstall(): bool {
		return true === $this->get()['delete_data_on_uninstall'];
	}

	/**
	 * "Never repair these paths" prefixes.
	 *
	 * @return string[]
	 */
	public function exclude_paths(): array {
		$paths = $this->get()['exclude_paths'];
		return is_array( $paths ) ? array_values( array_filter( $paths, 'is_string' ) ) : array();
	}

	/**
	 * Validates submitted fix states against the registry and the allow-list.
	 *
	 * @param array    $submitted Fix id => state.
	 * @param string[] $current   Saved states (including ids not registered right now).
	 * @return string[]
	 */
	private function sanitize_fixes( array $submitted, array $current ): array {
		$fixes = $current;
		foreach ( $submitted as $id => $state ) {
			$id    = is_string( $id ) ? $id : '';
			$state = is_scalar( $state ) ? sanitize_key( (string) $state ) : '';
			$fix   = '' === $id ? null : $this->registry->get( $id );

			if ( null === $fix ) {
				// A stored state for a fix that is not registered right now (its add-on is inactive) may be carried over unchanged.
				if ( '' === $id || ! isset( $current[ $id ] ) || $current[ $id ] !== $state ) {
					$this->rejected[] = array(
						'id'     => sanitize_key( $id ),
						'reason' => 'unknown_fix',
					);
				}
				continue;
			}
			if ( ! in_array( $state, Engine::STATES, true ) ) {
				$this->rejected[] = array(
					'id'     => $id,
					'reason' => 'unknown_state',
				);
				continue;
			}
			if ( 'live' === $state && 'live' !== ( isset( $current[ $id ] ) ? $current[ $id ] : '' ) && ! $this->registry->is_available( $fix ) ) {
				$this->rejected[] = array(
					'id'     => $id,
					'reason' => 'unavailable',
				);
				continue;
			}
			$fixes[ $id ] = $state;
		}
		return $fixes;
	}

	/**
	 * Path prefixes: one per line or an array, each plain text starting with "/".
	 *
	 * @param mixed $paths   Submitted value.
	 * @param int   $dropped Set to the number of non-empty lines that were not kept.
	 * @return string[]
	 */
	private static function sanitize_paths( $paths, int &$dropped = 0 ): array {
		$dropped = 0;
		if ( is_string( $paths ) ) {
			$paths = preg_split( '/\R/', $paths );
		}
		if ( ! is_array( $paths ) ) {
			return array();
		}

		$clean = array();
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = trim( sanitize_text_field( $path ) );
			if ( '' === $path ) {
				continue;
			}
			if ( '/' !== $path[0] || strlen( $path ) > self::EXCLUDE_MAX_LENGTH || ( ! isset( $clean[ $path ] ) && count( $clean ) >= self::EXCLUDE_MAX_ITEMS ) ) {
				++$dropped;
				continue;
			}
			$clean[ $path ] = $path;
		}
		return array_values( $clean );
	}
}
