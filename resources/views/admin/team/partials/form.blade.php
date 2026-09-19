{{--
    The team member editor body, shared by create and edit (phase-04 §8.4, §2.9, §6.4, §6.11).

    @include('admin.team.partials.form', ['member' => $member])   // null on create

    Reads (controller variables of create / edit):
      $socialPlatforms     array<string, array{label: string}>  SocialPlatform value => label, in display order
                           (from App\Enums\SocialPlatform::cases(); the form offers exactly these keys)
      $departmentOptions   list<string>                     existing department labels (suggestions only)
      $statusOptions       array<string, string>            ContentStatus::options()
      $reservedSlugs       list<string>
      $maxUploadMb         optional int

    Posts (StoreTeamMemberRequest / UpdateTeamMemberRequest, multipart):
      name, slug, designation, department, photo_media_id + photo (upload), bio (plain text), experience_years
      (0–60), experience_label, skills[] (max 20), social_links[<platform value>] (https URLs; empty ones are
      dropped by the service, unknown keys are a 422), portfolio_url, status (change_status holders only),
      is_public (0|1), sort_order.
      `department_id` and `employee_id` are deferred (§2.1): no picker until Phase 7.
--}}

@php
    use App\Enums\Cms\ContentStatus;

    $member = $member ?? null;
    $canStatus = (bool) auth()->user()?->can('team.change_status');
    $statusValue = $member?->status instanceof \BackedEnum ? $member->status->value : ($member?->status ?? ContentStatus::Draft->value);
    $wasPublished = $member !== null && $statusValue === ContentStatus::Published->value;

    $photoAsset = null;
    foreach (['photoAsset', 'photo', 'photoMedia'] as $relationName) {
        if ($member?->relationLoaded($relationName) && $member->getRelation($relationName) instanceof \App\Models\Cms\MediaAsset) {
            $photoAsset = $member->getRelation($relationName);
            break;
        }
    }

    $platforms = $socialPlatforms ?? [];
    if ($platforms === []) {
        foreach (['App\\Enums\\SocialPlatform', 'App\\Enums\\Cms\\SocialPlatform'] as $enumClass) {
            if (enum_exists($enumClass)) {
                foreach ($enumClass::cases() as $case) {
                    $platforms[$case->value] = ['label' => method_exists($case, 'label') ? $case->label() : \Illuminate\Support\Str::headline($case->value)];
                }
                break;
            }
        }
    }

    $links = (array) old('social_links', (array) ($member?->social_links ?? []));

    $tabFields = ['skills' => ['skills'], 'links' => ['social_links', 'portfolio_url'], 'visibility' => ['status', 'is_public', 'sort_order']];
    $initialTab = 'profile';
    foreach (array_keys($errors->getMessages()) as $errorKey) {
        $root = \Illuminate\Support\Str::before($errorKey, '.');
        foreach ($tabFields as $tab => $fields) {
            if (in_array($root, $fields, true)) {
                $initialTab = $tab;
                break 2;
            }
        }
    }
@endphp

<div x-data="uiTabs(@js($initialTab))">
    <x-ui.card :padded="false">
        <div class="px-4 sm:px-5">
            <x-ui.tabs :tabs="[
                ['label' => 'Profile', 'key' => 'profile', 'icon' => 'user'],
                ['label' => 'Skills', 'key' => 'skills', 'icon' => 'sparkles'],
                ['label' => 'Links', 'key' => 'links', 'icon' => 'link'],
                ['label' => 'Visibility', 'key' => 'visibility', 'icon' => 'eye'],
            ]" />
        </div>

        <div x-show="is('profile')" class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-3">
            <div class="space-y-5 lg:col-span-2">
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.input name="name" id="field-name" label="Name" :value="$member?->name" required maxlength="150" />
                    <x-ui.form.input name="designation" label="Designation" :value="$member?->designation" required maxlength="150" placeholder="e.g. Senior Laravel Developer" />
                </div>

                @include('admin.marketing.partials.slug-field', [
                    'value' => $member?->slug,
                    'sourceId' => 'field-name',
                    'prefix' => url('/team').'#',
                    'published' => $wasPublished,
                    'reserved' => $reservedSlugs ?? [],
                    'max' => 180,
                ])

                <div>
                    <x-ui.form.input name="department" label="Department" :value="$member?->department" maxlength="100" optional list="department-suggestions" help="The group heading on the team page. Members without one are listed last." />
                    <datalist id="department-suggestions">
                        @foreach ((array) ($departmentOptions ?? []) as $departmentOption)
                            <option value="{{ $departmentOption }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div>
                    <x-ui.form.textarea name="bio" label="Bio" :value="$member?->bio" :rows="6" maxlength="5000" optional help="Plain text; formatting is removed when saved. Opens in a dialog on the team page." />
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <x-ui.form.input name="experience_years" type="number" label="Years of experience" :value="$member?->experience_years" min="0" max="60" step="1" optional />
                    <x-ui.form.input name="experience_label" label="Experience label" :value="$member?->experience_label" maxlength="100" placeholder="e.g. 8+ years in fintech" optional help="Shown instead of the number when filled." />
                </div>
            </div>

            <div>
                <x-cms.image-field name="photo_media_id" upload="photo" label="Photo" :asset="$photoAsset" profile="Thumbnail" :max-mb="$maxUploadMb ?? null" help="A square photo works best." />
            </div>
        </div>

        <div x-show="is('skills')" x-cloak class="max-w-2xl p-4 sm:p-5">
            @include('admin.marketing.partials.list-input', [
                'name' => 'skills',
                'label' => 'Skills',
                'values' => $member?->skills ?? [],
                'mode' => 'tags',
                'max' => 20,
                'maxLength' => 60,
                'placeholder' => 'Type a skill and press Enter',
                'help' => 'Up to 20 skills. The first three appear on the card.',
            ])
        </div>

        <div x-show="is('links')" x-cloak class="grid grid-cols-1 gap-6 p-4 sm:p-5 lg:grid-cols-2">
            <div class="space-y-3">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Social profiles</p>
                @forelse ($platforms as $platformValue => $platform)
                    @php $linkId = 'social-'.$platformValue; @endphp
                    <div>
                        <label for="{{ $linkId }}" class="sr-only">{{ $platform['label'] ?? $platformValue }} URL</label>
                        <div @class([
                            'flex items-stretch overflow-hidden rounded-lg border bg-white shadow-sm focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20 dark:bg-slate-950/40',
                            'border-rose-400 dark:border-rose-500/60' => $errors->has('social_links.'.$platformValue),
                            'border-slate-300 dark:border-slate-700' => ! $errors->has('social_links.'.$platformValue),
                        ])>
                            <span class="inline-flex w-32 shrink-0 items-center gap-2 border-r border-slate-200 bg-slate-50 px-3 text-xs font-medium text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                                @include('site.marketing.partials.social-glyph', ['platform' => $platformValue, 'class' => 'h-4 w-4 text-slate-500 dark:text-slate-400'])
                                <span class="truncate">{{ $platform['label'] ?? $platformValue }}</span>
                            </span>
                            <input
                                id="{{ $linkId }}"
                                type="url"
                                name="social_links[{{ $platformValue }}]"
                                value="{{ $links[$platformValue] ?? '' }}"
                                maxlength="255"
                                placeholder="https://"
                                class="block w-full border-0 bg-transparent px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0 dark:text-white"
                            >
                        </div>
                        <x-ui.form.error :for="'social_links.'.$platformValue" />
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">No social platforms are configured.</p>
                @endforelse
                <x-ui.form.error for="social_links" />
            </div>

            <div class="space-y-3">
                <x-ui.form.input name="portfolio_url" type="url" label="Portfolio URL" :value="$member?->portfolio_url" maxlength="255" placeholder="https://" optional />
                <p class="text-xs text-slate-500 dark:text-slate-400">Every link opens in a new tab with nofollow and noopener.</p>
            </div>
        </div>

        <div x-show="is('visibility')" x-cloak class="max-w-xl space-y-5 p-4 sm:p-5">
            @if ($canStatus)
                <x-ui.form.select name="status" label="Status" :options="$statusOptions ?? ContentStatus::options()" :selected="$statusValue" />
            @else
                <div>
                    <p class="mb-1 text-sm font-medium text-slate-700 dark:text-slate-200">Status</p>
                    @include('admin.marketing.partials.enum-badge', ['value' => $member?->status ?? ContentStatus::Draft])
                </div>
            @endif

            <x-ui.form.toggle name="is_public" label="Show on the website" description="Switch off to hide this member from the team page without unpublishing the profile." :checked="(bool) ($member?->is_public ?? true)" />

            <x-ui.form.input name="sort_order" type="number" label="Display order" :value="$member?->sort_order ?? 0" min="0" step="1" help="Lower numbers come first within a department." />

            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800/40 dark:text-slate-300 dark:ring-slate-700">
                A member appears on the team page only when the status is Published <span class="font-semibold">and</span> "Show on the website" is on.
            </p>
        </div>
    </x-ui.card>
</div>
