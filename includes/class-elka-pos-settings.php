<?php
/**
 * Settings store (one option array).
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Settings {

	const OPTION = 'elka_pos_settings';

	/** Capability needed to use the till. Granted to shop managers and admins on activation. */
	const CAP = 'elka_pos_use';

	public static function defaults() {
		return array(
			// Store / receipt header.
			'store_name'        => 'ELKA Fashion',
			'store_tagline'     => 'Boutique shop',
			'company_name'      => '',
			'address'           => 'Mělník',
			'ico'               => '',
			'dic'               => '',
			'vat_payer'         => 'yes',
			'phone'             => '',
			'web'               => 'elkafashion.cz',
			'receipt_footer'    => 'Děkujeme za nákup! Zboží lze vrátit do 14 dnů s účtenkou.',
			'receipt_signoff'   => 's láskou, Elka',
			'receipt_copies'    => 1,
			'auto_print'        => 'yes',

			// Payments.
			'pay_cash'          => 'yes',
			'pay_qr'            => 'yes',
			'pay_card'          => 'yes',
			'card_label'        => 'Kartou (SumUp)',
			'cash_rounding'     => 'yes',      // Round cash payments to whole CZK.
			'qr_iban'           => '',         // IBAN for SPAYD QR payments.
			'qr_message'        => 'ELKA Fashion',

			// Orders.
			'order_status'      => 'completed',
			'email_default'     => 'no',       // Pre-tick "send receipt by email".
			'email_type'        => 'completed', // completed | invoice.
			'email_template'    => 0,          // Post ID of an email-builder template (XStore / VillaTheme), 0 = WooCommerce default.
			'register_id'       => 'POKLADNA1',
			'app_slug'          => 'kasa',

			// Customers.
			'default_country'   => 'CZ',

			// Offline.
			'offline_enabled'   => 'yes',
			'sync_minutes'      => 5,

			// EET 2.0.
			'eet_enabled'       => 'no',
			'eet_mode'          => 'playground', // playground | production.
			'eet_eic'           => '',
			'eet_unit_id'       => '',
			'eet_cert_path'     => '',
			'eet_cert_pass'     => '',
			'eet_verify_only'   => 'no',
			'eet_methods'       => array( 'cash', 'qr', 'card' ),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function is( $key ) {
		return 'yes' === self::get( $key );
	}

	public static function save( array $values ) {
		$current = self::all();
		update_option( self::OPTION, array_merge( $current, $values ), false );
	}

	/**
	 * Enabled payment methods: id => label.
	 */
	public static function payment_methods() {
		$methods = array();
		if ( self::is( 'pay_cash' ) ) {
			$methods['cash'] = 'Hotově';
		}
		if ( self::is( 'pay_qr' ) ) {
			$methods['qr'] = 'QR platba';
		}
		if ( self::is( 'pay_card' ) ) {
			$methods['card'] = self::get( 'card_label' ) ? self::get( 'card_label' ) : 'Kartou';
		}
		return $methods;
	}

	/**
	 * Public config for the till app (no secrets).
	 */
	public static function app_config() {
		$s = self::all();
		return array(
			'store'    => array(
				'name'        => $s['store_name'],
				'tagline'     => $s['store_tagline'],
				'company'     => $s['company_name'],
				'address'     => $s['address'],
				'ico'         => $s['ico'],
				'dic'         => $s['dic'],
				'vatPayer'    => 'yes' === $s['vat_payer'],
				'phone'       => $s['phone'],
				'web'         => $s['web'],
				'footer'      => $s['receipt_footer'],
				'signoff'     => $s['receipt_signoff'],
				'copies'      => max( 1, (int) $s['receipt_copies'] ),
				'autoPrint'   => 'yes' === $s['auto_print'],
				'registerId'  => $s['register_id'],
			),
			'payments' => self::payment_methods(),
			'cashRounding' => 'yes' === $s['cash_rounding'],
			'qr'       => array(
				'iban'    => preg_replace( '/\s+/', '', strtoupper( (string) $s['qr_iban'] ) ),
				'message' => $s['qr_message'],
			),
			'emailDefault' => 'yes' === $s['email_default'],
			'offline'  => 'yes' === $s['offline_enabled'],
			'syncMinutes' => max( 1, (int) $s['sync_minutes'] ),
			'eet'      => array(
				'enabled' => 'yes' === $s['eet_enabled'],
				'mode'    => $s['eet_mode'],
				'unitId'  => $s['eet_unit_id'],
			),
			'currency' => get_woocommerce_currency(),
			'taxes'    => wc_tax_enabled(),
			'pricesIncludeTax' => wc_prices_include_tax(),
			'decimals' => wc_get_price_decimals(),
		);
	}
}
