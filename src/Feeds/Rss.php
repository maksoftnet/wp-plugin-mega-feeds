<?php
namespace MegaFeeds\Feeds;

use MegaFeeds\Router;
use MegaFeeds\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RSS 2.0 with the content, Dublin Core, Atom and Media RSS extensions that
 * aggregators and social tools actually read: full content, author, a
 * featured image, categories and a self link.
 */
class Rss implements FeedInterface {

	public function id() {
		return 'rss';
	}

	public function label() {
		return __( 'RSS 2.0', 'mega-feeds' );
	}

	public function content_type() {
		return 'application/rss+xml';
	}

	public function render( array $args ) {
		$posts = get_posts( array(
			'post_type'           => $args['type'],
			'post_status'         => 'publish',
			'posts_per_page'      => $args['limit'],
			'orderby'             => 'date',
			'order'               => 'DESC',
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false,
		) );

		$full     = ( 'full' === Settings::get( 'content' ) );
		$modified = 0;
		$ttl      = (int) Settings::get( 'cache_ttl' );

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
		$xml->writeAttribute( 'href', Router::url( $this->id(), $this->self_args( $args ) ) );
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

		$items = array();
		foreach ( $posts as $post ) {
			$item_modified = (int) strtotime( $post->post_modified_gmt . ' UTC' );
			$modified      = max( $modified, $item_modified );
			$items[]       = $post;
		}

		// lastBuildDate reflects the newest content, not the render time, so
		// an unchanged feed produces a byte-identical document (stable ETag).
		$this->text( $xml, 'lastBuildDate', gmdate( 'D, d M Y H:i:s', $modified > 0 ? $modified : 0 ) . ' +0000' );

		global $post;
		$previous_post = isset( $post ) ? $post : null;

		foreach ( $items as $item ) {
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

	private function self_args( array $args ) {
		$self = array();
		if ( ! empty( $args['limit'] ) ) {
			$self['limit'] = (int) $args['limit'];
		}
		if ( ! empty( $args['type'] ) && 'post' !== $args['type'] ) {
			$self['type'] = $args['type'];
		}
		return $self;
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

		$image = get_the_post_thumbnail_url( $post, 'large' );
		if ( $image ) {
			$type = wp_check_filetype( wp_parse_url( $image, PHP_URL_PATH ) );
			$xml->startElement( 'media:content' );
			$xml->writeAttribute( 'url', $image );
			$xml->writeAttribute( 'medium', 'image' );
			if ( ! empty( $type['type'] ) ) {
				$xml->writeAttribute( 'type', $type['type'] );
			}
			$xml->endElement();
		}

		$xml->endElement(); // item
	}

	/**
	 * Names of every public term attached to the post, across taxonomies.
	 */
	private function terms( \WP_Post $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
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
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
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
