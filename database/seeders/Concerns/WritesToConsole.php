<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Console\Command;

/**
 * Console output for seeders, null-safe.
 *
 * `Illuminate\Database\Seeder::$command` is only set when the seeder is run through
 * `db:seed` / `migrate --seed`; it is null when a seeder is called from a test or from
 * application code. Every helper here degrades to a no-op in that case, so a seeder can
 * always report what it did without guarding each call site.
 */
trait WritesToConsole
{
    protected function seedLine(string $message): void
    {
        $this->console()?->line($message);
    }

    protected function seedInfo(string $message): void
    {
        $this->console()?->info($message);
    }

    protected function seedComment(string $message): void
    {
        $this->console()?->comment($message);
    }

    protected function seedWarning(string $message): void
    {
        $this->console()?->warn($message);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|null>>  $rows
     */
    protected function seedTable(array $headers, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->console()?->table($headers, $rows);
    }

    /**
     * The console command driving this seeder, when there is one.
     */
    private function console(): ?Command
    {
        $command = $this->command ?? null;

        return $command instanceof Command ? $command : null;
    }
}
