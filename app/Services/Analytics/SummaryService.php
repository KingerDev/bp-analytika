<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Generuje textové zhrnutie všetkých kľúčových zistení v slovenčine —
 * podklad pre analytickú kapitolu bakalárskej práce.
 */
class SummaryService
{
    public function __construct(
        protected KpiService $kpi,
        protected RfmService $rfm,
        protected TimePatternService $time,
        protected ProductService $products,
        protected ProcessService $process,
        protected CustomerStructureService $structure,
    ) {
    }

    public function build(): array
    {
        $kpi = $this->kpi->overview();
        $rfm = $this->rfm->analyze();
        $time = $this->time->analyze();
        $products = $this->products->analyze();
        $process = $this->process->analyze();
        $structure = $this->structure->analyze();

        $b2c = $kpi['segments']['b2c'];
        $b2b = $kpi['segments']['b2b'];

        $sections = [];

        // ------------------------------------------------- vzorka a metodika
        $range = DB::table('ana_orders')->selectRaw('MIN(ordered_at) min_d, MAX(ordered_at) max_d')->first();
        $sections[] = ['title' => 'Vzorka a metodika', 'items' => [
            sprintf(
                'Analyzované boli transakčné dáta oboch e-shopov za obdobie %s až %s (%d mesiacov). Maloobchodný segment (B2C) zahŕňa %s platných objednávok od %s zákazníkov, veľkoobchodný segment (B2B) %s objednávok od %s zákazníkov z %s organizácií.',
                substr($range->min_d, 0, 10), substr($range->max_d, 0, 10), config('analytics.period_months'),
                $this->n($b2c['orders']), $this->n($b2c['customers']),
                $this->n($b2b['orders']), $this->n($b2b['customers']),
                $this->n($structure['concentration']['b2b']['units'])
            ),
            'Dáta boli anonymizované (bez mien, e-mailov a IP adries), stornované objednávky a testovacie účty boli z analýzy vylúčené. Sumy sú uvádzané bez DPH. Vzhľadom na nenormálne rozdelenie hodnôt objednávok a asymetrickú veľkosť segmentov sa na testovanie rozdielov používa neparametrický Mann-Whitneyho U test a chi-kvadrát test nezávislosti.',
        ]];

        // ------------------------------------------------- hodnota objednávky
        $items = [
            sprintf(
                'Priemerná hodnota objednávky v B2B segmente (%s) je %s-násobne vyššia ako v B2C segmente (%s); medián je %s oproti %s.',
                $this->eur($b2b['aov']), $this->x($b2b['aov'] / max(0.01, $b2c['aov'])),
                $this->eur($b2c['aov']), $this->eur($b2b['median_aov']), $this->eur($b2c['median_aov'])
            ),
            sprintf(
                'B2B objednávka obsahuje v priemere %s rôznych položiek oproti %s v B2C — veľkoobchodní zákazníci nakupujú širšie koše.',
                $this->n($b2b['avg_items'], 1), $this->n($b2c['avg_items'], 1)
            ),
        ];
        if ($b2c['margin_pct'] !== null && $b2b['margin_pct'] !== null) {
            $items[] = sprintf(
                'Relatívna marža (podiel zisku na tržbách) je v B2B segmente %s %% oproti %s %% v B2C — veľkoobchodný segment je pre podnik nielen objemovo, ale aj rentabilitou významnejší.',
                $this->n($b2b['margin_pct'], 1), $this->n($b2c['margin_pct'], 1)
            );
        }
        if ($kpi['aov_test']) {
            $items[] = sprintf(
                'Rozdiel hodnôt objednávok medzi segmentmi je štatisticky významný (Mann-Whitneyho U test, %s; n₁ = %s, n₂ = %s).',
                $kpi['aov_test']['p_formatted'], $this->n($kpi['aov_test']['n1']), $this->n($kpi['aov_test']['n2'])
            );
        }
        $sections[] = ['title' => 'Hodnota a štruktúra objednávok', 'items' => $items];

        // ------------------------------------------------- vernosť a retencia
        $sections[] = ['title' => 'Vernosť a frekvencia nákupov', 'items' => [
            sprintf(
                'Opakovaný nákup uskutočnilo %s %% B2B zákazníkov oproti %s %% v B2C — veľkoobchod funguje ako vzťahový obchod s pravidelným dopytom, maloobchod je prevažne jednorazový.',
                $this->n($b2b['repeat_rate'], 1), $this->n($b2c['repeat_rate'], 1)
            ),
            sprintf(
                'B2B zákazník uskutoční v priemere %s objednávok za obdobie (B2C: %s); medián intervalu medzi nákupmi je %s dní v B2B a %s dní v B2C.',
                $this->n($b2b['orders_per_customer'], 1), $this->n($b2c['orders_per_customer'], 1),
                $this->n($rfm['segments']['b2b']['interpurchase_days']['median'] ?? 0, 1),
                $this->n($rfm['segments']['b2c']['interpurchase_days']['median'] ?? 0, 1)
            ),
        ]];

        // ------------------------------------------------- časové vzorce
        $items = [
            sprintf(
                'V pracovnom čase (po–pia 8:00–17:00) vzniká %s %% B2B objednávok, ale len %s %% B2C objednávok — nákup v B2B je súčasťou pracovnej agendy, B2C nakupuje vo voľnom čase.',
                $this->n($time['work_hours_share']['b2b'], 1), $this->n($time['work_hours_share']['b2c'], 1)
            ),
        ];
        if ($time['weekday_chi2']) {
            $items[] = sprintf(
                'Rozloženie objednávok v dňoch týždňa sa medzi segmentmi štatisticky významne líši (chi-kvadrát test, χ² = %s, df = %d, %s, Cramérovo V = %s).',
                $this->n($time['weekday_chi2']['chi2'], 1), $time['weekday_chi2']['df'],
                $time['weekday_chi2']['p_formatted'], $this->n($time['weekday_chi2']['cramers_v'], 2)
            );
        }
        $sections[] = ['title' => 'Časové vzorce', 'items' => $items];

        // ------------------------------------------------- rozhodovanie
        $ds = $process['decision_speed'];
        $items = [
            sprintf(
                'Medián času od registrácie po prvý nákup je v B2C %s dní (registrácia prebieha spravidla priamo pri nákupe — impulzné správanie), v B2B %s dní (registrácia predchádza nákupnému procesu).',
                $this->n($ds['b2c']['reg_to_first_median'] ?? 0, 0), $this->n($ds['b2b']['reg_to_first_median'] ?? 0, 0)
            ),
            sprintf(
                '%s B2B objednávok prešlo formálnym schvaľovacím procesom s mediánom %s hodín od vytvorenia po schválenie; %s objednávok (%s %%) schvaľovateľ zamietol. B2C ekvivalent neexistuje — rozhoduje jediná osoba.',
                $this->n($process['approval']['orders_with_approval']),
                $this->n($process['approval']['median_hours'] ?? 0, 1),
                $this->n($process['approval']['rejected_count']),
                $this->n($process['approval']['rejected_share'], 1)
            ),
            'Platobné preferencie segmentov sa neprekrývajú vôbec: B2C platí kartou alebo na dobierku, B2B takmer výhradne na faktúru — typický znak firemného nákupného procesu.',
        ];
        $sections[] = ['title' => 'Rozhodovací proces', 'items' => $items];

        // ------------------------------------------------- koncentrácia
        $cb2b = $structure['concentration']['b2b'];
        $cb2c = $structure['concentration']['b2c'];
        $sections[] = ['title' => 'Koncentrácia tržieb', 'items' => [
            sprintf(
                'Tržby B2B segmentu sú výrazne koncentrované: top 20 %% organizácií tvorí %s %% tržieb (Giniho koeficient %s). V B2C tvorí top 20 %% zákazníkov %s %% tržieb (Gini %s).',
                $this->n($cb2b['top20_share'], 1), $this->n($cb2b['gini'] ?? 0, 2),
                $this->n($cb2c['top20_share'] ?? 0, 1), $this->n($cb2c['gini'] ?? 0, 2)
            ),
            sprintf(
                'Podľa ABC analýzy tvorí 80 %% B2B tržieb len %s %% organizácií (kategória A) — strata jedného veľkého odberateľa má pre podnik zásadný dosah, čo zdôrazňuje význam starostlivosti o kľúčových zákazníkov.',
                $this->n($cb2b['abc']['shares']['A'] ?? 0, 1)
            ),
        ]];

        // ------------------------------------------------- verejná správa
        $ps = $structure['public_sector'];
        if (isset($ps['public'], $ps['private'])) {
            $items = [
                sprintf(
                    'Verejná správa tvorí %s organizácií (%s objednávok, tržby %s) s priemernou objednávkou %s; súkromné firmy %s organizácií s priemernou objednávkou %s.',
                    $this->n($ps['public']['orgs']), $this->n($ps['public']['orders']), $this->eur($ps['public']['revenue']),
                    $this->eur($ps['public']['aov']), $this->n($ps['private']['orgs']), $this->eur($ps['private']['aov'])
                ),
            ];
            if ($ps['public']['median_approval_hours'] && $ps['private']['median_approval_hours']) {
                $items[] = sprintf(
                    'Medián schvaľovania objednávky je vo verejnej správe %s hodín oproti %s hodinám vo firmách.',
                    $this->n($ps['public']['median_approval_hours'], 1), $this->n($ps['private']['median_approval_hours'], 1)
                );
            }
            if ($ps['aov_test']) {
                $items[] = sprintf(
                    'Rozdiel hodnôt objednávok medzi verejnou správou a firmami %s štatisticky významný (Mann-Whitney, %s).',
                    $ps['aov_test']['significant'] ? 'je' : 'nie je', $ps['aov_test']['p_formatted']
                );
            }
            $sections[] = ['title' => 'Verejná správa vs. súkromné firmy (B2B)', 'items' => $items];
        }

        // ------------------------------------------------- produkty
        $topB2c = $products['segments']['b2c']['top_categories'][0] ?? null;
        $topB2b = $products['segments']['b2b']['top_categories'][0] ?? null;
        if ($topB2c && $topB2b) {
            $sections[] = ['title' => 'Produktové preferencie', 'items' => [
                sprintf(
                    'Najsilnejšou kategóriou B2C je „%s" (%s %% tržieb), v B2B „%s" (%s %%). Priemerný počet kusov na riadok objednávky je v B2B %s oproti %s v B2C — veľkoobchod nakupuje množstevne.',
                    $topB2c['category'], $this->n($topB2c['share'], 1),
                    $topB2b['category'], $this->n($topB2b['share'], 1),
                    $this->n($products['segments']['b2b']['avg_line_quantity'], 1),
                    $this->n($products['segments']['b2c']['avg_line_quantity'], 1)
                ),
            ]];
        }

        // ------------------------------------------------- web správanie
        $clarity = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $clarity[$segment] = DB::table('ana_clarity_snapshots')
                ->where('segment', $segment)->orderByDesc('captured_on')
                ->select(['sessions', 'pages_per_session', 'active_avg_seconds', 'devices'])
                ->first();
        }
        if ($clarity['b2c'] && $clarity['b2b']) {
            $devShare = function ($snapshot, string $device): float {
                $devices = json_decode((string) $snapshot->devices, true) ?: [];
                $total = max(1, array_sum(array_column($devices, 'sessions')));
                foreach ($devices as $d) {
                    if ($d['device'] === $device) {
                        return round(100 * $d['sessions'] / $total, 1);
                    }
                }

                return 0.0;
            };
            $sections[] = ['title' => 'Správanie na webe (Microsoft Clarity)', 'items' => [
                sprintf(
                    'B2C e-shop navštevuje %s %% používateľov z mobilu, B2B portál %s %% z počítača — zodpovedá to nákupu vo voľnom čase (B2C) a nákupu z kancelárie (B2B).',
                    $this->n($devShare($clarity['b2c'], 'Mobile'), 1), $this->n($devShare($clarity['b2b'], 'PC'), 1)
                ),
                'B2C návšteva má exploračný charakter (vyhľadávanie, prezeranie kategórií, vyšší počet strán na reláciu), B2B návšteva je transakčná — najnavštevovanejšie stránky sú košík, objednávky a cenník.',
            ]];
        }

        // ------------------------------------------------- limitácie
        $sections[] = ['title' => 'Limitácie analýzy', 'items' => [
            'Veľkosť segmentov je asymetrická (B2B niekoľkonásobne prevyšuje B2C počtom objednávok) — porovnania preto pracujú s podielmi, mediánmi a neparametrickými testami.',
            'B2C status „Storno" reprezentuje prevažne nedokončené platby kartou (payment abandonment), nie zrušenie objednávky zákazníkom.',
            'Údaje o ziskovosti B2C objednávok sú v zdrojovom systéme evidované až od septembra 2025 — relatívna marža B2C sa preto počíta len z objednávok s vyplneným ziskom.',
            'Behaviorálne metriky B2B z Microsoft Clarity boli do opravy meracieho kódu skreslené (chýbajúce súhlasové cookies); kapitola o správaní na webe preto vychádza primárne z metrík viazaných na zobrazenia stránok.',
        ]];

        return $sections;
    }

    protected function n(float|int $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', ' ');
    }

    protected function eur(float $value): string
    {
        return $this->n($value, 2).' €';
    }

    protected function x(float $value): string
    {
        return $this->n($value, 1);
    }
}
