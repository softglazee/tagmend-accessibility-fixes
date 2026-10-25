<?php
/**
 * The page-cache reminder shown after fix changes and on the Overview.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names the active page-cache plugin, when one of a few well-known ones is
 * detected, and tells the administrator to clear its cache from that
 * plugin's own screen (decision D-05: a reminder only). Detection reads one
 * constant per plugin; no function, action or filter of another plugin is
 * called.
 */
final class Cache_Reminder {

	/**
	 * Constant defined by each plugin when it is loaded, and the plugin's name.
	 * Checked in each plugin's source: WP Super Cache 3.1.4 (wp-cache.php),
	 * LiteSpeed Cache 7.9.2 (litespeed-cache.php), W3 Total Cache 2.11.0
	 * (w3-total-cache-api.php), WP Rocket 3.23.5.1 (wp-rocket.php).
	 */
	const PLUGINS = array(
		'WPCACHEHOME'       => 'WP Super Cache',
		'LSCWP_V'           => 'LiteSpeed Cache',
		'W3TC'              => 'W3 Total Cache',
		'WP_ROCKET_VERSION' => 'WP Rocket',
	);

	/**
	 * Name of the first detected page-cache plugin, or ''.
	 *
	 * @param callable|null $is_defined Constant check (tests); defined() when null.
	 * @return string
	 */
	public static function detected( $is_defined = null ): string {
		$check = is_callable( $is_defined ) ? $is_defined : 'defined';
		foreach ( self::PLUGINS as $constant => $name ) {
			if ( call_user_func( $check, $constant ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * The sentence shown after a change that visitors see (a fix moved to or from Live).
	 *
	 * @param callable|null $is_defined Constant check (tests).
	 * @return string
	 */
	public static function after_change( $is_defined = null ): string {
		$name = self::detected( $is_defined );
		if ( '' === $name ) {
			return __( 'If this site uses a page cache or a CDN cache, clear it so visitors get the change: cached pages keep the previous version until then.', 'tagmend-accessibility-fixes' );
		}
		return sprintf(
			/* translators: %s: name of a page-cache plugin, for example WP Super Cache */
			__( '%s is active on this site: pages it has already stored keep the previous version until you clear its page cache from its own settings screen.', 'tagmend-accessibility-fixes' ),
			$name
		);
	}

	/**
	 * The sentence shown on the Overview.
	 *
	 * @param callable|null $is_defined Constant check (tests).
	 * @return string
	 */
	public static function overview( $is_defined = null ): string {
		$name = self::detected( $is_defined );
		if ( '' === $name ) {
			return __( 'After you change a fix, pages kept by a page cache or a CDN cache show the previous version until you clear that cache. No page-cache plugin that Tagmend recognises is active.', 'tagmend-accessibility-fixes' );
		}
		return sprintf(
			/* translators: %s: name of a page-cache plugin, for example WP Super Cache */
			__( 'After you change a fix, pages already stored by %s keep the previous version until you clear its page cache from its own settings screen. A CDN cache, if you use one, needs the same.', 'tagmend-accessibility-fixes' ),
			$name
		);
	}
}
