<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\DataObjects\Crm\ClientData;
use App\DataObjects\Finance\RecordPaymentData;
use App\DataObjects\Project\ProjectData;
use App\DataObjects\Project\TaskData;
use App\Enums\MeetingStatus;
use App\Enums\PaymentMethod;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\Project\Task;
use App\Models\Project\TaskChecklistItem;
use App\Models\Project\TimeEntry;
use App\Models\Support\Meeting;
use App\Models\User;
use App\Services\Crm\ClientService;
use App\Services\Finance\PaymentService;
use App\Services\Project\MilestoneService;
use App\Services\Project\ProjectService;
use App\Services\Project\TaskService;
use App\Services\Project\TimerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Every delivery destroy route is reachable from a screen, and only where the backend would allow it.
 *
 * For each of projects, milestones, tasks, checklist lines, time entries and meetings: a row the user may
 * remove shows the control and the DELETE works; a row they may not shows no control and a hand-made
 * DELETE is refused with a 403 (or a 404 where INV-P15 hides the row), never a 500.
 */
final class ProjectRowActionsTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->super = $this->createSuperAdmin();
        $this->actingAs($this->super);
    }

    // ---------------------------------------------------------------- projects

    #[Test]
    public function a_project_can_be_archived_from_its_row_and_its_page(): void
    {
        $project = $this->project('Archivable');
        $user = $this->createUserWithPermissions(['projects.view', 'projects.view_any', 'projects.edit', 'projects.delete']);
        $destroy = route('admin.projects.destroy', $project);

        $this->actingAs($user)->get(route('admin.projects.index'))
            ->assertOk()
            ->assertSee($this->form($destroy), false)
            ->assertSee(route('admin.projects.edit', $project), false);

        $this->actingAs($user)->get(route('admin.projects.show', $project))
            ->assertOk()
            ->assertSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)
            ->assertRedirect(route('admin.projects.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('projects', ['id' => $project->getKey()]);
    }

    #[Test]
    public function a_project_without_the_delete_permission_offers_no_archive_and_refuses_it(): void
    {
        $project = $this->project('Kept');
        $user = $this->createUserWithPermissions(['projects.view', 'projects.view_any']);
        $destroy = route('admin.projects.destroy', $project);

        $this->actingAs($user)->get(route('admin.projects.index'))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.projects.show', $project))->assertOk()->assertDontSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)->assertForbidden();
        $this->assertNotSoftDeleted('projects', ['id' => $project->getKey()]);
    }

    // -------------------------------------------------------------- milestones

    #[Test]
    public function a_milestone_without_payments_can_be_deleted_from_the_list_the_project_and_its_page(): void
    {
        $project = $this->project('Staged');
        $milestone = app(MilestoneService::class)->create($project, ['name' => 'Discovery', 'weight' => '1.0000'], $this->super);
        $user = $this->milestoneUser();
        $destroy = route('admin.milestones.destroy', $milestone);

        $this->actingAs($user)->get(route('admin.milestones.index', $project))->assertOk()->assertSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.projects.show', $project))->assertOk()->assertSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.milestones.show', $milestone))->assertOk()->assertSee($this->form($destroy), false);

        // From its own page it lands on the list, not back on the row it just removed.
        $this->actingAs($user)
            ->from(route('admin.milestones.show', $milestone))
            ->delete($destroy)
            ->assertRedirect(route('admin.milestones.index', $project))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('project_milestones', ['id' => $milestone->getKey()]);
    }

    #[Test]
    public function a_milestone_a_payment_is_booked_against_offers_no_delete_and_refuses_it(): void
    {
        $project = $this->project('Billed', '100000.00');
        $milestone = app(MilestoneService::class)->create(
            $project,
            ['name' => 'Advance', 'weight' => '1.0000', 'amount' => '25000.00'],
            $this->super,
        );

        app(PaymentService::class)->recordProjectPayment($project, new RecordPaymentData(
            amount: '25000.00',
            method: PaymentMethod::BankTransfer,
            milestoneId: (int) $milestone->getKey(),
            idempotencyKey: 'row-actions-milestone',
        ));

        $user = $this->milestoneUser();
        $destroy = route('admin.milestones.destroy', $milestone);

        $this->actingAs($user)->get(route('admin.milestones.index', $project))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.projects.show', $project))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.milestones.show', $milestone))->assertOk()->assertDontSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)->assertForbidden();

        // Super Admin skips the policy; the service still refuses, as a validation toast, not a 500.
        $this->actingAs($this->super)->get(route('admin.milestones.index', $project))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($this->super)
            ->from(route('admin.milestones.index', $project))
            ->delete($destroy)
            ->assertRedirect(route('admin.milestones.index', $project))
            ->assertSessionHasErrors('milestone');

        $this->assertNotSoftDeleted('project_milestones', ['id' => $milestone->getKey()]);
    }

    // ------------------------------------------------------------------- tasks

    #[Test]
    public function a_task_can_be_deleted_from_its_row_and_its_page(): void
    {
        $task = $this->task($this->project('Tasked'), 'Write the brief');
        $user = $this->createUserWithPermissions(['tasks.view', 'tasks.view_any', 'tasks.delete', 'projects.view_any']);
        $destroy = route('admin.tasks.destroy', $task);

        $this->actingAs($user)->get(route('admin.tasks.index'))->assertOk()->assertSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.tasks.show', $task))->assertOk()->assertSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)
            ->assertRedirect(route('admin.tasks.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('tasks', ['id' => $task->getKey()]);
    }

    #[Test]
    public function a_task_without_the_delete_permission_offers_no_delete_and_refuses_it(): void
    {
        $task = $this->task($this->project('Read only'), 'Look but do not touch');
        $user = $this->createUserWithPermissions(['tasks.view', 'tasks.view_any', 'projects.view_any']);
        $destroy = route('admin.tasks.destroy', $task);

        $this->actingAs($user)->get(route('admin.tasks.index'))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.tasks.show', $task))->assertOk()->assertDontSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)->assertForbidden();
        $this->assertNotSoftDeleted('tasks', ['id' => $task->getKey()]);
    }

    // -------------------------------------------------------- checklist lines

    #[Test]
    public function the_assignee_can_remove_a_checklist_line_from_the_task_page(): void
    {
        $assignee = $this->createUserWithPermissions(['tasks.view', 'tasks.view_any', 'tasks.edit', 'projects.view_any']);
        $task = $this->task($this->project('Checklisted'), 'With lines', $assignee);
        $item = $this->checklistLine($task, 'Draft outline');
        $destroy = route('admin.checklist.destroy', $item);

        // The tick form posts to the same path (PUT), so the control is identified by its label too.
        $this->actingAs($assignee)->get(route('admin.tasks.show', $task))->assertOk()
            ->assertSee($this->form($destroy), false)
            ->assertSee('Remove checklist line Draft outline', false);

        $this->actingAs($assignee)
            ->from(route('admin.tasks.show', $task))
            ->delete($destroy)
            ->assertRedirect(route('admin.tasks.show', $task))
            ->assertSessionHas('toast');

        $this->assertDatabaseMissing('task_checklist_items', ['id' => $item->getKey(), 'deleted_at' => null]);
    }

    #[Test]
    public function a_colleague_who_does_not_work_the_task_sees_no_remove_and_is_refused(): void
    {
        $assignee = $this->createUserWithPermissions(['tasks.view', 'tasks.view_any', 'tasks.edit', 'projects.view_any']);
        $colleague = $this->createUserWithPermissions(['tasks.view', 'tasks.view_any', 'tasks.edit', 'projects.view_any']);
        $task = $this->task($this->project('Not yours'), 'Somebody else', $assignee);
        $item = $this->checklistLine($task, 'Their line');
        $destroy = route('admin.checklist.destroy', $item);

        $this->actingAs($colleague)->get(route('admin.tasks.show', $task))->assertOk()
            ->assertDontSee($this->form($destroy), false)
            ->assertDontSee('Remove checklist line', false);

        $this->actingAs($colleague)->delete($destroy)->assertForbidden();
        $this->assertDatabaseHas('task_checklist_items', ['id' => $item->getKey()]);
    }

    // ------------------------------------------------------------ time entries

    #[Test]
    public function a_worker_can_discard_their_own_entry_with_a_reason(): void
    {
        $worker = $this->createUserWithPermissions(['time_tracking.view', 'time_tracking.create', 'time_tracking.delete', 'projects.view_any']);
        $entry = $this->timeEntry($worker, $this->project('Clocked'));
        $destroy = route('admin.time.destroy', $entry);

        $this->actingAs($worker)->get(route('admin.time.index'))->assertOk()->assertSee($this->form($destroy), false);

        // The reason is not optional, and its absence is a validation toast rather than an error page.
        $this->actingAs($worker)->from(route('admin.time.index'))->delete($destroy)
            ->assertRedirect(route('admin.time.index'))
            ->assertSessionHasErrors('discard_reason');

        $this->actingAs($worker)->from(route('admin.time.index'))
            ->delete($destroy, ['discard_reason' => 'Logged against the wrong project.'])
            ->assertRedirect(route('admin.time.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('time_entries', ['id' => $entry->getKey()]);
    }

    #[Test]
    public function an_entry_the_viewer_may_not_discard_offers_no_control_and_is_refused(): void
    {
        $worker = $this->createUserWithPermissions(['time_tracking.view', 'time_tracking.create', 'projects.view_any']);
        $entry = $this->timeEntry($worker, $this->project('Kept hours'));
        $destroy = route('admin.time.destroy', $entry);

        $this->actingAs($worker)->get(route('admin.time.index'))->assertOk()->assertDontSee($this->form($destroy), false);

        $this->actingAs($worker)
            ->delete($destroy, ['discard_reason' => 'Trying anyway.'])
            ->assertForbidden();

        $this->assertNotSoftDeleted('time_entries', ['id' => $entry->getKey()]);
    }

    // ---------------------------------------------------------------- meetings

    #[Test]
    public function a_cancelled_meeting_can_be_removed_from_its_row_and_its_page(): void
    {
        $meeting = $this->meeting(MeetingStatus::Cancelled);
        $user = $this->meetingUser();
        $destroy = route('admin.meetings.destroy', $meeting);

        $this->actingAs($user)->get(route('admin.meetings.index'))->assertOk()->assertSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.meetings.show', $meeting))->assertOk()->assertSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)
            ->assertRedirect(route('admin.meetings.index'))
            ->assertSessionHas('toast');

        $this->assertSoftDeleted('meetings', ['id' => $meeting->getKey()]);
    }

    #[Test]
    public function a_scheduled_meeting_offers_no_delete_and_refuses_it(): void
    {
        $meeting = $this->meeting(MeetingStatus::Scheduled);
        $user = $this->meetingUser();
        $destroy = route('admin.meetings.destroy', $meeting);

        $this->actingAs($user)->get(route('admin.meetings.index'))->assertOk()->assertDontSee($this->form($destroy), false);
        $this->actingAs($user)->get(route('admin.meetings.show', $meeting))->assertOk()->assertDontSee($this->form($destroy), false);

        $this->actingAs($user)->delete($destroy)->assertForbidden();
        $this->assertNotSoftDeleted('meetings', ['id' => $meeting->getKey()]);
    }

    // ================================================================= fixtures

    /** The confirm dialog's form — a destroy URL is often the same path as the show or update URL. */
    private function form(string $destroy): string
    {
        return 'action="'.$destroy.'"';
    }

    private function milestoneUser(): User
    {
        return $this->createUserWithPermissions([
            'projects.view', 'projects.view_any',
            'project_milestones.view', 'project_milestones.view_any', 'project_milestones.delete',
        ]);
    }

    private function meetingUser(): User
    {
        return $this->createUserWithPermissions(['meetings.view', 'meetings.view_any', 'meetings.delete']);
    }

    private function project(string $name, string $value = '0.00'): Project
    {
        $client = Client::query()->first()
            ?? app(ClientService::class)->create(new ClientData(name: 'Row Actions Client'));

        return app(ProjectService::class)->create(ProjectData::fromArray([
            'name' => $name,
            'client_id' => $client->getKey(),
            'project_type' => 'fixed_price',
            'priority' => 'medium',
            'project_value' => $value,
        ]), $this->super);
    }

    private function task(Project $project, string $title, ?User $assignee = null): Task
    {
        $task = app(TaskService::class)->create(TaskData::fromArray([
            'project_id' => $project->getKey(),
            'title' => $title,
        ]), $this->super);

        if ($assignee !== null) {
            $task->forceFill(['assigned_user_id' => $assignee->getKey()])->saveQuietly();
        }

        return $task->refresh();
    }

    private function checklistLine(Task $task, string $title): TaskChecklistItem
    {
        return TaskChecklistItem::query()->create([
            'task_id' => $task->getKey(),
            'title' => $title,
            'sort_order' => 1,
        ]);
    }

    private function timeEntry(User $worker, Project $project): TimeEntry
    {
        $timers = app(TimerService::class);
        $entry = $timers->start($worker, null, $project, null, 'Row actions');
        $timers->stop($entry);

        return $entry->refresh();
    }

    private function meeting(MeetingStatus $status): Meeting
    {
        $meeting = new Meeting;
        $meeting->forceFill([
            'title' => 'Row actions '.$status->value,
            'organizer_id' => $this->super->getKey(),
            'scheduled_at' => now()->addDays(3),
            'duration_minutes' => 30,
            'delivery_mode' => 'online',
            'meeting_url' => 'https://meet.example.test/row-actions',
            'status' => $status->value,
            'cancellation_reason' => $status === MeetingStatus::Scheduled ? null : 'Called off.',
        ])->save();

        return $meeting->refresh();
    }
}
