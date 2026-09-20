<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Models\Collaborator\CollaboratorReferralVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * What the browser carries between the click and the admission (phase-08-09 §6.4).
 *
 * **The browser never holds a referral code** (INV-R2). The session and the cookie hold a `visit_token`
 * — an opaque ULID naming a row this server wrote — and the server re-resolves the collaborator from
 * that row on every read. A visitor who forges the value can therefore at worst name a visit that does
 * not exist; they can never name a collaborator they never came through, which is what a code in a
 * cookie would let them do.
 *
 * Three carriers, deliberately: the session survives a form post, the cookie survives the session
 * expiring between the click and the admission a week later, and the hidden field survives a visitor
 * who blocks cookies. They are read in that order by the resolver (§6.3 ranks 3-5) and agree with each
 * other because all three are the same token.
 */
final class ReferralLinkService
{
    public const SESSION_KEY = 'referral.visit_token';

    public const COOKIE = 'ref_attr';

    /**
     * The name of the hidden field `<x-site.referral-field>` renders.
     */
    public const FORM_FIELD = 'referral_visit_token';

    public function __construct(
        private readonly ReferralTrackingService $tracking,
    ) {}

    /**
     * Called by the `CaptureReferral` middleware on every public page.
     *
     * **It never redirects, never throws and never blocks the page.** A referral link is a marketing
     * link: a visitor who followed one must see the page whatever this code thinks of their code, and a
     * 500 here would turn a partner's flyer into a broken site.
     */
    public function capture(Request $request): ?CollaboratorReferralVisit
    {
        $param = (string) setting('collaborator.referral_query_param', 'ref');
        $raw = $request->query($param);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            // Reuse the token this visitor already carries, so arriving through the same partner's link
            // on a second page grows `visits_count` instead of opening a second visit.
            $visit = $this->tracking->record($request, $raw, $this->currentToken($request));

            if ($visit === null) {
                // Tracking is switched off. There is no row to point at, so there is nothing to carry.
                return null;
            }

            $this->remember($request, $visit->visit_token);

            return $visit;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Put the token where all three carriers can be read from.
     */
    public function remember(Request $request, string $token): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $token);
        }

        Cookie::queue(Cookie::make(
            name: self::COOKIE,
            value: $token,
            minutes: $this->tracking->windowInDays() * 24 * 60,
            path: '/',
            domain: null,
            // Encrypted by Laravel's EncryptCookies, and `secure` wherever the site is served over TLS,
            // so the value is neither readable nor forgeable and never travels in clear.
            secure: $request->isSecure() || app()->isProduction(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * The token this request already carries, from whichever carrier still has it.
     *
     * The order is the resolver's own (§6.3 ranks 3-5): the session first because it is the freshest,
     * then the cookie, then a token posted back in the form.
     */
    public function currentToken(Request $request): ?string
    {
        $candidates = [
            $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null,
            $request->cookie(self::COOKIE),
            $request->input(self::FORM_FIELD),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * The value `<x-site.referral-field>` renders — a token, never a code.
     *
     * Null when the visitor carries nothing attributable, so the form renders no hidden field at all
     * rather than an empty one that looks like a value somebody could fill in.
     */
    public function tokenForForm(Request $request): ?string
    {
        $token = $this->currentToken($request);

        if ($token === null) {
            return null;
        }

        return $this->tracking->winningVisitFor($token) !== null ? $token : null;
    }

    /**
     * The partner a visitor is currently attributed to, for the "Referred by …" line on a public form.
     */
    public function currentVisit(Request $request): ?CollaboratorReferralVisit
    {
        return $this->tracking->winningVisitFor($this->currentToken($request));
    }

    /**
     * Drop the carriers after a conversion, or after the attribution expired.
     *
     * The **visit row is not touched**: it is evidence, and the fact that somebody arrived through a
     * partner stays true after the cookie is gone.
     */
    public function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }

        Cookie::queue(Cookie::forget(self::COOKIE, '/'));
    }
}
