{{--
    Icon field (phase-03 §6.6 / §8.1 `x-cms.icon-picker`).

    @include('admin.cms.partials.icon-picker', [
        'name' => 'content[icon]',
        'label' => 'Icon',
        'value' => 'sparkles',
        'help' => null,
        'errorKey' => null,   // optional override for the error lookup
    ])

    Options come from the allowlist in `resources/data/icons.php` (§6.6) when that file exists, else
    from the Heroicons names `x-ui.icon` can draw. The server validates the value `in:` the allowlist
    (the Form Request adds that rule); a custom icon is an image, never raw SVG.
--}}

@php
    $allowlistPath = resource_path('data/icons.php');
    $allowlist = [];

    if (is_file($allowlistPath)) {
        $data = require $allowlistPath;
        $allowlist = is_array($data) ? \Illuminate\Support\Arr::flatten($data) : [];
    }

    if ($allowlist === []) {
        $allowlist = [
            'academic-cap', 'banknotes', 'bars-3', 'bell', 'book-open', 'briefcase', 'building-office', 'building-office-2',
            'calendar-days', 'chart-bar', 'chart-pie', 'chat-bubble-left-right', 'check-badge', 'check-circle', 'clipboard-document-check',
            'clock', 'cog-6-tooth', 'computer-desktop', 'credit-card', 'document-text', 'envelope', 'flag', 'folder', 'globe-alt',
            'home', 'identification', 'information-circle', 'key', 'lifebuoy', 'lock-closed', 'megaphone', 'newspaper',
            'phone', 'photo', 'presentation-chart-bar', 'puzzle-piece', 'question-mark-circle', 'rectangle-stack', 'server-stack',
            'shield-check', 'sparkles', 'star', 'tag', 'trophy', 'user-group', 'users', 'video-camera', 'whatsapp', 'wrench-screwdriver',
        ];
    }

    $allowlist = array_values(array_unique(array_filter(array_map('strval', $allowlist))));
    sort($allowlist);

    $id = $id ?? 'field-'.str_replace(['[', ']', '.'], ['-', '', '-'], (string) $name);
    $errorKey = $errorKey ?? str_replace(['][', '[', ']'], ['.', '.', ''], (string) $name);
    $current = old($errorKey, $value ?? null);
    $options = collect($allowlist)->mapWithKeys(static fn (string $icon): array => [$icon => \Illuminate\Support\Str::headline($icon)])->all();
@endphp

<div class="flex items-end gap-2">
    <span class="mb-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-600 ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700" aria-hidden="true">
        @if (filled($current))
            <x-ui.icon :name="(string) $current" class="h-5 w-5" />
        @else
            <x-ui.icon name="minus" class="h-4 w-4 opacity-50" />
        @endif
    </span>

    <x-ui.form.select
        :name="$name"
        :id="$id"
        :label="$label ?? 'Icon'"
        :options="$options"
        :selected="$current"
        placeholder="No icon"
        :help="$help ?? null"
        class="flex-1"
    />
</div>
