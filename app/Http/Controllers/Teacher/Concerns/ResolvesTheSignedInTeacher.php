<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teacher\Concerns;

use App\Models\Institute\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Which teacher is this?" — asked once, in one place (phase-14-17 §9).
 *
 * Every teacher-panel query is scoped to the `teachers` row this user *is*, never to an id in the
 * URL. A user on the teacher panel with no teacher record behind them is a 404 rather than an empty
 * page: an account that can reach the panel but owns no rows is a misconfiguration, and showing it a
 * cheerful empty state hides that.
 */
trait ResolvesTheSignedInTeacher
{
    protected function teacher(Request $request): Teacher
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        $teacher = Teacher::query()->where('user_id', $user->getKey())->first();

        if (! $teacher instanceof Teacher) {
            throw new NotFoundHttpException;
        }

        return $teacher;
    }
}
