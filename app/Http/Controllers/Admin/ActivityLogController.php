<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LogFilterRequest;
use App\Models\Activity;
use App\Models\User;
use App\Support\Format;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Activity log / audit trail viewer (routes `admin.activity-log.index|show`, phase-01 §8).
 *
 * Read-only by design: `activity_log` is append-only, so there is nothing to store, update or
 * delete here. The screens are a filtered list, a single entry with its old/new value diff, and
 * a streamed CSV export.
 *
 * Permissions (phase-01 §4 — `activity_log` declares READ + LOGS + export):
 *   list / show  → `activity_log.view_logs`
 *   CSV          → `activity_log.export`
 * Exactly the strings routes/admin.php enforces and the sidebar advertises: one rule, stated in
 * three places, so a role can never see the menu item and then be refused (or the other way
 * round). The checks below mean a mis-wired route can never leak the log to someone who may not
 * read it.
 */
final class ActivityLogController extends Controller
{
    /** Columns a user may sort by — never raw input. */
    private const SORTABLE = ['created_at', 'description', 'event', 'module', 'log_name'];

    /** Anything matching these never renders a value, even if it reached the log. */
    private const REDACTED = ['password', 'token', 'secret', 'api_key', 'apikey', 'remember_token', 'private_key'];

    private const REDACTED_PLACEHOLDER = '••••••••';

    public function index(LogFilterRequest $request): View
    {
        $this->authorizeRead($request->user());

        $sort = $request->sortColumn(self::SORTABLE, 'created_at');
        $direction = $request->sortDirection('desc');

        $entries = $this->filtered($request)
            ->with('causer')
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            // An explicit ?per_page= choice wins; otherwise the `appearance.table_page_size` setting.
            ->paginate($request->perPage(per_page()))
            ->withQueryString();

        return view('admin.activity-log.index', [
            'entries' => $entries,
            'sort' => $sort,
            'direction' => $direction,
            'timezone' => $request->viewerTimezone(),
            'moduleNames' => Modules::names(),
            'userOptions' => $this->causerOptions(),
            'moduleOptions' => $this->moduleOptions(),
            'eventOptions' => $this->eventOptions(),
            'subjectOptions' => $this->subjectTypeOptions(),
            'hasFilters' => $request->hasActiveFilters(),
            'canExport' => $this->canExport($request->user()),
            'exportUrl' => $this->exportUrl($request),
        ]);
    }

    /**
     * The parameter is typed loosely on purpose: the routes file is owned by another agent, so
     * `{activity}`, `{activity_log}` or `{id}` must all work. Laravel passes the raw segment
     * when the argument is not a model, and the lookup happens here.
     */
    public function show(Request $request, int|string $activity): View
    {
        $this->authorizeRead($request->user());

        /** @var Activity $entry */
        $entry = Activity::query()
            ->with('causer')
            ->findOrFail($activity);

        return view('admin.activity-log.show', [
            'entry' => $entry,
            'diff' => $this->diffRows($entry),
            'extraProperties' => $this->extraProperties($entry),
            'subject' => $this->subjectSummary($entry),
            'moduleName' => $this->moduleLabel($entry->module),
            'timezone' => $this->timezoneFor($request->user()),
            'indexUrl' => Route::has('admin.activity-log.index') ? route('admin.activity-log.index') : null,
        ]);
    }

    /**
     * CSV of the current filter selection, streamed row by row from a generator so the response
     * never holds more than one chunk of the table in memory.
     */
    public function export(LogFilterRequest $request): StreamedResponse
    {
        abort_unless($this->canExport($request->user()), 403);

        $query = $this->filtered($request)->with('causer');
        $timezone = $request->viewerTimezone();
        $moduleNames = Modules::names();

        $filename = 'activity-log-'.CarbonImmutable::now($timezone)->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $timezone, $moduleNames): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // BOM so Excel opens UTF-8 names and descriptions correctly.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($this->exportRows($query, $timezone, $moduleNames) as $row) {
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
     * The filter set shared by the table and the export.
     *
     * @return Builder<Activity>
     */
    private function filtered(LogFilterRequest $request): Builder
    {
        $query = Activity::query();

        if (($userId = $request->userId()) !== null) {
            $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $userId);
        }

        if (($module = $request->filterString('module')) !== null) {
            $query->where('module', $module);
        }

        if (($event = $request->filterString('event')) !== null) {
            $query->where('event', $event);
        }

        if (($subjectType = $request->filterString('subject_type')) !== null) {
            $query->where('subject_type', $subjectType);
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
     * One CSV row at a time: the header, then the entries pulled in id-ordered chunks.
     *
     * @param  Builder<Activity>  $query
     * @param  array<string, string>  $moduleNames
     * @return Generator<int, array<int, string>>
     */
    private function exportRows(Builder $query, string $timezone, array $moduleNames): Generator
    {
        yield [
            'ID', 'Logged at', 'Log', 'Event', 'Description', 'Module',
            'User', 'User email', 'Causer type', 'Subject type', 'Subject ID',
            'IP address', 'Device', 'Reason', 'Old values', 'New values',
        ];

        foreach ($query->lazyById(500) as $entry) {
            /** @var Activity $entry */
            $causer = $entry->causer;

            yield [
                (string) $entry->getKey(),
                $entry->created_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                (string) ($entry->log_name ?? ''),
                (string) ($entry->event ?? ''),
                (string) $entry->description,
                $entry->module === null ? '' : ($moduleNames[$entry->module] ?? $entry->module),
                $causer instanceof User ? (string) $causer->name : '',
                $causer instanceof User ? (string) $causer->email : '',
                $entry->causer_type === null ? 'System' : class_basename((string) $entry->causer_type),
                $entry->subject_type === null ? '' : class_basename((string) $entry->subject_type),
                $entry->subject_id === null ? '' : (string) $entry->subject_id,
                (string) ($entry->ip_address ?? ''),
                (string) ($entry->device ?? ''),
                (string) ($entry->reason ?? ''),
                $this->valuesAsJson($entry->oldValues()),
                $this->valuesAsJson($entry->newValues()),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Filter options
    |--------------------------------------------------------------------------
    */

    /**
     * Only users who actually appear in the log — a filter that can never return nothing.
     *
     * @return array<string, string>
     */
    private function causerOptions(): array
    {
        try {
            $causerIds = Activity::query()
                ->select('causer_id')
                ->where('causer_type', (new User)->getMorphClass())
                ->whereNotNull('causer_id')
                ->distinct();

            /** @var array<string, string> $options */
            $options = User::query()
                ->withTrashed()
                ->whereIn('id', $causerIds)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(static fn (string $name, int|string $id): array => [(string) $id => $name])
                ->all();

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function moduleOptions(): array
    {
        $names = Modules::names();

        return $this->distinctColumn('module')
            ->mapWithKeys(fn (string $slug): array => [$slug => $names[$slug] ?? Str::headline($slug)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function eventOptions(): array
    {
        return $this->distinctColumn('event')
            ->mapWithKeys(static fn (string $event): array => [$event => Str::headline($event)])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function subjectTypeOptions(): array
    {
        return $this->distinctColumn('subject_type')
            ->mapWithKeys(static fn (string $type): array => [$type => Str::headline(class_basename($type))])
            ->all();
    }

    /**
     * Distinct non-null values of one log column, ordered.
     *
     * @return SupportCollection<int, string>
     */
    private function distinctColumn(string $column): SupportCollection
    {
        try {
            return Activity::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->map(static fn (mixed $value): string => (string) $value)
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Single entry
    |--------------------------------------------------------------------------
    */

    /**
     * Every attribute the entry carries, old beside new, with the ones that actually moved
     * flagged so the view can highlight them.
     *
     * @return array<int, array{attribute: string, label: string, old: string, new: string, changed: bool}>
     */
    private function diffRows(Activity $entry): array
    {
        $old = $entry->oldValues();
        $new = $entry->newValues();

        $attributes = array_keys($new + $old);
        sort($attributes);

        $rows = [];

        foreach ($attributes as $attribute) {
            $attribute = (string) $attribute;
            $before = $old[$attribute] ?? null;
            $after = $new[$attribute] ?? null;

            $rows[] = [
                'attribute' => $attribute,
                'label' => Str::headline($attribute),
                'old' => $this->presentValue($attribute, $before, array_key_exists($attribute, $old)),
                'new' => $this->presentValue($attribute, $after, array_key_exists($attribute, $new)),
                'changed' => $before !== $after,
            ];
        }

        return $rows;
    }

    /**
     * Custom properties that are neither `attributes` nor `old` — e.g. the context a service
     * attached to the entry.
     *
     * @return array<string, string>
     */
    private function extraProperties(Activity $entry): array
    {
        $properties = $entry->properties;

        if ($properties === null) {
            return [];
        }

        $bag = $properties instanceof SupportCollection ? $properties->all() : (array) $properties;

        unset($bag['attributes'], $bag['old']);

        $rows = [];

        foreach ($bag as $key => $value) {
            $rows[Str::headline((string) $key)] = $this->presentValue((string) $key, $value, true);
        }

        return $rows;
    }

    /**
     * What the entry was about: the model class, its key, and its name when the record is still
     * there to ask.
     *
     * @return array{type: string|null, label: string|null, id: int|string|null, name: string|null}
     */
    private function subjectSummary(Activity $entry): array
    {
        $type = $entry->subject_type;

        if ($type === null) {
            return ['type' => null, 'label' => null, 'id' => null, 'name' => null];
        }

        $name = null;

        try {
            $subject = $entry->subject;

            if ($subject !== null) {
                foreach (['name', 'title', 'label', 'slug', 'code', 'email'] as $candidate) {
                    $value = $subject->getAttribute($candidate);

                    if (is_string($value) && trim($value) !== '') {
                        $name = trim($value);

                        break;
                    }
                }
            }
        } catch (Throwable) {
            // Subject class gone or its table dropped — the type/id below still tell the story.
            $name = null;
        }

        return [
            'type' => (string) $type,
            'label' => Str::headline(class_basename((string) $type)),
            'id' => $entry->subject_id,
            'name' => $name,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Value presentation
    |--------------------------------------------------------------------------
    */

    /**
     * A log value as display text. `$present` separates "was null" from "not recorded".
     */
    private function presentValue(string $attribute, mixed $value, bool $present): string
    {
        if (! $present) {
            return '—';
        }

        if ($this->isRedacted($attribute)) {
            return self::REDACTED_PLACEHOLDER;
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof SupportCollection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $encoded === false ? '[unserialisable]' : $encoded;
        }

        if (is_scalar($value)) {
            $string = (string) $value;

            return $string === '' ? '(empty)' : $string;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unserialisable]' : $encoded;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function valuesAsJson(array $values): string
    {
        if ($values === []) {
            return '';
        }

        foreach ($values as $attribute => $value) {
            if ($this->isRedacted((string) $attribute)) {
                $values[$attribute] = self::REDACTED_PLACEHOLDER;
            }
        }

        $encoded = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    private function isRedacted(string $attribute): bool
    {
        $needle = strtolower($attribute);

        foreach (self::REDACTED as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function moduleLabel(?string $slug): ?string
    {
        if ($slug === null || trim($slug) === '') {
            return null;
        }

        return Modules::names()[$slug] ?? Str::headline($slug);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Reading the log needs `activity_log.view_logs` — the one ability the route middleware and
     * the sidebar item use as well. `Gate::before` still denies everything when the module is
     * disabled, and lets Super Admin through.
     */
    private function authorizeRead(?Authenticatable $user): void
    {
        abort_unless($this->canAny($user, ['activity_log.view_logs']), 403);
    }

    private function canExport(?Authenticatable $user): bool
    {
        return $this->canAny($user, ['activity_log.export']);
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

    private function timezoneFor(?Authenticatable $user): string
    {
        return $user instanceof User
            ? $user->effectiveTimezone()
            : Format::timezone(); // display timezone, never the UTC storage timezone (D61)
    }

    /**
     * The export link with the current filters attached, or null when the route is not
     * registered or the viewer may not export.
     */
    private function exportUrl(LogFilterRequest $request): ?string
    {
        if (! $this->canExport($request->user()) || ! Route::has('admin.activity-log.export')) {
            return null;
        }

        $query = collect($request->query())
            ->except(['page', 'per_page'])
            ->filter(static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->all();

        try {
            return route('admin.activity-log.export', $query);
        } catch (Throwable) {
            return null;
        }
    }
}
