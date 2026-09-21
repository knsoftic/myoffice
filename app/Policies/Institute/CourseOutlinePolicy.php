<?php

declare(strict_types=1);

namespace App\Policies\Institute;

use App\Enums\Ability;
use App\Models\Institute\CourseLecture;
use App\Models\Institute\CourseModule;
use App\Models\Institute\CourseTopic;
use App\Models\User;
use App\Policies\Institute\Concerns\ChecksInstitutePermissions;
use Illuminate\Database\Eloquent\Model;

/**
 * One policy for all five levels of the outline (§65, phase-14-17 §4.2).
 *
 * Five near-identical policies would be five places to forget the same rule. The tree is one thing with
 * one permission — `course_outline` — and the only question that varies by level is whether the node
 * has been taught from yet.
 *
 * **INV-I13 lives in {@see delete()}.** A module, topic or lecture that any coverage, progress or
 * session row points at is history: deleting it would move every affected percentage without anybody
 * deciding to, so the delete is refused and the screen offers Deactivate. A resource and a blueprint
 * are leaves nothing measures against, so they carry no such guard.
 *
 * Registered for all five models in `AppServiceProvider`, because `App\Models\Institute\CourseModule`
 * → `App\Policies\Institute\CourseModulePolicy` is the discovery path and none of these follow it.
 */
final class CourseOutlinePolicy
{
    use ChecksInstitutePermissions;

    public const MODULE = 'course_outline';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny);
    }

    public function view(User $user, Model $node): bool
    {
        return $this->holds($user, self::MODULE, Ability::View);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    public function update(User $user, Model $node): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit) && ! $this->isTrashed($node);
    }

    /**
     * Reordering and moving are both editing — §4 invents no new ability for either.
     */
    public function reorder(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Edit);
    }

    /**
     * Refused while anything downstream points at it (INV-I13).
     */
    public function delete(User $user, Model $node): bool
    {
        if (! $this->holds($user, self::MODULE, Ability::Delete)) {
            return false;
        }

        return ! $this->isReferenced($node);
    }

    public function restore(User $user, Model $node): bool
    {
        return $this->holds($user, self::MODULE, Ability::Restore);
    }

    /**
     * Switching a node off is deliberately its own right: it is the *alternative* to deleting one, and
     * somebody trusted to reword a topic is not automatically trusted to take it out of every
     * student's denominator.
     */
    public function changeStatus(User $user, Model $node): bool
    {
        return $this->holds($user, self::MODULE, Ability::ChangeStatus);
    }

    public function upload(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Upload);
    }

    public function download(User $user, Model $node): bool
    {
        return $this->holds($user, self::MODULE, Ability::Download);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function isReferenced(Model $node): bool
    {
        if ($node instanceof CourseTopic) {
            return $node->isReferenced();
        }

        if ($node instanceof CourseModule) {
            foreach ($node->topics as $topic) {
                if ($topic->isReferenced()) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof CourseLecture) {
            if (! app('db')->getSchemaBuilder()->hasTable('class_sessions')) {
                return false;
            }

            return app('db')->table('class_sessions')
                ->where('course_lecture_id', $node->getKey())
                ->exists();
        }

        // A resource and a blueprint are leaves: nothing downstream measures against them, so removing
        // one moves no percentage and needs no guard.
        return false;
    }
}
