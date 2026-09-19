{{--
    The sticky save bar under every Phase 4 editor (phase-04 §8.2 "Sticky save bar, dirty-state
    navigate-away warning (same Alpine pattern as Phase 2 settings)", §8.7 "Last saved by X at HH:MM").

    Must sit inside an element carrying x-data="cmsDirty()" (admin.cms.partials.scripts), because it reads
    `dirty` and the form's submit handler calls `submitted()`.

    @include('admin.marketing.partials.save-bar', [
        'form' => 'service-form',                    // id of the form the buttons submit
        'cancel' => route('admin.services.index'),   // where Cancel goes
        'submitLabel' => 'Save service',
        'record' => $service,                        // ?Model — its updated_at and updater are shown
        'updaterRelations' => ['editor'],            // Blameable's updater relation, eager-loaded by the controller
        'note' => null,                              // optional extra sentence
    ])
--}}

@php
    $record = $record ?? null;
    $updater = null;

    if ($record instanceof \Illuminate\Database\Eloquent\Model) {
        foreach ((array) ($updaterRelations ?? ['editor', 'updater', 'updatedBy']) as $relationName) {
            if ($record->relationLoaded($relationName) && $record->getRelation($relationName) instanceof \App\Models\User) {
                $updater = $record->getRelation($relationName);
                break;
            }
        }
    }

    $savedAt = $record instanceof \Illuminate\Database\Eloquent\Model && $record->exists ? $record->getAttribute('updated_at') : null;
@endphp

<div class="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border sm:shadow-lg dark:border-slate-800 dark:bg-slate-900/95">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1 text-xs text-slate-500 dark:text-slate-400" aria-live="polite">
            <p x-show="dirty" x-cloak class="font-semibold text-amber-700 dark:text-amber-400">You have unsaved changes.</p>
            <p x-show="! dirty">
                @if ($savedAt)
                    Last saved{{ $updater ? ' by '.$updater->name : '' }} {{ app_datetime($savedAt) }}.
                @else
                    Not saved yet.
                @endif
                @if (filled($note ?? null))
                    {{ $note }}
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (filled($cancel ?? null))
                <x-ui.button variant="ghost" :href="$cancel">Cancel</x-ui.button>
            @endif

            <x-ui.button type="submit" :form="$form ?? null" icon="check">{{ $submitLabel ?? 'Save' }}</x-ui.button>
        </div>
    </div>
</div>
