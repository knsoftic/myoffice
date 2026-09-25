<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Models\Cms\Event;
use App\Support\Format;
use App\Support\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;
use UnitEnum;

/**
 * Create an event — `admin.events.store`, `can:events.create`.
 *
 * **This class also holds the rules `UpdateEventRequest` uses**, through `eventRules(partial: true)`.
 * Every other phase-04 module keeps its shared rules in an `App\Http\Requests\Cms\Concerns\Validates*`
 * trait; this pair shares them by inheritance instead, because the brief for this module fixed the file
 * list at two requests and a copied rule set is exactly the thing that drifts. If a third module ever
 * needs these rules, lift them into a `ValidatesEvent` trait and let both classes use it — do not copy
 * them. (The class is therefore not `final`; `UpdateEventRequest` is.)
 *
 * **`status`, `published_at` and `is_featured` are `prohibited` here**, not merely absent. Publishing is
 * not editing: those three are written only by `admin.events.status` and `admin.events.featured`, both
 * behind `events.change_status`. Declaring them prohibited means a hand-crafted extra field on the save
 * form answers 422 by name rather than being silently dropped — and the model does not list them in
 * `$fillable` either, so there are two independent floors under the same rule.
 *
 * **The four database CHECK constraints are mirrored in {@see after()}**, not in the rule array, and
 * deliberately so. Each constraint is evaluated by MariaDB against the **final row**, which on a partial
 * update is "what was posted, falling back to what is already stored" — a declarative
 * `after_or_equal:starts_at` or `required_if:is_online,0` only sees what was posted, and passes happily
 * while the row it is about to write violates the constraint. `after()` reconstructs the effective row
 * and checks exactly what the database will check:
 *
 *   `chk_events_ends_after_starts`  `ends_at IS NULL OR ends_at >= starts_at`
 *   `chk_events_online_has_url`     `is_online = 0 OR meeting_url IS NOT NULL`
 *   `chk_events_has_a_where`        `is_online = 1 OR location IS NOT NULL`
 *   `chk_events_capacity`           `capacity IS NULL OR capacity > 0`
 *
 * The cover image is a media library id and nothing else (D24): there is no file input on this form, so
 * there is no upload rule — `cover_media_id` must name a live `media_assets` row, and an empty string
 * from the picker clears the slot.
 *
 * Times are read in the display timezone and returned in UTC (D61); `eventPayload()` does that
 * conversion so no caller has to remember it.
 */
class StoreEventRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentSlug;

    /** The `datetime-local` / date strings the form posts are short; anything longer is not a moment. */
    private const MOMENT_MAX = 40;

    protected function permission(): string
    {
        return 'events.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->eventRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->mirrorDatabaseChecks($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareEventInput();
    }

    /**
     * The event this request writes to — null while creating.
     */
    public function event(): ?Event
    {
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, list<mixed>>
     */
    protected function eventRules(bool $partial): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $id = $this->event()?->getKey();

        return [
            'title' => array_merge($required, ['bail', 'string', 'max:200']),
            'slug' => $this->slugRules('events', is_numeric($id) ? (int) $id : null, Event::SLUG_MAX_LENGTH),
            'summary' => ['sometimes', 'bail', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'bail', 'nullable', 'string', 'max:'.Event::MAX_DESCRIPTION_LENGTH],

            // D24: a live media library asset, or nothing. There is no path column and no file input.
            'cover_media_id' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],

            'starts_at' => array_merge($required, ['bail', 'string', 'max:'.self::MOMENT_MAX, 'date']),
            // Null is a moment rather than a span — an announcement. `after()` mirrors the CHECK.
            'ends_at' => ['sometimes', 'bail', 'nullable', 'string', 'max:'.self::MOMENT_MAX, 'date'],
            'is_all_day' => ['sometimes', 'boolean'],

            // Required-ness is decided in `after()` against the effective row, not against this post.
            'location' => ['sometimes', 'bail', 'nullable', 'string', 'max:255'],
            'is_online' => ['sometimes', 'boolean'],
            'meeting_url' => ['sometimes', 'bail', 'nullable', 'string', 'max:500', 'url:http,https'],
            'registration_url' => ['sometimes', 'bail', 'nullable', 'string', 'max:500', 'url:http,https'],

            // Null means "not limited", which is not the same as zero seats left (chk_events_capacity).
            'capacity' => ['sometimes', 'bail', 'nullable', 'integer', 'min:1', 'max:'.Event::MAX_CAPACITY],

            'sort_order' => ['sometimes', 'bail', 'nullable', 'integer', 'min:0', 'max:2147483647'],

            // Publishing is not editing: these three belong to `events.change_status` alone.
            'status' => ['prohibited'],
            'published_at' => ['prohibited'],
            'is_featured' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Publishing is a separate step: save the event, then use Publish. It needs the permission to change an event’s status.',
            'published_at.prohibited' => 'The go-live moment is set when the event is scheduled or published, not while it is saved.',
            'is_featured.prohibited' => 'Featuring an event needs the permission to change its status.',
            'meeting_url.url' => 'The joining link must be a full https:// address.',
            'registration_url.url' => 'The registration link must be a full https:// address.',
        ];
    }

    protected function prepareEventInput(): void
    {
        $this->trimInputs([
            'title', 'summary', 'description', 'location',
            'meeting_url', 'registration_url', 'starts_at', 'ends_at', 'cover_media_id',
        ]);

        $this->normaliseSlugInput();
    }

    /*
    |--------------------------------------------------------------------------
    | The database CHECK constraints, mirrored against the effective row
    |--------------------------------------------------------------------------
    */

    /**
     * Re-run the four table constraints on the row this request is about to write, so the editor gets a
     * message on the field instead of a driver error. A field that already failed its own rule is left
     * alone — two messages about one value read as two problems.
     */
    protected function mirrorDatabaseChecks(Validator $validator): void
    {
        $stored = $this->event();

        // chk_events_ends_after_starts — `ends_at IS NULL OR ends_at >= starts_at`.
        $starts = $this->effectiveMoment('starts_at', $stored);
        $ends = $this->effectiveMoment('ends_at', $stored);

        if ($starts !== null && $ends !== null && ! $validator->errors()->hasAny(['starts_at', 'ends_at']) && $ends->lessThan($starts)) {
            $validator->errors()->add('ends_at', 'The event cannot finish before it starts. Leave the end empty for something that happens at a single moment.');
        }

        $isOnline = $this->effectiveBool('is_online', $stored);

        // chk_events_online_has_url — `is_online = 0 OR meeting_url IS NOT NULL`.
        if ($isOnline && ! $validator->errors()->has('meeting_url') && $this->effectiveString('meeting_url', $stored) === null) {
            $validator->errors()->add('meeting_url', 'An online event needs a joining link — without one a visitor is told they can attend from home and given no way to.');
        }

        // chk_events_has_a_where — `is_online = 1 OR location IS NOT NULL`.
        if (! $isOnline && ! $validator->errors()->has('location') && $this->effectiveString('location', $stored) === null) {
            $validator->errors()->add('location', 'Give the event a place, or mark it online and add a joining link. Neither one is an event nobody can attend.');
        }

        // chk_events_capacity — `capacity IS NULL OR capacity > 0`.
        $capacity = $this->effectiveCapacity($stored);

        if ($capacity !== null && $capacity < 1 && ! $validator->errors()->has('capacity')) {
            $validator->errors()->add('capacity', 'Leave the capacity empty for an event with no limit. Zero seats is a full event, which is a state, not a setting.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Typed accessors
    |--------------------------------------------------------------------------
    */

    /**
     * The `events` columns to write: the validated fields, with the booleans resolved, the two moments
     * converted from the display timezone to UTC (D61), the rich text sanitised (D25) and the media id
     * as an int or null.
     *
     * `status`, `published_at` and `is_featured` can never appear here — they are `prohibited` above and
     * are not `$fillable` on the model.
     *
     * @return array<string, mixed>
     */
    public function eventPayload(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->except(['status', 'published_at', 'is_featured']);

        // A blank slug is "work it out", never "blank the column": on create `HasSlug` derives one from
        // the title, and on update the stored permalink is left exactly where it is. Writing the null
        // through would put NULL into a NOT NULL unique column and break every published link at once.
        if (array_key_exists('slug', $data) && ! is_string($data['slug'])) {
            unset($data['slug']);
        }

        // D25: rich text is sanitised on write (and again on render). There is no EventService to do
        // it, so it happens here — never at the Blade, which is far too late.
        if (array_key_exists('description', $data)) {
            $clean = $data['description'] === null ? '' : trim(RichText::sanitize((string) $data['description']));
            $data['description'] = $clean === '' ? null : $clean;
        }

        foreach (['is_all_day', 'is_online'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        foreach (['starts_at', 'ends_at'] as $moment) {
            if (array_key_exists($moment, $data)) {
                $data[$moment] = $this->momentInUtc($moment);
            }
        }

        if (array_key_exists('cover_media_id', $data)) {
            $data['cover_media_id'] = is_numeric($data['cover_media_id']) ? (int) $data['cover_media_id'] : null;
        }

        if (array_key_exists('capacity', $data)) {
            $data['capacity'] = is_numeric($data['capacity']) ? (int) $data['capacity'] : null;
        }

        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = is_numeric($data['sort_order']) ? (int) $data['sort_order'] : 0;
        }

        return $data;
    }

    /**
     * A posted `datetime-local` read in the display timezone and returned in UTC (D61), or null.
     */
    public function momentInUtc(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, Format::displayTimezone())->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Effective-row helpers (posted value, else what is already stored)
    |--------------------------------------------------------------------------
    */

    private function effectiveMoment(string $key, ?Event $stored): ?CarbonImmutable
    {
        if ($this->has($key)) {
            return $this->momentInUtc($key);
        }

        $value = $stored?->getAttribute($key);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function effectiveBool(string $key, ?Event $stored): bool
    {
        return $this->has($key)
            ? $this->boolean($key)
            : (bool) ($stored?->getAttribute($key) ?? false);
    }

    /**
     * The trimmed effective string, or null when it is absent or blank — which is what `IS NOT NULL`
     * has to mean here: a location of `"   "` satisfies the constraint and tells a visitor nothing.
     */
    private function effectiveString(string $key, ?Event $stored): ?string
    {
        $value = $this->has($key) ? $this->input($key) : $stored?->getAttribute($key);

        if ($value instanceof UnitEnum) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function effectiveCapacity(?Event $stored): ?int
    {
        $value = $this->has('capacity') ? $this->input('capacity') : $stored?->getAttribute('capacity');

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
