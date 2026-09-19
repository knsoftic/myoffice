<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 · §2.7 — clients: the client master of requirement §19.
 *
 * "Client ID" is the human-readable `client_code` (`crm.client_code_prefix` + a counter advanced only by
 * `DocumentNumberService`, D27 / D62); the surrogate `id` is never shown. `UNIQUE uq_clients_code` spans
 * soft-deleted rows, so a code is never re-issued (§11 test 6), and the model's `updating` hook makes it
 * immutable. `UNIQUE uq_clients_user` binds one portal login to one client (D2).
 *
 * Links:
 *   · `user_id`, `account_manager_id` — real foreign keys to `users` (`nullOnDelete`).
 *   · `lead_id` — the originating lead. `leads` is created **after** this table (§2 migration order), so the
 *     column is created bare and indexed here and the constraint is added by
 *     `add_crm_deferred_foreign_keys` ([D-P5-2]). Deliberately **not** unique: repeat business converts
 *     several leads onto one client.
 *   · `referral_code_captured` + `referral_recorded_at` — the `?ref=` code as it arrived ([D-P5-6]). There is
 *     **no `collaborator_id` column** (§11 test 55, D37): attribution lives in the spine's
 *     `collaborator_referrals`, reached only through `ReferralRecorder`.
 *
 * Tax percentages are `decimal(8,4)` (CLAUDE.md §3) and bounded 0-100 by two named CHECK constraints (§11 test 56).
 * No money column lives here: invoiced / paid / outstanding come from the owning phases' read models (§6.7).
 *
 * Mutable profile table → timestamps + softDeletes + blameable (CLAUDE.md §3, D19).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            Schema::create('clients', function (Blueprint $table): void {
                $table->id();
                $table->string('client_code', 32);
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('client_type', 16)->default('company');
                $table->string('name', 150);
                $table->string('company_name', 150)->nullable();
                $table->string('email', 150)->nullable();
                $table->string('email_normalized', 150)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('phone_normalized', 32)->nullable();
                $table->string('whatsapp', 32)->nullable();
                $table->string('whatsapp_normalized', 32)->nullable();
                $table->string('website', 255)->nullable();
                $table->string('industry', 96)->nullable();
                $table->text('about')->nullable();
                $table->string('logo_path', 255)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('city', 96)->nullable();
                $table->string('state', 96)->nullable();
                $table->string('postal_code', 24)->nullable();
                $table->string('country', 64)->nullable();
                $table->char('country_code', 2)->nullable();
                $table->boolean('billing_same_as_address')->default(true);
                $table->string('billing_address', 255)->nullable();
                $table->boolean('tax_registered')->default(false);
                $table->string('tax_number', 64)->nullable();
                $table->string('sales_tax_number', 64)->nullable();
                $table->string('cnic', 24)->nullable();
                $table->boolean('tax_exempt')->default(false);
                $table->decimal('tax_rate_override', 8, 4)->nullable();
                $table->decimal('withholding_tax_rate', 8, 4)->nullable();
                $table->string('tax_notes', 255)->nullable();
                $table->char('currency', 3)->nullable();
                $table->unsignedSmallInteger('payment_terms_days')->nullable();
                $table->string('status', 32)->default('active');
                $table->string('status_reason', 255)->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->boolean('portal_enabled')->default(false);
                $table->timestamp('portal_invited_at')->nullable();
                $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source', 32)->nullable();
                // [D-P5-2] deferred link → leads.id (nullOnDelete), added by add_crm_deferred_foreign_keys.
                $table->unsignedBigInteger('lead_id')->nullable();
                $table->string('referral_code_captured', 32)->nullable();
                $table->timestamp('referral_recorded_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.7 Keys. Both UNIQUE indexes are plain (never with deleted_at): a code or a login is never
                // re-used by a second row, trashed or not.
                $table->unique('client_code', 'uq_clients_code');
                $table->unique('user_id', 'uq_clients_user');
                $table->index(['status', 'company_name']);
                $table->index('account_manager_id');
                $table->index('email_normalized');
                $table->index('phone_normalized');
                $table->index('whatsapp_normalized');
                $table->index('lead_id');
                $table->index('referral_code_captured');
                $table->index('deleted_at');
            });
        }

        // §2.7 CHECK constraints — percentage convention, 0-100 inclusive (§11 test 56).
        $this->addCheck(
            'clients',
            'chk_clients_tax_rate',
            '`tax_rate_override` is null or (`tax_rate_override` >= 0 and `tax_rate_override` <= 100)'
        );
        $this->addCheck(
            'clients',
            'chk_clients_withholding_rate',
            '`withholding_tax_rate` is null or (`withholding_tax_rate` >= 0 and `withholding_tax_rate` <= 100)'
        );
    }

    public function down(): void
    {
        // Last to roll back among the nine CRM tables (first created). Every inbound key — client_contacts,
        // client_documents, leads.client_id, lead_conversions.client_id and the deferred testimonials /
        // portfolio_items constraints — belongs to a later Phase 5 migration and is dropped by its rollback
        // first. DROP TABLE removes the four foreign keys and both CHECK constraints this table owns.
        Schema::dropIfExists('clients');
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
