<?php

declare(strict_types=1);

namespace App\Policies\Cms;

use App\Policies\Cms\Concerns\AuthorizesContentModule;
use App\Policies\Cms\Concerns\AuthorizesModeration;

/**
 * Who may manage and moderate student reviews (phase-04 §4, §6.5, §7.2):
 * `student_reviews` = `CRUD` + `STATUS` + `APPROVE` + `FILES` — the same queue rules as testimonials,
 * through the same `ModerationService` code path.
 *
 * No row-level scope (§9.1). No student can reach this table in Phase 4 (§9.3); the `student_panel`
 * source is reserved for a later phase.
 */
final class StudentReviewPolicy
{
    use AuthorizesContentModule;
    use AuthorizesModeration;

    private const MODULE = 'student_reviews';
}
