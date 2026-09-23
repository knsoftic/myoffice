<?php

declare(strict_types=1);

namespace App\Models\Support;

use App\Enums\PanelType;
use App\Enums\TicketAssignStrategy;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * A queue tickets are filed into (phase-19-23 §2.17, requirement §93).
 *
 * **`allowed_panels` is the gate that keeps queues meaningful.** Without it a student could file into
 * a client-billing queue whose agents have no reason to read it, and the ticket would sit unanswered
 * while everybody involved believed it had been raised. `accepts()` is the one question
 * `TicketService::create()` asks, before anything else.
 *
 * **A used department is deactivated, never deleted.** `restrictOnDelete` from `support_tickets`
 * catches a hard delete; the hook below catches the **soft** one, which is an UPDATE no foreign key
 * ever sees. Both are needed because `Gate::before` waves a Super Admin past every policy (D124), and
 * a department that vanished would take the context of every ticket in it — which desk owned this,
 * what its targets were — with it. Deactivating hides it from every create form and moves nothing.
 *
 * @property string $name
 */
class TicketDepartment extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SoftDeletes;

    protected $table = 'ticket_departments';

    /**
     * `is_default` is absent: making a department the default clears the previous one, which is a
     * transaction rather than a field. `open_tickets_count` is a cache the service recounts.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'email',
        'allowed_panels',
        'default_assignee_id',
        'auto_assign_strategy',
        'sla_first_response_minutes',
        'sla_resolution_minutes',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_panels' => 'array',
            'auto_assign_strategy' => TicketAssignStrategy::class,
            'sla_first_response_minutes' => 'integer',
            'sla_resolution_minutes' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'open_tickets_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (self $department): void {
            // A hard delete is caught by `restrictOnDelete`; this is the soft one. See the class note
            // — both are needed, and this is the one that holds against a Super Admin.
            if ($department->isForceDeleting() || $department->hasTickets()) {
                throw new LogicException(sprintf(
                    'The %s department has tickets in it. Deactivate it instead — every one of those '
                    .'tickets would lose the desk that owned it and the targets it was measured '
                    .'against.',
                    (string) $department->getAttribute('name'),
                ));
            }
        });
    }

    public function moduleSlug(): string
    {
        return 'ticket_departments';
    }

    /**
     * Stamped onto every `activity_log` row this model writes.
     *
     * Separate from `moduleSlug()` on purpose: that one answers "which module owns this record?" for
     * the policy and the module gate, and this one fills a column. They happen to agree here, and a
     * model that let them drift would be one whose changes are invisible to the activity log's own
     * module filter — which is how an audit trail quietly stops covering a phase.
     */
    protected function activityModule(): ?string
    {
        return 'ticket_departments';
    }

    /**
     * May somebody on this panel open a ticket here?
     *
     * An empty list means nobody, not everybody — an allowlist fails closed, so a department
     * configured by mistake is one nobody can file into rather than one everybody can.
     */
    public function accepts(PanelType $panel): bool
    {
        return in_array($panel->value, (array) ($this->getAttribute('allowed_panels') ?? []), true);
    }

    /** @return list<PanelType> */
    public function panels(): array
    {
        $panels = [];

        foreach ((array) ($this->getAttribute('allowed_panels') ?? []) as $value) {
            $panel = PanelType::tryFrom((string) $value);

            if ($panel instanceof PanelType) {
                $panels[] = $panel;
            }
        }

        return $panels;
    }

    /** Has anything ever been filed here? Asked withTrashed: a hidden ticket still owned this desk. */
    public function hasTickets(): bool
    {
        return $this->tickets()->withTrashed()->exists();
    }

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'ticket_department_id');
    }

    /** Offered on a create form. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** The queues a person on this panel may file into. */
    public function scopeForPanel(Builder $query, PanelType $panel): Builder
    {
        return $query->whereJsonContains('allowed_panels', $panel->value);
    }
}
