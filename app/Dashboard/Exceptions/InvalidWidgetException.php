<?php

declare(strict_types=1);

namespace App\Dashboard\Exceptions;

use App\Dashboard\Contracts\DashboardWidget;
use InvalidArgumentException;

/**
 * Something was registered on the dashboard that is not a usable widget — a class that does not
 * implement `DashboardWidget`, an abstract class, or a widget whose `key()` is empty or not
 * snake_case.
 *
 * Thrown only by explicit `DashboardRegistry::register()` calls. Auto-discovery is deliberately
 * quieter: it skips a file it cannot use rather than taking the whole dashboard down, because a
 * half-written class in a working tree must not 500 the admin landing page.
 */
final class InvalidWidgetException extends InvalidArgumentException
{
    public static function notAWidget(string $class): self
    {
        return new self(sprintf(
            'Class [%s] cannot be registered as a dashboard widget: it does not implement %s.',
            $class,
            DashboardWidget::class,
        ));
    }

    public static function notInstantiable(string $class): self
    {
        return new self(sprintf(
            'Dashboard widget [%s] cannot be instantiated (abstract, an interface, or its '
            .'constructor needs arguments the container cannot resolve).',
            $class,
        ));
    }

    public static function badKey(string $class, string $key): self
    {
        return new self(sprintf(
            'Dashboard widget [%s] declares the key [%s]. A key must be snake_case, 1–64 '
            .'characters, matching /^[a-z][a-z0-9_]*$/.',
            $class,
            $key,
        ));
    }
}
