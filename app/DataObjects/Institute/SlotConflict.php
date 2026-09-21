<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * One overlap the detector found, in the words a person needs to fix it (phase-14-17 §6.7).
 *
 * "Clash" on its own is useless to whoever has to resolve it. Every conflict therefore names which
 * dimension collided, what the other booking is, and when it runs — so the form can say "Sir Ahmed
 * already teaches BATCH-PHP-12 on Monday 09:00–11:00" rather than refusing without explanation.
 */
final readonly class SlotConflict
{
    public const TEACHER = 'teacher';

    public const CLASSROOM = 'classroom';

    public const BATCH = 'batch';

    public function __construct(
        public string $dimension,
        public string $type,
        public int $id,
        public string $subject,
        public string $window,
        public ?int $batchId = null,
    ) {}

    /** Whether this one can be overridden at all: a batch clash never can ([D-IN-14]). */
    public function isOverridable(): bool
    {
        return $this->dimension !== self::BATCH;
    }

    public function message(): string
    {
        return match ($this->dimension) {
            self::TEACHER => 'The teacher is already booked: '.$this->subject.' ('.$this->window.').',
            self::CLASSROOM => 'The room is already taken: '.$this->subject.' ('.$this->window.').',
            default => 'This batch already has a class then: '.$this->subject.' ('.$this->window.').',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'type' => $this->type,
            'id' => $this->id,
            'subject' => $this->subject,
            'window' => $this->window,
            'batch_id' => $this->batchId,
            'overridable' => $this->isOverridable(),
            'message' => $this->message(),
        ];
    }
}
