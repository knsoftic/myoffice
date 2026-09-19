<?php

declare(strict_types=1);

namespace App\Models\Cms;

use App\Enums\ContactInquiryStatus;
use App\Enums\InquiryRoutingStatus;
use App\Enums\InquirySource;
use App\Enums\InquiryType;
use App\Models\Cms\Concerns\SearchesContent;
use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One public contact-form submission plus its routing state (phase-04 §2.20, requirement §17).
 *
 * **Phase 4 owns this table** (F-2.1). The row is the permanent record of what the visitor sent: routing
 * creates an additional Lead / CourseInquiry through `InquiryRouter` and never moves, edits or deletes the
 * inquiry. Spam is stored (`is_spam`), never routed, never notified.
 *
 * **What is not mass assignable, and why.**
 *   · Routing state (`routing_*`, `routed_*`) — `InquiryRouter::route()` is its only writer (§6.10.2).
 *   · Handling state (`status`, `is_spam`, `spam_reason`, `assigned_to`, `read_*`, `responded_at`,
 *     `response_notes`) — `ContactInquiryService` and `SpamGuard` only.
 *   · The referral snapshot (`collaborator_id`, `referral_code`, `referral_visit_id`) — written once at
 *     submission from the server-side `?ref=` capture and, from Phase 9, only by `SyncReferralSnapshot`.
 *     A `collaborator_id` posted by the browser must be discarded (§6.10.5, test 62), so none of the three
 *     can ever arrive through `fill()`. They are **display snapshots** (ND-3, D37): no engine and no scope
 *     reads them — `scopeVisibleTo()` below never does.
 * Services set these with `forceFill()`.
 *
 * **Isolation (§9.1.2).** `scopeVisibleTo()`: `contact_inquiries.view_any` sees every row; anyone else
 * only the rows assigned to it. Spam leaves every tab except Spam through `scopeTab()` — in the scope,
 * not the Blade.
 *
 * **The technical / PII block (F-12.4).** `TECHNICAL_COLUMNS` are selected only for a
 * `contact_inquiries.view_logs` holder (`scopeSelectVisibleColumns()`), so for everyone else they are
 * absent from the query and the response body, not merely hidden in Blade. They are also hidden from
 * serialisation and kept out of the activity log.
 *
 * `course_id` is a deferred link to Phase 14 (§2.1); `course_name` is the snapshot kept forever.
 * `routedRecord()` resolves the lazy `routed_type` + `routed_id` pointer and returns null — never throws —
 * when the class does not exist yet or the record was removed.
 *
 * @property int $id
 * @property InquiryType $inquiry_type
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $company
 * @property int|null $service_id
 * @property int|null $course_id
 * @property string|null $course_name
 * @property string|null $budget
 * @property string|null $subject
 * @property string $message
 * @property InquirySource $source
 * @property ContactInquiryStatus $status
 * @property bool $is_spam
 * @property string|null $spam_reason
 * @property InquiryRoutingStatus $routing_status
 * @property string|null $routing_target
 * @property string|null $routed_type
 * @property int|null $routed_id
 * @property Carbon|null $routed_at
 * @property int $routing_attempts
 * @property string|null $routing_error
 * @property int|null $collaborator_id
 * @property string|null $referral_code
 * @property int|null $referral_visit_id
 * @property int|null $assigned_to
 * @property Carbon|null $read_at
 * @property int|null $read_by
 * @property Carbon|null $responded_at
 * @property string|null $response_notes
 * @property string|null $page_url
 * @property string|null $referrer_url
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property int|null $filled_in_seconds
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ContactInquiry extends Model
{
    use Blameable;
    use LogsActivityWithContext;
    use SearchesContent;
    use SoftDeletes;

    /**
     * The technical / PII block (§9.1.2, F-12.4): selected only for `contact_inquiries.view_logs`.
     * The spam verdict (`is_spam`) stays selectable for everyone because the Spam tab and the "spam never
     * appears outside the Spam tab" rule filter on it; `spam_reason` — the verdict's detail — is gated.
     */
    public const TECHNICAL_COLUMNS = [
        'ip_address',
        'user_agent',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer_url',
        'filled_in_seconds',
        'spam_reason',
    ];

    /** The `contact_inquiries.spam_reason` codes `SpamGuard` writes (§2.20, §6.9). */
    public const SPAM_REASONS = ['honeypot', 'too_fast', 'token', 'duplicate', 'blocklist', 'score'];

    /** Routing failures before `routing_status` becomes `failed` (§2.20, §6.10.2 step 7). */
    public const MAX_ROUTING_ATTEMPTS = 3;

    /** `routing_error` codes for the two non-failing waits (§6.10.2 step 5). */
    public const ROUTING_ERROR_UNREGISTERED = 'target_unregistered';

    public const ROUTING_ERROR_MODULE_DISABLED = 'module_disabled';

    /** The §8.10 queue tabs understood by `scopeTab()`. */
    public const TABS = ['all', 'new', 'service', 'course', 'general', 'awaiting', 'routed', 'spam', 'trashed'];

    protected $table = 'contact_inquiries';

    /**
     * Exactly what a visitor's submission may carry into `fill()` (see the class docblock for the rest).
     *
     * @var list<string>
     */
    protected $fillable = [
        'inquiry_type',
        'name',
        'email',
        'phone',
        'whatsapp',
        'company',
        'service_id',
        'course_id',
        'course_name',
        'budget',
        'subject',
        'message',
        'source',
        'page_url',
        'referrer_url',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'filled_in_seconds',
        'ip_address',
        'user_agent',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'ip_address',
        'user_agent',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer_url',
        'filled_in_seconds',
        'spam_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'inquiry_type' => 'general',
        'source' => 'website',
        'status' => 'new',
        'is_spam' => false,
        'routing_status' => 'not_applicable',
        'routing_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inquiry_type' => InquiryType::class,
            'service_id' => 'integer',
            'course_id' => 'integer',
            'source' => InquirySource::class,
            'status' => ContactInquiryStatus::class,
            'is_spam' => 'boolean',
            'routing_status' => InquiryRoutingStatus::class,
            'routed_id' => 'integer',
            'routed_at' => 'datetime',
            'routing_attempts' => 'integer',
            'collaborator_id' => 'integer',
            'referral_visit_id' => 'integer',
            'assigned_to' => 'integer',
            'read_at' => 'datetime',
            'read_by' => 'integer',
            'responded_at' => 'datetime',
            'filled_in_seconds' => 'integer',
        ];
    }

    public function moduleSlug(): string
    {
        return 'contact_inquiries';
    }

    protected function activityModule(): ?string
    {
        return 'contact_inquiries';
    }

    /**
     * Handling, routing and snapshot changes are audited (§10.5). The technical block is not written to
     * the activity log: its viewer is not gated by `view_logs`, so logging it would reopen F-12.4.
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return array_values(array_diff(
            [
                ...$this->getFillable(),
                'status', 'is_spam', 'spam_reason', 'routing_status', 'routing_target', 'routed_type', 'routed_id',
                'routed_at', 'routing_attempts', 'routing_error', 'collaborator_id', 'referral_code',
                'referral_visit_id', 'assigned_to', 'read_at', 'read_by', 'responded_at', 'response_notes',
            ],
            self::TECHNICAL_COLUMNS,
        ));
    }

    /**
     * A routing retry bumps only the attempt counter and error; that alone is not worth an activity row
     * (the router writes its own entries for routed / failed outcomes).
     *
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'routing_attempts', 'routing_error'];
    }

    /**
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['name', 'email', 'phone', 'subject', 'message'];
    }

    /**
     * Stored trimmed and lower-cased (§2.20).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): string => mb_strtolower(trim((string) $value)),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isSpam(): bool
    {
        return (bool) $this->is_spam;
    }

    public function isRouted(): bool
    {
        return $this->routed_id !== null;
    }

    public function isAssignedTo(User|int|null $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return $id !== null && $this->assigned_to !== null && (int) $this->assigned_to === (int) $id;
    }

    /**
     * The record routing created, or null when there is none, the class is not installed yet, or the
     * record has since been removed (§2.20). Never throws.
     */
    public function routedRecord(): ?Model
    {
        $class = trim((string) $this->routed_type);

        if ($class === '' || $this->routed_id === null) {
            return null;
        }

        try {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                return null;
            }

            /** @var Model $model */
            $model = new $class;

            // A soft-deleted target still resolves, so the row can say "Lead #123 (trashed)" rather
            // than pretending routing never happened.
            $query = $model->newQuery()->withoutGlobalScope(SoftDeletingScope::class);

            return $query->find($this->routed_id);
        } catch (Throwable) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * §9.1.2 row scoping, verbatim: `contact_inquiries.view_any` sees every row; anyone else only the rows
     * assigned to it. It reads `assigned_to` and nothing else — never the referral snapshot (D37).
     *
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('contact_inquiries.view_any')) {
            return $query;
        }

        return $query->where($query->qualifyColumn('assigned_to'), $user->getKey());
    }

    /**
     * F-12.4: every column except the technical / PII block, unless the user holds
     * `contact_inquiries.view_logs`. Apply it to any query whose rows reach a response.
     *
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeSelectVisibleColumns(Builder $query, User $user): Builder
    {
        if ($user->can('contact_inquiries.view_logs')) {
            return $query->select($query->qualifyColumn('*'));
        }

        $columns = array_values(array_diff(self::allColumns(), self::TECHNICAL_COLUMNS));

        return $query->select(array_map(static fn (string $column): string => $query->qualifyColumn($column), $columns));
    }

    /**
     * The §8.10 queue tabs. Spam is excluded from every tab except `spam` (and `trashed` shows trashed
     * non-spam rows); `all` is every non-spam, non-trashed row.
     *
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeTab(Builder $query, ?string $tab): Builder
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'all';

        if ($tab === 'spam') {
            return $query->where($query->qualifyColumn('is_spam'), true);
        }

        $query->where($query->qualifyColumn('is_spam'), false);

        return match ($tab) {
            'new' => $query->where($query->qualifyColumn('status'), ContactInquiryStatus::New->value),
            'service' => $query->where($query->qualifyColumn('inquiry_type'), InquiryType::Service->value),
            'course' => $query->where($query->qualifyColumn('inquiry_type'), InquiryType::Course->value),
            'general' => $query->where($query->qualifyColumn('inquiry_type'), InquiryType::General->value),
            'awaiting' => $query->whereIn($query->qualifyColumn('routing_status'), [
                InquiryRoutingStatus::Pending->value,
                InquiryRoutingStatus::Failed->value,
            ]),
            'routed' => $query->where($query->qualifyColumn('routing_status'), InquiryRoutingStatus::Routed->value),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };
    }

    /**
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeNotSpam(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_spam'), false);
    }

    /**
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeSpam(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_spam'), true);
    }

    /**
     * What `InquiryRouter::routePending()` walks (§6.10.2): `pending` or `failed`, never spam, oldest
     * first. `$target` narrows to one target key (the "Awaiting CRM / Institute" tabs).
     *
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeAwaitingRouting(Builder $query, ?string $target = null): Builder
    {
        $query->where($query->qualifyColumn('is_spam'), false)
            ->whereIn($query->qualifyColumn('routing_status'), [
                InquiryRoutingStatus::Pending->value,
                InquiryRoutingStatus::Failed->value,
            ]);

        if ($target !== null && $target !== '') {
            $query->where($query->qualifyColumn('routing_target'), $target);
        }

        return $query->orderBy($query->qualifyColumn('created_at'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * @param  Builder<ContactInquiry>  $query
     * @param  ContactInquiryStatus|string|array<int, ContactInquiryStatus|string>  $status
     * @return Builder<ContactInquiry>
     */
    public function scopeWithStatus(Builder $query, ContactInquiryStatus|string|array $status): Builder
    {
        $values = array_map(
            static fn (ContactInquiryStatus|string $value): string => $value instanceof ContactInquiryStatus ? $value->value : $value,
            is_array($status) ? $status : [$status]
        );

        return $query->whereIn($query->qualifyColumn('status'), $values);
    }

    /**
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeOfType(Builder $query, InquiryType|string $type): Builder
    {
        return $query->where($query->qualifyColumn('inquiry_type'), $type instanceof InquiryType ? $type->value : $type);
    }

    /**
     * @param  Builder<ContactInquiry>  $query
     * @return Builder<ContactInquiry>
     */
    public function scopeAssignedTo(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('assigned_to'), $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * Every physical column of the table, in schema order — the base list `scopeSelectVisibleColumns()`
     * subtracts the technical block from. Kept in code (no schema query per request); a later additive
     * column must be appended here too, or it is simply not selected for non-`view_logs` users.
     *
     * @return list<string>
     */
    public static function allColumns(): array
    {
        return [
            'id', 'inquiry_type', 'name', 'email', 'phone', 'whatsapp', 'company', 'service_id', 'course_id',
            'course_name', 'budget', 'subject', 'message', 'source', 'status', 'is_spam', 'spam_reason',
            'routing_status', 'routing_target', 'routed_type', 'routed_id', 'routed_at', 'routing_attempts',
            'routing_error', 'collaborator_id', 'referral_code', 'referral_visit_id', 'assigned_to', 'read_at',
            'read_by', 'responded_at', 'response_notes', 'page_url', 'referrer_url', 'utm_source', 'utm_medium',
            'utm_campaign', 'filled_in_seconds', 'ip_address', 'user_agent', 'created_at', 'updated_at',
            'deleted_at', 'created_by', 'updated_by',
        ];
    }
}
