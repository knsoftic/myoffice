<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * The deferred link ids of phase-04 §2.1 — `client_id`, `student_id`, `course_id`, `department_id`,
 * `employee_id` — point at tables a later phase creates.
 *
 * Until that table exists the form offers the free-text snapshot column only (§2.1 rule 2), so a posted
 * id is **prohibited**: nothing can store an id that no row can ever be checked against. Once the owning
 * phase migrates, the same field is accepted when it names a real row. The public site always renders
 * the snapshot, never the link.
 */
trait ValidatesDeferredLinks
{
    /**
     * @return list<mixed>
     */
    protected function deferredLinkRules(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return ['prohibited'];
        }

        return ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists($table, 'id')];
    }
}
