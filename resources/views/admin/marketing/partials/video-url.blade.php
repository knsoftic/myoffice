{{--
    A video URL field with an inline embed preview (phase-04 §2.11 / §2.12 `video_url`, §6.9, §8.6).

    @include('admin.marketing.partials.video-url', ['value' => $review?->video_url])

    Posts `video_url`. Only youtube.com, youtu.be and vimeo.com are accepted by the Form Request; the preview is
    built from the parsed video id (youtube-nocookie / player.vimeo.com), never from raw pasted HTML, and shows a
    warning for any other host so the editor knows before saving.
--}}

@php
    $current = (string) old('video_url', $value ?? '');
@endphp

<div
    x-data="{
        url: @js($current),
        get embed() {
            const value = String(this.url || '').trim();
            let match = value.match(/^https?:\/\/(?:www\.|m\.)?youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)([A-Za-z0-9_-]{11})/i)
                || value.match(/^https?:\/\/youtu\.be\/([A-Za-z0-9_-]{11})/i);
            if (match) return 'https://www.youtube-nocookie.com/embed/' + match[1];
            match = value.match(/^https?:\/\/(?:www\.|player\.)?vimeo\.com\/(?:video\/)?(\d{6,12})/i);
            if (match) return 'https://player.vimeo.com/video/' + match[1];
            return null;
        },
        get invalid() { return String(this.url || '').trim() !== '' && this.embed === null; },
    }"
    class="space-y-3"
>
    <div>
        <label for="field-video_url" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">Video URL <span class="font-normal text-slate-400">(optional)</span></label>
        <input
            id="field-video_url"
            type="url"
            name="video_url"
            x-model.debounce.400ms="url"
            value="{{ $current }}"
            maxlength="255"
            placeholder="https://www.youtube.com/watch?v=… or https://vimeo.com/…"
            @class([
                'block w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:ring-2 dark:bg-slate-950/40 dark:text-white',
                'border-rose-400 focus:border-rose-500 focus:ring-rose-500/20 dark:border-rose-500/60' => $errors->has('video_url'),
                'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-700' => ! $errors->has('video_url'),
            ])
        >
        <p x-show="invalid" x-cloak class="mt-1.5 text-xs font-medium text-amber-700 dark:text-amber-400">Only YouTube and Vimeo links are accepted.</p>
        <x-ui.form.error for="video_url" />
    </div>

    <template x-if="embed">
        <div class="aspect-video overflow-hidden rounded-xl bg-slate-900 ring-1 ring-slate-200 dark:ring-slate-800">
            <iframe x-bind:src="embed" title="Video preview" class="h-full w-full" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture" allowfullscreen></iframe>
        </div>
    </template>
</div>
