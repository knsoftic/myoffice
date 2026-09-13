<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Services\Cms\SectionValidator;
use App\Support\RichText;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Base of every phase-03 CMS write request.
 *
 * Authorization is asked twice on purpose: the route's `can:` middleware answers first, and
 * `authorize()` repeats the **same** permission here, so a route wired without its middleware still
 * refuses before a single rule runs (CLAUDE.md §1.7). Record-level rules (a required section, a system
 * page, an asset in use) belong to the policies and the services, not to a request.
 *
 * Every scalar field declares a type rule and every nested payload declares `array`, so a hostile
 * `?title[]=x` or `content=string` answers 422 — never a TypeError and a 500.
 */
abstract class CmsFormRequest extends FormRequest
{
    /** The D63-style bounds for a discretionary reason (unpublish, remove, revert). */
    public const REASON_MIN = 5;

    public const REASON_MAX = 255;

    /**
     * The permission this request needs — exactly the `can:` of its route (phase-03 §7). Null when the
     * route bound nothing this request recognises, which always refuses (Super Admin included).
     */
    abstract protected function permission(): ?string;

    public function authorize(): bool
    {
        $permission = $this->permission();

        return $permission !== null && $this->user()?->can($permission) === true;
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
     * Rules for a required discretionary reason.
     *
     * @return list<string>
     */
    protected function requiredReasonRules(): array
    {
        return ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX];
    }

    /**
     * An href a public page may render: `https://`, `mailto:`, `tel:`, a site path or an `#anchor`
     * (phase-03 §6.6). `javascript:`, `data:`, `vbscript:` and protocol-relative `//host` never pass.
     */
    protected function safeHrefRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! is_string($value) || ! RichText::isSafeHref(trim($value))) {
                $fail('Use https://, mailto:, tel:, a path starting with / or an #anchor.');
            }
        };
    }

    /**
     * The allowlisted icon names of `resources/data/icons.php` (§6.6), read through the same
     * `SectionValidator::icons()` the service validates with, so the two can never disagree. While the
     * allowlist file has not shipped, an icon must still look like a Heroicons name.
     */
    protected function iconRule(): mixed
    {
        $icons = app(SectionValidator::class)->icons();

        return $icons === null ? 'regex:'.SectionValidator::ICON_PATTERN : Rule::in($icons);
    }

    /**
     * Trim string inputs; leave everything else exactly as it came so the rules can reject it.
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
}
