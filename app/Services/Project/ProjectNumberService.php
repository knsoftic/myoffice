<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Services\Finance\DocumentNumberService;

/**
 * Issues `projects.code` (phase-06 §6.1, requirement §20's "Project ID").
 *
 * **A thin delegate from day one** (§6.1, F-4.1, **D27**). `DocumentNumberService` is shipped by Phase 5,
 * which migrates before this phase, and it already holds the whole mechanism: the `SELECT ... FOR UPDATE`
 * on the settings row inside the caller's transaction, the increment, and the single retry when a unique
 * index still rejects the number. This class adds exactly three facts — the two settings keys and the
 * `PRJ-00001` shape — and no second locking implementation exists anywhere for anyone to pick by mistake.
 *
 * The pad is passed **explicitly**: `'%06d'` is only that method's default, and a project code is five
 * digits (§20). Relying on the default would make the shape of a project code depend on a constant in
 * another class.
 */
final readonly class ProjectNumberService
{
    public const PREFIX_KEY = 'projects.project_code_prefix';

    public const COUNTER_KEY = 'projects.project_code_next_number';

    public const PAD = '%05d';

    public function __construct(private DocumentNumberService $numbers) {}

    /**
     * The next project code, reserved inside the caller's transaction.
     */
    public function next(): string
    {
        return $this->numbers->next(self::PREFIX_KEY, self::COUNTER_KEY, self::PAD);
    }
}
