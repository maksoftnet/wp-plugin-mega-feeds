<?php
namespace MegaFeeds;

use MegaFeeds\Feeds\FeedInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a request to a feed and answers it.
 *
 * Every feed owns a first URL segment (its path) and optional scopes:
 *
 *   /rss/                   central feed, newest changes of every type
 *   /rss/posts/             one content type
 *   /rss/?limit=10          bounded
 *   /?mega_feed=rss&mega_scope=posts     the same, without pretty permalinks
 *
 * The response is produced at `parse_request`, before the main query runs, so
 * a feed hit costs one targeted query (or none, on a cache hit).
 */
class Router {

	const QUERY_VAR = 'mega_feed';
	const SCOPE_VAR = 'mega_scope';

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'add_rules' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ) );
	}

	/**
	 * Two rules per feed. Registered at 'top' so they win over WordPress's
	 * own legacy /rss/ alias.
	 */
	public static function add_rules() {
		foreach ( Registry::all() as $id => $feed ) {
			$path = preg_quote( trim( $feed->path(), '/' ), '#' );
			add_rewrite_rule( '^' . $path . '/?$', 'index.php?' . self::QUERY_VAR . '=' . $id, 'top' );
			add_rewrite_rule( '^' . $path . '/([a-z0-9_-]+)/?$', 'index.php?' . self::QUERY_VAR . '=' . $id . '&' . self::SCOPE_VAR . '=$matches[1]', 'top' );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::SCOPE_VAR;
		return $vars;
	}

	/**
	 * Public URL of a feed scope, using whichever form the site can resolve.
	 */
	public static function url( FeedInterface $feed, $scope = '', array $args = array() ) {
		if ( get_option( 'permalink_structure' ) ) {
			$path = '/' . trim( $feed->path(), '/' ) . '/' . ( '' !== $scope ? $scope . '/' : '' );
			$url  = home_url( $path );
		} else {
			$url = add_query_arg( self::QUERY_VAR, $feed->id(), home_url( '/' ) );
			if ( '' !== $scope ) {
				$url = add_query_arg( self::SCOPE_VAR, $scope, $url );
			}
		}
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	public static function maybe_serve( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		$scope = isset( $wp->query_vars[ self::SCOPE_VAR ] ) ? sanitize_key( $wp->query_vars[ self::SCOPE_VAR ] ) : '';
		self::serve( sanitize_key( $wp->query_vars[ self::QUERY_VAR ] ), $scope );
	}

	/**
	 * Turn the query string into a normalized, bounded argument set. Always
	 * returns the same keys, so equal requests share one cache entry.
	 */
	private static function request_args( FeedInterface $feed, $scope ) {
		$prefix    = ( 'products' === $feed->kind() ) ? 'product_' : '';
		$limit_max = (int) Settings::get( $prefix . 'limit_max' );
		$limit     = isset( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $limit < 1 ) {
			$limit = (int) Settings::get( $prefix . 'limit_default' );
		}

		return array(
			'limit' => max( 1, min( $limit_max, $limit ) ),
			'scope' => $scope,
		);
	}

	private static function not_found() {
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Feed not found.';
		exit;
	}

	private static function serve( $feed_id, $raw_scope ) {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD' );
			exit;
		}

		$feed = Registry::get( $feed_id );
		if ( ! $feed || ! Settings::feed_enabled( $feed_id ) ) {
			self::not_found();
		}

		$scope = $feed->resolve_scope( $raw_scope );
		if ( null === $scope ) {
			self::not_found();
		}

		$args  = self::request_args( $feed, $scope );
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
