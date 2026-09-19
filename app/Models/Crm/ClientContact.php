<?php

declare(strict_types=1);

namespace App\Models\Crm;

use App\Models\Concerns\Blameable;
use App\Models\Concerns\LogsActivityWithContext;
use App\Models\Crm\Concerns\HasGeneratedGuards;
use App\Models\Crm\Concerns\NormalizesContacts;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A named person at a client, and optionally an additional portal login (phase-05 §2.8).
 *
 * **One primary per client** is a database fact: the STORED `primary_guard` (`1` for the primary) under
 * `UNIQUE uq_cc_primary(client_id, primary_guard)`. `ClientContactService::setPrimary()` clears the previous
 * primary in the same transaction (§11 test 60); the guard itself is never written by Eloquent. The expression
 * ignores `deleted_at`, so a primary contact is demoted before it is trashed.
 *
 * **One login, one contact.** `UNIQUE uq_cc_user(user_id)` plus `clients.uq_clients_user` and the service's explicit
 * check keep a user bound to at most one client (§11 test 61).
 *
 * Mass assignable: the contact form. `client_id` comes from the relation, `user_id` / `portal_access` from
 * `ClientService::enablePortal()` (`ClientContactPolicy::grantPortalAccess`), `is_primary` from `setPrimary()` —
 * all written with `forceFill()`. `email_normalized` / `phone_normalized` are recomputed on save, expanding a
 * local number with the client's country.
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $designation
 * @property string|null $department
 * @property string|null $email
 * @property string|null $email_normalized
 * @property string|null $phone
 * @property string|null $phone_normalized
 * @property string|null $whatsapp
 * @property bool $is_primary
 * @property int|null $primary_guard STORED generated — read only
 * @property bool $is_billing_contact
 * @property bool $portal_access
 * @property bool $receives_notifications
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ClientContact extends Model
{
    use Blameable;
    use HasGeneratedGuards;
    use LogsActivityWithContext;
    use NormalizesContacts;
    use SoftDeletes;

    protected $table = 'client_contacts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'designation',
        'department',
        'email',
        'phone',
        'whatsapp',
        'is_billing_contact',
        'receives_notifications',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_primary' => false,
        'is_billing_contact' => false,
        'portal_access' => false,
        'receives_notifications' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'user_id' => 'integer',
            'is_primary' => 'boolean',
            'primary_guard' => 'integer',
            'is_billing_contact' => 'boolean',
            'portal_access' => 'boolean',
            'receives_notifications' => 'boolean',
        ];
    }

    public function moduleSlug(): string
    {
        return 'clients';
    }

    protected function activityModule(): ?string
    {
        return 'clients';
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['client_id', ...$this->getFillable(), 'user_id', 'is_primary', 'portal_access'];
    }

    /**
     * @return array<int, string>
     */
    protected function activityIgnoredAttributes(): array
    {
        return ['created_at', 'updated_at', 'updated_by', 'email_normalized', 'phone_normalized'];
    }

    /**
     * @return array<string, array{0: string, 1: 'email'|'phone'}>
     */
    protected function normalizedContactColumns(): array
    {
        return [
            'email' => ['email_normalized', 'email'],
            'phone' => ['phone_normalized', 'phone'],
        ];
    }

    /**
     * A contact has no country of its own: a local number is expanded with its client's.
     */
    protected function contactCountryCode(): ?string
    {
        $client = $this->relationLoaded('client')
            ? $this->getRelation('client')
            : ($this->getAttribute('client_id') === null
                ? null
                : Client::withTrashed()->select(['id', 'country_code'])->find($this->getAttribute('client_id')));

        $code = $client instanceof Client ? $client->getAttribute('country_code') : null;

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    /**
     * @return list<string>
     */
    protected function generatedGuardColumns(): array
    {
        return ['primary_guard'];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Holds a login that may reach the client panel as this client (subject to the client's own portal state).
     */
    public function hasPortalAccess(): bool
    {
        return (bool) $this->getAttribute('portal_access') && $this->getAttribute('user_id') !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }

    /**
     * The contact's portal login.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<ClientContact>  $query
     * @return Builder<ClientContact>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_primary'), true);
    }

    /**
     * @param  Builder<ClientContact>  $query
     * @return Builder<ClientContact>
     */
    public function scopeWithPortalAccess(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('portal_access'), true)
            ->whereNotNull($query->qualifyColumn('user_id'));
    }
}
