{{--
    The submission fields, shared by the first hand-in and a resubmission.

    Which of the two is required comes from the assignment's `submission_type`, asked of the enum so
    this, the Form Request and the service cannot disagree — `file_or_text` in particular needs at
    least one, which no single boolean expresses.
--}}
@if ($assignment->submission_type->allowsText())
    <x-ui.form.textarea name="submission_text" label="Your answer" rows="6"
                        :required="$assignment->submission_type->requiresText()"
                        :value="old('submission_text', $submission?->submission_text)" />
@endif

@if ($assignment->submission_type->allowsFile())
    <x-ui.form.file name="files[]" label="Your files" multiple
                    :required="$assignment->submission_type->requiresFile()"
                    :hint="App\DataObjects\Files\FileRules::submission(
                        is_array($assignment->allowed_extensions) && $assignment->allowed_extensions !== []
                            ? array_map('strval', $assignment->allowed_extensions)
                            : null,
                        $assignment->max_file_size_mb,
                    )->describe()"
                    help="At most {{ app_number($assignment->max_files) }} file{{ $assignment->max_files === 1 ? '' : 's' }}." />
@endif

@if ($assignment->submission_type->requiresEither())
    <x-ui.form.help>Attach a file or type your answer — either will do, but not neither.</x-ui.form.help>
@endif
