<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `admission.open` alias of phase-14-17 §7.10.
 *
 * **Closed is a 200, not a 404.** The page exists; the institute is simply not taking admissions
 * today. A 404 would tell a visitor who was sent the link that it was never real, and they would go
 * looking for a different institute rather than come back next term. The response carries `noindex`
 * so a crawler does not cache "we are closed" as the page's permanent content.
 *
 * **Two switches, both of which mean closed.** `maintenance.admission_form_enabled` is the operational
 * one — the form is off while somebody works on it — and `institute.admission_open` is the business
 * one. Either being false closes the door; asking for both to be true is what stops somebody turning
 * the wrong one on and believing they have opened admissions.
 *
 * **Staff pass through.** A user holding `admissions.create` sees the form with an amber ribbon naming
 * the state, which is how the person who is about to open admissions checks it first. This mirrors
 * Phase 3's `site` gate, which lets a user holding `website_sections.view` through the holding page.
 */
final class EnsureAdmissionFormOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isOpen() || $this->isPreviewingStaff($request)) {
            // The view reads this to decide whether to render the ribbon; the form itself is the same
            // form either way, because a staff preview of a different form would prove nothing.
            $request->attributes->set('admission_form_preview', ! $this->isOpen());

            return $next($request);
        }

        $message = trim((string) setting('institute.admission_closed_message', ''))
            ?: 'Admissions are closed at the moment. Leave your number on the contact page and we will '
              .'tell you the day they open.';

        if ($request->isMethod('POST')) {
            // 422 rather than a redirect: the POST came from a form that was open when it rendered,
            // and the submitter deserves the reason rather than a silent bounce to a closed page.
            return response()->json([
                'message' => $message,
                'errors' => ['course_id' => [$message]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()
            ->view('site.admission.closed', ['message' => $message], Response::HTTP_OK)
            ->header('X-Robots-Tag', 'noindex');
    }

    private function isOpen(): bool
    {
        return (bool) setting('maintenance.admission_form_enabled', true)
            && (bool) setting('institute.admission_open', true);
    }

    private function isPreviewingStaff(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->can('admissions.create');
    }
}
