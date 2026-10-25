<?php
/**
 * Decides once per request whether any repair runs, and in which mode.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The request gate (blueprint section 3.1). Repairs run only on a front-end
 * HTML GET request that is not an admin, AJAX, cron, REST, XML-RPC, WP-CLI,
 * JSON, feed, embed, robots, favicon, trackback, Customizer, AMP or builder
 * editor request, outside the excluded paths, with the kill switch off and
 * the softglaze_a11y_should_process filter agreeing.
 *
 * The result is computed lazily on first use, never before the wp action
 * (the conditional tags need the main query and REST_REQUEST is defined
 * during parse_request), and cached for the rest of the request. Request
 * values are read one key at a time and sanitized at the read.
 */
class Request_Gate {

	/**
	 * Query args that mark a page-builder editor or preview frame, or a
	 * WooCommerce AJAX endpoint. Only their presence is checked.
	 */
	const BUILDER_ARGS = array(
		'elementor-preview',
		'et_fb',
		'vc_editable',
		'vc_action',
		'fl_builder',
		'bricks',
		'ct_builder',
		'brizy-edit-iframe',
		'wc-ajax',
	);

	const VIEW_ARG       = 'softglaze_a11y_view';
	const VIEW_NONCE_ARG = 'softglaze_a11y_view_nonce';
	const VIEW_ACTION    = 'softglaze_a11y_view';

	const SCAN_ARG       = 'softglaze_a11y_scan';
	const SCAN_NONCE_ARG = 'softglaze_a11y_scan_nonce';
	const SCAN_ACTION    = 'softglaze_a11y_scan';

	/**
	 * Query args kept in a normalised URL (blueprint section 11).
	 */
	const URL_ARGS = array( 'p', 'page_id', 'paged', 'product' );

	/**
	 * Allowed values of the view arg and the mode each one selects.
	 */
	const VIEWS = array(
		'original' => Engine::MODE_ADMIN_ORIGINAL,
		'repaired' => Engine::MODE_ADMIN_REPAIRED,
	);

	/**
	 * Settings (excluded paths).
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Cached result: null until computed after the wp action.
	 *
	 * @var bool|null
	 */
	private $passes = null;

	/**
	 * Why the gate refused the request ('' when it passed).
	 *
	 * @var string
	 */
	private $reason = '';

	/**
	 * Cached mode.
	 *
	 * @var string|null
	 */
	private $mode = null;

	/**
	 * Creates the gate.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether repairs may run on this request. False before the wp action,
	 * without caching, so an early caller cannot fix the answer too soon.
	 *
	 * @return bool
	 */
	public function passes(): bool {
		if ( null !== $this->passes ) {
			return $this->passes;
		}
		if ( ! did_action( 'wp' ) ) {
			return false;
		}
		$this->reason = $this->first_refusal();
		$this->passes = '' === $this->reason;
		return $this->passes;
	}

	/**
	 * Short reason for a refusal ('' when the gate passed or has not run yet).
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Mode of this request: visitor; scan when an administrator follows a scan
	 * link with a valid nonce; or one of the two admin views when an
	 * administrator follows a view link with a valid nonce.
	 *
	 * @return string One of the Engine::MODE_* constants.
	 */
	public function mode(): string {
		if ( ! $this->passes() ) {
			return Engine::MODE_VISITOR;
		}
		if ( null === $this->mode ) {
			$this->mode = $this->view_mode();
		}
		return $this->mode;
	}

	/**
	 * Whether this request is one of the two admin views.
	 *
	 * @return bool
	 */
	public function is_admin_view(): bool {
		$mode = $this->mode();
		return Engine::MODE_ADMIN_ORIGINAL === $mode || Engine::MODE_ADMIN_REPAIRED === $mode;
	}

	/**
	 * Whether the kill switch SOFTGLAZE_A11Y_DISABLE is defined as true.
	 *
	 * @return bool
	 */
	public function kill_switch(): bool {
		return $this->constant_is_true( 'SOFTGLAZE_A11Y_DISABLE' );
	}

	/**
	 * Fix ids excluded on the queried post (_softglaze_a11y_skip_fixes).
	 *
	 * @return string[]
	 */
	public function skipped_fixes(): array {
		if ( ! did_action( 'wp' ) || ! is_singular() ) {
			return array();
		}
		$ids = get_post_meta( get_queried_object_id(), '_softglaze_a11y_skip_fixes', true );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', array_filter( $ids, 'is_string' ) ) ) );
	}

	/**
	 * The request URI (path and query), sanitized.
	 *
	 * @return string
	 */
	public function request_uri(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return '' === $uri ? '/' : $uri;
	}

	/**
	 * The request path relative to the site's home path, always starting with "/".
	 *
	 * @return string
	 */
	public function request_path(): string {
		$path = wp_parse_url( $this->request_uri(), PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '/';
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? $home : '/';
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . substr( $path, strlen( $home ) );
		}
		return '' === $path || '/' !== $path[0] ? '/' . $path : $path;
	}

	/**
	 * SHA-256 of the normalised URL of this request.
	 *
	 * @return string
	 */
	public function url_hash(): string {
		return hash( 'sha256', $this->normalised_url() );
	}

	/**
	 * The normalised URL of this request: scheme and host of the home URL,
	 * the request path, and only the p, page_id, paged and product query args
	 * (blueprint section 11).
	 *
	 * @return string
	 */
	public function normalised_url(): string {
		$path = wp_parse_url( $this->request_uri(), PHP_URL_PATH );
		$args = array();
		foreach ( self::URL_ARGS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: these args only identify the page; nothing is changed.
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above; normalise_url() reduces the value to a number or a slug.
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		return self::normalise_url( is_string( $path ) ? $path : '/', $args );
	}

	/**
	 * Builds a normalised URL from a path and query args: scheme, host and
	 * port of the home URL, the path, and the p, page_id, paged (positive
	 * numbers) and product (a slug) args in a fixed order. Shared by the front
	 * end, the REST routes and WP-CLI, so one page always gets one hash.
	 *
	 * @param string $path Path, starting with "/".
	 * @param array  $args Raw query values by name; other names are ignored.
	 * @return string
	 */
	public static function normalise_url( string $path, array $args ): string {
		$home   = wp_parse_url( home_url( '/' ) );
		$scheme = isset( $home['scheme'] ) ? strtolower( $home['scheme'] ) : 'http';
		$host   = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		$port   = isset( $home['port'] ) ? ':' . (int) $home['port'] : '';
		$path   = '' === $path || '/' !== $path[0] ? '/' . $path : $path;

		$query = array();
		foreach ( array( 'p', 'page_id', 'paged' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$number = absint( $args[ $key ] );
				if ( $number > 0 ) {
					$query[ $key ] = $number;
				}
			}
		}
		if ( isset( $args['product'] ) && is_scalar( $args['product'] ) ) {
			$product = sanitize_title( (string) $args['product'] );
			if ( '' !== $product ) {
				$query['product'] = $product;
			}
		}

		ksort( $query );
		return $scheme . '://' . $host . $port . $path . ( empty( $query ) ? '' : '?' . http_build_query( $query ) );
	}

	/**
	 * Forgets the cached result (tests).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->passes = null;
		$this->reason = '';
		$this->mode   = null;
	}

	/**
	 * Whether a constant is defined and truthy. A method so tests can stand in
	 * for request-type constants that cannot be undefined once set.
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	protected function constant_is_true( string $name ): bool {
		return defined( $name ) && constant( $name );
	}

	/**
	 * The first condition that refuses the request, or '' when none does.
	 *
	 * @return string
	 */
	private function first_refusal(): string {
		if ( $this->kill_switch() ) {
			return 'disabled';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( wp_doing_cron() ) {
			return 'cron';
		}
		if ( $this->is_rest() ) {
			return 'rest';
		}
		if ( $this->constant_is_true( 'XMLRPC_REQUEST' ) ) {
			return 'xmlrpc';
		}
		if ( $this->constant_is_true( 'WP_CLI' ) ) {
			return 'cli';
		}
		if ( wp_is_json_request() ) {
			return 'json';
		}
		if ( 'GET' !== $this->request_method() ) {
			return 'method';
		}

		$conditions = array(
			'feed'      => is_feed(),
			'embed'     => is_embed(),
			'robots'    => is_robots(),
			'favicon'   => is_favicon(),
			'trackback' => is_trackback(),
			'customize' => is_customize_preview(),
			'amp'       => function_exists( 'amp_is_request' ) && amp_is_request(),
		);
		foreach ( $conditions as $reason => $applies ) {
			if ( $applies ) {
				return $reason;
			}
		}

		foreach ( self::BUILDER_ARGS as $arg ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; the value is never read.
			if ( isset( $_GET[ $arg ] ) ) {
				return 'builder';
			}
		}

		if ( $this->is_excluded_path() ) {
			return 'path';
		}

		/**
		 * Filters whether the repair engine runs for this request. Return false to skip it.
		 *
		 * @param bool $process Whether to process the request. Default true.
		 */
		if ( false === apply_filters( 'softglaze_a11y_should_process', true ) ) {
			return 'filter';
		}

		return '';
	}

	/**
	 * Whether this is a REST request, or a REST dispatch is running inside this request.
	 *
	 * @return bool
	 */
	public function is_rest(): bool {
		global $wp_rest_server;

		if ( wp_is_serving_rest_request() || $this->constant_is_true( 'REST_REQUEST' ) ) {
			return true;
		}
		// An internal rest_do_request() during a page: never instantiate the server here, only ask an existing one.
		return $wp_rest_server instanceof WP_REST_Server && $wp_rest_server->is_dispatching();
	}

	/**
	 * The HTTP method, upper-case, or '' when unknown.
	 *
	 * @return string
	 */
	private function request_method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}
		$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		return in_array( $method, array( 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ), true ) ? $method : '';
	}

	/**
	 * Whether the request path starts with one of the excluded prefixes.
	 *
	 * @return bool
	 */
	private function is_excluded_path(): bool {
		$prefixes = $this->settings->exclude_paths();
		if ( empty( $prefixes ) ) {
			return false;
		}
		$path = $this->request_path();
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The mode an administrator asked for with a valid nonce, otherwise
	 * visitor: a scan first, then the two admin views.
	 *
	 * @return string
	 */
	private function view_mode(): string {
		if ( $this->scan_requested() ) {
			return Engine::MODE_SCAN;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified below, after the capability check.
		if ( ! isset( $_GET[ self::VIEW_ARG ] ) ) {
			return Engine::MODE_VISITOR;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Allow-listed below; the nonce is verified below.
		$view = sanitize_key( wp_unslash( $_GET[ self::VIEW_ARG ] ) );
		if ( ! isset( self::VIEWS[ $view ] ) ) {
			return Engine::MODE_VISITOR;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return Engine::MODE_VISITOR;
		}
		$nonce = isset( $_GET[ self::VIEW_NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::VIEW_NONCE_ARG ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::VIEW_ACTION ) ) {
			return Engine::MODE_VISITOR;
		}
		return self::VIEWS[ $view ];
	}

	/**
	 * Whether this request is a scan (blueprint section 10.3): the scan arg
	 * is "1", the user can manage options (checked first), and the scan nonce
	 * is valid for this user's session. Anything else is an ordinary request.
	 *
	 * @return bool
	 */
	private function scan_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified below, after the capability check.
		if ( ! isset( $_GET[ self::SCAN_ARG ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compared with "1" below; the nonce is verified below.
		$scan = sanitize_key( wp_unslash( $_GET[ self::SCAN_ARG ] ) );
		if ( '1' !== $scan || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$nonce = isset( $_GET[ self::SCAN_NONCE_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SCAN_NONCE_ARG ] ) ) : '';
		return '' !== $nonce && false !== wp_verify_nonce( $nonce, self::SCAN_ACTION );
	}
}
