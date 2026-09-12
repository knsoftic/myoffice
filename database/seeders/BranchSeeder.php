<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use Database\Seeders\Concerns\WritesToConsole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 · §5 — the default branch (decision D11).
 *
 * Branch-ready from day one: one default branch exists from the first seed so
 * `users.branch_id` and every institute table created later always have somewhere to point.
 *
 * Idempotent: matched on the natural key `branches.code`, never truncated, never deleted.
 * An existing row keeps whatever an administrator has edited (name, phone, city, …) — only
 * a missing row is created.
 */
class BranchSeeder extends Seeder
{
    use WritesToConsole;

    /** Natural key of the branch every fresh install gets. */
    public const DEFAULT_CODE = 'HQ';

    public function run(): void
    {
        DB::transaction(function (): void {
            // withTrashed: the code column is unique, so a soft-deleted HQ row would make a
            // plain firstOrCreate collide on the unique index.
            $branch = Branch::withTrashed()
                ->where('code', self::DEFAULT_CODE)
                ->first();

            if ($branch === null) {
                Branch::query()->create([
                    'code' => self::DEFAULT_CODE,
                    'name' => 'Head Office',
                    'phone' => null,
                    'email' => null,
                    'city' => 'Lahore',
                    'address' => null,
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);

                $this->seedInfo('Branches: created the default branch ['.self::DEFAULT_CODE.'].');

                return;
            }

            // The installation needs a usable default branch; restoring is not destructive.
            if ($branch->trashed()) {
                $branch->restore();

                $this->seedWarning('Branches: the default branch ['.self::DEFAULT_CODE.'] was soft-deleted and has been restored.');
            }

            $this->seedLine('Branches: default branch ['.self::DEFAULT_CODE.'] already present — left untouched.');
        });
    }
}
