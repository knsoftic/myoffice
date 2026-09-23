<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 3 — `ticket_replies` (phase-19-23 §2.19, requirement §93).
 *
 * **Append-only, and enforced in three places because it matters.** There is no edit route and no
 * delete route, the policy returns false for both for every role, and a correction is a new reply.
 * A support thread whose history could be rewritten is not evidence of anything — and "what did we
 * actually promise this client?" is the question a ticket exists to answer six months later.
 * `deleted_at` is here only to honour CLAUDE.md §3's default; nothing writes it.
 *
 * **`visibility` is the dangerous column** (INV-22-3). An `internal_note` is written on the
 * assumption the requester will never see it, so every portal query filters on it, and
 * `TicketService::reply()` **forces** a portal user's own reply to `public` rather than validating
 * it: a field the requester controls must not be able to mint a note only staff were meant to read.
 *
 * **`is_first_response` is a column rather than a derivation**, because the SLA answer has to stay
 * stable. Deriving "the earliest public staff reply" would change retroactively the moment somebody
 * deleted, re-visibilitied or back-dated a row — and the whole point of an SLA record is that it
 * says the same thing next quarter as it did this one. It is set once, by the service (INV-22-2).
 *
 * **`user_id` is nullable because a system entry has no author.** A status change, an assignment and
 * an SLA breach all land in the same timeline as a human reply, which is what makes the timeline
 * readable — the alternative is an activity log nobody opens beside a conversation that skips.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'ticket_replies';

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
            $table->id();

            // `cascadeOnDelete` for completeness; a ticket is never deleted, so it never fires.
            $table->unsignedBigInteger('support_ticket_id');

            // Null for a system entry. See the class note.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('panel', 32)->nullable();

            $table->longText('body')->nullable();
            $table->string('visibility', 32)->default('public');

            $table->boolean('is_first_response')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('system_event', 40)->nullable();
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32)->nullable();

            $table->unsignedTinyInteger('attachments_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // `(ticket, id)` rather than `(ticket, created_at)`: two replies posted in the same
            // second have to come back in the order they were written, and the id is the only thing
            // that always says so.
            $table->index(['support_ticket_id', 'id'], 'idx_tr_ticket');
            $table->index(['user_id', 'created_at'], 'idx_tr_author');
            $table->index('visibility', 'idx_tr_visibility');
            $table->index('is_system', 'idx_tr_system');

            $table->foreign('support_ticket_id', RawSchema::foreignKeyName(self::TABLE, 'support_ticket_id'))
                ->references('id')->on('support_tickets')->cascadeOnDelete();

            foreach (['user_id', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }

            // D125: `created_by` and `updated_by` lead no composite above, so each needs its own.
            $table->index('created_by', 'idx_tr_created_by');
            $table->index('updated_by', 'idx_tr_updated_by');
        });
    }

    private function constraints(): void
    {
        // A reply that says nothing and carries nothing is a row somebody's finger slipped on. A
        // system entry is exempt because its content is its `system_event`.
        $this->ensure(
            'chk_tr_body',
            '`is_system` = 1 OR `body` IS NOT NULL OR `attachments_count` > 0',
        );

        $this->ensure('chk_tr_counts', '`attachments_count` >= 0');
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
