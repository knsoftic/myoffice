<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\TargetsPublishable;

/**
 * Copy a revision back into the draft (`admin.website.*.revisions.revert`, §6.2, FT-11).
 *
 * A revert never goes straight to live, but it rewrites the draft someone may be working on, so it is a
 * `change_status` act with a mandatory reason. The revision's ownership (revision #7 of page A cannot be
 * reverted through page B) is checked by the controller before the service runs.
 */
final class RevertRevisionRequest extends CmsFormRequest
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
            'reason.required' => 'Say why the draft is being reverted. It is recorded in the audit trail.',
        ];
    }
}
