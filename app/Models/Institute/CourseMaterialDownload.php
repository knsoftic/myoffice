<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\MaterialAccessAction;
use App\Enums\PanelType;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * "This person opened this material at this moment" (phase-19-23 §2.5, §79, INV-19-4).
 *
 * **A write-once log row**, on Phase 4's `blog_post_views` pattern: `created_at` only, no `updated_at`,
 * no `deleted_at`, no blameable (D19). The hooks below refuse an edit and a delete outright, because a
 * row here is a claim about something that already happened and there is no honest way to change it.
 *
 * **INV-19-4: it is written *before* the stream starts** (§6.4 step 6 precedes step 7). Logging
 * afterwards would lose exactly the case most worth having — the download that failed half way — and
 * a stream that begins with no row is a private file served with no record. `bytes_sent` is nullable
 * for that reason: null means the stream did not finish, which is different from having sent nothing.
 *
 * @property MaterialAccessAction $action
 * @property PanelType $panel
 */
class CourseMaterialDownload extends Model
{
    use LogsActivityWithContext;

    /** Written once: there is no update to stamp. */
    public const UPDATED_AT = null;

    protected $table = 'course_material_downloads';

    /**
     * Nothing. Every column is composed by `MaterialAccessService` from the request and the resolved
     * entitlement — a mass assignment could not know which student the actor resolved to, and that is
     * the column the whole log exists to record.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_material_id' => 'integer',
            'user_id' => 'integer',
            'student_id' => 'integer',
            'teacher_id' => 'integer',
            'panel' => PanelType::class,
            'action' => MaterialAccessAction::class,
            'bytes_sent' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // `bytes_sent` is the one column stamped after insert, by the stream itself finishing. It
        // goes through recordBytesSent() below; anything else is rewriting an access record.
        static::updating(static function (self $row): void {
            if (array_keys($row->getDirty()) !== ['bytes_sent']) {
                throw new LogicException(sprintf(
                    'Access record #%s says a file was opened and cannot be edited (D19).',
                    (string) $row->getKey(),
                ));
            }
        });

        // No `deleted_at`, so delete() here is a hard DELETE — and this log is the evidence of who
        // read what, which is the first thing anybody investigating a leak asks for.
        static::deleting(static function (self $row): never {
            throw new LogicException(sprintf(
                'Access record #%s is an append-only log row (D19) and is never deleted. The prune '
                .'command removes whole date ranges under the retention setting instead.',
                (string) $row->getKey(),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'course_materials';
    }

    protected function activityModule(): ?string
    {
        return 'course_materials';
    }

    /**
     * Stamp how much actually went out, once the stream has finished. Leaving it null is the correct
     * outcome for a stream that was cut off, so this is only ever called on success.
     */
    public function recordBytesSent(int $bytes): void
    {
        $this->forceFill(['bytes_sent' => max(0, $bytes)])->saveQuietly();
    }

    /** Counted towards `download_count` — a link open does, a view does not (§2.5). */
    public function countsAsDownload(): bool
    {
        return $this->action->countsAsDownload();
    }

    public function scopeOfAction(Builder $query, MaterialAccessAction $action): Builder
    {
        return $query->where('action', $action->value);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CourseMaterial::class, 'course_material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }
}
