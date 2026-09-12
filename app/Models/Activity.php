<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * spatie activity + the request context from phase-01 §1.7 (decision D13): one table serving
 * both the Activity Log (§106) and the Audit Trail (§107).
 *
 * The context columns are filled by App\Models\Concerns\LogsActivityWithContext::tapActivity()
 * — nothing else should write them.
 *
 * Bound through `config('activitylog.activity_model')`.
 *
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $causer_type
 * @property int|null $causer_id
 * @property string|null $event
 * @property string|null $batch_uuid
 * @property Collection<array-key, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device
 * @property string|null $module
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Activity extends SpatieActivity
{
    /**
     * spatie ships `$guarded = []`; an explicit whitelist is safer and covers every column the
     * logger and the audit helpers write. The package itself assigns attributes directly, so
     * narrowing mass assignment here changes nothing for it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'log_name',
        'description',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'event',
        'batch_uuid',
        'properties',
        'ip_address',
        'user_agent',
        'device',
        'module',
        'reason',
    ];

    /*
    |--------------------------------------------------------------------------
    | Audit helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Values after the change.
     *
     * @return array<string, mixed>
     */
    public function newValues(): array
    {
        return $this->propertyBag('attributes');
    }

    /**
     * Values before the change.
     *
     * @return array<string, mixed>
     */
    public function oldValues(): array
    {
        return $this->propertyBag('old');
    }

    /**
     * Attribute => ['old' => mixed, 'new' => mixed] for every field that actually moved —
     * what the audit trail screen renders.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function changedValues(): array
    {
        $new = $this->newValues();
        $old = $this->oldValues();

        $changes = [];

        foreach (array_keys($new + $old) as $attribute) {
            $before = $old[$attribute] ?? null;
            $after = $new[$attribute] ?? null;

            if ($before === $after) {
                continue;
            }

            $changes[(string) $attribute] = ['old' => $before, 'new' => $after];
        }

        return $changes;
    }

    public function hasContext(): bool
    {
        return $this->ip_address !== null || $this->device !== null || $this->module !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * The module this entry belongs to, matched on `modules.slug`.
     *
     * @return BelongsTo<Module, $this>
     */
    public function moduleModel(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module', 'slug');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Activity>  $query
     * @param  Module|string|array<int, string>  $module
     * @return Builder<Activity>
     */
    public function scopeForModule(Builder $query, Module|string|array $module): Builder
    {
        if ($module instanceof Module) {
            return $query->where('module', $module->slug);
        }

        if (is_array($module)) {
            return $query->whereIn('module', $module);
        }

        return $query->where('module', $module);
    }

    /**
     * Entries caused by a user (or by nobody, when null is passed).
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeForCauser(Builder $query, Model|int|string|null $causer): Builder
    {
        if ($causer === null) {
            return $query->whereNull('causer_id');
        }

        if ($causer instanceof Model) {
            return $query
                ->where('causer_type', $causer->getMorphClass())
                ->where('causer_id', $causer->getKey());
        }

        return $query->where('causer_id', $causer);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('description', 'like', $like)
                ->orWhere('log_name', 'like', $like)
                ->orWhere('event', 'like', $like)
                ->orWhere('module', 'like', $like)
                ->orWhere('reason', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('subject_type', 'like', $like);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * One bucket out of the `properties` json, always as an array.
     *
     * @return array<string, mixed>
     */
    private function propertyBag(string $key): array
    {
        $properties = $this->properties;

        if (! $properties instanceof Collection) {
            return [];
        }

        $bag = $properties->get($key);

        if ($bag instanceof Collection) {
            $bag = $bag->all();
        }

        return is_array($bag) ? $bag : [];
    }
}
