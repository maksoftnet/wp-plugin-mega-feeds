<?php
namespace MegaFeeds\Feeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One output format. A feed knows how to turn a normalized argument set into
 * a document; routing, caching and HTTP semantics belong to the Router.
 */
interface FeedInterface {

	/**
	 * Stable slug used in settings and as the query var value, e.g. "rss".
	 */
	public function id();

	/**
	 * Human readable name for the admin screen.
	 */
	public function label();

	/**
	 * First URL segment, e.g. "rss" for /rss/ and /rss/posts/.
	 */
	public function path();

	/**
	 * MIME type of the document, without charset.
	 */
	public function content_type();

	/**
	 * "content" feeds list posts/pages and use the content limits, "products"
	 * feeds list catalogue offers and use the (larger) product limits.
	 */
	public function kind();

	/**
	 * Every scope the feed answers, canonical name => label. The empty
	 * string is the feed's central address.
	 *
	 * @return array
	 */
	public function scopes();

	/**
	 * Map a raw URL segment (which may be an alias) to a canonical scope,
	 * or null when the feed does not answer it.
	 *
	 * @param string $raw
	 * @return string|null
	 */
	public function resolve_scope( $raw );

	/**
	 * @param array $args Normalized request arguments (limit, scope).
	 * @return array {
	 *     @type string $body     The complete document.
	 *     @type int    $modified Unix time of the newest item, 0 if unknown.
	 * }
	 */
	public function render( array $args );
}
