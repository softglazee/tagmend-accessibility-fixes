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
 *   wp_get_attachment_image_attributes for F04, the_content_more_link and
 *   excerpt_more for F19, wp_nav_menu_args for F18; our own skip link
 *   printed on wp_body_open for F11 (only together with Layer C, which
 *   settles it) and the hidden new-tab note printed on wp_footer for F21.
 *   F22 only enqueues a fixed stylesheet.
 * - Layer C, the whole page: a callback on core's
 *   wp_template_enhancement_output_buffer filter (WordPress 6.9+), only when
 *   a fix applied in this mode needs page scope. Core owns the buffer. When
 *   another plugin stops core from starting it, the request falls back to
 *   Layer B (confirm_page_pass()).
 * - Layer B, content filters: only when Layer C is not registered and a fix
 *   applied in this mode needs content scope.
 *
 * Every callback checks the cached gate again, returns what it received on
 * any error, and never prints. Fallback reasons are kept in memory and
 * written on shutdown, after core has finished its output buffer callback.
 *
 * Scans (an administrator's own request with a valid scan nonce) register
 * only the page pass or the content filters: every fix is analysed, none is
 * applied, nothing is printed, and the response is the original HTML. Scans
 * and "View repaired" hand each run to the Scanner, which stores the report
 * on shutdown. Visitor requests never store anything except the throttled
 * fallback row.
 */
class Front_End {

	/**
	 * Layer B hooks and their priorities. Content, menu, search-form and image filters run late, after other plugins.
	 */
	const CONTENT_FILTERS = array(
		'the_content'                                     => 999,
		'render_block_core/template-part'                 => 10,
		'widget_block_content'                            => 10,
		'widget_text'                                     => 10,
		'get_search_form'                                 => 999,
		'wp_nav_menu'                                     => 999,
		'post_thumbnail_html'                             => 999,
		'woocommerce_single_product_image_thumbnail_html' => 999,
	);

	/**
	 * F19's more-link filters run after a theme's own (default priority).
	 */
	const MORE_LINK_PRIORITY = 20;

	/**
	 * F21's note is printed after the theme's and most plugins' footer output.
	 */
	const FOOTER_PRIORITY = 20;

	/**
	 * F04 runs after WooCommerce's and the theme's own attribute filters.
	 */
	const ATTACHMENT_PRIORITY = 20;

	/**
	 * F11's link comes right after the toolbar (core renders it at 0) and before a theme's own body output.
	 */
	const SKIP_LINK_PRIORITY = 1;

	/**
	 * F18 runs after a theme's own menu-argument filters.
	 */
	const NAV_MENU_PRIORITY = 20;

	/**
	 * F22's stylesheet is enqueued after the theme's styles.
	 */
	const STYLES_PRIORITY = 20;

	/**
	 * Core's template enhancement filter (WordPress 6.9+); our callback runs after core's own at 10.
	 */
	const PAGE_FILTER   = 'wp_template_enhancement_output_buffer';
	const PAGE_PRIORITY = 20;

	/**
	 * Core starts its template buffer on wp_before_include_template at 1000; the check runs right after.
	 */
	const BUFFER_CHECK_HOOK     = 'wp_before_include_template';
	const BUFFER_CHECK_PRIORITY = 1001;
	const BUFFER_STARTED_ACTION = 'wp_template_enhancement_output_buffer_started';

	/**
	 * Later than the default priority, so a theme or language plugin that sets lang wins.
	 */
	const LANGUAGE_PRIORITY = 20;

	/**
	 * Fallbacks and reports are recorded after core's buffer callback (shutdown priority 1) has run.
	 */
	const SHUTDOWN_PRIORITY = 100;

	/**
	 * The admin bar is switched off for a scan after any theme or plugin filter has spoken.
	 */
	const ADMIN_BAR_PRIORITY = 999;

	/**
	 * Modes whose runs are kept for a report, and the report source of each.
	 */
	const REPORT_SOURCES = array(
		'scan'           => 'admin_scan',
		'admin_repaired' => 'admin_view',
	);

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
	 * Report collector and stores.
	 *
	 * @var Report\Scanner
	 */
	protected $scanner;

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
	 * Whether a content run applied F21 on this request.
	 *
	 * @var bool
	 */
	private $new_tab_used = false;

	/**
	 * Whether the new-tab note was printed on this request.
	 *
	 * @var bool
	 */
	private $new_tab_printed = false;

	/**
	 * Whether F11's printer is hooked on this request.
	 *
	 * @var bool
	 */
	private $skip_link_hooked = false;

	/**
	 * Whether F11's printer ran on this request (printed, or stood back for core's link).
	 *
	 * @var bool
	 */
	private $skip_link_done = false;

	/**
	 * Whether core's template buffer was not started on this request although Layer C was registered.
	 *
	 * @var bool
	 */
	private $page_pass_vetoed = false;

	/**
	 * Creates the wiring.
	 *
	 * @param Request_Gate        $gate     Request gate.
	 * @param Registry            $registry Fix list.
	 * @param Settings            $settings Settings.
	 * @param Report\Scanner|null $scanner  Report collector; a default one when null.
	 */
	public function __construct( Request_Gate $gate, Registry $registry, Settings $settings, ?Report\Scanner $scanner = null ) {
		$this->gate     = $gate;
		$this->registry = $registry;
		$this->settings = $settings;
		$this->scanner  = null !== $scanner ? $scanner : new Report\Scanner( $gate, new Report\Pages_Store(), new Report\Log_Store() );
	}

	/**
	 * Hooks the wiring into the request.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp', array( $this, 'on_wp' ) );
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 1 );
	}

	/**
	 * On the wp action, only when the scan arg is present: if the gate
	 * accepts the request as a scan (capability, then nonce), switches the
	 * admin bar off for this request (core sets it up on template_redirect
	 * priority 0) and marks the response as not cacheable. Any other request
	 * is left alone.
	 *
	 * @return void
	 */
	public function on_wp(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; the gate checks the capability and the nonce.
		if ( ! isset( $_GET[ Request_Gate::SCAN_ARG ] ) || Engine::MODE_SCAN !== $this->gate->mode() ) {
			return;
		}
		add_filter( 'show_admin_bar', '__return_false', self::ADMIN_BAR_PRIORITY );
		$this->send_no_cache();
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

		$mode = $this->gate->mode();
		if ( $this->gate->is_admin_view() || Engine::MODE_SCAN === $mode ) {
			$this->send_no_cache();
		}

		if ( Engine::MODE_SCAN === $mode ) {
			$this->register_scan();
			return;
		}

		$applied = Engine::MODE_STATES[ $mode ]['apply'];
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

		if ( in_array( $this->state_of( 'read-more-context' ), $applied, true ) && $this->registry->get( 'read-more-context' ) instanceof Fixes\Fix_Read_More_Context ) {
			add_filter( 'the_content_more_link', array( $this, 'filter_more_link' ), self::MORE_LINK_PRIORITY, 2 );
			add_filter( 'excerpt_more', array( $this, 'filter_excerpt_more' ), self::MORE_LINK_PRIORITY );
			$this->layers['attribute'] = true;
		}

		if ( in_array( $this->state_of( 'new-tab-note' ), $applied, true ) && $this->registry->get( 'new-tab-note' ) instanceof Fixes\Fix_New_Tab_Note ) {
			add_action( 'wp_footer', array( $this, 'print_new_tab_note' ), self::FOOTER_PRIORITY );
		}

		if ( in_array( $this->state_of( 'nav-labels' ), $applied, true ) && $this->registry->get( 'nav-labels' ) instanceof Fixes\Fix_Nav_Labels ) {
			add_filter( 'wp_nav_menu_args', array( $this, 'filter_nav_menu_args' ), self::NAV_MENU_PRIORITY );
			$this->layers['attribute'] = true;
		}

		if ( in_array( $this->state_of( 'focus-visible' ), $applied, true ) && $this->registry->get( 'focus-visible' ) instanceof Fixes\Fix_Focus_Visible ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_focus_visible' ), self::STYLES_PRIORITY );
		}

		if ( $this->page_pass_available() && $this->needs_scope( Engine::SCOPE_PAGE, $applied ) ) {
			$this->add_page_filter();
		} elseif ( $this->needs_scope( Engine::SCOPE_CONTENT, $applied ) ) {
			$this->add_content_filters();
			$this->scanner->set_scope( Engine::SCOPE_CONTENT, $this->page_pass_available() ? Report\Scanner::REASON_NO_PAGE_FIX : $this->content_reason() );
		}

		// Our skip link is printed only when the page pass will settle it (target id, or hidden).
		if ( ! empty( $this->layers['page'] ) && in_array( $this->state_of( 'skip-link' ), $applied, true ) && $this->registry->get( 'skip-link' ) instanceof Fixes\Fix_Skip_Link ) {
			add_action( 'wp_body_open', array( $this, 'print_skip_link' ), self::SKIP_LINK_PRIORITY );
			$this->skip_link_hooked = true;
		}

		if ( ! empty( $this->layers ) ) {
			add_action( 'shutdown', array( $this, 'record_fallbacks' ), self::SHUTDOWN_PRIORITY );
			if ( isset( self::REPORT_SOURCES[ $mode ] ) ) {
				add_action( 'shutdown', array( $this, 'record_report' ), self::SHUTDOWN_PRIORITY );
			}
		}
	}

	/**
	 * Scan mode: the page pass when it can run, otherwise the content
	 * filters. No attribute hook, no printed element and no stylesheet, so
	 * the response stays the original HTML; the engine analyses every fix and
	 * applies none.
	 *
	 * @return void
	 */
	private function register_scan(): void {
		$this->url_hash = $this->gate->url_hash();
		if ( $this->page_pass_available() ) {
			$this->add_page_filter();
		} else {
			$this->add_content_filters();
			$this->scanner->set_scope( Engine::SCOPE_CONTENT, $this->content_reason() );
		}
		add_action( 'shutdown', array( $this, 'record_fallbacks' ), self::SHUTDOWN_PRIORITY );
		add_action( 'shutdown', array( $this, 'record_report' ), self::SHUTDOWN_PRIORITY );
	}

	/**
	 * Runs right after core tried to start its template buffer. A plugin can
	 * stop core from starting it through the
	 * wp_should_output_buffer_template_for_enhancement filter (Elementor 4.3.5
	 * returns false there on every front-end request), and then Layer C would
	 * never run. In that case this request falls back to the content filters,
	 * as on WordPress 6.5-6.8: page-only fixes do not run, and our skip link
	 * is not printed because nothing would settle it. The other plugin's
	 * choice is left as it is.
	 *
	 * @return void
	 */
	public function confirm_page_pass(): void {
		if ( empty( $this->layers['page'] ) || did_action( self::BUFFER_STARTED_ACTION ) > 0 ) {
			return;
		}
		remove_filter( self::PAGE_FILTER, array( $this, 'filter_page' ), self::PAGE_PRIORITY );
		unset( $this->layers['page'] );
		$this->page_pass_vetoed = true;

		if ( $this->skip_link_hooked ) {
			remove_action( 'wp_body_open', array( $this, 'print_skip_link' ), self::SKIP_LINK_PRIORITY );
			$this->skip_link_hooked = false;
		}

		// Scans and admin views record that they saw content fragments only (never remembered on a visitor request, D8).
		// TODO(Phase 7): tell the administrator on the Overview when scans show that the page pass could not run.
		$this->scanner->set_scope( Engine::SCOPE_CONTENT, Report\Scanner::REASON_PAGE_BUFFER_OFF );
		$mode = $this->gate->mode();
		if ( Engine::MODE_SCAN === $mode || $this->needs_scope( Engine::SCOPE_CONTENT, Engine::MODE_STATES[ $mode ]['apply'] ) ) {
			$this->add_content_filters();
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
			if ( ! $fix instanceof Fixes\Fix_Product_Img_Alt_Name ) {
				return $attr;
			}
			$result = $fix->filter_attributes( $attr, $attachment );
			$before = isset( $attr['alt'] ) && is_scalar( $attr['alt'] ) ? (string) $attr['alt'] : null;
			if ( isset( $result['alt'] ) && is_string( $result['alt'] ) && $result['alt'] !== $before ) {
				$this->record_attribute_change( 'product-img-alt-name', self::attribute_signature( 'img', $attr ), 'alt', $before, $result['alt'] );
			}
			return $result;
		} catch ( Throwable $throwable ) {
			return $attr;
		}
	}

	/**
	 * Layer A for F19: the "(more…)" link of the_content.
	 *
	 * @param string $link Link markup.
	 * @param string $text Link text (unused; the markup is read).
	 * @return string
	 */
	public function filter_more_link( $link, $text = '' ) {
		unset( $text );
		return $this->label_more_links( $link );
	}

	/**
	 * Layer A for F19: a theme's excerpt-more string, when it holds a link.
	 *
	 * @param string $more The excerpt-more string.
	 * @return string
	 */
	public function filter_excerpt_more( $more ) {
		return $this->label_more_links( $more );
	}

	/**
	 * Prints F21's hidden note once on wp_footer, escaped as it is built:
	 * always when the whole-page pass runs (it sees the header and footer
	 * links only after this hook), otherwise only when a content run already
	 * pointed a link at it. When it is not printed, later content runs stop
	 * adding references to it.
	 *
	 * @return void
	 */
	public function print_new_tab_note(): void {
		$fix = $this->registry->get( 'new-tab-note' );
		if ( $this->new_tab_printed || ! $fix instanceof Fixes\Fix_New_Tab_Note || ! $this->gate->passes() ) {
			return;
		}
		if ( empty( $this->layers['page'] ) && ! $this->new_tab_used ) {
			$fix->set_note_available( false );
			return;
		}
		$this->new_tab_printed = true;
		printf(
			'<span id="%1$s" hidden>%2$s</span>',
			esc_attr( Fixes\Fix_New_Tab_Note::NOTE_ID ),
			esc_html( Fixes\Fix_New_Tab_Note::note_text() )
		);
	}

	/**
	 * Prints F11's link once at the start of wp_body_open, escaped as it is
	 * built, and enqueues its stylesheet; stands back when core's block-theme
	 * skip link is active. The page pass then points the link at the main
	 * content or hides it.
	 *
	 * @return void
	 */
	public function print_skip_link(): void {
		$fix = $this->registry->get( 'skip-link' );
		if ( $this->skip_link_done || ! $fix instanceof Fixes\Fix_Skip_Link || ! $this->gate->passes() ) {
			return;
		}
		$this->skip_link_done = true;
		if ( Fixes\Fix_Skip_Link::core_skip_link_active() ) {
			$fix->set_printer( Fixes\Fix_Skip_Link::PRINTER_CORE );
			return;
		}
		$fix->set_printer( Fixes\Fix_Skip_Link::PRINTER_PRINTED );
		printf(
			'<a class="%1$s screen-reader-text" href="%2$s">%3$s</a>',
			esc_attr( Fixes\Fix_Skip_Link::LINK_CLASS ),
			esc_url( '#' . Fixes\Fix_Skip_Link::TARGET_ID ),
			esc_html( Fixes\Fix_Skip_Link::link_text() )
		);
		wp_enqueue_style( Fixes\Fix_Skip_Link::STYLE_HANDLE, plugins_url( Fixes\Fix_Skip_Link::STYLE_FILE, SOFTGLAZE_A11Y_FILE ), array(), SOFTGLAZE_A11Y_VERSION );
	}

	/**
	 * Layer A for F18: a label for wp_nav_menu() navs without one. Returns the input on any error.
	 *
	 * @param array $args wp_nav_menu() arguments.
	 * @return array
	 */
	public function filter_nav_menu_args( $args ) {
		if ( ! is_array( $args ) || ! $this->gate->passes() ) {
			return $args;
		}
		try {
			$fix = $this->registry->get( 'nav-labels' );
			if ( ! $fix instanceof Fixes\Fix_Nav_Labels ) {
				return $args;
			}
			$result = $fix->filter_args( $args );
			$before = isset( $args['container_aria_label'] ) && is_string( $args['container_aria_label'] ) ? $args['container_aria_label'] : '';
			if ( isset( $result['container_aria_label'] ) && is_string( $result['container_aria_label'] ) && $result['container_aria_label'] !== $before ) {
				$class = isset( $args['container_class'] ) && is_string( $args['container_class'] ) ? $args['container_class'] : '';
				$this->record_attribute_change( 'nav-labels', self::attribute_signature( 'nav', array( 'class' => $class ) ), 'aria-label', '' === $before ? null : $before, $result['container_aria_label'] );
			}
			return $result;
		} catch ( Throwable $throwable ) {
			return $args;
		}
	}

	/**
	 * Enqueues F22's fixed stylesheet.
	 *
	 * @return void
	 */
	public function enqueue_focus_visible(): void {
		if ( ! $this->gate->passes() ) {
			return;
		}
		wp_enqueue_style( Fixes\Fix_Focus_Visible::STYLE_HANDLE, plugins_url( Fixes\Fix_Focus_Visible::STYLE_FILE, SOFTGLAZE_A11Y_FILE ), array(), SOFTGLAZE_A11Y_VERSION );
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
		$skip_link = $this->registry->get( 'skip-link' );
		if ( $this->skip_link_hooked && ! $this->skip_link_done && 0 === did_action( 'wp_body_open' ) && $skip_link instanceof Fixes\Fix_Skip_Link ) {
			$skip_link->set_printer( Fixes\Fix_Skip_Link::PRINTER_NO_HOOK );
		}
		// TODO(Phase 7): when this run falls back (size, time), F11's printed link keeps href="#softglaze-a11y-main" without a target.
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

			$this->scanner->log()->add(
				'engine_fallback',
				array(
					'url_hash' => $this->url_hash,
					'detail'   => $reason,
				)
			);
		}
	}

	/**
	 * Shutdown, in a scan or "View repaired": stores what the engine found on
	 * this request (the Scanner checks the capability again).
	 *
	 * @return void
	 */
	public function record_report(): void {
		$mode = $this->gate->mode();
		if ( ! isset( self::REPORT_SOURCES[ $mode ] ) ) {
			return;
		}
		try {
			$this->scanner->store_request_report( self::REPORT_SOURCES[ $mode ] );
		} catch ( Throwable $throwable ) {
			return;
		}
	}

	/**
	 * The report collector.
	 *
	 * @return Report\Scanner
	 */
	public function scanner(): Report\Scanner {
		return $this->scanner;
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
	 * Whether Layer C was registered but core's template buffer was not started on this request.
	 *
	 * @return bool
	 */
	public function page_pass_vetoed(): bool {
		return $this->page_pass_vetoed;
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
		$this->engine           = null;
		$this->states           = null;
		$this->layers           = array();
		$this->fallbacks        = array();
		$this->url_hash         = '';
		$this->engine_ms        = 0.0;
		$this->no_cache         = false;
		$this->new_tab_used     = false;
		$this->new_tab_printed  = false;
		$this->skip_link_hooked = false;
		$this->skip_link_done   = false;
		$this->page_pass_vetoed = false;
		$this->scanner->reset();
		$fix = $this->registry->get( 'new-tab-note' );
		if ( $fix instanceof Fixes\Fix_New_Tab_Note ) {
			$fix->set_note_available( true );
		}
		$fix = $this->registry->get( 'skip-link' );
		if ( $fix instanceof Fixes\Fix_Skip_Link ) {
			$fix->set_printer( Fixes\Fix_Skip_Link::PRINTER_UNKNOWN );
		}
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
					// Report-only checks never run for visitors: their classes are not even loaded then.
					'checks'    => isset( Engine::MODE_CHECKS[ $this->gate->mode() ] ) ? $this->registry->checks() : array(),
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
	 * Registers the Layer C callback and the check that core started its buffer.
	 *
	 * @return void
	 */
	private function add_page_filter(): void {
		add_filter( self::PAGE_FILTER, array( $this, 'filter_page' ), self::PAGE_PRIORITY, 2 );
		add_action( self::BUFFER_CHECK_HOOK, array( $this, 'confirm_page_pass' ), self::BUFFER_CHECK_PRIORITY );
		$this->layers['page'] = true;
		$this->scanner->set_scope( Engine::SCOPE_PAGE );
	}

	/**
	 * Why the page pass cannot run on this request: WordPress below 6.9, or switched off in Settings.
	 *
	 * @return string
	 */
	private function content_reason(): string {
		return Registry::page_pass_exists() ? Report\Scanner::REASON_PAGE_PASS_OFF : Report\Scanner::REASON_NEEDS_WP69;
	}

	/**
	 * Registers the Layer B content filters.
	 *
	 * @return void
	 */
	private function add_content_filters(): void {
		foreach ( self::CONTENT_FILTERS as $hook => $priority ) {
			add_filter( $hook, array( $this, 'filter_content' ), $priority );
		}
		$this->layers['content'] = true;
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
			$counts = $recorder->counts();
			$state  = $this->state_of( 'new-tab-note' );
			if ( ! empty( $counts['new-tab-note'][ $state ] ) ) {
				$this->new_tab_used = true;
			}
			if ( isset( self::REPORT_SOURCES[ $this->gate->mode() ] ) ) {
				$this->scanner->add_run( $recorder, $html, $output, $scope );
			}
			return $output;
		} catch ( Throwable $throwable ) {
			$this->fallbacks['exception'] = true;
			return $html;
		}
	}

	/**
	 * F19 on a more-link string, with the current post's title. Returns the input on any error.
	 *
	 * @param mixed $html Markup from the filter.
	 * @return mixed
	 */
	private function label_more_links( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->gate->passes() ) {
			return $html;
		}
		try {
			$fix = $this->registry->get( 'read-more-context' );
			if ( ! $fix instanceof Fixes\Fix_Read_More_Context ) {
				return $html;
			}
			$result = $fix->label_more_links( $html, (string) get_the_title() );
			if ( $result !== $html ) {
				$this->record_more_link_changes( $html, $result );
			}
			return $result;
		} catch ( Throwable $throwable ) {
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

		$before = $processor->get_attribute( 'lang' );
		$value  = $this->html_lang_value( $before );
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
		$this->record_attribute_change( 'html-lang', 'html', 'lang', is_string( $before ) ? $before : null, $value );
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
	 * Keeps a change an attribute hook made, in "View repaired" only (never for visitors).
	 *
	 * @param string      $fix       Fix id.
	 * @param string      $signature Element signature.
	 * @param string      $attribute Attribute.
	 * @param string|null $before    Value before, null when absent.
	 * @param string      $after     Value after.
	 * @return void
	 */
	private function record_attribute_change( string $fix, string $signature, string $attribute, $before, string $after ): void {
		if ( Engine::MODE_ADMIN_REPAIRED !== $this->gate->mode() ) {
			return;
		}
		$this->scanner->add_attribute_change( $fix, $this->state_of( $fix ), $signature, $attribute, $before, $after );
	}

	/**
	 * Keeps the aria-label F19's attribute hook gave each more link, in "View repaired" only.
	 *
	 * @param string $before Markup before.
	 * @param string $after  Markup after.
	 * @return void
	 */
	private function record_more_link_changes( string $before, string $after ): void {
		if ( Engine::MODE_ADMIN_REPAIRED !== $this->gate->mode() ) {
			return;
		}
		$old   = new WP_HTML_Tag_Processor( $before );
		$new   = new WP_HTML_Tag_Processor( $after );
		$token = new Token();
		while ( $old->next_tag( 'A' ) && $new->next_tag( 'A' ) ) {
			$label = $new->get_attribute( 'aria-label' );
			$was   = $old->get_attribute( 'aria-label' );
			if ( ! is_string( $label ) || $label === $was ) {
				continue;
			}
			$token->set_opener( $old, 0, 'A', null, array() );
			$this->scanner->add_attribute_change( 'read-more-context', $this->state_of( 'read-more-context' ), $token->signature(), 'aria-label', is_string( $was ) ? $was : null, $label );
		}
		$token->clear();
	}

	/**
	 * A privacy-stripped signature for an element known only by its attributes: tag, three classes, link or image path.
	 *
	 * @param string $tag  Tag name.
	 * @param array  $attr Attribute values by name.
	 * @return string
	 */
	private static function attribute_signature( string $tag, array $attr ): string {
		$signature = $tag;
		$classes   = isset( $attr['class'] ) && is_string( $attr['class'] ) ? preg_split( '/\s+/', $attr['class'], -1, PREG_SPLIT_NO_EMPTY ) : array();
		foreach ( array_slice( (array) $classes, 0, 3 ) as $class ) {
			$signature .= '.' . mb_substr( $class, 0, 40 );
		}
		foreach ( array( 'href', 'src' ) as $attribute ) {
			if ( isset( $attr[ $attribute ] ) && is_string( $attr[ $attribute ] ) && '' !== $attr[ $attribute ] ) {
				$path       = wp_parse_url( $attr[ $attribute ], PHP_URL_PATH );
				$signature .= '[' . $attribute . '=' . mb_substr( is_string( $path ) ? $path : '', 0, 80 ) . ']';
				break;
			}
		}
		return mb_substr( $signature, 0, 200 );
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
