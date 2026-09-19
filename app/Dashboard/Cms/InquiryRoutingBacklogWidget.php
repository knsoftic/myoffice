<?php

declare(strict_types=1);

namespace App\Dashboard\Cms;

use App\Dashboard\Widget;
use App\Dashboard\WidgetGroup;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquiryType;
use App\Models\Cms\ContactInquiry;
use App\Models\User;
use App\Services\Cms\InquiryRouter;
use App\Support\DateRange;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The routing backlog — inquiries still `pending` or `failed` (phase-04 §8.12): the early warning that
 * leads are piling up because a target module is missing, switched off or failing. A standing total, not
 * range-scoped, broken down by target and by reason.
 */
final class InquiryRoutingBacklogWidget extends Widget
{
    public function key(): string
    {
        return 'inquiry_routing_backlog';
    }

    public function title(): string
    {
        return 'Inquiries awaiting routing';
    }

    public function icon(): string
    {
        return 'arrows-right-left';
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
        return 41;
    }

    public function href(): ?string
    {
        return $this->routeUrlWithQuery('admin.contact-inquiries.index', ['tab' => 'awaiting']);
    }

    public function emptyMessage(): ?string
    {
        return 'Every inquiry has been routed.';
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DateRange $range): array
    {
        try {
            $query = ContactInquiry::query()->awaitingRouting()->reorder();
            $user = Auth::user();

            if ($user instanceof User) {
                $query->visibleTo($user);
            }

            $rows = $query
                ->selectRaw('routing_target, routing_status, routing_error, count(*) as total')
                ->groupBy('routing_target', 'routing_status', 'routing_error')
                ->get();
        } catch (Throwable) {
            return ['available' => false, 'total' => 0, 'failed' => 0, 'targets' => [], 'reasons' => []];
        }

        $router = app(InquiryRouter::class);
        $targets = [];
        $reasons = [];
        $failed = 0;

        foreach ($rows as $row) {
            $count = (int) $row->total;
            $key = (string) ($row->routing_target ?? '');
            $targets[$key] = ($targets[$key] ?? 0) + $count;

            if ((string) $row->routing_status === InquiryRoutingStatus::Failed->value) {
                $failed += $count;
            }

            $probe = new ContactInquiry;
            $probe->setRawAttributes(['routing_target' => $key, 'routing_error' => $row->routing_error]);
            $reason = $router->waitingReason($probe) ?? 'Waiting for a manual Route now';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
        }

        $targetRows = [];

        foreach ($targets as $key => $count) {
            $targetRows[] = [
                'key' => $key,
                'label' => match ($key) {
                    InquiryType::TARGET_CRM_LEAD => 'Awaiting CRM',
                    InquiryType::TARGET_COURSE_INQUIRY => 'Awaiting Institute',
                    default => 'Awaiting routing',
                },
                'count' => $count,
            ];
        }

        arsort($reasons);

        return [
            'available' => true,
            'total' => array_sum($targets),
            'failed' => $failed,
            'targets' => $targetRows,
            'reasons' => array_map(
                static fn (string $reason, int $count): array => ['reason' => $reason, 'count' => $count],
                array_keys($reasons),
                array_values($reasons),
            ),
        ];
    }
}
