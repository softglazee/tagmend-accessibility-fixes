<?php
/**
 * REST routes for scan targets and report summaries.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two routes in softglaze-a11y/v1, both for users who can manage options
 * (blueprint section 10.3):
 *
 * - POST scan-targets: keeps only URLs on this site (scheme, host and port
 *   of home_url()) and returns each with the scan arg and a fresh nonce for
 *   that page only, so the administrator's browser can scan them; anything
 *   else is a 400. A target on a "Never repair these paths" prefix is
 *   marked excluded: the gate refuses it, so scanning it would store nothing.
 * - GET report?url=: the stored summary for one URL (counts only).
 *
 * The routes declare no required flag and no validate callback, and their
 * sanitize callbacks cannot fail, so the permission check always answers
 * first (core validates arguments before it checks permissions). Nothing is
 * written and no HTTP request is made.
 */
final class Rest_Controller {

	const ROUTE_NAMESPACE = 'softglaze-a11y/v1';

	/**
	 * Most URLs accepted in one scan-targets request.
	 */
	const MAX_TARGETS = 50;

	/**
	 * Scan links, URL checks and the report table.
	 *
	 * @var Report\Scanner
	 */
	private $scanner;

	/**
	 * Creates the controller.
	 *
	 * @param Report\Scanner $scanner Scan links, URL checks and the report table.
	 */
	public function __construct( Report\Scanner $scanner ) {
		$this->scanner = $scanner;
	}

	/**
	 * Hooks the routes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers both routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/scan-targets',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_targets' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'urls' => array(
						'description'       => __( 'Absolute URLs on this site to scan.', 'tagmend-accessibility-fixes' ),
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_url_list' ),
					),
				),
			)
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'report' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array(
						'description'       => __( 'Absolute URL of a page on this site.', 'tagmend-accessibility-fixes' ),
						'type'              => 'string',
						'sanitize_callback' => array( __CLASS__, 'sanitize_url_arg' ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback of both routes.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * POST scan-targets.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function scan_targets( WP_REST_Request $request ) {
		$urls = $request->get_param( 'urls' );
		$urls = is_array( $urls ) ? array_values( $urls ) : array();
		if ( empty( $urls ) || count( $urls ) > self::MAX_TARGETS ) {
			return new WP_Error(
				'softglaze_a11y_invalid_targets',
				sprintf(
					/* translators: %d: largest number of URLs in one request */
					__( 'Send between 1 and %d URLs.', 'tagmend-accessibility-fixes' ),
					self::MAX_TARGETS
				),
				array( 'status' => 400 )
			);
		}

		$targets  = array();
		$rejected = array();
		foreach ( $urls as $position => $url ) {
			$clean = $this->scanner->validate_target( $url );
			if ( '' === $clean ) {
				$rejected[] = $position;
				continue;
			}
			$targets[] = array(
				'url'      => $clean,
				'scan_url' => $this->scanner->scan_url( $clean ),
				'excluded' => $this->scanner->is_excluded( $clean ),
			);
		}
		if ( ! empty( $rejected ) ) {
			return new WP_Error(
				'softglaze_a11y_invalid_url',
				__( 'Only pages on this site can be scanned.', 'tagmend-accessibility-fixes' ),
				array(
					'status'   => 400,
					'rejected' => $rejected,
				)
			);
		}
		return rest_ensure_response( array( 'targets' => $targets ) );
	}

	/**
	 * GET report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function report( WP_REST_Request $request ) {
		$url = $this->scanner->validate_target( $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error(
				'softglaze_a11y_invalid_url',
				__( 'Only pages on this site have reports.', 'tagmend-accessibility-fixes' ),
				array( 'status' => 400 )
			);
		}
		$row = $this->scanner->report_for( $url );
		if ( null === $row ) {
			return new WP_Error(
				'softglaze_a11y_no_report',
				__( 'This page has not been scanned yet.', 'tagmend-accessibility-fixes' ),
				array( 'status' => 404 )
			);
		}
		return rest_ensure_response( Report\Scanner::summary( $row ) );
	}

	/**
	 * Sanitize callback for urls: a list of trimmed strings, never an error.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function sanitize_url_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$urls = array();
		foreach ( array_slice( array_values( $value ), 0, self::MAX_TARGETS + 1 ) as $url ) {
			$urls[] = self::sanitize_url_arg( $url );
		}
		return $urls;
	}

	/**
	 * Sanitize callback for url: a trimmed string of at most 2048 characters
	 * (validated after the permission check), never an error.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_url_arg( $value ): string {
		return is_string( $value ) ? substr( trim( $value ), 0, Report\Scanner::URL_MAX + 1 ) : '';
	}
}
