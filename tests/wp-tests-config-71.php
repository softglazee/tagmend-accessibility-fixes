<?php
/**
 * WordPress test library configuration for WordPress 7.1.3 (database wptests71).
 *
 * Used by tools/run-tests.sh and tools/run-tests-docker.sh. Core is the folder named by
 * SGL_WP_CORE_DIR (dev/wp-core/7.1.3 after tools/setup-dev.sh). The database host and
 * credentials come from the environment (SGL_DB_HOST, SGL_DB_USER, SGL_DB_PASSWORD), never
 * from this file.
 *
 * @package SoftGlaze\AccessibilityRepair\Tests
 */

define( 'ABSPATH', rtrim( getenv( 'SGL_WP_CORE_DIR' ) ? getenv( 'SGL_WP_CORE_DIR' ) : '/wpcore', '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', 'wptests71' );
define( 'DB_USER', (string) getenv( 'SGL_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'SGL_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'SGL_DB_HOST' ) ? getenv( 'SGL_DB_HOST' ) : 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'tagmend-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'tagmend-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'tagmend-tests-logged-in-key' );
define( 'NONCE_KEY', 'tagmend-tests-nonce-key' );
define( 'AUTH_SALT', 'tagmend-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'tagmend-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'tagmend-tests-logged-in-salt' );
define( 'NONCE_SALT', 'tagmend-tests-nonce-salt' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
