<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Models\Institute\Exam;
use App\Models\Institute\GradeScale;
use App\Models\Institute\GradeScaleBand;
use App\Models\User;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\NoGradeScale;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Maintaining the grade ladders (phase-19-23 §6.9, INV-20-3, INV-20-4).
 *
 * **`validateBands()` runs before anything is written, and nothing is written if it fails.** A scale
 * with a hole in it would grade a student into nothing at all — `ResultCalculator::bandFor()` would
 * return null and the row would carry a percentage and no grade. Validating inside the transaction is
 * what makes "a saved scale is a sound scale" true rather than hoped for.
 *
 * **`resolveFor()` throws rather than inventing a ladder.** An exam with no scale, in an institute with
 * no default, is a misconfiguration — and the honest response is to refuse to grade, not to produce a
 * grade from an assumption nobody made. A silent A/B/C fallback here would be the exact hardcoded
 * ladder [D-20-1] exists to prevent.
 *
 * **A referenced scale is deactivated, never deleted** (INV-20-4). The model refuses the delete and
 * `restrictOnDelete` refuses the hard one; this is the path that does what the user actually meant.
 */
final class GradeScaleService
{
    use WritesAuditTrail;

    private const MODULE = 'grade_scales';

    public function __construct(
        private readonly GradeBandValidator $validator,
    ) {}

    /**
     * Create a scale and its bands together — they are one thing, and a scale with no bands grades
     * nobody.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $bands
     */
    public function create(array $attributes, array $bands, ?User $actor = null): GradeScale
    {
        $actor ??= Auth::user();

        // Before the transaction opens: a refusal here costs nothing to roll back.
        $clean = $this->validator->validate($bands);

        return DB::transaction(function () use ($attributes, $clean, $bands): GradeScale {
            $scale = new GradeScale;
            $scale->forceFill([
                'code' => mb_strtoupper(trim((string) $attributes['code'])),
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'pass_percentage' => Money::round((string) ($attributes['pass_percentage'] ?? '40'), 4),
                'is_default' => false,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
            ]);
            $scale->save();

            $this->writeBands($scale, $clean, $bands);
            $this->recountBands($scale);

            $this->audit($scale, 'Grade scale created', [
                'attributes' => ['code' => $scale->getAttribute('code'), 'bands' => count($clean)],
            ], self::MODULE);

            return $scale->refresh();
        });
    }

    /**
     * Replace a scale's bands wholesale.
     *
     * **The old bands are removed and the new ones written**, which is why a band that has graded
     * somebody stops this: `GradeScaleBand`'s hook refuses the delete, so an edit that would orphan a
     * printed result card fails loudly rather than silently rewriting history (INV-20-4).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $bands  null leaves the bands alone
     */
    public function update(GradeScale $scale, array $attributes, ?array $bands = null, ?User $actor = null): GradeScale
    {
        $clean = $bands === null ? null : $this->validator->validate($bands);

        return DB::transaction(function () use ($scale, $attributes, $clean, $bands): GradeScale {
            $locked = $this->lock($scale);

            // Absent means unchanged; present-and-null means cleared. `?? null` here would wipe a
            // description on every partial save — D121, in the phase that learned it.
            $keep = static fn (string $key, mixed $current): mixed => array_key_exists($key, $attributes)
                ? $attributes[$key]
                : $current;

            $before = $locked->only(['code', 'name', 'description', 'pass_percentage', 'is_active', 'sort_order']);

            $locked->forceFill([
                'code' => mb_strtoupper(trim((string) $keep('code', $locked->getAttribute('code')))),
                'name' => $keep('name', $locked->getAttribute('name')),
                'description' => $keep('description', $locked->getAttribute('description')),
                'pass_percentage' => Money::round((string) $keep('pass_percentage', $locked->getAttribute('pass_percentage')), 4),
                'is_active' => (bool) $keep('is_active', $locked->getAttribute('is_active')),
                'sort_order' => (int) $keep('sort_order', $locked->getAttribute('sort_order')),
            ]);
            $locked->save();

            if ($clean !== null) {
                // Each removal goes through the model, so a band that has graded somebody refuses.
                foreach ($locked->bands()->get() as $existing) {
                    $existing->delete();
                }

                $this->writeBands($locked, $clean, $bands ?? []);
            }

            $this->recountBands($locked);

            $this->audit($locked, 'Grade scale updated', [
                'old' => $before,
                'attributes' => $locked->only(array_keys($before)),
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Make this the institute's default.
     *
     * The previous default is cleared **first**, inside the same transaction: `uq_gs_default` permits
     * exactly one row whose guard is 1, so setting the new one before clearing the old would collide.
     * The index is the backstop, not the mechanism — but it is what makes two defaults impossible even
     * if this method were called twice at once.
     */
    public function setDefault(GradeScale $scale, ?User $actor = null): GradeScale
    {
        return DB::transaction(function () use ($scale): GradeScale {
            $locked = $this->lock($scale);

            if (! (bool) $locked->getAttribute('is_active')) {
                throw CourseRuleException::refuse('is_default', 'An inactive scale cannot be the default.');
            }

            if ($locked->bands()->doesntExist()) {
                throw CourseRuleException::refuse('bands', 'A scale with no bands cannot grade anybody, so it cannot be the default.');
            }

            GradeScale::query()
                ->where('is_default', true)
                ->whereKeyNot($locked->getKey())
                ->get()
                ->each(static fn (GradeScale $previous) => $previous->forceFill(['is_default' => false])->saveQuietly());

            $locked->forceFill(['is_default' => true])->save();

            $this->audit($locked, 'Grade scale made the default', [
                'attributes' => ['code' => $locked->getAttribute('code')],
            ], self::MODULE);

            return $locked->refresh();
        });
    }

    /**
     * Which scale grades this exam: its own, else the institute's setting, else the `is_default` row.
     *
     * **Throws rather than inventing one.** An exam with no scale in an institute with no default is a
     * misconfiguration, and a grade produced from an assumption nobody made is worse than a refusal
     * somebody has to fix.
     */
    public function resolveFor(Exam $exam): GradeScale
    {
        $own = $exam->getAttribute('grade_scale_id');

        if ($own !== null) {
            $scale = GradeScale::query()->find($own);

            if ($scale instanceof GradeScale) {
                return $scale;
            }
        }

        $configured = settings_repo()->get('institute.default_grade_scale_id');

        if (is_numeric($configured)) {
            $scale = GradeScale::query()->find((int) $configured);

            if ($scale instanceof GradeScale) {
                return $scale;
            }
        }

        $fallback = GradeScale::query()->where('is_default', true)->first();

        if ($fallback instanceof GradeScale) {
            return $fallback;
        }

        throw NoGradeScale::forExam($exam);
    }

    /**
     * The band a percentage falls in — one indexed query, and a **bcmath** comparison rather than a
     * float `<=`, because a band boundary is exactly where float arithmetic bites.
     */
    public function bandFor(GradeScale $scale, string $percentage): ?GradeScaleBand
    {
        foreach ($scale->bands()->get() as $band) {
            if ($band->contains($percentage)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Take a scale out of use without losing it.
     *
     * Refuses while it is the default — an institute with no default scale cannot grade an exam that
     * names none, and discovering that halfway through a marking session is nobody's idea of a good
     * afternoon.
     */
    public function deactivate(GradeScale $scale, string $reason, ?User $actor = null): GradeScale
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw CourseRuleException::refuse('reason', 'Say why this scale is being taken out of use.');
        }

        return DB::transaction(function () use ($scale, $reason): GradeScale {
            $locked = $this->lock($scale);

            if ((bool) $locked->getAttribute('is_default')) {
                throw CourseRuleException::refuse(
                    'is_default',
                    'This is the institute’s default scale. Make another one the default first — an exam '
                    .'that names no scale has to have something to fall back on.',
                );
            }

            $locked->forceFill(['is_active' => false])->save();

            $this->audit($locked, 'Grade scale deactivated', [
                'old' => ['is_active' => true],
                'attributes' => ['is_active' => false, 'used_by_results' => $locked->results()->count()],
            ], self::MODULE, $reason);

            return $locked->refresh();
        });
    }

    /** The cache, by COUNT — never incremented. */
    public function recountBands(GradeScale $scale): void
    {
        $scale->forceFill(['bands_count' => $scale->bands()->count()])->saveQuietly();
    }

    // -------------------------------------------------------------------------------------------

    /**
     * @param  list<array{grade: string, min_percentage: string, max_percentage: string, is_pass: bool}>  $clean
     * @param  list<array<string, mixed>>  $raw  the caller's rows, for the fields the validator does not touch
     */
    private function writeBands(GradeScale $scale, array $clean, array $raw): void
    {
        $extras = [];

        foreach ($raw as $row) {
            $grade = trim((string) ($row['grade'] ?? ''));

            if ($grade !== '') {
                $extras[mb_strtoupper($grade)] = $row;
            }
        }

        foreach ($clean as $index => $row) {
            $extra = $extras[mb_strtoupper($row['grade'])] ?? [];

            $band = new GradeScaleBand;
            $band->forceFill([
                'grade_scale_id' => (int) $scale->getKey(),
                'grade' => $row['grade'],
                'title' => $extra['title'] ?? null,
                'min_percentage' => $row['min_percentage'],
                'max_percentage' => $row['max_percentage'],
                'grade_point' => $extra['grade_point'] ?? null,
                'is_pass' => $row['is_pass'],
                'color' => $extra['color'] ?? null,
                'remark_template' => $extra['remark_template'] ?? null,
                // Stored lowest first, which is the order the validator sorted them into.
                'sort_order' => $index,
            ]);
            $band->save();
        }
    }

    private function lock(GradeScale $scale): GradeScale
    {
        /** @var GradeScale $locked */
        $locked = GradeScale::query()->withTrashed()->whereKey($scale->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }
}
