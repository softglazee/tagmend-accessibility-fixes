<?php
/**
 * Scan links, scan targets and the report of an authenticated request.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Report;

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Install;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Request_Gate;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds nonce'd scan links for same-origin URLs and collects what the
 * engine found on a scan or an admin view (blueprint section 10.3), then
 * hands the report to the stores on shutdown.
 *
 * Whether a request is a scan is decided by the request gate (capability,
 * then the softglaze_a11y_scan nonce bound to the page). This class makes no
 * HTTP request: a scan always runs inside the administrator's own browser
 * request.
 *
 * With the content filters (WordPress 6.5-6.8, the page pass switched off,
 * or another plugin stopping core's page buffer) the engine runs once per
 * fragment. A fragment can contain an earlier one (a template part holding a
 * filtered menu), so an earlier run found again inside a later fragment
 * keeps only the changes it applied; the later run reports the rest.
 */
final class Scanner {

	/**
	 * Longest URL accepted as a scan target.
	 */
	const URL_MAX = 2048;

	/**
	 * Why a report covers content fragments instead of the whole page.
	 */
	const REASON_PAGE_BUFFER_OFF = 'page_buffer_off';
	const REASON_NEEDS_WP69      = 'needs_wp69';
	const REASON_PAGE_PASS_OFF   = 'page_pass_off';
	const REASON_NO_PAGE_FIX     = 'no_page_fix';

	/**
	 * Request gate.
	 *
	 * @var Request_Gate
	 */
	private $gate;

	/**
	 * Report table.
	 *
	 * @var Pages_Store
	 */
	private $pages;

	/**
	 * Activity log.
	 *
	 * @var Log_Store
	 */
	private $log;

	/**
	 * Engine runs of this request.
	 *
	 * @var array[]
	 */
	private $runs = array();

	/**
	 * Changes made by attribute hooks in an admin view.
	 *
	 * @var array[]
	 */
	private $attribute_changes = array();

	/**
	 * Scope of this request's report.
	 *
	 * @var string
	 */
	private $scope = '';

	/**
	 * Why the report covers content fragments ('' for the whole page).
	 *
	 * @var string
	 */
	private $scope_reason = '';

	/**
	 * Creates the scanner.
	 *
	 * @param Request_Gate $gate  Request gate.
	 * @param Pages_Store  $pages Report table.
	 * @param Log_Store    $log   Activity log.
	 */
	public function __construct( Request_Gate $gate, Pages_Store $pages, Log_Store $log ) {
		$this->gate  = $gate;
		$this->pages = $pages;
		$this->log   = $log;
	}

	/**
	 * The report table.
	 *
	 * @return Pages_Store
	 */
	public function pages(): Pages_Store {
		return $this->pages;
	}

	/**
	 * The activity log.
	 *
	 * @return Log_Store
	 */
	public function log(): Log_Store {
		return $this->log;
	}

	/**
	 * A URL on this site that may be scanned, cleaned, or '' when it is not
	 * one: scheme (http or https), host and port equal to home_url()'s, no
	 * user information, a path inside the home path and outside wp-admin and
	 * wp-login.php, no whitespace, control characters or backslashes. The
	 * fragment and our own scan and view args are removed.
	 *
	 * @param mixed $url Candidate.
	 * @return string
	 */
	public function validate_target( $url ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::URL_MAX || 1 === preg_match( '/[\s\x00-\x1F\x7F\\\\]/', $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if (
			! in_array( $scheme, array( 'http', 'https' ), true )
			|| strtolower( $home['scheme'] ) !== $scheme
			|| strtolower( $home['host'] ) !== strtolower( $parts['host'] )
			|| self::port( $parts ) !== self::port( $home )
		) {
			return '';
		}

		$path      = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$home_path = isset( $home['path'] ) ? trailingslashit( $home['path'] ) : '/';
		if ( 0 !== strpos( trailingslashit( $path ), $home_path ) || 1 === preg_match( '#(^|/)(wp-admin|wp-login\.php)(/|$)#i', $path ) ) {
			return '';
		}

		$without_fragment = explode( '#', $url, 2 );
		$clean            = remove_query_arg( array( Request_Gate::SCAN_ARG, Request_Gate::SCAN_NONCE_ARG, Request_Gate::VIEW_ARG, Request_Gate::VIEW_NONCE_ARG ), $without_fragment[0] );
		return (string) esc_url_raw( $clean, array( 'http', 'https' ) );
	}

	/**
	 * Whether a URL from validate_target() is on a "Never repair these paths"
	 * prefix. The gate refuses such requests, so a scan of one stores nothing.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return bool
	 */
	public function is_excluded( string $url ): bool {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return $this->gate->path_is_excluded( Request_Gate::relative_path( is_string( $path ) ? $path : '/' ) );
	}

	/**
	 * A scan link: the URL with softglaze_a11y_scan=1 and a fresh nonce for
	 * the current user's session and this page only (the nonce action holds
	 * the page's URL hash, Request_Gate::scan_nonce_action()).
	 *
	 * @param string $url Absolute URL on this site, from validate_target().
	 * @return string
	 */
	public function scan_url( string $url ): string {
		$url = remove_query_arg( array( Request_Gate::SCAN_ARG, Request_Gate::SCAN_NONCE_ARG, Request_Gate::VIEW_ARG, Request_Gate::VIEW_NONCE_ARG ), $url );
		return (string) esc_url_raw(
			add_query_arg(
				array(
					Request_Gate::SCAN_ARG       => '1',
					Request_Gate::SCAN_NONCE_ARG => wp_create_nonce( Request_Gate::scan_nonce_action( Request_Gate::url_hash_of( $url ) ) ),
				),
				$url
			)
		);
	}

	/**
	 * The normalised form of a URL from validate_target(), as stored and hashed (blueprint section 11).
	 *
	 * @param string $url Absolute URL on this site.
	 * @return string
	 */
	public function normalise_target( string $url ): string {
		return Request_Gate::normalised_url_of( $url );
	}

	/**
	 * The stored report of a URL from validate_target(), or null.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array|null
	 */
	public function report_for( string $url ) {
		return $this->pages->get_by_hash( hash( 'sha256', $this->normalise_target( $url ) ) );
	}

	/**
	 * Records which scope this request's report covers.
	 *
	 * @param string $scope  Engine::SCOPE_PAGE or Engine::SCOPE_CONTENT.
	 * @param string $reason One of the REASON_* constants for content scope, '' for the page.
	 * @return void
	 */
	public function set_scope( string $scope, string $reason = '' ): void {
		$this->scope        = $scope;
		$this->scope_reason = Engine::SCOPE_PAGE === $scope ? '' : $reason;
	}

	/**
	 * Scope of this request's report.
	 *
	 * @return array 'scope' and 'reason'.
	 */
	public function scope(): array {
		return array(
			'scope'  => $this->scope,
			'reason' => $this->scope_reason,
		);
	}

	/**
	 * Keeps what one engine run found.
	 *
	 * @param Recorder $recorder The run's recorder.
	 * @param string   $input    Input of the run.
	 * @param string   $output   Output of the run.
	 * @param string   $scope    Scope of the run.
	 * @return void
	 */
	public function add_run( Recorder $recorder, string $input, string $output, string $scope ): void {
		if ( Engine::SCOPE_CONTENT === $scope ) {
			$length = strlen( $input );
			foreach ( $this->runs as $i => $run ) {
				if ( $run['contained'] || '' === $run['output'] || strlen( $run['output'] ) >= $length || false === strpos( $input, $run['output'] ) ) {
					continue;
				}
				$this->runs[ $i ]['contained'] = true;
				$this->runs[ $i ]['output']    = '';
				$this->runs[ $i ]['findings']  = array();
				$this->runs[ $i ]['changes']   = array_values(
					array_filter(
						$run['changes'],
						static function ( $change ) {
							return ! empty( $change['applied'] );
						}
					)
				);
			}
		}

		$facts        = $recorder->engine();
		$this->runs[] = array(
			'output'    => Engine::SCOPE_CONTENT === $scope ? $output : '',
			'contained' => false,
			'changes'   => $recorder->changes(),
			'findings'  => $recorder->findings(),
			'ms'        => isset( $facts['ms'] ) ? (float) $facts['ms'] : 0.0,
			'bytes'     => isset( $facts['bytes'] ) ? (int) $facts['bytes'] : strlen( $input ),
			'fallback'  => $recorder->get_fallback(),
		);
	}

	/**
	 * Keeps a change an attribute hook made in an admin view.
	 *
	 * @param string      $fix       Fix id.
	 * @param string      $state     Fix state.
	 * @param string      $signature Element signature.
	 * @param string      $attribute Attribute.
	 * @param string|null $before    Value before, null when absent.
	 * @param string      $after     Value after.
	 * @return void
	 */
	public function add_attribute_change( string $fix, string $state, string $signature, string $attribute, $before, string $after ): void {
		$this->attribute_changes[] = array(
			'fix'     => $fix,
			'state'   => $state,
			'sig'     => $signature,
			'attr'    => $attribute,
			'before'  => is_string( $before ) ? $before : null,
			'after'   => $after,
			'applied' => true,
		);
	}

	/**
	 * Number of engine runs kept on this request.
	 *
	 * @return int
	 */
	public function run_count(): int {
		return count( $this->runs );
	}

	/**
	 * The merged result of this request (blueprint section 11), before the store's privacy pass.
	 *
	 * @return array
	 */
	public function result(): array {
		$changes   = $this->attribute_changes;
		$findings  = array();
		$fallbacks = array();
		$ms        = 0.0;
		$bytes     = 0;
		foreach ( $this->runs as $run ) {
			$ms += $run['ms'];
			if ( ! $run['contained'] ) {
				$bytes += $run['bytes'];
			}
			if ( null !== $run['fallback'] ) {
				$fallbacks[] = (string) $run['fallback'];
				continue;
			}
			foreach ( $run['changes'] as $change ) {
				$changes[] = $change;
			}
			foreach ( $run['findings'] as $finding ) {
				$findings[] = $finding;
			}
		}
		return self::shape( $changes, $findings, $fallbacks, $ms, $bytes, count( $this->runs ), $this->scope, $this->scope_reason, $this->gate->mode() );
	}

	/**
	 * The result of a single run (WP-CLI), in the stored shape.
	 *
	 * @param Recorder $recorder The run's recorder.
	 * @param string   $scope    Scope of the run.
	 * @return array
	 */
	public static function result_of_run( Recorder $recorder, string $scope ): array {
		$facts    = $recorder->engine();
		$fallback = $recorder->get_fallback();
		return self::shape(
			null === $fallback ? $recorder->changes() : array(),
			null === $fallback ? $recorder->findings() : array(),
			null === $fallback ? array() : array( (string) $fallback ),
			isset( $facts['ms'] ) ? (float) $facts['ms'] : 0.0,
			isset( $facts['bytes'] ) ? (int) $facts['bytes'] : 0,
			1,
			$scope,
			'',
			Engine::MODE_SCAN
		);
	}

	/**
	 * Stores the report of the current request. Called on shutdown in scan
	 * and admin_repaired mode; checks the capability again before writing.
	 *
	 * @param string $source 'admin_scan' or 'admin_view'.
	 * @return bool Whether a row was written.
	 */
	public function store_request_report( string $source ): bool {
		if ( ! in_array( $source, array( 'admin_scan', 'admin_view' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// Nothing ran: a redirect or an exit before the template, or a page pass that never received the page.
		$rendered = Engine::SCOPE_CONTENT === $this->scope && did_action( 'wp_footer' ) > 0;
		if ( empty( $this->runs ) && empty( $this->attribute_changes ) && ! $rendered ) {
			return false;
		}
		if ( ! Install::ready() ) {
			Install::create_tables();
		}

		$url    = $this->gate->normalised_url();
		$hash   = hash( 'sha256', $url );
		$object = self::queried_object();
		$result = $this->result();
		$saved  = $this->pages->save(
			array(
				'url'         => $url,
				'url_hash'    => $hash,
				'object_id'   => $object['id'],
				'object_type' => $object['type'],
				'source'      => $source,
				'result'      => $result,
			)
		);
		if ( $saved && 'admin_scan' === $source ) {
			$this->scan_done( $url, $hash, $source, $result );
		}
		return $saved;
	}

	/**
	 * Stores a WP-CLI analysis for a URL from validate_target().
	 *
	 * @param string $url    Absolute URL on this site.
	 * @param array  $result Result from result_of_run().
	 * @return bool Whether a row was written.
	 */
	public function store_cli_report( string $url, array $result ): bool {
		if ( ! Install::ready() ) {
			Install::create_tables();
		}
		$normalised = $this->normalise_target( $url );
		$hash       = hash( 'sha256', $normalised );
		$post_id    = url_to_postid( $url );
		$saved      = $this->pages->save(
			array(
				'url'         => $normalised,
				'url_hash'    => $hash,
				'object_id'   => $post_id,
				'object_type' => $post_id > 0 ? (string) get_post_type( $post_id ) : '',
				'source'      => 'cli',
				'result'      => $result,
			)
		);
		if ( $saved ) {
			$this->scan_done( $normalised, $hash, 'cli', $result );
		}
		return $saved;
	}

	/**
	 * Summary of a stored report row: counts only, no element signatures.
	 *
	 * @param array $row Row from Pages_Store::get_by_hash().
	 * @return array
	 */
	public static function summary( array $row ): array {
		$engine = isset( $row['result']['engine'] ) && is_array( $row['result']['engine'] ) ? $row['result']['engine'] : array();
		return array(
			'url'          => isset( $row['url'] ) ? (string) $row['url'] : '',
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
			'scanned_gmt'  => isset( $row['scanned_gmt'] ) ? (string) $row['scanned_gmt'] : '',
			'changes'      => array(
				'live'    => isset( $row['changes_live'] ) ? (int) $row['changes_live'] : 0,
				'preview' => isset( $row['changes_preview'] ) ? (int) $row['changes_preview'] : 0,
				'off'     => isset( $row['changes_off'] ) ? (int) $row['changes_off'] : 0,
			),
			'findings'     => isset( $row['findings'] ) ? (int) $row['findings'] : 0,
			'scope'        => isset( $engine['scope'] ) ? (string) $engine['scope'] : '',
			'scope_reason' => isset( $engine['scope_reason'] ) && '' !== $engine['scope_reason'] ? (string) $engine['scope_reason'] : null,
			'fallback'     => isset( $engine['fallback'] ) && '' !== $engine['fallback'] ? (string) $engine['fallback'] : null,
		);
	}

	/**
	 * Forgets this request's runs (tests).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->runs              = array();
		$this->attribute_changes = array();
		$this->scope             = '';
		$this->scope_reason      = '';
	}

	/**
	 * Logs a stored scan and fires softglaze_a11y_scan_completed.
	 *
	 * @param string $url    Normalised URL.
	 * @param string $hash   URL hash.
	 * @param string $source Report source.
	 * @param array  $result Stored result.
	 * @return void
	 */
	private function scan_done( string $url, string $hash, string $source, array $result ): void {
		$stripped = Pages_Store::privacy_strip( $result );
		$totals   = array(
			'live'    => 0,
			'preview' => 0,
			'off'     => 0,
		);
		foreach ( $stripped['counts'] as $states ) {
			foreach ( $totals as $state => $sum ) {
				$totals[ $state ] = $sum + $states[ $state ];
			}
		}
		$summary = array(
			'source'       => $source,
			'changes'      => $totals,
			'findings'     => $stripped['totals']['findings'],
			'scope'        => (string) $stripped['engine']['scope'],
			'scope_reason' => '' === (string) $stripped['engine']['scope_reason'] ? null : (string) $stripped['engine']['scope_reason'],
			'fallback'     => '' === (string) $stripped['engine']['fallback'] ? null : (string) $stripped['engine']['fallback'],
		);
		$this->log->add(
			'scan',
			array(
				'user_id'  => 'cli' === $source ? 0 : get_current_user_id(),
				'url_hash' => $hash,
				'detail'   => sprintf(
					'source=%1$s live=%2$d preview=%3$d off=%4$d findings=%5$d scope=%6$s%7$s',
					$source,
					$totals['live'],
					$totals['preview'],
					$totals['off'],
					$summary['findings'],
					$summary['scope'],
					null === $summary['scope_reason'] ? '' : ' reason=' . $summary['scope_reason']
				),
			)
		);

		try {
			/**
			 * Fires after a scan report was stored (admin scan or WP-CLI).
			 *
			 * @param string $url     Normalised URL of the page.
			 * @param array  $summary Counts: source, changes (live, preview, off), findings, scope, scope_reason, fallback.
			 */
			do_action( 'softglaze_a11y_scan_completed', $url, $summary );
		} catch ( Throwable $throwable ) {
			return;
		}
	}

	/**
	 * Builds a result in the stored shape.
	 *
	 * @param array    $changes      Changes.
	 * @param array    $findings     Findings.
	 * @param string[] $fallbacks    Fallback reasons.
	 * @param float    $ms           Engine time.
	 * @param int      $bytes        Bytes analysed.
	 * @param int      $runs         Engine runs.
	 * @param string   $scope        Scope.
	 * @param string   $scope_reason Reason for content scope.
	 * @param string   $mode         Mode.
	 * @return array
	 */
	private static function shape( array $changes, array $findings, array $fallbacks, float $ms, int $bytes, int $runs, string $scope, string $scope_reason, string $mode ): array {
		$counts = array();
		foreach ( $changes as $change ) {
			$fix   = isset( $change['fix'] ) ? (string) $change['fix'] : '';
			$state = isset( $change['state'] ) ? (string) $change['state'] : '';
			if ( '' === $fix || ! in_array( $state, Engine::STATES, true ) ) {
				continue;
			}
			if ( ! isset( $counts[ $fix ] ) ) {
				$counts[ $fix ] = array_fill_keys( Engine::STATES, 0 );
			}
			++$counts[ $fix ][ $state ];
		}
		$fallbacks = array_values( array_unique( $fallbacks ) );
		return array(
			'engine'   => array(
				'scope'        => $scope,
				'scope_reason' => '' === $scope_reason ? null : $scope_reason,
				'mode'         => $mode,
				'ms'           => round( $ms, 3 ),
				'bytes'        => $bytes,
				'runs'         => $runs,
				'fallback'     => empty( $fallbacks ) ? null : $fallbacks[0],
				'fallbacks'    => $fallbacks,
				'wp'           => get_bloginfo( 'version' ),
				'version'      => SOFTGLAZE_A11Y_VERSION,
			),
			'counts'   => $counts,
			'changes'  => array_slice( $changes, 0, Pages_Store::LIST_LIMIT ),
			'findings' => array_slice( $findings, 0, Pages_Store::LIST_LIMIT ),
			'totals'   => array(
				'changes'  => count( $changes ),
				'findings' => count( $findings ),
			),
		);
	}

	/**
	 * The queried object of the current request, as stored with a report.
	 *
	 * @return array 'id' (int) and 'type' (post type, 'term', 'home' or '').
	 */
	private static function queried_object(): array {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			return array(
				'id'   => $id,
				'type' => (string) get_post_type( $id ),
			);
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return array(
				'id'   => (int) get_queried_object_id(),
				'type' => 'term',
			);
		}
		if ( is_front_page() || is_home() ) {
			return array(
				'id'   => 0,
				'type' => 'home',
			);
		}
		return array(
			'id'   => 0,
			'type' => '',
		);
	}

	/**
	 * Port of a parsed URL, with the scheme's default.
	 *
	 * @param array $parts wp_parse_url() result.
	 * @return int
	 */
	private static function port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}
		return isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
	}
}
