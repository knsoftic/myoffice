<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ResolvesContentModule;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Delete a taxonomy term — `admin.{...}.destroy`, `can:{module}.delete` (phase-04 §6.2, §8.1,
 * acceptance test 60).
 *
 * `reassign_to` is optional here and required by `TaxonomyService::delete()` only when the term still
 * has children (that refusal is a 422 toast, never a silent orphan). When it is given it must be a
 * **different, existing, non-trashed** term of the same list — checked here as well as in the service.
 */
final class DeleteTaxonomyRequest extends CmsFormRequest
{
    use ResolvesContentModule;

    protected function permission(): ?string
    {
        return TaxonomyDefinition::for($this->contentModule()) === null ? null : $this->contentPermission('delete');
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reassign_to' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', $this->reassignTarget()],
        ];
    }

    public function reassignTo(): ?int
    {
        $value = $this->validated('reassign_to');

        return is_numeric($value) ? (int) $value : null;
    }

    private function reassignTarget(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $definition = TaxonomyDefinition::for($this->contentModule());
            $current = $this->route('term');

            if ($definition === null || ! is_numeric($value)) {
                return;
            }

            if (is_numeric($current) && (int) $current === (int) $value) {
                $fail('Choose a different entry to move the linked records to.');

                return;
            }

            $exists = DB::table($definition['table'])
                ->where('id', (int) $value)
                ->whereNull('deleted_at')
                ->exists();

            if (! $exists) {
                $fail('The entry to move the linked records to no longer exists.');
            }
        };
    }
}
