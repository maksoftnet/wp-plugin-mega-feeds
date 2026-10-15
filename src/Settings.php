<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single option holding every setting, with defaults so the plugin works the
 * moment it is activated. Anything the user does not set falls back here.
 */
class Settings {

	const OPTION = 'mega_feeds_settings';

	public static function defaults() {
		return array(
			// Items per content feed when the request does not ask for a number.
			'limit_default'         => 20,
			// Hard ceiling for content feeds.
			'limit_max'             => 100,
			// Same pair for product feeds, which are meant to carry the catalogue.
			'product_limit_default' => 1000,
			'product_limit_max'     => 5000,
			// Seconds before a stored feed is refreshed in the background (it is
			// also refreshed whenever content changes). 0 disables storing.
			'cache_ttl'             => 3600,
			// 'full' puts the whole post in the feed, 'excerpt' only a summary.
			'content'               => 'full',
			// Post types that may be exported. Empty means every public type.
			'post_types'            => array(),
			// Feed id => enabled. Feeds not listed default to enabled.
			'feeds'                 => array(),
			// Glami: size system for numeric sizes (EU, UK, US, IT, ...).
			'glami_size_system'     => 'EU',
			// Glami: one "category-slug = Glami category path" per line.
			'glami_category_map'    => '',
			// Glami: optional single carrier block.
			'glami_delivery_name'   => '',
			'glami_delivery_price'  => '',
			// Pazaruvaj: delivery days for items in stock, optional cost text,
			// whether the SKU is sent as ProductNumber, and whether sold-out
			// items are listed (with DeliveryTime NO).
			'pazaruvaj_delivery_days'  => '1',
			'pazaruvaj_delivery_cost'  => '',
			'pazaruvaj_product_number' => 'none',
			'pazaruvaj_include_oos'    => 1,
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function feed_enabled( $feed_id ) {
		$feeds = self::get( 'feeds' );
		if ( ! is_array( $feeds ) || ! array_key_exists( $feed_id, $feeds ) ) {
			return true;
		}
		return (bool) $feeds[ $feed_id ];
	}

	/**
	 * Post types the site owner may expose: public ones, minus attachments.
	 */
	public static function available_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		return $types;
	}

	/**
	 * Post types currently allowed for export. An empty selection means
	 * "everything public", so a fresh install exports posts, pages and
	 * products without any setup.
	 */
	public static function allowed_post_types() {
		$available = array_keys( self::available_post_types() );
		$chosen    = array_values( array_intersect( (array) self::get( 'post_types' ), $available ) );
		return empty( $chosen ) ? $available : $chosen;
	}

	/**
	 * URL segment for a post type: the plural collection name, as used by
	 * the WordPress REST API and WooCommerce (posts, pages, products).
	 */
	public static function scope_name( $post_type ) {
		$name = ( 's' === substr( $post_type, -1 ) ) ? $post_type : $post_type . 's';
		return apply_filters( 'mega_feeds_scope_name', $name, $post_type );
	}

	/**
	 * Canonical scope name => post type, for every allowed type.
	 */
	public static function type_scopes() {
		$map = array();
		foreach ( self::allowed_post_types() as $type ) {
			$map[ self::scope_name( $type ) ] = $type;
		}
		return $map;
	}

	/**
	 * Post types a content-feed scope covers. '' is the central feed (all
	 * allowed types); an unknown scope returns an empty array.
	 */
	public static function types_for_scope( $scope ) {
		if ( '' === $scope ) {
			return self::allowed_post_types();
		}
		$map = self::type_scopes();
		return isset( $map[ $scope ] ) ? array( $map[ $scope ] ) : array();
	}

	/**
	 * Settings API sanitize callback.
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$pair = function ( $max_key, $default_key, $ceiling ) use ( $input, $defaults ) {
			$max = isset( $input[ $max_key ] ) ? absint( $input[ $max_key ] ) : $defaults[ $max_key ];
			$max = max( 1, min( $ceiling, $max ) );
			$def = isset( $input[ $default_key ] ) ? absint( $input[ $default_key ] ) : $defaults[ $default_key ];
			$def = max( 1, min( $max, $def ) );
			return array( $max, $def );
		};

		list( $out['limit_max'], $out['limit_default'] )                   = $pair( 'limit_max', 'limit_default', 500 );
		list( $out['product_limit_max'], $out['product_limit_default'] )   = $pair( 'product_limit_max', 'product_limit_default', 20000 );

		$out['cache_ttl'] = isset( $input['cache_ttl'] ) ? max( 0, min( DAY_IN_SECONDS, absint( $input['cache_ttl'] ) ) ) : $defaults['cache_ttl'];
		$out['content']   = ( isset( $input['content'] ) && 'excerpt' === $input['content'] ) ? 'excerpt' : 'full';

		$available         = array_keys( self::available_post_types() );
		$types             = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$out['post_types'] = array_values( array_intersect( $types, $available ) );

		$out['feeds'] = array();
		foreach ( array_keys( Registry::all() ) as $feed_id ) {
			$out['feeds'][ $feed_id ] = ! empty( $input['feeds'][ $feed_id ] ) ? 1 : 0;
		}

		$out['glami_size_system']    = isset( $input['glami_size_system'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', $input['glami_size_system'] ) ) : $defaults['glami_size_system'];
		if ( '' === $out['glami_size_system'] ) {
			$out['glami_size_system'] = 'EU';
		}
		$out['glami_category_map']   = isset( $input['glami_category_map'] ) ? sanitize_textarea_field( $input['glami_category_map'] ) : '';
		$out['glami_delivery_name']  = isset( $input['glami_delivery_name'] ) ? sanitize_text_field( $input['glami_delivery_name'] ) : '';
		$price                       = isset( $input['glami_delivery_price'] ) ? str_replace( ',', '.', trim( $input['glami_delivery_price'] ) ) : '';
		$out['glami_delivery_price'] = is_numeric( $price ) ? (string) $price : '';

		$days = isset( $input['pazaruvaj_delivery_days'] ) ? absint( $input['pazaruvaj_delivery_days'] ) : 1;
		$out['pazaruvaj_delivery_days']  = (string) max( 1, min( 60, $days ) );
		$out['pazaruvaj_delivery_cost']  = isset( $input['pazaruvaj_delivery_cost'] ) ? sanitize_text_field( $input['pazaruvaj_delivery_cost'] ) : '';
		$out['pazaruvaj_product_number'] = ( isset( $input['pazaruvaj_product_number'] ) && 'sku' === $input['pazaruvaj_product_number'] ) ? 'sku' : 'none';
		$out['pazaruvaj_include_oos']    = ! empty( $input['pazaruvaj_include_oos'] ) ? 1 : 0;

		// Settings changed: rendered feeds are stale.
		Cache::bump();

		return $out;
	}
}
