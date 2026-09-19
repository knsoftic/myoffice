<?php

declare(strict_types=1);

namespace App\Events\Crm;

use App\Models\Crm\ClientDocument;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document was stored for a client on the private disk (phase-05 §6.8 `upload()`, §10.1).
 */
final class ClientDocumentUploaded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ClientDocument $document,
        public readonly bool $duplicateChecksum = false,
    ) {}
}
