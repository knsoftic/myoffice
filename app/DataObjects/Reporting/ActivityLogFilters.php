<?php

declare(strict_types=1);

namespace App\DataObjects\Reporting;

use App\Support\DateRange;
use Illuminate\Http\Request;

/**
 * What the §106 activity-log viewer is being asked for (phase-19-23 §6.22).
 *
 * A readonly filter set rather than a bag of request parameters, for the same reason `ClientFilters`
 * is one: the viewer, the export and the queued export job all need to apply *the same* narrowing,
 * and an export that rebuilt the filters from a query string would drift from the screen that
 * produced it.
 *
 * **`withReason` is not a cosmetic filter.** A `reason` is written when somebody had to explain
 * themselves — an amended mark, a cancelled fee, a re-linked collaborator — so "only rows with a
 * reason" is the fastest way to find every deliberate override in a period. That is usually the
 * first question an auditor asks.
 */
final readonly class ActivityLogFilters
{
    /**
     * @param  list<string>  $modules
     * @param  list<string>  $events
     * @param  list<string>  $logNames
     */
    public function __construct(
        public ?DateRange $range = null,
        public array $modules = [],
        public array $events = [],
        public array $logNames = [],
        public ?string $causerType = null,
        public ?int $causerId = null,
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $ipAddress = null,
        public ?string $device = null,
        public ?string $search = null,
        public bool $withReason = false,
        public ?string $batchUuid = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            range: self::rangeFrom($data),
            modules: self::strings($data, 'modules'),
            events: self::strings($data, 'events'),
            logNames: self::strings($data, 'log_names'),
            causerType: self::str($data, 'causer_type'),
            causerId: self::int($data, 'causer_id'),
            subjectType: self::str($data, 'subject_type'),
            subjectId: self::int($data, 'subject_id'),
            ipAddress: self::str($data, 'ip_address', 45),
            device: self::str($data, 'device', 64),
            search: self::str($data, 'search', 190),
            withReason: (bool) ($data['with_reason'] ?? false),
            batchUuid: self::str($data, 'batch_uuid', 36),
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray([
            'preset' => $request->input('preset'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'modules' => $request->input('modules', []),
            'events' => $request->input('events', []),
            'log_names' => $request->input('log_names', []),
            'causer_type' => $request->input('causer_type'),
            'causer_id' => $request->input('causer_id'),
            'subject_type' => $request->input('subject_type'),
            'subject_id' => $request->input('subject_id'),
            'ip_address' => $request->input('ip_address'),
            'device' => $request->input('device'),
            'search' => $request->input('search'),
            'with_reason' => $request->boolean('with_reason'),
            'batch_uuid' => $request->input('batch_uuid'),
        ]);
    }

    /**
     * The filter set as a plain array — what a queued export stores to rebuild it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'preset' => $this->range?->preset(),
            'from' => $this->range?->start()->toDateString(),
            'to' => $this->range?->end()->toDateString(),
            'modules' => $this->modules,
            'events' => $this->events,
            'log_names' => $this->logNames,
            'causer_type' => $this->causerType,
            'causer_id' => $this->causerId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'ip_address' => $this->ipAddress,
            'device' => $this->device,
            'search' => $this->search,
            'with_reason' => $this->withReason ?: null,
            'batch_uuid' => $this->batchUuid,
        ], static fn (mixed $v): bool => $v !== null && $v !== [] && $v !== '');
    }

    /** Is anything actually narrowing? Used by the screen to decide whether to offer "clear". */
    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function rangeFrom(array $data): ?DateRange
    {
        $preset = self::str($data, 'preset', 32);
        $from = self::str($data, 'from', 32);
        $to = self::str($data, 'to', 32);

        if ($preset === null && $from === null && $to === null) {
            return null;
        }

        try {
            return DateRange::make($preset ?? 'custom', $from, $to);
        } catch (\Throwable) {
            // An unreadable range means "no range", not an error: the log is still worth showing.
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        return array_values(array_filter(
            array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                (array) ($data[$key] ?? []),
            ),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key, int $max = 190): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
