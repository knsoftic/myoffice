<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Hr;

use App\Enums\HolidayType;
use App\Http\Controllers\Controller;
use App\Models\Hr\Holiday;
use App\Services\Hr\HolidayService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Holidays — `admin.holidays.*` (phase-07 §7.2, §8.8), `module:holidays`.
 *
 * A user enters a **range**; the service writes one row per date ([D-HR-6]). Declaring a holiday after
 * attendance was already marked re-resolves those days and leaves a correction row per change, so the
 * screen says how many days moved rather than pretending nothing happened.
 */
final class HolidayController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly HolidayService $holidays) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Holiday::class);

        $year = (int) $request->query('year', (string) now()->year);

        return view('admin.hr.holidays.index', [
            // **A year of the holiday calendar, with the year written into the query as a ceiling.** The
            // `whereYear` alone is not a bound PRF-05 accepts (phase-24-25 section 6.4) and it is right
            // not to: the table itself accumulates a batch of rows every January for ever, so a screen
            // that read it whole would slow by a little each year. 400 is a calendar year's 366 dates
            // plus per-branch duplicates of the same day.
            'holidays' => Holiday::query()
                ->with('branch:id,name')
                ->whereYear('holiday_date', $year)
                ->orderBy('holiday_date')
                ->limit(400)
                ->get(),
            'year' => $year,
            'years' => range(now()->year - 2, now()->year + 2),
            'types' => HolidayType::options(),
        ]);
    }

    public function calendar(Request $request): View
    {
        $this->authorize('viewAny', Holiday::class);

        $year = (int) $request->query('year', (string) now()->year);

        return view('admin.hr.holidays.calendar', [
            // The same one-year ceiling as the register above, for the same reason: the year filter bounds
            // the window, 400 bounds the table (phase-24-25 section 6.4, PRF-05).
            'holidays' => Holiday::query()
                ->whereYear('holiday_date', $year)
                ->orderBy('holiday_date')
                ->limit(400)
                ->get()
                ->groupBy(fn (Holiday $holiday): int => (int) $holiday->holiday_date->month),
            'year' => $year,
            'years' => range(now()->year - 2, now()->year + 2),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Holiday::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'holiday_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:holiday_date'],
            'holiday_type' => ['required', Rule::enum(HolidayType::class)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'is_paid' => ['nullable', 'boolean'],
            'is_recurring_yearly' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $created = $this->holidays->createRange(
            [
                'title' => $data['title'],
                'holiday_type' => $data['holiday_type'],
                'branch_id' => $data['branch_id'] ?? null,
                'is_paid' => (bool) ($data['is_paid'] ?? true),
                'is_recurring_yearly' => (bool) ($data['is_recurring_yearly'] ?? false),
                'description' => $data['description'] ?? null,
                'is_active' => true,
            ],
            Carbon::parse($data['holiday_date']),
            isset($data['to_date']) ? Carbon::parse($data['to_date']) : null,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('%d day(s) added to the calendar.', $created->count()),
        ]);
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorize('update', $holiday);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'holiday_type' => ['required', Rule::enum(HolidayType::class)],
            'is_paid' => ['nullable', 'boolean'],
            'is_recurring_yearly' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->holidays->update($holiday, $data);

        return back()->with('toast', ['type' => 'success', 'message' => 'Holiday saved.']);
    }

    public function copyYear(Request $request): RedirectResponse
    {
        $this->authorize('create', Holiday::class);

        $data = $request->validate([
            'from_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'to_year' => ['required', 'integer', 'min:2000', 'max:2100', 'different:from_year'],
        ]);

        $copied = $this->holidays->copyYear((int) $data['from_year'], (int) $data['to_year']);

        return back()->with('toast', [
            'type' => $copied > 0 ? 'success' : 'info',
            'message' => $copied > 0
                ? sprintf('%d recurring holiday(s) copied into %d.', $copied, (int) $data['to_year'])
                : 'Nothing to copy — every recurring holiday already exists in that year.',
        ]);
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorize('delete', $holiday);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->holidays->destroy($holiday, $data['reason']);

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Holiday removed. The days it covered were re-resolved from their punches.',
        ]);
    }
}
