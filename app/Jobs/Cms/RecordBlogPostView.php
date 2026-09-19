<?php

declare(strict_types=1);

namespace App\Jobs\Cms;

use App\Models\Cms\BlogPost;
use App\Models\User;
use App\Services\Cms\BlogViewCounter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Throwable;

/**
 * Count one blog read without making the page wait (phase-04 §6.7.1, §10.3).
 *
 * Dispatched with `dispatchAfterResponse()` from the public post page. `tries = 1`: the counter is
 * idempotent (the `uq_blog_post_view_daily` index swallows a repeat), so a retried or concurrent job
 * counts nothing twice and a lost view is preferable to a doubled one.
 *
 * The request is captured as the few values the counter needs — never the `Request` object, which does
 * not serialise. The job rebuilds an equivalent request for `BlogViewCounter::record()`.
 */
final class RecordBlogPostView implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, string>  $headers  only the headers the counter reads
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly string $method = 'GET',
        public readonly array $headers = [],
        public readonly ?int $userId = null,
        public readonly ?string $referrer = null,
        public readonly ?string $sessionKey = null,
    ) {
        $this->onQueue('default');
    }

    /**
     * Capture what the counter needs from the live request.
     */
    public static function fromRequest(BlogPost $post, Request $request): self
    {
        $headers = [];

        foreach (BlogViewCounter::INSPECTED_HEADERS as $name) {
            $value = $request->headers->get($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = mb_substr($value, 0, 255);
            }
        }

        $user = $request->user();

        return new self(
            postId: (int) $post->getKey(),
            ip: (string) $request->ip(),
            userAgent: mb_substr((string) $request->userAgent(), 0, 1000),
            method: $request->getMethod(),
            headers: $headers,
            userId: $user instanceof User ? (int) $user->getKey() : null,
            referrer: is_string($request->headers->get('referer')) ? mb_substr((string) $request->headers->get('referer'), 0, 1000) : null,
        );
    }

    public function handle(BlogViewCounter $counter): void
    {
        $post = BlogPost::query()->find($this->postId);

        if (! $post instanceof BlogPost) {
            return;
        }

        try {
            $counter->recordCaptured(
                post: $post,
                ip: $this->ip,
                userAgent: $this->userAgent,
                method: $this->method,
                headers: $this->headers,
                user: $this->userId === null ? null : User::query()->find($this->userId),
                referrer: $this->referrer,
            );
        } catch (Throwable $exception) {
            // A view is an optimisation-grade metric: report, never fail the job.
            report($exception);
        }
    }
}
