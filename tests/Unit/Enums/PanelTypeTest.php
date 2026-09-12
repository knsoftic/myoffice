<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\PanelType;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * App\Enums\PanelType (phase-01 §2, §8).
 *
 * `homeRoute()` is what the post-login redirect, the panel middleware's "send them home" branch and
 * the forced-password-change screen all resolve against. A name that does not match a registered
 * route degrades silently to a fallback, so the route names are asserted against the real router
 * rather than against a copy of the list.
 *
 * This is the one enum test that needs the application booted (for the router); it needs no
 * database, so it does not use RefreshDatabase.
 */
final class PanelTypeTest extends TestCase
{
    /**
     * @return array<string, array{0: PanelType, 1: string, 2: string, 3: string}>
     */
    public static function panelProvider(): array
    {
        return [
            'admin' => [PanelType::Admin, 'admin', 'admin.dashboard', '/admin'],
            'collaborator' => [PanelType::Collaborator, 'collaborator', 'collaborator.dashboard', '/collaborator'],
            'student' => [PanelType::Student, 'student', 'student.dashboard', '/student'],
            'teacher' => [PanelType::Teacher, 'teacher', 'teacher.dashboard', '/teacher'],
            'client' => [PanelType::Client, 'client', 'client.dashboard', '/client'],
        ];
    }

    #[Test]
    #[DataProvider('panelProvider')]
    public function home_route_names_a_registered_route(PanelType $panel, string $prefix, string $route, string $path): void
    {
        $this->assertSame($route, $panel->homeRoute());

        $this->assertTrue(
            Route::has($panel->homeRoute()),
            sprintf('%s::homeRoute() names %s, which no route file registers.', $panel->name, $route)
        );

        $this->assertSame($path, route($panel->homeRoute(), absolute: false));
    }

    #[Test]
    #[DataProvider('panelProvider')]
    public function route_prefix_matches_the_url_and_the_route_name(PanelType $panel, string $prefix, string $route, string $path): void
    {
        $this->assertSame($prefix, $panel->routePrefix());
        $this->assertSame('/'.$prefix, $path);
        $this->assertStringStartsWith($prefix.'.', $panel->homeRoute());
        $this->assertSame($prefix, $panel->value, 'The backing value is the prefix (roles.panel stores it).');
    }

    #[Test]
    public function every_case_has_a_home_route_that_exists(): void
    {
        foreach (PanelType::cases() as $panel) {
            $this->assertTrue(
                Route::has($panel->homeRoute()),
                sprintf('No route registered for %s.', $panel->homeRoute())
            );
        }

        $this->assertCount(5, PanelType::cases(), 'The system has exactly five panels.');
    }

    #[Test]
    public function the_home_routes_are_all_different(): void
    {
        $routes = array_map(static fn (PanelType $panel): string => $panel->homeRoute(), PanelType::cases());

        $this->assertSame(count($routes), count(array_unique($routes)));
    }

    #[Test]
    public function the_contracted_cases_exist_with_the_contracted_values(): void
    {
        $this->assertSame(
            ['admin', 'collaborator', 'student', 'teacher', 'client'],
            PanelType::values(),
        );
    }

    #[Test]
    public function the_staff_panel_is_the_admin_panel(): void
    {
        // Used as the fallback for a user with no usable role (User::primaryPanel()).
        $this->assertSame(PanelType::Admin, PanelType::staffPanel());
    }

    #[Test]
    public function every_case_has_a_label_and_a_colour(): void
    {
        foreach (PanelType::cases() as $panel) {
            $this->assertNotSame('', trim($panel->label()), $panel->value.' has no label.');
            $this->assertNotSame('', trim($panel->color()), $panel->value.' has no colour.');
        }

        $this->assertSame('Admin', PanelType::Admin->label());
        $this->assertSame('Collaborator', PanelType::Collaborator->label());
    }

    #[Test]
    public function options_is_a_value_to_label_map_for_select_inputs(): void
    {
        $this->assertSame(
            [
                'admin' => 'Admin',
                'collaborator' => 'Collaborator',
                'student' => 'Student',
                'teacher' => 'Teacher',
                'client' => 'Client',
            ],
            PanelType::options(),
        );
    }

    #[Test]
    public function an_unknown_stored_panel_resolves_to_null(): void
    {
        $this->assertNull(PanelType::tryFrom('supervisor'));
        $this->assertNull(PanelType::tryFrom(''));
        $this->assertSame(PanelType::Teacher, PanelType::tryFrom('teacher'));
    }
}
