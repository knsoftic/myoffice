<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * PRF-05 — every listing is bounded before it is rendered (phase-24-25 §6.4, §11.7).
 *
 * **A screen that loads a whole table is not slow, it is a time bomb.** It is quick on the demo
 * data, quick on the client's first month, and then one afternoon in year three somebody opens the
 * fee register and the request dies on `memory_limit` — with no code change to blame it on, because
 * nothing changed except that the business worked. The defect was shipped the day the query was
 * written, and no runtime test catches it, because at test time the table has forty rows in it.
 *
 * So this is a **static scan**, not a runtime measurement. It reads the source of every controller
 * under `app/Http/Controllers` and fails on `::all()`, `->get()` or `->cursor()` against a model,
 * inside an `index` / `board` / `calendar` / `export` action, where nothing in the chain bounds the
 * number of rows. What "bounded" means is spelled out in {@see self::boundedBy()} — an explicit
 * `->limit()`, a small reference table (`tests/Support/small-reference-tables.php`), or a restriction
 * to a key set the request already holds in memory.
 *
 * **The scan resolves the model through the file's `use` statements**, which is why it only reports
 * a chain whose head is a model class it can name. A chain starting at `$this->filtered($request)`
 * or `$employee->attendances()` is invisible to it: a false negative, deliberately, because the
 * alternative is a false positive, and a checker that cries wolf is a checker somebody deletes.
 *
 * The first two tests exist because **an assertion that never fires is not an assertion**: a scan
 * with a broken regex reports a clean bill over two hundred controllers and reads exactly like a
 * codebase with no findings in it.
 *
 * **`test_everything_paginates` is PRF-05's own name** and carries the scan itself; the page-size
 * clamp keeps a descriptive name because it is the same id's second clause, and the two fail for
 * entirely different reasons.
 *
 * The class carries `#[Group('perf')]` because `php artisan test --group=perf` (§11) is otherwise a
 * command that runs nothing and reports success.
 */
#[Group('perf')]
final class PaginationScanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The actions a listing lives in.
     *
     * Matched as a **prefix**, so `exportCsv`, `indexJson` and `calendarFeed` are covered: a listing
     * that renders to CSV is still a listing, and naming the method differently does not bound it.
     */
    private const LISTING_ACTIONS = '/^(index|board|calendar|export)/';

    /*
    |--------------------------------------------------------------------------
    | The scan catches what it claims to
    |--------------------------------------------------------------------------
    */

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function snippetProvider(): iterable
    {
        $controller = static fn (string $body, string $action = 'index'): string => <<<PHP
        <?php
        namespace App\\Http\\Controllers\\Admin;
        use App\\Models\\Institute\\Student;
        use App\\Models\\Hr\\LeaveType;
        use App\\Models\\Crm\\Lead;
        class SampleController {
            public function {$action}(\$request) {
                {$body}
            }
        }
        PHP;

        yield 'a whole table in an index' => [
            $controller("\$rows = Student::query()->orderBy('name')->get();"),
            true,
        ];

        yield 'Model::all() in an index' => [
            $controller('$rows = Student::all();'),
            true,
        ];

        yield 'a cursor over a whole table in an export' => [
            $controller('$rows = Student::query()->cursor();', 'export'),
            true,
        ];

        yield 'a board loading every card' => [
            $controller("\$cards = Lead::query()->where('status', 'new')->get();", 'board'),
            true,
        ];

        yield 'a calendar loading every row' => [
            $controller('$rows = Student::query()->get();', 'calendar'),
            true,
        ];

        yield 'an explicit limit bounds it' => [
            $controller('$rows = Student::query()->latest()->limit(20)->get();'),
            false,
        ];

        yield 'take() bounds it just as well' => [
            $controller('$rows = Student::query()->take(20)->get();'),
            false,
        ];

        yield 'a small reference table may be loaded whole' => [
            $controller("\$types = LeaveType::query()->orderBy('name')->get();"),
            false,
        ];

        yield 'a key set already in memory bounds it' => [
            $controller("\$rows = Student::query()->whereIn('id', \$page->pluck('student_id'))->get();"),
            false,
        ];

        yield 'paginate is not a finding' => [
            $controller('$rows = Student::query()->paginate(25);'),
            false,
        ];

        yield 'chunkById is not a finding' => [
            $controller('Student::query()->chunkById(500, fn ($rows) => $rows);', 'export'),
            false,
        ];

        yield 'the same query outside a listing action is not this test\'s business' => [
            $controller('$rows = Student::query()->get();', 'show'),
            false,
        ];

        yield 'a request bag is not a model' => [
            $controller("\$term = \$request->get('q');"),
            false,
        ];

        yield 'a collection get() is not a model' => [
            $controller('$first = $rows->get(0);'),
            false,
        ];
    }

    #[Test]
    #[DataProvider('snippetProvider')]
    public function the_scan_reports_an_unbounded_listing_and_nothing_else(string $source, bool $expected): void
    {
        $findings = $this->scan('SampleController.php', $source);

        $this->assertSame(
            $expected,
            $findings !== [],
            $expected
                ? 'This listing is unbounded and the scan did not report it — the scan does nothing.'
                : 'The scan reported a bounded listing: '.implode(' | ', array_column($findings, 'chain')),
        );
    }

    /**
     * Guards the guard: a scan that reads nothing passes whatever the controllers contain.
     */
    #[Test]
    public function the_scan_actually_reads_the_controllers(): void
    {
        $files = $this->controllerFiles();

        $this->assertGreaterThan(
            100,
            count($files),
            'The controller scan found almost no files — the path is wrong and every assertion below is vacuous.',
        );

        $this->assertArrayHasKey('Admin/Finance/InvoiceController.php', $files);

        $actions = 0;

        foreach ($files as $path) {
            $actions += count($this->listingActions((string) file_get_contents($path)));
        }

        $this->assertGreaterThan(
            100,
            $actions,
            'The scan found almost no index/board/calendar/export actions — the action matcher is broken.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The rule
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function test_everything_paginates(): void
    {
        $findings = [];

        foreach ($this->controllerFiles() as $relative => $path) {
            foreach ($this->scan($relative, (string) file_get_contents($path)) as $finding) {
                $findings[] = sprintf(
                    '%s:%d  %s()  %s  ->  %s',
                    $relative,
                    $finding['line'],
                    $finding['action'],
                    $finding['model'],
                    mb_substr($finding['chain'], 0, 120),
                );
            }
        }

        sort($findings);

        $this->assertSame(
            [],
            $findings,
            "These listings load an unbounded number of rows (phase-24-25 §6.4). Each one is quick today and\n".
            "fatal at volume. Fix it with ->paginate(), with an explicit ->limit() behind a searchable\n".
            "control, or — for an export — by streaming (LazyCollection + chunkById). Adding the model to\n".
            "tests/Support/small-reference-tables.php is not a fix unless a human really does maintain that\n".
            "table by hand:\n  ".implode("\n  ", $findings)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | The page size the listings paginate by
    |--------------------------------------------------------------------------
    */

    /**
     * `per_page()` is the other half of PRF-05: pagination that can be asked for 5,000 rows is not
     * pagination. The clamp lives in the helper rather than in a form rule because a value written
     * before the rule existed — by a seeder, by a raw SQL edit, by an import — must be clamped too.
     */
    #[Test]
    public function per_page_is_clamped_and_a_nonsense_value_falls_back(): void
    {
        $this->assertSame(100, $this->perPageFor(5000), 'A page size above 100 must be clamped to 100.');
        $this->assertSame(100, $this->perPageFor('100'), 'A numeric string is still a number.');
        $this->assertSame(10, $this->perPageFor(1), 'A page size below 10 must be lifted to 10.');
        $this->assertSame(15, $this->perPageFor('a lot'), 'A non-numeric page size falls back to the default, never errors.');
        $this->assertSame(15, $this->perPageFor(null), 'A missing page size falls back to the default.');
        $this->assertSame(25, $this->perPageFor(25), 'A sane page size is left alone.');
    }

    /**
     * `per_page()` with one stored value in place.
     *
     * The row is written straight to the table and the cache flushed, rather than going through
     * `SettingsService`: that path validates, and the values this test cares about are precisely the
     * ones validation would have refused. The clamp exists **because** such a value can be in the
     * table — written by an import, by a raw SQL edit, or before the rule existed — so a test that
     * can only produce valid values is a test of nothing. `null` means "no row at all".
     */
    private function perPageFor(mixed $value): int
    {
        $query = DB::table('settings')->where('group', 'appearance')->where('key', 'table_page_size');

        if ($value === null) {
            $query->update(['value' => null]);
        } else {
            $query->update(['value' => (string) $value, 'type' => 'string']);
        }

        settings_repo()->flush();

        return per_page();
    }

    /*
    |--------------------------------------------------------------------------
    | The scanner
    |--------------------------------------------------------------------------
    */

    /**
     * Every unbounded listing query in one controller's source.
     *
     * @return list<array{action: string, line: int, model: string, chain: string}>
     */
    private function scan(string $relative, string $source): array
    {
        $models = $this->importedModels($source);
        $allowed = $this->smallReferenceTables();
        $findings = [];

        foreach ($this->listingActions($source) as $action) {
            $body = $action['body'];

            if (! preg_match_all('/::\s*all\s*\(|->\s*get\s*\(|->\s*cursor\s*\(/', $body, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $chain = $this->chainBefore($body, (int) $match[1]);

                if (! preg_match('/^[\\\\]?([A-Z][A-Za-z0-9_]*)\s*::/', $chain, $head)) {
                    continue;
                }

                $class = $models[$head[1]] ?? null;

                if ($class === null || $this->boundedBy($chain, $class, $allowed)) {
                    continue;
                }

                $findings[] = [
                    'action' => $action['name'],
                    'line' => $action['lines'][(int) $match[1]] ?? 0,
                    'model' => $head[1],
                    'chain' => (string) preg_replace('/\s+/', ' ', $chain),
                ];
            }
        }

        return $findings;
    }

    /**
     * Whether something in the chain caps how many rows come back.
     *
     * Three ways, and only three:
     *
     * 1. **An explicit `->limit()` / `->take()`.** The number is written down where a reader can see
     *    it, which is the whole difference between "the top twenty" and "however many there are".
     * 2. **A small reference table.** Its row count is bounded by the business (see the header of
     *    `tests/Support/small-reference-tables.php`), so loading it whole is loading a known quantity.
     * 3. **A restriction to the model's own keys.** `whereIn('id', $page->pluck(...))` is bounded by
     *    whatever produced those keys — almost always the paginator one line above — so the query
     *    inherits that bound. A `whereIn` on a *foreign* key is not: one course can have ten thousand
     *    enrolments, and "the enrolments of the courses on this page" is not a bounded number.
     *
     * Deliberately absent: an owner `where('student_id', …)` and a date window. Both feel bounded and
     * neither is. One student accumulates results for years, and a "calendar" whose range comes from
     * the query string can be asked for a decade.
     *
     * @param  array<class-string, string>  $allowed
     */
    private function boundedBy(string $chain, string $class, array $allowed): bool
    {
        if (preg_match('/->\s*(limit|take)\s*\(/', $chain) === 1) {
            return true;
        }

        if (array_key_exists($class, $allowed)) {
            return true;
        }

        return preg_match('/->\s*(whereKey|findMany|whereIntegerInRaw)\s*\(/', $chain) === 1
            || preg_match('/->\s*whereIn\s*\(\s*[\'"]id[\'"]/', $chain) === 1;
    }

    /**
     * The expression a `->get()` was called on, read backwards from it.
     *
     * Walking backwards with a bracket counter is what makes `'courses' => Course::query()->get(),
     * 'batches' => Batch::query()->get()` two findings about two models rather than two findings
     * about whichever class happened to appear first in the statement.
     */
    private function chainBefore(string $body, int $offset): string
    {
        $depth = 0;
        $position = $offset - 1;

        while ($position >= 0) {
            $character = $body[$position];

            if ($character === ')' || $character === ']') {
                $depth++;
            } elseif ($character === '(' || $character === '[') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($depth === 0) {
                if (str_contains(',;{}=&|?!+*/%<', $character)) {
                    break;
                }

                // `=>` ends the previous array element; `::` does not end anything.
                if ($character === '>' && $position > 0 && $body[$position - 1] === '=') {
                    break;
                }

                if ($character === ':' && ($position === 0 || $body[$position - 1] !== ':')
                    && ($position + 1 >= strlen($body) || $body[$position + 1] !== ':')) {
                    break;
                }
            }

            $position--;
        }

        return trim(substr($body, $position + 1, $offset - $position - 1));
    }

    /**
     * Every listing action in a file, with its body and a character-to-line map for the body.
     *
     * Tokenised rather than matched with a regex: a brace inside a string or a heredoc would end the
     * method body early, and the query hiding after it would never be looked at.
     *
     * @return list<array{name: string, body: string, lines: array<int, int>}>
     */
    private function listingActions(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $actions = [];

        for ($index = 0; $index < $count; $index++) {
            if (! is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
                continue;
            }

            $next = $index + 1;

            while ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }

            if ($next >= $count || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
                continue;
            }

            $name = $tokens[$next][1];

            if (preg_match(self::LISTING_ACTIONS, $name) !== 1) {
                continue;
            }

            [$start, $end] = $this->bodyBounds($tokens, $next, $count);

            if ($start === null || $end === null) {
                continue;
            }

            $body = '';
            $lines = [];
            $line = 0;

            for ($position = $start; $position <= $end; $position++) {
                $token = $tokens[$position];
                $text = is_array($token) ? $token[1] : $token;
                $line = is_array($token) ? $token[2] : $line;

                for ($character = 0, $length = strlen($text); $character < $length; $character++) {
                    $lines[] = $line;
                }

                $body .= $text;
            }

            $actions[] = ['name' => $name, 'body' => $body, 'lines' => $lines];
        }

        return $actions;
    }

    /**
     * The token positions of a method's opening and closing brace.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int|null, 1: int|null}
     */
    private function bodyBounds(array $tokens, int $from, int $count): array
    {
        $depth = 0;
        $start = null;

        for ($position = $from; $position < $count; $position++) {
            $token = $tokens[$position];

            if (is_array($token)) {
                continue;
            }

            if ($token === '{') {
                $depth++;

                if ($start === null) {
                    $start = $position;
                }
            } elseif ($token === '}') {
                $depth--;

                if ($start !== null && $depth === 0) {
                    return [$start, $position];
                }
            } elseif ($token === ';' && $start === null) {
                // An abstract or interface method: no body to read.
                return [null, null];
            }
        }

        return [null, null];
    }

    /**
     * Short class name => fully qualified model, from the file's own `use` statements.
     *
     * @return array<string, class-string>
     */
    private function importedModels(string $source): array
    {
        preg_match_all(
            '/^use\s+(App\\\\Models\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $models = [];

        foreach ($matches as $match) {
            $alias = $match[2] ?? '';
            $short = $alias !== '' ? $alias : (string) preg_replace('/^.*\\\\/', '', $match[1]);

            /** @var class-string $class */
            $class = $match[1];
            $models[$short] = $class;
        }

        return $models;
    }

    /**
     * @return array<class-string, string>
     */
    private function smallReferenceTables(): array
    {
        $path = base_path('tests/Support/small-reference-tables.php');

        $this->assertFileExists(
            $path,
            'tests/Support/small-reference-tables.php is missing; without it every reference lookup '.
            'reads as an unbounded listing and PRF-05 is noise.',
        );

        /** @var array<class-string, string> $rows */
        $rows = require $path;

        return $rows;
    }

    /**
     * Relative path => absolute path, for every controller.
     *
     * @return array<string, string>
     */
    private function controllerFiles(): array
    {
        $root = app_path('Http/Controllers');
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }
}
