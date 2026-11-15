<?php
/**
 * Product and variation fields, quick edit and the products list column.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fields where shops enter amounts.
 */
class Ecopart_Product_Fields {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
		add_action( 'woocommerce_product_quick_edit_end', array( __CLASS__, 'quick_edit_fields' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( __CLASS__, 'quick_edit_save' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'list_query' ) );
	}

	/**
	 * Field label with HT or TTC.
	 *
	 * @return string
	 */
	private static function amount_label() {
		$s = Ecopart_Fee::settings();
		if ( ! wc_tax_enabled() ) {
			return __( 'Éco-participation', 'ecopart' );
		}
		return 'ttc' === $s['entry_mode'] ? __( 'Éco-participation (TTC)', 'ecopart' ) : __( 'Éco-participation (HT)', 'ecopart' );
	}

	/**
	 * Single base tax rate for the hint, or null when unclear.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	private static function hint_rate( $product ) {
		if ( ! wc_tax_enabled() ) {
			return null;
		}
		$rates = Ecopart_Fee::rates( $product, true );
		if ( 1 !== count( $rates ) ) {
			return null;
		}
		$r = reset( $rates );
		return (float) $r['rate'];
	}

	/**
	 * Hint under the amount.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $amount  Stored amount.
	 * @return string Plain text.
	 */
	private static function hint_text( $product, $amount ) {
		$rate = self::hint_rate( $product );
		if ( ! wc_tax_enabled() ) {
			return '';
		}
		if ( null === $rate ) {
			return __( "VAT is added at this product's own rate", 'ecopart' );
		}
		if ( '' === $amount ) {
			return '';
		}
		$s   = Ecopart_Fee::settings();
		$a   = (float) $amount;
		$out = 'ttc' === $s['entry_mode'] ? $a / ( 1 + $rate / 100 ) : $a * ( 1 + $rate / 100 );
		/* translators: 1: amount, 2: tax rate. */
		$tpl = 'ttc' === $s['entry_mode'] ? __( '= %1$s HT at %2$s %%', 'ecopart' ) : __( '= %1$s TTC at %2$s %%', 'ecopart' );
		return sprintf( $tpl, wp_strip_all_tags( wc_price( $out ) ), wc_format_localized_decimal( (string) $rate ) );
	}

	/**
	 * The amount field, shared by products and variations.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $name    Input name.
	 * @param string     $id      Input id.
	 * @param bool       $is_var  Variation.
	 */
	private static function amount_field( $product, $name, $id, $is_var ) {
		$amount = (string) $product->get_meta( '_ecopart_amount', true );
		$rate   = self::hint_rate( $product );
		$s      = Ecopart_Fee::settings();
		$hint   = $is_var && '' === $amount ? __( "Leave empty to use the main product's amount", 'ecopart' ) : self::hint_text( $product, $amount );
		$price  = (float) ( $product->get_sale_price() ? $product->get_sale_price() : $product->get_regular_price() );
		$warn   = '' !== $amount && $price > 0 && $price < self::comparable( $product, (float) $amount );
		?>
		<p class="form-field ecopart-field <?php echo $is_var ? 'form-row form-row-first' : ''; ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( self::amount_label() ); ?></label>
			<input type="text" class="short wc_input_price ecopart-amount-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( '' === $amount ? '' : wc_format_localized_price( $amount ) ); ?>" placeholder="<?php echo esc_attr( wc_format_localized_price( '0.00' ) ); ?>"
				data-rate="<?php echo esc_attr( null === $rate ? '' : (string) $rate ); ?>"
				data-mode="<?php echo esc_attr( $s['entry_mode'] ); ?>"
				data-var="<?php echo $is_var ? '1' : '0'; ?>"
				data-price="<?php echo esc_attr( (string) $price ); ?>" />
			<span class="description ecopart-hint"><?php echo esc_html( $hint ); ?></span>
			<span class="ecopart-warn" <?php echo $warn ? '' : 'hidden'; ?>><?php esc_html_e( "The price is lower than the éco-participation. The fee is included in the price, so check this product's price.", 'ecopart' ); ?></span>
		</p>
		<?php
	}

	/**
	 * The amount in the same terms as the product price, for the price check.
	 *
	 * @param WC_Product $product Product.
	 * @param float      $amount  Amount as entered.
	 * @return float
	 */
	private static function comparable( $product, $amount ) {
		$split = Ecopart_Fee::split( $amount, $product, Ecopart_Fee::rates( $product, true ) );
		return wc_prices_include_tax() ? $split['ttc'] : $split['ht'];
	}

	/**
	 * Fields on the General tab.
	 */
	public static function product_fields() {
		global $product_object;
		if ( ! $product_object instanceof WC_Product ) {
			return;
		}
		$s    = Ecopart_Fee::settings();
		$type = (string) $product_object->get_meta( '_ecopart_type', true );
		echo '<div class="options_group ecopart-fields">';
		wp_nonce_field( 'ecopart_product', 'ecopart_product_nonce' );
		self::amount_field( $product_object, 'ecopart_amount', 'ecopart_amount', false );
		woocommerce_wp_select(
			array(
				'id'      => 'ecopart_type',
				'label'   => __( 'Fee type', 'ecopart' ),
				'options' => Ecopart_Fee::types(),
				'value'   => $type ? $type : $s['default_type'],
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'ecopart_ref',
				'label'             => __( 'Reference (optional)', 'ecopart' ),
				'value'             => (string) $product_object->get_meta( '_ecopart_ref', true ),
				'description'       => __( "Your supplier's or éco-organisme's product code, for your records", 'ecopart' ),
				'custom_attributes' => array( 'maxlength' => 64 ),
			)
		);
		echo '</div>';
	}

	/**
	 * Write the three values to a product or variation.
	 *
	 * @param WC_Product $product Product.
	 * @param mixed      $amount  Raw amount.
	 * @param mixed      $type    Raw type.
	 * @param mixed      $ref     Raw reference.
	 */
	public static function write( $product, $amount, $type, $ref ) {
		$amount = Ecopart_Fee::clean_amount( $amount );
		$old    = (string) $product->get_meta( '_ecopart_amount', true );
		if ( '' === $amount ) {
			$product->delete_meta_data( '_ecopart_amount' );
		} else {
			$product->update_meta_data( '_ecopart_amount', $amount );
			if ( $old !== $amount ) {
				$product->update_meta_data( '_ecopart_source', 'manual' );
			}
		}
		$type = sanitize_key( (string) $type );
		if ( isset( Ecopart_Fee::types()[ $type ] ) ) {
			$product->update_meta_data( '_ecopart_type', $type );
		} elseif ( '' === $type ) {
			$product->delete_meta_data( '_ecopart_type' );
		}
		if ( null !== $ref ) {
			$ref = mb_substr( sanitize_text_field( (string) $ref ), 0, 64 );
			if ( '' === $ref ) {
				$product->delete_meta_data( '_ecopart_ref' );
			} else {
				$product->update_meta_data( '_ecopart_ref', $ref );
			}
		}
	}

	/**
	 * Save from the product screen.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function save_product( $product ) {
		if ( ! isset( $_POST['ecopart_product_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ecopart_product_nonce'] ), 'ecopart_product' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$amount = isset( $_POST['ecopart_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['ecopart_amount'] ) ) : '';
		$type   = isset( $_POST['ecopart_type'] ) ? sanitize_key( $_POST['ecopart_type'] ) : '';
		$ref    = isset( $_POST['ecopart_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ecopart_ref'] ) ) : '';
		self::write( $product, $amount, $type, $ref );
	}

	/**
	 * Fields on each variation.
	 *
	 * @param int     $loop           Index.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		$v = wc_get_product( $variation->ID );
		if ( ! $v ) {
			return;
		}
		$type  = (string) $v->get_meta( '_ecopart_type', true );
		$types = array( '' => __( 'Same as main product', 'ecopart' ) ) + Ecopart_Fee::types();
		echo '<div class="ecopart-var-fields">';
		self::amount_field( $v, 'ecopart_amount[' . (int) $loop . ']', 'ecopart_amount_' . (int) $loop, true );
		woocommerce_wp_select(
			array(
				'id'            => 'ecopart_type_' . (int) $loop,
				'name'          => 'ecopart_type[' . (int) $loop . ']',
				'label'         => __( 'Fee type', 'ecopart' ),
				'options'       => $types,
				'value'         => $type,
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => 'ecopart_ref_' . (int) $loop,
				'name'              => 'ecopart_ref[' . (int) $loop . ']',
				'label'             => __( 'Reference (optional)', 'ecopart' ),
				'value'             => (string) $v->get_meta( '_ecopart_ref', true ),
				'wrapper_class'     => 'form-row form-row-full',
				'custom_attributes' => array( 'maxlength' => 64 ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save a variation (WooCommerce's variations AJAX save).
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @param int                  $i         Index.
	 */
	public static function save_variation( $variation, $i ) {
		if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_key( $_POST['security'] ), 'save-variations' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in write().
		$amount = isset( $_POST['ecopart_amount'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['ecopart_amount'][ $i ] ) ) : '';
		$type   = isset( $_POST['ecopart_type'][ $i ] ) ? sanitize_key( wp_unslash( $_POST['ecopart_type'][ $i ] ) ) : '';
		$ref    = isset( $_POST['ecopart_ref'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['ecopart_ref'][ $i ] ) ) : '';
		// phpcs:enable
		self::write( $variation, $amount, $type, $ref );
	}

	/**
	 * Quick edit fields.
	 */
	public static function quick_edit_fields() {
		?>
		<br class="clear" />
		<div class="ecopart-quick">
			<?php wp_nonce_field( 'ecopart_quick', 'ecopart_quick_nonce' ); ?>
			<label>
				<span class="title"><?php esc_html_e( 'Éco-part.', 'ecopart' ); ?></span>
				<span class="input-text-wrap"><input type="text" name="ecopart_amount" class="text wc_input_price" value="" placeholder="<?php echo esc_attr( wc_format_localized_price( '0.00' ) ); ?>" /></span>
			</label>
			<label>
				<span class="title"><?php esc_html_e( 'Fee type', 'ecopart' ); ?></span>
				<span class="input-text-wrap"><select name="ecopart_type">
					<?php foreach ( Ecopart_Fee::types() as $k => $t ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select></span>
			</label>
		</div>
		<?php
	}

	/**
	 * Save quick edit.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function quick_edit_save( $product ) {
		if ( ! isset( $_REQUEST['ecopart_quick_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['ecopart_quick_nonce'] ), 'ecopart_quick' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$amount = isset( $_REQUEST['ecopart_amount'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ecopart_amount'] ) ) : '';
		$type   = isset( $_REQUEST['ecopart_type'] ) ? sanitize_key( $_REQUEST['ecopart_type'] ) : '';
		self::write( $product, $amount, $type, null );
		$product->save();
	}

	/**
	 * Add the column after the price.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function add_column( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'price' === $k ) {
				$out['ecopart'] = esc_html__( 'Éco-part.', 'ecopart' );
			}
		}
		if ( ! isset( $out['ecopart'] ) ) {
			$out['ecopart'] = esc_html__( 'Éco-part.', 'ecopart' );
		}
		return $out;
	}

	/**
	 * Column content, plus hidden values for quick edit.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Product ID.
	 */
	public static function render_column( $col, $post_id ) {
		if ( 'ecopart' !== $col ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$own  = (string) $product->get_meta( '_ecopart_amount', true );
		$type = (string) $product->get_meta( '_ecopart_type', true );
		$fee  = Ecopart_Fee::get( $product );
		if ( $fee ) {
			echo wp_kses_post( wc_price( $fee['amount'] ) );
			if ( 'manual' !== $fee['source'] ) {
				$sources = array(
					'import'   => __( 'import', 'ecopart' ),
					'category' => __( 'category', 'ecopart' ),
				);
				$label   = isset( $sources[ $fee['source'] ] ) ? $sources[ $fee['source'] ] : $fee['source'];
				echo ' <span class="ecopart-src">' . esc_html( $label ) . '</span>';
			}
		} elseif ( $product->is_type( 'variable' ) && self::variations_have_fee( $product ) ) {
			echo '<span class="ecopart-src">' . esc_html__( 'per variation', 'ecopart' ) . '</span>';
		} else {
			echo '<span class="na">&ndash;</span>';
		}
		printf(
			'<span class="hidden ecopart-inline" data-amount="%s" data-type="%s"></span>',
			esc_attr( '' === $own ? '' : wc_format_localized_price( $own ) ),
			esc_attr( $type ? $type : Ecopart_Fee::settings()['default_type'] )
		);
	}

	/**
	 * Whether any variation has its own amount.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	private static function variations_have_fee( $product ) {
		foreach ( $product->get_children() as $child_id ) {
			if ( '' !== (string) get_post_meta( $child_id, '_ecopart_amount', true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make the column sortable.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function sortable( $cols ) {
		$cols['ecopart'] = 'ecopart';
		return $cols;
	}

	/**
	 * Sorting and the "with / without a fee" filter on the products list.
	 *
	 * @param WP_Query $q Query.
	 */
	public static function list_query( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || 'product' !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( 'ecopart' === $q->get( 'orderby' ) ) {
			$mq   = (array) $q->get( 'meta_query' );
			$mq[] = array(
				'relation'    => 'OR',
				'ecopart_has' => array(
					'key'     => '_ecopart_amount',
					'compare' => 'EXISTS',
					'type'    => 'DECIMAL(10,4)',
				),
				'ecopart_no'  => array(
					'key'     => '_ecopart_amount',
					'compare' => 'NOT EXISTS',
				),
			);
			$q->set( 'meta_query', $mq );
			$q->set( 'orderby', array( 'ecopart_has' => $q->get( 'order' ) ? $q->get( 'order' ) : 'ASC' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$filter = isset( $_GET['ecopart'] ) ? sanitize_key( $_GET['ecopart'] ) : '';
		if ( 'none' === $filter || 'has' === $filter ) {
			$ids  = Ecopart_Admin::ids_with_fee();
			$only = array_filter( array_map( 'absint', (array) $q->get( 'post__in' ) ) );
			// WP_Query ignores post__not_in when post__in is set, as WooCommerce's product search does, so narrow post__in instead.
			if ( 'has' === $filter ) {
				$keep = $only ? array_intersect( $only, $ids ) : $ids;
				$q->set( 'post__in', $keep ? array_values( $keep ) : array( 0 ) );
			} elseif ( $only ) {
				$keep = array_diff( $only, $ids );
				$q->set( 'post__in', $keep ? array_values( $keep ) : array( 0 ) );
			} else {
				$q->set( 'post__not_in', $ids );
			}
		}
	}
}
