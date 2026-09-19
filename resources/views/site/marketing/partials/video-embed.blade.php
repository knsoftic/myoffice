{{--
    A YouTube / Vimeo embed built from the parsed video id — never from pasted HTML (phase-04 §6.9 `video_url`).

    @include('site.marketing.partials.video-embed', ['url' => $review->video_url, 'title' => 'Video review by Ali'])
    @include('site.marketing.partials.video-embed', ['embedUrl' => $card['video_embed_url'], 'title' => '…'])

    `url` goes through App\Support\Cms\VideoUrl::embedUrl() — the one host allowlist and id parser the Form Requests use.
    A ready `embedUrl` (a section provider's `video_embed_url`) is re-checked against the two embed hosts, because a
    snapshot is not a trust boundary. Anything else renders nothing. YouTube is the privacy-enhanced host; the iframe is lazy
    and never autoplays.
--}}

@php
    $embedSrc = null;
    $givenEmbed = trim((string) ($embedUrl ?? ''));

    if ($givenEmbed !== '' && preg_match('~^https://(?:www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}|player\.vimeo\.com/video/\d{1,12})$~', $givenEmbed) === 1) {
        $embedSrc = $givenEmbed;
    } elseif (filled($url ?? null)) {
        $embedSrc = class_exists(\App\Support\Cms\VideoUrl::class)
            ? \App\Support\Cms\VideoUrl::embedUrl((string) $url)
            : null;
    }
@endphp

@if ($embedSrc !== null)
    <div class="aspect-video overflow-hidden rounded-xl bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-white/10">
        <iframe
            src="{{ $embedSrc }}"
            title="{{ $title ?? 'Video' }}"
            class="h-full w-full"
            loading="lazy"
            referrerpolicy="strict-origin-when-cross-origin"
            allow="encrypted-media; picture-in-picture; fullscreen"
            allowfullscreen
        ></iframe>
    </div>
@endif
