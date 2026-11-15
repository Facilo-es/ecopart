<?php
/**
 * Coupon protection: a coupon never reduces an item below its fee.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Takes each item's fee out of what coupons may discount.
 *
 * WooCommerce works out every coupon type (percent, fixed product, fixed cart, the remainder
 * cents and stacked coupons) from each item's price. Lowering that price by the fee before a
 * coupon applies keeps every path exact: the fee is never part of the discountable amount.
 */
class Ecopart_Coupons {

	/**
	 * Whether a percent coupon touched an item with a fee in this calculation.
	 *
	 * @var bool
	 */
	private static $touched = false;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( __CLASS__, 'items_to_apply' ), 20, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reset' ), 1 );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'after_totals' ), 99 );
	}

	/**
	 * Whether protection is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$s = Ecopart_Fee::settings();
		/**
		 * Filters whether coupons are kept from reducing the fee.
		 *
		 * @param bool $on On.
		 */
		return (bool) apply_filters( 'ecopart_protect_coupons', ! empty( $s['protect_coupons'] ) );
	}

	/**
	 * Reset per-calculation state.
	 */
	public static function reset() {
		self::$touched = false;
	}

	/**
	 * The fee for a whole cart line, in the same tax terms WooCommerce discounts in.
	 *
	 * @param WC_Product $product       Product.
	 * @param float      $qty           Quantity.
	 * @param bool       $incl_tax      Prices include tax.
	 * @return float
	 */
	public static function line_fee( $product, $qty, $incl_tax ) {
		$fee = Ecopart_Fee::get( $product );
		if ( ! $fee ) {
			return 0.0;
		}
		$unit = Ecopart_Fee::unit( $fee, $incl_tax ? 'incl' : 'excl' );
		return round( $unit * $qty, wc_get_price_decimals() );
	}

	/**
	 * Lower each item's discountable price by its fee (prices here are in cents).
	 *
	 * @param array     $items  Items (clones, for this coupon only).
	 * @param WC_Coupon $coupon Coupon.
	 * @return array
	 */
	public static function items_to_apply( $items, $coupon ) {
		if ( ! self::enabled() || ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->product ) || ! $item->product instanceof WC_Product ) {
				continue;
			}
			$incl = isset( $item->price_includes_tax ) ? (bool) $item->price_includes_tax : wc_prices_include_tax();
			$fee  = self::line_fee( $item->product, (float) $item->quantity, $incl );
			if ( $fee <= 0 ) {
				continue;
			}
			$item->price = max( 0, $item->price - wc_add_number_precision( $fee ) );
			if ( $coupon instanceof WC_Coupon && $coupon->is_type( 'percent' ) && $coupon->get_amount() > 0 ) {
				self::$touched = true;
			}
		}
		return $items;
	}

	/**
	 * After the totals: show the note once when the fee was kept out of a discount.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function after_totals( $cart ) {
		if ( ! self::enabled() || ! $cart instanceof WC_Cart || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$applied = self::$touched || self::cap_reached( $cart );
		if ( ! $applied ) {
			WC()->session->set( 'ecopart_coupon_note', null );
			return;
		}
		$sig = implode( ',', $cart->get_applied_coupons() );
		if ( WC()->session->get( 'ecopart_coupon_note' ) === $sig ) {
			return;
		}
		WC()->session->set( 'ecopart_coupon_note', $sig );
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return;
		}
		wc_add_notice( esc_html( Ecopart_Fee::label( 'coupon_note' ) ), 'notice', array( 'ecopart' => 1 ) );
	}

	/**
	 * Whether a fixed coupon was held back by a fee: an item with a fee that a coupon brought down to the fee.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return bool
	 */
	private static function cap_reached( $cart ) {
		if ( ! $cart->get_applied_coupons() ) {
			return false;
		}
		$incl = wc_prices_include_tax();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || empty( $item['line_subtotal'] ) ) {
				continue;
			}
			$fee = self::line_fee( $item['data'], (float) $item['quantity'], $incl );
			if ( $fee <= 0 ) {
				continue;
			}
			$sub   = (float) $item['line_subtotal'] + ( $incl ? (float) $item['line_subtotal_tax'] : 0 );
			$total = (float) $item['line_total'] + ( $incl ? (float) $item['line_tax'] : 0 );
			if ( $total < $sub && $total <= $fee + 0.01 ) {
				return true;
			}
		}
		return false;
	}
}
