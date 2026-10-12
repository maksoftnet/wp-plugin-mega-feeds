<?php
/**
 * Plugin Name:       Mega Feeds
 * Description:       Serve your site content as dynamic, cached feeds for aggregators, social networks and marketplaces. Starts with RSS 2.0.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Maksoft
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mega-feeds
 *
 * Network-safe by construction: works whether the plugin is activated on a
 * single site or network-activated across a multisite network. Nothing here
 * assumes a single blog.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEGA_FEEDS_VERSION', '0.1.0' );
define( 'MEGA_FEEDS_DB_VERSION', '0.1.0' );
define( 'MEGA_FEEDS_FILE', __FILE__ );
define( 'MEGA_FEEDS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 style autoloader: MegaFeeds\Feeds\Rss -> src/Feeds/Rss.php
 */
spl_autoload_register( function ( $class ) {
	$prefix = 'MegaFeeds\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = MEGA_FEEDS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_readable( $file ) ) {
		require $file;
	}
} );

/**
 * Per-site setup: register the rewrite rules, flush them once and record the
 * schema version. Runs on activation, for every site of a network-wide
 * activation, for sites created later, and whenever the version gate fails.
 */
function mega_feeds_install_for_site() {
	\MegaFeeds\Router::add_rules();
	flush_rewrite_rules( false );
	\MegaFeeds\Cache::bump();
	update_option( 'mega_feeds_db_version', MEGA_FEEDS_DB_VERSION );
}

function mega_feeds_activate( $network_wide ) {
	if ( $network_wide && function_exists( 'get_sites' ) ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
			switch_to_blog( $site_id );
			mega_feeds_install_for_site();
			restore_current_blog();
		}
	} else {
		mega_feeds_install_for_site();
	}
}
register_activation_hook( __FILE__, 'mega_feeds_activate' );

function mega_feeds_deactivate() {
	flush_rewrite_rules( false );
}
register_deactivation_hook( __FILE__, 'mega_feeds_deactivate' );

/**
 * A site created after a network-wide activation never fires the activation
 * hook, so cover it explicitly.
 */
add_action( 'wp_initialize_site', function ( $new_site ) {
	if ( ! is_plugin_active_for_network( plugin_basename( MEGA_FEEDS_FILE ) ) ) {
		return;
	}
	switch_to_blog( $new_site->blog_id );
	mega_feeds_install_for_site();
	restore_current_blog();
}, 10, 1 );

/**
 * Version gate on every load: in the lab the files change underneath
 * WordPress with no "update" event, so the plugin re-checks its own schema
 * version instead of relying on activation.
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'mega_feeds_db_version' ) !== MEGA_FEEDS_DB_VERSION ) {
		add_action( 'init', 'mega_feeds_install_for_site', 99 );
	}
	\MegaFeeds\Plugin::boot();
} );
