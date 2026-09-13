{{--
    "Where it is used" — the list behind a delete that is refused while something references the row
    (CTA blocks §6.13, media assets §2.13 / FT-38).

    @include('admin.cms.partials.usage-list', [
        'usage' => $usage,        // iterable of arrays or objects: type, id, label, detail, url (optional)
        'emptyTitle' => 'Not used anywhere',
        'emptyMessage' => 'It can be deleted safely.',
    ])

    Rows are read with data_get(), so MediaService::usage() arrays and any service DTO render the same.
    A row with no `url` for a section or page gets its admin editor link when that route exists.
--}}

@php
    use Illuminate\Support\Facades\Route as RouteFacade;

    $usage = collect($usage ?? []);
    $iconFor = static fn (string $type): string => match ($type) {
        'page' => 'document',
        'cta_block' => 'megaphone',
        'seo_meta' => 'magnifying-glass',
        'faq' => 'question-mark-circle',
        default => 'view-columns',
    };
    $linkFor = static function (mixed $place): ?string {
        $url = data_get($place, 'url');

        if (filled($url)) {
            return (string) $url;
        }

        $id = data_get($place, 'id');

        return match ((string) data_get($place, 'type')) {
            'section' => $id && RouteFacade::has('admin.website.sections.edit') ? route('admin.website.sections.edit', ['section' => (int) $id]) : null,
            'page' => $id && RouteFacade::has('admin.website.pages.edit') ? route('admin.website.pages.edit', ['page' => (int) $id]) : null,
            'cta_block' => $id && RouteFacade::has('admin.website.cta-blocks.edit') ? route('admin.website.cta-blocks.edit', ['ctaBlock' => (int) $id]) : null,
            default => null,
        };
    };
@endphp

@if ($usage->isEmpty())
    <x-ui.empty-state :compact="true" icon="link" :title="$emptyTitle ?? 'Not used anywhere'" :message="$emptyMessage ?? null" />
@else
    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
        @foreach ($usage as $place)
            @php $link = $linkFor($place); @endphp
            <li class="flex items-start gap-3 px-4 py-3 text-sm">
                <x-ui.icon :name="$iconFor((string) data_get($place, 'type', 'section'))" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400" />
                <div class="min-w-0">
                    @if ($link)
                        <a href="{{ $link }}" class="font-medium text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{{ data_get($place, 'label', 'Untitled') }}</a>
                    @else
                        <span class="font-medium text-slate-900 dark:text-white">{{ data_get($place, 'label', 'Untitled') }}</span>
                    @endif
                    @if (filled(data_get($place, 'detail')))
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ data_get($place, 'detail') }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
