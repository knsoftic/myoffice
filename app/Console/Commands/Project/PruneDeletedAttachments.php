<?php

declare(strict_types=1);

namespace App\Console\Commands\Project;

use App\Models\Project\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remove the blobs of attachments deleted more than 30 days ago (phase-06 §10.3, §10.4).
 *
 * The row is kept: it is the audit trail of a file that existed. Only the bytes go, and each path is
 * logged, because "the file is gone" has to be explainable a year later.
 */
final class PruneDeletedAttachments extends Command
{
    protected $signature = 'attachments:prune-deleted {--days=30} {--limit=500}';

    protected $description = 'Delete the stored bytes of attachments soft-deleted beyond the grace window.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $pruned = 0;

        Attachment::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Attachment $attachment) use (&$pruned): void {
                $disk = Storage::disk($attachment->disk);

                if (! $disk->exists($attachment->path)) {
                    return;
                }

                $disk->delete($attachment->path);
                $pruned++;

                $this->components->twoColumnDetail($attachment->original_name, $attachment->path);
            });

        $this->components->info(sprintf('Pruned %d blob(s).', $pruned));

        return self::SUCCESS;
    }
}
