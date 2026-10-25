<?php
/**
 * F16: focusable elements inside aria-hidden containers are taken out of the tab order.
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
 * A link, button, field, frame or tabindex element inside an element with
 * aria-hidden="true" can take keyboard focus while screen readers are told
 * it is not there. Such an element gets tabindex="-1", unless any of its
 * aria-hidden containers looks like content a script reveals later, where
 * a lasting tabindex="-1" would lock keyboard users out once it is shown:
 *
 * - its class contains menu, nav, dropdown, modal, popup, popover,
 *   offcanvas, off-canvas, drawer, dialog, tab, slide, carousel, swiper,
 *   accordion, collapse or flyout;
 * - its role is one of those widgets (dialog, menu, tabpanel and so on),
 *   or it is or sits inside a nav element;
 * - its id is named by any aria-controls attribute, by an in-page link
 *   (href="#id") or by a data-target, data-bs-target or data-toggle-target
 *   value "#id" on a link or button;
 * - it also carries hidden or inert (not focusable now, shown later);
 * - in a content fragment, it has an id at all (the control that reveals
 *   it may be outside the fragment).
 *
 * Elements that are already out of the tab order (negative tabindex,
 * disabled, hidden) are left alone. Off by default: high risk.
 */
final class Fix_Aria_Hidden_Focus extends Fix_Base {

	/**
	 * Key in Element::$data for what is known about an aria-hidden container.
	 */
	const DATA_KEY = 'aria-hidden-focus';

	/**
	 * Class name parts of containers that scripts reveal.
	 */
	const REVEALED_CLASSES = '/menu|nav|dropdown|modal|popup|popover|offcanvas|off-canvas|drawer|dialog|tab|slide|carousel|swiper|accordion|collapse|flyout/i';

	/**
	 * Roles of containers that scripts reveal.
	 */
	const REVEALED_ROLES = array(
		'alertdialog' => true,
		'dialog'      => true,
		'listbox'     => true,
		'menu'        => true,
		'menubar'     => true,
		'navigation'  => true,
		'tabpanel'    => true,
		'tooltip'     => true,
		'tree'        => true,
	);

	/**
	 * Attributes on links and buttons whose "#id" value names what they reveal.
	 */
	const TARGET_ATTRIBUTES = array( 'data-target', 'data-bs-target', 'data-toggle-target' );

	/**
	 * Focusable elements found inside aria-hidden containers: element snapshot,
	 * tabindex before, ids of the containers, the container frames.
	 *
	 * @var array[]
	 */
	private $candidates = array();

	/**
	 * Ids named by in-page links and data targets.
	 *
	 * @var bool[]
	 */
	private $referenced = array();

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'aria-hidden-focus';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Take focusable items in hidden content out of the tab order', 'tagmend-accessibility-fixes' );
	}

	/**
	 * What the fix changes, shown on the Fixes screen.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Takes links, buttons and fields inside other content hidden from screen readers out of the tab order. Starts Off: if a script later shows that content without restoring the tab order, keyboard users cannot reach those controls. Before you set it Live, test every page it changes with the keyboard: open each menu, panel and dialog and check that Tab reaches every control in it.', 'tagmend-accessibility-fixes' );
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
		return array( 'aria-hidden-focus' );
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
	 * Risk: a script that reveals the container without resetting tabindex
	 * (description() asks for a keyboard test on every page the fix changes).
	 *
	 * @return string
	 */
	public function risk(): string {
		return 'high';
	}

	/**
	 * Starts Off.
	 *
	 * @return bool
	 */
	public function recommended(): bool {
		return false;
	}

	/**
	 * Wins over F14 on the same element: inside hidden content, out of the tab order beats "0".
	 *
	 * @return int
	 */
	public function priority(): int {
		return 9;
	}

	/**
	 * Links and buttons (for the ids they point at), plus every opener in aria-hidden content.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( 'A', 'BUTTON', self::IN_HIDDEN );
	}

	/**
	 * Reads the ids named by aria-controls.
	 *
	 * @return bool
	 */
	public function uses_controls(): bool {
		return true;
	}

	/**
	 * Clears per-document state.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->candidates = array();
		$this->referenced = array();
	}

	/**
	 * Notes containers, the ids links and buttons point at, and focusable
	 * elements inside hidden content.
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
		if ( 'A' === $token->name || 'BUTTON' === $token->name ) {
			$this->note_targets( $token );
		}
		if ( null !== $token->element && $token->is_aria_hidden() ) {
			$token->element->data[ self::DATA_KEY ] = array(
				'revealed' => self::revealed( $token, $index ),
				'id'       => $token->attr_text( 'id' ),
			);
		}

		$ids        = array();
		$containers = array();
		foreach ( $token->ancestors() as $ancestor ) {
			if ( ! $ancestor->aria_hidden ) {
				continue;
			}
			$containers[] = $ancestor;
			if ( ! isset( $ancestor->data[ self::DATA_KEY ] ) || $ancestor->data[ self::DATA_KEY ]['revealed'] ) {
				return;
			}
			if ( '' !== $ancestor->data[ self::DATA_KEY ]['id'] ) {
				$ids[] = $ancestor->data[ self::DATA_KEY ]['id'];
			}
		}
		if ( empty( $containers ) || ! self::focusable( $token ) ) {
			return;
		}
		$tabindex           = $token->attr( 'tabindex' );
		$this->candidates[] = array(
			'element'    => $token->snapshot( $recorder->is_recording() ),
			'tabindex'   => is_string( $tabindex ) ? $tabindex : null,
			'ids'        => $ids,
			'containers' => $containers,
		);
	}

	/**
	 * Plans tabindex="-1" for each focusable element whose containers nothing
	 * points at. An element counts as inside hidden content only when one of
	 * its aria-hidden containers ended before the input did (by its end tag
	 * or an implied end): a container still open at the end may have lost its
	 * end tag, and everything after it would be taken out of the tab order.
	 *
	 * @param Document_Index $index    The complete index.
	 * @param Plan           $plan     Planned edits.
	 * @param Recorder       $recorder Changes and findings.
	 * @return void
	 */
	public function finish( Document_Index $index, Plan $plan, Recorder $recorder ): void {
		foreach ( $this->candidates as $candidate ) {
			$closed = false;
			foreach ( $candidate['containers'] as $container ) {
				if ( ! $container->open_at_end ) {
					$closed = true;
					break;
				}
			}
			if ( ! $closed ) {
				continue;
			}
			foreach ( $candidate['ids'] as $id ) {
				if ( $index->is_controlled( $id ) || isset( $this->referenced[ $id ] ) ) {
					continue 2;
				}
			}
			$this->plan_at( $plan, $candidate['element'], 'tabindex', '-1', $candidate['tabindex'] );
		}
		$this->candidates = array();
	}

	/**
	 * Records the ids an in-page link or a data target names.
	 *
	 * @param Token $token Link or button opener.
	 * @return void
	 */
	private function note_targets( Token $token ): void {
		$values = array( $token->attr_text( 'href' ) );
		foreach ( self::TARGET_ATTRIBUTES as $attribute ) {
			$values[] = $token->attr_text( $attribute );
		}
		foreach ( $values as $value ) {
			if ( strlen( $value ) > 1 && '#' === $value[0] && 1 !== preg_match( '/[\s,]/', $value ) ) {
				$id                      = substr( $value, 1 );
				$this->referenced[ $id ] = true;
				$decoded                 = rawurldecode( $id );
				if ( $decoded !== $id ) {
					$this->referenced[ $decoded ] = true;
				}
			}
		}
	}

	/**
	 * Whether an aria-hidden container looks like content a script reveals.
	 *
	 * @param Token          $token Container opener.
	 * @param Document_Index $index Index.
	 * @return bool
	 */
	private static function revealed( Token $token, Document_Index $index ): bool {
		if ( 'NAV' === $token->name || null !== $token->closest( 'NAV' ) || null !== $token->attr( 'hidden' ) || null !== $token->attr( 'inert' ) ) {
			return true;
		}
		if ( Engine::SCOPE_CONTENT === $index->scope && '' !== $token->attr_text( 'id' ) ) {
			return true;
		}
		$class = $token->attr_text( 'class' );
		if ( '' !== $class && 1 === preg_match( self::REVEALED_CLASSES, $class ) ) {
			return true;
		}
		foreach ( (array) preg_split( '/\s+/', strtolower( $token->attr_text( 'role' ) ), -1, PREG_SPLIT_NO_EMPTY ) as $role ) {
			if ( isset( self::REVEALED_ROLES[ $role ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an element takes focus with the Tab key.
	 *
	 * @param Token $token Opener.
	 * @return bool
	 */
	private static function focusable( Token $token ): bool {
		if ( null !== $token->attr( 'hidden' ) ) {
			return false;
		}
		$tabindex = $token->attr( 'tabindex' );
		if ( is_string( $tabindex ) ) {
			if ( 1 === preg_match( '/^[\t\n\f\r ]*-[0-9]/', $tabindex ) ) {
				return false;
			}
			if ( 1 === preg_match( '/^[\t\n\f\r ]*\+?[0-9]/', $tabindex ) ) {
				return true;
			}
		}
		$editable = $token->attr( 'contenteditable' );
		if ( true === $editable || ( is_string( $editable ) && 'false' !== strtolower( trim( $editable ) ) ) ) {
			return true;
		}
		switch ( $token->name ) {
			case 'A':
			case 'AREA':
				return null !== $token->attr( 'href' );
			case 'BUTTON':
			case 'SELECT':
			case 'TEXTAREA':
				return null === $token->attr( 'disabled' );
			case 'INPUT':
				return null === $token->attr( 'disabled' ) && 'hidden' !== strtolower( $token->attr_text( 'type' ) );
			case 'IFRAME':
			case 'EMBED':
			case 'SUMMARY':
				return true;
			case 'AUDIO':
			case 'VIDEO':
				return null !== $token->attr( 'controls' );
		}
		return false;
	}
}
