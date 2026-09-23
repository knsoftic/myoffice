<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 10 — `notifications` (phase-19-23 §2.25, requirement §97).
 *
 * **Laravel's database-channel table, with §97's context columns added in the same migration**
 * rather than bolted on afterwards. Six phases have been writing `class_exists()` guards around
 * notification dispatches waiting for this table; the columns they will need exist from the first
 * row rather than from a second migration.
 *
 * **`event_key` is what makes §97 a feature rather than a list.** Laravel's own table knows only the
 * notification *class*, which is an implementation detail: rename it and every stored row is
 * orphaned, and a preference screen built on class names would offer the user
 * `App\Notifications\Institute\FeeDueReminder`. The registry key is stable, groupable, filterable and
 * preference-able, and it is what `notification_preferences.event_key` joins to.
 *
 * **`module` is here so a disabled module's rows can be hidden without deleting them.** Turning a
 * module off should not destroy the record that somebody was told something while it was on — and a
 * bell that silently dropped rows would make "did anybody see this?" unanswerable.
 *
 * **`archived_at`, because "clear all" must not delete.** §97's clear is an archive: the row leaves
 * the bell and stays on the notifications screen behind a filter. A user who clears a hundred rows
 * to get to an empty state has not asked to lose the one that mattered.
 *
 * **The bell count has to be one index scan**, which is why
 * `(notifiable_type, notifiable_id, read_at, archived_at)` exists beside Laravel's own two-column
 * index. It is read on every page of all five panels; a filesort there is a filesort everywhere.
 *
 * **No soft deletes and no blameable** — this is a log of things that were said to people, and §2.25
 * says so. `actor_id` records who caused it, which is a different question from who wrote the row.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'notifications';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // Laravel's shape, unchanged: a uuid primary key and a morph to the notifiable.
            $table->uuid('id')->primary();
            $table->string('type', 255);
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();

            // ---------------------------------------------------------------- §97's additions
            $table->string('event_key', 64);
            $table->string('module', 64)->nullable();
            $table->string('level', 32)->default('info');
            // Built by the registry's `urlBuilder`, never by a view — a deep link assembled in Blade
            // is one that breaks silently when a route is renamed.
            $table->string('url', 500)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            // Set when the mail channel actually sent, so "we emailed them" is a fact rather than an
            // intention.
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            // The bell. See the class note.
            $table->index(
                ['notifiable_type', 'notifiable_id', 'read_at', 'archived_at'],
                'idx_nt_bell',
            );
            $table->index(['event_key', 'created_at'], 'idx_nt_event');
            $table->index('module', 'idx_nt_module');
            $table->index('actor_id', 'idx_nt_actor');
            $table->index('created_at', 'idx_nt_created');

            $table->foreign('actor_id', RawSchema::foreignKeyName(self::TABLE, 'actor_id'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function constraints(): void
    {
        // A row cannot have been read before it existed, nor archived before it was created. Both
        // would make the §97 figures nonsense in a way nothing on screen would reveal.
        $this->ensure(
            'chk_nt_dates',
            '(`read_at` IS NULL OR `created_at` IS NULL OR `read_at` >= `created_at`)'
            .' AND (`archived_at` IS NULL OR `created_at` IS NULL OR `archived_at` >= `created_at`)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
