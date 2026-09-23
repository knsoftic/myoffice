<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 · file 3 — `certificate_verifications` (phase-19-23 §2.15, requirement §84).
 *
 * **Append-only, and therefore no `updated_at`, no `deleted_at` and no blameable columns.** CLAUDE.md
 * §3 lists "audit, log and run history" among the categories that carry no `deleted_at`, and this is
 * one: it is the abuse-detection surface, the rate limiter's evidence, and §106's log of a public
 * endpoint. A nullable `deleted_at` on a log lets one `->delete()` hide a row from every aggregate
 * while the cached `verification_count` keeps the number — D19, and never add it back.
 *
 * **`certificate_id` is nullable on purpose: a miss is the interesting row.** When a submitted code
 * matches nothing there is no certificate to point at, and that is precisely the record worth keeping
 * — a run of them from one address is somebody enumerating codes. `submitted_code` is stored verbatim
 * (truncated) so the pattern is visible rather than inferred.
 *
 * **The actor is an IP address, not a user**, because the endpoint is unauthenticated. That is why
 * there is no `created_by`: there is nobody to name.
 */
return new class extends Migration
{
    private const TABLE = 'certificate_verifications';

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

            // Null when the code matched nothing — see the class note.
            $table->unsignedBigInteger('certificate_id')->nullable();

            // Verbatim, truncated. An enumeration pattern is only visible if the misses are kept.
            $table->string('submitted_code', 40);
            // string(32) per CLAUDE.md §3 and D126, though `not_public` is the longest at ten.
            $table->string('result', 32);

            // 45 characters holds an IPv6 address with an IPv4 tail.
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 64)->nullable();
            $table->string('referer', 255)->nullable();

            // `created_at` only. See the class note.
            $table->timestamp('created_at')->nullable();

            $table->index(['certificate_id', 'created_at'], 'idx_cv_certificate');
            // The rate limiter's evidence.
            $table->index(['ip_address', 'created_at'], 'idx_cv_ip');
            $table->index(['result', 'created_at'], 'idx_cv_result');
            $table->index('submitted_code', 'idx_cv_code');

            // nullOnDelete rather than restrict: a certificate is never deleted (INV-21-1), so this
            // can only fire if somebody force-removes one past every other guard — and a log row
            // that loses its subject is still evidence of the attempt.
            $table->foreign('certificate_id', RawSchema::foreignKeyName(self::TABLE, 'certificate_id'))
                ->references('id')->on('certificates')->nullOnDelete();
        });
    }

    /**
     * **There is no CHECK on `submitted_code`, and there was one for exactly one commit.**
     *
     * `chk_cv_code` asserted `submitted_code <> ''`, which reads like sensible hygiene and is wrong:
     * the contract says the column stores what was submitted *verbatim*, and somebody submitting an
     * empty form has submitted something. The constraint turned a public, unauthenticated endpoint
     * into a 500 for the simplest possible input — press Verify with the box empty — while the log
     * row it refused to write was the one recording a flood of empty submissions.
     *
     * A constraint that forbids a value the application legitimately produces is not a guard, it is a
     * bug with a `chk_` prefix.
     */
    private function constraints(): void
    {
        // Nothing to ensure. Kept as a method so `up()` reads the same as its siblings and the
        // absence is deliberate rather than looking like an omission.
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
