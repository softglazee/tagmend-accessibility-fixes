<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO(Phase 7): delete stored data only when "Delete all data when the plugin is deleted" is on (blueprint section 15).
