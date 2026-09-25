<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\WidgetGroup;
use App\Support\DashboardRegistry;
use App\Support\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * The front-desk dashboard, and the blank page it was added to fix (**T57**).
 *
 * `Receptionist` holds 75 permissions and, before these widgets existed, could see **none** of the
 * 38 registered cards: every one of them sits behind `*.view_reports`, `*.view_financial`,
 * `login_history.view_logs`, `settings.view_any` or a module the front desk has no grant on. A role
 * can be configured perfectly and still sign in to an empty screen, because widget permissions were
 * picked per widget and never checked against the roles that would read them.
 *
 * So the load-bearing assertion here is not "the five new widgets exist" — it is
 * **`sees_a_non_empty_dashboard`**. That is the one that fails again if somebody re-gates a widget
 * on a reports permission the desk does not hold, which is exactly how the gap appeared the first
 * time.
 */
final class ReceptionDashboardTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** The five cards of the front-desk group, and the permission each is gated on. */
    private const RECEPTION_WIDGETS = [
        'reception_applications_awaiting' => 'student_applications.view_any',
        'reception_open_inquiries' => 'course_inquiries.view_any',
        'reception_demos_today' => 'demo_classes.view_any',
        'reception_fees_today' => 'student_fee_payments.view_any',
        'reception_admissions_range' => 'admissions.view_any',
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
    public function the_five_front_desk_widgets_are_discovered(): void
    {
        $keys = DashboardRegistry::keys();

        foreach (array_keys(self::RECEPTION_WIDGETS) as $key) {
            $this->assertContains(
                $key,
                $keys,
                sprintf('[%s] is not in the registry — discovery walks app/Dashboard/Widgets at any depth.', $key),
            );
        }
    }

    #[Test]
    public function every_front_desk_widget_is_gated_on_a_permission_the_receptionist_holds(): void
    {
        $user = $this->createUserWithRole('Receptionist');

        foreach (self::RECEPTION_WIDGETS as $key => $permission) {
            $widget = DashboardRegistry::find($key);

            $this->assertNotNull($widget, sprintf('[%s] is not registered.', $key));

            $this->assertSame(
                $permission,
                $widget->permission(),
                sprintf('[%s] changed the permission it is gated on.', $key),
            );

            $this->assertTrue(
                $user->can($permission),
                sprintf(
                    'Receptionist does not hold [%s], so widget [%s] would be invisible to the front desk.',
                    $permission,
                    $key,
                ),
            );
        }
    }

    /**
     * The regression this whole file exists for: T57's blank page.
     */
    #[Test]
    public function a_receptionist_sees_a_non_empty_dashboard(): void
    {
        $user = $this->createUserWithRole('Receptionist');

        $visible = DashboardRegistry::for($user);

        $this->assertNotEmpty(
            $visible,
            'The Receptionist dashboard is empty. A role with 75 permissions signing in to a blank '
            .'page is T57 happening again — check which permission each widget is gated on.',
        );
    }

    #[Test]
    public function the_front_desk_group_is_sorted_above_operations(): void
    {
        $groups = WidgetGroup::all();

        $this->assertArrayHasKey(
            WidgetGroup::FRONT_DESK,
            $groups,
            'The front-desk group is not registered, so its widgets would sort after every known group.',
        );
    }

    #[Test]
    public function every_front_desk_widget_has_its_blade_view(): void
    {
        foreach (array_keys(self::RECEPTION_WIDGETS) as $key) {
            $widget = DashboardRegistry::find($key);

            $this->assertNotNull($widget, sprintf('[%s] is not registered.', $key));

            $this->assertTrue(
                View::exists($widget->view()),
                sprintf('[%s] names view [%s], which does not exist.', $key, $widget->view()),
            );
        }
    }

    /**
     * Each card must survive an **empty** installation.
     *
     * A front desk reads this screen on its first morning, when there is no application, no
     * inquiry and no receipt in the system — and a widget that divides by a zero count or
     * dereferences a null "next demo" would 500 the whole dashboard on exactly that morning.
     */
    #[Test]
    public function every_front_desk_widget_returns_data_on_an_empty_installation(): void
    {
        $range = DateRange::month();

        foreach (array_keys(self::RECEPTION_WIDGETS) as $key) {
            $widget = DashboardRegistry::find($key);

            $this->assertNotNull($widget, sprintf('[%s] is not registered.', $key));

            $data = $widget->data($range);

            $this->assertArrayHasKey(
                'available',
                $data,
                sprintf('[%s] must report `available`, so its view can degrade rather than fail.', $key),
            );

            $this->assertTrue(
                $data['available'],
                sprintf('[%s] reported unavailable against a migrated, empty database.', $key),
            );
        }
    }
}
