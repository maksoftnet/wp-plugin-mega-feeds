<?php
/**
 * Uninstall routine. WordPress calls this file directly when the plugin is
 * deleted from wp-admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function mega_feeds_uninstall_for_site() {
	delete_option( 'mega_feeds_settings' );
	delete_option( 'mega_feeds_generation' );
	delete_option( 'mega_feeds_db_version' );
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
