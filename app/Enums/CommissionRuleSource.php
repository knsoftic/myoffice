<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where the rate that produced a commission came from (finance spine §3, [D-FS-9], F-5.6).
 *
 * **Three cases, and there is deliberately no `global_default`.** A commission the system paid because
 * nobody had set a rate is a commission nobody decided on, and the first time it is questioned there is
 * no answer to give. When no rule is effective, the engine skips with
 * `CommissionSkipReason::NoEffectiveRule` and says so on the skip report — a visible nothing rather than
 * an invisible something.
 */
enum CommissionRuleSource: string
{
    use HasOptions;

    case CollaboratorRule = 'collaborator_rule';
    case ProjectOverride = 'project_override';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::CollaboratorRule => 'The collaborator\'s rule',
            self::ProjectOverride => 'A rate set on the project',
            self::Manual => 'Entered by hand',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CollaboratorRule => 'sky',
            self::ProjectOverride => 'violet',
            self::Manual => 'amber',
        };
    }

    /**
     * Did a rule version produce this, or a person?
     */
    public function isFromARuleVersion(): bool
    {
        return $this === self::CollaboratorRule;
    }
}
