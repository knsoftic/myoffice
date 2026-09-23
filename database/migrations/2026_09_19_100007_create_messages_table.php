<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 7 — `messages` (phase-19-23 §2.24, requirement §94).
 *
 * **Append-only, like `ticket_replies`**: no edit route, no delete route, and the policy returns
 * false for both for every role. **[D-22-1]** records that a "delete for me" feature was considered
 * and deliberately not built — §94 asks for conversations, messages, attachments, read status and
 * timestamps, and a half-deleted thread makes §112's isolation unprovable: a row one participant
 * cannot see is a row nobody can prove was or was not delivered.
 *
 * **`body` is plain text plus safe links and is always rendered escaped.** It is not rich text and
 * never goes through `RichText` — a message is typed into a chat box by anybody with an account on
 * any of five panels, which is the widest authorship surface in the system, and the cheapest way to
 * be sure none of it becomes markup is for none of it to be allowed to.
 *
 * **`(conversation_id, id)` rather than `(conversation_id, created_at)`.** Two messages sent in the
 * same second must come back in the order they were written, and only the id always says so. It is
 * also the index `last_read_message_id >= id` reads, which is how `reads_count` is computed.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'messages';

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

            $table->unsignedBigInteger('conversation_id');

            // Null for a system entry — "X joined", "thread closed".
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('panel', 32)->nullable();

            $table->longText('body')->nullable();
            $table->unsignedTinyInteger('attachments_count')->default(0);

            $table->boolean('is_system')->default(false);
            $table->string('system_event', 40)->nullable();

            // A cache of the participants whose `last_read_message_id >= id`.
            $table->unsignedSmallInteger('reads_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // The thread page, in write order. See the class note.
            $table->index(['conversation_id', 'id'], 'idx_ms_thread');
            $table->index(['user_id', 'created_at'], 'idx_ms_author');
            $table->index('created_at', 'idx_ms_created');

            // D125: neither leads a composite above.
            $table->index('created_by', 'idx_ms_created_by');
            $table->index('updated_by', 'idx_ms_updated_by');

            $table->foreign('conversation_id', RawSchema::foreignKeyName(self::TABLE, 'conversation_id'))
                ->references('id')->on('conversations')->cascadeOnDelete();

            foreach (['user_id', 'created_by', 'updated_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    private function constraints(): void
    {
        // A message that says nothing and carries nothing is a stray Enter key.
        $this->ensure(
            'chk_ms_payload',
            '`is_system` = 1 OR `body` IS NOT NULL OR `attachments_count` > 0',
        );

        $this->ensure('chk_ms_counts', '`attachments_count` >= 0 AND `reads_count` >= 0');
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
