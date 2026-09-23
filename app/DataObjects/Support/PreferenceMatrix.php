<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\NotificationDigest;
use App\Enums\NotificationGroup;
use App\Notifications\NotificationEvent;

/**
 * The §97 preference screen, resolved (phase-19-23 §6.19).
 *
 * **Grouped, because fifty-three switches in one list is not a screen anybody uses.**
 * `NotificationGroup` is the heading, and a group with nothing in it — an institute-only
 * installation looking at the collaborator events — is absent rather than empty, because an empty
 * section invites the question "why can I not see anything here?".
 *
 * **`mailAvailable` is the installation's answer, not the person's.** Until real SMTP exists
 * `support.notifications_mail_enabled` is false (Q7), and the mail column renders disabled with an
 * explanation instead of letting somebody switch on a channel that will never send. A checkbox that
 * saves and does nothing is worse than one that is greyed out.
 */
final readonly class PreferenceMatrix
{
    /**
     * @param  array<string, array{event: NotificationEvent, database: bool, mail: bool, digest: NotificationDigest, locked: bool, customised: bool}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $mailAvailable = false,
    ) {}

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach (NotificationGroup::cases() as $group) {
            $inGroup = array_filter(
                $this->rows,
                static fn (array $row): bool => $row['event']->group === $group,
            );

            if ($inGroup !== []) {
                $grouped[$group->value] = $inGroup;
            }
        }

        return $grouped;
    }

    /** How many rows this person has actually changed — the "reset to defaults" button's reason to exist. */
    public function customisedCount(): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['customised']));
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
