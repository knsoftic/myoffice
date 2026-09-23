<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\CertificateStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use App\Services\Institute\Exceptions\CertificateIssuedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * A certificate of completion (phase-19-23 §2.14, requirement §84).
 *
 * **INV-21-4: every printed field is a snapshot, and that is the whole design.** A certificate is a
 * document somebody is holding. Read the student's name through a join and renaming them next year
 * makes the record disagree with the paper — and the verification page, whose entire job is to confirm
 * that paper, would confirm something else. Name, father's name, code, course, batch, trainer, branch
 * and the QR payload are all columns on this row.
 *
 * **INV-21-1: nothing deletes a certificate, and the model is what enforces it.** The policy refuses
 * `delete` and `forceDelete` for every role, but `Gate::before` waves a Super Admin past every policy
 * and a soft delete is an UPDATE that `restrictOnDelete` never sees — so the refusal lives in a hook.
 * That is D124, learned in Phase 19 by shipping a policy that stopped everyone except the one role
 * most able to do damage.
 *
 * **Issued means immutable, with a named and deliberately short list of exceptions.** Once a document
 * is in somebody's hands its content is fixed; what may still move is the evidence *about* it — how
 * many times it has been printed, how many times it has been verified, where its PDF lives, whether
 * it is publicly verifiable, and the status itself so it can be revoked. Everything else throws.
 *
 * **[D-21-3] `reissued` is a link, not a status.** A reissue is this row moving to `revoked` plus a
 * fresh `draft` carrying `reissue_of_id`, with `uq_ce_reissue` permitting exactly one successor. One
 * row is never simultaneously "the revoked one" and "the new one".
 *
 * @property string $verification_code
 */
class Certificate extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    /**
     * What may still change after a certificate has been issued.
     *
     * Kept short on purpose, and every entry is evidence about the document rather than content of
     * it. `status` is here so a revocation is possible; the revocation columns follow it.
     *
     * @var list<string>
     */
    private const MUTABLE_ONCE_ISSUED = [
        'status',
        'print_count',
        'last_printed_at',
        'last_printed_by',
        'verification_count',
        'last_verified_at',
        'pdf_path',
        'pdf_generated_at',
        'is_publicly_verifiable',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'notes',
        'updated_at',
        'updated_by',
    ];

    protected $table = 'certificates';

    /**
     * Every snapshot column is absent, and so is every counter and every timestamp. A request that
     * could set `student_name_snapshot` could make a certificate claim somebody else completed the
     * course; one that could set `verification_count` could fake a verification history. The service
     * fills them through `forceFill()` inside a transaction.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'course_id',
        'batch_id',
        'student_batch_enrollment_id',
        'teacher_id',
        'print_template_id',
        'grade_scale_id',
        'completion_date',
        'course_start_date',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'course_start_date' => 'date',
            'completion_date' => 'date',
            'issued_on' => 'date',
            'grade_point' => 'decimal:2',
            'percentage' => 'decimal:4',
            'attendance_percentage' => 'decimal:4',
            'progress_percentage' => 'decimal:4',
            'eligibility_snapshot' => 'array',
            'revoked_at' => 'datetime',
            'pdf_generated_at' => 'datetime',
            'last_printed_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'print_count' => 'integer',
            'verification_count' => 'integer',
            'is_publicly_verifiable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $certificate): void {
            // The *original* status decides, not the new one: a row moving from issued to revoked is
            // an issued row being changed, and reading the incoming value would let a revocation
            // smuggle a rewritten name through with it.
            //
            // **`getRawOriginal()`, not `getOriginal()`.** `getOriginal()` applies the model's casts,
            // so it hands back a `CertificateStatus` and `(string)` on an enum is a fatal — which is
            // what this line did for one commit. The hook then threw a TypeError on *every* update,
            // including the print-count bump it is supposed to allow, so it failed in both directions
            // at once: nothing could be edited, and the reason given was nonsense.
            $wasImmutable = CertificateStatus::tryFrom(
                (string) $certificate->getRawOriginal('status')
            )?->isImmutable() ?? false;

            if (! $wasImmutable) {
                return;
            }

            $forbidden = array_diff(array_keys($certificate->getDirty()), self::MUTABLE_ONCE_ISSUED);

            if ($forbidden !== []) {
                throw CertificateIssuedException::cannotChange(
                    (string) ($certificate->getAttribute('certificate_number') ?? $certificate->getKey()),
                    array_values($forbidden),
                );
            }
        });

        static::deleting(static function (self $certificate): never {
            throw new LogicException(sprintf(
                'Certificate %s is never deleted (INV-21-1). Revoke it with a reason — a document '
                .'somebody is holding stays on the record, and its verification page has to keep '
                .'answering.',
                (string) ($certificate->getAttribute('certificate_number') ?? '#'.$certificate->getKey()),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'certificates';
    }

    public function isIssued(): bool
    {
        return $this->status->isIssued();
    }

    public function isRevoked(): bool
    {
        return $this->status === CertificateStatus::Revoked;
    }

    public function isDraft(): bool
    {
        return $this->status === CertificateStatus::Draft;
    }

    /**
     * May the public page resolve this at all?
     *
     * **Three separate reasons to say no, all answering `not_found`.** A draft was never handed over;
     * a privacy opt-out asked not to be listed; a soft-deleted row should not exist. Distinguishing
     * them publicly would tell an unauthenticated guesser which codes are real, so they are
     * deliberately indistinguishable. A *revoked* certificate is not one of the three — it resolves
     * and says revoked, which is the whole point of a verification page.
     */
    public function isPubliclyResolvable(): bool
    {
        return $this->status->isPublic()
            && (bool) $this->getAttribute('is_publicly_verifiable')
            && $this->getAttribute('deleted_at') === null;
    }

    public function wasReissued(): bool
    {
        return $this->reissuedAs()->exists();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentBatchEnrollment::class, 'student_batch_enrollment_id');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class, 'print_template_id');
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(GradeScale::class, 'grade_scale_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function lastPrinter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }

    /** The certificate this one replaces. */
    public function reissueOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissue_of_id');
    }

    /** The certificate that replaced this one. `uq_ce_reissue` makes it at most one. */
    public function reissuedAs(): HasOne
    {
        return $this->hasOne(self::class, 'reissue_of_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(CertificateVerification::class, 'certificate_id');
    }

    /** Everything a student may see: issued or revoked, and not opted out (§3.2's shape for §84). */
    public function scopeVisibleToStudents(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CertificateStatus::Issued->value,
            CertificateStatus::Revoked->value,
        ]);
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', CertificateStatus::Issued->value);
    }
}
