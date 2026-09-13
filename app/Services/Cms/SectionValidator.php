<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\StatisticMetric;
use App\Enums\Cms\StatisticValueMode;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Support\Cms\SectionRegistry;
use App\Support\Money;
use App\Support\RichText;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Validates and normalises section content against the shape `SectionRegistry` declares
 * (phase-03 §6.1, §6.2) — the gate that keeps malformed content out of every public view.
 *
 * A Form Request validates first, but a seeder, a console command, an import or a later phase calls
 * the service with no Form Request, so the service validates again here and is the authority.
 *
 * Invariants:
 *
 *   · **Closed key set.** A content key the type does not declare is refused (`unknownKeys`), never
 *     silently stored; a `link` composite keeps exactly `label`, `url`, `style`, `new_tab`.
 *   · **Typed output.** What leaves this class is normalised: booleans are booleans, numbers are ints,
 *     decimals are decimal *strings* (never floats — CLAUDE.md §1.4), selects are one of their
 *     options, a readonly field always carries its registry default.
 *   · **Rich text is sanitised on write** by `RichText::sanitize($html, 'cms')` (INV-13); it is
 *     sanitised again on render.
 *   · **Link hrefs** pass `SectionRegistry::LINK_URL_RULE` *and* `RichText::isSafeHref()`.
 *   · **INV-3.** A reference field with a `column` is returned under `columns` (the real FK), never
 *     inside `content`; ids are checked against live (non-trashed) rows.
 *   · **Drafts may be incomplete, never malformed.** In draft mode a `required` rule is relaxed to
 *     `nullable` so autosave works mid-edit; in publish mode every `required` field must be filled
 *     and the first empty one is named (`requiredField`, FT-13).
 */
final class SectionValidator
{
    /** An allowlisted Heroicons name has this shape even when the allowlist file is absent. */
    public const ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const COLOR_PATTERN = '/^#[0-9a-f]{6}$/i';

    /** @var list<string>|null */
    private ?array $icons = null;

    public function __construct(
        private readonly ValidationFactory $validator,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * Validate a section's field values (content keys and column-backed reference keys).
     *
     * `$current` is the stored state keyed by **field** key (see `SectionService::currentFields()`);
     * `$input` overlays it, so a partial autosave does not wipe the fields it did not send.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array{content: array<string, mixed>, columns: array<string, int|string|null>}
     *
     * @throws UnknownSectionTypeException
     * @throws InvalidSectionContentException
     */
    public function content(string $key, array $input, array $current = [], bool $forPublish = false): array
    {
        $this->assertType($key);

        $fields = array_filter(
            SectionRegistry::fields($key),
            static fn (array $field): bool => $field['stored'] !== SectionRegistry::STORED_MEDIA
        );

        $unknown = array_values(array_diff(array_map('strval', array_keys($input)), array_keys($fields)));

        if ($unknown !== []) {
            throw InvalidSectionContentException::unknownKeys($key, $unknown);
        }

        $data = [];

        foreach ($fields as $name => $field) {
            $value = array_key_exists($name, $input) ? $input[$name] : ($current[$name] ?? $field['default']);

            if ($field['readonly']) {
                $value = $field['default'];
            }

            if ($field['type'] === SectionRegistry::TYPE_LINK) {
                $value = $this->linkShape($value);
            }

            $data[$name] = $value;
        }

        if ($forPublish) {
            foreach ($fields as $name => $field) {
                if ($field['required'] && $this->isEmpty($data[$name], $field['type'])) {
                    throw InvalidSectionContentException::requiredField($key, $name, (string) $field['label']);
                }
            }
        }

        $this->validate(
            $data,
            $this->rules($fields, $forPublish),
            sprintf('The %s section content is invalid.', SectionRegistry::label($key)),
            'content.'
        );

        $content = [];
        $columns = [];

        foreach ($fields as $name => $field) {
            $value = $this->normalise($field, $data[$name]);

            if ($field['stored'] === SectionRegistry::STORED_COLUMN) {
                $columns[(string) $field['column']] = $value;

                continue;
            }

            $content[$name] = $value;
        }

        ksort($content);

        return ['content' => $content, 'columns' => $columns];
    }

    /**
     * Validate one repeater item. Items are saved one at a time from a small form, so `required` is
     * always enforced for them.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current  stored item state keyed by field key, plus `is_enabled`
     * @return array{content: array<string, mixed>, columns: array<string, mixed>, is_enabled: bool}
     *
     * @throws InvalidSectionContentException
     */
    public function item(string $key, string $group, array $input, array $current = []): array
    {
        $this->assertType($key);

        if (! SectionRegistry::hasRepeater($key, $group)) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Section type [%s] has no [%s] repeater.', $key, $group),
                ['group' => [sprintf('"%s" is not a list this section keeps.', $group)]]
            );
        }

        $fields = SectionRegistry::itemFields($key, $group);
        $allowed = array_merge(array_keys($fields), ['is_enabled']);
        $unknown = array_values(array_diff(array_map('strval', array_keys($input)), $allowed));

        if ($unknown !== []) {
            throw InvalidSectionContentException::unknownKeys($key.'.'.$group, $unknown);
        }

        $data = [];

        foreach ($fields as $name => $field) {
            $value = array_key_exists($name, $input) ? $input[$name] : ($current[$name] ?? $field['default']);

            if ($field['readonly']) {
                $value = $field['default'];
            }

            if ($field['type'] === SectionRegistry::TYPE_LINK) {
                $value = $this->linkShape($value);
            }

            $data[$name] = $value;
        }

        $enabled = array_key_exists('is_enabled', $input) ? $input['is_enabled'] : ($current['is_enabled'] ?? true);
        $data['is_enabled'] = $enabled;

        $rules = $this->rules($fields, forPublish: true);
        $rules['is_enabled'] = ['required', 'boolean'];

        $this->validate(
            $data,
            $rules,
            sprintf('The %s entry is invalid.', (string) SectionRegistry::repeater($key, $group)['label']),
            ''
        );

        $content = [];
        $columns = [];
        $mediaFields = [];

        foreach ($fields as $name => $field) {
            $value = $this->normalise($field, $data[$name]);

            if ($field['stored'] === SectionRegistry::STORED_CONTENT) {
                $content[$name] = $value;

                continue;
            }

            $columns[(string) ($field['column'] ?? $name)] = $value;

            if (in_array($field['type'], SectionRegistry::MEDIA_TYPES, true) && $value !== null) {
                $mediaFields[$name] = [(int) $value, $field['type']];
            }
        }

        // An item image must be an image (and a video field a video), not merely an existing asset.
        foreach ($mediaFields as $name => [$assetId, $type]) {
            $mime = (string) $this->db->connection()->table('media_assets')
                ->where('id', $assetId)
                ->whereNull('deleted_at')
                ->value('mime_type');

            $wanted = $type === SectionRegistry::TYPE_VIDEO ? 'video/' : 'image/';

            if (! str_starts_with($mime, $wanted)) {
                throw InvalidSectionContentException::withErrors(
                    'The chosen media item is the wrong kind of file.',
                    [$name => [$type === SectionRegistry::TYPE_VIDEO ? 'Choose a video.' : 'Choose an image.']]
                );
            }
        }

        // The cross-field rule of §6.2 and CHECK chk_wsi_value: a live statistic names its metric.
        if (array_key_exists('value_mode', $columns)) {
            $mode = StatisticValueMode::tryFrom((string) $columns['value_mode']) ?? StatisticValueMode::Manual;
            $columns['value_mode'] = $mode->value;

            $metric = $columns['metric'] ?? null;
            $metric = $metric === null ? null : StatisticMetric::tryFrom((string) $metric);

            if ($mode->requiresMetric() && ($metric === null || ! $metric->isLive())) {
                throw InvalidSectionContentException::metricRequired($group);
            }

            if (array_key_exists('metric', $columns)) {
                $columns['metric'] = $metric?->value;
            }
        }

        ksort($content);

        return [
            'content' => $content,
            'columns' => $columns,
            'is_enabled' => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Validate a media payload: role => asset id, null, or (for a `multiple` role) a list of ids.
     *
     * Roles not present in `$media` are left untouched by the caller. Every id must be a live
     * `media_assets` row of the right kind (an image role takes an image, a video role a video).
     *
     * @param  array<string, mixed>  $media
     * @return array<string, list<int>> role => ordered asset ids (an empty list clears the role)
     *
     * @throws InvalidSectionContentException
     */
    public function media(string $key, array $media): array
    {
        $this->assertType($key);

        $roles = SectionRegistry::mediaRoles($key);
        $unknown = array_values(array_diff(array_map('strval', array_keys($media)), array_keys($roles)));

        if ($unknown !== []) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Section type [%s] has no media %s [%s].', $key, count($unknown) === 1 ? 'slot' : 'slots', implode(', ', $unknown)),
                ['media' => ['Unrecognised image slots: '.implode(', ', $unknown).'.']]
            );
        }

        $resolved = [];
        $errors = [];

        foreach ($media as $role => $value) {
            $role = (string) $role;
            $slot = $roles[$role];
            $ids = [];

            foreach ($value === null || $value === '' ? [] : (is_array($value) ? $value : [$value]) as $candidate) {
                if (! is_int($candidate) && ! (is_string($candidate) && ctype_digit($candidate))) {
                    $errors['media.'.$role][] = 'Choose an item from the media library.';

                    continue 2;
                }

                $ids[] = (int) $candidate;
            }

            $ids = array_values(array_unique($ids));

            if (! $slot['multiple'] && count($ids) > 1) {
                $errors['media.'.$role][] = sprintf('%s holds one file.', $slot['label']);

                continue;
            }

            $resolved[$role] = $ids;
        }

        $all = array_values(array_unique(array_merge([], ...array_values($resolved))));

        if ($all !== []) {
            $mimes = $this->db->connection()->table('media_assets')
                ->whereIn('id', $all)
                ->whereNull('deleted_at')
                ->pluck('mime_type', 'id')
                ->all();

            foreach ($resolved as $role => $ids) {
                $kind = (string) $roles[$role]['kind'];

                foreach ($ids as $id) {
                    $mime = $mimes[$id] ?? null;

                    if ($mime === null) {
                        $errors['media.'.$role][] = sprintf('Media item #%d does not exist or is in the trash.', $id);

                        continue;
                    }

                    $isVideo = str_starts_with((string) $mime, 'video/');

                    if ($kind === SectionRegistry::KIND_VIDEO ? ! $isVideo : $isVideo || ! str_starts_with((string) $mime, 'image/')) {
                        $errors['media.'.$role][] = sprintf(
                            '%s needs %s.',
                            $roles[$role]['label'],
                            $kind === SectionRegistry::KIND_VIDEO ? 'a video' : 'an image'
                        );
                    }
                }
            }
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The media selection is invalid.', $errors);
        }

        return $resolved;
    }

    /**
     * The allowlisted icon names (`resources/data/icons.php`, §6.6), or null when the file has not
     * shipped yet — in which case an icon must still match {@see self::ICON_PATTERN}.
     *
     * @return list<string>|null
     */
    public function icons(): ?array
    {
        if ($this->icons !== null) {
            return $this->icons === [] ? null : $this->icons;
        }

        $path = function_exists('resource_path') ? resource_path('data/icons.php') : '';

        if ($path === '' || ! is_file($path)) {
            $this->icons = [];

            return null;
        }

        $data = require $path;
        $names = [];

        $collect = static function (mixed $node) use (&$collect, &$names): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $index => $leaf) {
                if (is_array($leaf)) {
                    $collect($leaf);

                    continue;
                }

                $candidate = is_int($index) ? $leaf : $index;

                if (is_string($candidate) && preg_match(self::ICON_PATTERN, $candidate) === 1) {
                    $names[] = $candidate;
                }
            }
        };

        $collect($data);

        $this->icons = array_values(array_unique($names));

        return $this->icons === [] ? null : $this->icons;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function assertType(string $key): void
    {
        if (! SectionRegistry::exists($key)) {
            throw UnknownSectionTypeException::key($key, SectionRegistry::keys());
        }
    }

    /**
     * The registry rules, hardened: live-row `exists`, icon allowlist, colour pattern, multiselect
     * members, safe hrefs, and — in draft mode — `required` relaxed to `nullable`.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, list<mixed>>
     */
    private function rules(array $fields, bool $forPublish): array
    {
        $rules = [];

        foreach ($fields as $name => $field) {
            $own = [];

            foreach ((array) $field['rules'] as $rule) {
                $own[] = $this->hardenRule($rule, $forPublish);
            }

            switch ($field['type']) {
                case SectionRegistry::TYPE_ICON:
                    $icons = $this->icons();
                    $own[] = $icons === null ? 'regex:'.self::ICON_PATTERN : Rule::in($icons);
                    break;

                case SectionRegistry::TYPE_COLOR:
                    $own[] = 'regex:'.self::COLOR_PATTERN;
                    break;

                case SectionRegistry::TYPE_URL:
                    $own[] = $this->safeHrefRule();
                    break;

                case SectionRegistry::TYPE_MULTISELECT:
                    if (is_array($field['options']) && $field['options'] !== []) {
                        $rules[$name.'.*'] = ['string', Rule::in(array_map('strval', array_keys($field['options'])))];
                    }
                    break;
            }

            $rules[$name] = $own;

            foreach ((array) $field['item_rules'] as $child => $childRules) {
                $hardened = [];

                foreach ((array) $childRules as $rule) {
                    $hardened[] = $this->hardenRule($rule, $forPublish);
                }

                if ($child === 'url') {
                    $hardened[] = $this->safeHrefRule();
                }

                $rules[$name.'.'.$child] = $hardened;
            }
        }

        return $rules;
    }

    private function hardenRule(mixed $rule, bool $forPublish): mixed
    {
        if (! is_string($rule)) {
            return $rule;
        }

        if ($rule === 'required' && ! $forPublish) {
            return 'nullable';
        }

        if (str_starts_with($rule, 'exists:')) {
            [$table, $column] = array_pad(explode(',', substr($rule, 7), 2), 2, 'id');

            // A trashed CTA block, menu, page, category or asset is not a valid target (INV-3).
            return Rule::exists($table, $column)->whereNull('deleted_at');
        }

        return $rule;
    }

    private function safeHrefRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || ! RichText::isSafeHref($value)) {
                $fail('Use https://, mailto:, tel:, a path starting with / or an #anchor.');
            }
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<mixed>>  $rules
     */
    private function validate(array $data, array $rules, string $message, string $prefix): void
    {
        $validator = $this->validator->make($data, $rules);

        if (! $validator->fails()) {
            return;
        }

        $errors = [];

        foreach ($validator->errors()->toArray() as $field => $messages) {
            $errors[$prefix.$field] = array_values((array) $messages);
        }

        throw InvalidSectionContentException::withErrors($message, $errors);
    }

    /**
     * A `link` composite reduced to its four keys; anything that is not an array stays as-is so the
     * `array` rule rejects it.
     */
    private function linkShape(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return $value;
        }

        return [
            'label' => $value['label'] ?? null,
            'url' => $value['url'] ?? null,
            'style' => $value['style'] ?? null,
            'new_tab' => $value['new_tab'] ?? false,
        ];
    }

    private function isEmpty(mixed $value, string $type): bool
    {
        if ($type === SectionRegistry::TYPE_LINK) {
            return ! is_array($value)
                || trim((string) ($value['label'] ?? '')) === ''
                || trim((string) ($value['url'] ?? '')) === '';
        }

        if ($type === SectionRegistry::TYPE_RICHTEXT) {
            return RichText::plainText(is_string($value) ? $value : null) === ''
                && ! (is_string($value) && preg_match('~<(img|iframe)\b~i', $value) === 1);
        }

        if (is_array($value)) {
            return $value === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * One validated value, typed.
     *
     * @param  array<string, mixed>  $field
     */
    private function normalise(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return $field['type'] === SectionRegistry::TYPE_BOOLEAN ? false : null;
        }

        return match ($field['type']) {
            SectionRegistry::TYPE_TEXT,
            SectionRegistry::TYPE_TEXTAREA,
            SectionRegistry::TYPE_EMAIL,
            SectionRegistry::TYPE_TEL,
            SectionRegistry::TYPE_ICON => $this->text($value),
            SectionRegistry::TYPE_URL => $this->nullable(trim((string) $value)),
            SectionRegistry::TYPE_RICHTEXT => $this->nullable(RichText::sanitize((string) $value, RichText::DEFAULT_PROFILE)),
            SectionRegistry::TYPE_NUMBER => (int) $value,
            SectionRegistry::TYPE_DECIMAL => Money::round($this->decimalString($value), 2),
            SectionRegistry::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            SectionRegistry::TYPE_SELECT,
            SectionRegistry::TYPE_COLOR => $this->nullable(trim((string) $value)),
            SectionRegistry::TYPE_MULTISELECT => array_values(array_unique(array_map('strval', (array) $value))),
            SectionRegistry::TYPE_LINK => $this->link((array) $value),
            SectionRegistry::TYPE_IMAGE,
            SectionRegistry::TYPE_VIDEO,
            SectionRegistry::TYPE_CTA_REF,
            SectionRegistry::TYPE_PAGE_REF => $this->nullableId($value),
            SectionRegistry::TYPE_MENU_REF => $field['ref_by'] === SectionRegistry::REF_BY_LOCATION
                ? $this->nullable(trim((string) $value))
                : $this->nullableId($value),
            SectionRegistry::TYPE_FAQ_CATEGORY_REF => $field['ref_by'] === SectionRegistry::REF_BY_ID
                ? $this->nullableId($value)
                : $this->nullable(trim((string) $value)),
            default => throw new InvalidArgumentException(sprintf('No normaliser for field type [%s].', $field['type'])),
        };
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{label: string|null, url: string|null, style: string, new_tab: bool}
     */
    private function link(array $value): array
    {
        $style = ButtonStyle::tryFrom((string) ($value['style'] ?? '')) ?? ButtonStyle::Primary;

        return [
            'label' => $this->text($value['label'] ?? null),
            'url' => $this->nullable(trim((string) ($value['url'] ?? ''))),
            'style' => $style->value,
            'new_tab' => filter_var($value['new_tab'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Plain text is escaped by Blade on output; strip only what can never be meant as text.
        $value = (string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', (string) $value);

        return $this->nullable(trim($value));
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function decimalString(mixed $value): string
    {
        if (is_float($value)) {
            return sprintf('%.2F', $value);
        }

        return trim((string) $value);
    }
}
