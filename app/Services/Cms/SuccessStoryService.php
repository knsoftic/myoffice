<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Models\Cms\SuccessStory;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use App\Support\Cms\VideoUrl;
use Illuminate\Http\UploadedFile;

/**
 * Student success stories (phase-04 §6.4, requirement §92) — staff-authored, so there is no approval
 * queue: a story is a draft until it is published.
 *
 * Invariants: `story` is rich text through `RichText::sanitize()` (max 20,000 characters of source);
 * `video_url` is YouTube or Vimeo only and is rendered as an embed built from the parsed id; the photo is a
 * `media_assets` row (`general` / `Thumbnail`); `changeStatus()` writes one activity entry and a story is
 * never `scheduled`; `course_id` / `student_id` are deferred links (§2.1) beside the `course_name` /
 * `student_name` snapshots the site renders. Every method is one transaction.
 */
final class SuccessStoryService
{
    private const MODULE = 'success_stories';

    private const LABEL = 'Success story';

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, ?UploadedFile $photo): SuccessStory
    {
        return $this->content->transaction(function () use ($data, $photo): SuccessStory {
            $story = new SuccessStory;
            $status = $this->content->contentStatus($data['status'] ?? null) ?? ContentStatus::Draft;

            if ($status === ContentStatus::Scheduled) {
                throw ContentRuleException::cannotSchedule(self::LABEL);
            }

            $story->fill($this->attributes($data, null));
            $story->setAttribute('status', $status);
            $story->setAttribute('photo_media_id', $this->content->resolveMediaColumn(
                $data, 'photo_media_id', $photo, MediaCollection::General, ImageProfile::Thumbnail, 'photo', null, 'remove_photo',
            ));

            if (! array_key_exists('sort_order', $data)) {
                $story->setAttribute('sort_order', (int) SuccessStory::query()->withTrashed()->max('sort_order') + 1);
            }

            $story->save();

            $this->content->recountMediaAfterCommit([$story->getAttribute('photo_media_id')]);

            if ($status->isPublic()) {
                $this->content->flushPublicCache(sprintf('Success story of "%s" published', (string) $story->getAttribute('student_name')));
            }

            return $story;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SuccessStory $story, array $data, ?UploadedFile $photo): SuccessStory
    {
        return $this->content->transaction(function () use ($story, $data, $photo): SuccessStory {
            $oldPhoto = $story->getAttribute('photo_media_id');
            $statusBefore = $story->getAttribute('status');
            $wasPublic = $this->isPublic($story);
            $status = $this->content->contentStatus($data['status'] ?? null);

            $story->fill($this->attributes($data, $story));
            $story->setAttribute('photo_media_id', $this->content->resolveMediaColumn(
                $data, 'photo_media_id', $photo, MediaCollection::General, ImageProfile::Thumbnail, 'photo',
                $oldPhoto === null ? null : (int) $oldPhoto, 'remove_photo',
            ));
            $story->save();

            if ($status !== null) {
                $this->content->changeContentStatus($story, $status, self::MODULE, self::LABEL, 'student_name', null, null, false);
            }

            $this->content->recountMediaAfterCommit([$oldPhoto, $story->getAttribute('photo_media_id')]);

            if ($story->getAttribute('status') === $statusBefore && ($wasPublic || $this->isPublic($story))) {
                $this->content->flushPublicCache(sprintf('Success story of "%s" updated', (string) $story->getAttribute('student_name')));
            }

            return $story;
        });
    }

    public function changeStatus(SuccessStory $story, ContentStatus $status): SuccessStory
    {
        return $this->content->transaction(function () use ($story, $status): SuccessStory {
            if ($status === ContentStatus::Published && trim((string) $story->getAttribute('story')) === '') {
                throw ContentRuleException::refuse('story', 'Write the story before publishing it.');
            }

            // A story has no slug and no detail route (§2.12); its name for the publish gate is the student's.
            return $this->content->changeContentStatus($story, $status, self::MODULE, self::LABEL, 'student_name', null, null, false);
        });
    }

    public function toggleFeatured(SuccessStory $story): SuccessStory
    {
        return $this->content->transaction(function () use ($story): SuccessStory {
            $this->content->toggleFlag($story, 'is_featured', self::MODULE, self::LABEL, 'student_name', 'featured', 'unfeatured');

            if ($this->isPublic($story)) {
                $this->content->flushPublicCache(sprintf('Success story of "%s" featured toggled', (string) $story->getAttribute('student_name')));
            }

            return $story;
        });
    }

    public function delete(SuccessStory $story): void
    {
        $this->content->transaction(function () use ($story): void {
            $wasPublic = $this->isPublic($story);

            $story->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Success story of "%s" deleted', (string) $story->getAttribute('student_name')));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?SuccessStory $story): array
    {
        $attributes = [];
        $creating = $story === null;

        if ($creating || array_key_exists('student_name', $data)) {
            $name = $this->content->plain($data['student_name'] ?? null, 150);

            if ($name === null) {
                throw ContentRuleException::refuse('student_name', 'The student\'s name is required.');
            }

            $attributes['student_name'] = $name;
        }

        if ($creating || array_key_exists('story', $data)) {
            $raw = is_scalar($data['story'] ?? null) ? (string) $data['story'] : '';

            if (mb_strlen($raw) > SuccessStory::MAX_STORY_LENGTH) {
                throw ContentRuleException::refuse('story', sprintf('The story may be at most %d characters.', SuccessStory::MAX_STORY_LENGTH));
            }

            $html = $this->content->rich($raw);

            if ($html === null) {
                throw ContentRuleException::refuse('story', 'The story is required.');
            }

            $attributes['story'] = $html;
        }

        foreach (['course_name' => 150, 'headline' => 180, 'achievement' => 255, 'company_name' => 150, 'platform' => 100] as $column => $limit) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->plain($data[$column], $limit);
            }
        }

        foreach (['student_id', 'course_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->id($data[$column]);
            }
        }

        if (array_key_exists('video_url', $data)) {
            $url = $this->content->plain($data['video_url'], 255);

            if ($url !== null && ! VideoUrl::isAllowed($url)) {
                throw ContentRuleException::videoHostNotAllowed();
            }

            $attributes['video_url'] = $url;
        }

        if ($creating || array_key_exists('is_featured', $data)) {
            $attributes['is_featured'] = $this->content->bool($data['is_featured'] ?? null, false);
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    private function isPublic(SuccessStory $story): bool
    {
        return $story->getAttribute('status') === ContentStatus::Published && ! $story->trashed();
    }
}
