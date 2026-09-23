<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 · file 5 — `meeting_participants` (phase-19-23 §2.21, requirement §95).
 *
 * **A pivot, so no `deleted_at`** — CLAUDE.md §3's mutable-pivot case. Removing somebody from a
 * meeting deletes the row and writes an activity entry with the reason, and the scope is read live
 * rather than snapshotted, so a removed participant loses access immediately. A soft-deleted
 * membership would be a row that still satisfies "is this person a participant?" in any query that
 * forgot the scope, which on a table holding meeting minutes is the wrong way round.
 *
 * **Two uniques, because there are two kinds of identity here.** `uq_mp_user` stops the same account
 * being invited twice; `uq_mp_external` does the same for a guest with no account, keyed on their
 * email. Both are needed: an external guest has a null `user_id`, and MariaDB permits unlimited
 * NULLs in a unique index — so without the second, one external address could be added forty times.
 *
 * **`chk_mp_identity` and `chk_mp_external` are the pair that keeps the two kinds apart.** A row has
 * to be *somebody* — an account or an email — and a row claiming to be external has to have no
 * account and a name. Without the second, `participant_type` would be a label anybody could apply to
 * a row that contradicted it, and §9's isolation reads that label.
 *
 * **`attended` is nullable on purpose, and that is three states rather than two.** Null is "nobody
 * recorded it", which is a different fact from "did not attend" — an attendance rate computed over
 * unrecorded meetings would be a measure of how diligently somebody ticks boxes.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures the rest.
 */
return new class extends Migration
{
    private const TABLE = 'meeting_participants';

    /** The profile behind the user — for display, and for §9's scope. */
    private const PROFILES = [
        'client_id' => 'clients',
        'student_id' => 'students',
        'teacher_id' => 'teachers',
        'collaborator_id' => 'collaborators',
        'employee_id' => 'employees',
    ];

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

            $table->unsignedBigInteger('meeting_id');

            // Null for an external guest. See the class note.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('participant_type', 32);

            foreach (array_keys(self::PROFILES) as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }

            $table->string('external_name', 150)->nullable();
            $table->string('external_email', 180)->nullable();

            $table->string('role', 32);
            $table->string('response', 32)->default('pending');
            $table->timestamp('responded_at')->nullable();

            // Three states: null = not recorded, and that is not the same as false.
            $table->boolean('attended')->nullable();
            $table->timestamp('attendance_marked_at')->nullable();
            $table->unsignedBigInteger('attendance_marked_by')->nullable();

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('notes', 255)->nullable();

            // A pivot. No soft delete — see the class note.
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id'], 'uq_mp_user');
            $table->unique(['meeting_id', 'external_email'], 'uq_mp_external');

            $table->index(['user_id', 'meeting_id'], 'idx_mp_user');
            $table->index('participant_type', 'idx_mp_type');
            $table->index('response', 'idx_mp_response');

            $table->foreign('meeting_id', RawSchema::foreignKeyName(self::TABLE, 'meeting_id'))
                ->references('id')->on('meetings')->cascadeOnDelete();

            foreach (self::PROFILES as $column => $references) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on($references)->nullOnDelete();

                // D125: none of these leads a composite above.
                $table->index($column, 'idx_mp_'.str_replace('_id', '', $column));
            }

            foreach (['user_id', 'attendance_marked_by'] as $column) {
                $table->foreign($column, RawSchema::foreignKeyName(self::TABLE, $column))
                    ->references('id')->on('users')->nullOnDelete();
            }

            $table->index('attendance_marked_by', 'idx_mp_marker');
        });
    }

    private function constraints(): void
    {
        // A participant has to be somebody.
        $this->ensure('chk_mp_identity', '`user_id` IS NOT NULL OR `external_email` IS NOT NULL');

        // And a row claiming to be external has to actually be external: no account, and a name to
        // put in the room. `participant_type` feeds §9's scope, so it must not be a label that
        // contradicts the row carrying it.
        $this->ensure(
            'chk_mp_external',
            "`participant_type` <> 'external' OR (`user_id` IS NULL AND `external_name` IS NOT NULL)",
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
