<?php
/**
 * Plugin Name:       ELKA Pokladna
 * Plugin URI:        https://elkafashion.cz
 * Description:       Moderní pokladna (POS) pro prodejnu ELKA Fashion – produkty a velikosti, zákaznice a ELKA Klub, slevy a kupóny, vratky, tisk účtenek 80 mm, e-mailové doklady, offline režim a EET 2.0.
 * Version:           1.0.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            ELKA Fashion
 * License:           GPLv2 or later
 * Text Domain:       elka-pos
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

define( 'ELKA_POS_VERSION', '1.0.4' );
define( 'ELKA_POS_FILE', __FILE__ );
define( 'ELKA_POS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ELKA_POS_URL', plugin_dir_url( __FILE__ ) );

require_once ELKA_POS_DIR . 'includes/class-elka-pos-settings.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-klub.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-catalog.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-orders.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-eet.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-rest.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-app.php';
require_once ELKA_POS_DIR . 'includes/class-elka-pos-admin.php';

// HPOS + block checkout compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'Elka_Pos_App', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Elka_Pos_App', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>ELKA Pokladna vyžaduje aktivní WooCommerce.</p></div>';
				}
			);
			return;
		}
		Elka_Pos_App::init();
		Elka_Pos_Rest::init();
		Elka_Pos_Orders::init();
		Elka_Pos_Eet::init();
		if ( is_admin() ) {
			Elka_Pos_Admin::init();
		}
	}
);
