<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Support\Cms\VideoUrl;
use Closure;

/**
 * A `video_url` on a student review or a success story (phase-04 §2.11, §2.12, §6.9).
 *
 * Only YouTube and Vimeo are accepted, decided by `App\Support\Cms\VideoUrl::isAllowed()` — the same
 * parser the public page builds the embed from — so the request, the service and the renderer can never
 * disagree about which link is a video. The stored string is never rendered as HTML.
 */
trait ValidatesVideoUrl
{
    /**
     * @return list<mixed>
     */
    protected function videoUrlRules(): array
    {
        return ['sometimes', 'bail', 'nullable', 'string', 'max:255', 'url:http,https', $this->videoHostAllowed()];
    }

    private function videoHostAllowed(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            if (! VideoUrl::isAllowed($value)) {
                $fail('Only YouTube or Vimeo links can be used for a video.');
            }
        };
    }
}
