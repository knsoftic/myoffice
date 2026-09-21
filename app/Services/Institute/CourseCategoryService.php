<?php

declare(strict_types=1);

namespace App\Services\Institute;

use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use App\Models\User;
use App\Services\Cms\CacheVersion;
use App\Services\Institute\Exceptions\CourseRuleException;
use App\Support\SlugGenerator;
use Illuminate\Database\DatabaseManager;

/**
 * The catalogue's top level (§64, phase-14-17 §6.1).
 *
 * **Reordering is the interesting part.** `sort_order` carries no unique index, which is what makes any
 * permutation writable in any order: with a unique column, dragging row 3 above row 2 needs a temporary
 * value and a three-statement dance that can half-fail and leave the list in an order nobody chose.
 * Here the whole new order is written in one transaction, and an id that does not belong to the set is
 * rejected before a single row moves — so a crafted payload cannot reshuffle somebody else's rows.
 *
 * **Switching a category off never touches a course** unless the caller explicitly asks. Two facts,
 * two switches: "do not show this grouping" and "stop selling these courses" are different decisions,
 * and quietly doing the second because somebody asked for the first is how a published catalogue
 * disappears overnight. With the cascade, each course that moves is logged on its own row, because
 * "seven courses went to draft" is not an audit trail — seven audit rows are.
 */
final class CourseCategoryService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CacheVersion $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): CourseCategory
    {
        return $this->db->transaction(function () use ($data, $actor): CourseCategory {
            $category = new CourseCategory;

            $category->fill($this->columns($data));
            $category->slug = $this->slugFor($data, (string) $data['name']);
            // Appended, never inserted into the middle: a new category arriving above the ones an
            // admin arranged would undo their ordering without asking.
            $category->sort_order = (int) CourseCategory::query()->max('sort_order') + 1;
            $category->created_by = $actor?->getKey();
            $category->save();

            return $category->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CourseCategory $category, array $data, ?User $actor = null): CourseCategory
    {
        return $this->db->transaction(function () use ($category, $data, $actor): CourseCategory {
            $newSlug = $this->slugFor($data, (string) ($data['name'] ?? $category->name), $category);

            // A slug change on a category with published courses moves a public URL that may already be
            // shared, so it takes a reason — which the activity row then carries.
            if ($newSlug !== $category->slug && $this->hasPublishedCourses($category)) {
                $reason = trim((string) ($data['slug_change_reason'] ?? ''));

                if ($reason === '') {
                    throw CourseRuleException::reasonRequired('slug_change_reason', sprintf(
                        'Changing this category\'s address breaks every link already shared to '
                        .'/courses/category/%s. Say why, and the change is recorded with your name on it.',
                        $category->slug,
                    ));
                }

                $category->withReason($reason);
            }

            $category->fill($this->columns($data));
            $category->slug = $newSlug;
            $category->updated_by = $actor?->getKey();
            $category->save();

            $this->cache->bumpAfterCommit(sprintf('Course category #%d edited', $category->getKey()));

            return $category->refresh();
        }, 3);
    }

    /**
     * Write a whole new order in one transaction.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(array $orderedIds, ?User $actor = null): void
    {
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($orderedIds === []) {
            return;
        }

        $known = CourseCategory::query()->whereKey($orderedIds)->pluck('id')->all();
        $unknown = array_diff($orderedIds, $known);

        // Checked before anything moves: a payload carrying an id from another table — or a deleted
        // one — must reorder nothing at all rather than reorder what it could.
        if ($unknown !== []) {
            throw CourseRuleException::refuse('order', sprintf(
                '%d of the ids sent do not belong to the category list, so nothing was reordered.',
                count($unknown),
            ));
        }

        $this->db->transaction(function () use ($orderedIds, $actor): void {
            foreach ($orderedIds as $position => $id) {
                CourseCategory::query()->whereKey($id)->update([
                    // Spaced by ten so a later single insert can land between two rows without
                    // rewriting the whole list.
                    'sort_order' => ($position + 1) * 10,
                    'updated_by' => $actor?->getKey(),
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    /**
     * Switch a category on or off — and, only when asked, take its published courses to draft.
     */
    public function setActive(
        CourseCategory $category,
        bool $active,
        bool $cascadeCourses = false,
        ?User $actor = null,
    ): void {
        $this->db->transaction(function () use ($category, $active, $cascadeCourses, $actor): void {
            $category->forceFill([
                'is_active' => $active,
                'updated_by' => $actor?->getKey(),
            ])->save();

            if ($active || ! $cascadeCourses) {
                return;
            }

            $courses = $category->courses()->where('status', CourseStatus::Published->value)->get();

            foreach ($courses as $course) {
                // One row per course, saved individually: a single "cascaded 7 courses" line would
                // record that something happened without recording what.
                $course->withReason(sprintf('Category "%s" was switched off with cascade.', $category->name));
                $course->forceFill([
                    'status' => CourseStatus::Draft->value,
                    'updated_by' => $actor?->getKey(),
                ])->save();
            }
        }, 3);

        // Whichever way it went: a hidden category takes its courses' pages with it, and a visible one
        // brings them back. Either way the cached copies are now wrong.
        $this->cache->bumpAfterCommit(sprintf(
            'Course category #%d %s', $category->getKey(), $active ? 'shown' : 'hidden',
        ));
    }

    /**
     * Refused while any course points at it — the FK would refuse anyway, and this says why first.
     */
    public function delete(CourseCategory $category): void
    {
        $count = $category->courses()->withTrashed()->count();

        if ($count > 0) {
            throw CourseRuleException::refuse('category', sprintf(
                '%s still holds %d %s. Move them to another category first — deleting it would leave '
                .'them pointing at nothing.',
                $category->name,
                $count,
                $count === 1 ? 'course' : 'courses',
            ));
        }

        $category->delete();
    }

    /**
     * Rewrite `courses_count` from the table. The cache is only worth having because this exists.
     */
    public function recount(?CourseCategory $category = null): void
    {
        $query = CourseCategory::query()->when(
            $category !== null,
            fn ($q) => $q->whereKey($category->getKey()),
        );

        $query->chunkById(200, function ($categories): void {
            foreach ($categories as $one) {
                // Archived courses are excluded: the number beside a category is "what is on offer",
                // and counting retired courses would make a category look busier than the catalogue is.
                $count = Course::query()
                    ->where('course_category_id', $one->getKey())
                    ->whereNot('status', CourseStatus::Archived->value)
                    ->count();

                if ((int) $one->courses_count !== $count) {
                    CourseCategory::query()->whereKey($one->getKey())->update(['courses_count' => $count]);
                }
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'image_path' => $data['image_path'] ?? null,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ], static fn ($value, string $key): bool => array_key_exists($key, $data), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slugFor(array $data, string $name, ?CourseCategory $existing = null): string
    {
        $wanted = trim((string) ($data['slug'] ?? ''));

        if ($wanted !== '' && $existing !== null && $wanted === $existing->slug) {
            return $existing->slug;
        }

        return SlugGenerator::make(
            $wanted !== '' ? $wanted : $name,
            'course_categories',
            $existing?->getKey(),
            'slug',
            170,
        );
    }

    private function hasPublishedCourses(CourseCategory $category): bool
    {
        return $category->courses()->where('status', CourseStatus::Published->value)->exists();
    }
}
