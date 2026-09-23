<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Enums\ConversationScope;

/**
 * Whether two people may talk, and why not (phase-19-23 §6.18, requirement §94).
 *
 * **A refusal carries a sentence, not a boolean.** "Not permitted" sends somebody to ask an
 * administrator who will not know either; naming the route that *is* open — a support ticket, their
 * own teacher, the office — is the difference between a refusal and a dead end. Every `deny()` below
 * therefore takes a reason, and there is no constructor path that produces a refusal without one.
 *
 * **`scope` is null on a refusal and set on an allowance**, which is what makes the return value
 * usable directly: `ConversationService::startDirect()` writes `$decision->scope` into
 * `conversations.pair_scope` and never has to ask the matrix a second question.
 */
final readonly class MessagingDecision
{
    private function __construct(
        public bool $allowed,
        public ?ConversationScope $scope,
        public string $reason,
    ) {}

    /** They may talk, under this pairing. */
    public static function allow(ConversationScope $scope): self
    {
        return new self(true, $scope, $scope->describe());
    }

    /**
     * They may not, and here is what to tell them.
     *
     * The reason is written for the person who tried, not for a log — they are mid-task and need to
     * know what to do instead.
     */
    public static function deny(string $reason): self
    {
        return new self(false, null, $reason);
    }

    /** The scope, or an exception — for the caller that has already checked `allowed`. */
    public function scopeOrFail(): ConversationScope
    {
        if ($this->scope === null) {
            throw new \LogicException('This decision is a refusal and has no scope: '.$this->reason);
        }

        return $this->scope;
    }
}
