<?php

declare(strict_types=1);

namespace App\Models\Crm\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Relations to tables a later phase creates (phase-05 §1.3 [D-P5-1], D28).
 *
 * `Client` and `Lead` declare their later-phase relations (projects, invoices, payments, tickets, meetings,
 * referrals) as the contract asks, but **Phase 5 code never traverses them**: the data reaches CRM screens only
 * through capability contracts and `ClientPortalRegistry`. Until the owning phase ships its model, calling such a
 * relation is a programming error and throws a `LogicException` naming the phase — it never returns an invented
 * empty result that could be mistaken for a fact.
 *
 * The class names follow CLAUDE.md §2's domain folders (`app/Models/Project`, `app/Models/Finance`,
 * `app/Models/Collaborator`, `app/Models/Support`; phase-10-12 §2 names the last three for the spine). A phase
 * that ships the model under a different name updates the constant beside the relation — an additive edit.
 *
 * @mixin Model
 */
trait RelatesToLaterPhases
{
    /**
     * @return class-string<Model>
     */
    protected function laterPhaseModel(string $class, string $phase): string
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new LogicException(sprintf(
                '%s -> %s is installed by %s and is not available yet (phase-05 [D-P5-1]); reach this data through '
                .'its capability contract instead.',
                class_basename($this),
                $class,
                $phase
            ));
        }

        /** @var class-string<Model> $class */
        return $class;
    }
}
