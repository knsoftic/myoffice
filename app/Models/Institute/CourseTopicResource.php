<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CourseResourceType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A planned reading, link or file that is part of the published outline
 * (`course_topic_resources`, §65, phase-14-17 §2.8).
 *
 * **This is not Phase 19's `course_materials`, and the difference is who may see it.** A resource here
 * is syllabus — the reading a visitor can see listed before they enrol, and an `is_public` one appears
 * on the landing page. Phase 19's material is distributable content gated by enrollment and assignable
 * to one batch or one student. One table for both would mean every download route had to work out
 * which kind it was holding.
 *
 * `mime_type` records what the file **is**, not what it claimed: `CourseOutlineService` runs the content
 * through `finfo` and checks it against `CourseResourceType::allowedMimes()` (§111), and this column
 * stores that verdict rather than the upload's own story about itself.
 *
 * @property CourseResourceType $type
 */
class CourseTopicResource extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'course_topic_resources';

    /**
     * `file_path`, `file_size` and `mime_type` are absent on purpose: they are written by the service
     * from the stored file, never from a request body. A fillable `file_path` would let a crafted POST
     * point a public resource at any file on the disk.
     *
     * @var list<string>
     */
    protected $fillable = ['title', 'type', 'external_url', 'is_public', 'is_downloadable'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'course_topic_id' => 'integer',
            'course_id' => 'integer',
            'type' => CourseResourceType::class,
            'file_size' => 'integer',
            'is_public' => 'boolean',
            'is_downloadable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'course_outline';
    }

    protected function activityModule(): ?string
    {
        return 'course_outline';
    }

    /**
     * `is_public` is logged because flipping it puts a file on the open internet — the one change here
     * somebody may need to account for.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return ['title', 'type', 'course_topic_id', 'external_url', 'is_public', 'is_downloadable', 'sort_order'];
    }

    /*
    |--------------------------------------------------------------------------
    | What a screen asks
    |--------------------------------------------------------------------------
    */

    public function isLink(): bool
    {
        return ! $this->type->isFile() || $this->file_path === null;
    }

    /**
     * "2.4 MB" — the size a person recognises, or null when there is no file to size.
     */
    public function humanSize(): ?string
    {
        $bytes = (int) $this->file_size;

        if ($bytes <= 0) {
            return null;
        }

        foreach (['B', 'KB', 'MB', 'GB'] as $index => $unit) {
            $scaled = $bytes / (1024 ** $index);

            if ($scaled < 1024 || $unit === 'GB') {
                return round($scaled, $scaled < 10 && $index > 0 ? 1 : 0).' '.$unit;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function topic(): BelongsTo
    {
        return $this->belongsTo(CourseTopic::class, 'course_topic_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
