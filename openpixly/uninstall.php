<?php
/**
 * Fired when the plugin is deleted via the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'openpixel_settings' );
delete_option( 'openpixel_feed_settings' );
delete_option( 'openpixel_feed_status' );
delete_option( 'openpixel_feed_schedule_current' );
delete_option( 'openpixel_event_log' );
delete_option( 'openpixel_event_log_until' );

// Generated feed files.
$openpixel_uploads = wp_upload_dir();
$openpixel_dir     = trailingslashit( $openpixel_uploads['basedir'] ) . 'openpixly';
if ( is_dir( $openpixel_dir ) ) {
	foreach ( glob( $openpixel_dir . '/*' ) as $openpixel_file ) {
		if ( is_file( $openpixel_file ) ) {
			wp_delete_file( $openpixel_file );
		}
	}
	foreach ( array( '.htaccess' ) as $openpixel_hidden ) {
		if ( is_file( $openpixel_dir . '/' . $openpixel_hidden ) ) {
			wp_delete_file( $openpixel_dir . '/' . $openpixel_hidden );
		}
	}
	rmdir( $openpixel_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'openpixel_feed_scheduled_build', array(), 'openpixly' );
	as_unschedule_all_actions( 'openpixel_feed_build_batch', array(), 'openpixly' );
}
