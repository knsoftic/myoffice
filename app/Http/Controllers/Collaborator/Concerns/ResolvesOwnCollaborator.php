<?php

declare(strict_types=1);

namespace App\Http\Controllers\Collaborator\Concerns;

use App\Models\Collaborator\Collaborator;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The partner row behind the signed-in user (CLAUDE.md rule 10, phase-10-12 §7.5).
 *
 * **Every query in this panel is scoped through this, never through a form field.** A hidden
 * `collaborator_id` on a request is a number the browser can change; the session is not. This is the
 * whole of the data-isolation rule as it applies here: there is one place that answers "whose money is
 * this", and it reads the session.
 *
 * A row this partner may not see is a **404, not a 403** (§7.5). Telling somebody "that payout exists
 * but is not yours" leaks that it exists, and a partner enumerating ids would learn how many payouts
 * the business makes.
 */
trait ResolvesOwnCollaborator
{
    protected function ownCollaborator(Request $request): Collaborator
    {
        $user = $request->user();

        $collaborator = $user === null
            ? null
            : Collaborator::query()->where('user_id', $user->getKey())->first();

        if ($collaborator === null) {
            // The panel middleware let them in on a role, but no partner row is linked to the account.
            // That is a provisioning gap rather than a permission problem, and there is nothing here
            // for them either way.
            throw new NotFoundHttpException('This account is not linked to a collaborator profile.');
        }

        return $collaborator;
    }

    /**
     * 404 unless the row belongs to this partner.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $row
     * @return T
     */
    protected function assertOwn(Collaborator $collaborator, $row)
    {
        if ((int) $row->getAttribute('collaborator_id') !== (int) $collaborator->getKey()) {
            throw new NotFoundHttpException('No such record.');
        }

        return $row;
    }
}
