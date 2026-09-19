<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\ResolvesContentModule;
use App\Models\User;
use Closure;

/**
 * Set or clear the reviewer of a submission (phase-04 §6.11 `AssignRequest`, §9.1.2-§9.1.3):
 *
 *   `admin.job-applications.assign`   `can:job_applications.assign`
 *   `admin.contact-inquiries.assign`  `can:contact_inquiries.assign`
 *
 * `user_id` is `nullable, exists:users,id` (null unassigns) and the user must hold the module's
 * `view_any` **or** `view` permission, checked against that user's own grants — never the actor's. `view`
 * is enough because §9.1.2 / §9.1.3 exist to hand rows to reviewers without the whole queue (a Sales
 * Executive, a hiring manager); what they then see is scoped by the model's `visibleTo()`.
 */
final class AssignRequest extends CmsFormRequest
{
    use ResolvesContentModule;

    private const MODULES = ['job_applications', 'contact_inquiries'];

    private ?User $assignee = null;

    protected function permission(): ?string
    {
        return in_array($this->contentModule(), self::MODULES, true) ? $this->contentPermission('assign') : null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['present', 'bail', 'nullable', 'integer', 'min:1', 'exists:users,id', $this->assigneeMayWork()],
        ];
    }

    /**
     * The reviewer, or null to unassign.
     */
    public function assignee(): ?User
    {
        $id = $this->validated('user_id');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->assignee ??= User::query()->find((int) $id);
    }

    private function assigneeMayWork(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $viewAny = $this->contentPermission('view_any');
            $view = $this->contentPermission('view');

            if (! is_numeric($value) || $viewAny === null || $view === null) {
                return;
            }

            $user = User::query()->find((int) $value);

            // The pickers offer active accounts only; a suspended or inactive account cannot work a queue.
            if (! $user instanceof User || ! $user->isActive() || ! ($user->can($viewAny) || $user->can($view))) {
                $fail('That person cannot see this queue. Choose someone who can view these records.');

                return;
            }

            $this->assignee = $user;
        };
    }
}
