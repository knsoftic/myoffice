{{--
    The event editor — one form, shared by create and edit.

    @include('admin.events.partials.form', ['event' => $event])   // null on create

    Reads (controller variables of create / edit):
      $event            ?App\Models\Cms\Event   with coverImage loaded on edit
      $reservedSlugs    list<string>
      $mediaLibrary     list<array>             rendered once by the host page
      $maxUploadMb      int
      $timezone         string                  the display timezone every moment below is typed in
      $canChangeStatus  bool                    only decides what this form explains, never what it may do

    Posts (StoreEventRequest / UpdateEventRequest):
      title, slug, summary, description, cover_media_id, starts_at, ends_at, is_all_day,
      location, is_online, meeting_url, registration_url, capacity, sort_order.

    **status, published_at and is_featured are not on this form and cannot be.** Publishing is not
    editing: both write requests declare all three `prohibited`, the model does not list them in
    $fillable, and they are written only by admin.events.status / admin.events.featured, which are
    behind `events.change_status`. The publish controls therefore live in the page header, outside
    this form — a form cannot be nested inside another one, and those two endpoints are their own
    forms.

    The four database CHECK constraints are re-stated as help text here and enforced server side by
    the Form Request. The hints save a round trip; they are not the rule.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $event = $event ?? null;
    $timezone = $timezone ?? \App\Support\Format::displayTimezone();
    $canChange = (bool) ($canChangeStatus ?? auth()->user()?->can('events.change_status'));

    $statusValue = $event?->status instanceof \BackedEnum
        ? $event->status->value
        : ($event?->status ?? ContentStatus::Draft->value);

    // Ever live: published now, or unpublished / archived with its first-publish moment kept.
    $wasPublished = $event !== null && $event->published_at !== null && $statusValue !== ContentStatus::Scheduled->value;

    $coverAsset = $event?->relationLoaded('coverImage') && $event->getRelation('coverImage') instanceof \App\Models\Cms\MediaAsset
        ? $event->getRelation('coverImage')
        : null;

    $isOnline = (bool) old('is_online', $event?->is_online ?? false);
    $isAllDay = (bool) old('is_all_day', $event?->is_all_day ?? false);

    $startsValue = old('starts_at', $event?->starts_at ? app_input_datetime($event->starts_at) : '');
    $endsValue = old('ends_at', $event?->ends_at ? app_input_datetime($event->ends_at) : '');
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    {{-- Left: what the event is --}}
    <div class="space-y-6 xl:col-span-2">
        <x-ui.card>
            <div class="space-y-5">
                <div>
                    <label for="field-title" class="sr-only">Title</label>
                    <input
                        id="field-title"
                        type="text"
                        name="title"
                        value="{{ old('title', $event?->title) }}"
                        required
                        maxlength="200"
                        placeholder="Event title"
                        @class([
                            'block w-full rounded-lg border bg-white px-3 py-2.5 text-xl font-semibold tracking-tight text-slate-900 shadow-sm placeholder:text-slate-300 focus:ring-2 dark:bg-slate-950/40 dark:text-white dark:placeholder:text-slate-600',
                            'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20' => $errors->has('title'),
                            'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700' => ! $errors->has('title'),
                        ])
                    >
                    <x-ui.form.error for="title" />
                </div>

                @include('admin.marketing.partials.slug-field', [
                    'value' => $event?->slug,
                    'sourceId' => 'field-title',
                    'prefix' => url('/events').'/',
                    'published' => $wasPublished,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => \App\Models\Cms\Event::SLUG_MAX_LENGTH,
                ])

                <div>
                    <x-ui.form.textarea
                        name="summary"
                        label="Summary"
                        :value="$event?->summary"
                        :rows="3"
                        maxlength="500"
                        optional
                        help="One or two sentences. This is what the events list and the search result show."
                    />
                    @include('admin.cms.partials.length-meter', ['for' => 'field-summary', 'max' => 500, 'idealMin' => 100, 'idealMax' => 200])
                </div>

                @include('admin.cms.partials.richtext', [
                    'name' => 'description',
                    'id' => 'field-description',
                    'label' => 'Description',
                    'value' => $event?->description,
                    'rows' => 14,
                    'required' => false,
                ])
            </div>
        </x-ui.card>

        <x-ui.card title="When" subtitle="Typed and shown in {{ $timezone }}; stored in UTC." icon="calendar-days">
            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="field-starts_at" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Starts <span class="text-rose-500">*</span>
                        </label>
                        <input
                            id="field-starts_at"
                            type="datetime-local"
                            name="starts_at"
                            value="{{ $startsValue }}"
                            required
                            @class([
                                'mt-1.5 block w-full rounded-lg text-sm shadow-sm dark:bg-slate-950/40 dark:text-white',
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('starts_at'),
                                'border-slate-300 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700' => ! $errors->has('starts_at'),
                            ])
                        >
                        <x-ui.form.error for="starts_at" />
                    </div>

                    <div>
                        <label for="field-ends_at" class="block text-sm font-medium text-slate-700 dark:text-slate-200">
                            Ends <span class="font-normal text-slate-400">(optional)</span>
                        </label>
                        <input
                            id="field-ends_at"
                            type="datetime-local"
                            name="ends_at"
                            value="{{ $endsValue }}"
                            @class([
                                'mt-1.5 block w-full rounded-lg text-sm shadow-sm dark:bg-slate-950/40 dark:text-white',
                                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/30' => $errors->has('ends_at'),
                                'border-slate-300 focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700' => ! $errors->has('ends_at'),
                            ])
                        >
                        <x-ui.form.error for="ends_at" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Leave it empty for an announcement — something that happens at one moment rather than between two.
                            An end before the start is refused.
                        </p>
                    </div>
                </div>

                <x-ui.form.toggle
                    name="is_all_day"
                    label="All-day event"
                    description="The website shows the dates without times. Pick the times anyway — they decide the order."
                    :checked="$isAllDay"
                />
            </div>
        </x-ui.card>

        <x-ui.card title="Where" subtitle="Every event needs somewhere to be: a place, or a link." icon="building-office">
            <div class="space-y-5" x-data="{ online: @js($isOnline) }">
                <x-ui.form.toggle
                    name="is_online"
                    label="This event happens online"
                    description="An online event needs a joining link; an in-person one needs a place. The database refuses a row with neither."
                    :checked="$isOnline"
                    x-model="online"
                />

                <div x-show="! online" x-cloak>
                    <x-ui.form.input
                        name="location"
                        label="Place"
                        :value="$event?->location"
                        maxlength="255"
                        icon="building-office"
                        placeholder="Campus, room, street address"
                        help="Required for an event that is not online."
                    />
                </div>

                <div x-show="online" x-cloak>
                    <x-ui.form.input
                        name="meeting_url"
                        label="Joining link"
                        type="url"
                        :value="$event?->meeting_url"
                        maxlength="500"
                        icon="video-camera"
                        placeholder="https://…"
                        help="Required for an online event. Without it a visitor is told they can attend from home and given no way to."
                    />
                </div>

                <x-ui.form.input
                    name="registration_url"
                    label="Registration link"
                    type="url"
                    :value="$event?->registration_url"
                    maxlength="500"
                    icon="link"
                    optional
                    placeholder="https://…"
                    help="Where a visitor signs up, if that happens somewhere else."
                />
            </div>
        </x-ui.card>
    </div>

    {{-- Right rail --}}
    <div class="space-y-6">
        <x-ui.card title="Publication" icon="check-circle">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-sm text-slate-600 dark:text-slate-300">Status</span>
                    @include('admin.marketing.partials.enum-badge', ['value' => $event?->status ?? ContentStatus::Draft])
                </div>

                @if ($event?->published_at && $statusValue === ContentStatus::Published->value)
                    <p class="text-xs text-slate-500 dark:text-slate-400">Live since {{ app_datetime($event->published_at) }}.</p>
                @elseif ($event?->published_at && $statusValue === ContentStatus::Scheduled->value)
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/25">
                        Goes live {{ app_datetime($event->published_at) }} — {{ \App\Support\Format::forHumans($event->published_at) }}.
                    </p>
                @endif

                @if ($event?->is_featured)
                    <x-ui.badge color="amber" size="sm" icon="star">Featured on the board</x-ui.badge>
                @endif

                <p class="border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    @if ($event === null)
                        Saving creates a draft. Nothing here goes public until somebody publishes it.
                    @elseif ($canChange)
                        Publishing, scheduling, archiving and featuring are the buttons at the top of this page — a separate
                        permission from editing, so saving here never changes what the public sees.
                    @else
                        You can save changes to this event. Publishing, scheduling, archiving and featuring need the
                        permission to change an event’s status.
                    @endif
                </p>
            </div>
        </x-ui.card>

        <x-ui.card title="Cover image" icon="photo">
            <x-cms.image-field
                name="cover_media_id"
                label="Cover"
                :asset="$coverAsset"
                profile="Banner"
                help="Pick an image from the media library. It is the library that stores and serves it — this form never writes a file path."
            />
        </x-ui.card>

        <x-ui.card title="Seats and order" icon="users">
            <div class="space-y-5">
                <x-ui.form.input
                    name="capacity"
                    label="Capacity"
                    type="number"
                    :value="$event?->capacity"
                    min="1"
                    max="{{ \App\Models\Cms\Event::MAX_CAPACITY }}"
                    optional
                    placeholder="No limit"
                    help="Leave empty for an event with no limit. Zero is refused: no seats left is a state, not a setting."
                />

                <x-ui.form.input
                    name="sort_order"
                    label="Manual order"
                    type="number"
                    :value="$event?->sort_order ?? 0"
                    min="0"
                    optional
                    help="Only breaks ties. The board sorts by when the event happens."
                />
            </div>
        </x-ui.card>
    </div>
</div>
