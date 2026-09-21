<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentMethodType;
use App\Http\Controllers\Controller;
use App\Models\Finance\PaymentMethodOption;
use App\Services\Finance\PaymentMethodService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Configured payment methods — `admin.payment-methods.*` (§32, phase-13 §7.4, §6.6).
 *
 * **The enum on a payment row is the snapshot of record; this table is the presentation around it.**
 * Renaming "Bank transfer" to "Bank transfer — HBL" therefore changes every dropdown and no history.
 *
 * **The encrypted gateway config is never rendered.** No permission reveals it — not even
 * `view_financial`, which this module deliberately does not declare — so the form shows a masked field
 * with a replace toggle and the activity diff records that it changed rather than what it changed to.
 * A screen that could show it would make the encryption decorative.
 *
 * **Deletion is refused while anything references the row.** The correct act is deactivation, which
 * hides it from every dropdown and changes no history: a payment made last year by a method the
 * business has stopped offering is still a payment made by that method.
 */
final class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentMethodService $methods,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.payment-methods.index', [
            'methods' => PaymentMethodOption::query()
                ->withCount(['projectPayments', 'studentFeePayments', 'expenses', 'incomes'])
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'canCreate' => (bool) $request->user()?->can('payment_methods.create'),
            'canEdit' => (bool) $request->user()?->can('payment_methods.edit'),
            'canChangeStatus' => (bool) $request->user()?->can('payment_methods.change_status'),
        ]);
    }

    public function create(): View
    {
        return view('admin.payment-methods.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $method = $this->methods->create(
            $this->validateMethod($request) + ['config' => (array) $request->input('config', [])],
            $request->user(),
        );

        return redirect()->route('admin.payment-methods.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is available on the forms you chose.', $method->name),
        ]);
    }

    public function edit(PaymentMethodOption $method): View
    {
        return view('admin.payment-methods.edit', array_merge($this->formData(), ['method' => $method]));
    }

    public function update(Request $request, PaymentMethodOption $method): RedirectResponse
    {
        $this->methods->update(
            $method,
            $this->validateMethod($request, $method) + ['config' => (array) $request->input('config', [])],
            $request->user(),
        );

        return redirect()->route('admin.payment-methods.index')->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was updated. Payments already recorded under it are untouched.', $method->name),
        ]);
    }

    public function destroy(PaymentMethodOption $method): RedirectResponse
    {
        $used = $method->projectPayments()->exists()
            || $method->studentFeePayments()->exists()
            || $method->expenses()->exists()
            || $method->incomes()->exists();

        if ($used) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf('%s has money recorded against it. Switch it off instead — that hides '
                    .'it from every form and leaves the history intact.', $method->name),
            ]);
        }

        $method->delete();

        // The dropdown cache would otherwise keep offering a method that no longer exists.
        $this->methods->flush();

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was removed. Nothing had been recorded under it.', $method->name),
        ]);
    }

    public function toggle(Request $request, PaymentMethodOption $method): RedirectResponse
    {
        $active = $request->boolean('active');

        $validated = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => [$active ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Switching a method off removes it from every form. Say why.',
        ]);

        $this->methods->toggle($method, $active, $validated['reason'] ?? null, $request->user());

        if (filled($validated['reason'] ?? null)) {
            $method->withReason($validated['reason']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $active
                ? sprintf('%s is available again.', $method->name)
                : sprintf('%s is switched off. Every payment already recorded under it is unchanged.', $method->name),
        ]);
    }

    /**
     * One default, enforced by `uq_pm_default` rather than by a clear-all-others loop that can
     * half-fail and leave two.
     */
    public function setDefault(Request $request, PaymentMethodOption $method): RedirectResponse
    {
        $this->methods->setDefault($method, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s is now the default.', $method->name),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'codes' => PaymentMethod::cases(),
            'types' => PaymentMethodType::cases(),
            'contexts' => ['invoice', 'project_payment', 'student_fee', 'expense', 'income', 'payout'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateMethod(Request $request, ?PaymentMethodOption $existing = null): array
    {
        return $request->validate([
            // Must be an enum value: a method configured for an instrument no payment row can record
            // would be a dropdown entry that fails on save.
            'code' => ['required', Rule::enum(PaymentMethod::class),
                Rule::unique('payment_methods', 'code')->ignore($existing?->getKey())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PaymentMethodType::class)],
            'is_online' => ['nullable', 'boolean'],
            'gateway_driver' => ['nullable', 'string', 'max:32'],
            'is_test_mode' => ['nullable', 'boolean'],
            'supports_refund' => ['nullable', 'boolean'],
            'requires_reference' => ['nullable', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'usable_for' => ['required', 'array', 'min:1'],
            'usable_for.*' => ['in:invoice,project_payment,student_fee,expense,income,payout'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'config' => ['nullable', 'array'],
        ], [
            'usable_for.required' => 'A method nobody can choose on any form is not a method.',
        ]);
    }
}
