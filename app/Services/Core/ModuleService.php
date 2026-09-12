<?php

declare(strict_types=1);

namespace App\Services\Core;

use App\Models\Module;
use App\Services\Core\Concerns\WritesAuditTrail;
use App\Support\Modules;
use Illuminate\Support\Facades\DB;

/**
 * Enabling and disabling a module (phase-01 §1.3, decision D5).
 *
 * Flipping the switch changes exactly one boolean. It never touches the module's data: a
 * disabled module's routes 403, its sidebar entries disappear and `Gate::before` denies every
 * one of its permissions — including for Super Admin — but every row it owns stays untouched
 * and comes back intact when it is switched on again.
 *
 * Core modules are structural and can never be disabled; the registry decides what is core,
 * not the stored row.
 */
final class ModuleService
{
    use WritesAuditTrail;

    private const MODULE = 'modules';

    /**
     * Set the module's state. Returns the refreshed row.
     *
     * @throws ActionNotAllowedException when the module is core and the caller wants it off
     */
    public function setEnabled(Module $module, bool $enabled, ?string $reason = null): Module
    {
        if (! $enabled && ! $module->canBeDisabled()) {
            throw ActionNotAllowedException::coreModule((string) $module->name);
        }

        $reason = $this->clean($reason) ?? sprintf(
            'Module %s %s from the admin panel',
            (string) $module->slug,
            $enabled ? 'enabled' : 'disabled',
        );

        if ((bool) $module->is_enabled === $enabled) {
            // Nothing moved: no write, no audit row, no cache churn.
            return $module;
        }

        DB::transaction(function () use ($module, $enabled, $reason): void {
            // Only `is_enabled` is written — the settings json and every related table are
            // deliberately left alone, which is what makes disabling reversible.
            $module->withReason($reason)->fill(['is_enabled' => $enabled])->save();
        });

        // Module::booted() flushes on save; doing it again after the commit guarantees that no
        // request can read a stale gate map from inside the transaction window.
        Modules::flushCache();

        $this->audit(
            $module,
            $enabled ? 'Module enabled' : 'Module disabled',
            [
                'old' => ['is_enabled' => ! $enabled],
                'attributes' => ['is_enabled' => $enabled],
                'slug' => (string) $module->slug,
            ],
            self::MODULE,
            $reason,
        );

        return $module->refresh();
    }

    /**
     * Flip the module to the opposite of its current state.
     */
    public function toggle(Module $module, ?string $reason = null): Module
    {
        return $this->setEnabled($module, ! (bool) $module->is_enabled, $reason);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
