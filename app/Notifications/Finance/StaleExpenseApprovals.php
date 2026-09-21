<?php

declare(strict_types=1);

namespace App\Notifications\Finance;

use App\Notifications\Crm\Concerns\BuildsCrmNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The weekly digest of expense claims nobody has decided (phase-13 §10.5).
 *
 * It goes to whoever may actually approve one, and it **never approves or rejects anything**. Money
 * waiting on a decision is somebody's problem to look at, not a job's problem to guess at: an
 * auto-approval after fourteen days would turn the approval step into a delay.
 */
final class StaleExpenseApprovals extends Notification
{
    use BuildsCrmNotification;

    /**
     * @param  list<array{id: int, expense_no: string, title: string, amount: string, days: int}>  $expenses
     */
    public function __construct(
        public readonly int $count,
        public readonly string $total,
        public readonly int $staleDays,
        public readonly array $expenses,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->crmChannels($notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf('%d expense claim(s) waiting more than %d days', $this->count, $this->staleDays))
            ->greeting('Claims waiting on a decision')
            ->line(sprintf(
                'They total %s and none of it counts in a report until somebody agrees it.',
                money($this->total),
            ));

        foreach ($this->expenses as $expense) {
            $mail->line(sprintf(
                '%s — %s · %s · waiting %d days',
                $expense['expense_no'],
                $expense['title'],
                money($expense['amount']),
                $expense['days'],
            ));
        }

        return $mail->action('Open the approval queue', $this->link('admin.expenses.approvals', [], '/admin/expenses/approvals'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'expense.stale_approvals',
            'module' => 'expenses',
            'title' => sprintf('%d expense claim(s) waiting', $this->count),
            'body' => sprintf('Waiting more than %d days, totalling %s.', $this->staleDays, money($this->total)),
            'expenses' => $this->expenses,
            'url' => $this->link('admin.expenses.approvals', [], '/admin/expenses/approvals'),
        ];
    }
}
