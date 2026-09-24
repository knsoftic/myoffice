<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SearchEntityType;
use App\Search\Contracts\SearchProvider;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * The search-provider registry (phase-19-23 §6.23, [D-23-3]) — the fifth registry, same shape as
 * the other four.
 *
 * Unlike `ReportRegistry` this one does **not** auto-discover. Eleven providers is the whole set
 * §108 asks for, they are listed here explicitly, and a search provider is not the kind of thing a
 * later phase should be able to add by dropping a file in: every one of them reads a table with its
 * own isolation rule, and a provider that appeared without anybody reviewing its scope is exactly
 * the mistake this registry exists to prevent. Adding one is an edit to {@see self::PROVIDERS}, and
 * that edit is a place a reviewer will look.
 *
 * **`availableTo()` applies three gates, all of them AND.** The module must be enabled, the viewer
 * must hold the provider's permission, and the entity must be listed in
 * `reports.global_search_entities`. The third is an administrator narrowing the palette and grants
 * nobody anything — a point the settings screen's help text makes too, because a multiselect that
 * looks like an access control is one somebody will eventually use as one.
 */
final class GlobalSearchRegistry
{
    /**
     * The eleven, in declaration order. Weight decides the palette order, not this list.
     *
     * @var list<class-string<SearchProvider>>
     */
    private const PROVIDERS = [
        \App\Search\Providers\ClientSearchProvider::class,
        \App\Search\Providers\ProjectSearchProvider::class,
        \App\Search\Providers\TaskSearchProvider::class,
        \App\Search\Providers\LeadSearchProvider::class,
        \App\Search\Providers\InvoiceSearchProvider::class,
        \App\Search\Providers\StudentSearchProvider::class,
        \App\Search\Providers\TeacherSearchProvider::class,
        \App\Search\Providers\CourseSearchProvider::class,
        \App\Search\Providers\EmployeeSearchProvider::class,
        \App\Search\Providers\CollaboratorSearchProvider::class,
        \App\Search\Providers\TicketSearchProvider::class,
    ];

    /** @var array<string, SearchProvider>|null */
    private static ?array $providers = null;

    /** @var array<string, SearchProvider> */
    private static array $explicit = [];

    /**
     * Register a provider that is not one of the eleven — a package, or a test double.
     *
     * @throws InvalidArgumentException the class is not a provider
     * @throws RuntimeException a different class already owns this entity type
     */
    public static function register(SearchProvider|string $provider): void
    {
        $instance = is_string($provider) ? app($provider) : $provider;

        if (! $instance instanceof SearchProvider) {
            throw new InvalidArgumentException(sprintf(
                '[%s] is not a %s.',
                is_string($provider) ? $provider : $provider::class,
                SearchProvider::class,
            ));
        }

        $key = $instance->type()->value;
        $owner = self::$explicit[$key] ?? null;

        if ($owner !== null && $owner::class !== $instance::class) {
            throw new RuntimeException(sprintf(
                'Two providers claim the %s entity: [%s] and [%s]. One entity has one scope, and two '
                .'classes answering for it means one of the two isolation rules is being ignored.',
                $key,
                $owner::class,
                $instance::class,
            ));
        }

        self::$explicit[$key] = $instance;
        self::$providers = null;
    }

    public static function reset(): void
    {
        self::$providers = null;
        self::$explicit = [];
    }

    /**
     * Every provider, in palette order.
     *
     * @return Collection<string, SearchProvider>
     */
    public static function all(): Collection
    {
        return collect(self::resolve())->sortBy(static fn (SearchProvider $p): int => $p->weight());
    }

    public static function for(SearchEntityType $type): ?SearchProvider
    {
        return self::resolve()[$type->value] ?? null;
    }

    /**
     * The providers this person may actually search.
     *
     * A null viewer gets nothing — the palette is never anonymous, and returning everything for a
     * missing user would be the wrong default for the one caller who forgets to pass one.
     *
     * @return Collection<string, SearchProvider>
     */
    public static function availableTo(?Authenticatable $viewer): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        $enabled = self::enabledEntities();
        $gate = app(Gate::class)->forUser($viewer);

        return self::all()->filter(static function (SearchProvider $provider) use ($enabled, $gate): bool {
            if (! in_array($provider->type()->value, $enabled, true)) {
                return false;
            }

            if (! Modules::enabled($provider->module())) {
                return false;
            }

            // `view` or `view_any`: a person with only the narrow grant still searches, they just
            // see less — which is the provider's own scope's job, not this filter's.
            return $gate->allows($provider->permission())
                || $gate->allows($provider->module().'.view_any');
        });
    }

    /**
     * The entity types `reports.global_search_entities` allows.
     *
     * A missing or unreadable setting falls back to **all** of them rather than none: a palette that
     * silently searched nothing because a settings row had not been seeded would look like a broken
     * search box, and the setting is a narrowing convenience rather than a security control.
     *
     * @return list<string>
     */
    private static function enabledEntities(): array
    {
        $configured = setting('reports.global_search_entities');

        if (! is_array($configured) || $configured === []) {
            return SearchEntityType::values();
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /**
     * @return array<string, SearchProvider>
     */
    private static function resolve(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $providers = [];

        foreach (self::PROVIDERS as $class) {
            $instance = app($class);
            $providers[$instance->type()->value] = $instance;
        }

        // Explicit registrations win, so a test double can stand in for one of the eleven.
        foreach (self::$explicit as $key => $instance) {
            $providers[$key] = $instance;
        }

        return self::$providers = $providers;
    }
}
