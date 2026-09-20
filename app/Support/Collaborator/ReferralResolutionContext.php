<?php

declare(strict_types=1);

namespace App\Support\Collaborator;

use App\Enums\ReferralConversionSubject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Everything the ladder is allowed to look at (phase-08-09 §6.3).
 *
 * **The staff pick and the typed code are separate fields on purpose.** They are different facts — one
 * is a member of staff saying "this partner", the other is an applicant repeating something they were
 * told — and collapsing them would make a receptionist's decision indistinguishable from a visitor's
 * claim at exactly the moment the difference matters.
 *
 * `staffChoseNone` is likewise not the absence of `staffCollaboratorId`. "I did not fill this in" and
 * "I looked and there is no partner" are different answers, and only the second one outranks a captured
 * code.
 */
final readonly class ReferralResolutionContext
{
    public function __construct(
        public Request $request,
        public ?ReferralConversionSubject $subject = null,
        public ?int $subjectId = null,
        public ?int $staffCollaboratorId = null,
        public bool $staffChoseNone = false,
        public bool $staffConfirmedIneligible = false,
        public ?string $typedCode = null,
        public ?User $actor = null,
        public ?string $overrideReason = null,
        /** The subject's own business date — an admission back-dated to last week resolves from then. */
        public ?Carbon $subjectDate = null,
        /** The subject's own user, for self-referral detection when it is not the visitor. */
        public ?int $subjectUserId = null,
    ) {}

    public function hasStaffInput(): bool
    {
        return $this->staffCollaboratorId !== null || $this->staffChoseNone;
    }
}
