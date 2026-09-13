<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · §2.3 — website_section_items.
 *
 * The one repeater table for every section type, discriminated by `group`
 * ([D-W3-3]): the six hero statistics (§9), the about statistics, the
 * why-choose-us points and the history timeline (§10) are all rows here.
 *
 * `metric`, `value_mode` and `manual_value` are real columns because they are the only
 * repeater values a service resolves, validates and reports on (§6.11, §8.11).
 * `manual_value` is decimal(15,2) — INV-12: statistic values are decimal strings,
 * never floats (CLAUDE.md §1.4 applies to every number, not only money).
 *
 * Created after website_sections and media_assets, which it references.
 *
 * Mutable content table → timestamps + softDeletes + blameable (CLAUDE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('website_section_items')) {
            Schema::create('website_section_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_section_id')
                    ->constrained('website_sections')->cascadeOnDelete();
                // The repeater key declared by the registry:
                // statistic, why_choose_us, history, highlight, link.
                $table->string('group', 32);
                $table->json('content')->nullable();
                $table->string('metric', 48)->nullable();
                $table->string('value_mode', 16)->default('manual');
                $table->decimal('manual_value', 15, 2)->nullable();
                $table->foreignId('media_asset_id')->nullable()
                    ->constrained('media_assets')->nullOnDelete();
                $table->boolean('is_enabled')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                // §2.3 Keys.
                $table->index(
                    ['website_section_id', 'group', 'is_enabled', 'sort_order'],
                    'idx_wsi_group'
                );
                $table->index(['metric', 'value_mode']);
            });
        }

        // §2.3 CHECK chk_wsi_value.
        $this->addCheck(
            'website_section_items',
            'chk_wsi_value',
            "`value_mode` in ('manual','auto')"
            ." and (`value_mode` = 'manual' or `metric` is not null)"
        );
    }

    public function down(): void
    {
        // DROP TABLE removes the four foreign keys this table owns
        // (website_section_id, media_asset_id, created_by, updated_by) and the named
        // CHECK constraint in one statement. Nothing references this table.
        Schema::dropIfExists('website_section_items');
    }

    /**
     * Add a named CHECK constraint if MariaDB does not already carry it.
     */
    private function addCheck(string $table, string $name, string $expression): void
    {
        if (! Schema::hasTable($table) || $this->hasCheck($table, $name)) {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
            $table,
            $name,
            $expression
        ));
    }

    private function hasCheck(string $table, string $name): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS'
            .' WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1',
            [DB::getDatabaseName(), $table, $name]
        );

        return $rows !== [];
    }
};
