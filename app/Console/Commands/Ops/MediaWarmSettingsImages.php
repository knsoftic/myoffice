<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Services\Cms\SettingsImageService;
use App\Support\SettingsRegistry;
use App\Support\SettingsRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `media:warm-settings-images` — build the derivatives of the Settings images before a visitor asks.
 *
 * `SettingsImageService` generates on first use and remembers the result, which is the right
 * behaviour for a screen but the wrong thing to leave to chance on a public home page. Measured on
 * the real 4167x1571 logo: **1,086 ms cold, 3 ms warm**. A site with a light and a dark logo
 * therefore pays about two and a quarter seconds on exactly one request after a deploy — and the
 * visitor who pays it is as likely as not to be a performance audit, which then reports a number
 * nobody will ever see again and no amount of re-measuring explains.
 *
 * So this runs in the deploy, after `storage:link` and before the site takes traffic. It is
 * idempotent: a derivative that already exists is not re-encoded, so running it twice costs the
 * handful of `exists()` calls that the warm path costs.
 *
 * **It cannot fail a deploy.** Every image is attempted independently and a failure is reported and
 * counted, never thrown: a logo that will not encode must leave the site serving the original, which
 * is exactly what the view falls back to. The exit code is non-zero only with `--strict`, for a
 * pipeline that would rather stop and be told.
 *
 * **Which keys it warms, and why it is a list.** The registry declares seven public image settings —
 * the two logos, `favicon`, `og_image` (twice), `email_logo` and `login_background` — and warming all
 * seven would be busywork on five of them. A favicon is fetched by the browser as it was uploaded; an
 * Open Graph image is fetched by a scraper that has never heard of `srcset`; an email logo is rendered
 * by mail clients, most of which ignore `srcset` too. Derivatives only pay for themselves where
 * something renders a `<picture>`, which today is `<x-site.brand>` and the two logos it reads. So the
 * list below is not a shortcut around the registry: each entry is a decision about which profile an
 * image is rendered under, and "none, it is served as uploaded" is a valid answer that a registry
 * sweep would silently get wrong.
 */
final class MediaWarmSettingsImages extends Command
{
    protected $signature = 'media:warm-settings-images
                            {--strict : Exit non-zero when an image could not be processed}
                            {--json : Machine-readable output}';

    protected $description = 'Pre-build the srcset derivatives of the images uploaded through Settings.';

    /**
     * Every settings image that is rendered through `<x-site.image>`, and the profile it is rendered
     * under.
     *
     * Add a row here when a new settings image starts being rendered as a `<picture>` — not when one
     * is merely added to the registry. The class docblock says why.
     *
     * @var array<string, string>
     */
    private const PROFILES = [
        'branding.logo_light' => 'logo',
        'branding.logo_dark' => 'logo',
    ];

    public function handle(SettingsImageService $images, SettingsRepository $settings): int
    {
        $warmed = [];
        $failed = [];
        $skipped = [];

        foreach (array_keys(self::PROFILES) as $key) {
            // Checked against the registry so that a typo — or a key a later phase renames — is a
            // named failure at deploy time, not a silent no-op that reads as "warmed nothing, fine".
            if (SettingsRegistry::field($key) === null) {
                $failed[$key] = 'not a declared setting — renamed or removed?';

                continue;
            }

            $path = $settings->get($key);

            if (! is_string($path) || trim($path) === '') {
                $skipped[$key] = 'no image uploaded';

                continue;
            }

            $profile = self::PROFILES[$key] ?? 'logo';

            try {
                $started = microtime(true);
                $snapshot = $images->snapshot($path, $profile);
                $ms = (int) round((microtime(true) - $started) * 1000);

                if ($snapshot === null) {
                    // Not an exception: the service degrades to the original rather than throwing,
                    // so this is the one place that difference becomes visible to an operator.
                    $failed[$key] = 'no derivative could be produced (the original will be served)';

                    continue;
                }

                $warmed[$key] = [
                    'profile' => $profile,
                    'widths' => $this->widthsIn((string) $snapshot['srcset']),
                    'original_bytes' => $this->bytesOf($path),
                    'smallest_bytes' => $this->smallestIn((string) ($snapshot['webp_srcset'] ?: $snapshot['srcset'])),
                    'ms' => $ms,
                ];
            } catch (Throwable $e) {
                $failed[$key] = $e->getMessage();
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'warmed' => $warmed,
                'failed' => $failed,
                'skipped' => $skipped,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed !== [] && $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        foreach ($warmed as $key => $row) {
            $saving = $row['original_bytes'] > 0 && $row['smallest_bytes'] > 0
                ? sprintf(' — %s to %s', $this->human($row['original_bytes']), $this->human($row['smallest_bytes']))
                : '';

            $this->components->twoColumnDetail(
                sprintf('%s <fg=gray>(%s: %s)</>', $key, $row['profile'], implode(', ', $row['widths'])),
                sprintf('<fg=green>ready</>%s <fg=gray>%dms</>', $saving, $row['ms']),
            );
        }

        foreach ($skipped as $key => $why) {
            $this->components->twoColumnDetail($key, sprintf('<fg=gray>skipped — %s</>', $why));
        }

        foreach ($failed as $key => $why) {
            $this->components->twoColumnDetail($key, sprintf('<fg=yellow>%s</>', $why));
        }

        if ($warmed === [] && $failed === []) {
            $this->components->info('No Settings images are uploaded, so there is nothing to warm.');

            return self::SUCCESS;
        }

        $this->newLine();

        if ($failed === []) {
            $this->components->info(sprintf('%d image(s) warmed. The first visitor pays nothing.', count($warmed)));

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d warmed, %d could not be processed. The site serves the original for those, which is slow but not broken.',
            count($warmed),
            count($failed),
        ));

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function widthsIn(string $srcset): array
    {
        preg_match_all('/\s(\d+)w/', $srcset, $matches);

        return array_map(static fn (string $w): string => $w.'w', $matches[1]);
    }

    private function smallestIn(string $srcset): int
    {
        $sizes = [];

        foreach (array_filter(array_map('trim', explode(',', $srcset))) as $entry) {
            $url = (string) (preg_split('/\s+/', $entry)[0] ?? '');
            $bytes = $this->bytesOf($this->relative($url));

            if ($bytes > 0) {
                $sizes[] = $bytes;
            }
        }

        return $sizes === [] ? 0 : min($sizes);
    }

    private function bytesOf(string $path): int
    {
        try {
            $disk = Storage::disk('public');
            $path = ltrim($path, '/');

            return $disk->exists($path) ? (int) $disk->size($path) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /** A public-disk URL back to the path the disk knows it by. */
    private function relative(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);

        return (string) preg_replace('~^/?storage/~', '', $path);
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%.1f KB', $bytes / 1024);
    }
}
