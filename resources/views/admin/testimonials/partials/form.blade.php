{{--
    The testimonial form, shared by create and edit (phase-04 §8.5, §2.10, §6.11).

    @include('admin.testimonials.partials.form', ['testimonial' => $testimonial])   // null on create

    Reads: $typeOptions (TestimonialType::options()), $maxUploadMb (optional).

    Posts (StoreTestimonialRequest / UpdateTestimonialRequest, multipart): type, author_name,
    author_photo_media_id + author_photo (upload), author_designation, author_company (required for a client),
    course_name (required for a student), rating (1-5 or empty), review (max 2000, HTML stripped on write),
    review_date, sort_order.
    Status is never posted: approval, rejection and featuring are the moderation actions (§6.5). `source` is set
    by the controller (admin) and `client_id` / `student_id` stay null until Phases 5 and 15 add their pickers.
--}}

@php
    $testimonial = $testimonial ?? null;
    $typeValue = (string) old('type', $testimonial?->type instanceof \BackedEnum ? $testimonial->type->value : ($testimonial?->type ?? 'client'));

    $photoAsset = null;
    foreach (['authorPhotoAsset', 'authorPhoto', 'authorPhotoMedia'] as $relationName) {
        if ($testimonial?->relationLoaded($relationName) && $testimonial->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $photoAsset = $testimonial->getRelation($relationName);
            break;
        }
    }

    $types = $typeOptions ?? ['client' => 'Client', 'student' => 'Student', 'other' => 'Other'];
@endphp

<div x-data="{ type: @js($typeValue) }" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-ui.card title="Testimonial" class="lg:col-span-2">
        <div class="space-y-5">
            <fieldset>
                <legend class="mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">Who gave it <span class="text-rose-500">*</span></legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($types as $value => $label)
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 ring-inset transition has-[:checked]:bg-brand-50 has-[:checked]:text-brand-800 has-[:checked]:ring-brand-300 dark:has-[:checked]:bg-brand-500/10 dark:has-[:checked]:text-brand-200 dark:has-[:checked]:ring-brand-500/40 ring-slate-200 text-slate-700 dark:ring-slate-700 dark:text-slate-200">
                            <input type="radio" name="type" value="{{ $value }}" x-model="type" @checked($typeValue === (string) $value) class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500/40 dark:border-slate-600 dark:bg-slate-800">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <x-ui.form.error for="type" />
            </fieldset>

            <x-ui.form.input name="author_name" label="Name" :value="$testimonial?->author_name" required maxlength="150" />

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2" x-show="type !== 'student'">
                <x-ui.form.input name="author_designation" label="Designation" :value="$testimonial?->author_designation" maxlength="150" placeholder="e.g. CTO" optional />
                <div>
                    <x-ui.form.input name="author_company" label="Company" :value="$testimonial?->author_company" maxlength="150" x-bind:required="type === 'client'" />
                    <p x-show="type === 'client'" class="mt-1 text-2xs text-slate-400 dark:text-slate-500">Required for a client testimonial.</p>
                </div>
            </div>

            <div x-show="type === 'student'" x-cloak>
                <x-ui.form.input name="course_name" label="Course" :value="$testimonial?->course_name" maxlength="150" x-bind:required="type === 'student'" help="Free text until the institute's course list exists." />
            </div>

            <div>
                <x-ui.form.textarea name="review" label="Review" :value="$testimonial?->review" :rows="6" maxlength="2000" required help="Plain text. Formatting is removed when saved." />
                @include('admin.cms.partials.length-meter', ['for' => 'field-review', 'max' => 2000])
                @if ($testimonial)
                    <p class="mt-1.5 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <x-ui.icon name="information-circle" class="mt-px h-3.5 w-3.5" />
                        Corrections to the review text are recorded in the activity log with the old and the new wording.
                    </p>
                @endif
            </div>
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
                            <option value="{{ $stars }}" @selected((string) old('rating', $testimonial?->rating) === (string) $stars)>{{ $stars }} {{ $stars === 1 ? 'star' : 'stars' }}</option>
                        @endforeach
                    </select>
                    <x-ui.form.error for="rating" />
                </div>

                <x-ui.form.input name="review_date" type="date" label="Date given" :value="$testimonial?->review_date ? app_date($testimonial->review_date, 'Y-m-d') : null" optional />

                <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$testimonial?->sort_order ?? 0" min="0" step="1" />
            </div>
        </x-ui.card>

        <x-ui.card title="Photo">
            <x-cms.image-field name="author_photo_media_id" upload="author_photo" label="Author photo" :asset="$photoAsset" profile="Thumbnail" :max-mb="$maxUploadMb ?? null" />
        </x-ui.card>
    </div>
</div>
