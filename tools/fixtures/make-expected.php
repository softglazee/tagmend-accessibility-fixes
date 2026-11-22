<?php
/**
 * Prints the engine output for one captured fixture, every bundled fix Live,
 * page scope, visitor mode, with the site's real Media Library. Used to
 * (re)write tests/fixtures/expected/ and tests/fixtures/edge/*.expected.html.
 *
 * Usage (workspace root, Git Bash):
 *   MSYS_NO_PATHCONV=1 docker exec sgl-p3-cli wp eval-file /fixtures/tools/make-expected.php /fixtures/<slug>.html > tests/fixtures/expected/<slug>.html
 * (copy this file to dev/fixtures-share/tools/ first). Run it on the dev site
 * (8730), whose attachments 6-11 the PHPUnit lookup double mirrors. Review
 * every diff by hand before committing a changed expectation.
 *
 * @package SoftGlaze\AccessibilityRepair\Tools
 */

use SoftGlaze\AccessibilityRepair\Attachment_Lookup;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Plugin;

if ( empty( $args[0] ) || ! is_readable( $args[0] ) ) {
	WP_CLI::error( 'Usage: wp eval-file make-expected.php <fixture.html>' );
}

$softglaze_a11y_fixes  = Plugin::instance()->registry()->fixes();
$softglaze_a11y_states = array_fill_keys( array_keys( $softglaze_a11y_fixes ), 'live' );
// Image URLs are looked up as in an authenticated request (Phase 8B C3), like the PHPUnit lookup double.
$softglaze_a11y_engine = new Engine( $softglaze_a11y_fixes, $softglaze_a11y_states, array( 'attachments' => new Attachment_Lookup( true ) ) );
$softglaze_a11y_output = $softglaze_a11y_engine->run( file_get_contents( $args[0] ), Engine::MODE_VISITOR, Engine::SCOPE_PAGE );

if ( null !== $softglaze_a11y_engine->recorder()->get_fallback() ) {
	WP_CLI::error( 'Engine fell back: ' . $softglaze_a11y_engine->recorder()->get_fallback() );
}
echo $softglaze_a11y_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw HTML written to a test fixture.
