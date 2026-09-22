<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who a piece of material was shared with (phase-19-23 §3.1, §2.4).
 *
 * **[D-19-2] gives the target table three real foreign keys rather than a polymorphic pair**, because
 * a `target_type` + `target_id` cannot be constrained and §109 asks for real keys. `column()` and
 * `modelClass()` are the map between this enum and those three columns — written once here instead of
 * as a `match` inside every service that touches a target.
 *
 * `breadth()` is what `course_materials.audience_scope` reads. A material aimed at one batch and one
 * student is scoped `batch`, because the broadest live target is what the index badge should say. It is
 * an integer rather than the order the cases happen to be declared in: "broadest" is a question about
 * how many people can see it, not about how somebody listed them.
 */
enum MaterialTargetType: string
{
    use HasOptions;

    /** Everyone enrolled on the course, in any batch. */
    case Course = 'course';

    /** One batch of it. */
    case Batch = 'batch';

    /** One student. */
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Course => 'The whole course',
            self::Batch => 'One batch',
            self::Student => 'One student',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Course => 'brand',
            self::Batch => 'sky',
            self::Student => 'violet',
        };
    }

    /** The one nullable foreign key this target type fills (§2.4's `chk_cmt_one`). */
    public function column(): string
    {
        return match ($this) {
            self::Course => 'target_course_id',
            self::Batch => 'target_batch_id',
            self::Student => 'target_student_id',
        };
    }

    /**
     * How wide the audience is. Bigger is broader, and `audience_scope` takes the maximum across a
     * material's live targets.
     */
    public function breadth(): int
    {
        return match ($this) {
            self::Course => 3,
            self::Batch => 2,
            self::Student => 1,
        };
    }

    /**
     * Returned as a string rather than a `::class` reference, so a rename breaks the one line that maps
     * these three rather than being silently resolved somewhere else.
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Course => 'App\Models\Institute\Course',
            self::Batch => 'App\Models\Institute\Batch',
            self::Student => 'App\Models\Institute\Student',
        };
    }

    /**
     * The broadest of a set of target types — `audience_scope` in one call.
     *
     * @param  iterable<self>  $types
     */
    public static function broadest(iterable $types): ?self
    {
        $widest = null;

        foreach ($types as $type) {
            if ($widest === null || $type->breadth() > $widest->breadth()) {
                $widest = $type;
            }
        }

        return $widest;
    }
}
