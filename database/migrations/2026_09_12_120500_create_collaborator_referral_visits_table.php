<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 · §2.4 — `collaborator_referral_visits`: the evidence that a referral URL was used (§38).
 *
 * Two jobs. It is the **click record** a partner's own report reads, and it is the **carrier** that lets
 * an attribution survive a multi-step admission flow.
 *
 * **`visit_token` is the only referral value that ever travels through the browser** (INV-R2). The
 * cookie holds it, the hidden form field holds it, and the server re-resolves the code from this table.
 * A forged token can therefore at worst name a visit that does not exist — it can never name a
 * collaborator the visitor never came through.
 *
 * `referral_code` is kept **even when it did not resolve**, so "people are still using a dead code" is
 * reportable rather than invisible.
 *
 * `converted_subject_id` deliberately carries **no foreign key**: the seven possible subjects live in
 * five different phases' tables. The referentially sound link is `converted_referral_id`, or the
 * subject's own `referral_visit_id`.
 *
 * Not a financial table: it is pruned by retention (INV-R6 excepted) and has no delete trigger. No
 * `deleted_at` — an append-only evidence log under `CLAUDE.md` §3's category rule (**D19**).
 */
return new class extends Migration
{
    private const TABLE = 'collaborator_referral_visits';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->char('visit_token', 26);

            // restrictOnDelete: a visit is evidence. Null when the code did not resolve to anybody.
            $table->foreignId('collaborator_id')->nullable()->constrained('collaborators')->restrictOnDelete();
            $table->string('referral_code', 32);

            $table->string('outcome', 32)->default('captured');
            $table->string('outcome_detail', 191)->nullable();

            $table->string('landing_url', 255);
            $table->string('landing_path', 191);
            $table->string('query_string', 255)->nullable();
            $table->string('referer_url', 255)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 64)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->boolean('is_bot')->default(false);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_id', 255)->nullable();

            // One row per visitor-code pair, not per page view.
            $table->unsignedInteger('visits_count')->default(1);

            // DATETIME rather than TIMESTAMP throughout (D67): these are clock columns, and on this
            // server the first TIMESTAMP NOT NULL column silently gains ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('expires_at');
            $table->dateTime('converted_at')->nullable();

            $table->string('converted_subject_type', 24)->nullable();
            $table->unsignedBigInteger('converted_subject_id')->nullable();
            // The FK onto the spine's collaborator_referrals is promoted by its own guarded migration
            // once the spine's table exists (§1.4); the column ships now so nothing has to be widened.
            $table->unsignedBigInteger('converted_referral_id')->nullable();

            // No blameable: the writer is an anonymous web request.
            $table->timestamps();

            // §2.4 Keys. The token is the cookie's whole value, so a collision would hand one visitor
            // another's attribution.
            $table->unique('visit_token', 'uq_crv_token');
            $table->index(['collaborator_id', 'first_seen_at']);
            $table->index(['referral_code', 'first_seen_at']);
            $table->index(['outcome', 'first_seen_at']);
            $table->index('expires_at');
            $table->index('converted_referral_id');
            $table->index(['landing_path', 'first_seen_at']);
            $table->index(['ip_address', 'first_seen_at']);
            $table->index('user_id');
        });

        // Two CHECKs the schema builder cannot express. A visit that ended before it began, or that was
        // counted zero times, is a bug in the writer and should never reach the table.
        DB::statement(
            'ALTER TABLE `collaborator_referral_visits` ADD CONSTRAINT `chk_crv_dates` '
            .'CHECK (`last_seen_at` >= `first_seen_at` AND `expires_at` >= `first_seen_at`)'
        );

        DB::statement(
            'ALTER TABLE `collaborator_referral_visits` ADD CONSTRAINT `chk_crv_visits` '
            .'CHECK (`visits_count` >= 1)'
        );

        $this->assertConstraints(['chk_crv_dates', 'chk_crv_visits']);
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * A CHECK the server quietly ignored is worse than none: the code would trust a guarantee that is
     * not there. Verified after creation, exactly as Phase 7's step-18 migration does.
     *
     * @param  list<string>  $names
     */
    private function assertConstraints(array $names): void
    {
        foreach ($names as $name) {
            $exists = DB::table('information_schema.CHECK_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('CONSTRAINT_NAME', $name)
                ->exists();

            if (! $exists) {
                throw new RuntimeException(sprintf(
                    'phase-09 §2.4: the CHECK constraint %s was not kept by this server. The table '
                    .'would be trusting a guarantee it does not have.',
                    $name
                ));
            }
        }
    }
};
