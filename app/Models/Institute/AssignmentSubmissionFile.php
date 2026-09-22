<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\DataObjects\Files\StoredFile;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file a student handed in (phase-19-23 §2.8).
 *
 * One of the five specialised stores `CLAUDE.md` §3 names as exceptions to `attachments`, and it earns
 * the exception: it belongs to an *attempt* rather than to a model, it is served only through §6.4's
 * seven-step chain, and it is one of two places in the system where a checksum collision is worth
 * showing a human.
 *
 * **`checksum_sha256` flags a student handing in a classmate's identical file — as a warning on the
 * grading screen, never an accusation and never a block** (§2.8). Two students submitting the same
 * provided template is the ordinary case, so software that refused a match would be wrong far more often
 * than right. The column exists so a teacher can look, not so the system can decide.
 *
 * **Append-only child: timestamps, no soft deletes, no blameable.** `uploaded_by` records who sent the
 * bytes; the rest of the story belongs to the submission.
 */
class AssignmentSubmissionFile extends Model
{
    use LogsActivityWithContext;

    protected $table = 'assignment_submission_files';

    /**
     * Nothing. Every column here is what `SecureFileService` decided from the bytes — a request that
     * could set `mime_type` or `extension` could claim a `.php` was a PDF, which is the one thing
     * §6.1's gate exists to prevent.
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
            'assignment_submission_id' => 'integer',
            'file_size_bytes' => 'integer',
            'uploaded_by' => 'integer',
            'download_count' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'assignment_submissions';
    }

    protected function activityModule(): ?string
    {
        return 'assignment_submissions';
    }

    /** The row as `SecureFileService` speaks it — the column names line up exactly. */
    public function storedFile(): StoredFile
    {
        return StoredFile::fromColumns($this->attributesToArray());
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
