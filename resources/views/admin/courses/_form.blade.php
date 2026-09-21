{{--
    The course form — five tabs (§62, phase-14-17 §8.3).

    The Fees tab is **not rendered at all** without `courses.view_financial`, and the Form Request
    strips those fields from the payload for the same user. Hiding the tab is the courtesy; the strip
    is the control (FT-10) — otherwise a crafted POST against a tab nobody can see would set a price.

    The slug locks once the course is published: `/courses/{slug}` may be in a WhatsApp forward by then,
    so moving it takes a reason, which the activity row carries.

    Expects: $categories · $branches · $levels · $modes · $units · $seesMoney · $defaultClassDuration ·
             optionally $course.
--}}

@php
    $course = $course ?? null;
    $isPublished = $course?->published_at !== null;

    $value = static fn (string $field, $fallback = null) => old($field, $course?->{$field} ?? $fallback);

    $enumValue = static function (string $field, $current, string $fallback): string {
        $old = old($field);

        if ($old !== null) {
            return (string) $old;
        }

        return $current instanceof \BackedEnum ? $current->value : $fallback;
    };

    // Whatever the column or the old input holds, rendered one item per line.
    $listText = static function (string $field) use ($course): string {
        $value = old($field, $course?->{$field} ?? []);

        return is_array($value) ? implode("\n", $value) : (string) $value;
    };

    $tabs = ['basics' => 'Basics', 'content' => 'Content'];

    if ($seesMoney) {
        $tabs['fees'] = 'Fees';
    }

    $tabs['media'] = 'Media';
    $tabs['seo'] = 'Search engines';
@endphp

<div x-data="uiTabs('basics')" class="space-y-4">
    <x-ui.tabs variant="pill"
               :tabs="collect($tabs)->map(fn ($label, $key) => ['label' => $label, 'key' => $key])->values()->all()" />

    {{-- ------------------------------------------------------------- Basics --}}
    <div x-show="is('basics')">
        <x-ui.card title="What it is">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-ui.form.input name="name" label="Course name" required maxlength="180"
                                     :value="$value('name')" placeholder="Full-stack Web Development" />
                </div>

                <x-ui.form.input name="code" label="Course code" required maxlength="32"
                                 :value="$value('code')" placeholder="WEB-101"
                                 help="What staff call it. It has to be unique." />

                <x-ui.form.input name="slug" label="Web address" maxlength="200"
                                 :value="$value('slug')"
                                 prefix="/courses/"
                                 :readonly="false"
                                 :help="$isPublished
                                     ? 'This course is published, so changing the address breaks links already shared. It takes a reason.'
                                     : 'Left blank it is made from the name.'" />

                <x-ui.form.select name="course_category_id" label="Category" required placeholder="Choose a category">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((int) $value('course_category_id') === $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="branch_id" label="Branch" placeholder="Offered at every branch"
                                  help="Leave it blank unless only one branch runs this course.">
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) $value('branch_id') === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="level" label="Level" required>
                    @foreach ($levels as $level)
                        <option value="{{ $level->value }}" @selected($enumValue('level', $course?->level, 'beginner') === $level->value)>
                            {{ $level->label() }} — {{ $level->description() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <x-ui.form.select name="delivery_mode" label="How it is taught" required>
                    @foreach ($modes as $mode)
                        <option value="{{ $mode->value }}" @selected($enumValue('delivery_mode', $course?->delivery_mode, 'physical') === $mode->value)>
                            {{ $mode->label() }}
                        </option>
                    @endforeach
                </x-ui.form.select>

                <div class="grid grid-cols-2 gap-3">
                    <x-ui.form.input type="number" min="1" name="duration_value" label="Duration"
                                     :value="$value('duration_value')" />
                    <x-ui.form.select name="duration_unit" label="&nbsp;">
                        @foreach ($units as $unit)
                            <option value="{{ $unit->value }}" @selected($enumValue('duration_unit', $course?->duration_unit, 'weeks') === $unit->value)>
                                {{ $unit->label() }}
                            </option>
                        @endforeach
                    </x-ui.form.select>
                </div>

                <x-ui.form.input type="number" min="1" name="total_classes" label="Number of classes"
                                 :value="$value('total_classes')" />

                <x-ui.form.input type="number" min="5" max="1440" name="class_duration_minutes"
                                 label="Minutes per class"
                                 :value="$value('class_duration_minutes')"
                                 :placeholder="$defaultClassDuration"
                                 help="Blank falls back to the institute default." />

                <x-ui.form.input type="number" min="0" name="sort_order" label="Catalogue order"
                                 :value="$value('sort_order', 0)" />
            </div>

            <x-slot:footer>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.form.toggle name="is_featured" label="Featured"
                                      description="Pinned to the top of the public catalogue."
                                      :checked="(bool) $value('is_featured', false)" />
                    <x-ui.form.toggle name="admission_open" label="Taking admissions"
                                      description="Both this and the institute-wide switch have to be on."
                                      :checked="(bool) $value('admission_open', true)" />
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>

    {{-- ------------------------------------------------------------ Content --}}
    <div x-show="is('content')" x-cloak>
        <x-ui.card title="What a visitor reads">
            <div class="space-y-4">
                <x-ui.form.textarea name="short_description" label="Short description" rows="2" maxlength="500"
                                    :value="$value('short_description')"
                                    help="One or two sentences. It is the card text and the search-result snippet." />

                <x-ui.form.textarea name="full_description" label="Full description" rows="8"
                                    :value="$value('full_description')"
                                    help="Sanitised on save — the public page renders it." />

                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- One per line. The service normalises whatever arrives — a textarea or an
                         array of repeatable rows — into a flat list of trimmed, non-empty strings. --}}
                    <x-ui.form.textarea name="requirements" label="Requirements" rows="5"
                                        :value="$listText('requirements')"
                                        help="One per line." />

                    <x-ui.form.textarea name="outcomes" label="Learning outcomes" rows="5"
                                        :value="$listText('outcomes')"
                                        help="One per line." />
                </div>

                <x-ui.form.toggle name="certificate_available" label="Certificate on completion"
                                  description="Shown on the public page and read by the certificate module."
                                  :checked="(bool) $value('certificate_available', false)" />
            </div>
        </x-ui.card>
    </div>

    {{-- --------------------------------------------------------------- Fees --}}
    @if ($seesMoney)
        <div x-show="is('fees')" x-cloak>
            <x-ui.card title="The price list"
                       subtitle="These are what the next student is quoted. Changing them never touches an admission already agreed.">
                <div x-data="{
                        course: {{ (float) $value('course_fee', 0) }},
                        admission: {{ (float) $value('admission_fee', 0) }},
                        registration: {{ (float) $value('registration_fee', 0) }},
                        installments: {{ $value('installment_available', false) ? 'true' : 'false' }},
                        get total() { return (Number(this.course) + Number(this.admission) + Number(this.registration)); }
                     }"
                     class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.form.input type="number" step="0.01" min="0" name="course_fee" label="Course fee"
                                         :value="$value('course_fee', '0.00')" x-model="course"
                                         :prefix="setting('localization.currency_symbol', 'Rs')" />
                        <x-ui.form.input type="number" step="0.01" min="0" name="admission_fee" label="Admission fee"
                                         :value="$value('admission_fee', '0.00')" x-model="admission"
                                         :prefix="setting('localization.currency_symbol', 'Rs')" />
                        <x-ui.form.input type="number" step="0.01" min="0" name="registration_fee" label="Registration fee"
                                         :value="$value('registration_fee', '0.00')" x-model="registration"
                                         :prefix="setting('localization.currency_symbol', 'Rs')" />
                    </div>

                    <div class="flex items-baseline justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60">
                        <span class="text-sm text-slate-600 dark:text-slate-300">Total quoted at admission</span>
                        <span class="text-lg font-semibold tabular-nums text-slate-900 dark:text-white"
                              x-text="'{{ setting('localization.currency_symbol', 'Rs') }} ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                    </div>

                    <x-ui.form.input type="number" step="0.01" min="0" name="monthly_fee" label="Monthly fee"
                                     :value="$value('monthly_fee')"
                                     :prefix="setting('localization.currency_symbol', 'Rs')"
                                     help="Only for a course billed monthly. Blank means no monthly head." />

                    <div class="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
                        <x-ui.form.toggle name="installment_available" label="Offers installments"
                                          :checked="(bool) $value('installment_available', false)"
                                          x-model="installments" />

                        <div x-show="installments" x-cloak class="grid gap-4 sm:grid-cols-2">
                            <x-ui.form.input type="number" min="1" max="36" name="max_installments"
                                             label="Maximum installments"
                                             :value="$value('max_installments', 1)" />
                            <x-ui.form.input name="installment_note" label="Note for the public page" maxlength="255"
                                             :value="$value('installment_note')" />
                        </div>
                    </div>

                    <p class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-300">
                        A fee change applies to the <strong>next</strong> admission. An admission's agreed
                        figures are frozen from its first charge, so nothing already sold moves — and a
                        correction to one of those is a discount or a correction row, never an edit here.
                    </p>
                </div>
            </x-ui.card>
        </div>
    @endif

    {{-- -------------------------------------------------------------- Media --}}
    <div x-show="is('media')" x-cloak>
        <x-ui.card title="Pictures and video">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.form.input name="image_path" label="Hero image path" maxlength="255"
                                 :value="$value('image_path')"
                                 help="Stored on the public disk under courses/." />
                <x-ui.form.input name="thumbnail_path" label="Card image path" maxlength="255"
                                 :value="$value('thumbnail_path')" />
                <div class="sm:col-span-2">
                    <x-ui.form.input type="url" name="promo_video_url" label="Promo video" maxlength="255"
                                     :value="$value('promo_video_url')"
                                     placeholder="https://www.youtube.com/watch?v=…" />
                </div>
            </div>
        </x-ui.card>
    </div>

    {{-- ---------------------------------------------------------------- SEO --}}
    <div x-show="is('seo')" x-cloak>
        <x-ui.card title="How it looks in a search result">
            <div class="space-y-4">
                <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                    <p class="truncate text-xs text-emerald-700 dark:text-emerald-400">
                        {{ rtrim(config('app.url'), '/') }}/courses/{{ $value('slug') ?: 'your-course' }}
                    </p>
                    <p class="mt-0.5 truncate text-base text-sky-800 dark:text-sky-300">
                        {{ $value('seo_title') ?: ($value('name') ?: 'Course title') }}
                    </p>
                    <p class="mt-0.5 line-clamp-2 text-sm text-slate-600 dark:text-slate-400">
                        {{ $value('seo_description') ?: ($value('short_description') ?: 'The description a search engine shows.') }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.form.input name="seo_title" label="SEO title" maxlength="180" :value="$value('seo_title')" />
                    <x-ui.form.input name="seo_keywords" label="Keywords" maxlength="255" :value="$value('seo_keywords')" />
                    <div class="sm:col-span-2">
                        <x-ui.form.textarea name="seo_description" label="SEO description" rows="2" maxlength="500"
                                            :value="$value('seo_description')" />
                    </div>
                    <x-ui.form.input name="og_image_path" label="Share image path" maxlength="255"
                                     :value="$value('og_image_path')" />
                    <x-ui.form.input type="url" name="canonical_url" label="Canonical URL" maxlength="255"
                                     :value="$value('canonical_url')"
                                     help="Only when this page duplicates another one." />
                </div>

                <x-ui.form.toggle name="is_indexable" label="Let search engines index it"
                                  description="Off keeps it out of the sitemap as well — one instruction, not two that contradict each other."
                                  :checked="(bool) $value('is_indexable', true)" />
            </div>
        </x-ui.card>
    </div>

    @if ($isPublished)
        <x-ui.card>
            <x-ui.form.input name="slug_change_reason" label="Reason for a changed web address" maxlength="255"
                             help="Only needed if you changed the address above. It is recorded with the change." />
        </x-ui.card>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button type="submit" variant="primary" icon="check">
                {{ $course?->exists ? 'Save changes' : 'Create the course' }}
            </x-ui.button>
            <x-ui.button variant="ghost"
                         :href="$course?->exists ? route('admin.courses.show', $course) : route('admin.courses.index')">
                Cancel
            </x-ui.button>

            @unless ($course?->exists)
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    It is created as a draft — add an outline before publishing it.
                </span>
            @endunless
        </div>
    </x-ui.card>
</div>
