<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Enums\Ability;
use App\Enums\LeaveApprovalStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Hr\LeaveRequest;
use App\Models\User;
use App\Policies\Hr\Concerns\ChecksHrPermissions;
use Illuminate\Auth\Access\Response;

/**
 * Who may see, decide and withdraw a leave request (phase-07 §2.15, §2.17, §6.5.3, §9).
 *
 * **Nobody approves their own leave**, whatever permissions they hold. That is checked here *and* in
 * `LeaveRequestService`, deliberately: the policy stops the button from working, and the service stops
 * every other path — a console command, a future API, a queued job.
 *
 * **The reason and the attachment are private.** A leave reason is often medical. Only the employee, the
 * resolved approval chain, and holders of `leaves.view_any` may read them (§9) — a line manager elsewhere
 * in the org chart cannot, even though they can see that the person is away.
 */
final class LeaveRequestPolicy
{
    use ChecksHrPermissions;

    public const MODULE = 'leaves';

    public function viewAny(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->holds($user, self::MODULE, Ability::View);
    }

    public function view(User $user, LeaveRequest $request): bool|Response
    {
        return $this->reaches($user, self::MODULE, Ability::View, $this->reachesRequest($user, $request));
    }

    /**
     * May this user read the reason and open the attachment? Narrower than `view` on purpose (§9).
     */
    public function viewReason(User $user, LeaveRequest $request): bool
    {
        return $this->isSelf($user, $request->employee)
            || $this->holds($user, self::MODULE, Ability::ViewAny)
            || $this->isInChain($user, $request);
    }

    public function create(User $user): bool
    {
        return $this->holds($user, self::MODULE, Ability::Create);
    }

    /**
     * Editing is only ever a pending request — once a level has acted, the record of what was decided
     * must describe what was actually asked for.
     */
    public function update(User $user, LeaveRequest $request): bool|Response
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            return Response::deny('This request has already been decided; its record does not change.');
        }

        if ($this->isSelf($user, $request->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::Edit, $this->reachesRequest($user, $request));
    }

    public function delete(User $user, LeaveRequest $request): bool|Response
    {
        if ($request->approvals()->where('status', '<>', LeaveApprovalStatus::Pending)->exists()) {
            return Response::deny(
                'Somebody has already acted on this request. Cancel it instead — the decision stays on '
                .'the record.'
            );
        }

        return $this->reaches($user, self::MODULE, Ability::Delete, $this->reachesRequest($user, $request));
    }

    /**
     * Approve one level of the chain (§6.5.3).
     */
    public function approve(User $user, LeaveRequest $request): bool|Response
    {
        if ($this->isSelf($user, $request->employee)) {
            return Response::deny(
                'Nobody approves their own leave. This request needs somebody else in the chain.'
            );
        }

        if ($request->status !== LeaveRequestStatus::Pending) {
            return Response::deny('This request has already been decided.');
        }

        return $this->isInChain($user, $request) || $this->holds($user, self::MODULE, Ability::Approve);
    }

    public function reject(User $user, LeaveRequest $request): bool|Response
    {
        if ($this->isSelf($user, $request->employee)) {
            return Response::deny('Nobody decides their own leave.');
        }

        if ($request->status !== LeaveRequestStatus::Pending) {
            return Response::deny('This request has already been decided.');
        }

        return $this->isInChain($user, $request) || $this->holds($user, self::MODULE, Ability::Reject);
    }

    /**
     * Cancelling — the employee withdrawing their own, or HR doing it with `change_status`.
     */
    public function changeStatus(User $user, LeaveRequest $request): bool|Response
    {
        if ($request->status->isTerminal()) {
            return Response::deny(sprintf('This request is already %s.', $request->status->label()));
        }

        if ($this->isSelf($user, $request->employee)) {
            return true;
        }

        return $this->reaches($user, self::MODULE, Ability::ChangeStatus, $this->reachesRequest($user, $request));
    }

    public function download(User $user, LeaveRequest $request): bool|Response
    {
        if (! $this->holds($user, self::MODULE, Ability::Download) && ! $this->isSelf($user, $request->employee)) {
            return false;
        }

        return $this->viewReason($user, $request) ? true : Response::denyAsNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function reachesRequest(User $user, LeaveRequest $request): bool
    {
        return $this->seesEmployee($user, $request->employee)
            || $this->isSelf($user, $request->employee)
            || $this->isInChain($user, $request);
    }

    /**
     * Is this user a named approver on this request's chain?
     */
    private function isInChain(User $user, LeaveRequest $request): bool
    {
        return $request->approvals()
            ->where('expected_approver_user_id', $user->getKey())
            ->exists();
    }
}
