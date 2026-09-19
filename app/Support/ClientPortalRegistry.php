<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Portal\ClientPortalSection;
use App\Models\Crm\Client;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;
use Throwable;

/**
 * The client panel's section registry (phase-05 §6.9, [D-P5-1], D28, D31, E11).
 *
 * Phase 5 owns `routes/client.php` and every `client.*` route name; later phases never redeclare a name — they
 * `register()` a `ClientPortalSection` from their own service provider, and Phase 5's routes resolve to it:
 *
 *   app(ClientPortalRegistry::class)->register(new ProjectsPortalSection(...));
 *
 * `visibleTo()` filters exactly like `Sidebar` and `DashboardRegistry`: the section's module must be enabled
 * **and** the user must hold its permission. The panel nav is built from it, and a controller asks
 * `visibleSection()` for its key and `abort(404)`s on null — so an unregistered section, or one whose module is
 * disabled, has neither a nav item nor a reachable route (test 80).
 *
 * `#[Singleton]`: every resolution shares the registered sections, even before a provider binds the class.
 */
#[Singleton]
final class ClientPortalRegistry
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var array<string, ClientPortalSection> */
    private array $sections = [];

    /**
     * Register (or replace, for the same class) a section. Two different classes claiming one key is a defect
     * and throws, instead of one silently replacing the other.
     */
    public function register(ClientPortalSection $section): void
    {
        $key = $section->key();

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Client portal section [%s] declares an invalid key "%s".', $section::class, $key));
        }

        $existing = $this->sections[$key] ?? null;

        if ($existing !== null && $existing::class !== $section::class) {
            throw new InvalidArgumentException(sprintf(
                'Client portal section key "%s" is already owned by [%s]; [%s] may not redeclare it.',
                $key,
                $existing::class,
                $section::class,
            ));
        }

        $this->sections[$key] = $section;
    }

    /**
     * Every registered section, in `sort()` order.
     *
     * @return list<ClientPortalSection>
     */
    public function all(): array
    {
        $sections = array_values($this->sections);

        usort($sections, static fn (ClientPortalSection $a, ClientPortalSection $b): int => [$a->sort(), $a->key()] <=> [$b->sort(), $b->key()]);

        return $sections;
    }

    public function section(string $key): ?ClientPortalSection
    {
        return $this->sections[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->sections[$key]);
    }

    /**
     * The sections this user may see for this client: registered, module enabled, permission held.
     *
     * @return list<ClientPortalSection>
     */
    public function visibleTo(User $user, Client $client): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (ClientPortalSection $section): bool => $this->allows($user, $section),
        ));
    }

    /**
     * The section behind a route, or null when it must 404 (unregistered, module off, permission missing).
     */
    public function visibleSection(User $user, string $key): ?ClientPortalSection
    {
        $section = $this->section($key);

        return $section !== null && $this->allows($user, $section) ? $section : null;
    }

    public function allows(User $user, ClientPortalSection $section): bool
    {
        try {
            $module = $section->module();

            if ($module !== null && $module !== '' && ! Modules::enabled($module)) {
                return false;
            }

            return $user->can($section->permission());
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Drop one section — for tests that prove the unregistered path.
     */
    public function forget(string $key): void
    {
        unset($this->sections[$key]);
    }

    /**
     * Drop every section — for tests.
     */
    public function reset(): void
    {
        $this->sections = [];
    }
}
