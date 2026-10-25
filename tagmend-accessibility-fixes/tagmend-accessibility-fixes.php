<?php
/**
 * Plugin Name:       Tagmend – Accessibility Fixes
 * Description:       Accessibility fixes for theme, page builder and WooCommerce HTML: common WCAG failures repaired on your server. Preview each fix.
 * Version:           0.11.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            SoftGlaze
 * Author URI:        https://softglaze.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tagmend-accessibility-fixes
 * Domain Path:       /languages
 *
 * @package SoftGlaze\AccessibilityRepair
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOFTGLAZE_A11Y_VERSION', '0.11.0' );
define( 'SOFTGLAZE_A11Y_FILE', __FILE__ );
define( 'SOFTGLAZE_A11Y_DIR', plugin_dir_path( __FILE__ ) );

/*
 * Maps SoftGlaze\AccessibilityRepair\Sub_Space\Class_Name to
 * includes/sub-space/class-class-name.php (interface-class-name.php for the
 * Fix interface).
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SoftGlaze\\AccessibilityRepair\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$class = strtolower( str_replace( '_', '-', array_pop( $parts ) ) );
		$path  = SOFTGLAZE_A11Y_DIR . 'includes/';
		foreach ( $parts as $part ) {
			$path .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		foreach ( array( 'class-', 'interface-' ) as $file_prefix ) {
			$file = $path . $file_prefix . $class . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
);

\SoftGlaze\AccessibilityRepair\Plugin::instance();
