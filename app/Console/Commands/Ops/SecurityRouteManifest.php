<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\Ops\ManifestAuditor;
use App\Support\Ops\ManifestWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `security:route-manifest` — regenerate the route guard manifest from the live routes
 * (phase-24-25 §6.6).
 *
 * **Existing rationales are preserved, and that is the whole reason this is not just a regenerate.**
 * The `rationale` column is the one field a machine may not write (§6.1): it is a human saying why a
 * route is deliberately open, and it exists so that "I forgot the permission" can never look like
 * "this route needs none". Regenerating the file from scratch would discard forty-three sentences
 * somebody wrote after reading forty-three controllers, and the replacement would be a TODO that
 * passes review because the diff is enormous.
 *
 * So the merge is: every route the live router knows becomes a row, each row's guard fields are
 * read fresh from the middleware stack, and each row's rationale is the one already on file.
 *
 * Without `--write` it reports only, which is what `integrity:verify --suite=routes` runs.
 */
final class SecurityRouteManifest extends Command
{
    protected $signature = 'security:route-manifest
                            {--write : Rewrite tests/Support/route-guard-manifest.php}
                            {--json : Machine-readable output}';

    protected $description = 'Regenerate the route guard manifest, keeping every written rationale.';

    public function handle(ManifestAuditor $auditor, ManifestWriter $writer): int
    {
        $path = base_path('tests/Support/route-guard-manifest.php');
        $existing = $this->existingRows($path);

        // Through the public entry point: auditRouteGuards() is private, and reaching past that
        // would mean this command and audit:manifest could drift on what "missing" means.
        $findings = $auditor->audit()['route'] ?? [];

        $missing = $findings['missing'] ?? [];
        $orphaned = $findings['orphaned'] ?? [];
        $warnings = $findings['warnings'] ?? [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'rows' => count($existing),
                'missing' => $missing,
                'orphaned' => $orphaned,
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->summarise(count($existing), $missing, $orphaned, $warnings);
        }

        if (! $this->option('write')) {
            return $missing === [] && $orphaned === [] ? ($warnings === [] ? 0 : 1) : 1;
        }

        return $this->write($path, $writer, $existing, $missing, $orphaned);
    }

    /**
     * Rewrite the file.
     *
     * @param  array<string, array<string, mixed>>  $existing
     * @param  list<string>  $missing
     * @param  list<string>  $orphaned
     */
    private function write(
        string $path,
        ManifestWriter $writer,
        array $existing,
        array $missing,
        array $orphaned,
    ): int {
        if ($missing === [] && $orphaned === []) {
            $this->info('Nothing to write — the manifest already matches the router.');

            return 0;
        }

        $scaffolded = $writer->routeGuardRows($missing);
        $preserved = 0;

        foreach ($scaffolded as $index => $row) {
            $name = (string) $row['route'];

            // A rationale that survived a previous edit of this file is a sentence a person wrote.
            if (isset($existing[$name]['rationale']) && is_string($existing[$name]['rationale'])) {
                $scaffolded[$index]['rationale'] = $existing[$name]['rationale'];
                $preserved++;
            }
        }

        $target = base_path('tests/Support/route-guard-manifest.scaffold.php');

        File::put($target, sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n"
            ."/*\n"
            ." | Scaffolded by `security:route-manifest --write`.\n"
            ." |\n"
            ." | %d new row(s); %d rationale(s) carried over from the existing manifest.\n"
            ." | Every `TODO` below is a sentence a person owes: the rationale column exists so that\n"
            ." | \"I forgot the permission\" can never look like \"this route needs none\".\n"
            ." |\n"
            ." | Review, then merge into route-guard-manifest.php. Nothing is overwritten for you.\n"
            ." */\n\nreturn [\n%s];\n",
            count($scaffolded),
            $preserved,
            $writer->render($scaffolded),
        ));

        $this->newLine();
        $this->info(sprintf(
            'Wrote %d scaffolded row(s) to %s (%d rationale(s) preserved).',
            count($scaffolded),
            $this->relative($target),
            $preserved,
        ));

        if ($orphaned !== []) {
            $this->warn(sprintf(
                '%d row(s) describe routes that no longer exist and were NOT removed — a deleted '
                .'route leaves a row that still passes every sweep: %s',
                count($orphaned),
                implode(', ', array_slice($orphaned, 0, 5)),
            ));
        }

        return 1;
    }

    /**
     * @param  list<string>  $missing
     * @param  list<string>  $orphaned
     * @param  list<string>  $warnings
     */
    private function summarise(int $rows, array $missing, array $orphaned, array $warnings): void
    {
        $this->newLine();
        $this->line(sprintf('  manifest rows      %d', $rows));
        $this->line(sprintf('  routes with no row %d', count($missing)));
        $this->line(sprintf('  rows with no route %d', count($orphaned)));
        $this->line(sprintf('  warnings           %d', count($warnings)));

        foreach (array_slice($missing, 0, 10) as $route) {
            $this->line('    <fg=yellow>missing</>  '.$route);
        }

        foreach (array_slice($orphaned, 0, 10) as $route) {
            $this->line('    <fg=yellow>orphaned</> '.$route);
        }

        $this->newLine();

        if ($missing === [] && $orphaned === []) {
            $this->info('security:route-manifest — the manifest matches the router.');

            return;
        }

        $this->warn('security:route-manifest — the manifest and the router disagree. Run with --write.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function existingRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;
        $keyed = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['route']) && is_string($row['route'])) {
                $keyed[$row['route']] = $row;
            }
        }

        return $keyed;
    }

    private function relative(string $path): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}
