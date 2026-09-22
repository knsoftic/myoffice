<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Institute;

use App\Enums\InstallmentStatus;
use App\Enums\ReceivedPaymentStatus;
use App\Enums\StudentFeeStatus;
use App\Http\Controllers\Controller;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\StudentFee;
use App\Models\Institute\StudentFeeInstallment;
use App\Models\Institute\StudentFeePayment;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The cashier's worklist — `admin.fee-collection.index` (phase-18 §8.8, §68, §88).
 *
 * **It reads and never writes.** Collecting happens through the spine's record-payment modal, which
 * this screen embeds; the desk's job is to say who owes what today, and the money path stays the one
 * path.
 *
 * The four tabs are what a cashier's morning actually looks like: what is due today, what is coming
 * this week, what is late, and who is in credit. **Advances are a tab rather than a negative number
 * folded into the totals** — a desk shown "outstanding 200,000" when 40,000 of it is somebody else's
 * credit balance will chase the wrong students.
 */
final class FeeCollectionController extends Controller
{
    public function index(Request $request): View
    {
        $tab = in_array($request->input('tab'), ['today', 'upcoming', 'overdue', 'advances'], true)
            ? (string) $request->input('tab')
            : 'today';

        return view('admin.fee-collection.index', [
            'tab' => $tab,
            'rows' => $this->rowsFor($tab, $request)->paginate(per_page())->withQueryString(),
            'stats' => $this->stats($request),
            'pickers' => $this->pickers($request),
            'filters' => $request->only(['q', 'course_id', 'batch_id', 'fee_type', 'overdue_bucket']),
        ]);
    }

    /**
     * Each tab is a query over the **installment lines** where a plan exists, and over the charge
     * itself where one does not — because "what is due on Thursday" is a question about a line, and a
     * charge payable in full is its own single line.
     *
     * @return Builder<StudentFeeInstallment>|Builder<StudentFee>
     */
    private function rowsFor(string $tab, Request $request): Builder
    {
        $today = Carbon::today();

        if ($tab === 'advances') {
            return $this->scopedCharges($request)
                ->where('balance_amount', '<', 0)
                ->orderBy('balance_amount');
        }

        $lines = StudentFeeInstallment::query()
            ->with(['fee.student:id,name,student_code', 'fee.course:id,name', 'fee.batch:id,code'])
            ->whereIn('status', [
                InstallmentStatus::Pending->value,
                InstallmentStatus::Partial->value,
                InstallmentStatus::Overdue->value,
            ])
            ->whereRaw('(`amount` - `paid_amount` - `waived_amount`) > 0')
            ->whereIn('student_fee_id', $this->scopedCharges($request)->select('id'));

        return match ($tab) {
            'upcoming' => $lines
                ->whereDate('due_date', '>', $today->toDateString())
                ->whereDate('due_date', '<=', $today->copy()->addDays(7)->toDateString())
                ->orderBy('due_date'),
            'overdue' => $lines
                ->whereDate('due_date', '<', $today->toDateString())
                ->when($request->filled('overdue_bucket'), function ($q) use ($request, $today): void {
                    [$min, $max] = match ($request->string('overdue_bucket')->value()) {
                        '1-7' => [1, 7],
                        '8-30' => [8, 30],
                        default => [31, 36500],
                    };

                    $q->whereDate('due_date', '<=', $today->copy()->subDays($min)->toDateString())
                        ->whereDate('due_date', '>=', $today->copy()->subDays($max)->toDateString());
                })
                ->orderBy('due_date'),
            default => $lines->whereDate('due_date', $today->toDateString())->orderBy('due_date'),
        };
    }

    /**
     * The three stat cards: what is expected today, what has actually come in today, and the whole
     * overdue pile.
     *
     * @return array<string, string>
     */
    private function stats(Request $request): array
    {
        $today = Carbon::today();

        $expected = StudentFeeInstallment::query()
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::Partial->value, InstallmentStatus::Overdue->value])
            ->whereDate('due_date', $today->toDateString())
            ->whereIn('student_fee_id', $this->scopedCharges($request)->select('id'))
            ->get(['amount', 'paid_amount', 'waived_amount']);

        // `net_received_amount` is generated (`amount - refunded_amount`), so a refund taken today
        // cannot be forgotten by summing the wrong column.
        $collected = StudentFeePayment::query()
            ->whereNot('status', ReceivedPaymentStatus::Voided->value)
            ->whereDate('paid_on', $today->toDateString())
            ->whereIn('student_fee_id', $this->scopedCharges($request)->select('id'))
            ->get(['net_received_amount']);

        $overdue = $this->scopedCharges($request)
            ->where('status', StudentFeeStatus::Overdue->value)
            ->where('balance_amount', '>', 0)
            ->get(['balance_amount']);

        return [
            'expected_today' => Money::sum($expected
                ->map(static fn (StudentFeeInstallment $l): string => Money::sub(
                    Money::sub((string) $l->amount, (string) $l->paid_amount),
                    (string) $l->waived_amount,
                ))->all()),
            'collected_today' => Money::sum($collected->map(static fn ($p): string => (string) $p->net_received_amount)->all()),
            'collected_count' => (string) $collected->count(),
            'overdue_total' => Money::sum($overdue->map(static fn (StudentFee $c): string => (string) $c->balance_amount)->all()),
            'overdue_count' => (string) $overdue->count(),
        ];
    }

    /**
     * The branch and filter narrowing every tab and every stat shares, so a card can never describe a
     * different set of rows from the table under it.
     *
     * @return Builder<StudentFee>
     */
    private function scopedCharges(Request $request): Builder
    {
        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        return StudentFee::query()
            ->whereNot('status', StudentFeeStatus::Cancelled->value)
            ->when($branch !== null, fn ($q) => $q->where(
                fn ($inner) => $inner->whereNull('branch_id')->orWhere('branch_id', $branch),
            ))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->integer('course_id')))
            ->when($request->filled('batch_id'), fn ($q) => $q->where('batch_id', $request->integer('batch_id')))
            ->when($request->filled('fee_type'), fn ($q) => $q->where('fee_type', $request->string('fee_type')));
    }

    /**
     * @return array<string, Collection<int, string>>
     */
    private function pickers(Request $request): array
    {
        $branch = $request->user()?->branch_id === null ? null : (int) $request->user()->branch_id;

        return [
            'courses' => Course::query()->orderBy('name')->pluck('name', 'id'),
            'batches' => Batch::query()->forBranch($branch)->orderBy('code')->pluck('code', 'id'),
        ];
    }
}
