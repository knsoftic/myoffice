<?php

declare(strict_types=1);

namespace App\Services\Reporting\Exceptions;

use App\Enums\ExportFormat;
use RuntimeException;

/**
 * A format was asked for that this installation cannot produce (phase-19-23 §6.21).
 *
 * In practice this means `excel` without a writer package. The screen never offers it —
 * {@see ExportFormat::available()} removes it, and the button renders disabled with a tooltip — so
 * reaching this exception means a hand-built URL, a stale bookmark, or a queued export whose
 * package was removed between the request and the run.
 *
 * It exists as its own class rather than a bare `RuntimeException` so the controller can turn it
 * into a toast that names the format and says what is missing, instead of a 500 that says nothing.
 * The contract's rule is "a disabled button with a tooltip, never a 500"; this is the second half of
 * keeping that promise on the path the button does not cover.
 */
final class ExportFormatUnavailableException extends RuntimeException
{
    public static function for(ExportFormat $format): self
    {
        return new self(sprintf(
            '%s downloads are not available on this server. Turn on "Offer Excel downloads" under '
            .'Settings → Reports & Search, and install a spreadsheet writer package. CSV opens in '
            .'Excel and needs neither.',
            $format->label(),
        ));
    }
}
