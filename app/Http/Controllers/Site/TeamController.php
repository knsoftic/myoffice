<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Cms\TeamMember;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public team page — `site.team.index` (phase-04 §7.1, §8.11, §9.2), `site_module:team`.
 *
 * `website.team_page_enabled = false` makes the page a 404. Only `TeamMember::public()` rows render —
 * published **and** `is_public` (test 14). Members are grouped by the `department` snapshot label, with
 * the ungrouped members last.
 */
final class TeamController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(): Response
    {
        if (! $this->siteFlag('website.team_page_enabled')) {
            return $this->notFound();
        }

        $members = $this->eagerPublic(TeamMember::query()->public(), ['photo'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $groups = [];
        $ungrouped = [];

        foreach ($members as $member) {
            $department = is_string($member->department) ? trim($member->department) : '';

            if ($department === '') {
                $ungrouped[] = $member;
            } else {
                $groups[$department][] = $member;
            }
        }

        if ($ungrouped !== []) {
            $groups[''] = $ungrouped;
        }

        return $this->contentPage('site.team.index', [
            'members' => $members,
            // department label => members; the '' key (ungrouped) is always last.
            'groups' => $groups,
        ], $this->routeSeo('site.team.index'), 'site-team', ['title' => 'Our team', 'slug' => 'team']);
    }
}
