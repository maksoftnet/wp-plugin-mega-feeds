<?php
namespace MegaFeeds;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freshness token for stored feeds.
 *
 * Feeds themselves live on disk (see Store). What this class owns is the
 * "generation": a token that moves whenever something a feed shows may have
 * changed - a post is saved, trashed or deleted, a term or the site title
 * changes, settings are saved. A stored feed carries the generation it was
 * rendered under; when the two differ the file is stale and is refreshed in
 * the background (see Regenerator). Nothing is deleted on invalidation.
 */
class Cache {

	const GENERATION_OPTION = 'mega_feeds_generation';

	public static function hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'bump' ) );
		add_action( 'trashed_post', array( __CLASS__, 'bump' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'bump' ) );
		add_action( 'edited_term', array( __CLASS__, 'bump' ) );
		add_action( 'delete_term', array( __CLASS__, 'bump' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'bump' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'bump' ) );
		// WooCommerce changes that do not go through save_post: stock and
		// price edited from orders, imports and the quick-edit screens.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'bump' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'bump' ) );
	}

	public static function on_save_post( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::bump();
	}

	public static function generation() {
		$generation = get_option( self::GENERATION_OPTION );
		if ( ! $generation ) {
			$generation = self::bump( false );
		}
		return (string) $generation;
	}

	/**
	 * Move the token. Also announces the change so the Regenerator can
	 * schedule a refresh (debounced there).
	 *
	 * @param bool|mixed $announce Hook callbacks pass their own arguments
	 *                             here, so anything but false announces.
	 */
	public static function bump( $announce = true ) {
		$generation = (string) microtime( true );
		update_option( self::GENERATION_OPTION, $generation, true );
		if ( false !== $announce ) {
			do_action( 'mega_feeds_invalidated' );
		}
		return $generation;
	}
}
