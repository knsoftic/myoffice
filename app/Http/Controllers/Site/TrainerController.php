<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Concerns\ComposesContentPages;
use App\Http\Controllers\Site\Concerns\ComposesSite;
use App\Http\Controllers\Site\Concerns\RendersPages;
use App\Models\Institute\Teacher;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public trainers page — `site.trainers.index`.
 *
 * `website.trainers_page_enabled = false` makes the page a 404, and the setting defaults to **false**:
 * the page reads live operational rows, and an institute that has not marked anybody public yet would
 * publish an empty roster.
 *
 * **Two scopes, both of them:** `Teacher::public()` is the institute's own decision to publish somebody
 * (`is_public` **and** a slug — the CHECK refuses one without the other), and `Teacher::teaching()` is
 * `status = active`. A resigned or suspended trainer whose `is_public` was never turned back off is
 * still somebody the institute cannot put in front of a class next week, so the page will not advertise
 * them.
 *
 * **The select list is the privacy boundary.** `phone`, `whatsapp`, `email`, `salary`, `joining_date`,
 * `gender`, `notes`, `status_reason`, `teacher_code`, `employee_id`, `user_id` and the internal `bio`
 * are never loaded, so no template can print one by accident. `public_bio` is the column §72 created
 * precisely so the staff note and the website copy are not the same string. `photo_path` is also left
 * out: it is a private-disk path with no public serving route (D21), so the page renders the initials
 * avatar `Teacher::initials()` already provides rather than a broken image or a leaked path.
 */
final class TrainerController extends Controller
{
    use ComposesContentPages;
    use ComposesSite;
    use RendersPages;

    public function index(): Response
    {
        $trainers = Teacher::query()
            ->public()
            ->teaching()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id', 'slug', 'name',
                'specialization', 'qualification',
                'experience_years', 'experience_note',
                'skills', 'public_bio', 'social_links', 'sort_order',
            ]);

        return $this->contentPage('site.trainers.index', [
            'trainers' => $trainers,
        ], $this->routeSeo('site.trainers.index'), 'site-trainers', ['title' => 'Our trainers', 'slug' => 'trainers']);
    }
}
