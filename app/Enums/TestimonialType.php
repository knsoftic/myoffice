<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Who gave a testimonial (phase-04 §3, `testimonials.type`).
 *
 * The type drives the form's field set (§6.11): a `client` testimonial requires `author_company`, a
 * `student` one requires `course_name`; `other` requires neither.
 */
enum TestimonialType: string
{
    use HasOptions;

    case Client = 'client';
    case Student = 'student';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Student => 'Student',
            self::Other => 'Other',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Client => 'indigo',
            self::Student => 'sky',
            self::Other => 'slate',
        };
    }

    /**
     * `author_company` is required for this type (§6.11 `StoreTestimonialRequest`).
     */
    public function requiresCompany(): bool
    {
        return $this === self::Client;
    }

    /**
     * `course_name` is required for this type (§6.11 `StoreTestimonialRequest`).
     */
    public function requiresCourse(): bool
    {
        return $this === self::Student;
    }
}
