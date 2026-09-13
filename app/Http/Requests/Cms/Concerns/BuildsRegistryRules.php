<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

use App\Support\Cms\SectionRegistry;
use Illuminate\Validation\Rule;

/**
 * Turns `SectionRegistry` field definitions into Form Request rules (phase-03 §6.1, §6.2).
 *
 * The registry carries single-field rules only and deliberately no icon allowlist (a registry that
 * loaded a file would stop being pure arrays), so this adds exactly what the request layer owns:
 *
 *   · `bail` first on every field, so a hostile array stops at its type rule;
 *   · `exists:` narrowed to live rows — a trashed CTA block, menu, page, category or asset is not a
 *     valid target (INV-3), the same hardening `SectionValidator` applies;
 *   · the icon allowlist, the colour pattern, the multiselect members and the safe-href check on every
 *     `url` field and every `link` composite's `url`.
 *
 * `SectionValidator` validates the same payload again inside the service — a seeder or an import has no
 * Form Request — and stays the authority. This is the first line, not the only one.
 *
 * The using class must extend `CmsFormRequest` (it supplies `iconRule()` and `safeHrefRule()`).
 */
trait BuildsRegistryRules
{
    /**
     * @param  array<string, array<string, mixed>>  $fields  normalised registry fields
     * @param  bool  $relaxRequired  drafts may be incomplete, never malformed (handover §9.2)
     * @return array<string, list<mixed>>
     */
    protected function registryRules(array $fields, string $prefix, bool $relaxRequired): array
    {
        $prefix = rtrim($prefix, '.').'.';
        $rules = [];

        foreach ($fields as $name => $field) {
            // A section-level image / video field is a `website_section_media` slot, validated under
            // `media.*`, never as content (INV-3). Item image fields are column-backed and stay.
            if ($field['stored'] === SectionRegistry::STORED_MEDIA) {
                continue;
            }

            $own = ['bail'];

            foreach ((array) $field['rules'] as $rule) {
                $own[] = $this->hardenRule($rule, $relaxRequired);
            }

            switch ($field['type']) {
                case SectionRegistry::TYPE_ICON:
                    $own[] = $this->iconRule();
                    break;

                case SectionRegistry::TYPE_COLOR:
                    $own[] = 'regex:/^#[0-9a-f]{6}$/i';
                    break;

                case SectionRegistry::TYPE_URL:
                    $own[] = $this->safeHrefRule();
                    break;

                case SectionRegistry::TYPE_MULTISELECT:
                    if (is_array($field['options']) && $field['options'] !== []) {
                        $rules[$prefix.$name.'.*'] = ['bail', 'string', Rule::in(array_map('strval', array_keys($field['options'])))];
                    }
                    break;
            }

            $rules[$prefix.$name] = $own;

            foreach ((array) $field['item_rules'] as $child => $childRules) {
                $hardened = ['bail'];

                foreach ((array) $childRules as $rule) {
                    $hardened[] = $this->hardenRule($rule, $relaxRequired);
                }

                if ($child === 'url') {
                    $hardened[] = $this->safeHrefRule();
                }

                $rules[$prefix.$name.'.'.$child] = $hardened;
            }
        }

        return $rules;
    }

    /**
     * Keys the payload carries that the field set does not declare.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>  $alsoAllowed
     * @return list<string>
     */
    protected function unknownKeys(mixed $payload, array $fields, array $alsoAllowed = []): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $declared = array_merge(array_keys($fields), $alsoAllowed);

        return array_values(array_diff(array_map('strval', array_keys($payload)), $declared));
    }

    private function hardenRule(mixed $rule, bool $relaxRequired): mixed
    {
        if (! is_string($rule)) {
            return $rule;
        }

        if ($rule === 'required' && $relaxRequired) {
            return 'nullable';
        }

        if (str_starts_with($rule, 'exists:')) {
            [$table, $column] = array_pad(explode(',', substr($rule, 7), 2), 2, 'id');

            return Rule::exists($table, $column)->whereNull('deleted_at');
        }

        return $rule;
    }
}
