<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Institute;

use App\Enums\DeliveryMode;
use App\Enums\PreferredTiming;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An admission's non-financial details — `admin.admissions.update`.
 *
 * **Deliberately narrow.** The money has its own route (`figures`, with a reason, frozen on the first
 * charge), the stage moves only through the pipeline steps, and the student, course, branch and
 * admission number define what the admission *is* — changing one of those is a different admission,
 * which is cancel-and-create, not an edit. What is left is what a front desk genuinely gets wrong:
 * the date, the counsellor, the mode, the timing and the notes.
 */
final class UpdateAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route carries `can:update,admission`; one answer to "who may", not two.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admission_date' => ['required', 'date'],
            'counselor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'preferred_timing' => ['nullable', Rule::enum(PreferredTiming::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $validated = $this->validated();

        return [
            'admission_date' => $validated['admission_date'],
            'counselor_id' => $validated['counselor_id'] ?? null,
            'delivery_mode' => $validated['delivery_mode'] ?? null,
            'preferred_timing' => $validated['preferred_timing'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }
}
