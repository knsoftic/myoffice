<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Index manifest (D60, F-9.2; phase-24-25 §2.5, §6.1, §13.2; build-order E4)
|--------------------------------------------------------------------------
|
| The authority for "which indexes must exist". PRF-04 checks every entry against
| information_schema.STATISTICS: each listed column list must be the leading columns of an index on that
| table (a prefix of a longer index counts as present), and every foreign-key column must have one.
| PRF-04 joins KEY_COLUMN_USAGE to STATISTICS, so an FK index named in a contract's `Keys` block fails the
| same way whether it is missing from this file or from the schema.
|
|   table => [ [columns], ... ]   (order matters)
|
| Append-only per phase: each phase adds its own tables, under its own banner — every FK column of its
| `Keys` blocks, each filtered `status`, each sorted column and each `(branch_id, ...)` pair — and never
| edits another phase's entries. A later phase that indexes a table owned by an earlier one adds a second
| entry for that table under its own banner (PHP keeps the last key, so it repeats the earlier columns).
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Phase 3 — public website CMS (phase-03 §2, the Keys blocks of §2.2-§2.14)
    |----------------------------------------------------------------------
    | Every FK column is listed on its own even when a composite index already leads with it, so the row
    | reads as the contract's FK list; created_by / updated_by / published_by are the Blameable foreign keys.
    | tests/Feature/Cms/Http/CmsManifestTest proves every entry exists and every FK column is covered.
    */

    'media_assets' => [['checksum'], ['collection', 'created_at'], ['derivatives_status'], ['usage_count'], ['mime_type'], ['created_by'], ['updated_by']],
    'cta_blocks' => [['key'], ['status'], ['background_media_id'], ['created_by'], ['updated_by']],
    'pages' => [['slug'], ['status', 'published_at'], ['is_system'], ['layout'], ['banner_media_id'], ['published_by'], ['created_by'], ['updated_by']],
    'menus' => [['slug'], ['location'], ['created_by'], ['updated_by']],
    'menu_items' => [['menu_id', 'parent_id', 'is_enabled', 'sort_order'], ['menu_id'], ['parent_id'], ['page_id'], ['linkable_type', 'linkable_id'], ['created_by'], ['updated_by']],
    'website_sections' => [['instance_key'], ['placement', 'page_id', 'anchor'], ['placement', 'page_id', 'is_enabled', 'status', 'sort_order'], ['section_key'], ['has_unpublished_changes'], ['page_id'], ['cta_block_id'], ['menu_id'], ['published_by'], ['created_by'], ['updated_by']],
    'website_section_items' => [['website_section_id', 'group', 'is_enabled', 'sort_order'], ['website_section_id'], ['metric', 'value_mode'], ['media_asset_id'], ['created_by'], ['updated_by']],
    'website_section_media' => [['website_section_id', 'role', 'media_asset_id'], ['website_section_id'], ['media_asset_id']],
    'faq_categories' => [['slug'], ['is_enabled', 'sort_order'], ['created_by'], ['updated_by']],
    'faqs' => [['faq_category_id', 'status', 'sort_order'], ['faq_category_id'], ['faqable_type', 'faqable_id'], ['is_featured', 'status'], ['created_by'], ['updated_by']],
    'faq_website_section' => [['faq_id', 'website_section_id'], ['faq_id'], ['website_section_id', 'sort_order'], ['website_section_id']],
    'seo_meta' => [['seoable_type', 'seoable_id'], ['route_key'], ['robots', 'sitemap_include'], ['og_image_media_id'], ['created_by'], ['updated_by']],
    'cms_revisions' => [['revisionable_type', 'revisionable_id', 'id'], ['is_published_snapshot'], ['created_by']],
    'sitemap_generations' => [['created_at'], ['created_by']],

];
