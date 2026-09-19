<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Where a moderated record came from (phase-04 §3): `testimonials.source`, `student_reviews.source`.
 *
 * `student_panel` and `client_panel` are reserved so a later phase can add self-submission without a
 * migration (§9.3, §12.2 Q4); no panel writes these tables in Phase 4.
 */
enum ContentSource: string
{
    use HasOptions;

    case Admin = 'admin';
    case PublicForm = 'public_form';
    case ClientPanel = 'client_panel';
    case StudentPanel = 'student_panel';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::PublicForm => 'Public form',
            self::ClientPanel => 'Client panel',
            self::StudentPanel => 'Student panel',
            self::Import => 'Import',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'slate',
            self::PublicForm => 'amber',
            self::ClientPanel => 'indigo',
            self::StudentPanel => 'sky',
            self::Import => 'violet',
        };
    }

    /**
     * Must a record from this source wait in the moderation queue?
     *
     * False only for `admin` and `import` (staff acts, which `website.testimonial_auto_approve` may
     * create approved). A public or panel submission is always `pending`, whatever that setting says.
     */
    public function requiresModeration(): bool
    {
        return ! in_array($this, [self::Admin, self::Import], true);
    }
}
