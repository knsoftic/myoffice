<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\LeadActivityType;
use App\Enums\LeadContactOutcome;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A manual timeline entry on a lead (phase-05 §2.2, §6.1 `recordActivity()` / `updateActivity()`).
 *
 * Only the five manual types (`note`, `call`, `whatsapp`, `email`, `meeting`) are accepted by the services;
 * `occurredAt` is back-datable and defaults to now (UTC).
 */
final readonly class ActivityData
{
    use ReadsInput;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public LeadActivityType $type,
        public ?string $subject = null,
        public ?string $body = null,
        public ?LeadContactOutcome $outcome = null,
        public ?int $durationMinutes = null,
        public ?CarbonImmutable $occurredAt = null,
        public array $meta = [],
    ) {}

    /**
     * Keys: `type`, `subject`, `body`, `outcome`, `duration_minutes`, `occurred_at`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = self::enum($data, 'type', LeadActivityType::class);

        if (! $type instanceof LeadActivityType) {
            throw new InvalidArgumentException('An activity needs a type.');
        }

        $duration = self::int($data, 'duration_minutes');

        return new self(
            type: $type,
            subject: self::str($data, 'subject', 150),
            body: self::str($data, 'body'),
            outcome: self::enum($data, 'outcome', LeadContactOutcome::class),
            durationMinutes: $duration === null ? null : max(0, min(65535, $duration)),
            occurredAt: self::dateTime($data, 'occurred_at'),
        );
    }
}
