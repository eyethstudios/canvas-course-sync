<?php
/**
 * Canvas Course Sync Email Settings Component
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CCS_Email_Settings {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Register settings on init
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'ccs_settings',
			'ccs_notification_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => get_option( 'admin_email' ),
			)
		);
		register_setting(
			'ccs_settings',
			'ccs_auto_sync_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);
	}

	/**
	 * Render email settings section
	 */
	public function render() {
		$auto_sync_enabled  = get_option( 'ccs_auto_sync_enabled', false );
		$notification_email = get_option( 'ccs_notification_email', get_option( 'admin_email' ) );
		?>
		<div class="ccs-panel">
			<h2><?php _e( 'Auto-Sync Settings', 'canvas-course-sync' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'ccs_settings' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ccs_auto_sync_enabled"><?php _e( 'Enable Auto-Sync', 'canvas-course-sync' ); ?></label>
						</th>
						<td>
							<label class="ccs-toggle-switch">
								<input type="checkbox" name="ccs_auto_sync_enabled" id="ccs_auto_sync_enabled" value="1" 
										<?php checked( $auto_sync_enabled, 1 ); ?> />
								<span class="ccs-toggle-slider"></span>
							</label>
							<p class="description"><?php _e( 'Automatically sync new courses from Canvas once per week.', 'canvas-course-sync' ); ?></p>
						</td>
					</tr>
					<tr id="ccs-email-row" style="<?php echo $auto_sync_enabled ? '' : 'display: none;'; ?>">
						<th scope="row">
							<label for="ccs_notification_email"><?php _e( 'Notification Email', 'canvas-course-sync' ); ?></label>
						</th>
						<td>
							<input type="email" name="ccs_notification_email" id="ccs_notification_email" class="regular-text" 
									value="<?php echo esc_attr( $notification_email ); ?>" 
									<?php echo $auto_sync_enabled ? 'required' : ''; ?> />
							<p class="description"><?php _e( 'Email address to receive notifications when new courses are synced.', 'canvas-course-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			
			<div id="ccs-manual-sync">
				<h3><?php _e( 'Manual Trigger', 'canvas-course-sync' ); ?></h3>
				<button id="ccs-trigger-auto-sync" class="button button-secondary">
					<?php _e( 'Run Auto-Sync Now', 'canvas-course-sync' ); ?>
				</button>
				<div id="ccs-auto-sync-result"></div>
			</div>
		</div>

		<?php
	}
}
?>
