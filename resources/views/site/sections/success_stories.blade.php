{{--
    Section type `success_stories` — declared by Phase 4 (phase-04 §8.11, §2.12, §9.2; requirement §92). There is no
    success-story page: a story opens in an accessible Alpine dialog.

    Receives:
      $section   the published snapshot (id, anchor, provider)
      $content   heading, description, view_all_link (optional)
      $section['provider']  from Phase 4's SuccessStoriesSectionProvider — ONLY SuccessStory::public() rows (published),
                 featured first, as plain arrays:
                   items: list<array{id: int, student_name: string, headline: ?string, story: string (rich text, already
                          sanitised by RichText::sanitize() — sanitised AGAIN here by <x-site.prose>), achievement: ?string,
                          company_name: ?string, platform: ?string, course_name: ?string, video_embed_url: ?string,
                          photo: ?array, is_featured: bool}>
                 (App\Support\Cms\Sections\SuccessStoriesSectionProvider)

    Renders nothing without a published story.
--}}

@php
    $fields = (array) ($content ?? []);
    $provider = data_get($section ?? null, 'provider');
    $stories = collect(is_array(data_get($provider, 'items')) ? data_get($provider, 'items') : (is_array($provider) && array_is_list($provider) ? $provider : []))
        ->filter(static fn ($story): bool => filled(data_get($story, 'student_name')))
        ->take(9)
        ->values();

    $heading = trim((string) data_get($fields, 'heading', ''));
    $heading = $heading !== '' ? $heading : 'Success stories';
    $description = trim((string) data_get($fields, 'description', ''));
    $viewAll = data_get($fields, 'view_all_link');
    $sectionId = (int) data_get($section ?? null, 'id', 0);
@endphp

@if ($stories->isNotEmpty())
    <x-site.section :anchor="data_get($section ?? null, 'anchor')" background="muted" :label="$heading">
        <x-site.heading :title="$heading" :subtitle="$description !== '' ? $description : null" align="center" />

        <div x-data="{ openId: null, opener: null, show(id, el) { this.openId = id; this.opener = el; }, hide() { this.openId = null; this.$nextTick(() => this.opener?.focus()); } }" x-on:keydown.escape.window="if (openId !== null) hide()">
            <ul role="list" class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($stories as $story)
                    @php
                        $photo = data_get($story, 'photo');
                        $outcome = collect([data_get($story, 'company_name'), data_get($story, 'platform')])->filter()->implode(' · ');
                    @endphp
                    <li id="success-story-{{ (int) data_get($story, 'id') }}" class="scroll-mt-28">
                        <article class="flex h-full flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-card dark:border-white/10 dark:bg-slate-900">
                            <div class="flex items-center gap-4">
                                <span class="h-14 w-14 shrink-0 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    @if (filled(data_get($photo, 'url')))
                                        <x-site.image :media="$photo" profile="thumbnail" :alt="data_get($story, 'student_name')" ratio="1/1" class="h-full w-full" />
                                    @else
                                        <span class="flex h-full w-full items-center justify-center text-lg font-semibold text-slate-500 dark:text-slate-400" aria-hidden="true">{{ \Illuminate\Support\Str::upper(mb_substr((string) data_get($story, 'student_name'), 0, 1)) }}</span>
                                    @endif
                                </span>
                                <div class="min-w-0">
                                    <h3 class="truncate font-semibold text-slate-900 dark:text-white">{{ data_get($story, 'student_name') }}</h3>
                                    @if (filled(data_get($story, 'course_name')))
                                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ data_get($story, 'course_name') }}</p>
                                    @endif
                                </div>
                            </div>

                            @if (filled(data_get($story, 'headline')))
                                <p class="mt-5 text-lg font-semibold leading-snug text-slate-900 dark:text-white">{{ data_get($story, 'headline') }}</p>
                            @endif

                            @if (filled(data_get($story, 'achievement')))
                                <p class="mt-3 inline-flex items-start gap-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                    <x-ui.icon name="trophy" class="mt-0.5 h-4 w-4 shrink-0" /> {{ data_get($story, 'achievement') }}
                                </p>
                            @endif

                            @if ($outcome !== '')
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $outcome }}</p>
                            @endif

                            <div class="mt-auto pt-5">
                                <button type="button" x-on:click="show({{ (int) data_get($story, 'id') }}, $el)" class="inline-flex items-center gap-1 rounded text-sm font-semibold text-brand-600 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-brand-400 dark:hover:text-brand-300">
                                    Read the story <x-ui.icon name="arrow-right" class="h-4 w-4" />
                                </button>
                            </div>
                        </article>
                    </li>
                @endforeach
            </ul>

            @foreach ($stories as $story)
                @php $dialogId = 'story-dialog-'.$sectionId.'-'.(int) data_get($story, 'id'); @endphp
                <div x-show="openId === {{ (int) data_get($story, 'id') }}" x-cloak class="fixed inset-0 z-modal flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="{{ $dialogId }}-title" style="display: none">
                    <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-sm" x-on:click="hide()" aria-hidden="true"></div>
                    <div class="relative max-h-[88vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-modal ring-1 ring-slate-200 sm:p-8 dark:bg-slate-900 dark:ring-white/10" x-trap.noscroll="openId === {{ (int) data_get($story, 'id') }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 id="{{ $dialogId }}-title" class="text-xl font-bold text-slate-900 dark:text-white">{{ data_get($story, 'headline') ?: data_get($story, 'student_name') }}</h2>
                                <p class="text-sm text-slate-500 dark:text-slate-400">{{ collect([data_get($story, 'student_name'), data_get($story, 'course_name')])->filter()->implode(' · ') }}</p>
                            </div>
                            <button type="button" x-on:click="hide()" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 dark:text-slate-400 dark:hover:bg-white/10 dark:hover:text-white">
                                <x-ui.icon name="x-mark" class="h-5 w-5" /><span class="sr-only">Close</span>
                            </button>
                        </div>
                        @if (filled(data_get($story, 'video_embed_url')) || filled(data_get($story, 'video_url')))
                            <div class="mt-5">
                                @include('site.marketing.partials.video-embed', ['embedUrl' => data_get($story, 'video_embed_url'), 'url' => data_get($story, 'video_url'), 'title' => 'Video story of '.data_get($story, 'student_name')])
                            </div>
                        @endif
                        <x-site.prose :html="(string) data_get($story, 'story', '')" class="mt-5" />
                    </div>
                </div>
            @endforeach
        </div>

        @if (is_array($viewAll) && filled($viewAll['label'] ?? null) && filled($viewAll['url'] ?? null))
            <div class="mt-10 text-center">
                <x-site.button :link="$viewAll" icon="arrow-right" />
            </div>
        @endif
    </x-site.section>
@endif
