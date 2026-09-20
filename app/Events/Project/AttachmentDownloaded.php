<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Attachment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A file was downloaded through the permission-checked controller (phase-06 §7, requirement §60).
 *
 * `$byClient` separates a client reading their own file from staff reading it, because the activity row
 * has to say which — §9 makes that distinction the difference between routine and worth looking at.
 */
final class AttachmentDownloaded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Attachment $attachment,
        public readonly ?int $actorId = null,
        public readonly bool $byClient = false,
    ) {}
}
