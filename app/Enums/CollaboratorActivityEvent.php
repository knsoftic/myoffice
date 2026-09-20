<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The eleven things a collaborator's own activity feed shows (phase-08-09 §3.1, requirement §60).
 *
 * §60 asks for a per-collaborator activity log. **D13 contracts one audit store**, so this is a filtered
 * view over `activity_log` rather than a second table — and the filter is the indexed
 * `activity_log.collaborator_id` column, because the rows that matter most (a commission created, a
 * commission approved, a payout paid) have a **null causer**: the engine wrote them, or the scheduler
 * did. Scoping by causer alone would silently drop exactly the rows a partner most wants to see.
 *
 * **{@see visibleProperties()} is an allowlist, not a blocklist** (F-12.7). A key that is not on an
 * event's list is **absent from the response**, not blanked — a blanked key still tells the reader that
 * something was there. `reason` is on no list: an internal note about why a commission was reversed is
 * written for the business, not for the partner it concerns. The underlying `activity_log` row keeps
 * everything; only the collaborator's own view is narrowed.
 */
enum CollaboratorActivityEvent: string
{
    use HasOptions;

    case Login = 'login';
    case StudentReferral = 'student_referral';
    case ProjectReferral = 'project_referral';
    case TaskUpdate = 'task_update';
    case FileUpload = 'file_upload';
    case FileDownload = 'file_download';
    case CommissionCreated = 'commission_created';
    case CommissionApproved = 'commission_approved';
    case CommissionReversed = 'commission_reversed';
    case PayoutRequest = 'payout_request';
    case PayoutPaid = 'payout_paid';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Signed in',
            self::StudentReferral => 'Student referred',
            self::ProjectReferral => 'Project referred',
            self::TaskUpdate => 'Task updated',
            self::FileUpload => 'File uploaded',
            self::FileDownload => 'File downloaded',
            self::CommissionCreated => 'Commission earned',
            self::CommissionApproved => 'Commission approved',
            self::CommissionReversed => 'Commission reversed',
            self::PayoutRequest => 'Payout requested',
            self::PayoutPaid => 'Payout paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Login => 'slate',
            self::StudentReferral, self::ProjectReferral => 'sky',
            self::TaskUpdate => 'indigo',
            self::FileUpload, self::FileDownload => 'violet',
            self::CommissionCreated, self::CommissionApproved, self::PayoutPaid => 'emerald',
            self::CommissionReversed => 'rose',
            self::PayoutRequest => 'amber',
        };
    }

    /**
     * The value written to `activity_log.event`.
     */
    public function activityEvent(): string
    {
        return $this->value;
    }

    /**
     * The module slug the row belongs to, so module gating hides an event whose feature is switched off.
     */
    public function module(): string
    {
        return match ($this) {
            self::Login => 'login_history',
            self::StudentReferral, self::ProjectReferral => 'collaborator_referrals',
            self::TaskUpdate => 'tasks',
            self::FileUpload, self::FileDownload => 'files',
            self::CommissionCreated, self::CommissionApproved,
            self::CommissionReversed => 'collaborator_commissions',
            self::PayoutRequest, self::PayoutPaid => 'collaborator_payouts',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Login => 'arrow-right-on-rectangle',
            self::StudentReferral => 'academic-cap',
            self::ProjectReferral => 'briefcase',
            self::TaskUpdate => 'check-circle',
            self::FileUpload => 'arrow-up-tray',
            self::FileDownload => 'arrow-down-tray',
            self::CommissionCreated => 'banknotes',
            self::CommissionApproved => 'check-badge',
            self::CommissionReversed => 'arrow-uturn-left',
            self::PayoutRequest => 'paper-airplane',
            self::PayoutPaid => 'credit-card',
        };
    }

    /**
     * The **only** `properties` keys this event's row may show in a collaborator's own feed.
     *
     * Anything not listed here never reaches the response body. `reason` appears nowhere on purpose.
     *
     * @return list<string>
     */
    public function visibleProperties(): array
    {
        return match ($this) {
            self::Login => ['ip', 'device', 'platform', 'browser'],
            self::StudentReferral => ['student_name', 'course_name', 'referral_code', 'referred_at'],
            self::ProjectReferral => ['project_name', 'project_code', 'referral_code', 'referred_at'],
            self::TaskUpdate => ['task_title', 'project_name', 'old_status', 'new_status'],
            self::FileUpload, self::FileDownload => ['file_name', 'project_name'],
            self::CommissionCreated => ['amount', 'currency', 'subject_type', 'subject_name', 'rate'],
            self::CommissionApproved => ['amount', 'currency', 'subject_type', 'subject_name'],
            self::CommissionReversed => ['amount', 'currency', 'subject_type', 'subject_name'],
            self::PayoutRequest => ['amount', 'currency', 'method'],
            self::PayoutPaid => ['amount', 'currency', 'method', 'reference', 'paid_on'],
        };
    }

    /**
     * Keep only what this event is allowed to show.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function filterProperties(array $properties): array
    {
        return array_intersect_key($properties, array_flip($this->visibleProperties()));
    }
}
