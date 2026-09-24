<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DataObjects\Reporting\ActivityLogFilters;
use App\Models\Activity;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The §106 activity-log viewer (phase-19-23 §6.22).
 *
 * **INV-23-5: this class reads. It has no write method, no delete method, and no route that could
 * become one.** The activity log is the record of what everybody did; a service that could edit it
 * would make it a record of what somebody was willing to leave. Even pruning lives elsewhere — in a
 * console command, gated on a setting that defaults to never.
 *
 * **§9.5: a row whose subject belongs to a module the viewer cannot see is excluded from the
 * query.** Not greyed, not redacted — absent, and absent *in the SQL* rather than filtered out of a
 * page afterwards. Filtering a page would make the pagination lie: page 2 of 40 would sometimes
 * show three rows, and the count would tell somebody how many things they were not allowed to see.
 * {@see self::visibleModules()} resolves the allowed list once and the query is built against it.
 *
 * **The module list is an allow-list, and a row with no module is visible.** Phase 1 wrote log rows
 * before `activity_log.module` existed and a few subsystems still leave it null; treating null as
 * "deny" would silently hide the oldest history in the system from everybody, which is the opposite
 * of what an audit trail is for. A null module means "not attributable to a module", and anybody
 * who may open the viewer may read those.
 */
final class ActivityLogService
{
    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    /**
     * The viewer's list, narrowed and paginated.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function query(ActivityLogFilters $filters, User $viewer, int $perPage = 50): LengthAwarePaginator
    {
        return $this->build($filters, $viewer)
            ->latestFirst()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The history tab a detail screen embeds.
     *
     * Deliberately **not** paginated and deliberately capped: a history tab is a glance, and a
     * record with nine thousand rows should show the last fifty with a link to the full viewer
     * rather than a page that takes four seconds to render.
     *
     * @return Collection<int, Activity>
     */
    public function timelineFor(Model $subject, User $viewer, int $limit = 50): Collection
    {
        if (! $this->mayReadSubject($subject, $viewer)) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->with('causer')
            ->latestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * The context block a row expands into: where it came from and why.
     *
     * Returned as label => value pairs rather than raw columns, because this is rendered directly
     * and a screen should not have to know that `device` is a string and `causer` is a relation.
     *
     * @return array<string, string|null>
     */
    public function contextFor(Activity $activity): array
    {
        return [
            'When' => app_datetime($activity->created_at),
            'Who' => $this->causerName($activity),
            'What' => $activity->description,
            'Event' => $activity->event,
            'Module' => $activity->module !== null ? Modules::names()[$activity->module] ?? $activity->module : null,
            'Record' => $this->subjectLabel($activity),
            'IP address' => $activity->ip_address,
            'Device' => $activity->device,
            'Browser' => $activity->user_agent,
            'Reason given' => $activity->reason,
            'Batch' => $activity->batch_uuid,
        ];
    }

    /**
     * Rows for an export, as a generator-friendly builder.
     *
     * The export path takes the builder rather than a paginator so `chunkById` can stream it —
     * a log export is the one that is genuinely large.
     *
     * @return Builder<Activity>
     */
    public function exportQuery(ActivityLogFilters $filters, User $viewer): Builder
    {
        return $this->build($filters, $viewer)->latestFirst();
    }

    /**
     * One row, shaped for a table or a file.
     *
     * @return array<string, mixed>
     */
    public function row(Activity $activity): array
    {
        return [
            'when' => app_datetime($activity->created_at),
            'who' => $this->causerName($activity),
            'event' => $activity->event,
            'description' => $activity->description,
            'module' => $activity->module,
            'record' => $this->subjectLabel($activity),
            'ip_address' => $activity->ip_address,
            'device' => $activity->device,
            'reason' => $activity->reason,
        ];
    }

    /**
     * The filtered, scoped query — the one place the §9.5 rules are applied.
     *
     * @return Builder<Activity>
     */
    private function build(ActivityLogFilters $filters, User $viewer): Builder
    {
        $query = Activity::query()->with('causer');

        // §9.5, in the SQL. See the class note for why not afterwards.
        $allowed = $this->visibleModules($viewer);

        if ($allowed !== null) {
            $query->where(static function (Builder $q) use ($allowed): void {
                $q->whereNull('activity_log.module')->orWhereIn('activity_log.module', $allowed);
            });
        }

        if ($filters->range !== null) {
            $query->whereBetween('activity_log.created_at', [
                $filters->range->start(),
                $filters->range->end(),
            ]);
        }

        if ($filters->modules !== []) {
            // Intersected with what the viewer may see, so asking for a module by name can never
            // widen the scope past the allow-list.
            $modules = $allowed === null
                ? $filters->modules
                : array_values(array_intersect($filters->modules, $allowed));

            $query->whereIn('activity_log.module', $modules === [] ? ['__none__'] : $modules);
        }

        if ($filters->events !== []) {
            $query->whereIn('activity_log.event', $filters->events);
        }

        if ($filters->logNames !== []) {
            $query->whereIn('activity_log.log_name', $filters->logNames);
        }

        if ($filters->causerType !== null) {
            $query->where('activity_log.causer_type', $filters->causerType);
        }

        if ($filters->causerId !== null) {
            $query->where('activity_log.causer_id', $filters->causerId);
        }

        if ($filters->subjectType !== null) {
            $query->where('activity_log.subject_type', $filters->subjectType);
        }

        if ($filters->subjectId !== null) {
            $query->where('activity_log.subject_id', $filters->subjectId);
        }

        if ($filters->ipAddress !== null) {
            $query->where('activity_log.ip_address', $filters->ipAddress);
        }

        if ($filters->device !== null) {
            $query->where('activity_log.device', $filters->device);
        }

        if ($filters->batchUuid !== null) {
            $query->where('activity_log.batch_uuid', $filters->batchUuid);
        }

        if ($filters->withReason) {
            $query->whereNotNull('activity_log.reason')->where('activity_log.reason', '<>', '');
        }

        if ($filters->search !== null) {
            $query->search($filters->search);
        }

        return $query;
    }

    /**
     * The module slugs this viewer may read log rows for, or **null** for "everything".
     *
     * Null rather than a list of all 114 slugs, because `null` lets the query skip the `whereIn`
     * entirely — and for a Super Admin, who is the person most likely to be reading a log of
     * millions of rows, that is the difference between an index scan and a filesort.
     *
     * @return list<string>|null
     */
    private function visibleModules(User $viewer): ?array
    {
        $gate = app(Gate::class)->forUser($viewer);

        $allowed = [];
        $denied = 0;

        foreach (array_keys(Modules::map()) as $slug) {
            // `view_any` is the read ability for most modules; a module the viewer cannot list at
            // all is one whose history they have no business reading either.
            $gate->allows($slug.'.view_any') || $gate->allows($slug.'.view') || $gate->allows($slug.'.view_logs')
                ? $allowed[] = $slug
                : $denied++;
        }

        return $denied === 0 ? null : $allowed;
    }

    /**
     * May this person read this record's history at all?
     *
     * The timeline is embedded on detail screens that have already authorised the record, so this
     * is a backstop rather than the main gate — but a backstop that is missing is a detail screen
     * away from being a disclosure.
     */
    private function mayReadSubject(Model $subject, User $viewer): bool
    {
        $module = Modules::moduleForSubject($subject);

        if ($module === null) {
            return true;
        }

        $gate = app(Gate::class)->forUser($viewer);

        return $gate->allows($module.'.view_any')
            || $gate->allows($module.'.view')
            || $gate->allows($module.'.view_logs');
    }

    private function causerName(Activity $activity): ?string
    {
        $causer = $activity->causer;

        if ($causer === null) {
            // A row with no causer is the system acting on its own — a scheduled sweep, a queued
            // job. Saying so is better than an empty cell somebody reads as missing data.
            return $activity->causer_id === null ? 'System' : null;
        }

        return $causer->name ?? ('#'.$activity->causer_id);
    }

    private function subjectLabel(Activity $activity): ?string
    {
        if ($activity->subject_type === null) {
            return null;
        }

        return sprintf('%s #%s', class_basename((string) $activity->subject_type), (string) $activity->subject_id);
    }
}
