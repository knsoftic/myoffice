<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Upload manifest (D21, D60; phase-24-25 §6.1, §13.2; build-order E4)
|--------------------------------------------------------------------------
|
| One row per upload field: the route that accepts it, the field, the disk it lands on, the MIME types the
| server accepts (read from the file content, never the client's header or the extension), the size limit,
| the permission, and whether the stored file is reachable by URL. SEC-15 attacks every row; Phase 24's
| `audit:manifest --check` compares the manifest with every Form Request rule containing file / image /
| mimes, and fails a `public` disk on a private artefact (D21).
|
| Append-only per phase: each phase adds its own rows, under its own banner, as part of its definition of
| done, and never edits another phase's.
|
| Row shape:
|   'route'            route name (or names, "a / b") of the endpoint that accepts the upload
|   'field'            the request field
|   'disk'             the filesystem disk the file is written to
|   'allowed_mimes'    the content-sniffed MIME types accepted
|   'max_mb'           the size ceiling in megabytes, or the setting key that holds it
|   'permission'       the permission the upload needs
|   'owner_phase'      the phase that ships the field
|   'public_reachable' true when the stored file is served straight from a public URL
|   'stored_as'        (optional) where and under what name the file is written
|   'request'          (optional) the Form Request class(es) this row covers, for a request whose
|                      rules are built at runtime and whose field name cannot be read from source
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Phase 3 — public website CMS
    |----------------------------------------------------------------------
    | The media library is the one uploader of the website (D24): every later website image field picks
    | a `media_assets` row instead of accepting a file of its own. CMS images are public website content,
    | so the `public` disk is correct for them (D21 covers private artefacts only).
    */

    [
        'route' => 'admin.website.media.store',
        'field' => 'file',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'video/mp4', 'video/webm'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'website_media.upload',
        'owner_phase' => 3,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — a ULID name, never the client filename; SVG refused ([D-W3-15]); EXIF stripped; finfo + getimagesize() decide the type (INV-11, phase-03 §6.8)',
    ],

    /*
    |----------------------------------------------------------------------
    | Phase 4 — 23 upload fields (22 website images through MediaService, one private CV)
    |----------------------------------------------------------------------
    | Every website image is written by MediaService with a generated name (D24). A CV is a private
    | artefact (D21): it lands on the private disk and only admin.job-applications.cv streams it.
    */
    [
        'route' => 'admin.blog-categories.store',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'blog_categories.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.blog-categories.update',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'blog_categories.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.blog-posts.store',
        'field' => 'featured_image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'blog_posts.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.blog-posts.update',
        'field' => 'featured_image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'blog_posts.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.portfolio-categories.store',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'portfolio_categories.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.portfolio-categories.update',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'portfolio_categories.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.portfolio.images.store',
        'field' => 'images.*',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'portfolio.upload',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.portfolio.store',
        'field' => 'images.*',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'portfolio.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.service-categories.store',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'service_categories.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.service-categories.update',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'service_categories.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.services.store',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'services.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.services.update',
        'field' => 'image',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'services.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.student-reviews.store',
        'field' => 'student_photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'student_reviews.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.student-reviews.update',
        'field' => 'student_photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'student_reviews.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.success-stories.store',
        'field' => 'photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'success_stories.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.success-stories.update',
        'field' => 'photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'success_stories.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.team.store',
        'field' => 'photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'team.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.team.update',
        'field' => 'photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'team.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.technologies.store',
        'field' => 'logo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'technologies.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.technologies.update',
        'field' => 'logo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'technologies.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.testimonials.store',
        'field' => 'author_photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'testimonials.create',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'admin.testimonials.update',
        'field' => 'author_photo',
        'disk' => 'public',
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'testimonials.edit',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'cms/{Y}/{m}/{ulid}/{ulid}.{ext} — MediaService names it; SVG refused; EXIF stripped',
    ],
    [
        'route' => 'site.careers.apply',
        'field' => 'cv',
        'disk' => 'local',
        'allowed_mimes' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => null,
        'owner_phase' => 4,
        'public_reachable' => false,
        'stored_as' => 'job-applications/{ulid}.{ext} on the private disk — never the client filename',
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 13 — finance artefacts, none of them on the public disk (D21, F-12.5)
    |--------------------------------------------------------------------------
    | A receipt scan names a supplier and an amount, and an invoice PDF names a client and what they
    | were charged. Both are reachable only through a controller that re-runs the permission chain;
    | there is no public path and no signed URL.
    */
    [
        'route' => 'admin.expenses.store',
        'field' => 'receipt',
        'disk' => 'local',
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'expenses.create',
        'owner_phase' => 13,
        'public_reachable' => false,
        'stored_as' => 'expenses/{Y}/{m}/{ulid}.{ext} — streamed by admin.expenses.receipt',
    ],
    [
        'route' => 'admin.income.store',
        'field' => 'receipt',
        'disk' => 'local',
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'income.create',
        'owner_phase' => 13,
        'public_reachable' => false,
        'stored_as' => 'incomes/{Y}/{m}/{ulid}.{ext} — streamed by admin.income.receipt',
    ],
    // Phase 24: `admin.finance-reversals.store` no longer exists. The route was split per subject
    // - a reversal is raised against the expense or the income it reverses, not against a generic
    // endpoint - so the one row became two. Found by `audit:manifest`, which is the whole reason
    // the orphan side of the check exists: a row for a route nobody can reach still passes every
    // sweep, and that looks like coverage.
    [
        'route' => 'admin.expenses.reversals.store',
        'field' => 'attachment',
        'disk' => 'local',
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'expenses.change_status',
        'owner_phase' => 13,
        'public_reachable' => false,
        'stored_as' => 'finance-reversals/{Y}/{m}/{ulid}.{ext} — shown on the parent row only',
    ],
    [
        'route' => 'admin.income.reversals.store',
        'field' => 'attachment',
        'disk' => 'local',
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'income.change_status',
        'owner_phase' => 13,
        'public_reachable' => false,
        'stored_as' => 'finance-reversals/{Y}/{m}/{ulid}.{ext} — shown on the parent row only',
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 14 — the one syllabus upload, on the private disk (D21, D85)
    |--------------------------------------------------------------------------
    | phase-14-17 §2.8 puts this file on the `public` disk and, four lines earlier, requires
    | `course_outline.view` for a resource that is not `is_public`. Both cannot hold, so the file is
    | private and two controllers serve it: `admin.course-resources.download` re-runs
    | `course_outline.download`, and `site.courses.resource` answers only for a public, downloadable
    | resource of a course the catalogue would show.
    |
    | `allowed_mimes` is per resource type and is checked against the CONTENT (`finfo`), never against
    | the name or the client's claim (§111) — the list below is the union of
    | `CourseResourceType::allowedMimes()`.
    */
    [
        'route' => 'admin.course-resources.store',
        'field' => 'file',
        'disk' => 'local',
        'allowed_mimes' => 'App\\Enums\\CourseResourceType::allowedMimes() — per type, checked on the content',
        'max_mb' => 25,
        'permission' => 'course_outline.upload',
        'owner_phase' => 14,
        'public_reachable' => false,
        'stored_as' => 'courses/{course}/resources/{40 random chars}.{ext} — streamed by admin.course-resources.download and, when public, site.courses.resource',
    ],


    /*
    |----------------------------------------------------------------------
    | phase-24-25 §6.1 — the twelve fields that had no row.
    |
    | `audit:manifest` compares every Form Request rule containing file / image / mimes against
    | this file, and twelve had nothing to compare against. That means SEC-15 was not attacking
    | them and `--check` was not holding their disk to D21. An upload nobody listed is an upload
    | nobody tests.
    |
    | Five of the twelve are NOT here, and deliberately. ValidatesContentImage, ValidatesService,
    | ValidatesPortfolioItem, ValidatesTaxonomy and TaxonomyDefinition are rule-carrying traits
    | rather than requests behind a route, and D24 is why they exist: the media library is the
    | website's only uploader, so their file rules are there to REFUSE a raw file and demand a
    | `media_assets` pick. A row needs a live route — a placeholder one becomes an orphan, and an
    | orphaned row is a guarantee about a screen nobody can open. They are named instead in
    | ManifestAuditor::UPLOAD_RULE_ONLY, with the same reason.
    |----------------------------------------------------------------------
    */
    [
        'route' => 'admin.users.store / admin.users.update',
        'field' => 'avatar',
        'disk' => 'public',
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'max_mb' => '2',
        'permission' => 'users.create / users.edit',
        'owner_phase' => 1,
        'public_reachable' => true,
        'stored_as' => 'avatars/{ulid}.{ext} — a profile photo is shown to whoever may see the user, so the public disk is correct (D21 covers private artefacts). Content-sniffed; SVG refused.',
    ],
    [
        'route' => 'account.avatar.store',
        'field' => 'avatar',
        'disk' => 'public',
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'max_mb' => 'security.max_upload_mb',
        'permission' => '(none — a user always owns their own avatar)',
        'owner_phase' => 1,
        'public_reachable' => true,
        'stored_as' => 'avatars/{ulid}.{ext}. Extensions come from SettingsRegistry::uploadExtensions() intersected with this field own map, so widening security.allowed_file_types cannot widen this field.',
    ],
    [
        'route' => 'admin.assignments.store / admin.assignments.update',
        'field' => 'brief',
        'disk' => 'private',
        'allowed_mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'max_mb' => 'FileRules::assignmentBrief()',
        'permission' => 'assignments.create / assignments.edit',
        'owner_phase' => 19,
        'public_reachable' => false,
        'stored_as' => 'assignments/{assignment}/brief/{ulid}.{ext} on the private disk, served by a controller that re-runs the permission chain (D21).',
    ],
    [
        'route' => 'student.submissions.submit / student.submissions.resubmit',
        'field' => 'files.*',
        'disk' => 'private',
        'allowed_mimes' => [
            '(the assignment own allowed types)',
        ],
        'max_mb' => 'FileRules::submission()',
        'permission' => '(the student own enrolment — scoped by ownership, not a grant)',
        'owner_phase' => 19,
        'public_reachable' => false,
        'stored_as' => 'assignment_submission_files rows on the private disk. A submission is a student own work and is never reachable by URL.',
    ],
    [
        'route' => 'admin.assignment-submissions.grade / admin.assignment-submissions.grade-bulk',
        'field' => 'feedback_file',
        'disk' => 'private',
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
        'max_mb' => 'FileRules::feedback()',
        'permission' => 'assignments.grade',
        'owner_phase' => 19,
        'public_reachable' => false,
        'stored_as' => 'assignments/{assignment}/feedback/{ulid}.{ext}, private. Feedback names a student and a mark, so it is served by a controller, never by a URL.',
    ],
    [
        'route' => 'admin.portfolio.images.store',
        'field' => 'images',
        // Named explicitly: this request builds its rules at runtime (the gallery maximum is a
        // setting), so the field name cannot be read out of the source and field matching alone
        // would never clear the warning.
        'request' => ['App\\Http\\Requests\\Cms\\StorePortfolioImagesRequest'],
        'disk' => 'public',
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif',
        ],
        'max_mb' => 'security.max_upload_mb',
        'permission' => 'portfolio.upload',
        'owner_phase' => 4,
        'public_reachable' => true,
        'stored_as' => 'Through the media library (D24): an upload becomes a media_assets row and the gallery attaches it. A gallery may never exceed 20 attached images; a batch that would is refused whole.',
    ],
];
