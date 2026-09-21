<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which side of the books a category belongs to (`finance_categories.type`, phase-13 §2.3).
 *
 * One table serves both, separated by this column. Two near-identical tables would double the CRUD, the
 * policy and the seeder to express a difference that is one word long.
 */
enum FinanceCategoryType: string
{
    use HasOptions;

    case Expense = 'expense';
    case Income = 'income';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::Income => 'Income',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Expense => 'rose',
            self::Income => 'emerald',
        };
    }
}
