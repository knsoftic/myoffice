<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\Concerns;

use App\Contracts\Portal\ClientPortalSection;
use App\Http\Requests\ClientPortal\ClientPortalListRequest;
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\User;
use App\Support\ClientContext;
use App\Support\ClientPortalRegistry;
use App\Support\Exceptions\NoClientContextException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every client-panel controller shares (phase-05 §6.9, §8.10, §9.2, [D-P5-1], D28, D31).
 *
 * **The client comes from the session, never the request.** `client()` asks `ClientContext`, which `client.context`
 * has already resolved for this request; no controller reads a client id, a user id or a project owner from input
 * (CLAUDE.md rule 10).
 *
 * **Sections.** Every screen whose data a later phase owns is a `ClientPortalSection` in `ClientPortalRegistry`.
 * `section($key)` answers **404** when the section is unregistered, its module is disabled or the user lacks its
 * `client_portal.*` permission — exactly the condition under which the nav item is absent (test 80). A list delegates
 * to the section's `paginate(Client, filters)`, which selects the client's rows only and omits every withheld column.
 *
 * **Detail and download screens.** `ClientPortalSection` publishes a list, not a record. A detail route
 * (`client.projects.show`, `client.invoices.show`, `client.tickets.show`, `client.messages.show`) or a download route
 * (`client.files.download`, `client.invoices.pdf`) serves a record only when the owning phase's section also
 * implements the optional record capability: `find(Client, User, int $id): ?Model` plus `detailView(): string`, and
 * `download(Client, User, int $id, string $variant): Response`. Without it — or when `find()` returns null because the
 * id belongs to another client — the answer is **404** (test 71); it is never a 403 that confirms the id exists.
 */
trait ServesClientPortal
{
    use AuthorizesRequests;

    protected function portalUser(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        return $user;
    }

    /**
     * The signed-in user's client. `client.context` refuses the request earlier; this is the guard for a route wired
     * without it.
     */
    protected function client(): Client
    {
        try {
            return app(ClientContext::class)->client();
        } catch (NoClientContextException $exception) {
            abort(Response::HTTP_FORBIDDEN, $exception->getMessage());
        }
    }

    protected function contact(): ?ClientContact
    {
        try {
            return app(ClientContext::class)->contact();
        } catch (NoClientContextException) {
            return null;
        }
    }

    /**
     * The registered, enabled and permitted section behind a route — or a 404.
     */
    protected function section(Request $request, string $key): ClientPortalSection
    {
        $section = app(ClientPortalRegistry::class)->visibleSection($this->portalUser($request), $key);

        abort_unless($section instanceof ClientPortalSection, Response::HTTP_NOT_FOUND);

        return $section;
    }

    /**
     * A section's list for this client.
     *
     * @param  array<string, mixed>  $scope  filters fixed by the route (a project id), merged over the query filters
     * @param  array<string, mixed>  $data  extra view data
     */
    protected function sectionList(ClientPortalListRequest $request, string $key, array $scope = [], array $data = []): View
    {
        $section = $this->section($request, $key);
        $client = $this->client();
        $filters = array_merge($request->filters(), $scope, ['per_page' => per_page()]);

        $items = $section->paginate($client, $filters);

        if ($items instanceof AbstractPaginator) {
            $items->withQueryString();
        }

        return view($section->view(), array_merge($this->portalViewData($request, $client), [
            'section' => $section,
            'items' => $items,
            'filters' => $request->filters(),
        ], $scope, $data));
    }

    /**
     * One record of a section that implements the record capability, scoped to this client — or a 404.
     */
    protected function sectionRecord(Request $request, string $key, mixed $id): Model
    {
        $section = $this->section($request, $key);
        $recordId = $this->routeId($id);
        $record = null;

        if (method_exists($section, 'find')) {
            $record = $section->find($this->client(), $this->portalUser($request), $recordId);
        }

        abort_unless($record instanceof Model, Response::HTTP_NOT_FOUND);

        return $record;
    }

    /**
     * The detail screen of a section record.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sectionDetail(Request $request, string $key, mixed $id, array $data = []): View
    {
        $section = $this->section($request, $key);
        $record = $this->sectionRecord($request, $key, $id);

        abort_unless(method_exists($section, 'detailView'), Response::HTTP_NOT_FOUND);

        return view((string) $section->detailView(), array_merge($this->portalViewData($request, $this->client()), [
            'section' => $section,
            'record' => $record,
        ], $data));
    }

    /**
     * A file of a section record, streamed by the owning section after it re-checked ownership — or a 404.
     */
    protected function sectionDownload(Request $request, string $key, mixed $id, string $variant = 'file'): Response
    {
        $section = $this->section($request, $key);

        abort_unless(method_exists($section, 'download'), Response::HTTP_NOT_FOUND);

        $response = $section->download($this->client(), $this->portalUser($request), $this->routeId($id), $variant);

        abort_unless($response instanceof Response, Response::HTTP_NOT_FOUND);

        return $response;
    }

    /**
     * What every panel screen renders: the client's own company name in the header, so the scope is visible, and the
     * nav built from the sections this user may see.
     *
     * @return array<string, mixed>
     */
    protected function portalViewData(Request $request, Client $client): array
    {
        return [
            'client' => $client,
            'clientName' => $client->display_name,
            'portalSections' => app(ClientPortalRegistry::class)->visibleTo($this->portalUser($request), $client),
        ];
    }

    /**
     * An id route parameter, or a 404 when it is not a positive integer.
     */
    protected function routeId(mixed $value): int
    {
        if ($value instanceof Model) {
            $value = $value->getKey();
        }

        abort_unless(is_int($value) || (is_string($value) && ctype_digit($value) && $value !== '0'), Response::HTTP_NOT_FOUND);

        return (int) $value;
    }
}
