<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 · file 1 — `grade_scales` and `grade_scale_bands` (phase-19-23 §2.9, §2.10, §82).
 *
 * **[D-20-1] The grade scale is data, not code.** §82 asks for a grade, and a hardcoded A/B/C ladder
 * would be exactly the "hardcoded status" `CLAUDE.md` §1.8 forbids. A scale is a named set of
 * contiguous percentage bands that an exam — and later a certificate — snapshots.
 *
 * **`default_guard` is a generated STORED column for the usual MariaDB reason.** There must be exactly
 * one default scale in the whole database, and `UNIQUE (is_default)` cannot say that: it would also
 * forbid a second *non*-default scale, because `0` collides with `0`. The guard is `1` when the row is
 * the default and **NULL** otherwise, and MariaDB tolerates unlimited NULLs in a unique index — so
 * `uq_gs_default` permits one default and any number of ordinary scales.
 *
 * **Contiguity is not here, and cannot be.** That every band abuts the next and that the set covers
 * exactly 0–100 are properties of the *set*, which no CHECK can express. `GradeBandValidator` asserts
 * them inside the saving transaction and `grades:verify-scales` re-asserts them nightly (INV-20-3).
 * What the database can hold — a band that runs backwards, an edge outside 0–100 — it does hold.
 *
 * **D70: MariaDB DDL is not transactional**, so `up()` guards each CREATE and re-ensures the guard
 * column, the indexes and the CHECKs on every run.
 */
return new class extends Migration
{
    private const SCALES = 'grade_scales';

    private const BANDS = 'grade_scale_bands';

    public function up(): void
    {
        if (! Schema::hasTable(self::SCALES)) {
            $this->createScales();
        }

        if (! Schema::hasTable(self::BANDS)) {
            $this->createBands();
        }

        $this->guard();
        $this->constraints();
    }

    private function createScales(): void
    {
        Schema::create(self::SCALES, function (Blueprint $table): void {
            $table->id();

            // `DEFAULT`, `PRACTICAL-5`, … — a short handle a person types, not a slug.
            $table->string('code', 32);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();

            // The scale's own pass line, used when an exam carries no `passing_marks` of its own.
            // decimal(8,4) per CLAUDE.md §3 — it is a percentage.
            $table->decimal('pass_percentage', 8, 4)->default('40.0000');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);

            // CACHE of grade_scale_bands. Recounted, never incremented.
            $table->unsignedTinyInteger('bands_count')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique('code', 'uq_gs_code');
            $table->index(['is_active', 'sort_order'], 'idx_gs_active');

            $table->foreign('created_by', RawSchema::foreignKeyName(self::SCALES, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::SCALES, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    private function createBands(): void
    {
        Schema::create(self::BANDS, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('grade_scale_id');

            $table->string('grade', 8);
            $table->string('title', 60)->nullable();

            // Both **inclusive** (F-7.1): a band is 80.00–89.99 and the next starts at 90.00.
            $table->decimal('min_percentage', 8, 4);
            $table->decimal('max_percentage', 8, 4);

            // decimal(4,2): a GPA is 0.00–9.99 in every scheme anybody uses.
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->boolean('is_pass')->default(true);

            // A Tailwind token, so the badge colour is data like everything else on the row.
            $table->string('color', 16)->nullable();
            $table->string('remark_template', 255)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['grade_scale_id', 'grade'], 'uq_gsb_grade');
            // The lookup `ResultCalculator::bandFor()` makes for every marked row.
            $table->index(['grade_scale_id', 'min_percentage', 'max_percentage'], 'idx_gsb_range');

            $table->foreign('grade_scale_id', RawSchema::foreignKeyName(self::BANDS, 'grade_scale_id'))
                ->references('id')->on(self::SCALES)->cascadeOnDelete();

            $table->foreign('created_by', RawSchema::foreignKeyName(self::BANDS, 'created_by'))
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', RawSchema::foreignKeyName(self::BANDS, 'updated_by'))
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * One default scale for the whole database. Raw SQL, and it **fails loudly** if the server rejects
     * it (spine R-3): a `uq_gs_default` that silently did not apply would let two scales both claim to
     * be the default, and every exam that named neither would get whichever the query returned first.
     */
    private function guard(): void
    {
        if (! Schema::hasColumn(self::SCALES, 'default_guard')) {
            RawSchema::generatedColumn(
                self::SCALES,
                'default_guard',
                'tinyint',
                'CASE WHEN `is_default` = 1 THEN 1 ELSE NULL END',
            );
        }

        if (! RawSchema::indexExists(self::SCALES, 'uq_gs_default', true)) {
            RawSchema::uniqueIndex(self::SCALES, 'uq_gs_default', ['default_guard']);
        }
    }

    private function constraints(): void
    {
        $this->ensure(self::SCALES, 'chk_gs_pass', '`pass_percentage` BETWEEN 0 AND 100');
        $this->ensure(self::SCALES, 'chk_gs_bands', '`bands_count` >= 0');

        // What a single row can be asked: inside 0–100, and not running backwards. Whether the *set*
        // of bands is contiguous is INV-20-3's job — see the class note.
        $this->ensure(self::BANDS, 'chk_gsb_range',
            '`min_percentage` >= 0 AND `max_percentage` <= 100 AND `max_percentage` >= `min_percentage`');

        $this->ensure(self::BANDS, 'chk_gsb_point', '`grade_point` IS NULL OR `grade_point` >= 0');
    }

    public function down(): void
    {
        // Bands first: their foreign key points at the scales.
        Schema::dropIfExists(self::BANDS);

        // The unique index reads the generated column, so it goes before the column does — dropping a
        // column an index depends on is error 1553.
        if (Schema::hasTable(self::SCALES)) {
            if (RawSchema::indexExists(self::SCALES, 'uq_gs_default', true)) {
                RawSchema::dropIndex(self::SCALES, 'uq_gs_default');
            }

            if (Schema::hasColumn(self::SCALES, 'default_guard')) {
                RawSchema::dropColumn(self::SCALES, 'default_guard');
            }
        }

        Schema::dropIfExists(self::SCALES);
    }

    private function ensure(string $table, string $name, string $expression): void
    {
        if (! RawSchema::checkExists($name)) {
            RawSchema::check($table, $name, $expression);
        }
    }
};
