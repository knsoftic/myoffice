<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\LoginStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LogFilterRequest;
use App\Models\LoginHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Login history viewer (route `admin.login-history.index`, phase-01 §8).
 *
 * `login_histories` is written only by the auth listeners and is append-only, so this controller
 * reads: a filtered table, a summary strip over the same filter set, and a streamed CSV.
 *
 * Permissions: `login_history.view_logs` to read, `login_history.export` for the CSV — exactly
 * the strings routes/admin.php enforces and the sidebar advertises. Checked here as well as in
 * the route middleware.
 */
final class LoginHistoryController extends Controller
{
    /** Columns a user may sort by — never raw input. */
    private const SORTABLE = ['created_at', 'logged_in_at', 'logged_out_at', 'status', 'ip_address', 'email'];

    public function index(LogFilterRequest $request): View
    {
        $this->authorizeRead($request->user());

        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');

        $base = $this->filtered($request);

        $entries = (clone $base)
            ->with('user')
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return view('admin.login-history.index', [
            'entries' => $entries,
            'summary' => $this->summary($base),
            'durations' => $this->durations($entries->items()),
            'sort' => $sort,
            'direction' => $direction,
            'timezone' => $request->viewerTimezone(),
            'statusOptions' => LoginStatus::options(),
            'userOptions' => $this->userOptions(),
            'hasFilters' => $request->hasActiveFilters(),
            'rangeLabel' => $this->rangeLabel($request),
            'canExport' => $this->canExport($request->user()),
            'exportUrl' => $this->exportUrl($request),
        ]);
    }

    /**
     * CSV of the current filter selection, yielded row by row so a year of sign-ins does not
     * have to fit in memory.
     */
    public function export(LogFilterRequest $request): StreamedResponse
    {
        abort_unless($this->canExport($request->user()), 403);

        $query = $this->filtered($request)->with('user');
        $timezone = $request->viewerTimezone();

        $filename = 'login-history-'.CarbonImmutable::now($timezone)->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $timezone): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // BOM so Excel reads UTF-8 names correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($this->exportRows($query, $timezone) as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<LoginHistory>
     */
    private function filtered(LogFilterRequest $request): Builder
    {
        $query = LoginHistory::query();

        if (($userId = $request->userId()) !== null) {
            $query->where('user_id', $userId);
        }

        if (($status = $request->statusFilter()) !== null) {
            $query->where('status', $status->value);
        }

        if (($ip = $request->filterString('ip')) !== null) {
            $query->where('ip_address', 'like', '%'.$this->escapeLike($ip).'%');
        }

        if (($from = $request->fromDate()) !== null) {
            $query->where('created_at', '>=', $from);
        }

        if (($to = $request->toDate()) !== null) {
            $query->where('created_at', '<=', $to);
        }

        if (($term = $request->searchTerm()) !== null) {
            $query->search($term);
        }

        return $query;
    }

    /**
     * Successes, failures and distinct IPs across the whole filtered range — not just the page.
     *
     * @param  Builder<LoginHistory>  $base
     * @return array{total: int, statuses: array<string, int>, unique_ips: int}
     */
    private function summary(Builder $base): array
    {
        $statuses = [];

        foreach (LoginStatus::cases() as $case) {
            $statuses[$case->value] = 0;
        }

        $total = 0;
        $uniqueIps = 0;

        try {
            $rows = (clone $base)
                ->selectRaw('status as status_value, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status_value');

            foreach ($rows as $status => $aggregate) {
                $statuses[(string) $status] = (int) $aggregate;
                $total += (int) $aggregate;
            }

            $uniqueIps = (clone $base)
                ->whereNotNull('ip_address')
                ->distinct()
                ->count('ip_address');
        } catch (Throwable) {
            // Leave the strip at zero rather than failing the page.
        }

        return [
            'total' => $total,
            'statuses' => $statuses,
            'unique_ips' => (int) $uniqueIps,
        ];
    }

    /**
     * One CSV row at a time, in id-ordered chunks.
     *
     * @param  Builder<LoginHistory>  $query
     * @return Generator<int, array<int, string>>
     */
    private function exportRows(Builder $query, string $timezone): Generator
    {
        yield [
            'ID', 'Recorded at', 'Status', 'User', 'User email', 'Attempted email',
            'IP address', 'Device', 'Platform', 'Browser',
            'Logged in at', 'Logged out at', 'Duration', 'Session',
        ];

        foreach ($query->lazyById(500) as $entry) {
            /** @var LoginHistory $entry */
            yield [
                (string) $entry->getKey(),
                $entry->created_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                $entry->status->label(),
                (string) ($entry->user?->name ?? ''),
                (string) ($entry->user?->email ?? ''),
                (string) ($entry->email ?? ''),
                (string) ($entry->ip_address ?? ''),
                (string) ($entry->device ?? ''),
                (string) ($entry->platform ?? ''),
                (string) ($entry->browser ?? ''),
                $entry->logged_in_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                $entry->logged_out_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                $this->durationLabel($entry) ?? '',
                (string) ($entry->session_id ?? ''),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation helpers
    |--------------------------------------------------------------------------
    */

    /**
     * row id => human duration, so the view stays free of arithmetic.
     *
     * @param  array<int, LoginHistory>  $rows
     * @return array<int, string|null>
     */
    private function durations(array $rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row->getKey()] = $this->durationLabel($row);
        }

        return $map;
    }

    /**
     * "4h 12m" between sign-in and sign-out; null while the session is still open or when the
     * row never represented a session at all (a failed attempt).
     */
    private function durationLabel(LoginHistory $row): ?string
    {
        $start = $row->logged_in_at ?? ($row->status === LoginStatus::Success ? $row->created_at : null);
        $end = $row->logged_out_at;

        if ($start === null || $end === null) {
            return null;
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();

        if ($seconds < 0) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.'m '.($seconds % 60).'s';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours.'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
        }

        return intdiv($hours, 24).'d '.($hours % 24).'h';
    }

    /**
     * "1–14 Sep 2026", "since 1 Sep 2026", "up to 14 Sep 2026" or "all time" — the label the
     * summary strip prints so the numbers are never ambiguous.
     */
    private function rangeLabel(LogFilterRequest $request): string
    {
        $timezone = $request->viewerTimezone();
        $from = $request->fromDate()?->setTimezone($timezone);
        $to = $request->toDate()?->setTimezone($timezone);

        if ($from !== null && $to !== null) {
            return $from->format('d M Y').' – '.$to->format('d M Y');
        }

        if ($from !== null) {
            return 'since '.$from->format('d M Y');
        }

        if ($to !== null) {
            return 'up to '.$to->format('d M Y');
        }

        return 'all time';
    }

    /**
     * Users who appear in the history, so the filter can never return an empty page.
     *
     * @return array<string, string>
     */
    private function userOptions(): array
    {
        try {
            $userIds = LoginHistory::query()
                ->select('user_id')
                ->whereNotNull('user_id')
                ->distinct();

            /** @var array<string, string> $options */
            $options = User::query()
                ->withTrashed()
                ->whereIn('id', $userIds)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(static fn (string $name, int|string $id): array => [(string) $id => $name])
                ->all();

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization helpers
    |--------------------------------------------------------------------------
    */

    private function authorizeRead(?Authenticatable $user): void
    {
        abort_unless($this->canAny($user, ['login_history.view_logs']), 403);
    }

    private function canExport(?Authenticatable $user): bool
    {
        return $this->canAny($user, ['login_history.export']);
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function canAny(?Authenticatable $user, array $abilities): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        try {
            return $user->canAny($abilities);
        } catch (Throwable) {
            return false;
        }
    }

    private function exportUrl(LogFilterRequest $request): ?string
    {
        if (! $this->canExport($request->user()) || ! Route::has('admin.login-history.export')) {
            return null;
        }

        $query = collect($request->query())
            ->except(['page', 'per_page'])
            ->filter(static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->all();

        try {
            return route('admin.login-history.export', $query);
        } catch (Throwable) {
            return null;
        }
    }
}
