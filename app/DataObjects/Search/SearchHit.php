<?php

declare(strict_types=1);

namespace App\DataObjects\Search;

use App\Enums\SearchEntityType;

/**
 * One result in the palette (phase-19-23 §6.23, §108).
 *
 * **A hit whose detail route the viewer cannot open is rendered without a link, never dropped.**
 * That is the contract's rule and it is worth stating where the object is defined, because the
 * lazy reading — filter it out — is wrong in a way that is hard to notice: the count would then
 * disagree with the rows, and somebody searching for a student they know exists would be told
 * there are no results, which reads as "no such student" rather than "not for you".
 *
 * So `url` is nullable and null means exactly one thing: this record matched, and you may see that
 * it matched, and you may not open it.
 */
final readonly class SearchHit
{
    /**
     * @param  array<string, string|null>  $meta  extra labelled details shown under the subtitle
     */
    public function __construct(
        public SearchEntityType $type,
        public int|string $id,
        public string $title,
        public ?string $subtitle = null,
        public ?string $badge = null,
        public ?string $badgeColor = null,
        public array $meta = [],
        public ?string $url = null,
        public ?string $icon = null,
    ) {}

    /** Can the viewer follow this hit to the record? */
    public function isLinked(): bool
    {
        return $this->url !== null && $this->url !== '';
    }

    /** The same hit with its link removed — what a provider returns for a record the viewer may see but not open. */
    public function withoutLink(): self
    {
        return new self(
            type: $this->type,
            id: $this->id,
            title: $this->title,
            subtitle: $this->subtitle,
            badge: $this->badge,
            badgeColor: $this->badgeColor,
            meta: $this->meta,
            url: null,
            icon: $this->icon,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'badge' => $this->badge,
            'badge_color' => $this->badgeColor,
            'meta' => array_filter($this->meta, static fn (mixed $v): bool => $v !== null && $v !== ''),
            'url' => $this->url,
            'linked' => $this->isLinked(),
            'icon' => $this->icon ?? $this->type->icon(),
        ];
    }
}
