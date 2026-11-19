<?php
/**
 * REST API for the till: /wp-json/elka-pos/v1/...
 * Cookie + nonce auth, capability elka_pos_use.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Rest {

	const NS = 'elka-pos/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return current_user_can( Elka_Pos_Settings::CAP ) || current_user_can( 'manage_woocommerce' );
	}

	private static function route( $path, $method, $cb, $args = array() ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $cb ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'args'                => $args,
			)
		);
	}

	public static function routes() {
		self::route( '/bootstrap', 'GET', 'bootstrap' );
		self::route( '/products', 'GET', 'products' );
		self::route( '/customers', 'GET', 'customers' );
		self::route( '/customers/all', 'GET', 'customers_all' );
		self::route( '/customers', 'POST', 'customer_create' );
		self::route( '/customers/(?P<id>\d+)', 'GET', 'customer_get' );
		self::route( '/klub/member', 'GET', 'klub_member' );
		self::route( '/klub/join', 'POST', 'klub_join' );
		self::route( '/coupons/validate', 'POST', 'coupon_validate' );
		self::route( '/orders', 'POST', 'order_create' );
		self::route( '/orders', 'GET', 'orders_list' );
		self::route( '/orders/(?P<id>\d+)', 'GET', 'order_get' );
		self::route( '/orders/(?P<id>\d+)/email', 'POST', 'order_email' );
		self::route( '/orders/(?P<id>\d+)/refund', 'POST', 'order_refund' );
		self::route( '/orders/(?P<id>\d+)/eet', 'POST', 'order_eet_retry' );
		self::route( '/summary', 'GET', 'summary' );
	}

	private static function json( WP_REST_Request $r ) {
		$p = $r->get_json_params();
		return is_array( $p ) ? $p : array();
	}

	public static function bootstrap() {
		$user = wp_get_current_user();
		return array(
			'config'     => Elka_Pos_Settings::app_config(),
			'categories' => Elka_Pos_Catalog::categories(),
			'klub'       => Elka_Pos_Klub::program(),
			'taxClasses' => self::tax_classes(),
			'cashier'    => array(
				'id'   => $user->ID,
				'name' => $user->display_name,
			),
			'eetQueue'   => Elka_Pos_Eet::enabled() ? Elka_Pos_Eet::queue_stats() : null,
			'serverTime' => gmdate( 'Y-m-d H:i:s' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
		);
	}

	private static function tax_classes() {
		$out = array(
			array(
				'slug'  => '',
				'name'  => 'Základní sazba',
				'rate'  => self::rate_for( '' ),
			),
		);
		foreach ( WC_Tax::get_tax_classes() as $name ) {
			$slug  = sanitize_title( $name );
			$out[] = array(
				'slug' => $slug,
				'name' => $name,
				'rate' => self::rate_for( $slug ),
			);
		}
		return $out;
	}

	private static function rate_for( $class ) {
		if ( ! wc_tax_enabled() ) {
			return 0;
		}
		$rates = WC_Tax::get_base_tax_rates( $class );
		$sum   = 0;
		foreach ( $rates as $r ) {
			$sum += (float) $r['rate'];
		}
		return $sum;
	}

	public static function products( WP_REST_Request $r ) {
		$page     = max( 1, (int) $r->get_param( 'page' ) );
		$per_page = min( 250, max( 10, (int) ( $r->get_param( 'per_page' ) ? $r->get_param( 'per_page' ) : 100 ) ) );
		$since    = sanitize_text_field( (string) $r->get_param( 'modified_after' ) );
		$data     = Elka_Pos_Catalog::products( $page, $per_page, $since );
		$data['serverTime'] = gmdate( 'Y-m-d H:i:s' );
		return $data;
	}

	public static function customers( WP_REST_Request $r ) {
		return array( 'customers' => Elka_Pos_Catalog::search_customers( (string) $r->get_param( 'search' ), 25 ) );
	}

	public static function customers_all( WP_REST_Request $r ) {
		return Elka_Pos_Catalog::customers_page( max( 1, (int) $r->get_param( 'page' ) ), 200 );
	}

	public static function customer_get( WP_REST_Request $r ) {
		$user = get_userdata( (int) $r['id'] );
		if ( ! $user ) {
			return new WP_Error( 'elka_pos_404', 'Zákaznice nenalezena.', array( 'status' => 404 ) );
		}
		return Elka_Pos_Catalog::customer( $user->ID );
	}

	public static function klub_member( WP_REST_Request $r ) {
		$email  = sanitize_email( (string) $r->get_param( 'email' ) );
		$member = Elka_Pos_Klub::find( (int) $r->get_param( 'user_id' ), $email );
		return array( 'klub' => Elka_Pos_Klub::summary( $member ) );
	}

	/**
	 * New customer from the till. With an e-mail a WooCommerce customer account
	 * is created (no password e-mail); optional ELKA Klub membership.
	 */
	public static function customer_create( WP_REST_Request $r ) {
		$p     = self::json( $r );
		$email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
		$first = isset( $p['firstName'] ) ? sanitize_text_field( $p['firstName'] ) : '';
		$last  = isset( $p['lastName'] ) ? sanitize_text_field( $p['lastName'] ) : '';
		$phone = isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '';

		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'elka_pos_email', 'Zadejte platný e-mail.', array( 'status' => 400 ) );
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			$user_id = $existing->ID;
		} else {
			add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			$user_id = wc_create_new_customer(
				$email,
				'',
				wp_generate_password( 20 ),
				array(
					'first_name' => $first,
					'last_name'  => $last,
				)
			);
			remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 99 );
			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'elka_pos_customer', wp_strip_all_tags( $user_id->get_error_message() ), array( 'status' => 400 ) );
			}
		}
		$c = new WC_Customer( $user_id );
		if ( $first ) {
			$c->set_billing_first_name( $first );
			$c->set_first_name( $c->get_first_name() ? $c->get_first_name() : $first );
		}
		if ( $last ) {
			$c->set_billing_last_name( $last );
			$c->set_last_name( $c->get_last_name() ? $c->get_last_name() : $last );
		}
		if ( $phone ) {
			$c->set_billing_phone( $phone );
		}
		if ( ! $c->get_billing_email() ) {
			$c->set_billing_email( $email );
		}
		if ( ! $c->get_billing_country() ) {
			$c->set_billing_country( Elka_Pos_Settings::get( 'default_country' ) );
		}
		$c->update_meta_data( '_elka_pos_created', current_time( 'mysql' ) );
		$c->save();

		$warning = '';
		if ( ! empty( $p['joinKlub'] ) ) {
			$member = Elka_Pos_Klub::join(
				array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
					'user_id'    => $user_id,
					'birthday'   => isset( $p['birthday'] ) ? sanitize_text_field( $p['birthday'] ) : '',
				)
			);
			if ( is_wp_error( $member ) ) {
				$warning = $member->get_error_message();
			}
		}
		$row            = Elka_Pos_Catalog::customer( $user_id );
		$row['existed'] = (bool) $existing;
		$row['warning'] = $warning;
		return $row;
	}

	public static function klub_join( WP_REST_Request $r ) {
		$p     = self::json( $r );
		$email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'elka_pos_email', 'Pro ELKA Klub je potřeba e-mail.', array( 'status' => 400 ) );
		}
		$user_id = ! empty( $p['userId'] ) ? (int) $p['userId'] : 0;
		$user    = $user_id ? get_userdata( $user_id ) : get_user_by( 'email', $email );
		$member  = Elka_Pos_Klub::join(
			array(
				'email'      => $email,
				'first_name' => isset( $p['firstName'] ) ? sanitize_text_field( $p['firstName'] ) : ( $user ? $user->first_name : '' ),
				'last_name'  => isset( $p['lastName'] ) ? sanitize_text_field( $p['lastName'] ) : ( $user ? $user->last_name : '' ),
				'user_id'    => $user ? $user->ID : 0,
				'birthday'   => isset( $p['birthday'] ) ? sanitize_text_field( $p['birthday'] ) : '',
			)
		);
		if ( is_wp_error( $member ) ) {
			return new WP_Error( $member->get_error_code(), $member->get_error_message(), array( 'status' => 400 ) );
		}
		return array( 'klub' => Elka_Pos_Klub::summary( $member ) );
	}

	/**
	 * Validate a coupon against the current cart (WooCommerce rules) and return
	 * its definition so the till can preview the discount. The final amount is
	 * always calculated by WooCommerce when the sale is saved.
	 */
	public static function coupon_validate( WP_REST_Request $r ) {
		$p    = self::json( $r );
		$code = wc_format_coupon_code( isset( $p['code'] ) ? $p['code'] : '' );
		if ( '' === $code ) {
			return new WP_Error( 'elka_pos_coupon', 'Zadejte kód kupónu.', array( 'status' => 400 ) );
		}
		$coupon = new WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return new WP_Error( 'elka_pos_coupon', 'Kupón „' . strtoupper( $code ) . '“ neexistuje.', array( 'status' => 404 ) );
		}

		// Temporary in-memory order with the cart lines – never saved.
		$order = new WC_Order();
		$order->set_prices_include_tax( true );
		$lines = Elka_Pos_Orders::price_lines( isset( $p['lines'] ) ? (array) $p['lines'] : array(), isset( $p['cartDiscount'] ) ? $p['cartDiscount'] : null );
		foreach ( $lines as $l ) {
			$item = new WC_Order_Item_Product();
			if ( empty( $l['type'] ) || 'custom' !== $l['type'] ) {
				$product = wc_get_product( ! empty( $l['variationId'] ) ? (int) $l['variationId'] : (int) $l['productId'] );
				if ( $product ) {
					$item->set_product( $product );
				}
			}
			$tax_class = ( isset( $l['type'] ) && 'custom' === $l['type'] ) ? ( isset( $l['taxClass'] ) ? sanitize_title( $l['taxClass'] ) : '' ) : $item->get_tax_class();
			$item->set_tax_class( $tax_class );
			$split = Elka_Pos_Orders::split_gross( $l['final'], $tax_class );
			$item->set_quantity( max( 1, (int) $l['qty'] ) );
			$item->set_subtotal( $split['net'] );
			$item->set_total( $split['net'] );
			if ( $split['taxes'] ) {
				$item->set_taxes( array( 'subtotal' => $split['taxes'], 'total' => $split['taxes'] ) );
			}
			$order->add_item( $item );
		}
		$email = isset( $p['email'] ) ? sanitize_email( $p['email'] ) : '';
		if ( $email ) {
			$order->set_billing_email( $email );
		}
		if ( ! empty( $p['customerId'] ) ) {
			$order->set_customer_id( (int) $p['customerId'] );
		}
		$order->calculate_totals( false );

		$discounts = new WC_Discounts( $order );
		$valid     = $discounts->is_coupon_valid( $coupon );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'elka_pos_coupon', wp_strip_all_tags( $valid->get_error_message() ), array( 'status' => 400 ) );
		}
		// E-mail restriction (WC checks it only at checkout).
		$restr = $coupon->get_email_restrictions();
		if ( $restr ) {
			$check = strtolower( $order->get_billing_email() );
			$ok    = false;
			foreach ( $restr as $allowed ) {
				$allowed = strtolower( trim( $allowed ) );
				if ( $check && ( $allowed === $check || ( false !== strpos( $allowed, '*' ) && fnmatch( $allowed, $check ) ) ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				return new WP_Error( 'elka_pos_coupon', 'Kupón je vázaný na e-mail zákaznice – nejdřív k prodeji přiřaďte správnou zákaznici.', array( 'status' => 400 ) );
			}
		}
		$discounts->apply_coupon( $coupon, false );
		$amounts = $discounts->get_discounts_by_coupon( false );
		$net     = isset( $amounts[ $coupon->get_code() ] ) ? (float) $amounts[ $coupon->get_code() ] : 0.0;

		return array(
			'code'          => strtoupper( $coupon->get_code() ),
			'type'          => $coupon->get_discount_type(),
			'amount'        => (float) $coupon->get_amount(),
			'individualUse' => $coupon->get_individual_use(),
			'description'   => $coupon->get_description(),
			'preview'       => round( $net, wc_get_price_decimals() ), // WC computes in prices-incl-tax mode for this order.
		);
	}

	public static function order_create( WP_REST_Request $r ) {
		$order = Elka_Pos_Orders::create( self::json( $r ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		return Elka_Pos_Orders::receipt( $order );
	}

	public static function orders_list( WP_REST_Request $r ) {
		return array(
			'orders' => Elka_Pos_Orders::list_orders( (string) $r->get_param( 'search' ), 40, 'pos' === $r->get_param( 'scope' ) ),
		);
	}

	private static function order_or_404( $id ) {
		$order = wc_get_order( (int) $id );
		if ( ! $order || 'shop_order' !== $order->get_type() ) {
			return new WP_Error( 'elka_pos_404', 'Objednávka nenalezena.', array( 'status' => 404 ) );
		}
		return $order;
	}

	public static function order_get( WP_REST_Request $r ) {
		$order = self::order_or_404( $r['id'] );
		return is_wp_error( $order ) ? $order : Elka_Pos_Orders::receipt( $order );
	}

	public static function order_email( WP_REST_Request $r ) {
		$order = self::order_or_404( $r['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$p   = self::json( $r );
		$res = Elka_Pos_Orders::send_email( $order, isset( $p['email'] ) ? $p['email'] : '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array( 'sent' => true, 'email' => $order->get_billing_email() );
	}

	public static function order_refund( WP_REST_Request $r ) {
		$order = self::order_or_404( $r['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$refund = Elka_Pos_Orders::refund( $order, self::json( $r ) );
		if ( is_wp_error( $refund ) ) {
			return new WP_Error( $refund->get_error_code(), $refund->get_error_message(), array( 'status' => 400 ) );
		}
		$data           = Elka_Pos_Orders::receipt( wc_get_order( $order->get_id() ) );
		$data['refund'] = array(
			'id'     => $refund->get_id(),
			'amount' => (float) $refund->get_amount(),
			'payout' => (float) $refund->get_meta( '_elka_pos_payout' ),
			'method' => (string) $refund->get_meta( '_elka_pos_refund_method' ),
			'reason' => $refund->get_reason(),
			'items'  => array_values(
				array_map(
					function ( $ri ) {
						return array(
							'name'  => $ri->get_name(),
							'qty'   => abs( (int) $ri->get_quantity() ),
							'gross' => abs( (float) $ri->get_total() + (float) $ri->get_total_tax() ),
						);
					},
					$refund->get_items()
				)
			),
			'eet'    => Elka_Pos_Eet::status_for( wc_get_order( $refund->get_id() ) ),
		);
		return $data;
	}

	public static function order_eet_retry( WP_REST_Request $r ) {
		$order = self::order_or_404( $r['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( 'error' === $order->get_meta( '_elka_eet_status' ) ) {
			$order->update_meta_data( '_elka_eet_status', 'pending' );
			$order->save();
		}
		return Elka_Pos_Eet::send( $order );
	}

	public static function summary( WP_REST_Request $r ) {
		return Elka_Pos_Orders::day_summary( sanitize_text_field( (string) $r->get_param( 'date' ) ) );
	}
}
