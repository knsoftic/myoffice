<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\DataObjects\Crm\ClientData;
use App\DataObjects\Project\ProjectData;
use App\Models\Project\Project;
use App\Models\Project\TimeEntry;
use App\Models\Support\Meeting;
use App\Services\Core\SettingsService;
use App\Services\Crm\ClientService;
use App\Services\Project\ProjectService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * A time typed into a `datetime-local` box is the business's local time, and is stored as UTC (D61).
 *
 * Meetings, manual time entries, assignment deadlines, material windows and HR check-in corrections
 * stored the typed value as if it were UTC. Every page reads the column as UTC and shows it in
 * Asia/Karachi, so a meeting typed for 11:00 was shown at 16:00, and a time entry for the last five
 * hours was refused as "in the future".
 */
final class FormTimeZoneTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSeeded();

        app(SettingsService::class)->asSystem()->update('localization', ['timezone' => 'Asia/Karachi']);

        // 08:00 in Karachi.
        Carbon::setTestNow(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_form_time_is_read_in_the_business_timezone(): void
    {
        $this->assertSame('2026-10-15 06:00:00', Format::fromInputDateTime('2026-10-15T11:00')?->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', Format::fromInputDateTime('2026-10-15T11:00')?->getTimezone()->getName());
        $this->assertNull(Format::fromInputDateTime(''));
        $this->assertNull(Format::fromInputDateTime(null));
        $this->assertNull(Format::fromInputDateTime('not a time'));

        // An instant handed in by code keeps its instant.
        $instant = CarbonImmutable::parse('2026-10-15 06:00:00', 'UTC');
        $this->assertTrue($instant->equalTo(Format::fromInputDateTime($instant)));

        // And the round trip back into the box is the time that was typed.
        $this->assertSame('2026-10-15T11:00', Format::inputDateTime(Format::fromInputDateTime('2026-10-15T11:00')));
    }

    #[Test]
    public function a_meeting_typed_for_eleven_is_stored_in_utc_and_shown_at_eleven(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $this->post(route('admin.meetings.store'), [
            'title' => 'Kick-off',
            'scheduled_at' => '2026-10-16T11:00',
            'duration_minutes' => 45,
            'delivery_mode' => 'online',
            'meeting_url' => 'https://meet.example.test/kick-off',
        ])->assertSessionHasNoErrors();

        $meeting = Meeting::query()->where('title', 'Kick-off')->firstOrFail();
        $row = DB::table('meetings')->where('id', $meeting->getKey())->first();

        $this->assertSame('2026-10-16 06:00:00', (string) $row->scheduled_at);
        // The clash columns speak local time, like class sessions, demos and exams.
        $this->assertSame('2026-10-16', (string) $row->meeting_date);
        $this->assertSame('11:00:00', (string) $row->meeting_start_time);
        $this->assertSame('11:45:00', (string) $row->meeting_end_time);

        $this->get(route('admin.meetings.show', $meeting))
            ->assertOk()
            ->assertSee('11:00 AM')
            ->assertDontSee('04:00 PM');
    }

    #[Test]
    public function a_rescheduled_meeting_moves_to_the_local_time_typed(): void
    {
        $this->actingAs($this->createSuperAdmin());

        $this->post(route('admin.meetings.store'), [
            'title' => 'Review',
            'scheduled_at' => '2026-10-16T11:00',
            'duration_minutes' => 30,
            'delivery_mode' => 'online',
            'meeting_url' => 'https://meet.example.test/review',
        ]);
        $meeting = Meeting::query()->where('title', 'Review')->firstOrFail();

        $this->post(route('admin.meetings.reschedule', $meeting), [
            'scheduled_at' => '2026-10-17T15:30',
            'reason' => 'The client asked for the afternoon.',
        ])->assertSessionHasNoErrors();

        $moved = Meeting::query()->where('title', 'Review')->orderByDesc('id')->firstOrFail();
        $this->assertSame('2026-10-17 10:30:00', (string) DB::table('meetings')->where('id', $moved->getKey())->value('scheduled_at'));
    }

    #[Test]
    public function a_manual_time_entry_from_this_morning_is_accepted_and_dated_locally(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $project = $this->project();

        // 00:30–01:15 on the 15th in Karachi is 19:30–20:15 on the 14th in UTC; the work is the 15th's.
        $this->post(route('admin.time.store'), [
            'project_id' => $project->getKey(),
            'started_at' => '2026-10-15T00:30',
            'ended_at' => '2026-10-15T01:15',
            'description' => 'Night fix',
        ])->assertSessionHasNoErrors();

        // 06:00–07:30 in Karachi is in the past at 08:00 — it used to be refused as "in the future".
        $this->post(route('admin.time.store'), [
            'project_id' => $project->getKey(),
            'started_at' => '2026-10-15T06:00',
            'ended_at' => '2026-10-15T07:30',
            'description' => 'Wireframes',
        ])->assertSessionHasNoErrors();

        $night = TimeEntry::query()->where('description', 'Night fix')->firstOrFail();
        $morning = TimeEntry::query()->where('description', 'Wireframes')->firstOrFail();

        $this->assertSame('2026-10-14 19:30:00', (string) DB::table('time_entries')->where('id', $night->getKey())->value('started_at'));
        $this->assertSame('2026-10-15', (string) DB::table('time_entries')->where('id', $night->getKey())->value('work_date'));
        $this->assertSame('2026-10-15 01:00:00', (string) DB::table('time_entries')->where('id', $morning->getKey())->value('started_at'));
        $this->assertSame(5400, (int) $morning->duration_seconds);
    }

    #[Test]
    public function a_time_entry_that_really_is_in_the_future_is_still_refused(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $project = $this->project();

        $this->post(route('admin.time.store'), [
            'project_id' => $project->getKey(),
            'started_at' => '2026-10-15T09:00',
            'ended_at' => '2026-10-15T10:00',
            'description' => 'Later today',
        ])->assertSessionHasErrors('ended_at');

        $this->assertSame(0, TimeEntry::query()->count());
    }

    private function project(): Project
    {
        $client = app(ClientService::class)->create(new ClientData(name: 'Time Zone Client'));

        return app(ProjectService::class)->create(ProjectData::fromArray([
            'name' => 'Time Zone Project',
            'client_id' => $client->getKey(),
            'project_type' => 'fixed_price',
            'priority' => 'medium',
            'project_value' => '0.00',
        ]), auth()->user());
    }
}
