<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Enums\ClassroomType;
use App\Enums\DeliveryMode;
use App\Enums\Weekday;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Institute\Batch;
use App\Models\Institute\Classroom;
use App\Models\Institute\TimetableEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public class timetable — `site.timetable.index`, the week ahead as a day-by-day grid.
 *
 * `website.timetable_page_enabled = false` makes the page a 404 (the registry default is **off**: an
 * institute that has not seeded a timetable would otherwise publish an empty grid, and a soft-404 that
 * answers 200 gets indexed).
 *
 * **Nothing about a person reaches this page, and that is enforced by the SELECT, not by the template.**
 * The view never receives a model: every slot is a flat array built here from six facts — the course
 * name, the batch name, the weekday, the start and end time, the delivery mode, and the room (or the
 * word "Online"). No student, no roster, no enrolment or capacity count, no teacher, no meeting URL, no
 * fee. A timetable that says who is in which class is a safeguarding failure, so the columns a template
 * could print are the columns that were asked for and nothing else.
 *
 * **`timetable_entries` is the week; `class_sessions` is not.** The weekly rule ("this batch, Mondays,
 * 09:00–11:00") is exactly the shape a public grid wants, and `class_sessions` rows carry
 * `expected_count`, `present_count`, `absent_count`, `late_count`, the teacher and the substitute — an
 * attendance register that has no business being one join away from a marketing page. So the page is
 * driven by `Batch` (which owns the status that decides publication) through its `timetableEntries`
 * relation, and the register is never queried at all.
 *
 * **Only batches that are running or about to run.** `Batch::live()` is planned / enrolling / running —
 * it excludes `on_hold` (paused, its sessions cancelled), `completed` and `cancelled`. A batch whose
 * `end_date` has passed is dropped too, in case a status was never moved on. The course must itself be
 * publicly visible — published and in a switched-on category, the same predicate the catalogue uses —
 * so the page never advertises a class whose course page is a 404.
 */
final class TimetableController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    /** How far ahead "the week ahead" reaches, counting today. */
    private const WINDOW_DAYS = 7;

    public function index(): Response
    {
        if (! $this->siteFlag('website.timetable_page_enabled', false)) {
            return $this->notFound();
        }

        $from = Carbon::today();
        $to = $from->copy()->addDays(self::WINDOW_DAYS - 1);

        $batches = $this->eagerPublic(
            Batch::query()
                ->live()
                ->select(['id', 'name', 'course_id', 'classroom_id', 'delivery_mode', 'status', 'end_date'])
                // A batch left `running` past its own end date is not this week's timetable. The group
                // matters: an ungrouped `orWhere` here would OR away the status and course filters too.
                ->where(static function ($query) use ($from): void {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $from->toDateString());
                })
                ->whereHas('course', static function ($query): void {
                    $query->published()->whereHas('category', static function ($inner): void {
                        $inner->where('is_active', true);
                    });
                })
                ->whereHas('timetableEntries', static function ($query) use ($from, $to): void {
                    $query->active()->effectiveBetween($from, $to);
                }),
            [
                'course' => static function ($query): void {
                    $query->select(['id', 'name', 'slug']);
                },
                'classroom' => static function ($query): void {
                    $query->select(['id', 'name', 'type']);
                },
                'timetableEntries' => static function ($query) use ($from, $to): void {
                    $query->active()
                        ->effectiveBetween($from, $to)
                        // The only entry columns that exist as far as this page is concerned:
                        // `teacher_id`, `meeting_url` and `notes` are deliberately absent.
                        ->select(['id', 'batch_id', 'classroom_id', 'day_of_week', 'start_time', 'end_time', 'delivery_mode'])
                        ->orderBy('start_time')
                        ->orderBy('end_time')
                        ->orderBy('id');
                },
                'timetableEntries.classroom' => static function ($query): void {
                    $query->select(['id', 'name', 'type']);
                },
            ],
        )->get();

        $days = $this->week($this->slotsByDay($batches));

        $slotCount = array_sum(array_map(
            static fn (array $day): int => count($day['slots']),
            $days,
        ));

        return $this->contentPage('site.timetable.index', [
            // list<array{day: Weekday, slots: list<array<string, mixed>>}>, in the configured week order.
            'days' => $days,
            'slotCount' => $slotCount,
            'from' => $from,
            'to' => $to,
        ], $this->routeSeo('site.timetable.index'), 'site-timetable', ['title' => 'Class timetable', 'slug' => 'timetable']);
    }

    /**
     * Flatten the loaded batches into one plain array per slot, keyed by weekday. A flat array is the
     * privacy boundary: the Blade file cannot print an attribute that was never put in it.
     *
     * @param  iterable<int, Batch>  $batches
     * @return array<string, list<array<string, mixed>>>
     */
    private function slotsByDay(iterable $batches): array
    {
        $slots = [];

        foreach ($batches as $batch) {
            $course = $batch->course;
            $courseName = trim((string) ($course?->name ?? ''));

            if ($courseName === '') {
                continue;
            }

            $courseSlug = trim((string) ($course?->slug ?? ''));

            $courseUrl = $courseSlug !== '' && Route::has('site.courses.show')
                ? route('site.courses.show', ['course' => $courseSlug])
                : null;

            foreach ($batch->timetableEntries as $entry) {
                if (! $entry instanceof TimetableEntry) {
                    continue;
                }

                $day = $entry->day_of_week;

                if (! $day instanceof Weekday) {
                    continue;
                }

                $mode = $entry->delivery_mode instanceof DeliveryMode
                    ? $entry->delivery_mode
                    : ($batch->delivery_mode instanceof DeliveryMode ? $batch->delivery_mode : DeliveryMode::Physical);

                $room = $entry->classroom ?? $batch->classroom;
                $room = $room instanceof Classroom ? $room : null;

                $slots[$day->value][] = [
                    'id' => (int) $entry->getKey(),
                    'course_name' => $courseName,
                    'course_url' => $courseUrl,
                    'batch_name' => trim((string) $batch->name),
                    'starts_at' => (string) $entry->start_time,
                    'ends_at' => (string) $entry->end_time,
                    // `mode` is the enum VALUE, not the enum: the view maps it to one of the icons
                    // `x-ui.icon` actually registers. `DeliveryMode::icon()` names `arrows-right-left`,
                    // which the component does not carry, and a public page must not print its
                    // missing-icon placeholder.
                    'mode' => $mode->value,
                    'mode_label' => $mode->label(),
                    'mode_color' => $mode->color(),
                    'where' => $this->whereItMeets($mode, $room),
                ];
            }
        }

        return $slots;
    }

    /**
     * The room, or the word "Online" — never a meeting URL. A virtual "room" is a link with a name, so
     * it reads as online however the mode was typed.
     */
    private function whereItMeets(DeliveryMode $mode, ?Classroom $classroom): ?string
    {
        if ($mode === DeliveryMode::Online) {
            return 'Online';
        }

        if ($classroom === null) {
            return null;
        }

        if ($classroom->type === ClassroomType::Virtual) {
            return 'Online';
        }

        $name = trim((string) $classroom->name);

        return $name === '' ? null : $name;
    }

    /**
     * The columns the grid draws: the configured working days in the configured week order, plus any
     * day that actually has a class on it. Data that disagrees with a setting is shown, never hidden —
     * a Sunday revision class is a fact, and dropping it would make the page quietly wrong.
     *
     * @param  array<string, list<array<string, mixed>>>  $slots
     * @return list<array{day: Weekday, slots: list<array<string, mixed>>}>
     */
    private function week(array $slots): array
    {
        $working = [];

        foreach ((array) setting('institute.timetable_working_days', []) as $value) {
            $day = is_string($value) ? Weekday::tryFrom($value) : null;

            if ($day instanceof Weekday) {
                $working[$day->value] = true;
            }
        }

        $days = [];

        foreach (Weekday::ordered() as $day) {
            $daySlots = $slots[$day->value] ?? [];

            if ($daySlots === [] && $working !== [] && ! isset($working[$day->value])) {
                continue;
            }

            usort($daySlots, static fn (array $a, array $b): int => [$a['starts_at'], $a['course_name'], $a['batch_name']]
                <=> [$b['starts_at'], $b['course_name'], $b['batch_name']]);

            $days[] = ['day' => $day, 'slots' => $daySlots];
        }

        return $days;
    }
}
