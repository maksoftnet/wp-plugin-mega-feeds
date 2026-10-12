<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together. Kept deliberately thin: every concern lives in
 * its own class and registers its own hooks.
 */
class Plugin {

	private static $booted = false;

	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Router::hooks();
		Cache::hooks();

		if ( is_admin() ) {
			Admin\SettingsPage::hooks();
		}
	}
}
