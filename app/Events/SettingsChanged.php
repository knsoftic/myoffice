<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One or more settings values actually moved (phase-02 §3, `SettingsService::update()`, `resetGroup()`,
 * `deleteFile()`).
 *
 * Fired once per save, after the transaction that wrote the values has committed and the cached settings
 * payload has been flushed, so a listener reads the new values. A save that changes nothing fires
 * nothing. Carries the dotted keys only — never a value, because a value may be a secret.
 *
 * Listeners today: the public website cache (`App\Services\Cms\PublicCache::settingsChanged()`), whose
 * pages embed company, contact, branding, SEO and website settings and must not outlive a change to them.
 */
final class SettingsChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $keys  the dotted keys whose stored value moved (`seo.robots_indexable`)
     * @param  int|null  $actorId  `users.id` of whoever saved, null for a console or system write
     */
    public function __construct(
        public readonly array $keys,
        public readonly ?int $actorId = null,
    ) {}
}
