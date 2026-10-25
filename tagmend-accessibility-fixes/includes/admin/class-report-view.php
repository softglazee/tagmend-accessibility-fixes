<?php
/**
 * Labels and small formatters shared by the report and log screens.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Report\Scanner;
use SoftGlaze\AccessibilityRepair\Request_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated labels for report sources, fix states, checks, scopes and
 * fallback reasons, plus date and link helpers. Every method returns plain
 * text or a raw URL; the screens escape at output.
 */
final class Report_View {

	/**
	 * Object types stored with a report that are not posts.
	 */
	const NOT_POSTS = array( '', 'home', 'term' );

	/**
	 * Label of a report source.
	 *
	 * @param string $source 'admin_scan', 'admin_view' or 'cli'.
	 * @return string
	 */
	public static function source_label( string $source ): string {
		switch ( $source ) {
			case 'admin_scan':
				return __( 'Scan', 'tagmend-accessibility-fixes' );
			case 'admin_view':
				return __( 'View repaired', 'tagmend-accessibility-fixes' );
			case 'cli':
				return __( 'WP-CLI', 'tagmend-accessibility-fixes' );
			default:
				return __( 'Unknown', 'tagmend-accessibility-fixes' );
		}
	}

	/**
	 * Label of a fix state as it appears in a report.
	 *
	 * @param string $state 'live', 'preview' or 'off'.
	 * @return string
	 */
	public static function state_label( string $state ): string {
		switch ( $state ) {
			case 'live':
				return __( 'Live', 'tagmend-accessibility-fixes' );
			case 'preview':
				return __( 'Preview', 'tagmend-accessibility-fixes' );
			default:
				return __( 'Off (would change)', 'tagmend-accessibility-fixes' );
		}
	}

	/**
	 * Name of a report-only check (blueprint section 7).
	 *
	 * @param string $check Check id, for example 'R03'.
	 * @return string
	 */
	public static function check_label( string $check ): string {
		$labels = array(
			'R02' => __( 'Heading structure', 'tagmend-accessibility-fixes' ),
			'R03' => __( 'Alt text to review', 'tagmend-accessibility-fixes' ),
			'R04' => __( 'Images the plugin could not describe', 'tagmend-accessibility-fixes' ),
			'R05' => __( 'Links, buttons and fields the plugin could not name', 'tagmend-accessibility-fixes' ),
			'R06' => __( 'Fields without a visible label', 'tagmend-accessibility-fixes' ),
			'R07' => __( 'Link text that does not say where it goes', 'tagmend-accessibility-fixes' ),
			'R08' => __( 'Landmarks and skip links', 'tagmend-accessibility-fixes' ),
			'R09' => __( 'Page title', 'tagmend-accessibility-fixes' ),
			'R10' => __( 'Media that plays by itself', 'tagmend-accessibility-fixes' ),
			'R11' => __( 'Repeated ids the plugin did not change', 'tagmend-accessibility-fixes' ),
			'R12' => __( 'Controls that are not buttons or links', 'tagmend-accessibility-fixes' ),
			'R13' => __( 'Data tables without header cells', 'tagmend-accessibility-fixes' ),
		);
		return isset( $labels[ $check ] ) ? $labels[ $check ] : $check;
	}

	/**
	 * What the report's scope covers and why (blueprint sections 10.3 and 11).
	 *
	 * @param string      $scope  'page' or 'content'.
	 * @param string|null $reason Scope reason for content scope.
	 * @return string
	 */
	public static function scope_note( string $scope, $reason ): string {
		if ( 'page' === $scope ) {
			return __( 'This report covers the whole page.', 'tagmend-accessibility-fixes' );
		}
		switch ( (string) $reason ) {
			case Scanner::REASON_PAGE_BUFFER_OFF:
				return __( 'This report covers post content, template parts, widgets and menus only. Another plugin switched off the WordPress page buffer on this site (some page builders do), so the page-level checks (head, theme header and footer, skip links, repeated ids) did not run.', 'tagmend-accessibility-fixes' );
			case Scanner::REASON_NEEDS_WP69:
				return __( 'This report covers post content, template parts, widgets and menus only. Page-level checks (head, theme header and footer, skip links, repeated ids) need WordPress 6.9 or later.', 'tagmend-accessibility-fixes' );
			case Scanner::REASON_PAGE_PASS_OFF:
				return __( 'This report covers post content, template parts, widgets and menus only, because the whole-page repair is switched off in Settings.', 'tagmend-accessibility-fixes' );
			case Scanner::REASON_NO_PAGE_FIX:
				return __( 'This report covers post content, template parts, widgets and menus only: none of the Live or Preview fixes needed the whole page when this view was opened.', 'tagmend-accessibility-fixes' );
			default:
				return __( 'This report covers a part of the page only.', 'tagmend-accessibility-fixes' );
		}
	}

	/**
	 * Why the engine served a page, or a part of it, unchanged.
	 *
	 * @param string $reason Fallback reason.
	 * @return string
	 */
	public static function fallback_label( string $reason ): string {
		switch ( $reason ) {
			case 'size':
				return __( 'the page is larger than the size limit', 'tagmend-accessibility-fixes' );
			case 'time':
				return __( 'the repair took longer than the time limit', 'tagmend-accessibility-fixes' );
			case 'encoding':
				return __( 'the page is not valid UTF-8 text', 'tagmend-accessibility-fixes' );
			default:
				return sprintf(
					/* translators: %s: short technical reason, for example "sanity" */
					__( 'a safety check stopped the repair (%s)', 'tagmend-accessibility-fixes' ),
					$reason
				);
		}
	}

	/**
	 * A stored GMT date in the site's date and time format.
	 *
	 * @param string $gmt Date as Y-m-d H:i:s, GMT.
	 * @return string
	 */
	public static function date( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		return false === $formatted ? '' : $formatted;
	}

	/**
	 * Whether the post a report belongs to was modified after the report was
	 * made (blueprint section 10.3, stale marker); null when the page is not
	 * a single post or the post is gone.
	 *
	 * @param array $row Report row with object_id, object_type and scanned_gmt.
	 * @return bool|null
	 */
	public static function changed_since_scan( array $row ) {
		$id   = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
		$type = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
		if ( $id < 1 || in_array( $type, self::NOT_POSTS, true ) ) {
			return null;
		}
		$post    = get_post( $id );
		$scanned = strtotime( ( isset( $row['scanned_gmt'] ) ? (string) $row['scanned_gmt'] : '' ) . ' UTC' );
		if ( ! $post || false === $scanned ) {
			return null;
		}
		return (int) get_post_modified_time( 'U', true, $post ) > $scanned;
	}

	/**
	 * The post a report belongs to, when it is a single post that still exists.
	 *
	 * @param array $row Report row.
	 * @return int Post id, 0 when none.
	 */
	public static function post_id( array $row ): int {
		$id   = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
		$type = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
		if ( $id < 1 || in_array( $type, self::NOT_POSTS, true ) || ! get_post( $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * A front-end "View original" or "View repaired" link for a page, with a
	 * nonce for the softglaze_a11y_view action.
	 *
	 * @param string $url  Page URL.
	 * @param string $view 'original' or 'repaired'.
	 * @return string
	 */
	public static function view_url( string $url, string $view ): string {
		return add_query_arg(
			array(
				Request_Gate::VIEW_ARG       => 'repaired' === $view ? 'repaired' : 'original',
				Request_Gate::VIEW_NONCE_ARG => wp_create_nonce( Request_Gate::VIEW_ACTION ),
			),
			$url
		);
	}
}
