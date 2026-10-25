<?php
/**
 * The Fixes screen: one Off / Preview / Live choice per fix.
 *
 * @package SoftGlaze\AccessibilityRepair
 */

namespace SoftGlaze\AccessibilityRepair\Admin;

use SoftGlaze\AccessibilityRepair\Fix;
use SoftGlaze\AccessibilityRepair\Registry;
use SoftGlaze\AccessibilityRepair\Settings;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every registered fix as a fieldset of three radio buttons and saves
 * the choices through admin-post.php (capability first, then the nonce).
 * The result is shown inline on this screen; no admin notice hook is used.
 */
final class Screen_Fixes {

	const PAGE        = 'softglaze-a11y-fixes';
	const ACTION      = 'softglaze_a11y_save_fixes';
	const NONCE_FIELD = 'softglaze_a11y_fixes_nonce';
	const FIELD       = 'softglaze_a11y_fix';
	const STATUS_ARG  = 'softglaze_a11y_saved';

	/**
	 * Fix list.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Creates the screen.
	 *
	 * @param Registry $registry Fix list.
	 * @param Settings $settings Settings.
	 */
	public function __construct( Registry $registry, Settings $settings ) {
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Hooks the save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Adds the submenu page.
	 *
	 * @param string $parent_slug Top-level menu slug.
	 * @return void
	 */
	public function add_page( string $parent_slug ): void {
		add_submenu_page(
			$parent_slug,
			__( 'Fixes', 'tagmend-accessibility-fixes' ),
			__( 'Fixes', 'tagmend-accessibility-fixes' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Handles the admin-post.php request: saves, then returns to the screen.
	 *
	 * @return void
	 */
	public function handle_save() {
		wp_safe_redirect( $this->process_save() );
		exit;
	}

	/**
	 * Checks the capability, then the nonce, then saves the submitted states.
	 * Each state is read by fix id from the request and sanitized at the read;
	 * the sanitize callback validates it again. Returns the screen URL.
	 *
	 * @return string
	 */
	public function process_save(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change fix states.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die(
				esc_html__( 'This form has expired. Open the Fixes screen again and save once more.', 'tagmend-accessibility-fixes' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$states = array();
		foreach ( $this->registry->ids() as $id ) {
			if ( isset( $_POST[ self::FIELD ][ $id ] ) ) {
				$states[ $id ] = sanitize_key( wp_unslash( $_POST[ self::FIELD ][ $id ] ) );
			}
		}

		$rejected = empty( $states ) ? array() : $this->settings->save_fix_states( $states, get_current_user_id() );
		// TODO(Phase 7): name the detected page-cache plugin and remind the admin to purge it (decision D-05: reminder only).

		return add_query_arg(
			array(
				'page'           => self::PAGE,
				self::STATUS_ARG => empty( $rejected ) ? 'saved' : 'rejected',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->status();
		$states = $this->settings->fix_states();
		$labels = array(
			'off'     => __( 'Off', 'tagmend-accessibility-fixes' ),
			'preview' => __( 'Preview', 'tagmend-accessibility-fixes' ),
			'live'    => __( 'Live', 'tagmend-accessibility-fixes' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Fixes', 'tagmend-accessibility-fixes' ); ?></h1>
			<?php if ( 'saved' === $status ) : ?>
				<div class="notice notice-success inline" role="status"><p><?php echo esc_html__( 'Fix states saved.', 'tagmend-accessibility-fixes' ); ?></p></div>
			<?php elseif ( 'rejected' === $status ) : ?>
				<div class="notice notice-warning inline" role="status"><p><?php echo esc_html__( 'Some fix states could not be saved and kept their previous value.', 'tagmend-accessibility-fixes' ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Off: never applied. Preview: applied only when you open a page with "View repaired" in the admin bar. Live: applied for every visitor.', 'tagmend-accessibility-fixes' ); ?></p>
			<?php // TODO(Phase 7): groups, what each fix changes, "would change N on scanned pages", "Move all Preview fixes to Live", focus on the status message. ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION, self::NONCE_FIELD ); ?>
				<table class="form-table" role="presentation">
					<tbody>
					<?php
					foreach ( $this->registry->fixes() as $id => $fix ) {
						$this->render_row( $id, $fix, isset( $states[ $id ] ) ? $states[ $id ] : 'off', $labels );
					}
					?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save fix states', 'tagmend-accessibility-fixes' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one fix as a table row holding a fieldset of three radio buttons.
	 * Live is disabled, with the reason in text, when the fix cannot run here.
	 *
	 * @param string   $id     Fix id.
	 * @param Fix      $fix    Fix.
	 * @param string   $state  Saved state.
	 * @param string[] $labels State => translated label.
	 * @return void
	 */
	private function render_row( string $id, Fix $fix, string $state, array $labels ) {
		try {
			$label = $fix->label();
			$wcag  = implode( ', ', array_map( 'strval', (array) $fix->wcag() ) );
			$risk  = $this->risk_label( $fix->risk() );
		} catch ( Throwable $throwable ) {
			return;
		}
		$reason   = $this->registry->unavailable_reason( $fix );
		$field_id = 'softglaze-a11y-fix-' . $id;
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo esc_html( $label ); ?></span></legend>
					<?php foreach ( $labels as $value => $text ) : ?>
						<?php $disabled = 'live' === $value && '' !== $reason && 'live' !== $state; ?>
						<label>
							<input type="radio" name="<?php echo esc_attr( self::FIELD . '[' . $id . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>"<?php checked( $state, $value ); ?><?php disabled( $disabled ); ?><?php echo $disabled ? ' aria-describedby="' . esc_attr( $field_id . '-reason' ) . '"' : ''; ?>>
							<?php echo esc_html( $text ); ?>
						</label><br>
					<?php endforeach; ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: WCAG success criterion numbers, for example 4.1.2; 2: risk level, for example Low */
								__( 'WCAG %1$s. Risk: %2$s.', 'tagmend-accessibility-fixes' ),
								$wcag,
								$risk
							)
						);
						?>
					</p>
					<?php if ( '' !== $reason ) : ?>
						<p class="description" id="<?php echo esc_attr( $field_id . '-reason' ); ?>"><?php echo esc_html( $reason ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * The result of the last save, from the redirect back to this screen.
	 * Display-only and allow-listed, so no nonce is needed to read it.
	 *
	 * @return string 'saved', 'rejected' or ''.
	 */
	private function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status after a redirect; allow-listed below.
		$status = isset( $_GET[ self::STATUS_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_ARG ] ) ) : '';
		return in_array( $status, array( 'saved', 'rejected' ), true ) ? $status : '';
	}

	/**
	 * Translated risk level.
	 *
	 * @param string $risk 'low', 'medium' or 'high'.
	 * @return string
	 */
	private function risk_label( string $risk ): string {
		switch ( $risk ) {
			case 'low':
				return __( 'Low', 'tagmend-accessibility-fixes' );
			case 'medium':
				return __( 'Medium', 'tagmend-accessibility-fixes' );
			case 'high':
				return __( 'High', 'tagmend-accessibility-fixes' );
			default:
				return __( 'Unknown', 'tagmend-accessibility-fixes' );
		}
	}
}
