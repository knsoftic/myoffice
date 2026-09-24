<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DataObjects\Reporting\ActivityLogFilters;
use App\Enums\AuditSensitivity;
use App\Models\Activity;
use App\Models\User;
use App\Support\Modules;
use App\Support\SettingsRegistry;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The §107 audit trail (phase-19-23 §6.22) — the same table as §106, a different question.
 *
 * §106 asks *who did what*. This asks *what changed, from what, to what*, and it answers only for
 * the rows that can answer it: ones carrying both `properties.old` and `properties.attributes`.
 *
 * **The row is never hidden; only the values can be withheld.** That distinction is the whole of
 * §107 and it is worth stating plainly, because the obvious implementation gets it backwards. An
 * audit trail whose *rows* disappear for some readers is not an audit trail — the absence itself is
 * unauditable, and somebody looking at a gap cannot tell whether nothing happened or whether they
 * were not allowed to see what did. So every qualifying row is listed for anybody who may open the
 * trail, and a `financial` row's figures are replaced with a locked marker naming the permission
 * that would open them. Withheld, not blanked, and never dropped.
 *
 * **An encrypted value is `[encrypted]`, always, for everybody.** Phase 2's rule: `mail.password`
 * and its kind are never echoed back, and an audit trail that printed the old SMTP password to
 * whoever could read it would be a worse leak than the change it was recording.
 *
 * **`reports.audit_sensitive_modules` decides what the trail *opens on*, not what it can reach.**
 * Every module is still logged and still reachable by asking for it; the setting is the default
 * filter, because a trail that opened on all 114 modules would bury the commission-rate change
 * somebody came to find.
 */
final class AuditTrailService
{
    /** What a withheld figure is replaced with — a marker, never a blank or a zero. */
    public const WITHHELD = '[withheld]';

    public const ENCRYPTED = '[encrypted]';

    /**
     * The trail, narrowed and paginated.
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
     * Every recorded change to one record.
     *
     * @return LengthAwarePaginator<int, Activity>
     */
    public function forSubject(Model $subject, User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        return $this->changedRows(Activity::query())
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->with('causer')
            ->latestFirst()
            ->paginate($perPage);
    }

    /**
     * One row per changed field: `{field, label, old, new, type, withheld}`.
     *
     * **Only the fields that actually changed.** Spatie writes the whole attribute set on an update,
     * so a diff that listed every attribute would bury a changed commission rate among forty
     * unchanged columns — and the reader would have to spot the difference themselves, which is the
     * job this method exists to do.
     *
     * @return list<array<string, mixed>>
     */
    public function diff(Activity $activity, User $viewer): array
    {
        $old = $activity->oldValues();
        $new = $activity->newValues();

        if ($old === [] && $new === []) {
            return [];
        }

        $sensitivity = $this->sensitivityOf($activity);
        $withhold = $this->mustWithhold($activity, $sensitivity, $viewer);

        $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        $rows = [];

        foreach ($fields as $field) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;

            // Loose comparison on purpose: `"10.00"` and `10.0` out of a JSON column are the same
            // figure, and a diff that reported them as a change would cry wolf on every save.
            if ($this->same($before, $after)) {
                continue;
            }

            $encrypted = $this->isEncrypted($activity, $field);
            $financial = $this->isFinancialField($field);
            $hidden = $encrypted || ($withhold && $financial);

            $rows[] = [
                'field' => $field,
                'label' => $this->labelFor($field),
                'old' => $hidden ? $this->marker($encrypted) : $this->format($before),
                'new' => $hidden ? $this->marker($encrypted) : $this->format($after),
                'type' => $financial ? 'money' : $this->typeOf($after ?? $before),
                'withheld' => $hidden,
                'permission' => $hidden && ! $encrypted ? $this->financialPermissionFor($activity) : null,
            ];
        }

        return $rows;
    }

    /**
     * How closely this row is held.
     *
     * Financial beats sensitive: a change that touches money is a money change even when it also
     * touches a name, because the stricter reading is the safe one and the only cost of being wrong
     * that way is a marker somebody can ask to have lifted.
     */
    public function sensitivityOf(Activity $activity): AuditSensitivity
    {
        $module = (string) $activity->module;

        if ($module !== '' && $this->isFinancialModule($module)) {
            return AuditSensitivity::Financial;
        }

        foreach (array_keys([...$activity->oldValues(), ...$activity->newValues()]) as $field) {
            if ($this->isFinancialField((string) $field)) {
                return AuditSensitivity::Financial;
            }
        }

        return in_array($module, self::SENSITIVE_MODULES, true)
            ? AuditSensitivity::Sensitive
            : AuditSensitivity::Normal;
    }

    /**
     * The builder an export streams.
     *
     * @return Builder<Activity>
     */
    public function exportQuery(ActivityLogFilters $filters, User $viewer): Builder
    {
        return $this->build($filters, $viewer)->latestFirst();
    }

    /**
     * One trail row, flattened for a table or a file.
     *
     * The changes are rendered as `field: old → new` lines, which is what both a table cell and a
     * CSV column can hold. A withheld figure carries its marker into the file, so an exported trail
     * says what it is not showing rather than appearing complete.
     *
     * @return array<string, mixed>
     */
    public function row(Activity $activity, User $viewer): array
    {
        $diff = $this->diff($activity, $viewer);
        $sensitivity = $this->sensitivityOf($activity);

        return [
            'when' => app_datetime($activity->created_at),
            'who' => $activity->causer?->name ?? ($activity->causer_id === null ? 'System' : '#'.$activity->causer_id),
            'module' => $activity->module,
            'record' => $activity->subject_type === null
                ? null
                : sprintf('%s #%s', class_basename((string) $activity->subject_type), (string) $activity->subject_id),
            'event' => $activity->event,
            'sensitivity' => $sensitivity->label(),
            'changes' => implode("\n", array_map(
                static fn (array $c): string => sprintf('%s: %s → %s', $c['label'], $c['old'], $c['new']),
                $diff,
            )),
            'reason' => $activity->reason,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Building the query
    |--------------------------------------------------------------------------
    */

    /**
     * @return Builder<Activity>
     */
    private function build(ActivityLogFilters $filters, User $viewer): Builder
    {
        $query = $this->changedRows(Activity::query()->with('causer'));

        // The §9.5 module scope, identical to §106's: a row whose module the viewer cannot see is
        // absent from the query, not filtered out of the page.
        $allowed = $this->visibleModules($viewer);

        if ($allowed !== null) {
            $query->where(static function (Builder $q) use ($allowed): void {
                $q->whereNull('activity_log.module')->orWhereIn('activity_log.module', $allowed);
            });
        }

        // The default narrowing: what the trail opens on. An explicit module filter replaces it,
        // because the setting decides the default and never the ceiling.
        $modules = $filters->modules !== [] ? $filters->modules : $this->defaultModules();

        if ($modules !== []) {
            $modules = $allowed === null ? $modules : array_values(array_intersect($modules, $allowed));
            $query->whereIn('activity_log.module', $modules === [] ? ['__none__'] : $modules);
        }

        if ($filters->range !== null) {
            $query->whereBetween('activity_log.created_at', [$filters->range->start(), $filters->range->end()]);
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

        if ($filters->events !== []) {
            $query->whereIn('activity_log.event', $filters->events);
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
     * Only rows that recorded a before and an after.
     *
     * A created row has `attributes` and no `old`; a deleted row has `old` and no `attributes`.
     * Neither is a *change*, and §107 asks what changed — so the trail wants updates. The two JSON
     * keys are checked in SQL rather than in PHP so the count and the pagination are honest.
     *
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function changedRows(Builder $query): Builder
    {
        return $query
            ->whereNotNull('activity_log.properties')
            ->whereRaw("JSON_CONTAINS_PATH(activity_log.properties, 'one', '$.old')")
            ->whereRaw("JSON_CONTAINS_PATH(activity_log.properties, 'one', '$.attributes')");
    }

    /**
     * `reports.audit_sensitive_modules`, falling back to the registry default.
     *
     * @return list<string>
     */
    private function defaultModules(): array
    {
        $configured = setting('reports.audit_sensitive_modules');

        if (is_array($configured) && $configured !== []) {
            return array_values(array_filter($configured, 'is_string'));
        }

        $field = SettingsRegistry::field('reports.audit_sensitive_modules');

        return array_values((array) ($field['default'] ?? []));
    }

    /**
     * @return list<string>|null
     */
    private function visibleModules(User $viewer): ?array
    {
        $gate = app(Gate::class)->forUser($viewer);

        $allowed = [];
        $denied = 0;

        foreach (array_keys(Modules::map()) as $slug) {
            $gate->allows($slug.'.view_any') || $gate->allows($slug.'.view') || $gate->allows($slug.'.view_logs')
                ? $allowed[] = $slug
                : $denied++;
        }

        return $denied === 0 ? null : $allowed;
    }

    /*
    |--------------------------------------------------------------------------
    | Withholding
    |--------------------------------------------------------------------------
    */

    /**
     * Must this viewer's copy of this row have its figures withheld?
     *
     * Two conditions, and both have to hold. `reports.audit_show_financial_values` defaults to
     * **on**, because an institute that has granted somebody the audit trail has usually already
     * decided they may see what changed — and a trail reading "a number became another number"
     * answers nothing. When it is off, the module's own `view_financial` is what lifts the marker.
     */
    private function mustWithhold(Activity $activity, AuditSensitivity $sensitivity, User $viewer): bool
    {
        if (! $sensitivity->valuesNeedFinancialPermission()) {
            return false;
        }

        $permission = $this->financialPermissionFor($activity);

        if ($permission === null) {
            return false;
        }

        return ! app(Gate::class)->forUser($viewer)->allows($permission);
    }

    private function financialPermissionFor(Activity $activity): ?string
    {
        $module = (string) $activity->module;

        return $module === '' ? null : $module.'.view_financial';
    }

    private function marker(bool $encrypted): string
    {
        return $encrypted ? self::ENCRYPTED : self::WITHHELD;
    }

    /**
     * Is this a value that must never be printed, for anybody?
     *
     * The settings registry already knows which keys are encrypted, so the list is read from there
     * rather than repeated here — one declaration, as everywhere else.
     */
    private function isEncrypted(Activity $activity, string $field): bool
    {
        if (in_array($field, ['password', 'password_hash', 'secret', 'token', 'api_key', 'access_token'], true)) {
            return true;
        }

        if ($activity->module !== 'settings') {
            return false;
        }

        foreach (SettingsRegistry::encryptedKeys() as $key) {
            if ($key === $field || str_ends_with($key, '.'.$field)) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Naming and formatting
    |--------------------------------------------------------------------------
    */

    /** Modules whose changes are flagged even when no money is involved. */
    private const SENSITIVE_MODULES = ['users', 'roles', 'permissions', 'settings', 'modules', 'backups'];

    /** Modules where any change is treated as financial. */
    private const FINANCIAL_MODULES = [
        'collaborator_commission_settings',
        'collaborator_commissions',
        'collaborator_payouts',
        'student_fees',
        'fee_discounts',
        'invoices',
        'project_payments',
        'income',
        'expenses',
        'payroll',
    ];

    private function isFinancialModule(string $module): bool
    {
        return in_array($module, self::FINANCIAL_MODULES, true);
    }

    /**
     * Does this column hold money or a rate?
     *
     * Matched on the naming convention rather than a list, because CLAUDE.md §3 makes the convention
     * binding: money columns end in `_amount`, rates in `_rate` or `_percentage`. A list would need
     * a line adding for every new column and would be wrong the first time somebody forgot.
     */
    private function isFinancialField(string $field): bool
    {
        foreach (['_amount', '_rate', '_percentage', '_fee', '_salary', '_balance', '_total'] as $suffix) {
            if (str_ends_with($field, $suffix)) {
                return true;
            }
        }

        return in_array($field, ['amount', 'salary', 'balance', 'total', 'fee', 'rate', 'price', 'net_value'], true);
    }

    private function labelFor(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    private function typeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            $value instanceof DateTimeInterface => 'datetime',
            default => 'text',
        };
    }

    /**
     * Render one value the way a reader expects to see it.
     *
     * A null becomes an em dash rather than an empty cell, because "this field was empty before" and
     * "this cell failed to render" look identical otherwise.
     */
    private function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return app_datetime($value);
        }

        if (is_array($value)) {
            // A JSON column that changed: show it as JSON rather than "Array", which tells nobody
            // anything and is the single most common thing a diff gets wrong.
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—';
        }

        return (string) $value;
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            // "10.00" and 10.0 out of JSON are one figure - see diff().
            return bccomp((string) $a, (string) $b, 6) === 0;
        }

        return (string) $a === (string) $b;
    }
}
