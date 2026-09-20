<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Collaborator\CollaboratorCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Is this referral code real?" for a public form (phase-08-09 §7.6, §9's guest row).
 *
 * **The answer is deliberately thin.** `{valid, code, collaborator_name?}` and nothing else — never an
 * id, an email, a phone number, a status, a rate or a balance. Somebody guessing codes against this
 * endpoint learns at most a partner's display name, which is the intent of §38's "the URL preselects
 * that collaborator", and even that only when the business has said so.
 *
 * **A dead code and an ineligible one answer identically.** Both are `valid: false` with the same
 * sentence. Distinguishing them would turn the endpoint into a way to find out which partners have been
 * suspended, which is nobody's business from outside.
 *
 * Throttled at 10/minute by the route, because a cheap yes/no over a guessable key space is exactly
 * what an enumeration script wants.
 */
final class ReferralController extends Controller
{
    public function __construct(
        private readonly CollaboratorCodeService $codes,
    ) {}

    public function validateCode(Request $request): JsonResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $code = $this->codes->normalizeReferralCode($input['code']);
        $collaborator = $this->codes->isWellFormed($code) ? $this->codes->resolveCode($code) : null;

        $eligible = $collaborator !== null
            && ! $collaborator->trashed()
            && $collaborator->status->isSelectableForNewReferral();

        if (! $eligible) {
            return response()->json([
                'valid' => false,
                'code' => $code,
                'message' => 'We could not find that referral code. You can leave the field empty.',
            ]);
        }

        $payload = [
            'valid' => true,
            'code' => $code,
        ];

        if ((bool) setting('collaborator.referral_public_name_visible', true)) {
            $payload['collaborator_name'] = $collaborator->displayName();
        }

        return response()->json($payload);
    }
}
