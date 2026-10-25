<?php
/**
 * Suggested text for the site's privacy policy.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the plugin's paragraph to the privacy policy guide in Settings >
 * Privacy (blueprint section 15) through wp_add_privacy_policy_content().
 */
final class Privacy {

	/**
	 * Hooks the policy text into admin_init, where core expects it.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Hands the text to core's privacy policy guide.
	 *
	 * @return void
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Tagmend – Accessibility Fixes', 'tagmend-accessibility-fixes' ), self::policy_text() );
	}

	/**
	 * The suggested text, escaped as it is built.
	 *
	 * @return string HTML.
	 */
	public static function policy_text(): string {
		$paragraphs = array(
			__( 'This site uses Tagmend – Accessibility Fixes to repair common accessibility errors in the HTML of its pages.', 'tagmend-accessibility-fixes' ),
			__( 'The plugin stores scan reports about the pages of this site in the site database: element names and attributes such as the tag, class, link path and the text the plugin added, never form values. It also keeps an activity log of changes to its fixes and settings and of page scans, with the WordPress user IDs of the administrators who made them.', 'tagmend-accessibility-fixes' ),
			__( 'The plugin sets no cookies, collects nothing about visitors and sends nothing off this site.', 'tagmend-accessibility-fixes' ),
		);
		$html       = '';
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}
		return $html;
	}
}
