@extends('layouts.admin')

@section('title', 'Search')

{{--
    Full-page search results - admin.search.index (phase-19-23 7.8, 108).

    **A hit the viewer cannot open is shown without a link, never dropped.** If it were filtered out,
    the count would disagree with the rows, and somebody searching for a record they know exists
    would be told there are no results - which reads as "no such record" rather than "not for you".

    An entity that could not be searched is NAMED. A palette that quietly omitted a whole kind would
    have somebody concluding their record does not exist.
--}}

@section('header')
    <x-ui.page-header title="Search"
                      subtitle="Everything you are allowed to see, in one place."
                      icon="magnifying-glass" />
@endsection

@section('content')
    <x-ui.card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <x-ui.form.input name="q" label="Search for" :value="$term" autofocus
                                 placeholder="A name, a code, a number&hellip;" />
            </div>
            <x-ui.button type="submit" icon="magnifying-glass">Search</x-ui.button>
        </form>

        <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">
            Searching:
            @foreach ($entities as $entity)
                <span class="whitespace-nowrap">{{ $entity['label'] }} ({{ implode(', ', $entity['columns']) }})</span>@if (! $loop->last); @endif
            @endforeach
        </p>
    </x-ui.card>

    @if ($results->unavailable !== [])
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
            <strong class="font-semibold">Not searched:</strong> {{ implode(', ', $results->unavailable) }}.
            Something went wrong looking there, so these results are incomplete.
        </div>
    @endif

    @if ($results->tooShort)
        <x-ui.empty-state icon="magnifying-glass"
                          title="Keep typing"
                          :description="'Type at least ' . $results->minimumCharacters . ' characters to search.'" />
    @elseif ($results->isEmpty())
        <x-ui.empty-state icon="magnifying-glass"
                          title="Nothing matched"
                          :description="'No record you can see matches “' . $term . '”.'" />
    @else
        @foreach ($results->groups as $type => $hits)
            @php($entity = \App\Enums\SearchEntityType::tryFrom($type))

            <section class="mb-6">
                <x-ui.section-heading :title="$entity?->label() ?? $type"
                                      :subtitle="count($hits) . ' ' . \Illuminate\Support\Str::plural('match', count($hits))" />

                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($hits as $hit)
                        <x-ui.card>
                            <div class="flex items-start gap-3">
                                <x-ui.icon :name="$hit->icon ?? $entity?->icon() ?? 'document'" class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" />

                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium text-slate-800 dark:text-slate-100">
                                        @if ($hit->isLinked())
                                            <a href="{{ $hit->url }}" class="hover:underline">{{ $hit->title }}</a>
                                        @else
                                            {{ $hit->title }}
                                        @endif
                                    </p>

                                    @if ($hit->subtitle)
                                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $hit->subtitle }}</p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-400 dark:text-slate-500">
                                        @if ($hit->badge)
                                            <x-ui.badge :color="$hit->badgeColor ?? 'slate'">{{ $hit->badge }}</x-ui.badge>
                                        @endif

                                        @foreach ($hit->meta as $label => $value)
                                            @if ($value)
                                                <span>{{ $label }}: {{ $value }}</span>
                                            @endif
                                        @endforeach
                                    </div>

                                    @unless ($hit->isLinked())
                                        {{-- Shown, not dropped - see the file note. --}}
                                        <p class="mt-2 text-xs italic text-slate-400 dark:text-slate-500">
                                            You cannot open this record.
                                        </p>
                                    @endunless
                                </div>
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
@endsection
