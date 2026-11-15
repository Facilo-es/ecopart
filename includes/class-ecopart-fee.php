<?php
/**
 * Fee resolution, tax and formatting.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a product's éco-participation and turns it into amounts and text.
 */
class Ecopart_Fee {

	/**
	 * Settings cache for this request.
	 *
	 * @var array|null
	 */
	private static $settings = null;

	/**
	 * Default settings. Shopper texts stay French whatever the admin language.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'style'           => 1,
			'size'            => 'small',
			'loc_product'     => 1,
			'loc_shop'        => 0,
			'loc_cart'        => 1,
			'why'             => 1,
			'why_link'        => '',
			'entry_mode'      => 'ht',
			'protect_coupons' => 1,
			'default_type'    => 'eee',
			'wizard_done'     => 0,
			'delete_data'     => 0,
			'labels'          => self::default_labels(),
		);
	}

	/**
	 * Default shopper texts (French, from the spec, word for word).
	 *
	 * @return array
	 */
	public static function default_labels() {
		return array(
			'line1'       => "dont {fee} d'éco-participation",
			'line2'       => 'Éco-part. {fee} incluse',
			'cart1'       => 'Éco-participation : {fee} (incluse)',
			'cartn'       => 'Éco-participation : {fee} × {qty} = {total} (incluse)',
			'order'       => 'Éco-participation : {fee} × {qty} = {total}, incluse dans le prix',
			'coupon_note' => "L'éco-participation n'est pas remisée : elle reste identique quelle que soit la réduction.",
			'why_label'   => "Qu'est-ce que c'est ?",
			'why_more'    => 'En savoir plus',
			'why_text'    => "L'éco-participation est ajoutée au prix des équipements électriques et des meubles neufs. Elle finance la collecte, le réemploi et le recyclage de ces produits quand ils sont usagés. Son montant est fixé par un éco-organisme agréé et ne peut faire l'objet d'aucune remise.",
		);
	}

	/**
	 * Settings merged over defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( null === self::$settings ) {
			$saved          = get_option( 'ecopart_settings', array() );
			$saved          = is_array( $saved ) ? $saved : array();
			$defaults       = self::defaults();
			$s              = array_merge( $defaults, $saved );
			$s['labels']    = array_merge( $defaults['labels'], isset( $saved['labels'] ) && is_array( $saved['labels'] ) ? array_filter( $saved['labels'], 'strlen' ) : array() );
			self::$settings = $s;
		}
		return self::$settings;
	}

	/**
	 * Forget cached settings, after a save.
	 */
	public static function flush() {
		self::$settings = null;
	}

	/**
	 * One shopper text.
	 *
	 * @param string $key Label key.
	 * @return string
	 */
	public static function label( $key ) {
		$s = self::settings();
		return isset( $s['labels'][ $key ] ) ? (string) $s['labels'][ $key ] : '';
	}

	/**
	 * Fee types.
	 *
	 * @return array
	 */
	public static function types() {
		return array(
			'eee'       => __( 'Electrical and electronic equipment (DEEE)', 'ecopart' ),
			'furniture' => __( 'Furniture (ameublement)', 'ecopart' ),
			'other'     => __( 'Other', 'ecopart' ),
		);
	}

	/**
	 * Normalise an entered amount ("2,20", "2.2", " 2 ") to a decimal string with up to 4 decimals.
	 *
	 * @param mixed $raw Raw input.
	 * @return string Empty string when none.
	 */
	public static function clean_amount( $raw ) {
		$raw = trim( str_replace( array( ' ', "\xc2\xa0", '€' ), '', (string) $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		$raw = str_replace( ',', '.', $raw );
		if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
			return '';
		}
		$out = rtrim( rtrim( number_format( (float) $raw, 4, '.', '' ), '0' ), '.' );
		return '' === $out ? '0' : $out;
	}

	/**
	 * Resolve a product's fee: variation, then parent, then filters (Pro's category table).
	 *
	 * @param WC_Product $product Product.
	 * @return array|null
	 */
	public static function get( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		$fee    = null;
		$source = $product;
		$amount = (string) $product->get_meta( '_ecopart_amount', true );
		if ( '' === $amount && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$amount = (string) $parent->get_meta( '_ecopart_amount', true );
				$source = $parent;
			}
		}
		if ( '' !== $amount ) {
			$type = (string) $product->get_meta( '_ecopart_type', true );
			if ( '' === $type ) {
				$type = (string) $source->get_meta( '_ecopart_type', true );
			}
			$ref = (string) $product->get_meta( '_ecopart_ref', true );
			if ( '' === $ref && $source !== $product ) {
				$ref = (string) $source->get_meta( '_ecopart_ref', true );
			}
			$src = (string) $source->get_meta( '_ecopart_source', true );
			$fee = array(
				'amount' => (float) $amount,
				'type'   => $type ? $type : 'other',
				'ref'    => $ref,
				'source' => $src ? $src : 'manual',
			);
		}

		/**
		 * Filters the raw fee of a product before tax is worked out.
		 *
		 * @param array|null $fee     Array with amount (as entered), type, ref, source; or null.
		 * @param WC_Product $product Product or variation.
		 */
		$fee = apply_filters( 'ecopart_product_fee', $fee, $product );
		if ( ! is_array( $fee ) || ! isset( $fee['amount'] ) || (float) $fee['amount'] <= 0 ) {
			return null;
		}
		return array_merge( $fee, self::split( (float) $fee['amount'], $product ) );
	}

	/**
	 * Tax rates that apply to a product, for the current customer or the shop base.
	 *
	 * @param WC_Product $product Product.
	 * @param bool       $base    Use the shop's base rates.
	 * @return array
	 */
	public static function rates( $product, $base = false ) {
		if ( ! wc_tax_enabled() || 'taxable' !== $product->get_tax_status() ) {
			return array();
		}
		if ( $base ) {
			return WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
		}
		return WC_Tax::get_rates( $product->get_tax_class() );
	}

	/**
	 * Split an entered amount into HT, tax and TTC at the product's rates.
	 *
	 * @param float      $amount  Amount as entered.
	 * @param WC_Product $product Product.
	 * @param array|null $rates   Rates to use, or null for the product's.
	 * @return array ht, tax, ttc (unrounded).
	 */
	public static function split( $amount, $product, $rates = null ) {
		$rates = null === $rates ? self::rates( $product ) : $rates;
		$s     = self::settings();
		if ( ! wc_tax_enabled() ) {
			return array(
				'ht'  => $amount,
				'tax' => 0.0,
				'ttc' => $amount,
			);
		}
		if ( 'ttc' === $s['entry_mode'] ) {
			// Entered with tax: take the tax out at the shop's base rates, as WooCommerce does for prices.
			$base_rates = $rates ? self::rates( $product, true ) : array();
			$base_rates = $base_rates ? $base_rates : $rates;
			$ht         = $amount - array_sum( WC_Tax::calc_tax( $amount, $base_rates, true ) );
		} else {
			$ht = $amount;
		}
		$tax = $rates ? array_sum( WC_Tax::calc_tax( $ht, $rates, false ) ) : 0.0;
		return array(
			'ht'  => $ht,
			'tax' => $tax,
			'ttc' => $ht + $tax,
		);
	}

	/**
	 * How amounts show in a context: 'incl', 'excl' or 'none' (taxes off).
	 *
	 * @param string $context 'shop' or 'cart'.
	 * @return string
	 */
	public static function display_mode( $context = 'shop' ) {
		if ( ! wc_tax_enabled() ) {
			return 'none';
		}
		$opt = 'cart' === $context ? get_option( 'woocommerce_tax_display_cart' ) : get_option( 'woocommerce_tax_display_shop' );
		return 'excl' === $opt ? 'excl' : 'incl';
	}

	/**
	 * The unit amount for display, rounded to the shop's decimals.
	 *
	 * @param array  $fee  Resolved fee.
	 * @param string $mode Display mode.
	 * @return float
	 */
	public static function unit( $fee, $mode ) {
		$v = 'excl' === $mode ? $fee['ht'] : $fee['ttc'];
		return round( (float) $v, wc_get_price_decimals() );
	}

	/**
	 * Fill a text template. Amounts are HTML from wc_price(); the rest is escaped.
	 *
	 * @param string $template Template with {fee}, {qty}, {total}.
	 * @param array  $values   fee, qty, total (floats), suffix, currency.
	 * @return string HTML.
	 */
	public static function fill( $template, $values ) {
		$args   = isset( $values['currency'] ) ? array( 'currency' => $values['currency'] ) : array();
		$suffix = isset( $values['suffix'] ) && $values['suffix'] ? "\xc2\xa0" . esc_html( $values['suffix'] ) : '';
		$repl   = array(
			'{fee}'   => '<span class="ecopart-amount">' . wc_price( $values['fee'], $args ) . $suffix . '</span>',
			'{qty}'   => isset( $values['qty'] ) ? esc_html( (string) $values['qty'] ) : '',
			'{total}' => isset( $values['total'] ) ? '<span class="ecopart-amount">' . wc_price( $values['total'], $args ) . '</span>' : '',
		);
		$parts  = preg_split( '/(\{fee\}|\{qty\}|\{total\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out    = '';
		foreach ( $parts as $p ) {
			$out .= isset( $repl[ $p ] ) ? $repl[ $p ] : esc_html( $p );
		}
		// Keep "× 2 =" on one line with the amounts.
		return str_replace( array( ' × ', ' = ' ), array( "\xc2\xa0×\xc2\xa0", "\xc2\xa0=\xc2\xa0" ), $out );
	}
}
