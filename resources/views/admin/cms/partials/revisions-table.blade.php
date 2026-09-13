{{--
    The revision history table with revert (phase-03 §2.14, §6.2 revertToRevision, FT-11), shared by
    admin/cms/sections/revisions and admin/cms/pages/revisions.

    @include('admin.cms.partials.revisions-table', [
        'subject' => $section,                  // WebsiteSection|Page
        'routeParam' => 'section',              // 'section' | 'page'
        'revertRoute' => 'admin.website.sections.revisions.revert',
        'revisions' => $revisions,              // LengthAwarePaginator<CmsRevision>, newest first
        'authors' => $authors,                  // array<int, string> user id => name
        'currentHash' => $currentHash,          // the draft's content_hash
        'publishedHash' => $publishedHash,      // the live snapshot's hash
        'canRevert' => $canRevert,
    ])

    Revert posts `reason` (required) and restores the DRAFT, never the live version; publishing stays a
    separate act. A revision identical to the current draft offers no revert.
--}}

@php
    use App\Enums\Cms\RevisionEvent;

    $authors = $authors ?? [];
@endphp

<x-ui.table :is-empty="$revisions->isEmpty()" :columns="5">
    <x-slot:head>
        <th scope="col" class="px-4 py-3">Revision</th>
        <th scope="col" class="px-4 py-3">Event</th>
        <th scope="col" class="px-4 py-3">By</th>
        <th scope="col" class="px-4 py-3">Label / reason</th>
        <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @foreach ($revisions as $revision)
        @php
            $event = $revision->event instanceof RevisionEvent ? $revision->event : RevisionEvent::tryFrom((string) $revision->event);
            $author = $revision->created_by ? ($authors[(int) $revision->created_by] ?? null) : null;
            $isLive = filled($publishedHash ?? null) && $revision->content_hash === $publishedHash;
            $isDraft = filled($currentHash ?? null) && $revision->content_hash === $currentHash;
        @endphp
        <tr>
            <td class="whitespace-nowrap">
                <p class="font-medium text-slate-900 dark:text-white">#{{ $revision->id }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    <time datetime="{{ $revision->created_at?->toIso8601String() }}">{{ app_datetime($revision->created_at) }}</time>
                </p>
            </td>
            <td>
                <div class="flex flex-wrap items-center gap-1.5">
                    @if ($event)
                        <x-ui.badge :color="$event->color()" size="sm">{{ $event->label() }}</x-ui.badge>
                    @endif
                    @if ($revision->is_published_snapshot)
                        <x-ui.badge color="emerald" variant="outline" size="sm" icon="lock-closed" title="Published snapshots are never pruned.">Snapshot</x-ui.badge>
                    @endif
                    @if ($isLive)
                        <x-ui.badge color="emerald" size="sm">Identical to the live version</x-ui.badge>
                    @endif
                    @if ($isDraft)
                        <x-ui.badge color="indigo" size="sm">Matches the current draft</x-ui.badge>
                    @endif
                </div>
            </td>
            <td class="whitespace-nowrap text-sm">{{ $author ?? ($revision->created_by ? 'User #'.$revision->created_by : 'System') }}</td>
            <td class="max-w-md">
                @if (filled($revision->label))
                    <p class="font-medium text-slate-900 dark:text-white">{{ $revision->label }}</p>
                @endif
                @if (filled($revision->reason))
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $revision->reason }}</p>
                @endif
                @if (blank($revision->label) && blank($revision->reason))
                    <span class="text-slate-400 dark:text-slate-500">—</span>
                @endif
            </td>
            <td class="text-right">
                @if (($canRevert ?? false) && ! $isDraft)
                    <x-ui.confirm
                        :action="route($revertRoute, [$routeParam => $subject, 'revision' => $revision])"
                        method="POST"
                        :id="'revert-'.$revision->id"
                        :title="'Revert the draft to revision #'.$revision->id.'?'"
                        message="The draft is replaced by this revision’s content. The live site does not change until the draft is published. The current draft stays in this history."
                        confirm-label="Revert draft"
                        variant="warning"
                        icon="arrow-path"
                    >
                        <x-slot:trigger>
                            <x-ui.button size="sm" variant="secondary" icon="arrow-path">Revert</x-ui.button>
                        </x-slot:trigger>

                        <div class="mt-4">
                            <label for="revert-reason-{{ $revision->id }}" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                Reason <span class="text-rose-500">*</span> <span class="font-normal text-slate-400">(recorded with the revision)</span>
                            </label>
                            <input id="revert-reason-{{ $revision->id }}" type="text" name="reason" form="revert-{{ $revision->id }}" required minlength="{{ \App\Http\Requests\Cms\CmsFormRequest::REASON_MIN }}" maxlength="255" class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        </div>
                    </x-ui.confirm>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:empty>
        <x-ui.empty-state icon="clock" title="No revisions yet" message="A revision is recorded on every draft save, publish, unpublish and revert." />
    </x-slot:empty>

    <x-slot:footer>
        <x-ui.pagination-summary :paginator="$revisions" label="revisions" />
    </x-slot:footer>
</x-ui.table>

@error('reason')
    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
@enderror
@error('revision')
    <p class="mt-2 text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
@enderror
