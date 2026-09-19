<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Concerns\ComparesRanges;
use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\ContactInquiryStatus;
use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * New website inquiries in the range, split by type (phase-04 §8.12) — spam excluded, and scoped by
 * `ContactInquiry::visibleTo()` so a reviewer counts only what is assigned to it.
 */
final class NewInquiriesWidget extends Widget
{
    use ComparesRanges;

    public function key(): string
    {
        return 'new_inquiries';
    }

    public function title(): string
    {
        return 'New inquiries';
    }

    public function icon(): string
    {
        return 'inbox-arrow-down';
    }

    public function permission(): ?string
    {
        return 'contact_inquiries.view_any';
    }

    public function module(): ?string
    {
        return 'contact_inquiries';
    }

    public function group(): string
    {
        return WidgetGroup::OPERATIONS;
    }

    public function sort(): int
    {
        return 40;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.contact-inquiries.index', ['tab' => 'new']);
    }

    public function emptyMessage(): ?string
    {
        return 'No new inquiries in this period.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        $previous = $range->previous();

        try {
            $query = ContactInquiry::query()->notSpam()->where('status', ContactInquiryStatus::New->value);
            $user = Auth::user();

            if ($user instanceof User) {
                $query->visibleTo($user);
            }

            $rows = (clone $query)
                ->whereBetween('created_at', [$range->storageStart()->format('Y-m-d H:i:s'), $range->storageEnd()->format('Y-m-d H:i:s')])
                ->selectRaw('inquiry_type, count(*) as total')
                ->groupBy('inquiry_type')
                ->pluck('total', 'inquiry_type');

            $previousTotal = (clone $query)
                ->whereBetween('created_at', [$previous->storageStart()->format('Y-m-d H:i:s'), $previous->storageEnd()->format('Y-m-d H:i:s')])
                ->count();
        } catch (Throwable) {
            return ['available' => false, 'total' => 0, 'types' => [], 'delta' => $this->delta(0, 0), 'range_label' => $range->label()];
        }

        $types = [];

        foreach (InquiryType::cases() as $type) {
            $types[] = [
                'value' => $type->value,
                'label' => $type->label(),
                'color' => $type->color(),
                'count' => (int) ($rows[$type->value] ?? 0),
                'href' => $this->routeUrlWithQuery('admin.contact-inquiries.index', ['tab' => $type->value]),
            ];
        }

        $total = array_sum(array_column($types, 'count'));

        return [
            'available' => true,
            'total' => $total,
            'types' => $types,
            'delta' => $this->delta($total, $previousTotal),
            'range_label' => $range->label(),
            'previous_label' => $previous->label(),
        ];
    }
}
