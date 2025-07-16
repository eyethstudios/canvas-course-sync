<?php
/**
 * Uninstall Canvas Course Sync
 *
 * @package Canvas_Course_Sync
 */

// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options
delete_option( 'ccs_catalog_token' );
delete_option( 'ccs_version' );
delete_option( 'ccs_flush_rewrite_rules' );

// Clean up logs directory
$upload_dir = wp_upload_dir();
$log_dir    = $upload_dir['basedir'] . '/canvas-course-sync/logs';

if ( file_exists( $log_dir ) ) {
	$files = glob( $log_dir . '/*' );
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}
	@rmdir( $log_dir );
	@rmdir( dirname( $log_dir ) );
}

// Delete all courses posts and their meta
$courses = get_posts(
	array(
		'post_type'   => 'courses',
		'numberposts' => -1,
		'post_status' => 'any',
	)
);

foreach ( $courses as $course ) {
	wp_delete_post( $course->ID, true );
}
