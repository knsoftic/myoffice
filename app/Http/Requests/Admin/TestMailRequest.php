<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Controllers\Admin\SettingsController;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Send one test email with the **saved** mail settings (phase-02 §3, route
 * `admin.settings.mail.test`).
 *
 * Authorization is the mail group's own gate, not plain `settings.edit`: whoever may not change
 * the SMTP credentials may not use them to send either. The route carries `throttle:3,1` and
 * `TestMailService` rate-limits per user as well, so a bad host cannot be used as a probe.
 *
 * The recipient defaults to the signed-in user's own address — the common case is "does this
 * work at all", and it keeps the feature from becoming a way to mail strangers.
 */
final class TestMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return SettingsController::mayEditGroup($this->user(), SettingsController::GROUP_MAIL);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'recipient',
        ];
    }

    /**
     * An empty recipient means "send it to me".
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_array($email)) {
            // `email[]=a` is left as posted so the `email` rule refuses it as a 422, rather than a
            // (string) cast raising "Array to string conversion" and a 500.
            return;
        }

        $email = trim((string) $email);

        if ($email === '') {
            $email = (string) ($this->user()?->email ?? '');
        }

        $this->merge(['email' => $email]);
    }

    /**
     * Where the test message goes.
     */
    public function recipient(): string
    {
        return (string) $this->validated()['email'];
    }
}
