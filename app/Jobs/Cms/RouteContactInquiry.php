<?php

declare(strict_types=1);

namespace App\Jobs\Cms;

use App\Models\Cms\ContactInquiry;
use App\Services\Cms\InquiryRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Route one contact inquiry through `InquiryRouter::route()` (phase-04 §10.3).
 *
 * `default` queue, 3 tries, backoff 1 / 5 / 15 minutes. A `pending` outcome — the target phase is not
 * installed yet, or its module is switched off — is a **success**: the job finishes normally and nothing
 * lands in `failed_jobs` because a later phase is missing. The router records its own failures on the
 * inquiry, so the retries here only cover an infrastructure error (a lost connection, a lock timeout).
 *
 * The payload is the id, not the model: an inquiry deleted before the worker picks the job up is simply
 * skipped instead of failing on a missing model.
 */
final class RouteContactInquiry implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $inquiryId,
        public readonly bool $manual = false,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(InquiryRouter $router): void
    {
        $inquiry = ContactInquiry::query()->find($this->inquiryId);

        if (! $inquiry instanceof ContactInquiry) {
            return;
        }

        $router->route($inquiry, $this->manual);
    }
}
