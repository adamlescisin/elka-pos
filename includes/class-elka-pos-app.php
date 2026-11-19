<?php
/**
 * Serves the full-screen till at /kasa/ (configurable) plus its service worker and manifest.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_App {

	/** Default address of the till. (Not "pokladna" – that is the WooCommerce checkout in Czech.) */
	const SLUG = 'kasa';

	/** Address used by v1.0.0–1.0.3; only its service worker is still answered, to remove it. */
	const LEGACY_SLUG = 'pokladna';

	public static function slug() {
		$slug = sanitize_title( (string) Elka_Pos_Settings::get( 'app_slug' ) );
		return '' !== $slug ? $slug : self::SLUG;
	}

	/** Dedicated role for till staff: can use the till, nothing else in the admin. */
	const ROLE = 'elka_pos_cashier';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'wp_ajax_elka_pos_nonce', array( __CLASS__, 'ajax_nonce' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 5 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'wc_login_redirect' ), 20, 2 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'prevent_admin_access' ) );
	}

	/**
	 * Roles, capability and rewrite rules – re-applied after every update, so they
	 * exist even when the plugin was updated by uploading a zip (no activation hook).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'elka_pos_version' ) === ELKA_POS_VERSION ) {
			return;
		}
		self::setup_roles();
		self::rewrites();
		flush_rewrite_rules( false );
		update_option( 'elka_pos_version', ELKA_POS_VERSION, true );
	}

	public static function setup_roles() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( Elka_Pos_Settings::CAP );
			}
		}
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Pokladní (ELKA)', array( 'read' => true, Elka_Pos_Settings::CAP => true ) );
		} else {
			get_role( self::ROLE )->add_cap( Elka_Pos_Settings::CAP );
		}
	}

	/** Till staff (without access to the shop admin) land directly in the till. */
	private static function is_till_only( $user ) {
		return $user instanceof WP_User && $user->has_cap( Elka_Pos_Settings::CAP ) && ! $user->has_cap( 'manage_woocommerce' );
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( self::is_till_only( $user ) && ( ! $requested || false !== strpos( $redirect_to, 'wp-admin' ) ) ) {
			return self::url();
		}
		return $redirect_to;
	}

	public static function wc_login_redirect( $redirect, $user ) {
		return self::is_till_only( $user ) ? self::url() : $redirect;
	}

	/** WooCommerce sends non-staff from wp-admin to My Account; send till staff to the till instead. */
	public static function prevent_admin_access( $prevent ) {
		if ( $prevent && self::is_till_only( wp_get_current_user() ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		return $prevent;
	}

	public static function activate() {
		self::setup_roles();
		self::rewrites();
		flush_rewrite_rules();
		update_option( 'elka_pos_version', ELKA_POS_VERSION, true );
	}

	public static function deactivate() {
		flush_rewrite_rules();
		wp_clear_scheduled_hook( Elka_Pos_Eet::CRON );
	}

	public static function rewrites() {
		$slug = preg_quote( self::slug(), '#' );
		add_rewrite_rule( '^' . $slug . '/sw\.js$', 'index.php?elka_pos=sw', 'top' );
		add_rewrite_rule( '^' . $slug . '/manifest\.webmanifest$', 'index.php?elka_pos=manifest', 'top' );
		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?elka_pos=app', 'top' );
		if ( self::LEGACY_SLUG !== self::slug() ) {
			// Old till service worker → a version that unregisters itself (keeps checkout clean).
			add_rewrite_rule( '^' . self::LEGACY_SLUG . '/sw\.js$', 'index.php?elka_pos=sw_kill', 'top' );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'elka_pos';
		return $vars;
	}

	public static function no_canonical( $redirect, $requested ) {
		return get_query_var( 'elka_pos' ) ? false : $redirect;
	}

	public static function url( $path = '' ) {
		return home_url( '/' . self::slug() . '/' . ltrim( $path, '/' ) );
	}

	public static function serve() {
		$what = get_query_var( 'elka_pos' );
		if ( ! $what ) {
			return;
		}
		if ( 'sw' === $what ) {
			self::service_worker();
		}
		if ( 'sw_kill' === $what ) {
			self::service_worker_kill();
		}
		if ( 'manifest' === $what ) {
			self::manifest();
		}

		// The app itself.
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( ! Elka_Pos_Rest::can() ) {
			wp_die( 'K pokladně nemáte přístup.', 'ELKA Pokladna', array( 'response' => 403 ) );
		}
		$path = wp_parse_url( self::url(), PHP_URL_PATH );
		$req  = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );
		if ( $req && untrailingslashit( $req ) === untrailingslashit( $path ) && substr( $req, -1 ) !== '/' ) {
			wp_safe_redirect( self::url() );
			exit;
		}
		// Never cache the till (LiteSpeed Cache on Hostinger, WP Rocket, Cloudflare APO…).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) {
			define( 'LITESPEED_NO_OPTM', true ); // No JS/CSS combining or deferring on the till.
		}
		if ( ! defined( 'DONOTMINIFY' ) ) {
			define( 'DONOTMINIFY', true );
		}
		do_action( 'litespeed_control_set_nocache', 'ELKA Pokladna' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$config = array(
			'rest'      => esc_url_raw( rest_url( Elka_Pos_Rest::NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'assets'    => ELKA_POS_URL . 'assets/',
			'version'   => ELKA_POS_VERSION,
			'base'      => self::url(),
			'sw'        => self::url( 'sw.js' ),
			'admin'     => admin_url( 'admin.php?page=elka-pos' ),
			'orderAdmin'=> admin_url( 'post.php?action=edit&post=' ),
			'hpos'      => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'logout'    => wp_logout_url( self::url() ),
			'userId'    => get_current_user_id(),
			'site'      => get_bloginfo( 'name' ),
		);
		include ELKA_POS_DIR . 'templates/app.php';
		exit;
	}

	/**
	 * Fresh REST nonce for a till that has been open longer than the nonce lifetime.
	 */
	public static function ajax_nonce() {
		if ( ! Elka_Pos_Rest::can() ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	public static function admin_bar( $bar ) {
		if ( ! Elka_Pos_Rest::can() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'elka-pos',
				'title' => '<span class="ab-icon dashicons dashicons-store" style="top:2px"></span>Pokladna',
				'href'  => self::url(),
			)
		);
	}

	private static function service_worker() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . wp_parse_url( self::url(), PHP_URL_PATH ) );
		header( 'Cache-Control: no-cache' );
		$files = array_merge( glob( ELKA_POS_DIR . 'assets/js/*.js' ), glob( ELKA_POS_DIR . 'assets/css/*.css' ) );
		$stamp = '';
		foreach ( $files as $f ) {
			$stamp .= basename( $f ) . filemtime( $f );
		}
		$version = ELKA_POS_VERSION . '-' . substr( md5( $stamp ), 0, 8 );
		$v       = rawurlencode( ELKA_POS_VERSION );
		// Entry points are loaded with ?v=, ES modules import each other without a query.
		$assets = array(
			ELKA_POS_URL . 'assets/css/pos.css?v=' . $v,
			ELKA_POS_URL . 'assets/js/app.js?v=' . $v,
			ELKA_POS_URL . 'assets/vendor/qrcode.js',
			ELKA_POS_URL . 'assets/img/icon.svg',
		);
		foreach ( glob( ELKA_POS_DIR . 'assets/js/*.js' ) as $f ) {
			if ( 'sw.js' !== basename( $f ) && 'app.js' !== basename( $f ) ) {
				$assets[] = ELKA_POS_URL . 'assets/js/' . basename( $f );
			}
		}
		foreach ( glob( ELKA_POS_DIR . 'assets/fonts/*.woff2' ) as $font ) {
			$assets[] = ELKA_POS_URL . 'assets/fonts/' . basename( $font );
		}
		$shell = self::url();
		$src   = file_get_contents( ELKA_POS_DIR . 'assets/js/sw.js' ); // phpcs:ignore
		echo str_replace( // phpcs:ignore WordPress.Security.EscapeOutput
			array( '__VERSION__', '__ASSETS__', '__SHELL__' ),
			array( esc_js( $version ), wp_json_encode( $assets ), esc_url_raw( $shell ) ),
			$src
		);
		exit;
	}

	/**
	 * Served at the old address: unregisters the old till service worker and
	 * clears its caches, so the shop's checkout (/pokladna/) is never touched by it.
	 */
	private static function service_worker_kill() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		echo "self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',e=>e.waitUntil((async()=>{for(const k of await caches.keys()){if(k.startsWith('elka-pos-'))await caches.delete(k);}await self.registration.unregister();const cs=await self.clients.matchAll({type:'window'});cs.forEach(c=>c.navigate(c.url));})()));";
		exit;
	}

	private static function manifest() {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'name'             => 'ELKA Pokladna',
				'short_name'       => 'Pokladna',
				'start_url'        => self::url(),
				'scope'            => self::url(),
				'display'          => 'fullscreen',
				'background_color' => '#FBF7F3',
				'theme_color'      => '#3B3E44',
				'icons'            => array(
					array(
						'src'   => ELKA_POS_URL . 'assets/img/icon.svg',
						'sizes' => 'any',
						'type'  => 'image/svg+xml',
					),
				),
			)
		);
		exit;
	}
}
