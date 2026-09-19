{{--
    The success story form, shared by create and edit (phase-04 §8.6, §2.12, §6.4, §6.11). A single page with
    three sections — Story, Outcome, Publishing — because stories are staff-authored and have no approval queue.

    @include('admin.success-stories.partials.form', ['story' => $story])   // null on create

    Reads: $statusOptions (ContentStatus::options()), $courseOptions (optional suggestions), $platformOptions
    (optional suggestions: Upwork, Fiverr, …), $maxUploadMb (optional).

    Posts (StoreSuccessStoryRequest / UpdateSuccessStoryRequest, multipart): student_name,
    photo_media_id + photo (upload), course_name, headline, story (rich text, max 20000, sanitised on write),
    achievement, company_name, platform, video_url (YouTube / Vimeo), status (change_status holders only),
    is_featured, sort_order. `student_id` / `course_id` stay null until Phases 15 / 14.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $story = $story ?? null;
    $canStatus = (bool) auth()->user()?->can('success_stories.change_status');
    $statusValue = $story?->status instanceof \BackedEnum ? $story->status->value : ($story?->status ?? ContentStatus::Draft->value);

    $photoAsset = null;
    foreach (['photoAsset', 'photo', 'photoMedia'] as $relationName) {
        if ($story?->relationLoaded($relationName) && $story->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $photoAsset = $story->getRelation($relationName);
            break;
        }
    }
@endphp

<div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <x-ui.card title="Story" icon="document-text">
            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.input name="student_name" label="Student name" :value="$story?->student_name" required maxlength="150" />
                    <div>
                        <x-ui.form.input name="course_name" label="Course" :value="$story?->course_name" maxlength="150" optional list="story-course-suggestions" />
                        <datalist id="story-course-suggestions">
                            @foreach ((array) ($courseOptions ?? []) as $courseOption)
                                <option value="{{ $courseOption }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                </div>

                <div>
                    <x-ui.form.input name="headline" label="Headline" :value="$story?->headline" maxlength="180" placeholder="e.g. From zero to a full-time Flutter role in 6 months" optional />
                    @include('admin.cms.partials.length-meter', ['for' => 'field-headline', 'max' => 180])
                </div>

                @include('admin.cms.partials.richtext', [
                    'name' => 'story',
                    'label' => 'Story',
                    'value' => $story?->story,
                    'rows' => 14,
                    'required' => true,
                    'maxChars' => 20000,
                ])

                <x-ui.form.input name="achievement" label="Achievement" :value="$story?->achievement" maxlength="255" placeholder="e.g. Top Rated Plus on Upwork" optional />
            </div>
        </x-ui.card>

        <x-ui.card title="Outcome" icon="trophy">
            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.input name="company_name" label="Company" :value="$story?->company_name" maxlength="150" optional />
                    <div>
                        <x-ui.form.input name="platform" label="Platform" :value="$story?->platform" maxlength="100" placeholder="Upwork, Fiverr, …" optional list="story-platform-suggestions" />
                        <datalist id="story-platform-suggestions">
                            @foreach ((array) ($platformOptions ?? ['Upwork', 'Fiverr', 'Freelancer', 'Toptal', 'LinkedIn']) as $platformOption)
                                <option value="{{ $platformOption }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                </div>

                @include('admin.marketing.partials.video-url', ['value' => $story?->video_url])
            </div>
        </x-ui.card>
    </div>

    <div class="space-y-6">
        <x-ui.card title="Publishing" icon="eye">
            <div class="space-y-5">
                @if ($canStatus)
                    <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? ContentStatus::options()" :selected="$statusValue" />
                @else
                    <div>
                        <p class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-200">Status</p>
                        @include('admin.marketing.partials.enum-badge', ['value' => $story?->status ?? ContentStatus::Draft])
                    </div>
                @endif

                <x-ui.form.toggle name="is_featured" label="Featured" description="Featured stories lead the success-story section." :checked="(bool) ($story?->is_featured ?? false)" />

                <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$story?->sort_order ?? 0" min="0" step="1" />
            </div>
        </x-ui.card>

        <x-ui.card title="Photo" icon="photo">
            <x-cms.image-field name="photo_media_id" upload="photo" label="Student photo" :asset="$photoAsset" profile="Thumbnail" :max-mb="$maxUploadMb ?? null" />
        </x-ui.card>
    </div>
</div>
