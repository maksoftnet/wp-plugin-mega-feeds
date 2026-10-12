<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single option holding every setting, with defaults so the plugin works the
 * moment it is activated. Anything the user does not set falls back here.
 */
class Settings {

	const OPTION = 'mega_feeds_settings';

	public static function defaults() {
		return array(
			// Items per feed when the request does not ask for a number.
			'limit_default' => 20,
			// Hard ceiling: a request can never ask for more than this.
			'limit_max'     => 100,
			// Seconds a rendered feed is reused. 0 disables caching.
			'cache_ttl'     => 300,
			// 'full' puts the whole post in the feed, 'excerpt' only a summary.
			'content'       => 'full',
			// Post types that may be exported.
			'post_types'    => array( 'post' ),
			// Feed id => enabled. Feeds not listed default to enabled.
			'feeds'         => array(),
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function feed_enabled( $feed_id ) {
		$feeds = self::get( 'feeds' );
		if ( ! is_array( $feeds ) || ! array_key_exists( $feed_id, $feeds ) ) {
			return true;
		}
		return (bool) $feeds[ $feed_id ];
	}

	/**
	 * Post types the site owner may expose: public ones, minus attachments.
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Post types currently allowed for export, always a non-empty subset of
	 * the available ones.
	 */
	public static function allowed_post_types() {
		$available = array_keys( self::available_post_types() );
		$chosen    = array_values( array_intersect( (array) self::get( 'post_types' ), $available ) );
		if ( empty( $chosen ) ) {
			$chosen = in_array( 'post', $available, true ) ? array( 'post' ) : array_slice( $available, 0, 1 );
		}
		return $chosen;
	}

	/**
	 * Settings API sanitize callback.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$limit_max = isset( $input['limit_max'] ) ? absint( $input['limit_max'] ) : $defaults['limit_max'];
		$limit_max = max( 1, min( 500, $limit_max ) );

		$limit_default = isset( $input['limit_default'] ) ? absint( $input['limit_default'] ) : $defaults['limit_default'];
		$limit_default = max( 1, min( $limit_max, $limit_default ) );

		$out['limit_max']     = $limit_max;
		$out['limit_default'] = $limit_default;
		$out['cache_ttl']     = isset( $input['cache_ttl'] ) ? max( 0, min( DAY_IN_SECONDS, absint( $input['cache_ttl'] ) ) ) : $defaults['cache_ttl'];
		$out['content']       = ( isset( $input['content'] ) && 'excerpt' === $input['content'] ) ? 'excerpt' : 'full';

		$available          = array_keys( self::available_post_types() );
		$types              = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$out['post_types']  = array_values( array_intersect( $types, $available ) );

		$out['feeds'] = array();
		foreach ( array_keys( Registry::all() ) as $feed_id ) {
			$out['feeds'][ $feed_id ] = ! empty( $input['feeds'][ $feed_id ] ) ? 1 : 0;
		}

		// Settings changed: rendered feeds are stale.
		Cache::bump();

		return $out;
	}
}
