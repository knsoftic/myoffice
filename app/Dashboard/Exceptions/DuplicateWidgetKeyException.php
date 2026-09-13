<?php

declare(strict_types=1);

namespace App\Dashboard\Exceptions;

use RuntimeException;

/**
 * Two widget classes claimed the same `key()` (phase-02 §3, F-8.3).
 *
 * A dashboard key is owned by exactly one phase for the life of the system. Silently letting the
 * second registration win would make a card another phase shipped disappear with no error
 * anywhere, so `DashboardRegistry` fails loudly on the first request instead.
 */
final class DuplicateWidgetKeyException extends RuntimeException
{
    /**
     * @param  class-string  $incoming  the class that tried to claim the key
     * @param  class-string  $existing  the class that already owns it
     */
    public static function for(string $key, string $incoming, string $existing): self
    {
        return new self(sprintf(
            'Dashboard widget key [%s] is already registered by [%s]; [%s] cannot claim it. '
            .'One widget key belongs to one owning phase — give the new widget its own key.',
            $key,
            $existing,
            $incoming,
        ));
    }
}
