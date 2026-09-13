<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\TargetsPublishable;

/**
 * Take a section or page off the public site (`admin.website.*.unpublish`, §6.2, §6.4, FT-10).
 *
 * The reason is mandatory — it is written to `unpublished_reason`, the `unpublished` revision and the
 * activity row (INV-16). The service refuses an empty reason again, because a Super Admin or a console
 * caller never passes through this class.
 */
final class UnpublishContentRequest extends CmsFormRequest
{
    use TargetsPublishable;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this is being taken off the site. It is recorded in the audit trail.',
            'reason.min' => 'The reason must be at least :min characters.',
        ];
    }
}
