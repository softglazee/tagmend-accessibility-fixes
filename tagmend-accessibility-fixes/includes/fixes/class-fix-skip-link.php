<?php
/**
 * F11: a skip link when the page has none.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Engine;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front_End prints our own link, "Skip to content", at the start of
 * wp_body_open (escaped as it is built) unless the block-theme skip link
 * of core is active. The whole-page pass then settles it, touching only
 * our own link and the main element:
 *
 * - another in-page link among the first three focusable elements of the
 *   body (the theme's own skip link): our link gets hidden;
 * - one main element with a usable id: our link points at that id;
 * - one main element without an id: the main gets id="softglaze-a11y-main",
 *   which our link already points at;
 * - otherwise one element with id main, content, primary or main-content:
 *   our link points at it; with no target at all our link gets hidden.
 *
 * When our link is not in the page and the page has no skip link, the fix
 * records an R08 finding (and says so when the theme never calls
 * wp_body_open()). Needs the whole-page pass, so WordPress 6.9 or later.
 */
final class Fix_Skip_Link extends Fix_Base {

	/**
	 * Class of our own link; the page pass recognises the link by it.
	 */
	const LINK_CLASS = 'softglaze-a11y-skip-link';

	/**
	 * Id given to a main element that has none, and the href our link is printed with.
	 */
	const TARGET_ID = 'softglaze-a11y-main';

	/**
	 * Ids that mark the main content when there is no single main element, in order of preference.
	 */
	const FALLBACK_IDS = array( 'main', 'content', 'primary', 'main-content' );

	/**
	 * How many focusable elements at the start of the body are looked at for an existing skip link.
	 */
	const FOCUSABLE_LIMIT = 3;

	/**
	 * Classes of the toolbar WordPress shows to logged-in users; its items are not part of the visitor's page.
	 */
	const TOOLBAR_CLASSES = array( 'ab-item', 'screen-reader-shortcut', 'adminbar-input', 'adminbar-button' );

	/**
	 * Style handle core enqueues exactly when its block-template skip link is active
	 * (wp_enqueue_block_template_skip_link(), 6.4.0+, on wp_enqueue_scripts).
	 */
	const CORE_STYLE = 'wp-block-template-skip-link';

	/**
	 * Our stylesheet, enqueued only after our link was printed.
	 */
	const STYLE_HANDLE = 'softglaze-a11y-skip-link';
	const STYLE_FILE   = 'assets/css/skip-link.css';

	/**
	 * What happened to our link on this request (Front_End sets it).
	 */
	const PRINTER_UNKNOWN = '';
	const PRINTER_PRINTED = 'printed';
	const PRINTER_CORE    = 'core';
	const PRINTER_NO_HOOK = 'no_body_open';

	/**
	 * One of the PRINTER_* values.
	 *
	 * @var string
	 */
	private $printer = self::PRINTER_UNKNOWN;

	/**
	 * Whether the body element has started.
	 *
	 * @var bool
	 */
	private $in_body = false;

	/**
	 * Focusable elements counted at the start of the body (our link excluded).
	 *
	 * @var int
	 */
	private $focusables = 0;

	/**
	 * Whether an in-page link is among the first focusable elements.
	 *
	 * @var bool
	 */
	private $has_skip_link = false;

	/**
	 * Snapshot of our own link plus 'href' (string|null) and 'hidden' (bool), or null.
	 *
	 * @var array|null
	 */
	private $own = null;

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'skip-link';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Skip link when the page has none', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Adds a "Skip to content" link at the start of pages that have none, pointing at the main content; it shows when it receives keyboard focus. Nothing is added where WordPress or the theme already prints a skip link.', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '2.4.1' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'bypass', 'skip-link' );
	}

	/**
	 * Scopes: the printed link (attribute hook) and the page pass that settles it.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'attribute', 'page' );
	}

	/**
	 * Risk: a theme's CSS may style the link unexpectedly.
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
	 * The page pass exists from WordPress 6.9.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return array( 'wp69' );
	}

	/**
	 * After F12, which may give the main element the same id.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 15;
	}

	/**
	 * The body and the elements that can take focus.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'BODY', 'A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA' );
	}

	/**
	 * Reads the main landmark.
	 *
	 * @return bool
	 */
	public function uses_landmarks(): bool {
		return true;
	}

	/**
	 * The fixed, translated link text.
	 *
	 * @return string
	 */
	public static function link_text(): string {
		return __( 'Skip to content', 'tagmend-accessibility-fixes' );
	}

	/**
	 * The opening tag Front_End::print_skip_link() prints, escaped as it is
	 * built, so the printed link can be found again when the page pass falls back.
	 *
	 * @return string
	 */
	public static function printed_opener(): string {
		return '<a class="' . esc_attr( self::LINK_CLASS ) . ' screen-reader-text" href="' . esc_url( '#' . self::TARGET_ID ) . '">';
	}

	/**
	 * Whether core adds its own skip link on this request: a block theme
	 * rendering a block template, with core's skip-link style enqueued
	 * (core enqueues it only when its link is added; core adds the link with
	 * a script before 7.0 and in the template HTML from 7.0).
	 *
	 * @return bool
	 */
	public static function core_skip_link_active(): bool {
		if ( ! current_theme_supports( 'block-templates' ) || empty( $GLOBALS['_wp_current_template_content'] ) ) {
			return false;
		}
		return wp_style_is( self::CORE_STYLE, 'enqueued' ) || wp_style_is( self::CORE_STYLE, 'done' );
	}

	/**
	 * Whether an id can be written into an href unchanged by esc_url() in
	 * every supported version: letters, digits, "_", "-" and ".", at most 100.
	 *
	 * @param string $id Id value.
	 * @return bool
	 */
	public static function is_usable_id( string $id ): bool {
		return strlen( $id ) <= 100 && 1 === preg_match( '/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/', $id );
	}

	/**
	 * Tells the fix what happened to our link on this request.
	 *
	 * @param string $status One of the PRINTER_* values.
	 * @return void
	 */
	public function set_printer( string $status ): void {
		$this->printer = $status;
	}

	/**
	 * What happened to our link on this request.
	 *
	 * @return string
	 */
	public function printer(): string {
		return $this->printer;
	}

	/**
	 * Clears per-document state (the printer status belongs to the request).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->in_body       = false;
		$this->focusables    = 0;
		$this->has_skip_link = false;
		$this->own           = null;
	}

	/**
	 * Finds our own link and looks at the first focusable elements of the body.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( $token->is_closer ) {
			return;
		}
		if ( 'BODY' === $token->name ) {
			$this->in_body = true;
			return;
		}
		if ( 'A' === $token->name && null === $this->own && false !== strpos( $token->attr_text( 'class' ), self::LINK_CLASS ) && self::has_class( $token, self::LINK_CLASS ) ) {
			$href      = $token->attr( 'href' );
			$this->own = $token->snapshot( $recorder->is_recording() ) + array(
				'href'   => is_string( $href ) ? $href : null,
				'hidden' => null !== $token->attr( 'hidden' ),
			);
			return;
		}
		if ( ! $this->in_body || $this->focusables >= self::FOCUSABLE_LIMIT || ! self::is_focusable( $token ) ) {
			return;
		}
		++$this->focusables;
		$href = $token->attr_text( 'href' );
		if ( 'A' === $token->name && strlen( $href ) > 1 && '#' === $href[0] ) {
			$this->has_skip_link = true;
		}
	}

	/**
	 * Settles our link, or reports a page without a skip link.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		if ( Engine::SCOPE_PAGE !== $index->scope ) {
			return;
		}
		$target = $this->target( $index );

		if ( null === $this->own ) {
			$this->report_missing( $recorder, null !== $target );
			return;
		}
		if ( $this->has_skip_link || null === $target ) {
			$this->plan_at( $plan, $this->own, 'hidden', '', $this->own['hidden'] ? '' : null );
			return;
		}
		if ( isset( $target['main'] ) ) {
			$this->plan_at( $plan, $target['main'], 'id', self::TARGET_ID, null );
		}
		$this->plan_at( $plan, $this->own, 'href', '#' . $target['id'], $this->own['href'] );
	}

	/**
	 * Where a skip link should go: array( 'id' => …, 'main' => landmark when
	 * the main element still needs the id ), or null when there is no target.
	 *
	 * @param Document_Index $index The complete index.
	 * @return array|null
	 */
	private function target( Document_Index $index ) {
		$main = $index->single_main();
		if ( null !== $main ) {
			if ( '' === $main['id'] && 0 === $index->id_count( self::TARGET_ID ) ) {
				return array(
					'id'   => self::TARGET_ID,
					'main' => $main,
				);
			}
			if ( '' !== $main['id'] && self::is_usable_id( $main['id'] ) && 1 === $index->id_count( $main['id'] ) ) {
				return array( 'id' => $main['id'] );
			}
		}
		foreach ( self::FALLBACK_IDS as $id ) {
			if ( 1 === $index->id_count( $id ) ) {
				return array( 'id' => $id );
			}
		}
		return null;
	}

	/**
	 * R08 when the page has a target but no skip link at its start and ours is not there.
	 *
	 * @param Recorder $recorder   Recorder.
	 * @param bool     $has_target Whether a skip-link target exists.
	 * @return void
	 */
	private function report_missing( Recorder $recorder, bool $has_target ): void {
		if ( $this->has_skip_link || ! $has_target || self::PRINTER_CORE === $this->printer ) {
			return;
		}
		$detail = self::PRINTER_NO_HOOK === $this->printer
			? __( 'No skip link could be added: the theme does not call wp_body_open().', 'tagmend-accessibility-fixes' )
			: __( 'This page has no skip link among its first focusable elements.', 'tagmend-accessibility-fixes' );
		$recorder->add_finding( 'R08', '2.4.1', 'body', $detail );
	}

	/**
	 * Whether an element takes keyboard focus in document order (approximate:
	 * links with href and enabled form controls without hidden or a negative
	 * tabindex), leaving out the logged-in toolbar.
	 *
	 * @param Token $token Opener of a, button, input, select or textarea.
	 * @return bool
	 */
	private static function is_focusable( Token $token ): bool {
		if ( null !== $token->attr( 'hidden' ) || 1 === preg_match( '/^-\d/', $token->attr_text( 'tabindex' ) ) ) {
			return false;
		}
		if ( 'A' === $token->name ) {
			$focusable = null !== $token->attr( 'href' );
		} elseif ( 'INPUT' === $token->name ) {
			$focusable = 'hidden' !== strtolower( $token->attr_text( 'type' ) ) && null === $token->attr( 'disabled' );
		} else {
			$focusable = null === $token->attr( 'disabled' );
		}
		return $focusable && empty( array_intersect( self::TOOLBAR_CLASSES, self::classes( $token ) ) );
	}

	/**
	 * Whether the opener carries a class.
	 *
	 * @param Token  $token Opener.
	 * @param string $name  Class name.
	 * @return bool
	 */
	private static function has_class( Token $token, string $name ): bool {
		return in_array( $name, self::classes( $token ), true );
	}

	/**
	 * Class names of the opener.
	 *
	 * @param Token $token Opener.
	 * @return string[]
	 */
	public static function classes( Token $token ): array {
		return (array) preg_split( '/\s+/', $token->attr_text( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
	}
}
