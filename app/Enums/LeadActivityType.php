<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What one `lead_activities` timeline row records (phase-05 §3, §2.2).
 *
 * Five types are **manual** — a salesperson logs a note, a call, a WhatsApp, an email or a meeting. Every other
 * type is written by a service and is a **system** row: `lead_activities.is_system` is true for it, and a system
 * row is never editable or deletable (policy + model hook, §2.2, test 32).
 */
enum LeadActivityType: string
{
    use HasOptions;

    case Note = 'note';
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case FollowUpScheduled = 'follow_up_scheduled';
    case FollowUpCompleted = 'follow_up_completed';
    case FollowUpMissed = 'follow_up_missed';
    case Converted = 'converted';
    case Imported = 'imported';
    case DuplicateLinked = 'duplicate_linked';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Note => 'Note',
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Meeting => 'Meeting',
            self::StatusChanged => 'Status changed',
            self::Assigned => 'Assigned',
            self::FollowUpScheduled => 'Follow-up scheduled',
            self::FollowUpCompleted => 'Follow-up completed',
            self::FollowUpMissed => 'Follow-up missed',
            self::Converted => 'Converted',
            self::Imported => 'Imported',
            self::DuplicateLinked => 'Duplicate linked',
            self::System => 'System',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Note => 'slate',
            self::Call => 'cyan',
            self::WhatsApp => 'emerald',
            self::Email => 'sky',
            self::Meeting => 'violet',
            self::StatusChanged => 'indigo',
            self::Assigned => 'brand',
            self::FollowUpScheduled => 'amber',
            self::FollowUpCompleted => 'green',
            self::FollowUpMissed => 'rose',
            self::Converted => 'emerald',
            self::Imported => 'blue',
            self::DuplicateLinked => 'orange',
            self::System => 'slate',
        };
    }

    /**
     * Written by a service, never by a person: everything except the five manual types.
     */
    public function isSystem(): bool
    {
        return ! in_array($this, self::manual(), true);
    }

    /**
     * An icon name `<x-ui.icon>` can draw (resources/data/icons.php).
     */
    public function icon(): string
    {
        return match ($this) {
            self::Note => 'document-text',
            self::Call => 'phone',
            self::WhatsApp => 'whatsapp',
            self::Email => 'envelope',
            self::Meeting => 'video-camera',
            self::StatusChanged => 'arrow-path',
            self::Assigned => 'user-plus',
            self::FollowUpScheduled => 'calendar-days',
            self::FollowUpCompleted => 'check-circle',
            self::FollowUpMissed => 'exclamation-triangle',
            self::Converted => 'check-badge',
            self::Imported => 'arrow-up-tray',
            self::DuplicateLinked => 'link',
            self::System => 'information-circle',
        };
    }

    /**
     * A contact attempt: with an outcome whose `countsAsContact()` is true it stamps
     * `leads.last_contacted_at` (§6.1 `recordActivity`).
     */
    public function recordsContact(): bool
    {
        return in_array($this, [self::Call, self::WhatsApp, self::Email, self::Meeting], true);
    }

    /**
     * The five types a person may log (the manual activity form offers exactly these).
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Note, self::Call, self::WhatsApp, self::Email, self::Meeting];
    }
}
