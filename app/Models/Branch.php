<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical location / campus (phase-01 §1.5, decision D11).
 *
 * Branch-ready from day one: one default branch exists from the first seed and institute
 * tables carry a nullable `branch_id` as they are created. No branch-switching UI yet.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $city
 * @property string|null $address
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class Branch extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'city',
        'address',
        'is_default',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The branch new records belong to when nothing else is chosen: the one flagged
     * `is_default`, else the first active one, else any branch. Null only on an
     * unseeded database.
     */
    public static function default(): ?self
    {
        return static::query()
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * "HQ — Head Office", for selects and headers.
     */
    public function displayName(): string
    {
        $code = trim((string) $this->code);
        $name = trim((string) $this->name);

        return $code === '' ? $name : $code.' — '.$name;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        return $query->where(function (Builder $builder) use ($like): void {
            $builder->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like);
        });
    }
}
