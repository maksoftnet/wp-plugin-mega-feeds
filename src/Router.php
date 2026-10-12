<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a request to a feed and answers it.
 *
 * Two equivalent addresses, so a feed works with or without pretty
 * permalinks:
 *
 *   /mega-feeds/rss/?limit=10&type=post
 *   /?mega_feed=rss&limit=10&type=post
 *
 * The response is produced at `parse_request`, before the main query runs, so
 * a feed hit costs one targeted query (or none, on a cache hit).
 */
class Router {

	const QUERY_VAR = 'mega_feed';
	const BASE      = 'mega-feeds';

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ) );
	}

	public static function add_rules() {
		add_rewrite_rule(
			'^' . self::BASE . '/([a-z0-9_-]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Public URL of a feed, using whichever form the site can resolve.
	 */
	public static function url( $feed_id, array $args = array() ) {
		if ( get_option( 'permalink_structure' ) ) {
			$url = home_url( '/' . self::BASE . '/' . $feed_id . '/' );
		} else {
			$url = add_query_arg( self::QUERY_VAR, $feed_id, home_url( '/' ) );
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public static function maybe_serve( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		self::serve( sanitize_key( $wp->query_vars[ self::QUERY_VAR ] ) );
	}

	/**
	 * Turn the query string into a normalized, bounded argument set. Always
	 * returns the same keys, so equal requests share one cache entry.
	 */
	private static function request_args() {
		$limit_max = (int) Settings::get( 'limit_max' );
		$limit     = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $limit < 1 ) {
			$limit = (int) Settings::get( 'limit_default' );
		}
		$limit = max( 1, min( $limit_max, $limit ) );

		$allowed = Settings::allowed_post_types();
		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = $allowed[0];
		}

		return array(
			'limit' => $limit,
			'type'  => $type,
		);
	}

	private static function serve( $feed_id ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}

		$feed = Registry::get( $feed_id );
		if ( ! $feed || ! Settings::feed_enabled( $feed_id ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Feed not found.';
			exit;
		}

		$args  = self::request_args();
		$ttl   = (int) Settings::get( 'cache_ttl' );
		$key   = Cache::key( $feed_id, $args );
		$entry = $ttl > 0 ? Cache::get( $key ) : null;
		$state = 'HIT';

		if ( null === $entry ) {
			$state = $ttl > 0 ? 'MISS' : 'BYPASS';
			$entry = $feed->render( $args );
			Cache::set( $key, $entry, $ttl );
		}

		$etag          = '"' . md5( $entry['body'] ) . '"';
		$modified      = isset( $entry['modified'] ) ? (int) $entry['modified'] : 0;
		$last_modified = $modified > 0 ? gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' : '';

		header( 'Content-Type: ' . $feed->content_type() . '; charset=UTF-8' );
		header( 'ETag: ' . $etag );
		if ( $last_modified ) {
			header( 'Last-Modified: ' . $last_modified );
		}
		header( 'Cache-Control: public, max-age=' . max( 0, $ttl ) );
		header( 'X-Mega-Feeds-Cache: ' . $state );
		header( 'X-Content-Type-Options: nosniff' );

		if ( self::not_modified( $etag, $modified ) ) {
			status_header( 304 );
			exit;
		}

		status_header( 200 );
		if ( 'HEAD' !== $method ) {
			echo $entry['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered, escaped XML.
		}
		exit;
	}

	/**
	 * Conditional GET: If-None-Match wins; If-Modified-Since is only
	 * consulted when the client sent no ETag, as RFC 7232 requires.
	 */
	private static function not_modified( $etag, $modified ) {
		if ( ! empty( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
			$candidates = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );
			return in_array( $etag, $candidates, true ) || in_array( '*', $candidates, true );
		}
		if ( $modified > 0 && ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
			return false !== $since && $since >= $modified;
		}
		return false;
	}
}
