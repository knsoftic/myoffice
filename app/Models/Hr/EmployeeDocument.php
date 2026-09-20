<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Enums\DocumentVerificationStatus;
use App\Enums\EmployeeDocumentType;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One stored document belonging to an employee (phase-07 §2.6, requirement §24).
 *
 * **Private disk, always** (D21): `file_path` is a hashed name and the only way out is a
 * permission-checked download that writes an activity row.
 *
 * `is_confidential` defaults to **true**. A CNIC, a medical report or a police verification is not
 * something the whole office should be able to open, so the closed state is the default and opening one is
 * the deliberate act.
 *
 * `expiry_notified_at` is what stops the reminder sweep mailing the same expiring contract every morning —
 * a reminder nobody can silence is one everybody learns to ignore.
 */
class EmployeeDocument extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;


    protected $table = 'employee_documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'document_type',
        'title',
        'document_number',
        'issued_on',
        'expires_on',
        'reminder_days',
        'is_confidential',
        'notes',
    ];



    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'document_type' => EmployeeDocumentType::class,
            'size_kb' => 'integer',
            'issued_on' => 'date',
            'expires_on' => 'date',
            'reminder_days' => 'integer',
            'is_confidential' => 'boolean',
            'verification_status' => DocumentVerificationStatus::class,
            'verified_by' => 'integer',
            'verified_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    /**
     * Is this document past its expiry date today?
     */
    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    /**
     * How many days of warning this document wants before it lapses.
     */
    public function reminderWindow(): int
    {
        return $this->reminder_days ?? (int) setting('hr.document_expiry_reminder_days', 30);
    }

    public function moduleSlug(): string
    {
        return 'employee_documents';
    }

    protected function activityModule(): ?string
    {
        return 'employee_documents';
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
