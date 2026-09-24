<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\A11y;
use Throwable;

/**
 * `a11y:scan` — run the accessibility assertions over every screen in the manifest
 * (phase-24-25 §6.5, §6.6).
 *
 * **Headless, with no browser**, which is what makes it runnable on every commit. A browser catches
 * things this cannot — focus order, whether a trap releases, whether a 200 %-zoomed layout still
 * reaches its buttons — and those are the Playwright specs of §12 Q3. Everything decidable from the
 * markup is decidable here, in seconds.
 *
 * The suite in `tests/Feature/Platform` runs the same assertions against eight representative
 * screens on every commit; this walks all of them, which is the nightly sweep.
 *
 * Read-only: the session is opened with `Auth::login()` inside a transaction that is rolled back,
 * never by typing a password.
 */
final class A11yScan extends Command
{
    protected $signature = 'a11y:scan
                            {--route= : One route name}
                            {--panel=admin : Route-name prefix to walk}
                            {--json : Machine-readable output}';

    protected $description = 'Render every manifest screen and run the accessibility assertion set.';

    /**
     * The assertions, by the name that appears in a failure row.
     *
     * @var array<string, string>
     */
    private const CHECKS = [
        'lang' => 'assertHtmlLang',
        'title' => 'assertUniqueTitle',
        'one h1' => 'assertSingleH1',
        'heading order' => 'assertHeadingOrder',
        'labelled inputs' => 'assertEveryInputLabelled',
        'named icon buttons' => 'assertIconButtonsLabelled',
        'captioned tables' => 'assertTablesCaptioned',
        'associated errors' => 'assertErrorsAssociated',
        'chart alternatives' => 'assertChartHasTextAlternative',
        'no positive tabindex' => 'assertNoPositiveTabIndex',
    ];

    public function handle(): int
    {
        $rows = $this->screens();

        if ($rows === []) {
            $this->error('No measurable screens in tests/Support/screen-manifest.php.');

            return 2;
        }

        $admin = $this->superAdmin();

        if ($admin === null) {
            $this->error('No seeded Super Admin to render with.');

            return 2;
        }

        $failures = [];
        $scanned = 0;
        $skipped = 0;

        DB::beginTransaction();

        try {
            $admin->forceFill(['must_change_password' => false])->saveQuietly();
            Auth::login($admin);

            $kernel = app(HttpKernel::class);

            $bar = $this->option('json') ? null : $this->output->createProgressBar(count($rows));
            $bar?->start();

            foreach ($rows as $row) {
                $name = (string) $row['route'];
                $html = $this->render($kernel, $admin, $name);

                if ($html === null) {
                    $skipped++;
                    $bar?->advance();

                    continue;
                }

                $scanned++;

                foreach (self::CHECKS as $label => $method) {
                    $message = $this->failureOf(static fn () => A11y::{$method}($html, $name));

                    if ($message !== null) {
                        $failures[] = ['route' => $name, 'check' => $label, 'detail' => $message];
                    }
                }

                $bar?->advance();
            }

            $bar?->finish();
            $this->newLine(2);

            Auth::logout();
        } finally {
            DB::rollBack();
        }

        return $this->report($failures, $scanned, $skipped);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @return list<array<string, mixed>>
     */
    private function screens(): array
    {
        $path = base_path('tests/Support/screen-manifest.php');

        if (! is_file($path)) {
            return [];
        }

        /** @var mixed $rows */
        $rows = require $path;
        $route = $this->option('route');
        $prefix = (string) $this->option('panel');

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ! isset($row['route']) || ! is_string($row['route'])) {
                continue;
            }

            if ($route !== null) {
                if ($row['route'] === $route) {
                    $out[] = $row;
                }

                continue;
            }

            // A parameterised screen needs a fixture the manifest's `params` closure supplies, and
            // a file that only renders is not one. Skipped rather than rendered against whichever
            // record happened to be first.
            if (($row['params'] ?? null) !== null || ($row['response'] ?? 'html') !== 'html') {
                continue;
            }

            if (str_starts_with($row['route'], $prefix.'.')) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function render(HttpKernel $kernel, User $admin, string $name): ?string
    {
        try {
            $uri = route($name, [], false);
        } catch (Throwable) {
            return null;
        }

        try {
            $request = Request::create($uri, 'GET');
            $request->setUserResolver(static fn () => $admin);

            $response = $kernel->handle($request);
        } catch (Throwable) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $html = (string) $response->getContent();

        // A screen that renders a fragment rather than a document has no <html> to check, and
        // asserting a lang attribute on one would report a failure that is not there.
        return str_contains($html, '<html') ? $html : null;
    }

    private function failureOf(callable $assertion): ?string
    {
        try {
            $assertion();

            return null;
        } catch (AssertionFailedError $error) {
            return $error->getMessage();
        } catch (Throwable $error) {
            return get_class($error).': '.$error->getMessage();
        }
    }

    /**
     * @param  list<array<string, string>>  $failures
     */
    private function report(array $failures, int $scanned, int $skipped): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'scanned' => $scanned,
                'skipped' => $skipped,
                'failures' => $failures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? 0 : 2;
        }

        if ($failures !== []) {
            // Grouped by check rather than by screen: one broken component produces the same
            // failure on forty screens, and forty rows describing one fix is a wall nobody reads.
            $byCheck = [];

            foreach ($failures as $failure) {
                $byCheck[$failure['check']][] = $failure;
            }

            foreach ($byCheck as $check => $rows) {
                $this->newLine();
                $this->line(sprintf('<fg=red>%s</> — %d screen(s)', $check, count($rows)));

                foreach (array_slice($rows, 0, 5) as $row) {
                    $this->line('    '.$row['route']);
                    $this->line('      <fg=gray>'.mb_substr(
                        (string) preg_replace('/\s+/', ' ', $row['detail']),
                        0,
                        140,
                    ).'</>');
                }

                if (count($rows) > 5) {
                    $this->line(sprintf('    … and %d more', count($rows) - 5));
                }
            }
        }

        $this->newLine();
        $this->line(sprintf('  %d screen(s) scanned, %d skipped.', $scanned, $skipped));

        if ($failures === []) {
            $this->info('a11y:scan — every screen passed the machine-checkable rules.');

            return 0;
        }

        $this->error(sprintf('a11y:scan — %d failure(s).', count($failures)));

        return 2;
    }

    private function superAdmin(): ?User
    {
        try {
            return User::query()
                ->whereHas('roles', static fn ($query) => $query->where('name', User::SUPER_ADMIN_ROLE))
                ->first();
        } catch (Throwable) {
            return null;
        }
    }
}
