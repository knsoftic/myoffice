<?php

declare(strict_types=1);

namespace App\DataObjects\Files;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The owning row and the directory its file belongs in (phase-19-23 §6.1, §6.3).
 *
 * **§6.3's path patterns are binding, so they are written here once and nowhere else.** Every named
 * constructor below is one row of that table, and no caller assembles a path itself.
 *
 * The directory is built from integers and known literals only. The constructor refuses anything else —
 * not because a caller is expected to pass a request value, but because an object that *cannot* hold a
 * traversal is a stronger guarantee than a convention that it never receives one. §6.3 says it plainly:
 * the ids come from the database and the extension from the sniffed MIME, so path traversal has no
 * surface at all.
 *
 * Note for the reader: "target" here means *where the bytes go*. `MaterialTargetType` — the other target
 * in this phase — means *who a material is aimed at*. They are unrelated; the contract names both.
 */
final readonly class FileTarget
{
    public string $directory;

    /**
     * @param  list<string|int>  $segments  literals must match `[a-z0-9_-]+`; ids are cast from int
     * @param  Model|null  $owner  the row the file hangs off, for the §6.1 step-10 activity entry
     * @param  string|null  $module  the module slug that entry is filed under
     * @param  string|null  $fixedName  a server-chosen name for generated artefacts (a certificate is
     *                                  `{certificate_number}.pdf`, not a ULID). Uploads ignore it.
     */
    public function __construct(
        array $segments,
        public ?Model $owner = null,
        public ?string $module = null,
        public ?string $fixedName = null,
    ) {
        $clean = [];

        foreach ($segments as $segment) {
            if (is_int($segment)) {
                if ($segment < 1) {
                    throw new InvalidArgumentException('A file target id must be a positive integer.');
                }

                $clean[] = (string) $segment;

                continue;
            }

            if (preg_match('/^[a-z0-9_-]+$/', $segment) !== 1) {
                throw new InvalidArgumentException(sprintf('"%s" is not a usable file target segment.', $segment));
            }

            $clean[] = $segment;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('A file target needs at least one segment.');
        }

        $this->directory = implode('/', $clean);
    }

    /**
     * `institute/courses/{course_id}/materials/{YYYY}/{MM}`.
     *
     * The year and month partition keeps one course's directory from growing without bound over the
     * years it runs, which is the only reason they are in the path — nothing reads them back.
     */
    public static function courseMaterial(int $courseId, ?Model $owner = null, ?CarbonImmutable $on = null): self
    {
        $on ??= CarbonImmutable::now();

        return new self(
            ['institute', 'courses', $courseId, 'materials', $on->format('Y'), $on->format('m')],
            owner: $owner,
            module: 'course_materials',
        );
    }

    /** `institute/assignments/{assignment_id}/brief`. */
    public static function assignmentBrief(int $assignmentId, ?Model $owner = null): self
    {
        return new self(['institute', 'assignments', $assignmentId, 'brief'], owner: $owner, module: 'assignments');
    }

    /**
     * `institute/assignments/{assignment_id}/submissions/{student_id}/{attempt_no}`.
     *
     * The attempt is in the path because a resubmission does not overwrite its predecessor
     * (INV-19-5) — the old attempt keeps its own directory, its files and its marks.
     */
    public static function submission(int $assignmentId, int $studentId, int $attemptNo, ?Model $owner = null): self
    {
        return new self(
            ['institute', 'assignments', $assignmentId, 'submissions', $studentId, max(1, $attemptNo)],
            owner: $owner,
            module: 'assignment_submissions',
        );
    }

    /** `institute/assignments/{assignment_id}/feedback/{submission_id}`. */
    public static function feedback(int $assignmentId, int $submissionId, ?Model $owner = null): self
    {
        return new self(
            ['institute', 'assignments', $assignmentId, 'feedback', $submissionId],
            owner: $owner,
            module: 'assignment_submissions',
        );
    }

    /** `institute/id-cards/photos/{student_id}` — inlined into the PDF, never served as a URL. */
    public static function idCardPhoto(int $studentId, ?Model $owner = null): self
    {
        return new self(['institute', 'id-cards', 'photos', $studentId], owner: $owner, module: 'student_id_cards');
    }

    /** `support/tickets/{ticket_id}`. */
    public static function ticketAttachment(int $ticketId, ?Model $owner = null): self
    {
        return new self(['support', 'tickets', $ticketId], owner: $owner, module: 'support_tickets');
    }

    /** `support/conversations/{conversation_id}`. */
    public static function messageAttachment(int $conversationId, ?Model $owner = null): self
    {
        return new self(['support', 'conversations', $conversationId], owner: $owner, module: 'conversations');
    }

    /** `support/meetings/{meeting_id}`. */
    public static function meetingAttachment(int $meetingId, ?Model $owner = null): self
    {
        return new self(['support', 'meetings', $meetingId], owner: $owner, module: 'meetings');
    }

    /** `templates/{type}` on the **public** disk — the one public row in §6.3. */
    public static function printTemplateBackground(string $type, ?Model $owner = null): self
    {
        return new self(['templates', $type], owner: $owner, module: 'print_templates');
    }

    /** The full path a stored file takes, given the server-chosen name. */
    public function pathFor(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    /** What the §6.1 step-10 activity entry calls the thing this file belongs to. */
    public function ownerDescription(): string
    {
        return $this->owner === null
            ? $this->directory
            : class_basename($this->owner).'#'.((string) ($this->owner->getKey() ?? '?'));
    }
}
