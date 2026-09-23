<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * Why a student may — or may not — be given a certificate (phase-19-23 §6.14, requirement §84).
 *
 * **Every rule carries its actual value, not just a verdict.** "Not eligible" sends somebody to ask a
 * developer; "attendance 68.50%, needs 75.00%" is answered by the screen. That is the whole reason
 * this is a list of rows rather than a boolean, and it is why `actual` is a string rather than being
 * formatted at construction: the caller decides whether it is going on a screen, into a JSON snapshot
 * or into a log line.
 *
 * **The report is snapshotted onto the certificate at issue time** (`eligibility_snapshot`), so "why
 * was this issued?" stays answerable a year later, when the settings have changed and the student's
 * attendance has moved on. An override records the same report *with its failures intact* plus a
 * reason — an override that quietly rewrote the report to say "eligible" would be worse than no
 * record at all.
 */
final readonly class EligibilityReport
{
    /**
     * @param  list<EligibilityRule>  $rules
     * @param  string|null  $overrideReason  set only when somebody issued against a failing report
     */
    public function __construct(
        public array $rules = [],
        public ?string $overrideReason = null,
        public ?int $overriddenBy = null,
    ) {}

    /** Every rule passed. An empty report is **not** eligible — it means nothing was checked. */
    public function eligible(): bool
    {
        if ($this->rules === []) {
            return false;
        }

        foreach ($this->rules as $rule) {
            if (! $rule->passed) {
                return false;
            }
        }

        return true;
    }

    /** Eligible, or issued anyway with a recorded reason. */
    public function permitsIssue(): bool
    {
        return $this->eligible() || $this->overrideReason !== null;
    }

    public function wasOverridden(): bool
    {
        return $this->overrideReason !== null;
    }

    /** @return list<EligibilityRule> */
    public function failures(): array
    {
        return array_values(array_filter($this->rules, static fn (EligibilityRule $r): bool => ! $r->passed));
    }

    /**
     * The same report, with an override recorded against it.
     *
     * **The rules are carried over unchanged.** Overriding does not make a failing rule pass; it
     * records that somebody with the right to do so decided to issue anyway, and says who and why.
     */
    public function overriddenBy(int $userId, string $reason): self
    {
        return new self($this->rules, trim($reason), $userId);
    }

    /**
     * The shape stored in `certificates.eligibility_snapshot`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible(),
            'overridden' => $this->wasOverridden(),
            'override_reason' => $this->overrideReason,
            'overridden_by' => $this->overriddenBy,
            'rules' => array_map(static fn (EligibilityRule $r): array => $r->toArray(), $this->rules),
        ];
    }

    /** One sentence for a toast or a log line. */
    public function summary(): string
    {
        if ($this->eligible()) {
            return 'Every eligibility rule passed.';
        }

        $failures = $this->failures();

        if ($failures === []) {
            return 'Nothing was checked, so eligibility cannot be confirmed.';
        }

        return sprintf(
            '%d rule%s not met: %s.',
            count($failures),
            count($failures) === 1 ? '' : 's',
            implode('; ', array_map(static fn (EligibilityRule $r): string => $r->message, $failures)),
        );
    }
}
