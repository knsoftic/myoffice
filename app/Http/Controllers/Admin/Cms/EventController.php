<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Enums\Cms\ContentStatus;
use App\Http\Controllers\Admin\Cms\Concerns\RespondsForContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cms\ChangeEventStatusRequest;
use App\Http\Requests\Cms\ContentListRequest;
use App\Http\Requests\Cms\StoreEventRequest;
use App\Http\Requests\Cms\UpdateEventRequest;
use App\Models\Cms\Event;
use App\Models\User;
use App\Support\Format;
use App\Support\SlugGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Events and announcements — `admin.events.*`, `module:events`.
 *
 * **Publishing is not editing, and the split is enforced three times over.**
 *
 *   1. the routes: `admin.events.update` is `can:events.edit`, while `admin.events.status` and
 *      `admin.events.featured` are `can:events.change_status`;
 *   2. this controller: every action authorizes the module permission **and** {@see \App\Policies\Cms\EventPolicy},
 *      and the two status endpoints authorize `changeStatus` / `toggleFeatured`, never `update`;
 *   3. the data: `status`, `published_at` and `is_featured` are `prohibited` in both write requests and
 *      absent from `Event::$fillable`, so an extra posted field cannot reach them even if 1 and 2 were
 *      somehow bypassed.
 *
 * Hiding a button is not part of that list. The `can` array handed to the views only decides what is
 * worth drawing; every one of those decisions is taken again here before anything is written.
 *
 * **Why the writes are inline rather than in an `EventService`.** Every other phase-04 module routes its
 * writes through a service, and this one should too. The brief for this module fixed the file list and
 * a service was not on it, so the writes sit in short closures inside `DB::transaction()` with no
 * business logic beyond the status transition below. If events grow a publish precondition, a revision
 * snapshot or a scheduler, lift {@see applyStatus()} and the three closures into
 * `App\Services\Cms\EventService` first — do not add the second rule here.
 *
 * Activity is logged by the model (`LogsActivityWithContext`, `logOnlyDirty`), with `status`,
 * `published_at` and `is_featured` added to the logged set so a publish is auditable even though those
 * columns are not fillable. Status changes, deletes and restores carry a `reason` as well.
 */
final class EventController extends Controller
{
    use RespondsForContent;

    /** The list's tabs, in the order they render. */
    public const TABS = ['all', 'upcoming', 'past', 'published', 'scheduled', 'draft', 'archived', 'featured', 'trashed'];

    private const SORTABLE = ['title', 'starts_at', 'ends_at', 'status', 'sort_order', 'updated_at'];

    public function index(ContentListRequest $request): View
    {
        $this->authorize('events.view_any');

        $user = $this->actor($request);
        $tab = $request->filterString('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'all';

        if ($tab === 'trashed' && ! $user->can('events.restore')) {
            $tab = 'all';
        }

        $sort = $request->sortColumn(self::SORTABLE, 'starts_at');
        $direction = $request->sortDirection($tab === 'past' ? 'desc' : 'asc');

        $events = $this->withAvailable($this->filteredQuery($request, $tab), ['coverImage'])
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin.events.index', [
            'events' => $events,
            'tab' => $tab,
            'counts' => $this->tabCounts($user),
            'filters' => $request->activeFilters(),
            'sort' => $sort,
            'direction' => $direction,
            'statusOptions' => ContentStatus::options(),
            'timezone' => Format::displayTimezone(),
            'minScheduleAt' => $this->minScheduleAt(),
            'can' => [
                'create' => $user->can('events.create'),
                'edit' => $user->can('events.edit'),
                'delete' => $user->can('events.delete'),
                'changeStatus' => $user->can('events.change_status'),
                'restore' => $user->can('events.restore'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('events.create');

        return view('admin.events.create', array_merge($this->editorData($request), [
            'event' => null,
        ]));
    }

    public function store(StoreEventRequest $request): Response
    {
        $this->authorize('events.create');

        return $this->attempt($request, function () use ($request): Response {
            $event = DB::transaction(static function () use ($request): Event {
                // A new event is always a draft: `status` is prohibited on this request and is not
                // fillable, so nothing here can put it live. Publishing is its own endpoint.
                return Event::query()->create($request->eventPayload());
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved as a draft. Publish it when it is ready.', $event->title),
                redirect()->route('admin.events.edit', $event),
                ['id' => (int) $event->getKey()],
            );
        }, field: 'title');
    }

    /**
     * There is no admin detail screen: an event is its editor. Someone who may view but not edit is sent
     * to the public page when there is one to send them to, rather than bounced into a 403.
     */
    public function show(Request $request, Event $event): Response
    {
        $this->authorize('events.view');
        $this->authorize('view', $event);

        $user = $this->actor($request);

        if ($user->can('events.edit') && $user->can('update', $event)) {
            return redirect()->route('admin.events.edit', $event);
        }

        $publicUrl = $this->publicUrl($event);

        if ($publicUrl !== null) {
            return redirect()->away($publicUrl);
        }

        return redirect()
            ->route('admin.events.index')
            ->with('toast', ['type' => 'info', 'message' => sprintf('"%s" is not published yet, and editing it needs the edit permission.', $event->title)]);
    }

    public function edit(Request $request, Event $event): View
    {
        $this->authorize('events.edit');
        $this->authorize('update', $event);

        $user = $this->actor($request);
        $this->loadAvailable($event, ['coverImage', 'editor']);

        return view('admin.events.edit', array_merge($this->editorData($request), [
            'event' => $event,
            'publicUrl' => $this->publicUrl($event),
            'can' => [
                'delete' => $user->can('events.delete') && $user->can('delete', $event),
                'changeStatus' => $user->can('events.change_status') && $user->can('changeStatus', $event),
            ],
        ]));
    }

    public function update(UpdateEventRequest $request, Event $event): Response
    {
        $this->authorize('events.edit');
        $this->authorize('update', $event);

        return $this->attempt($request, function () use ($request, $event): Response {
            $event = DB::transaction(static function () use ($request, $event): Event {
                $event->fill($request->eventPayload());
                $event->save();

                return $event;
            });

            return $this->done(
                $request,
                sprintf('"%s" was saved.', $event->title),
                redirect()->route('admin.events.edit', $event),
            );
        }, field: 'title');
    }

    /**
     * Soft delete: the row leaves the website and the slug stays reserved, so a published permalink is
     * never handed to a different event later.
     */
    public function destroy(Request $request, Event $event): Response
    {
        $this->authorize('events.delete');
        $this->authorize('delete', $event);

        $title = (string) $event->title;

        return $this->attempt($request, function () use ($request, $event, $title): Response {
            $event->withReason('Deleted from the events list.')->delete();

            return $this->done(
                $request,
                sprintf('"%s" was moved to the trash.', $title),
                redirect()->route('admin.events.index'),
            );
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * The route is `whereNumber('event')` without `withTrashed()`, so the binding would 404 on the one
     * row this action exists for. The id is resolved here instead.
     */
    public function restore(Request $request, int|string $event): Response
    {
        $this->authorize('events.restore');

        $model = Event::withTrashed()->findOrFail($this->routeId($event));

        $this->authorize('restore', $model);

        return $this->attempt($request, function () use ($request, $model): Response {
            $model->withReason('Restored from the trash.')->restore();

            return $this->done(
                $request,
                sprintf('"%s" was restored. It keeps the status it had.', $model->title),
                redirect()->route('admin.events.edit', $model),
            );
        }, Response::HTTP_FORBIDDEN);
    }

    /**
     * Publish, schedule, unpublish (back to draft) or archive — `can:events.change_status` on the route,
     * the module permission and `EventPolicy::changeStatus()` again here. Nothing about the event's
     * content is touched.
     */
    public function status(ChangeEventStatusRequest $request, Event $event): Response
    {
        $this->authorize('events.change_status');
        $this->authorize('changeStatus', $event);

        $status = $request->contentStatus();
        $at = $request->publishedAt();

        return $this->attempt($request, function () use ($request, $event, $status, $at): Response {
            $event = DB::transaction(fn (): Event => $this->applyStatus($event, $status, $at));

            return $this->done(
                $request,
                $this->statusMessage($event, $status),
                null,
                [
                    'id' => (int) $event->getKey(),
                    'status' => $status->value,
                    'published_at' => $event->published_at?->toIso8601String(),
                ],
            );
        }, field: 'status');
    }

    /**
     * Featuring decides what the public sees first, so it sits with `change_status`, never with `edit`.
     */
    public function featured(Request $request, Event $event): Response
    {
        $this->authorize('events.change_status');
        $this->authorize('toggleFeatured', $event);

        return $this->attempt($request, function () use ($request, $event): Response {
            $event = DB::transaction(static function () use ($event): Event {
                $event->is_featured = ! $event->is_featured;
                $event->withReason($event->is_featured ? 'Featured on the events board.' : 'Removed from the featured events.')->save();

                return $event;
            });

            $featured = (bool) $event->is_featured;

            return $this->done(
                $request,
                $featured
                    ? sprintf('"%s" is now featured.', $event->title)
                    : sprintf('"%s" is no longer featured.', $event->title),
                null,
                ['id' => (int) $event->getKey(), 'is_featured' => $featured],
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The one piece of business logic here, and the reason it is worth naming.
     *
     * `published_at` means two things in turn, as it does for a blog post: while `scheduled` it is the
     * future go-live moment, and once `published` it is the first live moment — which is **never**
     * rewritten by a re-publish, an unpublish or an archive. That is why publishing an event that has
     * been live before keeps the original date rather than stamping today's.
     */
    private function applyStatus(Event $event, ContentStatus $status, ?CarbonImmutable $at): Event
    {
        $from = $event->status instanceof ContentStatus ? $event->status : ContentStatus::tryFrom((string) $event->status);

        if ($status === ContentStatus::Scheduled) {
            $event->published_at = $at;
        } elseif ($status === ContentStatus::Published && $event->published_at === null) {
            $event->published_at = CarbonImmutable::now();
        }

        $event->status = $status;

        $event->withReason(sprintf(
            'Status changed from %s to %s.',
            $from?->label() ?? 'unknown',
            $status->label(),
        ))->save();

        return $event;
    }

    private function statusMessage(Event $event, ContentStatus $status): string
    {
        return match ($status) {
            ContentStatus::Published => sprintf('"%s" is live.', $event->title),
            ContentStatus::Scheduled => sprintf('"%s" will publish itself on %s.', $event->title, Format::dateTime($event->published_at)),
            ContentStatus::Draft => sprintf('"%s" is back to draft and no longer public.', $event->title),
            ContentStatus::Archived => sprintf('"%s" was archived. Nothing was deleted.', $event->title),
        };
    }

    /**
     * @return Builder<Event>
     */
    private function filteredQuery(ContentListRequest $request, string $tab): Builder
    {
        $search = $request->searchTerm();
        $status = $request->filterEnum('status', ContentStatus::class);
        $featured = $request->filterBool('featured');
        $hasImage = $request->filterBool('has_image');
        $type = $request->filterString('type');
        $from = $request->fromDate();
        $to = $request->toDate();

        $query = Event::query();
        $this->scopeTab($query, $tab);

        return $query
            ->when($status instanceof ContentStatus, static fn (Builder $inner) => $inner->where('status', $status->value))
            ->when($featured !== null, static fn (Builder $inner) => $inner->where('is_featured', $featured))
            ->when($hasImage === true, static fn (Builder $inner) => $inner->whereNotNull('cover_media_id'))
            ->when($hasImage === false, static fn (Builder $inner) => $inner->whereNull('cover_media_id'))
            ->when($type === 'online', static fn (Builder $inner) => $inner->where('is_online', true))
            ->when($type === 'onsite', static fn (Builder $inner) => $inner->where('is_online', false))
            ->when($from !== null || $to !== null, static fn (Builder $inner) => $inner->startingBetween($from, $to))
            ->when($search !== null, static fn (Builder $inner) => $inner->search($search));
    }

    /**
     * @param  Builder<Event>  $query
     */
    private function scopeTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'upcoming' => $query->upcoming(),
            'past' => $query->past(),
            'published' => $query->where('status', ContentStatus::Published->value),
            'scheduled' => $query->where('status', ContentStatus::Scheduled->value),
            'draft' => $query->where('status', ContentStatus::Draft->value),
            'archived' => $query->where('status', ContentStatus::Archived->value),
            'featured' => $query->where('is_featured', true),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    private function tabCounts(User $user): array
    {
        $byStatus = $this->countBy(Event::query(), 'status');

        $counts = [
            'all' => array_sum($byStatus),
            'upcoming' => Event::query()->upcoming()->count(),
            'past' => Event::query()->past()->count(),
            'published' => $byStatus[ContentStatus::Published->value] ?? 0,
            'scheduled' => $byStatus[ContentStatus::Scheduled->value] ?? 0,
            'draft' => $byStatus[ContentStatus::Draft->value] ?? 0,
            'archived' => $byStatus[ContentStatus::Archived->value] ?? 0,
            'featured' => Event::query()->where('is_featured', true)->count(),
        ];

        if ($user->can('events.restore')) {
            $counts['trashed'] = Event::query()->onlyTrashed()->count();
        }

        return $counts;
    }

    /**
     * What the create and edit screens share.
     *
     * @return array<string, mixed>
     */
    private function editorData(Request $request): array
    {
        $user = $this->actor($request);

        return [
            'reservedSlugs' => SlugGenerator::RESERVED,
            'mediaLibrary' => $this->mediaLibrary(),
            'maxUploadMb' => $this->maxUploadMb(),
            'statusOptions' => ContentStatus::options(),
            'timezone' => Format::displayTimezone(),
            'minScheduleAt' => $this->minScheduleAt(),
            'canChangeStatus' => $user->can('events.change_status'),
        ];
    }

    /**
     * The schedule picker's floor, as a `datetime-local` value in the display timezone. The server rule
     * (`ChangeEventStatusRequest::MIN_SCHEDULE_MINUTES`) is the authoritative one.
     */
    private function minScheduleAt(): string
    {
        return CarbonImmutable::now(Format::displayTimezone())
            ->addMinutes(ChangeEventStatusRequest::MIN_SCHEDULE_MINUTES)
            ->format('Y-m-d\TH:i');
    }

    /**
     * The event's address on the public site, when it is actually live there.
     */
    private function publicUrl(Event $event): ?string
    {
        if (! $event->isPubliclyVisible() || ! Route::has('site.events.show')) {
            return null;
        }

        return route('site.events.show', ['event' => $event->slug]);
    }
}
