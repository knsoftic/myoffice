<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

/**
 * Rename a menu or switch it on and off (`admin.website.menus.update`, `can:menus.edit`, §2.5).
 *
 * `slug` and `location` are not writable here: the slug is the stable key Blade reads (`menu('header')`)
 * and the location is the layout slot, unique per menu (`uq_menus_location`, [D-W3-4]). Menus are created
 * by the seeder, one per `MenuLocation`.
 */
final class UpdateMenuRequest extends CmsFormRequest
{
    protected function permission(): string
    {
        return 'menus.edit';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'bail', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'slug' => ['prohibited'],
            'location' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name', 'description']);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->only(['name', 'description', 'is_active']);

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $this->boolean('is_active');
        }

        return $data;
    }
}
