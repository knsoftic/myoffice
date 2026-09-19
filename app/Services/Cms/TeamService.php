<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\ImageProfile;
use App\Enums\Cms\MediaCollection;
use App\Enums\SocialPlatform;
use App\Models\Cms\TeamMember;
use App\Services\Cms\Exceptions\ContentRuleException;
use App\Services\Cms\Support\ContentHelper;
use Illuminate\Http\UploadedFile;

/**
 * The public team page (phase-04 §6.4, requirement §13).
 *
 * A team member is website content, not an account: there is no `user_id`, and `employee_id` is a source
 * of defaults for a later phase, never a publication trigger (F-3.13, D32). Publication is the explicit
 * CMS act of `changeStatus()`; `togglePublic()` hides a member without unpublishing.
 *
 * Invariants:
 *
 *   · `skills` is a re-indexed list of trimmed, non-empty strings (max 20, each ≤ 60 characters); an empty
 *     list is stored as null.
 *   · `social_links` keys must be `SocialPlatform` values (an unknown key such as `myspace` is a 422) and
 *     each value an http(s) URL with a host; blank values are dropped and an empty map is stored as null.
 *   · `bio` is plain text (§6.9): tags stripped, escaped by Blade on render.
 *   · The photo is a `media_assets` row (`general` / `Thumbnail`) through `MediaService` (D24).
 *   · Every method is one transaction; a visible change bumps the public cache after commit.
 */
final class TeamService
{
    private const MODULE = 'team';

    private const LABEL = 'Team member';

    public function __construct(
        private readonly ContentHelper $content,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, ?UploadedFile $photo): TeamMember
    {
        return $this->content->transaction(function () use ($data, $photo): TeamMember {
            $member = new TeamMember;
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null) ?? ContentStatus::Draft;

            if ($status === ContentStatus::Scheduled) {
                throw ContentRuleException::cannotSchedule(self::LABEL);
            }

            $member->fill($this->attributes($data, null));
            $member->setAttribute('slug', $manualSlug ?? '');
            $member->setAttribute('status', $status);
            $member->setAttribute('photo_media_id', $this->content->resolveMediaColumn(
                $data, 'photo_media_id', $photo, MediaCollection::General, ImageProfile::Thumbnail, 'photo', null, 'remove_photo',
            ));

            if (! array_key_exists('sort_order', $data)) {
                $member->setAttribute('sort_order', (int) TeamMember::query()->withTrashed()->max('sort_order') + 1);
            }

            $this->content->saveWithSlug($member, $manualSlug !== null);
            $this->content->recountMediaAfterCommit([$member->getAttribute('photo_media_id')]);

            if ($this->isPublic($member)) {
                $this->content->flushPublicCache(sprintf('Team member "%s" added', (string) $member->getAttribute('name')));
            }

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TeamMember $member, array $data, ?UploadedFile $photo): TeamMember
    {
        return $this->content->transaction(function () use ($member, $data, $photo): TeamMember {
            $oldSlug = (string) $member->getAttribute('slug');
            $oldPhoto = $member->getAttribute('photo_media_id');
            $wasPublic = $this->isPublic($member);
            $statusBefore = $member->getAttribute('status');
            $manualSlug = $this->content->manualSlug($data);
            $status = $this->content->contentStatus($data['status'] ?? null);

            $member->fill($this->attributes($data, $member));

            if ($manualSlug !== null) {
                $member->setAttribute('slug', $manualSlug);
            }

            $member->setAttribute('photo_media_id', $this->content->resolveMediaColumn(
                $data, 'photo_media_id', $photo, MediaCollection::General, ImageProfile::Thumbnail, 'photo',
                $oldPhoto === null ? null : (int) $oldPhoto, 'remove_photo',
            ));

            $this->content->saveWithSlug($member, $manualSlug !== null);

            if ((string) $member->getAttribute('slug') !== $oldSlug) {
                $this->content->auditSlugChange(
                    $member,
                    self::MODULE,
                    $oldSlug,
                    (string) $member->getAttribute('slug'),
                    in_array($statusBefore, [ContentStatus::Published, ContentStatus::Archived], true),
                );
            }

            if ($status !== null) {
                $this->content->changeContentStatus($member, $status, self::MODULE, self::LABEL);
            }

            $this->content->recountMediaAfterCommit([$oldPhoto, $member->getAttribute('photo_media_id')]);

            if ($member->getAttribute('status') === $statusBefore && ($wasPublic || $this->isPublic($member))) {
                $this->content->flushPublicCache(sprintf('Team member "%s" updated', (string) $member->getAttribute('name')));
            }

            return $member;
        });
    }

    public function togglePublic(TeamMember $member): TeamMember
    {
        return $this->content->transaction(function () use ($member): TeamMember {
            $this->content->toggleFlag($member, 'is_public', self::MODULE, self::LABEL, 'name', 'shown_on_website', 'hidden_from_website');

            if ($member->getAttribute('status') === ContentStatus::Published) {
                $this->content->flushPublicCache(sprintf('Team member "%s" visibility changed', (string) $member->getAttribute('name')));
            }

            return $member;
        });
    }

    /**
     * Publish, unpublish or archive a card (the `admin.team.status` action).
     */
    public function changeStatus(TeamMember $member, ContentStatus $status): TeamMember
    {
        return $this->content->transaction(
            fn (): TeamMember => $this->content->changeContentStatus($member, $status, self::MODULE, self::LABEL)
        );
    }

    public function delete(TeamMember $member): void
    {
        $this->content->transaction(function () use ($member): void {
            $wasPublic = $this->isPublic($member);

            $member->delete();

            if ($wasPublic) {
                $this->content->flushPublicCache(sprintf('Team member "%s" deleted', (string) $member->getAttribute('name')));
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?TeamMember $member): array
    {
        $attributes = [];
        $creating = $member === null;

        foreach (['name' => 'A name is required.', 'designation' => 'A designation is required.'] as $column => $message) {
            if ($creating || array_key_exists($column, $data)) {
                $value = $this->content->plain($data[$column] ?? null, 150);

                if ($value === null) {
                    throw ContentRuleException::refuse($column, $message);
                }

                $attributes[$column] = $value;
            }
        }

        foreach (['department' => 100, 'experience_label' => 100] as $column => $limit) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->plain($data[$column], $limit);
            }
        }

        foreach (['department_id', 'employee_id'] as $column) {
            if (array_key_exists($column, $data)) {
                $attributes[$column] = $this->content->id($data[$column]);
            }
        }

        if (array_key_exists('bio', $data)) {
            $attributes['bio'] = $this->content->plain($data['bio']);
        }

        if (array_key_exists('skills', $data)) {
            $attributes['skills'] = $this->content->stringList($data['skills'], TeamMember::MAX_SKILLS, TeamMember::MAX_SKILL_LENGTH, 'skills');
        }

        if (array_key_exists('experience_years', $data)) {
            $years = $data['experience_years'];

            if ($years !== null && $years !== '' && (! is_numeric($years) || (int) $years < 0 || (int) $years > TeamMember::MAX_EXPERIENCE_YEARS)) {
                throw ContentRuleException::refuse('experience_years', sprintf('Experience must be between 0 and %d years.', TeamMember::MAX_EXPERIENCE_YEARS));
            }

            $attributes['experience_years'] = $this->content->int($years);
        }

        if (array_key_exists('social_links', $data)) {
            $attributes['social_links'] = $this->socialLinks($data['social_links']);
        }

        if (array_key_exists('portfolio_url', $data)) {
            $attributes['portfolio_url'] = $this->content->url($data['portfolio_url'], 'portfolio_url');
        }

        if ($creating || array_key_exists('is_public', $data)) {
            $attributes['is_public'] = $this->content->bool($data['is_public'] ?? null, true);
        }

        if (array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $this->content->int($data['sort_order'], 0, 0);
        }

        return $attributes;
    }

    /**
     * @return array<string, string>|null
     */
    private function socialLinks(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw ContentRuleException::refuse('social_links', 'Provide one link per platform.');
        }

        $allowed = SocialPlatform::values();
        $links = [];

        foreach ($value as $key => $url) {
            $key = (string) $key;

            if (! in_array($key, $allowed, true)) {
                throw ContentRuleException::unknownSocialPlatform($key);
            }

            $clean = $this->content->url($url, 'social_links.'.$key);

            if ($clean !== null) {
                $links[$key] = $clean;
            }
        }

        // Stored in the enum's declaration order, so the public icon row is stable.
        $ordered = [];

        foreach ($allowed as $platform) {
            if (isset($links[$platform])) {
                $ordered[$platform] = $links[$platform];
            }
        }

        return $ordered === [] ? null : $ordered;
    }

    private function isPublic(TeamMember $member): bool
    {
        return $member->getAttribute('status') === ContentStatus::Published
            && (bool) $member->getAttribute('is_public')
            && ! $member->trashed();
    }
}
