<?php

declare(strict_types=1);

namespace App\Console\Commands\Institute;

use App\Enums\CourseStatus;
use App\Models\Institute\Course;
use App\Services\Institute\CourseService;
use App\Services\Institute\Exceptions\CourseRuleException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publish draft courses in bulk — the catalogue-import companion to seeding one.
 *
 * The admin screens publish one course at a time, which is right for editorial work and wrong for
 * the morning after an import of thirty-four. Nothing here relaxes a rule the screens enforce: it
 * calls the same service, course by course, and reports each refusal with the reason the service
 * gave.
 *
 * **`--without-outline` is the whole point of the flag being a flag.** `Course::publishingGaps()`
 * demands at least one outline module, and for a long diploma that is exactly right. For a
 * one-month tool course it is stricter than the page it protects: the listing never mentions a
 * module, and the detail page wraps the syllabus in `@if ($payload->hasOutline())` so a course
 * without one renders cleanly with that block absent. The waiver is therefore available, one gap
 * wide, and **never the default** — somebody has to decide that these particular courses are
 * better on the site bare than off it entirely, and typing the flag is that decision.
 *
 * `--dry-run` prints the same table and writes nothing.
 */
final class PublishCourses extends Command
{
    protected $signature = 'courses:publish
                            {--without-outline : Publish courses whose only gap is a missing outline}
                            {--dry-run : Show what would happen and change nothing}';

    protected $description = 'Publish every draft course that passes the completeness gate.';

    public function handle(CourseService $courses): int
    {
        $waive = (bool) $this->option('without-outline');
        $dry = (bool) $this->option('dry-run');

        $drafts = Course::query()
            // `category` is eager-loaded because publishing recounts it, and this application runs
            // with `Model::preventLazyLoading` — so the relation that a screen would have fetched
            // without anyone noticing throws here instead. It did: the first real run refused all
            // thirty-four with a lazy-loading violation, which the `--dry-run` could not have shown
            // because a dry run never reaches the write path.
            ->with('category')
            ->where('status', CourseStatus::Draft->value)
            ->orderBy('course_category_id')
            ->orderBy('sort_order')
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('No draft courses. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '%d draft course(s). Outline requirement: %s.%s',
            $drafts->count(),
            $waive ? 'WAIVED' : 'enforced',
            $dry ? ' (dry run — nothing will be written)' : '',
        ));
        $this->newLine();

        $published = 0;
        $refused = [];

        foreach ($drafts as $course) {
            $gaps = $course->publishingGaps();

            if ($waive) {
                $gaps = array_values(array_diff($gaps, [Course::GAP_OUTLINE]));
            }

            if ($gaps !== []) {
                $refused[] = [$course->code, $course->name, implode(', ', $gaps)];

                continue;
            }

            if ($dry) {
                $published++;

                continue;
            }

            try {
                $waive
                    ? $courses->publishWithoutOutline($course)
                    : $courses->publish($course);

                $published++;
            } catch (CourseRuleException $e) {
                // The service is the floor; this loop only pre-filters. A refusal here means the two
                // disagreed, which is worth seeing rather than swallowing.
                $refused[] = [$course->code, $course->name, $e->getMessage()];
            } catch (Throwable $e) {
                $refused[] = [$course->code, $course->name, $e->getMessage()];
            }
        }

        if ($refused !== []) {
            $this->warn(sprintf('%d course(s) refused:', count($refused)));
            $this->table(['Code', 'Course', 'Still needs'], $refused);
            $this->newLine();
        }

        $verb = $dry ? 'would be published' : 'published';
        $this->info(sprintf('%d course(s) %s, %d refused.', $published, $verb, count($refused)));

        if ($published > 0 && ! $dry) {
            $this->newLine();
            $this->warn('Clear the public page cache or the old HTML keeps serving (T59):');
            $this->line('    php artisan cache:clear');

            if ($waive) {
                $this->newLine();
                $this->warn(
                    'These pages have no syllabus yet. A visitor sees the name, duration, fee and '
                    .'category, and nothing about what is taught — add outlines when you can.'
                );
            }
        }

        return $refused === [] ? self::SUCCESS : self::FAILURE;
    }
}
