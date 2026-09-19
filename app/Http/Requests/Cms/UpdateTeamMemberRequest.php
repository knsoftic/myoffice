<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Http\Requests\Cms\Concerns\ValidatesDeferredLinks;
use App\Http\Requests\Cms\Concerns\ValidatesTeamMember;
use App\Models\Cms\TeamMember;

/**
 * Update a team member — `admin.team.update`, `can:team.edit` (phase-04 §6.4, §6.11, §8.4).
 */
final class UpdateTeamMemberRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesDeferredLinks;
    use ValidatesTeamMember;

    protected function permission(): string
    {
        return 'team.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->teamMemberRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTeamMemberInput();
    }

    public function teamMember(): ?TeamMember
    {
        $member = $this->route('member');

        return $member instanceof TeamMember ? $member : null;
    }
}
