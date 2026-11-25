=== ICECOLD Shipping ===
Contributors: icecold
Tags: shipping, woocommerce, chilled, frozen, doprava, icecold
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Chlazená a mražená doprava ICECOLD pro WooCommerce — zásilky přes API, štítky a webhooky.

== Popis ==

Plugin propojí WooCommerce s ICECOLD API v1:

* přihlášení stejným účtem, jaký používáte do klientského portálu ICECOLD — heslo se neukládá, jen token,
* dopravní metody pro chlazený (1–5 °C) a mražený (−18 °C) režim s cenou a oknem,
* založí zásilku v API při změně stavu objednávky (nebo ručně z detailu objednávky),
* otevře tiskový štítek 100 × 150 mm vygenerovaný na icecoldtrans.cz — tisk 1:1
  z prohlížeče, token zůstává na serveru e-shopu,
* přijme webhooky `shipment.*` a `temperature.alert`, ověří podpis HMAC-SHA256
  a promítne stav do objednávky (po doručení ji případně označí jako dokončenou),
* přidá sloupec ICECOLD do seznamu objednávek.

Manuál a API: https://it.icecoldtrans.cz/integrace#woocommerce

== Instalace ==

1. Nahrajte složku icecold-shipping do wp-content/plugins, nebo ZIP přes Pluginy → Přidat nový → Nahrát plugin.
2. Plugin aktivujte.
3. WooCommerce → ICECOLD: přihlaste se e-mailem a heslem ke klientskému portálu ICECOLD. Plugin si uloží jen token, ne heslo.
4. Klikněte na „Zaregistrovat webhook u ICECOLD“ — uloží se URL i tajemství pro ověření podpisu.
5. WooCommerce → Nastavení → Doprava: přidejte metody ICECOLD (chlazená / mražená), nastavte cenu a okno.
6. Otestujte objednávku; pro sandbox použijte ruční API token v pokročilém nastavení.

Požadavky: PHP 8.1+, WooCommerce 8+, účet v klientském portálu ICECOLD (nebo ruční API token pro sandbox).

== Časté otázky ==

= Proč se doprava v košíku nenabízí? =
Dokud není e-shop přihlášený k ICECOLD (nebo vyplněný ruční API token), metoda se záměrně nenabízí.

= Kde vytisknu štítek? =
V detailu objednávky tlačítkem „Otevřít štítek k tisku“. Štítek se generuje
na icecoldtrans.cz v rozměru 100 × 150 mm; v tiskovém dialogu zvolte tisk 1:1
(bez změny měřítka) a termotiskárnu etiket.

= Co dělá Idempotency-Key? =
Posíláme číslo objednávky. Opakované volání do 24 hodin vrátí původní zásilku místo duplicity.

= Kde jsou chyby? =
V poznámce objednávky a v panelu ICECOLD u objednávky. Při zapnutém protokolu
také ve WooCommerce → Stav → Logy (zdroj icecold-shipping).

= Jak otestovat bez ostrého API? =
V nastavení nechte zapnutý sandbox a použijte sandboxový token. Pro staging
lze adresu API přepsat filtrem `icecold_api_base_url`.

== Changelog ==

= 2.2.0 =
* Přihlášení e-shopu portálovým účtem ICECOLD (e-mail + heslo jednorázově, ukládá se jen token).
* Token vydaný portálem lze zrušit tlačítkem „Odpojit e-shop“; na serveru je uložený jen jeho hash.
* Zachována zpětná kompatibilita s ručním API tokenem pro sandbox a speciální napojení.

= 2.1.1 =
* Oprava: dopravní metoda se načítá až po inicializaci WooCommerce (dřív mohl plugin shodit celý web).
* Nový filtr `icecold_api_base_url` pro nasměrování na testovací/staging API.

= 2.1.0 =
* Štítek se otevírá jako tisková stránka 100 × 150 mm z našeho webu (dřív PDF).
* Webhooky nově odebírají i stav „na trase“ (`shipment.in_transit`).
* Doplněna ochrana pro WooCommerce bez pole telefonu u doručovací adresy.

= 2.0.0 =
* Napojení na ICECOLD API v1: zakládání zásilek, štítky, webhooky s ověřením podpisu.
* Automatické zakládání podle stavu objednávky, ruční tlačítka v detailu objednávky.
* Sloupec ICECOLD v seznamu objednávek, protokolování požadavků.
* Podpora HPOS (vlastní tabulky objednávek).

= 1.4 =
* Dvě teplotní zóny v jedné zásilce.
