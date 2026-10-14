<?php
namespace MegaFeeds\Feeds;

use MegaFeeds\Products\Source;
use MegaFeeds\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Glami product feed (https://www.glami.bg/info/feed/).
 *
 * <SHOP> with one <SHOPITEM> per variant - every colour/size combination is
 * its own item, tied together by ITEMGROUP_ID. Tags are upper case, prices are
 * plain decimals, sold-out variants are left out, and an item with no usable
 * image is skipped rather than emitted invalid.
 */
class Glami implements FeedInterface {

	public function id() {
		return 'glami';
	}

	public function label() {
		return __( 'Glami (XML)', 'mega-feeds' );
	}

	public function path() {
		return trim( (string) apply_filters( 'mega_feeds_glami_path', 'glami' ), '/' );
	}

	public function content_type() {
		return 'application/xml';
	}

	public function kind() {
		return 'products';
	}

	public function scopes() {
		return array( '' => __( 'All in-stock product variants', 'mega-feeds' ) );
	}

	public function resolve_scope( $raw ) {
		return '' === $raw ? '' : null;
	}

	public function render( array $args ) {
		$xml = new \XMLWriter();
		$xml->openMemory();
		$xml->setIndent( true );
		$xml->setIndentString( "\t" );
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->startElement( 'SHOP' );

		$map      = $this->category_map( (string) Settings::get( 'glami_category_map' ) );
		$modified = 0;
		$skipped  = array( 'out_of_stock' => 0, 'no_image' => 0, 'no_price' => 0 );
		$items    = 0;
		$body     = '';

		Source::each( (int) $args['limit'], function ( array $offer ) use ( $xml, $map, &$modified, &$skipped, &$items, &$body ) {
			if ( ! $offer['in_stock'] ) {
				$skipped['out_of_stock']++;
				return;
			}
			if ( empty( $offer['image'] ) ) {
				$skipped['no_image']++;
				return;
			}
			if ( $offer['price'] <= 0 ) {
				$skipped['no_price']++;
				return;
			}
			$this->write_item( $xml, $offer, $map );
			$items++;
			$modified = max( $modified, (int) $offer['modified'] );

			// Keep the writer's buffer small on large catalogues.
			if ( 0 === $items % 100 ) {
				$body .= $xml->flush( true );
			}
		} );

		$xml->endElement(); // SHOP
		$xml->endDocument();
		$body .= $xml->flush( true );

		return array(
			'body'     => $body,
			'modified' => $modified,
		);
	}

	private function write_item( \XMLWriter $xml, array $offer, array $map ) {
		$xml->startElement( 'SHOPITEM' );

		$this->el( $xml, 'ITEM_ID', (string) $offer['id'] );
		$this->el( $xml, 'ITEMGROUP_ID', (string) $offer['group_id'] );
		$this->el( $xml, 'PRODUCTNAME', mb_substr( $offer['name'], 0, 200, 'UTF-8' ) );

		$xml->startElement( 'DESCRIPTION' );
		$xml->writeCdata( $this->clean( mb_substr( $offer['description'], 0, 65535, 'UTF-8' ) ) );
		$xml->endElement();

		$this->el( $xml, 'URL', $offer['url'] );
		if ( '' !== $offer['size_attribute'] && '' !== $offer['size_slug'] ) {
			$this->el( $xml, 'URL_SIZE', add_query_arg( 'attribute_' . $offer['size_attribute'], rawurlencode( $offer['size_slug'] ), $offer['url'] ) );
		}

		$this->el( $xml, 'IMGURL', $offer['image']['url'] );
		foreach ( $offer['images'] as $image ) {
			$this->el( $xml, 'IMGURL_ALTERNATIVE', $image['url'] );
		}

		$this->el( $xml, 'PRICE_VAT', number_format( (float) $offer['price'], 2, '.', '' ) );

		if ( '' !== $offer['brand'] ) {
			$this->el( $xml, 'MANUFACTURER', mb_substr( $offer['brand'], 0, 200, 'UTF-8' ) );
		}

		$category = $this->category_text( $offer, $map );
		if ( '' !== $category ) {
			$this->el( $xml, 'CATEGORYTEXT', mb_substr( $category, 0, 255, 'UTF-8' ) );
		}

		if ( '' !== $offer['size'] ) {
			$this->param( $xml, $this->t( 'size' ), $offer['size'] );
			$this->param( $xml, 'size_system', $this->size_system( $offer ) );
		}
		if ( '' !== $offer['color'] ) {
			$this->param( $xml, $this->t( 'color' ), $offer['color'] );
		}

		$this->el( $xml, 'DELIVERY_DATE', '0' );

		$carrier = trim( (string) Settings::get( 'glami_delivery_name' ) );
		$price   = (string) Settings::get( 'glami_delivery_price' );
		if ( '' !== $carrier && '' !== $price && is_numeric( $price ) ) {
			$xml->startElement( 'DELIVERY' );
			$this->el( $xml, 'DELIVERY_ID', $carrier );
			$this->el( $xml, 'DELIVERY_PRICE', number_format( (float) $price, 2, '.', '' ) );
			$xml->endElement();
		}

		if ( '' !== $offer['gtin'] ) {
			$this->el( $xml, 'GTIN', $offer['gtin'] );
		}

		$xml->endElement(); // SHOPITEM
	}

	private function param( \XMLWriter $xml, $name, $value ) {
		$xml->startElement( 'PARAM' );
		$this->el( $xml, 'PARAM_NAME', $name );
		$this->el( $xml, 'VAL', $value );
		$xml->endElement();
	}

	private function el( \XMLWriter $xml, $name, $value ) {
		$xml->startElement( $name );
		$xml->text( $this->clean( (string) $value ) );
		$xml->endElement();
	}

	/**
	 * Parameter names Glami.bg expects, kept as escapes so the file stays
	 * ASCII-only: razmer (size) and cvyat (colour) in Cyrillic.
	 */
	private function t( $key ) {
		$names = array(
			'size'  => "\u{0440}\u{0430}\u{0437}\u{043c}\u{0435}\u{0440}",
			'color' => "\u{0446}\u{0432}\u{044f}\u{0442}",
		);
		return apply_filters( 'mega_feeds_glami_param_name', $names[ $key ], $key );
	}

	/**
	 * Letter sizes arrive as INT from the product source; everything else
	 * uses the configured system (EU by default).
	 */
	private function size_system( array $offer ) {
		if ( ! empty( $offer['size_system'] ) ) {
			return $offer['size_system'];
		}
		$system = (string) Settings::get( 'glami_size_system' );
		return '' !== $system ? $system : 'EU';
	}

	/**
	 * Parse "category-slug = Glami category path" lines.
	 */
	private function category_map( $raw ) {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $slug, $path ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $slug && '' !== $path ) {
				$map[ sanitize_title( $slug ) ] = $path;
			}
		}
		return $map;
	}

	/**
	 * The mapped Glami category of the deepest mapped WooCommerce category;
	 * without a mapping, the WooCommerce path itself as a best effort.
	 */
	private function category_text( array $offer, array $map ) {
		foreach ( $offer['categories'] as $chain ) {
			foreach ( $chain as $term ) {
				if ( isset( $map[ $term['slug'] ] ) ) {
					return $map[ $term['slug'] ];
				}
			}
		}
		if ( ! empty( $offer['categories'][0] ) ) {
			$names = array_reverse( wp_list_pluck( $offer['categories'][0], 'name' ) );
			return implode( ' | ', $names );
		}
		return '';
	}

	private function clean( $value ) {
		$value = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value );
		return null === $value ? '' : trim( $value );
	}
}
