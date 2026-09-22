<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What an assignment will accept as work (phase-19-23 §3.1, §80).
 *
 * **The four booleans exist so the Form Request, the policy, the screen and the service all ask the
 * same question of the same object.** "May this submission carry a file" and "must it" are different
 * questions, and a rule written out four times is a rule that eventually disagrees with itself — the
 * `file_or_text` case in particular needs *at least one*, which no single boolean can express.
 */
enum SubmissionType: string
{
    use HasOptions;

    /** A file, and nothing else is accepted in its place. */
    case File = 'file';

    /** Typed into the box. No upload. */
    case Text = 'text';

    /** Either will do — but not neither. */
    case FileOrText = 'file_or_text';

    /** Both, and a submission missing one of them is incomplete. */
    case FileAndText = 'file_and_text';

    public function label(): string
    {
        return match ($this) {
            self::File => 'A file',
            self::Text => 'Typed text',
            self::FileOrText => 'A file or typed text',
            self::FileAndText => 'A file and typed text',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::File => 'sky',
            self::Text => 'violet',
            self::FileOrText => 'brand',
            self::FileAndText => 'amber',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::File => 'Students upload their work.',
            self::Text => 'Students type their answer. Nothing is uploaded.',
            self::FileOrText => 'Whichever suits the work — at least one of the two.',
            self::FileAndText => 'Both are required: the file, and a note explaining it.',
        };
    }

    /** A submission without a file is incomplete. */
    public function requiresFile(): bool
    {
        return $this === self::File || $this === self::FileAndText;
    }

    /** A submission without text is incomplete. */
    public function requiresText(): bool
    {
        return $this === self::Text || $this === self::FileAndText;
    }

    public function allowsFile(): bool
    {
        return $this !== self::Text;
    }

    public function allowsText(): bool
    {
        return $this !== self::File;
    }

    /**
     * The `file_or_text` rule, which none of the four booleans above can state on its own: at least one
     * of the two has to be present.
     */
    public function requiresEither(): bool
    {
        return $this === self::FileOrText;
    }
}
