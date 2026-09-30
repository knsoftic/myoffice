<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsSchedules;
use Tests\TestCase;

/**
 * The "Add a timetable slot" form on the Timetable screen.
 *
 * `TimetableController::store()` asks the batch policy through `$this->authorize()`, and the controller
 * did not use `AuthorizesRequests` — so every slot added from the screen was a 500. The service tests
 * never went through the controller, which is why nothing caught it.
 */
final class TimetableScreenTest extends TestCase
{
    use BuildsSchedules;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_slot_added_from_the_screen_is_stored(): void
    {
        $actor = $this->createSuperAdmin();
        $batch = $this->batch(actor: $actor);

        $this->actingAs($actor)
            ->from(route('admin.timetable.index'))
            ->post(route('admin.timetable.store'), [
                'batch_id' => $batch->getKey(),
                'day_of_week' => 'wednesday',
                'start_time' => '14:00',
                'end_time' => '15:00',
                'effective_from' => $this->nextMonday()->toDateString(),
            ])
            ->assertRedirect(route('admin.timetable.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('timetable_entries', [
            'batch_id' => $batch->getKey(),
            'day_of_week' => 'wednesday',
        ]);
    }

    #[Test]
    public function adding_a_slot_needs_the_right_to_edit_the_batch(): void
    {
        $batch = $this->batch();

        $scheduler = $this->createUserWithPermissions([
            'timetable.view_any', 'timetable.create',
        ]);

        $this->actingAs($scheduler)
            ->post(route('admin.timetable.store'), [
                'batch_id' => $batch->getKey(),
                'day_of_week' => 'wednesday',
                'start_time' => '14:00',
                'end_time' => '15:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('timetable_entries', [
            'batch_id' => $batch->getKey(),
            'day_of_week' => 'wednesday',
        ]);
    }
}
