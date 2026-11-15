<?php
/**
 * Plugin Name:       Ecopart – Éco-participation for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/ecopart/
 * Description:       Show the French éco-participation (DEEE, furniture) under prices, in the cart and on orders. Coupons never reduce it.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Facilo
 * Author URI:        https://facilo.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ecopart
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

define( 'ECOPART_VERSION', '1.0.0' );
define( 'ECOPART_FILE', __FILE__ );
define( 'ECOPART_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECOPART_URL', plugin_dir_url( __FILE__ ) );

// The Get Pro link. A plain link to the checkout: Free makes no outside request.
if ( ! defined( 'ECOPART_PRO_URL' ) ) {
	define( 'ECOPART_PRO_URL', 'https://checkout.freemius.com/plugin/45099/plan/79503/' );
}

require_once ECOPART_DIR . 'includes/class-ecopart-fee.php';
require_once ECOPART_DIR . 'includes/class-ecopart-product-fields.php';
require_once ECOPART_DIR . 'includes/class-ecopart-display.php';
require_once ECOPART_DIR . 'includes/class-ecopart-coupons.php';
require_once ECOPART_DIR . 'includes/class-ecopart-order.php';
require_once ECOPART_DIR . 'includes/class-ecopart-admin.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ECOPART_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ECOPART_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Ecopart_Product_Fields::init();
		Ecopart_Display::init();
		Ecopart_Coupons::init();
		Ecopart_Order::init();
		Ecopart_Admin::init();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( false === get_option( 'ecopart_settings' ) ) {
			add_option( 'ecopart_settings', Ecopart_Fee::defaults() );
		}
	}
);

/**
 * The fee of a product, resolved: variation, then parent, then Pro's category table.
 *
 * @param WC_Product $product Product or variation.
 * @return array|null Fee data (amount, ht, tax, ttc, type, ref, source), or null when none.
 */
function ecopart_get_fee( $product ) {
	return Ecopart_Fee::get( $product );
}
