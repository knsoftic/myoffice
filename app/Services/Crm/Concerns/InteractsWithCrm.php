<?php

declare(strict_types=1);

namespace App\Services\Crm\Concerns;

use App\Models\User;
use App\Support\SettingsRepository;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What every phase-05 service shares: the actor, typed `crm.*` settings reads with the contract's defaults, the
 * old/new diff for an audit row, and a way to save a model without its automatic activity row when the service
 * writes the explicit one (so a status move is exactly one `activity_log` entry with old, new and the reason).
 */
trait InteractsWithCrm
{
    protected function actor(): ?User
    {
        try {
            $user = Auth::user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    protected function actorId(): ?int
    {
        $id = $this->actor()?->getKey();

        return $id === null ? null : (int) $id;
    }

    protected function crmSetting(string $key, mixed $default = null): mixed
    {
        try {
            return app(SettingsRepository::class)->get('crm.'.$key, $default);
        } catch (Throwable) {
            return $default;
        }
    }

    protected function crmInt(string $key, int $default, int $min = 0, ?int $max = null): int
    {
        $value = $this->crmSetting($key, $default);
        $value = is_numeric($value) ? (int) $value : $default;
        $value = max($min, $value);

        return $max === null ? $value : min($max, $value);
    }

    protected function crmBool(string $key, bool $default): bool
    {
        $value = $this->crmSetting($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function crmList(string $key, array $default): array
    {
        $value = $this->crmSetting($key, $default);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * The `'%06d'` pad built from `crm.number_padding`.
     */
    protected function crmPad(): string
    {
        return sprintf('%%0%dd', $this->crmInt('number_padding', 6, 1, 20));
    }

    /**
     * Run `$callback` with automatic model activity logging switched off — for a save the service audits itself.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function withoutModelLogging(Closure $callback): mixed
    {
        try {
            $logger = activity();
        } catch (Throwable) {
            return $callback();
        }

        return $logger->withoutLogs($callback);
    }

    /**
     * Only the keys whose value changed, spatie's `old` / `attributes` shape.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    protected function changes(array $old, array $new): array
    {
        $before = [];
        $after = [];

        foreach ($new as $key => $value) {
            $previous = $old[$key] ?? null;

            if ($this->comparable($previous) === $this->comparable($value)) {
                continue;
            }

            $before[$key] = $this->plain($previous);
            $after[$key] = $this->plain($value);
        }

        return ['old' => $before, 'attributes' => $after];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function snapshot(Model $model, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = $this->plain($model->getAttribute($key));
        }

        return $out;
    }

    protected function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    protected function cleanText(?string $value, int $max = 255): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function comparable(mixed $value): string
    {
        $value = $this->plain($value);

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }
}
