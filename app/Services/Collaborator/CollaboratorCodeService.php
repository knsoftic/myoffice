<?php

declare(strict_types=1);

namespace App\Services\Collaborator;

use App\Models\Collaborator\Collaborator;
use App\Services\Collaborator\Exceptions\CollaboratorRuleException;
use App\Services\Collaborator\Exceptions\ReferralCodeLockedException;
use App\Services\Collaborator\Exceptions\ReferralCodeTakenException;
use App\Services\Finance\DocumentNumberService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * The two codes a collaborator carries, and the URLs built from one of them (phase-08-09 §6.1).
 *
 * **`normalizeReferralCode()` is the one normaliser.** The public URL, the typed form field, the admin
 * form and the lookup all call it, which is what makes `col-1024`, ` COL-1024 ` and `COL--1024` one
 * code rather than four. A second normalisation written anywhere else would be a way for a partner's
 * link to stop finding them.
 *
 * **Numbers come from Phase 5's `DocumentNumberService`** (D27, F-4.1) — reused, never re-created, with
 * this caller passing its own `'%04d'` pad. There is no second `FOR UPDATE` counter in the codebase.
 */
final class CollaboratorCodeService
{
    /**
     * `[A-Z0-9][A-Z0-9-]{3,31}` — a code has to start with something typeable and be long enough that a
     * two-character typo cannot land on somebody else's.
     */
    private const FORMAT = '/^[A-Z0-9][A-Z0-9-]{3,31}$/';

    /**
     * The tables whose rows lock a referral code (INV-C2). The first exists from this phase; the other
     * two are the spine's and arrive with Phase 10, so each is checked for existence first — a code must
     * not be declared free merely because the table that would have held its evidence is not built yet.
     *
     * @var array<string, string>
     */
    private const REFERENCING = [
        'collaborator_referral_visits' => 'collaborator_id',
        'collaborator_referrals' => 'collaborator_id',
        'collaborator_commission_ledger_entries' => 'collaborator_id',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The next `COL-1001`, reserved under the counter's row lock inside the caller's transaction.
     */
    public function nextCollaboratorCode(): string
    {
        return $this->numbers->next(
            'collaborator.collaborator_code_prefix',
            'collaborator.collaborator_code_next_number',
            '%04d',
        );
    }

    /**
     * Trim, strip every internal space, upper-case, and collapse repeated hyphens.
     *
     * Collapsing is deliberate rather than tidy: a partner reading a code off a printed flyer types
     * `COL--1024` often enough that treating it as a different code would lose the attribution.
     */
    public function normalizeReferralCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = (string) preg_replace('/\s+/', '', $code);

        return (string) preg_replace('/-{2,}/', '-', $code);
    }

    /**
     * Is this a code at all? Checked before availability, so a malformed string is never used as a
     * lookup key against the table.
     */
    public function isWellFormed(string $code): bool
    {
        return preg_match(self::FORMAT, $this->normalizeReferralCode($code)) === 1;
    }

    /**
     * Refuse a code somebody already holds.
     *
     * The message names the code and **nothing about the holder** — a form that answered "taken by Nova
     * Digital" would be a way to enumerate the partner list from outside.
     */
    public function assertAvailable(string $code, ?Collaborator $except = null): void
    {
        $code = $this->normalizeReferralCode($code);

        $taken = Collaborator::withTrashed()
            ->where('referral_code', $code)
            ->when($except?->exists, static fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->exists();

        if ($taken) {
            throw ReferralCodeTakenException::forCode($code);
        }
    }

    /**
     * Has anything referenced this collaborator yet? Once something has, the referral code is frozen
     * (INV-C2): every attribution row snapshotted it, and moving it now would make those rows name
     * something that no longer exists.
     */
    public function referralCodeIsLocked(Collaborator $collaborator): bool
    {
        foreach (self::REFERENCING as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if ($this->db->table($table)->where($column, $collaborator->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Move a collaborator onto a vanity code.
     *
     * Four refusals, in the order that gives the clearest answer: the setting is off, something already
     * references the collaborator, the code is not a code, or somebody else holds it.
     */
    public function changeReferralCode(Collaborator $collaborator, string $code, string $reason): Collaborator
    {
        $code = $this->normalizeReferralCode($code);

        if (! (bool) setting('collaborator.referral_code_editable', true)) {
            throw ReferralCodeLockedException::notEditable();
        }

        if ($this->referralCodeIsLocked($collaborator)) {
            throw ReferralCodeLockedException::because(
                $collaborator->referral_code,
                'a visit, a referral or a commission entry already names it, and those rows snapshotted '
                .'the code as it was (INV-C2)',
            );
        }

        if (! $this->isWellFormed($code)) {
            // Not "locked" — badly formed. The two refusals read differently on the form because they
            // ask for different things: one for a different code, one for a correction.
            throw CollaboratorRuleException::refuse('referral_code',
                'A referral code starts with a letter or a digit and is 4 to 32 characters of A-Z, 0-9 '
                .'and hyphens.');
        }

        $this->assertAvailable($code, $collaborator);

        if ($code === $collaborator->referral_code) {
            return $collaborator;
        }

        return $this->db->transaction(function () use ($collaborator, $code, $reason): Collaborator {
            // The model refuses this save unless the flag is set: the check lives there so that *any*
            // other path into the column is a mistake, and this is the one place allowed to set it.
            // `withReason()` puts the old and the new code, the actor, the IP and the mandatory reason
            // on one `activity_log` row (D13) — there is no second audit store to keep in step.
            $collaborator->referralCodeChangeIsAuthorised = true;
            $collaborator->withReason($reason)->forceFill(['referral_code' => $code])->save();
            $collaborator->referralCodeChangeIsAuthorised = false;

            return $collaborator->fresh();
        });
    }

    /**
     * The URL that carries this collaborator's code.
     *
     * Appends to an existing query string rather than overwriting it, so a campaign link such as
     * `/courses/php-basics?utm_source=flyer` keeps its parameters and gains `?ref=` alongside them.
     */
    public function referralUrl(Collaborator $collaborator, ?string $path = null): string
    {
        $base = $this->baseUrl();
        $path = $path ?? (string) setting('collaborator.referral_landing_path', '/admission');
        $param = (string) setting('collaborator.referral_query_param', 'ref');

        $fragment = '';

        if (str_contains($path, '#')) {
            [$path, $after] = explode('#', $path, 2);
            $fragment = '#'.$after;
        }

        $separator = str_contains($path, '?') ? '&' : '?';

        return $base.'/'.ltrim($path, '/').$separator.rawurlencode($param).'='.rawurlencode($collaborator->referral_code).$fragment;
    }

    /**
     * The site these links point at: the canonical base URL a business configured, falling back to the
     * app URL. Public, because a screen that previews a link live has to build it from the **same** base
     * the real link uses — one that guessed `config('app.url')` would show a host the link never has.
     */
    public function baseUrl(): string
    {
        return rtrim((string) (setting('seo.canonical_base_url') ?: config('app.url')), '/');
    }

    /**
     * The three §38 surfaces, for the copy-link UI: the student admission link, the client inquiry link,
     * and a builder for any other page a partner wants to send people to.
     *
     * @return array{admission: string, inquiry: string, builder: callable(string): string}
     */
    public function referralUrls(Collaborator $collaborator): array
    {
        return [
            'admission' => $this->referralUrl($collaborator, (string) setting('collaborator.referral_landing_path', '/admission')),
            'inquiry' => $this->referralUrl($collaborator, (string) setting('collaborator.referral_inquiry_landing_path', '/contact')),
            'builder' => fn (string $path): string => $this->referralUrl($collaborator, $path),
        ];
    }
}
