<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DurationUnit;
use App\Models\Institute\Course;
use App\Models\Institute\CourseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One installation's course catalogue: 8 categories and 34 courses, with their real durations and
 * fees.
 *
 * **Not in `DatabaseSeeder`, and deliberately so.** This is one institute's price list, not part of
 * the product. A fresh install of MyOffice elsewhere must not arrive carrying somebody else's
 * courses. Run it once, by name:
 *
 *     php artisan db:seed --force --class=CourseCatalogueSeeder
 *
 * **Insert-only, matched on slug.** Re-running it adds what is missing and touches nothing that
 * exists — no fee is overwritten, no name is corrected, no status is reset. The moment this runs,
 * the rows belong to whoever edits them in the admin, and a seeder that "restores" its own values
 * would quietly undo their work the next time somebody ran it (**D65**).
 *
 * **Every course is created as a DRAFT, and that is not an oversight.** `Course::publishingGaps()`
 * requires a name, a slug, a category, a fee above zero **and at least one outline module** before
 * a course may go live — the product's own guard, and its reasoning is that "a published course
 * with a gap in it is a page a visitor bounces off". The first four are in this file because they
 * were given. The fifth is curriculum: what a course actually teaches, week by week. Inventing that
 * would put made-up syllabi in front of real prospective students on a live site, so the outlines
 * are left for somebody who knows what is taught, and publishing stays an explicit human act.
 *
 * **Codes are derived from the category, not generated.** `WEB-01` says something on a certificate
 * that `CRS-0007` does not, and the mapping is mechanical rather than invented. Any of them can be
 * edited afterwards; `institute.course_code_prefix` still covers courses added later through the
 * form.
 *
 * Fees are written as strings and never arithmetic — `course_fee` is `decimal(15,2)` and money
 * never touches a float (golden rule 4). Nothing here adds two amounts together.
 */
final class CourseCatalogueSeeder extends Seeder
{
    /**
     * category name => [code prefix, [course name, months, total fee]]
     *
     * @var array<string, array{0: string, 1: list<array{0: string, 1: int, 2: string}>}>
     */
    private const CATALOGUE = [
        'Web Development' => ['WEB', [
            ['HTML & CSS', 1, '8000.00'],
            ['JavaScript', 1, '10000.00'],
            ['PHP & MySQL', 2, '15000.00'],
            ['WordPress', 2, '15000.00'],
            ['Full Stack Web Development', 6, '50000.00'],
        ]],
        'Graphic Designing' => ['GFX', [
            ['Adobe Photoshop', 1, '8000.00'],
            ['Adobe Illustrator', 1, '8000.00'],
            ['Adobe InDesign', 1, '8000.00'],
            ['Complete Graphic Designing', 3, '22000.00'],
            ['UI/UX Designing', 2, '18000.00'],
        ]],
        'Digital Marketing' => ['DMK', [
            ['Digital Marketing', 3, '25000.00'],
            ['SEO', 2, '15000.00'],
            ['Social Media Marketing', 2, '15000.00'],
            ['Facebook & Instagram Ads', 1, '10000.00'],
            ['Google Ads', 1, '12000.00'],
        ]],
        'E-commerce' => ['ECM', [
            ['Shopify', 2, '18000.00'],
            ['Amazon VA', 3, '25000.00'],
            ['Dropshipping', 2, '15000.00'],
            ['E-commerce Management', 3, '25000.00'],
        ]],
        'Video Editing' => ['VID', [
            ['Adobe Premiere Pro', 2, '15000.00'],
            ['Adobe After Effects', 2, '18000.00'],
            ['CapCut Video Editing', 1, '8000.00'],
            ['Complete Video Editing', 3, '25000.00'],
        ]],
        'App & Game Development' => ['APP', [
            ['Android Development', 4, '35000.00'],
            ['Flutter Development', 4, '40000.00'],
            ['React Native', 4, '40000.00'],
            ['Unity Game Development', 4, '40000.00'],
        ]],
        'Freelancing' => ['FRL', [
            ['Freelancing Basics', 1, '8000.00'],
            ['Fiverr & Upwork', 2, '12000.00'],
            ['Portfolio Building', 1, '8000.00'],
        ]],
        'Basic Computer & IT' => ['BIT', [
            ['Computer Basics', 1, '5000.00'],
            ['MS Office', 2, '10000.00'],
            ['Advanced Excel', 1, '8000.00'],
            ['Computerized Accounting', 2, '15000.00'],
        ]],
    ];

    public function run(): void
    {
        $categoriesCreated = 0;
        $coursesCreated = 0;
        $coursesSkipped = 0;
        $sort = 0;

        DB::transaction(function () use (&$categoriesCreated, &$coursesCreated, &$coursesSkipped, &$sort): void {
            foreach (self::CATALOGUE as $categoryName => [$prefix, $courses]) {
                $sort += 10;
                $categorySlug = Str::slug($categoryName);

                $category = CourseCategory::query()->where('slug', $categorySlug)->first();

                if ($category === null) {
                    $category = CourseCategory::query()->create([
                        'name' => $categoryName,
                        'slug' => $categorySlug,
                        'is_active' => true,
                        'sort_order' => $sort,
                    ]);

                    $categoriesCreated++;
                }

                foreach ($courses as $index => [$name, $months, $fee]) {
                    $slug = Str::slug($name);

                    // withTrashed: the slug and code are unique across soft-deleted rows too, so a
                    // course somebody archived must not be re-created under a colliding slug.
                    if (Course::withTrashed()->where('slug', $slug)->exists()) {
                        $coursesSkipped++;

                        continue;
                    }

                    Course::query()->create([
                        'course_category_id' => $category->getKey(),
                        'code' => sprintf('%s-%02d', $prefix, $index + 1),
                        'name' => $name,
                        'slug' => $slug,
                        'course_fee' => $fee,
                        'duration_value' => $months,
                        'duration_unit' => DurationUnit::Months,
                        // `status` is deliberately absent: it is not `$fillable` on Course - a mass
                        // assignment would drop it silently - and the column already defaults to
                        // `draft`, which is exactly what this seeder wants. Saying it here would
                        // have looked like it was doing something.
                        'sort_order' => ($index + 1) * 10,
                    ]);

                    $coursesCreated++;
                }
            }
        }, 3);

        $this->command?->info(sprintf(
            'Course catalogue: %d categor%s created, %d course(s) created, %d already present (left untouched).',
            $categoriesCreated,
            $categoriesCreated === 1 ? 'y' : 'ies',
            $coursesCreated,
            $coursesSkipped,
        ));

        if ($coursesCreated > 0) {
            $this->command?->warn(
                'Every course is a DRAFT. Each one needs at least one outline module before it can be '
                .'published — Institute → Courses → open a course → Outline.'
            );
        }
    }
}
