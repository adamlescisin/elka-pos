<?php
/**
 * Uninstall: removes settings and the capability. Orders, refunds and their
 * EET records stay – they are accounting records.
 *
 * @package ElkaPos
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'elka_pos_settings' );
foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'elka_pos_use' );
	}
}
wp_clear_scheduled_hook( 'elka_pos_eet_retry' );
remove_role( 'elka_pos_cashier' );
delete_option( 'elka_pos_version' );
