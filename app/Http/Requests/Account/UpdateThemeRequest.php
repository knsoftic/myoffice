<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\ThemePreference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /account/theme (route `account.theme.update`) — the endpoint resources/js/theme.js calls
 * whenever the switcher is used, so the preference survives onto another device.
 *
 * The allowed values are the ThemePreference cases, never a hand-written list.
 */
final class UpdateThemeRequest extends FormRequest
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
            'theme' => ['required', 'string', Rule::enum(ThemePreference::class)],
        ];
    }

    /**
     * The validated preference as an enum case.
     */
    public function theme(): ThemePreference
    {
        return ThemePreference::from((string) $this->validated('theme'));
    }
}
