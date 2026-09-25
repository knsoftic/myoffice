<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 · the `deleted_at` half of [D-P24-1] (phase-24-25 section 2.5, PRF-04 rule 4).
 *
 * **Index additions only** — the same contract as
 * `2026_09_25_100002_add_audit_performance_indexes_table`: it may `ADD INDEX`, it may never add,
 * alter or drop a column, it never drops an index it did not create, and `down()` removes only what
 * it added. Every name is `idx_p24_*` so `grep idx_p24_` still answers "what did Phase 24 add to
 * somebody else's table".
 *
 * ---------------------------------------------------------------------------------------------
 * Why a second file rather than a row in the first one
 * ---------------------------------------------------------------------------------------------
 *
 * Section 2.5's [D-P24-1] grants Phase 24 one *migration of this kind*, and this file keeps every
 * clause of it. It is separate only because `add_audit_performance_indexes_table` has already run
 * (batch 33 in the development schema, and in every environment that has deployed Phase 24), so a
 * row appended to its `INDEXES` constant would be applied on a `migrate:fresh` and nowhere else —
 * which is the one place the index is not needed. A new file is what actually reaches a deployed
 * database. `2026_09_24_100002_add_reporting_indexes_to_activity_log_table` is the same shape for
 * the same reason.
 *
 * ---------------------------------------------------------------------------------------------
 * What PRF-04 rule 4 found
 * ---------------------------------------------------------------------------------------------
 *
 * Section 2.5: "Every `deleted_at` on a table queried with `withTrashed()` is indexed."
 * `IndexCoverageTest::every_table_read_with_trashed_is_indexed_on_deleted_at` scans `app/` for
 * static `Model::withTrashed()` calls and found thirteen tables. Four were already indexed
 * (`collaborators`, `employees`, `projects`, `tasks` — each one `$table->index('deleted_at')` in its
 * creating migration). **The nine below carry a `deleted_at` column that appears in no index at all,
 * not even as a trailing column of a composite** — checked against `information_schema.STATISTICS`,
 * not against anyone's list of it.
 *
 * `users` is the one that should never have been missing: section 2.5's own worked manifest prints
 * `'users' => [['status'], ['branch_id'], ['email'], ['deleted_at']]`, and the index was not there.
 * `tests/Support/index-manifest.php` has no Phase 1 banner, so rule 1 never asked for it either —
 * that is R-15 exactly (a phase that ships without appending to the manifest is outside the sweep),
 * and the manifest rows go with this migration.
 *
 * The cost is paid twice over on every one of them, which is why a lookup-by-key call site is not a
 * reason to skip the index:
 *
 * · Laravel's soft-delete scope appends `deleted_at is null` to **every ordinary query** on these
 *   models, so the column is on the hot path of the live screens, not only the archive ones.
 * · The `withTrashed()` reads are the restore and archive screens, which are opened rarely, by an
 *   administrator, who assumes they are slow because they are big. There is no runtime symptom to
 *   test for — a full scan of forty demo rows is instant, and the same scan over sixty thousand is
 *   the page that times out.
 *
 * **D70: MariaDB DDL is not transactional.** Each index is checked before it is added, so a
 * half-applied run heals on the next one rather than failing forever on a 1061.
 */
return new class extends Migration
{
    /**
     * table => column. One entry per table PRF-04 rule 4 reported, and nothing the pattern merely
     * suggests: `collaborators`, `employees`, `projects` and `tasks` are read `withTrashed()` too and
     * are deliberately absent because they are already indexed.
     *
     * @var array<string, string>
     */
    private const INDEXES = [
        // app/Services/Cms/BlogService.php — lockStatus() and the tag audit.
        'blog_posts' => 'deleted_at',
        'blog_tags' => 'deleted_at',

        // app/Services/Cms/JobApplicationService.php — the duplicate-application guard.
        'job_applications' => 'deleted_at',

        // app/Notifications/Cms/NewJobApplicationNotification.php — the title of a closed opening.
        'job_openings' => 'deleted_at',

        // app/Services/Cms/PortfolioService.php — the orphaned-directory cleanup and lockItem().
        'media_assets' => 'deleted_at',
        'portfolio_items' => 'deleted_at',

        // app/Http/Requests/Cms/Concerns/ValidatesPage.php — "a trashed page holds this slug".
        'pages' => 'deleted_at',

        // app/Services/Institute/CourseProgressService.php — a trashed row occupies the seat guard.
        'student_course_progress' => 'deleted_at',

        // app/Services/Collaborator/CollaboratorOnboardingService.php — the email-already-used check.
        'users' => 'deleted_at',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $name = $this->name($table, $column);

            if (! RawSchema::indexExists($table, $name)) {
                RawSchema::index($table, $name, [$column]);
            }
        }
    }

    public function down(): void
    {
        // Only what this migration created. An index that happens to cover the same column but
        // carries another phase's name is that phase's to drop.
        foreach (self::INDEXES as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $name = $this->name($table, $column);

            if (RawSchema::indexExists($table, $name)) {
                RawSchema::dropIndex($table, $name);
            }
        }
    }

    /**
     * `idx_p24_{table}_{column}`, truncated to MariaDB's 64-character identifier limit — the same
     * naming `add_audit_performance_indexes_table` uses, for the same greppability.
     */
    private function name(string $table, string $column): string
    {
        return mb_substr(sprintf('idx_p24_%s_%s', $table, $column), 0, 64);
    }
};
