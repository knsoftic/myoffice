<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How closely a recorded change is held (phase-19-23 §3.5, §107).
 *
 * **This decides who may read the old and new *values*, never who may see that a change happened.**
 * That distinction is the whole of §107: an audit trail whose rows disappear for some readers is not
 * an audit trail, because the absence itself is unauditable. So every row is listed for anybody who
 * may open the trail; a `financial` row's figures are **withheld** — shown as a locked cell naming
 * the permission that would open it — rather than the row being dropped.
 *
 * **`sensitive` and `financial` differ in what the numbers mean, not in how secret they are.** A
 * changed commission *rate* and a changed student *phone number* are both things somebody should be
 * able to ask about; only one of them is money, and only money is gated on `view_financial` when
 * `reports.audit_show_financial_values` is off.
 */
enum AuditSensitivity: string
{
    use HasOptions;

    /** An ordinary change. Visible in full to anybody who may read the trail. */
    case Normal = 'normal';

    /** Personal data, credentials, roles, settings: listed and read in full, but flagged. */
    case Sensitive = 'sensitive';

    /** Money. The values can be withheld; the fact of the change never is. */
    case Financial = 'financial';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Sensitive => 'Sensitive',
            self::Financial => 'Financial',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Normal => 'slate',
            self::Sensitive => 'amber',
            self::Financial => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Normal => 'An ordinary change, shown in full.',
            self::Sensitive => 'Personal data, access or configuration. Shown in full, and flagged.',
            self::Financial => 'Money. The figures need the module\'s financial permission; the change itself is always listed.',
        };
    }

    /**
     * Do this row's old and new values need `view_financial` on the subject's module?
     *
     * Only when `reports.audit_show_financial_values` is off. The default is **on**, because an
     * institute that has given somebody the audit trail has usually already decided they may see
     * what changed — and a trail of rows reading "a number became another number" is a trail that
     * answers nothing.
     */
    public function valuesNeedFinancialPermission(): bool
    {
        return $this === self::Financial
            && ! (bool) setting('reports.audit_show_financial_values', true);
    }

    /** Worth a badge on the row. */
    public function isFlagged(): bool
    {
        return $this !== self::Normal;
    }
}
