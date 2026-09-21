<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Enums\ExpenseStatus;
use App\Models\Finance\Expense;
use App\Models\User;
use App\Notifications\Finance\StaleExpenseApprovals;
use App\Support\Format;
use App\Support\Modules;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * `expenses:flag-stale-approvals` — weekly, to whoever may actually approve one (phase-13 §10.5).
 *
 * **It never auto-approves and never auto-rejects money.** A claim waiting a fortnight is somebody's
 * problem to look at, not a job's problem to guess at: an auto-approval after fourteen days would turn
 * the approval step into a delay, and an auto-rejection would decide against an employee on a
 * timetable. The command's entire output is a list and a nudge.
 *
 * It goes to holders of `expenses.approve`, because a digest sent to somebody who cannot act on it is
 * noise they will learn to ignore — and the person who can act is exactly the person the queue is
 * waiting on.
 */
#[AsCommand(name: 'expenses:flag-stale-approvals')]
final class FlagStaleExpenseApprovals extends Command
{
    protected $signature = 'expenses:flag-stale-approvals {--days=14 : How long a claim may wait before it is flagged}';

    protected $description = 'Tell the approvers which expense claims have been waiting too long — and decide nothing';

    public function handle(): int
    {
        if (! Modules::enabled('expenses')) {
            $this->info('The expenses module is switched off.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now(Format::timezone())->subDays($days)->startOfDay();

        $stale = Expense::query()
            ->where('status', ExpenseStatus::Pending->value)
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->get(['id', 'expense_no', 'title', 'net_amount', 'created_at']);

        if ($stale->isEmpty()) {
            $this->info(sprintf('Nothing has been waiting more than %d days.', $days));

            return self::SUCCESS;
        }

        $total = Money::sum($stale->map(fn ($e): string => Money::of((string) $e->net_amount))->all());

        $listed = $stale->take(10)->map(static fn (Expense $expense): array => [
            'id' => (int) $expense->getKey(),
            'expense_no' => (string) $expense->expense_no,
            'title' => (string) $expense->title,
            'amount' => (string) $expense->net_amount,
            'days' => (int) $expense->created_at->diffInDays(Carbon::now(Format::timezone())),
        ])->all();

        $approvers = User::query()->active()->get()->filter(
            static fn (User $user): bool => $user->can('expenses.approve'),
        );

        $sent = 0;

        foreach ($approvers as $approver) {
            try {
                $approver->notify(new StaleExpenseApprovals($stale->count(), $total, $days, $listed));
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info(sprintf(
            '%d claim(s) totalling %s have waited more than %d days · %d approver(s) told.',
            $stale->count(),
            Money::format($total),
            $days,
            $sent,
        ));

        return self::SUCCESS;
    }
}
