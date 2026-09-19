<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\ClientStatus;
use App\Support\ContactNormalizer;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * The clients index / export filter set (phase-05 §8.7, §6.10 `ClientExporter`).
 */
final readonly class ClientFilters
{
    use ReadsInput;

    /**
     * @param  list<ClientStatus>  $statuses
     */
    public function __construct(
        public ?string $search = null,
        public array $statuses = [],
        public ?int $accountManagerId = null,
        public ?string $country = null,
        public ?bool $portalEnabled = null,
        public ?DateRange $created = null,
        public bool $trashed = false,
    ) {}

    /**
     * Keys: `q`, `status[]`, `account_manager_id`, `country`, `portal_enabled`, `created_preset` /
     * `created_from` / `created_to`, `trashed`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $statuses = [];

        foreach (self::strings($data, 'status') as $value) {
            $status = ClientStatus::tryFrom($value);

            if ($status instanceof ClientStatus) {
                $statuses[] = $status;
            }
        }

        $created = null;
        $preset = self::str($data, 'created_preset', 32);
        $from = self::str($data, 'created_from', 32);
        $to = self::str($data, 'created_to', 32);

        if ($preset !== null || $from !== null || $to !== null) {
            try {
                $created = DateRange::make($preset ?? 'custom', $from, $to);
            } catch (Throwable) {
                $created = null;
            }
        }

        return new self(
            search: self::str($data, 'q', 150) ?? self::str($data, 'search', 150),
            statuses: $statuses,
            accountManagerId: self::int($data, 'account_manager_id'),
            country: self::str($data, 'country', 64),
            portalEnabled: self::nullableBool($data, 'portal_enabled'),
            created: $created,
            trashed: self::bool($data, 'trashed'),
        );
    }

    /**
     * The filter set as query-string parameters — what a queued export rebuilds the filters from.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'q' => $this->search,
            'status' => array_map(static fn (ClientStatus $s): string => $s->value, $this->statuses),
            'account_manager_id' => $this->accountManagerId,
            'country' => $this->country,
            'portal_enabled' => $this->portalEnabled === null ? null : (int) $this->portalEnabled,
            'trashed' => $this->trashed ? 1 : null,
            'created_preset' => $this->created?->preset(),
            'created_from' => $this->created?->isCustom() ? $this->created->start()->toDateString() : null,
            'created_to' => $this->created?->isCustom() ? $this->created->end()->toDateString() : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        if ($this->trashed) {
            $query->onlyTrashed();
        }

        if ($this->search !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search).'%';
            $phone = strlen((string) preg_replace('/\D+/', '', $this->search)) >= 5 ? ContactNormalizer::phone($this->search, null) : null;

            $query->where(function (Builder $inner) use ($like, $phone): void {
                $inner->where($inner->qualifyColumn('client_code'), 'like', $like)
                    ->orWhere($inner->qualifyColumn('name'), 'like', $like)
                    ->orWhere($inner->qualifyColumn('company_name'), 'like', $like)
                    ->orWhere($inner->qualifyColumn('email'), 'like', $like);

                if ($phone !== null) {
                    $inner->orWhere($inner->qualifyColumn('phone_normalized'), $phone)
                        ->orWhere($inner->qualifyColumn('whatsapp_normalized'), $phone);
                }
            });
        }

        if ($this->statuses !== []) {
            $query->whereIn($query->qualifyColumn('status'), array_map(static fn (ClientStatus $s): string => $s->value, $this->statuses));
        }

        if ($this->accountManagerId !== null) {
            $query->where($query->qualifyColumn('account_manager_id'), $this->accountManagerId);
        }

        if ($this->country !== null) {
            $query->where($query->qualifyColumn('country'), $this->country);
        }

        if ($this->portalEnabled !== null) {
            $query->where($query->qualifyColumn('portal_enabled'), $this->portalEnabled);
        }

        if ($this->created instanceof DateRange) {
            $this->created->apply($query, $query->qualifyColumn('created_at'));
        }

        return $query;
    }
}
