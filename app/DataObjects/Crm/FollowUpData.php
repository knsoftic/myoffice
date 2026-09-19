<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadFollowUpType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One follow-up to schedule on a lead (phase-05 §2.3, §6.3 `schedule()`).
 *
 * `scheduledAt` is UTC (D61). `remindBeforeMinutes` null means `crm.follow_up_reminder_minutes`; `assignedTo`
 * null means the lead's assignee.
 */
final readonly class FollowUpData
{
    use ReadsInput;

    public function __construct(
        public LeadFollowUpType $type,
        public CarbonImmutable $scheduledAt,
        public ?int $remindBeforeMinutes = null,
        public ?int $assignedTo = null,
        public ?string $notes = null,
    ) {}

    /**
     * Keys: `type`, `scheduled_at`, `remind_before_minutes`, `assigned_to`, `notes`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = self::enum($data, 'type', LeadFollowUpType::class);
        $scheduledAt = self::dateTime($data, 'scheduled_at');

        if (! $type instanceof LeadFollowUpType || $scheduledAt === null) {
            throw new InvalidArgumentException('A follow-up needs a type and a scheduled date and time.');
        }

        $remind = self::int($data, 'remind_before_minutes');

        return new self(
            type: $type,
            scheduledAt: $scheduledAt,
            remindBeforeMinutes: $remind === null ? null : max(0, $remind),
            assignedTo: self::int($data, 'assigned_to'),
            notes: self::str($data, 'notes', 255),
        );
    }

    /**
     * The nested payload under `$key` (`follow_up`, `next`), or null when it is absent or blank.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromNested(array $data, string $key): ?self
    {
        $nested = $data[$key] ?? null;

        if (! is_array($nested) || ($nested['scheduled_at'] ?? null) === null || ($nested['scheduled_at'] ?? '') === '') {
            return null;
        }

        return self::fromArray($nested);
    }
}
