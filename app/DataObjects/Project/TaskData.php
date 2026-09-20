<?php

declare(strict_types=1);

namespace App\DataObjects\Project;

use App\Enums\Priority;

/**
 * The writable record of a task (phase-06 §2.5, §6.1 `TaskService::create()` / `update()`).
 *
 * Absent on purpose: `status` (`changeStatus()` / `move()`), the assignment columns (`assign()`),
 * `board_position` (`move()`), `depth` (derived from `parent_task_id`, INV-P12), `progress_percent`
 * (`ProjectProgressService`, INV-P8) and every count cache (`TaskCacheService`, INV-P6).
 *
 * `$provided` lists the payload keys that were supplied, so a partial form cannot blank a field it never
 * rendered — clearing a due date has to be an explicit `due_date: null`, not an omission.
 */
final readonly class TaskData
{
    /**
     * @var array<string, string>
     */
    public const FIELDS = [
        'project_id' => 'projectId',
        'project_milestone_id' => 'projectMilestoneId',
        'parent_task_id' => 'parentTaskId',
        'title' => 'title',
        'description' => 'description',
        'priority' => 'priority',
        'start_date' => 'startDate',
        'due_date' => 'dueDate',
        'estimated_minutes' => 'estimatedMinutes',
        'is_client_visible' => 'isClientVisible',
    ];

    /**
     * @param  list<string>  $provided
     */
    public function __construct(
        public ?int $projectId = null,
        public ?int $projectMilestoneId = null,
        public ?int $parentTaskId = null,
        public ?string $title = null,
        public ?string $description = null,
        public Priority|string|null $priority = null,
        public ?string $startDate = null,
        public ?string $dueDate = null,
        public ?int $estimatedMinutes = null,
        public ?bool $isClientVisible = null,
        public array $provided = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $read = static fn (string $key): mixed => $input[$key] ?? null;
        $string = static fn (mixed $v): ?string => $v === null || $v === '' ? null : trim((string) $v);
        $int = static fn (mixed $v): ?int => $v === null || $v === '' ? null : (int) $v;

        return new self(
            projectId: $int($read('project_id')),
            projectMilestoneId: $int($read('project_milestone_id')),
            parentTaskId: $int($read('parent_task_id')),
            title: $string($read('title')),
            description: $string($read('description')),
            priority: $string($read('priority')),
            startDate: $string($read('start_date')),
            dueDate: $string($read('due_date')),
            estimatedMinutes: $int($read('estimated_minutes')),
            isClientVisible: array_key_exists('is_client_visible', $input)
                ? (bool) $input['is_client_visible']
                : null,
            provided: array_values(array_intersect(array_keys(self::FIELDS), array_keys($input))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(bool $onlyProvided = false): array
    {
        $attributes = [];

        foreach (self::FIELDS as $column => $property) {
            if ($onlyProvided && ! in_array($column, $this->provided, true)) {
                continue;
            }

            $value = $this->{$property};

            if ($value === null && ! in_array($column, $this->provided, true)) {
                continue;
            }

            $attributes[$column] = $value instanceof Priority ? $value->value : $value;
        }

        return $attributes;
    }
}
