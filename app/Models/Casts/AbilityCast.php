<?php

declare(strict_types=1);

namespace App\Models\Casts;

use App\Enums\Ability;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts `permissions.ability` to an App\Enums\Ability case — tolerantly.
 *
 * Phase-01 §3 asks for `ability` => `Ability`, but a plain enum cast would be a landmine:
 * the portal permission prefixes in App\Support\PermissionRegistry (§4) deliberately declare
 * free-form abilities (`collaborator_portal.student_fee_status`, `student_portal.dashboard`,
 * …) that are not Ability cases, and Laravel's enum cast calls `Ability::from()`, which
 * throws a ValueError on anything it does not recognise. Reading the permissions table would
 * then blow up the permissions index and the role editor.
 *
 * So: a known value comes back as the enum case (what callers expect), an unknown value comes
 * back as the raw string. Writes accept either.
 *
 * @implements CastsAttributes<Ability|string|null, Ability|string|null>
 */
final class AbilityCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Ability|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Ability) {
            return $value;
        }

        $raw = (string) $value;

        return Ability::tryFrom($raw) ?? $raw;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Ability) {
            return $value->value;
        }

        return (string) $value;
    }
}
