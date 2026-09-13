<?php

declare(strict_types=1);

namespace App\Services\Cms\Exceptions;

use RuntimeException;

/**
 * A CMS business rule — not a permission — forbids this action (the `App\Services\Core\
 * ActionNotAllowedException` of phase-01, for the content side).
 *
 * These are the invariants that hold **even for a Super Admin**, who bypasses every policy through
 * `Gate::before`:
 *
 *   · a required section type (`header`, `hero`, `footer`) may be disabled, never deleted (INV-7)
 *   · a unique section type may not be placed twice (INV: `uq_ws_instance`)
 *   · an `is_system` page may never be deleted (§6.4, FT-17)
 *   · a reserved slug may not be taken (§6.4 `reservedSlugs()`, FT-14)
 *   · a CTA block still in use may not be deleted (§6.13)
 *   · a menu may not nest deeper than two levels, and may not be made its own ancestor (INV-6)
 *
 * Controllers catch it and redirect back with an error toast, so the rule reads as a refusal the user
 * can act on rather than a stack trace.
 */
final class ContentActionNotAllowedException extends RuntimeException
{
    public static function requiredSection(string $label): self
    {
        return new self(sprintf(
            '"%s" is a required part of the site and cannot be deleted — disable it instead.',
            $label
        ));
    }

    public static function uniqueSectionDuplicated(string $label, string $placement): self
    {
        return new self(sprintf('"%s" is already placed in %s and may only appear once there.', $label, $placement));
    }

    public static function notDuplicable(string $label): self
    {
        return new self(sprintf('"%s" may only exist once, so it cannot be duplicated.', $label));
    }

    public static function systemPage(string $title): self
    {
        return new self(sprintf(
            '"%s" is a system page: its content is editable but the page itself cannot be deleted.',
            $title
        ));
    }

    public static function reservedSlug(string $slug, string $reason): self
    {
        return new self(sprintf('The address "/%s" is reserved: %s', $slug, $reason));
    }

    public static function slugTaken(string $slug, bool $trashed): self
    {
        return new self($trashed
            ? sprintf(
                'The address "/%s" belongs to a page in the trash. Restore that page or delete it permanently first.',
                $slug
            )
            : sprintf('The address "/%s" is already used by another page.', $slug));
    }

    public static function ctaInUse(string $name, int $count): self
    {
        return new self(sprintf(
            '"%s" is still used by %d %s and cannot be deleted.',
            $name,
            $count,
            $count === 1 ? 'section' : 'sections'
        ));
    }

    public static function ctaKeyImmutable(string $key): self
    {
        return new self(sprintf(
            'The reference key "%s" cannot be changed while sections point at it.',
            $key
        ));
    }

    public static function menuDepth(): self
    {
        return new self('Menus go two levels deep: a top-level item and its children. No deeper.');
    }

    public static function menuParentHasChildren(string $label): self
    {
        return new self(sprintf(
            '"%s" has child items, so it cannot itself become a child — that would be three levels.',
            $label
        ));
    }

    public static function menuCycle(): self
    {
        return new self('An item cannot be its own parent, or the parent of its own parent.');
    }

    public static function foreignMenuParent(): self
    {
        return new self('The parent item must belong to the same menu.');
    }

    public static function unknownRoute(string $route): self
    {
        return new self(sprintf('There is no application route named "%s".', $route));
    }

    public static function unsafeUrl(string $url): self
    {
        return new self(sprintf(
            'The link "%s" is not an accepted address. Use https://, mailto:, tel:, a path starting with / or an #anchor.',
            $url
        ));
    }

    public static function notPublishable(string $label, string $reason): self
    {
        return new self(sprintf('"%s" cannot be published: %s', $label, $reason));
    }

    public static function revisionBelongsElsewhere(): self
    {
        return new self('That revision belongs to a different item.');
    }

    public static function scheduleMustBeFuture(): self
    {
        return new self('A scheduled publish date must be in the future.');
    }

    public static function archived(string $label): self
    {
        return new self(sprintf('"%s" is archived: restore it before editing.', $label));
    }
}
