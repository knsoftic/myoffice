<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Policies\Cms\Concerns\AuthorizesContentModule;
use App\Policies\Cms\Concerns\AuthorizesModeration;

/**
 * Who may manage and moderate testimonials (phase-04 §4, §6.5, §7.2):
 * `testimonials` = `CRUD` + `STATUS` + `APPROVE` + `FILES`.
 *
 *   · `testimonials.approve` / `.reject` run the moderation queue; `approve` also grants bulk approve.
 *   · `testimonials.edit` corrects a typo in a review — audited with old and new values, never silent.
 *   · Featuring is `testimonials.change_status`; featuring anything not approved is a service 422.
 *
 * No row-level scope (§9.1): no panel writes this table in Phase 4 (§9.3).
 */
final class TestimonialPolicy
{
    use AuthorizesContentModule;
    use AuthorizesModeration;

    private const MODULE = 'testimonials';
}
