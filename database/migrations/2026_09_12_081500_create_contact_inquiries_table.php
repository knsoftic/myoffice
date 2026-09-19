<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 · §2.20 — contact_inquiries: the public-form record plus its routing state.
 *
 * **Phase 4 owns this table** (F-2.1, resolutions §2.1). Phases 3, 5, 8-9 and 14-17 reference it and may
 * request additive columns only. The row is the permanent record of what the visitor submitted —
 * routing creates an additional record elsewhere and never moves, edits or deletes the inquiry. Spam is
 * stored too (`is_spam`), never routed, never notified.
 *
 * Links, all per §2.1 unless stated:
 *   · `service_id` — a **real** foreign key: `services` exists in this phase (`nullOnDelete`).
 *   · `course_id` — deferred → Phase 14 `courses`. No constraint here; `course_name` is the snapshot.
 *   · `routed_type` + `routed_id` — a lazy, morph-like pointer to the created Lead / CourseInquiry, no
 *     foreign key (the target lives in a later phase). `UNIQUE uq_contact_inquiry_routed_target` means
 *     two inquiries can never claim the same target row; MariaDB treats NULLs as distinct, so every
 *     unrouted inquiry still fits.
 *   · `collaborator_id` (deferred → Phase 8 `collaborators`), `referral_code` (plain string, points at
 *     nothing by design) and `referral_visit_id` (deferred → Phase 9 `collaborator_referral_visits`) —
 *     **display snapshots only** (ND-3, decision D37): no engine and no access scope reads them.
 *
 * Every foreign key and every deferred id has its own index (F-9.2, ND-3); `(routing_target,
 * routing_status)` drives the "Awaiting CRM / Institute" tabs and `routePending()` (F-9.3).
 *
 * Mutable queue table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_inquiries')) {
            return;
        }

        Schema::create('contact_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('inquiry_type', 32)->default('general');
            $table->string('name', 150);
            $table->string('email', 150);
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 32)->nullable();
            $table->string('company', 150)->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            // §2.1 deferred link → courses.id (Phase 14). No constraint here.
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('course_name', 150)->nullable();
            $table->string('budget', 100)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->string('source', 32)->default('website');
            $table->string('status', 32)->default('new');
            $table->boolean('is_spam')->default(false);
            $table->string('spam_reason', 100)->nullable();
            $table->string('routing_status', 32)->default('not_applicable');
            $table->string('routing_target', 32)->nullable();
            $table->string('routed_type', 255)->nullable();
            $table->unsignedBigInteger('routed_id')->nullable();
            $table->timestamp('routed_at')->nullable();
            $table->unsignedTinyInteger('routing_attempts')->default(0);
            $table->string('routing_error', 255)->nullable();
            // ND-3 / D37 display snapshots. The two ids are §2.1 deferred links → collaborators.id
            // (Phase 8) and collaborator_referral_visits.id (Phase 9); no constraint here.
            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->string('referral_code', 32)->nullable();
            $table->unsignedBigInteger('referral_visit_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->string('page_url', 255)->nullable();
            $table->string('referrer_url', 255)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->unsignedSmallInteger('filled_in_seconds')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            // §2.20 Keys.
            $table->unique(['routed_type', 'routed_id'], 'uq_contact_inquiry_routed_target');
            $table->index(['inquiry_type', 'status']);
            $table->index(['routing_status', 'created_at']);
            $table->index(['is_spam', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['routing_target', 'routing_status']);
            $table->index('service_id');
            $table->index('assigned_to');
            $table->index('read_by');
            $table->index('collaborator_id');
            $table->index('referral_code');
            $table->index('referral_visit_id');
            // Column-level indexes the table names (the deferred course id, email lookup, source filter).
            $table->index('course_id');
            $table->index('email');
            $table->index('source');

            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('read_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Last Phase 4 migration, first to roll back. Inbound keys from later phases
        // (leads.contact_inquiry_id, course_inquiries.contact_inquiry_id) are dropped by those phases'
        // own rollbacks first; DROP TABLE removes the five keys this table owns.
        Schema::dropIfExists('contact_inquiries');
    }
};
