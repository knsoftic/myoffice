<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Support;

use App\Enums\PanelType;
use App\Enums\TicketAssignStrategy;
use App\Models\Support\TicketDepartment;
use App\Models\User;
use App\Support\SlugGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A support desk (phase-19-23 §6.16, §7.6).
 *
 * **`allowed_panels` is a whitelist of enum values, never free text.** It is what
 * `TicketService::assertMayRaise()` reads to decide whether a student may file against this desk, so
 * a value the enum does not know would be a panel that silently never matches — the desk would
 * appear configured and accept nothing.
 *
 * **`default_assignee_id` is checked against the same definition the engine uses**: somebody who
 * cannot see the queue cannot be its default assignee, because their tickets would sit in a list
 * they are not allowed to open. `TicketAssignmentService` re-checks it at assignment time as well,
 * since the person may lose the permission later — but refusing it here means the form says so at
 * the moment somebody chooses, rather than silently falling through to nobody months afterwards.
 *
 * **The two SLA minutes are nullable, and null means "no target"**, not zero. A zero target is a
 * breach the instant the ticket is raised.
 */
class StoreTicketDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof TicketDepartment
            ? $this->user()?->can('update', $department) === true
            : $this->user()?->can('create', TicketDepartment::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $id = $department instanceof TicketDepartment ? $department->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable', 'string', 'max:170', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('ticket_departments', 'slug')->ignore($id)->withoutTrashed(),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'email' => ['nullable', 'email:rfc', 'max:180'],

            'allowed_panels' => ['required', 'array', 'min:1'],
            'allowed_panels.*' => ['required', 'string', Rule::in(array_column(PanelType::cases(), 'value'))],

            'default_assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'auto_assign_strategy' => ['required', 'string', Rule::in(array_column(TicketAssignStrategy::cases(), 'value'))],

            // Null is "no target". A number is minutes, and a day and a half is the longest first
            // response anybody would call a target at all.
            'sla_first_response_minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
            'sla_resolution_minutes' => ['nullable', 'integer', 'min:15', 'max:129600'],

            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assigneeId = $this->input('default_assignee_id');

            if ($assigneeId !== null && $assigneeId !== '') {
                $user = User::query()->find((int) $assigneeId);

                if ($user !== null && ! $user->can('support_tickets.view_any')) {
                    $validator->errors()->add(
                        'default_assignee_id',
                        $user->getAttribute('name').' cannot see the ticket queue, so tickets assigned to them would sit in a list they cannot open.',
                    );
                }
            }

            $first = $this->input('sla_first_response_minutes');
            $resolution = $this->input('sla_resolution_minutes');

            if ($first !== null && $first !== '' && $resolution !== null && $resolution !== ''
                && (int) $resolution < (int) $first) {
                // A resolution target inside the response target is a desk that is late before
                // anybody has replied, and every ticket on it breaches.
                $validator->errors()->add(
                    'sla_resolution_minutes',
                    'A resolution target has to be at least as long as the first-response target.',
                );
            }
        });
    }

    /**
     * The columns, with the slug derived when the form left it blank.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $validated = $this->validated();

        $slug = trim((string) ($validated['slug'] ?? ''));

        if ($slug === '') {
            // The generator is asked for a *unique* one, ignoring the row being edited — two desks
            // called "Billing" are ordinary and `uq` on the slug would otherwise refuse the second.
            $department = $this->route('department');

            $slug = SlugGenerator::make(
                (string) $validated['name'],
                'ticket_departments',
                $department instanceof TicketDepartment ? (int) $department->getKey() : null,
                'slug',
                170,
            );
        }

        return [
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'email' => $validated['email'] ?? null,
            'allowed_panels' => array_values(array_unique($validated['allowed_panels'])),
            'default_assignee_id' => ($validated['default_assignee_id'] ?? null) ?: null,
            'auto_assign_strategy' => $validated['auto_assign_strategy'],
            'sla_first_response_minutes' => ($validated['sla_first_response_minutes'] ?? null) ?: null,
            'sla_resolution_minutes' => ($validated['sla_resolution_minutes'] ?? null) ?: null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }
}
