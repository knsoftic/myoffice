<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the public verification page found (phase-19-23 §3.2, §6.14, INV-21-2).
 *
 * Declared in Phase 20 rather than Phase 21 because the same endpoint serves ID cards (§2.16), and an
 * enum that two phases share is better written once by the first of them than twice.
 *
 * **`not_found` and `not_public` are different, and the page must not let a visitor tell them apart.**
 * `not_found` means no such code; `not_public` means the code is real but its holder has switched
 * verification off. Both render the same sentence and both answer 404 — otherwise the page becomes an
 * oracle that confirms a certificate exists to anybody willing to guess codes at it.
 *
 * The distinction is kept for the **log**, which is where it belongs: a run of `not_public` hits is
 * somebody probing a code they already have, and a run of `not_found` is somebody guessing.
 */
enum VerificationResult: string
{
    use HasOptions;

    /** The code resolves and the document is live. */
    case Valid = 'valid';

    /** The code resolves and the document was revoked. Said plainly — this is the answer that matters. */
    case Revoked = 'revoked';

    /** No such code. */
    case NotFound = 'not_found';

    /** Too many attempts from this address. */
    case Throttled = 'throttled';

    /** The code is real, but its holder has turned public verification off. */
    case NotPublic = 'not_public';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Verified',
            self::Revoked => 'Revoked',
            self::NotFound => 'Not found',
            self::Throttled => 'Too many attempts',
            self::NotPublic => 'Not found',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Valid => 'emerald',
            self::Revoked => 'rose',
            self::NotFound, self::NotPublic => 'slate',
            self::Throttled => 'amber',
        };
    }

    /**
     * What the visitor is told. **`not_found` and `not_public` say the same thing on purpose** — see
     * the class note.
     */
    public function message(): string
    {
        return match ($this) {
            self::Valid => 'This document was issued by us and is valid.',
            self::Revoked => 'This document was issued by us and has since been revoked. Contact us if you were given it as proof of anything.',
            self::NotFound, self::NotPublic => 'We have no record of that code. Check it and try again.',
            self::Throttled => 'Too many checks from this connection. Try again in a few minutes.',
        };
    }

    /** Only one of the five means the document stands. */
    public function isPositive(): bool
    {
        return $this === self::Valid;
    }

    /**
     * `not_public` answers 404 like `not_found`, which is the whole of the note above: a different
     * status code would be the oracle the matching message was written to avoid.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Valid, self::Revoked => Response::HTTP_OK,
            self::NotFound, self::NotPublic => Response::HTTP_NOT_FOUND,
            self::Throttled => Response::HTTP_TOO_MANY_REQUESTS,
        };
    }

    /** Whether this attempt is worth keeping in the verification log. All of them are. */
    public function isLoggable(): bool
    {
        return true;
    }
}
