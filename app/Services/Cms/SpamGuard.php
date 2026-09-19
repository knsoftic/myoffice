<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Services\Cms\Data\SpamVerdict;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spam protection for the public contact and job-application forms — no external captcha (phase-04 §6.9).
 *
 * Hard signals, checked in this order; the first that matches decides:
 *
 *   | signal                                                           | reason      |
 *   |------------------------------------------------------------------|-------------|
 *   | the honeypot input is non-empty                                  | `honeypot`  |
 *   | `form_token` missing, undecryptable, from the future or > 12 h   | `token`     |
 *   | render → submit faster than `website.contact_min_submit_seconds` | `too_fast`  |
 *   | the same sha256(email + message) within the last 10 minutes      | `duplicate` |
 *   | subject or message contains a `website.spam_blocklist` entry     | `blocklist` |
 *
 * then a score — more than two URLs in the message +2, a message shorter than 15 characters or without a
 * single space +1, a URL or BBCode marker in the name +2 — and **score ≥ 3** is spam with reason `score`.
 *
 * The guard only judges. What happens to a spam verdict is the caller's business rule: an inquiry is
 * stored and never routed or notified; an application is dropped. Both return the same success response,
 * so a bot learns nothing from the answer. Rate limiting is separate (`PublicFormRateLimits`).
 */
final class SpamGuard
{
    public const HONEYPOT_FIELD = 'website_url';

    public const TIMESTAMP_FIELD = 'form_token';

    public const TOKEN_MAX_AGE_SECONDS = 43_200;

    public const DUPLICATE_WINDOW_SECONDS = 600;

    public const SCORE_THRESHOLD = 3;

    private const CACHE_PREFIX = 'spam-guard:duplicate:';

    /** `filled_in_seconds` is an unsignedSmallInteger. */
    private const MAX_RECORDED_SECONDS = 65_535;

    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly CacheRepository $cache,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Judge one submission.
     *
     * `$input` is the validated payload. The honeypot and the token are read from it and, when a Form
     * Request did not pass them through, from the request itself. The blocklist and the score read
     * `message` (contact form) or `cover_letter` (application).
     *
     * The `duplicate` fingerprint is `sha256(email + message)` of the **contact form** only (§6.9). An
     * application has no such signal: its duplicate rule is `uq_job_application_per_job`, answered as a 422 on
     * `email` (§6.8 invariant 4, test 35) — one answer, not two. Fingerprinting the cover letter would turn a
     * re-submitted application into a silent "thank you" (masking that 422), and would silently drop a
     * corrected re-submission whose first attempt was refused after the guard ran (a CV the service's second
     * MIME gate rejected), because an application verdict stores nothing.
     *
     * @param  array<string, mixed>  $input
     */
    public function verdict(array $input, Request $request): SpamVerdict
    {
        $elapsed = $this->elapsedSeconds($this->field($input, $request, self::TIMESTAMP_FIELD));
        $filled = $elapsed === null ? null : min($elapsed, self::MAX_RECORDED_SECONDS);

        if (trim($this->field($input, $request, self::HONEYPOT_FIELD)) !== '') {
            return SpamVerdict::spam(SpamVerdict::HONEYPOT, 0, $filled);
        }

        if ($elapsed === null) {
            return SpamVerdict::spam(SpamVerdict::TOKEN, 0, null);
        }

        if ($elapsed < $this->minimumSeconds()) {
            return SpamVerdict::spam(SpamVerdict::TOO_FAST, 0, $filled);
        }

        $email = Str::lower(trim($this->text($input['email'] ?? null)));
        $message = $this->text($input['message'] ?? $input['cover_letter'] ?? null);
        $subject = $this->text($input['subject'] ?? null);
        $name = $this->text($input['name'] ?? $input['applicant_name'] ?? null);

        if ($this->isDuplicate($email, $this->text($input['message'] ?? null))) {
            return SpamVerdict::spam(SpamVerdict::DUPLICATE, 0, $filled);
        }

        if ($this->matchesBlocklist($subject.' '.$message)) {
            return SpamVerdict::spam(SpamVerdict::BLOCKLIST, 0, $filled);
        }

        $score = $this->score($name, $message);

        return $score >= self::SCORE_THRESHOLD
            ? SpamVerdict::spam(SpamVerdict::SCORE, $score, $filled)
            : SpamVerdict::clean($score, $filled);
    }

    /**
     * The honeypot input's name: plausible to a bot, never used by a person (the field is visually hidden).
     */
    public function honeypotField(): string
    {
        return self::HONEYPOT_FIELD;
    }

    /**
     * The hidden input that carries `signedTimestamp()`.
     */
    public function timestampField(): string
    {
        return self::TIMESTAMP_FIELD;
    }

    /**
     * The render moment, encrypted with the application key — the form component puts it in a hidden
     * input, so it cannot be forged or back-dated without the key.
     */
    public function signedTimestamp(): string
    {
        return $this->encrypter->encryptString((string) Carbon::now()->getTimestamp());
    }

    /**
     * Seconds between rendering the form and submitting it, or null when the token is missing, forged,
     * from the future or older than 12 hours.
     */
    private function elapsedSeconds(string $token): ?int
    {
        $token = trim($token);

        if ($token === '' || strlen($token) > 2048) {
            return null;
        }

        try {
            $issued = $this->encrypter->decryptString($token);
        } catch (DecryptException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        if (! ctype_digit($issued)) {
            return null;
        }

        $elapsed = Carbon::now()->getTimestamp() - (int) $issued;

        if ($elapsed < 0 || $elapsed > self::TOKEN_MAX_AGE_SECONDS) {
            return null;
        }

        return $elapsed;
    }

    /**
     * Atomic check-and-record: the first submission of a fingerprint stores it for ten minutes, a second
     * one inside that window finds it taken. An empty message is never fingerprinted.
     */
    private function isDuplicate(string $email, string $message): bool
    {
        $message = trim($message);

        if ($message === '') {
            return false;
        }

        $key = self::CACHE_PREFIX.hash('sha256', $email.'|'.$message);

        try {
            return ! $this->cache->add($key, 1, self::DUPLICATE_WINDOW_SECONDS);
        } catch (Throwable $exception) {
            // An unavailable cache must never turn a genuine inquiry into spam.
            report($exception);

            return false;
        }
    }

    private function matchesBlocklist(string $haystack): bool
    {
        $haystack = trim($haystack);

        if ($haystack === '') {
            return false;
        }

        foreach ($this->blocklist() as $entry) {
            $pattern = '~(?<![\p{L}\p{N}])'.preg_quote($entry, '~').'(?![\p{L}\p{N}])~iu';

            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    private function score(string $name, string $message): int
    {
        $score = 0;

        if (preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~iu', $message) > 2) {
            $score += 2;
        }

        $trimmed = trim($message);

        if ($trimmed !== '' && (mb_strlen($trimmed) < 15 || preg_match('~\s~u', $trimmed) !== 1)) {
            $score += 1;
        }

        if (preg_match('~(?:https?://|www\.)|\[/?(?:url|link|img|b|i|u|color|size)\b[^\]]*\]~iu', $name) === 1) {
            $score += 2;
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function blocklist(): array
    {
        $raw = $this->settings->get('website.spam_blocklist', '');
        $lines = is_array($raw) ? $raw : preg_split('~\R~u', (string) $raw);
        $entries = [];

        foreach ((array) $lines as $line) {
            $line = Str::lower(trim((string) $line));

            if ($line !== '' && ! str_starts_with($line, '#')) {
                $entries[$line] = $line;
            }
        }

        return array_values($entries);
    }

    private function minimumSeconds(): int
    {
        $value = $this->settings->get('website.contact_min_submit_seconds', 3);

        return is_numeric($value) ? max(0, min(60, (int) $value)) : 3;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function field(array $input, Request $request, string $name): string
    {
        $value = array_key_exists($name, $input) ? $input[$name] : $request->input($name);

        return $this->text($value);
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
