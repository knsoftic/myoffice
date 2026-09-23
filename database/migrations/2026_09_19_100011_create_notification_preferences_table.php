<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 11 — `notification_preferences` (phase-19-23 §2.25, requirement §97).
 *
 * **A missing row means "the registry default", and that is the whole design.** No backfill is ever
 * needed when a later phase registers a new event: every user is already on the default for it,
 * because they have no row saying otherwise. The alternative — a row per user per event — would mean
 * a migration on every future phase that adds a notification, over a table that grows as
 * users × events.
 *
 * **`cascadeOnDelete` on `user_id`, unlike almost everywhere else in this phase.** A preference is
 * not a record of anything that happened; it is a setting belonging to an account, and it has no
 * meaning once the account is gone. Contrast `conversation_participants`, which is `restrict`
 * precisely because that row *is* a fact about a conversation.
 *
 * **`mail_enabled` defaults to false.** Somebody who has never opened the preference screen should
 * get a bell and an inbox they did not ask for — the registry raises it per event for the handful
 * that warrant an email, and `support.notifications_mail_enabled` is the master switch above that.
 * A system whose default is "email me everything" is one people silence entirely within a week.
 *
 * **[D-22-2] lives above this table, not in it.** A registry entry may declare `mandatory: true`, and
 * `NotificationService` **ignores a stored row that tries to disable one** — the preference screen
 * renders those locked with an explanation. There is deliberately no column here for it: a mandatory
 * flag stored per user is a flag that can be edited per user, and the point is that it cannot.
 *
 * **There is no permission on this table.** §4.1 says so in as many words: a user always owns their
 * own preferences, so the only scope is `user_id = auth()->id()`.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'notification_preferences';

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

            $table->unsignedBigInteger('user_id');
            // Must exist in `NotificationRegistry`; an unknown key is rejected by the service rather
            // than stored and silently ignored for ever.
            $table->string('event_key', 64);

            $table->boolean('database_enabled')->default(true);
            $table->boolean('mail_enabled')->default(false);
            $table->string('mail_digest', 32)->default('immediate');

            // No soft deletes: resetting to defaults removes the row, which is exactly what "use the
            // default" means here.
            $table->timestamps();

            $table->unique(['user_id', 'event_key'], 'uq_np');
            $table->index('event_key', 'idx_np_event');

            $table->foreign('user_id', RawSchema::foreignKeyName(self::TABLE, 'user_id'))
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function constraints(): void
    {
        // A row with both channels off is the same as `database_enabled = false`, which is a
        // preference the bell honours — so nothing is refused here. What *is* refused is a digest
        // value outside the enum, which the cast would otherwise turn into a null at read time.
        $this->ensure(
            'chk_np_digest',
            "`mail_digest` IN ('immediate','daily','off')",
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
