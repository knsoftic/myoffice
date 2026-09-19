<?php

declare(strict_types=1);

namespace App\DataObjects\Crm;

use App\DataObjects\Crm\Concerns\ReadsInput;
use App\Enums\ClientDocumentCategory;
use InvalidArgumentException;

/**
 * The metadata of a client document (phase-05 §2.9, §6.8 `upload()`).
 *
 * `visibleToClient` null means "use the default": `crm.client_visible_documents_default` or the category's
 * `defaultVisibleToClient()`. Dates are calendar dates (`Y-m-d`).
 */
final readonly class DocumentData
{
    use ReadsInput;

    public function __construct(
        public string $title,
        public ClientDocumentCategory $category,
        public ?string $description = null,
        public ?string $validFrom = null,
        public ?string $expiresAt = null,
        public ?bool $visibleToClient = null,
    ) {}

    /**
     * Keys: `title`, `category`, `description`, `valid_from`, `expires_at`, `visible_to_client`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $category = self::enum($data, 'category', ClientDocumentCategory::class);

        if (! $category instanceof ClientDocumentCategory) {
            throw new InvalidArgumentException('A document needs a category.');
        }

        return new self(
            title: (string) self::str($data, 'title', 150),
            category: $category,
            description: self::str($data, 'description', 255),
            validFrom: self::date($data, 'valid_from'),
            expiresAt: self::date($data, 'expires_at'),
            visibleToClient: self::nullableBool($data, 'visible_to_client'),
        );
    }
}
