<?php
/**
 * Admin: WooCommerce → ELKA Pokladna (settings, EET certificate and queue, help).
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_elka_pos_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_elka_pos_eet_test', array( __CLASS__, 'eet_test' ) );
		add_action( 'admin_post_elka_pos_eet_retry', array( __CLASS__, 'eet_retry' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ELKA_POS_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( Elka_Pos_App::url() ) . '">Otevřít pokladnu</a>', '<a href="' . esc_url( admin_url( 'admin.php?page=elka-pos' ) ) . '">Nastavení</a>' );
		return $links;
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'ELKA Pokladna', 'ELKA Pokladna', 'manage_woocommerce', 'elka-pos', array( __CLASS__, 'page' ) );
	}

	/** EET box on the order screen (classic + HPOS). */
	public static function meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'elka-pos-eet', 'ELKA Pokladna', array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order || ! Elka_Pos_Orders::is_pos_order( $order ) ) {
			echo '<p>Objednávka nevznikla na pokladně.</p>';
			return;
		}
		$eet = Elka_Pos_Eet::status_for( $order );
		$labels = array( 'off' => 'neevidováno', 'pending' => 'čeká na odeslání', 'sent' => 'evidováno', 'verify' => 'ověřovací režim', 'error' => 'chyba' );
		echo '<p><strong>Platba:</strong> ' . esc_html( $order->get_payment_method_title() ) . '<br>';
		echo '<strong>Pokladna:</strong> ' . esc_html( $order->get_meta( '_elka_pos_register' ) ) . '<br>';
		echo '<strong>EET:</strong> ' . esc_html( isset( $labels[ $eet['status'] ] ) ? $labels[ $eet['status'] ] : $eet['status'] );
		if ( $eet['code'] ) {
			echo '<br><code style="word-break:break-all">' . esc_html( $eet['code'] ) . '</code>';
		}
		if ( $eet['error'] ) {
			echo '<br><span style="color:#b32d2e">' . esc_html( $eet['error'] ) . '</span>';
		}
		echo '</p>';
	}

	private static function tabs() {
		return array(
			'store'   => 'Prodejna a účtenka',
			'payment' => 'Platby a doklady',
			'eet'     => 'EET 2.0',
			'help'    => 'Nápověda',
		);
	}

	public static function page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'store'; // phpcs:ignore WordPress.Security.NonceVerification
		$s   = Elka_Pos_Settings::all();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px">ELKA Pokladna <a class="page-title-action" href="<?php echo esc_url( Elka_Pos_App::url() ); ?>" target="_blank">Otevřít pokladnu</a></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success is-dismissible"><p>Nastavení uloženo.</p></div>
			<?php endif; ?>
			<?php
			$msg = get_transient( 'elka_pos_admin_msg_' . get_current_user_id() );
			if ( $msg ) {
				delete_transient( 'elka_pos_admin_msg_' . get_current_user_id() );
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $msg['type'] ), wp_kses_post( $msg['text'] ) );
			}
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $id => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=elka-pos&tab=' . $id ) ); ?>" class="nav-tab <?php echo $tab === $id ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'help' === $tab ) : ?>
				<?php self::help(); ?>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="elka_pos_save">
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php wp_nonce_field( 'elka_pos_save' ); ?>
				<table class="form-table" role="presentation">
				<?php
				if ( 'store' === $tab ) {
					self::text( 'store_name', 'Název prodejny', $s );
					self::text( 'store_tagline', 'Podtitul', $s );
					self::text( 'company_name', 'Provozovatel (firma / jméno)', $s, 'Vytiskne se na účtence.' );
					self::textarea( 'address', 'Adresa provozovny', $s );
					self::text( 'ico', 'IČO', $s );
					self::text( 'dic', 'DIČ', $s );
					self::check( 'vat_payer', 'Plátce DPH', $s, 'Na účtence se zobrazí rozpis DPH podle sazeb.' );
					self::text( 'phone', 'Telefon', $s );
					self::text( 'web', 'Web', $s );
					self::textarea( 'receipt_footer', 'Patička účtenky', $s );
					self::text( 'receipt_signoff', 'Podpis (ručním písmem)', $s );
					self::number( 'receipt_copies', 'Počet kopií účtenky', $s, 1, 3 );
					self::check( 'auto_print', 'Tisknout automaticky', $s, 'Po zaplacení se účtenka hned vytiskne. Pro tisk bez dialogu spusťte Chrome s parametrem --kiosk-printing (viz Nápověda).' );
					self::text( 'register_id', 'Označení pokladny', $s, 'Max. 20 znaků, použije se i pro EET (id_pokl).' );
					self::text( 'app_slug', 'Adresa pokladny', $s, 'Pokladna běží na ' . home_url( '/' ) . '<adresa>/ – nesmí se shodovat s žádnou stránkou webu (např. „pokladna“ je košík/pokladna e-shopu).' );
				} elseif ( 'payment' === $tab ) {
					self::check( 'pay_cash', 'Hotovost', $s );
					self::check( 'cash_rounding', 'Zaokrouhlovat hotovost', $s, 'Platby v hotovosti se zaokrouhlí na celé koruny (položka „Zaokrouhlení“).' );
					self::check( 'pay_qr', 'QR platba', $s );
					self::text( 'qr_iban', 'IBAN pro QR platby', $s, 'Z IBANu se pro každý nákup vytvoří QR kód (formát QR Platba / SPAYD) s částkou a variabilním symbolem.' );
					self::text( 'qr_message', 'Zpráva pro příjemce', $s );
					self::check( 'pay_card', 'Platební karta', $s );
					self::text( 'card_label', 'Název platby kartou', $s, 'Terminál SumUp je samostatný – na pokladně se platba jen potvrdí.' );
					self::select( 'order_status', 'Stav objednávky po prodeji', $s, array( 'completed' => 'Dokončeno', 'processing' => 'Zpracovává se' ) );
					self::select( 'email_type', 'E-mailový doklad', $s, array( 'completed' => 'E-mail „Dokončená objednávka“', 'invoice' => 'E-mail „Faktura / detail objednávky“' ), 'Šablonu i vzhled řídí WooCommerce → Nastavení → E-maily. Plugin WooCommerce PDF Invoices připojí PDF fakturu, pokud ji má pro zvolený e-mail zapnutou.' );
					$tpls = Elka_Pos_Orders::email_templates();
					if ( $tpls ) {
						self::select( 'email_template', 'Šablona e-mailového dokladu', $s, array( 0 => '— podle e-mailu WooCommerce (bez vynucené šablony) —' ) + $tpls, 'Šablona z XStore → Šablony pro vytváření e-mailů, použije se jen pro doklady z pokladny. Bloky objednávky v šabloně (položky, součty, adresy) se vyplní údaji z prodeje; šablona jen s blokem „obsah WooCommerce“ obalí standardní e-mail. Předmět: „název šablony č. objednávky“ (např. Pokladní doklad č. 106).' );
					} else {
						echo '<tr><th scope="row">Šablona e-mailového dokladu</th><td><p class="description">E-mailový builder (XStore / Email Template Customizer) nebyl nalezen – použije se šablona WooCommerce.</p></td></tr>';
					}
					self::check( 'email_default', 'Předvyplnit „Poslat doklad e-mailem“', $s );
					self::check( 'offline_enabled', 'Offline režim', $s, 'Pokladna uchovává katalog a zákaznice v prohlížeči a prodeje bez internetu odešle po obnovení připojení.' );
					self::number( 'sync_minutes', 'Synchronizace katalogu (minuty)', $s, 1, 60 );
				} elseif ( 'eet' === $tab ) {
					self::eet_intro();
					self::check( 'eet_enabled', 'Evidovat tržby (EET 2.0)', $s );
					self::select( 'eet_mode', 'Prostředí', $s, array( 'playground' => 'Playground (test)', 'production' => 'Produkce' ) );
					self::text( 'eet_eic', 'EIČ poplatníka', $s, 'DIČ včetně CZ, nebo identifikátor přidělený správcem daně.' );
					self::text( 'eet_unit_id', 'ID evidenční jednotky', $s, 'Číslo z DIS+ → Evidence tržeb → Evidenční jednotky.' );
					?>
					<tr><th scope="row">Pokladní certifikát (.p12)</th><td>
						<input type="file" name="eet_cert" accept=".p12,.pfx">
						<p class="description"><?php echo $s['eet_cert_path'] && file_exists( $s['eet_cert_path'] ) ? 'Nahraný certifikát: ' . esc_html( self::cert_info() ) : 'Zatím nenahrán. Vygenerujte jej v DIS+ (MOJE daně) → Evidence tržeb.'; ?></p>
					</td></tr>
					<tr><th scope="row"><label for="eet_cert_pass">Heslo k certifikátu</label></th><td>
						<input type="password" id="eet_cert_pass" name="eet_cert_pass" value="" autocomplete="new-password" placeholder="<?php echo $s['eet_cert_pass'] ? '•••••••• (uloženo)' : ''; ?>" class="regular-text">
					</td></tr>
					<?php
					self::check( 'eet_verify_only', 'Ověřovací režim', $s, 'Zprávy se posílají s příznakem „overeni“ – tržby se neevidují. Jen pro testování.' );
				}
				?>
				</table>
				<?php submit_button( 'Uložit nastavení' ); ?>
			</form>
			<?php if ( 'eet' === $tab ) : ?>
				<?php self::eet_tools(); ?>
			<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function eet_intro() {
		echo '<tr><td colspan="2" style="padding-left:0"><div style="background:#fff;border-left:4px solid #3B3E44;padding:12px 16px;max-width:820px">';
		echo '<p style="margin-top:0"><strong>EET 2.0 platí od 1. 1. 2027</strong> pro platby při osobním kontaktu se zákazníkem – hotovost, karta i QR. Leden 2027 je pilotní provoz.</p>';
		echo '<ol style="margin-bottom:0"><li>V DIS+ (MOJE daně) se přihlaste k evidenci tržeb, založte evidenční jednotku (prodejna) a vygenerujte pokladní certifikát.</li><li>Sem vyplňte EIČ, ID evidenční jednotky a nahrajte certifikát s heslem.</li><li>V prostředí Playground použijte „Test spojení“, pak přepněte na Produkci.</li></ol>';
		echo '<p style="margin-bottom:0">Prodej bez internetu se odešle automaticky po obnovení spojení (zákonná lhůta 48 h). Vratka se eviduje jako tržba se zápornou částkou.</p>';
		echo '</div></td></tr>';
	}

	private static function cert_info() {
		$cert = Elka_Pos_Eet::certificate();
		if ( is_wp_error( $cert ) ) {
			return $cert->get_error_message();
		}
		$info = openssl_x509_parse( $cert['cert'] );
		$cn   = isset( $info['subject']['CN'] ) ? $info['subject']['CN'] : '?';
		$to   = isset( $info['validTo_time_t'] ) ? wp_date( 'j. n. Y', $info['validTo_time_t'] ) : '?';
		return 'CN ' . $cn . ', platný do ' . $to;
	}

	private static function eet_tools() {
		$stats = Elka_Pos_Eet::queue_stats();
		?>
		<h2>Fronta EET</h2>
		<p>Čeká na odeslání: <strong><?php echo (int) $stats['pending']; ?></strong> · Chyby: <strong><?php echo (int) $stats['error']; ?></strong></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="elka_pos_eet_test"><?php wp_nonce_field( 'elka_pos_eet' ); ?>
			<?php submit_button( 'Test spojení (ověřovací zpráva 1 Kč)', 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
			<input type="hidden" name="action" value="elka_pos_eet_retry"><?php wp_nonce_field( 'elka_pos_eet' ); ?>
			<?php submit_button( 'Odeslat čekající a chybné nyní', 'secondary', 'submit', false ); ?>
		</form>
		<?php
		$rows = wc_get_orders(
			array(
				'type'     => array( 'shop_order', 'shop_order_refund' ),
				'limit'    => 30,
				'status'   => 'any',
				'meta_key' => '_elka_eet_status', // phpcs:ignore
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);
		if ( $rows ) {
			echo '<table class="widefat striped" style="max-width:1000px;margin-top:12px"><thead><tr><th>Doklad</th><th>Datum</th><th>Částka</th><th>Stav</th><th>Kód / chyba</th></tr></thead><tbody>';
			foreach ( $rows as $o ) {
				$e    = Elka_Pos_Eet::status_for( $o );
				$body = $o->get_meta( '_elka_eet_body' );
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s Kč</td><td>%4$s</td><td style="word-break:break-all">%5$s</td></tr>',
					esc_html( $e['porad'] ),
					esc_html( is_array( $body ) ? $body['dat_trzby'] : '' ),
					esc_html( is_array( $body ) ? $body['celk_trzba'] : '' ),
					esc_html( $e['status'] ),
					esc_html( $e['code'] ? $e['code'] : $e['error'] )
				);
			}
			echo '</tbody></table>';
		}
	}

	private static function help() {
		?>
		<div style="max-width:860px;font-size:14px;line-height:1.6">
			<h2>Spuštění pokladny</h2>
			<p>Pokladna běží na adrese <a href="<?php echo esc_url( Elka_Pos_App::url() ); ?>"><code><?php echo esc_html( Elka_Pos_App::url() ); ?></code></a>. Přístup mají administrátoři a správci obchodu (oprávnění <code>elka_pos_use</code>).</p>
			<h2>Doporučené nastavení na Windows PC</h2>
			<ol>
				<li>V Chromu otevřete pokladnu a přihlaste se (zaškrtněte „Zapamatovat si mě“).</li>
				<li>Výchozí tiskárnu ve Windows nastavte na termotiskárnu 80 mm, papír 80 mm (72 mm tisknutelná šířka), okraje žádné.</li>
				<li>Vytvořte zástupce na ploše s cílem:<br><code>"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing "<?php echo esc_html( Elka_Pos_App::url() ); ?>"</code><br><em>--kiosk-printing</em> tiskne účtenky rovnou bez dialogu, <em>--kiosk</em> spustí pokladnu přes celou obrazovku (ukončení Alt+F4).</li>
				<li>Volitelně: Chrome → nabídka → Uložit a sdílet → Instalovat stránku jako aplikaci.</li>
			</ol>
			<h2>Offline režim</h2>
			<p>Při prvním spuštění se stáhne katalog a seznam zákaznic. Bez internetu lze dál prodávat (hotově, kartou, QR), přidávat zákaznice do ELKA Klubu a tisknout účtenky. Prodeje se uloží do fronty a odešlou se po obnovení připojení – včetně odečtu skladu, bodů ELKA Klubu a EET. Kupóny vyžadují připojení.</p>
			<h2>Klávesové zkratky</h2>
			<p><kbd>F2</kbd> nebo <kbd>/</kbd> hledat · <kbd>F4</kbd> zákaznice · <kbd>F8</kbd> zaplatit · <kbd>Esc</kbd> zavřít okno. Kód produktu (SKU) nebo číslo klubové karty + Enter v hledání přidá produkt / vybere zákaznici – připraveno i pro čtečku čárových kódů.</p>
		</div>
		<?php
	}

	/* Field helpers ------------------------------------------------------ */

	private static function text( $key, $label, $s, $desc = '' ) {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%1$s" value="%3$s">%4$s</td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $s[ $key ] ), $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' );
	}

	private static function textarea( $key, $label, $s ) {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea class="large-text" rows="3" id="%1$s" name="%1$s">%3$s</textarea></td></tr>', esc_attr( $key ), esc_html( $label ), esc_textarea( $s[ $key ] ) );
	}

	private static function number( $key, $label, $s, $min, $max ) {
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" class="small-text" id="%1$s" name="%1$s" value="%3$d" min="%4$d" max="%5$d"></td></tr>', esc_attr( $key ), esc_html( $label ), (int) $s[ $key ], (int) $min, (int) $max );
	}

	private static function check( $key, $label, $s, $desc = '' ) {
		printf( '<tr><th scope="row">%2$s</th><td><label><input type="checkbox" name="%1$s" value="yes" %3$s> Zapnuto</label>%4$s</td></tr>', esc_attr( $key ), esc_html( $label ), checked( 'yes', $s[ $key ], false ), $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' );
	}

	private static function select( $key, $label, $s, $options, $desc = '' ) {
		$html = '';
		foreach ( $options as $v => $l ) {
			$html .= sprintf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $v ), selected( $s[ $key ], $v, false ), esc_html( $l ) );
		}
		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">%3$s</select>%4$s</td></tr>', esc_attr( $key ), esc_html( $label ), $html, $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ); // phpcs:ignore
	}

	/* Actions ------------------------------------------------------------ */

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		check_admin_referer( 'elka_pos_save' );
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'store';
		$fields = array(
			'store'   => array(
				'text'  => array( 'store_name', 'store_tagline', 'company_name', 'ico', 'dic', 'phone', 'web', 'receipt_signoff', 'register_id' ),
				'area'  => array( 'address', 'receipt_footer' ),
				'check' => array( 'vat_payer', 'auto_print' ),
				'int'   => array( 'receipt_copies' ),
			),
			'payment' => array(
				'text'  => array( 'qr_iban', 'qr_message', 'card_label', 'order_status', 'email_type' ),
				'area'  => array(),
				'check' => array( 'pay_cash', 'cash_rounding', 'pay_qr', 'pay_card', 'email_default', 'offline_enabled' ),
				'int'   => array( 'sync_minutes' ),
			),
			'eet'     => array(
				'text'  => array( 'eet_mode', 'eet_eic', 'eet_unit_id' ),
				'area'  => array(),
				'check' => array( 'eet_enabled', 'eet_verify_only' ),
				'int'   => array(),
			),
		);
		$values = array();
		if ( isset( $fields[ $tab ] ) ) {
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( $fields[ $tab ]['text'] as $k ) {
				$values[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
			}
			foreach ( $fields[ $tab ]['area'] as $k ) {
				$values[ $k ] = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
			}
			foreach ( $fields[ $tab ]['check'] as $k ) {
				$values[ $k ] = isset( $_POST[ $k ] ) ? 'yes' : 'no';
			}
			foreach ( $fields[ $tab ]['int'] as $k ) {
				$values[ $k ] = isset( $_POST[ $k ] ) ? absint( $_POST[ $k ] ) : 1;
			}
			// phpcs:enable
		}
		if ( 'payment' === $tab ) {
			$values['email_template'] = isset( $_POST['email_template'] ) ? absint( $_POST['email_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['app_slug'] ) && 'store' === $tab ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$slug  = sanitize_title( wp_unslash( $_POST['app_slug'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$taken = $slug ? get_page_by_path( $slug ) : null;
			$wc    = array_filter( array( wc_get_page_id( 'checkout' ), wc_get_page_id( 'cart' ), wc_get_page_id( 'myaccount' ), wc_get_page_id( 'shop' ) ), function ( $id ) { return $id > 0; } );
			$clash = $taken || in_array( $slug, array_map( function ( $id ) { return get_post_field( 'post_name', $id ); }, $wc ), true );
			if ( ! $slug || $clash ) {
				self::flash( 'error', 'Adresu pokladny „' . esc_html( $slug ) . '“ nelze použít – ' . ( $slug ? 'už ji používá stránka webu.' : 'je prázdná.' ) . ' Ponechána původní.' );
				unset( $values['app_slug'] );
			} else {
				$values['app_slug'] = $slug;
			}
		}
		if ( isset( $values['register_id'] ) ) {
			$values['register_id'] = substr( $values['register_id'] ? $values['register_id'] : 'POKLADNA1', 0, 20 );
		}

		if ( 'eet' === $tab ) {
			if ( ! empty( $_POST['eet_cert_pass'] ) ) {
				$values['eet_cert_pass'] = wp_unslash( $_POST['eet_cert_pass'] ); // phpcs:ignore -- password, stored as-is.
			}
			if ( ! empty( $_FILES['eet_cert']['tmp_name'] ) && is_uploaded_file( $_FILES['eet_cert']['tmp_name'] ) ) { // phpcs:ignore
				$dir = self::private_dir();
				$dest = trailingslashit( $dir ) . 'pokladni-certifikat-' . wp_generate_password( 8, false ) . '.p12';
				if ( move_uploaded_file( $_FILES['eet_cert']['tmp_name'], $dest ) ) { // phpcs:ignore
					$old = Elka_Pos_Settings::get( 'eet_cert_path' );
					if ( $old && file_exists( $old ) && $old !== $dest ) {
						wp_delete_file( $old );
					}
					$values['eet_cert_path'] = $dest;
				}
			}
		}
		$old_slug = Elka_Pos_App::slug();
		Elka_Pos_Settings::save( $values );
		Elka_Pos_Eet::schedule();
		if ( Elka_Pos_App::slug() !== $old_slug ) {
			Elka_Pos_App::rewrites();
			flush_rewrite_rules( false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=elka-pos&tab=' . $tab . '&saved=1' ) );
		exit;
	}

	/**
	 * Folder for the certificate outside public access (uploads + deny rules).
	 */
	private static function private_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'elka-pos-private';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', '<?php // Silence.' ); // phpcs:ignore
		}
		return $dir;
	}

	private static function flash( $type, $text ) {
		set_transient( 'elka_pos_admin_msg_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 60 );
	}

	public static function eet_test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		check_admin_referer( 'elka_pos_eet' );
		$r = Elka_Pos_Eet::test();
		if ( 'ok' === $r['result'] ) {
			self::flash( 'success', 'Spojení s EET (' . esc_html( Elka_Pos_Eet::endpoint() ) . ') funguje.' . ( $r['warnings'] ? ' Varování: ' . esc_html( implode( '; ', $r['warnings'] ) ) : '' ) );
		} else {
			self::flash( 'error', 'Test EET selhal: ' . esc_html( $r['error'] ) . ( $r['raw'] ? '<details><summary>Odpověď serveru</summary><pre style="white-space:pre-wrap">' . esc_html( $r['raw'] ) . '</pre></details>' : '' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=elka-pos&tab=eet' ) );
		exit;
	}

	public static function eet_retry() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Nemáte oprávnění.' );
		}
		check_admin_referer( 'elka_pos_eet' );
		Elka_Pos_Eet::retry_pending( true );
		$stats = Elka_Pos_Eet::queue_stats();
		self::flash( $stats['error'] ? 'warning' : 'success', 'Záznamy byly znovu odeslány.' . ( $stats['error'] ? ' Stále s chybou: ' . (int) $stats['error'] . ' – zkontrolujte certifikát a údaje.' : '' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=elka-pos&tab=eet' ) );
		exit;
	}
}
