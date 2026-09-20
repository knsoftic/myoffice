<?php

declare(strict_types=1);

namespace Tests\Feature\Project;

use App\DataObjects\Project\ProjectData;
use App\DataObjects\Project\TaskData;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Crm\Client;
use App\Models\Project\Project;
use App\Models\Project\ProjectValueRevision;
use App\Models\Project\Task;
use App\Models\User;
use App\Services\Project\Exceptions\ImmutableRevisionException;
use App\Services\Project\Exceptions\ProjectRuleException;
use App\Services\Project\Exceptions\TimerAlreadyRunningException;
use App\Services\Project\MilestoneService;
use App\Services\Project\ProjectProgressService;
use App\Services\Project\ProjectService;
use App\Services\Project\ProjectValueService;
use App\Services\Project\TaskService;
use App\Services\Project\TimerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\InteractsWithRbac;
use Tests\TestCase;

/**
 * Phase 6 delivery: the screens answer, the permission behind each one bites, money is withheld rather
 * than blanked, and the invariants that make the numbers trustworthy hold through the services.
 *
 * This is the integration gate, not the phase's full acceptance suite (phase-06 §11, P6-01 … P6-55),
 * which is still owed.
 */
final class ProjectDeliveryTest extends TestCase
{
    use InteractsWithRbac;
    use RefreshDatabase;

    /** Admin screens, each with the permission its route really checks. */
    private const ADMIN_SCREENS = [
        ['admin.projects.index', 'projects.view_any'],
        ['admin.projects.create', 'projects.create'],
        ['admin.tasks.index', 'tasks.view_any'],
        ['admin.tasks.board', 'tasks.view_any'],
        ['admin.tasks.create', 'tasks.create'],
        ['admin.time.index', 'time_tracking.view'],
    ];

    #[Test]
    public function every_delivery_screen_answers_for_a_super_admin(): void
    {
        $super = $this->createSuperAdmin();

        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($super)
                ->get(route($route))
                ->assertOk(sprintf('%s did not answer for a Super Admin.', $route));
        }
    }

    #[Test]
    public function a_delivery_screen_is_refused_without_its_permission_and_opens_with_it(): void
    {
        foreach (self::ADMIN_SCREENS as [$route, $permission]) {
            $this->actingAs($this->createUserWithPermissions([]))
                ->get(route($route))
                ->assertForbidden(sprintf('%s opened without %s.', $route, $permission));

            $this->actingAs($this->createUserWithPermissions([$permission]))
                ->get(route($route))
                ->assertOk(sprintf('%s stayed shut for a holder of %s.', $route, $permission));
        }
    }

    #[Test]
    public function the_money_columns_are_absent_without_view_financial(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $project = $this->project('Margin probe', '450000.00');

        // With the permission the figure is on the page.
        $this->actingAs($this->createUserWithPermissions(['projects.view_any', 'projects.view', 'projects.view_financial']))
            ->get(route('admin.projects.index'))
            ->assertOk()
            ->assertSee('450,000', false);

        // Without it, it is not merely hidden — it never reaches the response.
        $this->actingAs($this->createUserWithPermissions(['projects.view_any', 'projects.view']))
            ->get(route('admin.projects.index'))
            ->assertOk()
            ->assertDontSee('450,000', false);
    }

    #[Test]
    public function a_project_the_viewer_cannot_reach_answers_404_not_403(): void
    {
        $super = $this->createSuperAdmin();
        $this->actingAs($super);

        $project = $this->project('Somebody elses work');

        // Holds `projects.view` but is on no project, so the row is out of reach (INV-P15).
        $this->actingAs($this->createUserWithPermissions(['projects.view']))
            ->get(route('admin.projects.show', $project))
            ->assertNotFound();
    }

    #[Test]
    public function the_opening_value_is_recorded_as_a_revision_and_cannot_be_edited_away(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Audited value', '200000.00');

        $this->assertSame(1, $project->valueRevisions()->count(), 'The opening value is revision 1.');
        $this->assertSame(1, (int) $project->value_revision_count);

        // INV-P1: the ordinary edit path refuses, and says which service owns the column.
        $this->expectException(ProjectRuleException::class);

        app(ProjectService::class)->update(
            $project,
            ProjectData::fromArray(['project_value' => '999.00']),
            $actor,
        );
    }

    #[Test]
    public function a_value_revision_is_append_only(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Immutable history', '200000.00');

        $revision = app(ProjectValueService::class)->revise(
            $project,
            ['project_value' => '260000.00'],
            'Scope grew after the workshop.',
            null,
            $actor,
        );

        $this->assertSame('60000.00', (string) $revision->delta_amount, 'The signed delta is generated.');

        try {
            $revision->delete();
            $this->fail('A value revision was deleted.');
        } catch (ImmutableRevisionException) {
            // INV-P3: the model refuses before the trigger has to.
        }

        $this->assertDatabaseHas('project_value_revisions', ['id' => $revision->getKey()]);
    }

    #[Test]
    public function a_no_op_revision_is_refused(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('No-op', '200000.00');

        $this->expectException(ProjectRuleException::class);

        app(ProjectValueService::class)->revise(
            $project,
            ['project_value' => '200000.00'],
            'Nothing actually changed.',
            null,
            $actor,
        );
    }

    #[Test]
    public function progress_is_the_weighted_average_of_the_work_below_it(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Weighted');
        $tasks = app(TaskService::class);
        $progress = app(ProjectProgressService::class);

        $milestone = app(MilestoneService::class)->create($project, ['name' => 'Phase one', 'weight' => '1.0000'], $actor);

        $done = $tasks->create(TaskData::fromArray([
            'project_id' => $project->getKey(),
            'project_milestone_id' => $milestone->getKey(),
            'title' => 'Done',
            'estimated_minutes' => 60,
        ]), $actor);

        $open = $tasks->create(TaskData::fromArray([
            'project_id' => $project->getKey(),
            'project_milestone_id' => $milestone->getKey(),
            'title' => 'Open',
            'estimated_minutes' => 180,
        ]), $actor);

        $tasks->changeStatus($done, TaskStatus::InProgress, null, $actor);
        $tasks->changeStatus($done, TaskStatus::Completed, null, $actor);

        $progress->forget();
        $progress->recalculateMilestone($milestone->refresh());

        // 100 weighted 60, 0 weighted 180 -> 25.
        $this->assertSame(0, Money::compare((string) $milestone->refresh()->progress_percent, '25'));

        // INV-P9: cancelling the open task lifts the milestone to 100, it does not leave it at 50.
        $tasks->changeStatus($open, TaskStatus::Cancelled, 'Dropped from scope.', $actor);

        $progress->forget();
        $progress->recalculateMilestone($milestone->refresh());

        $this->assertSame(0, Money::compare((string) $milestone->refresh()->progress_percent, '100'));
    }

    #[Test]
    public function progress_percent_has_exactly_one_writer(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Guarded progress');

        $this->expectException(\LogicException::class);

        $project->forceFill(['progress_percent' => '50.0000'])->save();
    }

    #[Test]
    public function only_one_timer_runs_per_worker_and_the_refusal_names_the_work(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('One clock');
        $timers = app(TimerService::class);

        $first = $timers->start($actor, null, $project, null, 'First');

        $this->assertSame('running', $first->status->value);
        // INV-P5: an open segment contributes nothing, so no stored number ticks.
        $this->assertSame(0, (int) $first->duration_seconds);

        try {
            $timers->start($actor, null, $project, null, 'Second');
            $this->fail('A second timer started for the same worker.');
        } catch (TimerAlreadyRunningException $exception) {
            $this->assertSame($first->getKey(), $exception->runningEntryId);
        }

        $timers->stop($first);

        $this->assertSame('stopped', $first->refresh()->status->value);
        $this->assertNotNull($first->ended_at);
    }

    #[Test]
    public function a_task_cannot_be_completed_while_a_subtask_is_open_and_the_error_names_it(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Subtasks');
        $tasks = app(TaskService::class);

        $parent = $tasks->create(TaskData::fromArray([
            'project_id' => $project->getKey(),
            'title' => 'Parent',
        ]), $actor);

        $tasks->create(TaskData::fromArray([
            'project_id' => $project->getKey(),
            'parent_task_id' => $parent->getKey(),
            'title' => 'Unfinished child',
        ]), $actor);

        $tasks->changeStatus($parent, TaskStatus::InProgress, null, $actor);

        try {
            $tasks->changeStatus($parent, TaskStatus::Completed, null, $actor);
            $this->fail('A parent was completed with an open subtask.');
        } catch (ProjectRuleException $exception) {
            $message = implode(' ', array_merge(...array_values($exception->errors())));
            $this->assertStringContainsString('Unfinished child', $message);
        }
    }

    #[Test]
    public function an_illegal_status_move_is_refused_with_the_allowed_list(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Transitions');

        // planning -> completed is not in the §2.13.1 table.
        $this->expectException(ProjectRuleException::class);

        app(ProjectService::class)->changeStatus($project, ProjectStatus::Completed, null, $actor);
    }

    #[Test]
    public function a_board_move_places_the_card_between_its_neighbours(): void
    {
        $actor = $this->createSuperAdmin();
        $this->actingAs($actor);

        $project = $this->project('Board');
        $tasks = app(TaskService::class);

        $a = $tasks->create(TaskData::fromArray(['project_id' => $project->getKey(), 'title' => 'A']), $actor);
        $b = $tasks->create(TaskData::fromArray(['project_id' => $project->getKey(), 'title' => 'B']), $actor);
        $c = $tasks->create(TaskData::fromArray(['project_id' => $project->getKey(), 'title' => 'C']), $actor);

        $tasks->move($c, TaskStatus::Todo, $a->getKey(), $b->getKey(), $actor);

        $position = (string) $c->refresh()->board_position;

        $this->assertGreaterThan(0, Money::compare($position, (string) $a->refresh()->board_position));
        $this->assertLessThan(0, Money::compare($position, (string) $b->refresh()->board_position));
    }

    private function project(string $name, string $value = '0.00'): Project
    {
        $client = Client::query()->first() ?? $this->client();

        return app(ProjectService::class)->create(ProjectData::fromArray([
            'name' => $name,
            'client_id' => $client->getKey(),
            'project_type' => 'fixed_price',
            'priority' => 'medium',
            'project_value' => $value,
        ]), auth()->user());
    }

    private function client(): Client
    {
        return app(\App\Services\Crm\ClientService::class)
            ->create(new \App\DataObjects\Crm\ClientData(name: 'Delivery Test Client'));
    }
}
