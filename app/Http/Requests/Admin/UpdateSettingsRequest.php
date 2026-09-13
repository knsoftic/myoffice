<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use App\Services\Core\SettingsService;
use App\Support\ConfigureFromSettings;
use App\Support\SettingsRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Save one settings group (phase-02 §4, route `admin.settings.update`).
 *
 * Every rule comes from `SettingsRegistry::rulesFor($group, 'settings')` — there is no
 * hand-written copy of a rule anywhere in the settings screen, so a field added to the registry
 * is validated the moment it appears on the form.
 *
 * The form nests its inputs under one `settings` key (`settings[brand_color]`,
 * `settings[business_hours][monday][open]`, `settings[payout_methods][]`), which is exactly the
 * shape the registry's `$prefix` argument exists for.
 *
 * Three payload shapes are refused rather than ignored, so a crafted POST cannot quietly write
 * somewhere it should not (the acceptance matrix in §6 "Validation" / "Authorization"):
 *
 *   · an **unknown** key — not declared by the registry at all;
 *   · a key **belonging to another group** — declared, but not in the group being saved;
 *   · a **readonly** key — declared readonly by the registry or stored with `is_readonly = 1`, and
 *     writable only from the console or the environment.
 *
 * Laravel's `validated()` would simply drop all three (no rule, no data). The explicit check in
 * `withValidator()` turns that silence into a 422 naming the key, which is what an operator
 * needs to see and what a test can assert.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /** The one input name every field nests under. */
    public const PAYLOAD = 'settings';

    /**
     * readonlyKeys(), memoised for the request.
     *
     * @var list<string>|null
     */
    private ?array $readonly = null;

    /**
     * `settings.edit`, plus the extra gate the mail group carries.
     *
     * Both live in SettingsController so the screen, the tab rail and this request cannot
     * disagree about who may save what.
     */
    public function authorize(): bool
    {
        return SettingsController::mayEditGroup($this->user(), $this->groupSlug());
    }

    /**
     * Registry rules, minus the readonly keys.
     *
     * A readonly field is never rendered as an input and is refused below if it is posted
     * anyway, so carrying its rules here would only risk a `required` rule failing on a field
     * the form is not allowed to send.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->groupSlug();
        $readonly = $this->readonlyKeys();

        $rules = [
            self::PAYLOAD => ['array'],
        ];

        foreach (SettingsRegistry::rulesFor($group, self::PAYLOAD) as $key => $fieldRules) {
            if (in_array($this->bareKey((string) $key), $readonly, true)) {
                continue;
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Field labels, so an error reads "The brand colour must be…" instead of naming the key.
     *
     * Array children get a name too: without one, a rejected option reads "The selected
     * settings.payout_methods.1 is invalid". Laravel resolves `…payout_methods.1` back to the
     * wildcard rule it came from, so naming the wildcard is enough.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->definitions() as $key => $field) {
            $label = mb_strtolower((string) $field['label']);

            $attributes[self::PAYLOAD.'.'.$key] = $label;

            foreach (array_keys((array) $field['item_rules']) as $suffix) {
                $suffix = (string) $suffix;

                $child = trim(str_replace(['*', '.', '_'], ['', ' ', ' '], $suffix));

                $attributes[self::PAYLOAD.'.'.$key.'.'.$suffix] = $child === ''
                    ? Str::singular($label)
                    : $label.' '.$child;
            }
        }

        return $attributes;
    }

    /**
     * Normalise what an HTML form unavoidably posts.
     *
     *   · `settings` always exists, so the `array` rule has something to look at;
     *   · a nullable text field submitted empty becomes null rather than an empty string, so a
     *     cleared field reads back as "not set" everywhere (`filled()`, the masked secret, the
     *     public website) instead of as an empty value;
     *   · booleans arrive as the "0" / "1" the hidden input + checkbox pair posts.
     *
     * Files are left alone — they are not part of `input()`.
     */
    protected function prepareForValidation(): void
    {
        $payload = $this->input(self::PAYLOAD);

        if ($payload !== null && ! is_array($payload)) {
            // `settings=nonsense`: leave it exactly as posted so the `array` rule refuses it,
            // rather than quietly turning it into an empty payload that saves nothing.
            return;
        }

        $payload ??= [];

        $definitions = $this->definitions();

        foreach ($payload as $key => $value) {
            $field = $definitions[$key] ?? null;

            if ($field === null) {
                continue;
            }

            if ($field['type'] === SettingsRegistry::TYPE_BOOLEAN) {
                $payload[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

                continue;
            }

            if ($field['type'] === SettingsRegistry::TYPE_MULTISELECT) {
                // The form posts an empty sentinel so an all-unchecked list still sends the key;
                // it is dropped here, leaving a genuine empty array the rules can judge.
                $payload[$key] = is_array($value)
                    ? array_values(array_filter(
                        $value,
                        static fn (mixed $item): bool => $item !== null && $item !== ''
                    ))
                    : $value;

                continue;
            }

            if ($field['storage'] === 'decimal') {
                // Restated at the field's scale through Money BEFORE the rules run, so the rules, the
                // 100 % cap and the stored column judge one value: '5000.' is judged as 5000.0000
                // instead of slipping past a pattern that did not anticipate a trailing dot.
                // Phase 2 review low 1: only an exact restatement — '1000.005' at scale 2 is left as
                // posted and refused in withValidator(), never rounded to 1000.01. Anything that is
                // not a plain decimal ('1e3', '5,5') is left for the rules to refuse.
                $payload[$key] = is_string($value) && trim($value) === '' && $this->isNullable($field)
                    ? null
                    : SettingsRegistry::normaliseDecimal($field, $value);

                continue;
            }

            if ($field['type'] === SettingsRegistry::TYPE_JSON && is_string($value)) {
                // A json textarea posts a string; store real JSON, not a string holding JSON.
                $decoded = json_decode($value, true);

                $payload[$key] = is_array($decoded) ? $decoded : $value;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->castArrayChildren($field, $value);

                continue;
            }

            if (is_string($value) && trim($value) === '' && $this->isNullable($field)) {
                $payload[$key] = null;
            }
        }

        $this->merge([self::PAYLOAD => $payload]);
    }

    /**
     * Refuse an unknown key, a foreign key and a readonly key by name — and enforce the one rule
     * the registry cannot express.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertTransportIsUsable($validator);

            // A commission rate is at most 100 while its type is a percentage.
            $crossField = SettingsRegistry::crossFieldErrors(
                $this->groupSlug(),
                fn (string $key): mixed => in_array($key, $this->submittedKeys(), true)
                    ? $this->input(self::PAYLOAD.'.'.$key)
                    : settings_repo()->get($this->groupSlug().'.'.$key),
                $this->submittedKeys(),
            );

            foreach ($crossField as $key => $message) {
                $validator->errors()->add(self::PAYLOAD.'.'.$key, $message);
            }

            $group = $this->groupSlug();
            $definitions = $this->definitions();
            $readonly = $this->readonlyKeys();

            foreach ($this->submittedKeys() as $key) {
                if (! array_key_exists($key, $definitions)) {
                    $owner = $this->owningGroup($key);

                    $validator->errors()->add(
                        self::PAYLOAD.'.'.$key,
                        $owner === null
                            ? sprintf('[%s] is not a setting.', $key)
                            : sprintf('[%s] belongs to the %s settings, not to %s.', $key, $owner, $group)
                    );

                    continue;
                }

                if (in_array($key, $readonly, true)) {
                    $validator->errors()->add(
                        self::PAYLOAD.'.'.$key,
                        sprintf('[%s] is read-only and may only be changed from the console.', $key)
                    );

                    continue;
                }

                // Phase 2 review low 1: a decimal with more decimals than its scale is refused, never
                // rounded — enforced here, not left to whether the field happens to carry `decimal:`.
                if (
                    ! $validator->errors()->has(self::PAYLOAD.'.'.$key)
                    && SettingsRegistry::exceedsScale($definitions[$key], $this->input(self::PAYLOAD.'.'.$key))
                ) {
                    $validator->errors()->add(self::PAYLOAD.'.'.$key, SettingsRegistry::scaleErrorMessage($definitions[$key]));
                }

                // A json field still holding a string is a textarea whose JSON did not parse;
                // "must be an array" would be a riddle, so say what is actually wrong.
                if (
                    $definitions[$key]['type'] === SettingsRegistry::TYPE_JSON
                    && is_string($this->input(self::PAYLOAD.'.'.$key))
                ) {
                    $validator->errors()->add(
                        self::PAYLOAD.'.'.$key,
                        sprintf('The %s is not valid JSON.', mb_strtolower((string) $definitions[$key]['label']))
                    );
                }
            }
        });
    }

    /**
     * "SMTP with no host" is refused here, where the other keys' values can be seen.
     *
     * `SettingsRegistry::rulesFor()` is per-field by design — it is handed one key at a time and
     * cannot look at another — so `mail.host`'s own help text ("Required once the transport is
     * SMTP") was enforced nowhere: `mailer` could be saved as `smtp` with `host` empty, and the
     * failure then surfaced as a transport error on the next send instead of a field error on the
     * screen. `ConfigureFromSettings::isUsableMailer()` already encodes the same rule for the
     * runtime, which is exactly why it belongs on the way in too.
     *
     * The judgement is made on the **effective** value — what will be stored once this save lands,
     * i.e. the submitted value when the key is present and the currently stored one otherwise.
     * A naive `required_if:settings.mailer,smtp` would read only the payload, so a partial save
     * carrying `mailer` without `host` (or `host` without `mailer`) would pass while leaving the
     * pair broken.
     *
     * The rule is derived from the registry, not hardcoded to a group name: it applies to whichever
     * group declares both `mailer` and `host`.
     */
    private function assertTransportIsUsable(Validator $validator): void
    {
        $definitions = $this->definitions();

        if (! array_key_exists('mailer', $definitions) || ! array_key_exists('host', $definitions)) {
            return;
        }

        $mailer = $this->effectiveValue('mailer');
        $host = $this->effectiveValue('host');

        if (ConfigureFromSettings::isUsableMailer($mailer, $host)) {
            return;
        }

        if ($mailer !== 'smtp') {
            // An unknown transport is already refused by the field's own `in:` rule.
            return;
        }

        $validator->errors()->add(
            self::PAYLOAD.'.host',
            sprintf(
                'The %s is required when the transport is SMTP.',
                mb_strtolower((string) $definitions['host']['label']),
            ),
        );
    }

    /**
     * What this key will hold once the save lands: the submitted value when it was submitted, the
     * stored value otherwise. Trimmed to a string, because that is what the transport check reads.
     */
    private function effectiveValue(string $key): string
    {
        if (in_array($key, $this->submittedKeys(), true)) {
            $value = $this->input(self::PAYLOAD.'.'.$key);

            return is_scalar($value) ? trim((string) $value) : '';
        }

        $value = settings_repo()->get($this->groupSlug().'.'.$key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /*
    |--------------------------------------------------------------------------
    | Readers the controller uses
    |--------------------------------------------------------------------------
    */

    /**
     * The group being saved, 404 when it is not a declared group.
     */
    public function groupSlug(): string
    {
        $group = (string) $this->route('group');

        if (! SettingsRegistry::hasGroup($group)) {
            throw new NotFoundHttpException(sprintf('Unknown settings group [%s].', $group));
        }

        return $group;
    }

    /**
     * What to hand `SettingsService`: bare key => value, uploads included.
     *
     * Only keys that survived validation are here, so unknown, foreign and readonly keys are
     * already gone by construction as well as by the error above.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $validated = $this->validated();
        $values = $validated[self::PAYLOAD] ?? [];

        return is_array($values) ? $values : [];
    }

    /**
     * The uploads among those values, keyed the same way.
     *
     * @return array<string, UploadedFile>
     */
    public function uploads(): array
    {
        return array_filter(
            $this->values(),
            static fn (mixed $value): bool => $value instanceof UploadedFile
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * This group's field definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        return SettingsRegistry::fields($this->groupSlug());
    }

    /**
     * Keys of this group that may not be written from the screen: registry-readonly keys AND rows
     * stored with `is_readonly = 1` — the same answer `SettingsService::readonlyKeys()` gives, because
     * it is that method. Resolved once per request.
     *
     * @return list<string>
     */
    private function readonlyKeys(): array
    {
        return $this->readonly ??= app(SettingsService::class)->readonlyKeys($this->groupSlug());
    }

    /**
     * Every key the request actually carries, from the body and from the uploads.
     *
     * @return list<string>
     */
    private function submittedKeys(): array
    {
        $payload = $this->input(self::PAYLOAD);
        $files = $this->allFiles()[self::PAYLOAD] ?? [];

        $keys = array_merge(
            is_array($payload) ? array_keys($payload) : [],
            is_array($files) ? array_keys($files) : [],
        );

        return array_values(array_unique(array_map('strval', $keys)));
    }

    /**
     * The group that owns a key the current group does not, or null when nobody does.
     */
    private function owningGroup(string $key): ?string
    {
        foreach (SettingsRegistry::all() as $group => $fields) {
            if (array_key_exists($key, $fields)) {
                return (string) $group;
            }
        }

        return null;
    }

    /**
     * 'settings.business_hours.*.open' => 'business_hours'.
     */
    private function bareKey(string $ruleKey): string
    {
        $key = str_starts_with($ruleKey, self::PAYLOAD.'.')
            ? substr($ruleKey, strlen(self::PAYLOAD) + 1)
            : $ruleKey;

        return explode('.', $key, 2)[0];
    }

    /**
     * Cast the children of a json / multiselect value the way its own `item_rules` describe.
     *
     * `business_hours` is the case that needs it: its `*.closed` child is a checkbox, and a
     * checkbox posts the string "0" or "1". The rule to apply is read from the registry
     * (`item_rules['*.closed']` contains `boolean`), never from the key's name, so any later
     * field with a boolean child is handled without touching this method.
     *
     * @param  array<string, mixed>  $field
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function castArrayChildren(array $field, array $value): array
    {
        foreach ((array) $field['item_rules'] as $suffix => $itemRules) {
            if (! in_array('boolean', (array) $itemRules, true)) {
                continue;
            }

            $segments = explode('.', (string) $suffix);

            if (count($segments) !== 2 || $segments[0] !== '*') {
                continue;
            }

            $child = $segments[1];

            foreach ($value as $index => $row) {
                if (! is_array($row) || ! array_key_exists($child, $row)) {
                    continue;
                }

                $value[$index][$child] = filter_var(
                    $row[$child],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ) ?? false;
            }
        }

        return $this->completeRows($field, $value);
    }

    /**
     * Give every row of a row-editor json value each child the registry declares, in declared
     * order, with null for a child the browser did not post.
     *
     * A closed business-hours day disables its time inputs, and a disabled input posts nothing, so
     * the row arrived as `{"closed": true}` while the stored row is
     * `{"open": null, "close": null, "closed": true}`. `validated()` then kept only what was posted
     * and an untouched Contact save rewrote `business_hours` and logged a change nobody made.
     * Completing the row here makes the posted shape the stored shape, so a no-op is a no-op.
     *
     * @param  array<string, mixed>  $field
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function completeRows(array $field, array $value): array
    {
        $children = [];

        foreach (array_keys((array) $field['item_rules']) as $suffix) {
            if (preg_match('/^\*\.([A-Za-z0-9_]+)$/', (string) $suffix, $matches) === 1) {
                $children[] = $matches[1];
            }
        }

        if ($children === []) {
            return $value;
        }

        foreach ($value as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $complete = [];

            foreach ($children as $child) {
                $complete[$child] = $row[$child] ?? null;
            }

            // Undeclared children are kept for the validator to see; validated() drops them.
            $value[$index] = $complete + $row;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isNullable(array $field): bool
    {
        foreach ((array) $field['rules'] as $rule) {
            if (is_string($rule) && ($rule === 'nullable' || $rule === 'sometimes')) {
                return true;
            }
        }

        return false;
    }
}
