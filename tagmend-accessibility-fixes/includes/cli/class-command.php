<?php
/**
 * WP-CLI commands: wp softglaze-a11y.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Cli;

use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Plugin;
use SoftGlaze\AccessibilityRepair\Report\Pages_Store;
use SoftGlaze\AccessibilityRepair\Report\Scanner;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fix states, analysis of saved HTML with the repair engine, and stored
 * reports. No command makes an HTTP request.
 */
final class Command {

	/**
	 * Files larger than this are refused before reading.
	 */
	const MAX_FILE_BYTES = 10485760;

	/**
	 * Analyses a saved HTML file in scan mode and lists what each fix would change. Makes no request; writes only with --save.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path of a local HTML file.
	 *
	 * [--url=<url>]
	 * : Address of the page the file was saved from, on this site. Needed with --save. This is WP-CLI's global --url, which also selects the site on a multisite network.
	 *
	 * [--save]
	 * : Store the result as the report of --url (source "cli").
	 *
	 * [--scope=<scope>]
	 * : Whether the file is a whole page or a content fragment.
	 * ---
	 * default: page
	 * options:
	 *   - page
	 *   - content
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp softglaze-a11y analyse --file=/tmp/home.html
	 *     wp softglaze-a11y analyse --file=/tmp/home.html --format=json
	 *     wp softglaze-a11y analyse --file=/tmp/home.html --url=https://example.com/ --save
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function analyse( $args, $assoc_args ) {
		unset( $args );
		$path    = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$scope   = isset( $assoc_args['scope'] ) && Engine::SCOPE_CONTENT === $assoc_args['scope'] ? Engine::SCOPE_CONTENT : Engine::SCOPE_PAGE;
		$format  = isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ? 'json' : 'table';
		$save    = ! empty( $assoc_args['save'] );
		$plugin  = Plugin::instance();
		$scanner = $plugin->scanner();
		$url     = '';

		// WP-CLI takes --url as a global parameter, so the value is read from its configuration.
		$given = isset( $assoc_args['url'] ) ? (string) $assoc_args['url'] : (string) WP_CLI::get_config( 'url' );
		if ( '' !== $given ) {
			$url = $scanner->validate_target( $given );
			if ( '' === $url ) {
				WP_CLI::error( 'Not an address on this site: ' . $given );
			}
		}
		if ( $save && '' === $url ) {
			WP_CLI::error( '--save needs --url, the address of the page the file was saved from.' );
		}

		if ( '' === $path || false !== strpos( $path, '://' ) || wp_is_stream( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			WP_CLI::error( 'Not a readable local file: ' . $path );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_FILE_BYTES ) {
			WP_CLI::error( 'The file is larger than 10 MB.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local file named on the command line; URLs and stream wrappers are refused above.
		$html = file_get_contents( $path );
		if ( false === $html ) {
			WP_CLI::error( 'The file could not be read: ' . $path );
		}

		$engine = new Engine(
			$plugin->registry()->fixes(),
			$plugin->settings()->fix_states(),
			array(
				'checks'      => $plugin->registry()->checks(),
				'attachments' => new Attachment_Lookup( true ),
			)
		);
		$engine->run( $html, Engine::MODE_SCAN, $scope );
		$result = $engine->recorder()->to_array();

		if ( $save ) {
			if ( ! $scanner->store_cli_report( $url, Scanner::result_of_run( $engine->recorder(), $scope ) ) ) {
				WP_CLI::error( 'The report could not be stored.' );
			}
		}

		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$facts = $result['engine'];
		WP_CLI::line(
			sprintf(
				'Engine: %1$s scope, %2$d bytes, %3$s ms, %4$d tokens, %5$d openers, fallback: %6$s',
				$facts['scope'],
				$facts['bytes'],
				$facts['ms'],
				$facts['tokens'],
				$facts['openers'],
				null === $facts['fallback'] ? 'none' : $facts['fallback']
			)
		);
		self::print_lists( $result );
		if ( $save ) {
			WP_CLI::success( 'Report stored for ' . $scanner->normalise_target( $url ) . ' (source cli).' );
		}
	}

	/**
	 * Shows the stored report of one page: when and how it was made, the changes and the findings.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Address of a page on this site.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp softglaze-a11y report https://example.com/?page_id=2
	 *     wp softglaze-a11y report https://example.com/ --format=json
	 *
	 * @param array $args       Positional arguments: the URL.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function report( $args, $assoc_args ) {
		$scanner = Plugin::instance()->scanner();
		$given   = isset( $args[0] ) ? (string) $args[0] : '';
		$url     = $scanner->validate_target( $given );
		if ( '' === $url ) {
			WP_CLI::error( 'Not an address on this site: ' . $given );
		}
		$row = $scanner->report_for( $url );
		if ( null === $row ) {
			WP_CLI::error( 'No report for ' . $scanner->normalise_target( $url ) . '. Use "Scan this page" in the admin bar, or wp softglaze-a11y analyse --save.' );
		}

		$summary = Scanner::summary( $row );
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'summary' => $summary,
						'result'  => $row['result'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
			return;
		}

		WP_CLI::line( 'URL: ' . $summary['url'] );
		WP_CLI::line( sprintf( 'Stored: %1$s UTC, source %2$s', $summary['scanned_gmt'], $summary['source'] ) );
		WP_CLI::line(
			sprintf(
				'Scope: %1$s%2$s; fallback: %3$s',
				$summary['scope'],
				null === $summary['scope_reason'] ? '' : ' (' . $summary['scope_reason'] . ')',
				null === $summary['fallback'] ? 'none' : $summary['fallback']
			)
		);
		WP_CLI::line( sprintf( 'Changes: %1$d Live, %2$d Preview, %3$d Off (would change); findings: %4$d', $summary['changes']['live'], $summary['changes']['preview'], $summary['changes']['off'], $summary['findings'] ) );
		self::print_lists( $row['result'] );
	}

	/**
	 * Lists the stored reports, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Most rows shown.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp softglaze-a11y pages
	 *     wp softglaze-a11y pages --limit=10 --format=json
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function pages( $args, $assoc_args ) {
		unset( $args );
		$pages  = Plugin::instance()->pages();
		$limit  = isset( $assoc_args['limit'] ) ? max( 1, absint( $assoc_args['limit'] ) ) : 50;
		$format = isset( $assoc_args['format'] ) && in_array( $assoc_args['format'], array( 'json', 'csv' ), true ) ? $assoc_args['format'] : 'table';

		$rows = array();
		foreach ( $pages->recent( $limit ) as $row ) {
			$rows[] = array(
				'url'      => $row['url'],
				'scanned'  => $row['scanned_gmt'],
				'source'   => $row['source'],
				'live'     => (int) $row['changes_live'],
				'preview'  => (int) $row['changes_preview'],
				'off'      => (int) $row['changes_off'],
				'findings' => (int) $row['findings'],
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'url', 'scanned', 'source', 'live', 'preview', 'off', 'findings' ) );
		if ( 'table' === $format ) {
			WP_CLI::line( sprintf( 'Reports stored: %1$d (kept: %2$d)', $pages->count(), $pages->cap() ) );
		}
	}

	/**
	 * Prints the change and finding tables of a result.
	 *
	 * @param array $result Result with 'changes' and 'findings'.
	 * @return void
	 */
	private static function print_lists( array $result ): void {
		$rows = array();
		foreach ( $result['changes'] as $change ) {
			$rows[] = array(
				'fix'       => $change['fix'],
				'state'     => $change['state'],
				'element'   => $change['sig'],
				'attribute' => $change['attr'],
				'before'    => null === $change['before'] ? '(none)' : $change['before'],
				'after'     => $change['after'],
			);
		}
		WP_CLI::line( sprintf( 'Changes listed: %d', count( $rows ) ) );
		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'fix', 'state', 'element', 'attribute', 'before', 'after' ) );
		}

		$findings = array();
		foreach ( $result['findings'] as $finding ) {
			$findings[] = array(
				'check'      => $finding['check'],
				'sc'         => $finding['sc'],
				'element'    => $finding['sig'],
				'attachment' => isset( $finding['attachment'] ) ? $finding['attachment'] : '',
				'detail'     => $finding['detail'],
			);
		}
		WP_CLI::line( sprintf( 'Findings listed: %d', count( $findings ) ) );
		if ( ! empty( $findings ) ) {
			\WP_CLI\Utils\format_items( 'table', $findings, array( 'check', 'sc', 'element', 'attachment', 'detail' ) );
		}
	}

	/**
	 * Lists every fix with its saved state and whether it can run on this site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp softglaze-a11y status
	 *     wp softglaze-a11y status --format=json
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$plugin   = Plugin::instance();
		$registry = $plugin->registry();
		$states   = $plugin->settings()->fix_states();
		$format   = isset( $assoc_args['format'] ) && in_array( $assoc_args['format'], array( 'json', 'csv' ), true ) ? $assoc_args['format'] : 'table';

		$rows = array();
		foreach ( $registry->fixes() as $id => $fix ) {
			$reason = $registry->unavailable_reason( $fix );
			$rows[] = array(
				'id'          => $id,
				'state'       => isset( $states[ $id ] ) ? $states[ $id ] : 'off',
				'available'   => '' === $reason ? 'yes' : $reason,
				'scopes'      => implode( ',', $fix->scopes() ),
				'recommended' => $fix->recommended() ? 'yes' : 'no',
				'label'       => $fix->label(),
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'state', 'available', 'scopes', 'recommended', 'label' ) );

		if ( 'table' === $format ) {
			// The same reading as the Overview: another plugin switching off the page buffer shows only in scan and "View repaired" reports.
			$status = $plugin->pages()->page_pass_status( $plugin->settings()->page_scope_enabled() );
			switch ( $status['status'] ) {
				case Pages_Store::PAGE_PASS_NEEDS_WP69:
					$page_pass = 'Needs WordPress 6.9 or later';
					break;
				case Pages_Store::PAGE_PASS_SWITCHED_OFF:
					$page_pass = 'switched off in Settings';
					break;
				case Pages_Store::PAGE_PASS_VETOED:
					$page_pass = sprintf( 'switched off by another plugin on the last scanned pages (report of %1$s, %2$s UTC)', $status['url'], $status['scanned_gmt'] );
					break;
				default:
					$page_pass = $status['confirmed']
						? sprintf( 'available (report of %1$s, %2$s UTC, covers the whole page)', $status['url'], $status['scanned_gmt'] )
						: 'available (not confirmed by a scan yet)';
			}
			WP_CLI::line( 'Whole-page pass: ' . $page_pass );
		}
	}

	/**
	 * Sets the state of one or more fixes.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>]
	 * : One fix id, or several separated by commas.
	 *
	 * [--all]
	 * : Every registered fix. With --state=live, fixes that cannot run on this site are skipped with a warning.
	 *
	 * --state=<state>
	 * : The new state: off, preview or live.
	 *
	 * ## EXAMPLES
	 *
	 *     wp softglaze-a11y fix iframe-title,html-lang --state=live
	 *     wp softglaze-a11y fix --all --state=preview
	 *
	 * @param array $args       Positional arguments: the ids.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function fix( $args, $assoc_args ) {
		$plugin   = Plugin::instance();
		$registry = $plugin->registry();
		$settings = $plugin->settings();
		$state    = isset( $assoc_args['state'] ) ? (string) $assoc_args['state'] : '';
		$all      = ! empty( $assoc_args['all'] );
		$listed   = isset( $args[0] ) && '' !== trim( (string) $args[0] );

		if ( ! in_array( $state, Engine::STATES, true ) ) {
			WP_CLI::error( 'Unknown state. Use off, preview or live.' );
		}
		if ( $all === $listed ) {
			WP_CLI::error( 'Name one or more fix ids, or use --all (not both).' );
		}

		$ids     = $all ? $registry->ids() : array_values( array_filter( array_map( 'trim', explode( ',', (string) $args[0] ) ) ) );
		$unknown = array_diff( $ids, $registry->ids() );
		if ( ! empty( $unknown ) ) {
			WP_CLI::error( 'Unknown fix id: ' . implode( ', ', $unknown ) . '. Nothing was changed. Run "wp softglaze-a11y status" for the list.' );
		}

		$before = $settings->fix_states();
		$wanted = array();
		foreach ( $ids as $id ) {
			$reason = $registry->unavailable_reason( $registry->get( $id ) );
			if ( 'live' === $state && '' !== $reason && 'live' !== $before[ $id ] ) {
				if ( ! $all ) {
					WP_CLI::error( $id . ': ' . $reason . '. Nothing was changed.' );
				}
				WP_CLI::warning( $id . ': ' . $reason . '. Skipped.' );
				continue;
			}
			$wanted[ $id ] = $state;
		}

		$rejected = empty( $wanted ) ? array() : $settings->save_fix_states( $wanted, 0 );
		$after    = $settings->fix_states();
		$changed  = 0;
		foreach ( array_keys( $wanted ) as $id ) {
			if ( $before[ $id ] !== $after[ $id ] ) {
				WP_CLI::line( sprintf( '%1$s: %2$s -> %3$s', $id, $before[ $id ], $after[ $id ] ) );
				++$changed;
			}
		}
		foreach ( $rejected as $entry ) {
			WP_CLI::warning( sprintf( 'Refused: %1$s (%2$s).', $entry['id'], $entry['reason'] ) );
		}
		if ( ! empty( $rejected ) ) {
			WP_CLI::error( 'Some states were refused and kept their saved value.' );
		}
		WP_CLI::success( sprintf( '%d fix state(s) changed.', $changed ) );
	}
}
