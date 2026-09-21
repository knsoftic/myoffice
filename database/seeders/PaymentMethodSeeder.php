<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PaymentMethod;
use App\Enums\PaymentMethodType;
use App\Models\Finance\PaymentMethodOption;
use Illuminate\Database\Seeder;

/**
 * The four payment methods §32 names, plus the gateway placeholder it asks to be ready for
 * (phase-13 §2.2).
 *
 * **Idempotent and additive** (D65). Every row is a `firstOrCreate` on `code`, so re-running this never
 * renames a method somebody has relabelled ("Bank transfer — HBL"), never re-ticks a context they
 * unticked and never switches one back on. A seeder that reset any of those would undo an admin's work
 * on every deployment.
 *
 * The **default** is set only when no method holds it yet: `uq_pm_default` permits exactly one
 * system-wide, and a seeder that claimed it unconditionally would fail the second time it ran on an
 * installation where somebody had moved it.
 */
class PaymentMethodSeeder extends Seeder
{
    /**
     * `code => [name, type, usable_for, requires_reference, supports_refund, sort_order]`.
     *
     * The gateway row ships inactive on purpose: no gateway is wired in this release, and an active
     * method that cannot actually take money is a dropdown entry that fails at the worst moment.
     *
     * @var array<string, array{name: string, type: PaymentMethodType, usable_for: list<string>, requires_reference: bool, supports_refund: bool, sort_order: int, is_active?: bool, description?: string}>
     */
    private const METHODS = [
        PaymentMethod::Cash->value => [
            'name' => 'Cash',
            'type' => PaymentMethodType::Cash,
            'usable_for' => ['invoice', 'project_payment', 'student_fee', 'expense', 'income', 'payout'],
            'requires_reference' => false,
            'supports_refund' => true,
            'sort_order' => 10,
            'description' => 'Handed over in person and receipted on the spot.',
        ],
        PaymentMethod::BankTransfer->value => [
            'name' => 'Bank transfer',
            'type' => PaymentMethodType::Bank,
            'usable_for' => ['invoice', 'project_payment', 'student_fee', 'expense', 'income', 'payout'],
            // The transfer reference is the only thing that ties a line on a statement to a row here.
            'requires_reference' => true,
            'supports_refund' => true,
            'sort_order' => 20,
            'description' => 'Into the company account. The reference is what reconciles it.',
        ],
        PaymentMethod::Card->value => [
            'name' => 'Card',
            'type' => PaymentMethodType::Card,
            'usable_for' => ['invoice', 'project_payment', 'student_fee', 'expense'],
            'requires_reference' => true,
            'supports_refund' => true,
            'sort_order' => 30,
            'description' => 'Taken on a terminal. The slip number is the reference.',
        ],
        PaymentMethod::Other->value => [
            'name' => 'Manual payment',
            'type' => PaymentMethodType::Manual,
            'usable_for' => ['invoice', 'project_payment', 'student_fee', 'expense', 'income', 'payout'],
            'requires_reference' => false,
            'supports_refund' => true,
            'sort_order' => 40,
            'description' => 'Anything settled outside the instruments above — write what it was in the reference.',
        ],
        PaymentMethod::OnlineGateway->value => [
            'name' => 'Online gateway',
            'type' => PaymentMethodType::Gateway,
            'usable_for' => ['invoice', 'project_payment', 'student_fee'],
            'requires_reference' => true,
            'supports_refund' => false,
            'sort_order' => 50,
            // Off until a driver is actually wired: §32 asks for a gateway-ready architecture, not for
            // an integration nobody has paid for.
            'is_active' => false,
            'description' => 'Reserved. No gateway is wired in this release; the driver is recorded for later.',
        ],
    ];

    public function run(): void
    {
        foreach (self::METHODS as $code => $method) {
            PaymentMethodOption::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $method['name'],
                    'description' => $method['description'] ?? null,
                    'type' => $method['type'],
                    'is_online' => false,
                    'is_test_mode' => true,
                    'supports_refund' => $method['supports_refund'],
                    'requires_reference' => $method['requires_reference'],
                    'usable_for' => $method['usable_for'],
                    'is_active' => $method['is_active'] ?? true,
                    'is_default' => false,
                    'sort_order' => $method['sort_order'],
                ],
            );
        }

        // Only if nobody holds it. `uq_pm_default` allows exactly one, and claiming it unconditionally
        // would make the second run of this seeder fail on an installation where it had been moved.
        if (! PaymentMethodOption::query()->where('is_default', true)->exists()) {
            PaymentMethodOption::query()
                ->where('code', PaymentMethod::BankTransfer->value)
                ->update(['is_default' => true, 'updated_at' => now()]);
        }
    }
}
