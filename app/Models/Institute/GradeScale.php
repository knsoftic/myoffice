<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * A named ladder of percentage bands (phase-19-23 §2.9, requirement §82).
 *
 * **[D-20-1] The grade scale is data, not code.** §82 asks for a grade; a hardcoded A/B/C ladder in PHP
 * would be exactly the "hardcoded status" `CLAUDE.md` §1.8 forbids — and it would make a practical
 * course that marks out of five impossible to express without a second code path.
 *
 * **`default_guard` is generated, and it is NULL for every non-default scale.** `UNIQUE (is_default)`
 * could not say "exactly one default": it would also forbid a second *ordinary* scale, because `0`
 * collides with `0`. MariaDB tolerates unlimited NULLs in a unique index, so a guard that is `1` for
 * the default and NULL otherwise permits one default and any number of the rest.
 *
 * **A referenced scale is deactivated, never deleted** (INV-20-4). `restrictOnDelete` from `exams`,
 * `exam_results` and `certificates` holds it, and the hook below refuses the soft delete too — because
 * a soft delete is an UPDATE that no foreign key ever sees, and `Gate::before` waves a Super Admin past
 * any policy that tried to say otherwise (D124).
 *
 * @property string $pass_percentage
 */
class GradeScale extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'grade_scales';

    /**
     * `is_default` is absent: making a scale the default clears the previous one, which is a
     * transaction rather than a field. `bands_count` is a cache the service recounts.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'pass_percentage',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pass_percentage' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'bands_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // INV-20-4. A scale behind a printed result card is provenance, and provenance that can be
        // hidden by one click is not provenance.
        static::deleting(static function (self $scale): void {
            if ($scale->results()->exists() || $scale->exams()->exists()) {
                throw new LogicException(sprintf(
                    'Grade scale %s has been used to grade somebody. Deactivate it instead — a scale '
                    .'behind a result that was printed and handed over has to stay reachable.',
                    (string) $scale->getAttribute('code'),
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

    /** Bands lowest first, which is the order `GradeBandValidator` and every screen read them in. */
    public function bands(): HasMany
    {
        return $this->hasMany(GradeScaleBand::class, 'grade_scale_id')->orderBy('min_percentage');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'grade_scale_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class, 'grade_scale_id');
    }

    /**
     * Has anything been graded with it? The deactivate-don't-delete rule turns on this.
     *
     * **Certificates are not asked yet, and the class docblock above already says they hold a scale
     * alive.** That is deliberate rather than an oversight: `certificates` and its model arrive in
     * Phase 21, and a `hasMany(Certificate::class)` here would fatal on every delete until they do.
     * The `restrictOnDelete` on `certificates.grade_scale_id` is real, so **Phase 21 must add the
     * relation and extend this method in the same commit that adds the table** — otherwise a scale
     * used solely by a certificate passes this check, reaches the foreign key, and fails with a raw
     * constraint violation instead of the sentence a coordinator can act on.
     */
    public function hasBeenUsed(): bool
    {
        return $this->results()->exists() || $this->exams()->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
