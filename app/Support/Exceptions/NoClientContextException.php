<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * The signed-in user resolves to no usable client (phase-05 §6.9 `ClientContext`).
 *
 * `reason` is one of the `ClientContext::REASON_*` codes, so `EnsureClientContext` can render an explanatory
 * 403 page ("your company's portal access has been switched off") instead of a bare error.
 */
final class NoClientContextException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'No client account is linked to this login.',
    ) {
        parent::__construct($message);
    }
}
