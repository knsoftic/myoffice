<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Attachment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An attachment was soft-deleted (phase-06 §6.1). The blob survives 30 days so an accidental delete is
 * recoverable; `attachments:prune-deleted` removes it after that.
 */
final class AttachmentDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Attachment $attachment,
        public readonly ?int $actorId = null,
    ) {}
}
