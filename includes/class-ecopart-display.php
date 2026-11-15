<?php
/**
 * Shopper-facing lines: under prices, in the cart, checkout and mini-cart.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront display.
 */
class Ecopart_Display {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register front assets. They're enqueued only when a fee is shown.
	 */
	public static function register_assets() {
		wp_register_style( 'ecopart-front', ECOPART_URL . 'assets/front.css', array(), ECOPART_VERSION );
		wp_register_script(
			'ecopart-front',
			ECOPART_URL . 'assets/front.js',
			array(),
			ECOPART_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Enqueue the stylesheet, and the "?" script when it's on.
	 *
	 * @param bool $why Also the script.
	 */
	private static function enqueue( $why = false ) {
		if ( ! wp_style_is( 'ecopart-front', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'ecopart-front' );
		if ( $why ) {
			wp_enqueue_script( 'ecopart-front' );
		}
	}

	/**
	 * Where the price is being shown: 'product' for the main product of a product page, otherwise 'shop'.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function context( $product ) {
		if ( ! is_product() ) {
			return 'shop';
		}
		$main = get_queried_object_id();
		$id   = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( (int) $main !== (int) $id ) {
			return 'shop';
		}
		// Related and up-sell loops on the product page are lists, not the main product.
		if ( wc_get_loop_prop( 'name' ) && in_array( wc_get_loop_prop( 'name' ), array( 'related', 'up-sells', 'cross-sells' ), true ) ) {
			return 'shop';
		}
		return 'product';
	}

	/**
	 * The line under a price.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function price_html( $html, $product ) {
		if ( '' === $html || ( is_admin() && ! wp_doing_ajax() ) || ! $product instanceof WC_Product ) {
			return $html;
		}
		$s   = Ecopart_Fee::settings();
		$ctx = self::context( $product );
		if ( ( 'product' === $ctx && empty( $s['loc_product'] ) ) || ( 'shop' === $ctx && empty( $s['loc_shop'] ) ) ) {
			return $html;
		}
		$line = self::price_line( $product, 'product' === $ctx && ! empty( $s['why'] ) );
		if ( '' === $line ) {
			return $html;
		}
		/**
		 * Filters the line shown under a price.
		 *
		 * @param string     $line    Line HTML.
		 * @param WC_Product $product Product.
		 * @param string     $context 'product' or 'shop'.
		 */
		$line = apply_filters( 'ecopart_price_html', $line, $product, $ctx );
		return $html . $line;
	}

	/**
	 * Build the price line for a product, or '' without a fee.
	 *
	 * @param WC_Product $product Product.
	 * @param bool       $why     Add the "?" explanation.
	 * @return string
	 */
	public static function price_line( $product, $why = false ) {
		$fee = Ecopart_Fee::get( $product );
		if ( ! $fee ) {
			return '';
		}
		$s    = Ecopart_Fee::settings();
		$mode = Ecopart_Fee::display_mode( 'shop' );
		$tpl  = 2 === (int) $s['style'] ? Ecopart_Fee::label( 'line2' ) : Ecopart_Fee::label( 'line1' );
		$text = Ecopart_Fee::fill(
			$tpl,
			array(
				'fee'    => Ecopart_Fee::unit( $fee, $mode ),
				'suffix' => 'excl' === $mode ? 'HT' : '',
			)
		);
		self::enqueue( $why );
		$cls = 'ecopart-line' . ( 'normal' === $s['size'] ? ' is-normal' : '' );
		return '<span class="' . esc_attr( $cls ) . '"><span class="ecopart-text">' . $text . '</span>' . ( $why ? self::why() : '' ) . '</span>';
	}

	/**
	 * The "?" button and its explanation.
	 *
	 * @return string
	 */
	public static function why() {
		static $n = 0;
		++$n;
		$s    = Ecopart_Fee::settings();
		$id   = 'ecopart-why-' . $n;
		$link = '';
		if ( ! empty( $s['why_link'] ) ) {
			$link = ' <a href="' . esc_url( $s['why_link'] ) . '" target="_blank" rel="noopener">' . esc_html( Ecopart_Fee::label( 'why_more' ) ) . '</a>';
		}
		return '<span class="ecopart-why"><button type="button" class="ecopart-why-btn" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">' . esc_html( Ecopart_Fee::label( 'why_label' ) ) . '</button>'
			. '<span class="ecopart-tip" role="tooltip" id="' . esc_attr( $id ) . '" hidden>' . esc_html( Ecopart_Fee::label( 'why_text' ) ) . $link . '</span></span>';
	}

	/**
	 * Cart, checkout and mini-cart item line.
	 *
	 * @param array $data Item data.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function item_data( $data, $item ) {
		$s = Ecopart_Fee::settings();
		if ( empty( $s['loc_cart'] ) || empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return $data;
		}
		$fee = Ecopart_Fee::get( $item['data'] );
		if ( ! $fee ) {
			return $data;
		}
		$qty  = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
		$mode = Ecopart_Fee::display_mode( 'cart' );
		$unit = Ecopart_Fee::unit( $fee, $mode );
		$tpl  = $qty > 1 ? Ecopart_Fee::label( 'cartn' ) : Ecopart_Fee::label( 'cart1' );
		$html = Ecopart_Fee::fill(
			$tpl,
			array(
				'fee'    => $unit,
				'qty'    => $qty,
				'total'  => $unit * $qty,
				'suffix' => 'excl' === $mode ? 'HT' : '',
			)
		);
		/**
		 * Filters the cart item line.
		 *
		 * @param string $html Line HTML, "Name : value".
		 * @param array  $item Cart item.
		 * @param array  $fee  Resolved fee.
		 */
		$html = apply_filters( 'ecopart_item_label', $html, $item, $fee );
		self::enqueue();
		list( $key, $value ) = self::split_label( $html );
		$data[]              = array(
			'key'     => $key,
			'value'   => wp_strip_all_tags( $value ),
			'display' => $value,
		);
		return $data;
	}

	/**
	 * Split "Name : value" into its two halves. WooCommerce prints its own colon,
	 * so the name keeps a no-break space before it, as French typography wants.
	 *
	 * @param string $html Line.
	 * @return array
	 */
	public static function split_label( $html ) {
		$pos = strpos( $html, ' : ' );
		if ( false === $pos ) {
			return array( "Éco-participation\u{00A0}", $html );
		}
		return array( substr( $html, 0, $pos ) . "\u{00A0}", substr( $html, $pos + 3 ) );
	}
}
