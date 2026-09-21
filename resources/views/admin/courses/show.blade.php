@extends('layouts.admin')

@section('title', $course->name)

@php
    $isPublished = $course->status === \App\Enums\CourseStatus::Published;
    $isArchived = $course->status === \App\Enums\CourseStatus::Archived;
@endphp

@section('header')
    <x-ui.page-header :title="$course->name"
                      :subtitle="$course->code.' · '.($course->category?->name ?? 'no category')"
                      icon="academic-cap"
                      :badge="$course->status->label()"
                      :badge-color="$course->status->color()"
                      :back="route('admin.courses.index')">
        <x-slot:actions>
            @if ($isPublished)
                <x-ui.button variant="secondary" icon="globe-alt" target="_blank"
                             :href="route('site.courses.show', $course->slug)">View page</x-ui.button>
            @endif

            @if ($canEdit)
                <x-ui.button variant="secondary" icon="pencil" :href="route('admin.courses.edit', $course)">Edit</x-ui.button>
            @endif

            @if ($canChangeStatus && ! $isArchived)
                @if ($isPublished)
                    <form method="POST" action="{{ route('admin.courses.unpublish', $course) }}">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" icon="eye-slash">Unpublish</x-ui.button>
                    </form>
                @else
                    @if ($gaps === [])
                        <form method="POST" action="{{ route('admin.courses.publish', $course) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary" icon="globe-alt">Publish</x-ui.button>
                        </form>
                    @else
                        <span title="Still needs: {{ implode(', ', $gaps) }}">
                            <x-ui.button variant="primary" icon="globe-alt" :disabled="true">Publish</x-ui.button>
                        </span>
                    @endif
                @endif
            @endif

            @can('duplicate', $course)
                <form method="POST" action="{{ route('admin.courses.duplicate', $course) }}">
                    @csrf
                    <x-ui.button type="submit" variant="ghost" icon="document-duplicate">Duplicate</x-ui.button>
                </form>
            @endcan

            @if ($canChangeStatus)
                @if ($isArchived)
                    <x-ui.button variant="secondary" icon="arrow-uturn-left"
                                 x-on:click.prevent="$dispatch('open-modal', 'revive-course')">Revive</x-ui.button>
                @else
                    <x-ui.button variant="danger" icon="archive-box"
                                 x-on:click.prevent="$dispatch('open-modal', 'archive-course')">Archive</x-ui.button>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    @if ($gaps !== [] && ! $isArchived)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
            <p class="font-semibold">Not ready for the site yet.</p>
            <p class="mt-1">
                It still needs: <strong>{{ implode(', ', $gaps) }}</strong>. A published course with a
                gap in it is a page a visitor bounces off.
            </p>
        </div>
    @endif

    @if ($isArchived)
        <div class="mb-4 rounded-lg border border-slate-300 bg-slate-100 p-4 text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
            This course is retired. Every batch, admission and fee row against it is untouched and stays
            readable — that is what archiving is for.
        </div>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Outline" :value="$course->modules_count.' modules'" icon="list-bullet" color="slate"
                        :delta-label="$course->topics_count.' topics · '.$course->lectures_count.' lectures'" />
        <x-ui.stat-card label="Teaching time"
                        :value="$course->outline_minutes > 0 ? round($course->outline_minutes / 60).' hours' : 'Not set'"
                        icon="clock" color="sky"
                        delta-label="from the lecture durations" />
        @if ($seesMoney)
            <x-ui.stat-card label="Course fee" :value="money($course->course_fee)" icon="banknotes" color="emerald"
                            :delta-label="'total at admission '.money($course->totalFee())" />
        @endif
        <x-ui.stat-card label="Admissions"
                        :value="$course->admission_open ? 'Open' : 'Closed'"
                        icon="inbox-arrow-down"
                        :color="$course->admission_open ? 'emerald' : 'slate'"
                        :delta-label="setting('institute.admission_open', true) ? 'institute-wide switch is on' : 'institute-wide switch is OFF'" />
    </div>

    <div x-data="uiTabs('overview')" class="space-y-4">
        <x-ui.tabs :tabs="[
            ['label' => 'Overview', 'key' => 'overview'],
            ['label' => 'Outline', 'key' => 'outline', 'count' => $course->modules_count],
            ['label' => 'FAQs', 'key' => 'faqs', 'count' => $faqs->count()],
        ]" />

        {{-- ---------------------------------------------------------- Overview --}}
        <div x-show="is('overview')">
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <x-ui.card title="What it is">
                        <dl class="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Category</dt>
                                <dd class="text-slate-900 dark:text-white">{{ $course->category?->name ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Branch</dt>
                                <dd class="text-slate-900 dark:text-white">{{ $course->branch?->name ?: 'Every branch' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Level</dt>
                                <dd><x-ui.badge :color="$course->level->color()" size="xs">{{ $course->level->label() }}</x-ui.badge></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Taught</dt>
                                <dd><x-ui.badge :color="$course->delivery_mode->color()" size="xs">{{ $course->delivery_mode->label() }}</x-ui.badge></dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Duration</dt>
                                <dd class="text-slate-900 dark:text-white">{{ $course->durationLabel() ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500 dark:text-slate-400">Classes</dt>
                                <dd class="text-slate-900 dark:text-white">
                                    {{ $course->total_classes ?: '—' }}
                                    @if ($course->class_duration_minutes)
                                        <span class="text-slate-500 dark:text-slate-400">· {{ $course->class_duration_minutes }} min each</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-slate-500 dark:text-slate-400">Web address</dt>
                                <dd class="font-mono text-xs text-slate-900 dark:text-white">/courses/{{ $course->slug }}</dd>
                            </div>
                            @if (filled($course->short_description))
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500 dark:text-slate-400">Short description</dt>
                                    <dd class="text-slate-700 dark:text-slate-200">{{ $course->short_description }}</dd>
                                </div>
                            @endif
                        </dl>
                    </x-ui.card>

                    @if (filled($course->full_description))
                        <x-ui.card title="Full description">
                            <div class="prose prose-sm max-w-none dark:prose-invert">
                                {!! \App\Support\RichText::sanitize($course->full_description) !!}
                            </div>
                        </x-ui.card>
                    @endif

                    @if (($course->requirements ?? []) !== [] || ($course->outcomes ?? []) !== [])
                        <div class="grid gap-4 sm:grid-cols-2">
                            @if (($course->requirements ?? []) !== [])
                                <x-ui.card title="Requirements">
                                    <ul class="space-y-1.5 text-sm text-slate-700 dark:text-slate-200">
                                        @foreach ($course->requirements as $item)
                                            <li class="flex gap-2">
                                                <x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                                                <span>{{ $item }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </x-ui.card>
                            @endif

                            @if (($course->outcomes ?? []) !== [])
                                <x-ui.card title="Learning outcomes">
                                    <ul class="space-y-1.5 text-sm text-slate-700 dark:text-slate-200">
                                        @foreach ($course->outcomes as $item)
                                            <li class="flex gap-2">
                                                <x-ui.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                                                <span>{{ $item }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </x-ui.card>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="space-y-4">
                    @if ($seesMoney)
                        <x-ui.card title="The price list"
                                   subtitle="What the next student is quoted. Nothing already sold moves when this changes.">
                            <dl class="space-y-2 text-sm">
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-500 dark:text-slate-400">Course fee</dt>
                                    <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($course->course_fee) }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-500 dark:text-slate-400">Admission fee</dt>
                                    <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($course->admission_fee) }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt class="text-slate-500 dark:text-slate-400">Registration fee</dt>
                                    <dd class="tabular-nums text-slate-900 dark:text-white">{{ money($course->registration_fee) }}</dd>
                                </div>
                                <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 dark:border-slate-800">
                                    <dt class="font-medium text-slate-900 dark:text-white">Total</dt>
                                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-white">{{ money($course->totalFee()) }}</dd>
                                </div>
                                @if ($course->monthly_fee !== null)
                                    <div class="flex justify-between gap-3">
                                        <dt class="text-slate-500 dark:text-slate-400">Monthly</dt>
                                        <dd class="tabular-nums text-slate-700 dark:text-slate-200">{{ money($course->monthly_fee) }}</dd>
                                    </div>
                                @endif
                            </dl>

                            @if ($course->installment_available)
                                <x-slot:footer>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        Up to {{ $course->max_installments }} installments.
                                        {{ $course->installment_note }}
                                    </p>
                                </x-slot:footer>
                            @endif
                        </x-ui.card>
                    @endif

                    <x-ui.card title="On the site">
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Published</dt>
                                <dd class="text-right text-slate-900 dark:text-white">
                                    {{ $course->published_at ? app_date($course->published_at) : 'Never' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Featured</dt>
                                <dd class="text-right">
                                    @if ($canChangeStatus)
                                        <form method="POST" action="{{ route('admin.courses.featured', $course) }}">
                                            @csrf
                                            <input type="hidden" name="featured" value="{{ $course->is_featured ? 0 : 1 }}">
                                            <x-ui.button type="submit" size="sm" variant="ghost">
                                                {{ $course->is_featured ? 'Unfeature' : 'Feature' }}
                                            </x-ui.button>
                                        </form>
                                    @else
                                        {{ $course->is_featured ? 'Yes' : 'No' }}
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Indexable</dt>
                                <dd class="text-right text-slate-900 dark:text-white">{{ $course->is_indexable ? 'Yes' : 'No' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-slate-500 dark:text-slate-400">Certificate</dt>
                                <dd class="text-right text-slate-900 dark:text-white">{{ $course->certificate_available ? 'Yes' : 'No' }}</dd>
                            </div>
                        </dl>
                    </x-ui.card>

                    @if (filled($course->notes))
                        <x-ui.card title="Internal notes" subtitle="Never rendered on the public page.">
                            <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{{ $course->notes }}</p>
                        </x-ui.card>
                    @endif
                </div>
            </div>
        </div>

        {{-- ----------------------------------------------------------- Outline --}}
        <div x-show="is('outline')" x-cloak>
            <x-ui.card title="The syllabus"
                       subtitle="Three levels: module, topic, lecture. A node that has been taught from is switched off, never deleted.">
                @include('admin.courses._outline-tree', [
                    'canCreate' => $canCreateOutline,
                    'canEdit' => $canEditOutline,
                    'canDelete' => $canDeleteOutline,
                    'canToggle' => $canToggleOutline,
                    'canUpload' => $canUpload,
                    'lectureTypes' => \App\Enums\LectureType::cases(),
                    'resourceTypes' => \App\Enums\CourseResourceType::cases(),
                ])
            </x-ui.card>
        </div>

        {{-- -------------------------------------------------------------- FAQs --}}
        <div x-show="is('faqs')" x-cloak>
            <x-ui.card title="Questions on the course page"
                       subtitle="These are the site's own FAQ rows, attached to this course — there is no separate table for them.">
                <x-ui.table :is-empty="$faqs->isEmpty()">
                    <x-slot:head>
                        <th class="px-4 py-3 text-left font-semibold">Question</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                    </x-slot:head>

                    @foreach ($faqs as $faq)
                        <tr>
                            <td class="px-4 py-3">
                                <span class="block text-slate-900 dark:text-white">{{ $faq->question }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ \Illuminate\Support\Str::limit(strip_tags((string) $faq->answer), 90) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <x-ui.badge color="slate" size="xs">
                                    {{ $faq->status instanceof \BackedEnum ? $faq->status->label() : $faq->status }}
                                </x-ui.badge>
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:empty>
                        <x-ui.empty-state icon="question-mark-circle" compact
                                          title="No questions yet"
                                          message="FAQs on a course page answer what the description does not." />
                    </x-slot:empty>
                </x-ui.table>
            </x-ui.card>
        </div>
    </div>

    @if ($canChangeStatus && ! $isArchived)
        <x-ui.modal name="archive-course" title="Retire this course" icon="archive-box">
            <form method="POST" action="{{ route('admin.courses.archive', $course) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    It comes off the site and out of the catalogue. Every batch, admission and fee against
                    it stays exactly where it is — that is the point of retiring rather than deleting.
                    It is refused while a batch is still enrolling or running.
                </p>
                <x-ui.form.textarea name="reason" label="Why" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'archive-course')">Keep it</x-ui.button>
                    <x-ui.button type="submit" variant="danger">Retire it</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    @if ($canChangeStatus && $isArchived)
        <x-ui.modal name="revive-course" title="Bring this course back" icon="arrow-uturn-left">
            <form method="POST" action="{{ route('admin.courses.revive', $course) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    It comes back as a <strong>draft</strong>, not straight to the site — whatever made it
                    worth retiring deserves a look before it is selling again.
                </p>
                <x-ui.form.textarea name="reason" label="Why" required rows="3" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', 'revive-course')">Cancel</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Bring it back</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
@endsection
