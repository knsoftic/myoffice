{{--
    The add-section form (phase-03 §8.4), shared by the dialog on sections.index and the standalone
    sections.available screen.

    @include('admin.cms.sections.partials.add-form', [
        'placement' => $placement,          // SectionPlacement
        'pageId' => $pageId,                // ?int — posted only for the page placement
        'addableByGroup' => $grouped,       // Collection<group, array<key, addable type>> (see SectionController::addableTypes)
        'groups' => SectionRegistry::groups(),
        'formId' => 'add-section-form',
    ])

    Posts POST admin.website.sections.store {placement}: section_key, page_id, name. A unique type that is
    already placed renders disabled with its reason and a link to the existing one — never hidden.
--}}

@php
    use App\Enums\Cms\SectionPlacement;
@endphp

<form id="{{ $formId }}" method="POST" action="{{ route('admin.website.sections.store', ['placement' => $placement->value]) }}" x-data="{ key: @js(old('section_key')) }" class="space-y-5">
    @csrf
    @if ($placement === SectionPlacement::Page && $pageId)
        <input type="hidden" name="page_id" value="{{ $pageId }}">
    @endif

    @foreach ($addableByGroup as $group => $types)
        <fieldset>
            <legend class="mb-2 text-2xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{{ $groups[$group]['label'] ?? \Illuminate\Support\Str::headline((string) $group) }}</legend>

            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($types as $typeKey => $type)
                    @php $taken = (bool) ($type['disabled'] ?? false); @endphp
                    <label @class([
                        'relative flex gap-3 rounded-lg p-3 ring-1 transition',
                        'cursor-not-allowed bg-slate-50 opacity-80 ring-slate-200 dark:bg-slate-800/40 dark:ring-slate-700' => $taken,
                        'cursor-pointer ring-slate-200 hover:ring-brand-300 has-[:checked]:bg-brand-50 has-[:checked]:ring-2 has-[:checked]:ring-brand-500 dark:ring-slate-700 dark:hover:ring-brand-500/60 dark:has-[:checked]:bg-brand-500/10' => ! $taken,
                    ])>
                        <input type="radio" name="section_key" value="{{ $type['key'] ?? $typeKey }}" x-model="key" class="sr-only" @disabled($taken) required>
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-ui.icon :name="(string) ($type['icon'] ?? 'rectangle-stack')" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-semibold text-slate-900 dark:text-white">{{ $type['label'] }}</span>
                                @if (! empty($type['required']))
                                    <x-ui.badge color="slate" size="sm">Required</x-ui.badge>
                                @endif
                            </span>
                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $type['description'] ?? '' }}</span>
                            @if ($taken)
                                <span class="mt-1 block text-xs font-medium text-amber-700 dark:text-amber-400">
                                    {{ $type['reason'] ?? 'Already placed here.' }}
                                    @if (filled($type['existing_url'] ?? null))
                                        <a href="{{ $type['existing_url'] }}" class="underline">Edit it</a>
                                    @endif
                                </span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <x-ui.form.error for="section_key" />
    <x-ui.form.error for="page_id" />

    <x-ui.form.input name="name" :id="$formId.'-name'" label="Admin label" placeholder="Optional — shown in the list instead of the type name" maxlength="150" optional />
</form>
