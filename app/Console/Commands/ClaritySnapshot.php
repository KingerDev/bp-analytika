<?php

namespace App\Console\Commands;

use App\Services\Analytics\ClarityService;
use Illuminate\Console\Command;

class ClaritySnapshot extends Command
{
    protected $signature = 'analytics:clarity-snapshot {segment? : b2c, b2b alebo prázdne pre oba} {--days=3 : počet dní (1–3)}';

    protected $description = 'Stiahne denný snapshot metrík z Microsoft Clarity a archivuje ho do DB';

    public function handle(ClarityService $clarity): int
    {
        $segments = $this->argument('segment') ? [$this->argument('segment')] : ['b2c', 'b2b'];
        $failed = false;

        foreach ($segments as $segment) {
            try {
                $snapshot = $clarity->captureSnapshot($segment, (int) $this->option('days'));
                $this->info(sprintf(
                    '%s: %d relácií, %d používateľov, %.2f strán/reláciu (snapshot %s)',
                    $segment, $snapshot->sessions, $snapshot->users,
                    $snapshot->pages_per_session ?? 0, $snapshot->captured_on->toDateString(),
                ));
            } catch (\Throwable $e) {
                $this->error("{$segment}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
