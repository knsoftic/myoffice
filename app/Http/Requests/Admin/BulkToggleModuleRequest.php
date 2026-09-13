<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ModuleGroup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Switch a whole group of modules at once (`admin.modules.bulk-toggle`).
 *
 * Two shapes are accepted, and exactly one is required:
 *   · `group` — every non-core module in one `ModuleGroup`, which is what the group header's
 *     "Enable all / Disable all" action posts;
 *   · `modules[]` — an explicit list of module ids, for a hand-picked selection.
 *
 * Authorization is deliberately **not** decided here: a bulk action spans many modules and
 * `ModulePolicy::toggle()` answers per module (it refuses core modules outright). The route
 * carries `can:modules.change_status`, and the controller re-runs the policy for every module it
 * is about to touch, skipping — never silently flipping — the ones it may not.
 *
 * `cascade` is the same explicit confirmation as the single toggle: without it a module whose
 * enabled dependents would break is reported as skipped, not switched off.
 */
final class BulkToggleModuleRequest extends FormRequest
{
    /**
     * How many modules one bulk action may name. The biggest legitimate batch is a single group
     * (the Institute group is 21 modules); the cap only stops an absurd payload.
     */
    private const MAX_MODULES = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],

            'group' => ['required_without:modules', 'nullable', 'string', Rule::enum(ModuleGroup::class)],

            'modules' => ['required_without:group', 'nullable', 'array', 'max:'.self::MAX_MODULES],
            // Existence is checked for every id at once in withValidator() — one whereIn query, not
            // one `exists:modules,id` query per id (Phase 2 review low 7).
            'modules.*' => ['integer', 'min:1', 'distinct'],

            // D63: switching modules off requires a human reason on the server (5–255 characters).
            'reason' => $this->has('enabled') && ! $this->boolean('enabled')
                ? ['required', 'string', 'min:'.ToggleModuleRequest::REASON_MIN, 'max:'.ToggleModuleRequest::REASON_MAX]
                : ['nullable', 'string', 'max:'.ToggleModuleRequest::REASON_MAX],
            'cascade' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'group.required_without' => 'Choose a module group, or name the modules to switch.',
            'modules.required_without' => 'Choose a module group, or name the modules to switch.',
            'reason.required' => 'Give a reason for switching these modules off. It is recorded in the audit trail.',
            'reason.min' => 'The reason must be at least :min characters.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => 'target state',
            'group' => 'module group',
            'modules' => 'module selection',
            'modules.*' => 'module',
            'cascade' => 'cascade confirmation',
        ];
    }

    /**
     * Every named module must exist — decided with ONE query for the whole list.
     *
     * Phase 2 review low 7: `exists:modules,id` on `modules.*` ran a query per id (up to
     * MAX_MODULES of them). The same verdict now comes from one `whereIn` against the same table,
     * and every missing id still gets its own error on its own index, worded exactly as the
     * `exists` rule words it. A value the `integer` / `min:1` rules already refused is not looked
     * up; an over-long list is refused by `max:` and not looked up at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = $this->input('modules');

            if (! is_array($ids) || $ids === [] || count($ids) > self::MAX_MODULES) {
                return;
            }

            /** @var array<array-key, int> $candidates index => id */
            $candidates = [];

            foreach ($ids as $index => $id) {
                // The same reading the `integer` rule makes (filter_var), bounded like `min:1`.
                $int = is_scalar($id)
                    ? filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : false;

                if ($int !== false) {
                    $candidates[$index] = $int;
                }
            }

            if ($candidates === []) {
                return;
            }

            $found = DB::table('modules')
                ->whereIn('id', array_values(array_unique($candidates)))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($candidates as $index => $id) {
                if (! in_array($id, $found, true)) {
                    $validator->errors()->add(
                        'modules.'.$index,
                        __('validation.exists', ['attribute' => $this->attributes()['modules.*']]),
                    );
                }
            }
        });
    }

    /**
     * An empty select posts `''`, which is "not supplied" rather than an invalid enum.
     */
    protected function prepareForValidation(): void
    {
        foreach (['group', 'reason'] as $key) {
            if ($this->has($key) && is_string($this->input($key)) && trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * The state every named module is being moved to.
     */
    public function desiredState(): bool
    {
        return $this->boolean('enabled');
    }

    public function groupFilter(): ?ModuleGroup
    {
        $group = $this->input('group');

        return is_string($group) && $group !== '' ? ModuleGroup::tryFrom($group) : null;
    }

    /**
     * The explicitly named module ids.
     *
     * @return list<int>
     */
    public function moduleIds(): array
    {
        $ids = $this->input('modules');

        if (! is_array($ids)) {
            return [];
        }

        $clean = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0 && ! in_array((int) $id, $clean, true)) {
                $clean[] = (int) $id;
            }
        }

        return $clean;
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');
        $reason = is_string($reason) ? trim($reason) : '';

        return $reason === '' ? null : $reason;
    }

    public function cascade(): bool
    {
        return $this->boolean('cascade');
    }
}
