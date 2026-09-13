@extends('layouts.admin')

@section('title', ($group['label'] ?? 'Settings').' settings')

{{--
    The settings screen (route admin.settings.index, phase-02 §5).

    Layout: a vertical tab rail of every SettingsRegistry group beside one card holding that
    group's fields. Every field — all seventeen types — renders through <x-settings.field>, which
    switches on the registry's `type`. This file lists no setting key and no validation rule.

    What sits outside the form, and why: x-ui.confirm and the file-removal endpoints carry their
    own <form>, and HTML does not allow one form inside another. So the danger zone, the mail test
    and the hidden DELETE forms are siblings of the settings form, not children of it.

    From the controller:
        $groups   the tab rail (slug, label, icon, url, active, locked)
        $group    the current group's registry metadata
        $fields   one view model per field, already carrying its stored value
        $canEdit  may this user save THIS group (the mail group needs its own permission)
        $audit    last updated by / at, for the footer
        $preview  branding only — the live preview's colours and logos
        $mail     mail only — the transport summary and the last test result
--}}

@section('header')
    <x-ui.page-header
        title="Settings"
        :subtitle="$group['description'] ?? 'Everything a business user can change without a developer.'"
        icon="cog-6-tooth"
        :badge="$group['label'] ?? null"
        badge-color="brand"
    >
        <x-slot:actions>
            @if ($canClearCache)
                <form method="POST" action="{{ route('admin.settings.cache.clear') }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="arrow-path">
                        Clear caches
                    </x-ui.button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>
@endsection

@section('content')
    <div class="flex flex-col gap-5 lg:flex-row">

        {{-- ── Group rail ───────────────────────────────────────────────────────────────── --}}
        <aside class="lg:w-60 lg:shrink-0 xl:w-64">
            <div class="lg:sticky lg:top-20">
                <x-settings.tab-rail :groups="$groups" />
            </div>
        </aside>

        {{-- ── The group ────────────────────────────────────────────────────────────────── --}}
        <div class="min-w-0 flex-1 space-y-5">

            @unless ($canEdit)
                <div class="flex items-start gap-2.5 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-100 dark:ring-amber-500/25">
                    <x-ui.icon name="lock-closed" class="mt-0.5 h-4 w-4 shrink-0" />
                    <div>
                        <p class="font-semibold">Read-only</p>
                        <p class="mt-0.5">
                            You can see these values but not change them.
                            @if (($group['slug'] ?? null) === \App\Http\Controllers\Admin\SettingsController::GROUP_MAIL)
                                The mail credentials are restricted beyond the usual settings permission.
                            @endif
                        </p>
                    </div>
                </div>
            @endunless

            @if ($preview !== null)
                <x-settings.brand-preview :preview="$preview" />
            @endif

            <form
                method="POST"
                action="{{ route('admin.settings.update', $group['slug'] ?? '') }}"
                enctype="multipart/form-data"
                x-data="{
                    dirty: false,
                    saving: false,
                    discard() {
                        this.$el.reset();
                        this.dirty = false;
                    },
                }"
                x-on:input="dirty = true"
                x-on:change="dirty = true"
                x-on:submit="saving = true"
                x-on:beforeunload.window="if (dirty && ! saving) { $event.preventDefault(); $event.returnValue = ''; }"
            >
                @csrf
                @method('PUT')

                <x-ui.card :title="$group['label'] ?? 'Settings'" :icon="$group['icon'] ?? 'cog-6-tooth'">
                    @if ($fields === [])
                        <x-ui.empty-state
                            icon="adjustments-horizontal"
                            title="This group has no settings yet"
                            message="A later phase declares its fields in SettingsRegistry; the screen picks them up with no change here."
                            compact
                        />
                    @else
                        <div class="grid grid-cols-12 gap-x-4 gap-y-5">
                            @foreach ($fields as $field)
                                <x-settings.field :field="$field" />
                            @endforeach
                        </div>
                    @endif

                    <x-slot:footer>
                        <x-settings.group-footer :audit="$audit" :group="$group" />
                    </x-slot:footer>
                </x-ui.card>

                @if ($canEdit && $fields !== [])
                    <x-settings.save-bar />
                @endif
            </form>

            @if ($mail !== null)
                <x-settings.mail-test :mail="$mail" />
            @endif

            @if ($canEdit && $fields !== [])
                <x-settings.danger-zone :group="$group" />
            @endif

            {{-- One hidden DELETE form per stored file; see the component's note. --}}
            <x-settings.file-forms :group="$group['slug'] ?? ''" :fields="$fields" />
        </div>
    </div>
@endsection
