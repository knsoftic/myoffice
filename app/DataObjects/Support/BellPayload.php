<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use Illuminate\Support\Collection;

/**
 * What the bell dropdown renders (phase-19-23 §6.19 `NotificationService::bell()`).
 *
 * **One query, and the count is not `$rows->count()`.** The dropdown shows the newest
 * `support.notification_bell_page_size` rows; the badge shows how many are unread, which is almost
 * always more. Deriving the badge from the page would mean a person with forty unread notifications
 * sees "10", and would see "10" no matter how many arrived.
 */
final readonly class BellPayload
{
    /**
     * @param  Collection<int, object>  $rows
     */
    public function __construct(
        public int $unread,
        public Collection $rows,
        public bool $hasMore = false,
    ) {}

    public static function empty(): self
    {
        return new self(0, new Collection, false);
    }

    /** The number the badge shows — capped, because "99+" is a badge and "1,284" is a paragraph. */
    public function badge(int $cap = 99): string
    {
        return $this->unread > $cap ? $cap.'+' : (string) $this->unread;
    }

    public function isEmpty(): bool
    {
        return $this->rows->isEmpty();
    }
}
