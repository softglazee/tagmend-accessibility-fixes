<?php
/**
 * F09: untitled iframes get a title.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Titles an iframe that has no accessible name, from a host map or, failing
 * that, "Embedded content from {host}".
 */
final class Fix_Iframe_Title extends Fix_Base {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'iframe-title';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Titles for embedded frames', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '4.1.2' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'frame-title' );
	}

	/**
	 * Scopes.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'content', 'page' );
	}

	/**
	 * Risk.
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'low';
	}

	/**
	 * Starts in Preview.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return true;
	}

	/**
	 * Only iframe openers.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'IFRAME' );
	}

	/**
	 * Plans a title for an iframe without a name.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer || 'IFRAME' !== $token->name ) {
			return;
		}
		if ( '' !== $token->attr_text( 'title' ) || '' !== $token->attr_text( 'aria-label' ) || '' !== $token->attr_text( 'aria-labelledby' ) ) {
			return;
		}

		$label = self::plain_text( $this->label_for( $token->attr_text( 'src' ) ) );
		if ( '' !== $label ) {
			$this->plan_edit( $token, $plan, $recorder, 'title', $label );
		}
	}

	/**
	 * The title for an iframe source URL.
	 *
	 * @param string $src Decoded src attribute.
	 * @return string
	 */
	public function label_for( string $src ): string {
		$host = '' === $src ? '' : wp_parse_url( $src, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : '';
		if ( '' === $host || ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ) {
			return __( 'Embedded content', 'tagmend-accessibility-fixes' );
		}

		$path = wp_parse_url( $src, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		if ( self::host_is( $host, 'youtube.com' ) || self::host_is( $host, 'youtube-nocookie.com' ) ) {
			return __( 'YouTube video', 'tagmend-accessibility-fixes' );
		}
		if ( self::host_is( $host, 'player.vimeo.com' ) ) {
			return __( 'Vimeo video', 'tagmend-accessibility-fixes' );
		}
		if ( self::host_is( $host, 'maps.google.com' ) || ( self::host_is( $host, 'google.com' ) && 0 === strpos( $path, '/maps' ) ) ) {
			return __( 'Map', 'tagmend-accessibility-fixes' );
		}
		if ( self::host_is( $host, 'open.spotify.com' ) ) {
			return __( 'Spotify player', 'tagmend-accessibility-fixes' );
		}
		if ( self::host_is( $host, 'w.soundcloud.com' ) ) {
			return __( 'SoundCloud player', 'tagmend-accessibility-fixes' );
		}
		if ( self::host_is( $host, 'calendly.com' ) ) {
			return __( 'Calendly booking', 'tagmend-accessibility-fixes' );
		}

		return sprintf(
			/* translators: %s: host name of the embedded page, for example www.example.com */
			__( 'Embedded content from %s', 'tagmend-accessibility-fixes' ),
			$host
		);
	}

	/**
	 * Whether a host is the domain or one of its subdomains.
	 *
	 * @param string $host   Lower-case host.
	 * @param string $domain Domain to match.
	 * @return bool
	 */
	private static function host_is( string $host, string $domain ): bool {
		return $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain;
	}
}
