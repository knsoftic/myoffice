<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Cms\Exceptions\UnknownRichTextProfileException;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

/**
 * The one HTML sanitiser of the whole system — decision **D25**, phase-03 §6.6, ND-5, INV-13.
 *
 *     RichText::sanitize($html);               // the `cms` profile (the default)
 *     RichText::sanitize($html, 'material');   // print templates and course material (phase-19-23)
 *
 * ---------------------------------------------------------------------------------------------
 * Invariants this class guarantees (and FT-36 / FT-36b assert)
 * ---------------------------------------------------------------------------------------------
 *
 *  1. **Closed profile map.** `$profile` is a *name* resolved through {@see self::PROFILES}. Any name
 *     outside that constant throws `UnknownRichTextProfileException` before a single byte is parsed,
 *     and the caller's string is never handed to any engine as a configuration key. Adding a third
 *     profile is an edit to this class, reviewed as a security change.
 *
 *  2. **A common core no profile can weaken.** Whatever a profile lists, the following never survive:
 *     `script object embed link meta form input base applet` (plus the other raw-text and foreign
 *     containers in {@see self::DROPPED_WITH_CONTENT}); every `on*` attribute; every `javascript:`,
 *     `vbscript:` and `file:` URL in any attribute; every Blade and PHP construct (`{{`, `{!!`, `@php`,
 *     `@include`, `@extends`, `<?php`, ...); every `<iframe>` whose `src` is not on the calling
 *     profile's host allowlist. The core is applied *after* the profile, by code that does not read
 *     the profile — so a mistaken widening of `PROFILES` still cannot re-admit a `<script>`.
 *
 *  3. **Link hrefs** accept only `http(s)://`, `mailto:`, `tel:`, a site-relative `/path` or a
 *     `#anchor` under every profile (§6.6). The `material` profile's `data:` concession applies to an
 *     `<img src>` of `image/png|jpeg|gif|webp` only, never to an `href`.
 *
 *  4. **Deterministic and idempotent.** `sanitize(sanitize($x)) === sanitize($x)`, so sanitising on
 *     write and again on render (the database is not a trust boundary) cannot drift.
 *
 *  5. **One code path.** The allowlist below is enforced by a DOM walker that always runs. When
 *     `ezyang/htmlpurifier` (pulled in by `mews/purifier`, §13.4) is installed it runs *first* as a
 *     parser hardening pass, configured from the same `PROFILES` constant — never from a config file
 *     named by the caller — and any failure inside it falls back to the walker alone, which is the
 *     authority either way. With or without the package the output obeys the same allowlist.
 *
 * Pure PHP: no facade, no container, no I/O, so it is safe in views, jobs, seeders and tests.
 */
final class RichText
{
    public const DEFAULT_PROFILE = 'cms';

    /**
     * Tags the common core removes under every profile (§6.6 — verbatim).
     *
     * @var list<string>
     */
    public const CORE_REMOVED_TAGS = ['script', 'object', 'embed', 'link', 'meta', 'form', 'input', 'base', 'applet'];

    /**
     * URL schemes the common core removes from every attribute under every profile.
     *
     * @var list<string>
     */
    public const CORE_BLOCKED_SCHEMES = ['javascript:', 'vbscript:', 'file:'];

    /**
     * Link-href prefixes accepted under every profile (§6.6, the same rule as
     * `SectionRegistry::LINK_URL_RULE`).
     */
    public const HREF_PATTERN = '~^(https?://|mailto:|tel:|/(?![/\\\\])|#)~i';

    /**
     * The closed profile map (ND-5). Exactly two committed profiles; there is no third.
     *
     * | key            | meaning                                                                     |
     * |----------------|-----------------------------------------------------------------------------|
     * | `tags`         | elements kept (anything else is unwrapped, keeping its text)                |
     * | `attributes`   | attribute name => the elements it may appear on (`*` = any kept element)     |
     * | `iframe_hosts` | `https://` prefixes an `<iframe src>` must start with; empty = no iframes    |
     * | `img_data`     | may an `<img src>` be a base64 `data:` image of the four raster types?      |
     * | `img_relative` | may an `<img src>` be a document-relative path (`images/a.png`)?            |
     * | `classes`      | the fixed `class` token allowlist                                            |
     * | `css`          | CSS property allowlist for `style` (empty = `style` not allowed)             |
     *
     * @var array<string, array<string, mixed>>
     */
    public const PROFILES = [
        'cms' => [
            'label' => 'Website content',
            'tags' => [
                'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
                'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr',
                'span', 'iframe',
            ],
            'attributes' => [
                'href' => ['a'],
                'title' => ['*'],
                'target' => ['a'],
                'rel' => ['a'],
                'src' => ['img', 'iframe'],
                'alt' => ['img'],
                'width' => ['img', 'iframe', 'table', 'th', 'td'],
                'height' => ['img', 'iframe', 'table', 'th', 'td'],
                'class' => ['*'],
            ],
            'iframe_hosts' => [
                'https://www.youtube.com/embed/',
                'https://player.vimeo.com/video/',
                'https://www.google.com/maps/embed',
            ],
            'img_data' => false,
            'img_relative' => false,
            'classes' => self::CLASSES,
            'css' => [],
        ],
        'material' => [
            'label' => 'Documents and course material',
            'tags' => [
                'p', 'br', 'strong', 'em', 'u', 's', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
                'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'hr',
                'span',
                'div', 'h1', 'h5', 'h6', 'small', 'b', 'i', 'sub', 'sup',
            ],
            'attributes' => [
                'href' => ['a'],
                'title' => ['*'],
                'target' => ['a'],
                'rel' => ['a'],
                'src' => ['img'],
                'alt' => ['img'],
                'width' => ['img', 'table', 'th', 'td'],
                'height' => ['img', 'table', 'th', 'td'],
                'class' => ['*'],
                'style' => ['*'],
                'align' => ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'th', 'td', 'img', 'figure'],
            ],
            'iframe_hosts' => [],
            'img_data' => true,
            'img_relative' => true,
            'classes' => self::CLASSES,
            'css' => self::MATERIAL_CSS_PROPERTIES,
        ],
    ];

    /**
     * The fixed `class` allowlist (§6.6: "`class` restricted to a fixed allowlist"). Editor output
     * (Trix attachments) plus the alignment and print helpers the public prose styles and the
     * material templates target. A token outside it is dropped; an emptied attribute is removed.
     *
     * @var list<string>
     */
    public const CLASSES = [
        'text-left', 'text-center', 'text-right', 'text-justify',
        'lead', 'note', 'muted', 'highlight',
        'align-left', 'align-center', 'align-right',
        'image-left', 'image-center', 'image-right', 'image-full',
        'table-bordered', 'table-striped',
        'attachment', 'attachment--preview', 'attachment--file', 'attachment__caption',
        'attachment-gallery', 'attachment-gallery--2', 'attachment-gallery--3', 'attachment-gallery--4',
        'video-embed', 'map-embed',
        'page-break', 'page-break-before', 'page-break-after', 'no-break',
    ];

    /**
     * CSS properties the `material` profile keeps in a `style` attribute or a stylesheet.
     * Deliberately no `position`, `behavior`, `-moz-binding`, `content` or `cursor`.
     *
     * @var list<string>
     */
    public const MATERIAL_CSS_PROPERTIES = [
        'color', 'background', 'background-color', 'background-image', 'background-position',
        'background-repeat', 'background-size',
        'font', 'font-family', 'font-size', 'font-style', 'font-weight', 'font-variant',
        'text-align', 'text-decoration', 'text-indent', 'text-transform', 'line-height',
        'letter-spacing', 'word-spacing', 'white-space', 'vertical-align', 'direction',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-color', 'border-style', 'border-width', 'border-radius', 'border-collapse',
        'border-spacing',
        'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height',
        'display', 'float', 'clear', 'overflow', 'box-sizing',
        'list-style', 'list-style-type', 'list-style-position',
        'table-layout', 'caption-side', 'empty-cells',
        'page-break-before', 'page-break-after', 'page-break-inside',
        'break-before', 'break-after', 'break-inside', 'orphans', 'widows',
        'opacity',
    ];

    /**
     * Elements removed together with everything inside them, under every profile. The core tags of
     * §6.6 plus every raw-text, foreign-content or interactive container whose contents are not prose.
     * `form` is the one core tag that is unwrapped instead, so its visible text survives while its
     * `input`s do not.
     *
     * @var list<string>
     */
    private const DROPPED_WITH_CONTENT = [
        'script', 'object', 'embed', 'link', 'meta', 'input', 'base', 'applet',
        'style', 'iframe', 'frame', 'frameset', 'noframes', 'noscript', 'noembed', 'template',
        'svg', 'math', 'head', 'title', 'textarea', 'select', 'option', 'optgroup', 'button',
        'datalist', 'output', 'param', 'source', 'track', 'video', 'audio', 'canvas', 'map', 'area',
        'xml', 'xmp', 'plaintext', 'listing', 'portal', 'dialog', 'slot',
    ];

    /**
     * Blade / PHP constructs removed from text and attribute values (§6.6). `@` directives are only
     * matched when not preceded by a word character or a dot, so `info@include.pk` is left alone.
     */
    private const TEMPLATE_PATTERNS = [
        '~<\?(?:php|=)?~i',
        '~\?>~',
        '~\{\{--|--\}\}~',
        '~\{!!|!!\}~',
        '~\{\{|\}\}~',
        '~(?<![\w.])@(?:php|endphp|include(?:If|When|Unless|First)?|extends|section|endsection|show|yield|parent|stack|push|endpush|prepend|endprepend|component|endcomponent|slot|endslot|inject|each|verbatim|endverbatim|eval|livewire|csrf|method|once|endonce|use|vite|dd|dump)\b~i',
    ];

    /** Attributes whose value is a URL: a blocked scheme anywhere in them removes the attribute. */
    private const URL_ATTRIBUTES = ['href', 'src'];

    /** Allowed `target` values. `_blank` always forces `rel="noopener noreferrer"`. */
    private const TARGETS = ['_blank', '_self'];

    /** Allowed `rel` tokens. */
    private const REL_TOKENS = ['noopener', 'noreferrer', 'nofollow', 'ugc', 'sponsored', 'external'];

    /** Allowed `align` values (material). */
    private const ALIGN_VALUES = ['left', 'right', 'center', 'justify'];

    /** The raster types a `data:` image may carry (material). */
    private const DATA_IMAGE_PATTERN = '~^data:image/(png|jpeg|jpg|gif|webp);base64,[a-z0-9+/=\s]+$~i';

    /**
     * Engine state, memoised per profile. `false` means "tried and unavailable".
     *
     * @var array<string, object|false>
     */
    private static array $purifiers = [];

    /** Why the optional parser pass last failed, for diagnostics (never user-facing). */
    private static ?string $lastEngineError = null;

    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    */

    /**
     * Sanitise HTML against one profile of the closed map.
     *
     * @throws UnknownRichTextProfileException when `$profile` is not a committed profile name
     */
    public static function sanitize(?string $html, string $profile = self::DEFAULT_PROFILE): string
    {
        $definition = self::profile($profile);

        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        $html = self::stripInvalidUtf8($html);
        $html = self::purifierPass($html, $profile, $definition);

        return self::domPass($html, $definition);
    }

    /**
     * Sanitise a supplied stylesheet (`print_templates.custom_css`, §6.6). Only a profile with a CSS
     * allowlist may carry CSS; for any other profile the answer is the empty string.
     *
     * `@import`, `@charset`, `@namespace` and `@font-face` are removed; `expression(`, `behavior`,
     * `-moz-binding`, escapes and every external `url()` are stripped; `<` cannot appear in the output,
     * so the result can never close the `<style>` element it is printed into.
     *
     * @throws UnknownRichTextProfileException
     */
    public static function sanitizeCss(?string $css, string $profile = 'material'): string
    {
        $definition = self::profile($profile);
        $allowed = (array) $definition['css'];

        $css = self::stripInvalidUtf8((string) $css);

        if ($allowed === [] || trim($css) === '') {
            return '';
        }

        $css = (string) preg_replace('~/\*.*?(\*/|$)~s', '', $css);
        // `<` is the only character that can end the enclosing <style> element; `\` is how CSS
        // escapes hide `expression`. Neither is ever needed by a print stylesheet.
        $css = str_replace(['<', '\\'], '', $css);
        $css = self::stripTemplateConstructs($css);
        $css = (string) preg_replace('~@(import|charset|namespace)\b[^;{}]*;?~i', '', $css);
        $css = (string) preg_replace('~@font-face\s*\{[^}]*\}~i', '', $css);

        $output = [];

        // One level of nesting: `@media print { sel { ... } }` and `@page { ... }` are kept.
        if (preg_match_all('~(@media[^{]*)\{((?:[^{}]*\{[^{}]*\})*)[^{}]*\}|(@page[^{]*)\{([^{}]*)\}|([^{}@][^{}@]*)\{([^{}]*)\}~i', $css, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    $inner = [];

                    if (preg_match_all('~([^{}]+)\{([^{}]*)\}~', (string) $match[2], $rules, PREG_SET_ORDER) > 0) {
                        foreach ($rules as $rule) {
                            $block = self::cssRule((string) $rule[1], (string) $rule[2], $allowed);

                            if ($block !== null) {
                                $inner[] = '  '.$block;
                            }
                        }
                    }

                    $media = trim((string) preg_replace('~[^@a-z0-9\s(),:.\-]~i', '', (string) $match[1]));

                    if ($inner !== [] && preg_match('~^@media\b~i', $media) === 1) {
                        $output[] = $media." {\n".implode("\n", $inner)."\n}";
                    }

                    continue;
                }

                if (($match[3] ?? '') !== '') {
                    $declarations = self::cssDeclarations((string) $match[4], $allowed);

                    if ($declarations !== '') {
                        $output[] = '@page { '.$declarations.' }';
                    }

                    continue;
                }

                $block = self::cssRule((string) ($match[5] ?? ''), (string) ($match[6] ?? ''), $allowed);

                if ($block !== null) {
                    $output[] = $block;
                }
            }
        }

        return implode("\n", $output);
    }

    /**
     * The committed profile names, in declaration order.
     *
     * @return list<string>
     */
    public static function profiles(): array
    {
        return array_keys(self::PROFILES);
    }

    public static function hasProfile(string $profile): bool
    {
        return array_key_exists($profile, self::PROFILES);
    }

    /**
     * One profile definition.
     *
     * @return array<string, mixed>
     *
     * @throws UnknownRichTextProfileException
     */
    public static function profile(string $profile): array
    {
        if (! self::hasProfile($profile)) {
            throw UnknownRichTextProfileException::for($profile, self::profiles());
        }

        return self::PROFILES[$profile];
    }

    /**
     * May this value be used as a link `href` under every profile (§6.6)? The one question every
     * service that stores a URL (menu items, CTA buttons, section `link` fields) asks.
     */
    public static function isSafeHref(?string $url): bool
    {
        $normalised = self::normaliseUrl((string) $url);

        if ($normalised === '' || self::hasBlockedScheme($normalised)) {
            return false;
        }

        return preg_match(self::HREF_PATTERN, $normalised) === 1;
    }

    /**
     * The visible text of sanitised HTML, whitespace collapsed — for excerpts, meta-description
     * fallbacks and character counters. Never contains markup.
     *
     * @throws UnknownRichTextProfileException
     */
    public static function plainText(?string $html, string $profile = self::DEFAULT_PROFILE): string
    {
        $clean = self::sanitize($html, $profile);

        if ($clean === '') {
            return '';
        }

        $clean = (string) preg_replace('~<(br|/p|/li|/h[1-6]|/tr|/blockquote|/figcaption|hr)\b[^>]*>~i', ' ', $clean);
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }

    /**
     * Which engines ran on the last call: `dom` or `htmlpurifier+dom`. Diagnostics only.
     */
    public static function engine(): string
    {
        return class_exists('HTMLPurifier') ? 'htmlpurifier+dom' : 'dom';
    }

    public static function lastEngineError(): ?string
    {
        return self::$lastEngineError;
    }

    /*
    |--------------------------------------------------------------------------
    | Optional parser pass (ezyang/htmlpurifier via mews/purifier)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function purifierPass(string $html, string $profile, array $definition): string
    {
        if (! class_exists('HTMLPurifier') || ! class_exists('HTMLPurifier_Config')) {
            return $html;
        }

        try {
            $purifier = self::$purifiers[$profile] ??= self::makePurifier($definition);

            if ($purifier === false) {
                return $html;
            }

            /** @var \HTMLPurifier $purifier */
            return (string) $purifier->purify($html);
        } catch (Throwable $exception) {
            // The walker below is the authority; a misbehaving optional pass must never either
            // widen the output or take the page down.
            self::$purifiers[$profile] = false;
            self::$lastEngineError = $exception->getMessage();

            return $html;
        }
    }

    /**
     * Build an HTMLPurifier instance from the profile constant — never from a named config file.
     *
     * @param  array<string, mixed>  $definition
     */
    private static function makePurifier(array $definition): object
    {
        $tags = self::effectiveTags($definition);
        $attributes = (array) $definition['attributes'];

        $elements = [];

        foreach ($tags as $tag) {
            $own = [];

            foreach ($attributes as $attribute => $on) {
                if (in_array('*', (array) $on, true) || in_array($tag, (array) $on, true)) {
                    $own[] = $attribute;
                }
            }

            $elements[] = $own === [] ? $tag : $tag.'['.implode('|', $own).']';
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', implode(',', $elements));
        $config->set('Attr.AllowedClasses', (array) $definition['classes']);
        $config->set('Attr.AllowedFrameTargets', self::TARGETS);
        $config->set('Attr.AllowedRel', self::REL_TOKENS);

        $schemes = ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true];

        if ((bool) $definition['img_data']) {
            $schemes['data'] = true;
        }

        $config->set('URI.AllowedSchemes', $schemes);

        $hosts = (array) $definition['iframe_hosts'];

        if ($hosts !== []) {
            $config->set('HTML.SafeIframe', true);
            $config->set('URI.SafeIframeRegexp', '%^('.implode('|', array_map(
                static fn (string $host): string => preg_quote($host, '%'),
                $hosts
            )).')%');
        }

        if ((array) $definition['css'] !== []) {
            $config->set('CSS.AllowedProperties', (array) $definition['css']);
        }

        $config->set('HTML.DefinitionID', 'app-richtext-'.md5(implode(',', $elements)));
        $config->set('HTML.DefinitionRev', 1);

        $raw = $config->maybeGetRawHTMLDefinition();

        if ($raw !== null) {
            if (in_array('figure', $tags, true)) {
                $raw->addElement('figure', 'Block', 'Optional: (figcaption, Flow) | (Flow, figcaption) | Flow', 'Common');
            }

            if (in_array('figcaption', $tags, true)) {
                $raw->addElement('figcaption', 'Inline', 'Flow', 'Common');
            }
        }

        return new \HTMLPurifier($config);
    }

    /*
    |--------------------------------------------------------------------------
    | The authoritative DOM walker
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function domPass(string $html, array $definition): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>'
                .$html
                .'</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            // Unparseable input: fail closed, as text.
            return htmlspecialchars(self::stripTemplateConstructs(strip_tags($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return '';
        }

        $allowedTags = array_flip(self::effectiveTags($definition));

        self::walkChildren($body, $definition, $allowedTags);

        $output = '';

        foreach (iterator_to_array($body->childNodes) as $child) {
            $output .= (string) $document->saveHTML($child);
        }

        // Belt and braces: nothing the walker let through may spell a template construct once
        // serialised, and no raw `<?` can exist in HTML the walker produced.
        $output = self::stripTemplateConstructs($output);

        return trim($output);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $allowedTags
     */
    private static function walkChildren(DOMNode $parent, array $definition, array $allowedTags): void
    {
        // Snapshot the list: the loop mutates the tree.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            self::sanitizeNode($node, $definition, $allowedTags);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $allowedTags
     */
    private static function sanitizeNode(DOMNode $node, array $definition, array $allowedTags): void
    {
        if ($node instanceof DOMText && $node->nodeType === XML_TEXT_NODE) {
            $clean = self::stripTemplateConstructs($node->data);

            if ($clean !== $node->data) {
                $node->data = $clean;
            }

            return;
        }

        if (! $node instanceof DOMElement) {
            // Comments, processing instructions (`<?php`), CDATA, entity references.
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->localName ?? $node->nodeName);

        if (in_array($tag, self::DROPPED_WITH_CONTENT, true) && ! ($tag === 'iframe' && isset($allowedTags['iframe']))) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if ($tag === 'iframe') {
            self::sanitizeIframe($node, $definition);

            return;
        }

        if (! isset($allowedTags[$tag])) {
            self::walkChildren($node, $definition, $allowedTags);
            self::unwrap($node);

            return;
        }

        self::sanitizeAttributes($node, $tag, $definition);

        if ($tag === 'img' && ! $node->hasAttribute('src')) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if ($tag === 'a' && ! $node->hasAttribute('href')) {
            self::walkChildren($node, $definition, $allowedTags);
            self::unwrap($node);

            return;
        }

        if ($tag === 'img' || $tag === 'br' || $tag === 'hr') {
            while ($node->firstChild !== null) {
                $node->removeChild($node->firstChild);
            }

            return;
        }

        self::walkChildren($node, $definition, $allowedTags);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function sanitizeIframe(DOMElement $node, array $definition): void
    {
        $src = self::normaliseUrl($node->getAttribute('src'));

        if (! self::iframeHostAllowed($src, (array) $definition['iframe_hosts'])) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $keep = [
            'src' => $src,
            'width' => self::dimension($node->getAttribute('width')),
            'height' => self::dimension($node->getAttribute('height')),
            'title' => self::stripTemplateConstructs(trim($node->getAttribute('title'))),
            'class' => self::classTokens($node->getAttribute('class'), (array) $definition['classes']),
        ];

        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $node->removeAttributeNode($attribute);
            }
        }

        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }

        foreach ($keep as $name => $value) {
            if ($value !== null && $value !== '') {
                $node->setAttribute($name, $value);
            }
        }

        // Sanitiser-owned attributes, never read from input: lazy, fullscreen-capable, and unable to
        // navigate the page that embeds it.
        $node->setAttribute('loading', 'lazy');
        $node->setAttribute('allowfullscreen', 'allowfullscreen');
        $node->setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation');
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function sanitizeAttributes(DOMElement $node, string $tag, array $definition): void
    {
        $allowed = (array) $definition['attributes'];
        $forceRel = false;

        foreach (iterator_to_array($node->attributes ?? []) as $attribute) {
            if (! $attribute instanceof DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->nodeName);
            $raw = (string) $attribute->value;
            $node->removeAttributeNode($attribute);

            // Core: event handlers never survive, whatever a profile lists.
            if (str_starts_with($name, 'on') || ! isset($allowed[$name])) {
                continue;
            }

            $on = (array) $allowed[$name];

            if (! in_array('*', $on, true) && ! in_array($tag, $on, true)) {
                continue;
            }

            // Core: a URL attribute may not contain a blocked scheme anywhere, and no other attribute
            // may start with one. (Prose such as alt="Profile: CEO" is not a URL and stays.)
            $normalised = self::normaliseUrl($raw);

            if (in_array($name, self::URL_ATTRIBUTES, true)
                ? self::hasBlockedScheme($normalised)
                : self::startsWithBlockedScheme($normalised)
            ) {
                continue;
            }

            $value = match ($name) {
                'href' => self::isSafeHref($raw) ? self::cleanUrl($raw) : null,
                'src' => self::imageSource($raw, $definition),
                'target' => in_array(strtolower(trim($raw)), self::TARGETS, true) ? strtolower(trim($raw)) : null,
                'rel' => self::relTokens($raw),
                'width', 'height' => self::dimension($raw),
                'class' => self::classTokens($raw, (array) $definition['classes']),
                'style' => self::cssDeclarations($raw, (array) $definition['css']),
                'align' => in_array(strtolower(trim($raw)), self::ALIGN_VALUES, true) ? strtolower(trim($raw)) : null,
                // Prose attributes (title, alt): no quote or angle bracket, so the serialiser never
                // has to switch quoting styles and no scanner can mistake the text for markup.
                default => self::stripTemplateConstructs(trim(str_replace(['"', '<', '>', '`'], '', $raw))),
            };

            if ($value === null || $value === '') {
                continue;
            }

            if ($name === 'target' && $value === '_blank') {
                $forceRel = true;
            }

            $node->setAttribute($name, $value);
        }

        if ($forceRel) {
            $tokens = array_filter(explode(' ', $node->getAttribute('rel')));
            $tokens = array_values(array_unique(array_merge($tokens, ['noopener', 'noreferrer'])));
            $node->setAttribute('rel', implode(' ', $tokens));
        }
    }

    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    /*
    |--------------------------------------------------------------------------
    | Value rules
    |--------------------------------------------------------------------------
    */

    /**
     * The profile's tags minus the common core — the non-weakenable intersection.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    private static function effectiveTags(array $definition): array
    {
        $tags = array_map('strtolower', (array) $definition['tags']);
        $tags = array_diff($tags, self::CORE_REMOVED_TAGS, array_diff(self::DROPPED_WITH_CONTENT, ['iframe']));

        if ((array) $definition['iframe_hosts'] === []) {
            $tags = array_diff($tags, ['iframe']);
        }

        return array_values(array_unique($tags));
    }

    /**
     * Remove control characters and whitespace a browser ignores inside a URL scheme, for checking.
     */
    private static function normaliseUrl(string $url): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('~[\x00-\x20\x7F]+~', '', $url);
    }

    /**
     * The value written back: trimmed, control characters removed, spaces kept.
     */
    private static function cleanUrl(string $url): string
    {
        return trim((string) preg_replace('~[\x00-\x1F\x7F]+~', '', $url));
    }

    private static function startsWithBlockedScheme(string $normalised): bool
    {
        $lower = strtolower($normalised);

        foreach (self::CORE_BLOCKED_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return true;
            }
        }

        return false;
    }

    private static function hasBlockedScheme(string $normalised): bool
    {
        $lower = strtolower($normalised);

        foreach (self::CORE_BLOCKED_SCHEMES as $scheme) {
            if (str_contains($lower, $scheme)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An `<img src>`: `https:` or site-relative under every profile, plus `data:` images and
     * document-relative paths where the profile allows them.
     *
     * @param  array<string, mixed>  $definition
     */
    private static function imageSource(string $raw, array $definition): ?string
    {
        $normalised = self::normaliseUrl($raw);

        if ($normalised === '') {
            return null;
        }

        if (preg_match('~^https://~i', $normalised) === 1 || preg_match('~^/(?![/\\\\])~', $normalised) === 1) {
            return self::cleanUrl($raw);
        }

        if ((bool) $definition['img_data'] && preg_match(self::DATA_IMAGE_PATTERN, trim($raw)) === 1) {
            return (string) preg_replace('~\s+~', '', trim($raw));
        }

        if ((bool) $definition['img_relative']
            && preg_match('~^[a-z][a-z0-9+.\-]*:~i', $normalised) !== 1
            && preg_match('~^[/\\\\]~', $normalised) !== 1
            && str_contains($normalised, '\\') === false
        ) {
            return self::cleanUrl($raw);
        }

        return null;
    }

    /**
     * @param  list<string>  $hosts
     */
    private static function iframeHostAllowed(string $src, array $hosts): bool
    {
        if ($hosts === [] || $src === '' || str_contains($src, '\\') || self::hasBlockedScheme($src)) {
            return false;
        }

        $parts = parse_url($src);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        ) {
            return false;
        }

        $lower = strtolower($src);

        foreach ($hosts as $prefix) {
            $prefix = strtolower($prefix);
            $prefixHost = (string) parse_url($prefix, PHP_URL_HOST);

            if (strtolower((string) ($parts['host'] ?? '')) !== $prefixHost || ! str_starts_with($lower, $prefix)) {
                continue;
            }

            $rest = substr($lower, strlen($prefix));

            // `.../maps/embed` must be followed by a query, a path or nothing — never `embedevil`.
            if (str_ends_with($prefix, '/') || $rest === '' || in_array($rest[0], ['?', '/', '#'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function dimension(string $raw): ?string
    {
        $raw = trim($raw);

        return preg_match('~^\d{1,4}%?$~', $raw) === 1 ? $raw : null;
    }

    private static function relTokens(string $raw): ?string
    {
        $tokens = preg_split('~\s+~', strtolower(trim($raw))) ?: [];
        $tokens = array_values(array_unique(array_intersect($tokens, self::REL_TOKENS)));

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function classTokens(string $raw, array $allowed): ?string
    {
        $tokens = preg_split('~\s+~', trim($raw)) ?: [];
        $tokens = array_values(array_unique(array_intersect($tokens, $allowed)));

        return $tokens === [] ? null : implode(' ', $tokens);
    }

    /**
     * One `style` attribute (or one rule body) reduced to allowlisted, inert declarations.
     *
     * @param  list<string>  $allowed
     */
    private static function cssDeclarations(string $raw, array $allowed): string
    {
        if ($allowed === []) {
            return '';
        }

        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = (string) preg_replace('~/\*.*?(\*/|$)~s', '', $raw);

        $kept = [];

        foreach (explode(';', $raw) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (! in_array($property, $allowed, true) || $value === '') {
                continue;
            }

            $lower = strtolower((string) preg_replace('~\s+~', '', $value));

            if (str_contains($value, '\\') || str_contains($value, '<') || str_contains($value, '>')
                || str_contains($lower, 'expression(') || str_contains($lower, '@import')
                || str_contains($lower, 'behavior') || str_contains($lower, '-moz-binding')
                || str_contains($lower, 'javascript:') || str_contains($lower, 'vbscript:')
                || str_contains($lower, 'file:')
                || str_contains($value, '{') || str_contains($value, '}')
            ) {
                continue;
            }

            if (str_contains($lower, 'url(') && ! self::cssUrlsAreLocal($value)) {
                continue;
            }

            $value = self::stripTemplateConstructs($value);

            if (trim($value) === '') {
                continue;
            }

            $kept[] = $property.': '.trim($value);
        }

        return implode('; ', $kept);
    }

    /**
     * Every `url()` in a value is a document-relative path or an allowlisted `data:` image — never an
     * external address, which would be a tracking pixel in a printed document.
     */
    private static function cssUrlsAreLocal(string $value): bool
    {
        if (preg_match_all('~url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)~i', $value, $matches) === false) {
            return false;
        }

        if (substr_count(strtolower($value), 'url(') !== count($matches[1])) {
            return false;
        }

        foreach ($matches[1] as $target) {
            $target = trim((string) $target);
            $normalised = self::normaliseUrl($target);

            if (preg_match(self::DATA_IMAGE_PATTERN, $target) === 1) {
                continue;
            }

            if ($normalised === ''
                || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $normalised) === 1
                || str_starts_with($normalised, '//')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function cssRule(string $selector, string $body, array $allowed): ?string
    {
        $selector = trim((string) preg_replace('/[^a-z0-9\s#.,:>+~*\-_\[\]="\'()]/i', '', $selector));
        $declarations = self::cssDeclarations($body, $allowed);

        if ($selector === '' || $declarations === '') {
            return null;
        }

        return $selector.' { '.$declarations.' }';
    }

    /**
     * Remove Blade and PHP constructs until none remain (a removal can join two halves into a new one).
     */
    private static function stripTemplateConstructs(string $value): string
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $next = (string) preg_replace(self::TEMPLATE_PATTERNS, '', $value);

            if ($next === $value) {
                return $value;
            }

            $value = $next;
        }

        return $value;
    }

    private static function stripInvalidUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
