<?php

declare(strict_types=1);

namespace App\Console\Commands\Crm;

use App\Enums\LeadStatus;
use App\Models\Crm\Lead;
use App\Models\Scopes\LeadVisibilityScope;
use App\Models\User;
use App\Notifications\Crm\StaleLeadsDigest;
use App\Support\Modules;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `crm:stale-lead-digest` — daily at 09:15, only while `crm.stale_digest_enabled` is on (phase-05 §10.5).
 *
 * One digest per assignee, naming only that assignee's own open leads with no activity for `crm.stale_lead_days`.
 */
#[AsCommand(name: 'crm:stale-lead-digest')]
final class SendStaleLeadDigest extends Command
{
    protected $signature = 'crm:stale-lead-digest';

    protected $description = 'Send each assignee a digest of their stale open leads';

    public function handle(SettingsRepository $settings): int
    {
        if (! filter_var($settings->get('crm.stale_digest_enabled', false), FILTER_VALIDATE_BOOLEAN) || ! Modules::enabled('leads')) {
            $this->info('The stale lead digest is switched off.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $settings->get('crm.stale_lead_days', 7));
        $cutoff = CarbonImmutable::now()->subDays($days);
        $open = array_map(static fn (LeadStatus $status): string => $status->value, LeadStatus::open());

        $stale = static fn () => Lead::query()
            ->withoutGlobalScope(LeadVisibilityScope::class)
            ->whereIn('status', $open)
            ->whereNotNull('assigned_to')
            ->where(static function ($query) use ($cutoff): void {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(static fn ($inner) => $inner->whereNull('last_activity_at')->where('created_at', '<', $cutoff));
            });

        $counts = $stale()->toBase()->selectRaw('assigned_to, COUNT(*) AS c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
        $sent = 0;

        foreach ($counts as $userId => $count) {
            $user = User::query()->active()->find((int) $userId);

            if (! $user instanceof User) {
                continue;
            }

            $leads = $stale()
                ->where('assigned_to', (int) $userId)
                ->orderBy('last_activity_at')
                ->limit(10)
                ->get(['id', 'lead_no', 'name'])
                ->map(static fn (Lead $lead): array => [
                    'id' => (int) $lead->getKey(),
                    'lead_no' => (string) $lead->getAttribute('lead_no'),
                    'name' => (string) $lead->getAttribute('name'),
                ])
                ->all();

            try {
                $user->notify(new StaleLeadsDigest((int) $count, $days, array_values($leads)));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info(sprintf('%d stale lead digest(s) sent.', $sent));

        return self::SUCCESS;
    }
}
