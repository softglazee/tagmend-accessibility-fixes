<?php
/**
 * F07: icon fonts and unlabelled SVGs inside named controls are hidden from screen readers.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Element;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inside a link or button that has a name of its own, sets aria-hidden="true"
 * on (a) i and span elements that carry an icon-font class and contain
 * nothing but icon glyphs (Private Use Area characters) or nothing at all,
 * or that contain only such glyphs, and on (b) inline SVGs without a title,
 * aria-label, aria-labelledby or role (plus focusable="false"). The decision
 * is taken when the control closes, so its name is computed from the original
 * markup. An svg with role="img" and no name is not decorative: it is listed
 * as a finding instead.
 */
final class Fix_Decorative_Icons extends Fix_Base {

	/**
	 * Icon-font class names: Font Awesome, Dashicons, Elementor, Divi,
	 * Genericons, Glyphicons, Bootstrap Icons, Themify, Ionicons, Line
	 * Awesome, Material, UIkit, Astra and generic icon-* classes.
	 */
	const ICON_CLASS = '/^(?:fa|fas|far|fab|fal|fad|fat|fa-[a-z0-9-]+|dashicons(?:-[a-z0-9-]+)?|eicon-[a-z0-9-]+|et-pb-icon|genericons?(?:-[a-z0-9-]+)?|glyphicon(?:-[a-z0-9-]+)?|bi|bi-[a-z0-9-]+|ti-[a-z0-9-]+|ion-[a-z0-9-]+|ionicons?|la|las|lar|lab|la-[a-z0-9-]+|material-icons(?:-[a-z]+)?|material-symbols-[a-z]+|uk-icon|ast-icon|icon|icon-[a-z0-9-]+)$/i';

	/**
	 * Data key on element frames.
	 */
	const KEY = 'decorative-icons';

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'decorative-icons';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Hide decorative icons in links and buttons', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.1.1' );
	}

	/**
	 * Axe rules (must not rise).
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'svg-img-alt' );
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
	 * Runs after the fixes that name links and buttons.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 30;
	}

	/**
	 * Controls, icon elements and inline SVG.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'A', 'BUTTON', 'I', 'SPAN', 'SVG' );
	}

	/**
	 * Collects icon candidates on their openers and closers and decides when their control closes.
	 *
	 * @param Token          $token    Current token.
	 * @param Document_Index $index    Index.
	 * @param Plan           $plan     Plan.
	 * @param Recorder       $recorder Recorder.
	 * @return void
	 */
	public function analyse( Token $token, Document_Index $index, Plan $plan, Recorder $recorder ): void {
		switch ( $token->name ) {
			case 'A':
			case 'BUTTON':
				if ( $token->is_closer ) {
					$this->resolve( $token->element, $plan );
				} else {
					$token->remember_control();
				}
				return;
			case 'SVG':
				if ( $token->is_closer ) {
					$this->close_svg( $token, $recorder );
				} else {
					$this->open_svg( $token, $recorder );
				}
				return;
			default:
				if ( $token->is_closer ) {
					$this->close_icon( $token );
				} else {
					$this->open_icon( $token, $recorder );
				}
		}
	}

	/**
	 * Marks an i or span inside a control as a possible icon.
	 *
	 * @param Token    $token    An i or span opener.
	 * @param Recorder $recorder Recorder.
	 * @return void
	 */
	private function open_icon( Token $token, Recorder $recorder ): void {
		// Only tracked inside links and buttons; anything already labelled or with a role is left alone.
		if ( null === $token->element || null !== $token->attr( 'aria-hidden' ) || null !== $token->attr( 'role' ) || $token->inside_aria_hidden() ) {
			return;
		}
		if ( '' !== $token->attr_text( 'aria-label' ) || '' !== $token->attr_text( 'aria-labelledby' ) ) {
			return;
		}
		$icon_class = false;
		foreach ( (array) preg_split( '/\s+/', $token->attr_text( 'class' ), -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			if ( preg_match( self::ICON_CLASS, (string) $class ) ) {
				$icon_class = true;
				break;
			}
		}
		$token->element->data[ self::KEY ] = array(
			'element'    => $token->snapshot( $recorder->is_recording() ),
			'icon_class' => $icon_class,
		);
	}

	/**
	 * On the end of an i or span: an icon when it holds only glyphs, or nothing and an icon class.
	 *
	 * @param Token $token Closer.
	 * @return void
	 */
	private function close_icon( Token $token ): void {
		$element = $token->element;
		if ( null === $element || ! isset( $element->data[ self::KEY ] ) ) {
			return;
		}
		$candidate = $element->data[ self::KEY ];
		$text      = (string) preg_replace( '/[\s\x{00A0}]+/u', '', $element->text );
		if ( '' !== Element::without_private_use( $text ) || ( '' === $text && ! $candidate['icon_class'] ) ) {
			return;
		}
		$this->add_candidate( $token->closest_control(), $candidate['element'], null );
	}

	/**
	 * Marks an inline SVG inside a control as possibly decorative, or remembers an unnamed role="img".
	 *
	 * @param Token    $token    An svg opener.
	 * @param Recorder $recorder Recorder.
	 * @return void
	 */
	private function open_svg( Token $token, Recorder $recorder ): void {
		if ( null !== $token->attr( 'aria-hidden' ) || $token->inside_aria_hidden() ) {
			return;
		}
		$named = '' !== $token->attr_text( 'aria-label' ) || '' !== $token->attr_text( 'aria-labelledby' );
		$role  = strtolower( $token->attr_text( 'role' ) );

		if ( 'img' === $role ) {
			if ( ! $named && $recorder->is_recording() ) {
				$finding = array( 'sig' => $token->signature() );
				if ( null === $token->element ) {
					$this->report_unnamed_svg( $recorder, $finding['sig'] );
				} else {
					$token->element->data[ self::KEY . '-img' ] = $finding;
				}
			}
			return;
		}
		if ( $named || null !== $token->attr( 'role' ) || null === $token->closest_control() ) {
			return;
		}

		$focusable = $token->attr( 'focusable' );
		$candidate = array(
			'element'   => $token->snapshot( $recorder->is_recording() ),
			'focusable' => is_string( $focusable ) ? $focusable : ( true === $focusable ? '' : null ),
		);
		if ( null === $token->element ) {
			// A self-closing <svg/> has no children, so no title.
			$this->add_candidate( $token->closest_control(), $candidate['element'], $candidate['focusable'] );
			return;
		}
		$token->element->data[ self::KEY ] = $candidate;
	}

	/**
	 * On the end of an SVG: decorative when it had no title.
	 *
	 * @param Token    $token    Closer.
	 * @param Recorder $recorder Recorder.
	 * @return void
	 */
	private function close_svg( Token $token, Recorder $recorder ): void {
		$element = $token->element;
		if ( null === $element || $element->has_svg_title ) {
			return;
		}
		if ( isset( $element->data[ self::KEY . '-img' ] ) ) {
			$this->report_unnamed_svg( $recorder, $element->data[ self::KEY . '-img' ]['sig'] );
			return;
		}
		if ( isset( $element->data[ self::KEY ] ) ) {
			$candidate = $element->data[ self::KEY ];
			$this->add_candidate( $token->closest_control(), $candidate['element'], $candidate['focusable'] );
		}
	}

	/**
	 * Stores an icon on its control's frame until the control closes.
	 *
	 * @param Element|null $control   The control around the icon.
	 * @param array        $element   Token::snapshot() of the icon.
	 * @param string|null  $focusable Current focusable value of an SVG, null for i/span or when absent.
	 * @return void
	 */
	private function add_candidate( $control, array $element, $focusable ): void {
		if ( null === $control ) {
			return;
		}
		$control->data[ self::KEY ][] = array(
			'element'   => $element,
			'svg'       => 'SVG' === $element['tag'],
			'focusable' => $focusable,
		);
	}

	/**
	 * Resolve step, when a control closes: hides its icons if the control has a name.
	 *
	 * @param Element|null $control The closing link or button.
	 * @param Plan         $plan    Plan.
	 * @return void
	 */
	private function resolve( $control, Plan $plan ): void {
		if ( null === $control || empty( $control->data[ self::KEY ] ) ) {
			return;
		}
		// TODO(Phase 3): also treat the control as named when F05/F06 apply an aria-label to it in this mode (read their proposal from the plan), so icons in links and buttons they name are hidden too.
		if ( ! $control->is_named_control() ) {
			return;
		}
		foreach ( $control->data[ self::KEY ] as $icon ) {
			$this->plan_at( $plan, $icon['element'], 'aria-hidden', 'true', null );
			if ( $icon['svg'] && 'false' !== strtolower( (string) $icon['focusable'] ) ) {
				$this->plan_at( $plan, $icon['element'], 'focusable', 'false', $icon['focusable'] );
			}
		}
	}

	/**
	 * Lists an svg with role="img" and no name (report R05).
	 *
	 * @param Recorder $recorder  Recorder.
	 * @param string   $signature Element signature.
	 * @return void
	 */
	private function report_unnamed_svg( Recorder $recorder, string $signature ): void {
		$recorder->add_finding( 'R05', '1.1.1', $signature, __( 'SVG image with role="img" has no name: add a title element or an aria-label.', 'tagmend-accessibility-fixes' ) );
	}
}
