<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Enums\ClientDocumentCategory;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A private document of a client (phase-05 §2.9, requirement §19, §96, §111).
 *
 * **Private by default ([D-P5-10], D21).** The file lives on the private disk named by `disk` (`local`) at a hashed
 * `path` under `clients/{client_id}/documents/`. `disk` and `path` are hidden from serialisation and never reach a
 * response: downloads are streamed by `ClientDocumentService::download()` after `ClientDocumentPolicy::download`
 * (staff) or `::downloadAsClient` (portal).
 *
 * **`visible_to_client` is the only portal gate** and has no effect on staff (§9.1). `shared_at` / `shared_by` are
 * stamped the first time it is switched on.
 *
 * Mass assignable: the upload / edit form (`title`, `category`, `description`, `valid_from`, `expires_at`). The
 * client, every fact about the stored file (`disk`, `path`, `original_name`, the sniffed `mime_type`, `extension`,
 * `size_bytes`, `checksum`) and the visibility trio are written by `ClientDocumentService` with `forceFill()`.
 * A soft delete keeps the file (a restore must work); the service's force delete removes it.
 *
 * @property int $id
 * @property int $client_id
 * @property string $title
 * @property ClientDocumentCategory $category
 * @property string|null $description
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property string|null $checksum
 * @property bool $visible_to_client
 * @property Carbon|null $shared_at
 * @property int|null $shared_by
 * @property Carbon|null $valid_from
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ClientDocument extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /** §8.9: the amber expiry badge starts this many days before `expires_at`. */
    public const EXPIRY_WARNING_DAYS = 30;

    protected $table = 'client_documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'category',
        'description',
        'valid_from',
        'expires_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'disk',
        'path',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'disk' => 'local',
        'visible_to_client' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'category' => ClientDocumentCategory::class,
            'size_bytes' => 'integer',
            'visible_to_client' => 'boolean',
            'shared_at' => 'datetime',
            'shared_by' => 'integer',
            'valid_from' => 'date',
            'expires_at' => 'date',
        ];
    }

    public function moduleSlug(): string
    {
        return 'client_documents';
    }

    protected function activityModule(): ?string
    {
        return 'client_documents';
    }

    /**
     * What a reviewer needs to reconstruct the document's history (test 86: visibility with old and new). The
     * storage location is never logged.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'client_id', ...$this->getFillable(),
            'original_name', 'mime_type', 'size_bytes', 'checksum', 'visible_to_client', 'shared_at', 'shared_by',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isVisibleToClient(): bool
    {
        return (bool) $this->getAttribute('visible_to_client');
    }

    public function belongsToClient(Client|int|null $client): bool
    {
        $id = $client instanceof Client ? $client->getKey() : $client;

        return $id !== null && (int) $this->getAttribute('client_id') === (int) $id;
    }

    /**
     * Past its `expires_at` date (the rose badge).
     */
    public function isExpired(?CarbonInterface $today = null): bool
    {
        $expiresAt = $this->expires_at;

        return $expiresAt instanceof CarbonInterface
            && $expiresAt->copy()->startOfDay()->lt(($today ?? now())->copy()->startOfDay());
    }

    /**
     * Not yet expired, but within `$days` of it (the amber badge).
     */
    public function expiresWithin(int $days = self::EXPIRY_WARNING_DAYS, ?CarbonInterface $today = null): bool
    {
        $expiresAt = $this->expires_at;

        if (! $expiresAt instanceof CarbonInterface || $this->isExpired($today)) {
            return false;
        }

        $start = ($today ?? now())->copy()->startOfDay();

        return $expiresAt->copy()->startOfDay()->lte($start->copy()->addDays(max(0, $days)));
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sharedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * §9.2 "Documents" for a client: `client_id = $C AND visible_to_client = 1` (the soft-delete scope supplies
     * `deleted_at IS NULL`).
     *
     * @param  Builder<ClientDocument>  $query
     * @return Builder<ClientDocument>
     */
    public function scopeSharedWithClient(Builder $query, Client|int $client): Builder
    {
        return $query->where($query->qualifyColumn('client_id'), $client instanceof Client ? $client->getKey() : $client)
            ->where($query->qualifyColumn('visible_to_client'), true);
    }

    /**
     * @param  Builder<ClientDocument>  $query
     * @return Builder<ClientDocument>
     */
    public function scopeForClient(Builder $query, Client|int $client): Builder
    {
        return $query->where($query->qualifyColumn('client_id'), $client instanceof Client ? $client->getKey() : $client);
    }

    /**
     * @param  Builder<ClientDocument>  $query
     * @return Builder<ClientDocument>
     */
    public function scopeExpiringWithin(Builder $query, int $days, ?CarbonInterface $today = null): Builder
    {
        $start = ($today ?? now())->copy()->startOfDay();

        return $query->whereNotNull($query->qualifyColumn('expires_at'))
            ->whereBetween($query->qualifyColumn('expires_at'), [
                $start->toDateString(),
                $start->copy()->addDays(max(0, $days))->toDateString(),
            ]);
    }
}
