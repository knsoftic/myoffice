<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\DataObjects\Files\FileRules;
use App\Enums\CourseResourceType;
use App\Enums\MaterialTargetType;
use App\Models\Institute\CourseMaterial;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Sharing a material, or editing one (phase-19-23 §8.2, requirement §79).
 *
 * **The file's own rules are not restated here.** `SecureFileService` owns §6.1's ten-step gate, and
 * the only sound way to know whether a file is acceptable is to sniff its bytes — which a `mimes:` rule
 * does not do in the way the gate needs. This request checks the *shape* of the request (is a file
 * present when the kind needs one, is the URL an `http(s)` one) and leaves the file itself to the gate.
 * A second, weaker whitelist here would be a second answer to the same question.
 *
 * **A material is a file or a link, never both and never neither** — `chk_cm_payload` says so at the
 * database and this says it on the field, so the teacher gets a message rather than a 500.
 */
class StoreCourseMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof CourseMaterial
            ? $this->user()?->can('update', $material) === true
            : $this->user()?->can('create', CourseMaterial::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->route('material') instanceof CourseMaterial;

        return [
            // Fixed once set: a material belongs to the course whose students were targeted, and
            // moving it would orphan every one of those targets.
            'course_id' => [$editing ? 'nullable' : 'required', 'integer', Rule::exists('courses', 'id')->withoutTrashed()],
            'course_topic_id' => ['nullable', 'integer', Rule::exists('course_topics', 'id')->withoutTrashed()],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teachers', 'id')->withoutTrashed()],

            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => [$editing ? 'nullable' : 'required', Rule::enum(CourseResourceType::class)],

            // `url` alone permits `javascript:` in some PHP builds, so the scheme is pinned as well.
            // The service checks it again — this is the message, that is the guarantee.
            'external_url' => ['nullable', 'string', 'max:500', 'url', 'regex:/^https?:\/\//i'],

            'is_downloadable' => ['nullable', 'boolean'],
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after:available_from'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Size is checked here so a 400 MB upload is refused before it is fully read; everything
            // else about the file is the gate's business.
            'file' => ['nullable', 'file', 'max:'.$this->ceiling()],

            'targets' => ['nullable', 'array', 'max:200'],
            'targets.*.type' => ['required_with:targets', Rule::enum(MaterialTargetType::class)],
            'targets.*.id' => ['required_with:targets', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = CourseResourceType::tryFrom((string) $this->input('type'));

            if (! $type instanceof CourseResourceType) {
                return;
            }

            $hasUrl = trim((string) $this->input('external_url')) !== '';
            $hasFile = $this->hasFile('file');
            $editing = $this->route('material') instanceof CourseMaterial;

            if ($type->isFile()) {
                if ($hasUrl) {
                    $validator->errors()->add('external_url', 'A '.$type->label().' material carries a file, not a link.');
                }

                // On an edit the existing file stands; only a new material must arrive with one.
                if (! $hasFile && ! $editing) {
                    $validator->errors()->add('file', 'Choose the file to share.');
                }

                return;
            }

            if ($hasFile) {
                $validator->errors()->add('file', 'A link material carries no file. Remove it or change the kind.');
            }

            if (! $hasUrl) {
                $validator->errors()->add('external_url', 'A link material needs a web address.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_url.regex' => 'A material link has to start with http:// or https://.',
            'available_until.after' => 'The material would stop being available before it started.',
            'file.max' => 'That file is larger than the institute allows. The current limit is '
                .app_number($this->ceiling() / 1024, 0).' MB.',
        ];
    }

    /** The effective ceiling in kilobytes — the institute's, the security cap and PHP's, whichever is lowest. */
    private function ceiling(): int
    {
        $type = CourseResourceType::tryFrom((string) $this->input('type')) ?? CourseResourceType::Pdf;

        return FileRules::courseMaterial($type)->maxKilobytes();
    }
}
