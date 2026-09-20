<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 · §2.2, §2.3 — a collaborator's skills and the services they offer (requirement §34).
 *
 * **Skills are a child list, not a shared taxonomy** ([D-P8-3]). Phases 7, 13 and 16 also carry skills;
 * inventing a cross-domain `skills` table here would collide with whatever Phase 7 already built. If a
 * shared taxonomy ever appears, this table migrates into it additively.
 *
 * **Services are a pivot onto Phase 4's catalogue**, not free text — which is what makes "find an agency
 * that does SEO" a join rather than a `LIKE`. The pivot is guarded on `services` existing, in the spirit
 * of [D-FS-1], and says so out loud when it skips rather than failing silently.
 *
 * Neither table carries `deleted_at`: both are history pivots under `CLAUDE.md` §3's category rule
 * (**D19**). A removed skill has no audit or financial value, and a soft-deleted row would collide with
 * `uq_cskill` the moment somebody re-added the same skill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collaborator_skills')) {
            Schema::create('collaborator_skills', function (Blueprint $table): void {
                $table->id();
                // cascadeOnDelete: a skill is meaningless without its parent, and the parent can only
                // ever be soft-deleted (INV-C5), so this fires only on a force delete the policy refuses.
                $table->foreignId('collaborator_id')->constrained('collaborators')->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('slug', 80);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // The sync path is "replace the set"; this index is what makes a double-submitted
                // profile form idempotent instead of additive.
                $table->unique(['collaborator_id', 'slug'], 'uq_cskill');
                $table->index('slug');
            });
        }

        if (Schema::hasTable('collaborator_service')) {
            return;
        }

        if (! Schema::hasTable('services')) {
            // Loud rather than silent: a skipped pivot is a missing feature, not a tidy no-op.
            logger()->warning(
                'phase-08 §2.3: collaborator_service was skipped because the services table does not '
                .'exist yet. Re-run this migration after Phase 4.'
            );

            return;
        }

        Schema::create('collaborator_service', function (Blueprint $table): void {
            $table->foreignId('collaborator_id')->constrained('collaborators')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            // No updated_at: the row has nothing to update.
            $table->timestamp('created_at')->nullable();

            // The composite primary key *is* the uniqueness guarantee, so a re-submitted form cannot
            // duplicate a service.
            $table->primary(['collaborator_id', 'service_id']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaborator_service');
        Schema::dropIfExists('collaborator_skills');
    }
};
