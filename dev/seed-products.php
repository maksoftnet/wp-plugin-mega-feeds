<?php
/**
 * Lab-only fixture: WooCommerce products shaped like a sports shop (brands,
 * sizes, colours, sale prices, stock states, a draft). Idempotent - products
 * are matched by SKU and updated, never duplicated.
 *
 * Run from a slot:
 *   php wp-cli.phar --path=sites/delta eval-file plugins/mega-feeds/dev/seed-products.php
 *
 * Non-ASCII text is kept as JSON escapes so the file stays ASCII-only.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) {
	echo "WooCommerce is not active.\n";
	return;
}

$t = json_decode( '{
	"men":"Мъже","women":"Жени","kids":"Деца",
	"shoes":"Обувки","clothing":"Облекло","accessories":"Аксесоари",
	"black":"Черен","white":"Бял","pink":"Розов","grey":"Сив","blue":"Син","navy":"Тъмносин",
	"p_tee":"Мъжка тениска Essentials",
	"p_leggings":"Дамски клин Training",
	"p_ball":"Футболна топка Pro",
	"p_backpack":"Раница Classic",
	"p_socks":"Чорапи 3 чифта",
	"p_runner":"Мъжки маратонки Runner",
	"p_flex":"Дамски маратонки Flex",
	"p_jacket":"Мъжко яке Windbreaker",
	"p_track":"Детски анцуг Junior",
	"p_draft":"Чернова – не за фийд"
}', true );

// --- Store basics -----------------------------------------------------------
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_default_country', 'BG' );
update_option( 'woocommerce_manage_stock', 'yes' );

// --- Categories -------------------------------------------------------------
$cat = array();
foreach ( array( 'men', 'women', 'kids', 'shoes', 'clothing', 'accessories' ) as $key ) {
	$term = term_exists( $t[ $key ], 'product_cat' );
	if ( ! $term ) {
		$term = wp_insert_term( $t[ $key ], 'product_cat' );
	}
	$cat[ $key ] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
}

// --- Global attributes (pa_brand, pa_color, pa_size) -----------------------
$attr = array();
foreach ( array( 'brand' => 'Brand', 'color' => 'Color', 'size' => 'Size' ) as $slug => $label ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute( array(
			'name'         => $label,
			'slug'         => $slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		) );
	}
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false ) );
	}
	$attr[ $slug ] = array( 'id' => (int) $id, 'tax' => $taxonomy );
}

/** Ensure terms exist and return their ids, in order. */
function mf_seed_terms( $taxonomy, array $names ) {
	$ids = array();
	foreach ( $names as $name ) {
		$term = term_exists( $name, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term( $name, $taxonomy );
		}
		$ids[] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
	}
	return $ids;
}

/** Build a visible global attribute for a product. */
function mf_seed_attribute( array $def, array $names, $variation ) {
	$a = new WC_Product_Attribute();
	$a->set_id( $def['id'] );
	$a->set_name( $def['tax'] );
	$a->set_options( mf_seed_terms( $def['tax'], $names ) );
	$a->set_visible( true );
	$a->set_variation( (bool) $variation );
	return $a;
}

function mf_seed_find( $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	return $id ? wc_get_product( $id ) : null;
}

// --- Product definitions ----------------------------------------------------
$simple = array(
	array( 'sku' => 'PUM-TS-001', 'name' => $t['p_tee'],      'brand' => 'Puma',   'cats' => array( 'men', 'clothing' ),        'price' => '29.90', 'sale' => '24.90', 'stock' => 25 ),
	array( 'sku' => 'NIK-LG-002', 'name' => $t['p_leggings'], 'brand' => 'Nike',   'cats' => array( 'women', 'clothing' ),      'price' => '49.00', 'sale' => '',      'stock' => 12 ),
	array( 'sku' => 'ADI-BL-003', 'name' => $t['p_ball'],     'brand' => 'Adidas', 'cats' => array( 'accessories' ),            'price' => '59.90', 'sale' => '',      'stock' => 0 ),
	array( 'sku' => 'PUM-BP-004', 'name' => $t['p_backpack'], 'brand' => 'Puma',   'cats' => array( 'accessories', 'kids' ),    'price' => '39.90', 'sale' => '34.90', 'stock' => 8 ),
	array( 'sku' => 'NIK-SK-005', 'name' => $t['p_socks'],    'brand' => 'Nike',   'cats' => array( 'accessories', 'men' ),     'price' => '12.90', 'sale' => '',      'stock' => 100 ),
);

$variable = array(
	array(
		'sku' => 'NIK-SH-101', 'name' => $t['p_runner'], 'brand' => 'Nike', 'cats' => array( 'men', 'shoes' ),
		'price' => '119.00', 'sale' => '99.00',
		'sizes' => array( '40', '41', '42', '43', '44' ), 'colors' => array( $t['black'], $t['white'] ),
		'out_of_stock' => array( '44|' . $t['white'] ),
	),
	array(
		'sku' => 'ADI-SH-102', 'name' => $t['p_flex'], 'brand' => 'Adidas', 'cats' => array( 'women', 'shoes' ),
		'price' => '89.00', 'sale' => '',
		'sizes' => array( '36', '37', '38', '39', '40' ), 'colors' => array( $t['pink'], $t['grey'] ),
		'out_of_stock' => array(),
	),
	array(
		'sku' => 'PUM-JK-103', 'name' => $t['p_jacket'], 'brand' => 'Puma', 'cats' => array( 'men', 'clothing' ),
		'price' => '79.90', 'sale' => '59.90',
		'sizes' => array( 'S', 'M', 'L', 'XL' ), 'colors' => array( $t['black'], $t['blue'] ),
		'out_of_stock' => array( 'XL|' . $t['black'] ),
	),
	array(
		'sku' => 'ADI-TR-104', 'name' => $t['p_track'], 'brand' => 'Adidas', 'cats' => array( 'kids', 'clothing' ),
		'price' => '54.90', 'sale' => '',
		'sizes' => array( '116', '128', '140' ), 'colors' => array( $t['navy'] ),
		'out_of_stock' => array(),
	),
);

$created = 0;
$updated = 0;

// --- Simple products --------------------------------------------------------
foreach ( $simple as $d ) {
	$p      = mf_seed_find( $d['sku'] );
	$is_new = ! $p;
	if ( $is_new ) {
		$p = new WC_Product_Simple();
	}
	$p->set_name( $d['name'] );
	$p->set_status( 'publish' );
	$p->set_sku( $d['sku'] );
	$p->set_regular_price( $d['price'] );
	$p->set_sale_price( $d['sale'] );
	$p->set_description( '<p>' . esc_html( $d['name'] ) . ' - lab fixture. <strong>Brand:</strong> ' . esc_html( $d['brand'] ) . '.</p>' );
	$p->set_short_description( esc_html( $d['name'] ) . ' (' . esc_html( $d['brand'] ) . ')' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $d['stock'] );
	$p->set_stock_status( $d['stock'] > 0 ? 'instock' : 'outofstock' );
	$p->set_category_ids( array_map( function ( $k ) use ( $cat ) { return $cat[ $k ]; }, $d['cats'] ) );
	$p->set_attributes( array( mf_seed_attribute( $attr['brand'], array( $d['brand'] ), false ) ) );
	$p->save();
	$is_new ? $created++ : $updated++;
}

// --- Variable products ------------------------------------------------------
foreach ( $variable as $d ) {
	$p      = mf_seed_find( $d['sku'] );
	$is_new = ! $p;
	if ( $is_new ) {
		$p = new WC_Product_Variable();
	}
	$p->set_name( $d['name'] );
	$p->set_status( 'publish' );
	$p->set_sku( $d['sku'] );
	$p->set_description( '<p>' . esc_html( $d['name'] ) . ' - lab fixture. <strong>Brand:</strong> ' . esc_html( $d['brand'] ) . '.</p>' );
	$p->set_short_description( esc_html( $d['name'] ) . ' (' . esc_html( $d['brand'] ) . ')' );
	$p->set_category_ids( array_map( function ( $k ) use ( $cat ) { return $cat[ $k ]; }, $d['cats'] ) );
	$p->set_attributes( array(
		mf_seed_attribute( $attr['brand'], array( $d['brand'] ), false ),
		mf_seed_attribute( $attr['color'], $d['colors'], true ),
		mf_seed_attribute( $attr['size'], $d['sizes'], true ),
	) );
	$parent_id = $p->save();

	$existing = array();
	foreach ( $p->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		if ( $child ) {
			$existing[ $child->get_sku() ] = $child;
		}
	}

	foreach ( $d['colors'] as $ci => $color ) {
		foreach ( $d['sizes'] as $size ) {
			$sku = $d['sku'] . '-' . $size . '-' . ( $ci + 1 );
			$v   = isset( $existing[ $sku ] ) ? $existing[ $sku ] : new WC_Product_Variation();
			$v->set_parent_id( $parent_id );
			$v->set_sku( $sku );
			$v->set_attributes( array(
				$attr['color']['tax'] => sanitize_title( $color ),
				$attr['size']['tax']  => sanitize_title( $size ),
			) );
			$v->set_regular_price( $d['price'] );
			$v->set_sale_price( $d['sale'] );
			$out = in_array( $size . '|' . $color, $d['out_of_stock'], true );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $out ? 0 : 5 );
			$v->set_stock_status( $out ? 'outofstock' : 'instock' );
			$v->save();
		}
	}
	WC_Product_Variable::sync( $parent_id );
	$is_new ? $created++ : $updated++;
}

// --- A draft: must never appear in a feed -----------------------------------
$draft = mf_seed_find( 'DRAFT-000' );
if ( ! $draft ) {
	$draft = new WC_Product_Simple();
	$created++;
}
$draft->set_name( $t['p_draft'] );
$draft->set_status( 'draft' );
$draft->set_sku( 'DRAFT-000' );
$draft->set_regular_price( '1.00' );
$draft->save();

// --- Images: generated placeholders (main + two gallery) --------------------
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

/** Create a solid-colour PNG attachment with a label and return its ID. */
function mf_seed_image( $label, $rgb, $parent_id ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return 0;
	}
	$img = imagecreatetruecolor( 800, 800 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );
	imagestring( $img, 5, 40, 40, $label, imagecolorallocate( $img, 255, 255, 255 ) );
	ob_start();
	imagepng( $img );
	$data = ob_get_clean();
	imagedestroy( $img );

	$upload = wp_upload_bits( sanitize_file_name( $label ) . '.png', null, $data );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}
	$id = wp_insert_attachment( array(
		'post_mime_type' => 'image/png',
		'post_title'     => $label,
		'post_status'    => 'inherit',
	), $upload['file'], $parent_id );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return (int) $id;
}

$palette = array( array( 30, 90, 160 ), array( 160, 50, 60 ), array( 40, 130, 80 ) );
foreach ( array_merge( $simple, $variable ) as $i => $d ) {
	$id = wc_get_product_id_by_sku( $d['sku'] );
	$p  = $id ? wc_get_product( $id ) : null;
	if ( ! $p || $p->get_image_id() ) {
		continue;
	}
	$main = mf_seed_image( $d['sku'] . ' main', $palette[ $i % 3 ], $id );
	$g1   = mf_seed_image( $d['sku'] . ' alt1', $palette[ ( $i + 1 ) % 3 ], $id );
	$g2   = mf_seed_image( $d['sku'] . ' alt2', $palette[ ( $i + 2 ) % 3 ], $id );
	if ( $main ) {
		$p->set_image_id( $main );
	}
	$p->set_gallery_image_ids( array_filter( array( $g1, $g2 ) ) );
	$p->save();
}

wc_delete_product_transients();
echo "Products created: $created, updated: $updated\n";
