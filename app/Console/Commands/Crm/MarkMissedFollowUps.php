<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Models\Crm\LeadFollowUp;
use App\Services\Crm\LeadFollowUpService;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `crm:follow-ups-mark-missed` — hourly (phase-05 §10.5, test 30).
 *
 * `pending` follow-ups whose `scheduled_at` is older than `crm.follow_up_overdue_grace_minutes` become `missed`: one
 * `follow_up_missed` timeline row each and one `LeadFollowUpOverdue` to the assignee. Each follow-up is its own
 * transaction under a row lock, so a row completed by a person a moment earlier is left alone.
 */
#[AsCommand(name: 'crm:follow-ups-mark-missed')]
final class MarkMissedFollowUps extends Command
{
    protected $signature = 'crm:follow-ups-mark-missed {--limit=500 : Maximum follow-ups handled in this run}';

    protected $description = 'Mark pending follow-ups past their grace window as missed';

    public function handle(LeadFollowUpService $followUps): int
    {
        if (! Modules::enabled('leads')) {
            $this->info('The leads module is disabled: nothing marked.');

            return self::SUCCESS;
        }

        $limit = max(1, min(5000, (int) $this->option('limit')));
        $marked = 0;

        foreach ($followUps->overdueIds(CarbonImmutable::now(), $limit) as $id) {
            $followUp = LeadFollowUp::query()->find($id);

            if (! $followUp instanceof LeadFollowUp) {
                continue;
            }

            try {
                if ($followUps->markMissed($followUp)) {
                    $marked++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error(sprintf('Follow-up #%d could not be marked: %s', $id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%d follow-up(s) marked missed.', $marked));

        return self::SUCCESS;
    }
}
