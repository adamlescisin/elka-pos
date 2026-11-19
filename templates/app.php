<?php
/**
 * Till app shell.
 *
 * @package ElkaPos
 * @var array $config
 */

defined( 'ABSPATH' ) || exit;
$v = rawurlencode( ELKA_POS_VERSION );
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#3B3E44">
<title>Pokladna · <?php echo esc_html( Elka_Pos_Settings::get( 'store_name' ) ); ?></title>
<link rel="manifest" href="<?php echo esc_url( Elka_Pos_App::url( 'manifest.webmanifest' ) ); ?>">
<link rel="icon" href="<?php echo esc_url( ELKA_POS_URL . 'assets/img/icon.svg' ); ?>" type="image/svg+xml">
<link rel="preload" href="<?php echo esc_url( ELKA_POS_URL . 'assets/fonts/jost-latin-ext-400-normal.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url( ELKA_POS_URL . 'assets/css/pos.css?v=' . $v ); ?>">
<script data-cfasync="false">window.ELKA_POS = <?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body>
<div id="app" class="boot">
	<div class="boot-screen">
		<svg viewBox="0 0 120 120" width="120" height="120" aria-hidden="true" class="boot-logo"><circle cx="60" cy="60" r="52" fill="none" stroke="currentColor" stroke-width="10" pathLength="120" stroke-dasharray="0.45 1.05"></circle><circle cx="60" cy="60" r="42.5" fill="none" stroke="currentColor" stroke-width="1.8" pathLength="90" stroke-dasharray="0 1" stroke-linecap="round"></circle><text x="60" y="72" text-anchor="middle" font-family="Playfair Display, Georgia, serif" font-size="34" fill="currentColor">LK</text></svg>
		<div class="boot-title">ELKA FASHION</div>
		<div class="boot-sub">Pokladna se připravuje…</div>
	</div>
</div>
<div id="modal-root"></div>
<div id="toast-root" aria-live="polite"></div>
<iframe id="print-frame" title="Tisk účtenky" aria-hidden="true" tabindex="-1"></iframe>
<noscript>Pokladna potřebuje zapnutý JavaScript.</noscript>
<script data-cfasync="false">
(function () {
	function fail(msg) {
		if (window.__ELKA_POS_STARTED) return;
		var el = document.querySelector('.boot-sub');
		if (el) { el.innerHTML = msg; el.style.color = '#B8455A'; el.style.maxWidth = '640px'; el.style.textAlign = 'center'; }
	}
	window.__elkaPosFail = fail;
	window.addEventListener('error', function (e) {
		var t = e.target;
		if (t && t.tagName === 'SCRIPT') fail('Nepodařilo se načíst soubor pokladny (' + (t.src || '').split('/').pop() + '). Zkuste vypnout optimalizaci JavaScriptu v cache pluginu pro stránku /<?php echo esc_js( Elka_Pos_App::slug() ); ?>/.');
		else if (e.message) fail('Chyba pokladny: ' + e.message);
	}, true);
	setTimeout(function () {
		fail('Pokladna se nespustila. Obnovte stránku (Ctrl+F5). Pokud problém trvá, otevřete konzoli prohlížeče (F12) a pošlete chybové hlášení.');
	}, 15000);
})();
</script>
<script type="module" data-cfasync="false" src="<?php echo esc_url( ELKA_POS_URL . 'assets/js/app.js?v=' . $v ); ?>" onerror="window.__elkaPosFail && window.__elkaPosFail('Nepodařilo se načíst aplikaci pokladny (app.js).')"></script>
</body>
</html>
