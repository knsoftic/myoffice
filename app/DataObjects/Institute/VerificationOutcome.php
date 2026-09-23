<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

use App\Enums\VerificationResult;

/**
 * What the public verification page found (phase-19-23 §6.14, requirement §84).
 *
 * **The payload is already filtered when it gets here.** `CertificateVerificationService::publicPayload()`
 * builds it by whitelist from `institute.certificate_verification_reveals`, so this object carries a
 * map that is safe to render as-is (INV-21-3). A controller that reached past `payload` to the model
 * would be the leak the whitelist exists to prevent — which is why the model is deliberately **not**
 * on this object.
 *
 * **`subject` is a word, not a model**: `certificate` or `id_card`. One QR endpoint serves both, and
 * the page needs to know which it is looking at to choose its wording — but not to reach anything.
 */
final readonly class VerificationOutcome
{
    /**
     * @param  array<string, mixed>  $payload  already whitelisted; safe to render
     */
    public function __construct(
        public VerificationResult $result,
        public array $payload = [],
        public ?string $subject = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    /** Nothing matched — or the code matched something nobody outside the office may see. */
    public static function miss(VerificationResult $result = VerificationResult::NotFound): self
    {
        return new self($result);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(VerificationResult::Throttled, retryAfterSeconds: $retryAfterSeconds);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function found(VerificationResult $result, array $payload, string $subject): self
    {
        return new self($result, $payload, $subject);
    }

    public function isPositive(): bool
    {
        return $this->result->isPositive();
    }

    public function httpStatus(): int
    {
        return $this->result->httpStatus();
    }
}
