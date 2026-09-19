<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\ClientDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document became visible to the client (phase-05 §6.8 `setClientVisibility()`, §10.1, test 68).
 * Listener: `NotifyClientOfSharedDocument` — `ClientDocumentShared` to the client's portal logins, so the
 * confirm dialog's "the client will see and be notified immediately" is literally true.
 */
final class ClientDocumentSharedWithClient implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ClientDocument $document,
        public readonly ?int $sharedBy = null,
    ) {}
}
