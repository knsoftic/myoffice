{{--
    The student review form, shared by create and edit (phase-04 §8.5, §2.11, §6.11).

    @include('admin.student-reviews.partials.form', ['review' => $review])   // null on create

    Reads: $courseOptions (optional, distinct course names offered as suggestions), $maxUploadMb (optional).

    Posts (StoreStudentReviewRequest / UpdateStudentReviewRequest, multipart): student_name,
    student_photo_media_id + student_photo (upload), course_name, rating (1-5 or empty), review (max 2000,
    plain text), video_url (YouTube / Vimeo only), sort_order.
    Status is never posted (moderation actions, §6.5). `student_id` / `course_id` stay null until Phases 15 / 14.
--}}

@php
    $review = $review ?? null;

    $photoAsset = null;
    foreach (['studentPhotoAsset', 'studentPhoto', 'studentPhotoMedia'] as $relationName) {
        if ($review?->relationLoaded($relationName) && $review->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $photoAsset = $review->getRelation($relationName);
            break;
        }
    }
@endphp

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-ui.card title="Review" class="lg:col-span-2">
        <div class="space-y-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.form.input name="student_name" label="Student name" :value="$review?->student_name" required maxlength="150" />
                <div>
                    <x-ui.form.input name="course_name" label="Course" :value="$review?->course_name" maxlength="150" optional list="course-suggestions" help="Free text until the institute's course list exists." />
                    <datalist id="course-suggestions">
                        @foreach ((array) ($courseOptions ?? []) as $courseOption)
                            <option value="{{ $courseOption }}"></option>
                        @endforeach
                    </datalist>
                </div>
            </div>

            <div>
                <x-ui.form.textarea name="review" label="Review" :value="$review?->review" :rows="6" maxlength="2000" required help="Plain text. Formatting is removed when saved." />
                @include('admin.cms.partials.length-meter', ['for' => 'field-review', 'max' => 2000])
                @if ($review)
                    <p class="mt-1.5 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="information-circle" class="mt-px h-3.5 w-3.5" />
                        Corrections to the review text are recorded in the activity log with the old and the new wording.
                    </p>
                @endif
            </div>

            @include('admin.marketing.partials.video-url', ['value' => $review?->video_url])
        </div>
    </x-ui.card>

    <div class="space-y-6">
        <x-ui.card title="Details">
            <div class="space-y-5">
                <div>
                    <label for="field-rating" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Rating <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="field-rating" name="rating" class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white">
                        <option value="">No rating (no stars shown)</option>
                        @foreach ([5, 4, 3, 2, 1] as $stars)
                            <option value="{{ $stars }}" @selected((string) old('rating', $review?->rating) === (string) $stars)>{{ $stars }} {{ $stars === 1 ? 'star' : 'stars' }}</option>
                        @endforeach
                    </select>
                    <x-ui.form.error for="rating" />
                </div>

                <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$review?->sort_order ?? 0" min="0" step="1" />
            </div>
        </x-ui.card>

        <x-ui.card title="Photo">
            <x-cms.image-field name="student_photo_media_id" upload="student_photo" label="Student photo" :asset="$photoAsset" profile="Thumbnail" :max-mb="$maxUploadMb ?? null" />
        </x-ui.card>
    </div>
</div>
