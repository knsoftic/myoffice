<?php

declare(strict_types=1);

namespace App\Models\Project;

use App\Enums\AttachmentVisibility;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One uploaded file, attached to whatever owns it (phase-06 §2.9, requirements §22 and §96).
 *
 * **[D-P6-6] One polymorphic store for the whole system.** There is no `files` table — the `files` module
 * slug governs this one (CLAUDE.md §3). Later phases attach here rather than building a second store;
 * Phase 22 extends {@see MORPH_ALIASES} for tickets, meetings and messages.
 *
 * `attachable_type` holds a **morph map alias**, never a raw FQCN, so a model can be moved between
 * namespaces without rewriting rows. Two of the six aliases — `collaborator` and `invoice` — are the §96
 * subjects that had no file store at all (F-13.2); until phases 8 and 13 ship those models, a row pointing
 * at one must read as a **404, never a 500**, which is why {@see attachable()} is only ever reached through
 * a controller that resolves the subject first.
 *
 * **Nothing here is web-reachable** (D21): `disk` is the private disk and the only way to read a file is
 * the permission-checked download controller of §7, which re-runs the permission chain and writes an
 * activity row naming the actor (§60). `path` is a hashed `{ulid}.{ext}` name — the human filename lives
 * in `original_name` and is used only for the download header — and `mime_type` comes from the sniffed
 * content, never from the request header.
 *
 * Soft delete keeps the audit row; the blob itself is removed by a queued job only after 30 days.
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property string $extension
 * @property int $size_bytes
 * @property string|null $checksum_sha256
 * @property AttachmentVisibility $visibility
 * @property int|null $uploaded_by
 * @property string $uploaded_by_name
 */
class Attachment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * The six aliases Phase 6 registers (§2.9). Later phases **extend** this map; they never rename an
     * entry, because the alias is what is stored in every existing row.
     *
     * @var array<string, class-string<Model>|string>
     */
    public const MORPH_ALIASES = [
        'project' => Project::class,
        'task' => Task::class,
        'task_comment' => TaskComment::class,
        'project_milestone' => ProjectMilestone::class,
        // Unreachable until phases 8 and 13 ship these models — a 404, never a 500 (F-13.2).
        'collaborator' => 'App\\Models\\Collaborator\\Collaborator',
        'invoice' => 'App\\Models\\Finance\\Invoice',
    ];

    protected $table = 'attachments';

    /**
     * Only the two things a human chooses. Everything describing the stored blob — disk, path, mime,
     * extension, size, checksum — is written by `AttachmentService` from the sniffed file, never from the
     * request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'visibility',
        'original_name',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visibility' => 'team',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachable_id' => 'integer',
            'size_bytes' => 'integer',
            'visibility' => AttachmentVisibility::class,
            'uploaded_by' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'files';
    }

    protected function activityModule(): ?string
    {
        return 'files';
    }

    /**
     * The morph map entries that resolve to a model that actually exists in this build.
     *
     * @return array<string, class-string<Model>>
     */
    public static function availableMorphAliases(): array
    {
        return array_filter(
            self::MORPH_ALIASES,
            static fn (string $class): bool => class_exists($class) && is_subclass_of($class, Model::class)
        );
    }

    /**
     * The attachments a client may see: `visibility = client` only (§96, §9).
     */
    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('visibility'),
            AttachmentVisibility::valuesVisibleToClient()
        );
    }

    /**
     * The attachments a collaborator on the subject's project may see: `team` and `client`.
     */
    public function scopeVisibleToCollaborator(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('visibility'),
            AttachmentVisibility::valuesVisibleToCollaborator()
        );
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
