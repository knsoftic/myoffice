<?php

declare(strict_types=1);

namespace App\Services\Core\Concerns;

use App\Support\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Throwable;

/**
 * Writes an explicit audit row for changes Eloquent's model events cannot see.
 *
 * `LogsActivityWithContext` already records attribute changes with their old and new values.
 * Pivot writes (role ↔ permission, user ↔ role) fire no model event, so the services log
 * those by hand — with the same request context the trait stamps, so the Activity Log screen
 * renders both kinds of entry identically (phase-01 §1.7, §10 "Audit").
 */
trait WritesAuditTrail
{
    /**
     * @param  array<string, mixed>  $properties  e.g. ['old' => [...], 'attributes' => [...]]
     */
    protected function audit(
        Model $subject,
        string $description,
        array $properties = [],
        ?string $module = null,
        ?string $reason = null,
    ): void {
        $logger = activity()->performedOn($subject);

        $causer = Auth::user();

        if ($causer instanceof Model) {
            $logger->causedBy($causer);
        }

        if ($properties !== []) {
            $logger->withProperties($properties);
        }

        $context = $this->auditContext();

        $logger->tap(function (ActivityContract $activity) use ($context, $module, $reason): void {
            foreach ($context as $column => $value) {
                if ($value !== null) {
                    $activity->setAttribute($column, $value);
                }
            }

            if ($module !== null && $module !== '') {
                $activity->setAttribute('module', mb_substr($module, 0, 64));
            }

            if ($reason !== null && $reason !== '') {
                $activity->setAttribute('reason', mb_substr($reason, 0, 500));
            }
        });

        $logger->log($description);
    }

    /**
     * ip_address / user_agent / device of the current request; empty in console context so a
     * seeder never stamps rows with 127.0.0.1 (same rule as LogsActivityWithContext).
     *
     * @return array<string, string|null>
     */
    private function auditContext(): array
    {
        try {
            if (app()->runningInConsole()) {
                return [];
            }

            $request = request();
            $agent = trim((string) $request->userAgent());
            $ip = (string) $request->ip();

            return [
                'ip_address' => $ip === '' ? null : mb_substr($ip, 0, 45),
                'user_agent' => $agent === '' ? null : $agent,
                'device' => $agent === '' ? null : mb_substr(Device::device($agent), 0, 64),
            ];
        } catch (Throwable) {
            return [];
        }
    }
}
