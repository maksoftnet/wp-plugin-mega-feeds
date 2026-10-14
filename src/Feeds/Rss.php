<?php
namespace MegaFeeds\Feeds;

use MegaFeeds\Images;
use MegaFeeds\Router;
use MegaFeeds\Settings;
use MegaFeeds\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RSS 2.0 with the content, Dublin Core, Atom and Media RSS extensions that
 * aggregators and social tools actually read.
 *
 *   /rss/            newest changes across every exported type
 *   /rss/posts/      posts only
 *   /rss/pages/      pages only
 *   /rss/products/   products only (when WooCommerce is active)
 *
 * Items are ordered by last modification, newest first, so a consumer that
 * polls sees corrections as well as new content. Each item carries its main
 * image (enclosure + media:content isDefault) and any additional images the
 * page has (further media:content elements).
 */
class Rss implements FeedInterface {

	public function id() {
		return 'rss';
	}

	public function label() {
		return __( 'RSS 2.0', 'mega-feeds' );
	}

	public function path() {
		return trim( (string) apply_filters( 'mega_feeds_rss_path', 'rss' ), '/' );
	}

	public function content_type() {
		return 'application/rss+xml';
	}

	public function kind() {
		return 'content';
	}

	public function scopes() {
		$scopes = array( '' => __( 'Latest changes (all content)', 'mega-feeds' ) );
		foreach ( Settings::type_scopes() as $scope => $type ) {
			$object = get_post_type_object( $type );
			$scopes[ $scope ] = $object ? $object->labels->name : $type;
		}
		return $scopes;
	}

	/**
	 * Canonical plural names (posts, pages, products) plus the singular
	 * post-type slug (post, page, product) as an alias.
	 */
	public function resolve_scope( $raw ) {
		if ( '' === $raw ) {
			return '';
		}
		$map = Settings::type_scopes();
		if ( isset( $map[ $raw ] ) ) {
			return $raw;
		}
		$by_type = array_flip( $map );
		return isset( $by_type[ $raw ] ) ? $by_type[ $raw ] : null;
	}

	public function render( array $args ) {
		$types = Settings::types_for_scope( $args['scope'] );

		$query = array(
			'post_type'           => $types ? $types : array( 'post' ),
			'post_status'         => 'publish',
			'posts_per_page'      => $args['limit'],
			'orderby'             => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false,
		);
		$excluded = $this->excluded_ids( $types );
		if ( $excluded ) {
			$query['post__not_in'] = $excluded;
		}
		$posts = get_posts( $query );

		$full     = ( 'full' === Settings::get( 'content' ) );
		$ttl      = (int) Settings::get( 'cache_ttl' );
		$modified = 0;
		foreach ( $posts as $post ) {
			$modified = max( $modified, (int) strtotime( $post->post_modified_gmt . ' UTC' ) );
		}

		$xml = new \XMLWriter();
		$xml->openMemory();
		$xml->setIndent( true );
		$xml->setIndentString( "\t" );
		$xml->startDocument( '1.0', 'UTF-8' );

		$xml->startElement( 'rss' );
		$xml->writeAttribute( 'version', '2.0' );
		$xml->writeAttribute( 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/' );
		$xml->writeAttribute( 'xmlns:dc', 'http://purl.org/dc/elements/1.1/' );
		$xml->writeAttribute( 'xmlns:atom', 'http://www.w3.org/2005/Atom' );
		$xml->writeAttribute( 'xmlns:media', 'http://search.yahoo.com/mrss/' );

		$xml->startElement( 'channel' );
		$this->text( $xml, 'title', get_option( 'blogname' ) );
		$xml->startElement( 'atom:link' );
		$xml->writeAttribute( 'href', Router::url( $this, $args['scope'], array( 'limit' => (int) $args['limit'] ) ) );
		$xml->writeAttribute( 'rel', 'self' );
		$xml->writeAttribute( 'type', 'application/rss+xml' );
		$xml->endElement();
		$this->text( $xml, 'link', home_url( '/' ) );
		$this->text( $xml, 'description', get_option( 'blogdescription' ) );
		$this->text( $xml, 'language', get_bloginfo( 'language' ) );
		if ( $ttl > 0 ) {
			$this->text( $xml, 'ttl', (string) max( 1, (int) round( $ttl / 60 ) ) );
		}
		$this->text( $xml, 'generator', 'Mega Feeds ' . MEGA_FEEDS_VERSION );

		// lastBuildDate reflects the newest content, not the render time, so
		// an unchanged feed produces a byte-identical document (stable ETag).
		$this->text( $xml, 'lastBuildDate', gmdate( 'D, d M Y H:i:s', $modified ) . ' +0000' );

		global $post;
		$previous_post = isset( $post ) ? $post : null;

		foreach ( $posts as $item ) {
			$post = $item; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			setup_postdata( $item );
			$this->write_item( $xml, $item, $full );
		}

		$post = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $previous_post ) {
			setup_postdata( $previous_post );
		} else {
			wp_reset_postdata();
		}

		$xml->endElement(); // channel
		$xml->endElement(); // rss
		$xml->endDocument();

		return array(
			'body'     => $xml->outputMemory(),
			'modified' => $modified,
		);
	}

	/**
	 * Utility pages that must never be syndicated: WooCommerce cart,
	 * checkout and account. Extendable through the filter.
	 */
	private function excluded_ids( array $types ) {
		$ids = array();
		if ( in_array( 'page', $types, true ) && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$id = (int) wc_get_page_id( $page );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( array_map( 'intval', (array) apply_filters( 'mega_feeds_excluded_ids', $ids, $types ) ) ) );
	}

	private function write_item( \XMLWriter $xml, \WP_Post $post, $full ) {
		$permalink = get_permalink( $post );

		$xml->startElement( 'item' );
		$this->text( $xml, 'title', get_the_title( $post ) );
		$this->text( $xml, 'link', $permalink );

		$xml->startElement( 'guid' );
		$xml->writeAttribute( 'isPermaLink', 'false' );
		$xml->text( $this->clean( (string) $post->guid ) );
		$xml->endElement();

		$this->text( $xml, 'pubDate', mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ) );
		$this->text( $xml, 'atom:updated', mysql2date( 'Y-m-d\TH:i:s\Z', $post->post_modified_gmt, false ) );
		$this->text( $xml, 'dc:type', $post->post_type );

		$author = get_the_author_meta( 'display_name', $post->post_author );
		if ( '' !== (string) $author ) {
			$this->text( $xml, 'dc:creator', $author );
		}

		foreach ( $this->terms( $post ) as $term_name ) {
			$this->text( $xml, 'category', $term_name );
		}

		$this->text( $xml, 'description', get_the_excerpt( $post ) );

		if ( $full ) {
			$content = apply_filters( 'the_content', $post->post_content );
			$content = str_replace( ']]>', ']]&gt;', $content );
			$xml->startElement( 'content:encoded' );
			$xml->writeCdata( $this->clean( $content, false ) );
			$xml->endElement();
		}

		$this->write_images( $xml, Images::for_post( $post ) );

		$xml->endElement(); // item
	}

	/**
	 * Main image as a standard RSS enclosure plus Media RSS (isDefault), and
	 * every additional image as a further media:content.
	 */
	private function write_images( \XMLWriter $xml, array $images ) {
		if ( empty( $images['main'] ) ) {
			return;
		}
		$main = $images['main'];

		$xml->startElement( 'enclosure' );
		$xml->writeAttribute( 'url', $main['url'] );
		$xml->writeAttribute( 'length', (string) (int) $main['length'] );
		$xml->writeAttribute( 'type', '' !== $main['type'] ? $main['type'] : 'image/jpeg' );
		$xml->endElement();

		$this->media_content( $xml, $main, true );
		foreach ( $images['extra'] as $image ) {
			$this->media_content( $xml, $image, false );
		}
	}

	private function media_content( \XMLWriter $xml, array $image, $is_default ) {
		$xml->startElement( 'media:content' );
		$xml->writeAttribute( 'url', $image['url'] );
		$xml->writeAttribute( 'medium', 'image' );
		if ( '' !== $image['type'] ) {
			$xml->writeAttribute( 'type', $image['type'] );
		}
		if ( $image['width'] > 0 ) {
			$xml->writeAttribute( 'width', (string) $image['width'] );
			$xml->writeAttribute( 'height', (string) $image['height'] );
		}
		if ( $is_default ) {
			$xml->writeAttribute( 'isDefault', 'true' );
		}
		if ( '' !== $image['alt'] ) {
			$xml->startElement( 'media:description' );
			$xml->text( $this->clean( $image['alt'] ) );
			$xml->endElement();
		}
		$xml->endElement();
	}

	/**
	 * Names of every public term attached to the post, across taxonomies.
	 */
	private function terms( \WP_Post $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) || 0 === strpos( $taxonomy->name, 'pa_' ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Write a text element from a WordPress value: entities and markup are
	 * reduced to plain text, because XMLWriter does the escaping.
	 */
	private function text( \XMLWriter $xml, $name, $value ) {
		$value = Text::plain( $value );
		$xml->startElement( $name );
		$xml->text( $this->clean( $value ) );
		$xml->endElement();
	}

	/**
	 * Strip characters that are illegal in XML 1.0 and would make the whole
	 * document unparsable.
	 */
	private function clean( $value, $trim = true ) {
		$value = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value );
		if ( null === $value ) {
			$value = '';
		}
		return $trim ? trim( $value ) : $value;
	}
}
