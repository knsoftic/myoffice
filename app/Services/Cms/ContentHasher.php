<?php

declare(strict_types=1);

namespace App\Services\Cms;

use BackedEnum;
use DateTimeInterface;
use JsonException;

/**
 * Canonical payloads and their hashes — what makes INV-4 ("unpublished changes can never lie") true
 * (phase-03 §6.2 `CmsHasher`).
 *
 * `website_sections.has_unpublished_changes` and `pages.has_unpublished_changes` are STORED generated
 * columns derived from `content_hash <> published_hash`. They are only as honest as the hash, so the
 * hash is computed here and nowhere else, over a **canonical** array:
 *
 *   · associative arrays are key-sorted recursively; lists keep their order (an item order is data)
 *   · enums become their backing value, dates an ISO string, floats a locale-free string — no
 *     `serialize()`, no float formatting, so the same draft hashes identically on every PHP build
 *   · every key named `id` inside `items` is dropped before hashing: an item recreated by a revert
 *     has a new id and the same content, and must hash as "identical to the live version"
 *
 * Section canonical shape (built by `SectionService::canonicalPayload()`):
 *
 *     [
 *       'section_key' => 'hero',
 *       'fields'  => [...content, key-sorted],
 *       'columns' => ['cta_block_id' => ?int, 'menu_id' => ?int],
 *       'items'   => [group => [[sort_order, content, metric, value_mode, manual_value,
 *                                 media_asset_id, is_enabled, id], ...]],
 *       'media'   => [role => [asset ids in sort order]],
 *       'faqs'    => [faq ids in pivot order],
 *     ]
 *
 * Page canonical shape: `['content' => (string) $html]` — the page body is the only snapshot-published
 * column of `pages` (§2.7, §2.15); title, banner and template are live columns.
 *
 * Pure: no container, no database, no facade.
 */
final class ContentHasher
{
    /**
     * sha1 over the canonical JSON — the value of `content_hash` / `published_hash` (char 40).
     *
     * @param  array<string, mixed>  $canonical
     */
    public function hash(array $canonical): string
    {
        return sha1($this->json($canonical, forHash: true));
    }

    /**
     * The canonical JSON: what `cms_revisions.snapshot` stores. With `$forHash` the item ids are
     * removed first (see the class note).
     *
     * @param  array<string, mixed>  $canonical
     *
     * @throws JsonException
     */
    public function json(array $canonical, bool $forHash = false): string
    {
        if ($forHash && isset($canonical['items']) && is_array($canonical['items'])) {
            foreach ($canonical['items'] as $group => $items) {
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $index => $item) {
                    if (is_array($item)) {
                        unset($item['id']);
                        $canonical['items'][$group][$index] = $item;
                    }
                }
            }
        }

        return json_encode(
            $this->canonicalise($canonical),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /**
     * The page canonical payload.
     *
     * @return array{content: string}
     */
    public function pageCanonical(?string $content): array
    {
        return ['content' => (string) $content];
    }

    public function pageHash(?string $content): string
    {
        return $this->hash($this->pageCanonical($content));
    }

    /**
     * Recursively normalise a value into a deterministic, JSON-safe shape.
     */
    public function canonicalise(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_float($value)) {
            // Locale-independent in PHP 8; never exponent-formatted for the magnitudes CMS content holds.
            $string = (string) $value;

            return str_contains($string, 'E') ? sprintf('%.2F', $value) : $string;
        }

        if (is_string($value)) {
            // One newline convention, so a CRLF paste and an LF paste of the same text hash alike.
            return str_replace("\r\n", "\n", $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalise($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalise($item);
        }

        return $value;
    }
}
