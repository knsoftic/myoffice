<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Models\Cms\Event;
use Illuminate\Validation\Validator;

/**
 * Update an event — `admin.events.update`, `can:events.edit`.
 *
 * Everything but the three answers below is inherited from {@see StoreEventRequest}: the rules, the
 * input shaping, the four mirrored CHECK constraints and `eventPayload()`. It extends rather than
 * repeats because a copied rule set drifts — see that class's note on why these two share by
 * inheritance instead of the usual `Concerns\Validates*` trait.
 *
 * `partial: true` turns every required field into `sometimes|required`, so a form that posts a subset
 * changes only what it posted; the constraint mirror then reads the untouched values off the bound
 * model, which is exactly what MariaDB will do to the finished row.
 *
 * `status`, `published_at` and `is_featured` stay `prohibited`: this route is `can:events.edit`, and
 * `events.edit` does not publish. Those go to `admin.events.status` and `admin.events.featured`.
 */
final class UpdateEventRequest extends StoreEventRequest
{
    protected function permission(): string
    {
        return 'events.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->eventRules(partial: true);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->mirrorDatabaseChecks($validator)];
    }

    /**
     * The bound event: the slug's uniqueness rule ignores it, and the CHECK mirror falls back to its
     * stored values for every column this request did not post.
     */
    public function event(): ?Event
    {
        return $this->boundModel('event', Event::class);
    }
}
