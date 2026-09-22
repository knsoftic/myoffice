<?php

declare(strict_types=1);

namespace App\DataObjects\Institute;

/**
 * May this person open this material, and if not, why not (phase-19-23 §6.5, INV-19-3).
 *
 * **The refusal reason is a named constant, not a sentence.** §6.5 requires the UI and the tests to
 * agree on why access was denied, and a string assembled at the refusal site cannot be asserted against
 * without pinning its wording. The reason is the machine-readable fact; `message()` is the sentence,
 * and changing the sentence never breaks a test.
 *
 * **A denial is not always a 404.** `fee_blocked` and `outside_window` are states the student should be
 * *told* about — they have a right to the material and something temporary is in the way. `not_targeted`
 * is different: the material was never theirs, and saying so would confirm it exists. `isDiscoverable()`
 * is what separates the two, and the controller reads it rather than deciding again.
 */
final readonly class MaterialGrant
{
    /** The material is not published, so only staff and its author see it at all. */
    public const NOT_PUBLISHED = 'not_published';

    /** Published, but `available_from` has not arrived or `available_until` has passed. */
    public const OUTSIDE_WINDOW = 'outside_window';

    /** No target resolves to this person. They were never an audience for it. */
    public const NOT_TARGETED = 'not_targeted';

    /** Targeted once, but the enrollment has ended and the post-batch grace has run out. */
    public const ENROLLMENT_EXPIRED = 'enrollment_expired';

    /** Entitled, but the fee block is on. Temporary, and the student is told. */
    public const FEE_BLOCKED = 'fee_blocked';

    /** The whole module is switched off — including for a Super Admin (`Gate::before` step 1). */
    public const MODULE_DISABLED = 'module_disabled';

    /** A link material has no bytes; asking to stream one is a category error. */
    public const NOT_A_FILE = 'not_a_file';

    /** The row says a file is there and the disk disagrees. */
    public const FILE_MISSING = 'file_missing';

    private function __construct(
        public bool $allowed,
        public ?string $reason = null,
        public bool $inline = false,
    ) {}

    /**
     * @param  bool  $inline  whether this viewer gets it rendered rather than downloaded — which is
     *                        what `is_downloadable = false` means, and §6.5 is plain that it is
     *                        deterrence rather than DRM
     */
    public static function allow(bool $inline = false): self
    {
        return new self(allowed: true, inline: $inline);
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, reason: $reason);
    }

    /**
     * Whether the person may be told this material exists.
     *
     * `not_targeted` is the one refusal that must not be explained: the material was never aimed at
     * them, and a message saying "you are not in the audience for this" confirms there is something to
     * be in the audience for. Everything else names a condition they can do something about — wait for
     * the window, clear the fee, ask why the module is off.
     */
    public function isDiscoverable(): bool
    {
        return $this->reason !== self::NOT_TARGETED && $this->reason !== self::NOT_PUBLISHED;
    }

    /** What the toast says. Never used to decide anything — `reason` is. */
    public function message(): string
    {
        return match ($this->reason) {
            self::OUTSIDE_WINDOW => 'This material is not available yet. Check back after its release date.',
            self::ENROLLMENT_EXPIRED => 'Your access to this batch’s material has ended.',
            self::FEE_BLOCKED => 'Material is unavailable while there is an outstanding balance on your account.',
            self::MODULE_DISABLED => 'Course materials are switched off. Ask an administrator.',
            self::NOT_A_FILE => 'This material is a link, not a file.',
            self::FILE_MISSING => 'The file is no longer available. Tell your teacher.',
            default => 'This material is not available.',
        };
    }
}
