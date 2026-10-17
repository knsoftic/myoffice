<?php

declare(strict_types=1);

namespace App\Support\Cms;

/**
 * The site-ownership meta tags the public `<head>` carries — Google Search Console, Bing, Yandex,
 * Pinterest, Facebook, and any other service through `seo.custom_meta_tags`.
 *
 * **Paste what the service gives you.** Every one of these services shows the owner a whole tag,
 * `<meta name="google-site-verification" content="abc…" />`, and the old field accepted only the
 * `content` value: a pasted tag failed a regex in the view and the tag silently never appeared, so
 * verification failed with nothing on the screen saying why. Each field now takes the code or the whole
 * tag, and the settings form refuses anything that is neither (`settingErrors()`), so a mistake is a
 * field error on save rather than a failed verification a day later.
 *
 * **Nothing pasted is ever printed.** A tag is parsed into a name and a content value, both checked, and
 * rebuilt by the view through the escaping echo. Only `name=` / `property=` tags are accepted — never
 * `http-equiv` (a refresh redirect, a content-security override) and never the names the SEO pipeline
 * already writes (robots, description, og:*, …), so a pasted tag cannot quietly de-index the site or
 * fight the page's own description.
 */
final class SiteVerificationTags
{
    /**
     * Setting key (group `seo`) => [meta name, service].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PROVIDERS = [
        'google_site_verification' => ['google-site-verification', 'Google Search Console'],
        'bing_site_verification' => ['msvalidate.01', 'Bing Webmaster Tools'],
        'yandex_verification' => ['yandex-verification', 'Yandex Webmaster'],
        'pinterest_verification' => ['p:domain_verify', 'Pinterest'],
        'facebook_domain_verification' => ['facebook-domain-verification', 'Meta (Facebook) business'],
    ];

    public const CUSTOM_KEY = 'custom_meta_tags';

    /** At most this many custom tags. */
    public const MAX_CUSTOM = 20;

    /** Names the page already writes, or that would change how the page is indexed or shown. */
    private const RESERVED = [
        'description', 'keywords', 'robots', 'googlebot', 'bingbot', 'viewport', 'theme-color',
        'format-detection', 'referrer', 'csrf-token', 'generator', 'author',
    ];

    private const RESERVED_PREFIXES = ['og:', 'twitter:', 'article:', 'fb:app_id'];

    private const TOKEN = '/^[A-Za-z0-9_\-=.+\/:]{4,190}$/';

    private const NAME = '/^[A-Za-z0-9_.:\-]{1,64}$/';

    /**
     * The tags for the public `<head>`: the five services first, then the custom ones. A custom tag that
     * repeats a service's name is dropped — the dedicated field wins.
     *
     * @return list<array{attribute: 'name'|'property', name: string, content: string}>
     */
    public static function forHead(callable $setting): array
    {
        $tags = [];
        $seen = [];

        foreach (self::PROVIDERS as $key => [$name]) {
            $token = self::token($setting('seo.'.$key), $name);

            if ($token !== null) {
                $tags[] = ['attribute' => 'name', 'name' => $name, 'content' => $token];
                $seen[strtolower($name)] = true;
            }
        }

        foreach (self::parseCustom($setting('seo.'.self::CUSTOM_KEY))['tags'] as $tag) {
            if (! isset($seen[strtolower($tag['name'])])) {
                $tags[] = $tag;
                $seen[strtolower($tag['name'])] = true;
            }
        }

        return $tags;
    }

    /**
     * The verification code from what an owner pasted: the code itself, or a whole `<meta>` tag whose
     * content is taken (and whose name, when it has one, must be this service's). Null when it is neither.
     */
    public static function token(mixed $raw, ?string $expectedName = null): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        if (str_contains($raw, '<') || preg_match('/\bcontent\s*=/i', $raw) === 1) {
            $tag = self::parseTag($raw);

            if ($tag === null || ($expectedName !== null && strcasecmp($tag['name'], $expectedName) !== 0)) {
                return null;
            }

            $raw = $tag['content'];
        }

        return preg_match(self::TOKEN, $raw) === 1 ? $raw : null;
    }

    /**
     * Every `<meta>` tag in the custom box, and a message for each line that is not one.
     *
     * @return array{tags: list<array{attribute: 'name'|'property', name: string, content: string}>, errors: list<string>}
     */
    public static function parseCustom(mixed $raw): array
    {
        $tags = [];
        $errors = [];

        if (! is_string($raw) || trim($raw) === '') {
            return ['tags' => [], 'errors' => []];
        }

        // One tag per line is what people paste, but two on a line, or one broken over two, still parse.
        preg_match_all('/<meta\b[^>]*>/i', $raw, $matches);
        $leftover = trim((string) preg_replace('/<meta\b[^>]*>/i', '', $raw));

        // Anything that is not a meta tag — a <script>, a stray word — is refused, not quietly dropped.
        if (($leftover !== '' && trim(strip_tags($leftover)) !== '') || preg_match('/<(?!meta\b)[a-z!\/]/i', $leftover) === 1) {
            $errors[] = 'Only <meta> tags can go here — remove everything else (scripts, links, text).';
        }

        foreach ($matches[0] as $index => $html) {
            $tag = self::parseTag($html);
            $position = sprintf('Tag %d', $index + 1);

            if (preg_match('/\b(http-equiv|charset)\s*=/i', $html) === 1) {
                $errors[] = $position.': http-equiv and charset tags are not allowed — they can redirect the site or change its security headers.';

                continue;
            }

            if ($tag === null) {
                $errors[] = $position.' needs a name (or property) and a content value, e.g. <meta name="ahrefs-site-verification" content="abc123">.';

                continue;
            }

            if (self::reserved($tag['name'])) {
                $errors[] = sprintf('%s: "%s" is set elsewhere in Settings (SEO), not here.', $position, $tag['name']);

                continue;
            }

            $tags[] = $tag;
        }

        if (count($tags) > self::MAX_CUSTOM) {
            $errors[] = sprintf('At most %d custom tags.', self::MAX_CUSTOM);
            $tags = array_slice($tags, 0, self::MAX_CUSTOM);
        }

        return ['tags' => $tags, 'errors' => $errors];
    }

    /**
     * Field errors for a save of the `seo` group (`SettingsRegistry::crossFieldErrors()`).
     *
     * @param  callable(string): mixed  $effective  bare key => value after the save
     * @param  list<string>  $submitted
     * @return array<string, string>
     */
    public static function settingErrors(callable $effective, array $submitted): array
    {
        $errors = [];

        foreach (self::PROVIDERS as $key => [$name, $service]) {
            if (! in_array($key, $submitted, true)) {
                continue;
            }

            $value = $effective($key);

            if (is_string($value) && trim($value) !== '' && self::token($value, $name) === null) {
                $errors[$key] = sprintf(
                    'That is not a %s verification code. Paste the code, or the whole tag %s gives you: <meta name="%s" content="…">.',
                    $service,
                    $service,
                    $name,
                );
            }
        }

        if (in_array(self::CUSTOM_KEY, $submitted, true)) {
            $parsed = self::parseCustom($effective(self::CUSTOM_KEY));

            if ($parsed['errors'] !== []) {
                $errors[self::CUSTOM_KEY] = implode(' ', $parsed['errors']);
            }
        }

        return $errors;
    }

    /**
     * @return array{attribute: 'name'|'property', name: string, content: string}|null
     */
    private static function parseTag(string $html): ?array
    {
        preg_match_all('/([A-Za-z][A-Za-z0-9_:\-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\/]+))/', $html, $pairs, PREG_SET_ORDER);

        $attributes = [];

        foreach ($pairs as $pair) {
            $attributes[strtolower($pair[1])] = html_entity_decode($pair[2] !== '' ? $pair[2] : (($pair[3] ?? '') !== '' ? $pair[3] : ($pair[4] ?? '')), ENT_QUOTES | ENT_HTML5);
        }

        if (isset($attributes['http-equiv']) || isset($attributes['charset'])) {
            return null;
        }

        $attribute = isset($attributes['name']) ? 'name' : (isset($attributes['property']) ? 'property' : null);
        $name = $attribute === null ? '' : trim($attributes[$attribute]);
        $content = trim((string) ($attributes['content'] ?? ''));

        if ($attribute === null || preg_match(self::NAME, $name) !== 1 || $content === '' || mb_strlen($content) > 500) {
            return null;
        }

        return ['attribute' => $attribute, 'name' => $name, 'content' => $content];
    }

    private static function reserved(string $name): bool
    {
        $name = strtolower($name);

        if (in_array($name, self::RESERVED, true)) {
            return true;
        }

        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
