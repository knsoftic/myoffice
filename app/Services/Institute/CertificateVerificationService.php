<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\DataObjects\Institute\VerificationOutcome;
use App\Enums\CertificateStatus;
use App\Enums\VerificationResult;
use App\Models\Institute\Certificate;
use App\Models\Institute\CertificateVerification;
use App\Models\Institute\StudentIdCard;
use App\Support\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public side of §84: somebody scans a QR code and asks whether the document is real
 * (phase-19-23 §6.14, INV-21-3).
 *
 * **This is the only unauthenticated write path in the institute**, so every decision here is made
 * against a hostile caller rather than a helpful one.
 *
 * **Every answer that is not "here it is" is the same answer.** A draft, a privacy opt-out, a
 * soft-deleted row and a code that never existed all return `not_found` with a 404 and an identical
 * message. Distinguishing them would hand an unauthenticated guesser an oracle: *this code is real
 * but hidden* is exactly the fact a privacy opt-out was asked for to conceal. `VerificationResult`
 * enforces the shared status code; this class enforces the shared message by never building a
 * different one.
 *
 * **A revoked certificate still resolves, and says revoked.** It is the one negative answer that is
 * *more* informative than a miss, because somebody holding a revoked certificate needs to be told —
 * that is the entire purpose of a verification page.
 *
 * **The rate limit is checked before the lookup, not after.** Throttling after querying would let
 * somebody time the database while being told to slow down, which is the timing oracle the
 * `hash_equals()` below is there to close.
 *
 * **Every attempt is logged, including the throttled ones.** `certificate_verifications` is the
 * rate limiter's evidence and §106's record of a public endpoint; a log that skipped the interesting
 * cases would be a log of nothing but success.
 */
final class CertificateVerificationService
{
    public function __construct(
        private readonly QrCodeService $qr,
    ) {}

    /**
     * Resolve a submitted code.
     *
     * One QR endpoint serves certificates **and** ID cards, so a miss on the first is tried against
     * the second before it becomes a miss overall — a student scanning their own card should not be
     * told their card does not exist because it is not a certificate.
     */
    public function verify(?string $submitted, Request $request): VerificationOutcome
    {
        $code = $this->qr->normaliseCode($submitted);

        // ---------------------------------------------------------------- 1. the limit, before the query
        $limit = max(1, (int) setting('institute.certificate_verification_rate_limit_per_minute', 20));
        $key = 'certificate-verify:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->log($code, VerificationResult::Throttled, null, $request);

            return VerificationOutcome::throttled(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        // An empty code never reaches the database. It is still logged and still counted against the
        // limit, because a flood of empty submissions is a flood.
        if ($code === '') {
            $this->log($code, VerificationResult::NotFound, null, $request);

            return VerificationOutcome::miss();
        }

        // ---------------------------------------------------------------- 2. the certificate
        $certificate = Certificate::query()
            ->withTrashed()
            ->where('verification_code', $code)
            ->first();

        if ($certificate instanceof Certificate && $this->matches($certificate->getAttribute('verification_code'), $code)) {
            return $this->resolveCertificate($certificate, $code, $request);
        }

        // ---------------------------------------------------------------- 3. the ID card
        $card = StudentIdCard::query()
            ->withTrashed()
            ->where('verification_code', $code)
            ->first();

        if ($card instanceof StudentIdCard && $this->matches($card->getAttribute('verification_code'), $code)) {
            return $this->resolveCard($card, $code, $request);
        }

        $this->log($code, VerificationResult::NotFound, null, $request);

        return VerificationOutcome::miss();
    }

    /**
     * **Built by whitelist, never by exclusion** (INV-21-3).
     *
     * `array_intersect_key` against the configured reveal list means a column added to `certificates`
     * next year is invisible here until somebody deliberately adds it to both the setting's options
     * and this map. An exclusion list would have the opposite default, and the default is what
     * matters: the failure mode of "forgot to exclude" is a leak, and the failure mode of "forgot to
     * include" is a missing line on a page.
     *
     * The four always-present keys are the document's own identity and standing, which is what a
     * verification page is *for* — there is no configuration that hides whether a certificate is
     * revoked.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(Certificate $certificate): array
    {
        $available = [
            'student_name' => $certificate->getAttribute('student_name_snapshot'),
            'father_name' => $certificate->getAttribute('father_name_snapshot'),
            'course_name' => $certificate->getAttribute('course_name_snapshot'),
            'batch_name' => $certificate->getAttribute('batch_name_snapshot'),
            'completion_date' => app_date($certificate->getAttribute('completion_date')),
            'grade' => $certificate->getAttribute('grade'),
            'percentage' => $certificate->getAttribute('percentage'),
            'trainer_name' => $certificate->getAttribute('teacher_name_snapshot'),
            'attendance_percentage' => $certificate->getAttribute('attendance_percentage'),
            // Deliberately absent from the map until the photo pipeline is built: a key the setting
            // offers and the payload cannot fill shows an empty row rather than leaking anything.
        ];

        $revealed = array_intersect_key(
            $available,
            array_flip($this->revealedKeys()),
        );

        $payload = array_merge($revealed, [
            'certificate_number' => $certificate->getAttribute('certificate_number'),
            'status' => $certificate->status->value,
            'status_label' => $certificate->status->label(),
            'issued_on' => app_date($certificate->getAttribute('issued_on')),
        ]);

        if ($certificate->isRevoked()) {
            $payload['revoked_at'] = app_date($certificate->getAttribute('revoked_at'));
            $payload['revocation_reason'] = $certificate->getAttribute('revocation_reason');
        }

        return $payload;
    }

    /**
     * The card's public face: who it belongs to and whether it is still valid.
     *
     * **A fixed list, with no setting to widen it.** `certificate_verification_reveals` governs
     * certificates; a card carries a guardian's phone and a joining date, and neither has any
     * business on a page anybody with the code can open. Somebody checking a card at a door needs
     * the name, the roll number and whether it is in date.
     *
     * @return array<string, mixed>
     */
    public function payloadForCard(StudentIdCard $card): array
    {
        return [
            'card_number' => $card->getAttribute('card_number'),
            'student_name' => $card->getAttribute('student_name_snapshot'),
            'student_code' => $card->getAttribute('student_code_snapshot'),
            'course_name' => $card->getAttribute('course_name_snapshot'),
            'batch_name' => $card->getAttribute('batch_name_snapshot'),
            'valid_until' => app_date($card->getAttribute('valid_until')),
            'status' => $card->status->value,
            'status_label' => $card->status->label(),
            'usable' => $card->isUsable(),
        ];
    }

    // ===========================================================================================

    private function resolveCertificate(Certificate $certificate, string $code, Request $request): VerificationOutcome
    {
        // Every reason to say no collapses to one answer. See the class note.
        if (! $certificate->isPubliclyResolvable()) {
            $result = $certificate->status === CertificateStatus::Draft
                ? VerificationResult::NotFound
                : VerificationResult::NotPublic;

            // The *logged* result distinguishes them, because the office needs to know a real code
            // was tried; the *response* does not, because the caller does not get to know.
            $this->log($code, $result, $certificate, $request);

            return VerificationOutcome::miss();
        }

        $result = $certificate->isRevoked() ? VerificationResult::Revoked : VerificationResult::Valid;

        $this->log($code, $result, $certificate, $request);
        $this->countHit($certificate);

        return VerificationOutcome::found($result, $this->publicPayload($certificate), 'certificate');
    }

    private function resolveCard(StudentIdCard $card, string $code, Request $request): VerificationOutcome
    {
        if ($card->getAttribute('deleted_at') !== null) {
            $this->log($code, VerificationResult::NotFound, null, $request);

            return VerificationOutcome::miss();
        }

        // A card that is expired, lost or revoked still resolves and says so — the same reasoning as
        // a revoked certificate. Somebody checking a card at a door needs to be told it is not valid,
        // not told it does not exist.
        $result = $card->isUsable() ? VerificationResult::Valid : VerificationResult::Revoked;

        $this->log($code, $result, null, $request);

        return VerificationOutcome::found($result, $this->payloadForCard($card), 'id_card');
    }

    /**
     * A constant-time comparison on top of the indexed lookup.
     *
     * **Honest about what it buys.** The index lookup has already leaked something by the time this
     * runs — that is unavoidable without a full table scan, which would be a denial of service. What
     * `hash_equals()` closes is the *second* comparison: it stops a near-miss and an exact match
     * taking measurably different times inside PHP, and it costs nothing. The real defence against
     * enumeration is the rate limit above and the 32^16 code space, not this.
     */
    private function matches(mixed $stored, string $submitted): bool
    {
        return hash_equals((string) $stored, $submitted);
    }

    /**
     * Always written — including for a throttled attempt and a miss. See the class note.
     *
     * `saveQuietly()` is wrong here and `create()` is right: the model's `updating` and `deleting`
     * hooks refuse, but a *create* is exactly what this is.
     */
    private function log(string $code, VerificationResult $result, ?Certificate $certificate, Request $request): void
    {
        $agent = (string) $request->userAgent();

        CertificateVerification::query()->create([
            'certificate_id' => $certificate?->getKey(),
            // Truncated rather than refused: a 400-character submission is itself worth recording,
            // and the column is 40. The pattern is what matters, not the whole payload.
            'submitted_code' => mb_substr($code, 0, 40),
            'result' => $result->value,
            'ip_address' => $request->ip(),
            'user_agent' => $agent === '' ? null : $agent,
            'device' => $agent === '' ? null : mb_substr(Device::device($agent), 0, 64),
            'referer' => mb_substr((string) $request->headers->get('referer'), 0, 255) ?: null,
        ]);
    }

    /**
     * The cache on the certificate, which stays re-derivable by counting the log.
     *
     * `saveQuietly()` so counting a public scan does not write an activity row or bump
     * `updated_at` — a verification is something that happened *to* the certificate, not a change to
     * it, and INV-21-1's immutability list includes these two columns for exactly this.
     */
    private function countHit(Certificate $certificate): void
    {
        $certificate->forceFill([
            'verification_count' => (int) $certificate->getAttribute('verification_count') + 1,
            'last_verified_at' => Carbon::now(),
        ])->saveQuietly();
    }

    /**
     * The reveal list, defaulted to the contract's four when the setting is empty or malformed.
     *
     * A misconfigured multiselect must not fall through to "reveal everything"; falling back to the
     * narrow default is the only safe direction for a failure in a privacy setting.
     *
     * @return list<string>
     */
    private function revealedKeys(): array
    {
        $configured = setting('institute.certificate_verification_reveals', null);

        if (! is_array($configured) || $configured === []) {
            return ['student_name', 'course_name', 'completion_date', 'grade'];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => (string) $key, $configured),
            static fn (string $key): bool => $key !== '',
        ));
    }
}
