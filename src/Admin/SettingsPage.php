<?php
namespace MegaFeeds\Admin;

use MegaFeeds\Cache;
use MegaFeeds\Regenerator;
use MegaFeeds\Registry;
use MegaFeeds\Router;
use MegaFeeds\Settings;
use MegaFeeds\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal settings screen under Settings > Mega Feeds: which feeds are on,
 * where they live, and the few knobs that bound their cost.
 */
class SettingsPage {

	const SLUG  = 'mega-feeds';
	const GROUP = 'mega_feeds';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_mega_feeds_flush', array( __CLASS__, 'flush_cache' ) );
		add_action( 'admin_post_mega_feeds_regenerate', array( __CLASS__, 'regenerate_now' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Mega Feeds', 'mega-feeds' ),
			__( 'Mega Feeds', 'mega-feeds' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'MegaFeeds\\Settings', 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function flush_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mega-feeds' ), 403 );
		}
		check_admin_referer( 'mega_feeds_flush' );
		Cache::bump();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'flushed' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function regenerate_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mega-feeds' ), 403 );
		}
		check_admin_referer( 'mega_feeds_regenerate' );
		Cache::bump( false );
		Regenerator::run_all();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'regenerating' => 1 ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$name     = Settings::OPTION;
		$types    = Settings::available_post_types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mega Feeds', 'mega-feeds' ); ?></h1>

			<?php if ( ! empty( $_GET['regenerating'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feeds are being regenerated in the background.', 'mega-feeds' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['flushed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Feed cache cleared.', 'mega-feeds' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Feeds', 'mega-feeds' ); ?></h2>
				<table class="widefat striped" style="max-width:900px">
					<thead>
						<tr>
							<th style="width:90px"><?php esc_html_e( 'Enabled', 'mega-feeds' ); ?></th>
							<th><?php esc_html_e( 'Format', 'mega-feeds' ); ?></th>
							<th><?php esc_html_e( 'URL', 'mega-feeds' ); ?></th>
							<th style="width:200px"><?php esc_html_e( 'Last generated', 'mega-feeds' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( Registry::all() as $id => $feed ) : ?>
						<?php foreach ( $feed->scopes() as $scope => $scope_label ) : ?>
						<tr>
							<td>
								<?php if ( '' === $scope ) : ?>
								<input type="checkbox"
									name="<?php echo esc_attr( $name ); ?>[feeds][<?php echo esc_attr( $id ); ?>]"
									value="1" <?php checked( Settings::feed_enabled( $id ) ); ?>>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $feed->label() ); ?><?php echo '' !== $scope ? ' &mdash; ' . esc_html( $scope_label ) : ''; ?></td>
							<td><a href="<?php echo esc_url( Router::url( $feed, $scope ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( Router::url( $feed, $scope ) ); ?></code></a></td>
							<td>
								<?php
								$stored = Store::read( Store::key( $id, Router::normalize_args( $feed, $scope, 0 ) ) );
								if ( $stored ) {
									echo esc_html( sprintf(
										/* translators: 1: time difference, 2: seconds to render, 3: size in KB */
										__( '%1$s ago (%2$s s, %3$s KB)', 'mega-feeds' ),
										human_time_diff( (int) $stored['meta']['generated_at'] ),
										isset( $stored['meta']['duration'] ) ? $stored['meta']['duration'] : '-',
										number_format_i18n( ( isset( $stored['meta']['bytes'] ) ? $stored['meta']['bytes'] : 0 ) / 1024, 0 )
									) );
								} else {
									esc_html_e( 'Not generated yet', 'mega-feeds' );
								}
								?>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Append ?limit=N to ask for fewer items. Content types are selected by the path: /rss/posts/, /rss/pages/, /rss/products/.', 'mega-feeds' ); ?>
				</p>

				<h2><?php esc_html_e( 'Content', 'mega-feeds' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'mega-feeds' ); ?><p class="description" style="font-weight:400"><?php esc_html_e( 'Leave all unchecked to export every public type.', 'mega-feeds' ); ?></p></th>
						<td>
							<?php foreach ( $types as $slug => $type ) : ?>
								<label style="display:block">
									<input type="checkbox"
										name="<?php echo esc_attr( $name ); ?>[post_types][]"
										value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $settings['post_types'], true ) ); ?>>
									<?php echo esc_html( $type->labels->name ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Item body', 'mega-feeds' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[content]" value="full" <?php checked( 'full', $settings['content'] ); ?>> <?php esc_html_e( 'Full content', 'mega-feeds' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[content]" value="excerpt" <?php checked( 'excerpt', $settings['content'] ); ?>> <?php esc_html_e( 'Excerpt only', 'mega-feeds' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Limits and cache', 'mega-feeds' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mf-limit-default"><?php esc_html_e( 'Default items', 'mega-feeds' ); ?></label></th>
						<td><input id="mf-limit-default" type="number" min="1" max="500" class="small-text"
							name="<?php echo esc_attr( $name ); ?>[limit_default]" value="<?php echo esc_attr( $settings['limit_default'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="mf-limit-max"><?php esc_html_e( 'Maximum items', 'mega-feeds' ); ?></label></th>
						<td>
							<input id="mf-limit-max" type="number" min="1" max="500" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[limit_max]" value="<?php echo esc_attr( $settings['limit_max'] ); ?>">
							<p class="description"><?php esc_html_e( 'No request can return more than this, whatever ?limit= says.', 'mega-feeds' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mf-plimit-default"><?php esc_html_e( 'Product feeds: default / maximum products', 'mega-feeds' ); ?></label></th>
						<td>
							<input id="mf-plimit-default" type="number" min="1" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[product_limit_default]" value="<?php echo esc_attr( $settings['product_limit_default'] ); ?>">
							/
							<input type="number" min="1" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[product_limit_max]" value="<?php echo esc_attr( $settings['product_limit_max'] ); ?>">
							<p class="description"><?php esc_html_e( 'Counted in products; each variation of a product is one more item in the feed.', 'mega-feeds' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mf-cache-ttl"><?php esc_html_e( 'Refresh every (seconds)', 'mega-feeds' ); ?></label></th>
						<td>
							<input id="mf-cache-ttl" type="number" min="0" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[cache_ttl]" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>">
							<p class="description"><?php esc_html_e( 'A stored feed is also refreshed in the background whenever content changes. 0 turns storing off and renders every request.', 'mega-feeds' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Glami', 'mega-feeds' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mf-glami-size"><?php esc_html_e( 'Size system', 'mega-feeds' ); ?></label></th>
						<td><input id="mf-glami-size" type="text" class="small-text"
							name="<?php echo esc_attr( $name ); ?>[glami_size_system]" value="<?php echo esc_attr( $settings['glami_size_system'] ); ?>">
							<p class="description"><?php esc_html_e( 'For numeric sizes: EU, UK, US, IT ... Letter sizes (S, M, L) are sent as INT automatically.', 'mega-feeds' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mf-glami-map"><?php esc_html_e( 'Category map', 'mega-feeds' ); ?></label></th>
						<td><textarea id="mf-glami-map" rows="6" class="large-text code"
							name="<?php echo esc_attr( $name ); ?>[glami_category_map]"><?php echo esc_textarea( $settings['glami_category_map'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: product-category-slug = Glami category path (with gender). Unmapped categories are sent with their store path.', 'mega-feeds' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'mega-feeds' ); ?></th>
						<td>
							<input type="text" placeholder="<?php esc_attr_e( 'Carrier', 'mega-feeds' ); ?>"
								name="<?php echo esc_attr( $name ); ?>[glami_delivery_name]" value="<?php echo esc_attr( $settings['glami_delivery_name'] ); ?>">
							<input type="text" class="small-text" placeholder="<?php esc_attr_e( 'Price', 'mega-feeds' ); ?>"
								name="<?php echo esc_attr( $name ); ?>[glami_delivery_price]" value="<?php echo esc_attr( $settings['glami_delivery_price'] ); ?>">
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Pazaruvaj', 'mega-feeds' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mf-paz-days"><?php esc_html_e( 'Delivery time (days)', 'mega-feeds' ); ?></label></th>
						<td><input id="mf-paz-days" type="number" min="1" max="60" class="small-text"
							name="<?php echo esc_attr( $name ); ?>[pazaruvaj_delivery_days]" value="<?php echo esc_attr( $settings['pazaruvaj_delivery_days'] ); ?>">
							<p class="description"><?php esc_html_e( 'Sent for items in stock. Sold-out items are sent with NO.', 'mega-feeds' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="mf-paz-cost"><?php esc_html_e( 'Delivery cost', 'mega-feeds' ); ?></label></th>
						<td><input id="mf-paz-cost" type="text" class="small-text"
							name="<?php echo esc_attr( $name ); ?>[pazaruvaj_delivery_cost]" value="<?php echo esc_attr( $settings['pazaruvaj_delivery_cost'] ); ?>">
							<p class="description"><?php esc_html_e( 'A number (5, 5 EUR) or Free. Empty omits the field.', 'mega-feeds' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manufacturer code', 'mega-feeds' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[pazaruvaj_product_number]" value="none" <?php checked( 'none', $settings['pazaruvaj_product_number'] ); ?>> <?php esc_html_e( 'Do not send', 'mega-feeds' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[pazaruvaj_product_number]" value="sku" <?php checked( 'sku', $settings['pazaruvaj_product_number'] ); ?>> <?php esc_html_e( 'Send the SKU as ProductNumber (only if the SKU is the manufacturer code)', 'mega-feeds' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sold-out items', 'mega-feeds' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[pazaruvaj_include_oos]" value="1" <?php checked( ! empty( $settings['pazaruvaj_include_oos'] ) ); ?>> <?php esc_html_e( 'Include them (DeliveryTime NO)', 'mega-feeds' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="mega_feeds_regenerate">
				<?php wp_nonce_field( 'mega_feeds_regenerate' ); ?>
				<?php submit_button( __( 'Regenerate feeds now', 'mega-feeds' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" style="display:inline-block" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mega_feeds_flush">
				<?php wp_nonce_field( 'mega_feeds_flush' ); ?>
				<?php submit_button( __( 'Clear feed cache', 'mega-feeds' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
