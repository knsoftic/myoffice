<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BlogService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `blog:publish-scheduled` — publish every scheduled blog post whose moment has come (phase-04 §6.7.3).
 *
 * Scheduled every minute with `withoutOverlapping(5)->runInBackground()`. The work is
 * `BlogService::publishDue()`: chunks of 50 under row locks, `published_at` kept as the scheduled moment,
 * `BlogPostPublished` fired per post with `$viaScheduler = true`, one activity entry per post with the system
 * as the actor. Safe to run concurrently and after an outage — a backlog is cleared in one pass and no post
 * is published twice. Drafts and archived posts are never touched.
 */
#[AsCommand(name: 'blog:publish-scheduled')]
final class PublishScheduledPosts extends Command
{
    protected $signature = 'blog:publish-scheduled {--chunk=50 : Posts published per transaction}';

    protected $description = 'Publish scheduled blog posts whose publish time has arrived';

    public function handle(BlogService $blog): int
    {
        $chunk = max(1, min(500, (int) $this->option('chunk')));
        $count = $blog->publishDue($chunk);

        $this->info(sprintf('published %d post(s)', $count));

        return self::SUCCESS;
    }
}
