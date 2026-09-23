<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\MeetingParticipantRole;
use App\Support\ParticipantResolver;

/**
 * One person the organiser wants in the room (phase-19-23 §6.17).
 *
 * **It is a user id or a name-and-email, never both and never neither.** `chk_mp_identity` says the
 * same thing at the database and `chk_mp_external` says an external participant has no `user_id`;
 * this object refuses the malformed pair before a row is built, so the caller gets a sentence on a
 * field instead of a 4025.
 *
 * **`participant_type` is absent on purpose.** §6.17 requires it to be derived from the person's
 * roles and never posted — {@see ParticipantResolver} does that. A field here would be
 * a field on the form, and a field on the form is a student filing themselves as staff.
 */
final readonly class ParticipantInput
{
    private function __construct(
        public ?int $userId,
        public ?string $externalName,
        public ?string $externalEmail,
        public MeetingParticipantRole $role,
        public ?string $notes,
    ) {}

    public static function user(int $userId, MeetingParticipantRole $role = MeetingParticipantRole::Required, ?string $notes = null): self
    {
        return new self($userId, null, null, $role, $notes);
    }

    public static function external(string $name, string $email, MeetingParticipantRole $role = MeetingParticipantRole::Optional, ?string $notes = null): self
    {
        return new self(null, trim($name), mb_strtolower(trim($email)), $role, $notes);
    }

    /**
     * Build from one posted row, after the Form Request has had its say.
     *
     * Returns null for a row that names nobody — a repeater whose last line is empty is the normal
     * way a participants field arrives, and dropping it is friendlier than a validation error about
     * a line the person never filled in.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $role = $row['role'] ?? null;
        $role = $role instanceof MeetingParticipantRole
            ? $role
            : (MeetingParticipantRole::tryFrom((string) $role) ?? MeetingParticipantRole::Required);

        $notes = isset($row['notes']) && trim((string) $row['notes']) !== '' ? trim((string) $row['notes']) : null;

        $userId = isset($row['user_id']) && $row['user_id'] !== '' ? (int) $row['user_id'] : null;

        if ($userId !== null) {
            return self::user($userId, $role, $notes);
        }

        $name = trim((string) ($row['external_name'] ?? ''));
        $email = trim((string) ($row['external_email'] ?? ''));

        if ($name === '' && $email === '') {
            return null;
        }

        return self::external($name, $email, $role, $notes);
    }

    public function isExternal(): bool
    {
        return $this->userId === null;
    }

    /**
     * What is wrong with this line, in a sentence, or null when nothing is.
     *
     * An external participant needs **both** halves (PH22-37): an email with no name gives the
     * attendee list a blank row, and a name with no email means the invitation goes nowhere — the
     * person would be listed as invited and would never hear about it.
     */
    public function problem(): ?string
    {
        if (! $this->isExternal()) {
            return null;
        }

        if ((string) $this->externalName === '') {
            return 'An outside guest needs a name — the invitation has to say who is coming.';
        }

        if ((string) $this->externalEmail === '') {
            return 'An outside guest needs an email address, or the invitation has nowhere to go.';
        }

        if (! filter_var($this->externalEmail, FILTER_VALIDATE_EMAIL)) {
            return '"'.$this->externalEmail.'" is not an email address the invitation could reach.';
        }

        return null;
    }

    /** The key two lines naming the same person share, so a duplicate can be dropped. */
    public function identity(): string
    {
        return $this->isExternal()
            ? 'external:'.$this->externalEmail
            : 'user:'.$this->userId;
    }
}
