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
	 * Stable slug used in the URL and in settings, e.g. "rss".
	 */
	public function id();

	/**
	 * Human readable name for the admin screen.
	 */
	public function label();

	/**
	 * MIME type of the document, without charset.
	 */
	public function content_type();

	/**
	 * @param array $args Normalized request arguments (limit, type).
	 * @return array {
	 *     @type string $body     The complete document.
	 *     @type int    $modified Unix time of the newest item, 0 if unknown.
	 * }
	 */
	public function render( array $args );
}
