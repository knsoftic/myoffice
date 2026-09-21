<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FinanceCategoryType;
use App\Models\Finance\FinanceCategory;
use Illuminate\Database\Seeder;

/**
 * The categories both sides of the books start with (phase-13 §2.3).
 *
 * **Idempotent and additive.** Every row is a `firstOrCreate` on `(type, code)`, so re-running it never
 * overwrites a name an admin has changed or reactivates one they switched off. A seeder that reset
 * either would undo somebody's work every deployment, which is the same rule the permission and module
 * seeders follow (D65).
 *
 * `salaries` is the one row with teeth: `RecordPayrollExpense` posts every paid payroll run into it, and
 * the policy refuses both deletion and deactivation (D44). Without it the profit-and-loss statement
 * would quietly lose its largest line.
 */
class FinanceCategorySeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const EXPENSE = [
        // The reserved one goes first, so a fresh install has it before anything can post.
        FinanceCategory::RESERVED_EXPENSE_CODE => 'Salaries',
        'rent' => 'Rent',
        'utilities' => 'Utilities',
        'internet_telephone' => 'Internet & Telephone',
        'marketing' => 'Marketing & Advertising',
        'software' => 'Software & Subscriptions',
        'hardware' => 'Hardware & Equipment',
        'office_supplies' => 'Office Supplies',
        'travel' => 'Travel & Conveyance',
        'professional_fees' => 'Professional Fees',
        'taxes' => 'Taxes & Government Fees',
        'bank_charges' => 'Bank Charges',
        'repairs' => 'Repairs & Maintenance',
        'training' => 'Training',
        'miscellaneous' => 'Miscellaneous',
    ];

    /**
     * @var array<string, string>
     */
    private const INCOME = [
        'consulting' => 'Consulting',
        'training_services' => 'Training Services',
        'maintenance_support' => 'Maintenance & Support',
        'asset_sale' => 'Asset Sale',
        'interest' => 'Interest',
        'miscellaneous' => 'Miscellaneous',
    ];

    public function run(): void
    {
        $created = 0;
        $sort = 0;

        foreach ([
            [FinanceCategoryType::Expense, self::EXPENSE],
            [FinanceCategoryType::Income, self::INCOME],
        ] as [$type, $rows]) {
            foreach ($rows as $code => $name) {
                $sort += 10;

                $category = FinanceCategory::query()->firstOrNew([
                    'type' => $type->value,
                    'code' => $code,
                ]);

                if ($category->exists) {
                    continue;
                }

                $category->forceFill([
                    'name' => $name,
                    // Null: most categories belong to both halves of the business, and forcing a choice
                    // would make every shared overhead pick a side it does not belong to.
                    'context' => null,
                    'is_active' => true,
                    'sort_order' => $sort,
                ])->save();

                $created++;
            }
        }

        $this->command?->info(sprintf(
            'Finance categories: %d declared, %d created, %d already present (nothing renamed, nothing reactivated).',
            count(self::EXPENSE) + count(self::INCOME),
            $created,
            count(self::EXPENSE) + count(self::INCOME) - $created,
        ));
    }
}
