<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

class TimePatternService
{
    public function __construct(protected StatsService $stats)
    {
    }

    public function analyze(): array
    {
        return [
            'hourly' => $this->byHour(),
            'weekday' => $this->byWeekday(),
            'monthly_seasonality' => $this->byCalendarMonth(),
            'work_hours_share' => $this->workHoursShare(),
            'weekday_chi2' => $this->weekdayChiSquare(),
        ];
    }

    /** Chi-kvadrát test: závisí rozloženie objednávok v týždni od segmentu? */
    protected function weekdayChiSquare(): ?array
    {
        $table = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $rows = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)
                ->selectRaw('WEEKDAY(ordered_at) d, COUNT(*) c')
                ->groupBy('d')->pluck('c', 'd');
            $table[] = array_map(fn ($d) => (int) ($rows[$d] ?? 0), range(0, 6));
        }

        return $this->stats->chiSquare($table);
    }

    /** Podiel objednávok podľa hodiny dňa (v %, aby boli segmenty porovnateľné). */
    protected function byHour(): array
    {
        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $rows = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)
                ->selectRaw('HOUR(ordered_at) h, COUNT(*) c')
                ->groupBy('h')->orderBy('h')
                ->pluck('c', 'h');
            $total = max(1, $rows->sum());
            $series[$segment] = array_map(
                fn ($h) => round(100 * ($rows[$h] ?? 0) / $total, 2),
                range(0, 23)
            );
        }

        return ['labels' => array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23)), 'series' => $series];
    }

    protected function byWeekday(): array
    {
        $labels = ['Pondelok', 'Utorok', 'Streda', 'Štvrtok', 'Piatok', 'Sobota', 'Nedeľa'];
        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            // WEEKDAY(): 0 = pondelok … 6 = nedeľa
            $rows = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)
                ->selectRaw('WEEKDAY(ordered_at) d, COUNT(*) c')
                ->groupBy('d')->orderBy('d')
                ->pluck('c', 'd');
            $total = max(1, $rows->sum());
            $series[$segment] = array_map(
                fn ($d) => round(100 * ($rows[$d] ?? 0) / $total, 2),
                range(0, 6)
            );
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Sezónnosť: podiel objednávok podľa kalendárneho mesiaca. */
    protected function byCalendarMonth(): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $rows = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)
                ->selectRaw('MONTH(ordered_at) m, COUNT(*) c')
                ->groupBy('m')->orderBy('m')
                ->pluck('c', 'm');
            $total = max(1, $rows->sum());
            $series[$segment] = array_map(
                fn ($m) => round(100 * ($rows[$m] ?? 0) / $total, 2),
                range(1, 12)
            );
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Podiel objednávok v pracovnom čase (po–pia 8:00–16:59). */
    protected function workHoursShare(): array
    {
        $out = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $base = DB::table('ana_orders')->where('segment', $segment)->where('is_cancelled', false);
            $total = (clone $base)->count();
            $work = (clone $base)
                ->whereRaw('WEEKDAY(ordered_at) < 5')
                ->whereRaw('HOUR(ordered_at) BETWEEN 8 AND 16')
                ->count();
            $out[$segment] = $total ? round(100 * $work / $total, 1) : 0;
        }

        return $out;
    }
}
