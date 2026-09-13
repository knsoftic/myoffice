<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Support\Device;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Throwable;

/**
 * Writes the `activity_log` rows the CMS acts demand (phase-03 INV-16, D13).
 *
 * The CMS services persist snapshots, hashes, pivots and sort orders through the query builder so
 * that no model accessor, cast or generated column can interfere with a publish — which means the
 * model's own `LogsActivityWithContext` events do not fire for those writes. Every such act is
 * therefore recorded here, explicitly, with:
 *
 *   · the old and the new values (`properties.old` / `properties.attributes`, spatie's shape, so the
 *     Activity Log screen renders these rows exactly like model events)
 *   · the actor, the IP, the user agent and the device (none of them in console context, where a
 *     fake request would stamp every seeded row with 127.0.0.1 — the same rule as
 *     `App\Services\Core\Concerns\WritesAuditTrail`)
 *   · the CMS module slug, and the reason for a discretionary act (unpublish, revert, remove)
 *
 * Unlike `WritesAuditTrail` a subject is optional: a placement reorder or a cache bump has no single
 * row to hang the entry on.
 *
 * Invariant: an audit failure never rolls back or fails the business write it describes — it is
 * reported, not thrown. (Callers run it inside their transaction, so a failure *before* commit still
 * rolls the whole act back with it; only a failure of the logger itself is swallowed.)
 */
final class CmsAuditor
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * @param  array<string, mixed>  $properties  typically ['old' => [...], 'attributes' => [...]]
     */
    public function record(
        string $module,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?string $reason = null,
        ?string $event = null,
    ): void {
        try {
            $logger = activity();

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $causer = $this->auth->guard()->user();

            if ($causer instanceof Model) {
                $logger->causedBy($causer);
            }

            if ($event !== null && $event !== '') {
                $logger->event($event);
            }

            if ($properties !== []) {
                $logger->withProperties($properties);
            }

            $context = $this->requestContext();

            $logger->tap(static function (ActivityContract $activity) use ($context, $module, $reason): void {
                foreach ($context as $column => $value) {
                    if ($value !== null) {
                        $activity->setAttribute($column, $value);
                    }
                }

                $activity->setAttribute('module', mb_substr($module, 0, 64));

                $reason = $reason === null ? '' : trim($reason);

                if ($reason !== '') {
                    $activity->setAttribute('reason', mb_substr($reason, 0, 500));
                }
            });

            $logger->log($description);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Only the keys whose value actually changed, as spatie's old/attributes pair.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    public function diff(array $old, array $new): array
    {
        $before = [];
        $after = [];

        foreach ($new as $key => $value) {
            $previous = $old[$key] ?? null;

            if ($this->comparable($previous) === $this->comparable($value)) {
                continue;
            }

            $before[$key] = $previous;
            $after[$key] = $value;
        }

        return ['old' => $before, 'attributes' => $after];
    }

    /**
     * The id of the authenticated actor, or null in console / queue / seeder context.
     */
    public function actorId(): ?int
    {
        try {
            $id = $this->auth->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    private function comparable(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        return is_scalar($value) || $value === null
            ? (string) $value
            : (string) json_encode($value);
    }

    /**
     * @return array<string, string|null>
     */
    private function requestContext(): array
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
