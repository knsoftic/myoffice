<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.1 — leads: the sales pipeline of requirement §18.
 *
 * `lead_no` (`crm.lead_number_prefix` + a counter only `DocumentNumberService` advances, D27 / D62) is UNIQUE
 * across soft-deleted rows and immutable (model `updating` hook). `budget_amount` is the only money column
 * (`decimal(15,2)`); the Kanban value sums are `SUM(budget_amount)` and CHECK `chk_leads_budget` keeps it
 * non-negative. The three `*_normalized` columns are plain indexed columns filled in PHP by `ContactNormalizer`
 * ([D-P5-5] / D29): duplicates are **warned, never blocked** — there is deliberately no unique index on a phone
 * or an email.
 *
 * Idempotency of the website hand-off (F-3.7, R7): `UNIQUE uq_leads_inquiry(contact_inquiry_id)` — the INSERT
 * is the guard and a 1062 means "already imported"; MariaDB ignores NULLs, so manual leads stack freely.
 *
 * Deferred links ([D-P5-2]) — created bare and indexed here, constrained by `add_crm_deferred_foreign_keys`:
 * `service_id` → services, `contact_inquiry_id` → contact_inquiries, `lead_import_id` → lead_imports (created
 * after this table), and `referral_visit_id` → collaborator_referral_visits (Phase 9 owns that promotion too).
 * `client_id` and `duplicate_of_lead_id` are real keys here: both targets exist.
 *
 * **`chk_leads_not_self_duplicate` is a trigger pair, not a CHECK.** The contract writes it as
 * `CHECK (duplicate_of_lead_id IS NULL OR duplicate_of_lead_id <> id)`, but MariaDB 10.4 refuses any CHECK that
 * names an AUTO_INCREMENT column (error 1901, "Function or expression 'AUTO_INCREMENT' cannot be used in the
 * CHECK clause of `id`" — probed on 10.4.32). The same rule is enforced with `BEFORE INSERT` / `BEFORE UPDATE`
 * triggers that SIGNAL SQLSTATE '45000' carrying the contract's constraint name (D17: the guarantee stays in the
 * database). `LeadService::linkDuplicate()` refuses a self-link and a cycle before the database is asked.
 *
 * There is **no `collaborator_id` column** (§11 test 55, D37): the captured code is a snapshot, attribution
 * lives in the spine's `collaborator_referrals`.
 *
 * Mutable business table → timestamps + softDeletes + blameable (CLAUDE.md §3, D19).
 */
return new class extends Migration
{
    private const TABLE = 'leads';

    private const TRIGGER_INSERT = 'trg_leads_not_self_duplicate_insert';

    private const TRIGGER_UPDATE = 'trg_leads_not_self_duplicate_update';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('lead_no', 32);
                $table->string('name', 150);
                $table->string('company', 150)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('email_normalized', 150)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('phone_normalized', 32)->nullable();
                $table->string('whatsapp', 32)->nullable();
                $table->string('whatsapp_normalized', 32)->nullable();
                $table->string('country', 64)->nullable();
                $table->char('country_code', 2)->nullable();
                // [D-P5-2] deferred link → services.id (nullOnDelete).
                $table->unsignedBigInteger('service_id')->nullable();
                $table->string('interested_service', 150)->nullable();
                $table->decimal('budget_amount', 15, 2)->nullable();
                $table->string('source', 32);
                $table->string('source_detail', 255)->nullable();
                $table->string('status', 32)->default('new');
                $table->timestamp('status_changed_at')->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('follow_up_at')->nullable();
                $table->timestamp('last_contacted_at')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->text('notes')->nullable();
                $table->string('lost_reason', 255)->nullable();
                $table->timestamp('lost_at')->nullable();
                $table->timestamp('won_at')->nullable();
                $table->foreignId('duplicate_of_lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->timestamp('duplicate_flagged_at')->nullable();
                $table->string('duplicate_note', 255)->nullable();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->timestamp('converted_at')->nullable();
                $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('referral_code_captured', 32)->nullable();
                $table->timestamp('referral_recorded_at')->nullable();
                // [D-P5-2] / F-3.5 deferred link → collaborator_referral_visits.id (nullOnDelete, Phase 9).
                $table->unsignedBigInteger('referral_visit_id')->nullable();
                // [D-P5-2] deferred link → contact_inquiries.id (nullOnDelete, Phase 4).
                $table->unsignedBigInteger('contact_inquiry_id')->nullable();
                // [D-P5-2] deferred link → lead_imports.id (nullOnDelete), created after this table.
                $table->unsignedBigInteger('lead_import_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.1 Keys.
                $table->unique('lead_no', 'uq_leads_no');
                $table->unique('contact_inquiry_id', 'uq_leads_inquiry');
                $table->index(['status', 'follow_up_at', 'id'], 'idx_leads_board');
                $table->index(['assigned_to', 'status'], 'idx_leads_mine');
                $table->index(['created_by', 'status']);
                $table->index('follow_up_at');
                $table->index('last_activity_at');
                $table->index(['source', 'created_at']);
                $table->index('email_normalized');
                $table->index('phone_normalized');
                $table->index('whatsapp_normalized');
                $table->index('client_id');
                $table->index('referral_code_captured');
                $table->index('referral_visit_id');
                $table->index('deleted_at');
                // Every foreign-key column is indexed (F-9.2): the two deferred ones get theirs here so the
                // promoter adds only a constraint.
                $table->index('service_id');
                $table->index('lead_import_id');
            });
        }

        $this->addCheck(self::TABLE, 'chk_leads_budget', '`budget_amount` is null or `budget_amount` >= 0');
        $this->addSelfDuplicateTriggers();
    }

    public function down(): void
    {
        // Inbound keys — clients.lead_id (deferred migration), lead_activities, lead_follow_ups,
        // lead_conversions and lead_import_rows — are dropped by later Phase 5 rollbacks first.
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::TRIGGER_INSERT.'`');
        DB::unprepared('DROP TRIGGER IF EXISTS `'.self::TRIGGER_UPDATE.'`');

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * §2.1 `chk_leads_not_self_duplicate`, as triggers (see the class docblock for why it cannot be a CHECK).
     *
     * On INSERT the auto-increment id is still 0 unless the caller supplied one, so only an explicit id can
     * collide; on UPDATE the id is known.
     */
    private function addSelfDuplicateTriggers(): void
    {
        $body = static fn (string $idCondition): string => 'BEGIN '
            .'IF NEW.`duplicate_of_lead_id` IS NOT NULL AND '.$idCondition.' NEW.`duplicate_of_lead_id` = NEW.`id` THEN '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'chk_leads_not_self_duplicate: a lead cannot be a duplicate of itself'; "
            .'END IF; '
            .'END';

        if (! $this->hasTrigger(self::TRIGGER_INSERT)) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER `%s` BEFORE INSERT ON `%s` FOR EACH ROW %s',
                self::TRIGGER_INSERT,
                self::TABLE,
                $body('NEW.`id` <> 0 AND')
            ));
        }

        if (! $this->hasTrigger(self::TRIGGER_UPDATE)) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER `%s` BEFORE UPDATE ON `%s` FOR EACH ROW %s',
                self::TRIGGER_UPDATE,
                self::TABLE,
                $body('')
            ));
        }
    }

    private function hasTrigger(string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $name]
        ) !== [];
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it (idempotent re-run).
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)', $table, $name, $expression));
    }

    private function hasCheck(string $table, string $name): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        ) !== [];
    }
};
