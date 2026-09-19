<?php

declare(strict_types=1);

namespace App\Services\Cms\Data;

/**
 * What `SpamGuard::verdict()` decided about one public form submission (phase-04 §6.9).
 *
 * `isSpam` with its `reason` (`honeypot` / `token` / `too_fast` / `duplicate` / `blocklist` / `score`,
 * stored verbatim in `contact_inquiries.spam_reason`), the heuristic `score` that was reached, and the
 * render→submit time the signed form token proved (`filled_in_seconds`, null when the token was missing
 * or forged) — recorded for every submission, spam or not, so the thresholds can be tuned from data.
 */
readonly class SpamVerdict
{
    public const HONEYPOT = 'honeypot';

    public const TOKEN = 'token';

    public const TOO_FAST = 'too_fast';

    public const DUPLICATE = 'duplicate';

    public const BLOCKLIST = 'blocklist';

    public const SCORE = 'score';

    public function __construct(
        public bool $isSpam,
        public ?string $reason = null,
        public int $score = 0,
        public ?int $filledInSeconds = null,
    ) {}

    public static function clean(int $score = 0, ?int $filledInSeconds = null): self
    {
        return new self(false, null, $score, $filledInSeconds);
    }

    public static function spam(string $reason, int $score = 0, ?int $filledInSeconds = null): self
    {
        return new self(true, mb_substr($reason, 0, 100), $score, $filledInSeconds);
    }
}
