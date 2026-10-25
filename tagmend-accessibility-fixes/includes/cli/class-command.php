<?php
/**
 * WP-CLI commands: wp softglaze-a11y.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Cli;

use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Html_Lang;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Iframe_Title;
use SoftGlaze\AccessibilityRepair\Fixes\Fix_Viewport_Zoom;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyses saved HTML with the repair engine.
 */
final class Command {

	/**
	 * Files larger than this are refused before reading.
	 */
	const MAX_FILE_BYTES = 10485760;

	/**
	 * Analyses a saved HTML file in scan mode and lists what each fix would change. Makes no request and writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path of a local HTML file.
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
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function analyse( $args, $assoc_args ) {
		unset( $args );
		$path   = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$scope  = isset( $assoc_args['scope'] ) && Engine::SCOPE_CONTENT === $assoc_args['scope'] ? Engine::SCOPE_CONTENT : Engine::SCOPE_PAGE;
		$format = isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ? 'json' : 'table';

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

		// TODO(Phase 1B): fixes and saved states from the Registry (softglaze_a11y_register_fixes, softglaze_a11y_settings).
		$fixes  = self::fixes();
		$engine = new Engine( $fixes, Engine::default_states( $fixes ) );
		$engine->run( $html, Engine::MODE_SCAN, $scope );
		// TODO(Phase 6A): --url and --save record the result as a report with source "cli".
		$result = $engine->recorder()->to_array();

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
		WP_CLI::line( sprintf( 'Changes: %d', count( $rows ) ) );
		if ( ! empty( $rows ) ) {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'fix', 'state', 'element', 'attribute', 'before', 'after' ) );
		}

		WP_CLI::line( sprintf( 'Findings: %d', count( $result['findings'] ) ) );
		if ( ! empty( $result['findings'] ) ) {
			\WP_CLI\Utils\format_items( 'table', $result['findings'], array( 'check', 'sc', 'sig', 'detail' ) );
		}
	}

	/**
	 * The bundled fixes built so far.
	 *
	 * @return \SoftGlaze\AccessibilityRepair\Fix[]
	 */
	private static function fixes(): array {
		return array(
			new Fix_Iframe_Title(),
			new Fix_Html_Lang(),
			new Fix_Viewport_Zoom(),
		);
	}
}
