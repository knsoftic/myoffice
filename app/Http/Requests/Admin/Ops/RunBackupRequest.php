<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ops;

use App\Enums\BackupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Back up now" — and the reason that makes the archive worth keeping (phase-24-25 section 4.2,
 * section 8.1).
 *
 * **The reason is mandatory, and it is the whole purpose of this class.** `BackupTrigger::Manual`
 * answers `requiresReason()` with true and `BackupService::run()` throws without one, so this Form
 * Request is not what makes the rule true — it is what makes the rule *readable*, as a field error on
 * the modal instead of a 500 from a service. A manual backup is a claim about a moment ("before I
 * rewrote the fee structure", "the client says their project vanished"), and six months later that
 * sentence is the only thing that distinguishes it from the nightly run beside it. An archive with no
 * reason on it is a file with a date.
 *
 * `min:10` rather than `min:1`: "test", "x" and "backup" are all present and all useless, and the
 * only cost of the floor is that somebody types four more words on the one screen where four more
 * words are worth having. The ceiling is 255 because `backup_runs.reason` is `string(255)` and the
 * column must never be the thing that truncates an explanation (section 2.1).
 *
 * **The type is validated against the enum, never trusted from the form.** `type` reaches
 * `BackupService::run()` and decides whether a dump, a file sweep or both happen; a value outside
 * {@see BackupType} would resolve to nothing and produce an archive containing nothing.
 */
final class RunBackupRequest extends FormRequest
{
    /**
     * The route already carries `can:backups.create` and `throttle:backup-run`. This is the second
     * line, for the same reason every Form Request in this system re-asks: a route's middleware is one
     * edit away from being someone else's copy-paste.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('backups.create') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(BackupType::values())],
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why you are taking this backup. It is what tells somebody in six '
                .'months which archive to reach for, and there is no default for "why".',
            'reason.min' => 'A few more words: "before the fee change" is useful, "test" is not.',
            'type.in' => 'Choose database, files, or both. An archive of neither is not a backup.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'backup type',
            'reason' => 'reason',
        ];
    }

    /**
     * The validated type as the enum the service takes.
     *
     * Resolved here rather than in the controller so the cast happens once, next to the rule that
     * guarantees it can happen at all.
     */
    public function backupType(): BackupType
    {
        return BackupType::from((string) $this->validated('type'));
    }

    /**
     * The reason, trimmed.
     *
     * Trimmed here rather than in the service: a reason of twelve spaces passes `min:10` on the raw
     * string and would then be stored as an empty explanation.
     */
    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    /**
     * Whitespace is normalised before the rules run, so `min:10` judges what will actually be stored.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }
}
