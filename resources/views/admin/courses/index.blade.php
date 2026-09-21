@extends('layouts.admin')

@section('title', 'Courses')

@php $query = request()->query(); @endphp

@section('header')
    <x-ui.page-header title="Courses"
                      subtitle="The catalogue. A course is a price list and a syllabus — what it earns lives with the students on it."
                      icon="academic-cap">
        <x-slot:actions>
            @can('courses.export')
                <x-ui.button variant="secondary" icon="arrow-down-tray"
                             :href="route('admin.courses.export', ['format' => 'csv'] + $query)">Export</x-ui.button>
            @endcan
            @if ($canCreate)
                <x-ui.button variant="primary" :href="route('admin.courses.create')" icon="plus">Add course</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Published" :value="$counts['published']" icon="globe-alt" color="emerald"
                        delta-label="on the public site"
                        :href="route('admin.courses.index', ['status' => 'published'])" />
        <x-ui.stat-card label="Draft" :value="$counts['draft']" icon="pencil-square" color="slate"
                        delta-label="not yet on sale"
                        :href="route('admin.courses.index', ['status' => 'draft'])" />
        <x-ui.stat-card label="Archived" :value="$counts['archived']" icon="archive-box" color="amber"
                        delta-label="retired, history intact"
                        :href="route('admin.courses.index', ['status' => 'archived'])" />
        <x-ui.stat-card label="Featured" :value="$counts['featured']" icon="star" color="brand"
                        delta-label="pinned to the top"
                        :href="route('admin.courses.index', ['featured' => 1])" />
    </div>

    <x-ui.card class="mb-4">
        <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.form.input name="q" label="Search" :value="request('q')" placeholder="Name or code" />

            <x-ui.form.select name="category" label="Category" placeholder="Any category">
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="status" label="Status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="level" label="Level" placeholder="Any level">
                @foreach ($levels as $level)
                    <option value="{{ $level->value }}" @selected(request('level') === $level->value)>{{ $level->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="mode" label="Mode" placeholder="Any mode">
                @foreach ($modes as $mode)
                    <option value="{{ $mode->value }}" @selected(request('mode') === $mode->value)>{{ $mode->label() }}</option>
                @endforeach
            </x-ui.form.select>

            <x-ui.form.select name="branch" label="Branch" placeholder="Every branch">
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(request('branch') == $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </x-ui.form.select>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="certificate" value="1" @checked(request()->boolean('certificate'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Certificate
            </label>

            <label class="flex items-end gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="installments" value="1" @checked(request()->boolean('installments'))
                       class="rounded border-slate-300 text-brand-600 dark:border-slate-700 dark:bg-slate-900">
                Installments
            </label>

            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <x-ui.button type="submit" variant="secondary" icon="funnel">Filter</x-ui.button>
                <x-ui.button variant="ghost" :href="route('admin.courses.index')">Clear</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @unless ($seesMoney)
        <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
            You can see the catalogue and its syllabi. The fees need
            <code>courses.view_financial</code>, which is a separate permission.
        </div>
    @endunless

    <x-ui.card :title="$courses->total() . ' ' . \Illuminate\Support\Str::plural('course', $courses->total())">
        <x-ui.table :is-empty="$courses->isEmpty()">
            <x-slot:head>
                <th class="px-4 py-3 text-left font-semibold">Course</th>
                <th class="px-4 py-3 text-left font-semibold">Category</th>
                <th class="px-4 py-3 text-left font-semibold">Shape</th>
                <th class="px-4 py-3 text-right font-semibold">Outline</th>
                @if ($seesMoney)
                    <th class="px-4 py-3 text-right font-semibold">Course fee</th>
                @endif
                <th class="px-4 py-3 text-left font-semibold">Status</th>
                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
            </x-slot:head>

            @foreach ($courses as $course)
                <tr>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            @if (filled($course->thumbnail_path))
                                <img src="{{ \Illuminate\Support\Facades\Storage::url($course->thumbnail_path) }}"
                                     alt="" class="h-10 w-14 shrink-0 rounded object-cover">
                            @else
                                <span class="flex h-10 w-14 shrink-0 items-center justify-center rounded bg-slate-100 dark:bg-slate-800">
                                    <x-ui.icon name="academic-cap" class="h-5 w-5 text-slate-400" />
                                </span>
                            @endif

                            <div class="min-w-0">
                                <a href="{{ route('admin.courses.show', $course) }}"
                                   class="block truncate font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                                    {{ $course->name }}
                                    @if ($course->is_featured)
                                        <x-ui.icon name="star" class="ml-1 inline h-4 w-4 text-amber-400" />
                                    @endif
                                </a>
                                <span class="block font-mono text-xs text-slate-500 dark:text-slate-400">{{ $course->code }}</span>
                            </div>
                        </div>
                    </td>

                    <td class="px-4 py-3">
                        <span class="block text-slate-900 dark:text-white">{{ $course->category?->name }}</span>
                        @if ($course->branch)
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $course->branch->name }}</span>
                        @else
                            <span class="block text-xs text-slate-400">every branch</span>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            <x-ui.badge :color="$course->level->color()" size="xs">{{ $course->level->label() }}</x-ui.badge>
                            <x-ui.badge :color="$course->delivery_mode->color()" size="xs">{{ $course->delivery_mode->label() }}</x-ui.badge>
                            @if ($course->certificate_available)
                                <x-ui.badge color="sky" size="xs">certificate</x-ui.badge>
                            @endif
                        </div>
                        @if ($course->durationLabel())
                            <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $course->durationLabel() }}</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-right text-xs tabular-nums text-slate-600 dark:text-slate-300">
                        <span class="block">{{ $course->modules_count }}m · {{ $course->topics_count }}t · {{ $course->lectures_count }}l</span>
                        @if ($course->outline_minutes > 0)
                            <span class="block text-slate-500 dark:text-slate-400">
                                ~{{ (int) round($course->outline_minutes / 60) }}h
                            </span>
                        @endif
                    </td>

                    @if ($seesMoney)
                        <td class="px-4 py-3 text-right">
                            <span class="block font-semibold tabular-nums text-slate-900 dark:text-white">
                                {{ money($course->course_fee) }}
                            </span>
                            @if ($course->installment_available)
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    up to {{ $course->max_installments }} installments
                                </span>
                            @endif
                        </td>
                    @endif

                    <td class="px-4 py-3">
                        <x-ui.badge :color="$course->status->color()" size="xs">{{ $course->status->label() }}</x-ui.badge>
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @can('course_outline.view')
                                <x-ui.icon-button icon="list-bullet" label="Outline of {{ $course->name }}"
                                                  :href="route('admin.course-outline.index', $course)" />
                            @endcan
                            @can('update', $course)
                                <x-ui.icon-button icon="pencil" label="Edit {{ $course->name }}"
                                                  :href="route('admin.courses.edit', $course)" />
                            @endcan
                            <x-ui.icon-button icon="eye" label="Open {{ $course->name }}"
                                              :href="route('admin.courses.show', $course)" />
                        </div>
                    </td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-ui.empty-state icon="academic-cap"
                                  :title="request()->hasAny(['q', 'category', 'status', 'level', 'mode', 'branch'])
                                      ? 'No courses match these filters'
                                      : 'No courses yet'"
                                  message="A course starts as a draft: add its outline, then publish it." />
            </x-slot:empty>

            <x-slot:footer>
                <x-ui.pagination-summary :paginator="$courses" label="courses" />
            </x-slot:footer>
        </x-ui.table>
    </x-ui.card>
@endsection
