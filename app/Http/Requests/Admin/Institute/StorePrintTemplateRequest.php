<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\PageOrientation;
use App\Enums\PaperSize;
use App\Enums\PrintTemplateType;
use App\Models\Institute\PrintTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A printable document's layout (phase-19-23 §6.13, §8, INV-21-5).
 *
 * **This layer does not sanitise, and that is deliberate.** `PrintTemplateService` calls
 * `RichText::sanitize($html, 'material')` on save and again on render; doing it here as well would be
 * a third place the rule lives, and the one that lagged would be the one that let something through.
 * What this checks is shape — a `type` that exists, a paper size the renderer knows, dimensions when
 * `custom` needs them — so the service receives something it can reason about.
 *
 * **Unknown tokens are not checked here either.** They are a *warning* the service returns after
 * saving, naming each one, because a designer gets a typo wrong on the way to getting a layout right
 * and losing an hour of work to a rejected save is the worse outcome. A Form Request can only refuse.
 */
class StorePrintTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('print_template');

        return $template instanceof PrintTemplate
            ? $this->user()?->can('update', $template) === true
            : $this->user()?->can('create', PrintTemplate::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = $this->route('print_template');
        $id = $template instanceof PrintTemplate ? $template->getKey() : null;
        $editing = $template instanceof PrintTemplate;

        return [
            // The type is fixed after creation: a certificate template's token set is not an ID
            // card's, and switching would leave a body full of tokens the new type does not know.
            'type' => [$editing ? 'nullable' : 'required', Rule::enum(PrintTemplateType::class)],

            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('print_templates', 'code')->ignore($id)->withoutTrashed(),
            ],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->withoutTrashed()],

            'paper_size' => ['nullable', Rule::enum(PaperSize::class)],
            'orientation' => ['nullable', Rule::enum(PageOrientation::class)],
            // decimal(6,2). `decimal:0,2` is what refuses exponent notation — `numeric` alone accepts
            // `1E1` and stores a value the column cannot parse (D114).
            'width_mm' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:2000'],
            'height_mm' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:2000'],
            'margin_mm' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],

            'background_image_path' => ['nullable', 'string', 'max:255'],
            'logo_path' => ['nullable', 'string', 'max:255'],

            // Sanitised by the service, twice. See the class note.
            'body_html' => ['required', 'string', 'max:200000'],
            'custom_css' => ['nullable', 'string', 'max:50000'],

            'signatories' => ['nullable', 'array', 'max:3'],
            'signatories.*.name' => ['nullable', 'string', 'max:120'],
            'signatories.*.title' => ['nullable', 'string', 'max:120'],
            'signatories.*.image_path' => ['nullable', 'string', 'max:255'],

            'show_qr' => ['nullable', 'boolean'],
            'qr_size_mm' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:100'],

            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Required by the service when a template with issued documents is edited.
            'reason' => ['nullable', 'string', 'min:5', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $size = PaperSize::tryFrom((string) $this->input('paper_size', 'a4'));

            // `chk_pt_custom` says the same thing at the database. This says it on the field, so the
            // person filling the form is told which two boxes to complete rather than shown a
            // constraint violation they cannot read.
            if ($size === PaperSize::Custom) {
                foreach (['width_mm', 'height_mm'] as $field) {
                    if (trim((string) $this->input($field)) === '') {
                        $validator->errors()->add($field, 'A custom size needs both a width and a height.');
                    }
                }
            }

            // A QR code bigger than the page is not a QR code, it is a mistake nobody notices until
            // a hundred cards are printed.
            $qr = $this->input('qr_size_mm');

            if ($qr !== null && $qr !== '' && $size instanceof PaperSize) {
                $shortest = $size === PaperSize::Custom
                    ? min((float) $this->input('width_mm', 0), (float) $this->input('height_mm', 0))
                    : min((float) $size->widthMm(), (float) $size->heightMm());

                if ($shortest > 0 && (float) $qr > $shortest) {
                    $validator->errors()->add(
                        'qr_size_mm',
                        sprintf('This page is only %smm across at its narrowest, so a %smm code will not fit.', $shortest, $qr),
                    );
                }
            }
        });
    }

    /**
     * An unticked checkbox sends nothing at all, so `show_qr` would arrive absent and the service
     * would read "not stated" where the form said "no".
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_qr' => $this->boolean('show_qr'),
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'A code is letters, digits, dashes and underscores — nothing else.',
            'code.unique' => 'Another template already uses that code.',
            'body_html.required' => 'A template with no body prints a blank page.',
            'signatories.max' => 'Three signatories is what the token list carries.',
            'reason.min' => 'Say a little more about why.',
        ];
    }
}
