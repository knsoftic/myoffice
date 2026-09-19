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

];
