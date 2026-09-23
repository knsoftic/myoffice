<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ParticipantType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which kind of person a user is, and which profile row says so (phase-19-23 §6.17).
 *
 * §6.17 says a participant's `participant_type` and profile foreign keys are **derived from their
 * roles, never posted**. This is where that derivation lives — once, so that a meeting, a future
 * attendee list and anything else that has to file a person under one of five headings all file
 * them the same way. A posted `participant_type` would let a student be filed as staff by editing a
 * form field, and the meeting's visibility scope reads that column.
 *
 * **The order is fixed and it is not alphabetical.** A person can hold several profiles — a teacher
 * who is also an employee is the common one, a collaborator who enrolled on a course is the awkward
 * one — and the meeting row has a single `participant_type`. Staff comes first because somebody who
 * works here is attending as a colleague; external comes last because it is the absence of all the
 * others. Without a stated order the same person would be filed differently depending on which query
 * returned first, and the §9 scope would show them different meetings on different days.
 *
 * **A profile row with no `user_id` is not reachable from here**, which is correct: this class
 * answers "who is this *user*", and a student who has never been given a login is not a user.
 */
final class ParticipantResolver
{
    /**
     * Profile table by type, in the order a person holding several is filed under.
     *
     * `staff` is `employees`; the column on `meeting_participants` is `employee_id`, which
     * {@see ParticipantType::profileColumn()} already knows.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'staff' => 'employees',
        'teacher' => 'teachers',
        'student' => 'students',
        'client' => 'clients',
        'collaborator' => 'collaborators',
    ];

    /**
     * Resolved profiles, keyed by user id — one page renders the same participant many times.
     *
     * @var array<int, array{type: ParticipantType, id: ?int}>
     */
    private array $cache = [];

    /**
     * The heading this user is filed under, and the id of the row that says so.
     *
     * Returns `staff` with a null id for a user who holds an admin-panel role but has no `employees`
     * row — a founder, an early admin account, anybody set up before HR existed. **That is a real
     * case and not an error**: they are staff, the meeting needs to say so, and `employee_id` is
     * nullable precisely because the profile may be missing. Refusing here would make a meeting
     * unbookable by the person most likely to be booking it.
     *
     * @return array{type: ParticipantType, id: ?int}
     */
    public function resolve(User $user): array
    {
        $key = (int) $user->getKey();

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        foreach (self::TABLES as $type => $table) {
            $id = $this->profileId($table, $key);

            if ($id !== null) {
                return $this->cache[$key] = [
                    'type' => ParticipantType::from($type),
                    'id' => $id,
                ];
            }
        }

        // No profile row anywhere. The panels their roles reach decide the heading; a user who
        // reaches no panel at all is still somebody with a login, and staff is the honest default.
        $panels = $user->panels()->map(static fn ($panel): string => $panel->value)->all();

        foreach (array_keys(self::TABLES) as $type) {
            $participant = ParticipantType::from($type);

            if (in_array($participant->panel()?->value, $panels, true)) {
                return $this->cache[$key] = ['type' => $participant, 'id' => null];
            }
        }

        return $this->cache[$key] = ['type' => ParticipantType::Staff, 'id' => null];
    }

    /**
     * The `meeting_participants` columns for this user, ready to merge into an insert.
     *
     * Every profile column is written, not just the matching one: a participant row reused for
     * somebody else must not keep the previous person's `student_id`, and listing all five makes
     * that impossible to forget.
     *
     * @return array<string, mixed>
     */
    public function columnsFor(User $user): array
    {
        ['type' => $type, 'id' => $id] = $this->resolve($user);

        $columns = [
            'participant_type' => $type->value,
            'user_id' => (int) $user->getKey(),
            'client_id' => null,
            'student_id' => null,
            'teacher_id' => null,
            'collaborator_id' => null,
            'employee_id' => null,
            'external_name' => null,
            'external_email' => null,
        ];

        $profileColumn = $type->profileColumn();

        if ($profileColumn !== null && $id !== null) {
            $columns[$profileColumn] = $id;
        }

        return $columns;
    }

    /** Forget what was resolved — for a long-running command that outlives a profile being created. */
    public function flush(): void
    {
        $this->cache = [];
    }

    // ===============================================================================================

    /**
     * All five profile tables soft-delete (CLAUDE.md §3 — a profile is mutable, not append-only), so
     * the `deleted_at` filter is unconditional. A deleted profile does not make somebody a student.
     */
    private function profileId(string $table, int $userId): ?int
    {
        $id = DB::table($table)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }
}
