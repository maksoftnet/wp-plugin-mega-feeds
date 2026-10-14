<?php
namespace MegaFeeds\Products;

use MegaFeeds\Images;
use MegaFeeds\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns WooCommerce products into a flat, platform-neutral list of offers so
 * that marketplace feeds never touch WooCommerce objects themselves.
 *
 * One offer = one purchasable thing: a simple product, or one variation of a
 * variable product. Offers of the same product share `group_id`.
 *
 * Offer keys:
 *   id, group_id, sku, name, description, url, price (incl. tax), regular_price,
 *   currency, in_stock, stock_quantity, brand, gtin, categories (term arrays,
 *   deepest first), size, size_slug, size_attribute, size_system (INT or ''),
 *   color, image (main),
 *   images (additional), modified.
 */
class Source {

	/**
	 * Walk published, catalogue-visible products in stable ID order and hand
	 * every offer to $callback. $limit caps *products*, not offers.
	 *
	 * @param int      $limit
	 * @param callable $callback function ( array $offer ): void
	 * @return int Products visited.
	 */
	public static function each( $limit, $callback ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$per_page = 50;
		$paged    = 1;
		$visited  = 0;

		while ( $visited < $limit ) {
			$ids = get_posts( array(
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'fields'              => 'ids',
				'posts_per_page'      => min( $per_page, $limit - $visited ),
				'paged'               => $paged,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'has_password'        => false,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,
			) );
			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || 'hidden' === $product->get_catalog_visibility() ) {
					continue;
				}
				$visited++;
				foreach ( self::offers( $product ) as $offer ) {
					call_user_func( $callback, $offer );
				}
			}
			$paged++;
		}

		return $visited;
	}

	/**
	 * @return array[] Offers of one product.
	 */
	public static function offers( \WC_Product $product ) {
		$post = get_post( $product->get_id() );
		if ( ! $post ) {
			return array();
		}

		$images = Images::for_post( $post, 10 );
		$shared = array(
			'group_id'    => $product->get_id(),
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'description' => self::description( $product ),
			'url'         => $product->get_permalink(),
			'currency'    => get_woocommerce_currency(),
			'brand'       => self::brand( $product ),
			'attributes'  => self::attributes( $product ),
			'categories'  => self::categories( $product ),
			'image'       => $images['main'],
			'images'      => $images['extra'],
		);

		$out = array();

		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation || 'publish' !== get_post_status( $child_id ) ) {
					continue;
				}
				$out[] = self::offer( $variation, $product, $shared );
			}
		} else {
			$out[] = self::offer( $product, $product, $shared );
		}

		return $out;
	}

	private static function offer( \WC_Product $item, \WC_Product $parent, array $shared ) {
		$is_variation = $item->is_type( 'variation' );
		$attributes   = $is_variation ? self::variation_attributes( $item, $parent ) : array();

		$offer = array_merge( $shared, array(
			'id'             => $item->get_id(),
			'sku'            => (string) $item->get_sku(),
			'price'          => (float) wc_get_price_including_tax( $item ),
			'net_price'      => (float) wc_get_price_excluding_tax( $item ),
			'regular_price'  => (float) wc_get_price_including_tax( $item, array( 'price' => $item->get_regular_price() ) ),
			'in_stock'       => $item->is_in_stock(),
			'stock_quantity' => $item->get_stock_quantity(),
			'gtin'           => self::gtin( $item, $parent ),
			'size'           => '',
			'size_slug'      => '',
			'size_attribute' => '',
			'size_system'    => '',
			'color'          => '',
			'own_image'      => false,
			'modified'       => $item->get_date_modified() ? $item->get_date_modified()->getTimestamp() : 0,
		) );

		// A variation with its own picture: it becomes the main image, the
		// parent's pictures follow as additional ones.
		if ( $is_variation && $item->get_image_id( 'edit' ) ) {
			$own = Images::attachment( $item->get_image_id( 'edit' ) );
			if ( $own ) {
				$extra = array();
				if ( ! empty( $shared['image'] ) ) {
					$extra[] = $shared['image'];
				}
				$extra = array_merge( $extra, $shared['images'] );
				$offer['images'] = array_values( array_filter( $extra, function ( $image ) use ( $own ) {
					return $image['url'] !== $own['url'];
				} ) );
				$offer['image']     = $own;
				$offer['own_image'] = true;
			}
		}

		foreach ( $attributes as $taxonomy => $value ) {
			$kind = self::attribute_kind( $taxonomy );
			if ( 'size' === $kind && '' === $offer['size'] ) {
				$offer['size']           = $value['name'];
				$offer['size_slug']      = $value['slug'];
				$offer['size_attribute'] = $taxonomy;
			} elseif ( 'color' === $kind && '' === $offer['color'] ) {
				$offer['color'] = $value['name'];
			}
		}

		// Colour and size that belong to the product, not to a variation
		// (one colour for every size): use them when there is exactly one.
		if ( '' === $offer['color'] ) {
			$offer['color'] = self::product_level_value( $parent, 'color' );
		}
		if ( '' === $offer['size'] ) {
			$offer['size'] = self::product_level_value( $parent, 'size' );
		}

		$size                = Text::size( $offer['size'] );
		$offer['size']        = $size['value'];
		$offer['size_system'] = $size['system'];

		return $offer;
	}

	/**
	 * Variation attribute => array( name, slug ), keyed by attribute name
	 * (taxonomy such as pa_size, or the plain attribute name).
	 */
	private static function variation_attributes( \WC_Product $variation, \WC_Product $parent ) {
		$out = array();
		foreach ( $variation->get_variation_attributes( false ) as $name => $slug ) {
			if ( '' === (string) $slug ) {
				continue; // "any" - the value is not fixed for this variation.
			}
			$label = $slug;
			if ( taxonomy_exists( $name ) ) {
				$term = get_term_by( 'slug', $slug, $name );
				if ( $term ) {
					$label = $term->name;
				}
			}
			$out[ $name ] = array(
				'name' => (string) $label,
				'slug' => (string) $slug,
			);
		}
		return $out;
	}

	/**
	 * Classify an attribute as size / color / brand from its taxonomy and
	 * label, so sites that call them "Razmeri", "Cvetove" or "Marki" work
	 * without configuration.
	 */
	public static function attribute_kind( $taxonomy ) {
		$label = taxonomy_exists( $taxonomy ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
		$hay   = strtolower( $taxonomy ) . ' ' . mb_strtolower( (string) $label, 'UTF-8' );

		// Patterns are escapes so this file stays ASCII-only:
		// size: razmer; color: cvyat / cvyatove (Cyrillic) ; brand: marka.
		if ( preg_match( '/size|razmer|\x{0440}\x{0430}\x{0437}\x{043c}\x{0435}\x{0440}/u', $hay ) ) {
			return 'size';
		}
		if ( preg_match( '/colou?r|cvyat|cvet|tsvyat|\x{0446}\x{0432}\x{044f}\x{0442}|\x{0446}\x{0432}\x{0435}\x{0442}/u', $hay ) ) {
			return 'color';
		}
		if ( preg_match( '/brand|marka|marki|\x{043c}\x{0430}\x{0440}\x{043a}/u', $hay ) ) {
			return 'brand';
		}
		return '';
	}

	/**
	 * The single value of a product-level attribute of the given kind
	 * (size/color/brand), or '' when absent or ambiguous.
	 */
	private static function product_level_value( \WC_Product $product, $kind ) {
		foreach ( $product->get_attributes() as $attribute ) {
			if ( self::attribute_kind( $attribute->get_name() ) !== $kind ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ), 'strlen' ) );
			if ( 1 === count( $values ) ) {
				return $values[0];
			}
		}
		return '';
	}

	/**
	 * Descriptive attributes that do not vary and are not brand, size or
	 * colour, as label => text. Feeds use them as free-form parameters.
	 */
	private static function attributes( \WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute->get_variation() || ! $attribute->get_visible() ) {
				continue;
			}
			$name = $attribute->get_name();
			if ( self::attribute_kind( $name ) ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_filter( array_map( 'trim', array_map( 'strval', (array) $values ) ), 'strlen' );
			if ( $values ) {
				$out[ wc_attribute_label( $name ) ] = implode( ', ', $values );
			}
		}
		return $out;
	}

	private static function brand( \WC_Product $product ) {
		// WooCommerce's built-in brands taxonomy first.
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $product->get_id(), 'product_brand' );
			if ( is_array( $terms ) && $terms ) {
				return $terms[0]->name;
			}
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->is_taxonomy() || 'brand' !== self::attribute_kind( $attribute->get_name() ) ) {
				continue;
			}
			$names = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
			if ( $names ) {
				return (string) $names[0];
			}
		}
		return '';
	}

	/**
	 * Assigned categories with their ancestors, deepest first.
	 */
	private static function categories( \WC_Product $product ) {
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$chain = array( $term );
			foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$chain[] = $ancestor;
				}
			}
			$out[] = array_map( function ( $t ) {
				return array( 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => $t->name );
			}, $chain );
		}
		// Deepest chains first.
		usort( $out, function ( $a, $b ) {
			return count( $b ) - count( $a );
		} );
		return $out;
	}

	private static function gtin( \WC_Product $item, \WC_Product $parent ) {
		foreach ( array( $item, $parent ) as $candidate ) {
			if ( method_exists( $candidate, 'get_global_unique_id' ) ) {
				$value = trim( (string) $candidate->get_global_unique_id() );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}

	/**
	 * Plain-text description: the long one, else the short one, else the name.
	 */
	private static function description( \WC_Product $product ) {
		$text = $product->get_description();
		if ( '' === Text::plain( $text ) ) {
			$text = $product->get_short_description();
		}
		$text = Text::plain( $text );
		return '' !== $text ? $text : Text::plain( $product->get_name() );
	}
}
