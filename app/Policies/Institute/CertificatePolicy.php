<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\Certificate;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;

/**
 * Who may draft, issue, revoke, print and reissue a certificate
 * (phase-19-23 §9.3, §4.2, INV-21-1, requirement §84).
 *
 * **The rules that actually protect a certificate are not in this file, and that is deliberate.**
 * `Gate::before` allows a Super Admin everything *before* a policy is consulted, so a policy is the
 * wrong place for an invariant — it is the right place for "may this role, on this branch, do this
 * ordinary thing". The immutability of an issued certificate and the refusal to delete one live in
 * the model, where no role skips them. That is D124, learned in Phase 19 by shipping a policy that
 * stopped everyone except the one role most able to do damage.
 *
 * **`delete` is narrow rather than absent.** Unlike a result, a *draft* certificate is a document
 * nobody has been given, and deleting one is reasonable — so the ability exists and this narrows it
 * to drafts. `CertificatePolicy::delete()` is therefore a real check with a real answer, and the
 * model refuses anything it lets through by mistake (INV-21-1).
 *
 * **Issuing is `change_status`, and approving is separate.** §4.2 splits them because §84's
 * `institute.certificate_issue_requires_approval` makes the second a different person's job — the
 * same shape as Phase 20's four-eyes step on a result sheet, and for the same reason.
 */
final class CertificatePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'certificates';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::View)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /** Preparing a draft. */
    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing. **A draft only** — an issued or revoked certificate is evidence of a document somebody
     * is holding, and the model throws `CertificateIssuedException` on anything but the short list of
     * columns that record what has happened *to* it. Answering false here means a screen never offers
     * an edit button that would throw.
     */
    public function update(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit)
            && $certificate->isDraft()
            && ! $this->isTrashed($certificate)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /**
     * The approval step, when `institute.certificate_issue_requires_approval` is on — and the
     * **override** of a failing eligibility report.
     *
     * An override is recorded with its failures intact plus a mandatory reason;
     * `CertificateService::issue()` enforces that, because a policy sees one row and cannot demand a
     * sentence.
     */
    public function approve(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::Approve)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    public function reject(User $user, Certificate $certificate): bool
    {
        return $this->approve($user, $certificate);
    }

    /** Issuing, revoking and reissuing — §4.2 puts all three behind one ability. */
    public function changeStatus(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus)
            && ! $this->isTrashed($certificate)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /**
     * Printing, and re-printing.
     *
     * **A draft cannot be printed.** It has no number and no QR payload, so the document would be
     * unverifiable the moment it left the building — and `print_count` on a draft would make the
     * "Reprint #n" line meaningless.
     */
    public function print(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::Print)
            && $certificate->status->isPublic()
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /** Streaming the stored PDF. Same rule as printing — it *is* printing, by another route. */
    public function download(User $user, Certificate $certificate): bool
    {
        return $this->print($user, $certificate);
    }

    public function export(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Export);
    }

    /**
     * The verification log (§4.4 puts `certificate_verifications` behind this).
     *
     * It is the abuse-detection surface, so reading it is a separate right from reading the
     * certificate: knowing that somebody tried four hundred codes from one address is an operational
     * fact, not part of a student's record.
     */
    public function viewLogs(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewLogs)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /**
     * **A draft, and nothing else.** An issued or revoked certificate is never deleted (INV-21-1) —
     * a wrong one is revoked with a reason the public verification page then shows. The model
     * refuses the act outright, including for a Super Admin who skips this method entirely; this is
     * the version a screen can read before offering a button.
     */
    public function delete(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::Delete)
            && $certificate->isDraft()
            && ! $this->isTrashed($certificate)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    public function restore(User $user, Certificate $certificate): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore)
            && $this->isTrashed($certificate)
            && $this->sharesBranch($user, $this->branchOf($certificate));
    }

    /** Never. Even a draft keeps its row, because its verification code was already allocated. */
    public function forceDelete(User $user, Certificate $certificate): bool
    {
        return false;
    }

    private function branchOf(Certificate $certificate): ?int
    {
        $branchId = $certificate->getAttribute('branch_id');

        return $branchId === null ? null : (int) $branchId;
    }
}
