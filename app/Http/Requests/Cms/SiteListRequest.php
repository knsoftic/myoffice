<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for the phase-04 public listings (phase-04 §8.11): `/services`, `/portfolio`,
 * `/blog`, `/blog/category/{slug}`, `/blog/tag/{slug}`, `/careers`, `/contact`.
 *
 * Filters are slugs, a short search term, a page number and the contact form's pre-selection
 * (`?type=service&service=12`). A hostile `?category[]=x` is a 422 — never a TypeError and a 500 — and it
 * never reaches the public response cache, which only stores 200s.
 */
final class SiteListRequest extends FormRequest
{
    private const SLUG = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:180', self::SLUG],
            'technology' => ['nullable', 'string', 'max:180', self::SLUG],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'type' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_]+$/'],
            'service' => ['nullable', 'integer', 'min:1'],
            'ref' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        abort(422);
    }

    public function slug(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function searchTerm(): ?string
    {
        $value = $this->validated('q');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function validatedId(string $key): ?int
    {
        $value = $this->validated($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
