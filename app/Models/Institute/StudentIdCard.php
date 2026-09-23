<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\IdCardStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A student's ID card (phase-19-23 §2.16, requirement §85).
 *
 * **A card is a physical object, and the model is shaped by that.** Five of `IdCardStatus`'s six cases
 * are reasons the card is not in use — expired, lost, damaged, replaced, revoked — because collapsing
 * them into `inactive` would lose exactly what the office needs: whether to charge for a replacement,
 * whether to expect the old card back, and whether the old number should still open a door.
 *
 * **`photo_path` is a copy, not a pointer** (INV-21-4). The student's photograph is duplicated onto
 * the private disk at issue time, so a profile picture changed next year does not alter a card in
 * somebody's wallet — the same reasoning as every `*_snapshot` column beside it.
 *
 * **Nothing deletes a card.** The policy refuses it and the hook below refuses it again, because
 * `Gate::before` waves a Super Admin past every policy and a soft delete is an UPDATE no foreign key
 * sees (D124). A lost card becomes `lost` and its replacement is a new row.
 *
 * **Expiry is derived, never stored as a status.** `isExpired()` compares `valid_until` with today;
 * the `expired` *status* is what a sweep writes once it has actually acted. Storing the status alone
 * would make a card that expired last night still read as `active` until a job ran, and deriving it
 * alone would lose the fact that somebody was told.
 */
class StudentIdCard extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'student_id_cards';

    /**
     * Every snapshot, every counter and the card number are absent: a request that could set
     * `student_name_snapshot` could print somebody else's name on a card, and one that could set
     * `card_number` could collide with an issued one. The service fills them inside a transaction.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_id',
        'student_batch_enrollment_id',
        'course_id',
        'batch_id',
        'print_template_id',
        'valid_until',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdCardStatus::class,
            'joining_date_snapshot' => 'date',
            'issued_on' => 'date',
            'valid_until' => 'date',
            'revoked_at' => 'datetime',
            'last_printed_at' => 'datetime',
            'print_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $card): never {
            throw new LogicException(sprintf(
                'Card %s is never deleted. A card that was issued stays on the record — mark it lost, '
                .'damaged or revoked, and issue a replacement.',
                (string) ($card->getAttribute('card_number') ?? '#'.$card->getKey()),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'student_id_cards';
    }

    public function isUsable(): bool
    {
        return $this->status->isUsable() && ! $this->isExpired();
    }

    /**
     * Past its date, whatever the stored status says.
     *
     * A card with no `valid_until` never expires — that is a deliberate configuration, not a missing
     * value, and treating null as "expired" would invalidate every card at an institute that does not
     * date them.
     */
    public function isExpired(): bool
    {
        $validUntil = $this->getAttribute('valid_until');

        return $validUntil !== null && Carbon::parse($validUntil)->endOfDay()->isPast();
    }

    /** Is there a newer card standing in for this one? `uq_sic_replacement` makes it at most one. */
    public function wasReplaced(): bool
    {
        return $this->replacedBy()->exists();
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentBatchEnrollment::class, 'student_batch_enrollment_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class, 'print_template_id');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function lastPrinter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }

    /** The card this one replaces. */
    public function replacementOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_of_id');
    }

    /** The card that replaced this one. */
    public function replacedBy(): HasOne
    {
        return $this->hasOne(self::class, 'replacement_of_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', IdCardStatus::Active->value);
    }

    /**
     * Cards the expiry sweep should act on: still marked active, dated, and past that date.
     *
     * Scoped to `active` rather than to every non-terminal status, so a card already marked lost is
     * not quietly relabelled expired — which would lose the fact that it is missing.
     */
    public function scopeDueToExpire(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query
            ->where('status', IdCardStatus::Active->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', ($asOf ?? Carbon::now())->toDateString());
    }
}
