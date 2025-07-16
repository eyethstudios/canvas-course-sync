<?php
/**
 * Canvas Course Sync Logs Display
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs Display class
 */
class CCS_Logs_Display {
	/**
	 * Logger instance
	 *
	 * @var CCS_Logger
	 */
	private $logger;

	/**
	 * Constructor
	 */
	public function __construct() {
		$canvas_course_sync = canvas_course_sync();
		$this->logger       = ( $canvas_course_sync && isset( $canvas_course_sync->logger ) ) ? $canvas_course_sync->logger : null;
	}

	/**
	 * Render logs page
	 */
	public function render() {
		// Scripts are already enqueued by main plugin

		?>
		<div class="wrap">
			<h1><?php _e( 'Canvas Course Sync - Logs', 'canvas-course-sync' ); ?></h1>
			
			<div class="ccs-logs-container">
				<div class="ccs-logs-controls" style="margin: 20px 0;">
					<button type="button" id="ccs-refresh-logs" class="button button-secondary">
						<?php _e( 'Refresh Logs', 'canvas-course-sync' ); ?>
					</button>
					<button type="button" id="ccs-clear-logs" class="button button-secondary" style="margin-left: 10px;">
						<?php _e( 'Clear All Logs', 'canvas-course-sync' ); ?>
					</button>
				</div>
				
				<?php if ( $this->logger ) : ?>
					<div id="ccs-logs-display">
						<?php $this->display_logs(); ?>
					</div>
				<?php else : ?>
					<div class="notice notice-error">
						<p><?php _e( 'Logger not available. Please check plugin installation.', 'canvas-course-sync' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Display logs table
	 */
	private function display_logs() {
		$logs = $this->logger ? $this->logger->get_recent_logs( 50 ) : array();

		if ( empty( $logs ) ) {
			echo '<div class="notice notice-info"><p>' . __( 'No logs found.', 'canvas-course-sync' ) . '</p></div>';
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col" style="width: 150px;"><?php _e( 'Timestamp', 'canvas-course-sync' ); ?></th>
					<th scope="col" style="width: 80px;"><?php _e( 'Level', 'canvas-course-sync' ); ?></th>
					<th scope="col"><?php _e( 'Message', 'canvas-course-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td>
							<?php
							$timestamp = isset( $log->timestamp ) ? $log->timestamp : '';
							echo esc_html( mysql2date( 'Y-m-d H:i:s', $timestamp ) );
							?>
						</td>
						<td>
							<span class="ccs-log-level ccs-log-level-<?php echo esc_attr( $log->level ?? 'info' ); ?>">
								<?php echo esc_html( strtoupper( $log->level ?? 'INFO' ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $log->message ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
