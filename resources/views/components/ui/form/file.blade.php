@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'help' => null,
    'accept' => null,
    'multiple' => false,
    'required' => false,
    'disabled' => false,
    'current' => null,
    'hint' => 'PNG, JPG or WEBP up to 2 MB',
    'icon' => 'upload',
    'errorBag' => 'default',
])

{{--
    x-ui.form.file — drag-and-drop field with an image preview.

        <x-ui.form.file name="avatar" label="Profile photo" accept="image/*"
                        :current="$user->avatar_url" hint="Square image, up to 2 MB" />

    The real <input type="file"> is present and named, so the form posts normally and
    server-side validation stays the only gatekeeper; the drop zone is just nicer UI.
--}}

@php
    $id = $id ?? ($name ? 'file-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name) : 'file-'.uniqid());
    $errorKey = $name ? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name) : null;
    $invalid = $errorKey ? $errors->getBag($errorBag)->has($errorKey) : false;
@endphp

<div {{ $attributes->only('class')->class('w-full') }} x-data="uiFile(@js((bool) $multiple))">
    @if (filled($label))
        <x-ui.form.label :for="$id" :required="$required" class="mb-1.5">{{ $label }}</x-ui.form.label>
    @endif

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
        @if ($current)
            {{-- What is stored right now, replaced live once a new file is chosen. --}}
            <div class="shrink-0" x-show="files.length === 0">
                <img
                    src="{{ $current }}"
                    alt="Current file"
                    class="h-20 w-20 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-slate-700"
                />
            </div>
        @endif

        <div
            class="flex-1"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="onDrop($event)"
        >
            <label
                for="{{ $id }}"
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-6 text-center transition-colors"
                x-bind:class="dragging
                    ? 'border-brand-500 bg-brand-50/60 dark:border-brand-400 dark:bg-brand-500/10'
                    : '{{ $invalid ? 'border-rose-400 dark:border-rose-500/60' : 'border-slate-300 hover:border-brand-400 hover:bg-slate-50 dark:border-slate-700 dark:hover:border-brand-500 dark:hover:bg-slate-800/50' }}'"
            >
                <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <x-ui.icon :name="$icon" class="h-5 w-5" />
                </span>

                <span class="text-sm text-slate-600 dark:text-slate-300">
                    <span class="font-semibold text-brand-600 dark:text-brand-400">Click to upload</span>
                    or drag and drop
                </span>

                @if (filled($hint))
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $hint }}</span>
                @endif

                <input
                    type="file"
                    id="{{ $id }}"
                    x-ref="input"
                    x-on:change="onChange($event)"
                    @if ($name) name="{{ $name }}{{ $multiple ? '[]' : '' }}" @endif
                    @if ($accept) accept="{{ $accept }}" @endif
                    @if ($multiple) multiple @endif
                    @required($required)
                    @disabled($disabled)
                    @if ($invalid) aria-invalid="true" @endif
                    class="sr-only"
                    {{ $attributes->except('class') }}
                />
            </label>

            {{-- Chosen files --}}
            <ul class="mt-2 space-y-2" x-show="files.length > 0" x-cloak>
                <template x-for="(file, index) in files" :key="index">
                    <li class="flex items-center gap-3 rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200 dark:bg-slate-800/60 dark:ring-slate-700">
                        <template x-if="file.url">
                            <img :src="file.url" :alt="file.name" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
                        </template>

                        <template x-if="! file.url">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-slate-400 dark:bg-slate-900 dark:text-slate-500">
                                <x-ui.icon name="document" class="h-5 w-5" />
                            </span>
                        </template>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200" x-text="file.name"></p>
                            <p class="text-2xs text-slate-500 tabular-nums dark:text-slate-400" x-text="humanSize(file.size)"></p>
                        </div>

                        <button
                            type="button"
                            x-on:click="reset()"
                            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-white hover:text-rose-600 dark:hover:bg-slate-900"
                            aria-label="Remove file"
                        >
                            <x-ui.icon name="x-mark" class="h-4 w-4" />
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>

    @if (filled($help))
        <x-ui.form.help>{{ $help }}</x-ui.form.help>
    @endif

    @if ($name)
        <x-ui.form.error :for="$name" :bag="$errorBag" />
    @endif
</div>
