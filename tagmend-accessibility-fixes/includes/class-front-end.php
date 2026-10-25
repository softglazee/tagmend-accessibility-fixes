<?php
/**
 * Front-end wiring of the repair layers.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

use Throwable;
use WP_HTML_Tag_Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the repair layers on template_redirect, once the request gate has
 * passed (blueprint section 2.1):
 *
 * - Layer A, attribute hooks: language_attributes for F10,
 *   wp_get_attachment_image_attributes for F04.
 * - Layer C, the whole page: a callback on core's
 *   wp_template_enhancement_output_buffer filter (WordPress 6.9+), only when
 *   a fix applied in this mode needs page scope. Core owns the buffer.
 * - Layer B, content filters: only when Layer C is not registered and a fix
 *   applied in this mode needs content scope.
 *
 * Every callback checks the cached gate again, returns what it received on
 * any error, and never prints. Fallback reasons are kept in memory and
 * written on shutdown, after core has finished its output buffer callback.
 */
class Front_End {

	/**
	 * Layer B hooks and their priorities. Image filters run late, after lazy-load and optimisation plugins.
	 * TODO(Phase 3): get_search_form and wp_nav_menu.
	 */
	const CONTENT_FILTERS = array(
		'the_content'                                     => 999,
		'render_block_core/template-part'                 => 10,
		'widget_block_content'                            => 10,
		'widget_text'                                     => 10,
		'post_thumbnail_html'                             => 999,
		'woocommerce_single_product_image_thumbnail_html' => 999,
	);

	/**
	 * F04 runs after WooCommerce's and the theme's own attribute filters.
	 */
	const ATTACHMENT_PRIORITY = 20;

	/**
	 * Core's template enhancement filter (WordPress 6.9+); our callback runs after core's own at 10.
	 */
	const PAGE_FILTER   = 'wp_template_enhancement_output_buffer';
	const PAGE_PRIORITY = 20;

	/**
	 * Later than the default priority, so a theme or language plugin that sets lang wins.
	 */
	const LANGUAGE_PRIORITY = 20;

	/**
	 * Fallbacks are recorded after core's buffer callback (shutdown priority 1) has run.
	 */
	const SHUTDOWN_PRIORITY = 100;

	/**
	 * Request gate.
	 *
	 * @var Request_Gate
	 */
	protected $gate;

	/**
	 * Fix list.
	 *
	 * @var Registry
	 */
	protected $registry;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Engine for this request, built on first use.
	 *
	 * @var Engine|null
	 */
	private $engine = null;

	/**
	 * Fix states for this request, built on first use.
	 *
	 * @var string[]|null
	 */
	private $states = null;

	/**
	 * Layers registered on this request: 'attribute', 'content', 'page' => true.
	 *
	 * @var bool[]
	 */
	private $layers = array();

	/**
	 * Fallback reasons seen on this request, recorded on shutdown.
	 *
	 * @var bool[]
	 */
	private $fallbacks = array();

	/**
	 * Hash of this request's normalised URL.
	 *
	 * @var string
	 */
	private $url_hash = '';

	/**
	 * Engine time of this request, in milliseconds.
	 *
	 * @var float
	 */
	private $engine_ms = 0.0;

	/**
	 * Whether this request was marked as not cacheable.
	 *
	 * @var bool
	 */
	private $no_cache = false;

	/**
	 * Creates the wiring.
	 *
	 * @param Request_Gate $gate     Request gate.
	 * @param Registry     $registry Fix list.
	 * @param Settings     $settings Settings.
	 */
	public function __construct( Request_Gate $gate, Registry $registry, Settings $settings ) {
		$this->gate     = $gate;
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Hooks the wiring into the request.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 1 );
	}

	/**
	 * Registers the layers this request needs, after the gate passed.
	 *
	 * @return void
	 */
	public function on_template_redirect(): void {
		if ( ! $this->gate->passes() ) {
			return;
		}

		if ( $this->gate->is_admin_view() ) {
			$this->send_no_cache();
		}

		$applied = Engine::MODE_STATES[ $this->gate->mode() ]['apply'];
		if ( empty( $applied ) ) {
			return;
		}

		$this->url_hash = $this->gate->url_hash();

		if ( in_array( $this->state_of( 'html-lang' ), $applied, true ) && $this->registry->get( 'html-lang' ) instanceof Fixes\Fix_Html_Lang ) {
			add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), self::LANGUAGE_PRIORITY );
			$this->layers['attribute'] = true;
		}

		// states() counts F04 as Off when WooCommerce is not active.
		if ( in_array( $this->state_of( 'product-img-alt-name' ), $applied, true ) && $this->product_image_fix() instanceof Fixes\Fix_Product_Img_Alt_Name ) {
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_attachment_image_attributes' ), self::ATTACHMENT_PRIORITY, 2 );
			$this->layers['attribute'] = true;
		}

		if ( $this->page_pass_available() && $this->needs_scope( Engine::SCOPE_PAGE, $applied ) ) {
			add_filter( self::PAGE_FILTER, array( $this, 'filter_page' ), self::PAGE_PRIORITY, 2 );
			$this->layers['page'] = true;
		} elseif ( $this->needs_scope( Engine::SCOPE_CONTENT, $applied ) ) {
			foreach ( self::CONTENT_FILTERS as $hook => $priority ) {
				add_filter( $hook, array( $this, 'filter_content' ), $priority );
			}
			$this->layers['content'] = true;
		}

		if ( ! empty( $this->layers ) ) {
			add_action( 'shutdown', array( $this, 'record_fallbacks' ), self::SHUTDOWN_PRIORITY );
		}
	}

	/**
	 * Layer A for F10: adds lang to the html element's attributes when it is
	 * missing or malformed. Returns the input on any error.
	 *
	 * @param string $output Attribute string, for example 'lang="en-US" dir="ltr"'.
	 * @return string
	 */
	public function filter_language_attributes( $output ) {
		if ( ! is_string( $output ) || ! $this->gate->passes() ) {
			return $output;
		}
		try {
			return $this->repair_language_attributes( $output );
		} catch ( Throwable $throwable ) {
			return $output;
		}
	}

	/**
	 * Layer A for F04: product name as alt for the product's own images on a
	 * single product page. Returns the input on any error.
	 *
	 * @param array $attr       Attribute values keyed by name.
	 * @param mixed $attachment The attachment post.
	 * @return array
	 */
	public function filter_attachment_image_attributes( $attr, $attachment = null ) {
		if ( ! is_array( $attr ) || ! $this->gate->passes() ) {
			return $attr;
		}
		try {
			$fix = $this->product_image_fix();
			return $fix instanceof Fixes\Fix_Product_Img_Alt_Name ? $fix->filter_attributes( $attr, $attachment ) : $attr;
		} catch ( Throwable $throwable ) {
			return $attr;
		}
	}

	/**
	 * Layer B: repairs a content fragment.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	public function filter_content( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->gate->passes() || $this->gate->is_rest() ) {
			return $html;
		}
		return $this->repair( $html, Engine::SCOPE_CONTENT );
	}

	/**
	 * Layer C: repairs the whole page. Works on the first argument (the output
	 * of earlier callbacks, including core's own) and returns it unchanged on
	 * any fallback; the second argument is never returned (blueprint section 2.4).
	 *
	 * @param string $filtered_output Output of earlier callbacks.
	 * @param string $output          Raw template output (unused).
	 * @return string
	 */
	public function filter_page( $filtered_output, $output = '' ) {
		unset( $output );
		if ( ! is_string( $filtered_output ) || '' === $filtered_output || ! $this->gate->passes() ) {
			return $filtered_output;
		}
		$repaired = $this->repair( $filtered_output, Engine::SCOPE_PAGE );
		if ( $this->gate->is_admin_view() ) {
			$this->send_server_timing();
		}
		return $repaired;
	}

	/**
	 * Shutdown: one throttled record per fallback reason per hour.
	 *
	 * @return void
	 */
	public function record_fallbacks(): void {
		$reasons         = array_keys( $this->fallbacks );
		$this->fallbacks = array();
		foreach ( $reasons as $reason ) {
			$key = 'softglaze_a11y_fallback_' . $reason;
			if ( false !== get_transient( $key ) ) {
				continue;
			}
			set_transient( $key, time(), HOUR_IN_SECONDS );

			/**
			 * Fires at most once per reason per hour when the engine served a page unrepaired.
			 *
			 * @param string $reason   Short reason: size, encoding, time, incomplete, ordinal, apply, sanity, exception.
			 * @param string $url_hash SHA-256 of the normalised URL.
			 */
			do_action( 'softglaze_a11y_engine_fallback', $reason, $this->url_hash );
			// TODO(Phase 6A): one engine_fallback row in the activity log.
		}
	}

	/**
	 * Layers registered on this request.
	 *
	 * @return bool[]
	 */
	public function layers(): array {
		return $this->layers;
	}

	/**
	 * Whether this request was marked as not cacheable.
	 *
	 * @return bool
	 */
	public function no_cache_sent(): bool {
		return $this->no_cache;
	}

	/**
	 * Fallback reasons waiting for shutdown.
	 *
	 * @return string[]
	 */
	public function pending_fallbacks(): array {
		return array_keys( $this->fallbacks );
	}

	/**
	 * Fix states used on this request: saved states, with fixes that cannot
	 * run here and fixes skipped on the queried post counted as Off.
	 *
	 * @return string[]
	 */
	public function states(): array {
		if ( null !== $this->states ) {
			return $this->states;
		}
		$states = $this->settings->fix_states();
		foreach ( $this->registry->fixes() as $id => $fix ) {
			if ( ! $this->registry->is_available( $fix ) ) {
				$states[ $id ] = 'off';
			}
		}
		foreach ( $this->gate->skipped_fixes() as $id ) {
			if ( isset( $states[ $id ] ) ) {
				$states[ $id ] = 'off';
			}
		}
		$this->states = $states;
		return $this->states;
	}

	/**
	 * Forgets everything about the current request (tests).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->engine    = null;
		$this->states    = null;
		$this->layers    = array();
		$this->fallbacks = array();
		$this->url_hash  = '';
		$this->engine_ms = 0.0;
		$this->no_cache  = false;
	}

	/**
	 * The engine for this request.
	 *
	 * @return Engine
	 */
	protected function engine(): Engine {
		if ( null === $this->engine ) {
			$this->engine = new Engine(
				$this->registry->fixes(),
				$this->states(),
				array(
					'max_bytes' => $this->settings->max_bytes(),
					'max_ms'    => $this->settings->max_ms(),
					'checks'    => $this->registry->checks(),
				)
			);
		}
		return $this->engine;
	}

	/**
	 * The F04 fix object (a test double in tests).
	 *
	 * @return Fix|null
	 */
	protected function product_image_fix() {
		return $this->registry->get( 'product-img-alt-name' );
	}

	/**
	 * The lang value F10 would set for a current value (null: leave it).
	 *
	 * @param string|true|null $current Current lang attribute.
	 * @return string|null
	 */
	protected function html_lang_value( $current ) {
		$fix = $this->registry->get( 'html-lang' );
		return $fix instanceof Fixes\Fix_Html_Lang ? $fix->repaired_value( $current ) : null;
	}

	/**
	 * Marks an admin view as not cacheable: DONOTCACHEPAGE, honoured by the
	 * common page caches, and core's no-cache headers.
	 *
	 * @return void
	 */
	protected function send_no_cache(): void {
		$this->no_cache = true;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The page-cache convention; defined only on admin views and only when absent.
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * Runs the engine and keeps the fallback reason; returns the input on any error.
	 *
	 * @param string $html  Input.
	 * @param string $scope Engine::SCOPE_PAGE or Engine::SCOPE_CONTENT.
	 * @return string
	 */
	private function repair( string $html, string $scope ): string {
		try {
			$engine   = $this->engine();
			$output   = $engine->run( $html, $this->gate->mode(), $scope );
			$recorder = $engine->recorder();
			$facts    = $recorder->engine();
			$reason   = $recorder->get_fallback();

			$this->engine_ms += isset( $facts['ms'] ) ? (float) $facts['ms'] : 0.0;
			if ( null !== $reason ) {
				$this->fallbacks[ sanitize_key( $reason ) ] = true;
			}
			// TODO(Phase 6A): in admin_repaired views, store the recorder's changes and findings for this URL.
			return $output;
		} catch ( Throwable $throwable ) {
			$this->fallbacks['exception'] = true;
			return $html;
		}
	}

	/**
	 * Adds or corrects lang in a language_attributes() string with the HTML API.
	 *
	 * @param string $output Attribute string.
	 * @return string
	 */
	private function repair_language_attributes( string $output ): string {
		$tag       = '<html ' . $output . '>';
		$processor = new WP_HTML_Tag_Processor( $tag );
		if ( ! $processor->next_tag() || 'HTML' !== $processor->get_tag() ) {
			return $output;
		}

		$value = $this->html_lang_value( $processor->get_attribute( 'lang' ) );
		if ( null === $value || '' === $value || ! $processor->set_attribute( 'lang', $value ) ) {
			return $output;
		}

		$updated = $processor->get_updated_html();
		if (
			0 !== strpos( $updated, '<html ' )
			|| '>' !== substr( $updated, -1 )
			|| substr_count( $updated, '<' ) !== substr_count( $tag, '<' )
			|| substr_count( $updated, '>' ) !== substr_count( $tag, '>' )
		) {
			return $output;
		}
		return trim( substr( $updated, 6, -1 ) );
	}

	/**
	 * State of one fix on this request.
	 *
	 * @param string $id Fix id.
	 * @return string
	 */
	private function state_of( string $id ): string {
		$states = $this->states();
		return isset( $states[ $id ] ) ? $states[ $id ] : 'off';
	}

	/**
	 * Whether the whole-page pass can be used: WordPress 6.9+ and switched on in Settings.
	 *
	 * @return bool
	 */
	private function page_pass_available(): bool {
		return Registry::page_pass_exists() && $this->settings->page_scope_enabled();
	}

	/**
	 * Whether a fix whose state is applied in this mode works in a scope.
	 *
	 * @param string   $scope   Scope.
	 * @param string[] $applied States applied in this mode.
	 * @return bool
	 */
	private function needs_scope( string $scope, array $applied ): bool {
		$states = $this->states();
		foreach ( $this->registry->fixes() as $id => $fix ) {
			if ( ! isset( $states[ $id ] ) || ! in_array( $states[ $id ], $applied, true ) ) {
				continue;
			}
			try {
				if ( in_array( $scope, (array) $fix->scopes(), true ) ) {
					return true;
				}
			} catch ( Throwable $throwable ) {
				continue;
			}
		}
		return false;
	}

	/**
	 * Sends the engine time of an admin view, while headers can still be sent.
	 *
	 * @return void
	 */
	private function send_server_timing(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'Server-Timing: softglaze-a11y;dur=' . number_format( $this->engine_ms, 1, '.', '' ), false );
	}
}
