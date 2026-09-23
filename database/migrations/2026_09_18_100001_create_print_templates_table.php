<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 · file 1 — `print_templates` (phase-19-23 §2.13, requirements §82, §84, §85).
 *
 * **[D-21-1] One table for three printable documents.** §84 and §85 each demand an admin-controlled
 * template and §82 a printable result card. Three tables would have been three editors, three token
 * lists and three sanitisers, drifting apart on their own schedules; one table with a `type` gives one
 * of each.
 *
 * **[D-21-2] `body_html` is HTML with `{tokens}`, never Blade.** Storing Blade and rendering it would
 * hand remote code execution to anybody holding `print_templates.edit`. It is sanitised by
 * `RichText::sanitize($html, 'material')` on save *and* again on render, and replaced by a token
 * substituter over a fixed list — so a row written straight into the database still cannot reach a
 * printed page (INV-21-5).
 *
 * **`default_guard` is a generated STORED column** for the same MariaDB reason as Phase 20's
 * `active_guard`: a unique index tolerates unlimited NULLs, so `CASE WHEN is_default THEN 1 ELSE NULL`
 * lets `uq_pt_default` permit exactly one default per (type, branch) while every other row coexists.
 *
 * **Every enum-backed column is string(32).** `PrintTemplateType::StudentIdCard` is fifteen characters
 * and the contract says string(24), which fits — but Phase 20 shipped `exams.status` at varchar(16)
 * with a seventeen-character case in it, and the publish step was unreachable until somebody probed
 * the whole ladder. The convention (CLAUDE.md §3) is a width nobody has to measure against. **D126.**
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards the CREATE and re-ensures everything else.
 */
return new class extends Migration
{
    private const TABLE = 'print_templates';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->create();
        }

        $this->guard();
        $this->constraints();
    }

    private function create(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            // See the class note on width. 32 throughout, measured against nothing.
            $table->string('type', 32);
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();

            // Null means every branch. A branch may own its own letterhead.
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('paper_size', 32)->default('a4');
            $table->string('orientation', 32)->default('portrait');
            // Required when `paper_size = custom` — chk_pt_custom says so.
            $table->decimal('width_mm', 6, 2)->nullable();
            $table->decimal('height_mm', 6, 2)->nullable();
            $table->decimal('margin_mm', 5, 2)->default('10.00');

            // A design asset with no PII, so the public disk is correct here — unlike a certificate
            // PDF, which is a private artefact and never touches it (D21).
            $table->string('background_image_path', 255)->nullable();
            $table->string('logo_path', 255)->nullable();

            // Sanitised on save and again on render. Never compiled, never evaluated (INV-21-5).
            $table->longText('body_html');
            $table->longText('custom_css')->nullable();

            // Extracted on save. An unknown token is a warning naming it, not a refusal — see
            // PrintTokenRegistry::unknownIn().
            $table->json('tokens_used')->nullable();
            // Ordered [{name, title, image_path}].
            $table->json('signatories')->nullable();

            $table->boolean('show_qr')->default(true);
            $table->decimal('qr_size_mm', 5, 2)->default('25.00');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // A cached render, on the private disk: it is built from example data, but a preview of a
            // template is still an internal artefact and there is no reason to serve it publicly.
            $table->string('preview_path', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_pt_code');
            $table->index(['type', 'is_active', 'sort_order'], 'idx_pt_type');
            $table->index(['branch_id', 'type'], 'idx_pt_branch');

            $table->foreign('branch_id', RawSchema::foreignKeyName(self::TABLE, 'branch_id'))
                ->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by', RawSchema::foreignKeyName(self::TABLE, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::TABLE, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * One default per (type, branch). NULL for a non-default row is the mechanism, not an accident
     * being worked around — see the class note.
     */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'default_guard')) {
            RawSchema::generatedColumn(
                self::TABLE,
                'default_guard',
                'tinyint',
                'CASE WHEN `is_default` = 1 THEN 1 ELSE NULL END',
            );
        }

        if (! RawSchema::indexExists(self::TABLE, 'uq_pt_default', true)) {
            RawSchema::uniqueIndex(self::TABLE, 'uq_pt_default', ['type', 'branch_id', 'default_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure(
            'chk_pt_custom',
            "`paper_size` <> 'custom' OR (`width_mm` IS NOT NULL AND `height_mm` IS NOT NULL)",
        );
        $this->ensure(
            'chk_pt_dims',
            '(`width_mm` IS NULL OR `width_mm` > 0) AND (`height_mm` IS NULL OR `height_mm` > 0) AND `margin_mm` >= 0',
        );
        $this->ensure('chk_pt_qr', '`qr_size_mm` > 0');
    }

    public function down(): void
    {
        // The index reads the generated column, so it goes first: dropping a column an index depends
        // on is error 1553, and the rollback test runs this over a table holding rows.
        if (Schema::hasTable(self::TABLE)) {
            if (RawSchema::indexExists(self::TABLE, 'uq_pt_default', true)) {
                RawSchema::dropIndex(self::TABLE, 'uq_pt_default');
            }

            if (Schema::hasColumn(self::TABLE, 'default_guard')) {
                RawSchema::dropColumn(self::TABLE, 'default_guard');
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function ensure(string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check(self::TABLE, $name, $expression);
        }
    }
};
