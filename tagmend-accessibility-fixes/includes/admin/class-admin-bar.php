<?php
/**
 * The "Tagmend" node in the front-end admin bar.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Request_Gate;
use SoftGlaze\AccessibilityRepair\Settings;
use WP_Admin_Bar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "View original" and "View repaired (Live + Preview)" links for the
 * current page, each carrying a nonce for the softglaze_a11y_view action
 * (blueprint section 10.2). Front end only, users who can manage options
 * only, no CSS or JavaScript.
 */
final class Admin_Bar {

	const NODE = 'softglaze-a11y';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Request gate (current URI).
	 *
	 * @var Request_Gate
	 */
	private $gate;

	/**
	 * Creates the node builder.
	 *
	 * @param Settings     $settings Settings.
	 * @param Request_Gate $gate     Request gate.
	 */
	public function __construct( Settings $settings, Request_Gate $gate ) {
		$this->settings = $settings;
		$this->gate     = $gate;
	}

	/**
	 * Hooks the node into the admin bar.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_nodes' ), 100 );
	}

	/**
	 * Adds the node and its view links.
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar.
	 * @return void
	 */
	public function add_nodes( $admin_bar ) {
		if ( ! $admin_bar instanceof WP_Admin_Bar || is_admin() || ! current_user_can( 'manage_options' ) || ! $this->settings->admin_bar_enabled() ) {
			return;
		}

		$page_uri = remove_query_arg( array( Request_Gate::VIEW_ARG, Request_Gate::VIEW_NONCE_ARG ), $this->gate->request_uri() );
		$nonce    = wp_create_nonce( Request_Gate::VIEW_ACTION );

		$node_title_escaped     = esc_html__( 'Tagmend', 'tagmend-accessibility-fixes' );
		$original_title_escaped = esc_html__( 'View original', 'tagmend-accessibility-fixes' );
		$repaired_title_escaped = esc_html__( 'View repaired (Live + Preview)', 'tagmend-accessibility-fixes' );

		$admin_bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => $node_title_escaped,
				'href'  => admin_url( 'admin.php?page=' . Screen_Fixes::PAGE ),
			)
		);
		$admin_bar->add_node(
			array(
				'parent' => self::NODE,
				'id'     => self::NODE . '-view-original',
				'title'  => $original_title_escaped,
				'href'   => add_query_arg(
					array(
						Request_Gate::VIEW_ARG       => 'original',
						Request_Gate::VIEW_NONCE_ARG => $nonce,
					),
					$page_uri
				),
			)
		);
		$admin_bar->add_node(
			array(
				'parent' => self::NODE,
				'id'     => self::NODE . '-view-repaired',
				'title'  => $repaired_title_escaped,
				'href'   => add_query_arg(
					array(
						Request_Gate::VIEW_ARG       => 'repaired',
						Request_Gate::VIEW_NONCE_ARG => $nonce,
					),
					$page_uri
				),
			)
		);
		// TODO(Phase 6A): "Changes on this page (N)" and "Scan this page"; no node in scan mode. TODO(Phase 7): the Settings toggle for this node.
	}
}
