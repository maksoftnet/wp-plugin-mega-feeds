<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendered-feed cache.
 *
 * Entries live in transients, which WordPress transparently routes to a
 * persistent object cache (Redis, Memcached, ...) when one is installed and
 * to the options table otherwise - so any caching mechanism present on the
 * site is used without this plugin knowing about it.
 *
 * Invalidation is generation based: every key embeds a generation token, and
 * "clearing the cache" is just moving the token. Stale entries are never
 * deleted explicitly, they simply stop being addressed and expire by TTL.
 */
class Cache {

	const GENERATION_OPTION = 'mega_feeds_generation';

	public static function hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'bump' ) );
		add_action( 'trashed_post', array( __CLASS__, 'bump' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'bump' ) );
		add_action( 'edited_term', array( __CLASS__, 'bump' ) );
		add_action( 'delete_term', array( __CLASS__, 'bump' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'bump' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'bump' ) );
	}

	public static function on_save_post( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::bump();
	}

	public static function generation() {
		$generation = get_option( self::GENERATION_OPTION );
		if ( ! $generation ) {
			$generation = self::bump();
		}
		return (string) $generation;
	}

	public static function bump() {
		$generation = (string) microtime( true );
		update_option( self::GENERATION_OPTION, $generation, true );
		return $generation;
	}

	public static function key( $feed_id, array $args ) {
		ksort( $args );
		return 'mf_' . md5( wp_json_encode( array( $feed_id, $args, self::generation(), MEGA_FEEDS_VERSION, Settings::get( 'content' ) ) ) );
	}

	/**
	 * @return array|null Cached entry (body, modified) or null on a miss.
	 */
	public static function get( $key ) {
		$entry = get_transient( $key );
		return ( is_array( $entry ) && isset( $entry['body'] ) ) ? $entry : null;
	}

	public static function set( $key, array $entry, $ttl ) {
		if ( $ttl > 0 ) {
			set_transient( $key, $entry, $ttl );
		}
	}
}
