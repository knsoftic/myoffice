<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadFollowUpStatus;
use App\Enums\LeadFollowUpType;

/**
 * The follow-up worklist's filter bar (phase-05 §6.3 `worklist()`, §8.5).
 *
 * `assignee` defaults to "me"; "all" is honoured only for a holder of `leads.view_any` (the service enforces
 * it — a crafted `assignee=all` from anyone else still reads as "me").
 */
final readonly class FollowUpFilters
{
    use ReadsInput;

    public const ASSIGNEE_ME = 'me';

    public const ASSIGNEE_ALL = 'all';

    /** Sortable columns of the worklist. */
    public const SORTS = ['scheduled_at', 'type', 'status', 'lead'];

    /**
     * @param  int|string  $assignee  a user id, `me` or `all`
     */
    public function __construct(
        public int|string $assignee = self::ASSIGNEE_ME,
        public ?LeadFollowUpType $type = null,
        public ?LeadFollowUpStatus $status = null,
        public bool $overdueOnly = false,
        public int $perPage = 25,
        public ?string $search = null,
        public string $sort = 'scheduled_at',
        public string $direction = 'asc',
    ) {}

    /**
     * Keys: `assignee`, `type`, `status`, `overdue`, `per_page`, `q` (lead number, name, company or the follow-up's
     * notes), `sort` (`scheduled_at` | `type` | `status` | `lead`), `direction` (`asc` | `desc`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $assignee = self::str($data, 'assignee', 16) ?? self::ASSIGNEE_ME;

        if (! in_array($assignee, [self::ASSIGNEE_ME, self::ASSIGNEE_ALL], true)) {
            $assignee = ctype_digit($assignee) ? (int) $assignee : self::ASSIGNEE_ME;
        }

        $perPage = self::int($data, 'per_page') ?? 25;
        $sort = self::str($data, 'sort', 32);
        $direction = strtolower((string) self::str($data, 'direction', 4));

        return new self(
            assignee: $assignee,
            type: self::enum($data, 'type', LeadFollowUpType::class),
            status: self::enum($data, 'status', LeadFollowUpStatus::class),
            overdueOnly: self::bool($data, 'overdue'),
            perPage: max(5, min(100, $perPage)),
            search: self::str($data, 'q', 100) ?? self::str($data, 'search', 100),
            sort: in_array($sort, self::SORTS, true) ? $sort : 'scheduled_at',
            direction: $direction === 'desc' ? 'desc' : 'asc',
        );
    }
}
