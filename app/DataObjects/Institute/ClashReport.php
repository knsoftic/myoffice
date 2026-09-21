<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * Everything wrong with a proposed slot, at once (phase-14-17 §6.7).
 *
 * **Every clash found, never just the first.** A coordinator who is told "the teacher is busy", fixes
 * that, and is then told "and so is the room" has been made to do the work twice. The detector
 * collects all three dimensions and the form shows them together.
 *
 * `blocking` and `overridable` are separate because [D-IN-14] splits them: a batch clash is always an
 * error — students cannot be in two rooms — while a teacher or room clash can be accepted by somebody
 * holding `timetable.change_status` who supplies a reason.
 */
final readonly class ClashReport
{
    /**
     * @param  list<SlotConflict>  $conflicts
     */
    public function __construct(
        public bool $clean,
        public array $conflicts = [],
    ) {}

    public static function clean(): self
    {
        return new self(clean: true, conflicts: []);
    }

    /**
     * @param  list<SlotConflict>  $conflicts
     */
    public static function of(array $conflicts): self
    {
        return new self(clean: $conflicts === [], conflicts: array_values($conflicts));
    }

    /** The ones no permission can wave through. */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->conflicts,
            static fn (SlotConflict $c): bool => ! $c->isOverridable(),
        ));
    }

    public function overridable(): array
    {
        return array_values(array_filter(
            $this->conflicts,
            static fn (SlotConflict $c): bool => $c->isOverridable(),
        ));
    }

    /** Can this be written at all, by anybody, with any reason? */
    public function hasBlocking(): bool
    {
        return $this->blocking() !== [];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (SlotConflict $c): string => $c->message(), $this->conflicts);
    }

    public function summary(): string
    {
        return implode(' ', $this->messages());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'clean' => $this->clean,
            'has_blocking' => $this->hasBlocking(),
            'conflicts' => array_map(static fn (SlotConflict $c): array => $c->toArray(), $this->conflicts),
        ];
    }
}
