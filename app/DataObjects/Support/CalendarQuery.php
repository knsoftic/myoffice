<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\MeetingStatus;
use Carbon\CarbonImmutable;

/**
 * The window and the filters behind one calendar screen (phase-19-23 §6.17 `calendar()`).
 *
 * **The window is bounded here rather than trusted from the query string.** A month view asks for
 * roughly six weeks; `?from=2000-01-01&to=2099-12-31` asks for a hundred years of meetings, and the
 * feed would happily build every one of them into JSON. {@see self::MAX_DAYS} caps it at a year,
 * which is more than any of the four views needs and small enough that the worst request is still a
 * request rather than an outage.
 *
 * **`view` is not decoration.** The month grid needs whole weeks either side of the month or the
 * first row starts mid-air, so the view decides how the window is padded — and the same `from` means
 * a different span depending on which screen asked.
 */
final readonly class CalendarQuery
{
    public const VIEW_MONTH = 'month';

    public const VIEW_WEEK = 'week';

    public const VIEW_DAY = 'day';

    public const VIEW_AGENDA = 'agenda';

    public const VIEWS = [self::VIEW_MONTH, self::VIEW_WEEK, self::VIEW_DAY, self::VIEW_AGENDA];

    /** A year. Beyond this the feed is not a calendar, it is an export. */
    public const MAX_DAYS = 366;

    /**
     * @param  list<MeetingStatus>  $statuses  empty means every status the viewer may see
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $view = self::VIEW_MONTH,
        public array $statuses = [],
        public ?int $branchId = null,
        public ?int $classroomId = null,
        public ?int $projectId = null,
        public ?int $batchId = null,
        public ?int $clientId = null,
        public ?int $organizerId = null,
        public ?string $search = null,
        /** Only meetings this user is in — the default on every panel but the admin one. */
        public bool $mineOnly = false,
    ) {}

    /**
     * Build from a request, padding the window to whole units and clamping it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::now()->startOfDay();

        $view = (string) ($input['view'] ?? self::VIEW_MONTH);
        $view = in_array($view, self::VIEWS, true) ? $view : self::VIEW_MONTH;

        $anchor = isset($input['date']) && trim((string) $input['date']) !== ''
            ? CarbonImmutable::parse((string) $input['date'])->startOfDay()
            : $today;

        [$from, $to] = match ($view) {
            // The grid shows the tail of the previous month and the head of the next, or its first
            // row would start on a Thursday with three empty cells.
            self::VIEW_MONTH => [
                $anchor->startOfMonth()->startOfWeek(),
                $anchor->endOfMonth()->endOfWeek()->endOfDay(),
            ],
            self::VIEW_WEEK => [$anchor->startOfWeek(), $anchor->endOfWeek()->endOfDay()],
            self::VIEW_DAY => [$anchor->startOfDay(), $anchor->endOfDay()],
            // The agenda is a forward list, not a window around today — looking back at an agenda is
            // what the list screen is for.
            self::VIEW_AGENDA => [$anchor->startOfDay(), $anchor->addDays(30)->endOfDay()],
        };

        // An explicit range overrides the view's own, which is what the export and the print view use.
        if (isset($input['from'], $input['to']) && trim((string) $input['from']) !== '' && trim((string) $input['to']) !== '') {
            $from = CarbonImmutable::parse((string) $input['from'])->startOfDay();
            $to = CarbonImmutable::parse((string) $input['to'])->endOfDay();
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->addDays(self::MAX_DAYS)->endOfDay();
        }

        $statuses = array_values(array_filter(array_map(
            static fn (mixed $value): ?MeetingStatus => $value instanceof MeetingStatus
                ? $value
                : MeetingStatus::tryFrom((string) $value),
            (array) ($input['statuses'] ?? $input['status'] ?? []),
        )));

        $search = isset($input['search']) && trim((string) $input['search']) !== ''
            ? trim((string) $input['search'])
            : null;

        return new self(
            from: $from,
            to: $to,
            view: $view,
            statuses: $statuses,
            branchId: self::id($input['branch_id'] ?? null),
            classroomId: self::id($input['classroom_id'] ?? null),
            projectId: self::id($input['project_id'] ?? null),
            batchId: self::id($input['batch_id'] ?? null),
            clientId: self::id($input['client_id'] ?? null),
            organizerId: self::id($input['organizer_id'] ?? null),
            search: $search,
            mineOnly: (bool) ($input['mine'] ?? false),
        );
    }

    /** The same window, restricted to the viewer's own meetings — what a portal controller passes. */
    public function mine(): self
    {
        return new self(
            from: $this->from,
            to: $this->to,
            view: $this->view,
            statuses: $this->statuses,
            branchId: $this->branchId,
            classroomId: $this->classroomId,
            projectId: $this->projectId,
            batchId: $this->batchId,
            clientId: $this->clientId,
            organizerId: $this->organizerId,
            search: $this->search,
            mineOnly: true,
        );
    }

    /**
     * @return list<string>
     */
    public function statusValues(): array
    {
        return array_map(static fn (MeetingStatus $status): string => $status->value, $this->statuses);
    }

    private static function id(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
