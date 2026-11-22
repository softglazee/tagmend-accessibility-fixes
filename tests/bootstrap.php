<?php
/**
 * PHPUnit bootstrap: picks the WordPress test library named by WP_TESTS_VERSION
 * (7.1.3 or 6.5.13, exported into dev/wp-tests/), loads the plugin on
 * muplugins_loaded, then hands over to the library's own bootstrap.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

$softglaze_a11y_tests_root    = dirname( __DIR__ );
$softglaze_a11y_tests_version = getenv( 'WP_TESTS_VERSION' ) ? getenv( 'WP_TESTS_VERSION' ) : '7.1.3';
$softglaze_a11y_tests_configs = array(
	'7.1.3'  => 'wp-tests-config-71.php',
	'6.5.13' => 'wp-tests-config-65.php',
);

if ( ! isset( $softglaze_a11y_tests_configs[ $softglaze_a11y_tests_version ] ) ) {
	fwrite( STDERR, 'Unknown WP_TESTS_VERSION "' . $softglaze_a11y_tests_version . '"; use 7.1.3 or 6.5.13.' . PHP_EOL );
	exit( 1 );
}

$softglaze_a11y_tests_lib = $softglaze_a11y_tests_root . '/dev/wp-tests/' . $softglaze_a11y_tests_version . '/tests/phpunit';
if ( ! is_readable( $softglaze_a11y_tests_lib . '/includes/bootstrap.php' ) ) {
	fwrite( STDERR, 'WordPress test library not found in ' . $softglaze_a11y_tests_lib . PHP_EOL );
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/' . $softglaze_a11y_tests_configs[ $softglaze_a11y_tests_version ] );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $softglaze_a11y_tests_root . '/vendor/yoast/phpunit-polyfills' );
define( 'SOFTGLAZE_A11Y_TESTS_ROOT', $softglaze_a11y_tests_root );
define( 'SOFTGLAZE_A11Y_TESTS_WP_VERSION', $softglaze_a11y_tests_version );

require_once $softglaze_a11y_tests_lib . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require SOFTGLAZE_A11Y_TESTS_ROOT . '/tagmend-accessibility-fixes/tagmend-accessibility-fixes.php';
	}
);

require $softglaze_a11y_tests_lib . '/includes/bootstrap.php';
