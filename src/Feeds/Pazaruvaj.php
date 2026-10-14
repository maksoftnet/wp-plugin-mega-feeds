<?php
namespace MegaFeeds\Feeds;

use MegaFeeds\Products\Source;
use MegaFeeds\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pazaruvaj product file (partner portal "feed-format").
 *
 * <Products> with one <Product> per purchasable variant. Colour and size are
 * single values per product, so every variant is its own item; GroupId ties
 * the variants together. Price is the final consumer price, plain decimal,
 * no currency. Categories are written root > ... > leaf.
 */
class Pazaruvaj implements FeedInterface {

	public function id() {
		return 'pazaruvaj';
	}

	public function label() {
		return __( 'Pazaruvaj (XML)', 'mega-feeds' );
	}

	public function path() {
		return trim( (string) apply_filters( 'mega_feeds_pazaruvaj_path', 'pazaruvaj' ), '/' );
	}

	public function content_type() {
		return 'application/xml';
	}

	public function kind() {
		return 'products';
	}

	public function scopes() {
		return array( '' => __( 'All product variants', 'mega-feeds' ) );
	}

	public function resolve_scope( $raw ) {
		return '' === $raw ? '' : null;
	}

	public function render( array $args ) {
		$include_oos = ! empty( Settings::get( 'pazaruvaj_include_oos' ) );

		$xml = new \XMLWriter();
		$xml->openMemory();
		$xml->setIndent( true );
		$xml->setIndentString( "\t" );
		$xml->startDocument( '1.0', 'UTF-8' );
		$xml->startElement( 'Products' );

		$modified = 0;
		$items    = 0;
		$body     = '';

		Source::each( (int) $args['limit'], function ( array $offer ) use ( $xml, $include_oos, &$modified, &$items, &$body ) {
			if ( $offer['price'] <= 0 ) {
				return;
			}
			if ( ! $offer['in_stock'] && ! $include_oos ) {
				return;
			}
			$this->write_item( $xml, $offer );
			$items++;
			$modified = max( $modified, (int) $offer['modified'] );

			if ( 0 === $items % 100 ) {
				$body .= $xml->flush( true );
			}
		} );

		$xml->endElement(); // Products
		$xml->endDocument();
		$body .= $xml->flush( true );

		return array(
			'body'     => $body,
			'modified' => $modified,
		);
	}

	private function write_item( \XMLWriter $xml, array $offer ) {
		$xml->startElement( 'Product' );

		$this->el( $xml, 'Identifier', (string) $offer['id'] );
		if ( '' !== $offer['brand'] ) {
			$this->el( $xml, 'Manufacturer', $offer['brand'] );
		}
		$this->el( $xml, 'Name', $this->name( $offer ) );
		$this->el( $xml, 'ProductUrl', $offer['url'] );
		$this->el( $xml, 'Price', number_format( (float) $offer['price'], 2, '.', '' ) );

		$category = $this->category( $offer );
		if ( '' !== $category ) {
			$this->el( $xml, 'Category', $category );
		}

		if ( ! empty( $offer['image'] ) ) {
			$this->el( $xml, 'ImageUrl', $offer['image']['url'] );
			if ( ! empty( $offer['images'][0] ) ) {
				$this->el( $xml, 'ImageUrl2', $offer['images'][0]['url'] );
			}
		}

		$this->el( $xml, 'Description', mb_substr( $offer['description'], 0, 3000, 'UTF-8' ) );

		$days = (string) Settings::get( 'pazaruvaj_delivery_days' );
		$this->el( $xml, 'DeliveryTime', $offer['in_stock'] ? ( '' !== $days ? $days : '1' ) : 'NO' );

		$cost = trim( (string) Settings::get( 'pazaruvaj_delivery_cost' ) );
		if ( '' !== $cost ) {
			$this->el( $xml, 'DeliveryCost', $cost );
		}

		if ( 'sku' === Settings::get( 'pazaruvaj_product_number' ) && '' !== $offer['sku'] ) {
			$this->el( $xml, 'ProductNumber', $offer['sku'] );
		}

		$barcode = preg_replace( '/\D/', '', (string) $offer['gtin'] );
		if ( strlen( $barcode ) >= 8 && strlen( $barcode ) <= 13 ) {
			$this->el( $xml, 'Barcode', $barcode );
		}

		if ( $offer['net_price'] > 0 ) {
			$this->el( $xml, 'NetPrice', number_format( (float) $offer['net_price'], 2, '.', '' ) );
		}

		// Pazaruvaj accepts a colour only when the picture matches it, so it
		// is sent for variants that have their own image (or when forced).
		$send_color = apply_filters( 'mega_feeds_pazaruvaj_send_color', ! empty( $offer['own_image'] ), $offer );
		if ( $send_color && '' !== $offer['color'] ) {
			$this->el( $xml, 'Color', $offer['color'] );
		}
		if ( '' !== $offer['size'] ) {
			$this->el( $xml, 'Size', $offer['size'] );
		}

		$this->el( $xml, 'GroupId', (string) $offer['group_id'] );

		if ( ! empty( $offer['attributes'] ) ) {
			$xml->startElement( 'Attributes' );
			foreach ( $offer['attributes'] as $label => $value ) {
				$xml->startElement( 'Attribute' );
				$this->el( $xml, 'Attribute_name', (string) $label );
				$this->el( $xml, 'Attribute_value', (string) $value );
				$xml->endElement();
			}
			$xml->endElement();
		}

		$xml->endElement(); // Product
	}

	/**
	 * Variants need individual names: colour and size are appended to the
	 * product name so two variants never share one.
	 */
	private function name( array $offer ) {
		$name  = $offer['name'];
		$parts = array_filter( array( $offer['color'], $offer['size'] ), 'strlen' );
		if ( $parts ) {
			$name .= ' - ' . implode( ', ', $parts );
		}
		return apply_filters( 'mega_feeds_pazaruvaj_name', $name, $offer );
	}

	/**
	 * Deepest category, every level from the root, joined with " > ".
	 */
	private function category( array $offer ) {
		if ( empty( $offer['categories'][0] ) ) {
			return '';
		}
		$names = array_reverse( wp_list_pluck( $offer['categories'][0], 'name' ) );
		return implode( ' > ', $names );
	}

	private function el( \XMLWriter $xml, $name, $value ) {
		$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		$xml->startElement( $name );
		$xml->text( null === $value ? '' : trim( $value ) );
		$xml->endElement();
	}
}
