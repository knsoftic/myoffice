<?php

declare(strict_types=1);

namespace App\Listeners\Cms;

use App\Enums\Cms\ContentStatus;
use App\Events\Cms\BlogPostPublished;
use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Notifications\Cms\PostPublishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "Your scheduled post went live" to the author (phase-04 §10.1, §10.2) — queued, and **only** when the
 * scheduler published the post (`$viaScheduler`); a manual Publish notifies nobody.
 *
 * Skipped when the post has no author, the author account is not active, or the post is no longer
 * published by the time the worker runs.
 */
final class NotifyAuthorOfPublication implements ShouldQueue
{
    public bool $deleteWhenMissingModels = true;

    /**
     * Queue (and run) this listener only for a scheduler publication.
     */
    public function shouldQueue(BlogPostPublished $event): bool
    {
        return $event->viaScheduler;
    }

    public function handle(BlogPostPublished $event): void
    {
        if (! $event->viaScheduler) {
            return;
        }

        $post = BlogPost::query()->find($event->post->getKey());

        if (! $post instanceof BlogPost || $post->getAttribute('status') !== ContentStatus::Published) {
            return;
        }

        $author = User::query()->find($post->getAttribute('author_id'));

        if (! $author instanceof User || ! $author->isActive()) {
            return;
        }

        try {
            $author->notify(new PostPublishedNotification($post));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
