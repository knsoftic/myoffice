<?php

declare(strict_types=1);

namespace App\DataObjects\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a dispatch is for (phase-19-23 §6.19 `NotificationService::dispatch()`).
 *
 * **There are only three honest ways to name an audience**, and this is all three: the people you
 * already hold, the people who hold a permission, and "whatever the event's own declaration says".
 * A caller that could pass raw SQL, or a query builder, would be a caller that could notify
 * everybody by writing one clause wrong — and the service has no way to tell a mistake from an
 * intention.
 *
 * **Models are accepted because phases hold them, not users.** A fee reminder is about a student, a
 * commission about a collaborator; both carry `user_id`, which may be null for somebody who has
 * never been given a login. `NotificationService` drops those, quietly and by design — there is
 * nobody to tell.
 */
final readonly class AudienceInput
{
    private function __construct(
        /** @var list<User|Model|int> */
        public array $subjects,
        public ?string $permission,
        public bool $fromRegistry,
    ) {}

    /**
     * @param  User|Model|int|iterable<mixed>  $subjects
     */
    public static function of(User|Model|int|iterable $subjects): self
    {
        return new self(self::flatten($subjects), null, false);
    }

    /** "Tell whoever may act on this." */
    public static function permission(string $permission): self
    {
        return new self([], $permission, false);
    }

    /** Use the audience the event itself declares — the default for a scheduled or system dispatch. */
    public static function fromRegistry(): self
    {
        return new self([], null, true);
    }

    public static function none(): self
    {
        return new self([], null, false);
    }

    /** Add more people to an audience already named — a permission list plus the person concerned. */
    public function plus(User|Model|int|iterable $subjects): self
    {
        return new self(
            array_merge($this->subjects, self::flatten($subjects)),
            $this->permission,
            $this->fromRegistry,
        );
    }

    public function isEmpty(): bool
    {
        return $this->subjects === [] && $this->permission === null && ! $this->fromRegistry;
    }

    /**
     * @param  User|Model|int|iterable<mixed>  $subjects
     * @return list<User|Model|int>
     */
    private static function flatten(User|Model|int|iterable $subjects): array
    {
        if ($subjects instanceof User || $subjects instanceof Model || is_int($subjects)) {
            return [$subjects];
        }

        $out = [];

        foreach ($subjects as $subject) {
            if ($subject instanceof User || $subject instanceof Model || is_int($subject)) {
                $out[] = $subject;

                continue;
            }

            // A numeric string from a form or a JSON payload. Anything else is not somebody.
            if (is_string($subject) && ctype_digit($subject)) {
                $out[] = (int) $subject;
            }
        }

        return $out;
    }
}
