<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Finance;

use App\Enums\FinanceCategoryType;
use App\Enums\FinanceContext;
use App\Http\Controllers\Controller;
use App\Models\Finance\FinanceCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Expense and income categories — `admin.finance-categories.*` (§30, phase-13 §7.4).
 *
 * One screen for both sides, because they are one table separated by `type`. The module declares **no
 * `view_financial`** and holds no amount, so somebody who maintains the list never has to be given
 * sight of a single figure to do it.
 *
 * **`salaries` cannot be deleted or switched off** (D44): every paid payroll run posts into it, and a
 * missing category would silently drop payroll out of the profit-and-loss statement. The model refuses
 * the deletion; this refuses the deactivation, because a category hidden from the dropdown is just as
 * missing to the job that needs it.
 */
final class FinanceCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $type = FinanceCategoryType::tryFrom((string) $request->input('type', 'expense'))
            ?? FinanceCategoryType::Expense;

        return view('admin.finance-categories.index', [
            'type' => $type,
            'types' => FinanceCategoryType::cases(),
            'contexts' => FinanceContext::cases(),
            'categories' => FinanceCategory::query()
                ->ofType($type)
                ->withCount(['expenses', 'incomes'])
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
            'canCreate' => (bool) $request->user()?->can('finance_categories.create'),
            'canEdit' => (bool) $request->user()?->can('finance_categories.edit'),
            'canChangeStatus' => (bool) $request->user()?->can('finance_categories.change_status'),
            'canDelete' => (bool) $request->user()?->can('finance_categories.delete'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(FinanceCategoryType::class)],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', Rule::enum(FinanceContext::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'code.regex' => 'A code is lowercase letters, numbers and underscores — it is what reports group by.',
        ]);

        $code = Str::of($validated['code'] ?? $validated['name'])->slug('_')->limit(32, '')->toString();

        $exists = FinanceCategory::query()
            ->where('type', $validated['type'])->where('code', $code)->withTrashed()->exists();

        if ($exists) {
            return back()->withInput()->with('toast', [
                'type' => 'error',
                'message' => sprintf('A %s category with the code "%s" already exists. A report groups by '
                    .'the code, so two cannot share one.', $validated['type'], $code),
            ]);
        }

        $category = new FinanceCategory;

        $category->forceFill([
            'type' => $validated['type'],
            'code' => $code,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'context' => $validated['context'] ?? null,
            'is_active' => true,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'created_by' => $request->user()?->getKey(),
        ])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s added.', $category->name),
        ]);
    }

    public function update(Request $request, FinanceCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'context' => ['nullable', Rule::enum(FinanceContext::class)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // The code is deliberately not editable: a year of reports groups by it, and renaming it would
        // split one category's history into two that no longer add up.
        $category->forceFill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'context' => $validated['context'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? $category->sort_order),
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was renamed. Every row already filed under it stays where it is.', $category->name),
        ]);
    }

    public function destroy(FinanceCategory $category): RedirectResponse
    {
        if ($category->isReserved()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Salaries is where every paid payroll run posts. Removing it would leave that '
                    .'history pointing at nothing, and the profit-and-loss statement would lose its largest line.',
            ]);
        }

        if ($category->isInUse()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => sprintf('%s has money filed under it. Switch it off instead — that hides it '
                    .'from the forms and leaves every report intact.', $category->name),
            ]);
        }

        $category->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%s was removed.', $category->name),
        ]);
    }

    public function toggle(Request $request, FinanceCategory $category): RedirectResponse
    {
        $active = $request->boolean('active');

        if (! $active && ! $category->isDeactivatable()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Salaries cannot be switched off: the payroll job would have nowhere to post, '
                    .'and the cost would vanish from the profit-and-loss statement rather than fail loudly.',
            ]);
        }

        $category->forceFill(['is_active' => $active, 'updated_by' => $request->user()?->getKey()])->save();

        return back()->with('toast', [
            'type' => 'success',
            'message' => $active
                ? sprintf('%s is available again.', $category->name)
                : sprintf('%s is hidden from the forms. Everything already filed under it is unchanged.', $category->name),
        ]);
    }

    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($validated, $request): void {
            foreach (array_values($validated['order']) as $position => $id) {
                FinanceCategory::query()->whereKey($id)->update([
                    'sort_order' => ($position + 1) * 10,
                    'updated_by' => $request->user()?->getKey(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'The order was saved.',
        ]);
    }
}
