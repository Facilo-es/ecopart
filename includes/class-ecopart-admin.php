<?php
/**
 * WooCommerce → Éco-participation: setup wizard, Overview, Display, Help and Pro tabs.
 *
 * @package Ecopart
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
class Ecopart_Admin {

	const SLUG = 'ecopart';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_ecopart_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ECOPART_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entry under WooCommerce.
	 */
	public static function menu() {
		self::$hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Éco-participation', 'ecopart' ),
			__( 'Éco-participation', 'ecopart' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ecopart' ) . '</a>' );
		return $links;
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = '', $args = array() ) {
		$args = array_merge( array( 'page' => self::SLUG ), $tab ? array( 'tab' => $tab ) : array(), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Admin assets: our screen, the product editor and the products list.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$ours    = self::$hook && $hook === self::$hook;
		$product = $screen && 'product' === $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true );
		if ( ! $ours && ! $product ) {
			return;
		}
		wp_enqueue_style( 'ecopart-admin', ECOPART_URL . 'assets/admin.css', array(), ECOPART_VERSION );
		wp_enqueue_script( 'ecopart-admin', ECOPART_URL . 'assets/admin.js', array(), ECOPART_VERSION, true );
		wp_localize_script(
			'ecopart-admin',
			'ecopartAdmin',
			array(
				'decimals'   => wc_get_price_decimals(),
				'dec'        => wc_get_price_decimal_separator(),
				'thousand'   => wc_get_price_thousand_separator(),
				'format'     => get_woocommerce_price_format(),
				'symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				/* translators: 1: amount with currency, 2: VAT rate. */
				'hintIncl'   => __( '= %1$s TTC at %2$s %%', 'ecopart' ),
				/* translators: 1: amount with currency, 2: VAT rate. */
				'hintExcl'   => __( '= %1$s HT at %2$s %%', 'ecopart' ),
				'pricesIncl' => wc_prices_include_tax() ? 1 : 0,
			)
		);
	}

	/**
	 * Product IDs (parents) that have a fee on themselves or on a variation.
	 *
	 * @return int[]
	 */
	public static function ids_with_fee() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$found = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'id=>parent',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only count, run on our screen and the filtered products list.
				'meta_query'     => array(
					array(
						'key'     => '_ecopart_amount',
						'value'   => 0,
						'compare' => '>',
						'type'    => 'DECIMAL(10,4)',
					),
				),
			)
		);
		$ids = array();
		// 'id=>parent' gives an id => parent map on some WordPress versions and objects on others.
		foreach ( $found as $id => $post ) {
			$parent = is_object( $post ) ? (int) $post->post_parent : (int) $post;
			$own    = is_object( $post ) ? (int) $post->ID : (int) $id;
			$ids[]  = $parent ? $parent : $own;
		}
		$ids = array_values( array_unique( $ids ) );
		return $ids;
	}

	/**
	 * Numbers for the Overview cards.
	 *
	 * @return array with, without, types
	 */
	public static function stats() {
		$with  = self::ids_with_fee();
		$all   = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$with  = array_values( array_intersect( $with, array_map( 'intval', $all ) ) );
		$types = array();
		$def   = Ecopart_Fee::settings()['default_type'];
		if ( $with ) {
			update_meta_cache( 'post', $with );
		}
		foreach ( $with as $id ) {
			$t = get_post_meta( $id, '_ecopart_type', true );
			$t = $t ? $t : $def;
			if ( ! isset( $types[ $t ] ) ) {
				$types[ $t ] = 0;
			}
			++$types[ $t ];
		}
		return array(
			'with'    => count( $with ),
			'without' => max( 0, count( $all ) - count( $with ) ),
			'types'   => $types,
		);
	}

	/**
	 * Save settings from the wizard or the Display tab.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'ecopart' ), 403 );
		}
		check_admin_referer( 'ecopart_save', 'ecopart_nonce' );

		$s    = Ecopart_Fee::settings();
		$form = isset( $_POST['ecopart_form'] ) ? sanitize_key( wp_unslash( $_POST['ecopart_form'] ) ) : '';
		$bool = static function ( $key ) {
			return empty( $_POST[ $key ] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		};
		$pick = static function ( $key, $allowed, $fallback ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$v = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			return in_array( $v, $allowed, true ) ? $v : $fallback;
		};

		$s['default_type'] = $pick( 'default_type', array_keys( Ecopart_Fee::types() ), $s['default_type'] );
		$s['style']        = (int) $pick( 'style', array( '1', '2' ), (string) $s['style'] );
		$s['size']         = $pick( 'size', array( 'small', 'normal' ), $s['size'] );
		$s['why']          = $bool( 'why' );
		$s['loc_product']  = $bool( 'loc_product' );
		$s['loc_shop']     = $bool( 'loc_shop' );
		$s['loc_cart']     = $bool( 'loc_cart' );

		if ( 'display' === $form ) {
			$s['entry_mode']      = $pick( 'entry_mode', array( 'ht', 'ttc' ), $s['entry_mode'] );
			$s['protect_coupons'] = $bool( 'protect_coupons' );
			$s['delete_data']     = $bool( 'delete_data' );
			$s['why_link']        = isset( $_POST['why_link'] ) ? esc_url_raw( wp_unslash( $_POST['why_link'] ) ) : '';
			$posted               = isset( $_POST['labels'] ) && is_array( $_POST['labels'] ) ? wp_unslash( $_POST['labels'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
			$labels               = array();
			foreach ( Ecopart_Fee::default_labels() as $key => $default ) {
				$v = isset( $posted[ $key ] ) ? sanitize_textarea_field( $posted[ $key ] ) : '';
				// Store only what differs from the default, so improved defaults still reach old installs.
				if ( '' !== $v && $v !== $default ) {
					$labels[ $key ] = $v;
				}
			}
			$s['labels'] = $labels;
		} else {
			unset( $s['labels'] );
			$saved            = get_option( 'ecopart_settings', array() );
			$s['labels']      = isset( $saved['labels'] ) && is_array( $saved['labels'] ) ? $saved['labels'] : array();
			$s['wizard_done'] = 1;
		}

		update_option( 'ecopart_settings', $s );
		Ecopart_Fee::flush();

		$go = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : '';
		if ( 'products' === $go ) {
			$to = add_query_arg(
				array(
					'post_type' => 'product',
					'ecopart'   => 'none',
				),
				admin_url( 'edit.php' )
			);
		} elseif ( 'pro' === $go ) {
			$to = self::url( 'pro' );
		} else {
			$to = self::url( 'display' === $form ? 'display' : '', array( 'updated' => 1 ) );
		}
		wp_safe_redirect( $to );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = Ecopart_Fee::settings();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$wizard = ! $s['wizard_done'] || ! empty( $_GET['wizard'] );
		$saved  = ! empty( $_GET['updated'] );
		// phpcs:enable
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap ecopart-wrap">';
		self::title( $wizard );
		if ( $wizard ) {
			self::wizard( $s );
			echo '</div>';
			return;
		}
		echo '<nav class="nav-tab-wrapper ecopart-tabs" aria-label="' . esc_attr__( 'Éco-participation sections', 'ecopart' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::url( 'overview' === $key ? '' : $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'ecopart' ) . '</p></div>';
		}
		if ( method_exists( __CLASS__, 'tab_' . $tab ) ) {
			call_user_func( array( __CLASS__, 'tab_' . $tab ), $s );
		} else {
			/**
			 * Render a tab added through ecopart_admin_tabs.
			 *
			 * @param array $s Settings.
			 */
			do_action( 'ecopart_admin_tab_' . $tab, $s );
		}
		echo '</div>';
	}

	/**
	 * Tabs.
	 *
	 * @return array
	 */
	private static function tabs() {
		$tabs = array(
			'overview' => __( 'Overview', 'ecopart' ),
			'display'  => __( 'Display', 'ecopart' ),
			'help'     => __( 'Help', 'ecopart' ),
			'pro'      => __( 'Pro', 'ecopart' ),
		);
		/**
		 * Filter the tabs, so Pro can add its own.
		 *
		 * @param array $tabs key => label.
		 */
		return apply_filters( 'ecopart_admin_tabs', $tabs );
	}

	/**
	 * Title row, with the wizard steps.
	 *
	 * @param bool $wizard Wizard showing.
	 */
	private static function title( $wizard ) {
		echo '<div class="ecopart-title">';
		echo '<img class="ecopart-mark" src="' . esc_url( ECOPART_URL . 'assets/img/mark.svg' ) . '" alt="" width="36" height="36" />';
		echo '<h1>' . esc_html__( 'Éco-participation', 'ecopart' ) . '</h1>';
		if ( $wizard ) {
			$steps = array( __( 'What you sell', 'ecopart' ), __( 'How it looks', 'ecopart' ), __( 'Where it shows', 'ecopart' ) );
			echo '<ol class="ecopart-dots" aria-label="' . esc_attr__( 'Setup steps', 'ecopart' ) . '">';
			foreach ( $steps as $i => $label ) {
				printf( '<li data-step="%1$d"%2$s>%3$s</li>', (int) $i + 1, 0 === $i ? ' class="cur" aria-current="step"' : '', esc_html( $label ) );
			}
			echo '</ol>';
		}
		echo '</div>';
	}

	/**
	 * Hidden fields shared by both forms.
	 *
	 * @param string $form wizard or display.
	 */
	private static function form_open( $form ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ecopart-form" data-form="' . esc_attr( $form ) . '">';
		echo '<input type="hidden" name="action" value="ecopart_save" />';
		echo '<input type="hidden" name="ecopart_form" value="' . esc_attr( $form ) . '" />';
		wp_nonce_field( 'ecopart_save', 'ecopart_nonce' );
	}

	/**
	 * A choice card: a real radio inside a label, styled as a card.
	 *
	 * @param string $name    Field.
	 * @param string $value   Value.
	 * @param bool   $checked Checked.
	 * @param string $inner   Inner HTML, already escaped.
	 */
	private static function card( $name, $value, $checked, $inner ) {
		printf(
			'<label class="ecopart-card"><input type="radio" name="%1$s" value="%2$s"%3$s />%4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$inner // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the callers.
		);
	}

	/**
	 * A switch: a real checkbox styled as a toggle.
	 *
	 * @param string $name    Field.
	 * @param bool   $checked Checked.
	 * @param string $label   Label text.
	 * @param string $note    Optional note.
	 * @param bool   $locked  Always on.
	 */
	private static function toggle( $name, $checked, $label, $note = '', $locked = false ) {
		printf(
			'<label class="ecopart-tg%1$s"><input type="checkbox" role="switch" name="%2$s" value="1"%3$s%4$s /><i aria-hidden="true"></i><span>%5$s%6$s</span></label>',
			$locked ? ' is-locked' : '',
			esc_attr( $name ),
			checked( $checked || $locked, true, false ),
			$locked ? ' disabled' : '',
			esc_html( $label ),
			$note ? '<small>' . esc_html( $note ) . '</small>' : ''
		);
	}

	/**
	 * Size switch (Small · Normal).
	 *
	 * @param array $s Settings.
	 */
	private static function size_switch( $s ) {
		echo '<fieldset class="ecopart-seg"><legend>' . esc_html__( 'Size', 'ecopart' ) . '</legend>';
		foreach ( array(
			'small'  => __( 'Small', 'ecopart' ),
			'normal' => __( 'Normal', 'ecopart' ),
		) as $v => $l ) {
			printf( '<label><input type="radio" name="size" value="%1$s"%2$s /><span>%3$s</span></label>', esc_attr( $v ), checked( $s['size'], $v, false ), esc_html( $l ) );
		}
		echo '</fieldset>';
	}

	/**
	 * Style cards with the sample line.
	 *
	 * @param array $s Settings.
	 */
	private static function style_cards( $s ) {
		echo '<div class="ecopart-cards" role="radiogroup" aria-label="' . esc_attr__( 'Label style', 'ecopart' ) . '">';
		foreach ( array( 1, 2 ) as $n ) {
			$inner = '<span class="ecopart-p">' . wp_kses_post( wc_price( 129 ) ) . '</span>'
				. '<span class="ecopart-sample">' . self::sample( 'line' . $n, 2.2 ) . '</span>';
			self::card( 'style', (string) $n, (int) $s['style'] === $n, $inner );
		}
		echo '</div>';
	}

	/**
	 * A sample shopper line from the default text.
	 *
	 * @param string $key   Label key.
	 * @param float  $fee   Fee.
	 * @param int    $qty   Quantity.
	 * @param bool   $saved Use the saved label rather than the default.
	 * @return string HTML.
	 */
	private static function sample( $key, $fee, $qty = 1, $saved = true ) {
		$labels = Ecopart_Fee::default_labels();
		$tpl    = $saved ? Ecopart_Fee::label( $key ) : $labels[ $key ];
		return Ecopart_Fee::fill(
			$tpl,
			array(
				'fee'    => $fee,
				'qty'    => $qty,
				'total'  => $fee * $qty,
				'suffix' => 'order' === $key ? 'TTC' : '',
			)
		);
	}

	/**
	 * Setup wizard: three steps in one form. Without JavaScript all steps show at once.
	 *
	 * @param array $s Settings.
	 */
	private static function wizard( $s ) {
		self::form_open( 'wizard' );
		$types = array(
			'eee'       => array( __( 'Electrical and electronic equipment', 'ecopart' ), __( 'Appliances, electronics, lighting, tools', 'ecopart' ) ),
			'furniture' => array( __( 'Furniture, bedding and decoration textiles', 'ecopart' ), __( 'Chairs, tables, mattresses, curtains', 'ecopart' ) ),
			'other'     => array( __( 'Other products with an eco fee', 'ecopart' ), __( 'Any other product your supplier bills a fee on', 'ecopart' ) ),
		);
		?>
		<section class="ecopart-ctl ecopart-step" data-step="1" aria-labelledby="ecopart-q1">
			<div class="ecopart-q">
				<span class="ecopart-n">1/3</span>
				<h2 id="ecopart-q1"><?php esc_html_e( 'What do you sell?', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'New products get this fee type. You can change it on any product.', 'ecopart' ); ?></p>
			</div>
			<div class="ecopart-cards is-three" role="radiogroup" aria-labelledby="ecopart-q1">
				<?php
				foreach ( $types as $key => $t ) {
					self::card( 'default_type', $key, $s['default_type'] === $key, '<b>' . esc_html( $t[0] ) . '</b><span>' . esc_html( $t[1] ) . '</span>' );
				}
				?>
			</div>
			<div class="ecopart-go">
				<button type="button" class="button button-primary ecopart-next"><?php esc_html_e( 'Continue', 'ecopart' ); ?></button>
			</div>
		</section>

		<section class="ecopart-ctl ecopart-step" data-step="2" aria-labelledby="ecopart-q2">
			<div class="ecopart-q">
				<span class="ecopart-n">2/3</span>
				<h2 id="ecopart-q2"><?php esc_html_e( 'How should it look?', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'Your shoppers see the fee right under the price, in the cart and on the invoice.', 'ecopart' ); ?></p>
			</div>
			<?php self::style_cards( $s ); ?>
			<div class="ecopart-opts">
				<?php
				self::size_switch( $s );
				self::toggle( 'why', ! empty( $s['why'] ), __( 'Show a ? link that explains the fee', 'ecopart' ) );
				?>
			</div>
			<div class="ecopart-go">
				<button type="button" class="button ecopart-back"><?php esc_html_e( 'Back', 'ecopart' ); ?></button>
				<button type="button" class="button button-primary ecopart-next"><?php esc_html_e( 'Continue', 'ecopart' ); ?></button>
			</div>
		</section>

		<section class="ecopart-ctl ecopart-step" data-step="3" aria-labelledby="ecopart-q3">
			<div class="ecopart-q">
				<span class="ecopart-n">3/3</span>
				<h2 id="ecopart-q3"><?php esc_html_e( 'Where should it show?', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'Enter product prices that already include the éco-participation, as in shops.', 'ecopart' ); ?></p>
			</div>
			<div class="ecopart-opts is-list">
				<?php self::locations( $s ); ?>
			</div>
			<div class="ecopart-go">
				<button type="button" class="button ecopart-back"><?php esc_html_e( 'Back', 'ecopart' ); ?></button>
				<button type="submit" name="go" value="products" class="button button-primary"><?php esc_html_e( 'Add my first amount', 'ecopart' ); ?></button>
				<button type="submit" name="go" value="pro" class="button-link ecopart-prolink"><?php esc_html_e( 'Importing a supplier file? See Pro', 'ecopart' ); ?></button>
			</div>
		</section>
		<p class="ecopart-skip"><button type="submit" name="go" value="" class="button-link"><?php esc_html_e( 'Skip setup and keep these settings', 'ecopart' ); ?></button></p>
		</form>
		<?php
		self::preview( $s );
	}

	/**
	 * Location toggles.
	 *
	 * @param array $s Settings.
	 */
	private static function locations( $s ) {
		self::toggle( 'loc_product', ! empty( $s['loc_product'] ), __( 'Product page', 'ecopart' ) );
		self::toggle( 'loc_shop', ! empty( $s['loc_shop'] ), __( 'Shop and category pages', 'ecopart' ) );
		self::toggle( 'loc_cart', ! empty( $s['loc_cart'] ), __( 'Cart and checkout', 'ecopart' ) );
		self::toggle( 'loc_order', true, __( 'Orders, emails and invoices', 'ecopart' ), __( 'Always on, so every order keeps its record', 'ecopart' ), true );
	}

	/**
	 * The live preview: product page, phone cart and order email, built from a sample order.
	 * Both label styles are rendered; admin.js shows the chosen one as settings change.
	 *
	 * @param array  $s     Settings.
	 * @param string $extra Extra class.
	 */
	private static function preview( $s, $extra = '' ) {
		$img   = ECOPART_URL . 'assets/img/';
		$size  = 'normal' === $s['size'] ? ' is-normal' : '';
		$style = (int) $s['style'];
		$why   = '<span class="ecopart-why ecopart-preview-why"' . ( $s['why'] ? '' : ' hidden' ) . '><span class="ecopart-why-btn">' . esc_html( Ecopart_Fee::label( 'why_label' ) ) . '</span></span>';
		$line  = static function ( $fee ) use ( $style ) {
			return '<span class="ecopart-text" data-style="1"' . ( 1 === $style ? '' : ' hidden' ) . '>' . self::sample( 'line1', $fee ) . '</span>'
				. '<span class="ecopart-text" data-style="2"' . ( 2 === $style ? '' : ' hidden' ) . '>' . self::sample( 'line2', $fee ) . '</span>';
		};
		$chair = wc_price( 129 );
		$lamp  = wc_price( 59.8 );
		$cp    = wc_price( -18.6 );
		$total = wc_price( 170.2 );
		?>
		<div class="ecopart-stage<?php echo esc_attr( $size . ( $extra ? ' ' . $extra : '' ) ); ?>" aria-label="<?php esc_attr_e( 'Preview with a sample order', 'ecopart' ); ?>" role="group">
			<figure class="ecopart-pane ecopart-web" data-loc="product"<?php echo $s['loc_product'] ? '' : ' data-off="1"'; ?>>
				<figcaption><?php esc_html_e( 'Product page', 'ecopart' ); ?><em class="ecopart-offnote"><?php esc_html_e( 'Hidden', 'ecopart' ); ?></em></figcaption>
				<div class="ecopart-browser">
					<div class="ecopart-chrome" aria-hidden="true"><i></i><i></i><i></i><span><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></span></div>
					<div class="ecopart-pdp">
						<div class="ecopart-img"><img src="<?php echo esc_url( $img . 'chair.svg' ); ?>" alt="" /></div>
						<div class="ecopart-pi">
							<p class="ecopart-crumb"><?php esc_html_e( 'Sample product', 'ecopart' ); ?></p>
							<h3><?php esc_html_e( 'Armchair', 'ecopart' ); ?></h3>
							<p class="ecopart-price"><?php echo wp_kses_post( $chair ); ?><span class="ecopart-line"><?php echo $line( 2.2 ) . $why; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></span></p>
							<div class="ecopart-buy" aria-hidden="true"><span class="ecopart-qty">1</span><span class="ecopart-add"><?php esc_html_e( 'Add to cart', 'ecopart' ); ?></span></div>
						</div>
					</div>
				</div>
			</figure>
			<figure class="ecopart-pane" data-loc="cart"<?php echo $s['loc_cart'] ? '' : ' data-off="1"'; ?>>
				<figcaption><?php esc_html_e( 'Cart on a phone', 'ecopart' ); ?><em class="ecopart-offnote"><?php esc_html_e( 'Hidden', 'ecopart' ); ?></em></figcaption>
				<div class="ecopart-phone"><div class="ecopart-scr">
					<p class="ecopart-ph"><?php esc_html_e( 'Cart', 'ecopart' ); ?></p>
					<div class="ecopart-it">
						<img class="ecopart-th" src="<?php echo esc_url( $img . 'chair.svg' ); ?>" alt="" />
						<div class="ecopart-tx"><b><?php esc_html_e( 'Armchair', 'ecopart' ); ?></b><span class="ecopart-item"><?php echo self::sample( 'cart1', 2.2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fill(). ?></span></div>
						<span class="ecopart-pr"><?php echo wp_kses_post( $chair ); ?></span>
					</div>
					<div class="ecopart-it">
						<img class="ecopart-th" src="<?php echo esc_url( $img . 'lamp.svg' ); ?>" alt="" />
						<div class="ecopart-tx"><b><?php esc_html_e( 'Lamp', 'ecopart' ); ?> &times; 2</b><span class="ecopart-item"><?php echo self::sample( 'cartn', 0.3, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fill(). ?></span></div>
						<span class="ecopart-pr"><?php echo wp_kses_post( $lamp ); ?></span>
					</div>
					<div class="ecopart-tot">
						<p class="ecopart-cp"><span><?php esc_html_e( 'Coupon: BIENVENUE10', 'ecopart' ); ?></span><span><?php echo wp_kses_post( $cp ); ?></span></p>
						<span class="ecopart-note"><?php echo esc_html( Ecopart_Fee::label( 'coupon_note' ) ); ?></span>
						<p class="ecopart-big"><span><?php esc_html_e( 'Total', 'ecopart' ); ?></span><span><?php echo wp_kses_post( $total ); ?></span></p>
					</div>
				</div></div>
			</figure>
			<figure class="ecopart-pane" data-loc="order">
				<figcaption><?php esc_html_e( 'Order email and invoice', 'ecopart' ); ?><em class="ecopart-lock"><?php esc_html_e( 'Always on, so every order keeps its record', 'ecopart' ); ?></em></figcaption>
				<div class="ecopart-mail">
					<div class="ecopart-mh"><?php esc_html_e( 'Thank you for your order', 'ecopart' ); ?></div>
					<div class="ecopart-mb">
						<p class="ecopart-on"><?php esc_html_e( 'Armchair × 1', 'ecopart' ); ?></p>
						<p class="ecopart-item"><?php echo self::sample( 'order', 2.2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fill(). ?></p>
						<p class="ecopart-on"><?php esc_html_e( 'Lamp × 2', 'ecopart' ); ?></p>
						<p class="ecopart-item"><?php echo self::sample( 'order', 0.3, 2 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in fill(). ?></p>
					</div>
				</div>
			</figure>
		</div>
		<?php
	}

	/**
	 * Overview tab.
	 *
	 * @param array $s Settings.
	 */
	public static function tab_overview( $s ) {
		$st    = self::stats();
		$types = Ecopart_Fee::types();
		$none  = add_query_arg(
			array(
				'post_type' => 'product',
				'ecopart'   => 'none',
			),
			admin_url( 'edit.php' )
		);
		$has   = add_query_arg( 'ecopart', 'has', remove_query_arg( 'ecopart', $none ) );
		?>
		<div class="ecopart-stats">
			<div class="ecopart-stat">
				<b><?php echo esc_html( number_format_i18n( $st['with'] ) ); ?></b><span><?php echo esc_html( _n( 'product with a fee', 'products with a fee', $st['with'], 'ecopart' ) ); ?></span>
				<?php
				if ( $st['with'] ) :
					?>
					<a href="<?php echo esc_url( $has ); ?>"><?php esc_html_e( 'Show them', 'ecopart' ); ?></a><?php endif; ?>
			</div>
			<div class="ecopart-stat">
				<b><?php echo esc_html( number_format_i18n( $st['without'] ) ); ?></b><span><?php echo esc_html( _n( 'product without a fee', 'products without a fee', $st['without'], 'ecopart' ) ); ?></span>
				<?php
				if ( $st['without'] ) :
					?>
					<a href="<?php echo esc_url( $none ); ?>"><?php esc_html_e( 'Show them', 'ecopart' ); ?></a><?php endif; ?>
			</div>
			<div class="ecopart-stat">
				<b><?php echo esc_html( number_format_i18n( count( $st['types'] ) ) ); ?></b><span><?php echo esc_html( _n( 'fee type in use', 'fee types in use', count( $st['types'] ), 'ecopart' ) ); ?></span>
				<?php if ( $st['types'] ) : ?>
					<ul>
						<?php foreach ( $st['types'] as $t => $n ) : ?>
							<li><?php echo esc_html( ( isset( $types[ $t ] ) ? $types[ $t ] : $t ) . ': ' . number_format_i18n( $n ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! $st['with'] ) : ?>
			<div class="ecopart-empty">
				<p><?php esc_html_e( "No amounts yet. Open a product and fill in Éco-participation, or import your supplier's file with Pro.", 'ecopart' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( $none ); ?>"><?php esc_html_e( 'Add my first amount', 'ecopart' ); ?></a> <a class="button" href="<?php echo esc_url( self::url( 'pro' ) ); ?>"><?php esc_html_e( 'See Pro', 'ecopart' ); ?></a></p>
			</div>
		<?php endif; ?>
		<div class="ecopart-summary">
			<h2><?php esc_html_e( 'What your shoppers see', 'ecopart' ); ?></h2>
			<a href="<?php echo esc_url( self::url( 'display' ) ); ?>"><?php esc_html_e( 'Edit display', 'ecopart' ); ?></a>
		</div>
		<?php
		self::preview( $s, 'is-static' );
	}

	/**
	 * Display tab: settings with the live preview beside them.
	 *
	 * @param array $s Settings.
	 */
	public static function tab_display( $s ) {
		$labels = array(
			'line1'       => __( 'Under the price, first style', 'ecopart' ),
			'line2'       => __( 'Under the price, second style', 'ecopart' ),
			'cart1'       => __( 'Cart item, quantity 1', 'ecopart' ),
			'cartn'       => __( 'Cart item, quantity over 1', 'ecopart' ),
			'order'       => __( 'Order line (emails, invoices)', 'ecopart' ),
			'coupon_note' => __( 'Coupon note', 'ecopart' ),
			'why_label'   => __( '"?" link label', 'ecopart' ),
			'why_text'    => __( 'Explanation text', 'ecopart' ),
			'why_more'    => __( 'Explanation link text', 'ecopart' ),
		);
		$def    = Ecopart_Fee::default_labels();
		echo '<div class="ecopart-split">';
		self::form_open( 'display' );
		?>
		<section class="ecopart-box">
			<h2><?php esc_html_e( 'Look', 'ecopart' ); ?></h2>
			<?php self::style_cards( $s ); ?>
			<div class="ecopart-opts">
				<?php
				self::size_switch( $s );
				self::toggle( 'why', ! empty( $s['why'] ), __( 'Show a ? link that explains the fee', 'ecopart' ) );
				?>
			</div>
			<p class="ecopart-field">
				<label for="ecopart-why-link"><?php esc_html_e( 'Link in the explanation (optional)', 'ecopart' ); ?></label>
				<input type="url" id="ecopart-why-link" name="why_link" class="regular-text" value="<?php echo esc_attr( $s['why_link'] ); ?>" placeholder="https://" />
			</p>
		</section>
		<section class="ecopart-box">
			<h2><?php esc_html_e( 'Where it shows', 'ecopart' ); ?></h2>
			<div class="ecopart-opts is-list"><?php self::locations( $s ); ?></div>
		</section>
		<section class="ecopart-box">
			<h2><?php esc_html_e( 'Amounts and coupons', 'ecopart' ); ?></h2>
			<fieldset class="ecopart-seg">
				<legend><?php esc_html_e( 'Amounts are entered', 'ecopart' ); ?></legend>
				<label><input type="radio" name="entry_mode" value="ht"<?php checked( $s['entry_mode'], 'ht' ); ?> /><span><?php esc_html_e( 'Without VAT (HT)', 'ecopart' ); ?></span></label>
				<label><input type="radio" name="entry_mode" value="ttc"<?php checked( $s['entry_mode'], 'ttc' ); ?> /><span><?php esc_html_e( 'With VAT (TTC)', 'ecopart' ); ?></span></label>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Barèmes are HT. Enter HT and the plugin adds VAT at the product\'s rate.', 'ecopart' ); ?></p>
			<p class="ecopart-field">
				<label for="ecopart-default-type"><?php esc_html_e( 'Fee type for new products', 'ecopart' ); ?></label>
				<select id="ecopart-default-type" name="default_type">
					<?php foreach ( Ecopart_Fee::types() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $s['default_type'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<div class="ecopart-opts is-list">
				<?php self::toggle( 'protect_coupons', ! empty( $s['protect_coupons'] ), __( 'Coupons never reduce the éco-participation', 'ecopart' ), __( 'On by default. The cart shows a short note when a coupon touches an item with a fee.', 'ecopart' ) ); ?>
			</div>
		</section>
		<section class="ecopart-box">
			<h2><?php esc_html_e( 'Shopper texts', 'ecopart' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These are in French whatever your admin language. {fee}, {qty} and {total} are filled in for you. Empty a box to go back to the default.', 'ecopart' ); ?></p>
			<?php foreach ( $labels as $key => $l ) : ?>
				<p class="ecopart-field">
					<label for="ecopart-l-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $l ); ?></label>
					<?php if ( 'why_text' === $key ) : ?>
						<textarea id="ecopart-l-<?php echo esc_attr( $key ); ?>" name="labels[<?php echo esc_attr( $key ); ?>]" rows="4" class="large-text" data-label="<?php echo esc_attr( $key ); ?>" placeholder="<?php echo esc_attr( $def[ $key ] ); ?>"><?php echo esc_textarea( Ecopart_Fee::label( $key ) ); ?></textarea>
					<?php else : ?>
						<input type="text" id="ecopart-l-<?php echo esc_attr( $key ); ?>" name="labels[<?php echo esc_attr( $key ); ?>]" class="large-text" data-label="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( Ecopart_Fee::label( $key ) ); ?>" placeholder="<?php echo esc_attr( $def[ $key ] ); ?>" />
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</section>
		<section class="ecopart-box">
			<h2><?php esc_html_e( 'Uninstall', 'ecopart' ); ?></h2>
			<div class="ecopart-opts is-list">
				<?php self::toggle( 'delete_data', ! empty( $s['delete_data'] ), __( 'Remove all data on uninstall', 'ecopart' ), __( 'Off by default: product amounts and the records on past orders stay, because orders are accounting records.', 'ecopart' ) ); ?>
			</div>
		</section>
		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'ecopart' ); ?></button>
			<a class="button-link ecopart-rerun" href="<?php echo esc_url( self::url( '', array( 'wizard' => 1 ) ) ); ?>"><?php esc_html_e( 'Run the setup again', 'ecopart' ); ?></a>
		</p>
		</form>
		<div class="ecopart-side">
			<?php self::preview( $s, 'is-side' ); ?>
		</div>
		</div>
		<?php
	}

	/**
	 * Help tab.
	 */
	public static function tab_help() {
		$links = array(
			'https://www.ecosystem.eco/'       => 'Ecosystem',
			'https://www.ecologic-france.com/' => 'Ecologic',
			'https://www.ecomaison.com/'       => 'Ecomaison',
		);
		?>
		<div class="ecopart-help">
			<section class="ecopart-box">
				<h2><?php esc_html_e( 'What the plugin does', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'It shows the amounts you enter: under the price, in the cart and checkout, and on orders, emails and invoices. It doesn\'t decide the amounts. Enter product prices that already include the éco-participation, as in shops.', 'ecopart' ); ?></p>
			</section>
			<section class="ecopart-box">
				<h2><?php esc_html_e( 'Where amounts come from', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'Your supplier\'s invoice or file shows the amount for each product. If you import products yourself, use your éco-organisme\'s barème:', 'ecopart' ); ?></p>
				<ul class="ecopart-links">
					<?php foreach ( $links as $url => $name ) : ?>
						<li><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $name ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</section>
			<section class="ecopart-box">
				<h2><?php esc_html_e( 'HT or TTC', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'Barèmes are HT. Enter HT and the plugin adds VAT at the product\'s rate. If you prefer to enter amounts with VAT, change it in Display.', 'ecopart' ); ?></p>
			</section>
			<section class="ecopart-box">
				<h2><?php esc_html_e( 'Coupons', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'The éco-participation is passed on in full, so a discount can\'t reduce it. With this setting on, coupons only reduce the rest of the price, and the cart says so in one short line.', 'ecopart' ); ?></p>
			</section>
			<section class="ecopart-box">
				<h2><?php esc_html_e( 'What this plugin isn\'t', 'ecopart' ); ?></h2>
				<p><?php esc_html_e( 'Ecopart is a display tool, not legal advice. Check the rules for your products with your éco-organisme or adviser.', 'ecopart' ); ?></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Pro tab: a plain link, no remote calls.
	 */
	public static function tab_pro() {
		$bullets = array(
			__( 'Import your supplier\'s file and every matching product updates, with an undo button.', 'ecopart' ),
			__( 'Set amounts by category or weight, once.', 'ecopart' ),
			__( 'Show the total éco-participation on invoices and emails.', 'ecopart' ),
			__( 'Offer free take-back at checkout.', 'ecopart' ),
			__( 'See what you sold by fee type.', 'ecopart' ),
		);
		?>
		<section class="ecopart-pro">
			<h2><?php esc_html_e( 'Update hundreds of amounts in a minute', 'ecopart' ); ?></h2>
			<ul>
				<?php foreach ( $bullets as $b ) : ?>
					<li><?php echo esc_html( $b ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="ecopart-price-line"><?php esc_html_e( 'EUR 49 a year for one store · EUR 129 a year for up to 10 stores', 'ecopart' ); ?></p>
			<p><a class="button button-primary button-hero" href="<?php echo esc_url( ECOPART_PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'ecopart' ); ?></a></p>
		</section>
		<?php
	}
}
