<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The user editing their own profile (route `account.profile.update`, phase-01 §7).
 *
 * Writable here: name, phone, whatsapp, locale, timezone. Email, status, roles and branch are
 * **not** — those are administrative fields and belong to the users module, so they can never be
 * changed by posting extra keys to this endpoint.
 *
 * The language list is read from the `localization.locale` setting row, never hardcoded
 * (CLAUDE.md §1.8); the current value is always allowed so an existing row stays valid even if
 * the setting changes later.
 */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-.\s]{6,32}$/'],
            'whatsapp' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+()\-.\s]{6,32}$/'],
            'locale' => ['required', 'string', 'max:8', Rule::in(array_keys(self::localeOptions($this->user()?->locale)))],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Use digits, spaces and + ( ) - only.',
            'whatsapp.regex' => 'Use digits, spaces and + ( ) - only.',
            'timezone.timezone' => 'Choose a timezone from the list.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'locale' => 'language',
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'whatsapp', 'timezone'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = $this->input($field);

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            $this->merge([$field => $value === '' ? null : $value]);
        }
    }

    /**
     * Languages a user may choose: whatever `localization.locale` offers, plus the value already
     * on the row and the application default.
     *
     * Shared with the profile screen so the select and the validator can never disagree.
     *
     * @return array<string, string>
     */
    public static function localeOptions(?string $current = null): array
    {
        $options = [];

        try {
            $setting = Setting::query()
                ->where('group', 'localization')
                ->where('key', 'locale')
                ->first();

            $stored = $setting?->options;

            if (is_array($stored)) {
                foreach ($stored as $value => $label) {
                    $value = trim((string) $value);

                    if ($value !== '') {
                        $options[$value] = (string) $label;
                    }
                }
            }
        } catch (Throwable) {
            // Settings table unavailable (fresh install): fall back to the defaults below.
        }

        foreach ([$current, (string) config('app.locale', 'en'), (string) config('app.fallback_locale', 'en')] as $extra) {
            $extra = trim((string) $extra);

            if ($extra !== '' && ! array_key_exists($extra, $options)) {
                $options[$extra] = mb_strtoupper($extra);
            }
        }

        return $options === [] ? ['en' => 'English'] : $options;
    }
}
