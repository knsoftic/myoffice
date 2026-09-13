<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Dashboard\Layout;
use App\Support\DashboardRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PUT `admin/dashboard/layout` — the per-user widget arrangement (phase-02 §4, §5).
 *
 * Two lists of widget keys and nothing else. The payload is UI state, but it is still a write, so
 * it is validated like one:
 *
 *  · both lists must be arrays of snake_case keys, bounded in length;
 *  · **every key must be one this viewer is actually allowed to see.** The registry re-decides
 *    that here — the browser's list is never trusted — so a crafted payload cannot store the key
 *    of a card the actor's permissions exclude, and "hidden" can never be used to discover which
 *    widgets exist;
 *  · a key may not appear in both lists, because "ordered third" and "switched off" are not
 *    contradictory but a client that sends both is confused and should be told.
 *
 * Authorization itself is the route's (`can:dashboard.*`): a layout is personal, so there is no
 * row rule beyond "it is your own layout", which the controller guarantees by writing only to
 * `$request->user()`.
 */
final class UpdateDashboardLayoutRequest extends FormRequest
{
    /** Guard against a payload built to bloat the JSON column. */
    private const MAX_KEYS = 200;

    /**
     * The keys this viewer may arrange, memoised so the rules and the after-hook agree.
     *
     * @var list<string>|null
     */
    private ?array $allowed = null;

    public function authorize(): bool
    {
        // The route carries `can:dashboard.view`; beyond that a user may always arrange their own
        // dashboard, and the controller only ever writes to the authenticated user's row.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order' => ['sometimes', 'array', 'max:'.self::MAX_KEYS],
            'order.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'hidden' => ['sometimes', 'array', 'max:'.self::MAX_KEYS],
            'hidden.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.*.regex' => 'That is not a dashboard widget key.',
            'hidden.*.regex' => 'That is not a dashboard widget key.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->allowedKeys();

            foreach (['order', 'hidden'] as $field) {
                foreach ($this->keyList($field) as $index => $key) {
                    if (! in_array($key, $allowed, true)) {
                        // Same message for "does not exist" and "not yours": a rejected key must
                        // not tell the caller which of the two it was.
                        $validator->errors()->add($field.'.'.$index, 'That widget is not available to you.');
                    }
                }
            }

            $both = array_intersect($this->keyList('order'), $this->keyList('hidden'));

            if ($both !== []) {
                $validator->errors()->add(
                    'hidden',
                    'A widget cannot be ordered and hidden in the same request: '.implode(', ', $both).'.',
                );
            }
        });
    }

    /**
     * The validated arrangement, already restricted to what this viewer may see.
     *
     * `restrictTo()` is belt and braces over the rule above: the rule produces the error message,
     * this produces the value that is actually stored.
     */
    public function layout(): Layout
    {
        return Layout::fromArray($this->keyList('order'), $this->keyList('hidden'))
            ->restrictTo($this->allowedKeys());
    }

    /**
     * @return list<string>
     */
    private function allowedKeys(): array
    {
        return $this->allowed ??= array_values(DashboardRegistry::for($this->user())->keys()->all());
    }

    /**
     * @return list<string>
     */
    private function keyList(string $field): array
    {
        $values = $this->input($field, []);

        if (! is_array($values)) {
            return [];
        }

        $keys = [];

        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $keys[] = trim((string) $value);
            }
        }

        return $keys;
    }
}
