<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Collaborator;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or reject a named set of ledger entries ([D-IMP-6], phase-10-12 §7.4).
 *
 * **`entries[]` is required and explicit.** There is deliberately no "everything matching the current
 * filter" shape: a filter evaluated on the server is a set whose contents the person pressing the
 * button never saw, and approving money they did not look at is the exact mistake a bulk action makes
 * easy. The screen sends the ids it displayed.
 *
 * The 500 cap is the service's too; it is repeated here so an oversized payload is a 422 on the field
 * rather than an exception from inside a transaction.
 */
final class BulkCommissionRequest extends FormRequest
{
    private const MAX_ENTRIES = 500;

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
            'entries' => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*' => ['integer', 'min:1'],
            // Only the reject route reads it, and its own route is what makes it required there.
            'reason' => ['nullable', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entries.required' => 'Select the commission entries first. A bulk action always names its rows.',
            'entries.max' => 'A bulk action covers at most '.self::MAX_ENTRIES.' entries. Narrow the filter and do it in batches, so that what was approved is always what somebody looked at.',
        ];
    }

    /**
     * @return list<int>
     */
    public function entryIds(): array
    {
        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map('intval', (array) $this->input('entries', []))));

        return $ids;
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason', ''));
    }
}
