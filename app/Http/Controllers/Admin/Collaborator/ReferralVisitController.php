<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Collaborator;

use App\Enums\ReferralVisitOutcome;
use App\Http\Controllers\Controller;
use App\Models\Collaborator\Collaborator;
use App\Models\Collaborator\CollaboratorReferralVisit;
use App\Support\CsvWriter;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The click register and the conversion report — `admin.referral-visits.*` (phase-08-09 §7.3, §8.6).
 *
 * **Read-only, with no row actions at all**, because nobody edits a click. A register that could be
 * corrected would stop being evidence, and evidence is the entire reason these rows exist.
 *
 * **The IP is masked to `/24` unless the viewer holds `collaborator_referral_visits.view_logs`.** A
 * marketing manager reading a funnel does not need to know which household a visitor came from; whoever
 * is investigating abuse does, and that is a separate decision with its own permission.
 */
final class ReferralVisitController extends Controller
{
    public function index(Request $request): View
    {
        $seesRawIp = (bool) $request->user()?->can('collaborator_referral_visits.view_logs');

        return view('admin.referral-visits.index', [
            'visits' => $this->filtered($request)
                ->with('collaborator:id,collaborator_code,name,company_name')
                ->latest('last_seen_at')
                ->paginate(30)
                ->withQueryString(),
            'outcomes' => $this->outcomeOptions(),
            // 500, not every row: **the collaborator network is a table that grows while the business
            // works, so an unbounded filter `<select>` is a page that grows with it** (phase-24-25
            // section 6.4, PRF-05). Same ceiling as the finance pickers.
            'collaborators' => Collaborator::query()->orderBy('name')->limit(500)->get(['id', 'name', 'company_name', 'collaborator_code']),
            'seesRawIp' => $seesRawIp,
            'range' => $this->range($request),
        ]);
    }

    public function show(Request $request, CollaboratorReferralVisit $visit): View
    {
        return view('admin.referral-visits.show', [
            'visit' => $visit->load('collaborator:id,collaborator_code,name,company_name', 'user:id,name'),
            'seesRawIp' => (bool) $request->user()?->can('collaborator_referral_visits.view_logs'),
        ]);
    }

    /**
     * Clicks, unique visitors, attributable clicks, conversions and the outcome breakdown.
     *
     * **It reads this table and nothing else** (INV-C7): there is no join to a ledger anywhere here,
     * because what a partner earned comes from the wallet and statement services and a second
     * calculation of it is a second number that can disagree.
     */
    public function report(Request $request): View
    {
        $range = $this->range($request);
        $base = $this->filtered($request);

        $rows = (clone $base)
            ->selectRaw('collaborator_id, referral_code, COUNT(*) as visits, SUM(visits_count) as clicks')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 1 ELSE 0 END) as attributable', [ReferralVisitOutcome::Captured->value])
            ->selectRaw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as conversions')
            ->groupBy('collaborator_id', 'referral_code')
            ->orderByDesc('clicks')
            ->get();

        $byOutcome = (clone $base)
            ->selectRaw('outcome, COUNT(*) as visits')
            ->groupBy('outcome')
            ->pluck('visits', 'outcome')
            ->all();

        // Codes that receive clicks but resolve to nobody — how a partner who printed an old flyer, or
        // a typo on a banner, actually gets found.
        $deadCodes = (clone $base)
            ->whereNull('collaborator_id')
            ->selectRaw('referral_code, COUNT(*) as visits, SUM(visits_count) as clicks, MAX(last_seen_at) as last_seen')
            ->groupBy('referral_code')
            ->orderByDesc('clicks')
            ->limit(25)
            ->get();

        return view('admin.referral-visits.report', [
            'rows' => $rows,
            'collaborators' => Collaborator::query()
                ->whereIn('id', $rows->pluck('collaborator_id')->filter()->all())
                ->get(['id', 'name', 'company_name', 'collaborator_code'])
                ->keyBy('id'),
            'byOutcome' => $byOutcome,
            'deadCodes' => $deadCodes,
            'range' => $range,
            'outcomes' => $this->outcomeOptions(),
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        if ($format !== 'csv') {
            return back()->with('toast', ['type' => 'error', 'message' => 'Only CSV export is available.']);
        }

        $seesRawIp = (bool) $request->user()?->can('collaborator_referral_visits.view_logs');

        $rows = $this->filtered($request)
            ->with('collaborator:id,collaborator_code,name')
            ->latest('last_seen_at')
            ->limit(5000)
            ->get();

        return (new CsvWriter)->download(
            'referral-visits-'.app_date(now(), 'Y-m-d').'.csv',
            ['First seen', 'Last seen', 'Code', 'Collaborator', 'Landing path', 'Clicks', 'Outcome', 'Converted', 'Device', 'IP'],
            $rows->map(fn (CollaboratorReferralVisit $visit): array => [
                app_datetime($visit->first_seen_at),
                app_datetime($visit->last_seen_at),
                $visit->referral_code,
                $visit->collaborator?->name,
                $visit->landing_path,
                $visit->visits_count,
                $visit->outcome->label(),
                $visit->converted_at !== null ? app_datetime($visit->converted_at) : null,
                $visit->device,
                // Masked here too: an export is the easiest thing in the system to forward to somebody
                // who never held the permission.
                $this->ip($visit, $seesRawIp),
            ])->all(),
        );
    }

    /**
     * Mask an IPv4 address to its /24, and an IPv6 to its /48 prefix.
     *
     * Enough to see "these forty clicks came from one office" without naming the household.
     */
    public function ip(CollaboratorReferralVisit $visit, bool $raw): ?string
    {
        $ip = $visit->ip_address;

        if ($ip === null || $raw) {
            return $ip;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 3)).'::/48';
        }

        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return null;
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0/24';
    }

    /**
     * @return Builder<CollaboratorReferralVisit>
     */
    private function filtered(Request $request): Builder
    {
        $range = $this->range($request);

        return CollaboratorReferralVisit::query()
            ->whereBetween('last_seen_at', [$range->start(), $range->end()])
            ->when($request->filled('collaborator_id'), static fn (Builder $query): Builder => $query
                ->where('collaborator_id', $request->integer('collaborator_id')))
            ->when($request->filled('code'), static fn (Builder $query): Builder => $query
                ->where('referral_code', 'like', '%'.$request->string('code')->toString().'%'))
            ->when($request->filled('outcome'), static fn (Builder $query): Builder => $query
                ->where('outcome', $request->string('outcome')->toString()))
            ->when($request->filled('landing_path'), static fn (Builder $query): Builder => $query
                ->where('landing_path', 'like', '%'.$request->string('landing_path')->toString().'%'))
            ->when($request->filled('ip'), static fn (Builder $query): Builder => $query
                ->where('ip_address', 'like', $request->string('ip')->toString().'%'))
            ->when($request->filled('converted'), static fn (Builder $query): Builder => $request->string('converted')->toString() === 'yes'
                ? $query->whereNotNull('converted_at')
                : $query->whereNull('converted_at'))
            ->when($request->filled('bot'), static fn (Builder $query): Builder => $query
                ->where('is_bot', $request->string('bot')->toString() === 'yes'));
    }

    private function range(Request $request): DateRange
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return DateRange::custom($from, $to);
        }

        return DateRange::lastDays(30);
    }

    /**
     * @return array<string, string>
     */
    private function outcomeOptions(): array
    {
        $options = [];

        foreach (ReferralVisitOutcome::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
