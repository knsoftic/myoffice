<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentMethod as PaymentMethodEnum;
use App\Enums\PaymentMethodType;
use App\Models\Finance\PaymentMethodOption;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Configured payment methods (§32, phase-13 §6.6).
 *
 * **The enum on a payment row is the snapshot of record; this table is the presentation around it.**
 * Renaming "Bank transfer" to "Bank transfer — HBL" therefore changes every dropdown and no history —
 * a payment made last year by a method the business has since stopped offering is still a payment made
 * by that method.
 *
 * **`availableFor()` is the only source of a method dropdown.** Every form in every panel asks this,
 * so a method switched off disappears everywhere at once rather than lingering on the one screen
 * somebody forgot to filter.
 *
 * **The encrypted config never leaves this class as plaintext.** It is written through the model's
 * `encrypted:array` cast and is never returned, logged or diffed: the activity trail records *that* it
 * changed, never *what* it changed to. A method that could reveal it would make the encryption
 * decorative.
 */
final class PaymentMethodService
{
    private const CACHE_PREFIX = 'finance.payment_methods.';

    /**
     * Long, because the cache is invalidated on every write rather than waiting to expire.
     */
    private const CACHE_TTL = 3600;

    public const CONTEXTS = ['invoice', 'project_payment', 'student_fee', 'expense', 'income', 'payout'];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /**
     * The methods a given form may offer, default first.
     *
     * @return Collection<int, PaymentMethodOption>
     */
    public function availableFor(string $context): Collection
    {
        $this->assertContext($context);

        return Cache::remember(
            self::CACHE_PREFIX.$context,
            self::CACHE_TTL,
            static fn (): Collection => PaymentMethodOption::query()->usableFor($context)->get(),
        );
    }

    public function defaultFor(string $context): ?PaymentMethodOption
    {
        return $this->availableFor($context)->first(
            static fn (PaymentMethodOption $method): bool => $method->is_default,
        ) ?? $this->availableFor($context)->first();
    }

    public function flush(): void
    {
        foreach (self::CONTEXTS as $context) {
            Cache::forget(self::CACHE_PREFIX.$context);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): PaymentMethodOption
    {
        return $this->db->transaction(function () use ($data, $actor): PaymentMethodOption {
            $this->clearDefault($data);

            $method = new PaymentMethodOption;
            $method->forceFill($this->columns($data) + ['created_by' => $actor?->getKey()])->save();

            $this->writeConfig($method, $data['config'] ?? []);

            $this->flush();

            return $method;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentMethodOption $method, array $data, ?User $actor = null): PaymentMethodOption
    {
        return $this->db->transaction(function () use ($method, $data, $actor): PaymentMethodOption {
            $this->clearDefault($data, $method);

            $method->forceFill($this->columns($data) + ['updated_by' => $actor?->getKey()])->save();

            $this->writeConfig($method, $data['config'] ?? []);

            $this->flush();

            return $method;
        }, 3);
    }

    /**
     * One statement inside a transaction. `uq_pm_default` is the guarantee that exactly one method
     * holds it — not a "clear all the others" loop that can half-fail and leave two, or none.
     */
    public function setDefault(PaymentMethodOption $method, ?User $actor = null): PaymentMethodOption
    {
        return $this->db->transaction(function () use ($method, $actor): PaymentMethodOption {
            PaymentMethodOption::query()
                ->where('is_default', true)
                ->whereKeyNot($method->getKey())
                ->update(['is_default' => false, 'updated_at' => now()]);

            $method->forceFill(['is_default' => true, 'updated_by' => $actor?->getKey()])->save();

            $this->flush();

            return $method;
        }, 3);
    }

    /**
     * Deactivating hides the method from every dropdown and changes no history.
     *
     * The reason is required when switching one off, because somebody will ask next month why a method
     * they used to choose is missing.
     */
    public function toggle(
        PaymentMethodOption $method,
        bool $active,
        ?string $reason = null,
        ?User $actor = null,
    ): PaymentMethodOption {
        if (! $active && trim((string) $reason) === '') {
            throw new InvalidArgumentException('Switching a payment method off takes a reason.');
        }

        $method->forceFill([
            'is_active' => $active,
            // Never the default while it is switched off: the forms would preselect something nobody
            // can choose.
            'is_default' => $active ? $method->is_default : false,
            'updated_by' => $actor?->getKey(),
        ])->save();

        $this->flush();

        return $method;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $code = PaymentMethodEnum::tryFrom((string) ($data['code'] ?? ''));

        if ($code === null) {
            throw new InvalidArgumentException(
                'A payment method must name a PaymentMethod the payment rows can actually store.',
            );
        }

        $type = PaymentMethodType::from((string) $data['type']);

        // `chk_pm_online` refuses an online non-gateway; this is the quiet half of the same rule, so
        // ticking the box on a cash method simply has no effect rather than failing the save.
        $online = (bool) ($data['is_online'] ?? false) && $type->isOnlineCapable();

        $contexts = array_values(array_intersect(self::CONTEXTS, (array) ($data['usable_for'] ?? [])));

        if ($contexts === []) {
            throw new InvalidArgumentException('A method nobody can choose on any form is not a method.');
        }

        return [
            'code' => $code->value,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $type->value,
            'is_online' => $online,
            'gateway_driver' => $online ? ($data['gateway_driver'] ?? null) : null,
            'is_test_mode' => (bool) ($data['is_test_mode'] ?? true),
            'supports_refund' => (bool) ($data['supports_refund'] ?? false),
            'requires_reference' => (bool) ($data['requires_reference'] ?? false),
            'instructions' => $data['instructions'] ?? null,
            'usable_for' => $contexts,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * Written only when the form actually sent one, so an edit that leaves the masked field alone
     * keeps the existing keys rather than wiping them.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeConfig(PaymentMethodOption $method, array $config): void
    {
        $config = array_filter($config, static fn ($value): bool => $value !== null && $value !== '');

        if ($config === []) {
            return;
        }

        $method->forceFill(['config_encrypted' => $config])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function clearDefault(array $data, ?PaymentMethodOption $except = null): void
    {
        if (! (bool) ($data['is_default'] ?? false)) {
            return;
        }

        PaymentMethodOption::query()
            ->where('is_default', true)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    private function assertContext(string $context): void
    {
        if (! in_array($context, self::CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not a form a payment method can be offered on. Known: %s.',
                $context,
                implode(', ', self::CONTEXTS),
            ));
        }
    }
}
