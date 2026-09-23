<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which of §94's six pairs a conversation joins (phase-19-23 §3.4, §6.18, INV-22-4).
 *
 * **§94's six, verbatim and in that order, and the order is load-bearing.** A pair that resolves to
 * several scopes takes the **first** match — somebody who is both an employee and a teacher messaging
 * a manager is `admin_employee`, not `teacher_management` — and that value is what lands in
 * `conversations.pair_scope`. Reordering the cases would silently re-label existing threads, so the
 * declaration order is part of the contract rather than a matter of taste.
 *
 * **What is absent matters more than what is present.** Student ↔ client, student ↔ student,
 * client ↔ client, client ↔ collaborator, teacher ↔ client and the rest are refused because there is
 * no case for them, not because a rule somewhere says no — an allowlist fails closed, and a new kind
 * of user added next year is refused until somebody decides otherwise. `describe()` exists so that
 * refusal arrives as a sentence a person can act on rather than "not permitted".
 *
 * **`settingKey()` is the second gate.** `support.messaging_allowed_pairs` can switch a pair off even
 * when the roles match, and `MessagingMatrix::mayParticipate()` re-checks on **every send** — so
 * turning a pair off silences existing threads rather than only preventing new ones (INV-22-4). A
 * check made once at thread creation would leave yesterday's conversations running under today's
 * policy.
 */
enum ConversationScope: string
{
    use HasOptions;

    /** An administrator and an employee. */
    case AdminEmployee = 'admin_employee';

    /** Two employees. */
    case EmployeeEmployee = 'employee_employee';

    /** A client and whoever runs their projects. */
    case ClientManager = 'client_manager';

    /** A collaborator and the staff who look after collaborators. */
    case CollaboratorStaff = 'collaborator_staff';

    /** A student and institute staff. */
    case StudentStaff = 'student_staff';

    /** A teacher and institute management. */
    case TeacherManagement = 'teacher_management';

    public function label(): string
    {
        return match ($this) {
            self::AdminEmployee => 'Administrator and employee',
            self::EmployeeEmployee => 'Between employees',
            self::ClientManager => 'Client and project manager',
            self::CollaboratorStaff => 'Collaborator and staff',
            self::StudentStaff => 'Student and institute staff',
            self::TeacherManagement => 'Teacher and management',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::AdminEmployee => 'brand',
            self::EmployeeEmployee => 'slate',
            self::ClientManager => 'sky',
            self::CollaboratorStaff => 'amber',
            self::StudentStaff => 'emerald',
            self::TeacherManagement => 'indigo',
        };
    }

    /**
     * The two panels this scope joins.
     *
     * Two employees are both on the admin panel, so the pair is `[Admin, Admin]` rather than a
     * special case — a scope always joins exactly two panels, even when they are the same one.
     *
     * @return array{0: PanelType, 1: PanelType}
     */
    public function panels(): array
    {
        return match ($this) {
            self::AdminEmployee, self::EmployeeEmployee => [PanelType::Admin, PanelType::Admin],
            self::ClientManager => [PanelType::Client, PanelType::Admin],
            self::CollaboratorStaff => [PanelType::Collaborator, PanelType::Admin],
            self::StudentStaff => [PanelType::Student, PanelType::Admin],
            self::TeacherManagement => [PanelType::Teacher, PanelType::Admin],
        };
    }

    /**
     * The settings key that can switch this pair off.
     *
     * A value inside `support.messaging_allowed_pairs`, so an institute that does not want students
     * messaging staff turns off one pair rather than the whole feature.
     */
    public function settingKey(): string
    {
        return $this->value;
    }

    /**
     * Why a pair outside this scope was refused, in words the person can act on.
     *
     * "Not permitted" sends somebody to ask an administrator who will not know either. Naming the
     * route that *is* open — a ticket, their own teacher, the office — is the difference between a
     * refusal and a dead end.
     */
    public function describe(): string
    {
        return match ($this) {
            self::AdminEmployee => 'Administrators and employees can message each other directly.',
            self::EmployeeEmployee => 'Employees can message each other directly.',
            self::ClientManager => 'A client can message whoever runs their projects. For anything else, a support ticket reaches the right desk.',
            self::CollaboratorStaff => 'A collaborator can message the staff who look after collaborators.',
            self::StudentStaff => 'A student can message institute staff and their own teachers — not other students, and not clients or collaborators.',
            self::TeacherManagement => 'A teacher can message institute management.',
        };
    }

    /**
     * The scopes in the order `MessagingMatrix` tries them.
     *
     * `cases()` already returns declaration order; this names the fact so nobody reorders the cases
     * thinking the order is cosmetic. See the class note.
     *
     * @return list<self>
     */
    public static function inMatchOrder(): array
    {
        return self::cases();
    }
}
