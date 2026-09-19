<?php

declare(strict_types=1);

namespace App\Services\Project\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A phase-06 business rule refused the act — a 422 with the message on the named field.
 *
 * These rules hold whatever the caller's permissions, Super Admin included: a value revision needs a reason
 * and must actually change something, a blocked task needs a blocked reason, a member with a running timer
 * cannot be removed, a manual entry cannot push a day past its cap, a milestone with a payment against it
 * cannot be deleted.
 *
 * It **is** a `ValidationException`, exactly like phase-05's `CrmRuleException`: Laravel renders it as a 422
 * with an `errors` bag for a JSON request and as a redirect back with the error for a browser form, and it is
 * thrown inside the service's transaction, so the refused act leaves nothing behind.
 */
class ProjectRuleException extends ValidationException
{
    public static function refuse(string $field, string $message): static
    {
        return static::withMessages([$field => [$message]]);
    }

    public static function reasonRequired(string $field = 'reason', string $message = 'A reason is required.'): static
    {
        return static::refuse($field, $message);
    }

    public static function noChange(): static
    {
        return static::refuse('project_value', 'Nothing changed, so there is no revision to record.');
    }

    public static function valueBelowZero(): static
    {
        return static::refuse(
            'project_value',
            'A project value and its discount cannot be negative, and the discount cannot exceed the value.'
        );
    }

    public static function halfConfiguredOverride(): static
    {
        return static::refuse(
            'commission_type',
            'A percentage override needs a rate, and a fixed override needs an amount.'
        );
    }

    public static function manualProgressDisabled(): static
    {
        return static::refuse(
            'progress_percent',
            'Manual progress is switched off; progress is derived from the work below it.'
        );
    }

    public static function notAProjectMember(): static
    {
        return static::refuse(
            'assigned_user_id',
            'Only an active member of this project can be assigned to its work.'
        );
    }

    public static function collaboratorCannotLogTime(): static
    {
        return static::refuse(
            'assigned_collaborator_id',
            'That collaborator is an observer on this project, so they cannot be given work to log time against.'
        );
    }

    public static function alreadyOnTheTeam(): static
    {
        return static::refuse('user_id', 'That person is already on this team.');
    }

    public static function collaboratorCannotManage(): static
    {
        return static::refuse('role', 'A collaborator cannot be the manager or the lead of a project.');
    }

    public static function lastManager(): static
    {
        return static::refuse(
            'role',
            'This is the project manager. Hand the project over before changing their role.'
        );
    }

    public static function memberHasRunningTimer(): static
    {
        return static::refuse(
            'member',
            'That member has a running timer on this project. Stop it before removing them.'
        );
    }

    public static function subtaskOfSubtask(): static
    {
        return static::refuse('parent_task_id', 'A subtask cannot have subtasks of its own.');
    }

    public static function milestoneNotOnProject(): static
    {
        return static::refuse('project_milestone_id', 'That milestone belongs to a different project.');
    }

    public static function openSubtasks(string $titles): static
    {
        return static::refuse('status', 'Finish or cancel the open subtasks first: '.$titles.'.');
    }

    public static function blockedReasonRequired(): static
    {
        return static::refuse('blocked_reason', 'Say what the task is blocked on.');
    }

    public static function milestoneHasPayment(string $reference): static
    {
        return static::refuse(
            'milestone',
            sprintf('Payment %s is booked against this milestone, so it cannot be deleted.', $reference)
        );
    }

    public static function taskHasRunningTimer(): static
    {
        return static::refuse('task', 'A timer is running on this task. Stop it first.');
    }

    public static function projectClosedToTime(): static
    {
        return static::refuse(
            'project_id',
            'This project is closed, so no more time can be logged against it.'
        );
    }

    public static function timeNeedsATask(): static
    {
        return static::refuse('task_id', 'Choose the task this time belongs to.');
    }

    public static function timeWindowInvalid(): static
    {
        return static::refuse(
            'ended_at',
            'The finish time has to be after the start time, and neither can be in the future.'
        );
    }

    public static function backdatedReasonRequired(int $days): static
    {
        return static::refuse(
            'manual_reason',
            sprintf('Entries dated more than %d days back need a written reason.', $days)
        );
    }

    public static function dayCapExceeded(int $hours): static
    {
        return static::refuse(
            'duration',
            sprintf('That would take this day past the %d-hour limit for one person.', $hours)
        );
    }

    public static function timerEntryNotEditable(): static
    {
        return static::refuse(
            'time_entry',
            'A timer entry is evidence of when work happened. Discard it with a reason and key the correction in by hand.'
        );
    }

    public static function commentWindowClosed(int $minutes): static
    {
        return static::refuse(
            'body',
            sprintf('A comment can be edited for %d minutes after it is posted.', $minutes)
        );
    }
}
