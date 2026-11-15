<?php
/**
 * Order line record: frozen at order time, shown in admin, emails, My account and invoices.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saves each item's fee on the order and prints one line from it.
 */
class Ecopart_Order {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'from_checkout' ), 20, 3 );
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'from_admin' ), 20, 2 );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'formatted' ), 20, 2 );
	}

	/**
	 * Classic and block checkout (both run this hook).
	 *
	 * @param WC_Order_Item_Product $item     Item.
	 * @param string                $cart_key Cart key.
	 * @param array                 $values   Cart item.
	 */
	public static function from_checkout( $item, $cart_key, $values ) {
		if ( empty( $values['data'] ) || ! $values['data'] instanceof WC_Product ) {
			return;
		}
		self::record( $item, $values['data'], null, Ecopart_Fee::display_mode( 'cart' ) );
	}

	/**
	 * Orders created or edited in admin: product lines with no record yet.
	 *
	 * @param int           $item_id  Item ID.
	 * @param WC_Order_Item $item     Item.
	 */
	public static function from_admin( $item_id, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product || '' !== (string) $item->get_meta( '_ecopart_unit_ht', true ) ) {
			return;
		}
		$product = $item->get_product();
		if ( ! $product ) {
			return;
		}
		if ( self::record( $item, $product, Ecopart_Fee::rates( $product, true ), Ecopart_Fee::display_mode( 'cart' ) ) ) {
			$item->save_meta_data();
		}
	}

	/**
	 * Write the hidden record onto an item.
	 *
	 * @param WC_Order_Item_Product $item    Item.
	 * @param WC_Product            $product Product.
	 * @param array|null            $rates   Tax rates, or null for the customer's.
	 * @param string                $mode    Display mode at order time.
	 * @return bool Whether a fee was recorded.
	 */
	public static function record( $item, $product, $rates, $mode ) {
		$fee = Ecopart_Fee::get( $product );
		if ( ! $fee ) {
			return false;
		}
		if ( null !== $rates ) {
			$fee = array_merge( $fee, Ecopart_Fee::split( (float) $fee['amount'], $product, $rates ) );
		}
		$item->update_meta_data( '_ecopart_unit_ht', wc_format_decimal( $fee['ht'], 4 ) );
		$item->update_meta_data( '_ecopart_unit_tax', wc_format_decimal( $fee['tax'], 4 ) );
		$item->update_meta_data( '_ecopart_type', $fee['type'] );
		$item->update_meta_data( '_ecopart_ref', $fee['ref'] );
		$item->update_meta_data( '_ecopart_qty', (string) $item->get_quantity() );
		$item->update_meta_data( '_ecopart_display', $mode );
		$item->update_meta_data( '_ecopart_label', Ecopart_Fee::label( 'order' ) );
		return true;
	}

	/**
	 * Amounts of a recorded item.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @return array|null unit (rounded, in the frozen display mode), qty, total, ht, tax, mode.
	 */
	public static function amounts( $item ) {
		$ht = (string) $item->get_meta( '_ecopart_unit_ht', true );
		if ( '' === $ht ) {
			return null;
		}
		$tax  = (float) $item->get_meta( '_ecopart_unit_tax', true );
		$mode = (string) $item->get_meta( '_ecopart_display', true );
		$mode = $mode ? $mode : 'incl';
		$unit = round( 'excl' === $mode || 'none' === $mode ? (float) $ht : (float) $ht + $tax, wc_get_price_decimals() );
		$qty  = (float) $item->get_quantity();
		return array(
			'unit'  => $unit,
			'qty'   => $qty,
			'total' => $unit * $qty,
			'ht'    => (float) $ht,
			'tax'   => $tax,
			'mode'  => $mode,
		);
	}

	/**
	 * The visible line, built from the record and its frozen label.
	 *
	 * @param array         $meta Formatted meta.
	 * @param WC_Order_Item $item Item.
	 * @return array
	 */
	public static function formatted( $meta, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $meta;
		}
		$a = self::amounts( $item );
		if ( ! $a ) {
			return $meta;
		}
		$order               = $item->get_order();
		$label               = (string) $item->get_meta( '_ecopart_label', true );
		$label               = $label ? $label : Ecopart_Fee::label( 'order' );
		$suffixes            = array(
			'incl' => 'TTC',
			'excl' => 'HT',
			'none' => '',
		);
		$html                = Ecopart_Fee::fill(
			$label,
			array(
				'fee'      => $a['unit'],
				'qty'      => wc_stock_amount( $a['qty'] ),
				'total'    => $a['total'],
				'suffix'   => isset( $suffixes[ $a['mode'] ] ) ? $suffixes[ $a['mode'] ] : '',
				'currency' => $order ? $order->get_currency() : get_woocommerce_currency(),
			)
		);
		list( $key, $value ) = Ecopart_Display::split_label( $html );
		$meta['ecopart']     = (object) array(
			'key'           => 'ecopart',
			'value'         => wp_strip_all_tags( $value ),
			'display_key'   => $key,
			'display_value' => $value,
		);
		return $meta;
	}
}
