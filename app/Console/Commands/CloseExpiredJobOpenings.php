<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\JobOpeningService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `careers:close-expired` — close `open` job openings whose deadline has passed (phase-04 §6.8, §10.4).
 *
 * Scheduled daily at 00:10. `JobOpeningService::closeExpired()` stamps `closed_at` and writes one activity
 * entry per opening ("Deadline passed"); an opening without a deadline is never touched.
 */
#[AsCommand(name: 'careers:close-expired')]
final class CloseExpiredJobOpenings extends Command
{
    protected $signature = 'careers:close-expired';

    protected $description = 'Close open job openings whose application deadline has passed';

    public function handle(JobOpeningService $jobs): int
    {
        $count = $jobs->closeExpired();

        $this->info(sprintf('closed %d job opening(s)', $count));

        return self::SUCCESS;
    }
}
