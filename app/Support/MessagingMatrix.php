<?php

declare(strict_types=1);

namespace App\Support;

use App\DataObjects\Support\MessagingDecision;
use App\Enums\ConversationScope;
use App\Enums\PanelType;
use App\Models\Collaborator\Collaborator;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\Project\Project;
use App\Models\Support\Conversation;
use App\Models\User;

/**
 * The whole of §94 in one class — the thing that stops a student messaging a client
 * (phase-19-23 §6.18, INV-22-4).
 *
 * **An allowlist, and it fails closed.** Six pairs are permitted and everything else is refused —
 * not because a rule somewhere forbids it, but because no rule permits it. Student ↔ client,
 * student ↔ student, client ↔ client, client ↔ collaborator, teacher ↔ client and the rest have no
 * case, so a seventh kind of user added next year is refused until somebody decides otherwise. A
 * denylist would have the opposite default, and the failure mode of "forgot to deny" is a student
 * messaging a client.
 *
 * **Order matters and is the enum's.** A pair that resolves to several scopes takes the **first**
 * match in `ConversationScope`'s declaration order, and that value lands in
 * `conversations.pair_scope`. Somebody who is both an employee and a teacher messaging a manager is
 * `admin_employee`, not `teacher_management` — reordering the cases would silently re-label existing
 * threads, which is why the enum says so in its own note.
 *
 * **`mayParticipate()` is re-asked on every send, and that is INV-22-4.** A check made once at
 * creation leaves yesterday's conversations running under today's policy: switch `student_staff` off
 * in `support.messaging_allowed_pairs` and every existing student thread would keep working, which
 * is the opposite of what switching it off means. Turning a pair off silences the threads that
 * already exist.
 *
 * **Every refusal carries a sentence somebody can act on.** "Not permitted" sends a student to ask
 * an administrator who will not know either; "a student can message institute staff and their own
 * teachers — for anything else, a support ticket reaches the right desk" tells them what to do next.
 *
 * Pure: no database writes, no container, no request state beyond what it is handed. The settings it
 * reads are the two gates above the matrix — the master switch and the per-pair list.
 */
final class MessagingMatrix
{
    /**
     * The pairing that authorises these two talking, or null.
     *
     * Null covers three different things and deliberately does not distinguish them here — not a
     * permitted pair, one of them inactive, the same person twice. `mayStart()` is the method that
     * explains; this one answers the question the service asks when it already knows the answer is
     * yes.
     */
    public static function pairFor(User $a, User $b): ?ConversationScope
    {
        $decision = self::mayStart($a, $b);

        return $decision->allowed ? $decision->scope : null;
    }

    /**
     * May these two start a thread?
     *
     * The order of the checks is the order of the costs: the cheap universal refusals first, then
     * the settings, then the six pairs — each of which may hit the database to resolve a profile.
     */
    public static function mayStart(User $initiator, User $target): MessagingDecision
    {
        if (! (bool) setting('support.messaging_enabled', true)) {
            return MessagingDecision::deny('Internal messaging is switched off.');
        }

        if ((int) $initiator->getKey() === (int) $target->getKey()) {
            return MessagingDecision::deny('You cannot start a conversation with yourself.');
        }

        // **An inactive account is refused in both directions.** Messaging somebody suspended would
        // put a message somewhere nobody reads; being messaged *by* one is worse.
        if (! $initiator->isActive()) {
            return MessagingDecision::deny('Your account is not active, so you cannot send messages.');
        }

        if (! $target->isActive()) {
            return MessagingDecision::deny('That account is not active, so it cannot receive messages.');
        }

        $allowed = self::enabledPairs();

        foreach (ConversationScope::inMatchOrder() as $scope) {
            if (! self::pairMatches($scope, $initiator, $target)) {
                continue;
            }

            // Matching the roles is not enough: §5.2's list is the second gate, and a pair switched
            // off there is refused even when the roles line up exactly.
            if (! in_array($scope->settingKey(), $allowed, true)) {
                return MessagingDecision::deny(sprintf(
                    'This institute has turned off messaging between %s. %s',
                    mb_strtolower($scope->label()),
                    self::alternative($initiator),
                ));
            }

            return MessagingDecision::allow($scope);
        }

        return MessagingDecision::deny(self::refusalFor($initiator, $target));
    }

    /**
     * May this person still take part in this thread?
     *
     * **Asked on every send** (INV-22-4). It re-reads the stored `pair_scope` rather than
     * recomputing the pairing, because the thread was authorised under the pairing that existed when
     * it started and that is the one the institute's setting is being applied to. Recomputing would
     * mean a person whose role changed silently moved their old threads into a different scope.
     */
    public static function mayParticipate(Conversation $conversation, User $user): MessagingDecision
    {
        if (! (bool) setting('support.messaging_enabled', true)) {
            return MessagingDecision::deny('Internal messaging is switched off.');
        }

        if (! $user->isActive()) {
            return MessagingDecision::deny('Your account is not active, so you cannot send messages.');
        }

        if (! $conversation->acceptsMessages()) {
            return MessagingDecision::deny('This conversation is closed. It stays readable, but nothing more can be added.');
        }

        if (! $conversation->includes($user)) {
            return MessagingDecision::deny('You are not in this conversation.');
        }

        $scope = $conversation->pair_scope;

        if (! in_array($scope->settingKey(), self::enabledPairs(), true)) {
            return MessagingDecision::deny(sprintf(
                'This institute has turned off messaging between %s, so this conversation is now read only.',
                mb_strtolower($scope->label()),
            ));
        }

        return MessagingDecision::allow($scope);
    }

    /**
     * Every panel this user can act on.
     *
     * A person may hold several — a teacher who is also an employee is on two — which is exactly why
     * the matrix tests pairs rather than comparing one panel with another.
     *
     * @return list<PanelType>
     */
    public static function panelsOf(User $user): array
    {
        return $user->panels()->all();
    }

    // ===============================================================================================

    /**
     * The pairs this institute has left switched on.
     *
     * An unset or empty setting means **all six**, not none: an installation that has never opened
     * the settings screen should have working messaging, and a missing row is not a decision to
     * switch everything off.
     *
     * @return list<string>
     */
    private static function enabledPairs(): array
    {
        $configured = setting('support.messaging_allowed_pairs', null);

        if (! is_array($configured) || $configured === []) {
            return array_map(
                static fn (ConversationScope $scope): string => $scope->settingKey(),
                ConversationScope::cases(),
            );
        }

        return array_values(array_map(static fn (mixed $value): string => (string) $value, $configured));
    }

    /** Does this pairing describe these two, in either direction? */
    private static function pairMatches(ConversationScope $scope, User $a, User $b): bool
    {
        return match ($scope) {
            ConversationScope::AdminEmployee => self::eitherWay(
                $a,
                $b,
                static fn (User $x): bool => self::isSenior($x),
                static fn (User $y): bool => self::onPanel($y, PanelType::Admin),
            ),
            ConversationScope::EmployeeEmployee => self::onPanel($a, PanelType::Admin)
                && self::onPanel($b, PanelType::Admin),
            ConversationScope::ClientManager => self::eitherWay(
                $a,
                $b,
                static fn (User $x): bool => self::isClient($x),
                static fn (User $y): bool => $y->can('projects.view_any') || self::managesAProject($y),
            ),
            ConversationScope::CollaboratorStaff => self::eitherWay(
                $a,
                $b,
                static fn (User $x): bool => self::isCollaborator($x),
                static fn (User $y): bool => $y->can('collaborators.view_any'),
            ),
            ConversationScope::StudentStaff => self::eitherWay(
                $a,
                $b,
                static fn (User $x): bool => self::isStudent($x),
                static fn (User $y): bool => $y->can('students.view_any') || self::isTeacher($y),
            ),
            ConversationScope::TeacherManagement => self::eitherWay(
                $a,
                $b,
                static fn (User $x): bool => self::isTeacher($x),
                static fn (User $y): bool => $y->can('teachers.view_any') || $y->can('batches.view_any'),
            ),
        };
    }

    /**
     * One of them is the first thing and the other is the second — whichever way round they came.
     *
     * **The two must be different people in the two roles**, which `mayStart()` has already
     * guaranteed by refusing a self-pair; without that, somebody who satisfied both halves would
     * match every scope they touched.
     */
    private static function eitherWay(User $a, User $b, callable $first, callable $second): bool
    {
        return ($first($a) && $second($b)) || ($first($b) && $second($a));
    }

    private static function onPanel(User $user, PanelType $panel): bool
    {
        foreach (self::panelsOf($user) as $held) {
            if ($held === $panel) {
                return true;
            }
        }

        return false;
    }

    /**
     * Senior enough to be the "administrator" half of `admin_employee`.
     *
     * §6.18 puts the line at `roles.level <= 20`. The level ladder is Phase 1's, where Super Admin is
     * 1 and an ordinary staff role is 50 — so this is "management", not "anybody on the admin panel".
     */
    private static function isSenior(User $user): bool
    {
        foreach ($user->roles as $role) {
            if ((int) ($role->level ?? 50) <= 20) {
                return true;
            }
        }

        return false;
    }

    private static function isClient(User $user): bool
    {
        return self::onPanel($user, PanelType::Client);
    }

    private static function isStudent(User $user): bool
    {
        return Student::query()->where('user_id', $user->getKey())->exists();
    }

    private static function isTeacher(User $user): bool
    {
        return Teacher::query()->where('user_id', $user->getKey())->exists();
    }

    private static function isCollaborator(User $user): bool
    {
        return Collaborator::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Does this person run any project?
     *
     * The alternative to `projects.view_any` in `client_manager`: a project manager who holds no
     * blanket permission is still the person a client of that project should be able to reach.
     */
    private static function managesAProject(User $user): bool
    {
        return Project::query()->where('project_manager_id', $user->getKey())->exists();
    }

    /**
     * Why these two cannot talk, written for whoever tried.
     *
     * Specific where the system can be specific — a student, a client and a collaborator each get
     * the route that *is* open to them — and honest where it cannot.
     */
    private static function refusalFor(User $initiator, User $target): string
    {
        return sprintf(
            'Messaging is not open between these two accounts. %s',
            self::alternative($initiator),
        );
    }

    /** What this person should do instead. */
    private static function alternative(User $user): string
    {
        if (self::isStudent($user)) {
            return 'A student can message institute staff and their own teachers. For anything else, raise a support ticket and it reaches the right desk.';
        }

        if (self::isClient($user)) {
            return 'A client can message whoever runs their projects. For anything else, a support ticket reaches the right desk.';
        }

        if (self::isCollaborator($user)) {
            return 'A collaborator can message the staff who look after collaborators. For anything else, raise a support ticket.';
        }

        if (self::isTeacher($user)) {
            return 'A teacher can message institute management and their own students. For anything else, raise a support ticket.';
        }

        return 'Raise a support ticket instead and it will reach the right desk.';
    }
}
