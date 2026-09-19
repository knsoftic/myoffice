<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms\Concerns;

/**
 * Which phase-04 module a shared request serves, read from the **route name** (phase-04 §7.2).
 *
 * `ReorderRequest`, `AssignRequest`, `ModerationRequest`, `BulkModerationRequest`,
 * `UpdateContentStatusRequest` and the taxonomy requests are each mounted on several resources. The
 * permission they demand must be exactly the `can:` of the route they are on, so it is derived from the
 * route the router matched — never from a form field, which a client controls.
 *
 * The prefixes end in a dot, so `admin.portfolio.` can never match `admin.portfolio-categories.`.
 */
trait ResolvesContentModule
{
    /** Route-name prefix => module slug. */
    private const CONTENT_ROUTE_MODULES = [
        'admin.service-categories.' => 'service_categories',
        'admin.services.' => 'services',
        'admin.technologies.' => 'technologies',
        'admin.portfolio-categories.' => 'portfolio_categories',
        'admin.portfolio.' => 'portfolio',
        'admin.team.' => 'team',
        'admin.testimonials.' => 'testimonials',
        'admin.student-reviews.' => 'student_reviews',
        'admin.success-stories.' => 'success_stories',
        'admin.blog-categories.' => 'blog_categories',
        'admin.blog-tags.' => 'blog_tags',
        'admin.blog-posts.' => 'blog_posts',
        'admin.jobs.' => 'jobs',
        'admin.job-applications.' => 'job_applications',
        'admin.contact-inquiries.' => 'contact_inquiries',
    ];

    /**
     * The module slug of the matched route, or null when the route is not a phase-04 admin route.
     */
    protected function contentModule(): ?string
    {
        $name = $this->route()?->getName();

        if (! is_string($name) || $name === '') {
            return null;
        }

        foreach (self::CONTENT_ROUTE_MODULES as $prefix => $module) {
            if (str_starts_with($name, $prefix)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * The last segment of the route name (`store`, `approve`, `bulk-approve`, ...).
     */
    protected function contentAction(): ?string
    {
        $name = $this->route()?->getName();

        if (! is_string($name) || $name === '') {
            return null;
        }

        $position = strrpos($name, '.');

        return $position === false ? null : substr($name, $position + 1);
    }

    /**
     * `{module}.{ability}` for the matched route, or null (which refuses everyone, Super Admin included).
     */
    protected function contentPermission(string $ability): ?string
    {
        $module = $this->contentModule();

        return $module === null ? null : $module.'.'.$ability;
    }
}
