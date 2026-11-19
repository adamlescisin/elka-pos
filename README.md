# ELKA Pokladna

Moderní pokladna (POS) pro prodejnu **ELKA Fashion** v Mělníku – plugin pro WordPress/WooCommerce. Nahrazuje ukončený oficiální WooCommerce Point of Sale.

Pokladna běží na adrese **`/kasa/`** (lze změnit v nastavení). Prodeje jsou běžné objednávky WooCommerce, takže sklad, reporty i věrnostní program [ELKA Klub](https://github.com/adamlescisin/elka_klub) jsou společné s e-shopem.

## Funkce

- Katalog s kategoriemi, hledáním a skladem; varianty a velikosti (klik na velikost = do košíku), ruční položky
- Zákaznice: host, hledání (jméno, e-mail, telefon, klubová karta), založení nové, přihlášení do ELKA Klubu, body a vouchery
- Slevy na položku i nákup (% / Kč), kupóny WooCommerce
- Platby: hotově (vrácení, zaokrouhlení), QR platba (SPAYD), karta přes terminál SumUp
- Tisk účtenek 80 mm, kopie, doklad o vrácení; e-mailový doklad přes šablonu z e-mailového builderu XStore
- Vratky (sklad, body, storno EET)
- Offline režim (IndexedDB + service worker, fronta prodejů)
- EET 2.0 (od 1. 1. 2027) – SOAP v4.1, pokladní certifikát, fronta a opakované odeslání

## Instalace

Stáhněte repozitář jako ZIP (složka musí mít název `elka-pos`) a nahrajte v WordPressu přes Pluginy → Instalace pluginů → Nahrát plugin. Podrobnosti, nastavení Chrome kiosku a zprovoznění EET jsou v [readme.txt](readme.txt).

## Struktura

| Cesta | Obsah |
|---|---|
| `elka-pos.php` | bootstrap pluginu |
| `includes/` | nastavení, REST API (`/wp-json/elka-pos/v1/`), objednávky a vratky, katalog, ELKA Klub, EET, administrace, aplikace na `/kasa/` |
| `templates/app.php` | HTML kostra pokladny |
| `assets/js/` | aplikace pokladny (ES moduly, bez build kroku), service worker |
| `assets/css/pos.css` | vzhled (identita „Butik Mělník“) |
| `assets/fonts/`, `assets/vendor/` | lokální písma a knihovna QR kódů (offline) |
