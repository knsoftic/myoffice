<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CourseLevel;
use App\Enums\CourseStatus;
use App\Enums\DeliveryMode;
use App\Enums\DurationUnit;
use App\Models\Branch;
use App\Models\Cms\Faq;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A course the institute sells (`courses`, §62, phase-14-17 §2.4).
 *
 * **The three fee columns are a price list, not money.** Nothing on this row is a charge: Phase 18's
 * `StudentFeeService` reads them once, at admission, to build a fee structure — and editing a price
 * afterwards changes what the *next* student is quoted and nothing that has already been sold (INV-I1,
 * INV-I2). That is why `totalFee()` is an arithmetic helper and never a stored column: a cached total
 * would be one more figure that could disagree with an admission nobody may change.
 *
 * **`slug` is the public contract.** Once `published_at` is stamped, `/courses/{slug}` may be in a
 * WhatsApp forward or somebody's bookmarks, so `CourseService` refuses to move it without a reason.
 *
 * **The four counts and `outline_minutes` are caches** written only by `CourseOutlineService` and
 * `CourseService::recountOutline()`. Nothing decides anything from them — the completeness check that
 * gates publishing counts real modules — so a stale cache is a cosmetic bug, never a wrong answer.
 *
 * **`branch_id` null means every branch** (§2.2, D11): the catalogue is one catalogue, and a
 * branch-specific course is the exception that carries a value.
 *
 * @property CourseStatus $status
 * @property CourseLevel $level
 * @property DeliveryMode $delivery_mode
 * @property DurationUnit $duration_unit
 */
class Course extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'courses';

    /**
     * Deliberately excludes `status`, `published_at` and the five cache columns: a status moves only
     * through `CourseService`'s transition table (§2.30.1), and a cache only through a recount. Mass
     * assignment is where both rules would otherwise be bypassed by a crafted request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'branch_id', 'course_category_id', 'code', 'name', 'slug',
        'short_description', 'full_description',
        'image_path', 'thumbnail_path', 'promo_video_url',
        'duration_value', 'duration_unit', 'total_classes', 'class_duration_minutes',
        'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
        'installment_available', 'max_installments', 'installment_note',
        'level', 'delivery_mode', 'default_teacher_id',
        'requirements', 'outcomes',
        'certificate_available', 'is_featured', 'admission_open', 'sort_order',
        'seo_title', 'seo_description', 'seo_keywords', 'og_image_path', 'canonical_url',
        'is_indexable', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'course_category_id' => 'integer',
            'default_teacher_id' => 'integer',
            'status' => CourseStatus::class,
            'level' => CourseLevel::class,
            'delivery_mode' => DeliveryMode::class,
            'duration_unit' => DurationUnit::class,
            'course_fee' => 'decimal:2',
            'admission_fee' => 'decimal:2',
            'registration_fee' => 'decimal:2',
            'monthly_fee' => 'decimal:2',
            'installment_available' => 'boolean',
            'max_installments' => 'integer',
            'certificate_available' => 'boolean',
            'is_featured' => 'boolean',
            'admission_open' => 'boolean',
            'is_indexable' => 'boolean',
            'requirements' => 'array',
            'outcomes' => 'array',
            'published_at' => 'immutable_datetime',
            'duration_value' => 'integer',
            'total_classes' => 'integer',
            'class_duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'modules_count' => 'integer',
            'topics_count' => 'integer',
            'lectures_count' => 'integer',
            'outline_minutes' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'courses';
    }

    protected function activityModule(): ?string
    {
        return 'courses';
    }

    /**
     * The money columns are logged because a price change is a business decision somebody will ask
     * about, even though it changes no existing admission.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'code', 'name', 'slug', 'course_category_id', 'branch_id', 'status',
            'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
            'installment_available', 'max_installments',
            'level', 'delivery_mode', 'default_teacher_id',
            'certificate_available', 'is_featured', 'admission_open', 'is_indexable',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    /**
     * The three components added up, through bcmath.
     *
     * Not a column: a stored total is a fourth number that can disagree with the three it came from,
     * and the one place the disagreement would surface is a student's first invoice.
     */
    public function totalFee(): string
    {
        return Money::sum([
            (string) $this->course_fee,
            (string) $this->admission_fee,
            (string) $this->registration_fee,
        ]);
    }

    /**
     * "12 weeks", "40 hours" — the institute's own wording, with the unit agreeing with the number.
     */
    public function durationLabel(): ?string
    {
        if ($this->duration_value === null) {
            return null;
        }

        return $this->duration_value.' '.$this->duration_unit->labelFor($this->duration_value);
    }

    /**
     * Is the outline good enough to publish? §2.30.1's completeness guard, asked of the real rows.
     *
     * Returns the **missing field names**, so the screen and the service can say which one rather than
     * "something is missing".
     *
     * @return list<string>
     */
    public function publishingGaps(): array
    {
        $gaps = [];

        if (trim((string) $this->name) === '') {
            $gaps[] = 'name';
        }

        if (trim((string) $this->slug) === '') {
            $gaps[] = 'slug';
        }

        if ($this->course_category_id === null) {
            $gaps[] = 'category';
        }

        // A published course with no fee would render "Contact us" where a price belongs and take an
        // admission the institute cannot charge against.
        if (Money::compare((string) $this->course_fee, Money::ZERO) <= 0) {
            $gaps[] = 'course fee';
        }

        // Counted live, never from `modules_count`: the cache is the thing most likely to be stale on
        // a course somebody is about to publish for the first time.
        if ($this->modules()->count() === 0) {
            $gaps[] = 'at least one module';
        }

        return $gaps;
    }

    /**
     * Does this course still hold anything that stops it being deleted?
     *
     * The FKs refuse it anyway; this is what lets the screen explain before the database has to.
     */
    public function hasSalesHistory(): bool
    {
        foreach (['batches', 'student_admissions', 'student_applications', 'student_batch_enrollments', 'student_fees'] as $table) {
            if (! app('db')->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            if (app('db')->table($table)->where('course_id', $this->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CourseStatus::Published->value);
    }

    /**
     * The branch rule of §9: a course with no branch belongs to every branch, and a user with no
     * branch sees the lot.
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        if ($branchId === null) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('branch_id')
            ->orWhere('branch_id', $branchId));
    }

    /**
     * The public catalogue's order, used by the catalogue, the category page and the sitemap.
     */
    public function scopeCatalogueOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('name');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner
            ->where('name', 'like', '%'.$term.'%')
            ->orWhere('code', 'like', '%'.$term.'%')
            ->orWhere('short_description', 'like', '%'.$term.'%'));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'course_category_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Reached through the modules, but selected in one query: "every topic of this course" is asked by
     * every progress recount, and two round trips for a tree of forty rows is forty too many.
     */
    public function topics(): HasMany
    {
        return $this->hasMany(CourseTopic::class);
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(CourseLecture::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CourseTopicResource::class);
    }

    public function assignmentBlueprints(): HasMany
    {
        return $this->hasMany(CourseTopicAssignment::class);
    }

    /**
     * The lectures a visitor may watch before applying (§90).
     */
    public function previewLectures(): HasMany
    {
        return $this->lectures()->where('is_preview', true)->where('is_active', true);
    }

    /**
     * Phase 3's `faqs` rows — this phase creates no `course_faqs` table (§2.10, F-2.2).
     */
    public function faqs(): MorphMany
    {
        return $this->morphMany(Faq::class, 'faqable');
    }

    /**
     * Topics through modules, for the one place a two-level walk is genuinely wanted.
     */
    public function topicsThroughModules(): HasManyThrough
    {
        return $this->hasManyThrough(CourseTopic::class, CourseModule::class);
    }
}
