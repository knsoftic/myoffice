<?php

declare(strict_types=1);

namespace App\Support;

use App\DataObjects\Support\ChannelSet;
use App\Enums\NotificationGroup;
use App\Enums\NotificationLevel;
use App\Enums\PanelType;
use App\Models\User;
use App\Notifications\NotificationEvent;

/**
 * Every notifiable event in the system, declared once (phase-19-23 §6.19, §10.3, D55).
 *
 * **The fourth registry, and the same argument as the first three.** `PermissionRegistry` is the
 * only place a permission name exists, `SettingsRegistry` the only place a setting key does, and
 * this is the only place an event key does. The alternative is a `notification_events` table, and a
 * table can drift from the code that dispatches into it: a typo'd key would create a row nobody ever
 * sees a preference for, and the preference screen would be built from whatever happened to be in
 * the table rather than from what the system can actually send.
 *
 * **Each event is owned twice over, and the split is the point.** The *business act* belongs to the
 * phase that performs it — Phase 6 creates a project, Phase 12 reverses a commission, Phase 20
 * publishes a result. The *key, the audience, the channels and the delivery* belong here. So a phase
 * that already ships a notification class keeps it and simply becomes preference-able; a phase that
 * does not gets `RegistryNotification` and needs no class at all.
 *
 * **A key is permanent.** `notification_preferences.event_key` and `notifications.event_key` are
 * both filed under it, so renaming one orphans every preference a person set and every row already
 * in their bell. Add and deprecate; never rename.
 *
 * **`forUser()` is the Sidebar rule, applied to events**: the module must be enabled *and* the
 * person must be able to receive it. A preference screen listing events that can never reach you is
 * a screen that teaches people their preferences do not work.
 */
final class NotificationRegistry
{
    /** @var array<string, NotificationEvent>|null */
    private static ?array $events = null;

    /**
     * @return array<string, NotificationEvent>
     */
    public static function events(): array
    {
        if (self::$events !== null) {
            return self::$events;
        }

        $events = [];

        foreach ([
            ...self::softwareHouse(),
            ...self::institute(),
            ...self::finance(),
            ...self::collaborator(),
            ...self::support(),
            ...self::system(),
        ] as $event) {
            $events[$event->key] = $event;
        }

        return self::$events = $events;
    }

    public static function event(string $key): ?NotificationEvent
    {
        return self::events()[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::events()[$key]);
    }

    /**
     * @return array<string, NotificationEvent>
     */
    public static function group(NotificationGroup $group): array
    {
        return array_filter(
            self::events(),
            static fn (NotificationEvent $event): bool => $event->group === $group,
        );
    }

    /**
     * The events this person could actually receive — what the §97 preference screen renders.
     *
     * Two filters, both necessary and neither sufficient. The module gate hides an event the
     * institute has switched off entirely. The permission gate hides one whose rows this person
     * would never be given — a student has no business setting a preference for "a payout was
     * approved", and showing them the row invites them to wonder why it never fires.
     *
     * @return array<string, NotificationEvent>
     */
    public static function forUser(User $user): array
    {
        $panels = $user->panels()->all();

        return array_filter(self::events(), static function (NotificationEvent $event) use ($user, $panels): bool {
            if (! Modules::enabled($event->module)) {
                return false;
            }

            // The declared audience. Without this a student's preference screen offers to mute
            // "a wallet disagrees with its ledger", and a manager's hides "your assignment has been
            // marked" — neither of which either person can do anything about.
            if (! $event->reachesAnyOf($panels)) {
                return false;
            }

            if ($event->requiredPermission !== null && ! $user->can($event->requiredPermission)) {
                return false;
            }

            // An event addressed to "whoever may approve a payout" is not one somebody who may not
            // approve payouts will ever see a row for.
            $audience = $event->audiencePermission();

            return $audience === null || $user->can($audience);
        });
    }

    public static function defaultsFor(string $key): ChannelSet
    {
        return self::event($key)?->defaults() ?? new ChannelSet(true, false);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::events());
    }

    /** Only a test that registered one; production never forgets. */
    public static function flush(): void
    {
        self::$events = null;
    }

    // ===============================================================================================

    /**
     * @return list<NotificationEvent>
     */
    private static function softwareHouse(): array
    {
        return [
            new NotificationEvent(
                key: 'project.created',
                title: 'A project was created',
                description: 'A new project has been opened and you are on it.',
                group: NotificationGroup::SoftwareHouse,
                module: 'projects',
                panels: [PanelType::Admin, PanelType::Client],
                ownedByPhase: 6,
            ),
            new NotificationEvent(
                key: 'task.assigned',
                title: 'A task is yours',
                description: 'A task has been assigned to you.',
                group: NotificationGroup::SoftwareHouse,
                module: 'tasks',
                panels: [PanelType::Admin, PanelType::Collaborator],
                ownedByPhase: 6,
            ),
            new NotificationEvent(
                key: 'lead.created',
                title: 'A new lead arrived',
                description: 'A lead has come in and needs an owner.',
                group: NotificationGroup::SoftwareHouse,
                module: 'leads',
                audience: 'permission:leads.assign',
                panels: [PanelType::Admin],
                ownedByPhase: 5,
            ),
            new NotificationEvent(
                key: 'lead.followup_due',
                title: 'A follow-up is due',
                description: 'A lead you own is due a follow-up.',
                group: NotificationGroup::SoftwareHouse,
                module: 'leads',
                level: NotificationLevel::Warning,
                panels: [PanelType::Admin],
                ownedByPhase: 5,
            ),
            new NotificationEvent(
                key: 'client.portal_invitation',
                title: 'A client was invited to the portal',
                description: 'A portal invitation has been sent.',
                group: NotificationGroup::SoftwareHouse,
                module: 'client_portal',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin, PanelType::Client],
                ownedByPhase: 5,
            ),
            new NotificationEvent(
                key: 'client.document_shared',
                title: 'A document was shared with you',
                description: 'A new document is available in your portal.',
                group: NotificationGroup::SoftwareHouse,
                module: 'client_documents',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Client, PanelType::Admin],
                ownedByPhase: 5,
            ),
        ];
    }

    /**
     * @return list<NotificationEvent>
     */
    private static function institute(): array
    {
        return [
            new NotificationEvent(
                key: 'admission.created',
                title: 'A new admission',
                description: 'A student has been admitted to a batch.',
                group: NotificationGroup::Institute,
                module: 'admissions',
                audience: 'permission:admissions.view_any',
                panels: [PanelType::Admin],
                ownedByPhase: 15,
            ),
            new NotificationEvent(
                key: 'student.created',
                title: 'A new student registered',
                description: 'A student record has been created.',
                group: NotificationGroup::Institute,
                module: 'students',
                audience: 'permission:students.view_any',
                panels: [PanelType::Admin],
                ownedByPhase: 15,
            ),
            new NotificationEvent(
                key: 'batch.near_capacity',
                title: 'A batch is nearly full',
                description: 'A batch has reached the seats-remaining threshold.',
                group: NotificationGroup::Institute,
                module: 'batches',
                level: NotificationLevel::Warning,
                audience: 'permission:batches.view_any',
                panels: [PanelType::Admin],
                ownedByPhase: 16,
            ),
            new NotificationEvent(
                key: 'session.unmarked',
                title: 'A class has no attendance',
                description: 'A class was held and nobody marked the register.',
                group: NotificationGroup::Institute,
                module: 'student_attendance',
                level: NotificationLevel::Warning,
                panels: [PanelType::Admin, PanelType::Teacher],
                ownedByPhase: 17,
            ),
            new NotificationEvent(
                key: 'material.published',
                title: 'New course material',
                description: 'Material has been shared with your batch.',
                group: NotificationGroup::Institute,
                module: 'course_materials',
                requiredPermission: 'student_portal.materials',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'material.reminder',
                title: 'You have not opened some material',
                description: 'Material shared with you is still unopened.',
                group: NotificationGroup::Institute,
                module: 'course_materials',
                requiredPermission: 'student_portal.materials',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'assignment.published',
                title: 'A new assignment',
                description: 'An assignment has been set for your batch.',
                group: NotificationGroup::Institute,
                module: 'assignments',
                requiredPermission: 'student_portal.assignments',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'assignment.deadline_reminder',
                title: 'An assignment is due',
                description: 'The deadline for an assignment you have not submitted is approaching.',
                group: NotificationGroup::Institute,
                module: 'assignments',
                level: NotificationLevel::Warning,
                requiredPermission: 'student_portal.assignments',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'assignment.submitted',
                title: 'A submission arrived',
                description: 'A student has submitted an assignment you set.',
                group: NotificationGroup::Institute,
                module: 'assignment_submissions',
                panels: [PanelType::Teacher, PanelType::Admin],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'assignment.graded',
                title: 'Your assignment has been marked',
                description: 'Marks and feedback are available.',
                group: NotificationGroup::Institute,
                module: 'assignment_submissions',
                level: NotificationLevel::Success,
                requiredPermission: 'student_portal.assignments',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'assignment.returned',
                title: 'An assignment was returned to you',
                description: 'Your submission has been returned for changes.',
                group: NotificationGroup::Institute,
                module: 'assignment_submissions',
                level: NotificationLevel::Warning,
                requiredPermission: 'student_portal.assignments',
                panels: [PanelType::Student],
                ownedByPhase: 19,
            ),
            new NotificationEvent(
                key: 'exam.scheduled',
                title: 'An exam has been scheduled',
                description: 'An exam has been added to your timetable.',
                group: NotificationGroup::Institute,
                module: 'exams',
                panels: [PanelType::Student, PanelType::Teacher, PanelType::Admin],
                ownedByPhase: 20,
            ),
            new NotificationEvent(
                key: 'result.published',
                title: 'Your result is out',
                description: 'A result has been published for you.',
                group: NotificationGroup::Institute,
                module: 'results',
                defaultChannels: ChannelSet::databaseAndMail(),
                requiredPermission: 'student_portal.results',
                panels: [PanelType::Student],
                ownedByPhase: 20,
            ),
            new NotificationEvent(
                key: 'certificate.generated',
                title: 'Your certificate is ready',
                description: 'A certificate has been issued, with a link that verifies it.',
                group: NotificationGroup::Institute,
                module: 'certificates',
                level: NotificationLevel::Success,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Student, PanelType::Admin],
                ownedByPhase: 21,
            ),
            new NotificationEvent(
                key: 'certificate.revoked',
                title: 'A certificate was revoked',
                description: 'A certificate has been revoked and no longer verifies.',
                group: NotificationGroup::Institute,
                module: 'certificates',
                level: NotificationLevel::Critical,
                defaultChannels: ChannelSet::databaseAndMail(),
                // A revoked certificate is a fact the holder must be told and cannot mute: somebody
                // may present it in good faith to an employer who checks it.
                mandatory: true,
                panels: [PanelType::Student, PanelType::Admin],
                ownedByPhase: 21,
            ),
            new NotificationEvent(
                key: 'idcard.issued',
                title: 'Your student ID card is ready',
                description: 'An ID card has been issued for you.',
                group: NotificationGroup::Institute,
                module: 'student_id_cards',
                requiredPermission: 'student_portal.id_card',
                panels: [PanelType::Student],
                ownedByPhase: 21,
            ),
        ];
    }

    /**
     * @return list<NotificationEvent>
     */
    private static function finance(): array
    {
        return [
            new NotificationEvent(
                key: 'fee.paid',
                title: 'A fee payment was received',
                description: 'A payment has been recorded, with a link to the receipt.',
                group: NotificationGroup::Finance,
                module: 'student_fee_payments',
                level: NotificationLevel::Success,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Student, PanelType::Admin],
                ownedByPhase: 18,
            ),
            new NotificationEvent(
                key: 'fee.due',
                title: 'A fee is due',
                description: 'A fee instalment is due shortly.',
                group: NotificationGroup::Finance,
                module: 'fee_reminders',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Student, PanelType::Admin],
                ownedByPhase: 18,
            ),
            new NotificationEvent(
                key: 'fee.overdue',
                title: 'A fee is overdue',
                description: 'A fee instalment has passed its due date.',
                group: NotificationGroup::Finance,
                module: 'fee_reminders',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Student, PanelType::Admin],
                ownedByPhase: 18,
            ),
            new NotificationEvent(
                key: 'invoice.sent',
                title: 'An invoice was sent',
                description: 'An invoice has been issued to a client.',
                group: NotificationGroup::Finance,
                module: 'invoices',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin, PanelType::Client],
                ownedByPhase: 13,
            ),
            new NotificationEvent(
                key: 'invoice.reminder',
                title: 'An invoice is overdue',
                description: 'An invoice has passed its due date.',
                group: NotificationGroup::Finance,
                module: 'invoices',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin, PanelType::Client],
                ownedByPhase: 13,
            ),
            new NotificationEvent(
                key: 'refund.awaiting_approval',
                title: 'A refund needs approving',
                description: 'A refund has been requested and is waiting on a decision.',
                group: NotificationGroup::Finance,
                module: 'payment_reversals',
                level: NotificationLevel::Warning,
                audience: 'permission:payment_reversals.approve',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin],
                ownedByPhase: 13,
            ),
            new NotificationEvent(
                key: 'leave.requested',
                title: 'A leave request needs a decision',
                description: 'An employee has requested leave.',
                group: NotificationGroup::Finance,
                module: 'leaves',
                audience: 'permission:leaves.approve',
                panels: [PanelType::Admin],
                ownedByPhase: 7,
            ),
            new NotificationEvent(
                key: 'leave.decided',
                title: 'Your leave request was decided',
                description: 'A decision has been recorded on your leave request.',
                group: NotificationGroup::Finance,
                module: 'leaves',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin],
                ownedByPhase: 7,
            ),
            new NotificationEvent(
                key: 'payroll.slip_ready',
                title: 'Your salary slip is ready',
                description: 'A salary slip has been published for you.',
                group: NotificationGroup::Finance,
                module: 'salary_slips',
                level: NotificationLevel::Success,
                defaultChannels: ChannelSet::databaseAndMail(),
                requiredPermission: 'employee_self_service.view',
                panels: [PanelType::Admin],
                ownedByPhase: 7,
            ),
        ];
    }

    /**
     * @return list<NotificationEvent>
     */
    private static function collaborator(): array
    {
        return [
            new NotificationEvent(
                key: 'commission.student_added',
                title: 'A student commission was added',
                description: 'A commission has been credited to your wallet.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_commissions',
                level: NotificationLevel::Success,
                requiredPermission: 'collaborator_portal.student_commission',
                panels: [PanelType::Collaborator],
                ownedByPhase: 10,
            ),
            new NotificationEvent(
                key: 'commission.project_added',
                title: 'A project commission was added',
                description: 'A commission has been credited to your wallet.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_commissions',
                level: NotificationLevel::Success,
                requiredPermission: 'collaborator_portal.project_commission',
                panels: [PanelType::Collaborator],
                ownedByPhase: 11,
            ),
            new NotificationEvent(
                key: 'commission.approved',
                title: 'A commission was approved',
                description: 'A commission is now available to withdraw.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_commissions',
                level: NotificationLevel::Success,
                panels: [PanelType::Collaborator],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'commission.reversed',
                title: 'A commission was reversed',
                description: 'A commission has been reversed, with the reason.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_commissions',
                level: NotificationLevel::Critical,
                defaultChannels: ChannelSet::databaseAndMail(),
                // Money leaving a wallet is never something a person can have turned off. "I was
                // never told" is a dispute, not an inconvenience.
                mandatory: true,
                panels: [PanelType::Collaborator],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'commission.generation_failed',
                title: 'A commission could not be generated',
                description: 'A received payment did not produce the commission it should have.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_commissions',
                level: NotificationLevel::Critical,
                audience: 'permission:collaborator_commissions.view_reports',
                defaultChannels: ChannelSet::databaseAndMail(),
                mandatory: true,
                panels: [PanelType::Admin],
                ownedByPhase: 10,
            ),
            new NotificationEvent(
                key: 'payout.requested',
                title: 'A payout was requested',
                description: 'A collaborator has requested a withdrawal.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_payouts',
                audience: 'permission:collaborator_payouts.approve',
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Admin],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'payout.approved',
                title: 'Your payout was approved',
                description: 'A payout request has been approved.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_payouts',
                level: NotificationLevel::Success,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Collaborator],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'payout.rejected',
                title: 'Your payout was rejected',
                description: 'A payout request has been rejected, with the reason.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_payouts',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                panels: [PanelType::Collaborator],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'payout.paid',
                title: 'A payout was paid',
                description: 'Money has been sent, with the reference.',
                group: NotificationGroup::Collaborator,
                module: 'collaborator_payouts',
                level: NotificationLevel::Success,
                defaultChannels: ChannelSet::databaseAndMail(),
                mandatory: true,
                panels: [PanelType::Collaborator],
                ownedByPhase: 12,
            ),
            new NotificationEvent(
                key: 'wallet.drift',
                title: 'A wallet disagrees with its ledger',
                description: 'A reconciliation found a wallet balance that the ledger does not support.',
                group: NotificationGroup::Collaborator,
                module: 'wallet_reconciliation',
                level: NotificationLevel::Critical,
                audience: 'permission:wallet_reconciliation.view',
                defaultChannels: ChannelSet::databaseAndMail(),
                mandatory: true,
                panels: [PanelType::Admin],
                ownedByPhase: 12,
            ),
        ];
    }

    /**
     * @return list<NotificationEvent>
     */
    private static function support(): array
    {
        return [
            new NotificationEvent(
                key: 'ticket.created',
                title: 'A ticket was raised',
                description: 'A support ticket needs an owner.',
                group: NotificationGroup::Support,
                module: 'support_tickets',
                audience: 'permission:support_tickets.assign',
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'ticket.replied',
                title: 'A ticket has a reply',
                description: 'There is a new reply on a ticket you are on.',
                group: NotificationGroup::Support,
                module: 'support_tickets',
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'ticket.assigned',
                title: 'A ticket is yours',
                description: 'A ticket has been assigned to you.',
                group: NotificationGroup::Support,
                module: 'support_tickets',
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'ticket.status_changed',
                title: 'A ticket changed status',
                description: 'A ticket you raised has been resolved, closed or reopened.',
                group: NotificationGroup::Support,
                module: 'support_tickets',
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'ticket.sla_breach',
                title: 'A ticket has breached its SLA',
                description: 'A ticket has passed its response or resolution target.',
                group: NotificationGroup::Support,
                module: 'support_tickets',
                level: NotificationLevel::Critical,
                audience: 'permission:support_tickets.view_reports',
                defaultChannels: ChannelSet::databaseAndMail(),
                // A target that can be missed quietly is not a target.
                mandatory: true,
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'meeting.invited',
                title: 'You have been invited to a meeting',
                description: 'A meeting has been put in your diary.',
                group: NotificationGroup::Support,
                module: 'meetings',
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'meeting.updated',
                title: 'A meeting has changed',
                description: 'A meeting you are in has moved or changed, so your answer has been reset.',
                group: NotificationGroup::Support,
                module: 'meetings',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'meeting.cancelled',
                title: 'A meeting was cancelled',
                description: 'A meeting you were invited to has been called off, with the reason.',
                group: NotificationGroup::Support,
                module: 'meetings',
                level: NotificationLevel::Warning,
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'meeting.reminder',
                title: 'A meeting starts soon',
                description: 'A meeting you have not declined is about to start.',
                group: NotificationGroup::Support,
                module: 'meetings',
                defaultChannels: ChannelSet::databaseAndMail(),
                ownedByPhase: 22,
            ),
            new NotificationEvent(
                key: 'message.received',
                title: 'A new message',
                description: 'Somebody has written to you.',
                group: NotificationGroup::Support,
                module: 'messages',
                ownedByPhase: 22,
            ),
        ];
    }

    /**
     * @return list<NotificationEvent>
     */
    private static function system(): array
    {
        return [
            new NotificationEvent(
                key: 'export.ready',
                title: 'Your export is ready',
                description: 'A report export has finished and can be downloaded.',
                group: NotificationGroup::System,
                module: 'reports',
                level: NotificationLevel::Success,
                panels: [PanelType::Admin],
                ownedByPhase: 23,
            ),
            new NotificationEvent(
                key: 'export.failed',
                title: 'An export failed',
                description: 'A report export could not be built, with the reason.',
                group: NotificationGroup::System,
                module: 'reports',
                level: NotificationLevel::Warning,
                panels: [PanelType::Admin],
                ownedByPhase: 23,
            ),
        ];
    }
}
