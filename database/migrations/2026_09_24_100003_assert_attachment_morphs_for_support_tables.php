<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 23 · file 3 — an assertion, not a change (phase-19-23 §2.27, §96, [D-22-3]).
 *
 * **This migration alters nothing.** It asserts that `attachments` exists and carries its
 * `visibility` column, and fails loudly with an instruction if either is missing.
 *
 * The reason it exists at all: §96 makes tickets, replies, messages and meetings four more owners
 * of the one `attachments` table, and client visibility on all of them is `attachments.visibility`
 * — never a boolean on some other table (F-2.6). Both of those are decisions that live in *other*
 * phases' migrations, and a later phase quietly re-adding `is_client_visible` somewhere would not
 * break anything until the day a client saw a file they should not have.
 *
 * **A migration is the right place for it, and a test is not enough.** A test runs when somebody
 * runs the suite; a migration runs on every deployment, including the one where a hotfix reordered
 * things. This is the cheapest possible check — two `information_schema` reads — placed where it
 * cannot be skipped.
 *
 * It is deliberately guarded on `hasTable` at the top for the one legitimate case: an installation
 * migrating from empty, where Phase 6's `attachments` has not been created yet because this file
 * sorted first. That cannot happen with the current dates, and the guard costs nothing if it never
 * fires.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attachments')) {
            throw new RuntimeException(
                'The `attachments` table is missing. Phase 6 owns it, and §96 makes tickets, replies, '
                .'messages and meetings four more owners of it — there is no second file table and none '
                .'should be created. Run Phase 6\'s migrations before this one.'
            );
        }

        if (! Schema::hasColumn('attachments', 'visibility')) {
            throw new RuntimeException(
                'The `attachments` table has no `visibility` column. Client visibility is that column '
                .'(enum AttachmentVisibility: internal / team / client) and never a boolean on another '
                .'table — audit F-2.6, [D-22-3]. Restore it before running this migration; adding an '
                .'`is_client_visible` flag somewhere else would give the system two answers to the same '
                .'question, and only one of them would be checked.'
            );
        }

        // Nothing structural. The assertion IS the migration.
    }

    public function down(): void
    {
        // Nothing to undo: this migration never changed anything. Stated rather than left empty,
        // so the next reader does not go looking for the missing half.
    }
};
