<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Throwable;

/**
 * spatie `LogsActivity` plus the request context the audit trail needs (phase-01 §3, D13).
 *
 * What it adds on top of the package:
 *   · only dirty attributes are logged, with both the old and the new value
 *   · `tapActivity()` fills activity_log.ip_address / user_agent / device / module
 *   · `withReason('...')` attaches a human explanation to the next write
 *
 * Defaults are driven by small overridable hooks so a model only states what differs:
 *
 *   protected function activityModule(): ?string { return 'users'; }
 *   protected function activityLogAttributes(): array { return ['name', 'status']; }
 *   protected function activityIgnoredAttributes(): array { return ['last_login_at']; }
 *
 * Never log a secret: `activitySecretAttributes()` is subtracted from the logged set, and
 * `password` / `remember_token` are in it by default.
 */
trait LogsActivityWithContext
{
    use LogsActivity;

    /**
     * Reason attached to the activities this instance writes from now on.
     */
    protected ?string $activityReason = null;

    /**
     * Options handed to spatie for every logged event.
     */
    public function getActivitylogOptions(): LogOptions
    {
        // Captured by value so the description closure stays static (cleanly serializable).
        $subject = $this->activitySubjectLabel();

        return LogOptions::defaults()
            ->logOnly($this->activityLogAttributes())
            ->logExcept($this->activitySecretAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly($this->activityIgnoredAttributes())
            ->useLogName($this->activityLogName())
            ->setDescriptionForEvent(static fn (string $eventName): string => trim($subject.' '.$eventName));
    }

    /**
     * Called by spatie right before the activity row is saved (ActivityLogger::log()).
     *
     * @param  ActivityContract&Model  $activity
     */
    public function tapActivity(ActivityContract $activity, string $eventName = ''): void
    {
        foreach ($this->activityRequestContext() as $column => $value) {
            if ($value !== null) {
                $activity->setAttribute($column, $value);
            }
        }

        $module = $this->activityModule();

        if ($module !== null && $module !== '') {
            $activity->setAttribute('module', mb_substr($module, 0, 64));
        }

        if ($this->activityReason !== null && $this->activityReason !== '') {
            $activity->setAttribute('reason', mb_substr($this->activityReason, 0, 500));
        }

        // phase-08-09 §2.5 / §13. §60 wants "this collaborator's activity log", and D13 contracts one
        // audit store — so the filter has to be an indexed column on the row rather than a second table.
        // The causer cannot serve: the rows a partner most wants to see (commission created, payout
        // paid) are written by the engine with a **null** causer. A model that belongs to a collaborator
        // says so here, and everything else leaves the column null.
        $collaboratorId = $this->activityCollaboratorId();

        if ($collaboratorId !== null) {
            $activity->setAttribute('collaborator_id', $collaboratorId);
        }
    }

    /**
     * Which collaborator is this row about? Null for everything that is about nobody in particular,
     * which is almost every model in the system.
     */
    protected function activityCollaboratorId(): ?int
    {
        return null;
    }

    /**
     * Explain the next change — stored in `activity_log.reason`.
     *
     *   $user->withReason('Suspended after repeated failed logins')->update(['status' => ...]);
     *
     * The reason stays attached to this instance until it is changed or cleared.
     */
    public function withReason(string $reason): static
    {
        $reason = trim($reason);

        $this->activityReason = $reason === '' ? null : mb_substr($reason, 0, 500);

        return $this;
    }

    /**
     * Drop a previously attached reason.
     */
    public function withoutReason(): static
    {
        $this->activityReason = null;

        return $this;
    }

    /**
     * The reason currently attached to this instance.
     */
    public function currentActivityReason(): ?string
    {
        return $this->activityReason;
    }

    /**
     * ip_address / user_agent / device for the current request.
     *
     * Console, queue and seeder writes intentionally record no request context: a fake CLI
     * request would otherwise stamp every seeded row with 127.0.0.1.
     *
     * @return array<string, string|null>
     */
    protected function activityRequestContext(): array
    {
        try {
            if (app()->runningInConsole()) {
                return [];
            }

            $request = request();
            $userAgent = trim((string) $request->userAgent());
            $ip = (string) $request->ip();

            return [
                'ip_address' => $ip === '' ? null : mb_substr($ip, 0, 45),
                'user_agent' => $userAgent === '' ? null : $userAgent,
                'device' => $userAgent === '' ? null : mb_substr(Device::device($userAgent), 0, 64),
            ];
        } catch (Throwable) {
            // No request bound (early boot, artisan, test tear-down): context stays empty.
            return [];
        }
    }

    /**
     * Module slug the change belongs to (activity_log.module). Null keeps the column empty.
     */
    protected function activityModule(): ?string
    {
        return null;
    }

    /**
     * Attributes whose changes are worth recording. Defaults to the mass-assignable set.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        $attributes = $this->getFillable();

        return $attributes === [] ? ['*'] : array_values($attributes);
    }

    /**
     * Never written to the log, whatever else is configured.
     *
     * @return array<int, string>
     */
    protected function activitySecretAttributes(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];
    }

    /**
     * Changing only these attributes is not worth an activity row (housekeeping writes).
     *
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'remember_token'];
    }

    /**
     * Log channel name (activity_log.log_name). Null falls back to the config default.
     */
    protected function activityLogName(): ?string
    {
        return null;
    }

    /**
     * Human label used in the description — "User updated", "Branch created".
     */
    protected function activitySubjectLabel(): string
    {
        return Str::headline(class_basename($this));
    }
}
