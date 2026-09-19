{{--
    The job opening editor body, shared by create and edit (phase-04 §8.8, §2.18, §6.8, §6.11).

    @include('admin.jobs.partials.form', ['job' => $job])   // null on create

    Reads (controller variables of create / edit):
      $statusOptions           array<string, string>   JobOpeningStatus::options()
      $employmentTypeOptions   array<string, string>   App\Enums\EmploymentType::options()
      $workModeOptions         array<string, string>   WorkMode::options()
      $salaryPeriodOptions     array<string, string>   monthly / yearly / hourly / project
      $departmentOptions       list<string>            existing department labels (suggestions)
      $reservedSlugs           list<string>
      $seoMeta, $seoInherited, $publicUrl              SEO (optional)

    Posts (StoreJobOpeningRequest / UpdateJobOpeningRequest):
      title, slug, department, location, work_mode, employment_type, openings_count, experience_min_years,
      experience_note, salary_min, salary_max (decimal strings; min <= max through Money::compare), salary_period,
      salary_visible, description, responsibilities, requirements (rich text), skills[] (max 30), status
      (jobs.change_status holders only), deadline (Y-m-d; an open job with a past deadline is refused),
      is_featured, sort_order, seo[...]. `department_id` stays null until Phase 7 (§2.1).
--}}

@php
    $job = $job ?? null;
    $canStatus = (bool) auth()->user()?->can('jobs.change_status');
    $statusValue = $job?->status instanceof \BackedEnum ? $job->status->value : ($job?->status ?? 'draft');
    $wasOpen = $job !== null && $job->opened_at !== null;
    $currency = \App\Support\Format::currencySymbol();

    $tabFields = [
        'compensation' => ['salary_min', 'salary_max', 'salary_period', 'salary_visible'],
        'content' => ['description', 'responsibilities', 'requirements', 'skills'],
        'publishing' => ['status', 'deadline', 'is_featured', 'sort_order', 'seo'],
    ];
    $initialTab = 'details';
    foreach (array_keys($errors->getMessages()) as $errorKey) {
        $root = \Illuminate\Support\Str::before($errorKey, '.');
        foreach ($tabFields as $tab => $fields) {
            if (in_array($root, $fields, true)) {
                $initialTab = $tab;
                break 2;
            }
        }
    }

    $periods = $salaryPeriodOptions ?? ['monthly' => 'Per month', 'yearly' => 'Per year', 'hourly' => 'Per hour', 'project' => 'Per project'];
@endphp

<div x-data="uiTabs(@js($initialTab))">
    <x-ui.card :padded="false">
        <div class="px-4 sm:px-5">
            <x-ui.tabs :tabs="[
                ['label' => 'Details', 'key' => 'details', 'icon' => 'briefcase'],
                ['label' => 'Compensation', 'key' => 'compensation', 'icon' => 'banknotes'],
                ['label' => 'Content', 'key' => 'content', 'icon' => 'document-text'],
                ['label' => 'Publishing', 'key' => 'publishing', 'icon' => 'globe-alt'],
            ]" />
        </div>

        <div x-show="is('details')" class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-2">
            <div class="space-y-5">
                <x-ui.form.input name="title" id="field-title" label="Job title" :value="$job?->title" required maxlength="180" />

                @include('admin.marketing.partials.slug-field', [
                    'value' => $job?->slug,
                    'sourceId' => 'field-title',
                    'prefix' => url('/careers').'/',
                    'published' => $wasOpen,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => 180,
                ])

                <div>
                    <x-ui.form.input name="department" label="Department" :value="$job?->department" maxlength="100" optional list="job-department-suggestions" help="Openings are grouped by department on the careers page." />
                    <datalist id="job-department-suggestions">
                        @foreach ((array) ($departmentOptions ?? []) as $departmentOption)
                            <option value="{{ $departmentOption }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <x-ui.form.input name="location" label="Location" :value="$job?->location" maxlength="150" placeholder="e.g. Lahore office" optional />
            </div>

            <div class="space-y-5">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.select name="work_mode" label="Work mode" :options="$workModeOptions ?? []" :selected="$job?->work_mode instanceof \BackedEnum ? $job->work_mode->value : ($job?->work_mode ?? 'onsite')" required />
                    <x-ui.form.select name="employment_type" label="Employment type" :options="$employmentTypeOptions ?? []" :selected="$job?->employment_type instanceof \BackedEnum ? $job->employment_type->value : $job?->employment_type" placeholder="Choose a type" required />
                </div>

                <x-ui.form.input name="openings_count" type="number" label="Openings" :value="$job?->openings_count ?? 1" min="1" max="255" step="1" required help="How many people you are hiring for this role." />

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.input name="experience_min_years" type="number" label="Minimum experience (years)" :value="$job?->experience_min_years" min="0" max="40" step="1" optional />
                    <x-ui.form.input name="experience_note" label="Experience note" :value="$job?->experience_note" maxlength="150" placeholder="e.g. Fresh graduates welcome" optional />
                </div>
            </div>
        </div>

        <div x-show="is('compensation')" x-cloak class="max-w-2xl space-y-5 p-4 sm:p-5">
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-ui.form.input name="salary_min" label="Salary from" :value="$job?->salary_min" :prefix="$currency" inputmode="decimal" autocomplete="off" optional />
                <x-ui.form.input name="salary_max" label="Salary to" :value="$job?->salary_max" :prefix="$currency" inputmode="decimal" autocomplete="off" optional help="Must not be lower than the starting figure." />
            </div>

            <x-ui.form.select name="salary_period" label="Paid" :options="$periods" :selected="$job?->salary_period ?? 'monthly'" />

            <x-ui.form.toggle
                name="salary_visible"
                label="Show the salary on the website"
                description="Switched off, the website shows “Negotiable” and the figures never appear in the page."
                :checked="(bool) ($job?->salary_visible ?? true)"
            />
        </div>

        <div x-show="is('content')" x-cloak class="space-y-6 p-4 sm:p-5">
            @include('admin.cms.partials.richtext', ['name' => 'description', 'label' => 'Description', 'value' => $job?->description, 'rows' => 10, 'required' => true])

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @include('admin.cms.partials.richtext', ['name' => 'responsibilities', 'label' => 'Responsibilities', 'value' => $job?->responsibilities, 'rows' => 8])
                @include('admin.cms.partials.richtext', ['name' => 'requirements', 'label' => 'Requirements', 'value' => $job?->requirements, 'rows' => 8])
            </div>

            <div class="max-w-2xl">
                @include('admin.marketing.partials.list-input', [
                    'name' => 'skills',
                    'label' => 'Skills',
                    'values' => $job?->skills ?? [],
                    'mode' => 'tags',
                    'max' => 30,
                    'maxLength' => 60,
                    'placeholder' => 'Type a skill and press Enter',
                ])
            </div>
        </div>

        <div x-show="is('publishing')" x-cloak class="grid grid-cols-1 gap-6 p-4 sm:p-5 xl:grid-cols-3">
            <div class="space-y-5">
                @if ($canStatus)
                    <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? []" :selected="$statusValue" help="Only an open job lists on the website and accepts applications." />
                @else
                    <div>
                        <p class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-200">Status</p>
                        @include('admin.marketing.partials.enum-badge', ['value' => $job?->status ?? 'draft'])
                    </div>
                @endif

                <x-ui.form.input name="deadline" type="date" label="Application deadline" :value="$job?->deadline ? app_date($job->deadline, 'Y-m-d') : null" :min="app_date(now(), 'Y-m-d')" optional help="The job closes itself the day after. An open job cannot be saved with a past deadline." />

                <x-ui.form.toggle name="is_featured" label="Featured" description="Listed first on the careers page." :checked="(bool) ($job?->is_featured ?? false)" />

                <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$job?->sort_order ?? 0" min="0" step="1" />
            </div>

            <div class="xl:col-span-2">
                <x-cms.seo-fields :model="$job" :seo="$seoMeta ?? null" :inherited="$seoInherited ?? null" :display-url="$publicUrl ?? url('/careers/'.($job?->slug ?? 'your-job'))" />
            </div>
        </div>
    </x-ui.card>
</div>
