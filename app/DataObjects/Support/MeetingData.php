<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\DeliveryMode;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Everything a person may set on a meeting (phase-19-23 §6.17).
 *
 * **Status, counts, `rescheduled_from_id` and the reminder stamps are deliberately absent.** They
 * are the service's to write: a form that could post `status` could mark a meeting completed without
 * anybody attending it, and a form that could post `attended_count` could disagree with the
 * attendance rows underneath it. What a person decides is here; what the system concludes is not.
 *
 * **`endsAt()` is derived, never stored on the form.** The column is generated in the database from
 * `scheduled_at + duration_minutes`, and a posted end time would be a second opinion — the two would
 * disagree the first time somebody edited one of them.
 */
final readonly class MeetingData
{
    public function __construct(
        public string $title,
        public CarbonImmutable $scheduledAt,
        public int $durationMinutes,
        public DeliveryMode $deliveryMode = DeliveryMode::Online,
        public ?string $location = null,
        public ?string $meetingUrl = null,
        public ?string $agenda = null,
        public ?int $branchId = null,
        public ?int $classroomId = null,
        public ?int $projectId = null,
        public ?int $courseId = null,
        public ?int $batchId = null,
        public ?int $clientId = null,
        public ?int $leadId = null,
        public ?int $collaboratorId = null,
        public ?int $supportTicketId = null,
        public bool $isPrivate = false,
        public ?int $reminderMinutesBefore = null,
    ) {}

    /**
     * Build from validated input.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $mode = $input['delivery_mode'] ?? DeliveryMode::Online;
        $mode = $mode instanceof DeliveryMode ? $mode : (DeliveryMode::tryFrom((string) $mode) ?? DeliveryMode::Online);

        return new self(
            title: trim((string) ($input['title'] ?? '')),
            scheduledAt: CarbonImmutable::parse((string) ($input['scheduled_at'] ?? 'now'))->startOfMinute(),
            durationMinutes: (int) ($input['duration_minutes'] ?? 30),
            deliveryMode: $mode,
            location: self::text($input['location'] ?? null),
            meetingUrl: self::text($input['meeting_url'] ?? null),
            // The agenda is shown to participants across five panels, so it is sanitised here rather
            // than at each of them — one of them would eventually forget.
            agenda: RichText::sanitize(self::text($input['agenda'] ?? null), 'cms'),
            branchId: self::id($input['branch_id'] ?? null),
            classroomId: self::id($input['classroom_id'] ?? null),
            projectId: self::id($input['project_id'] ?? null),
            courseId: self::id($input['course_id'] ?? null),
            batchId: self::id($input['batch_id'] ?? null),
            clientId: self::id($input['client_id'] ?? null),
            leadId: self::id($input['lead_id'] ?? null),
            collaboratorId: self::id($input['collaborator_id'] ?? null),
            supportTicketId: self::id($input['support_ticket_id'] ?? null),
            isPrivate: (bool) ($input['is_private'] ?? false),
            reminderMinutesBefore: self::id($input['reminder_minutes_before'] ?? null),
        );
    }

    /** The same data with a new start — what `reschedule()` writes onto the successor row. */
    public function startingAt(CarbonInterface $at): self
    {
        return new self(
            title: $this->title,
            scheduledAt: CarbonImmutable::parse($at)->startOfMinute(),
            durationMinutes: $this->durationMinutes,
            deliveryMode: $this->deliveryMode,
            location: $this->location,
            meetingUrl: $this->meetingUrl,
            agenda: $this->agenda,
            branchId: $this->branchId,
            classroomId: $this->classroomId,
            projectId: $this->projectId,
            courseId: $this->courseId,
            batchId: $this->batchId,
            clientId: $this->clientId,
            leadId: $this->leadId,
            collaboratorId: $this->collaboratorId,
            supportTicketId: $this->supportTicketId,
            isPrivate: $this->isPrivate,
            reminderMinutesBefore: $this->reminderMinutesBefore,
        );
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->scheduledAt->addMinutes($this->durationMinutes);
    }

    /**
     * The classroom this booking actually occupies, or null.
     *
     * An online meeting named a room by accident — the field was filled in before the mode was
     * changed — and holding a room for it would block a class that needs one.
     */
    public function occupiedClassroomId(): ?int
    {
        return $this->deliveryMode->needsClassroom() ? $this->classroomId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'title' => $this->title,
            'branch_id' => $this->branchId,
            'scheduled_at' => $this->scheduledAt,
            'duration_minutes' => $this->durationMinutes,
            'delivery_mode' => $this->deliveryMode,
            'location' => $this->location,
            'classroom_id' => $this->classroomId,
            'meeting_url' => $this->meetingUrl,
            'agenda' => $this->agenda,
            'project_id' => $this->projectId,
            'course_id' => $this->courseId,
            'batch_id' => $this->batchId,
            'client_id' => $this->clientId,
            'lead_id' => $this->leadId,
            'collaborator_id' => $this->collaboratorId,
            'support_ticket_id' => $this->supportTicketId,
            'is_private' => $this->isPrivate,
            'reminder_minutes_before' => $this->reminderMinutesBefore,
        ];
    }

    // ===============================================================================================

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
