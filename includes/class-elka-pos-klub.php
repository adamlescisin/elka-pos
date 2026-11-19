<?php
/**
 * Adapter to the ELKA Club plugin (adamlescisin/elka_klub). Everything here
 * degrades gracefully when that plugin is not active.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Klub {

	public static function active() {
		return class_exists( 'Elka_Club_Members' ) && class_exists( 'Elka_Club_Settings' );
	}

	public static function program() {
		if ( ! self::active() ) {
			return null;
		}
		return array(
			'name'           => Elka_Club_Settings::program_name(),
			'threshold'      => (int) Elka_Club_Settings::get( 'voucher_threshold' ),
			'amountPerPoint' => (float) Elka_Club_Settings::get( 'amount_per_point' ),
			'restrictEmail'  => Elka_Club_Settings::is( 'voucher_restrict_email' ),
		);
	}

	/**
	 * Member for a WP user and/or email.
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email.
	 * @return object|null
	 */
	public static function find( $user_id = 0, $email = '' ) {
		if ( ! self::active() ) {
			return null;
		}
		$member = null;
		if ( $user_id ) {
			$member = Elka_Club_Members::get_by_user( $user_id );
		}
		if ( ! $member && $email ) {
			$member = Elka_Club_Members::get_by_email( $email );
		}
		return $member;
	}

	/**
	 * Find by card number (exact) – used by the search box / future scanner.
	 *
	 * @param string $number Card number.
	 */
	public static function find_by_card( $number ) {
		if ( ! self::active() ) {
			return null;
		}
		$member = Elka_Club_Members::get_by_card( $number );
		if ( ! $member && class_exists( 'Elka_Club_Cards' ) ) {
			$member = Elka_Club_Members::get_by_card( Elka_Club_Cards::clean( $number ) );
		}
		return $member;
	}

	/**
	 * Compact member summary for the till.
	 *
	 * @param object|null $member Member row.
	 * @param bool        $with_vouchers Include usable vouchers (one query per member).
	 */
	public static function summary( $member, $with_vouchers = true ) {
		if ( ! $member ) {
			return null;
		}
		$data = array(
			'memberId' => (int) $member->id,
			'status'   => $member->status,
			'card'     => (string) $member->card_number,
			'balance'  => (int) $member->balance,
			'lifetime' => (int) $member->lifetime_points,
			'since'    => substr( (string) $member->created_at, 0, 10 ),
			'vouchers' => array(),
		);
		if ( $with_vouchers && class_exists( 'Elka_Club_Vouchers' ) ) {
			foreach ( Elka_Club_Vouchers::for_member( $member->id, true ) as $coupon ) {
				$expires            = $coupon->get_date_expires();
				$data['vouchers'][] = array(
					'code'    => $coupon->get_code(),
					'label'   => Elka_Club_Vouchers::value_text( $coupon ),
					'expires' => $expires ? $expires->date( 'Y-m-d' ) : null,
				);
			}
		}
		return $data;
	}

	/**
	 * Sign a customer up (staff in the store, customer consents in person).
	 *
	 * @param array $data email, first_name, last_name, user_id, birthday.
	 * @return object|WP_Error Member row.
	 */
	public static function join( array $data ) {
		if ( ! self::active() ) {
			return new WP_Error( 'elka_pos_no_klub', 'Plugin ELKA Klub není aktivní.' );
		}
		$existing = Elka_Club_Members::get_by_email( $data['email'] );
		if ( $existing ) {
			if ( Elka_Club_Members::STATUS_ACTIVE !== $existing->status ) {
				Elka_Club_Members::activate( $existing->id );
			}
			return Elka_Club_Members::get( $existing->id );
		}
		$id = Elka_Club_Members::create(
			array(
				'email'          => $data['email'],
				'first_name'     => isset( $data['first_name'] ) ? $data['first_name'] : '',
				'last_name'      => isset( $data['last_name'] ) ? $data['last_name'] : '',
				'user_id'        => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'birthday'       => isset( $data['birthday'] ) ? $data['birthday'] : '',
				'status'         => 'active',
				'consent_source' => 'pos',
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return Elka_Club_Members::get( $id );
	}

	/**
	 * Points an order total (incl. VAT, without shipping) is worth – for the preview in the cart.
	 * Final points are always computed by the club engine when the order completes.
	 *
	 * @param float $amount Amount.
	 */
	public static function points_for_amount( $amount ) {
		if ( ! self::active() || ! method_exists( 'Elka_Club_Settings', 'points_for_amount' ) ) {
			return 0;
		}
		return (int) Elka_Club_Settings::points_for_amount( $amount );
	}

	/**
	 * Make sure the club engine has processed the order (it listens to status changes,
	 * but a POS order is created directly as completed).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function sync_order( $order_id ) {
		if ( self::active() && class_exists( 'Elka_Club_Engine' ) ) {
			Elka_Club_Engine::sync_order( $order_id );
		}
	}
}
