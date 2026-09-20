<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Which side of the business a commission rule governs (`collaborator_commission_settings.scope`,
 * finance spine §3).
 *
 * Two, and deliberately only two: the institute sells courses to students, and the software house sells
 * projects to clients. A partner may have a different rate on each, which is why the rule table is keyed
 * on this rather than one rate per collaborator.
 */
enum CommissionScope: string
{
    use HasOptions;

    case Student = 'student';
    case Project = 'project';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student commission',
            self::Project => 'Project commission',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Student => 'violet',
            self::Project => 'sky',
        };
    }

    /**
     * The settings prefix this scope reads its defaults from.
     */
    public function defaultsPrefix(): string
    {
        return 'collaborator.default_'.$this->value.'_commission_';
    }

    public function baseSettingKey(): string
    {
        return 'collaborator.'.$this->value.'_commission_base';
    }
}
