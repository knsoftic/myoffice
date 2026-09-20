<?php

declare(strict_types=1);

namespace App\Console\Commands\Project;

use App\Enums\ProjectStatus;
use App\Models\Project\Project;
use App\Services\Project\ProjectProgressService;
use Illuminate\Console\Command;

/**
 * Nightly drift repair over open projects (phase-06 §6.3, §10.4).
 *
 * **A repair that finds something is a bug report, not routine maintenance.** Every interactive change
 * already recalculates inline inside its own transaction, so a row this command has to correct means
 * some path skipped the cascade — which is why each correction is logged with both figures rather than
 * fixed silently.
 */
final class RecalculateProgress extends Command
{
    protected $signature = 'projects:recalculate-progress {--limit=500}';

    protected $description = 'Recompute progress on open projects and report anything that had drifted.';

    public function handle(ProjectProgressService $progress): int
    {
        $corrected = 0;
        $checked = 0;

        Project::query()
            ->whereNotIn('status', [ProjectStatus::Completed->value, ProjectStatus::Cancelled->value])
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Project $project) use ($progress, &$corrected, &$checked): void {
                $checked++;
                $before = (string) $project->progress_percent;

                $progress->forget();

                foreach ($project->tasks()->where('depth', 0)->get() as $task) {
                    $progress->recalculateTask($task, cascade: false);
                }

                foreach ($project->milestones as $milestone) {
                    $progress->recalculateMilestone($milestone, cascade: false);
                }

                if ($progress->recalculateProject($project)) {
                    $corrected++;

                    $this->components->warn(sprintf(
                        '%s drifted: %s -> %s. Something wrote progress without cascading.',
                        $project->code,
                        $before,
                        (string) $project->refresh()->progress_percent
                    ));
                }
            });

        $this->components->info(sprintf('Checked %d project(s); corrected %d.', $checked, $corrected));

        return self::SUCCESS;
    }
}
