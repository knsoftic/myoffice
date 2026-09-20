<?php

declare(strict_types=1);

namespace App\Events\Project;

use App\Models\Project\Attachment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A file was attached to a project, milestone, task or comment (phase-06 §6.1, requirement §96).
 */
final class AttachmentUploaded implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Attachment $attachment,
        public readonly ?int $actorId = null,
    ) {}
}
