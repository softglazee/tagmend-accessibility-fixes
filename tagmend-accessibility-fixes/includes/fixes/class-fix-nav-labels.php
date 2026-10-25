<?php
/**
 * F18: theme menus printed in a nav element get a label.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Fixes;

use SoftGlaze\AccessibilityRepair\Document_Index;
use SoftGlaze\AccessibilityRepair\Fix_Base;
use SoftGlaze\AccessibilityRepair\Plan;
use SoftGlaze\AccessibilityRepair\Recorder;
use SoftGlaze\AccessibilityRepair\Token;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layer A only (wp_nav_menu_args): when wp_nav_menu() is asked for a nav
 * container and no container_aria_label, the label becomes the registered
 * description of the menu's theme location, else the menu's name. Core
 * escapes the value when it prints the nav element; the fix hands it plain
 * text. Navigation landmarks printed any other way are listed by R08.
 */
final class Fix_Nav_Labels extends Fix_Base {

	/**
	 * Stable id.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'nav-labels';
	}

	/**
	 * Admin label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Menu location names for theme menus', 'tagmend-accessibility-fixes' );
	}

	/**
	 * WCAG success criteria.
	 *
	 * @return string[]
	 */
	public function wcag(): array {
		return array( '1.3.1', '2.4.1' );
	}

	/**
	 * Axe rules.
	 *
	 * @return string[]
	 */
	public function axe_rules(): array {
		return array( 'landmark-unique' );
	}

	/**
	 * Scopes: the menu arguments, before the menu is printed.
	 *
	 * @return string[]
	 */
	public function scopes(): array {
		return array( 'attribute' );
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
	 * Takes no tokens: the engine never runs it.
	 *
	 * @return string[]
	 */
	public function tags(): array {
		return array( self::FINISH_ONLY );
	}

	/**
	 * Not used: the fix works on wp_nav_menu() arguments.
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
	 * Adds container_aria_label to wp_nav_menu() arguments that print an unlabelled nav.
	 *
	 * @param array $args Arguments after earlier wp_nav_menu_args callbacks.
	 * @return array
	 */
	public function filter_args( array $args ): array {
		if ( ! isset( $args['container'] ) || 'nav' !== $args['container'] ) {
			return $args;
		}
		$current = isset( $args['container_aria_label'] ) && is_string( $args['container_aria_label'] ) ? trim( $args['container_aria_label'] ) : '';
		if ( '' !== $current ) {
			return $args;
		}
		$label = self::plain_text( $this->label_for( $args ) );
		if ( '' !== $label ) {
			$args['container_aria_label'] = $label;
		}
		return $args;
	}

	/**
	 * The theme location's registered description, else the menu's name, else ''.
	 *
	 * @param array $args wp_nav_menu() arguments.
	 * @return string
	 */
	public function label_for( array $args ): string {
		$location = isset( $args['theme_location'] ) && is_string( $args['theme_location'] ) ? $args['theme_location'] : '';
		if ( '' !== $location ) {
			$registered = get_registered_nav_menus();
			if ( isset( $registered[ $location ] ) && is_string( $registered[ $location ] ) && '' !== trim( $registered[ $location ] ) ) {
				return $registered[ $location ];
			}
		}

		$menu = isset( $args['menu'] ) ? $args['menu'] : '';
		if ( ( '' === $menu || null === $menu ) && '' !== $location ) {
			$assigned = get_nav_menu_locations();
			$menu     = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
		}
		if ( $menu instanceof WP_Term ) {
			$object = $menu;
		} elseif ( is_int( $menu ) || ( is_string( $menu ) && '' !== $menu ) ) {
			$object = wp_get_nav_menu_object( $menu );
		} else {
			$object = null;
		}
		return $object instanceof WP_Term ? (string) $object->name : '';
	}
}
