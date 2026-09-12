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
            self::Assign,
            self::ChangeStatus => 'amber',

            self::Delete,
            self::Reject => 'rose',

            self::Approve,
            self::ViewFinancial => 'emerald',
        };
    }
}
