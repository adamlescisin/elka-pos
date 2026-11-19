<?php
/**
 * Self-updater: shows new versions in Plugins / Dashboard → Updates and installs them
 * with one click. Source = GitHub Releases of adamlescisin/elka-pos (public repository).
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Updater {

	const REPO      = 'adamlescisin/elka-pos';
	const TRANSIENT = 'elka_pos_latest_release';
	const TTL       = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( ELKA_POS_FILE ), array( __CLASS__, 'row_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	/** Latest release from GitHub (cached). Returns array(version, zip, url, notes, published) or null. */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached ? $cached : null;
			}
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ELKA-Pokladna/' . ELKA_POS_VERSION,
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS ); // Back off for an hour on errors.
			return null;
		}
		$rel = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $rel['tag_name'] ) ) {
			set_transient( self::TRANSIENT, array(), HOUR_IN_SECONDS );
			return null;
		}
		$zip = '';
		if ( ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
			foreach ( $rel['assets'] as $a ) {
				if ( isset( $a['name'], $a['browser_download_url'] ) && preg_match( '/\.zip$/i', $a['name'] ) ) {
					$zip = $a['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $zip && ! empty( $rel['zipball_url'] ) ) {
			$zip = $rel['zipball_url'];
		}
		$data = array(
			'version'   => ltrim( (string) $rel['tag_name'], 'vV' ),
			'zip'       => $zip,
			'url'       => isset( $rel['html_url'] ) ? $rel['html_url'] : 'https://github.com/' . self::REPO,
			'notes'     => isset( $rel['body'] ) ? (string) $rel['body'] : '',
			'published' => isset( $rel['published_at'] ) ? (string) $rel['published_at'] : '',
		);
		set_transient( self::TRANSIENT, $data, self::TTL );
		return $data;
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$basename = plugin_basename( ELKA_POS_FILE );
		$rel      = self::latest();
		if ( $rel && $rel['zip'] && version_compare( $rel['version'], ELKA_POS_VERSION, '>' ) ) {
			$transient->response[ $basename ] = (object) array(
				'id'           => 'github.com/' . self::REPO,
				'slug'         => 'elka-pos',
				'plugin'       => $basename,
				'new_version'  => $rel['version'],
				'url'          => $rel['url'],
				'package'      => $rel['zip'],
				'requires'     => '6.4',
				'requires_php' => '7.4',
			);
			unset( $transient->no_update[ $basename ] );
		} else {
			$transient->no_update[ $basename ] = (object) array(
				'id'          => 'github.com/' . self::REPO,
				'slug'        => 'elka-pos',
				'plugin'      => $basename,
				'new_version' => ELKA_POS_VERSION,
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => '',
			);
			unset( $transient->response[ $basename ] );
		}
		return $transient;
	}

	/** "Zobrazit podrobnosti verze" popup. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'elka-pos' !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		if ( ! $rel ) {
			return $result;
		}
		$notes = $rel['notes'] ? nl2br( esc_html( $rel['notes'] ) ) : 'Poznámky k verzi najdete na GitHubu.';
		return (object) array(
			'name'          => 'ELKA Pokladna',
			'slug'          => 'elka-pos',
			'version'       => $rel['version'],
			'author'        => 'ELKA Fashion',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.4',
			'requires_php'  => '7.4',
			'last_updated'  => $rel['published'],
			'download_link' => $rel['zip'],
			'sections'      => array(
				'description' => 'Pokladna (POS) pro prodejnu ELKA Fashion.',
				'changelog'   => $notes,
			),
		);
	}

	/** GitHub zipballs unpack to "owner-repo-hash/" – WordPress must get "elka-pos/". */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( ELKA_POS_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . 'elka-pos/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return $wanted;
		}
		return new WP_Error( 'elka_pos_folder', 'ELKA Pokladna: nepodařilo se připravit složku aktualizace.' );
	}

	public static function row_links( $links ) {
		$url             = wp_nonce_url( admin_url( 'plugins.php?elka_pos_check=1' ), 'elka_pos_check' );
		$links['update'] = '<a href="' . esc_url( $url ) . '">Zkontrolovat aktualizace</a>';
		return $links;
	}

	public static function maybe_force_check() {
		if ( empty( $_GET['elka_pos_check'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		check_admin_referer( 'elka_pos_check' );
		delete_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$rel = self::latest( true );
		$msg = ( $rel && version_compare( $rel['version'], ELKA_POS_VERSION, '>' ) ) ? 'new' : ( $rel ? 'current' : 'error' );
		wp_safe_redirect( add_query_arg( 'elka_pos_checked', $msg, admin_url( 'plugins.php' ) ) );
		exit;
	}

	public static function notice() {
		if ( empty( $_GET['elka_pos_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$r = sanitize_key( $_GET['elka_pos_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'new' === $r ) {
			echo '<div class="notice notice-success is-dismissible"><p>ELKA Pokladna: je dostupná nová verze – klikněte u pluginu na „Aktualizovat“.</p></div>';
		} elseif ( 'current' === $r ) {
			echo '<div class="notice notice-info is-dismissible"><p>ELKA Pokladna je aktuální (verze ' . esc_html( ELKA_POS_VERSION ) . ').</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>ELKA Pokladna: GitHub se nepodařilo zkontrolovat, zkuste to později.</p></div>';
		}
	}

	public static function after_upgrade( $upgrader, $options ) {
		if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( plugin_basename( ELKA_POS_FILE ), (array) $options['plugins'], true ) ) {
			delete_transient( self::TRANSIENT );
		}
	}
}

add_action( 'admin_notices', array( 'Elka_Pos_Updater', 'notice' ) );
