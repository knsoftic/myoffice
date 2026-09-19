<?php

declare(strict_types=1);

namespace App\Models\Crm\Concerns;

use App\Support\ContactNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the `*_normalized` comparison columns true on every save ([D-P5-5], D29, phase-05 §2.1 "written on save").
 *
 * The services normalise through `ContactNormalizer` as well; this hook is the net that makes the columns
 * impossible to leave stale — an import, a profile edit or a seeder that writes `phone` directly still rewrites
 * `phone_normalized`. It recomputes only when a source column (or `country_code`, which changes how a local
 * number expands) is dirty, or the row is new. Pure PHP, no query.
 *
 * A model declares its map:
 *
 *   protected function normalizedContactColumns(): array
 *   {
 *       return ['email' => ['email_normalized', 'email'], 'phone' => ['phone_normalized', 'phone']];
 *   }
 *
 * @mixin Model
 */
trait NormalizesContacts
{
    public static function bootNormalizesContacts(): void
    {
        static::saving(static function (Model $model): void {
            /** @var Model&self $model */
            $model->refreshNormalizedContacts();
        });
    }

    /**
     * source column => [normalized column, kind (`email` | `phone`)].
     *
     * @return array<string, array{0: string, 1: 'email'|'phone'}>
     */
    abstract protected function normalizedContactColumns(): array;

    /**
     * Recompute the normalized columns whose source changed (all of them on a new row).
     */
    public function refreshNormalizedContacts(bool $force = false): void
    {
        $countryChanged = $this->isDirty('country_code');

        foreach ($this->normalizedContactColumns() as $source => [$target, $kind]) {
            $mustRefresh = $force
                || ! $this->exists
                || $this->isDirty($source)
                || ($kind === 'phone' && $countryChanged);

            if (! $mustRefresh) {
                continue;
            }

            $raw = $this->getAttribute($source);
            $raw = is_scalar($raw) ? (string) $raw : null;

            $this->setAttribute($target, $kind === 'email'
                ? ContactNormalizer::email($raw)
                : ContactNormalizer::phone($raw, $this->contactCountryCode()));
        }
    }

    /**
     * The ISO-3166 alpha-2 code a local number is expanded with. Rows without their own `country_code` (client
     * contacts) override this to borrow their parent's.
     */
    protected function contactCountryCode(): ?string
    {
        $code = $this->getAttribute('country_code');

        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }
}
