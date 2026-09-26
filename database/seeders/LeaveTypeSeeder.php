<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LeaveAccrualMethod;
use App\Models\Hr\LeaveType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The four leave types almost every employer needs, so an HR module does not start empty.
 *
 * **The quotas here are starting points, not entitlements.** What an employee is actually owed
 * depends on the contract, the province and the size of the establishment, and this file knows none
 * of those. The numbers below are the common defaults an HR manager then adjusts on the screen —
 * they exist so the first leave request has something to be filed against, not because a seeder is
 * in a position to decide what anybody is entitled to. Anyone relying on them as a legal position
 * should check them against their own obligations.
 *
 * The **types** are the useful part and they are not really a judgement call: paid annual leave,
 * short-notice casual leave, sick leave and unpaid leave are what a leave screen needs in order to
 * be a leave screen at all.
 *
 * Insert-only, matched on `code`. A re-run adds what is missing and never rewrites a quota somebody
 * has set — the moment this runs, these rows belong to whoever maintains them (**D65**).
 *
 *     php artisan db:seed --force --class=LeaveTypeSeeder
 */
final class LeaveTypeSeeder extends Seeder
{
    /**
     * code, name, days, paid, accrual, carries forward, half days, description
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: LeaveAccrualMethod, 5: bool, 6: bool, 7: string}>
     */
    private const TYPES = [
        [
            'AL', 'Annual Leave', '14.00', true, LeaveAccrualMethod::AnnualGrant, true, true,
            'Planned time off, granted as a yearly allowance. Unused days carry forward up to the cap.',
        ],
        [
            'CL', 'Casual Leave', '10.00', true, LeaveAccrualMethod::AnnualGrant, false, true,
            'Short, unplanned absences — a day or two at a time, for something that could not be scheduled.',
        ],
        [
            'SL', 'Sick Leave', '8.00', true, LeaveAccrualMethod::AnnualGrant, false, true,
            'Illness. A medical certificate can be required past a set number of consecutive days.',
        ],
        [
            'UL', 'Unpaid Leave', '0.00', false, LeaveAccrualMethod::None, false, false,
            'Approved absence with no pay and no allowance behind it — used once a paid type is exhausted.',
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use (&$created, &$skipped): void {
            foreach (self::TYPES as $index => [$code, $name, $days, $paid, $accrual, $carries, $halfDays, $description]) {
                // withTrashed: `code` is unique across soft-deleted rows, so a type somebody retired
                // must not be re-created under a colliding code.
                if (LeaveType::withTrashed()->where('code', $code)->exists()) {
                    $skipped++;

                    continue;
                }

                LeaveType::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'annual_quota_days' => $days,
                    'is_paid' => $paid,
                    'accrual_method' => $accrual,
                    'accrual_days_per_month' => '0.00',
                    'carry_forward_enabled' => $carries,
                    // Only meaningful when it carries; a cap on a type that does not carry is noise.
                    'max_carry_forward_days' => $carries ? $days : '0.00',
                    'allow_half_day' => $halfDays,
                    'excludes_weekends' => true,
                    'excludes_holidays' => true,
                    'approval_levels' => 1,
                    'color' => ['sky', 'amber', 'rose', 'slate'][$index] ?? 'slate',
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                ]);

                $created++;
            }
        }, 3);

        $this->command?->info(sprintf(
            'Leave types: %d created, %d already present (left untouched).',
            $created,
            $skipped,
        ));

        if ($created > 0) {
            $this->command?->warn(
                'The quotas are common defaults, not entitlements — check them against your own '
                .'contracts and obligations, and adjust at HR → Leave → Leave Types.'
            );
        }
    }
}
