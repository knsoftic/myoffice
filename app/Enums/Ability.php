<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The ability half of a permission name ("{module}.{ability}", e.g. students.view_any).
 * Declared once here and consumed by App\Support\PermissionRegistry.
 */
enum Ability: string
{
    use HasOptions;

    case ViewAny = 'view_any';
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Delete = 'delete';
    case Restore = 'restore';
    case Approve = 'approve';
    case Reject = 'reject';
    case Assign = 'assign';
    case Print = 'print';
    case Export = 'export';
    case Import = 'import';
    case Upload = 'upload';
    case Download = 'download';
    case ChangeStatus = 'change_status';
    case ViewFinancial = 'view_financial';
    case ViewReports = 'view_reports';
    case ViewLogs = 'view_logs';

    /*
    |--------------------------------------------------------------------------
    | Narrowly-scoped abilities
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md §4: beyond the general abilities above, a module may declare a narrow ability for
    | ONE guarded operation, and it must never be widened into a general `edit`. They are declared
    | last so the general list keeps the contract's order, and they are enum cases rather than bare
    | strings because App\Support\PermissionRegistry's integrity tests require every ability of a
    | real (non-portal) module to resolve back through this enum — that is what keeps a renamed
    | ability a compile-time problem instead of a silent 403.
    |
    |  · `edit_mail` (Phase 2, settings) — writing the SMTP credentials and sending a test through
    |    them. Their owner can read every password-reset mail in the system, so phase-01 §5 gives
    |    Admin "everything except `settings.edit` of SMTP"; this is that carve-out.
    |  · `link_invoice` (Phase 10, project_payments, decision D43) lands here the same way.
    |
    */
    case EditMail = 'edit_mail';

    /**
     * phase-10-12 §4.1 / D43, and **this ability permits exactly one mutation**: moving
     * `project_payments.invoice_id` from NULL to a value and back, through `InvoiceService` alone,
     * while the payment is not voided, with a mandatory reason and zero commission effect.
     *
     * It belongs to **no preset** — and specifically not to `MONEY`, so holding it reveals no amount by
     * itself. It is written on `project_payments` and on no other slug. A narrow ability must never be
     * widened into a general `edit`: a registered `edit` on a money module is exactly what a later role
     * edit or seeder would quietly reuse for a real edit, while a single-purpose one cannot be reused
     * because no other code path checks it.
     */
    case LinkInvoice = 'link_invoice';

    public function label(): string
    {
        return match ($this) {
            self::ViewAny => 'View Any',
            self::View => 'View',
            self::Create => 'Create',
            self::Edit => 'Edit',
            self::Delete => 'Delete',
            self::Restore => 'Restore',
            self::Approve => 'Approve',
            self::Reject => 'Reject',
            self::Assign => 'Assign',
            self::Print => 'Print',
            self::Export => 'Export',
            self::Import => 'Import',
            self::Upload => 'Upload',
            self::Download => 'Download',
            self::ChangeStatus => 'Change Status',
            self::ViewFinancial => 'View Financial Data',
            self::ViewReports => 'View Reports',
            self::ViewLogs => 'View Logs',
            self::EditMail => 'Edit Mail',
            self::LinkInvoice => 'Link to an Invoice',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ViewAny,
            self::View,
            self::ViewReports,
            self::ViewLogs,
            self::Download,
            self::Export,
            self::Print => 'slate',

            self::Create,
            self::Import,
            self::Upload,
            self::Restore => 'indigo',

            self::Edit,
            self::EditMail,
            self::Assign,
            self::ChangeStatus,
            // Amber with the other write abilities, not emerald with `view_financial`: it changes
            // something, and it deliberately reveals no amount.
            self::LinkInvoice => 'amber',

            self::Delete,
            self::Reject => 'rose',

            self::Approve,
            self::ViewFinancial => 'emerald',
        };
    }
}
