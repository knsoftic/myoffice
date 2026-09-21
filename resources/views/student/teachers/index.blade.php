@extends('layouts.panel')

@section('title', 'My teachers')

@section('header')
    <x-ui.page-header title="My teachers"
                      subtitle="The people who teach your batches — including anybody who has covered a class for them."
                      icon="presentation-chart-bar" />
@endsection

@section('content')
    @if ($teachers->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="presentation-chart-bar" title="No teachers yet"
                              description="Once you are in a batch, the people who teach it appear here." />
        </x-ui.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($teachers as $teacher)
                <x-ui.card>
                    <div class="flex items-start gap-3">
                        <x-ui.avatar :name="$teacher->name" size="lg" />
                        <div class="min-w-0">
                            <div class="font-medium text-slate-700 dark:text-slate-200">{{ $teacher->name }}</div>
                            @if (filled($teacher->specialization))
                                <div class="text-xs text-slate-400">{{ $teacher->specialization }}</div>
                            @endif
                            @if (filled($teacher->qualification))
                                <div class="mt-1 text-xs text-slate-500">{{ $teacher->qualification }}</div>
                            @endif
                        </div>
                    </div>

                    @if (filled($teacher->public_bio))
                        <p class="mt-3 text-sm text-slate-500">{{ $teacher->public_bio }}</p>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
@endsection
