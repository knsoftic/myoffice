<?php

declare(strict_types=1);

namespace App\Console\Commands\Collaborator;

use App\Enums\CommissionRuleStatus;
use App\Models\Collaborator\CollaboratorCommissionSetting;
use App\Support\Format;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `commission-rules:activate` — move rule versions through the two transitions a clock owns
 * (spine §2.18.5, §10.4).
 *
 * A rate agreed in March to start in April is saved as `scheduled`. A version whose `effective_to` has
 * passed with nothing after it is `expired`. Neither transition has an actor: the date arrives, and the
 * status should say so.
 *
 * **Nothing here changes what a version means.** `status` is a label about where a version sits in
 * time, and `CollaboratorCommissionSetting::coversDate()` — the thing the engine actually asks —
 * answers from the dates, not from the label. So a missed run delays a badge on a screen, never a
 * commission: a scheduled version whose date has come already governs payments, whether or not this
 * command has caught up with it.
 */
#[AsCommand(name: 'commission-rules:activate')]
final class ActivateCommissionRules extends Command
{
    protected $signature = 'commission-rules:activate';

    protected $description = 'Promote scheduled commission rule versions and expire finished ones';

    public function handle(): int
    {
        $today = Carbon::now(Format::timezone())->startOfDay()->toDateString();

        $activated = $this->move(
            CollaboratorCommissionSetting::query()
                ->where('status', CommissionRuleStatus::Scheduled->value)
                ->whereDate('effective_from', '<=', $today),
            CommissionRuleStatus::Active,
        );

        // Expired only when nothing succeeded it: a version closed *by* a successor is `superseded`,
        // which is a different fact and reads differently on the timeline.
        $expired = $this->move(
            CollaboratorCommissionSetting::query()
                ->where('status', CommissionRuleStatus::Active->value)
                ->whereNotNull('effective_to')
                ->whereDate('effective_to', '<', $today),
            CommissionRuleStatus::Expired,
        );

        $this->info(sprintf('%d version(s) activated, %d expired', $activated, $expired));

        return self::SUCCESS;
    }

    private function move(Builder $query, CommissionRuleStatus $to): int
    {
        $rules = $query->orderBy('id')->get();
        $moved = 0;

        foreach ($rules as $rule) {
            CollaboratorCommissionSetting::allowDirectWrites(static function () use ($rule, $to): void {
                $rule->forceFill(['status' => $to->value])->save();
            });

            $moved++;
        }

        return $moved;
    }
}
