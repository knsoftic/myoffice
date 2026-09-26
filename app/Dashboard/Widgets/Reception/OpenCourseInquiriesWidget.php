<?php

declare(strict_types=1);

namespace App\Dashboard\Widgets\Reception;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\CourseInquiryStatus;
use App\Models\Institute\CourseInquiry;
use App\Support\DateRange;
use App\Support\Format;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The follow-up queue: who asked about a course and has not been called back.
 *
 * **Three numbers, and the second is the one that costs money.** An open inquiry is a person who
 * asked. An inquiry whose follow-up date has passed is a person who asked and was promised a call
 * that did not come. An inquiry with zero contact attempts is a person nobody has ever spoken to.
 * The first is a workload, the other two are a lost admission in progress, so they are counted
 * separately rather than rolled into one total that would hide both.
 *
 * **Overdue is counted against the institute's today, not the server's.** A follow-up due "today"
 * that the card judged in UTC would turn red hours early or hours late, and the front desk reads
 * this card beside the list screen that already uses the configured timezone.
 *
 * "Open" comes from `CourseInquiryStatus::isOpen()` — not won, not lost — so this card, the work
 * queue and the stale-inquiry sweep cannot drift apart into three different definitions.
 */
final class OpenCourseInquiriesWidget extends Widget
{
    public function key(): string
    {
        return 'reception_open_inquiries';
    }

    public function title(): string
    {
        return 'Inquiries to chase';
    }

    public function icon(): string
    {
        return 'phone-arrow-up-right';
    }

    public function permission(): ?string
    {
        return 'course_inquiries.view_any';
    }

    public function module(): ?string
    {
        return 'course_inquiries';
    }

    public function group(): string
    {
        return WidgetGroup::FRONT_DESK;
    }

    public function sort(): int
    {
        return 20;
    }

    public function href(): ?string
    {
        return $this->routeUrl('admin.course-inquiries.index');
    }

    public function emptyMessage(): ?string
    {
        return 'Nobody is waiting for a call.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $open = array_map(
                static fn (CourseInquiryStatus $status): string => $status->value,
                array_filter(
                    CourseInquiryStatus::cases(),
                    static fn (CourseInquiryStatus $status): bool => $status->isOpen(),
                ),
            );

            $today = Carbon::now(Format::timezone())->toDateString();

            // **One query, not four.** All four figures are aggregates over the same open rows.
            // `contact_attempts = 0` means zero calls made — the follow-up flow increments it — and
            // not merely "no note was written".
            $row = CourseInquiry::query()
                ->toBase()
                ->whereIn('status', $open)
                ->selectRaw(
                    'COUNT(*) as open_total,'
                    .' SUM(CASE WHEN follow_up_date IS NOT NULL AND follow_up_date < ? THEN 1 ELSE 0 END) as overdue,'
                    .' SUM(CASE WHEN follow_up_date = ? THEN 1 ELSE 0 END) as due_today,'
                    .' SUM(CASE WHEN contact_attempts = 0 THEN 1 ELSE 0 END) as untouched',
                    [$today, $today],
                )
                ->first();
        } catch (Throwable) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'open' => (int) ($row->open_total ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'due_today' => (int) ($row->due_today ?? 0),
            'untouched' => (int) ($row->untouched ?? 0),
        ];
    }
}
