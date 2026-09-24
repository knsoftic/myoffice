<?php

declare(strict_types=1);

namespace App\Policies\Reporting;

use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Support\ReportRegistry;

/**
 * Who may do what with a built export (phase-19-23 §7.8, §9.5).
 *
 * **Ownership is checked regardless of role, and that includes Super Admin.** §9.5 is explicit: the
 * register is visible to whoever may export, but the *file* belongs to the person who asked for it
 * — because its contents were shaped by that person's permissions and their scope. Handing it to
 * somebody else would launder the isolation every report applies, and it would do it silently, in a
 * file that then travels by email.
 *
 * This is one of the places `Gate::before` has to be worked around rather than relied on: it allows
 * Super Admin everything before a policy is consulted, so the ownership rule could not live only
 * here. `ReportExportService::download()` re-checks it in the service, and this policy states it
 * again for the route. Two gates agreeing is cheap; one gate that turns out to be the only one is
 * how a file leaks.
 */
final class ReportExportPolicy
{
    /** The register: anybody who may export can see their own list. */
    public function viewAny(User $user): bool
    {
        return $user->can('reports.export');
    }

    public function view(User $user, ReportExport $export): bool
    {
        return $this->owns($user, $export);
    }

    /**
     * May this person download the bytes?
     *
     * Four conditions, and the last is the one that makes this a live check rather than a stored
     * one: the report's own permissions must **still** be held. Somebody who lost `view_financial`
     * yesterday cannot download yesterday's file today (PH23-16).
     */
    public function download(User $user, ReportExport $export): bool
    {
        if (! $this->owns($user, $export)) {
            return false;
        }

        if (! $export->isDownloadable()) {
            return false;
        }

        $definition = ReportRegistry::definition($export->report_key);

        if ($definition === null) {
            return false;
        }

        if (! ReportRegistry::allows($user, $definition)) {
            return false;
        }

        // Every money column's own permission, re-asked now.
        foreach ($definition->columns() as $column) {
            if ($column->isFinancial() && $column->permission !== null && ! $user->can($column->permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removing an export means removing its **file**, not its row.
     *
     * The row is the record that a file was built and who built it, and §2.26 keeps it for ever.
     * The controller calls the service's expire path; this only decides who may ask.
     */
    public function delete(User $user, ReportExport $export): bool
    {
        return $this->owns($user, $export) && $user->can('reports.export');
    }

    /**
     * The ownership rule, in one place.
     *
     * Deliberately not `$user->can(...)` anything: role plays no part. The question is whose file
     * this is.
     */
    private function owns(User $user, ReportExport $export): bool
    {
        return (int) $export->requested_by === (int) $user->getKey();
    }
}
