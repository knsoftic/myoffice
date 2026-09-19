{{--
    The blog post editor — a two-column layout, not a wizard (phase-04 §8.7, §2.16, §6.7, §6.11).

    @include('admin.blog-posts.partials.form', ['post' => $post])   // null on create

    Reads (controller variables of create / edit):
      $categoryOptions   array<int, string>
      $tagSuggestions    list<string>          active tag names for the create-on-type combobox
      $authorOptions     array<int, string>    users who may author posts (rendered only for blog_posts.approve)
      $reservedSlugs     list<string>
      $seoMeta, $seoInherited, $publicUrl      SEO box (optional)
      $maxUploadMb       optional int
      $canChangeStatus   bool   BlogPostPolicy::changeStatus() for this post (create: blog_posts.change_status)

    Posts (StoreBlogPostRequest / UpdateBlogPostRequest, multipart):
      title, slug, excerpt (max 500; blank = auto from content), content (rich text), featured_image_media_id +
      featured_image (upload), featured_image_alt, blog_category_id, tags[] (names, max 40, matched by slug),
      author_id (editors only), is_featured, seo[...],
      intent = save | publish | schedule — which submit button was pressed (missing = save). `save` stores the
      fields and never changes the status (a new post is a draft; a live post stays live). `publish` calls
      BlogService::publish() after the save and `schedule` calls BlogService::schedule() with published_at
      (datetime-local in the display timezone, validated with ScheduleBlogPostRequest's `after:now`), both only when
      the policy allows changeStatus; otherwise the controller saves and says in the toast why it did not publish.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $post = $post ?? null;
    $user = auth()->user();
    $statusValue = $post?->status instanceof \BackedEnum ? $post->status->value : ($post?->status ?? ContentStatus::Draft->value);
    // Ever live: published now, or unpublished/archived with its first-publish moment kept (§6.7 invariants 2-3).
    $wasPublished = $post !== null && $post->published_at !== null && $statusValue !== ContentStatus::Scheduled->value;
    $canChange = (bool) ($canChangeStatus ?? $user?->can('blog_posts.change_status'));
    $isEditor = (bool) $user?->can('blog_posts.approve');
    $timezone = \App\Support\Format::displayTimezone();

    $imageAsset = null;
    foreach (['featuredImageAsset', 'featuredImage', 'featuredImageMedia'] as $relationName) {
        if ($post?->relationLoaded($relationName) && $post->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $imageAsset = $post->getRelation($relationName);
            break;
        }
    }

    $tagNames = $post?->relationLoaded('tags') ? $post->tags->pluck('name')->all() : [];
    $scheduleValue = old('published_at', $statusValue === ContentStatus::Scheduled->value && $post?->published_at ? app_datetime($post->published_at, 'Y-m-d\TH:i') : '');
    $minSchedule = app_datetime(now()->addMinutes(5), 'Y-m-d\TH:i');
    $nowLocal = app_datetime(now(), 'Y-m-d\TH:i');
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    {{-- Left: the writing surface --}}
    <div class="space-y-6 xl:col-span-2">
        <x-ui.card>
            <div class="space-y-5">
                <div>
                    <label for="field-title" class="sr-only">Title</label>
                    <input
                        id="field-title"
                        type="text"
                        name="title"
                        value="{{ old('title', $post?->title) }}"
                        required
                        maxlength="200"
                        placeholder="Post title"
                        @class([
                            'block w-full rounded-lg border bg-white px-3 py-2.5 text-xl font-semibold tracking-tight text-slate-900 shadow-sm placeholder:text-slate-300 focus:ring-2 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-600',
                            'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20' => $errors->has('title'),
                            'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700' => ! $errors->has('title'),
                        ])
                    >
                    <x-ui.form.error for="title" />
                </div>

                @include('admin.marketing.partials.slug-field', [
                    'value' => $post?->slug,
                    'sourceId' => 'field-title',
                    'prefix' => url('/blog').'/',
                    'published' => $wasPublished,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => 200,
                ])

                <div>
                    <x-ui.form.textarea name="excerpt" label="Excerpt" :value="$post?->excerpt" :rows="3" maxlength="500" optional help="Left empty, the first 160 characters of the post are used." />
                    @include('admin.cms.partials.length-meter', ['for' => 'field-excerpt', 'max' => 500, 'idealMin' => 120, 'idealMax' => 160])
                </div>

                @include('admin.cms.partials.richtext', [
                    'name' => 'content',
                    'id' => 'field-content',
                    'label' => 'Content',
                    'value' => $post?->content,
                    'rows' => 22,
                    'required' => true,
                ])

                <div
                    x-data="{
                        words: 0,
                        count() {
                            const field = document.getElementById('field-content');
                            const text = String(field ? field.value : '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim();
                            this.words = text === '' ? 0 : text.split(/\s+/).length;
                        },
                    }"
                    x-init="count(); document.addEventListener('input', (e) => { if (e.target && e.target.id === 'field-content') count(); }); document.addEventListener('trix-change', () => count());"
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400"
                    aria-live="polite"
                >
                    <span class="inline-flex items-center gap-1"><x-ui.icon name="document-text" class="h-3.5 w-3.5" /><span class="tabular-nums" x-text="words.toLocaleString()">0</span> words</span>
                    <span class="inline-flex items-center gap-1"><x-ui.icon name="clock" class="h-3.5 w-3.5" /><span class="tabular-nums" x-text="Math.max(1, Math.ceil(words / 200))">1</span> min read</span>
                    <span class="text-slate-400 dark:text-slate-500">at 200 words a minute; stored when you save</span>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Featured image" icon="photo">
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <x-cms.image-field name="featured_image_media_id" upload="featured_image" label="Image" :asset="$imageAsset" profile="Banner" :max-mb="$maxUploadMb ?? null" />
                <div>
                    <x-ui.form.input name="featured_image_alt" label="Alt text for this post" :value="$post?->featured_image_alt" maxlength="180" optional :placeholder="$imageAsset?->alt_text ? 'Library alt text: '.$imageAsset->alt_text : 'Describe the image for screen readers'" help="Leave empty to use the image's own alt text from the media library." />
                </div>
            </div>
        </x-ui.card>

        {{-- The SEO box sits under the writing surface rather than in the narrow rail: Phase 3's SEO panel lays its
             fields and the search-result preview side by side and needs the width. --}}
        <x-ui.card title="Search engines" subtitle="Empty fields inherit the title, the excerpt and the site defaults." icon="globe-alt">
            <x-cms.seo-fields :model="$post" :seo="$seoMeta ?? null" :inherited="$seoInherited ?? null" :display-url="$publicUrl ?? url('/blog/'.($post?->slug ?? 'your-post'))" />
        </x-ui.card>
    </div>

    {{-- Right rail --}}
    <div class="space-y-6">
        <x-ui.card title="Publish" icon="check-circle">
            <div class="space-y-4" x-data="{ scheduling: @js($errors->has('published_at') || $statusValue === ContentStatus::Scheduled->value), when: @js((string) $scheduleValue), now: @js($nowLocal) }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm text-slate-600 dark:text-slate-300">Status</span>
                    @include('admin.marketing.partials.enum-badge', ['value' => $post?->status ?? ContentStatus::Draft])
                </div>

                @if ($post?->published_at && $statusValue === ContentStatus::Published->value)
                    <p class="text-xs text-slate-500 dark:text-slate-400">Live since {{ app_datetime($post->published_at) }}.</p>
                @elseif ($post?->published_at && $statusValue === ContentStatus::Scheduled->value)
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25">
                        Goes live {{ app_datetime($post->published_at) }} — {{ \App\Support\Format::forHumans($post->published_at) }}.
                    </p>
                @endif

                @if ($isEditor && ! empty($authorOptions))
                    <x-ui.form.select name="author_id" label="Author" :options="$authorOptions" :selected="$post?->author_id ?? $user?->getKey()" placeholder="The company (no named author)" />
                @endif

                <x-ui.form.toggle name="is_featured" label="Featured post" description="Shown as the hero of the blog index." :checked="(bool) ($post?->is_featured ?? false)" />

                <div class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <x-ui.button type="submit" name="intent" value="save" variant="secondary" icon="check" :block="true">{{ in_array($statusValue, [ContentStatus::Published->value, ContentStatus::Scheduled->value], true) ? 'Save changes' : 'Save draft' }}</x-ui.button>

                    @if ($canChange)
                        <x-ui.button type="submit" name="intent" value="publish" icon="check-circle" :block="true" x-bind:disabled="scheduling">
                            {{ $statusValue === ContentStatus::Published->value ? 'Save and keep live' : 'Publish now' }}
                        </x-ui.button>

                        @if ($statusValue !== ContentStatus::Published->value)
                            <div class="rounded-lg ring-1 ring-inset ring-slate-200 dark:ring-slate-700">
                                <button type="button" x-on:click="scheduling = ! scheduling" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-sm font-medium text-slate-700 dark:text-slate-200" x-bind:aria-expanded="scheduling ? 'true' : 'false'">
                                    <span class="inline-flex items-center gap-2"><x-ui.icon name="calendar-days" class="h-4 w-4 text-slate-400" /> Schedule…</span>
                                    <x-ui.icon name="chevron-down" class="h-4 w-4 text-slate-400 transition" x-bind:class="scheduling ? 'rotate-180' : ''" />
                                </button>
                                <div x-show="scheduling" x-cloak class="space-y-2 border-t border-slate-200 px-3 py-3 dark:border-slate-700">
                                    <label for="field-published_at" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                                        Publish at <span class="font-normal text-slate-400">({{ $timezone }})</span>
                                    </label>
                                    <input
                                        id="field-published_at"
                                        type="datetime-local"
                                        name="published_at"
                                        x-model="when"
                                        x-bind:disabled="! scheduling"
                                        min="{{ $minSchedule }}"
                                        class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                                    >
                                    <p class="text-xs text-slate-600 dark:text-slate-300" x-show="when" x-text="(() => {
                                        const [d, t] = when.split('T');
                                        if (! d || ! t) return '';
                                        const [y, m, day] = d.split('-').map(Number);
                                        const [h, min] = t.split(':').map(Number);
                                        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                                        const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        const target = Date.UTC(y, m - 1, day, h, min);
                                        const [nd, nt] = now.split('T');
                                        const [ny, nm, nday] = nd.split('-').map(Number);
                                        const [nh, nmin] = nt.split(':').map(Number);
                                        const diff = Math.round((target - Date.UTC(ny, nm - 1, nday, nh, nmin)) / 60000);
                                        const weekday = days[new Date(Date.UTC(y, m - 1, day)).getUTCDay()];
                                        let rel = diff < 5 ? 'too soon — pick at least 5 minutes ahead' : (diff < 60 ? 'in ' + diff + ' minutes' : (diff < 2880 ? 'in about ' + Math.round(diff / 60) + ' hours' : 'in ' + Math.round(diff / 1440) + ' days'));
                                        return weekday + ' ' + day + ' ' + months[m - 1] + ' ' + y + ' at ' + String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0') + ' — ' + rel;
                                    })()"></p>
                                    <x-ui.form.error for="published_at" />
                                    <x-ui.button type="submit" name="intent" value="schedule" variant="secondary" icon="calendar-days" size="sm" :block="true">Schedule</x-ui.button>
                                </div>
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-slate-500 dark:text-slate-400">You can save drafts. Publishing and scheduling need the change-status permission for this post.</p>
                    @endif
                </div>

                @if ($post && \Illuminate\Support\Facades\Route::has('admin.blog-posts.preview-link'))
                    <div x-data="cmsCopy(@js(['url' => route('admin.blog-posts.preview-link', $post)]))" class="border-t border-slate-100 pt-4 dark:border-slate-800">
                        <x-ui.button variant="ghost" size="sm" icon="share" x-on:click="copy()" :block="true">
                            <span x-text="copied ? 'Preview link copied' : 'Copy preview link'">Copy preview link</span>
                        </x-ui.button>
                        <p class="mt-1 text-center text-2xs text-slate-400 dark:text-slate-500">A signed link for signed-in reviewers; never indexed, never counted.</p>
                    </div>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="Categories and tags" icon="tag">
            <div class="space-y-5">
                <x-ui.form.select name="blog_category_id" label="Category" :options="$categoryOptions ?? []" :selected="$post?->blog_category_id" placeholder="Uncategorised" />

                @include('admin.marketing.partials.list-input', [
                    'name' => 'tags',
                    'label' => 'Tags',
                    'values' => $tagNames,
                    'mode' => 'tags',
                    'max' => 40,
                    'maxLength' => 40,
                    'suggestions' => $tagSuggestions ?? [],
                    'placeholder' => 'Type a tag and press Enter',
                    'help' => 'Existing tags are matched ignoring case; new ones are created when you save.',
                ])
            </div>
        </x-ui.card>

    </div>
</div>
