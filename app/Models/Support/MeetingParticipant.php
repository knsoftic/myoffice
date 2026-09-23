<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\MeetingParticipantRole;
use App\Enums\MeetingResponse;
use App\Enums\ParticipantType;
use App\Models\Crm\Client;
use App\Models\Hr\Employee;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody in a meeting (phase-19-23 §2.21, requirement §95).
 *
 * **A pivot, so no soft deletes and no blameable** — CLAUDE.md §3's mutable-pivot case. Removing
 * somebody deletes the row and writes an activity entry with the reason, and §9's scope reads this
 * table **live**: a removed participant loses access to the notes immediately, rather than at the
 * next cache clear. A soft-deleted membership would still satisfy "is this person a participant?" in
 * any query that forgot the scope, which on a table guarding meeting minutes is the wrong default.
 *
 * **`attended` is three states, not two.** Null is "nobody recorded it", which is a different fact
 * from "did not attend" — an attendance rate computed over unrecorded meetings measures how
 * diligently somebody ticks boxes rather than who turned up. `attendanceRecorded()` is the question
 * to ask before counting.
 *
 * **`participant_type` is derived from the person's own roles, never posted.** It feeds §9's
 * isolation, so a value a form could set would be a way to declare yourself into a different scope —
 * and `chk_mp_external` refuses a row whose type contradicts the columns beside it.
 *
 * @property ParticipantType $participant_type
 * @property MeetingResponse $response
 */
class MeetingParticipant extends Model
{
    protected $table = 'meeting_participants';

    /**
     * Only what a person legitimately chooses about themselves. The type, the profile keys and every
     * stamped timestamp are the service's.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'response',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participant_type' => ParticipantType::class,
            'role' => MeetingParticipantRole::class,
            'response' => MeetingResponse::class,
            'responded_at' => 'datetime',
            'attended' => 'boolean',
            'attendance_marked_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /** Has anybody actually recorded whether they came? See the class note. */
    public function attendanceRecorded(): bool
    {
        return $this->getAttribute('attended') !== null;
    }

    /** Somebody with no account here — invited by name and email. */
    public function isExternal(): bool
    {
        return $this->participant_type === ParticipantType::External;
    }

    /** What to put on screen, whoever they are. */
    public function displayName(): string
    {
        if ($this->isExternal()) {
            return (string) ($this->getAttribute('external_name') ?? 'Guest');
        }

        return (string) ($this->user?->getAttribute('name') ?? 'Unknown');
    }

    /** Where to write to, whoever they are. */
    public function displayEmail(): ?string
    {
        if ($this->isExternal()) {
            return $this->getAttribute('external_email');
        }

        return $this->user?->getAttribute('email');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** Null for an external guest. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Collaborator\Collaborator::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceMarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('response', MeetingResponse::Accepted->value);
    }

    /** Whose answer is still outstanding — what the reminder sweep reads. */
    public function scopeAwaitingReply(Builder $query): Builder
    {
        return $query->where('response', MeetingResponse::Pending->value);
    }

    /** The roles a quorum depends on. `optional` is not one of them. */
    public function scopeCountingInQuorum(Builder $query): Builder
    {
        $values = [];

        foreach (MeetingParticipantRole::cases() as $role) {
            if ($role->countsInQuorum()) {
                $values[] = $role->value;
            }
        }

        return $query->whereIn('role', $values);
    }
}
