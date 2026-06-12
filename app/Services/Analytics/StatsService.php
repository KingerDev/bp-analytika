<?php

namespace App\Services\Analytics;

/**
 * Štatistické metódy pre porovnanie segmentov.
 *
 * Mann-Whitneyho U test je neparametrický test zhody distribúcií dvoch nezávislých
 * výberov — vhodný pre hodnoty objednávok, ktoré nemajú normálne rozdelenie.
 * Pri veľkých výberoch (n > 20) sa používa normálna aproximácia U štatistiky.
 */
class StatsService
{
    /**
     * @param  float[]  $a
     * @param  float[]  $b
     * @return array{u: float, z: float, p: float, n1: int, n2: int, median1: float, median2: float}|null
     */
    public function mannWhitney(array $a, array $b): ?array
    {
        $n1 = count($a);
        $n2 = count($b);
        if ($n1 < 5 || $n2 < 5) {
            return null;
        }

        // spoločné poradie s priemernými poradiami pre zhodné hodnoty (ties)
        $combined = [];
        foreach ($a as $v) {
            $combined[] = ['v' => (float) $v, 'g' => 1];
        }
        foreach ($b as $v) {
            $combined[] = ['v' => (float) $v, 'g' => 2];
        }
        usort($combined, fn ($x, $y) => $x['v'] <=> $y['v']);

        $n = $n1 + $n2;
        $ranks = array_fill(0, $n, 0.0);
        $tieCorrection = 0.0;
        $i = 0;
        while ($i < $n) {
            $j = $i;
            while ($j + 1 < $n && $combined[$j + 1]['v'] === $combined[$i]['v']) {
                $j++;
            }
            $avgRank = ($i + $j + 2) / 2; // poradia sú 1-based
            $t = $j - $i + 1;
            if ($t > 1) {
                $tieCorrection += $t ** 3 - $t;
            }
            for ($k = $i; $k <= $j; $k++) {
                $ranks[$k] = $avgRank;
            }
            $i = $j + 1;
        }

        $r1 = 0.0;
        foreach ($combined as $idx => $item) {
            if ($item['g'] === 1) {
                $r1 += $ranks[$idx];
            }
        }

        $u1 = $r1 - $n1 * ($n1 + 1) / 2;
        $u2 = $n1 * $n2 - $u1;
        $u = min($u1, $u2);

        $mu = $n1 * $n2 / 2;
        $sigma = sqrt(($n1 * $n2 / 12) * (($n + 1) - $tieCorrection / ($n * ($n - 1))));
        if ($sigma == 0.0) {
            return null;
        }

        $z = ($u1 - $mu) / $sigma;
        $p = 2 * (1 - $this->normalCdf(abs($z))); // obojstranný test

        return [
            'u' => round($u, 1),
            'z' => round($z, 3),
            'p' => $p,
            'n1' => $n1,
            'n2' => $n2,
            'median1' => $this->median($a),
            'median2' => $this->median($b),
        ];
    }

    public function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Distribučná funkcia N(0,1) cez Abramowitz–Stegunovu aproximáciu erf. */
    protected function normalCdf(float $x): float
    {
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989423 * exp(-$x * $x / 2);
        $prob = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return $x > 0 ? 1 - $prob : $prob;
    }

    /** Formátovanie p-hodnoty pre zobrazenie v práci. */
    public function formatP(float $p): string
    {
        if ($p < 0.001) {
            return 'p < 0,001';
        }

        return 'p = '.number_format($p, 3, ',', '');
    }

    /**
     * Chi-kvadrát test nezávislosti pre kontingenčnú tabuľku (riadky = skupiny,
     * stĺpce = kategórie). P-hodnota cez Wilsonovu–Hilfertyho aproximáciu.
     * Cramérovo V ako miera veľkosti efektu.
     *
     * @param  array<int, array<int, int|float>>  $table
     * @return array{chi2: float, df: int, p: float, p_formatted: string, cramers_v: float, n: int, significant: bool}|null
     */
    public function chiSquare(array $table): ?array
    {
        $rows = count($table);
        $cols = $rows ? count($table[0]) : 0;
        if ($rows < 2 || $cols < 2) {
            return null;
        }

        $rowSums = array_map('array_sum', $table);
        $colSums = array_fill(0, $cols, 0.0);
        foreach ($table as $row) {
            foreach ($row as $j => $value) {
                $colSums[$j] += $value;
            }
        }
        $n = array_sum($rowSums);
        if ($n < 20) {
            return null;
        }

        $chi2 = 0.0;
        foreach ($table as $i => $row) {
            foreach ($row as $j => $observed) {
                $expected = $rowSums[$i] * $colSums[$j] / $n;
                if ($expected > 0) {
                    $chi2 += ($observed - $expected) ** 2 / $expected;
                }
            }
        }

        $df = ($rows - 1) * ($cols - 1);

        // Wilsonova–Hilfertyho aproximácia: (X²/df)^(1/3) ~ N(1 - 2/(9df), 2/(9df))
        $z = ((($chi2 / $df) ** (1 / 3)) - (1 - 2 / (9 * $df))) / sqrt(2 / (9 * $df));
        $p = 1 - $this->normalCdf($z);

        $cramersV = sqrt($chi2 / ($n * min($rows - 1, $cols - 1)));

        return [
            'chi2' => round($chi2, 2),
            'df' => $df,
            'p' => $p,
            'p_formatted' => $this->formatP($p),
            'cramers_v' => round($cramersV, 3),
            'n' => (int) $n,
            'significant' => $p < 0.05,
        ];
    }

    /** Giniho koeficient koncentrácie (0 = rovnomerné, 1 = extrémne koncentrované). */
    public function gini(array $values): ?float
    {
        $values = array_values(array_filter(array_map('floatval', $values), fn ($v) => $v >= 0));
        $n = count($values);
        $total = array_sum($values);
        if ($n < 2 || $total <= 0) {
            return null;
        }
        sort($values);
        $weighted = 0.0;
        foreach ($values as $i => $value) {
            $weighted += ($i + 1) * $value;
        }

        return round((2 * $weighted) / ($n * $total) - ($n + 1) / $n, 3);
    }
}
