<?php

declare(strict_types=1);

namespace App\Services\Support\Exceptions;

use App\Support\NotificationRegistry;
use InvalidArgumentException;

/**
 * A dispatch named an event the registry does not have (phase-19-23 §6.19, INV-22-7).
 *
 * **This is the one failure in `NotificationService` that throws**, and it throws because the
 * alternative is silence. Every other problem — the module off, nobody to tell, a broken deep link —
 * is a real situation that a running installation meets and recovers from. A key that is not in the
 * registry is a typo, and a typo that returns quietly produces a notification nobody ever receives
 * and nobody ever misses. It fails in development, at the moment somebody writes it.
 *
 * **The message names the near misses.** `meeting.invite` against `meeting.invited` is the whole of
 * the bug, and reading it beside the list is faster than reading the list.
 */
final class InvalidNotificationEvent extends InvalidArgumentException
{
    public static function key(string $key): self
    {
        $suggestions = self::near($key);

        return new self(sprintf(
            'No notification event is registered under "%s".%s Declare it in App\Support\NotificationRegistry — the registry is the only place an event key exists.',
            $key,
            $suggestions === [] ? '' : ' Did you mean '.implode(' or ', array_map(static fn (string $k): string => '"'.$k.'"', $suggestions)).'?',
        ));
    }

    /**
     * @return list<string>
     */
    private static function near(string $key): array
    {
        $candidates = [];

        foreach (NotificationRegistry::keys() as $known) {
            $distance = levenshtein($key, $known);

            if ($distance <= 3) {
                $candidates[$known] = $distance;
            }
        }

        asort($candidates);

        return array_slice(array_keys($candidates), 0, 2);
    }
}
