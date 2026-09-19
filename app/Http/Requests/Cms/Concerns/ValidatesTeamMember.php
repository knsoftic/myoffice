<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Enums\Cms\ContentStatus;
use App\Enums\SocialPlatform;
use App\Models\Cms\TeamMember;
use Closure;
use Illuminate\Validation\Rule;

/**
 * A public team profile (phase-04 §2.9, §6.4, §6.11 `StoreTeamMemberRequest` /
 * `UpdateTeamMemberRequest`, §8.4, acceptance test 14).
 *
 *   · `social_links` is a map whose keys must be `SocialPlatform` values (`myspace` → 422) and whose
 *     values are `http(s)` URLs with a host;
 *   · `skills` — at most 20 entries of at most 60 characters;
 *   · `experience_years` — `integer, between:0,60`;
 *   · `department_id` / `employee_id` are deferred links (§2.1) — prohibited until Phase 7 ships
 *     `departments` / `employees`; `department` is the free-text snapshot the page groups by;
 *   · `status` and `is_public` are on the form's *Visibility* tab (§8.4); the controller demands
 *     `team.change_status` before either is changed and routes them through `TeamService`.
 *
 * The using class must extend `CmsFormRequest` and implement `teamMember()`.
 */
trait ValidatesTeamMember
{
    abstract public function teamMember(): ?TeamMember;

    /**
     * @return array<string, list<mixed>>
     */
    protected function teamMemberRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->teamMember()?->getKey();

        return array_merge([
            'name' => array_merge($required, ['bail', 'string', 'max:150']),
            'slug' => $this->slugRules('team_members', is_numeric($id) ? (int) $id : null),
            'designation' => array_merge($required, ['bail', 'string', 'max:150']),
            'department' => ['sometimes', 'bail', 'nullable', 'string', 'max:100'],
            'department_id' => $this->deferredLinkRules('departments'),
            'employee_id' => $this->deferredLinkRules('employees'),
            'bio' => ['sometimes', 'bail', 'nullable', 'string', 'max:5000'],
            'skills' => ['sometimes', 'bail', 'nullable', 'array', 'max:20'],
            'skills.*' => ['bail', 'string', 'max:60'],
            'experience_years' => ['sometimes', 'bail', 'nullable', 'integer', 'between:0,60'],
            'experience_label' => ['sometimes', 'bail', 'nullable', 'string', 'max:100'],
            'social_links' => ['sometimes', 'bail', 'nullable', 'array', $this->socialLinkKeys()],
            'social_links.*' => ['bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'portfolio_url' => ['sometimes', 'bail', 'nullable', 'string', 'max:255', 'url:http,https'],
            'is_public' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'bail', 'string', Rule::in([
                ContentStatus::Draft->value,
                ContentStatus::Published->value,
                ContentStatus::Archived->value,
            ])],
            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],
        ], $this->imageRules('photo'));
    }

    protected function prepareTeamMemberInput(): void
    {
        $this->trimInputs(['name', 'designation', 'department', 'bio', 'experience_label', 'portfolio_url']);
        $this->normaliseSlugInput();
        $this->cleanStringLists(['skills']);

        $links = $this->input('social_links');

        if (is_array($links)) {
            $clean = [];

            foreach ($links as $platform => $url) {
                if (is_string($url)) {
                    $url = trim($url);

                    if ($url === '') {
                        continue; // an empty row of the Links tab is "no link", not an invalid URL
                    }
                }

                $clean[$platform] = $url;
            }

            $this->merge(['social_links' => $clean]);
        }
    }

    /**
     * The `team_members` columns for `TeamService` — without the upload and without the two fields
     * that have their own service calls (`status`, `is_public`).
     *
     * @return array<string, mixed>
     */
    public function teamMemberPayload(): array
    {
        $data = $this->safe()->except(['photo', 'photo_media_id', 'remove_photo', 'status', 'is_public']);

        if (array_key_exists('skills', $data)) {
            $skills = $this->validatedStrings('skills');
            $data['skills'] = $skills === [] ? null : $skills;
        }

        if (array_key_exists('social_links', $data)) {
            $links = is_array($data['social_links']) ? $data['social_links'] : [];
            $data['social_links'] = $links === [] ? null : array_map('strval', $links);
        }

        return array_merge($data, $this->imageColumnPayload('photo_media_id', 'photo'));
    }

    public function requestedStatus(): ?ContentStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? ContentStatus::tryFrom($status) : null;
    }

    public function requestedPublic(): ?bool
    {
        return array_key_exists('is_public', $this->validated()) ? $this->boolean('is_public') : null;
    }

    private function socialLinkKeys(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $unknown = array_values(array_diff(array_map('strval', array_keys($value)), SocialPlatform::values()));

            if ($unknown !== []) {
                $fail('Unknown social platform: '.implode(', ', $unknown).'.');
            }
        };
    }
}
