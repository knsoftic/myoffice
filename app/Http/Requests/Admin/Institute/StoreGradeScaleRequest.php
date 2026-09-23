<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Models\Institute\GradeScale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A grade scale and the bands that make it up (phase-19-23 §6.8, INV-20-3).
 *
 * **The band geometry is not checked here.** Contiguity, overlap, the 0–100 span and the "at least one
 * passing band" rule are `GradeBandValidator`'s, because they are facts about the *set* of bands rather
 * than about any one of them, and because the service has to re-check them anyway before it writes. A
 * copy of that arithmetic in a Form Request would be a second opinion that could disagree with the
 * first — and the one that matters is whichever runs last.
 *
 * What this layer does is make every band well-formed enough for that validator to reason about:
 * a grade that is a string, two percentages that are numbers in range **at two decimals**, a pass flag
 * that is a boolean, a colour the badge component can actually render. `GradeBandValidator` may then
 * assume its input is shaped and speak only about geometry — which is what lets its messages name the
 * actual gap instead of a type error.
 */
class StoreGradeScaleRequest extends FormRequest
{
    /**
     * The palette `resources/views/components/ui/badge.blade.php` holds, in its order.
     *
     * Duplicated rather than read from the view, because a Blade file is not a source of data — but
     * duplicated *deliberately and named*, so the next person to add a colour knows there are two
     * places. The badge falls back to slate for anything it does not know, which is why a value it
     * cannot render must be refused here rather than stored and quietly ignored.
     */
    private const BADGE_COLOURS = [
        'slate', 'gray', 'brand', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'red',
        'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky', 'blue',
    ];

    public function authorize(): bool
    {
        $scale = $this->route('grade_scale');

        return $scale instanceof GradeScale
            ? $this->user()?->can('update', $scale) === true
            : $this->user()?->can('create', GradeScale::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $scale = $this->route('grade_scale');
        $id = $scale instanceof GradeScale ? $scale->getKey() : null;

        return [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('grade_scales', 'code')->ignore($id)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],

            // A percentage, so decimal(8,4) — CLAUDE.md §3, and `decimal` is what refuses `1E1` (D114).
            'pass_percentage' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],

            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Absent means "leave the bands alone" on an update; the service reads null that way.
            'bands' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'],
            'bands.*.grade' => ['required', 'string', 'max:8'],
            'bands.*.title' => ['nullable', 'string', 'max:80'],
            // **Two decimals, not four, even though the column is decimal(8,4).** `GradeBandValidator`
            // reasons at two throughout — §6.9's step between bands is 0.01, and a percentage is
            // computed half-up at two — so `39.9950` and `40.0050` satisfy the step arithmetically
            // while leaving `40.00` in no band at all. Accepting four here does not make that work; it
            // makes the validator report "F (up to 40.00) and C (from 40.00) overlap" about two numbers
            // the administrator can see are different, which is a worse error than refusing the input.
            'bands.*.min_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'bands.*.max_percentage' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            // Nullable: a scale that does not carry GPA leaves this empty rather than storing a 0.00
            // that would print on a transcript as if it meant something.
            'bands.*.grade_point' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:10'],
            'bands.*.is_pass' => ['nullable', 'boolean'],
            // A **Tailwind colour token**, not a hex code. `x-ui.badge` resolves colours from a
            // literal map so Tailwind's scanner sees every class, which means a hex value is not just
            // off-convention — it is unrenderable, and would have shown as the fallback slate while
            // the stored row insisted it was green. Every enum's `color()` speaks this vocabulary and
            // so does `GradeScaleSeeder`; this is the same list the badge component holds.
            'bands.*.color' => ['nullable', 'string', Rule::in(self::BADGE_COLOURS)],
            'bands.*.remark_template' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * An unticked checkbox sends nothing at all, so `is_pass` would arrive absent for every failing
     * band and the validator would read "not stated" where the form said "no". Filling them in here
     * keeps that distinction out of the geometry check, which has enough to think about.
     */
    protected function prepareForValidation(): void
    {
        $bands = $this->input('bands');

        if (! is_array($bands)) {
            return;
        }

        foreach ($bands as $index => $band) {
            if (! is_array($band)) {
                continue;
            }

            $bands[$index]['is_pass'] = (bool) ($band['is_pass'] ?? false);
        }

        $this->merge(['bands' => $bands]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A code is letters, digits, dashes and underscores — nothing else.',
            'code.unique' => 'Another scale already uses that code.',
            'bands.required' => 'A scale with no bands cannot grade anybody.',
            'bands.max' => 'Twenty bands is already more than any scale needs.',
            'bands.*.grade.required' => 'Every band needs a grade letter.',
            'bands.*.min_percentage.decimal' => 'Band edges go to two decimals — 39.99, not 39.9950.',
            'bands.*.max_percentage.decimal' => 'Band edges go to two decimals — 39.99, not 39.9950.',
            'bands.*.color.in' => 'Pick one of the standard colours — emerald, sky, amber, rose and so on.',
        ];
    }
}
