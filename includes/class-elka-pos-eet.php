<?php
/**
 * EET 2.0 – evidence tržeb (from 1. 1. 2027).
 *
 * Interface per the Financial Administration developer documentation (June 2026):
 * SOAP 1.1 document/literal, namespace fs.gov.cz/eet/schema/v4 (interface 4.1),
 * one operation OdeslaniTrzby, WS-Security with the till certificate (PKCS#12 from
 * DIS+ / MOJE daně), XML-DSig RSA-SHA256. Response: Potvrzeni (confirmation),
 * Chyba (error) or Varovani (warnings). A failed report is resent with the same
 * body but a new header (new UUID, prvni_zaslani=false); legal limit 48 h.
 * Returns/corrections are sent as a new report with a negative amount.
 *
 * The message follows the official schema EETXMLSchema.xsd v4.1 (Trzba = Hlavicka +
 * Data, attributes only). Run "Test spojení" against the Playground before going live.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Eet {

	const NS         = 'http://fs.gov.cz/eet/schema/v4';
	const URL_PG     = 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';
	const URL_PROD   = 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4';
	const CRON       = 'elka_pos_eet_retry';
	const TIMEOUT    = 4; // Seconds. The documented response limit is at least 2 s.

	const NS_SOAP    = 'http://schemas.xmlsoap.org/soap/envelope/';
	const NS_WSSE    = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
	const NS_WSU     = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
	const NS_DS      = 'http://www.w3.org/2000/09/xmldsig#';
	const NS_EXC     = 'http://www.w3.org/2001/10/xml-exc-c14n#';

	public static function init() {
		add_action( 'elka_pos_order_created', array( __CLASS__, 'on_order' ), 20 );
		add_action( 'elka_pos_refund_created', array( __CLASS__, 'on_refund' ), 20 );
		add_action( self::CRON, array( __CLASS__, 'retry_pending' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedules( $s ) {
		$s['elka_pos_5min'] = array(
			'interval' => 300,
			'display'  => 'Každých 5 minut (ELKA Pokladna)',
		);
		return $s;
	}

	public static function schedule() {
		if ( self::enabled() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'elka_pos_5min', self::CRON );
		}
		if ( ! self::enabled() && wp_next_scheduled( self::CRON ) ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function enabled() {
		return Elka_Pos_Settings::is( 'eet_enabled' );
	}

	public static function endpoint() {
		return 'production' === Elka_Pos_Settings::get( 'eet_mode' ) ? self::URL_PROD : self::URL_PG;
	}

	/* ------------------------------------------------------------------ */
	/* Queue                                                              */
	/* ------------------------------------------------------------------ */

	public static function on_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::enabled() ) {
			return;
		}
		$methods = (array) Elka_Pos_Settings::get( 'eet_methods' );
		if ( ! in_array( $order->get_meta( '_elka_pos_payment' ), $methods, true ) ) {
			return;
		}
		$created = $order->get_date_created();
		self::queue(
			$order,
			array(
				'porad_cis'  => (string) $order->get_order_number(),
				'dat_trzby'  => $created ? $created->date( 'c' ) : gmdate( 'c' ),
				'celk_trzba' => (float) $order->get_total(),
			)
		);
		self::send( $order );
	}

	public static function on_refund( $refund_id ) {
		$refund = wc_get_order( $refund_id );
		if ( ! $refund || ! self::enabled() ) {
			return;
		}
		$parent = wc_get_order( $refund->get_parent_id() );
		// Only storno what was reported.
		if ( ! $parent || ! $parent->get_meta( '_elka_eet_status' ) ) {
			return;
		}
		$payout = (float) $refund->get_meta( '_elka_pos_payout' );
		self::queue(
			$refund,
			array(
				'porad_cis'  => 'R' . $refund->get_id(),
				'dat_trzby'  => gmdate( 'c' ),
				'celk_trzba' => -1 * ( $payout ? $payout : (float) $refund->get_amount() ),
			)
		);
		self::send( $refund );
	}

	/**
	 * Store the immutable report body on the order/refund.
	 *
	 * @param WC_Abstract_Order $obj  Order or refund.
	 * @param array             $data porad_cis, dat_trzby, celk_trzba.
	 */
	private static function queue( $obj, array $data ) {
		$s    = Elka_Pos_Settings::all();
		$body = array(
			'eic_popl'    => strtoupper( trim( $s['eet_eic'] ) ),
			'id_jednotky' => (string) (int) $s['eet_unit_id'],
			'id_pokl'     => self::clean20( $s['register_id'], 20 ),
			'porad_cis'   => self::clean20( $data['porad_cis'], 25 ),
			'dat_trzby'   => self::format_date( $data['dat_trzby'] ),
			'celk_trzba'  => number_format( $data['celk_trzba'], 2, '.', '' ),
		);
		$obj->update_meta_data( '_elka_eet_body', $body );
		$obj->update_meta_data( '_elka_eet_status', 'pending' );
		$obj->update_meta_data( '_elka_eet_attempts', 0 );
		$obj->update_meta_data( '_elka_eet_mode', $s['eet_mode'] );
		$obj->save();
	}

	/** Allowed characters per the XSD (string20 / string25). */
	private static function clean20( $value, $len ) {
		$v = substr( preg_replace( '/[^0-9A-Za-z\.,:;\/#\-_ ]/', '', remove_accents( (string) $value ) ), 0, $len );
		return '' !== $v ? $v : '1';
	}

	private static function format_date( $iso ) {
		$ts = strtotime( $iso );
		$dt = new DateTime( '@' . ( $ts ? $ts : time() ) );
		$dt->setTimezone( wp_timezone() );
		return $dt->format( 'Y-m-d\TH:i:sP' );
	}

	/**
	 * Status summary for receipts/UI.
	 *
	 * @param WC_Abstract_Order $obj Order or refund.
	 */
	public static function status_for( $obj ) {
		$status = (string) $obj->get_meta( '_elka_eet_status' );
		$body   = $obj->get_meta( '_elka_eet_body' );
		return array(
			'status'   => $status ? $status : 'off',
			'code'     => (string) $obj->get_meta( '_elka_eet_code' ),
			'error'    => (string) $obj->get_meta( '_elka_eet_error' ),
			'unit'     => is_array( $body ) ? $body['id_jednotky'] : '',
			'register' => is_array( $body ) ? $body['id_pokl'] : '',
			'porad'    => is_array( $body ) ? $body['porad_cis'] : '',
			'mode'     => (string) $obj->get_meta( '_elka_eet_mode' ),
			'sentAt'   => (string) $obj->get_meta( '_elka_eet_sent_at' ),
		);
	}

	/**
	 * Send (or resend) one queued report.
	 *
	 * @param WC_Abstract_Order $obj Order or refund.
	 * @return array status_for() after the attempt.
	 */
	public static function send( $obj ) {
		$body = $obj->get_meta( '_elka_eet_body' );
		if ( ! is_array( $body ) || 'sent' === $obj->get_meta( '_elka_eet_status' ) ) {
			return self::status_for( $obj );
		}
		$attempts = (int) $obj->get_meta( '_elka_eet_attempts' );
		$result   = self::transmit( $body, 0 === $attempts, Elka_Pos_Settings::is( 'eet_verify_only' ) );

		$obj->update_meta_data( '_elka_eet_attempts', $attempts + 1 );
		$obj->update_meta_data( '_elka_eet_last_try', current_time( 'mysql' ) );
		if ( 'ok' === $result['result'] ) {
			$obj->update_meta_data( '_elka_eet_status', Elka_Pos_Settings::is( 'eet_verify_only' ) ? 'verify' : 'sent' );
			$obj->update_meta_data( '_elka_eet_code', $result['code'] );
			$obj->update_meta_data( '_elka_eet_sent_at', current_time( 'mysql' ) );
			$obj->update_meta_data( '_elka_eet_warnings', $result['warnings'] );
			$obj->delete_meta_data( '_elka_eet_error' );
		} elseif ( 'rejected' === $result['result'] ) {
			// A rejected message will not pass on retry – needs attention.
			$obj->update_meta_data( '_elka_eet_status', 'error' );
			$obj->update_meta_data( '_elka_eet_error', $result['error'] );
		} else {
			$obj->update_meta_data( '_elka_eet_status', 'pending' );
			$obj->update_meta_data( '_elka_eet_error', $result['error'] );
		}
		$obj->save();
		return self::status_for( $obj );
	}

	/**
	 * Cron: resend everything still pending (network outage, offline sales).
	 */
	public static function retry_pending( $include_errors = false ) {
		if ( ! self::enabled() ) {
			return;
		}
		foreach ( array( 'shop_order', 'shop_order_refund' ) as $type ) {
			$items = wc_get_orders(
				array(
					'type'       => $type,
					'limit'      => 50,
					'status'     => 'any',
					'meta_key'   => '_elka_eet_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $include_errors ? array( 'pending', 'error' ) : 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_compare' => $include_errors ? 'IN' : '=',
					'orderby'    => 'date',
					'order'      => 'ASC',
				)
			);
			foreach ( $items as $obj ) {
				if ( 'error' === $obj->get_meta( '_elka_eet_status' ) ) {
					$obj->update_meta_data( '_elka_eet_status', 'pending' );
					$obj->save();
				}
				self::send( $obj );
			}
		}
	}

	/**
	 * Counts for the admin / till status chip.
	 */
	public static function queue_stats() {
		$out = array( 'pending' => 0, 'error' => 0, 'oldestPending' => '' );
		foreach ( array( 'pending', 'error' ) as $st ) {
			foreach ( array( 'shop_order', 'shop_order_refund' ) as $type ) {
				$ids = wc_get_orders(
					array(
						'type'       => $type,
						'limit'      => 200,
						'status'     => 'any',
						'return'     => 'ids',
						'meta_key'   => '_elka_eet_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => $st, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				$out[ $st ] += count( $ids );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* SOAP + signature                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Load the till certificate.
	 *
	 * @return array|WP_Error { pkey, cert }
	 */
	public static function certificate() {
		$path = Elka_Pos_Settings::get( 'eet_cert_path' );
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'elka_eet_cert', 'Chybí pokladní certifikát (.p12).' );
		}
		$certs = array();
		if ( ! openssl_pkcs12_read( file_get_contents( $path ), $certs, (string) Elka_Pos_Settings::get( 'eet_cert_pass' ) ) ) { // phpcs:ignore
			return new WP_Error( 'elka_eet_cert', 'Certifikát nelze otevřít – zkontrolujte heslo.' );
		}
		return $certs;
	}

	/**
	 * Build the signed SOAP envelope.
	 *
	 * @param array $body   Data attributes.
	 * @param bool  $first  First attempt.
	 * @param bool  $verify Verification mode (overeni).
	 * @param array $cert   Certificate from certificate().
	 * @return string XML.
	 */
	public static function build_message( array $body, $first, $verify, array $cert ) {
		$doc = new DOMDocument( '1.0', 'UTF-8' );
		$env = $doc->createElementNS( self::NS_SOAP, 'soap:Envelope' );
		$doc->appendChild( $env );
		$header = $doc->createElementNS( self::NS_SOAP, 'soap:Header' );
		$env->appendChild( $header );
		$soap_body = $doc->createElementNS( self::NS_SOAP, 'soap:Body' );
		$soap_body->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:wsu', self::NS_WSU );
		$soap_body->setAttributeNS( self::NS_WSU, 'wsu:Id', 'id-body' );
		$env->appendChild( $soap_body );

		$trzba = $doc->createElementNS( self::NS, 'Trzba' );
		$soap_body->appendChild( $trzba );

		$hl = $doc->createElementNS( self::NS, 'Hlavicka' );
		$hl->setAttribute( 'uuid_zpravy', wp_generate_uuid4() );
		$hl->setAttribute( 'dat_odesl', self::format_date( gmdate( 'c' ) ) );
		$hl->setAttribute( 'prvni_zaslani', $first ? 'true' : 'false' );
		if ( $verify ) {
			$hl->setAttribute( 'overeni', 'true' );
		}
		$trzba->appendChild( $hl );

		$data = $doc->createElementNS( self::NS, 'Data' );
		foreach ( array( 'eic_popl', 'id_jednotky', 'id_pokl', 'porad_cis', 'dat_trzby', 'celk_trzba' ) as $k ) {
			$data->setAttribute( $k, $body[ $k ] );
		}
		$trzba->appendChild( $data );

		// WS-Security.
		$sec = $doc->createElementNS( self::NS_WSSE, 'wsse:Security' );
		$sec->setAttributeNS( self::NS_SOAP, 'soap:mustUnderstand', '1' );
		$header->appendChild( $sec );

		$der = '';
		openssl_x509_export( $cert['cert'], $pem );
		$der = base64_encode( base64_decode( preg_replace( '/-----[^-]+-----|\s+/', '', $pem ) ) ); // phpcs:ignore
		$bst = $doc->createElementNS( self::NS_WSSE, 'wsse:BinarySecurityToken', $der );
		$bst->setAttribute( 'EncodingType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary' );
		$bst->setAttribute( 'ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3' );
		$bst->setAttributeNS( self::NS_WSU, 'wsu:Id', 'X509-token' );
		$sec->appendChild( $bst );

		$digest = base64_encode( hash( 'sha256', $soap_body->C14N( true, false ), true ) ); // phpcs:ignore

		$signature = $doc->createElementNS( self::NS_DS, 'ds:Signature' );
		$sec->appendChild( $signature );
		$si = $doc->createElementNS( self::NS_DS, 'ds:SignedInfo' );
		$signature->appendChild( $si );
		$cm = $doc->createElementNS( self::NS_DS, 'ds:CanonicalizationMethod' );
		$cm->setAttribute( 'Algorithm', self::NS_EXC );
		$si->appendChild( $cm );
		$sm = $doc->createElementNS( self::NS_DS, 'ds:SignatureMethod' );
		$sm->setAttribute( 'Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' );
		$si->appendChild( $sm );
		$ref = $doc->createElementNS( self::NS_DS, 'ds:Reference' );
		$ref->setAttribute( 'URI', '#id-body' );
		$si->appendChild( $ref );
		$tr  = $doc->createElementNS( self::NS_DS, 'ds:Transforms' );
		$ref->appendChild( $tr );
		$t   = $doc->createElementNS( self::NS_DS, 'ds:Transform' );
		$t->setAttribute( 'Algorithm', self::NS_EXC );
		$tr->appendChild( $t );
		$dm  = $doc->createElementNS( self::NS_DS, 'ds:DigestMethod' );
		$dm->setAttribute( 'Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256' );
		$ref->appendChild( $dm );
		$ref->appendChild( $doc->createElementNS( self::NS_DS, 'ds:DigestValue', $digest ) );

		$signed = '';
		openssl_sign( $si->C14N( true, false ), $signed, $cert['pkey'], OPENSSL_ALGO_SHA256 );
		$signature->appendChild( $doc->createElementNS( self::NS_DS, 'ds:SignatureValue', base64_encode( $signed ) ) ); // phpcs:ignore

		$ki  = $doc->createElementNS( self::NS_DS, 'ds:KeyInfo' );
		$str = $doc->createElementNS( self::NS_WSSE, 'wsse:SecurityTokenReference' );
		$r   = $doc->createElementNS( self::NS_WSSE, 'wsse:Reference' );
		$r->setAttribute( 'URI', '#X509-token' );
		$r->setAttribute( 'ValueType', 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3' );
		$str->appendChild( $r );
		$ki->appendChild( $str );
		$signature->appendChild( $ki );

		return $doc->saveXML();
	}

	/**
	 * Send one report.
	 *
	 * @param array $body   Data attributes.
	 * @param bool  $first  First attempt.
	 * @param bool  $verify Verification only.
	 * @return array { result: ok|rejected|failed, code, warnings, error, raw }
	 */
	public static function transmit( array $body, $first = true, $verify = false ) {
		$cert = self::certificate();
		if ( is_wp_error( $cert ) ) {
			return array( 'result' => 'failed', 'code' => '', 'warnings' => array(), 'error' => $cert->get_error_message(), 'raw' => '' );
		}
		if ( empty( $body['eic_popl'] ) || empty( $body['id_jednotky'] ) ) {
			return array( 'result' => 'failed', 'code' => '', 'warnings' => array(), 'error' => 'Doplňte DIČ (EIČ) a ID evidenční jednotky v nastavení.', 'raw' => '' );
		}
		$xml      = self::build_message( $body, $first, $verify, $cert );
		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '"http://fs.gov.cz/eet/OdeslaniTrzby"',
				),
				'body'    => $xml,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 'result' => 'failed', 'code' => '', 'warnings' => array(), 'error' => 'Spojení s EET selhalo: ' . $response->get_error_message(), 'raw' => '' );
		}
		return self::parse_response( wp_remote_retrieve_body( $response ), wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Parse the SOAP answer without depending on exact prefixes.
	 *
	 * @param string $raw  Response body.
	 * @param int    $http HTTP status.
	 */
	public static function parse_response( $raw, $http = 200 ) {
		$out = array( 'result' => 'failed', 'code' => '', 'warnings' => array(), 'error' => '', 'raw' => substr( (string) $raw, 0, 4000 ) );
		if ( ! $raw ) {
			$out['error'] = 'Prázdná odpověď (HTTP ' . (int) $http . ').';
			return $out;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadXML( $raw );
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			$out['error'] = 'Nečitelná odpověď (HTTP ' . (int) $http . ').';
			return $out;
		}
		$xp = new DOMXPath( $doc );
		foreach ( $xp->query( '//*[local-name()="Varovani"]' ) as $w ) {
			$out['warnings'][] = trim( $w->getAttribute( 'kod_varov' ) . ' ' . $w->textContent );
		}
		$chyba = $xp->query( '//*[local-name()="Chyba"]' )->item( 0 );
		if ( $chyba ) {
			$code = $chyba->getAttribute( 'kod' );
			$out['error']  = trim( 'Chyba ' . $code . ': ' . $chyba->textContent );
			// Negative/system codes are temporary; positive codes mean the message is invalid.
			$out['result'] = ( '' !== $code && (int) $code > 0 ) ? 'rejected' : 'failed';
			return $out;
		}
		$fault = $xp->query( '//*[local-name()="Fault"]' )->item( 0 );
		if ( $fault ) {
			$out['error'] = 'SOAP Fault: ' . trim( preg_replace( '/\s+/', ' ', $fault->textContent ) );
			return $out;
		}
		$pot = $xp->query( '//*[local-name()="Potvrzeni"]' )->item( 0 );
		if ( $pot ) {
			$out['result'] = 'ok';
			foreach ( array( 'pok', 'fik', 'kod_potvrzeni' ) as $attr ) {
				if ( $pot->hasAttribute( $attr ) ) {
					$out['code'] = $pot->getAttribute( $attr );
					break;
				}
			}
			if ( '' === $out['code'] ) {
				$out['code'] = trim( $pot->textContent );
			}
			return $out;
		}
		$out['error'] = 'Neznámá odpověď (HTTP ' . (int) $http . ').';
		return $out;
	}

	/**
	 * Admin "Test spojení": a verification-mode message with 1 Kč.
	 */
	public static function test() {
		$s = Elka_Pos_Settings::all();
		return self::transmit(
			array(
				'eic_popl'    => strtoupper( trim( $s['eet_eic'] ) ),
				'id_jednotky' => (string) (int) $s['eet_unit_id'],
				'id_pokl'     => self::clean20( $s['register_id'], 20 ),
				'porad_cis'   => 'TEST-' . time(),
				'dat_trzby'   => self::format_date( gmdate( 'c' ) ),
				'celk_trzba'  => '1.00',
			),
			true,
			true
		);
	}
}
