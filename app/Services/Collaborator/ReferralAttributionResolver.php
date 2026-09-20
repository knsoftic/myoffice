<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Enums\ReferralCandidateChannel;
use App\Enums\ReferralVisitOutcome;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Support\Collaborator\ReferralCandidate;
use App\Support\Collaborator\ReferralDecision;
use App\Support\Collaborator\ReferralResolutionContext;
use Illuminate\Support\Carbon;

/**
 * Who gets the credit, and why (phase-08-09 §6.3, [D-P9-2]).
 *
 * **Six ranks, and the first eligible one wins.** A member of staff naming a partner at the desk always
 * outranks a cookie, because the person standing in front of them said something and the cookie did not.
 * An applicant typing a code they were given outranks a link they happened to click, for the same
 * reason. Everything below that is the click, read from whichever carrier still has the token.
 *
 * **Nothing the browser sends is trusted as a code** (INV-R2). Ranks 3-5 carry a **visit token**; the
 * collaborator is re-resolved from the row that token names and re-validated here. Rank 6 is the raw
 * `?ref=` still on the submitting request, and it too is re-resolved server-side rather than believed.
 *
 * **A losing candidate is kept, not discarded.** The question this system will actually be asked is
 * never "who won" — it is "why did my code not win", six weeks later, by somebody whose commission
 * depended on the answer. `ReferralDecision::$losers` is what answers it, and Phase 10 writes each one
 * as a superseded row through the spine's published method rather than this class touching that table.
 *
 * **This class writes nothing at all.** It reads, decides, and returns. The only side effect it permits
 * itself is stamping a *visit* with why it could not attribute — which is the visit explaining itself,
 * not an attribution being made.
 */
final class ReferralAttributionResolver
{
    public function __construct(
        private readonly CollaboratorCodeService $codes,
        private readonly ReferralTrackingService $tracking,
    ) {}

    public function resolve(ReferralResolutionContext $context): ReferralDecision
    {
        $model = (string) setting('collaborator.referral_attribution_model', 'last_touch');
        $candidates = $this->candidates($context);

        /** @var ReferralCandidate|null $winner */
        $winner = null;

        foreach ($candidates as $candidate) {
            if ($candidate->isEligible()) {
                $winner = $candidate;
                break;
            }
        }

        // Rank 1 with an explicit "no collaborator" is a decision, not an absence: it wins with a null
        // winner, and every captured candidate below it becomes a loser with its reason recorded. It is
        // resolved **before** the lower ranks are consulted for a winner — asking afterwards would let a
        // cookie quietly overrule the person who looked at the form and said there is no partner.
        //
        // Naming somebody and saying "nobody" are contradictory, and naming somebody is the more
        // specific answer, so a pick wins over the checkbox rather than the other way round.
        $staffChoseNone = $context->staffChoseNone && $context->staffCollaboratorId === null;

        if ($staffChoseNone) {
            $winner = null;
        }

        $losers = $this->losers($candidates, $winner, $staffChoseNone);

        $overrideRequired = $this->overrideReasonRequired($winner, $losers, $context);

        return new ReferralDecision(
            winner: $winner?->collaborator,
            channel: $winner?->channel,
            source: $winner?->channel->referralSource(),
            visit: $winner?->visit,
            losers: $losers,
            overrideReasonRequired: $overrideRequired,
            rejectionReason: $staffChoseNone
                ? 'Staff recorded that there is no referring collaborator.'
                : ($winner === null ? $this->firstRejection($candidates) : null),
            effectiveFrom: $this->effectiveFrom($winner, $context),
            attributionModel: $model,
            subject: $context->subject,
            subjectId: $context->subjectId,
            overrideReason: $context->overrideReason,
        );
    }

    /**
     * Every rank that produced something, in ladder order.
     *
     * A rank that resolved to nobody at all is left out entirely; a rank that resolved to somebody
     * *ineligible* is kept with its reason, because "your suspended partner's link was used" is a thing
     * the register should be able to say.
     *
     * @return list<ReferralCandidate>
     */
    public function candidates(ReferralResolutionContext $context): array
    {
        $candidates = [];

        foreach (ReferralCandidateChannel::ladder() as $channel) {
            $candidate = match ($channel) {
                ReferralCandidateChannel::StaffSelection => $this->fromStaff($context),
                ReferralCandidateChannel::TypedCode => $this->fromTypedCode($context),
                ReferralCandidateChannel::Session, ReferralCandidateChannel::Cookie,
                ReferralCandidateChannel::HiddenField => $this->fromToken($context, $channel),
                ReferralCandidateChannel::QueryParam => $this->fromQueryParam($context),
            };

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Rank 1. A member of staff named somebody.
     *
     * `suspended` is refused outright — a suspension exists to stop new business flowing to somebody,
     * and letting a form override it would make the state decorative. `pending` and `inactive` are
     * allowed **with an explicit confirmation**, because somebody genuinely does refer a student the
     * week before their own application is approved.
     */
    private function fromStaff(ReferralResolutionContext $context): ?ReferralCandidate
    {
        if ($context->staffCollaboratorId === null) {
            return null;
        }

        $collaborator = Collaborator::query()->find($context->staffCollaboratorId);

        if ($collaborator === null) {
            return new ReferralCandidate(
                channel: ReferralCandidateChannel::StaffSelection,
                collaborator: null,
                rejectedBecause: 'The selected collaborator no longer exists.',
            );
        }

        $rejection = match (true) {
            $collaborator->trashed() => 'That collaborator has been removed.',
            $collaborator->status->isSelectableForNewReferral() => null,
            $collaborator->status->needsStaffConfirmationForReferral() && $context->staffConfirmedIneligible => null,
            $collaborator->status->needsStaffConfirmationForReferral() => sprintf(
                '%s is %s. Confirm the selection to attribute anyway.',
                $collaborator->displayName(),
                $collaborator->status->label(),
            ),
            default => sprintf(
                '%s is %s, and a suspension exists precisely to stop new business flowing to somebody.',
                $collaborator->displayName(),
                $collaborator->status->label(),
            ),
        };

        return new ReferralCandidate(
            channel: ReferralCandidateChannel::StaffSelection,
            collaborator: $collaborator,
            code: $collaborator->referral_code,
            rejectedBecause: $rejection,
        );
    }

    /**
     * Rank 2. The applicant typed a code into the visible field (§67).
     */
    private function fromTypedCode(ReferralResolutionContext $context): ?ReferralCandidate
    {
        $typed = trim((string) $context->typedCode);

        if ($typed === '') {
            return null;
        }

        $code = $this->codes->normalizeReferralCode($typed);
        $collaborator = $this->codes->isWellFormed($code) ? $this->codes->resolveCode($code) : null;

        if ($collaborator === null) {
            return new ReferralCandidate(
                channel: ReferralCandidateChannel::TypedCode,
                collaborator: null,
                code: $code,
                rejectedBecause: 'No collaborator has that code.',
            );
        }

        return new ReferralCandidate(
            channel: ReferralCandidateChannel::TypedCode,
            collaborator: $collaborator,
            code: $code,
            rejectedBecause: $this->ineligibleBecause($collaborator, $context),
        );
    }

    /**
     * Ranks 3, 4 and 5 — the session, the cookie and the hidden field.
     *
     * All three carry the same kind of value and are validated identically; they differ only in which
     * one still has it. A visitor whose session expired between the click and the admission is carried
     * by the cookie; one who blocks cookies is carried by the hidden field the form rendered.
     */
    private function fromToken(ReferralResolutionContext $context, ReferralCandidateChannel $channel): ?ReferralCandidate
    {
        $token = match ($channel) {
            ReferralCandidateChannel::Session => $context->request->hasSession()
                ? $context->request->session()->get(ReferralLinkService::SESSION_KEY)
                : null,
            ReferralCandidateChannel::Cookie => $context->request->cookie(ReferralLinkService::COOKIE),
            default => $context->request->input(ReferralLinkService::FORM_FIELD),
        };

        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $visit = CollaboratorReferralVisit::query()->where('visit_token', trim($token))->first();

        if ($visit === null) {
            // A token naming nothing is what a forged value looks like, and it can do no harm: there is
            // no row, so there is no collaborator to steal (INV-R2).
            return new ReferralCandidate(
                channel: $channel,
                collaborator: null,
                rejectedBecause: 'That visit does not exist.',
            );
        }

        return $this->fromVisit($visit, $channel, $context);
    }

    /**
     * Rank 6. `?ref=` still on the submitting request — a form posted straight from a referral URL.
     *
     * Re-resolved and re-validated server-side, never believed as sent.
     */
    private function fromQueryParam(ReferralResolutionContext $context): ?ReferralCandidate
    {
        $param = (string) setting('collaborator.referral_query_param', 'ref');
        $raw = $context->request->input($param);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $code = $this->codes->normalizeReferralCode($raw);
        $collaborator = $this->codes->isWellFormed($code) ? $this->codes->resolveCode($code) : null;

        if ($collaborator === null) {
            return new ReferralCandidate(
                channel: ReferralCandidateChannel::QueryParam,
                collaborator: null,
                code: $code,
                rejectedBecause: 'No collaborator has that code.',
            );
        }

        return new ReferralCandidate(
            channel: ReferralCandidateChannel::QueryParam,
            collaborator: $collaborator,
            code: $code,
            rejectedBecause: $this->ineligibleBecause($collaborator, $context),
        );
    }

    /**
     * Turn one visit into a candidate, applying the five modifiers of §6.3 in the order that gives the
     * most honest reason: a crawler is a crawler before it is an expiry, and an expiry is an expiry
     * before the partner's own state is anybody's business.
     */
    private function fromVisit(
        CollaboratorReferralVisit $visit,
        ReferralCandidateChannel $channel,
        ReferralResolutionContext $context,
    ): ReferralCandidate {
        $collaborator = $visit->collaborator;

        $rejection = match (true) {
            $visit->is_bot => 'That visit was a crawler.',
            $visit->hasExpired() => sprintf('That visit expired on %s.', app_date($visit->expires_at)),
            ! $visit->outcome->attributable() => sprintf('That visit is %s.', $visit->outcome->label()),
            $collaborator === null => 'That visit resolved to no collaborator.',
            default => $this->ineligibleBecause($collaborator, $context),
        };

        // The visit explains itself, so the register shows the reason rather than a silent gap. This is
        // the visit recording its own state — no attribution is written anywhere by this class.
        if ($rejection !== null && $visit->outcome === ReferralVisitOutcome::Captured) {
            $stamp = match (true) {
                $visit->is_bot => ReferralVisitOutcome::BotFiltered,
                $visit->hasExpired() => ReferralVisitOutcome::Expired,
                $collaborator !== null && $this->isSelfReferral($collaborator, $context) => ReferralVisitOutcome::SelfReferral,
                default => ReferralVisitOutcome::CollaboratorNotEligible,
            };

            $visit = $this->tracking->refuse($visit, $stamp, $rejection);
        }

        return new ReferralCandidate(
            channel: $channel,
            collaborator: $collaborator,
            code: $visit->referral_code,
            visit: $visit,
            rejectedBecause: $rejection,
        );
    }

    /**
     * Why this partner cannot take a new referral — or null when they can.
     */
    private function ineligibleBecause(Collaborator $collaborator, ReferralResolutionContext $context): ?string
    {
        if ($collaborator->trashed()) {
            return 'That collaborator has been removed.';
        }

        if ($this->isSelfReferral($collaborator, $context)) {
            return 'A collaborator does not refer themselves.';
        }

        if (! $collaborator->status->isSelectableForNewReferral()) {
            return sprintf('%s is %s, so new referrals are not attributed to them.',
                $collaborator->displayName(), $collaborator->status->label());
        }

        return null;
    }

    /**
     * The partner's own login, either as the visitor or as the subject.
     *
     * Both halves matter: a partner clicking their own link is one case, and a partner enrolling
     * themselves on a course is the other, and only checking the first would leave the second paying
     * somebody commission on their own fee.
     */
    private function isSelfReferral(Collaborator $collaborator, ReferralResolutionContext $context): bool
    {
        if (! (bool) setting('collaborator.referral_self_attribution_blocked', true)) {
            return false;
        }

        if ($collaborator->user_id === null) {
            return false;
        }

        $ownUserId = (int) $collaborator->user_id;

        return $ownUserId === (int) $context->request->user()?->getKey()
            || ($context->subjectUserId !== null && $ownUserId === $context->subjectUserId);
    }

    /**
     * Every candidate that named a **different** partner from the winner.
     *
     * An ineligible candidate is a loser too — it is evidence that somebody's link was used, which is
     * the whole point of keeping it — but a candidate naming the *same* partner as the winner is not:
     * that is one attribution reached twice, not a dispute.
     *
     * @param  list<ReferralCandidate>  $candidates
     * @return list<ReferralCandidate>
     */
    private function losers(array $candidates, ?ReferralCandidate $winner, bool $staffChoseNone): array
    {
        $losers = [];
        $winnerId = $winner?->collaboratorId();

        foreach ($candidates as $candidate) {
            if ($winner !== null && $candidate === $winner) {
                continue;
            }

            $id = $candidate->collaboratorId();

            if ($id === null) {
                continue;
            }

            if (! $staffChoseNone && $winnerId !== null && $id === $winnerId) {
                continue;
            }

            $losers[] = $candidate;
        }

        return $losers;
    }

    /**
     * Does this submission need a written reason?
     *
     * Only when staff overruled something: a staff pick that contradicts a captured candidate, or an
     * explicit "no collaborator" over one. A submission where nothing was captured has nothing to
     * explain, and demanding a reason for it would train people to type "n/a".
     */
    private function overrideReasonRequired(?ReferralCandidate $winner, array $losers, ReferralResolutionContext $context): bool
    {
        if (! (bool) setting('collaborator.referral_override_reason_required', true)) {
            return false;
        }

        if (! $context->hasStaffInput()) {
            return false;
        }

        foreach ($losers as $loser) {
            if ($loser->isEligible()) {
                return true;
            }
        }

        return false;
    }

    /**
     * `min(today, the subject's business date)`, floored at the winning visit's first sighting.
     *
     * A back-dated admission credits the partner from the admission date, **never from before the click**
     * — otherwise a partner whose link was used yesterday could be credited for an enrolment dated last
     * month — and never from the future, because the spine resolves every payment against this window.
     */
    private function effectiveFrom(?ReferralCandidate $winner, ReferralResolutionContext $context): Carbon
    {
        $today = now()->startOfDay();
        $date = $context->subjectDate?->copy()->startOfDay() ?? $today;

        if ($date->greaterThan($today)) {
            $date = $today;
        }

        $firstSeen = $winner?->visit?->first_seen_at;

        if ($firstSeen !== null) {
            $floor = $firstSeen->copy()->startOfDay();

            if ($date->lessThan($floor)) {
                $date = $floor;
            }
        }

        return $date;
    }

    /**
     * The first reason the ladder gave, for a decision with no winner — so a screen can say "your code
     * was not used because …" rather than falling silent.
     *
     * @param  list<ReferralCandidate>  $candidates
     */
    private function firstRejection(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate->rejectedBecause !== null) {
                return $candidate->rejectedBecause;
            }
        }

        return null;
    }
}
