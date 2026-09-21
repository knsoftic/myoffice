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

    /*
    |----------------------------------------------------------------------
    | Phase 4 — the twenty catalogue, social-proof, blog, careers and inquiry tables
    |----------------------------------------------------------------------
    | Read from information_schema, so each list is a real index in column order, and every foreign key
    | column leads an index (F-9.2).
    */
    'service_categories' => [
        ['created_by'],
        ['image_media_id'],
        ['is_active', 'sort_order'],
        ['slug'],
        ['sort_order'],
        ['updated_by'],
    ],
    'technologies' => [
        ['created_by'],
        ['is_active', 'sort_order'],
        ['logo_media_id'],
        ['slug'],
        ['updated_by'],
    ],
    'services' => [
        ['created_by'],
        ['image_media_id'],
        ['is_featured'],
        ['service_category_id', 'status'],
        ['slug'],
        ['status', 'is_featured', 'sort_order'],
        ['updated_by'],
        ['service_category_id'],    ],
    'service_technology' => [
        ['technology_id'],
        ['service_id'],    ],
    'portfolio_categories' => [
        ['created_by'],
        ['image_media_id'],
        ['is_active', 'sort_order'],
        ['slug'],
        ['sort_order'],
        ['updated_by'],
    ],
    'portfolio_items' => [
        ['client_id'],
        ['completion_date'],
        ['cover_media_id'],
        ['created_by'],
        ['is_featured'],
        ['portfolio_category_id', 'status'],
        ['slug'],
        ['status', 'is_featured', 'sort_order'],
        ['updated_by'],
        ['portfolio_category_id'],    ],
    'portfolio_item_technology' => [
        ['technology_id'],
        ['portfolio_item_id'],    ],
    'portfolio_item_media' => [
        ['created_by'],
        ['media_asset_id'],
        ['portfolio_item_id', 'sort_order'],
        ['portfolio_item_id', 'media_asset_id'],
        ['portfolio_item_id'],    ],
    'team_members' => [
        ['created_by'],
        ['department_id'],
        ['employee_id'],
        ['is_public'],
        ['photo_media_id'],
        ['slug'],
        ['status', 'is_public', 'sort_order'],
        ['updated_by'],
    ],
    'testimonials' => [
        ['approved_by'],
        ['author_photo_media_id'],
        ['client_id'],
        ['created_by'],
        ['is_featured'],
        ['source'],
        ['status', 'is_featured'],
        ['status', 'type', 'sort_order'],
        ['student_id'],
        ['submitted_by_user_id'],
        ['type', 'client_id'],
        ['type', 'student_id'],
        ['updated_by'],
    ],
    'student_reviews' => [
        ['approved_by'],
        ['course_id', 'status'],
        ['created_by'],
        ['is_featured'],
        ['source'],
        ['status', 'is_featured', 'sort_order'],
        ['student_id'],
        ['student_photo_media_id'],
        ['submitted_by_user_id'],
        ['updated_by'],
        ['course_id'],    ],
    'success_stories' => [
        ['course_id'],
        ['created_by'],
        ['is_featured'],
        ['photo_media_id'],
        ['status', 'is_featured', 'sort_order'],
        ['student_id'],
        ['updated_by'],
    ],
    'blog_categories' => [
        ['created_by'],
        ['image_media_id'],
        ['is_active', 'sort_order'],
        ['slug'],
        ['updated_by'],
    ],
    'blog_tags' => [
        ['created_by'],
        ['is_active'],
        ['slug'],
        ['updated_by'],
    ],
    'blog_post_blog_tag' => [
        ['blog_tag_id'],
        ['blog_post_id'],    ],
    'blog_posts' => [
        ['author_id', 'status'],
        ['blog_category_id', 'status', 'published_at'],
        ['created_by'],
        ['featured_image_media_id'],
        ['is_featured', 'status'],
        ['published_at'],
        ['slug'],
        ['status', 'published_at'],
        ['updated_by'],
        ['views_count'],
        ['author_id'],
        ['blog_category_id'],    ],
    'blog_post_views' => [
        ['blog_post_id', 'viewed_on'],
        ['user_id'],
        ['blog_post_id', 'visitor_hash', 'viewed_on'],
        ['blog_post_id'],    ],
    'job_openings' => [
        ['created_by'],
        ['deadline'],
        ['department_id'],
        ['employment_type', 'status'],
        ['slug'],
        ['status', 'deadline'],
        ['status', 'is_featured', 'sort_order'],
        ['updated_by'],
        ['work_mode'],
    ],
    'job_applications' => [
        ['assigned_to', 'status'],
        ['created_by'],
        ['email'],
        ['employee_id'],
        ['job_opening_id', 'status'],
        ['source'],
        ['status_changed_by'],
        ['status', 'created_at'],
        ['updated_by'],
        ['job_opening_id', 'email'],
        ['assigned_to'],
        ['job_opening_id'],    ],
    'contact_inquiries' => [
        ['assigned_to'],
        ['collaborator_id'],
        ['course_id'],
        ['created_by'],
        ['email'],
        ['inquiry_type', 'status'],
        ['is_spam', 'created_at'],
        ['read_by'],
        ['referral_code'],
        ['referral_visit_id'],
        ['routing_status', 'created_at'],
        ['routing_target', 'routing_status'],
        ['service_id'],
        ['source'],
        ['status', 'created_at'],
        ['updated_by'],
        ['routed_type', 'routed_id'],
    ],

    /*
    |----------------------------------------------------------------------
    | Phase 5 - CRM: clients, contacts, documents, leads and their sub-records
    |----------------------------------------------------------------------
    | Every Keys-block index plus a row for each foreign key column (F-9.2).
    */
    'clients' => [['client_code'], ['user_id'], ['status', 'company_name'], ['account_manager_id'], ['email_normalized'], ['phone_normalized'], ['whatsapp_normalized'], ['lead_id'], ['referral_code_captured'], ['deleted_at'], ['created_by'], ['updated_by']],
    'client_contacts' => [['client_id', 'primary_guard'], ['user_id'], ['client_id', 'is_primary'], ['email_normalized'], ['phone_normalized'], ['portal_access'], ['client_id'], ['created_by'], ['updated_by']],
    'client_documents' => [['client_id', 'category'], ['client_id', 'visible_to_client'], ['checksum'], ['expires_at'], ['client_id'], ['shared_by'], ['created_by'], ['updated_by']],
    'leads' => [['lead_no'], ['contact_inquiry_id'], ['status', 'follow_up_at', 'id'], ['assigned_to', 'status'], ['created_by', 'status'], ['follow_up_at'], ['last_activity_at'], ['source', 'created_at'], ['email_normalized'], ['phone_normalized'], ['whatsapp_normalized'], ['client_id'], ['referral_code_captured'], ['referral_visit_id'], ['deleted_at'], ['service_id'], ['lead_import_id'], ['assigned_to'], ['assigned_by'], ['duplicate_of_lead_id'], ['converted_by'], ['created_by'], ['updated_by']],
    'lead_activities' => [['lead_id', 'occurred_at', 'id'], ['type', 'occurred_at'], ['created_by', 'occurred_at'], ['lead_follow_up_id'], ['occurred_at'], ['lead_id'], ['from_user_id'], ['to_user_id'], ['related_lead_id'], ['created_by'], ['updated_by']],
    'lead_follow_ups' => [['lead_id', 'open_guard'], ['previous_follow_up_id'], ['status', 'reminder_due_at', 'reminder_sent_at'], ['assigned_to', 'status', 'scheduled_at'], ['lead_id', 'scheduled_at'], ['scheduled_at'], ['reminder_due_at'], ['lead_id'], ['assigned_to'], ['completed_by'], ['created_by'], ['updated_by']],
    'lead_conversions' => [['lead_id', 'active_guard'], ['client_id'], ['project_id'], ['converted_at'], ['converted_by', 'converted_at'], ['collaborator_referral_id'], ['lead_id'], ['converted_by'], ['created_by'], ['updated_by']],
    'lead_imports' => [['status', 'created_at'], ['file_hash'], ['created_by', 'created_at'], ['created_by'], ['updated_by']],
    'lead_import_rows' => [['lead_import_id', 'row_number'], ['lead_import_id', 'status'], ['lead_id'], ['lead_import_id'], ['duplicate_lead_id']],
    // phase-05 promotes these two Phase 4 columns to foreign keys; the index already exists (phase-04 rows).

    /*
    |----------------------------------------------------------------------
    | Phase 13 - software-house finance: invoices, expenses, other income
    |----------------------------------------------------------------------
    | Every Keys-block index of phase-13 §2, plus a row for each foreign key column (F-9.2). The three
    | deferred foreign keys this phase finally creates (`project_payments.invoice_id` and the two
    | `payment_method_id` columns) already have their indexes from the spine's own migrations.
    */
    'payment_methods' => [['code'], ['default_guard'], ['is_active', 'sort_order'], ['type'], ['created_by'], ['updated_by']],
    'finance_categories' => [['type', 'code'], ['type', 'is_active', 'sort_order'], ['created_by'], ['updated_by']],
    'invoices' => [
        ['invoice_number'], ['public_token'],
        ['client_id', 'status'], ['project_id', 'status'], ['status', 'due_date'],
        ['issue_date'], ['due_date'], ['branch_id'],
        ['replaces_invoice_id'], ['payment_method_id'], ['issued_by'], ['cancelled_by'],
        ['client_id'], ['project_id'], ['created_by'], ['updated_by'],
    ],
    'invoice_items' => [['invoice_id', 'sort_order'], ['project_milestone_id'], ['invoice_id'], ['created_by'], ['updated_by']],
    'expenses' => [
        ['expense_no'], ['idempotency_key'], ['source_type', 'source_id'],
        ['status', 'expense_date'], ['finance_category_id', 'expense_date'], ['created_by', 'status'],
        ['project_id'], ['branch_id'], ['expense_date'], ['payment_method'],
        ['approved_by'], ['rejected_by'], ['voided_by'], ['corrects_expense_id'], ['payment_method_id'],
        ['finance_category_id'], ['created_by'], ['updated_by'],
    ],
    'incomes' => [
        ['income_no'], ['idempotency_key'],
        ['status', 'received_on'], ['finance_category_id', 'received_on'],
        ['client_id'], ['project_id'], ['branch_id'], ['received_on'],
        ['voided_by'], ['corrects_income_id'], ['payment_method_id'],
        ['finance_category_id'], ['created_by'], ['updated_by'],
    ],
    'finance_reversals' => [
        ['reversal_no'], ['idempotency_key'],
        ['expense_id'], ['income_id'], ['occurred_on', 'type'],
        ['performed_by'], ['created_by'], ['updated_by'],
    ],
];
