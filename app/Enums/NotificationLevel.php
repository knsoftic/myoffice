<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How much a notification is asking for (phase-19-23 §3.4, §6.19, requirement §97).
 *
 * **Four, and the ladder is deliberately short.** A fifth level is how a notification system becomes
 * one where everything is urgent and nothing is read. `critical` is reserved for things somebody has
 * to act on today — an SLA breached, a payout awaiting approval, a wallet that disagrees with its
 * ledger — and the registry, not the caller, decides which events get it, so no individual dispatch
 * can promote itself.
 *
 * **`color()` drives the bell dot**, which is the only place in the shell where one unread row
 * changes what the whole topbar looks like. That is why the level lives on the event definition in
 * `NotificationRegistry` rather than on the notification class: it is a property of the *kind* of
 * thing that happened, and a class that chose its own would eventually choose `critical` for a
 * newsletter.
 */
enum NotificationLevel: string
{
    use HasOptions;

    /** Something happened that you asked to hear about. */
    case Info = 'info';

    /** Something you were waiting for has gone right. */
    case Success = 'success';

    /** Something needs attention before it becomes a problem. */
    case Warning = 'warning';

    /** Something needs acting on now. */
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'For information',
            self::Success => 'Done',
            self::Warning => 'Needs attention',
            self::Critical => 'Act now',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Info => 'sky',
            self::Success => 'emerald',
            self::Warning => 'amber',
            self::Critical => 'rose',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Info => 'information-circle',
            self::Success => 'check-circle',
            self::Warning => 'exclamation-triangle',
            self::Critical => 'exclamation-circle',
        };
    }

    /** Does the bell dot turn red for this one? */
    public function demandsAttention(): bool
    {
        return $this === self::Warning || $this === self::Critical;
    }
}
