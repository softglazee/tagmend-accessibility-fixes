<?php
/**
 * F05: links without a name get one from a reliable source.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Accessible_Name;
use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An a[href] that is not hidden and has no accessible name in the original
 * markup (no text, no image alt, no SVG title, no aria-label, valid
 * aria-labelledby or title) gets an aria-label from the first source that
 * fits: (1) a link to the home page holding only an image or SVG: "{site}
 * home page"; (2) mailto: and tel: links; (3) the host map (social networks);
 * (4) the icon-class map on an icon inside the link; (5) the WooCommerce cart
 * and account pages. Otherwise the link is listed as R05, unless it holds an
 * image without alt (F01 or R04 deal with that). A link with visible text is
 * never touched (2.5.3 Label in Name).
 */
final class Fix_Link_Name extends Fix_Base {

	/**
	 * Icon-class map of this run, built when first needed.
	 *
	 * @var string[]|null
	 */
	private $icon_map = null;

	/**
	 * Host map of this run, built when first needed.
	 *
	 * @var string[]|null
	 */
	private $host_map = null;

	/**
	 * Normalised WooCommerce cart and account URLs => label, built when first needed.
	 *
	 * @var string[]|null
	 */
	private $shop_urls = null;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'link-name';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Names for empty links', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Names links that have no text, such as logo, social, email, phone, cart and icon links, with an aria-label from a reliable source: the site name, the email address or phone number, the social network, the icon, or the cart or account page. Links it cannot name are listed in the report.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.4', '4.1.2' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'link-name' );
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
		return 'medium';
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
	 * Before F07, which hides icons only in links that end up named.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * Decides from the naming facts in finish(); needs no tokens.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Reads the naming facts.
	 *
	 * @return bool
	 */
	public function uses_names(): bool {
		return true;
	}

	/**
	 * Forgets the maps of the previous run (filters may have changed).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->icon_map  = null;
		$this->host_map  = null;
		$this->shop_urls = null;
	}

	/**
	 * Not used: the fix decides in finish().
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
	}

	/**
	 * Names every unnamed link a source fits, lists the rest.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $index->names() as $link ) {
			if ( Accessible_Name::KIND_LINK !== $link->kind || 'A' !== $link->tag || ! $link->has( 'href' ) || $link->hidden || $link->is_named() ) {
				continue;
			}
			$label = $this->label_for( $link );
			if ( '' !== $label ) {
				$this->plan_at( $plan, $link->snapshot(), 'aria-label', $label, $link->raw( 'aria-label' ) );
				continue;
			}
			if ( $link->images_without_alt > 0 || ! $recorder->is_recording() ) {
				continue;
			}
			$recorder->add_finding( 'R05', '2.4.4', $link->sig, __( 'Link has no text, and no label source fits: add visible text, or an aria-label that says where it goes.', 'tagmend-accessibility-fixes' ) );
		}
	}

	/**
	 * The label for an unnamed link, or ''.
	 *
	 * @param Accessible_Name $link Naming facts of the link.
	 * @return string
	 */
	public function label_for( Accessible_Name $link ): string {
		$href = $link->attr( 'href' );

		if ( ( $link->images > 0 || $link->svgs > 0 ) && self::is_same_url( $href, home_url( '/' ) ) ) {
			$site = self::plain_text( (string) get_bloginfo( 'name' ) );
			if ( '' !== $site ) {
				/* translators: %s: the site name */
				return self::plain_text( sprintf( __( '%s home page', 'tagmend-accessibility-fixes' ), $site ) );
			}
		}

		if ( 1 === preg_match( '/^mailto:([^?]*)/i', $href, $match ) ) {
			$address = self::plain_text( rawurldecode( $match[1] ) );
			if ( '' === $address ) {
				return __( 'Email', 'tagmend-accessibility-fixes' );
			}
			/* translators: %s: an email address */
			return self::plain_text( sprintf( __( 'Email %s', 'tagmend-accessibility-fixes' ), $address ) );
		}
		if ( 1 === preg_match( '/^tel:(.*)$/is', $href, $match ) ) {
			$number = self::plain_text( rawurldecode( $match[1] ) );
			if ( '' === $number ) {
				return __( 'Call', 'tagmend-accessibility-fixes' );
			}
			/* translators: %s: a phone number */
			return self::plain_text( sprintf( __( 'Call %s', 'tagmend-accessibility-fixes' ), $number ) );
		}

		$label = $this->host_label( $href );
		if ( '' === $label ) {
			$label = $this->icon_label( $link->icon_classes );
		}
		if ( '' === $label ) {
			$label = $this->shop_label( $href );
		}
		return $label;
	}

	/**
	 * The label the icon-class map gives the first matching class, or ''.
	 * F06 uses the same map.
	 *
	 * @param string[] $classes Lower-case class names of icon elements.
	 * @return string
	 */
	public function icon_label( array $classes ): string {
		if ( empty( $classes ) ) {
			return '';
		}
		if ( null === $this->icon_map ) {
			/**
			 * Filters the icon-class map of F05 and F06: lower-case class name of
			 * an icon element inside a link or button => label (plain text).
			 *
			 * @param string[] $map Class name => label.
			 */
			$this->icon_map = self::clean_map( apply_filters( 'softglaze_a11y_icon_label_map', self::icon_label_map() ) );
		}
		foreach ( $classes as $class ) {
			if ( isset( $this->icon_map[ $class ] ) ) {
				return $this->icon_map[ $class ];
			}
		}
		return '';
	}

	/**
	 * The bundled host map: host => platform name (brand names, not translated).
	 *
	 * @return string[]
	 */
	public static function host_label_map(): array {
		return array(
			'facebook.com'  => 'Facebook',
			'instagram.com' => 'Instagram',
			'x.com'         => 'X (Twitter)',
			'twitter.com'   => 'X (Twitter)',
			'linkedin.com'  => 'LinkedIn',
			'youtube.com'   => 'YouTube',
			'youtu.be'      => 'YouTube',
			'tiktok.com'    => 'TikTok',
			'pinterest.com' => 'Pinterest',
			'wa.me'         => 'WhatsApp',
			'whatsapp.com'  => 'WhatsApp',
			't.me'          => 'Telegram',
			'github.com'    => 'GitHub',
			'threads.net'   => 'Threads',
		);
	}

	/**
	 * The bundled icon-class map: class name => label. Labels that are not
	 * brand names are translated. Astra 4.14.1 and Kadence 1.5.2 label their
	 * own header icons (search, account, cart, social), so no theme classes are
	 * mapped.
	 *
	 * @return string[]
	 */
	public static function icon_label_map(): array {
		$search  = __( 'Search', 'tagmend-accessibility-fixes' );
		$cart    = __( 'Cart', 'tagmend-accessibility-fixes' );
		$account = __( 'My account', 'tagmend-accessibility-fixes' );
		$menu    = __( 'Menu', 'tagmend-accessibility-fixes' );
		$close   = __( 'Close', 'tagmend-accessibility-fixes' );
		$email   = __( 'Email', 'tagmend-accessibility-fixes' );
		$call    = __( 'Call', 'tagmend-accessibility-fixes' );

		$map = array();
		$add = static function ( array $classes, string $label ) use ( &$map ) {
			foreach ( $classes as $class ) {
				$map[ $class ] = $label;
			}
		};
		$add( array( 'fa-search', 'fa-magnifying-glass', 'eicon-search', 'eicon-search-bold', 'dashicons-search', 'bi-search' ), $search );
		$add( array( 'fa-shopping-cart', 'fa-cart-shopping', 'fa-shopping-basket', 'fa-basket-shopping', 'fa-shopping-bag', 'fa-bag-shopping', 'eicon-cart', 'eicon-cart-medium', 'eicon-cart-solid', 'eicon-basket-medium', 'dashicons-cart', 'bi-cart' ), $cart );
		$add( array( 'fa-user', 'fa-user-circle', 'fa-circle-user', 'eicon-user-circle-o', 'dashicons-admin-users', 'bi-person' ), $account );
		$add( array( 'fa-bars', 'fa-navicon', 'fa-reorder', 'eicon-menu-bar', 'dashicons-menu', 'dashicons-menu-alt', 'bi-list' ), $menu );
		$add( array( 'fa-times', 'fa-xmark', 'fa-close', 'fa-remove', 'eicon-close', 'dashicons-no', 'dashicons-no-alt', 'dashicons-dismiss', 'bi-x', 'bi-x-lg' ), $close );
		$add( array( 'fa-envelope', 'fa-envelope-o', 'fa-envelope-open', 'eicon-envelope', 'dashicons-email', 'dashicons-email-alt', 'bi-envelope' ), $email );
		$add( array( 'fa-phone', 'fa-phone-alt', 'fa-phone-flip', 'fa-phone-square', 'dashicons-phone', 'bi-telephone' ), $call );
		$add( array( 'fa-facebook', 'fa-facebook-f', 'fa-facebook-square', 'fa-square-facebook', 'fa-facebook-official', 'eicon-facebook', 'eicon-facebook-f', 'et-social-facebook', 'dashicons-facebook', 'dashicons-facebook-alt', 'bi-facebook' ), 'Facebook' );
		$add( array( 'fa-instagram', 'fa-square-instagram', 'eicon-instagram', 'et-social-instagram', 'dashicons-instagram', 'bi-instagram' ), 'Instagram' );
		$add( array( 'fa-twitter', 'fa-twitter-square', 'fa-square-twitter', 'fa-x-twitter', 'fa-square-x-twitter', 'eicon-twitter', 'eicon-x-twitter', 'et-social-twitter', 'dashicons-twitter', 'bi-twitter', 'bi-twitter-x' ), 'X (Twitter)' );
		$add( array( 'fa-linkedin', 'fa-linkedin-in', 'fa-linkedin-square', 'eicon-linkedin', 'et-social-linkedin', 'dashicons-linkedin', 'bi-linkedin' ), 'LinkedIn' );
		$add( array( 'fa-youtube', 'fa-youtube-play', 'fa-youtube-square', 'fa-square-youtube', 'eicon-youtube', 'et-social-youtube', 'dashicons-youtube', 'bi-youtube' ), 'YouTube' );
		$add( array( 'fa-pinterest', 'fa-pinterest-p', 'fa-pinterest-square', 'eicon-pinterest', 'et-social-pinterest', 'dashicons-pinterest', 'bi-pinterest' ), 'Pinterest' );
		$add( array( 'fa-tiktok', 'bi-tiktok' ), 'TikTok' );
		$add( array( 'fa-whatsapp', 'fa-whatsapp-square', 'fa-square-whatsapp', 'eicon-whatsapp', 'dashicons-whatsapp', 'bi-whatsapp' ), 'WhatsApp' );
		$add( array( 'fa-telegram', 'fa-telegram-plane', 'bi-telegram' ), 'Telegram' );
		$add( array( 'fa-github', 'fa-github-alt', 'fa-github-square', 'eicon-github', 'bi-github' ), 'GitHub' );
		$add( array( 'fa-threads', 'fa-square-threads', 'bi-threads' ), 'Threads' );
		return $map;
	}

	/**
	 * Whether two URLs point at the same page: same host (ignoring "www.")
	 * and port, same path (ignoring a trailing slash), no query, no fragment.
	 * A root-relative href is read against the home URL's host.
	 *
	 * @param string $href Decoded href.
	 * @param string $url  Absolute URL to compare with.
	 * @return bool
	 */
	public static function is_same_url( string $href, string $url ): bool {
		$a = self::normalise_url( $href, $url );
		return '' !== $a && self::normalise_url( $url, $url ) === $a;
	}

	/**
	 * The platform name for a link's host, or ''.
	 *
	 * @param string $href Decoded href.
	 * @return string
	 */
	private function host_label( string $href ): string {
		$host = wp_parse_url( $href, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}
		$host = strtolower( $host );
		if ( null === $this->host_map ) {
			/**
			 * Filters the host map of F05: domain => platform name (plain text).
			 * A domain also matches its subdomains.
			 *
			 * @param string[] $map Domain => label.
			 */
			$this->host_map = self::clean_map( apply_filters( 'softglaze_a11y_host_label_map', self::host_label_map() ) );
		}
		foreach ( $this->host_map as $domain => $label ) {
			if ( $host === $domain || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * "Cart" or "My account" for links to those WooCommerce pages, or ''.
	 * WooCommerce's functions are only called when they exist; pages that
	 * are not set up (which fall back to the home URL) are ignored.
	 *
	 * @param string $href Decoded href.
	 * @return string
	 */
	private function shop_label( string $href ): string {
		if ( null === $this->shop_urls ) {
			$this->shop_urls = array();
			$home            = home_url( '/' );
			$pages           = array();
			if ( function_exists( 'wc_get_cart_url' ) ) {
				$pages[] = array( (string) wc_get_cart_url(), __( 'Cart', 'tagmend-accessibility-fixes' ) );
			}
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$pages[] = array( (string) wc_get_page_permalink( 'myaccount', '' ), __( 'My account', 'tagmend-accessibility-fixes' ) );
			}
			foreach ( $pages as $page ) {
				$url = self::normalise_url( $page[0], $home );
				if ( '' !== $url && ! self::is_same_url( $page[0], $home ) && ! isset( $this->shop_urls[ $url ] ) ) {
					$this->shop_urls[ $url ] = $page[1];
				}
			}
		}
		if ( empty( $this->shop_urls ) ) {
			return '';
		}
		$url = self::normalise_url( $href, home_url( '/' ) );
		return isset( $this->shop_urls[ $url ] ) ? $this->shop_urls[ $url ] : '';
	}

	/**
	 * "host:port/path" for an http(s) or root-relative URL without a query
	 * or fragment, '' for anything else.
	 *
	 * @param string $url  URL.
	 * @param string $base Absolute URL whose host a root-relative URL takes.
	 * @return string
	 */
	private static function normalise_url( string $url, string $base ): string {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || isset( $parts['query'] ) || ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) ) {
			return '';
		}
		if ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		if ( ! isset( $parts['host'] ) ) {
			$path = isset( $parts['path'] ) ? $parts['path'] : '';
			if ( isset( $parts['scheme'] ) || 0 !== strpos( $path, '/' ) ) {
				return '';
			}
			$base_parts = wp_parse_url( $base );
			if ( ! is_array( $base_parts ) || ! isset( $base_parts['host'] ) ) {
				return '';
			}
			$parts['host'] = $base_parts['host'];
			if ( isset( $base_parts['port'] ) ) {
				$parts['port'] = $base_parts['port'];
			}
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		return $host . $port . $path;
	}

	/**
	 * A filtered map reduced to lower-case string keys and plain-text labels.
	 *
	 * @param mixed $map Map returned by the filter.
	 * @return string[]
	 */
	private static function clean_map( $map ): array {
		$clean = array();
		foreach ( is_array( $map ) ? $map : array() as $key => $label ) {
			if ( ! is_string( $key ) || ! is_string( $label ) ) {
				continue;
			}
			$key   = strtolower( trim( $key ) );
			$label = self::plain_text( $label );
			if ( '' !== $key && '' !== $label ) {
				$clean[ $key ] = $label;
			}
		}
		return $clean;
	}
}
