<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\Contracts\DashboardWidget;
use App\Dashboard\Exceptions\DuplicateWidgetKeyException;
use App\Dashboard\Exceptions\InvalidWidgetException;
use App\Dashboard\Widget;
use App\Support\DashboardRegistry;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * phase-02 §6 "Widget keys" (F-8.3): every registered key is unique across the whole registry, and
 * registering a second class under an existing key throws `DuplicateWidgetKeyException` — no card can
 * silently replace another. Plus the ownership rule of §3: the ten Phase 2 keys are Phase 2's, and the
 * keys other phases own are not registered here.
 */
final class DashboardWidgetRegistryTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private const PHASE2_KEYS = [
        'users_by_status', 'roles_overview', 'modules_enabled', 'logins_today', 'failed_logins',
        'login_trend_chart', 'recent_activity', 'recent_logins', 'system_health', 'storage_usage',
    ];

    /** Keys phase-02 §3 says belong to other phases and must not be registered by Phase 2. */
    private const FOREIGN_KEYS = [
        'fee_collected_today', 'pending_fees', 'overdue_fees',
        'website_content', 'seo_health',
        'backup_status', 'integrity_status', 'failed_jobs', 'queue_health',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();
        DashboardRegistry::reset();
        DashboardRegistry::flushCache();
    }

    protected function tearDown(): void
    {
        DashboardRegistry::reset();
        DashboardRegistry::flushCache();

        parent::tearDown();
    }

    #[Test]
    public function every_registered_key_is_unique_across_the_whole_registry(): void
    {
        $keys = DashboardRegistry::keys();

        $this->assertNotSame([], $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertCount(count($keys), DashboardRegistry::owners(), 'One owning class per key.');
        $this->assertSame(count(DashboardRegistry::owners()), count(array_unique(DashboardRegistry::owners())), 'One key per owning class.');

        foreach (DashboardRegistry::all() as $key => $widget) {
            $this->assertSame($key, $widget->key());
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $key);
        }
    }

    #[Test]
    public function the_ten_phase_two_keys_are_registered_and_the_foreign_keys_are_not(): void
    {
        $keys = DashboardRegistry::keys();

        foreach (self::PHASE2_KEYS as $key) {
            $this->assertContains($key, $keys, $key.' is a Phase 2 key for the life of the system.');
            $this->assertStringStartsWith('App\\Dashboard\\Widgets\\', DashboardRegistry::owners()[$key]);
        }

        foreach (self::FOREIGN_KEYS as $key) {
            $this->assertNotContains($key, $keys, $key.' belongs to another phase and must not be registered by Phase 2.');
        }
    }

    #[Test]
    public function registering_a_second_class_under_a_discovered_key_throws(): void
    {
        $impostor = new class extends Widget
        {
            public function key(): string
            {
                return 'users_by_status';
            }

            public function title(): string
            {
                return 'Impostor';
            }

            public function data(DateRange $range): array
            {
                return ['impostor' => true];
            }
        };

        try {
            DashboardRegistry::register($impostor);
            $this->fail('A second class claimed users_by_status and the registry accepted it.');
        } catch (DuplicateWidgetKeyException $exception) {
            $this->assertStringContainsString('users_by_status', $exception->getMessage());
        }

        $this->assertStringStartsWith('App\\Dashboard\\Widgets\\', DashboardRegistry::owners()['users_by_status'], 'The original card still owns its key.');
        $this->assertNotInstanceOf($impostor::class, DashboardRegistry::find('users_by_status'));
    }

    #[Test]
    public function two_explicit_registrations_of_different_classes_under_one_key_throw(): void
    {
        DashboardRegistry::withoutDiscovery();

        DashboardRegistry::register($this->probe('phase2_duplicate_probe', 'First'));

        $this->expectException(DuplicateWidgetKeyException::class);

        DashboardRegistry::register($this->otherProbe('phase2_duplicate_probe'));
    }

    #[Test]
    public function registering_the_same_class_twice_is_a_no_op(): void
    {
        DashboardRegistry::withoutDiscovery();

        $widget = $this->probe('phase2_same_class_probe', 'Same');

        DashboardRegistry::register($widget);
        DashboardRegistry::register($widget);

        $this->assertSame(['phase2_same_class_probe'], DashboardRegistry::keys());
    }

    #[Test]
    public function a_malformed_key_is_refused(): void
    {
        foreach (['Has-Capitals', '9starts_with_digit', 'has space', ''] as $key) {
            try {
                DashboardRegistry::register($this->probe($key, 'Bad key'));
                $this->fail(sprintf('The registry accepted the key %s.', var_export($key, true)));
            } catch (InvalidWidgetException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function probe(string $key, string $title): DashboardWidget
    {
        return new class($key, $title) extends Widget
        {
            public function __construct(private readonly string $probeKey, private readonly string $probeTitle) {}

            public function key(): string
            {
                return $this->probeKey;
            }

            public function title(): string
            {
                return $this->probeTitle;
            }

            public function data(DateRange $range): array
            {
                return [];
            }
        };
    }

    private function otherProbe(string $key): DashboardWidget
    {
        return new class($key) extends Widget
        {
            public function __construct(private readonly string $probeKey) {}

            public function key(): string
            {
                return $this->probeKey;
            }

            public function title(): string
            {
                return 'Second';
            }

            public function data(DateRange $range): array
            {
                return [];
            }
        };
    }
}
