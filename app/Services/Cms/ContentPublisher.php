<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\RevisionEvent;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\Page;
use App\Models\Cms\WebsiteSection;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Services\Cms\Exceptions\UnknownSectionTypeException;
use App\Support\Cms\SectionRegistry;
use App\Support\RichText;
use Carbon\CarbonInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Publishing by snapshot — decision **D22** (phase-03 §2.15, §6.2, §6.4, [D-W3-7], INV-1, INV-4, INV-8).
 *
 * The public site renders only `published_content`. This class is the **only** writer of that
 * column and of `published_hash`, `published_at` and `published_by`, for both snapshot-published
 * entities: `website_sections` and `pages`. One public entry point per operation:
 *
 *   publish()     draft -> snapshot (a page with a future `published_at` is scheduled instead)
 *   unpublish()   take it off the public site; the snapshot is kept, a reason is mandatory
 *   revert()      copy a revision back into the **draft** — never straight to live
 *   schedule()    a page goes live by itself at a future moment
 *   publishDue()  promote every scheduled page that is due, bumping the cache once (§10.4)
 *   verify()      the checks `cms:verify-published-snapshots` runs on one section
 *
 * Invariants:
 *
 *   · **Every write of an act is one transaction**, with the row locked `FOR UPDATE` first: the
 *     snapshot, the hashes, the status, the revision and the audit row commit together or not at all.
 *   · **A publish is complete or refused.** A section is checked by `SectionService::assertPublishable()`
 *     before anything is written; an incomplete section leaves `published_hash` unchanged (FT-13).
 *   · **INV-4.** Publishing writes `content_hash` and `published_hash` from the same canonical payload
 *     in the same statement, so `has_unpublished_changes` is 0 exactly when the live copy is the draft.
 *   · **One activity row per publish, unpublish and revert** (INV-16), with old and new values, the
 *     actor, the request context and — for unpublish and revert — the reason.
 *   · **Revisions are append-only.** Each act appends a `cms_revisions` row (the publish snapshot
 *     flagged `is_published_snapshot`); no revision is ever edited or deleted here, and reverting to
 *     one appends a `reverted` row.
 *   · **INV-8.** Anything that changes what the public sees bumps the cache version **after commit**;
 *     a rolled-back publish never flushes the site. A revert changes only the draft and bumps nothing.
 */
final class ContentPublisher
{
    private const SECTION_MODULE = 'website_sections';

    private const PAGE_MODULE = 'pages';

    /** Queued after a page publish/unpublish (§10.1 `QueueSitemapRegeneration`). */
    private const SITEMAP_JOB = 'App\\Jobs\\Cms\\RegenerateSitemap';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SectionService $sections,
        private readonly SnapshotBuilder $snapshots,
        private readonly ContentHasher $hasher,
        private readonly RevisionRecorder $revisions,
        private readonly SeoService $seo,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Publish
    |--------------------------------------------------------------------------
    */

    /**
     * Publish a section or a page.
     *
     * @template T of WebsiteSection|Page
     *
     * @param  T  $target
     * @return T
     *
     * @throws InvalidSectionContentException when the section is incomplete
     * @throws UnknownSectionTypeException when the section type is no longer registered
     */
    public function publish(WebsiteSection|Page $target, ?string $label = null): WebsiteSection|Page
    {
        return $target instanceof WebsiteSection
            ? $this->publishSection($target, $label)
            : $this->publishPage($target, $label, promoting: false);
    }

    /**
     * Take a section or page off the public site. The snapshot is kept so a re-publish and the history
     * stay lossless (§6.2 `unpublish()`, FT-10).
     *
     * @template T of WebsiteSection|Page
     *
     * @param  T  $target
     * @return T
     */
    public function unpublish(WebsiteSection|Page $target, string $reason): WebsiteSection|Page
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidSectionContentException::reasonRequired('unpublish');
        }

        $isSection = $target instanceof WebsiteSection;
        $table = $isSection ? 'website_sections' : 'pages';

        $this->connection()->transaction(function () use ($target, $reason, $isSection, $table): void {
            $row = $this->lock($table, (int) $target->getKey());
            $label = $this->label($row, $isSection);
            $status = (string) $row->status;

            if (! in_array($status, [ContentStatus::Published->value, ContentStatus::Scheduled->value], true)) {
                throw new ContentActionNotAllowedException(sprintf('"%s" is not live or scheduled, so there is nothing to unpublish.', $label));
            }

            $now = Carbon::now();

            $this->connection()->table($table)->where('id', $row->id)->update([
                'status' => ContentStatus::Draft->value,
                'unpublished_reason' => mb_substr($reason, 0, 255),
                'updated_at' => $now,
                'updated_by' => $this->auditor->actorId(),
            ]);

            $canonical = $isSection
                ? $this->sections->canonicalPayload($target)
                : $this->hasher->pageCanonical($row->content);

            $this->revisions->record($target, RevisionEvent::Unpublished, $canonical, $reason);

            $this->auditor->record(
                module: $isSection ? self::SECTION_MODULE : self::PAGE_MODULE,
                description: sprintf('%s unpublished: %s', $isSection ? 'Section' : 'Page', $label),
                subject: $target,
                properties: ['old' => ['status' => $status], 'attributes' => ['status' => ContentStatus::Draft->value]],
                reason: $reason,
                event: 'unpublished',
            );

            if ($status === ContentStatus::Published->value) {
                $this->cache->bumpAfterCommit(sprintf('%s #%d unpublished', $isSection ? 'Section' : 'Page', $row->id));
            }

            if (! $isSection) {
                $this->queueSitemap('unpublish');
            }
        });

        return $this->fresh($target);
    }

    /**
     * Copy a revision back into the draft (§6.2 `revertToRevision()`, FT-11). The live copy is untouched
     * until someone publishes.
     *
     * @template T of WebsiteSection|Page
     *
     * @param  T  $target
     * @return T
     */
    public function revert(WebsiteSection|Page $target, CmsRevision $revision, string $reason): WebsiteSection|Page
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidSectionContentException::reasonRequired('revert to an earlier revision');
        }

        $this->revisions->assertBelongsTo($revision, $target);
        $canonical = $this->revisions->canonical($revision);
        $isSection = $target instanceof WebsiteSection;

        $this->connection()->transaction(function () use ($target, $revision, $canonical, $reason, $isSection): void {
            $table = $isSection ? 'website_sections' : 'pages';
            $row = $this->lock($table, (int) $target->getKey());

            if ($row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That item is in the trash.', ['revision' => ['Restore it before reverting.']]);
            }

            $oldHash = $row->content_hash;

            if ($isSection) {
                $newHash = $this->sections->applyCanonical($target, $canonical);
                $after = $this->sections->canonicalPayload($target);
            } else {
                $content = array_key_exists('content', $canonical) ? RichText::sanitize((string) $canonical['content']) : (string) $row->content;
                $after = $this->hasher->pageCanonical($content);
                $newHash = $this->hasher->hash($after);

                $this->connection()->table('pages')->where('id', $row->id)->update([
                    'content' => $content,
                    'content_hash' => $newHash,
                    'updated_at' => Carbon::now(),
                    'updated_by' => $this->auditor->actorId(),
                ]);
            }

            $this->revisions->record($target, RevisionEvent::Reverted, $after, $reason, sprintf('Reverted to revision #%d', $revision->getKey()));

            $this->auditor->record(
                module: $isSection ? self::SECTION_MODULE : self::PAGE_MODULE,
                description: sprintf('%s reverted to revision #%d: %s', $isSection ? 'Section' : 'Page', $revision->getKey(), $this->label($row, $isSection)),
                subject: $target,
                properties: ['old' => ['content_hash' => $oldHash], 'attributes' => ['content_hash' => $newHash, 'revision_id' => (int) $revision->getKey()]],
                reason: $reason,
                event: 'reverted',
            );
        });

        return $this->fresh($target);
    }

    /**
     * Schedule a page to publish itself (§6.4 `schedule()`, FT-32). A page that is live now is refused:
     * scheduling it would take it off the public site until the moment arrives.
     */
    public function schedule(Page $page, CarbonInterface $at): Page
    {
        if (! $at->isFuture()) {
            throw ContentActionNotAllowedException::scheduleMustBeFuture();
        }

        $this->connection()->transaction(function () use ($page, $at): void {
            $row = $this->lock('pages', (int) $page->getKey());
            $label = $this->label($row, false);

            $this->assertPageWritable($row, $label);

            if ((string) $row->status === ContentStatus::Published->value) {
                throw new ContentActionNotAllowedException(sprintf(
                    '"%s" is live. Publish the changes now, or unpublish it before scheduling a new date.',
                    $label
                ));
            }

            $this->connection()->table('pages')->where('id', $row->id)->update([
                'status' => ContentStatus::Scheduled->value,
                'published_at' => Carbon::instance($at),
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::PAGE_MODULE,
                description: sprintf('Page scheduled: %s', $label),
                subject: $page,
                properties: $this->auditor->diff(
                    ['status' => $row->status, 'published_at' => $row->published_at],
                    ['status' => ContentStatus::Scheduled->value, 'published_at' => Carbon::instance($at)->toDateTimeString()]
                ),
                event: 'scheduled',
            );
        });

        /** @var Page */
        return $this->fresh($page);
    }

    /**
     * Promote every scheduled page whose moment has come — one transaction per page, one cache bump
     * for the whole batch (§10.4 `cms:publish-scheduled`). A page that fails is reported and skipped;
     * it does not stop the others.
     *
     * @return list<int> the ids of the pages published
     */
    public function publishDue(?CarbonInterface $now = null): array
    {
        $now = $now === null ? Carbon::now() : Carbon::instance($now);

        $ids = $this->connection()->table('pages')
            ->where('status', ContentStatus::Scheduled->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now)
            ->whereNull('deleted_at')
            ->orderBy('published_at')->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->cache->batch(function () use ($ids): array {
            $published = [];

            foreach (Page::query()->whereIn('id', $ids)->get() as $page) {
                try {
                    $this->publishPage($page, null, promoting: true);
                    $published[] = (int) $page->getKey();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            return $published;
        }, 'Scheduled pages published');
    }

    /**
     * What `cms:verify-published-snapshots` asserts for one section (§10.4). Empty means healthy.
     *
     * @return list<string>
     */
    public function verify(WebsiteSection $section): array
    {
        $row = $this->connection()->table('website_sections')->where('id', $section->getKey())->first();

        if ($row === null) {
            return ['The section no longer exists.'];
        }

        $problems = [];
        $key = (string) $row->section_key;
        $live = (bool) $row->is_enabled && (string) $row->status === ContentStatus::Published->value && $row->deleted_at === null;

        if (! SectionRegistry::exists($key)) {
            $problems[] = sprintf('The section type [%s] is no longer registered (INV-2).', $key);
        }

        if (! $live) {
            return $problems;
        }

        $snapshot = is_string($row->published_content) ? json_decode($row->published_content, true) : null;

        if (! is_array($snapshot) || $snapshot === []) {
            $problems[] = 'The section is enabled and published but has no published snapshot.';

            return $problems;
        }

        if (($snapshot['content_hash'] ?? null) !== $row->published_hash) {
            $problems[] = 'The published hash does not match the hash recorded inside the snapshot.';
        }

        $referenced = $this->connection()->table('website_section_media')
            ->where('website_section_id', $row->id)->pluck('media_asset_id')->map(static fn (mixed $id): int => (int) $id)->all();

        $missing = array_diff($referenced, $this->connection()->table('media_assets')
            ->whereIn('id', $referenced ?: [0])->whereNull('deleted_at')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all());

        if ($missing !== []) {
            $problems[] = sprintf('Media placed on the section no longer exist: #%s.', implode(', #', $missing));
        }

        return $problems;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function publishSection(WebsiteSection $section, ?string $label): WebsiteSection
    {
        $this->connection()->transaction(function () use ($section, $label): void {
            $row = $this->lock('website_sections', (int) $section->getKey());
            $name = $this->label($row, true);

            if ($row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That section is in the trash.', ['section' => ['Restore it before publishing.']]);
            }

            if ((string) $row->status === ContentStatus::Archived->value) {
                throw ContentActionNotAllowedException::archived($name);
            }

            $this->sections->assertPublishable($section);

            $hash = $this->sections->refreshHash($section);
            $canonical = $this->sections->canonicalPayload($section);
            $snapshot = $this->snapshots->build($section);
            $snapshot['content_hash'] = $hash;
            $now = Carbon::now();
            $actor = $this->auditor->actorId();

            $this->connection()->table('website_sections')->where('id', $row->id)->update([
                'published_content' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'content_hash' => $hash,
                'published_hash' => $hash,
                'status' => ContentStatus::Published->value,
                'published_at' => $now,
                'published_by' => $actor,
                'unpublished_reason' => null,
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            $this->revisions->record($section, RevisionEvent::Published, $canonical, label: $label);

            $this->auditor->record(
                module: self::SECTION_MODULE,
                description: sprintf('Section published: %s', $name),
                subject: $section,
                properties: $this->auditor->diff(
                    ['status' => $row->status, 'published_hash' => $row->published_hash, 'published_at' => $row->published_at],
                    ['status' => ContentStatus::Published->value, 'published_hash' => $hash, 'published_at' => $now->toDateTimeString()]
                ),
                event: 'published',
            );

            $this->cache->bumpAfterCommit(sprintf('Section #%d published', $row->id));
        });

        /** @var WebsiteSection */
        return $this->fresh($section);
    }

    private function publishPage(Page $page, ?string $label, bool $promoting): Page
    {
        $this->connection()->transaction(function () use ($page, $label, $promoting): void {
            $row = $this->lock('pages', (int) $page->getKey());
            $name = $this->label($row, false);

            $this->assertPageWritable($row, $name);

            $scheduledFor = $row->published_at === null ? null : Carbon::parse((string) $row->published_at);

            // §6.4: "published_at = now() unless a future published_at is set (then status = scheduled)".
            // A page that is live now is published immediately instead of being taken down to wait.
            if (! $promoting && $scheduledFor !== null && $scheduledFor->isFuture()
                && (string) $row->status !== ContentStatus::Published->value) {
                if ((string) $row->status !== ContentStatus::Scheduled->value) {
                    $this->connection()->table('pages')->where('id', $row->id)->update([
                        'status' => ContentStatus::Scheduled->value,
                        'updated_at' => Carbon::now(),
                        'updated_by' => $this->auditor->actorId(),
                    ]);

                    $this->auditor->record(
                        module: self::PAGE_MODULE,
                        description: sprintf('Page scheduled: %s', $name),
                        subject: $page,
                        properties: ['old' => ['status' => $row->status], 'attributes' => ['status' => ContentStatus::Scheduled->value, 'published_at' => $scheduledFor->toDateTimeString()]],
                        event: 'scheduled',
                    );
                }

                return;
            }

            $body = RichText::sanitize((string) $row->content);
            $canonical = $this->hasher->pageCanonical((string) $row->content);
            $hash = $this->hasher->hash($canonical);
            $now = Carbon::now();
            $actor = $this->auditor->actorId();
            $publishedAt = $promoting && $scheduledFor !== null ? $scheduledFor : $now;

            $this->connection()->table('pages')->where('id', $row->id)->update([
                'published_content' => $body,
                'content_hash' => $hash,
                'published_hash' => $hash,
                'status' => ContentStatus::Published->value,
                'published_at' => $publishedAt,
                'published_by' => $actor,
                'unpublished_reason' => null,
                'updated_at' => $now,
                'updated_by' => $actor,
            ]);

            $this->revisions->record($page, RevisionEvent::Published, $canonical, label: $label);

            // §6.4: a published page always has its SEO record.
            $this->seo->ensure($page);

            $this->auditor->record(
                module: self::PAGE_MODULE,
                description: sprintf('Page %s: %s', $promoting ? 'published on schedule' : 'published', $name),
                subject: $page,
                properties: $this->auditor->diff(
                    ['status' => $row->status, 'published_hash' => $row->published_hash, 'published_at' => $row->published_at],
                    ['status' => ContentStatus::Published->value, 'published_hash' => $hash, 'published_at' => $publishedAt->toDateTimeString()]
                ),
                event: 'published',
            );

            $this->cache->bumpAfterCommit(sprintf('Page #%d published', $row->id));
            $this->queueSitemap($promoting ? 'scheduled' : 'publish');
        });

        /** @var Page */
        return $this->fresh($page);
    }

    private function assertPageWritable(object $row, string $label): void
    {
        if ($row->deleted_at !== null) {
            throw InvalidSectionContentException::withErrors('That page is in the trash.', ['page' => ['Restore it first.']]);
        }

        if ((string) $row->status === ContentStatus::Archived->value) {
            throw ContentActionNotAllowedException::archived($label);
        }
    }

    private function queueSitemap(string $trigger): void
    {
        $this->connection()->afterCommit(static function () use ($trigger): void {
            $job = self::SITEMAP_JOB;

            if (! class_exists($job)) {
                return; // the sitemap is version-stamped; the bump already invalidated it
            }

            try {
                dispatch(new $job($trigger));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    private function lock(string $table, int $id): object
    {
        $row = $this->connection()->table($table)->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That item no longer exists.', ['id' => ['It may have been deleted in another tab.']]);
        }

        return $row;
    }

    private function label(object $row, bool $isSection): string
    {
        if (! $isSection) {
            return (string) $row->title;
        }

        $name = trim((string) ($row->name ?? ''));
        $key = (string) $row->section_key;

        return $name !== '' ? $name : (SectionRegistry::exists($key) ? SectionRegistry::label($key) : $key);
    }

    /**
     * @template T of WebsiteSection|Page
     *
     * @param  T  $target
     * @return T
     */
    private function fresh(WebsiteSection|Page $target): WebsiteSection|Page
    {
        return $target::query()->withoutGlobalScopes()->findOrFail($target->getKey());
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
