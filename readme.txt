=== ELKA Pokladna ===
Requires at least: 6.4
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.4
License: GPLv2 or later

Moderní pokladna (POS) pro prodejnu ELKA Fashion v Mělníku. Nahrazuje ukončený
plugin WooCommerce Point of Sale. Prodeje jsou běžné objednávky WooCommerce,
takže sklad, reporty i ELKA Klub jsou společné s e-shopem.

== Co umí ==

* Katalog s kategoriemi, hledáním (název, kód, značka) a skladem; varianty a velikosti
  s počtem kusů – klik na velikost rovnou přidá do košíku.
* Ruční položky (název, cena, sazba DPH).
* Zákaznice: hledání podle jména, e-mailu, telefonu nebo čísla klubové karty,
  prodej bez zákaznice (host), založení nové zákaznice.
* ELKA Klub: zobrazení bodů a voucherů, přihlášení do Klubu přímo u pokladny,
  uplatnění voucheru jedním klikem, body se připíší automaticky.
* Slevy na položku i na celý nákup (% nebo Kč) a kupóny WooCommerce.
* Platby: hotově (s výpočtem vrácení a zaokrouhlením), QR platba (QR kód s částkou
  a VS), kartou přes samostatný terminál SumUp.
* Tisk účtenek na termotiskárnu 80 mm, kopie účtenky, doklad o vrácení.
* E-mailový doklad (WooCommerce e-mail, s PDF fakturou z pluginu WooCommerce PDF
  Invoices, pokud ji má pro daný e-mail zapnutou) – hned nebo dodatečně.
* Vratky s vrácením na sklad, odečtem bodů a stornem v EET.
* Offline režim: bez internetu lze prodávat dál, prodeje se odešlou po obnovení spojení.
* EET 2.0 (od 1. 1. 2027): odeslání tržby s pokladním certifikátem, fronta a
  opakované odeslání, storno při vratce.

== Instalace ==

1. Nahrajte složku elka-pos do wp-content/plugins a aktivujte plugin
   (WooCommerce musí být aktivní, ELKA Klub doporučen).
2. WooCommerce → ELKA Pokladna: vyplňte údaje na účtence (provozovatel, adresa,
   IČO, DIČ), IBAN pro QR platby a označení pokladny.
3. Pokladna je na adrese https://VAS-WEB/kasa/ (lze změnit v nastavení) (odkaz je i v horní liště).
4. Na pokladním PC (Windows) spusťte Chrome takto (zástupce na ploše):
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing "https://VAS-WEB/kasa/"
   --kiosk-printing tiskne bez dialogu na výchozí tiskárnu (termotiskárna 80 mm,
   bez okrajů). Ukončení kiosku: Alt+F4.
5. Původní plugin WooCommerce Point of Sale deaktivujte.

== EET 2.0 – zprovoznění ==

1. MOJE daně → DIS+ → Evidence tržeb: přihlášení k evidenci, evidenční jednotka
   (prodejna Mělník), vygenerovat pokladní certifikát (.p12) a heslo.
2. WooCommerce → ELKA Pokladna → EET 2.0: zapnout, EIČ (DIČ s CZ), ID evidenční
   jednotky, nahrát certifikát a heslo. Prostředí: Playground.
3. Tlačítko „Test spojení" musí vrátit úspěch. Pak přepnout na Produkci.
4. Zpráva odpovídá oficiálnímu schématu EETXMLSchema.xsd v4.1 (ověřeno proti
   Playgroundu). Leden 2027 je pilotní provoz, ostrý provoz od 1. 2. 2027.

== Klávesy ==

F2 nebo / hledat · F4 zákaznice · F6 prodeje a vratky · F8 zaplatit · 1/2/3 způsob
platby · Enter dokončit · Esc zavřít. Kód produktu nebo číslo klubové karty + Enter
v hledání – připraveno pro čtečku čárových kódů.

== Technické poznámky ==

* REST API: /wp-json/elka-pos/v1/ (oprávnění elka_pos_use – správce obchodu, admin).
* Prodej je objednávka se stavem Dokončeno, created_via = elka-pos, platební metoda
  elka_pos_cash / elka_pos_qr / elka_pos_card. Kompatibilní s HPOS.
* Ruční slevy se promítnou do ceny položky (původní cena a sleva jsou v metadatech
  a na účtence), kupóny počítá WooCommerce.
* Automatické e-maily zákaznici ani obchodu se u prodejů z pokladny neposílají –
  jen na vyžádání z pokladny.
* Offline: katalog a zákaznice v IndexedDB prohlížeče, aplikace v service workeru.
  Prodej má UUID, opakované odeslání nevytvoří duplicitu.
* Certifikát EET se ukládá do wp-content/uploads/elka-pos-private (zakázaný přístup
  přes .htaccess; na Nginx doplňte deny pravidlo).

== Changelog ==

= 1.0.4 =
* Pokladna přesunuta na /kasa/ (adresa nastavitelná). /pokladna/ je opět pokladna (checkout) e-shopu; starý service worker se sám odstraní.

= 1.0.3 =
* E-mailová šablona s vlastními bloky objednávky (položky, součty, adresy) se vyplní údaji z prodeje.

= 1.0.2 =
* Volba šablony e-mailového dokladu z e-mailového builderu XStore (např. „Pokladní doklad“); předmět „Pokladní doklad č. …“.

= 1.0.1 =
* Oprava: pokladna se na hostingu Hostinger/LiteSpeed nespustila (soubor .mjs servírovaný jako text). Hlášení chyby místo nekonečného načítání.
* Nová role „Pokladní (ELKA)“; oprávnění se obnoví po každé aktualizaci.
* Pokladna vyloučena z LiteSpeed Cache a Cloudflare Rocket Loader.

= 1.0.0 =
* První verze.
