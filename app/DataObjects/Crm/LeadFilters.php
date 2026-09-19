<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\InquirySource;
use App\Enums\LeadStatus;
use App\Support\ContactNormalizer;
use App\Support\DateRange;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The lead list / board / export filter set (phase-05 §8.1, §8.2, §6.10 `LeadExporter`).
 *
 * `apply()` narrows a query that already carries the §9 visibility scope (`LeadVisibilityScope`); it never
 * widens it and it never reads an owner from the request — "Me" is the actor id handed in by the service.
 * Relative follow-up windows ("today", "next 7 days") are computed in the display timezone and queried in UTC
 * boundaries (D61).
 */
readonly class LeadFilters
{
    use ReadsInput;

    public const ASSIGNEE_ME = 'me';

    public const ASSIGNEE_UNASSIGNED = 'unassigned';

    public const FOLLOW_UP_WINDOWS = ['overdue', 'today', 'week', 'none'];

    /**
     * @param  list<LeadStatus>  $statuses
     * @param  list<InquirySource>  $sources
     * @param  int|string|null  $assignee  a user id, `me` or `unassigned`
     */
    public function __construct(
        public ?string $search = null,
        public array $statuses = [],
        public array $sources = [],
        public int|string|null $assignee = null,
        public ?string $followUp = null,
        public ?DateRange $created = null,
        public ?string $budgetMin = null,
        public ?string $budgetMax = null,
        public ?bool $hasDuplicate = null,
        public ?bool $converted = null,
        public bool $trashed = false,
        public ?int $serviceId = null,
    ) {}

    /**
     * Keys: `q`, `status[]`, `source[]`, `assignee`, `follow_up`, `created_preset` / `created_from` /
     * `created_to`, `budget_min`, `budget_max`, `has_duplicate`, `converted`, `trashed`, `service_id`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $statuses = [];

        foreach (self::strings($data, 'status') as $value) {
            $status = LeadStatus::tryFrom($value);

            if ($status instanceof LeadStatus) {
                $statuses[] = $status;
            }
        }

        $sources = [];

        foreach (self::strings($data, 'source') as $value) {
            $source = InquirySource::tryFrom($value);

            if ($source instanceof InquirySource) {
                $sources[] = $source;
            }
        }

        $assignee = self::str($data, 'assignee', 32);

        if ($assignee !== null && ! in_array($assignee, [self::ASSIGNEE_ME, self::ASSIGNEE_UNASSIGNED], true)) {
            $assignee = ctype_digit($assignee) ? (int) $assignee : null;
        }

        $followUp = self::str($data, 'follow_up', 16);

        return new static(
            search: self::str($data, 'q', 150) ?? self::str($data, 'search', 150),
            statuses: $statuses,
            sources: $sources,
            assignee: $assignee,
            followUp: in_array($followUp, self::FOLLOW_UP_WINDOWS, true) ? $followUp : null,
            created: self::range($data),
            budgetMin: self::money($data, 'budget_min'),
            budgetMax: self::money($data, 'budget_max'),
            hasDuplicate: self::nullableBool($data, 'has_duplicate'),
            converted: self::nullableBool($data, 'converted'),
            trashed: self::bool($data, 'trashed'),
            serviceId: self::int($data, 'service_id'),
        );
    }

    /**
     * Narrow `$query` (a `Lead` query) by every filter set here.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  bool  $withStatuses  false for the board, which partitions by status itself
     * @return Builder<TModel>
     */
    public function apply(Builder $query, ?int $actorId, bool $withStatuses = true): Builder
    {
        if ($this->trashed) {
            $query->onlyTrashed();
        }

        if ($this->search !== null) {
            $this->applySearch($query, $this->search);
        }

        if ($withStatuses && $this->statuses !== []) {
            $query->whereIn($query->qualifyColumn('status'), array_map(static fn (LeadStatus $s): string => $s->value, $this->statuses));
        }

        if ($this->sources !== []) {
            $query->whereIn($query->qualifyColumn('source'), array_map(static fn (InquirySource $s): string => $s->value, $this->sources));
        }

        if ($this->assignee === self::ASSIGNEE_UNASSIGNED) {
            $query->whereNull($query->qualifyColumn('assigned_to'));
        } elseif ($this->assignee === self::ASSIGNEE_ME) {
            $query->where($query->qualifyColumn('assigned_to'), $actorId ?? 0);
        } elseif (is_int($this->assignee)) {
            $query->where($query->qualifyColumn('assigned_to'), $this->assignee);
        }

        if ($this->serviceId !== null) {
            $query->where($query->qualifyColumn('service_id'), $this->serviceId);
        }

        $this->applyFollowUpWindow($query);

        if ($this->created instanceof DateRange) {
            $this->created->apply($query, $query->qualifyColumn('created_at'));
        }

        if ($this->budgetMin !== null) {
            $query->where($query->qualifyColumn('budget_amount'), '>=', $this->budgetMin);
        }

        if ($this->budgetMax !== null) {
            $query->where($query->qualifyColumn('budget_amount'), '<=', $this->budgetMax);
        }

        if ($this->hasDuplicate !== null) {
            $this->hasDuplicate
                ? $query->whereNotNull($query->qualifyColumn('duplicate_of_lead_id'))
                : $query->whereNull($query->qualifyColumn('duplicate_of_lead_id'));
        }

        if ($this->converted !== null) {
            $this->converted
                ? $query->whereNotNull($query->qualifyColumn('converted_at'))
                : $query->whereNull($query->qualifyColumn('converted_at'));
        }

        return $query;
    }

    /**
     * The filter set as query-string parameters (pagination links, "load more", the export button).
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->search,
            'status' => array_map(static fn (LeadStatus $s): string => $s->value, $this->statuses),
            'source' => array_map(static fn (InquirySource $s): string => $s->value, $this->sources),
            'assignee' => $this->assignee,
            'follow_up' => $this->followUp,
            'budget_min' => $this->budgetMin,
            'budget_max' => $this->budgetMax,
            'has_duplicate' => $this->hasDuplicate === null ? null : (int) $this->hasDuplicate,
            'converted' => $this->converted === null ? null : (int) $this->converted,
            'trashed' => $this->trashed ? 1 : null,
            'service_id' => $this->serviceId,
            'created_preset' => $this->created?->preset(),
            'created_from' => $this->created?->isCustom() ? $this->created->start()->toDateString() : null,
            'created_to' => $this->created?->isCustom() ? $this->created->end()->toDateString() : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';
        $phoneKey = strlen($digits) >= 5 ? ContactNormalizer::phone($term, null) : null;
        $email = str_contains($term, '@') ? ContactNormalizer::email($term) : null;

        $query->where(function (Builder $inner) use ($like, $phoneKey, $email): void {
            $inner->where($inner->qualifyColumn('lead_no'), 'like', $like)
                ->orWhere($inner->qualifyColumn('name'), 'like', $like)
                ->orWhere($inner->qualifyColumn('company'), 'like', $like)
                ->orWhere($inner->qualifyColumn('email'), 'like', $like);

            if ($phoneKey !== null) {
                $inner->orWhere($inner->qualifyColumn('phone_normalized'), $phoneKey)
                    ->orWhere($inner->qualifyColumn('whatsapp_normalized'), $phoneKey);
            }

            if ($email !== null) {
                $inner->orWhere($inner->qualifyColumn('email_normalized'), $email);
            }
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyFollowUpWindow(Builder $query): void
    {
        if ($this->followUp === null) {
            return;
        }

        $column = $query->qualifyColumn('follow_up_at');
        $zone = Format::displayTimezone();
        $now = CarbonImmutable::now();
        $startOfToday = $now->setTimezone($zone)->startOfDay()->utc();
        $endOfToday = $now->setTimezone($zone)->endOfDay()->utc();

        match ($this->followUp) {
            'overdue' => $query->whereNotNull($column)->where($column, '<', $now),
            'today' => $query->whereBetween($column, [$startOfToday, $endOfToday]),
            'week' => $query->whereBetween($column, [$now, $now->setTimezone($zone)->addDays(7)->endOfDay()->utc()]),
            'none' => $query->whereNull($column),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function range(array $data): ?DateRange
    {
        $preset = self::str($data, 'created_preset', 32);
        $from = self::str($data, 'created_from', 32);
        $to = self::str($data, 'created_to', 32);

        if ($preset === null && $from === null && $to === null) {
            return null;
        }

        try {
            return DateRange::make($preset ?? ($from !== null || $to !== null ? 'custom' : null), $from, $to);
        } catch (Throwable) {
            return null;
        }
    }
}
