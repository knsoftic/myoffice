<?php

declare(strict_types=1);

namespace App\Support\Portal\Sections;

use App\Contracts\Portal\ClientPortalSection;
use App\Enums\ClientDocumentCategory;
use App\Models\Crm\Client;
use App\Models\Crm\ClientDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client panel's Documents screen — Phase 5's own section (phase-05 §8.10, §9.2, test 67).
 *
 * Exactly `client_documents.client_id = $C AND visible_to_client = 1 AND deleted_at IS NULL`, where `$C` is the
 * `Client` resolved by `ClientContext`, never a request value. The column list omits the storage path, the disk,
 * the checksum and every staff-only field.
 */
final class DocumentsSection implements ClientPortalSection
{
    /** Columns a client may see. */
    public const COLUMNS = ['id', 'client_id', 'title', 'category', 'description', 'original_name', 'mime_type', 'extension', 'size_bytes', 'shared_at', 'valid_from', 'expires_at', 'created_at'];

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Documents';
    }

    public function icon(): string
    {
        return 'paper-clip';
    }

    public function module(): ?string
    {
        return 'client_documents';
    }

    public function permission(): string
    {
        return 'client_portal.documents';
    }

    public function sort(): int
    {
        return 60;
    }

    public function badgeCount(Client $client): ?int
    {
        return $this->query($client)->count();
    }

    /**
     * Filters: `category`, `q`, `per_page`.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, ClientDocument>
     */
    public function paginate(Client $client, array $filters): LengthAwarePaginator
    {
        $query = $this->query($client)->select(self::COLUMNS);

        $category = is_string($filters['category'] ?? null) ? ClientDocumentCategory::tryFrom($filters['category']) : null;

        if ($category instanceof ClientDocumentCategory) {
            $query->where('category', $category->value);
        }

        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';

        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($search, 0, 100)).'%';

            $query->where(static function (Builder $inner) use ($like): void {
                $inner->where('title', 'like', $like)->orWhere('original_name', 'like', $like);
            });
        }

        $perPage = is_numeric($filters['per_page'] ?? null) ? max(5, min(100, (int) $filters['per_page'])) : 20;

        return $query->orderByDesc('shared_at')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function view(): string
    {
        return 'client.documents.index';
    }

    /**
     * @return Builder<ClientDocument>
     */
    public function query(Client $client): Builder
    {
        return ClientDocument::query()
            ->where('client_id', $client->getKey())
            ->where('visible_to_client', true);
    }
}
