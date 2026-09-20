<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Enums\ReferralConversionSubject;
use App\Enums\ReferralVisitOutcome;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Support\Device;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The click record, and the carrier that lets a referral survive a multi-step admission (phase-08-09 §6.4).
 *
 * **A refused code is still written.** `invalid_code`, `collaborator_not_eligible`, `self_referral` and
 * `bot_filtered` are named outcomes rather than missing rows, because "forty-one people this month used
 * a code that no longer exists" is something a business needs to be told, and a gap in a table cannot
 * tell it. Only the eligible ones ever attribute.
 *
 * **One row per visitor-code pair, not per page view.** A partner sending somebody to four pages should
 * see one visit with `visits_count = 4`, not four visits — otherwise every funnel number is inflated by
 * however much of the site the visitor happened to read.
 *
 * **Tracking off is not attribution off.** With `collaborator.referral_visit_tracking_enabled` false
 * this writes nothing and returns null; the session and cookie still carry the attribution, because
 * losing a partner's commission is a money loss and switching off a report is not a reason to cause one.
 */
final class ReferralTrackingService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CollaboratorCodeService $codes,
    ) {}

    /**
     * Record one arrival through a referral URL.
     *
     * The classification order matters: a crawler is filtered before the code is judged, because a bot
     * hitting a dead link should read as a bot rather than inflating the "people are using a dead code"
     * report that a business acts on.
     */
    public function record(Request $request, string $rawCode, ?string $visitToken = null): ?CollaboratorReferralVisit
    {
        if (! (bool) setting('collaborator.referral_visit_tracking_enabled', true)) {
            return null;
        }

        $code = $this->codes->normalizeReferralCode($rawCode);

        if ($code === '') {
            return null;
        }

        $agent = (string) $request->userAgent();
        $parsed = Device::parse($agent);
        $isBot = Device::isBot($agent);

        $collaborator = $this->codes->resolveCode($code);

        [$outcome, $detail] = $this->classify($request, $collaborator, $isBot);

        return $this->db->transaction(function () use ($request, $code, $collaborator, $outcome, $detail, $parsed, $isBot, $agent, $visitToken): CollaboratorReferralVisit {
            $held = $visitToken === null
                ? null
                : CollaboratorReferralVisit::query()->where('visit_token', $visitToken)->first();

            // Same visitor, same partner: one visit, `visits_count` grows.
            $existing = $held !== null && $held->referral_code === $code ? $held : null;

            // Same visitor, a **different** partner's link. `uq_crv_token` is unique — the token is the
            // cookie's whole value, and a collision would hand one visitor another's attribution — so
            // this is a second visit with a token of its own rather than a second row sharing one.
            // The caller re-queues the cookie with what comes back, and the older visit stays as
            // evidence for `first_touch` attribution to find.
            if ($held !== null && $existing === null) {
                $visitToken = null;
            }

            if ($existing !== null) {
                // The same visitor reading a second page is the same visit. `first_seen_at` is left
                // alone: it is what `first_touch` attribution and the effective-date floor read.
                $existing->forceFill([
                    'visits_count' => $existing->visits_count + 1,
                    'last_seen_at' => now(),
                    'landing_url' => Str::limit($request->fullUrl(), 255, ''),
                    'landing_path' => Str::limit('/'.ltrim($request->path(), '/'), 191, ''),
                    'query_string' => Str::limit((string) $request->getQueryString(), 255, ''),
                ])->save();

                return $existing->fresh();
            }

            $days = max(1, (int) setting('collaborator.referral_cookie_days', 30));

            $visit = new CollaboratorReferralVisit;
            $visit->forceFill([
                // A ULID rather than a UUID: it sorts by creation time, so the register's default order
                // and the token are the same order, and a support question about "the first visit" has
                // an answer that does not need a join.
                'visit_token' => $visitToken ?? (string) Str::ulid(),
                // Kept even when the outcome refuses it: "people are still using a suspended
                // partner's code" is only reportable if the row names that partner. Null only when
                // the code resolved to nobody at all.
                'collaborator_id' => $collaborator?->getKey(),
                'referral_code' => $code,
                'outcome' => $outcome->value,
                'outcome_detail' => $detail,
                'landing_url' => Str::limit($request->fullUrl(), 255, ''),
                'landing_path' => Str::limit('/'.ltrim($request->path(), '/'), 191, ''),
                'query_string' => Str::limit((string) $request->getQueryString(), 255, '') ?: null,
                'referer_url' => Str::limit((string) $request->headers->get('referer'), 255, '') ?: null,
                'ip_address' => $request->ip(),
                'user_agent' => $agent !== '' ? $agent : null,
                'device' => $parsed['device'],
                'platform' => $parsed['platform'],
                'browser' => $parsed['browser'],
                'is_bot' => $isBot,
                'user_id' => $request->user()?->getKey(),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
                'visits_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addDays($days),
            ])->save();

            return $visit->fresh();
        });
    }

    /**
     * Stamp a visit as having produced something.
     *
     * Idempotent on `converted_at`: only the **first** conversion sets it, because that timestamp is
     * what a funnel report measures and re-stamping it on a second admission would move a conversion
     * into the wrong month. Later ones append to `outcome_detail`, so the evidence that a visitor
     * converted twice is not lost either.
     */
    public function markConverted(
        CollaboratorReferralVisit $visit,
        ReferralConversionSubject $subject,
        int $subjectId,
        ?int $referralId = null,
    ): void {
        $this->db->transaction(static function () use ($visit, $subject, $subjectId, $referralId): void {
            $note = sprintf('%s #%d', $subject->value, $subjectId);

            if ($visit->converted_at !== null) {
                $visit->forceFill([
                    'outcome_detail' => Str::limit(
                        trim((string) $visit->outcome_detail.' · also '.$note), 191, ''
                    ),
                ])->save();

                return;
            }

            $visit->forceFill([
                'outcome' => ReferralVisitOutcome::Converted->value,
                'outcome_detail' => Str::limit($note, 191, ''),
                'converted_at' => now(),
                'converted_subject_type' => $subject->value,
                'converted_subject_id' => $subjectId,
                'converted_referral_id' => $referralId,
            ])->save();
        });
    }

    /**
     * The visits that could still win an attribution for one token, newest or oldest first depending on
     * `collaborator.referral_attribution_model`.
     *
     * @return Collection<int, CollaboratorReferralVisit>
     */
    public function attributableVisitsFor(string $visitToken)
    {
        $firstTouch = setting('collaborator.referral_attribution_model', 'last_touch') === 'first_touch';

        return CollaboratorReferralVisit::query()
            ->attributable()
            ->where('visit_token', $visitToken)
            ->orderBy($firstTouch ? 'first_seen_at' : 'last_seen_at', $firstTouch ? 'asc' : 'desc')
            ->get();
    }

    /**
     * The one visit that wins for this token under the configured model, or null.
     */
    public function winningVisitFor(?string $visitToken): ?CollaboratorReferralVisit
    {
        if ($visitToken === null || $visitToken === '') {
            return null;
        }

        return $this->attributableVisitsFor($visitToken)->first();
    }

    /**
     * Stamp a visit with why it could not attribute, so the register explains itself.
     *
     * Kept separate from `record()` because the reason is often only discovered later — a partner
     * suspended after the click, a cookie read past its expiry — and the row is evidence either way.
     */
    public function refuse(CollaboratorReferralVisit $visit, ReferralVisitOutcome $outcome, ?string $detail = null): CollaboratorReferralVisit
    {
        $visit->forceFill([
            'outcome' => $outcome->value,
            'outcome_detail' => $detail === null ? $visit->outcome_detail : Str::limit($detail, 191, ''),
        ])->save();

        return $visit->fresh();
    }

    /**
     * Why this arrival can or cannot attribute.
     *
     * @return array{0: ReferralVisitOutcome, 1: string|null}
     */
    private function classify(Request $request, ?Collaborator $collaborator, bool $isBot): array
    {
        if ($isBot) {
            return [ReferralVisitOutcome::BotFiltered, 'A crawler, not a visitor.'];
        }

        if ($collaborator === null) {
            return [ReferralVisitOutcome::InvalidCode, 'No collaborator has this code.'];
        }

        if ($this->isSelfReferral($request, $collaborator)) {
            return [
                ReferralVisitOutcome::SelfReferral,
                'The collaborator followed their own link while signed in.',
            ];
        }

        if (! $collaborator->status->isSelectableForNewReferral() || $collaborator->trashed()) {
            return [
                ReferralVisitOutcome::CollaboratorNotEligible,
                sprintf('%s is %s, so new referrals are not attributed to them.',
                    $collaborator->displayName(), $collaborator->status->label()),
            ];
        }

        return [ReferralVisitOutcome::Captured, null];
    }

    /**
     * A partner clicking their own link while signed in is not a referral, and the setting exists
     * because a business that uses collaborators as an internal sales team may legitimately want it to
     * be one.
     */
    private function isSelfReferral(Request $request, Collaborator $collaborator): bool
    {
        if (! (bool) setting('collaborator.referral_self_attribution_blocked', true)) {
            return false;
        }

        $userId = $request->user()?->getKey();

        return $userId !== null
            && $collaborator->user_id !== null
            && (int) $collaborator->user_id === (int) $userId;
    }

    /**
     * How many days a visit stays able to attribute.
     */
    public function windowInDays(): int
    {
        return max(1, (int) setting('collaborator.referral_cookie_days', 30));
    }

    /**
     * When a visit captured today stops counting.
     */
    public function expiryFrom(Carbon $from): Carbon
    {
        return $from->copy()->addDays($this->windowInDays());
    }
}
