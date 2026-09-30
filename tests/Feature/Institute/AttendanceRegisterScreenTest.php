<?php

declare(strict_types=1);

namespace Tests\Feature\Institute;

use App\Enums\StudentAttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\Feature\Institute\Concerns\BuildsRegisters;
use Tests\TestCase;

/**
 * Re-opening a register that has already been taken.
 *
 * The marking form names who took the register, through `StudentAttendance::marker`. Lazy loading is
 * off, and the roster did not load that relation — so the moment a class had any attendance, opening
 * its register was a 500, on the admin screen and on the teacher's.
 */
final class AttendanceRegisterScreenTest extends TestCase
{
    use BuildsRegisters;
    use InteractsWithRbac;
    use RefreshDatabase;

    #[Test]
    public function a_register_that_has_been_taken_reopens_and_names_who_took_it(): void
    {
        $actor = $this->createSuperAdmin(['name' => 'Register Keeper']);
        $batch = $this->runningBatch(actor: $actor);
        $enrollment = $this->seat($batch, options: ['enrolled_on' => Carbon::today()->subMonth()->toDateString()], actor: $actor);
        $session = $this->classOn($batch, Carbon::today()->subDay()->toDateString(), 'scheduled');

        $this->markOne($session, $enrollment, StudentAttendanceStatus::Present, actor: $actor);

        $this->actingAs($actor)
            ->get(route('admin.student-attendance.mark', $session))
            ->assertOk()
            ->assertSee('Marked by Register Keeper');
    }
}
