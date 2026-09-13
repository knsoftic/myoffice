{{--
    Per-group bulk enable / disable (posts to admin.modules.bulk-toggle).

    Expects: $group ['key','label','color','modules','toggleable'], $switchable (int).

    The action is group-wide on purpose — it names the group, not the visible cards — and it can
    only ever move the group's non-core modules. Disabling asks for a reason and for the cascade
    confirmation, because the service refuses any module whose enabled dependents would be
    stranded unless the cascade was explicitly accepted. No data is deleted either way.
--}}

@php
    $enableId = 'bulk-enable-'.$group['key'];
    $disableId = 'bulk-disable-'.$group['key'];
@endphp

<div class="flex items-center gap-1.5">
    {{-- Enable all --}}
    <x-ui.confirm
        :id="$enableId"
        :action="route('admin.modules.bulk-toggle')"
        method="POST"
        :title="'Enable every switchable module in '.$group['label'].'?'"
        :message="$switchable.' '.Str::plural('module', $switchable).' in this group can be switched on. Their routes, sidebar entries and permissions become available again to everyone who holds them; core modules are already on and are left alone.'"
        confirm-label="Enable group"
        variant="warning"
        icon="check-circle"
    >
        <x-slot:trigger>
            <x-ui.button size="sm" variant="ghost" icon="check-circle" title="Enable every switchable module in this group">
                Enable all
            </x-ui.button>
        </x-slot:trigger>

        <x-slot:fields>
            <input type="hidden" name="enabled" value="1" />
            <input type="hidden" name="group" value="{{ $group['key'] }}" />
        </x-slot:fields>

        <div class="mt-4">
            <label for="{{ $enableId }}-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                Reason <span class="font-normal text-slate-400">(recorded against every module)</span>
            </label>
            <input
                id="{{ $enableId }}-reason"
                type="text"
                name="reason"
                maxlength="255"
                form="{{ $enableId }}"
                placeholder="e.g. rolling out the institute features"
                class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
            />
        </div>
    </x-ui.confirm>

    {{-- Disable all --}}
    <x-ui.confirm
        :id="$disableId"
        :action="route('admin.modules.bulk-toggle')"
        method="POST"
        :title="'Disable every switchable module in '.$group['label'].'?'"
        :message="$switchable.' '.Str::plural('module', $switchable).' in this group can be switched off. Their routes will answer 403 for everyone — Super Admin included — and their sidebar entries disappear. NO DATA IS DELETED: every row stays exactly where it is and comes back when you switch the module on again.'"
        confirm-label="Disable group"
        variant="danger"
        icon="eye-slash"
        :require-text="$group['label']"
    >
        <x-slot:trigger>
            <x-ui.button size="sm" variant="ghost" icon="eye-slash" title="Disable every switchable module in this group">
                Disable all
            </x-ui.button>
        </x-slot:trigger>

        <x-slot:fields>
            <input type="hidden" name="enabled" value="0" />
            <input type="hidden" name="group" value="{{ $group['key'] }}" />
        </x-slot:fields>

        <div class="mt-4 space-y-3">
            <div>
                <label for="{{ $disableId }}-reason" class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                    Reason <span class="text-rose-500">*</span>
                    <span class="font-normal text-slate-400">(recorded against every module)</span>
                </label>
                <input
                    id="{{ $disableId }}-reason"
                    type="text"
                    name="reason"
                    maxlength="255"
                    minlength="5"
                    required
                    form="{{ $disableId }}"
                    placeholder="e.g. the institute side is not live yet"
                    class="mt-1.5 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500/30 dark:border-slate-700 dark:bg-slate-950/40 dark:text-white"
                />
            </div>

            <label class="flex items-start gap-2 rounded-lg bg-amber-50/70 p-2.5 text-xs text-amber-800 ring-1 ring-inset ring-amber-100 dark:bg-amber-500/5 dark:text-amber-200 dark:ring-amber-500/20">
                <input
                    type="checkbox"
                    name="cascade"
                    value="1"
                    form="{{ $disableId }}"
                    class="mt-0.5 h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500/40 dark:border-amber-500/40 dark:bg-transparent"
                />
                <span>
                    <span class="font-medium">Also switch off modules outside this group that depend on these.</span>
                    Without this, any module whose dependents are still on is skipped and reported instead of
                    being switched off.
                </span>
            </label>
        </div>
    </x-ui.confirm>
</div>
