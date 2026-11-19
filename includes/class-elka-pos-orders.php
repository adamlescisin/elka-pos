<?php
/**
 * Creating till sales as normal WooCommerce orders (shared stock with the e-shop),
 * refunds/returns, receipt data and e-mail control.
 *
 * Pricing model: the till works with shelf prices incl. VAT. Manual discounts
 * (per line and for the whole sale) are folded into the line price, so that
 * WooCommerce coupons can still be applied on top by WooCommerce itself and
 * the order stays the single source of truth for totals.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Orders {

	const CREATED_VIA = 'elka-pos';

	/** True while the till is sending an e-mail on purpose. */
	private static $sending = false;

	public static function init() {
		// Till sales do not e-mail the customer or the shop automatically – only on request.
		foreach ( array( 'customer_completed_order', 'customer_processing_order', 'customer_on_hold_order', 'customer_invoice', 'new_order', 'customer_refunded_order', 'customer_partially_refunded_order' ) as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'filter_email_enabled' ), 99, 3 );
		}
		add_filter( 'woocommerce_order_get_payment_method_title', array( __CLASS__, 'payment_title' ), 10, 2 );
	}

	public static function is_pos_order( $order ) {
		return $order instanceof WC_Order && self::CREATED_VIA === $order->get_created_via();
	}

	public static function filter_email_enabled( $enabled, $order = null, $email = null ) {
		if ( ! self::is_pos_order( $order ) ) {
			return $enabled;
		}
		return self::$sending ? true : false;
	}

	public static function payment_title( $title, $order ) {
		if ( self::is_pos_order( $order ) && '' === (string) $title ) {
			$method = $order->get_meta( '_elka_pos_payment' );
			$labels = self::payment_labels();
			return isset( $labels[ $method ] ) ? $labels[ $method ] : $title;
		}
		return $title;
	}

	public static function payment_labels() {
		return array(
			'cash' => 'Hotově',
			'qr'   => 'QR platba',
			'card' => Elka_Pos_Settings::get( 'card_label' ) ? Elka_Pos_Settings::get( 'card_label' ) : 'Kartou',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Create                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Find an order already created from this till payload (offline queue retries).
	 *
	 * @param string $uuid Client UUID.
	 * @return WC_Order|null
	 */
	public static function find_by_uuid( $uuid ) {
		if ( ! $uuid ) {
			return null;
		}
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_elka_pos_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'status'     => 'any',
			)
		);
		return $orders ? $orders[0] : null;
	}

	/**
	 * Split a gross amount into net + taxes using the shop's base rates.
	 *
	 * @param float  $gross     Gross amount.
	 * @param string $tax_class Tax class.
	 * @param bool   $taxable   Taxable.
	 * @return array { net, taxes[rate_id => amount] }
	 */
	public static function split_gross( $gross, $tax_class = '', $taxable = true ) {
		if ( ! wc_tax_enabled() || ! $taxable ) {
			return array( 'net' => (float) $gross, 'taxes' => array() );
		}
		$rates = WC_Tax::get_base_tax_rates( $tax_class );
		$taxes = WC_Tax::calc_tax( (float) $gross, $rates, true );
		$taxes = array_map(
			function ( $t ) {
				return wc_round_tax_total( $t );
			},
			$taxes
		);
		return array(
			'net'   => (float) $gross - array_sum( $taxes ),
			'taxes' => $taxes,
		);
	}

	/**
	 * Apply line + cart manual discounts to gross line amounts.
	 * Mirrors assets/js/cart.js – keep both in sync.
	 *
	 * @param array      $lines         Each with unitPrice, qty, discount.
	 * @param array|null $cart_discount type percent|amount, value.
	 * @return array Lines with 'gross', 'final', 'lineDiscount', 'cartDiscount'.
	 */
	public static function price_lines( array $lines, $cart_discount = null ) {
		$dec = wc_get_price_decimals();
		foreach ( $lines as &$l ) {
			$qty        = max( 0, (float) $l['qty'] );
			$gross      = round( (float) $l['unitPrice'] * $qty, $dec );
			$disc       = 0.0;
			if ( ! empty( $l['discount']['value'] ) ) {
				$v    = (float) $l['discount']['value'];
				$disc = 'percent' === $l['discount']['type'] ? round( $gross * min( 100, $v ) / 100, $dec ) : min( $gross, $v );
			}
			$l['gross']        = $gross;
			$l['lineDiscount'] = $disc;
			$l['final']        = max( 0, $gross - $disc );
			$l['cartDiscount'] = 0.0;
		}
		unset( $l );

		if ( $cart_discount && ! empty( $cart_discount['value'] ) ) {
			$base = 0.0;
			foreach ( $lines as $l ) {
				$base += $l['final'];
			}
			if ( $base > 0 ) {
				$v     = (float) $cart_discount['value'];
				$total = 'percent' === $cart_discount['type'] ? round( $base * min( 100, $v ) / 100, $dec ) : min( $base, $v );
				$left  = $total;
				$count = count( $lines );
				$i     = 0;
				foreach ( $lines as &$l ) {
					$i++;
					$share = $i === $count ? $left : round( $total * $l['final'] / $base, $dec );
					$share = min( $share, $l['final'], $left );
					$l['cartDiscount'] = $share;
					$l['final']       -= $share;
					$left             -= $share;
				}
				unset( $l );
			}
		}
		return $lines;
	}

	/**
	 * Create a completed sale from the till payload.
	 *
	 * @param array $p Payload (see assets/js/app.js → buildOrderPayload).
	 * @return WC_Order|WP_Error
	 */
	public static function create( array $p ) {
		$uuid = isset( $p['uuid'] ) ? sanitize_text_field( $p['uuid'] ) : '';
		$dup  = self::find_by_uuid( $uuid );
		if ( $dup ) {
			return $dup; // Idempotent: the offline queue may resend.
		}
		if ( empty( $p['lines'] ) || ! is_array( $p['lines'] ) ) {
			return new WP_Error( 'elka_pos_empty', 'Košík je prázdný.', array( 'status' => 400 ) );
		}
		$methods = self::payment_labels();
		$method  = isset( $p['payment']['method'] ) && isset( $methods[ $p['payment']['method'] ] ) ? $p['payment']['method'] : 'cash';

		$lines = self::price_lines( $p['lines'], isset( $p['cartDiscount'] ) ? $p['cartDiscount'] : null );

		$order = new WC_Order();
		$order->set_created_via( self::CREATED_VIA );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( true );

		// Sale time: offline sales keep the time they really happened.
		$when = ! empty( $p['createdAt'] ) ? strtotime( $p['createdAt'] ) : false;
		if ( $when && $when < time() + 300 ) {
			$order->set_date_created( $when );
		}

		// Customer.
		$c = isset( $p['customer'] ) && is_array( $p['customer'] ) ? $p['customer'] : array();
		$customer = null;
		if ( ! empty( $c['id'] ) ) {
			try {
				$customer = new WC_Customer( (int) $c['id'] );
			} catch ( Exception $e ) {
				$customer = null; // Account deleted meanwhile (offline queue) – sell as guest with the e-mail.
			}
		}
		if ( $customer && $customer->get_id() ) {
			$order->set_customer_id( $customer->get_id() );
			$order->set_billing_first_name( $customer->get_billing_first_name() ? $customer->get_billing_first_name() : $customer->get_first_name() );
			$order->set_billing_last_name( $customer->get_billing_last_name() ? $customer->get_billing_last_name() : $customer->get_last_name() );
			$order->set_billing_email( $customer->get_billing_email() ? $customer->get_billing_email() : $customer->get_email() );
			$order->set_billing_phone( $customer->get_billing_phone() );
			$order->set_billing_address_1( $customer->get_billing_address_1() );
			$order->set_billing_city( $customer->get_billing_city() );
			$order->set_billing_postcode( $customer->get_billing_postcode() );
			$order->set_billing_company( $customer->get_billing_company() );
			$order->set_billing_country( $customer->get_billing_country() ? $customer->get_billing_country() : Elka_Pos_Settings::get( 'default_country' ) );
		} else {
			$order->set_billing_first_name( isset( $c['firstName'] ) ? sanitize_text_field( $c['firstName'] ) : '' );
			$order->set_billing_last_name( isset( $c['lastName'] ) ? sanitize_text_field( $c['lastName'] ) : '' );
			$order->set_billing_country( Elka_Pos_Settings::get( 'default_country' ) );
		}
		$email = ! empty( $p['email'] ) ? sanitize_email( $p['email'] ) : ( ! empty( $c['email'] ) ? sanitize_email( $c['email'] ) : '' );
		if ( $email && is_email( $email ) && ! $order->get_billing_email() ) {
			$order->set_billing_email( $email );
		}

		// Items.
		foreach ( $lines as $l ) {
			$qty = max( 1, (int) $l['qty'] );
			if ( isset( $l['type'] ) && 'custom' === $l['type'] ) {
				$item = new WC_Order_Item_Product();
				$item->set_name( ! empty( $l['name'] ) ? sanitize_text_field( $l['name'] ) : 'Ruční položka' );
				$item->set_quantity( $qty );
				$tax_class = isset( $l['taxClass'] ) ? sanitize_title( $l['taxClass'] ) : '';
				$item->set_tax_class( $tax_class );
				$split = self::split_gross( $l['final'], $tax_class, true );
				$item->add_meta_data( '_elka_pos_custom', 1, true );
			} else {
				$product_id = ! empty( $l['variationId'] ) ? (int) $l['variationId'] : (int) $l['productId'];
				$product    = wc_get_product( $product_id );
				if ( ! $product ) {
					return new WP_Error( 'elka_pos_product', sprintf( 'Produkt #%d už neexistuje.', $product_id ), array( 'status' => 400 ) );
				}
				$item = new WC_Order_Item_Product();
				$item->set_product( $product );
				$item->set_quantity( $qty );
				if ( $product->is_type( 'variation' ) ) {
					// Fill "any" attributes chosen at the till.
					$attrs = $product->get_variation_attributes();
					if ( ! empty( $l['attrs'] ) && is_array( $l['attrs'] ) ) {
						foreach ( $l['attrs'] as $k => $v ) {
							if ( isset( $attrs[ $k ] ) && '' === $attrs[ $k ] ) {
								$attrs[ $k ] = sanitize_title( $v );
							}
						}
					}
					foreach ( $attrs as $k => $v ) {
						$tax  = str_replace( 'attribute_', '', $k );
						$term = taxonomy_exists( $tax ) ? get_term_by( 'slug', $v, $tax ) : null;
						$item->add_meta_data( $tax, $term ? $term->name : $v, true );
					}
				}
				$split = self::split_gross( $l['final'], $product->get_tax_class(), $product->is_taxable() );
			}
			$item->set_subtotal( $split['net'] );
			$item->set_total( $split['net'] );
			if ( $split['taxes'] ) {
				$item->set_taxes(
					array(
						'subtotal' => $split['taxes'],
						'total'    => $split['taxes'],
					)
				);
			}
			// Remember the shelf price and manual discounts for the receipt.
			$item->add_meta_data( '_elka_pos_unit_gross', (float) $l['unitPrice'], true );
			$item->add_meta_data( '_elka_pos_line_gross', (float) $l['gross'], true );
			$disc = (float) $l['lineDiscount'] + (float) $l['cartDiscount'];
			if ( $disc > 0 ) {
				$item->add_meta_data( '_elka_pos_discount', $disc, true );
				$label = '';
				if ( ! empty( $l['discount']['value'] ) ) {
					$label = 'percent' === $l['discount']['type'] ? wc_format_decimal( $l['discount']['value'] ) . ' %' : wc_format_decimal( $l['discount']['value'], 2 ) . ' Kč';
				}
				$item->add_meta_data( 'Sleva', trim( wc_format_decimal( $disc, 2 ) . ' Kč ' . ( $label ? '(' . $label . ')' : '' ) ), true );
			}
			$order->add_item( $item );
		}

		$order->update_taxes();
		$order->calculate_totals( false );
		$order->save();

		// Coupons (validated by WooCommerce – restrictions, e-mail lock, usage limits).
		if ( ! empty( $p['coupons'] ) && is_array( $p['coupons'] ) ) {
			foreach ( $p['coupons'] as $code ) {
				$res = $order->apply_coupon( wc_format_coupon_code( $code ) );
				if ( is_wp_error( $res ) ) {
					$order->delete( true );
					return new WP_Error( 'elka_pos_coupon', sprintf( 'Kupón %1$s nelze použít: %2$s', strtoupper( $code ), wp_strip_all_tags( $res->get_error_message() ) ), array( 'status' => 400 ) );
				}
			}
		}

		// Cash rounding to whole crowns.
		if ( 'cash' === $method && Elka_Pos_Settings::is( 'cash_rounding' ) ) {
			$total   = (float) $order->get_total();
			$rounded = round( $total );
			$diff    = round( $rounded - $total, 2 );
			if ( abs( $diff ) >= 0.01 ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( 'Zaokrouhlení' );
				$fee->set_amount( $diff );
				$fee->set_total( $diff );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
				$order->calculate_totals( false );
			}
		}

		// Payment.
		$total    = (float) $order->get_total();
		$tendered = isset( $p['payment']['tendered'] ) ? (float) $p['payment']['tendered'] : 0;
		$order->set_payment_method( 'elka_pos_' . $method );
		$order->set_payment_method_title( $methods[ $method ] );
		$order->set_date_paid( $when && $when < time() + 300 ? $when : time() );
		$order->update_meta_data( '_elka_pos_uuid', $uuid );
		$order->update_meta_data( '_elka_pos_payment', $method );
		$order->update_meta_data( '_elka_pos_register', Elka_Pos_Settings::get( 'register_id' ) );
		$order->update_meta_data( '_elka_pos_cashier', get_current_user_id() );
		if ( 'cash' === $method && $tendered > 0 ) {
			$order->update_meta_data( '_elka_pos_tendered', $tendered );
			$order->update_meta_data( '_elka_pos_change', max( 0, round( $tendered - $total, 2 ) ) );
		}
		if ( ! empty( $p['offline'] ) ) {
			$order->update_meta_data( '_elka_pos_offline', 1 );
		}
		if ( ! empty( $p['localNumber'] ) ) {
			$order->update_meta_data( '_elka_pos_local_number', sanitize_text_field( $p['localNumber'] ) );
		}
		if ( ! empty( $p['cartDiscount']['reason'] ) ) {
			$order->update_meta_data( '_elka_pos_discount_reason', sanitize_text_field( $p['cartDiscount']['reason'] ) );
		}
		if ( ! empty( $p['note'] ) ) {
			$order->set_customer_note( sanitize_textarea_field( $p['note'] ) );
		}
		$order->save();

		$status = Elka_Pos_Settings::get( 'order_status' );
		$order->update_status( in_array( $status, array( 'completed', 'processing' ), true ) ? $status : 'completed', 'Prodej na pokladně ELKA (' . $methods[ $method ] . ').' );

		Elka_Pos_Klub::sync_order( $order->get_id() );

		/**
		 * Fires after a till sale was created (EET reporting hooks in here).
		 */
		do_action( 'elka_pos_order_created', $order->get_id(), $p );

		if ( ! empty( $p['sendEmail'] ) && $order->get_billing_email() ) {
			self::send_email( $order );
		}

		return wc_get_order( $order->get_id() );
	}

	/* ------------------------------------------------------------------ */
	/* E-mail                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Send the receipt / invoice e-mail. WooCommerce PDF Invoices (if active)
	 * attaches the PDF to whichever e-mails are ticked in its settings.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $email Optional address (saved to the order if it has none).
	 * @return true|WP_Error
	 */
	public static function send_email( $order, $email = '' ) {
		if ( $email ) {
			$email = sanitize_email( $email );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'elka_pos_email', 'Neplatná e-mailová adresa.', array( 'status' => 400 ) );
			}
			if ( $order->get_billing_email() !== $email ) {
				$order->set_billing_email( $email );
				$order->save();
			}
		}
		if ( ! $order->get_billing_email() ) {
			return new WP_Error( 'elka_pos_email', 'Objednávka nemá e-mail.', array( 'status' => 400 ) );
		}
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$type   = Elka_Pos_Settings::get( 'email_type' );
		$key    = 'invoice' === $type ? 'WC_Email_Customer_Invoice' : 'WC_Email_Customer_Completed_Order';
		if ( empty( $emails[ $key ] ) ) {
			return new WP_Error( 'elka_pos_email', 'E-mailová šablona WooCommerce není k dispozici.' );
		}
		self::$sending = true;
		$force         = self::force_template_start();
		// The email builder looks up the visitor's country for lookups without an order;
		// give it the order's country (avoids a warning in the builder when nothing matches).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$had_country = isset( $_POST['billing_country'] );
		$old_country = $had_country ? $_POST['billing_country'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $force && ! $had_country ) {
			$_POST['billing_country'] = $order->get_billing_country() ? $order->get_billing_country() : Elka_Pos_Settings::get( 'default_country' );
		}
		try {
			$emails[ $key ]->trigger( $order->get_id(), $order );
		} finally {
			self::$sending = false;
			self::force_template_end( $force );
			if ( $had_country ) {
				$_POST['billing_country'] = $old_country;
			} else {
				unset( $_POST['billing_country'] );
			}
		}
		// phpcs:enable
		$order->add_order_note( 'Doklad odeslán e-mailem z pokladny na ' . $order->get_billing_email() . '.' );
		$order->update_meta_data( '_elka_pos_emailed', current_time( 'mysql' ) );
		$order->save();
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* E-mail builder templates (XStore Email Builder / VillaTheme)       */
	/* ------------------------------------------------------------------ */

	/**
	 * Post types of email-builder templates. XStore's builder is based on VillaTheme's
	 * "Email Template Customizer" (post type viwec_template); names are matched loosely
	 * in case the theme renames them.
	 */
	public static function template_post_types() {
		global $wpdb;
		$out = array();
		foreach ( get_post_types( array(), 'names' ) as $pt ) {
			if ( false !== strpos( $pt, 'viwec' ) || ( false !== strpos( $pt, 'email' ) && false !== strpos( $pt, 'templ' ) ) ) {
				$out[] = $pt;
			}
		}
		// The builder registers its post type only inside wp-admin – look in the database too.
		$db = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts} WHERE post_type LIKE '%viwec%' OR post_type LIKE '%email%templ%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_values( array_unique( array_merge( $out, (array) $db ) ) );
	}

	/**
	 * Template type stored by the builder (meta key ending in "settings_type").
	 *
	 * @param int $post_id Template ID.
	 */
	public static function template_type( $post_id ) {
		foreach ( get_post_meta( $post_id ) as $k => $v ) {
			if ( 'settings_type' === substr( $k, -13 ) ) {
				return (string) maybe_unserialize( $v[0] );
			}
		}
		return '';
	}

	/**
	 * "Frame" template: only wraps the regular WooCommerce e-mail (has the
	 * "WooCommerce content" placeholder block and no order blocks of its own).
	 *
	 * @param int $post_id Template ID.
	 */
	public static function is_frame_template( $post_id ) {
		$raw = '';
		foreach ( get_post_meta( $post_id ) as $k => $v ) {
			if ( 'email_structure' === substr( $k, -15 ) ) {
				$raw = html_entity_decode( (string) $v[0] );
				break;
			}
		}
		if ( '' === $raw ) {
			return true;
		}
		$has_content = false !== strpos( $raw, 'html/recover_content' );
		$has_order   = (bool) preg_match( '#html/(order_detail|order_subtotal|order_total|billing_address|shipping_address|payment_method)#', $raw );
		return $has_content && ! $has_order;
	}

	/**
	 * id => label for the settings dropdown.
	 */
	public static function email_templates() {
		$types = self::template_post_types();
		if ( ! $types ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		$labels = array(
			'default'                   => 'Výchozí šablona',
			'customer_completed_order'  => 'Dokončená objednávka',
			'customer_processing_order' => 'Zpracovává se',
			'customer_invoice'          => 'Faktura',
			'new_order'                 => 'Nová objednávka',
		);
		$out = array();
		foreach ( $posts as $p ) {
			$type          = self::template_type( $p->ID );
			$out[ $p->ID ] = wp_strip_all_tags( $p->post_title ? $p->post_title : '#' . $p->ID ) . ( $type ? ' (' . ( isset( $labels[ $type ] ) ? $labels[ $type ] : $type ) . ')' : '' );
		}
		return $out;
	}

	/**
	 * While the till sends a receipt, every template lookup of the email builder
	 * (it queries its templates by e-mail type) returns the template chosen in the
	 * till settings – both for the specific e-mail and the "default template" fallback.
	 *
	 * @return array|null Hooks to remove afterwards.
	 */
	private static function force_template_start() {
		$id   = (int) Elka_Pos_Settings::get( 'email_template' );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$type       = $post->post_type;
		$is_default = 'default' === self::template_type( $id ) && self::is_frame_template( $id );
		$hook       = function ( $query ) use ( $id, $type, $is_default ) {
			$pt = (array) $query->get( 'post_type' );
			if ( ! in_array( $type, $pt, true ) || ! $query->get( 'meta_key' ) && ! $query->get( 'meta_query' ) ) {
				return;
			}
			$asked = (string) $query->get( 'meta_value' );
			if ( $is_default ) {
				// A "default" template is a frame around the regular WooCommerce e-mail
				// (order table included): no specific template may match, the default
				// lookup returns the chosen one.
				$query->set( 'post__in', array( 'default' === $asked ? $id : 0 ) );
			} else {
				// A template with its own order blocks (or made for a specific e-mail type)
				// is rendered as the complete e-mail, so its order blocks get the order.
				$query->set( 'post__in', array( $id ) );
			}
			$query->set( 'meta_key', '' );
			$query->set( 'meta_value', '' );
			$query->set( 'meta_query', array() );
		};
		add_action( 'pre_get_posts', $hook, 999 );

		// Subject = template name ("Pokladní doklad č. 106"); {order_number}, {customer_name}
		// and {site_title} placeholders in the name are filled in.
		$title   = trim( wp_strip_all_tags( $post->post_title ) );
		$subject = null;
		if ( '' !== $title ) {
			$subject = function ( $subj, $order ) use ( $title ) {
				if ( ! $order instanceof WC_Order ) {
					return $subj;
				}
				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				$out  = str_replace(
					array( '{order_number}', '{customer_name}', '{site_title}' ),
					array( $order->get_order_number(), $name, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
					$title
				);
				if ( false === strpos( $title, '{order_number}' ) ) {
					$out .= ' č. ' . $order->get_order_number();
				}
				return $out;
			};
			foreach ( array( 'customer_completed_order', 'customer_invoice', 'customer_invoice_paid' ) as $eid ) {
				add_filter( 'woocommerce_email_subject_' . $eid, $subject, 999, 2 );
			}
		}
		return array( $hook, $subject );
	}

	private static function force_template_end( $force ) {
		if ( ! $force ) {
			return;
		}
		remove_action( 'pre_get_posts', $force[0], 999 );
		if ( $force[1] ) {
			foreach ( array( 'customer_completed_order', 'customer_invoice', 'customer_invoice_paid' ) as $eid ) {
				remove_filter( 'woocommerce_email_subject_' . $eid, $force[1], 999 );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Refunds / returns                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Return goods from a till (or e-shop) order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $p     lines[{itemId, qty}], restock, method, reason.
	 * @return WC_Order_Refund|WP_Error
	 */
	public static function refund( $order, array $p ) {
		$line_items = array();
		$amount     = 0.0;
		$refundable = self::refundable_qty( $order );
		foreach ( (array) ( isset( $p['lines'] ) ? $p['lines'] : array() ) as $l ) {
			$item_id = (int) $l['itemId'];
			$qty     = (int) $l['qty'];
			$item    = $order->get_item( $item_id );
			if ( ! $item || $qty <= 0 ) {
				continue;
			}
			$max = isset( $refundable[ $item_id ] ) ? $refundable[ $item_id ] : 0;
			if ( $qty > $max ) {
				return new WP_Error( 'elka_pos_refund_qty', sprintf( 'U položky „%1$s“ lze vrátit nejvýše %2$d ks.', $item->get_name(), $max ), array( 'status' => 400 ) );
			}
			$ratio  = $qty / max( 1, $item->get_quantity() );
			$total  = round( (float) $item->get_total() * $ratio, wc_get_price_decimals() );
			$taxes  = array();
			$itax   = $item->get_taxes();
			foreach ( isset( $itax['total'] ) ? $itax['total'] : array() as $rate_id => $tax ) {
				$taxes[ $rate_id ] = round( (float) $tax * $ratio, wc_get_price_decimals() );
			}
			$line_items[ $item_id ] = array(
				'qty'          => $qty,
				'refund_total' => $total,
				'refund_tax'   => $taxes,
			);
			$amount += $total + array_sum( $taxes );
		}
		if ( ! $line_items ) {
			return new WP_Error( 'elka_pos_refund_empty', 'Vyberte položky k vrácení.', array( 'status' => 400 ) );
		}

		$methods = self::payment_labels();
		$method  = isset( $p['method'] ) && isset( $methods[ $p['method'] ] ) ? $p['method'] : 'cash';
		$amount  = round( $amount, wc_get_price_decimals() );
		$payout  = $amount;
		if ( 'cash' === $method && Elka_Pos_Settings::is( 'cash_rounding' ) ) {
			$payout = round( $amount );
		}
		$reason = ! empty( $p['reason'] ) ? sanitize_text_field( $p['reason'] ) : 'Vrácení zboží na prodejně';

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => min( $amount, (float) $order->get_remaining_refund_amount() ),
				'reason'         => $reason . ' · ' . $methods[ $method ],
				'line_items'     => $line_items,
				'refund_payment' => false,
				'restock_items'  => ! isset( $p['restock'] ) || ! empty( $p['restock'] ),
			)
		);
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		$refund->update_meta_data( '_elka_pos_refund_method', $method );
		$refund->update_meta_data( '_elka_pos_payout', $payout );
		$refund->update_meta_data( '_elka_pos_cashier', get_current_user_id() );
		$refund->save();

		do_action( 'elka_pos_refund_created', $refund->get_id(), $order->get_id() );
		return $refund;
	}

	/**
	 * item_id => quantity that can still be returned.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function refundable_qty( $order ) {
		$out = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$out[ $item_id ] = max( 0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Receipt data                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Everything the till needs to print a receipt for an order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function receipt( $order ) {
		$dec        = wc_get_price_decimals();
		$items      = array();
		$refundable = self::refundable_qty( $order );
		$bases      = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$qty        = (int) $item->get_quantity();
			$line_gross = (float) $item->get_total() + (float) $item->get_total_tax();
			$shelf      = (float) $item->get_meta( '_elka_pos_line_gross' );
			$unit       = (float) $item->get_meta( '_elka_pos_unit_gross' );
			if ( ! $shelf ) {
				$shelf = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
				$unit  = $qty ? $shelf / $qty : $shelf;
			}
			$meta = array();
			foreach ( $item->get_formatted_meta_data( '_', true ) as $m ) {
				if ( 'Sleva' === $m->key ) {
					continue;
				}
				$meta[] = wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value );
			}
			$product   = $item->get_product();
			$taxes     = $item->get_taxes();
			foreach ( isset( $taxes['total'] ) ? $taxes['total'] : array() as $rate_id => $t ) {
				if ( ! isset( $bases[ $rate_id ] ) ) {
					$bases[ $rate_id ] = 0;
				}
				$bases[ $rate_id ] += (float) $item->get_total();
			}
			$items[] = array(
				'itemId'     => $item_id,
				'productId'  => $item->get_product_id(),
				'variationId'=> $item->get_variation_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? $product->get_sku() : '',
				'meta'       => $meta,
				'qty'        => $qty,
				'unitGross'  => round( $unit, $dec ),
				'shelfGross' => round( $shelf, $dec ),
				'lineGross'  => round( $line_gross, $dec ),
				'discount'   => round( max( 0, $shelf - $line_gross ), $dec ),
				'refundable' => isset( $refundable[ $item_id ] ) ? $refundable[ $item_id ] : 0,
				'custom'     => (bool) $item->get_meta( '_elka_pos_custom' ),
			);
		}

		$tax_rows = array();
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			$rid        = $tax->get_rate_id();
			$base       = isset( $bases[ $rid ] ) ? $bases[ $rid ] : 0;
			$tax_rows[] = array(
				'label' => $tax->get_label(),
				'rate'  => (float) ( method_exists( $tax, 'get_rate_percent' ) && null !== $tax->get_rate_percent() ? $tax->get_rate_percent() : WC_Tax::get_rate_percent_value( $rid ) ),
				'base'  => round( $base, $dec ),
				'tax'   => round( (float) $tax->get_tax_total(), $dec ),
			);
		}

		$coupons = array();
		foreach ( $order->get_items( 'coupon' ) as $ci ) {
			$coupons[] = array(
				'code'   => strtoupper( $ci->get_code() ),
				'amount' => round( (float) $ci->get_discount() + (float) $ci->get_discount_tax(), $dec ),
			);
		}
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $fi ) {
			$fees[] = array(
				'name'   => $fi->get_name(),
				'amount' => round( (float) $fi->get_total() + (float) $fi->get_total_tax(), $dec ),
			);
		}

		$refunds = array();
		foreach ( $order->get_refunds() as $r ) {
			$refunds[] = array(
				'id'     => $r->get_id(),
				'date'   => $r->get_date_created() ? $r->get_date_created()->date( 'c' ) : '',
				'amount' => (float) $r->get_amount(),
				'payout' => (float) $r->get_meta( '_elka_pos_payout' ),
				'reason' => $r->get_reason(),
				'eet'    => Elka_Pos_Eet::status_for( $r ),
			);
		}

		$klub   = null;
		$member = Elka_Pos_Klub::find( $order->get_customer_id(), $order->get_billing_email() );
		if ( $member ) {
			global $wpdb;
			$earned = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(points),0) FROM ' . Elka_Club_Install::ledger_table() . ' WHERE member_id = %d AND order_id = %d', $member->id, $order->get_id() ) ); // phpcs:ignore
			$fresh  = Elka_Club_Members::get( $member->id );
			$klub   = array(
				'card'      => (string) $fresh->card_number,
				'earned'    => $earned,
				'balance'   => (int) $fresh->balance,
				'threshold' => (int) Elka_Club_Settings::get( 'voucher_threshold' ),
			);
		}

		$created = $order->get_date_created();
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		return array(
			'id'         => $order->get_id(),
			'number'     => $order->get_order_number(),
			'localNumber'=> (string) $order->get_meta( '_elka_pos_local_number' ),
			'date'       => $created ? $created->date( 'c' ) : '',
			'status'     => $order->get_status(),
			'pos'        => self::is_pos_order( $order ),
			'customer'   => array(
				'id'    => $order->get_customer_id(),
				'name'  => $name,
				'email' => $order->get_billing_email(),
			),
			'items'      => $items,
			'coupons'    => $coupons,
			'fees'       => $fees,
			'taxes'      => $tax_rows,
			'total'      => round( (float) $order->get_total(), $dec ),
			'totalTax'   => round( (float) $order->get_total_tax(), $dec ),
			'refunded'   => round( (float) $order->get_total_refunded(), $dec ),
			'refunds'    => $refunds,
			'payment'    => array(
				'method'   => (string) $order->get_meta( '_elka_pos_payment' ),
				'label'    => $order->get_payment_method_title(),
				'tendered' => (float) $order->get_meta( '_elka_pos_tendered' ),
				'change'   => (float) $order->get_meta( '_elka_pos_change' ),
			),
			'register'   => (string) $order->get_meta( '_elka_pos_register' ),
			'emailed'    => (string) $order->get_meta( '_elka_pos_emailed' ),
			'klub'       => $klub,
			'eet'        => Elka_Pos_Eet::status_for( $order ),
			'note'       => $order->get_customer_note(),
		);
	}

	/**
	 * Recent orders / search for the "Prodeje" screen.
	 *
	 * @param string $search Number, e-mail or name.
	 * @param int    $limit  Limit.
	 * @param bool   $pos_only Only till sales.
	 */
	public static function list_orders( $search = '', $limit = 30, $pos_only = false ) {
		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
			'status'  => array( 'wc-completed', 'wc-processing', 'wc-refunded', 'wc-on-hold' ),
		);
		if ( $pos_only ) {
			$args['created_via'] = self::CREATED_VIA;
		}
		$search = trim( (string) $search );
		$orders = array();
		if ( '' !== $search ) {
			$num = ltrim( $search, '#' );
			if ( ctype_digit( $num ) ) {
				$o = wc_get_order( (int) $num );
				if ( $o && 'shop_order' === $o->get_type() ) {
					$orders[] = $o;
				}
			}
			if ( is_email( $search ) ) {
				$args['billing_email'] = $search;
			} else {
				$args['s'] = $search;
			}
			if ( empty( $orders ) || is_email( $search ) ) {
				$orders = array_merge( $orders, wc_get_orders( $args ) );
			}
		} else {
			$orders = wc_get_orders( $args );
		}
		$out  = array();
		$seen = array();
		foreach ( $orders as $o ) {
			if ( isset( $seen[ $o->get_id() ] ) ) {
				continue;
			}
			$seen[ $o->get_id() ] = true;
			$created              = $o->get_date_created();
			$out[] = array(
				'id'       => $o->get_id(),
				'number'   => $o->get_order_number(),
				'date'     => $created ? $created->date( 'c' ) : '',
				'total'    => (float) $o->get_total(),
				'refunded' => (float) $o->get_total_refunded(),
				'status'   => $o->get_status(),
				'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : $o->get_billing_email(),
				'payment'  => $o->get_payment_method_title(),
				'pos'      => self::is_pos_order( $o ),
				'items'    => $o->get_item_count(),
				'eet'      => Elka_Pos_Eet::status_for( $o )['status'],
			);
		}
		return $out;
	}

	/**
	 * Day summary (Z-report light) for the till header.
	 *
	 * @param string $date Y-m-d (local), default today.
	 */
	public static function day_summary( $date = '' ) {
		$date   = $date ? $date : current_time( 'Y-m-d' );
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'created_via'  => self::CREATED_VIA,
				'date_created' => $date,
				'status'       => array( 'wc-completed', 'wc-processing', 'wc-refunded' ),
			)
		);
		$sum = array(
			'date'     => $date,
			'count'    => 0,
			'total'    => 0.0,
			'refunded' => 0.0,
			'byMethod' => array(),
		);
		foreach ( $orders as $o ) {
			$m = (string) $o->get_meta( '_elka_pos_payment' );
			$sum['count']++;
			$sum['total']    += (float) $o->get_total();
			$sum['refunded'] += (float) $o->get_total_refunded();
			if ( ! isset( $sum['byMethod'][ $m ] ) ) {
				$sum['byMethod'][ $m ] = 0.0;
			}
			$sum['byMethod'][ $m ] += (float) $o->get_total() - (float) $o->get_total_refunded();
		}
		$sum['total']    = round( $sum['total'], 2 );
		$sum['refunded'] = round( $sum['refunded'], 2 );
		$sum['byMethod'] = array_map(
			function ( $v ) {
				return round( $v, 2 );
			},
			$sum['byMethod']
		);
		return $sum;
	}
}
