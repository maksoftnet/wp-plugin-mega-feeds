<?php
namespace MegaFeeds;

use MegaFeeds\Feeds\FeedInterface;
use MegaFeeds\Feeds\Rss;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The set of known feed formats, keyed by id. Third parties and future
 * format adapters join through the `mega_feeds_register_feeds` filter.
 */
class Registry {

	private static $feeds = null;

	/**
	 * @return FeedInterface[] id => feed
	 */
	public static function all() {
		if ( null === self::$feeds ) {
			$list = apply_filters( 'mega_feeds_register_feeds', array( new Rss() ) );
			self::$feeds = array();
			foreach ( (array) $list as $feed ) {
				if ( $feed instanceof FeedInterface ) {
					self::$feeds[ $feed->id() ] = $feed;
				}
			}
		}
		return self::$feeds;
	}

	/**
	 * @return FeedInterface|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}
}
