<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reporting;

use App\DataObjects\Search\SearchHit;
use App\Enums\SearchEntityType;
use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The 108 palette and its full-page results (phase-19-23 7.8).
 *
 * **`suggest` is throttled at 60/minute per user**, which is roughly four characters a second with
 * the debounce applied - fast enough to type through and slow enough that the endpoint cannot be
 * used to enumerate a table one letter at a time.
 *
 * **Nothing here caches.** 6.23 is explicit: results are never shared across users, because two
 * people searching one word are running eleven differently-scoped queries and a shared entry would
 * hand the narrower viewer the wider answer.
 */
final class GlobalSearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $search,
    ) {}

    /** The full-page results view. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $term = $request->string('q')->toString();

        return view('admin.search.index', [
            'term' => $term,
            'results' => $this->search->search($term, $user, (array) $request->input('types', [])),
            'entities' => $this->search->describeFor($user),
            'recent' => $this->search->recentFor($user),
        ]);
    }

    /** The palette's JSON. */
    public function suggest(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = $request->string('q')->toString();

        $results = $this->search->search($term, $user, (array) $request->input('types', []));

        return response()->json(array_merge($results->toArray(), [
            'recent' => $term === '' ? $this->search->recentFor($user) : [],
            // What the palette says it is searching, so somebody who typed a phone number and got
            // nothing can see whether phone was ever among the columns.
            'entities' => $this->search->describeFor($user),
            'debounce_ms' => (int) setting('reports.global_search_debounce_ms', 250),
        ]));
    }

    /**
     * Remember that this person opened a hit, then send them to it.
     *
     * A redirect rather than a JSON acknowledgement, so the palette can simply follow the link and
     * the recording is a side effect of going there rather than a second request that might not
     * arrive.
     */
    public function open(Request $request): JsonResponse
    {
        $type = SearchEntityType::tryFrom($request->string('type')->toString());

        abort_if($type === null, 404);

        $this->search->remember($request->user(), new SearchHit(
            type: $type,
            id: $request->string('id')->toString(),
            title: $request->string('title')->toString(),
            subtitle: $request->input('subtitle'),
            url: $request->input('url'),
        ));

        return response()->json(['ok' => true]);
    }
}
