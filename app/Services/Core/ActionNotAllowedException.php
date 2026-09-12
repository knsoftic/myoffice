<?php

declare(strict_types=1);

namespace App\Services\Core;

use RuntimeException;

/**
 * A business rule — not a permission — forbids this action.
 *
 * Authorization failures belong to the policies (403). This exception covers the invariants
 * that hold even for a Super Admin, who bypasses every policy through `Gate::before`:
 * you may not delete your own account, the system must keep at least one Super Admin,
 * a protected role may not be renamed or removed, a core module may not be switched off.
 *
 * Controllers catch it and redirect back with an error toast, so the rule reads as a
 * refusal the user can understand rather than a stack trace.
 */
final class ActionNotAllowedException extends RuntimeException
{
    public static function selfDeletion(): self
    {
        return new self('You cannot delete your own account.');
    }

    public static function selfStatusChange(): self
    {
        return new self('You cannot change your own account status — ask another administrator.');
    }

    public static function selfPasswordReset(): self
    {
        return new self('Change your own password from your account settings, not from here.');
    }

    public static function lastSuperAdmin(): self
    {
        return new self('This is the last Super Admin — promote another account before deleting this one.');
    }

    public static function protectedRole(string $role): self
    {
        return new self(sprintf('"%s" is a system role and cannot be renamed or deleted.', $role));
    }

    public static function roleInUse(string $role, int $users): self
    {
        return new self(sprintf(
            '"%s" is still assigned to %d %s — reassign them before deleting the role.',
            $role,
            $users,
            $users === 1 ? 'user' : 'users',
        ));
    }

    public static function coreModule(string $module): self
    {
        return new self(sprintf('"%s" is a core module and can never be disabled.', $module));
    }

    public static function unknownPermission(string $permission): self
    {
        return new self(sprintf('The permission "%s" does not exist.', $permission));
    }
}
