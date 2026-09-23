<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 1 — `ticket_departments` (phase-19-23 §2.17, requirement §93).
 *
 * **§93's "department" is a row, not a setting**, and §4.1 gives it its own module so a support lead
 * maintains the queues and their SLAs without holding `settings.edit`. That is the whole reason this
 * table exists rather than a `support.departments` json blob: a department is referenced by every
 * ticket in it, carries its own targets, and has to survive being renamed.
 *
 * **`allowed_panels` is what stops a student filing into a client-billing queue.** It is checked by
 * `TicketService::create()` against the requester's own panel *before* anything else, because the
 * alternative — letting the ticket in and hiding it later — puts a student's question in a queue
 * whose agents have no reason to read it.
 *
 * **`default_guard` is a generated STORED column** for the MariaDB reason every guard in this system
 * exists: a unique index tolerates unlimited NULLs, so `CASE WHEN is_default THEN 1 ELSE NULL` lets
 * `uq_td_default` permit exactly one default while every other row coexists.
 *
 * **Every enum-backed column is string(32), not the contract's 16.** `TicketAssignStrategy`'s longest
 * case is `default_assignee` at sixteen — it fits, *exactly*, which is the most dangerous width there
 * is. D126 is the phase where `exams.status` was varchar(16) with a seventeen-character case in it
 * and the publish step was unreachable until somebody probed the whole ladder. CLAUDE.md §3 says 32
 * precisely so nobody has to count.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures everything
 * else on every run.
 */
return new class extends Migration
{
    private const TABLE = 'ticket_departments';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guard();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('name', 150);
            // The stable key settings and reports hold, so renaming a department to something
            // friendlier does not orphan a configuration row.
            $table->string('slug', 170);
            $table->string('description', 500)->nullable();
            // The inbox a mail-in integration would read. Nothing polls it yet; the column is here
            // because a department without a contact address is one somebody has to invent later.
            $table->string('email', 180)->nullable();

            // Array of PanelType values. See the class note.
            $table->json('allowed_panels');

            $table->unsignedBigInteger('default_assignee_id')->nullable();
            $table->string('auto_assign_strategy', 32)->default('none');

            // Null means "use the institute-wide target" — a department that has not been given its
            // own is not a department with no target.
            $table->unsignedInteger('sla_first_response_minutes')->nullable();
            $table->unsignedInteger('sla_resolution_minutes')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // A cache, recounted under a row lock by `TicketService::recountCaches()`.
            $table->unsignedInteger('open_tickets_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('slug', 'uq_td_slug');
            $table->index(['is_active', 'sort_order'], 'idx_td_active');
            // Its own index rather than leaning on a composite: D125's rule, and this one is a
            // foreign key nothing else indexes.
            $table->index('default_assignee_id', 'idx_td_assignee');

            $table->foreign('default_assignee_id', RawSchema::foreignKeyName(self::TABLE, 'default_assignee_id'))
                ->references('id')->on('users')->nullOnDelete();

            foreach (['created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /** One default department. NULL for the rest is the mechanism — see the class note. */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'default_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'default_guard',
                'tinyint',
                'CASE WHEN `is_default` = 1 THEN 1 ELSE NULL END',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_td_default', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_td_default', ['default_guard']);
        }
    }

    private function constraints(): void
    {
        // A target of zero minutes is not a demanding target, it is a breach on arrival. Null means
        // "inherit"; anything present has to be a real number of minutes.
        $this->ensure(
            'chk_td_sla',
            '(`sla_first_response_minutes` IS NULL OR `sla_first_response_minutes` > 0)'
            .' AND (`sla_resolution_minutes` IS NULL OR `sla_resolution_minutes` > 0)',
        );

        $this->ensure('chk_td_counts', '`open_tickets_count` >= 0');
    }

    public function down(): void
    {
        // The index reads the generated column, so it goes first: dropping a column an index depends
        // on is error 1553, and the rollback test runs this over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_td_default', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_td_default');
            }

            if (Schema::hasColumn(self::TABLE, 'default_guard')) {
                RawSchema::dropColumn(self::TABLE, 'default_guard');
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
