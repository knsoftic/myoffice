<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Models\Institute\CourseCategory;
use App\Support\SlugGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a category (§64, phase-14-17 §8.1).
 *
 * `sort_order` is absent on purpose: a new category is appended by the service and the order is changed
 * by dragging, which posts the whole arrangement at once. A `sort_order` field here would be a second
 * way to set the same thing, and the two would disagree the first time somebody used both.
 */
class StoreCourseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category instanceof CourseCategory
            ? $this->user()?->can('update', $category) === true
            : $this->user()?->can('create', CourseCategory::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof CourseCategory ? (int) $category->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:170', 'regex:'.SlugGenerator::PATTERN,
                Rule::unique('course_categories', 'slug')->ignore($id)->withoutTrashed()],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:180'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'slug_change_reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Another category already uses that web address.',
            'icon.regex' => 'An icon is a name from the icon set, not markup.',
        ];
    }
}
