@extends('layouts.admin')

@section('title', $material->title)

@section('header')
    <x-ui.page-header :title="$material->title"
                      :subtitle="$material->course?->name"
                      icon="folder-open">
        <x-slot:actions>
            @if ($canDownload && $material->isFile())
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.course-materials.download', $material)">Download</x-ui.button>
            @endif
            @if ($canSeeEngagement)
                <x-ui.button variant="ghost" icon="chart-bar"
                             :href="route('admin.course-materials.engagement', $material)">Engagement</x-ui.button>
            @endif
            @if ($canEdit)
                <x-ui.button variant="ghost" icon="pencil"
                             :href="route('admin.course-materials.edit', $material)">Edit</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 grid gap-6">
            <x-ui.card>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.badge :color="$material->status->color()">{{ $material->status->label() }}</x-ui.badge>
                    <x-ui.badge :color="$material->type->color()" size="xs">{{ $material->type->label() }}</x-ui.badge>
                    <x-ui.badge :color="$material->audience_scope->color()" size="xs">{{ $material->audience_scope->label() }}</x-ui.badge>
                    @unless ($material->is_downloadable)
                        <x-ui.badge color="slate" size="xs">View only</x-ui.badge>
                    @endunless
                </div>

                @if ($material->description)
                    <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">{{ $material->description }}</p>
                @endif

                <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                    @if ($material->isFile())
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">File</dt>
                            <dd class="text-sm text-slate-700 dark:text-slate-200">{{ $material->original_name }}</dd>
                            <dd class="text-xs text-slate-400">
                                {{ strtoupper((string) $material->extension) }} ·
                                {{ app_number(($material->file_size_bytes ?? 0) / 1024, 0) }} KB
                            </dd>
                        </div>
                    @else
                        <div class="sm:col-span-2">
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Link</dt>
                            <dd class="text-sm break-all text-slate-700 dark:text-slate-200">{{ $material->external_url }}</dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Available</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">
                            {{ $material->available_from ? app_datetime($material->available_from) : 'as soon as published' }}
                            &rarr;
                            {{ $material->available_until ? app_datetime($material->available_until) : 'no end' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">Published</dt>
                        <dd class="text-sm text-slate-700 dark:text-slate-200">
                            {{ $material->published_at ? app_datetime($material->published_at) : 'not yet' }}
                        </dd>
                    </div>

                    @if ($material->topic)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-400">Topic</dt>
                            <dd class="text-sm text-slate-700 dark:text-slate-200">{{ $material->topic->title }}</dd>
                        </div>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card>
                <x-ui.section-heading title="Who gets it"
                                      subtitle="Resolved live every time somebody asks for the file — this list is the audience, not a cache of it." />

                @if ($targets->isEmpty())
                    <x-ui.empty-state icon="user-group" title="Nobody yet"
                                      description="A material with no audience cannot be published." />
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-slate-700">
                        @foreach ($targets as $target)
                            <li class="flex items-center justify-between gap-3 py-3">
                                <div>
                                    <div class="text-sm font-medium text-slate-700 dark:text-slate-200">
                                        @switch($target->target_type->value)
                                            @case('batch') {{ $target->targetBatch?->code ?? '—' }} @break
                                            @case('student') {{ $target->targetStudent?->name ?? '—' }} @break
                                            @default {{ $target->targetCourse?->name ?? '—' }}
                                        @endswitch
                                    </div>
                                    <div class="text-xs text-slate-400">
                                        <x-ui.badge :color="$target->target_type->color()" size="xs">{{ $target->target_type->label() }}</x-ui.badge>
                                        @if ($target->notified_at)
                                            · told {{ app_date($target->notified_at) }}
                                        @else
                                            · not told yet
                                        @endif
                                    </div>
                                </div>

                                @if ($canAssign)
                                    <form method="POST"
                                          action="{{ route('admin.course-materials.targets.destroy', [$material, $target]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">Remove</x-ui.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canAssign)
                    <form method="POST" action="{{ route('admin.course-materials.targets.store', $material) }}"
                          class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-700">
                        @csrf

                        @foreach ($targets as $i => $existing)
                            <input type="hidden" name="targets[{{ $i }}][type]" value="{{ $existing->target_type->value }}">
                            <input type="hidden" name="targets[{{ $i }}][id]" value="{{ $existing->targetId() }}">
                        @endforeach

                        <x-ui.form.help class="mb-3">
                            Adding an audience tells only the people who have not already been told. Removing one
                            stops their access at once.
                        </x-ui.form.help>

                        <div class="flex flex-wrap items-end gap-2">
                            <input type="hidden" name="targets[{{ $targets->count() }}][type]" value="course">
                            <input type="hidden" name="targets[{{ $targets->count() }}][id]" value="{{ $material->course_id }}">
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="plus">Share with the whole course</x-ui.button>
                        </div>
                    </form>
                @endif
            </x-ui.card>
        </div>

        <div class="grid gap-6">
            @if ($canPublish)
                <x-ui.card>
                    <x-ui.section-heading title="Status" />

                    <form method="POST" action="{{ route('admin.course-materials.status', $material) }}" class="grid gap-3">
                        @csrf

                        <x-ui.form.select name="status" label="Move to">
                            <option value="published" @selected($material->status->value === 'published')>Published</option>
                            <option value="draft" @selected($material->status->value === 'draft')>Draft</option>
                            <option value="archived" @selected($material->status->value === 'archived')>Archived</option>
                        </x-ui.form.select>

                        <x-ui.form.input name="reason" label="Reason"
                                         help="Needed for anything except a first publish — somebody will ask where it went." />

                        <x-ui.button type="submit" variant="secondary" icon="arrow-path">Apply</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card>
                <x-ui.section-heading title="Opened by" />

                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-xl font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($material->view_count) }}</div>
                        <div class="text-xs text-slate-400">views</div>
                    </div>
                    <div>
                        <div class="text-xl font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($material->download_count) }}</div>
                        <div class="text-xs text-slate-400">downloads</div>
                    </div>
                    <div>
                        <div class="text-xl font-semibold tabular-nums text-slate-700 dark:text-slate-200">{{ app_number($material->unique_students_count) }}</div>
                        <div class="text-xs text-slate-400">students</div>
                    </div>
                </div>

                @if ($recent->isNotEmpty())
                    <ul class="mt-4 divide-y divide-slate-100 text-sm dark:divide-slate-700">
                        @foreach ($recent as $entry)
                            <li class="flex items-center justify-between gap-2 py-2">
                                <span class="text-slate-600 dark:text-slate-300">
                                    {{ $entry->student?->name ?? $entry->user?->name ?? 'Someone' }}
                                </span>
                                <span class="text-xs text-slate-400">
                                    {{ $entry->action->label() }} · {{ app_date($entry->created_at) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
@endsection
