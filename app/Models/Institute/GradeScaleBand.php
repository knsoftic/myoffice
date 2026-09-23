<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * One rung of a grade ladder (phase-19-23 §2.10).
 *
 * **Both edges are inclusive**, and the next band starts one hundredth higher: `80.00–89.99`, then
 * `90.00–100.00`. That works precisely because a percentage is computed half-up at two decimals
 * (§6.8), so the 0.01 gap between two bands contains no value that can occur — which is also why
 * `GradeBandValidator` insists the edges themselves are at two decimals. An edge of `89.9950` would
 * satisfy the step and leave `90.00` belonging to nothing.
 *
 * **`contains()` compares with bcmath, never a float `<=`.** `0.1 + 0.2 !== 0.3` in a float, and a band
 * boundary is exactly where that bites: a student on 79.99999999 handed the grade above is the kind of
 * bug nobody finds until a parent asks.
 *
 * **A band behind a result is deactivated with its scale, never deleted** (INV-20-4) — the hook below
 * says so where `restrictOnDelete` cannot, because a soft delete is an UPDATE no foreign key sees.
 *
 * @property string $min_percentage
 * @property string $max_percentage
 */
class GradeScaleBand extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'grade_scale_bands';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade',
        'title',
        'min_percentage',
        'max_percentage',
        'grade_point',
        'is_pass',
        'color',
        'remark_template',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade_scale_id' => 'integer',
            'min_percentage' => 'decimal:4',
            'max_percentage' => 'decimal:4',
            'grade_point' => 'decimal:2',
            'is_pass' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $band): void {
            if ($band->results()->exists()) {
                throw new LogicException(sprintf(
                    'Grade %s has been awarded. Change the scale rather than removing the band — a '
                    .'result card that was handed over has to stay explainable.',
                    (string) $band->getAttribute('grade'),
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'grade_scales';
    }

    protected function activityModule(): ?string
    {
        return 'grade_scales';
    }

    /** Both edges inclusive, compared as decimals. See the class note. */
    public function contains(string $percentage): bool
    {
        return Money::compare($percentage, (string) $this->min_percentage) >= 0
            && Money::compare($percentage, (string) $this->max_percentage) <= 0;
    }

    /** "80.00 – 89.99", for the scale editor and the printed card's key. */
    public function rangeLabel(): string
    {
        return app_number($this->min_percentage, 2).' – '.app_number($this->max_percentage, 2).'%';
    }

    /** The colour is data too, so a badge never needs a `match` in a template. */
    public function badgeColor(): string
    {
        $color = trim((string) $this->getAttribute('color'));

        if ($color !== '') {
            return $color;
        }

        return $this->getAttribute('is_pass') ? 'emerald' : 'rose';
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class, 'grade_scale_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class, 'grade_scale_band_id');
    }
}
