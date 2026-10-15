<?php
/**
 * Uninstall routine. WordPress calls this file directly when the plugin is
 * deleted from wp-admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function mega_feeds_uninstall_for_site() {
	wp_clear_scheduled_hook( 'mega_feeds_warm' );
	wp_clear_scheduled_hook( 'mega_feeds_regenerate_all' );
	delete_option( 'mega_feeds_settings' );
	delete_option( 'mega_feeds_generation' );
	delete_option( 'mega_feeds_db_version' );

	// Stored feeds live in this site's uploads folder.
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'mega-feeds/';
	if ( is_dir( $dir ) ) {
		foreach ( (array) glob( $dir . '{*,.htaccess}', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $dir );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
		switch_to_blog( $site_id );
		mega_feeds_uninstall_for_site();
		restore_current_blog();
	}
} else {
	mega_feeds_uninstall_for_site();
}
