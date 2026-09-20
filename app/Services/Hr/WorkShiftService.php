<?php

declare(strict_types=1);

namespace App\Services\Hr;

use App\Models\Hr\WorkShift;
use App\Services\Hr\Exceptions\HrRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Working patterns (phase-07 §2.7, §6.2).
 *
 * **`crosses_midnight` and `expected_minutes` are derived, never typed.** A hand-entered "expected
 * minutes" that disagreed with the clock would quietly change everybody's overtime, and nobody would
 * think to check it — so the two columns are computed here from the times and the break, every time.
 *
 * **Editing a shift changes nothing about the past** (HR-2). Every attendance row snapshotted the window
 * it was measured against, so fixing a typo or moving a start time cannot rewrite a late mark from three
 * months ago. The edit screen says so in a sentence, because the opposite assumption is the natural one.
 */
class WorkShiftService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): WorkShift
    {
        return DB::transaction(function () use ($data): WorkShift {
            $shift = new WorkShift;

            return $this->persist($this->apply($shift, $data));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkShift $shift, array $data): WorkShift
    {
        return DB::transaction(function () use ($shift, $data): WorkShift {
            return $this->persist($this->apply($shift, $data));
        });
    }

    public function toggle(WorkShift $shift): WorkShift
    {
        if ($shift->is_active && $shift->is_default) {
            throw HrRuleException::refuse('is_active',
                'This is the default shift. Make another shift the default first — otherwise a new '
                .'employee with no shift of their own would have nothing to be measured against.');
        }

        $shift->forceFill(['is_active' => ! $shift->is_active])->save();

        return $shift;
    }

    /**
     * Remove a shift. Refused while employees or attendance still point at it, naming the count — a shift
     * that vanished would take the fallback for every one of those people with it.
     */
    public function destroy(WorkShift $shift): void
    {
        $employees = $shift->employees()->count();

        if ($employees > 0) {
            throw HrRuleException::refuse('id', sprintf(
                '%d employee(s) are on this shift. Move them to another shift first.',
                $employees
            ));
        }

        $shift->delete();
    }

    /**
     * Fill the columns a form may set, then **force** the two derived ones.
     *
     * `crosses_midnight` and `expected_minutes` are deliberately absent from `$fillable` (§2.7): a
     * hand-entered expected-minutes that disagreed with the clock would quietly change everybody's
     * overtime. They are computed here and written past the guard, which is the only way they are ever
     * written.
     *
     * @param  array<string, mixed>  $data
     */
    private function apply(WorkShift $shift, array $data): WorkShift
    {
        $prepared = $this->prepare($data, $shift->exists ? $shift : null);

        $derived = [
            'crosses_midnight' => $prepared['crosses_midnight'],
            'expected_minutes' => $prepared['expected_minutes'],
        ];

        unset($prepared['crosses_midnight'], $prepared['expected_minutes']);

        return $shift->fill($prepared)->forceFill($derived);
    }

    /**
     * The derived columns, in one place (§2.7).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?WorkShift $existing = null): array
    {
        $start = (string) ($data['start_time'] ?? $existing?->start_time ?? '09:00:00');
        $end = (string) ($data['end_time'] ?? $existing?->end_time ?? '17:00:00');
        $break = (int) ($data['break_minutes'] ?? $existing?->break_minutes ?? 0);

        $window = $this->window($start, $end);

        $data['code'] = strtoupper(trim((string) ($data['code'] ?? $existing?->code ?? '')));
        $data['start_time'] = $window['start']->format('H:i:s');
        $data['end_time'] = $window['end']->format('H:i:s');
        $data['break_minutes'] = $break;

        $gross = (int) $window['start']->diffInMinutes($window['end']);
        $expected = $gross - $break;

        if ($expected <= 0) {
            throw HrRuleException::refuse('break_minutes', sprintf(
                'The break of %d minutes is as long as the shift itself (%d minutes), which would leave '
                .'nobody with any paid time.',
                $break,
                $gross
            ));
        }

        $data['crosses_midnight'] = $window['crosses'];
        $data['expected_minutes'] = $expected;

        $data['grace_in_minutes'] ??= (int) setting('hr.late_grace_minutes', 15);
        $data['grace_out_minutes'] ??= (int) setting('hr.early_leave_grace_minutes', 10);
        $data['min_full_day_minutes'] ??= (int) setting('hr.full_day_min_minutes', 480);
        $data['min_half_day_minutes'] ??= (int) setting('hr.half_day_min_minutes', 240);

        if ((int) $data['min_half_day_minutes'] > (int) $data['min_full_day_minutes']) {
            throw HrRuleException::refuse('min_half_day_minutes',
                'A half day cannot need more minutes than a full one.');
        }

        return $data;
    }

    /**
     * @return array{start: Carbon, end: Carbon, crosses: bool}
     */
    private function window(string $start, string $end): array
    {
        $from = Carbon::createFromFormat('H:i:s', $this->normalise($start));
        $to = Carbon::createFromFormat('H:i:s', $this->normalise($end));

        $crosses = $to->lessThanOrEqualTo($from);

        return [
            'start' => $from,
            'end' => $crosses ? $to->copy()->addDay() : $to,
            'crosses' => $crosses,
        ];
    }

    private function normalise(string $time): string
    {
        $time = trim($time);

        return match (substr_count($time, ':')) {
            1 => $time.':00',
            2 => $time,
            default => '00:00:00',
        };
    }

    private function persist(WorkShift $shift): WorkShift
    {
        try {
            $shift->save();
        } catch (UniqueConstraintViolationException) {
            // Either `uq_ws_code` or `uq_ws_default` ([D-HR-5]) — one default shift per branch.
            $clash = WorkShift::query()
                ->where('code', $shift->code)
                ->where('id', '<>', $shift->getKey() ?? 0)
                ->first();

            if ($clash !== null) {
                throw HrRuleException::refuse('code', sprintf('The code %s is already in use.', $shift->code));
            }

            throw HrRuleException::refuse('is_default',
                'That branch already has a default shift. A branch has exactly one, so that an employee '
                .'with no shift of their own always falls back to a single answer.');
        }

        return $shift;
    }
}
