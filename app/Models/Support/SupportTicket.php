<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\PanelType;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Client;
use App\Models\Hr\Employee;
use App\Models\Institute\Batch;
use App\Models\Institute\Course;
use App\Models\Institute\Student;
use App\Models\Institute\Teacher;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A support ticket (phase-19-23 §2.18, requirement §93).
 *
 * **A ticket is never deleted, by anybody, for any reason — INV-22-1.** The policy refuses `delete`
 * and `forceDelete` for every role, and the hook below refuses it again below the gate, because
 * `Gate::before` walks a Super Admin past every policy (D124, and D140 is the third time that
 * mattered). A support thread is the record of what was promised to somebody; a wrong ticket is
 * closed, not erased.
 *
 * **The eight subject foreign keys are derived, never accepted.** `TicketService::create()` fills
 * them from `ClientContext` and the requester's own profiles, so a client cannot file a ticket "as"
 * another client by posting an id. Nothing here is mass-assignable that a form could reach.
 *
 * **`awaitingUs()` reads `last_reply_panel`, not the status.** A ticket left `in_progress` after the
 * requester replied is still waiting on us, and a queue sorted by status alone would bury it. The
 * column is written by the service on every reply precisely so this question is one column read
 * rather than a join to the newest row.
 *
 * **Every SLA member answers null-safely when the feature is off** (`support.sla_enabled`). With it
 * off, `first_response_due_at` and `resolution_due_at` are null, and a badge that read "overdue" off
 * a null would be a badge on every ticket in the system.
 *
 * @property TicketStatus $status
 * @property Priority $priority
 */
class SupportTicket extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'support_tickets';

    /**
     * Deliberately short. The number, the status, every SLA column, every cache and all eight
     * subject keys are the service's — see the class note.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_department_id',
        'subject',
        'description',
        'priority',
        'is_private_to_creator',
        'tags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requester_panel' => PanelType::class,
            'last_reply_panel' => PanelType::class,
            'priority' => Priority::class,
            'status' => TicketStatus::class,
            'assigned_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'first_response_breached' => 'boolean',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolution_breached' => 'boolean',
            'closed_at' => 'datetime',
            'waiting_since' => 'datetime',
            'total_waiting_minutes' => 'integer',
            'reopened_count' => 'integer',
            'last_reopened_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'replies_count' => 'integer',
            'staff_replies_count' => 'integer',
            'requester_replies_count' => 'integer',
            'attachments_count' => 'integer',
            'is_private_to_creator' => 'boolean',
            'tags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $ticket): never {
            throw new LogicException(sprintf(
                'Ticket %s is never deleted (INV-22-1). Close it — a support thread is the record of '
                .'what was promised to somebody, and a closed ticket still answers that question.',
                (string) ($ticket->getAttribute('ticket_number') ?? '#'.$ticket->getKey()),
            ));
        });
    }

    public function moduleSlug(): string
    {
        return 'support_tickets';
    }

    protected function activityModule(): ?string
    {
        return 'support_tickets';
    }

    /*
    |--------------------------------------------------------------------------
    | What state is it in
    |--------------------------------------------------------------------------
    */

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Is the ball in our court?
     *
     * Null when nobody has replied at all, which reads as "yes" — a ticket raised an hour ago with
     * no reply is waiting on us even though `last_reply_panel` is empty.
     */
    public function awaitingUs(): bool
    {
        $panel = $this->last_reply_panel;

        return $panel === null || $panel !== PanelType::Admin;
    }

    /** Has anybody on staff answered the requester yet? */
    public function hasBeenAnswered(): bool
    {
        return $this->getAttribute('first_response_at') !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | The SLA, answering null-safely when it is switched off
    |--------------------------------------------------------------------------
    */

    /** Is a first response late? False when there is no target, or one has already been given. */
    public function firstResponseOverdue(?Carbon $asOf = null): bool
    {
        $due = $this->getAttribute('first_response_due_at');

        if ($due === null || $this->hasBeenAnswered()) {
            return false;
        }

        return $due->isBefore($asOf ?? Carbon::now());
    }

    /** Is a resolution late? */
    public function resolutionOverdue(?Carbon $asOf = null): bool
    {
        $due = $this->getAttribute('resolution_due_at');

        if ($due === null || $this->status->isResolvedOrClosed()) {
            return false;
        }

        return $due->isBefore($asOf ?? Carbon::now());
    }

    /** Does this ticket have an SLA at all? False for every ticket raised while the feature was off. */
    public function hasSla(): bool
    {
        return $this->getAttribute('first_response_due_at') !== null
            || $this->getAttribute('resolution_due_at') !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'ticket_department_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The person who raised it. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function firstResponder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_response_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function lastReplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reply_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Collaborator\Collaborator::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'support_ticket_id');
    }

    /** What a portal user is shown: the human replies that reached them. */
    public function publicReplies(): HasMany
    {
        return $this->replies()->public();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            TicketStatus::Open->value,
            TicketStatus::InProgress->value,
            TicketStatus::Waiting->value,
        ]);
    }

    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where('assigned_to', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Every ticket this person raised.
     *
     * Deliberately `user_id` and not a client id: §9.4's "view only what is mine" is about the
     * person, and a colleague at the same client does not inherit it.
     */
    public function scopeRaisedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /** Past a target and still unanswered or unresolved. Matches nothing when the SLA is off. */
    public function scopeBreaching(Builder $query, ?Carbon $asOf = null): Builder
    {
        $now = $asOf ?? Carbon::now();

        return $query->where(function (Builder $outer) use ($now): void {
            $outer
                ->where(function (Builder $inner) use ($now): void {
                    $inner->whereNull('first_response_at')
                        ->whereNotNull('first_response_due_at')
                        ->where('first_response_due_at', '<', $now);
                })
                ->orWhere(function (Builder $inner) use ($now): void {
                    $inner->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
                        ->whereNotNull('resolution_due_at')
                        ->where('resolution_due_at', '<', $now);
                });
        });
    }
}
