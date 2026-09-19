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
 * Create a team member — `admin.team.store`, `can:team.create` (phase-04 §6.4, §6.11, §8.4).
 */
final class StoreTeamMemberRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;
    use ValidatesContentSlug;
    use ValidatesDeferredLinks;
    use ValidatesTeamMember;

    protected function permission(): string
    {
        return 'team.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->teamMemberRules(partial: false);
    }

    protected function prepareForValidation(): void
    {
        $this->prepareTeamMemberInput();
    }

    public function teamMember(): ?TeamMember
    {
        return null;
    }
}
