<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * `demo:seed` — the demo dataset of phase-24-25 section 6.7, and the guard that keeps it off
 * production (section 6.6, section 6.8 step 20).
 *
 * **On `APP_ENV=production` this is a refusal, not a confirmation prompt.** There is no `--force`,
 * no `--i-know-what-i-am-doing` and no typed phrase that gets past it, because the mistake is not
 * recoverable in any way that matters: a week later the demo rows carry real receipt numbers, real
 * audit trails and real dates, and nobody can tell which client is fictional. The person who runs
 * this by accident on the live database is precisely the person who will not be able to answer that
 * question, and the only honest remedy is restoring the backup taken before it. A prompt would be a
 * button that should not exist. {@see DemoSeeder} refuses on the same condition, so `db:seed
 * --class=DemoSeeder` cannot get round this command by going straight to the seeder.
 *
 * **`--fresh` rebuilds the database; it does not delete demo rows, because it cannot.** Half the
 * dataset lands in append-only tables — receipts, reversals, ledger entries, payouts, discounts —
 * each protected by a model `deleting` hook and a `BEFORE DELETE` trigger (D19). A "remove the demo
 * data" flag would therefore be a flag that half works, which is worse than none. So `--fresh` means
 * what it says on a demo box: drop every table, re-run the migrations, re-seed the production
 * reference data, then lay the demo dataset on top. It asks first, and in a non-interactive shell the
 * unanswered question counts as no.
 *
 * **The dataset is written by the real services**, so the pairing in section 6.7 is the actual
 * acceptance test of this command:
 *
 *     php artisan demo:seed
 *     php artisan integrity:verify --suite=all
 *
 * A demo dataset that does not reconcile is a bug in the commission engine, not in the seeder
 * (FIN-19) — which is why this command prints that second line at the end rather than assuming
 * somebody remembers it.
 *
 * Exit codes (the contract a CI job reads):
 *   0  every stage completed
 *   1  refused (production, no `DEMO_PASSWORD`, no Super Admin to attribute rows to, `--fresh`
 *      declined) **or** at least one stage failed — the summary table names it and says why
 */
final class DemoSeed extends Command
{
    protected $signature = 'demo:seed
                            {--fresh : Drop every table, migrate, re-seed the reference data, then seed the demo dataset}';

    protected $description = 'Seed the demo dataset (section 6.7). Refuses outright when APP_ENV=production.';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('demo:seed refuses to run with APP_ENV=production.');
            $this->newLine();
            $this->line(
                'Demo data in a production database is indistinguishable from real data within a week: '
                .'the rows carry real receipt numbers, real audit trails and real dates, and the only '
                .'way back is the backup you took before running this. There is no flag that overrides '
                .'this check. Point APP_ENV at a staging or local environment and run it there.'
            );

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->rebuild()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('Seeding the demo dataset. Every money row goes through its real service, so this is');
        $this->line('slower than an insert loop and that is the point — the dataset is a run of the engine.');
        $this->newLine();

        $seeder = new DemoSeeder;
        $seeder->setContainer($this->getLaravel());
        $seeder->setCommand($this);

        try {
            $seeder->run();
        } catch (Throwable $e) {
            // A refusal from the seeder itself — production, a missing DEMO_PASSWORD, no Super Admin
            // to attribute the rows to. The message is written to be read by whoever ran this, so it
            // is printed as-is rather than summarised.
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = array_keys(array_filter(
            $seeder->report(),
            static fn (array $stage): bool => ! $stage['ok'],
        ));

        $this->newLine();
        $this->line('Now prove it reconciles — section 6.7 pairs the two, and only the pair means anything:');
        $this->line('    php artisan integrity:verify --suite=all');
        $this->newLine();

        if ($failed !== []) {
            $this->error(sprintf(
                'demo:seed — %d stage(s) failed: %s. The rows the other stages wrote are still there and '
                .'still valid; nothing was rolled back behind your back.',
                count($failed),
                implode(', ', $failed),
            ));

            return self::FAILURE;
        }

        $this->info('demo:seed — every stage completed.');

        return self::SUCCESS;
    }

    /**
     * `--fresh`: drop everything and rebuild, once somebody has said yes.
     *
     * `migrate:fresh --seed` runs `DatabaseSeeder`, which is the production reference data (modules,
     * permissions, roles, settings, the default branch, the catalogues). The demo dataset is laid on
     * top of that baseline afterwards and never edits it — D65's converge-additively rule, which is
     * also why `DemoSeeder` is not in that seeder's list.
     *
     * The confirmation defaults to **no**, so a CI job that passes `--fresh` without a TTY stops
     * instead of dropping a database nobody was watching.
     */
    private function rebuild(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');

        $this->newLine();
        $this->warn(sprintf('--fresh will DROP EVERY TABLE in "%s" (connection "%s") and rebuild it.', $database, $connection));
        $this->line('Everything in it is lost: real rows as well as demo rows. There is no undo.');
        $this->newLine();

        if (! $this->confirm(sprintf('Drop and rebuild "%s"?', $database), false)) {
            $this->line('Nothing was dropped. Run without --fresh to add the demo dataset to the database as it stands.');

            return false;
        }

        $this->newLine();
        $this->line('Rebuilding the schema and the production reference data…');

        // --force because `migrate:fresh` prompts on its own otherwise, and the question has already
        // been asked above with the database name in it, which is the more useful question.
        $exit = $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        if ($exit !== self::SUCCESS) {
            $this->error('migrate:fresh failed, so the demo dataset was not seeded. Fix the migration first.');

            return false;
        }

        return true;
    }
}
