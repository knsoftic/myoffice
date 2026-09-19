<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Models\User;
use App\Support\Format;
use App\Support\SettingsRegistry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * Base of every phase-05 CRM write request (phase-05 §7 "Form Requests in app/Http/Requests/Crm/").
 *
 * Authorization is asked twice on purpose: the route's `can:` middleware answers first and each request's
 * `authorize()` repeats the **same** ability — the permission string, or the policy ability on the bound model —
 * so a route wired without its middleware still refuses before a single rule runs (CLAUDE.md rule 7).
 *
 * Every scalar field declares a type rule and every nested payload declares `array` with its allowed keys, so a
 * hostile `?name[]=x` or `follow_up=string` answers 422 — never a TypeError and a 500. A field the rules do not
 * list never reaches `validated()`, which is how a posted `lead_no`, `client_code`, `status` or `user_id` is
 * dropped rather than trusted (phase-05 §6.1, §6.7, test 81).
 *
 * Settings are read through `crmSetting()` / `setting()` here, never in a view (the view receives them as data).
 */
abstract class CrmFormRequest extends FormRequest
{
    /** The D63-style bounds for a discretionary reason (delete, supersede, disable the portal). */
    public const REASON_MIN = 5;

    public const REASON_MAX = 255;

    /** A non-negative decimal(15,2) amount as typed: up to 13 integer digits and 2 decimals. */
    public const MONEY_PATTERN = '/^\d{1,13}(\.\d{1,2})?$/';

    /** A percentage in the decimal(8,4) convention, 0-100 (CHECK `chk_clients_tax_rate`). */
    public const RATE_PATTERN = '/^\d{1,3}(\.\d{1,4})?$/';

    /** A phone or WhatsApp number as a person types it; `ContactNormalizer` derives the comparison key. */
    public const PHONE_PATTERN = '/^\+?[0-9()\-.\s]{3,32}$/';

    /** An ISO-3166-1 alpha-2 country code. */
    public const COUNTRY_CODE_PATTERN = '/^[A-Za-z]{2}$/';

    /** A referral code as it arrives on `?ref=` (`COL-1024`). */
    public const REFERRAL_CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/';

    /**
     * The bound route model, when it is an instance of the expected class.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel|null
     */
    protected function boundModel(string $parameter, string $class): ?Model
    {
        $value = $this->route($parameter);

        return $value instanceof $class ? $value : null;
    }

    /**
     * The user asking, when there is one.
     */
    protected function actor(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The actor holds `$ability` (a permission name, or a policy ability on `$subject`).
     */
    protected function actorCan(string $ability, mixed $subject = null): bool
    {
        $actor = $this->actor();

        if (! $actor instanceof User) {
            return false;
        }

        return $subject === null ? $actor->can($ability) : $actor->can($ability, $subject);
    }

    /**
     * A `crm.*` setting (phase-05 §5), or `$default` when settings cannot be read. Every key read here is one of the
     * contract's §5 keys, declared by the `crm` group of `SettingsRegistry`.
     */
    protected function crmSetting(string $key, mixed $default = null): mixed
    {
        try {
            return settings_repo()->get('crm.'.$key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * A positive integer `crm.*` setting with a floor, for caps such as `bulk_max_ids` and `import_max_rows`.
     */
    protected function crmInt(string $key, int $default, int $min = 1): int
    {
        $value = $this->crmSetting($key, $default);

        return max($min, is_numeric($value) ? (int) $value : $default);
    }

    /**
     * Rules for a required discretionary reason.
     *
     * @return list<string>
     */
    protected function requiredReasonRules(): array
    {
        return ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX];
    }

    /**
     * Rules for an optional money field (`budget_amount`). Integers are accepted (a JSON client); floats are not,
     * because a float never touches money (CLAUDE.md rule 4).
     *
     * @return list<mixed>
     */
    protected function moneyRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (! is_string($value) && ! is_int($value)) {
                    $fail('Enter the amount as a number with at most two decimals.');

                    return;
                }

                if (preg_match(self::MONEY_PATTERN, trim((string) $value)) !== 1) {
                    $fail('Enter a positive amount with at most two decimals.');
                }
            },
        ];
    }

    /**
     * Rules for an optional percentage in the 0-100 decimal(8,4) convention.
     *
     * @return list<mixed>
     */
    protected function rateRules(): array
    {
        return [
            'nullable',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if ((! is_string($value) && ! is_int($value)) || preg_match(self::RATE_PATTERN, trim((string) $value)) !== 1) {
                    $fail('Enter a percentage between 0 and 100 with at most four decimals.');

                    return;
                }

                if (bccomp(trim((string) $value), '100', 4) === 1) {
                    $fail('A percentage cannot be more than 100.');
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    protected function phoneRules(): array
    {
        return ['nullable', 'string', 'max:32', 'regex:'.self::PHONE_PATTERN];
    }

    /**
     * @return list<string>
     */
    protected function countryCodeRules(): array
    {
        return ['nullable', 'string', 'size:2', 'regex:'.self::COUNTRY_CODE_PATTERN];
    }

    /**
     * The chosen user exists, is active, and holds `$permission` on their own grants — never the actor's
     * (`crm.auto_assign_roles`: "only users with `leads.view` and status Active are eligible").
     */
    protected function activeUserHolding(string $permission, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($permission, $message): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_numeric($value)) {
                $fail($message);

                return;
            }

            $user = User::query()->active()->find((int) $value);

            if (! $user instanceof User || ! $user->can($permission)) {
                $fail($message);
            }
        };
    }

    /**
     * A date-time typed in the display timezone that is not earlier than the start of today there (a follow-up
     * "may not be in the past beyond the current day", phase-05 §6.3).
     */
    protected function notBeforeTodayRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            try {
                $zone = Format::displayTimezone();
                $at = CarbonImmutable::parse(trim($value), $zone);
                $startOfToday = CarbonImmutable::now($zone)->startOfDay();
            } catch (Throwable) {
                $fail('Enter a valid date and time.');

                return;
            }

            if ($at->lessThan($startOfToday)) {
                $fail('Choose today or a later date.');
            }
        };
    }

    /**
     * The nested follow-up payload rules (`follow_up.*`, `next.*`) — the keys `FollowUpData::fromArray()` reads.
     *
     * @return array<string, list<mixed>>
     */
    protected function followUpRules(string $prefix, bool $required, array $typeValues): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            $prefix => [$presence, 'array:type,scheduled_at,remind_before_minutes,assigned_to,notes'],
            $prefix.'.type' => ['required_with:'.$prefix.'.scheduled_at', 'nullable', 'string', 'in:'.implode(',', $typeValues)],
            $prefix.'.scheduled_at' => ['required_with:'.$prefix.'.type', 'nullable', 'string', 'date', $this->notBeforeTodayRule()],
            $prefix.'.remind_before_minutes' => ['nullable', 'integer', 'min:0', 'max:43200'],
            $prefix.'.assigned_to' => [
                'nullable',
                'integer',
                'min:1',
                $this->activeUserHolding('leads.view', 'Choose an active user who can work on leads.'),
            ],
            $prefix.'.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The extensions an upload may have right now: `$own` narrowed by `security.allowed_file_types`, never a script
     * or executable type (`SettingsRegistry::uploadExtensions()`).
     *
     * @param  list<string>  $own
     * @return list<string>
     */
    protected function uploadExtensions(array $own): array
    {
        try {
            $allowed = settings_repo()->get('security.allowed_file_types');
        } catch (Throwable) {
            $allowed = null;
        }

        return SettingsRegistry::uploadExtensions($own, $allowed);
    }

    /**
     * The largest upload accepted right now, in kilobytes (`security.max_upload_mb`, capped by PHP's own limit).
     */
    protected function uploadKilobytes(int $ownKilobytes): int
    {
        try {
            $megabytes = settings_repo()->get('security.max_upload_mb');
        } catch (Throwable) {
            $megabytes = null;
        }

        return SettingsRegistry::uploadKilobytes($ownKilobytes, $megabytes);
    }

    /**
     * Trim string inputs and turn blanks into null; leave everything else exactly as it came so the rules can
     * reject it.
     *
     * @param  list<string>  $keys
     */
    protected function trimStrings(array $keys): void
    {
        $clean = [];

        foreach ($keys as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $value = trim($value);
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * The trimmed reason, or null when none was given.
     */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        if (! is_string($reason)) {
            return null;
        }

        $reason = trim($reason);

        return $reason === '' ? null : $reason;
    }
}
