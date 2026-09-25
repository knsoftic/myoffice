<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\Institute\CourseTopicAssignment;
use App\Models\Institute\CourseTopicResource;
use App\Models\User;
use App\Services\Cms\CacheVersion;
use App\Services\Cms\FaqService;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Services\Institute\Exceptions\InvalidStatusTransition;
use App\Support\Money;
use App\Support\SlugGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * A course, from draft to catalogue and back (§62, phase-14-17 §6.2).
 *
 * **The status ladder is a table, not a pile of `if`s** (§2.30.1). Every move a course may make is
 * declared once in {@see TRANSITIONS}, so "can an archived course go straight to published" has one
 * answer in one place and a screen, a job and a test all read the same one.
 *
 * **Publishing is refused until the course is actually sellable.** Name, slug, category, a fee and at
 * least one module — checked against the real rows, never the cached counts, because the cache is the
 * thing most likely to be stale on a course somebody is publishing for the first time. The refusal
 * names the missing field, because "cannot publish" without a reason is a dead end.
 *
 * **A published slug does not move without a reason.** `/courses/{slug}` may be in a WhatsApp forward,
 * a printed flyer or somebody's bookmarks; the reason is recorded on the activity row so the broken
 * links have an explanation attached to them.
 *
 * **Changing a fee changes nothing already sold.** The three columns are a price list. Phase 18 reads
 * them once, at admission, and an admission's agreed figures are frozen from its first charge (INV-I2)
 * — so an edit here is logged with old and new values and touches no student.
 */
final class CourseService
{
    /**
     * §2.30.1, verbatim: `from` => the statuses it may move to.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'draft' => ['published', 'archived'],
        'published' => ['draft', 'archived'],
        // A revived course comes back as a draft, never straight to the catalogue: whatever made it
        // worth retiring deserves a look before it is on the site again.
        'archived' => ['draft'],
    ];

    /**
     * The moves that cannot happen without somebody saying why.
     *
     * @var list<string>
     */
    private const REASON_REQUIRED = ['archived'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CourseOutlineService $outline,
        private readonly CourseCategoryService $categories,
        private readonly FaqService $faqs,
        private readonly CacheVersion $cache,
        private readonly StudentNumberService $numbers,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Writing one
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Course
    {
        return $this->db->transaction(function () use ($data, $actor): Course {
            $course = new Course;

            $course->fill($this->columns($data));
            $course->code = $this->codeFor($data);
            $course->slug = $this->slugFor($data, (string) $data['name']);
            // Always a draft. There is no "create published" path, because publishing runs a
            // completeness check that a brand-new row cannot pass.
            $course->status = CourseStatus::Draft;
            $course->created_by = $actor?->getKey();
            $course->save();

            $this->categories->recount($course->category);

            return $course->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Course $course, array $data, ?User $actor = null): Course
    {
        return $this->db->transaction(function () use ($course, $data, $actor): Course {
            $previousCategory = $course->category;
            $newSlug = $this->slugFor($data, (string) ($data['name'] ?? $course->name), $course);

            if ($newSlug !== $course->slug && $course->published_at !== null) {
                $reason = trim((string) ($data['slug_change_reason'] ?? ''));

                if ($reason === '') {
                    throw CourseRuleException::reasonRequired('slug_change_reason', sprintf(
                        'This course has been published, so /courses/%s may already be in somebody\'s '
                        .'bookmarks or a printed flyer. Changing the address breaks those links — say '
                        .'why, and the reason is recorded with the change.',
                        $course->slug,
                    ));
                }

                $course->withReason($reason);
            }

            $course->fill($this->columns($data));
            $course->slug = $newSlug;

            if (array_key_exists('code', $data)) {
                $course->code = $this->codeFor($data, $course);
            }

            $course->updated_by = $actor?->getKey();
            $course->save();

            $course->refresh();

            // A course that moved category changes two counts, and neither is worth getting wrong on
            // a screen that is about to show both.
            $this->categories->recount($course->category);

            if ($previousCategory !== null && $previousCategory->getKey() !== $course->course_category_id) {
                $this->categories->recount($previousCategory);
            }

            if ($course->status->isPublic()) {
                $this->cache->bumpAfterCommit(sprintf('Course #%d edited', $course->getKey()));
            }

            return $course;
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | The status ladder (§2.30.1)
    |--------------------------------------------------------------------------
    */

    public function publish(Course $course, ?User $actor = null): Course
    {
        $gaps = $course->publishingGaps();

        if ($gaps !== []) {
            throw CourseRuleException::refuse('status', sprintf(
                'This course is not ready for the site yet — it still needs: %s. A published course '
                .'with a gap in it is a page a visitor bounces off.',
                implode(', ', $gaps),
            ));
        }

        return $this->transition($course, CourseStatus::Published, null, $actor);
    }

    public function unpublish(Course $course, ?User $actor = null): Course
    {
        return $this->transition($course, CourseStatus::Draft, null, $actor);
    }

    /**
     * Retire a course. Refused while a batch is still selling or running it.
     */
    public function archive(Course $course, string $reason, ?User $actor = null): Course
    {
        $this->assertNoLiveBatches($course);

        return $this->transition($course, CourseStatus::Archived, $reason, $actor);
    }

    /**
     * Bring an archived course back — as a draft, and with a reason.
     */
    public function revive(Course $course, string $reason, ?User $actor = null): Course
    {
        if (trim($reason) === '') {
            throw CourseRuleException::reasonRequired('reason',
                'Reviving a retired course puts it back in front of staff. Say why.');
        }

        return $this->transition($course, CourseStatus::Draft, $reason, $actor);
    }

    public function setFeatured(Course $course, bool $featured, ?User $actor = null): Course
    {
        $course->forceFill(['is_featured' => $featured, 'updated_by' => $actor?->getKey()])->save();

        return $course->refresh();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds, ?User $actor = null): void
    {
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($orderedIds === []) {
            return;
        }

        $known = Course::query()->whereKey($orderedIds)->pluck('id')->all();

        if (array_diff($orderedIds, $known) !== []) {
            throw CourseRuleException::refuse('order',
                'Some of the ids sent are not courses, so nothing was reordered.');
        }

        $this->db->transaction(function () use ($orderedIds, $actor): void {
            foreach ($orderedIds as $position => $id) {
                Course::query()->whereKey($id)->update([
                    'sort_order' => ($position + 1) * 10,
                    'updated_by' => $actor?->getKey(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Copying one
    |--------------------------------------------------------------------------
    */

    /**
     * Deep-copy the course and its whole tree — and nothing that was ever sold.
     *
     * Modules, topics, lectures, resources, assignment blueprints and the course's FAQ rows come
     * across; batches, admissions, enrollments and fees do not, and cannot: the copy is a new course
     * with a new code and a new slug, in draft.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function duplicate(Course $course, array $overrides = [], ?User $actor = null): Course
    {
        return $this->db->transaction(function () use ($course, $overrides, $actor): Course {
            $name = (string) ($overrides['name'] ?? $course->name.' (copy)');

            $copy = new Course;

            $copy->forceFill(array_merge(
                // Every authored column, and none of the lifecycle or cache ones.
                $course->only([
                    'branch_id', 'course_category_id', 'short_description', 'full_description',
                    'image_path', 'thumbnail_path', 'promo_video_url',
                    'duration_value', 'duration_unit', 'total_classes', 'class_duration_minutes',
                    'course_fee', 'admission_fee', 'registration_fee', 'monthly_fee',
                    'installment_available', 'max_installments', 'installment_note',
                    'level', 'delivery_mode', 'default_teacher_id',
                    'requirements', 'outcomes', 'certificate_available',
                    'seo_title', 'seo_description', 'seo_keywords', 'og_image_path',
                    'is_indexable', 'notes',
                ]),
                [
                    'name' => $name,
                    'code' => $this->codeFor(['code' => $overrides['code'] ?? $course->code.'-COPY']),
                    'slug' => SlugGenerator::make((string) ($overrides['slug'] ?? $name), 'courses', null, 'slug', 200),
                    // A copy is never featured and never open: both are decisions about *this* course,
                    // and inheriting them would put an unfinished draft in the featured rail.
                    'is_featured' => false,
                    'admission_open' => true,
                    'status' => CourseStatus::Draft->value,
                    'published_at' => null,
                    // A canonical URL points at the original, and copying it would tell search engines
                    // the new course is the old one.
                    'canonical_url' => null,
                    'sort_order' => 0,
                    'created_by' => $actor?->getKey(),
                ],
            ))->save();

            $this->copyTree($course, $copy, $actor);
            $this->copyFaqs($course, $copy);

            $this->recountOutline($copy);
            $this->categories->recount($copy->category);

            return $copy->refresh();
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Caches and questions
    |--------------------------------------------------------------------------
    */

    /**
     * Rewrite the four outline caches from the tree. Idempotent, and the only writer besides
     * `CourseOutlineService`.
     */
    public function recountOutline(Course $course): void
    {
        $modules = CourseModule::query()->where('course_id', $course->getKey())->count();
        $topics = CourseTopic::query()->where('course_id', $course->getKey())->count();
        $lectures = CourseLecture::query()->where('course_id', $course->getKey())->count();

        $minutes = (int) CourseLecture::query()
            ->where('course_id', $course->getKey())
            ->sum('duration_minutes');

        Course::query()->whereKey($course->getKey())->update([
            'modules_count' => $modules,
            'topics_count' => $topics,
            'lectures_count' => $lectures,
            'outline_minutes' => $minutes,
            'updated_at' => now(),
        ]);
    }

    /**
     * Can somebody apply to this course right now?
     *
     * The single definition, used by the public page, the admission form and every "Apply" button.
     * Three facts, and all three have to be true: the institute is taking admissions, this course is,
     * and the course is actually on the site.
     */
    public function effectiveAdmissionOpen(Course $course): bool
    {
        return (bool) setting('institute.admission_open', true)
            && $course->admission_open
            && $course->status->isPublic();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function transition(Course $course, CourseStatus $to, ?string $reason, ?User $actor): Course
    {
        $from = $course->status;

        if ($from === $to) {
            return $course;
        }

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw InvalidStatusTransition::between('status', $from->label(), $to->label(), $course->name);
        }

        if (in_array($to->value, self::REASON_REQUIRED, true) && trim((string) $reason) === '') {
            throw CourseRuleException::reasonRequired('reason', sprintf(
                'Retiring %s takes it off the site and out of the catalogue. Say why — somebody will '
                .'ask what happened to it.',
                $course->name,
            ));
        }

        return $this->db->transaction(function () use ($course, $to, $reason, $actor): Course {
            if (filled($reason)) {
                $course->withReason((string) $reason);
            }

            $course->forceFill(array_filter([
                'status' => $to->value,
                // Stamped once, on the first publish: it is "when did this course first go live", and
                // re-stamping it on every republish would make that unanswerable.
                'published_at' => $to === CourseStatus::Published && $course->published_at === null
                    ? Carbon::now()
                    : $course->published_at,
                'updated_by' => $actor?->getKey(),
            ], static fn ($value): bool => $value !== null))->save();

            $this->categories->recount($course->category);

            // The public cache is versioned, not keyed per page (D22): one bump makes every cached
            // course page unreachable at once. Without it, a course taken off the site keeps being
            // served from cache until its TTL runs out — which is the one failure a visitor would see
            // and nobody internal would.
            $this->cache->bumpAfterCommit(sprintf(
                'Course #%d moved to %s', $course->getKey(), $to->value,
            ));

            return $course->refresh();
        }, 3);
    }

    private function assertNoLiveBatches(Course $course): void
    {
        if (! $this->db->getSchemaBuilder()->hasTable('batches')) {
            return;
        }

        $live = $this->db->table('batches')
            ->where('course_id', $course->getKey())
            ->whereIn('status', ['enrolling', 'running'])
            ->count();

        if ($live > 0) {
            throw CourseRuleException::refuse('status', sprintf(
                '%s still has %d %s enrolling or running. Finish or cancel %s before retiring the '
                .'course — the students in them are mid-course.',
                $course->name,
                $live,
                $live === 1 ? 'batch' : 'batches',
                $live === 1 ? 'it' : 'them',
            ));
        }
    }

    private function copyTree(Course $source, Course $copy, ?User $actor): void
    {
        $modules = CourseModule::query()
            ->where('course_id', $source->getKey())
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($modules as $module) {
            $newModule = new CourseModule;
            $newModule->forceFill(array_merge(
                $module->only(['title', 'description', 'sort_order', 'duration_minutes', 'is_active']),
                ['course_id' => $copy->getKey(), 'created_by' => $actor?->getKey()],
            ))->save();

            $topics = CourseTopic::query()
                ->where('course_module_id', $module->getKey())
                ->orderBy('sort_order')->orderBy('id')
                ->get();

            foreach ($topics as $topic) {
                $newTopic = new CourseTopic;
                $newTopic->forceFill(array_merge(
                    $topic->only(['title', 'description', 'sort_order', 'weight', 'estimated_minutes', 'is_active']),
                    [
                        'course_module_id' => $newModule->getKey(),
                        'course_id' => $copy->getKey(),
                        'created_by' => $actor?->getKey(),
                    ],
                ))->save();

                $this->copyTopicChildren($topic, $newTopic, $copy, $actor);
            }
        }
    }

    private function copyTopicChildren(CourseTopic $topic, CourseTopic $newTopic, Course $copy, ?User $actor): void
    {
        foreach (CourseLecture::query()->where('course_topic_id', $topic->getKey())->orderBy('sort_order')->get() as $lecture) {
            (new CourseLecture)->forceFill(array_merge(
                $lecture->only(['title', 'description', 'lecture_type', 'sort_order', 'duration_minutes',
                    'video_url', 'is_preview', 'is_active']),
                [
                    'course_topic_id' => $newTopic->getKey(),
                    'course_id' => $copy->getKey(),
                    'created_by' => $actor?->getKey(),
                ],
            ))->save();
        }

        foreach (CourseTopicResource::query()->where('course_topic_id', $topic->getKey())->orderBy('sort_order')->get() as $resource) {
            // The stored file is shared rather than copied: two rows pointing at one immutable upload
            // is correct, and duplicating a 40 MB video per course copy is not.
            (new CourseTopicResource)->forceFill(array_merge(
                $resource->only(['title', 'type', 'file_path', 'external_url', 'file_size', 'mime_type',
                    'is_public', 'is_downloadable', 'sort_order']),
                [
                    'course_topic_id' => $newTopic->getKey(),
                    'course_id' => $copy->getKey(),
                    'created_by' => $actor?->getKey(),
                ],
            ))->save();
        }

        foreach (CourseTopicAssignment::query()->where('course_topic_id', $topic->getKey())->orderBy('sort_order')->get() as $blueprint) {
            (new CourseTopicAssignment)->forceFill(array_merge(
                $blueprint->only(['title', 'description', 'instructions', 'estimated_marks',
                    'estimated_hours', 'attachment_path', 'sort_order', 'is_active']),
                [
                    'course_topic_id' => $newTopic->getKey(),
                    'course_id' => $copy->getKey(),
                    'created_by' => $actor?->getKey(),
                ],
            ))->save();
        }
    }

    /**
     * The course's FAQs are Phase 3's rows (§2.10, F-2.2), so they are copied through its service —
     * not by inserting into a table this phase does not own.
     */
    private function copyFaqs(Course $source, Course $copy): void
    {
        foreach ($source->faqs()->orderBy('sort_order')->get() as $faq) {
            $this->faqs->save([
                'question' => $faq->question,
                'answer' => $faq->answer,
                'faq_category_id' => $faq->faq_category_id,
                'is_featured' => (bool) $faq->is_featured,
                'status' => $faq->status instanceof \BackedEnum ? $faq->status->value : $faq->status,
                'faqable_type' => Course::class,
                'faqable_id' => $copy->getKey(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $columns = [];

        foreach ([
            'branch_id', 'course_category_id', 'name',
            'short_description', 'full_description',
            'image_path', 'thumbnail_path', 'promo_video_url',
            'duration_value', 'duration_unit', 'total_classes', 'class_duration_minutes',
            'installment_note', 'level', 'delivery_mode', 'default_teacher_id',
            'certificate_available', 'is_featured', 'admission_open', 'sort_order',
            'seo_title', 'seo_description', 'seo_keywords', 'og_image_path', 'canonical_url',
            'is_indexable', 'notes',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = $data[$key];
            }
        }

        // Money through `Money`, never a float: §110, and the CHECK would refuse a negative anyway.
        foreach (['course_fee', 'admission_fee', 'registration_fee'] as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = Money::of((string) $data[$key]);
            }
        }

        if (array_key_exists('monthly_fee', $data)) {
            $columns['monthly_fee'] = $data['monthly_fee'] === null || $data['monthly_fee'] === ''
                ? null
                : Money::of((string) $data['monthly_fee']);
        }

        // The two installment columns move together or the CHECK refuses the row.
        if (array_key_exists('installment_available', $data)) {
            $available = (bool) $data['installment_available'];
            $columns['installment_available'] = $available;
            $columns['max_installments'] = $available
                ? max(1, min(36, (int) ($data['max_installments'] ?? 1)))
                : 0;
        }

        foreach (['requirements', 'outcomes'] as $key) {
            if (array_key_exists($key, $data)) {
                $columns[$key] = $this->normaliseList($data[$key]);
            }
        }

        return $columns;
    }

    /**
     * A flat array of trimmed, non-empty strings — whatever the form posted.
     *
     * @return list<string>
     */
    private function normaliseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\r\n|\r|\n/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($line): string => trim((string) $line), $value),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function codeFor(array $data, ?Course $existing = null): string
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        /*
        | **Blank means "generate one", and only on create.**
        |
        | A course still needs a code — it is what staff call it, what goes on the certificate and
        | what somebody types into a search box — but making a person invent one before they can
        | save is a question with no good answer at the moment it is asked. So an empty field mints
        | `institute.course_code_prefix` + the next number, and a typed field is kept exactly as
        | entered: `WEB-101` reads better than `CRS-0007` and nothing here should argue with that.
        |
        | **On update, blank still refuses.** An existing course already has a code that timetables,
        | certificates and fee slips refer to; silently minting a new one there would renumber a
        | course behind the back of everything pointing at it. `$existing === null` is the whole
        | difference, and it is the difference between a convenience and a data-loss bug.
        |
        | The uniqueness check below runs either way. A generated code is unique by construction —
        | the counter only goes forwards — but "by construction" is an argument, and a unique index
        | sits under this column because arguments are not guarantees.
        */
        if ($code === '') {
            if ($existing !== null) {
                throw CourseRuleException::refuse('code', 'A course that already has a code cannot have it emptied — timetables, certificates and fee slips refer to it.');
            }

            $code = strtoupper($this->numbers->nextCourseCode());
        }

        $clash = Course::query()
            ->withTrashed()
            ->where('code', $code)
            ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing->getKey()))
            ->exists();

        if ($clash) {
            throw CourseRuleException::refuse('code', sprintf(
                'The code %s already belongs to another course. Two courses sharing a code makes every '
                .'reference to it a guess.',
                $code,
            ));
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slugFor(array $data, string $name, ?Course $existing = null): string
    {
        $wanted = trim((string) ($data['slug'] ?? ''));

        if ($wanted !== '' && $existing !== null && $wanted === $existing->slug) {
            return $existing->slug;
        }

        return SlugGenerator::make(
            $wanted !== '' ? $wanted : $name,
            'courses',
            $existing?->getKey(),
            'slug',
            200,
        );
    }
}
