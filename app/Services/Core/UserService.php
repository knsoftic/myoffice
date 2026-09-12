<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use App\Services\Core\Concerns\WritesAuditTrail;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything that writes to a user account from the admin panel.
 *
 * The controller validates and authorizes; this class owns the side effects — avatar files,
 * role synchronisation, the audit entries for role changes, status bookkeeping, temporary
 * passwords and session revocation. Every write is one transaction (CLAUDE.md §9).
 *
 * Business rules enforced here (and not in a policy, because `Gate::before` hands Super Admin
 * an unconditional pass and these invariants must hold for them too):
 *   · nobody deletes their own account;
 *   · nobody changes their own roles;
 *   · `status` is never written by `update()` — activating, deactivating and suspending is
 *     `changeStatus()`, which demands an actor who is not the target and records a reason;
 *   · the system always keeps at least one Super Admin.
 *
 * The role and status rules are re-stated here rather than left to `UpdateUserRequest` because later
 * phases will call this class from places that have no Form Request at all — a console command, an
 * importer, a queued job. A guard that only lives in HTTP validation is not an invariant.
 */
final class UserService
{
    use WritesAuditTrail;

    /** Module slug stamped on the audit entries this service writes. */
    private const MODULE = 'users';

    /** Directory on the `public` disk holding avatars. */
    private const AVATAR_DIRECTORY = 'avatars';

    /**
     * Mass-assignable profile fields.
     *
     * `status` and `status_reason` are **not** here: they are written by `changeStatus()` and by
     * `create()` (where there is no session to end and no audit reason to demand), never by a
     * generic profile save.
     *
     * @var array<int, string>
     */
    private const PROFILE_FIELDS = ['name', 'email', 'phone', 'whatsapp', 'branch_id', 'locale', 'timezone'];

    /**
     * Every password write in the admin panel goes through the same class the account screens use,
     * so "set a password" can never again mean two different things depending on which form you
     * reached for.
     */
    public function __construct(private readonly PasswordChangeService $passwords) {}

    /**
     * Create an account and attach its roles.
     *
     * @param  array<string, mixed>  $data  validated payload from StoreUserRequest
     */
    public function create(array $data, ?UploadedFile $avatar = null): User
    {
        return DB::transaction(function () use ($data, $avatar): User {
            $user = new User;

            $user->fill($this->profileAttributes($data));

            // The only place a status arrives with the profile: a brand-new account has no open
            // session to end and nobody to be protected from.
            $status = $this->statusIn($data) ?? UserStatus::Active;

            $user->status = $status->value;
            $user->status_reason = $status === UserStatus::Active
                ? null
                : $this->clean($data['status_reason'] ?? null);

            $user->password = (string) $data['password'];
            $user->password_changed_at = now();
            $user->must_change_password = (bool) ($data['must_change_password'] ?? false);
            $user->status_changed_at = now();

            if ($avatar instanceof UploadedFile) {
                $user->avatar_path = $this->storeAvatar($avatar);
            }

            $user->save();

            $roles = $this->resolveRoles($data['roles'] ?? []);
            $user->syncRoles($roles);

            $this->auditRoleChange($user, [], $roles->pluck('name')->all());

            return $user;
        });
    }

    /**
     * Update profile fields, the avatar and the role set.
     *
     * `$actor` is required rather than inferred from the session: the self-targeting guards below
     * are the whole point of this method and a caller must not be able to skip them by forgetting
     * an optional argument.
     *
     * @param  array<string, mixed>  $data  validated payload from UpdateUserRequest
     *
     * @throws ActionNotAllowedException when the payload would change the status, or when the actor
     *                                   is editing their own role set
     */
    public function update(User $user, array $data, User $actor, ?UploadedFile $avatar = null, bool $removeAvatar = false): User
    {
        $this->assertStatusIsNotChangedHere($user, $data);
        $this->assertRolesAreNotSelfAssigned($user, $data, $actor);

        if (filled($data['password'] ?? null)) {
            // Same rule the Reset-password button already follows: an administrator does not set
            // their own password from the admin panel. /account/password confirms the current one
            // first, and setting it from here would revoke the sessions of the actor doing it.
            $this->assertNotSelf($user, $actor, ActionNotAllowedException::selfPasswordReset());
        }

        return DB::transaction(function () use ($user, $data, $avatar, $removeAvatar): User {
            $previousAvatar = (string) $user->avatar_path;

            $user->fill($this->profileAttributes($data));

            if (array_key_exists('must_change_password', $data)) {
                $user->must_change_password = (bool) $data['must_change_password'];
            }

            if ($avatar instanceof UploadedFile) {
                $user->avatar_path = $this->storeAvatar($avatar);
            } elseif ($removeAvatar) {
                $user->avatar_path = null;
            }

            $user->save();

            if ($avatar instanceof UploadedFile || $removeAvatar) {
                $this->deleteAvatarFile($previousAvatar, (string) $user->avatar_path);
            }

            if (array_key_exists('roles', $data)) {
                $before = $user->roles->pluck('name')->all();
                $roles = $this->resolveRoles($data['roles'] ?? []);

                $user->syncRoles($roles);
                $user->load('roles');

                $this->auditRoleChange($user, $before, $roles->pluck('name')->all());
            }

            // Setting someone's password from the edit form is the action an administrator reaches
            // for when an account is compromised, so it has to cut the attacker off: rotate the
            // remember token and drop the stored sessions, exactly as the account screen does.
            if (filled($data['password'] ?? null)) {
                $this->setPassword(
                    $user,
                    (string) $data['password'],
                    (bool) $user->must_change_password,
                    'Password set by an administrator on the user edit screen',
                );
            }

            return $user;
        });
    }

    /**
     * Activate / deactivate / suspend an account, recording why.
     *
     * @throws ActionNotAllowedException when the actor is the target
     */
    public function changeStatus(User $user, UserStatus $status, User $actor, ?string $reason = null): User
    {
        // `UserPolicy::changeStatus()` refuses your own row, but `Gate::before` hands Super Admin
        // an unconditional pass, so the policy never runs for them. Without this, a Super Admin
        // could suspend themselves, be signed out instantly, and — if they are the only one —
        // leave nobody able to undo it.
        $this->assertNotSelf($user, $actor, ActionNotAllowedException::selfStatusChange());

        $reason = $this->clean($reason);

        return DB::transaction(function () use ($user, $status, $reason): User {
            $user->withReason($reason ?? sprintf('Status changed to %s', $status->label()))
                ->fill([
                    'status' => $status->value,
                    // The note explains a restriction; an active account carries none.
                    'status_reason' => $status === UserStatus::Active ? null : $reason,
                    'status_changed_at' => now(),
                ])
                ->save();

            if (! $status->canLogin()) {
                $this->revokeSessions($user);
            }

            return $user;
        });
    }

    /**
     * Issue a temporary password and force a change on next sign-in.
     *
     * Returns the plain password so the controller can show it exactly once — it is never
     * stored, mailed or logged from here.
     *
     * @throws ActionNotAllowedException when the actor is the target
     */
    public function resetPassword(User $user, User $actor, ?string $reason = null): string
    {
        // Same reason as changeStatus(): a Super Admin bypasses the policy, and forcing a
        // temporary password onto your own account only closes your own sessions.
        $this->assertNotSelf($user, $actor, ActionNotAllowedException::selfPasswordReset());

        $plain = Str::password(14, true, true, true, false);
        $reason = $this->clean($reason) ?? 'Temporary password issued by an administrator';

        DB::transaction(function () use ($user, $plain, $reason): void {
            // Whoever is signed in with the old credentials is shown the door — sessions *and*
            // the "remember me" cookie, which a bare session purge used to leave working.
            $this->setPassword($user, $plain, true, $reason);
        });

        return $plain;
    }

    /**
     * Soft delete an account.
     *
     * @throws ActionNotAllowedException when the actor is the target, or the target is the
     *                                   last remaining Super Admin
     */
    public function delete(User $user, User $actor, ?string $reason = null): void
    {
        $this->assertNotSelf($user, $actor, ActionNotAllowedException::selfDeletion());

        if ($this->isLastSuperAdmin($user)) {
            throw ActionNotAllowedException::lastSuperAdmin();
        }

        $reason = $this->clean($reason) ?? 'Account deleted from the admin panel';

        DB::transaction(function () use ($user, $reason): void {
            $user->withReason($reason)->delete();

            $this->revokeSessions($user);
        });
    }

    /**
     * Would deleting this account remove the last Super Admin?
     */
    public function isLastSuperAdmin(User $user): bool
    {
        try {
            if (! $user->hasRole(User::SUPER_ADMIN_ROLE)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $remaining = User::query()
            ->whereKeyNot($user->getKey())
            ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
            ->count();

        return $remaining === 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The three self-targeting guards share this check.
     *
     * They live here rather than only in UserPolicy because `Gate::before` grants the Super Admin
     * role every ability before any policy is consulted, so a policy alone cannot protect an
     * administrator from themselves.
     *
     * @throws ActionNotAllowedException
     */
    private function assertNotSelf(User $user, User $actor, ActionNotAllowedException $exception): void
    {
        if ($actor->getKey() !== null && $actor->getKey() === $user->getKey()) {
            throw $exception;
        }
    }

    /**
     * A profile save never moves the status.
     *
     * `changeStatus()` is the only writer: it demands an actor who is not the target, records why,
     * stamps `status_changed_at` and ends the open sessions. Letting a status ride along with a
     * profile save meant `users.edit` alone could suspend someone — and could suspend *itself*,
     * locking the only Super Admin out of a system nobody else outranks.
     *
     * A payload that merely echoes the status already on the row is accepted: it changes nothing.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ActionNotAllowedException
     */
    private function assertStatusIsNotChangedHere(User $user, array $data): void
    {
        $submitted = $this->statusIn($data);

        if ($submitted === null || $submitted === $user->status) {
            return;
        }

        // Constructed directly rather than through a named factory: ActionNotAllowedException is
        // owned elsewhere in this review and must not be edited from here.
        throw new ActionNotAllowedException(
            'Account status is changed from the status control, which records who did it and why.'
        );
    }

    /**
     * Nobody edits their own role set.
     *
     * `UserPolicy::assignRoles()` says the same thing (you never outrank yourself), but
     * `Gate::before` grants the Super Admin role every ability before a policy is consulted, so the
     * rule has to exist outside the Gate as well.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ActionNotAllowedException
     */
    private function assertRolesAreNotSelfAssigned(User $user, array $data, User $actor): void
    {
        if (! array_key_exists('roles', $data)) {
            return;
        }

        if ($actor->getKey() === null || $actor->getKey() !== $user->getKey()) {
            return;
        }

        $submitted = $this->normaliseIds((array) $data['roles']);
        $current = $this->normaliseIds($user->roles->pluck('id')->all());

        if ($submitted === $current) {
            return;
        }

        throw new ActionNotAllowedException(
            'You cannot change your own roles — ask another administrator.'
        );
    }

    /**
     * The one place a password is written from the admin panel.
     *
     * `PasswordChangeService` owns the whole side-effect list — hash, `password_changed_at`, a fresh
     * `remember_token` (which invalidates every issued "remember me" cookie) and the removal of the
     * account's other stored sessions. Routing both admin paths through it is what stops the edit
     * form and the reset button from drifting apart again.
     */
    private function setPassword(User $user, string $plain, bool $mustChangePassword, string $reason): void
    {
        $user->withReason($reason);

        $this->passwords->change($user, $plain, $reason);

        // change() clears must_change_password — it is written for someone choosing their own
        // password. An administrator decides that explicitly, so their choice is restored.
        if ($mustChangePassword && ! $user->must_change_password) {
            $user->withReason($reason)->forceFill(['must_change_password' => true])->save();
        }
    }

    /**
     * The status a payload asks for, or null when it names none (or names nonsense).
     *
     * @param  array<string, mixed>  $data
     */
    private function statusIn(array $data): ?UserStatus
    {
        $status = $data['status'] ?? null;

        if ($status instanceof UserStatus) {
            return $status;
        }

        return is_string($status) ? UserStatus::tryFrom($status) : null;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function normaliseIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        sort($ids);

        return $ids;
    }

    /**
     * The mass-assignable slice of a validated payload. `password`, `roles`, `status` and the
     * avatar are handled explicitly, so they are deliberately absent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileAttributes(array $data): array
    {
        $attributes = [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        return $attributes;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return EloquentCollection<int, Role>
     */
    private function resolveRoles(array $ids): EloquentCollection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if ($ids === []) {
            /** @var EloquentCollection<int, Role> $empty */
            $empty = Role::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        /** @var EloquentCollection<int, Role> $roles */
        $roles = Role::query()->whereIn('id', $ids)->get();

        return $roles;
    }

    /**
     * Role grants are the most sensitive change an admin can make, and the pivot write fires no
     * model event — so it gets an explicit audit row with the old and the new set (phase-01 §10).
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    private function auditRoleChange(User $user, array $before, array $after): void
    {
        sort($before);
        sort($after);

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return;
        }

        $this->audit(
            $user,
            'User roles updated',
            [
                'old' => ['roles' => $before],
                'attributes' => ['roles' => $after],
                'added' => $added,
                'removed' => $removed,
            ],
            self::MODULE,
            $this->describeRoleChange($added, $removed),
        );
    }

    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function describeRoleChange(array $added, array $removed): string
    {
        $parts = [];

        if ($added !== []) {
            $parts[] = 'granted '.implode(', ', $added);
        }

        if ($removed !== []) {
            $parts[] = 'revoked '.implode(', ', $removed);
        }

        return 'Roles '.implode('; ', $parts);
    }

    /**
     * Store an avatar on the `public` disk and return its relative path.
     */
    private function storeAvatar(UploadedFile $file): ?string
    {
        $path = $file->store(self::AVATAR_DIRECTORY, 'public');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Remove the file that was replaced, never the one now in use.
     */
    private function deleteAvatarFile(string $previous, string $current): void
    {
        $previous = trim($previous);

        if ($previous === '' || $previous === trim($current)) {
            return;
        }

        // Only ever touch files this application wrote into the avatar directory.
        if (! Str::startsWith($previous, self::AVATAR_DIRECTORY.'/')) {
            return;
        }

        try {
            $disk = Storage::disk('public');

            if ($disk->exists($previous)) {
                $disk->delete($previous);
            }
        } catch (Throwable) {
            // A missing file is not a reason to fail the request.
        }
    }

    /**
     * Drop every database session belonging to this user.
     *
     * Read-only elsewhere (the session-management screen), written here so suspending or
     * deleting an account, or forcing a new password, takes effect immediately.
     */
    private function revokeSessions(User $user): void
    {
        try {
            if (config('session.driver') !== 'database') {
                return;
            }

            $table = (string) config('session.table', 'sessions');

            if ($table === '' || ! Schema::hasTable($table)) {
                return;
            }

            DB::table($table)->where('user_id', $user->getKey())->delete();
        } catch (Throwable) {
            // No session store reachable: nothing to revoke.
        }
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
