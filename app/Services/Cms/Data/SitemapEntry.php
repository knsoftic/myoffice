<?php

declare(strict_types=1);

namespace App\Services\Cms\Data;

use App\Enums\Cms\SitemapChangeFrequency;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One `<url>` of `sitemap.xml` (phase-03 §6.5 `SitemapUrl`).
 *
 * Invariants: `loc` is an absolute `http(s)` URL; `priority` is a one-decimal **string** between
 * "0.0" and "1.0" (the `decimal(2,1)` column, never a float); `provider` names who contributed the
 * entry so `sitemap_generations.providers` can count per phase.
 */
readonly class SitemapEntry
{
    public string $priority;

    public function __construct(
        public string $loc,
        public ?CarbonInterface $lastmod,
        public SitemapChangeFrequency $changefreq,
        string $priority,
        public string $provider = 'pages',
    ) {
        if (preg_match('~^https?://~i', $loc) !== 1) {
            throw new InvalidArgumentException(sprintf('A sitemap location must be absolute: [%s].', $loc));
        }

        $this->priority = self::priority($priority);
    }

    /**
     * Normalise to "0.0".."1.0" without float arithmetic: out-of-range or malformed input becomes "0.5".
     */
    public static function priority(string $value): string
    {
        $value = trim($value);

        if (preg_match('~^(0(\.\d+)?|1(\.0+)?|\.\d+)$~', $value) !== 1) {
            return '0.5';
        }

        if (str_starts_with($value, '1')) {
            return '1.0';
        }

        $digits = str_pad(substr((string) strstr($value, '.'), 1), 2, '0');
        $tenths = (int) $digits[0];

        // Half-up on the hundredths digit, capped at 1.0.
        if ((int) $digits[1] >= 5) {
            $tenths++;
        }

        return $tenths >= 10 ? '1.0' : '0.'.$tenths;
    }

    /**
     * Build from a provider's array (`loc`, `lastmod`, `changefreq`, `priority`).
     *
     * @param  array<string, mixed>  $entry
     */
    public static function fromArray(array $entry, string $provider): self
    {
        $lastmod = $entry['lastmod'] ?? null;
        $changefreq = $entry['changefreq'] ?? null;

        return new self(
            loc: (string) ($entry['loc'] ?? ''),
            lastmod: $lastmod instanceof CarbonInterface ? $lastmod : ($lastmod === null ? null : Carbon::parse((string) $lastmod)),
            changefreq: $changefreq instanceof SitemapChangeFrequency
                ? $changefreq
                : (SitemapChangeFrequency::tryFrom((string) $changefreq) ?? SitemapChangeFrequency::Weekly),
            priority: (string) ($entry['priority'] ?? '0.5'),
            provider: $provider,
        );
    }
}
