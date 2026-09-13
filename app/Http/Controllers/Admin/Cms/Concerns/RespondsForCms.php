<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms\Concerns;

use App\Models\User;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\MediaInUseException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Services\Cms\Exceptions\UnsupportedUploadException;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every phase-03 admin CMS controller shares: explicit authorization, one response shape for a
 * browser form and for the Alpine `fetch()` calls (sortable lists, toggles, uploads), and one place the
 * services' refusals become an answer the user can act on.
 *
 *   · `InvalidSectionContentException`, `UnknownSectionTypeException`, `UnsupportedUploadException`
 *     → Laravel's own 422 (JSON `errors`, or a redirect back with the errors bag);
 *   · `ContentActionNotAllowedException` — a business rule that holds even for a Super Admin (INV-7,
 *     `is_system`, a unique type, menu depth) → 422 naming the field, or 403 for a protected record;
 *   · `MediaInUseException` → 403 with the usage list (FT-38).
 *
 * The mapping lives here, not only in `bootstrap/app.php`, so a controller answers correctly whether or
 * not the global exception mapping of the handover has been applied — never a stack trace.
 */
trait RespondsForCms
{
    use AuthorizesRequests;

    /**
     * Run a service call, turning a CMS refusal into a response.
     *
     * @param  Closure(): Response  $action
     * @param  int  $refusalStatus  422 for "fix the input", 403 for "this record is protected"
     */
    protected function attempt(Request $request, Closure $action, int $refusalStatus = 422, string $field = 'action'): Response
    {
        try {
            return $action();
        } catch (InvalidSectionContentException $exception) {
            throw $exception->toValidationException();
        } catch (UnknownSectionTypeException $exception) {
            throw ValidationException::withMessages(['section_key' => [$exception->getMessage()]]);
        } catch (UnsupportedUploadException $exception) {
            throw $exception->toValidationException('file');
        } catch (ContentActionNotAllowedException $exception) {
            return $this->refuse($request, $exception->getMessage(), $refusalStatus, $field);
        } catch (MediaInUseException $exception) {
            return $this->inUse($request, $exception->getMessage(), collect($exception->usage));
        }
    }

    /**
     * A refusal: JSON with the status, or back to the form with the message as a toast (and, for a 422,
     * on the field too).
     */
    protected function refuse(Request $request, string $message, int $status = 422, string $field = 'action'): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(
                $status === 422 ? ['message' => $message, 'errors' => [$field => [$message]]] : ['message' => $message],
                $status
            );
        }

        $redirect = back()->with('toast', ['type' => 'error', 'message' => $message]);

        return $status === 422 ? $redirect->withInput()->withErrors([$field => $message]) : $redirect;
    }

    /**
     * "Still used in N places" — 403 with the list of places (§8.11, §8.13, FT-38).
     *
     * @param  Collection<array-key, mixed>  $usage
     */
    protected function inUse(Request $request, string $message, Collection $usage): Response
    {
        $usage = $usage->values()->all();

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $message, 'usage' => $usage], Response::HTTP_FORBIDDEN);
        }

        return back()
            ->with('toast', ['type' => 'error', 'message' => $message])
            ->with('cms_usage', $usage);
    }

    /**
     * A success: JSON (`message` plus any data) for a `fetch()`, otherwise a redirect with a toast.
     *
     * @param  array<string, mixed>  $data
     */
    protected function done(Request $request, string $message, ?RedirectResponse $redirect = null, array $data = [], string $type = 'success'): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(array_merge(['message' => $message], $data));
        }

        return ($redirect ?? back())->with('toast', ['type' => $type, 'message' => $message]);
    }

    /**
     * Rows per page for a list screen (`appearance.table_page_size`, clamped by the helper).
     */
    protected function perPage(): int
    {
        return function_exists('per_page') ? per_page() : 15;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, Response::HTTP_FORBIDDEN);

        return $actor;
    }

    /**
     * A LIKE pattern with the wildcards of the term itself escaped.
     */
    protected function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
    }
}
