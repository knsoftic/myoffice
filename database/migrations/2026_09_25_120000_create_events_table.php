<?php

declare(strict_types=1);

use App\Support\Schema\RawSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `events` — the public events and announcements board.
 *
 * **It reuses `ContentStatus`, and does not get a status enum of its own.** Draft, scheduled,
 * published and archived already mean exactly the same things here as they do for a blog post, and
 * a second four-case enum spelling the same four words is how two screens end up disagreeing about
 * what "scheduled" allows. `published_at` carries the same double duty: the future go-live moment
 * while `scheduled`, the live moment once `published`.
 *
 * **An announcement is an event with no end.** Rather than a `type` column splitting the table in
 * two, `ends_at` nullable does the work: something that happens at a moment and something that runs
 * between two moments are the same row shape, and the listing sorts both by `starts_at`. A column
 * that exists only to be branched on in every query is a table pretending to be two tables.
 *
 * The cover image is a `media_assets` row (**D24**: the CMS media library is the website's only
 * uploader), never a path column — an events module with its own upload path is a second private
 * disk nobody remembers to back up.
 *
 * Soft-deleted, not append-only: an event is mutable content an editor revises and withdraws, which
 * is the ordinary case **D19** leaves with `deleted_at`.
 *
 * **D70 — MariaDB DDL is not transactional.** The CREATE is guarded so a re-run cannot fail on an
 * existing table, and the CHECK constraints and indexes are ensured on every run rather than only
 * inside the create block: a migration that died half way leaves the table it already made, and the
 * constraints it had not reached yet must still arrive on the retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->id();

                $table->string('title', 200);
                $table->string('slug', 200);
                $table->string('summary', 500)->nullable();
                $table->longText('description')->nullable();

                // D24: the image is a media library row, never a path this table owns.
                $table->unsignedBigInteger('cover_media_id')->nullable();

                $table->dateTime('starts_at');
                // Null means a moment, not a span — an announcement rather than an event.
                $table->dateTime('ends_at')->nullable();
                $table->boolean('is_all_day')->default(false);

                $table->string('location', 255)->nullable();
                $table->boolean('is_online')->default(false);
                $table->string('meeting_url', 500)->nullable();
                $table->string('registration_url', 500)->nullable();

                // Null means "not limited", which is not the same as zero seats left.
                $table->unsignedInteger('capacity')->nullable();

                $table->string('status', 32)->default('draft');
                $table->dateTime('published_at')->nullable();

                $table->boolean('is_featured')->default(false);
                $table->integer('sort_order')->default(0);

                $table->timestamps();
                $table->softDeletes();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

                $table->unique('slug', 'uq_events_slug');

                // The listing's own query: published, ordered by when it happens.
                $table->index(['status', 'starts_at'], 'idx_events_status_starts');
                $table->index('starts_at', 'idx_events_starts');
                $table->index(['is_featured', 'starts_at'], 'idx_events_featured_starts');

                $table->foreign('cover_media_id', 'fk_events_cover_media')
                    ->references('id')->on('media_assets')->nullOnDelete();
            });
        }

        /*
        | Ensured every run, per D70. Each one refuses a row that would make the public page lie.
        */

        // An event that finishes before it starts renders as a negative duration and sorts wrongly
        // in both directions. The form validates it too; this is the floor under the form.
        if (! RawSchema::checkExists('chk_events_ends_after_starts')) {
            RawSchema::check('events', 'chk_events_ends_after_starts', '`ends_at` IS NULL OR `ends_at` >= `starts_at`');
        }

        // "Online" with nowhere to go is the one combination a visitor cannot recover from: they
        // read that they can attend from home and the page gives them no way to.
        if (! RawSchema::checkExists('chk_events_online_has_url')) {
            RawSchema::check('events', 'chk_events_online_has_url', '`is_online` = 0 OR `meeting_url` IS NOT NULL');
        }

        // Neither a place nor a link is an event nobody can attend.
        if (! RawSchema::checkExists('chk_events_has_a_where')) {
            RawSchema::check('events', 'chk_events_has_a_where', '`is_online` = 1 OR `location` IS NOT NULL');
        }

        // Zero seats is a full event, which is a state; a capacity of zero on creation is a typo.
        if (! RawSchema::checkExists('chk_events_capacity')) {
            RawSchema::check('events', 'chk_events_capacity', '`capacity` IS NULL OR `capacity` > 0');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
