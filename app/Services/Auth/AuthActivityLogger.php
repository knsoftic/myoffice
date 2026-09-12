<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Device;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Throwable;

/**
 * Writes the `activity_log` entries produced by the authentication area (phase-01 §1.7, §7).
 *
 * Models get their context columns filled by App\Models\Concerns\LogsActivityWithContext;
 * authentication events are not model changes, so they are logged by hand — this class is the
 * one place that knows how to stamp `ip_address`, `user_agent`, `device` and `module` on them.
 *
 *   $logger->record('Signed in', $user, event: 'login', module: 'login_history');
 *
 * Never throws: an audit write must not be able to break a sign-in. Failures are reported to
 * the application log instead.
 */
final class AuthActivityLogger
{
    /**
     * `activity_log.log_name` every entry written here shares.
     */
    public const LOG_NAME = 'auth';

    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $description,
        ?User $user = null,
        string $event = 'auth',
        string $module = 'login_history',
        array $properties = [],
    ): void {
        try {
            $logger = activity(self::LOG_NAME);

            if ($user instanceof User && $user->exists) {
                $logger->performedOn($user)->causedBy($user);
            } else {
                $logger->causedByAnonymous();
            }

            $context = $this->context();

            $logger
                ->event($event)
                ->withProperties($properties)
                ->tap(function (ActivityContract $activity) use ($context, $module): void {
                    if (! $activity instanceof Model) {
                        return;
                    }

                    $activity->setAttribute('module', mb_substr($module, 0, 64));

                    foreach ($context as $column => $value) {
                        if ($value !== null) {
                            $activity->setAttribute($column, $value);
                        }
                    }
                })
                ->log($description);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * ip_address / user_agent / device for the current request.
     *
     * @return array{ip_address: string|null, user_agent: string|null, device: string|null}
     */
    private function context(): array
    {
        try {
            if (app()->runningInConsole()) {
                return ['ip_address' => null, 'user_agent' => null, 'device' => null];
            }

            $request = request();
            $ip = trim((string) $request->ip());
            $userAgent = trim((string) $request->userAgent());

            return [
                'ip_address' => $ip === '' ? null : mb_substr($ip, 0, 45),
                'user_agent' => $userAgent === '' ? null : $userAgent,
                'device' => $userAgent === '' ? null : mb_substr(Device::device($userAgent), 0, 64),
            ];
        } catch (Throwable) {
            return ['ip_address' => null, 'user_agent' => null, 'device' => null];
        }
    }
}
