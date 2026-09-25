<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ops;

use App\Enums\RestoreTarget;
use App\Models\Ops\BackupRun;
use App\Services\Ops\BackupPathResolver;
use App\Support\Format;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The three gates a restore form can hold, in the one place they cannot be skipped (phase-24-25
 * section 6.10.5, section 8.3).
 *
 * Section 6.10.5 lists four gates. The first — `backups.restore`, Super Admin's alone — belongs to the
 * route and `BackupRunPolicy`. The second, re-authentication, belongs to `password.confirm` on both the
 * form and the submit. **The other two are this class, and neither of them is cosmetic:**
 *
 * 1.  **The typed confirmation phrase, compared byte for byte and case included.** The phrase is built
 *     from `backup.restore_confirmation_phrase` with `{database}` and `{date}` substituted, so what the
 *     operator types names the database they are about to overwrite and the age of the archive they are
 *     overwriting it with. Compared with `!==` on the exact strings: a case-insensitive or trimmed
 *     comparison turns a sentence somebody had to read into a box somebody can slap.
 * 2.  **A written reason of at least twenty characters.** `backup_restores.reason` is NOT NULL and
 *     immutable (section 2.2) — the row outlives the operator and "testing" does not pass review. The
 *     floor is the contract's own number.
 *
 * **The database name is refused in both directions, and that pair of rules is the one that prevents
 * the unrecoverable mistake.** A `production` restore must name the live database and nothing else — a
 * typo would restore over a database nobody is watching and leave production untouched while everybody
 * believes it was fixed. A `local` or `staging` restore must **not** name the live database: that is
 * the whole meaning of "non-production", and the mistake in that direction cannot be undone by a
 * second restore, because the thing it overwrote was the original.
 *
 * `target` is validated against {@see RestoreTarget}, and `production` is additionally refused unless
 * the application really is running in production (section 8.3 step 2 preselects it only there). A
 * staging box that can post `target=production` writes a `backup_restores` row claiming production was
 * overwritten, and that row is permanent.
 */
final class RestoreBackupRequest extends FormRequest
{
    /**
     * The route carries `can:backups.restore`, `password.confirm` and `throttle:backup-restore`, and
     * the controller asks `BackupRunPolicy::restore()` about the archive itself. This is the
     * permission's second line; see `BackupRunPolicy` for why the archive's side of the question is
     * never asked through the Gate.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('backups.restore') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->targetOrNull();

        return [
            'target' => ['required', 'string', Rule::in($this->selectableTargets())],

            /*
            | The same character class the `backup.restore_scratch_database` setting is validated with:
            | a database name reaches a `CREATE DATABASE` and a `mysql` command line, and anything
            | outside `[A-Za-z0-9_]` there is either a quoting bug or an injection.
            */
            'database_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]{1,64}$/'],

            'reason' => ['required', 'string', 'min:20', 'max:500'],

            // Required only where the target demands it, so a `local` rehearsal is not made to type a
            // phrase about a database nobody else can see. `nullable` elsewhere, never absent: the
            // closure in `after()` still compares whatever arrived.
            'confirmation_phrase' => [
                $target !== null && $target->requiresConfirmationPhrase() ? 'required' : 'nullable',
                'string',
                'max:190',
            ],

            // Section 8.3 step 2's acknowledgement. `accepted` rather than `boolean`: the operator has
            // to tick it, and an unticked box must fail rather than default to false and proceed.
            'acknowledge_pre_backup' => [
                $target !== null && $target->requiresPreBackup() ? 'accepted' : 'nullable',
            ],
        ];
    }

    /**
     * The two checks that need the archive and the settings in hand, not just the field values.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->checkDatabaseName($validator);
                $this->checkConfirmationPhrase($validator);
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A restore overwrites a database. Write down why, for whoever reads '
                .'this record after you have left the company.',
            'reason.min' => 'Twenty characters at least. "testing" does not pass review, and this row '
                .'can never be edited.',
            'database_name.regex' => 'Letters, digits and underscores only — this name is handed to '
                .'the database server.',
            'acknowledge_pre_backup.accepted' => 'A safety copy is taken before anything is overwritten '
                .'and the restore aborts if it fails. Confirm that you know that.',
            'target.in' => 'Choose where this restore is going. Production is offered only when the '
                .'application is actually running in production.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'database_name' => 'database name',
            'confirmation_phrase' => 'confirmation phrase',
            'acknowledge_pre_backup' => 'pre-restore backup acknowledgement',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | What the controller reads back
    |--------------------------------------------------------------------------
    */

    public function target(): RestoreTarget
    {
        return RestoreTarget::from((string) $this->validated('target'));
    }

    public function databaseName(): string
    {
        return trim((string) $this->validated('database_name'));
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    /**
     * When the typed phrase was accepted — the stamp `backup_restores.confirmed_at` carries.
     *
     * Null for a target that does not require a phrase, so the column keeps meaning "the phrase was
     * typed and matched" rather than "the form was submitted". `BackupRestore::gatesSatisfied()` reads
     * it that way (section 2.2).
     */
    public function confirmedAt(): ?Carbon
    {
        return $this->target()->requiresConfirmationPhrase() ? Carbon::now() : null;
    }

    /*
    |--------------------------------------------------------------------------
    | The phrase — one implementation, shared with the wizard that renders it
    |--------------------------------------------------------------------------
    */

    /**
     * The phrase the operator must type, with `{database}` and `{date}` substituted.
     *
     * Public and static because the wizard has to render the expected text above the input (section
     * 8.3 step 3) and the server has to compare against it. Two implementations of one string is a
     * confirmation nobody can pass, or worse, one anybody can.
     *
     * `{date}` is the **archive's** date, not today's: the sentence an operator reads has to name how
     * old the snapshot they are about to install is. It is rendered in the display timezone (D61 stores
     * UTC) with a fixed `Y-m-d` format rather than the localized one, because the phrase has to be
     * typeable from the screen — a slash-separated date in a phrase is a date somebody types with
     * dashes.
     */
    public static function expectedPhrase(BackupRun $run, string $database): string
    {
        $template = setting('backup.restore_confirmation_phrase', 'RESTORE {database} {date}');
        $template = is_string($template) && trim($template) !== ''
            ? trim($template)
            : 'RESTORE {database} {date}';

        $at = $run->started_at ?? $run->created_at;

        $date = $at === null
            ? Carbon::now(Format::timezone())->format('Y-m-d')
            : Carbon::instance($at)->timezone(Format::timezone())->format('Y-m-d');

        return str_replace(
            ['{database}', '{date}'],
            [$database, $date],
            $template,
        );
    }

    /**
     * The phrase template with `{date}` resolved and `{database}` left in place.
     *
     * The wizard substitutes the database name in the browser as the operator types it, so the expected
     * text on screen always describes the target they have actually chosen.
     */
    public static function phraseTemplate(BackupRun $run): string
    {
        return self::expectedPhrase($run, '{database}');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Section 8.3 step 2: production is selectable only when this really is production.
     *
     * @return list<string>
     */
    private function selectableTargets(): array
    {
        $targets = RestoreTarget::values();

        if (app()->environment('production')) {
            return $targets;
        }

        return array_values(array_filter(
            $targets,
            static fn (string $value): bool => $value !== RestoreTarget::Production->value,
        ));
    }

    /**
     * Both directions of the database-name rule — see the class note.
     */
    private function checkDatabaseName(Validator $validator): void
    {
        $target = $this->targetOrNull();
        $typed = trim((string) $this->input('database_name'));

        if ($target === null || $typed === '') {
            return;
        }

        $live = $this->liveDatabaseName();

        if ($live === null) {
            return;
        }

        // Compared case-insensitively on purpose: MySQL on Windows folds database names, so
        // `My_Office` and `my_office` are the same schema and the "not the live database" rule has to
        // treat them as the same schema too.
        $isLive = strcasecmp($typed, $live) === 0;

        if ($target === RestoreTarget::Production && ! $isLive) {
            $validator->errors()->add(
                'database_name',
                sprintf(
                    'A production restore writes to %s and nothing else. Restoring over some other '
                    .'database would leave production exactly as broken as it is now, while everybody '
                    .'believes it was fixed.',
                    $live,
                ),
            );

            return;
        }

        if ($target !== RestoreTarget::Production && $isLive) {
            $validator->errors()->add(
                'database_name',
                sprintf(
                    'That is the live database (%s). A %s restore must name a scratch database — this '
                    .'is the one mistake on this screen that cannot be undone by restoring again.',
                    $live,
                    $target->label(),
                ),
            );
        }
    }

    /**
     * The typed phrase, compared exactly.
     */
    private function checkConfirmationPhrase(Validator $validator): void
    {
        $target = $this->targetOrNull();
        $run = $this->backupRun();

        if ($target === null || $run === null || ! $target->requiresConfirmationPhrase()) {
            return;
        }

        $typed = (string) $this->input('confirmation_phrase');
        $expected = self::expectedPhrase($run, trim((string) $this->input('database_name')));

        // `!==`, not a normalised comparison. See the class note: the point of the phrase is that it
        // was read.
        if ($typed !== $expected) {
            $validator->errors()->add(
                'confirmation_phrase',
                'That is not the phrase. Type it exactly as shown, including the capitals — it names '
                .'the database being overwritten and the date of the snapshot going onto it.',
            );
        }
    }

    /**
     * The target as an enum, or null while the field is missing or invalid.
     *
     * Used by `rules()`, which runs before any of them have passed, so it must tolerate rubbish.
     */
    private function targetOrNull(): ?RestoreTarget
    {
        $value = $this->input('target');

        return is_string($value) ? RestoreTarget::tryFrom($value) : null;
    }

    /**
     * The archive this restore is for, from the route.
     */
    private function backupRun(): ?BackupRun
    {
        $run = $this->route('backup');

        return $run instanceof BackupRun ? $run : null;
    }

    /**
     * The live database name, or null when it cannot be resolved.
     *
     * Null means the checks above stand down rather than guess: a name the application is not sure of
     * must not be the thing an error message tells somebody to type.
     */
    private function liveDatabaseName(): ?string
    {
        try {
            $name = app(BackupPathResolver::class)->databaseName();
        } catch (Throwable) {
            return null;
        }

        return trim($name) === '' ? null : $name;
    }

    /**
     * Whitespace normalised before the rules run, so `min:20` judges what will be stored.
     *
     * `confirmation_phrase` is deliberately **not** trimmed: it is compared exactly, and silently
     * fixing the operator's whitespace is the same as not asking them to read it.
     */
    protected function prepareForValidation(): void
    {
        foreach (['reason', 'database_name'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }
}
