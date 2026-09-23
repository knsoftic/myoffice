<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which kind of person this is (phase-19-23 §3.4, §2.21, requirements §94, §95).
 *
 * **Derived from the person's own roles and profiles, never posted.** `MeetingService::create()`
 * resolves it, because a value a form could set is a value somebody can lie about — and this one
 * feeds §9's isolation, so a client who could declare themselves `staff` would be declaring
 * themselves into a different scope.
 *
 * **`external` is the odd one and carries its own guard.** It has no user, no panel and no profile
 * column; `chk_mp_external` refuses a row claiming to be external while holding a `user_id`, and
 * refuses one with no name. An external guest exists so a meeting can honestly record who was in the
 * room without inventing an account for somebody who will never log in.
 *
 * **`profileColumn()` is the one place the six map onto their tables.** Without it every query that
 * wants "the client behind this participant" writes its own match, and the seventh copy is where a
 * `collaborator_id` gets read out of an `employee_id`.
 */
enum ParticipantType: string
{
    use HasOptions;

    /** An employee, on the admin panel. */
    case Staff = 'staff';

    /** A client contact, on the client panel. */
    case Client = 'client';

    case Student = 'student';

    case Teacher = 'teacher';

    case Collaborator = 'collaborator';

    /** Somebody with no account here at all. */
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Client => 'Client',
            self::Student => 'Student',
            self::Teacher => 'Teacher',
            self::Collaborator => 'Collaborator',
            self::External => 'External guest',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Staff => 'brand',
            self::Client => 'sky',
            self::Student => 'emerald',
            self::Teacher => 'indigo',
            self::Collaborator => 'amber',
            self::External => 'slate',
        };
    }

    /**
     * The panel this kind of person signs into, or null when they sign into none.
     *
     * An external guest has no panel, which is exactly why they have no `user_id` either.
     */
    public function panel(): ?PanelType
    {
        return match ($this) {
            self::Staff => PanelType::Admin,
            self::Client => PanelType::Client,
            self::Student => PanelType::Student,
            self::Teacher => PanelType::Teacher,
            self::Collaborator => PanelType::Collaborator,
            self::External => null,
        };
    }

    /**
     * The foreign key on `meeting_participants` that holds this kind of person's profile.
     *
     * Null for `external`, which has no profile to point at.
     */
    public function profileColumn(): ?string
    {
        return match ($this) {
            self::Staff => 'employee_id',
            self::Client => 'client_id',
            self::Student => 'student_id',
            self::Teacher => 'teacher_id',
            self::Collaborator => 'collaborator_id',
            self::External => null,
        };
    }

    /**
     * Does this person belong to the organisation?
     *
     * Everybody with a panel. It is what decides whether an invitation is a notification in the bell
     * or an email to somebody outside — and, in §9, whether a meeting's notes are reachable at all.
     */
    public function isInternal(): bool
    {
        return $this === self::Staff || $this === self::Teacher;
    }

    /** Has an account here, whether or not they are internal. */
    public function hasAccount(): bool
    {
        return $this !== self::External;
    }
}
