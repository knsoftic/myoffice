<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a certificate has got to (phase-19-23 §3.3, requirement §84).
 *
 * **Three cases, and `reissued` is deliberately not one of them.** [D-21-3]: it is tempting to add,
 * and it would be wrong, because a single certificate is never simultaneously "the revoked one" and
 * "the new one". A reissue is the original moving to `revoked` plus a fresh `draft` carrying
 * `reissue_of_id` — two rows, one link, and `uq_ce_reissue` permitting exactly one successor. A
 * fourth case would let one row claim both histories and make "which certificate does this QR code
 * resolve to" ambiguous.
 *
 * **A revoked certificate stays publicly resolvable, and that is the point.** Somebody holding a
 * printed certificate scans its code; answering `not_found` would tell them nothing, while answering
 * *revoked, on this date, for this reason* is the whole reason a verification page exists. Only a
 * `draft` — a document nobody has been given — is invisible to the public.
 */
enum CertificateStatus: string
{
    use HasOptions;

    /** Being prepared. Editable, unnumbered, and nobody has it. */
    case Draft = 'draft';

    /** Handed over. Immutable but for print counts, verification counts and the PDF (INV-21-1). */
    case Issued = 'issued';

    /** Withdrawn with a reason, which the public page shows. */
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Revoked => 'Revoked',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Issued => 'emerald',
            self::Revoked => 'rose',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Draft => 'Being prepared. It has no number yet and nobody has been given it.',
            self::Issued => 'Handed over. Its details are fixed so the printed copy stays reproducible.',
            self::Revoked => 'Withdrawn. The verification page still resolves it, and says so.',
        };
    }

    public function isIssued(): bool
    {
        return $this === self::Issued;
    }

    /**
     * May the public verification page resolve it?
     *
     * `issued` **and** `revoked` — see the class note. A draft answers `not_found`, which is true:
     * as far as anybody outside the office is concerned it does not exist.
     */
    public function isPublic(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Everything except a draft. An issued or revoked row is evidence of a document somebody is
     * holding, and editing it would make the paper and the record disagree.
     */
    public function isImmutable(): bool
    {
        return $this !== self::Draft;
    }
}
