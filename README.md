# BP Analytika

Analytická aplikácia k bakalárskej práci **„Nákupné správanie zákazníkov kancelárskeho e-shopu:
porovnanie maloobchodného a veľkoobchodného segmentu"**.

Porovnáva dva reálne e-shopy jednej firmy s kancelárskymi potrebami:

| Segment | Zdrojový systém | Zdrojová DB |
|---|---|---|
| **B2C – maloobchod** | titi e-shop (Laravel) | `titi` (produkcia cez SSH tunel) |
| **B2B – veľkoobchod** | tsv e-shop (CodeIgniter) | `tsv` (produkcia cez SSH tunel) |

## Architektúra

- **VILT stack**: Laravel 12, Vue 3, Inertia 2, Tailwind CSS
- **Grafy**: ApexCharts (toolbar každého grafu umožňuje export PNG/SVG/CSV pre prácu)
- **Tabuľky**: každá má tlačidlo „⬇ CSV" (oddeľovač `;`, kompatibilné so slovenským Excelom)
- **Vlastná DB** `bp_analytika`: zjednotená anonymizovaná schéma `ana_*`
  (customers, orders, order_items, products, cart_items), všetko so stĺpcom `segment` (`b2c`/`b2b`)

## Anonymizácia (GDPR)

ETL **neimportuje žiadne osobné údaje** — mená, e-maily, telefóny ani IP adresy.
Zákazník dostáva anonymný kód (`B2C-000123` / `B2B-000456`). Z adresy sa preberá len mesto
(analýza regiónov), z organizácie len kategória veľkosti, príznak verejnej správy a cenová hladina.

## Spustenie

```bash
composer install && npm install
php artisan migrate
npm run dev          # alebo npm run build
php artisan serve
```

## ETL import dát

1. V samostatnom termináli otvor SSH tunel k produkčnej DB:
   ```bash
   python3 scripts/tunnel.py
   ```
2. Spusti import (oba segmenty, posledných 24 mesiacov — viď `config/analytics.php`):
   ```bash
   php artisan analytics:import        # oba segmenty
   php artisan analytics:import b2c    # len maloobchod
   php artisan analytics:import b2b    # len veľkoobchod
   ```

Import je idempotentný (`updateOrCreate` / delete+insert), možno ho kedykoľvek zopakovať —
dashboardy potom ukazujú čerstvý snapshot. Dátum importu sa zobrazuje v pätičke sidebaru.

Po importe sa automaticky stiahne aj denný **Clarity snapshot** (ak dnešný ešte neexistuje —
ochrana API limitu pri opakovaných importoch). Preskočiť: `--no-clarity`.

Alternatívny B2C zdroj bez tunela: lokálna `titi-dev` (v `.env` prepni `SRC_TITI_*` na
host `127.0.0.1`, port `3306`, db `titi-dev`, user `root`).

## Dashboardy

| URL | Obsah |
|---|---|
| `/prehlad` | KPI porovnanie (objednávky, tržby, AOV, medián, retencia, stornovanosť), mesačný vývoj, histogram hodnôt objednávok, **Mann-Whitneyho U test** rozdielu hodnôt objednávok |
| `/rfm` | RFM segmentácia (kvintily, šampióni → stratení), frekvencia nákupov, interval medzi nákupmi, kvartálne kohortové retenčné tabuľky |
| `/casove-vzorce` | Hodina dňa, deň v týždni, sezónnosť, podiel nákupov v pracovnom čase |
| `/produkty` | Top kategórie a produkty podľa tržieb, šírka sortimentu na zákazníka, kusy na riadok, darčekové položky |
| `/nakupny-proces` | Platobné a dopravné preferencie, zľavy, **B2B schvaľovací workflow** (dĺžka schvaľovania, zamietnutia, roly v nákupnom centre), B2C kanál web/app, košíky |
| `/zakaznici` | **Koncentrácia tržieb** (Lorenzova krivka, Gini, ABC), verejná správa vs. firmy, organizácie a nákupné centrá B2B |
| `/heatmapy` | Manuálne exporty heatmáp z Clarity (PNG + CSV) — párovanie B2C vs B2B podľa stránky, porovnávacie scroll krivky z CSV |
| `/clarity` | Behaviorálne dáta z Microsoft Clarity: relácie, zariadenia, engagement, scroll, rage/dead clicks (na 1 000 relácií), top stránky, trendy z archivovaných snapshotov |
| `/zhrnutie` | **Automaticky generovaný textový sumár** všetkých zistení v slovenčine (podklad pre analytickú kapitolu), tlačiteľný do PDF |

## Metodické poznámky pre BP

- Obdobie: posledných **24 mesiacov**, rovnaké pre oba segmenty (`config/analytics.php → period_months`).
- Stornované objednávky sú vylúčené zo všetkých metrík okrem stornovanosti a prehľadu stavov
  (B2C: status „Storno"; B2B: „Zamietnutá schvaľovateľom" a „Zrušená" — `cancelled_status_ids`).
- B2C „Storno" v praxi znamená **nedokončenú online platbu kartou** — všetkých 133 storien
  v dátach má platobnú metódu `card`, dobierky majú stornovanosť 0 %. V práci ho preto
  interpretuj ako opustenie platobného kroku (payment abandonment), nie ako zrušenie objednávky.
- Testovacie účty B2C sú vylúčené už pri importe
  (`config/analytics.php → excluded_customer_source_ids`).
- Veľkosť vzoriek je asymetrická (B2B ≫ B2C) — preto sa časové a distribučné grafy zobrazujú
  v **percentuálnych podieloch** a na testovanie rozdielov sa používa neparametrický
  **Mann-Whitneyho U test** (hodnoty objednávok nemajú normálne rozdelenie).
- Sumy sú **bez DPH** (stĺpec `total_net`), aby boli segmenty porovnateľné (B2B ceny sa
  komunikujú bez DPH, B2C s DPH).
- Dĺžka rozhodovacieho procesu B2B = čas od vytvorenia objednávky (`date_added`) po jej
  schválenie schvaľovateľom (`date_approved`).
- **Marža B2C je počítaná len z objednávok od ~09/2025** — staršie objednávky nemajú v zdrojovom
  systéme zaznamenaný zisk (hodnota 0/NULL sa pri importe považuje za chýbajúci údaj).
- Kategorické rozdiely (platby, dni v týždni) sa testujú **chi-kvadrát testom** (p-hodnota cez
  Wilsonovu–Hilfertyho aproximáciu) s Cramérovým V; koncentrácia tržieb **Giniho koeficientom**
  a ABC analýzou (B2B na úrovni organizácií, B2C zákazníkov).

## Clarity snapshoty

Clarity Data Export API vracia len posledné 1–3 dni (limit 10 requestov/projekt/deň),
preto sa metriky archivujú lokálne:

```bash
php artisan analytics:clarity-snapshot   # 2 requesty na projekt (zariadenia + URL)
```

Spúšťaj raz denne. Na shared hostingu (Hostinger) pridaj cron job:

```
0 20 * * * cd /cesta/k/bp-analytika && php artisan analytics:clarity-snapshot >> storage/logs/clarity-cron.log 2>&1
```

Každý beh (úspešný aj neúspešný) sa loguje do `ana_snapshot_runs` — na stránke `/clarity`
je sekcia **História sťahovania snapshotov** so zdravotným indikátorom per segment.
Ak je posledný úspešný snapshot starší ako 2 dni, stránka zobrazí červenú výstrahu
(API vidí len 3 dni dozadu, dlhší výpadok = nenahraditeľná diera v trendoch).
Stránka `/clarity` číta výhradne z DB; z denných snapshotov sa časom skladajú trendy.

## Heatmapy

Clarity API heatmapy neposkytuje — exportujú sa manuálne z Clarity UI
(Heatmaps → vybrať stránku/typ/zariadenie → Download PNG aj CSV) a nahrávajú na `/heatmapy`.
Rovnaký „párovací názov" stránky pre B2C aj B2B verziu ich zobrazí vedľa seba; zo scroll CSV
sa automaticky kreslí porovnávacia krivka hĺbky scrollovania. Heatmapy sa viažu na zobrazenia
stránok (nie relácie), takže B2B heatmapy sú validné aj pred opravou cookie trackingu.

## MCP (analýza cez Claude Code)

`.mcp.json` obsahuje tri servery:

- **tsv-mysql** — priamy SQL prístup k produkčným DB `tsv`, `titi`, `naklady` cez SSH tunel
  (rovnaká konfigurácia ako v TSV-PORTAL-Deploy)
- **clarity-b2c / clarity-b2b** — Microsoft Clarity Data Export API; v `args` doplň tokeny
  (Clarity → Settings → Data export → Generate new API token) a rovnaké tokeny vlož aj do
  `.env` (`CLARITY_B2C_TOKEN`, `CLARITY_B2B_TOKEN`), aby stránka `/clarity` vedela, že sú nastavené
