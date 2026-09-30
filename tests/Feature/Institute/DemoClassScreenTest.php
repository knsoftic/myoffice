<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\DemoClassStatus;
use App\Models\Institute\Course;
use App\Models\Institute\DemoClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The admin Demo classes screen can book a demo and act on one.
 *
 * The routes behind it always existed; the screen offered none of them, so a demo could not be booked,
 * marked, cancelled or moved from the admin panel at all. These tests read the rendered page as well
 * as the POST, because a route nobody can reach from a screen is the defect being guarded here.
 */
final class DemoClassScreenTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function the_screen_offers_booking_to_somebody_who_may_create_a_demo(): void
    {
        $booker = $this->createUserWithPermissions(['demo_classes.view_any', 'demo_classes.create']);

        $this->actingAs($booker)
            ->get(route('admin.demo-classes.index'))
            ->assertOk()
            ->assertSee('Book a demo')
            ->assertSee(route('admin.demo-classes.store'), false);
    }

    #[Test]
    public function the_screen_does_not_offer_booking_without_the_create_ability(): void
    {
        $viewer = $this->createUserWithPermissions(['demo_classes.view_any']);

        $this->actingAs($viewer)
            ->get(route('admin.demo-classes.index'))
            ->assertOk()
            ->assertDontSee('Book a demo')
            ->assertDontSee('Book the demo');
    }

    #[Test]
    public function a_demo_booked_from_the_form_is_stored_against_the_enquiry(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $inquiry = $this->inquiry(['course_id' => $course->getKey()], $actor);
        $teacher = $this->teacher(actor: $actor);

        $this->actingAs($actor)
            ->post(route('admin.demo-classes.store'), [
                '_form' => 'book',
                'subject_type' => 'inquiry',
                'subject_id' => $inquiry->getKey(),
                'course_id' => $course->getKey(),
                'teacher_id' => $teacher->getKey(),
                'delivery_mode' => 'online',
                'meeting_url' => 'https://meet.example.test/demo',
                'scheduled_on' => Carbon::tomorrow()->toDateString(),
                'start_time' => '15:00',
                'end_time' => '16:00',
            ])
            ->assertRedirect(route('admin.demo-classes.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('demo_classes', [
            'course_inquiry_id' => $inquiry->getKey(),
            'course_id' => $course->getKey(),
            'teacher_id' => $teacher->getKey(),
            'status' => DemoClassStatus::Scheduled->value,
        ]);

        // A demo booked for 15:00 is shown at 15:00. The list used to put the TIME column through the
        // instant formatter, which shifted it into the display timezone — five hours late in Karachi.
        $this->actingAs($actor)
            ->get(route('admin.demo-classes.index'))
            ->assertSee(app_clock('15:00:00').' – '.app_clock('16:00:00'))
            ->assertDontSee(app_time(Carbon::tomorrow()->setTime(15, 0)));
    }

    #[Test]
    public function a_scheduled_demo_can_be_marked_moved_and_cancelled_from_the_screen(): void
    {
        $actor = $this->createSuperAdmin();
        $course = $this->publishedCourse(actor: $actor);
        $demo = $this->bookedDemo($course, '10:00', $actor);

        $this->actingAs($actor)
            ->get(route('admin.demo-classes.index'))
            ->assertOk()
            ->assertSee(route('admin.demo-classes.status', $demo), false)
            ->assertSee(route('admin.demo-classes.reschedule', $demo), false);

        $this->actingAs($actor)
            ->post(route('admin.demo-classes.reschedule', $demo), [
                'scheduled_on' => Carbon::today()->addDays(3)->toDateString(),
                'start_time' => '11:00',
                'reason' => 'The attendee asked for a later day.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Carbon::today()->addDays(3)->toDateString(), $demo->refresh()->scheduled_on->toDateString());

        $this->actingAs($actor)
            ->post(route('admin.demo-classes.status', $demo), [
                'status' => DemoClassStatus::Cancelled->value,
                'reason' => 'The attendee chose another institute.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(DemoClassStatus::Cancelled, $demo->refresh()->status);

        $attended = $this->bookedDemo($course, '12:00', $actor);

        $this->actingAs($actor)
            ->post(route('admin.demo-classes.status', $attended), [
                'status' => DemoClassStatus::Attended->value,
                'remarks' => 'Keen; asked about fees.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(DemoClassStatus::Attended, $attended->refresh()->status);
    }

    private function bookedDemo(Course $course, string $start, User $actor): DemoClass
    {
        return $this->demoService()->schedule($this->inquiry(['course_id' => $course->getKey()], $actor), [
            'course_id' => $course->getKey(),
            'delivery_mode' => 'online',
            'scheduled_on' => Carbon::today()->toDateString(),
            'start_time' => $start,
        ], $actor);
    }
}
