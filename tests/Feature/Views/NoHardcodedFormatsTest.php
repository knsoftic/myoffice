<?php

declare(strict_types=1);

namespace Tests\Feature\Views;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * No Blade view formats a date, a time or a number by hand (phase-02 §3).
 *
 * Every date and number a screen renders goes through `App\Support\Format` — `app_date()`,
 * `app_time()`, `app_datetime()`, `app_number()` and `money()` — so `localization.date_format`,
 * `time_format`, the separators and the display timezone (D61) restyle the whole application at
 * once. A single `->format('d M Y, H:i')` left in a view silently ignores all four settings and,
 * because a raw Carbon value is still in UTC, shows the wrong hour as well.
 *
 * This is a static scan of `resources/views`: it fails on `->format(`, `number_format(` and a
 * global `date(` call (plus Carbon's `->isoFormat(` / `->translatedFormat(`, the same hand-rolled
 * format under another name). Blade comments are ignored, so documentation may quote the forms it
 * forbids.
 *
 * Fixing a hit is almost always a one-word change — including machine formats: an
 * `<input type="date">` value is `app_date($value, 'Y-m-d')`, which also converts into the viewer's
 * timezone first. Reach for the allow-list only when the code genuinely is not ours.
 */
final class NoHardcodedFormatsTest extends TestCase
{
    /**
     * What counts as a hand-rolled format, name => pattern.
     *
     * The look-behind on the two function forms (`(?<![\w$>:])`) keeps the helpers and methods
     * that share the name out of it: `app_date(`, `Format::date(`, `$request->date(`, and a closure
     * held in `$date(`. A leading backslash (`\date('Y')`) is still the global function and is still
     * caught. `date(` also requires the argument to open with a quote or a variable, so prose such
     * as "Start date (optional)" in a label is not mistaken for a call.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '->format(' => '/->\s*format\s*\(/',
        '->isoFormat(' => '/->\s*isoFormat\s*\(/',
        '->translatedFormat(' => '/->\s*translatedFormat\s*\(/',
        'number_format(' => '/(?<![\w$>:])number_format\s*\(/',
        'date(' => '/(?<![\w$>:])date\(\s*[\'"$]/',
    ];

    /**
     * Paths excused from the scan, relative to `resources/views` with forward slashes, each with the
     * reason it is excused. An entry ending in "/" excuses that whole directory.
     *
     * Keep this list SMALL and keep every entry commented. An application view never belongs here:
     * if a screen needs a format the helpers cannot express, pass the format to the helper
     * (`app_date($value, 'Y-m-d')`) or add a method to `App\Support\Format`.
     *
     * @var array<string, string>
     */
    private const ALLOW_LIST = [
        // Framework and package views copied in by `php artisan vendor:publish` (pagination, mail,
        // notifications). They are upstream code: a re-publish overwrites them, so a fix made there
        // would not survive, and none of them renders a stored application timestamp.
        'vendor/' => 'published package views',
    ];

    #[Test]
    public function no_view_formats_a_date_or_a_number_by_hand(): void
    {
        $violations = [];

        foreach ($this->viewFiles() as $relative => $path) {
            if ($this->isAllowListed($relative)) {
                continue;
            }

            foreach ($this->violationsIn((string) file_get_contents($path)) as $hit) {
                $violations[] = sprintf('%s:%d  %s  ->  %s', $relative, $hit['line'], $hit['pattern'], $hit['code']);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'These views format a date or a number by hand. Use app_date(), app_time(), app_datetime(), '.
            "app_number() or money() so the localization settings and the display timezone apply:\n  ".
            implode("\n  ", $violations)
        );
    }

    /**
     * Guards the guard: a scan that silently reads nothing passes whatever the views contain.
     */
    #[Test]
    public function the_scan_actually_reads_the_views(): void
    {
        $files = $this->viewFiles();

        $this->assertGreaterThan(20, count($files), 'The view scan found almost no Blade files — the path is wrong.');
        $this->assertArrayHasKey('layouts/admin.blade.php', $files);
        $this->assertArrayHasKey('account/profile.blade.php', $files);
        $this->assertArrayHasKey('components/ui/pagination-summary.blade.php', $files);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function handRolledFormats(): array
    {
        return [
            'carbon format' => ["{{ \$user->created_at->format('d M Y') }}"],
            'nullsafe carbon format' => ["{{ \$entry->logged_out_at?->format('H:i') }}"],
            'spaced arrow' => ["{{ \$date -> format('Y') }}"],
            'chained timezone then format' => ["{{ \$row->created_at->timezone(\$tz)->format('j M Y, g:i A') }}"],
            'iso format' => ["{{ \$post->published_at->isoFormat('LL') }}"],
            'translated format' => ["{{ \$post->published_at->translatedFormat('j F Y') }}"],
            'number_format' => ['{{ number_format($total) }}'],
            'number_format with decimals' => ['{{ number_format((float) $amount, 2) }}'],
            'fully qualified number_format' => ['{{ \number_format($total) }}'],
            'global date' => ["&copy; {{ date('Y') }}"],
            'fully qualified date' => ['{{ \date("d/m/Y", $timestamp) }}'],
            'date with a variable format' => ['{{ date($format) }}'],
        ];
    }

    #[Test]
    #[DataProvider('handRolledFormats')]
    public function the_patterns_catch_a_hand_rolled_format(string $snippet): void
    {
        $this->assertNotSame([], $this->violationsIn($snippet), "Not caught: {$snippet}");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sanctionedForms(): array
    {
        return [
            'app_date' => ['{{ app_date($invoice->issued_on) }}'],
            'app_date with a machine format' => ["<input type=\"date\" value=\"{{ app_date(\$value, 'Y-m-d') }}\">"],
            'app_time' => ['{{ app_time($session->starts_at) }}'],
            'app_datetime' => ['{{ app_datetime($activity->created_at) }}'],
            'app_number' => ['{{ app_number($paginator->total()) }}'],
            'money' => ['{{ money($invoice->total_amount) }}'],
            'format class' => ['{{ \App\Support\Format::date($value) }}'],
            'relative time' => ['{{ \App\Support\Format::forHumans($entry->created_at) }}'],
            'closure named date' => ['{{ $date($value) }}'],
            'request date method' => ["{{ \$request->date('from') }}"],
            'prose in a label' => ['<label>Start date (optional)</label>'],
            'javascript date' => ['<span x-text="new Date().getFullYear()"></span>'],
            'blade comment' => ["{{-- never \$value->format('d M Y') or number_format(\$n) --}}"],
            'multi-line blade comment' => ["{{--\n    Do not call date('Y') here.\n--}}"],
        ];
    }

    #[Test]
    #[DataProvider('sanctionedForms')]
    public function the_patterns_spare_the_helpers_and_prose(string $snippet): void
    {
        $this->assertSame([], $this->violationsIn($snippet), "Wrongly flagged: {$snippet}");
    }

    #[Test]
    public function a_hit_reports_the_line_it_is_on_even_below_a_comment(): void
    {
        $body = "{{--\n  a comment\n  over three lines\n--}}\n<p>ok</p>\n<p>{{ number_format(\$n) }}</p>\n";

        $hits = $this->violationsIn($body);

        $this->assertCount(1, $hits);
        $this->assertSame(6, $hits[0]['line']);
        $this->assertSame('number_format(', $hits[0]['pattern']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Every Blade file under resources/views, keyed by its path relative to that directory.
     *
     * @return array<string, string>
     */
    private function viewFiles(): array
    {
        $root = resource_path('views');
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function isAllowListed(string $relative): bool
    {
        foreach (array_keys(self::ALLOW_LIST) as $entry) {
            if (str_ends_with($entry, '/') ? str_starts_with($relative, $entry) : $relative === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every hand-rolled format in a Blade body, with the line it sits on.
     *
     * Blade comments are blanked first — replaced by as many newlines as they spanned, so the line
     * numbers of everything after them stay true.
     *
     * @return list<array{line: int, pattern: string, code: string}>
     */
    private function violationsIn(string $body): array
    {
        $code = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
            $body,
        );

        $hits = [];

        foreach (preg_split('/\r\n|\r|\n/', $code) ?: [] as $index => $line) {
            foreach (self::PATTERNS as $name => $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $hits[] = ['line' => $index + 1, 'pattern' => $name, 'code' => trim($line)];
                }
            }
        }

        return $hits;
    }
}
