<?php

declare(strict_types=1);

namespace App\Models\Institute;

use App\Enums\VerificationResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One attempt to verify a certificate from the public page (phase-19-23 §2.15, requirement §84).
 *
 * **Append-only: `created_at` only, no `updated_at`, no `deleted_at`, no blameable.** CLAUDE.md §3
 * lists audit and log tables among the categories that carry no `deleted_at`, and D19 says never add
 * one back: a nullable `deleted_at` on a log lets one `->delete()` hide a row from every aggregate
 * while the cached `verification_count` on the certificate keeps the number. This is the rate
 * limiter's evidence and §106's log of a public endpoint; evidence that can be edited is not evidence.
 *
 * **A miss is the interesting row.** `certificate_id` is nullable because a submitted code that
 * matches nothing has no certificate to point at — and a run of those from one address is somebody
 * enumerating codes. `submitted_code` is stored verbatim so the pattern is visible rather than
 * inferred.
 *
 * **There is no actor**, because the endpoint is unauthenticated. The IP address is the closest thing
 * to one, which is exactly why `idx_cv_ip` exists.
 */
class CertificateVerification extends Model
{
    protected $table = 'certificate_verifications';

    /** `created_at` only — see the class note. */
    public const UPDATED_AT = null;

    /**
     * Written by `CertificateVerificationService` and nothing else. Listed rather than guarded so a
     * mass-assignment from a request could not invent a `result`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'certificate_id',
        'submitted_code',
        'result',
        'ip_address',
        'user_agent',
        'device',
        'referer',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => VerificationResult::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $row): never {
            throw new LogicException(
                'A verification attempt is a fact about a moment and is never edited. If the row is '
                .'wrong, the thing to fix is whatever wrote it.'
            );
        });

        static::deleting(static function (self $row): never {
            throw new LogicException(
                'A verification attempt is never deleted individually — it is the rate limiter’s '
                .'evidence. The retention command prunes the log wholesale, by age.'
            );
        });
    }

    public function moduleSlug(): string
    {
        return 'certificates';
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'certificate_id');
    }

    /** Attempts that found a live certificate. */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('result', VerificationResult::Valid->value);
    }

    /**
     * Everything one address has tried lately — what the rate limiter counts.
     *
     * Reads `idx_cv_ip`, which is why that index exists rather than being inferred from
     * `idx_cv_result`.
     */
    public function scopeFromAddressSince(Builder $query, ?string $ip, Carbon $since): Builder
    {
        return $query
            ->where('ip_address', $ip)
            ->where('created_at', '>=', $since);
    }

    /**
     * Misses only — the enumeration signal.
     *
     * A genuine visitor scanning the QR code on their own certificate produces one `valid`. Somebody
     * walking the code space produces a stream of `not_found`, and the difference is worth being able
     * to query directly.
     */
    public function scopeMisses(Builder $query): Builder
    {
        return $query->whereIn('result', [
            VerificationResult::NotFound->value,
            VerificationResult::Throttled->value,
        ]);
    }
}
