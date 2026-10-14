<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the images that belong to a post: the main image plus whatever
 * else the page carries. Used by every feed so "main image" and "additional
 * images" mean the same thing everywhere.
 *
 * Sources, in priority order, each deduplicated by URL:
 *   1. the featured image (the main image)
 *   2. a WooCommerce product gallery
 *   3. images attached to the post
 *   4. images referenced in the content (wp-image-N classes, absolute src)
 *
 * When there is no featured image the first image found becomes the main one.
 */
class Images {

	/**
	 * @param \WP_Post $post
	 * @param int      $max  Cap on images returned in total, main included.
	 * @return array {
	 *     @type array|null $main  Image or null.
	 *     @type array      $extra Additional images.
	 * }
	 * Each image: url, type, width, height, length, alt.
	 */
	public static function for_post( \WP_Post $post, $max = 10 ) {
		$max = (int) apply_filters( 'mega_feeds_max_images', $max, $post );
		$max = max( 1, $max );

		$ids = array();
		$raw = array(); // URL-only images found in content.

		$featured = (int) get_post_thumbnail_id( $post );
		if ( $featured ) {
			$ids[] = $featured;
		}

		$gallery = get_post_meta( $post->ID, '_product_image_gallery', true );
		if ( $gallery ) {
			foreach ( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) as $id ) {
				$ids[] = $id;
			}
		}

		$attached = get_children( array(
			'post_parent'    => $post->ID,
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'numberposts'    => $max,
			'orderby'        => 'menu_order ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		foreach ( (array) $attached as $id ) {
			$ids[] = (int) $id;
		}

		if ( '' !== $post->post_content && preg_match_all( '/<img\b[^>]*>/i', $post->post_content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/wp-image-(\d+)/', $tag, $m ) ) {
					$ids[] = (int) $m[1];
				} elseif ( preg_match( '/\ssrc=["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$raw[] = self::absolute( html_entity_decode( $m[1] ) );
				}
			}
		}

		$images = array();
		$seen   = array();

		foreach ( $ids as $id ) {
			$image = self::attachment( $id );
			if ( $image && ! isset( $seen[ $image['url'] ] ) ) {
				$seen[ $image['url'] ] = true;
				$images[]              = $image;
			}
		}
		foreach ( $raw as $url ) {
			if ( $url && ! isset( $seen[ $url ] ) ) {
				$seen[ $url ] = true;
				$type         = wp_check_filetype( wp_parse_url( $url, PHP_URL_PATH ) );
				$images[]     = array(
					'url'    => $url,
					'type'   => (string) $type['type'],
					'width'  => 0,
					'height' => 0,
					'length' => 0,
					'alt'    => '',
				);
			}
		}

		$images = array_slice( $images, 0, $max );
		$main   = $images ? array_shift( $images ) : null;

		return array(
			'main'  => $main,
			'extra' => $images,
		);
	}

	/**
	 * One attachment as an image array, or null when it is not an image.
	 */
	public static function attachment( $id ) {
		$id = (int) $id;
		if ( $id < 1 || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src || empty( $src[0] ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id );
		return array(
			'url'    => $src[0],
			'type'   => (string) get_post_mime_type( $id ),
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'length' => ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) ? (int) $meta['filesize'] : 0,
			'alt'    => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
		);
	}

	private static function absolute( $url ) {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}
}
