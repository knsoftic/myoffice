<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Enums\Cms\ButtonStyle;
use App\Enums\Cms\ContentStatus;
use App\Enums\Cms\CtaVariant;
use App\Enums\Cms\SectionPlacement;
use App\Models\Cms\CtaBlock;
use App\Services\Cms\Exceptions\ContentActionNotAllowedException;
use App\Services\Cms\Exceptions\InvalidSectionContentException;
use App\Support\Cms\SectionRegistry;
use App\Support\RichText;
use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reusable call-to-action blocks (phase-03 §2.8, §6.13 `CtaBlockService`, §8.11).
 *
 * One public entry point per operation:
 *
 *   save()     create a block, or update one — including the partial `['status' => ...]` of the toggle route
 *   usage()    the sections (home, global or on a page) that reference the block
 *   delete()   soft-delete a block nothing references
 *   recount()  refresh the `usage_count` cache the delete guard and the policy read
 *
 * Invariants:
 *
 *   · **Referenced, never copied (INV-3).** Sections point at a block through
 *     `website_sections.cta_block_id`; this class never writes JSON references.
 *   · **The key is immutable while referenced** (§6.13): a key change is refused while any live
 *     section points at the block — for a Super Admin too, who bypasses `CtaBlockPolicy::changeKey()`.
 *   · **Delete is refused while in use** (§6.13, M-7), with the live count recomputed under the row lock;
 *     a delete is a soft delete (INV-14).
 *   · **Background image and colour are mutually exclusive**: the one set by this save clears the other;
 *     when both arrive unchanged the image wins (§2.8 "ignored when a background image is set").
 *   · **Safe hrefs only** (§6.6): button URLs pass `RichText::isSafeHref()`. `description` is plain text.
 *   · **Closed keys, typed values.** A key outside `WRITABLE` is refused; statuses are `ContentStatus`
 *     values other than `scheduled` (only pages schedule).
 *   · **INV-16.** Every effective change is audited with old and new values and bumps the public cache
 *     after commit; a save that changes nothing writes nothing.
 */
final class CtaBlockService
{
    /** @var list<string> */
    public const WRITABLE = [
        'key', 'name', 'variant', 'heading', 'subheading', 'description',
        'primary_label', 'primary_url', 'primary_style', 'primary_new_tab',
        'secondary_label', 'secondary_url', 'secondary_style', 'secondary_new_tab',
        'background_media_id', 'background_color', 'status',
    ];

    public const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    private const MODULE = 'website_cta_blocks';

    /** @var array<string, int> column => max characters (the column widths of §2.8) */
    private const LIMITS = [
        'key' => 64,
        'name' => 150,
        'heading' => 200,
        'subheading' => 300,
        'description' => 5000,
        'primary_label' => 60,
        'primary_url' => 500,
        'secondary_label' => 60,
        'secondary_url' => 500,
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly MediaService $media,
        private readonly CacheVersion $cache,
        private readonly CmsAuditor $auditor,
    ) {}

    /**
     * Create a block (`$block` null) or update one. On update only the keys present change.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidSectionContentException for malformed input
     * @throws ContentActionNotAllowedException for a key change while the block is referenced
     */
    public function save(array $data, ?CtaBlock $block = null): CtaBlock
    {
        $this->assertKnownKeys($data);

        $assets = [];

        $id = $this->connection()->transaction(function () use ($data, $block, &$assets): int {
            $row = $block === null ? null : $this->lock((int) $block->getKey());

            if ($row !== null && $row->deleted_at !== null) {
                throw InvalidSectionContentException::withErrors('That CTA block is in the trash.', [
                    'cta_block' => ['Restore it before editing.'],
                ]);
            }

            $values = $this->clean($data, $row === null);
            $values = $this->resolveBackground($values, $data, $row);

            if (array_key_exists('key', $values) && ($row === null || $values['key'] !== (string) $row->key)) {
                if ($row !== null && $this->liveUsageCount((int) $row->id) > 0) {
                    throw ContentActionNotAllowedException::ctaKeyImmutable((string) $row->key);
                }

                $this->assertKeyAvailable((string) $values['key'], $row === null ? null : (int) $row->id);
            }

            return $row === null
                ? $this->insert($values, $assets)
                : $this->update($row, $values, $assets);
        });

        $this->recountAssets($assets);

        return $this->find($id);
    }

    /**
     * The live places that reference the block: one entry per section (§8.11 popover, the delete
     * refusal). Keyed `section:{id}`; call `values()` for a list.
     *
     * @return Collection<string, array{type: string, id: int, label: string, detail: string|null}>
     */
    public function usage(CtaBlock $block): Collection
    {
        $rows = $this->connection()->table('website_sections as s')
            ->leftJoin('pages as p', 'p.id', '=', 's.page_id')
            ->where('s.cta_block_id', $block->getKey())
            ->whereNull('s.deleted_at')
            ->orderBy('s.placement')->orderBy('s.page_id')->orderBy('s.sort_order')->orderBy('s.id')
            ->get(['s.id', 's.section_key', 's.name', 's.placement', 's.status', 's.is_enabled', 'p.title as page_title']);

        $places = [];

        foreach ($rows as $row) {
            $key = (string) $row->section_key;
            $name = trim((string) ($row->name ?? ''));
            $placement = SectionPlacement::tryFrom((string) $row->placement);
            $where = $placement === SectionPlacement::Page && $row->page_title !== null
                ? sprintf('Page: %s', $row->page_title)
                : ($placement?->label() ?? (string) $row->placement);
            $status = ContentStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status;

            $places['section:'.$row->id] = [
                'type' => 'section',
                'id' => (int) $row->id,
                'label' => $name !== '' ? $name : (SectionRegistry::exists($key) ? SectionRegistry::label($key) : $key),
                'detail' => sprintf('%s · %s%s', $where, $status, (bool) $row->is_enabled ? '' : ' · disabled'),
            ];
        }

        return collect($places);
    }

    /**
     * Soft-delete a block nothing references (§6.13).
     *
     * @throws ContentActionNotAllowedException while any live section still points at it
     */
    public function delete(CtaBlock $block): void
    {
        $assets = [];

        $refusal = $this->connection()->transaction(function () use ($block, &$assets): ?ContentActionNotAllowedException {
            $row = $this->lock((int) $block->getKey());

            if ($row->deleted_at !== null) {
                return null;
            }

            $count = $this->liveUsageCount((int) $row->id);

            if ((int) $row->usage_count !== $count) {
                $this->connection()->table('cta_blocks')->where('id', $row->id)->update(['usage_count' => $count]);
            }

            if ($count > 0) {
                // Refuse once the transaction has ended, so the refreshed cache the policy reads is kept.
                return ContentActionNotAllowedException::ctaInUse((string) $row->name, $count);
            }

            $now = Carbon::now();

            $this->connection()->table('cta_blocks')->where('id', $row->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'updated_by' => $this->auditor->actorId(),
            ]);

            $this->auditor->record(
                module: self::MODULE,
                description: sprintf('CTA block deleted: %s', $row->name),
                subject: $block,
                properties: ['old' => ['deleted_at' => null, 'key' => $row->key], 'attributes' => ['deleted_at' => $now->toDateTimeString()]],
                event: 'deleted',
            );

            $this->cache->bumpAfterCommit(sprintf('CTA block #%d deleted', $row->id));

            $assets = array_filter([$row->background_media_id]);

            return null;
        });

        if ($refusal !== null) {
            $block->setAttribute('usage_count', $this->liveUsageCount((int) $block->getKey()));
            $block->syncOriginalAttribute('usage_count');

            throw $refusal;
        }

        $this->recountAssets($assets);
    }

    /**
     * Refresh `usage_count` = live sections referencing the block (§6.13 `recount()`): for one block, a
     * set of ids, or (no argument) every block in one grouped query.
     *
     * @param  CtaBlock|array<int, int|string>|null  $blocks
     */
    public function recount(CtaBlock|array|null $blocks = null): void
    {
        $ids = match (true) {
            $blocks instanceof CtaBlock => [(int) $blocks->getKey()],
            is_array($blocks) => array_values(array_unique(array_filter(array_map('intval', $blocks)))),
            default => null,
        };

        if ($ids === []) {
            return;
        }

        $counts = $this->connection()->table('website_sections')
            ->whereNotNull('cta_block_id')->whereNull('deleted_at')
            ->when($ids !== null, static fn ($query) => $query->whereIn('cta_block_id', $ids))
            ->groupBy('cta_block_id')
            ->selectRaw('cta_block_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'cta_block_id')
            ->all();

        $rows = $this->connection()->table('cta_blocks')
            ->when($ids !== null, static fn ($query) => $query->whereIn('id', $ids))
            ->get(['id', 'usage_count']);

        foreach ($rows as $row) {
            $count = (int) ($counts[$row->id] ?? 0);

            if ((int) $row->usage_count !== $count) {
                $this->connection()->table('cta_blocks')->where('id', $row->id)->update(['usage_count' => $count]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $values
     * @param  list<int|null>  $assets
     */
    private function insert(array $values, array &$assets): int
    {
        $now = Carbon::now();
        $actor = $this->auditor->actorId();

        $row = array_merge([
            'variant' => CtaVariant::Banner->value,
            'subheading' => null,
            'description' => null,
            'primary_label' => null,
            'primary_url' => null,
            'primary_style' => ButtonStyle::Primary->value,
            'primary_new_tab' => false,
            'secondary_label' => null,
            'secondary_url' => null,
            'secondary_style' => ButtonStyle::Outline->value,
            'secondary_new_tab' => false,
            'background_media_id' => null,
            'background_color' => null,
            'status' => ContentStatus::Draft->value,
        ], $values, [
            'usage_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actor,
            'updated_by' => $actor,
        ]);

        try {
            $id = (int) $this->connection()->table('cta_blocks')->insertGetId($row);
        } catch (UniqueConstraintViolationException) {
            throw $this->keyTaken();
        }

        $this->auditor->record(
            module: self::MODULE,
            description: sprintf('CTA block created: %s', $row['name']),
            subject: $this->find($id),
            properties: ['attributes' => array_intersect_key($row, array_flip(self::WRITABLE))],
            event: 'created',
        );

        $assets[] = $row['background_media_id'];

        return $id;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<int|null>  $assets
     */
    private function update(object $row, array $values, array &$assets): int
    {
        $changes = [];

        foreach ($values as $column => $value) {
            if ($this->comparable($row->{$column} ?? null) !== $this->comparable($value)) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return (int) $row->id;
        }

        try {
            $this->connection()->table('cta_blocks')->where('id', $row->id)->update(array_merge($changes, [
                'updated_at' => Carbon::now(),
                'updated_by' => $this->auditor->actorId(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw $this->keyTaken();
        }

        $onlyStatus = array_keys($changes) === ['status'];

        $this->auditor->record(
            module: self::MODULE,
            description: $onlyStatus
                ? sprintf('CTA block status changed: %s', $row->name)
                : sprintf('CTA block updated: %s', $changes['name'] ?? $row->name),
            subject: $this->find((int) $row->id),
            properties: $this->auditor->diff(
                array_intersect_key((array) $row, $changes),
                $changes
            ),
            event: $onlyStatus ? 'status_changed' : 'updated',
        );

        $this->cache->bumpAfterCommit(sprintf('CTA block #%d changed', $row->id));

        if (array_key_exists('background_media_id', $changes)) {
            $assets[] = $row->background_media_id === null ? null : (int) $row->background_media_id;
            $assets[] = $changes['background_media_id'];
        }

        return (int) $row->id;
    }

    /**
     * Validate and normalise the keys present. Creating requires `key`, `name` and `heading`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data, bool $creating): array
    {
        $errors = [];
        $values = [];

        foreach (['key', 'name', 'heading'] as $required) {
            if ($creating && ! array_key_exists($required, $data)) {
                $errors[$required][] = 'This field is required.';
            }
        }

        foreach ($data as $column => $value) {
            switch ($column) {
                case 'key':
                case 'name':
                case 'heading':
                    $text = $this->text($value);

                    if ($text === null) {
                        $errors[$column][] = 'This field is required.';
                    } elseif (mb_strlen($text) > self::LIMITS[$column]) {
                        $errors[$column][] = sprintf('At most %d characters.', self::LIMITS[$column]);
                    } elseif ($column === 'key' && preg_match(self::KEY_PATTERN, $text) !== 1) {
                        $errors[$column][] = 'Use lowercase letters, digits and underscores (for example free_consultation).';
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'subheading':
                case 'description':
                case 'primary_label':
                case 'secondary_label':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'This must be text.';

                        break;
                    }

                    $text = $this->text($value);

                    if ($text !== null && mb_strlen($text) > self::LIMITS[$column]) {
                        $errors[$column][] = sprintf('At most %d characters.', self::LIMITS[$column]);
                    } else {
                        $values[$column] = $text;
                    }

                    break;

                case 'primary_url':
                case 'secondary_url':
                    $url = is_string($value) || $value === null ? $this->text($value) : false;

                    if ($url === false) {
                        $errors[$column][] = 'This must be text.';
                    } elseif ($url !== null && (mb_strlen($url) > self::LIMITS[$column] || ! RichText::isSafeHref($url))) {
                        $errors[$column][] = 'Use https://, mailto:, tel:, a path starting with / or an #anchor (at most 500 characters).';
                    } else {
                        $values[$column] = $url;
                    }

                    break;

                case 'primary_style':
                case 'secondary_style':
                    $style = $value === null || $value === ''
                        ? ($column === 'primary_style' ? ButtonStyle::Primary : ButtonStyle::Outline)
                        : ($value instanceof ButtonStyle ? $value : ButtonStyle::tryFrom($this->scalar($value)));

                    if ($style === null) {
                        $errors[$column][] = 'Choose one of the listed button styles.';
                    } else {
                        $values[$column] = $style->value;
                    }

                    break;

                case 'primary_new_tab':
                case 'secondary_new_tab':
                    $flag = $this->bool($value);

                    if ($flag === null) {
                        $errors[$column][] = 'This must be true or false.';
                    } else {
                        $values[$column] = $flag;
                    }

                    break;

                case 'variant':
                    $variant = $value instanceof CtaVariant ? $value : CtaVariant::tryFrom($this->scalar($value));

                    if ($variant === null) {
                        $errors[$column][] = 'Choose one of the listed layouts.';
                    } else {
                        $values[$column] = $variant->value;
                    }

                    break;

                case 'background_media_id':
                    if ($value === null || $value === '') {
                        $values[$column] = null;

                        break;
                    }

                    $assetId = $this->positiveInt($value);
                    $mime = $assetId === null ? null : $this->connection()->table('media_assets')
                        ->where('id', $assetId)->whereNull('deleted_at')->value('mime_type');

                    if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
                        $errors[$column][] = 'Choose an image from the media library.';
                    } else {
                        $values[$column] = $assetId;
                    }

                    break;

                case 'background_color':
                    if ($value !== null && ! is_string($value)) {
                        $errors[$column][] = 'Use a hex colour such as #1d4ed8.';

                        break;
                    }

                    $color = $this->text($value);

                    if ($color !== null && preg_match(SectionValidator::COLOR_PATTERN, $color) !== 1) {
                        $errors[$column][] = 'Use a hex colour such as #1d4ed8.';
                    } else {
                        $values[$column] = $color === null ? null : strtolower($color);
                    }

                    break;

                case 'status':
                    $status = $value instanceof ContentStatus ? $value : ContentStatus::tryFrom($this->scalar($value));

                    if ($status === null || $status === ContentStatus::Scheduled) {
                        $errors[$column][] = 'Choose draft, published or archived.';
                    } else {
                        $values[$column] = $status->value;
                    }

                    break;
            }
        }

        if ($errors !== []) {
            throw InvalidSectionContentException::withErrors('The CTA block could not be saved.', $errors);
        }

        return $values;
    }

    /**
     * §2.8 / §6.13: background image and colour are mutually exclusive — the one this save sets clears
     * the other; when both are present and neither changed, the image wins.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBackground(array $values, array $data, ?object $row): array
    {
        $storedImage = $row?->background_media_id === null ? null : (int) $row->background_media_id;
        $storedColor = $row?->background_color === null ? null : strtolower((string) $row->background_color);

        $image = array_key_exists('background_media_id', $values) ? $values['background_media_id'] : $storedImage;
        $color = array_key_exists('background_color', $values) ? $values['background_color'] : $storedColor;

        if ($image === null || $color === null) {
            return $values;
        }

        $imageSet = array_key_exists('background_media_id', $data) && $image !== $storedImage;
        $colorSet = array_key_exists('background_color', $data) && $color !== $storedColor;

        if ($colorSet && ! $imageSet) {
            $values['background_media_id'] = null;
        } else {
            $values['background_color'] = null;
        }

        return $values;
    }

    private function assertKeyAvailable(string $key, ?int $exceptId): void
    {
        $taken = $this->connection()->table('cta_blocks')
            ->where('key', $key)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw $this->keyTaken();
        }
    }

    private function keyTaken(): InvalidSectionContentException
    {
        return InvalidSectionContentException::withErrors('That key is already used.', [
            'key' => ['Another CTA block (possibly one in the trash) already uses this key.'],
        ]);
    }

    private function liveUsageCount(int $id): int
    {
        return $this->connection()->table('website_sections')
            ->where('cta_block_id', $id)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * @param  array<int, mixed>  $assets
     */
    private function recountAssets(array $assets): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assets))));

        if ($ids !== []) {
            $this->media->recountUsage($ids);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertKnownKeys(array $data): void
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($data)), self::WRITABLE));

        if ($unknown !== []) {
            throw InvalidSectionContentException::withErrors(
                sprintf('Unknown CTA block fields: %s.', implode(', ', $unknown)),
                ['cta_block' => [sprintf('These fields cannot be written here: %s.', implode(', ', $unknown))]]
            );
        }
    }

    private function lock(int $id): object
    {
        $row = $this->connection()->table('cta_blocks')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw InvalidSectionContentException::withErrors('That CTA block no longer exists.', [
                'cta_block' => ['It may have been deleted in another tab.'],
            ]);
        }

        return $row;
    }

    private function find(int $id): CtaBlock
    {
        /** @var CtaBlock */
        return CtaBlock::query()->withoutGlobalScopes()->findOrFail($id);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace("\r\n", "\n", trim($value));

        return $value === '' ? null : $value;
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function comparable(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }

    private function connection(): Connection
    {
        return $this->db->connection();
    }
}
