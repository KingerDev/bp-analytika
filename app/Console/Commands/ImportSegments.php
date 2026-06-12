<?php

namespace App\Console\Commands;

use App\Models\AnaClaritySnapshot;
use App\Services\Analytics\ClarityService;
use App\Services\Etl\B2bImporter;
use App\Services\Etl\B2cImporter;
use Illuminate\Console\Command;

class ImportSegments extends Command
{
    protected $signature = 'analytics:import
        {segment? : b2c, b2b alebo prázdne pre oba}
        {--no-clarity : preskočí sťahovanie Clarity snapshotu}';

    protected $description = 'Importuje dáta zo zdrojových e-shopov do zjednotenej anonymizovanej schémy';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');

        $segment = $this->argument('segment');
        $importers = match ($segment) {
            'b2c' => ['b2c' => new B2cImporter],
            'b2b' => ['b2b' => new B2bImporter],
            null => ['b2c' => new B2cImporter, 'b2b' => new B2bImporter],
            default => null,
        };

        if ($importers === null) {
            $this->error("Neznámy segment '{$segment}'. Použi b2c alebo b2b.");

            return self::FAILURE;
        }

        foreach ($importers as $name => $importer) {
            $this->info("=== Import segmentu {$name} ===");
            $start = microtime(true);
            $stats = $importer->run(fn (string $msg) => $this->line('  '.$msg));
            $took = round(microtime(true) - $start, 1);
            foreach ($stats as $entity => $count) {
                $this->line("  {$entity}: {$count}");
            }
            $this->info("  Hotovo za {$took}s");
        }

        if (! $this->option('no-clarity')) {
            $this->captureClaritySnapshots(array_keys($importers));
        }

        return self::SUCCESS;
    }

    /**
     * Po importe stiahne aj denný Clarity snapshot. Ak dnešný už existuje,
     * preskočí ho — API má limit 10 requestov/projekt/deň (snapshot = 2).
     */
    protected function captureClaritySnapshots(array $segments): void
    {
        $this->info('=== Clarity snapshot ===');
        $clarity = app(ClarityService::class);

        foreach ($segments as $segment) {
            if (AnaClaritySnapshot::where('segment', $segment)->whereDate('captured_on', now())->exists()) {
                $this->line("  {$segment}: dnešný snapshot už existuje, preskakujem (šetrím API limit)");

                continue;
            }

            try {
                $snapshot = $clarity->captureSnapshot($segment);
                $this->line(sprintf(
                    '  %s: %d relácií, %.2f strán/reláciu',
                    $segment, $snapshot->sessions, $snapshot->pages_per_session ?? 0,
                ));
            } catch (\Throwable $e) {
                $this->warn("  {$segment}: {$e->getMessage()} — import tým nie je ovplyvnený");
            }
        }
    }
}
