<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student\Concerns;

use App\Models\Institute\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Which student is this?" — asked once, in one place (phase-14-17 §9).
 *
 * Every student-panel query begins from the `students` row this user *is*. Nothing on this panel
 * takes a student id from the URL, so there is no id to guess; a row that is not theirs is a 404,
 * because a 403 would confirm that it exists.
 */
trait ResolvesTheSignedInStudent
{
    protected function student(Request $request): Student
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        $student = Student::query()->where('user_id', $user->getKey())->first();

        if (! $student instanceof Student) {
            throw new NotFoundHttpException;
        }

        return $student;
    }
}
