# Phase 5 integration list — CRM: leads, clients, client panel

Each role appends its own section below under a heading with its role name. Contract: docs/phases/phase-05.md.

## Schema, enums, models, policies (phase-05 role: schema/enums/models/policies)

Verified before hand-off: `php -l` + `./vendor/bin/pint --test` clean on every file below. A disposable scratch schema
(`p5_scratch_schema_verify`, created and dropped by the check — never `my_office`, never `my_office_test`) ran every
committed migration, then the 10 staged files, then **307 assertions**: table / column / type / default inventory, the
three STORED generated columns, the seven CHECK constraints, the trigger pair, every named UNIQUE and Keys-block
index, every FK with its delete rule and its index, no `ON UPDATE CURRENT_TIMESTAMP` anywhere, idempotent re-run of
all ten `up()`s, model hooks (immutability, append-only, system rows, generated-guard writes, normalisation, billing
nulling), the 1062 guards, the visibility scope, the policies' 403 / 404 paths (direct and through `Gate::inspect`),
`migrate:rollback` of the phase-05 batch (all nine tables and both triggers gone, Phase 4 intact, the testimonials FK
removed) and a clean re-run. A second scratch schema without the Phase 4 migrations proved the deferred-FK no-op
(§11 test 2). No test suite was run; no git command beyond one read-only `git status`.

### S.1 Files delivered (all new; nothing existing edited)

```
database/migrations-staged/phase-05/   10 files, 2026_09_12_090100 … 2026_09_12_091000 (§2 migration order)
  090100_create_clients_table                090600_create_lead_follow_ups_table
  090200_create_client_contacts_table        090700_create_lead_conversions_table
  090300_create_client_documents_table       090800_create_lead_imports_table
  090400_create_leads_table                  090900_create_lead_import_rows_table
  090500_create_lead_activities_table        091000_add_crm_deferred_foreign_keys
app/Enums/                    LeadStatus LeadActivityType LeadContactOutcome LeadFollowUpType LeadFollowUpStatus
                              LeadConversionType LeadDuplicateMatchType LeadImportStatus LeadImportRowStatus
                              LeadImportDuplicateStrategy ClientType ClientStatus ClientDocumentCategory   (13, §3)
app/Models/Scopes/            LeadVisibilityScope                                                       ([D-P5-8])
app/Models/Crm/               Lead LeadActivity LeadFollowUp LeadConversion LeadImport LeadImportRow
                              Client ClientContact ClientDocument
app/Models/Crm/Concerns/      NormalizesContacts HasGeneratedGuards RelatesToLaterPhases
app/Policies/Crm/             LeadPolicy LeadActivityPolicy LeadFollowUpPolicy LeadConversionPolicy LeadImportPolicy
                              ClientPolicy ClientContactPolicy ClientDocumentPolicy ClientPortalPolicy     (§9.3)
app/Policies/Crm/Concerns/    ChecksCrmPermissions ResolvesPortalClient
```

No enum another phase owns is redeclared: `leads.source` / `clients.source` cast to Phase 4's `InquirySource`.

### S.2 Migrations — move, then migrate (integration stage)

1. **Pre-check on `my_office` (read-only)** — the deferred-FK migration promotes `testimonials.client_id` and
   `portfolio_items.client_id` to real keys (phase-04 §13, build-order §5). `clients` is new in this release, so any
   non-null value there is necessarily dangling, and the migration **refuses** (it never nulls data itself):

   ```sql
   SELECT 'testimonials' AS t, COUNT(*) AS dangling FROM testimonials WHERE client_id IS NOT NULL
   UNION ALL
   SELECT 'portfolio_items', COUNT(*) FROM portfolio_items WHERE client_id IS NOT NULL;
   ```

   Both must be `0` (Phase 4's `ValidatesDeferredLinks` prohibits the id while `clients` is absent, so they should be).
   If not, stop and ask the owner — do not edit the migration. (At hand-off Phase 4's tables were not yet migrated
   on `my_office`, so this runs after Phase 4's migrate and before Phase 5's.)
2. Move all ten files from `database/migrations-staged/phase-05/` into `database/migrations/` unchanged. Names sort
   after `2026_09_12_081500_create_contact_inquiries_table.php` and in the contract's dependency order.
3. `php artisan migrate` (forward only). The leads migration creates two triggers: the migration user needs the
   `TRIGGER` privilege (root has it; note for Phase 25's DDL user, D58).
4. Re-running `add_crm_deferred_foreign_keys` is safe at any time (it checks for an existing FK on each column).
   `leads.referral_visit_id`, `lead_conversions.project_id` and `lead_conversions.collaborator_referral_id` stay
   bare until their tables exist; Phase 9 / Phase 6 / the spine promote them (build-order §5) and so does a re-run
   of this file.

### S.3 Deviations from the letter of phase-05 §2 — record in `DEVELOPMENT_LOG.md` (verify agent)

| # | Contract text | What was built | Why |
|---|---|---|---|
| SD-1 | §2.1 `CHECK chk_leads_not_self_duplicate: duplicate_of_lead_id IS NULL OR duplicate_of_lead_id <> id` | two triggers `trg_leads_not_self_duplicate_insert` / `_update` (BEFORE INSERT / UPDATE, `SIGNAL SQLSTATE '45000'` with the constraint name in the message) + a model `saving` refusal | MariaDB 10.4.32 rejects any CHECK naming an AUTO_INCREMENT column: **error 1901** "Function or expression 'AUTO_INCREMENT' cannot be used in the CHECK clause of `id`" (probed). Same guarantee, still in the database (D17). §11 test wording that expects a CHECK name should assert the trigger / the 45000 instead |
| SD-2 | §2.2 `occurred_at` "defaults to now()"; §2.4 `converted_at` timestamp not null | `DEFAULT CURRENT_TIMESTAMP` on both (plus a model default for `occurred_at`) | a first `TIMESTAMP NOT NULL` column with no explicit default gets MariaDB's implicit `ON UPDATE CURRENT_TIMESTAMP` (`explicit_defaults_for_timestamp=OFF`), which would rewrite `converted_at` on supersede. Verified: no column of the nine tables carries `ON UPDATE` |
| SD-3 | Keys blocks | extra plain indexes on every FK column not already leading an index: `leads.service_id`, `leads.lead_import_id`, `lead_follow_ups.scheduled_at` / `reminder_due_at` / `completed_by` (the first two are column-note indexes), `lead_activities.occurred_at` (column note), `lead_conversions.collaborator_referral_id`, `lead_import_rows.duplicate_lead_id`, `client_documents.shared_by` | F-9.2 / PRF-04: every FK column indexed; the deferred ones get theirs up front so a promoter adds only a constraint |
| SD-4 | §2.9 `visible_to_client` default `crm.client_visible_documents_default`; §2.3 `remind_before_minutes` default `crm.follow_up_reminder_minutes` | DB defaults `0` and `60` (the settings' defaults); the services write the resolved setting | a column default cannot read a setting |
| SD-5 | [D-P5-2] affected list | the deferred-FK file also promotes `testimonials.client_id` and `portfolio_items.client_id` → `clients.id` `nullOnDelete` | phase-04 §13 "Phase 5 — CRM" asks for exactly this; build-order §5 names Phase 5 the promoter |

**Risks found while probing (no change made — contract owner's call):**

- **R-S1 — InnoDB `ON DELETE SET NULL` bypasses CHECK constraints.** Probed: a row satisfying `chk_lc_target` only
  through `project_id` ends with `client_id` and `project_id` both NULL after its project is hard-deleted. Only a
  `conversion_type = project` row (no client) can reach it, and Phase 6 soft-deletes projects, so it is theoretical
  today; Phase 6's promoter should keep `lead_conversions.project_id` hard-delete-proof or accept the NULL pair.
- **R-S2 — §7 route `POST /admin/leads/conversions/{conversion}/supersede` carries `can:convert,lead` but has no
  `{lead}` parameter**, so the middleware cannot resolve a subject. Use `can:supersede,conversion`
  (`LeadConversionPolicy::supersede` = reach the lead + `leads.edit` + `clients.create` + conversion still live).
- **R-S3 — `client_contacts.primary_guard` ignores `deleted_at`** (contract expression, kept verbatim): a trashed
  primary still holds the slot. `ClientContactService` must demote (`is_primary = false`) before it soft-deletes.
- **R-S4 — `lead_follow_ups.open_guard` ignores `deleted_at`** likewise: `LeadService::delete()` already cancels the
  open follow-up (§6.1); nothing may soft-delete a pending follow-up without changing its status first.

### S.4 `app/Providers/AppServiceProvider.php` — policy registrations and the portal gate

Laravel would guess `App\Models\Crm\X` → `App\Policies\Crm\XPolicy`, but this provider registers every policy
explicitly "for the same one-place reason", so do the same. Imports — add:

```php
use App\Models\Crm\Client;
use App\Models\Crm\ClientContact;
use App\Models\Crm\ClientDocument;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadConversion;
use App\Models\Crm\LeadFollowUp;
use App\Models\Crm\LeadImport;
use App\Policies\Crm\ClientContactPolicy;
use App\Policies\Crm\ClientDocumentPolicy;
use App\Policies\Crm\ClientPolicy;
use App\Policies\Crm\ClientPortalPolicy;
use App\Policies\Crm\LeadActivityPolicy;
use App\Policies\Crm\LeadConversionPolicy;
use App\Policies\Crm\LeadFollowUpPolicy;
use App\Policies\Crm\LeadImportPolicy;
use App\Policies\Crm\LeadPolicy;
```

`POLICIES` — append after `ContactInquiry::class => ContactInquiryPolicy::class,`:

```php
        // phase-05 §9.3: one policy per CRM model (LeadImportRow is authorised through its batch).
        Lead::class => LeadPolicy::class,
        LeadActivity::class => LeadActivityPolicy::class,
        LeadFollowUp::class => LeadFollowUpPolicy::class,
        LeadConversion::class => LeadConversionPolicy::class,
        LeadImport::class => LeadImportPolicy::class,
        Client::class => ClientPolicy::class,
        ClientContact::class => ClientContactPolicy::class,
        ClientDocument::class => ClientDocumentPolicy::class,
```

In `registerPolicies()`, after the `foreach`:

```php
        // phase-05 §9.3: ClientPortalPolicy::section() answers for a section key, not a model —
        // Gate::allows('client-portal-section', 'documents'): unregistered or module-off = 404, no permission = 403.
        Gate::define(ClientPortalPolicy::ABILITY, [ClientPortalPolicy::class, 'section']);
```

### S.5 `app/Support/Modules.php` — `MODEL_MODULES`

Every model instance answers through `moduleSlug()`; a class-string check (`can('viewAny', LeadImport::class)`) falls
back to the naming convention, which is wrong for these six. Imports — add:

```php
use App\Models\Crm\ClientContact;
use App\Models\Crm\LeadActivity;
use App\Models\Crm\LeadConversion;
use App\Models\Crm\LeadFollowUp;
use App\Models\Crm\LeadImport;
use App\Models\Crm\LeadImportRow;
```

Append inside `MODEL_MODULES`, after the last existing entry:

```php
        // phase-05 §4.1: the lead sub-records belong to the `leads` module, contacts to `clients`.
        LeadActivity::class => 'leads',
        LeadFollowUp::class => 'leads',
        LeadConversion::class => 'leads',
        LeadImport::class => 'leads',
        LeadImportRow::class => 'leads',
        ClientContact::class => 'clients',
```

(`Lead` → `leads`, `Client` → `clients`, `ClientDocument` → `client_documents` resolve by convention once the
`client_documents` slug is in `PermissionRegistry`.)

### S.6 Permissions the policies check (verification list for the §4 `PermissionRegistry` block)

The policies build `{module}.{ability}` from the `Ability` enum; nothing is typed as a string except the two portal
names. A permission missing from the registry simply denies.

| Checked by | Permissions |
|---|---|
| `LeadPolicy`, `LeadActivityPolicy`, `LeadFollowUpPolicy`, `LeadConversionPolicy`, `LeadImportPolicy` | `leads.view_any` `leads.view` `leads.create` `leads.edit` `leads.delete` `leads.restore` `leads.change_status` `leads.assign` `leads.import` `leads.export` `leads.print`; `clients.create` (convert, supersede); `projects.create` (`LeadPolicy::convertToProject`) |
| `ClientPolicy`, `ClientContactPolicy` | `clients.view_any` `clients.view` `clients.create` `clients.edit` `clients.delete` `clients.restore` `clients.change_status` `clients.assign` `clients.view_financial` `clients.export` `clients.print`; `users.create` (`managePortal`, `grantPortalAccess`); `client_portal.profile` (`viewOwn`, `updateOwnProfile`) |
| `ClientDocumentPolicy` | `client_documents.view_any` `client_documents.view` `client_documents.upload` `client_documents.download` `client_documents.edit` `client_documents.delete` `client_documents.change_status`; `client_portal.download` (`downloadAsClient`) |
| `ClientPortalPolicy` | the section's own `permission()` (a `client_portal.*` name) |

Decisions the controllers role should know: `LeadPolicy::viewAny` accepts `leads.view` **or** `leads.view_any`
(the rows are narrowed by the scope); record access needs `leads.view` plus reach. `ClientDocumentPolicy::download`
needs `download` **and** (`view` or `view_any`). `LeadActivityPolicy::update/delete`: `leads.edit` on a live, reachable
lead; the author only inside `crm.activity_edit_window_minutes` (default 1440), another `leads.edit` holder at any time;
system rows never.

### S.7 `tests/Support/index-manifest.php` — phase-05 rows (F-9.2, PRF-04, E4)

Append under a Phase 5 banner. Every Keys-block index plus every FK column (all present — verified against
`information_schema.STATISTICS` with the manifest's leading-prefix rule):

```php
    'clients'          => [['client_code'], ['user_id'], ['status', 'company_name'], ['account_manager_id'], ['email_normalized'], ['phone_normalized'], ['whatsapp_normalized'], ['lead_id'], ['referral_code_captured'], ['deleted_at'], ['created_by'], ['updated_by']],
    'client_contacts'  => [['client_id', 'primary_guard'], ['user_id'], ['client_id', 'is_primary'], ['email_normalized'], ['phone_normalized'], ['portal_access'], ['client_id'], ['created_by'], ['updated_by']],
    'client_documents' => [['client_id', 'category'], ['client_id', 'visible_to_client'], ['checksum'], ['expires_at'], ['client_id'], ['shared_by'], ['created_by'], ['updated_by']],
    'leads'            => [['lead_no'], ['contact_inquiry_id'], ['status', 'follow_up_at', 'id'], ['assigned_to', 'status'], ['created_by', 'status'], ['follow_up_at'], ['last_activity_at'], ['source', 'created_at'], ['email_normalized'], ['phone_normalized'], ['whatsapp_normalized'], ['client_id'], ['referral_code_captured'], ['referral_visit_id'], ['deleted_at'], ['service_id'], ['lead_import_id'], ['assigned_to'], ['assigned_by'], ['duplicate_of_lead_id'], ['converted_by'], ['created_by'], ['updated_by']],
    'lead_activities'  => [['lead_id', 'occurred_at', 'id'], ['type', 'occurred_at'], ['created_by', 'occurred_at'], ['lead_follow_up_id'], ['occurred_at'], ['lead_id'], ['from_user_id'], ['to_user_id'], ['related_lead_id'], ['created_by'], ['updated_by']],
    'lead_follow_ups'  => [['lead_id', 'open_guard'], ['previous_follow_up_id'], ['status', 'reminder_due_at', 'reminder_sent_at'], ['assigned_to', 'status', 'scheduled_at'], ['lead_id', 'scheduled_at'], ['scheduled_at'], ['reminder_due_at'], ['lead_id'], ['assigned_to'], ['completed_by'], ['created_by'], ['updated_by']],
    'lead_conversions' => [['lead_id', 'active_guard'], ['client_id'], ['project_id'], ['converted_at'], ['converted_by', 'converted_at'], ['collaborator_referral_id'], ['lead_id'], ['converted_by'], ['created_by'], ['updated_by']],
    'lead_imports'     => [['status', 'created_at'], ['file_hash'], ['created_by', 'created_at'], ['created_by'], ['updated_by']],
    'lead_import_rows' => [['lead_import_id', 'row_number'], ['lead_import_id', 'status'], ['lead_id'], ['lead_import_id'], ['duplicate_lead_id']],
    // phase-05 promotes these two Phase 4 columns to foreign keys; the index already exists (phase-04 rows).
```

### S.8 Model contract other roles build on (no file to edit — read before writing services / controllers / views)

- **Mass assignment.** Each model's `$fillable` is the form it serves; everything with its own service method or
  provenance is written with `forceFill()`:
  - `Lead` fillable: `name company email phone whatsapp country country_code service_id interested_service
    budget_amount source source_detail notes`. forceFill: `lead_no`, `status` / `status_changed_at` / `won_at` /
    `lost_at` / `lost_reason`, `assigned_to` / `assigned_at` / `assigned_by`, `follow_up_at` / `last_contacted_at` /
    `last_activity_at`, `duplicate_of_lead_id` / `duplicate_flagged_at` / `duplicate_note`, `client_id` /
    `converted_at` / `converted_by`, `referral_code_captured` / `referral_recorded_at` / `referral_visit_id`,
    `contact_inquiry_id`, `lead_import_id`.
  - `Client` fillable: the staff form (profile, address, billing, tax, `currency`, `payment_terms_days`, `source`,
    `notes`). forceFill: `client_code`, `user_id`, `status` / `status_reason` / `status_changed_at`, `portal_enabled` /
    `portal_invited_at`, `account_manager_id`, `lead_id`, the referral pair. `notes` is `$hidden`.
  - `ClientContact` fillable: `name designation department email phone whatsapp is_billing_contact
    receives_notifications notes`; forceFill `user_id`, `is_primary`, `portal_access`; `client_id` via the relation.
  - `ClientDocument` fillable: `title category description valid_from expires_at`; forceFill `disk path original_name
    mime_type extension size_bytes checksum visible_to_client shared_at shared_by`. `disk` / `path` are `$hidden`.
  - `LeadActivity` fillable: `type subject body outcome duration_minutes occurred_at meta`; forceFill `is_system`,
    `from_status` / `to_status`, `from_user_id` / `to_user_id`, `lead_follow_up_id`, `related_lead_id`.
  - `LeadFollowUp` fillable: `type scheduled_at remind_before_minutes notes`; forceFill `assigned_to`, `status`,
    `reminder_sent_at`, `completed_*`, `outcome` / `outcome_note`, `previous_follow_up_id`, `rescheduled_at`,
    `cancel_reason`.
  - `LeadConversion` fillable (insert-once snapshot): `conversion_type created_client matched_by from_status
    lead_snapshot field_map budget_amount referral_code converted_at notes`; forceFill `lead_id client_id
    converted_by project_id collaborator_referral_id superseded_at supersede_reason`.
  - `LeadImport` fillable: `original_filename delimiter encoding column_map defaults duplicate_strategy`; forceFill
    `stored_path file_hash status error_report_path started_at finished_at failure_message`; counters only through
    atomic `increment()`. `stored_path` / `error_report_path` are `$hidden`.
  - `LeadImportRow` fillable: `row_number raw status lead_id duplicate_lead_id duplicate_match_type errors`.
- **Model hooks that throw `LogicException`:** changing `leads.lead_no` or `clients.client_code`; a lead linked to
  itself; updating or deleting a `LeadActivity` whose `is_system` is true (a system *type* always stores
  `is_system = true`); any `LeadConversion` update outside `superseded_at supersede_reason project_id
  collaborator_referral_id updated_by updated_at`, un-superseding, and every delete; changing a `LeadImportRow`'s
  `lead_import_id` / `row_number` / `raw`, and every Eloquent delete of one (prune with
  `LeadImportRow::query()->createdBefore($cutoff)->delete()`, a query-builder delete).
- **Computed on save:** the `*_normalized` columns (`ContactNormalizer::email()` / `::phone()`, static — a contact
  borrows its client's `country_code`); `lead_follow_ups.reminder_due_at`; `clients.billing_address = null` when
  `billing_same_as_address`. The services may still normalise first; the result is identical.
- **Generated guards** (`open_guard`, `primary_guard`, `active_guard`) are never written by Eloquent — even after
  `replicate()` — and read as null on a fresh instance until `refresh()`. Catch the 1062 from `uq_lfu_open`,
  `uq_lc_lead_active`, `uq_cc_primary`, `uq_leads_inquiry`, `uq_lir_row` as the domain signal the contract describes.
- **Visibility.** `Lead` carries `#[ScopedBy(LeadVisibilityScope::class)]`: for the signed-in user without
  `leads.view_any`, every `Lead` query (index, board counts and sums, exports, relations, route binding) is limited to
  `assigned_to = me OR created_by = me`; no signed-in user (console, queue, a guest website request) means no scope.
  Work done for a user outside their request must add `->visibleTo($user)` (or `LeadVisibilityScope::forUser()`).
  Bypass deliberately with `Lead::withoutGlobalScope(LeadVisibilityScope::class)` — the duplicate detector's
  `restricted` matches, the inquiry target's 1062 lookup, the policies' parent resolution (`resolveLead()` on
  `LeadActivity` / `LeadFollowUp` / `LeadConversion`). `LeadImport::visibleTo($user)` / `isVisibleTo()`: creator or
  `leads.view_any`.
- **Portal.** `Client::resolvePortalFor(User)` applies `ClientContext`'s rule (primary binding wins, else a contact
  with `portal_access`; `portal_enabled` + `ClientStatus::canUsePortal()` + not trashed) for a user who is not the
  signed-in one; `Client::canUsePortal()` is the state half. Policies use `ClientContext` for the signed-in user.
  `ClientDocument::scopeSharedWithClient($client)` is §9.2's documents rule.
- **Relations to later phases** (`Client::projects() invoices() projectPayments() supportTickets() meetings()
  collaboratorReferrals()`, `Lead::collaboratorReferrals()`, `LeadConversion::project() collaboratorReferral()`)
  throw a `LogicException` naming the owning phase until its model exists; they follow the CLAUDE.md §2 folders
  (`App\Models\Project\Project`, `App\Models\Finance\Invoice` / `ProjectPayment`,
  `App\Models\Collaborator\CollaboratorReferral`, `App\Models\Support\SupportTicket` / `Meeting`) and the constant
  beside each is the one line a later phase changes if it picks another name. Phase 5 code never traverses them.
- **Enum helpers** beyond §3's minimum: `LeadStatus::canTransitionTo() isReopening() requiresReasonFor()
  requiresFollowUp() ordered() open()`; `LeadActivityType::recordsContact() manual()`; `LeadFollowUpType::icon()`;
  `LeadDuplicateMatchType::field()`; `LeadImportStatus::isCancellable()`; `LeadImportRowStatus::isSkipped() isError()`;
  `LeadImportDuplicateStrategy::description()`; `ClientStatus::requiresReason()`. Every colour is in
  `EnumContractTest`'s token list; every icon is in `resources/data/icons.php`.

## Controllers, Form Requests and middleware (phase-05 role: controllers/requests/middleware)

Verified before hand-off: `php -l` and `./vendor/bin/pint --test` clean on every file below; a reflection script loaded
all 65 classes/traits and confirmed every service method, DTO factory, model relation/scope, enum helper and policy
method the controllers and requests call exists on disk (`missing: 0`). No
route was registered in the application, no test was run, nothing touched the database, no git command was run.

A second scratch script booted the app against an in-memory SQLite connection and an array cache (never `my_office`),
registered C.3 and C.4 verbatim under throw-away prefixes and checked them: **91 routes** (67 admin + 24 client — every
§7 row plus the two queued-export downloads of C.7 #8), **0 problems**: every action exists, no name twice, every admin route has exactly one `module:` and one `can:`,
every client route has one `can:` and `client.context`, every `{parameter}` is a controller argument, and every URI
round-trips through the router to its own name.

### C.1 Files delivered (all new; one existing file is replaced through C.5, not edited)

```
app/Http/Middleware/EnsureClientContext.php            alias client.context (§6.9) — C.2
app/Http/Controllers/Admin/Crm/Concerns/RespondsForCrm.php   authorize + JSON/redirect answers + CrmRuleException → 422/toast
                                                             + crm.* settings read in PHP and handed to views
app/Http/Controllers/Admin/LeadController.php          index create store show edit update destroy restore print status assign
                                                       bulkAssign bulkStatus bulkDestroy duplicateCheck duplicateLink export
                                                       exportDownload
app/Http/Controllers/Admin/LeadBoardController.php     index column move
app/Http/Controllers/Admin/LeadActivityController.php  store update destroy
app/Http/Controllers/Admin/LeadFollowUpController.php   index store complete reschedule cancel
app/Http/Controllers/Admin/LeadConversionController.php create store supersede
app/Http/Controllers/Admin/LeadImportController.php    index template store show mapping validateRows run cancel errors
app/Http/Controllers/Admin/ClientController.php        index create store show edit update destroy restore print export status
                                                       accountManager financials enablePortal disablePortal exportDownload
app/Http/Controllers/Admin/ClientContactController.php store update primary destroy
app/Http/Controllers/Admin/ClientDocumentController.php index store update visibility download destroy
app/Http/Controllers/Client/Concerns/ServesClientPortal.php  ClientContext + ClientPortalRegistry section resolution (404s)
app/Http/Controllers/Client/{Profile,Project,Task,Milestone,File,Document,Invoice,Payment,Meeting,Ticket,Message,Notification}Controller.php
app/Http/Requests/Crm/CrmListRequest.php               every staff list/board/export query string (hostile arrays → 422)
app/Http/Requests/Crm/CrmFormRequest.php               base: authorize repeats the route's can:, money/rate/phone rules, crmSetting()
app/Http/Requests/Crm/Concerns/{ValidatesLeadIds,ValidatesClientFields,ValidatesImportOptions}.php
app/Http/Requests/Crm/  (the 19 §7 names)  StoreLeadRequest UpdateLeadRequest ChangeLeadStatusRequest AssignLeadRequest
                        BulkLeadAssignRequest BulkLeadStatusRequest StoreLeadActivityRequest StoreLeadFollowUpRequest
                        CompleteLeadFollowUpRequest ConvertLeadRequest StoreLeadImportRequest UpdateLeadImportMappingRequest
                        StoreClientRequest UpdateClientRequest ChangeClientStatusRequest StoreClientContactRequest
                        UpdateClientContactRequest StoreClientDocumentRequest EnableClientPortalRequest
app/Http/Requests/Crm/  (actions §7 lists without naming a request)  UpdateLeadActivityRequest RescheduleLeadFollowUpRequest
                        CancelLeadFollowUpRequest LinkDuplicateLeadRequest DuplicateCheckRequest SupersedeConversionRequest
                        BulkLeadDestroyRequest DeleteRecordRequest AssignAccountManagerRequest DisableClientPortalRequest
                        CancelLeadImportRequest UpdateClientDocumentRequest ChangeDocumentVisibilityRequest
                        DownloadCrmExportRequest
app/Http/Requests/ClientPortal/UpdateClientProfileRequest.php  (§7 name)
app/Http/Requests/ClientPortal/ClientPortalListRequest.php     every client-panel list query string
```

Every request builds the services role's DTO with its own `fromArray()` (`App\DataObjects\Crm\*`) from `validated()`,
so a field the rules do not list — `lead_no`, `client_code`, `status`, `user_id`, `portal_enabled`, `logo_path`, a tax
field on the client profile — never reaches a service (test 81). Every scalar has a type rule and every nested block is
`array:<allowed keys>`, so `?name[]=x` / `follow_up=string` is a 422, never a 500.

### C.2 `bootstrap/app.php` — the `client.context` alias

Import — add beside the other middleware imports:

```php
use App\Http\Middleware\EnsureClientContext;
```

In `$middleware->alias([...])`, after `'panel' => EnsurePanelAccess::class,`:

```php
            // phase-05 §6.9 / D31: every /client route. 403 with an explanatory page when crm.client_portal_enabled is
            // off, the login resolves to no client, the portal is disabled or the client's status forbids it — re-evaluated
            // on every request, so revocation is immediate (test 63).
            'client.context' => EnsureClientContext::class,
```

`App\Support\ClientContext` is `#[Scoped]`; the middleware still calls `forget()` before resolving so one application
serving two requests (the test client, a long-running worker) never keeps a stale answer.

### C.3 `routes/admin.php` — inside the existing `Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'panel:admin'])` group

Imports — add:

```php
use App\Enums\LeadStatus;
use App\Http\Controllers\Admin\ClientContactController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\ClientDocumentController;
use App\Http\Controllers\Admin\LeadActivityController;
use App\Http\Controllers\Admin\LeadBoardController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LeadConversionController;
use App\Http\Controllers\Admin\LeadFollowUpController;
use App\Http\Controllers\Admin\LeadImportController;
```

Paste at the end of the group body. Every literal segment precedes its `{parameter}` sibling and every parameter is
`whereNumber`; each route carries exactly one `module:` (its block) and the contract's `can:` (one deviation, C.7 #1).
The two throttles carry their own limiter prefix, as the Phase 2/3 routes do.

```php
        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: leads (list, board, follow-ups, import, conversion)
        |------------------------------------------------------------------
        | {lead} binds through LeadVisibilityScope, so another rep's lead is a 404 before any action runs (D30).
        | Controllers repeat the can: and add the policy's record rule.
        */
        Route::middleware('module:leads')->group(static function (): void {
            Route::get('leads', [LeadController::class, 'index'])->middleware('can:leads.view')->name('leads.index');
            Route::get('leads/board', [LeadBoardController::class, 'index'])->middleware('can:leads.view')->name('leads.board');
            Route::get('leads/board/column/{status}', [LeadBoardController::class, 'column'])->whereIn('status', LeadStatus::values())->middleware('can:leads.view')->name('leads.board.column');
            Route::get('leads/follow-ups', [LeadFollowUpController::class, 'index'])->middleware('can:leads.view')->name('leads.follow-ups.index');
            Route::get('leads/create', [LeadController::class, 'create'])->middleware('can:leads.create')->name('leads.create');
            Route::post('leads', [LeadController::class, 'store'])->middleware('can:leads.create')->name('leads.store');
            Route::post('leads/duplicate-check', [LeadController::class, 'duplicateCheck'])->middleware(['can:leads.create', 'throttle:60,1,crm-duplicate-check'])->name('leads.duplicate-check');
            Route::get('leads/export', [LeadController::class, 'export'])->middleware('can:leads.export')->name('leads.export');
            // C.7 #8: the link BuildCrmExport puts in the "export ready" notification.
            Route::get('leads/export/download', [LeadController::class, 'exportDownload'])->middleware('can:leads.export')->name('leads.export.download');
            Route::post('leads/bulk/assign', [LeadController::class, 'bulkAssign'])->middleware('can:leads.assign')->name('leads.bulk.assign');
            Route::post('leads/bulk/status', [LeadController::class, 'bulkStatus'])->middleware('can:leads.change_status')->name('leads.bulk.status');
            Route::post('leads/bulk/destroy', [LeadController::class, 'bulkDestroy'])->middleware('can:leads.delete')->name('leads.bulk.destroy');

            Route::get('leads/import', [LeadImportController::class, 'index'])->middleware('can:leads.import')->name('leads.import.index');
            Route::get('leads/import/template', [LeadImportController::class, 'template'])->middleware('can:leads.import')->name('leads.import.template');
            Route::post('leads/import', [LeadImportController::class, 'store'])->middleware('can:leads.import')->name('leads.import.store');
            Route::get('leads/import/{import}', [LeadImportController::class, 'show'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.show');
            Route::put('leads/import/{import}/mapping', [LeadImportController::class, 'mapping'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.mapping');
            Route::post('leads/import/{import}/validate', [LeadImportController::class, 'validateRows'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.validate');
            Route::post('leads/import/{import}/run', [LeadImportController::class, 'run'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.run');
            Route::post('leads/import/{import}/cancel', [LeadImportController::class, 'cancel'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.cancel');
            Route::get('leads/import/{import}/errors', [LeadImportController::class, 'errors'])->whereNumber('import')->middleware('can:leads.import')->name('leads.import.errors');

            // C.7 #1: the contract's `can:convert,lead` cannot resolve — this URI has no {lead}.
            Route::post('leads/conversions/{conversion}/supersede', [LeadConversionController::class, 'supersede'])->whereNumber('conversion')->middleware('can:supersede,conversion')->name('leads.conversions.supersede');

            Route::get('leads/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->middleware('can:view,lead')->name('leads.show');
            Route::get('leads/{lead}/edit', [LeadController::class, 'edit'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.edit');
            Route::put('leads/{lead}', [LeadController::class, 'update'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.update');
            Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->whereNumber('lead')->middleware('can:delete,lead')->name('leads.destroy');
            Route::post('leads/{lead}/restore', [LeadController::class, 'restore'])->whereNumber('lead')->withTrashed()->middleware('can:leads.restore')->name('leads.restore');
            Route::get('leads/{lead}/print', [LeadController::class, 'print'])->whereNumber('lead')->middleware('can:leads.print')->name('leads.print');
            Route::patch('leads/{lead}/status', [LeadController::class, 'status'])->whereNumber('lead')->middleware('can:changeStatus,lead')->name('leads.status');
            Route::patch('leads/{lead}/assign', [LeadController::class, 'assign'])->whereNumber('lead')->middleware('can:assign,lead')->name('leads.assign');
            Route::patch('leads/{lead}/board-move', [LeadBoardController::class, 'move'])->whereNumber('lead')->middleware(['can:leads.change_status', 'throttle:120,1,crm-board-move'])->name('leads.board.move');
            Route::post('leads/{lead}/duplicate-link', [LeadController::class, 'duplicateLink'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.duplicate-link');

            Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.activities.store');
            Route::put('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'update'])->whereNumber(['lead', 'activity'])->middleware('can:update,activity')->name('leads.activities.update');
            Route::delete('leads/{lead}/activities/{activity}', [LeadActivityController::class, 'destroy'])->whereNumber(['lead', 'activity'])->middleware('can:delete,activity')->name('leads.activities.destroy');

            Route::post('leads/{lead}/follow-ups', [LeadFollowUpController::class, 'store'])->whereNumber('lead')->middleware('can:update,lead')->name('leads.follow-ups.store');
            Route::patch('leads/{lead}/follow-ups/{followUp}/complete', [LeadFollowUpController::class, 'complete'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.complete');
            Route::patch('leads/{lead}/follow-ups/{followUp}/reschedule', [LeadFollowUpController::class, 'reschedule'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.reschedule');
            Route::patch('leads/{lead}/follow-ups/{followUp}/cancel', [LeadFollowUpController::class, 'cancel'])->whereNumber(['lead', 'followUp'])->middleware('can:complete,followUp')->name('leads.follow-ups.cancel');

            Route::get('leads/{lead}/convert', [LeadConversionController::class, 'create'])->whereNumber('lead')->middleware('can:convert,lead')->name('leads.convert.form');
            Route::post('leads/{lead}/convert', [LeadConversionController::class, 'store'])->whereNumber('lead')->middleware('can:convert,lead')->name('leads.convert.store');
        });

        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: clients, contacts
        |------------------------------------------------------------------
        | Money is withheld, not hidden: the financial figures are computed and passed only for clients.view_financial.
        */
        Route::middleware('module:clients')->group(static function (): void {
            Route::get('clients', [ClientController::class, 'index'])->middleware('can:clients.view_any')->name('clients.index');
            Route::get('clients/create', [ClientController::class, 'create'])->middleware('can:clients.create')->name('clients.create');
            Route::post('clients', [ClientController::class, 'store'])->middleware('can:clients.create')->name('clients.store');
            Route::get('clients/export', [ClientController::class, 'export'])->middleware('can:clients.export')->name('clients.export');
            // C.7 #8: the link BuildCrmExport puts in the "export ready" notification.
            Route::get('clients/export/download', [ClientController::class, 'exportDownload'])->middleware('can:clients.export')->name('clients.export.download');
            Route::get('clients/{client}', [ClientController::class, 'show'])->whereNumber('client')->middleware('can:view,client')->name('clients.show');
            Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->whereNumber('client')->middleware('can:update,client')->name('clients.edit');
            Route::put('clients/{client}', [ClientController::class, 'update'])->whereNumber('client')->middleware('can:update,client')->name('clients.update');
            Route::delete('clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client')->middleware('can:delete,client')->name('clients.destroy');
            Route::post('clients/{client}/restore', [ClientController::class, 'restore'])->whereNumber('client')->withTrashed()->middleware('can:clients.restore')->name('clients.restore');
            Route::get('clients/{client}/print', [ClientController::class, 'print'])->whereNumber('client')->middleware('can:clients.print')->name('clients.print');
            Route::patch('clients/{client}/status', [ClientController::class, 'status'])->whereNumber('client')->middleware('can:changeStatus,client')->name('clients.status');
            Route::patch('clients/{client}/account-manager', [ClientController::class, 'accountManager'])->whereNumber('client')->middleware('can:assign,client')->name('clients.account-manager');
            Route::get('clients/{client}/financials', [ClientController::class, 'financials'])->whereNumber('client')->middleware('can:viewFinancial,client')->name('clients.financials');
            Route::post('clients/{client}/portal/enable', [ClientController::class, 'enablePortal'])->whereNumber('client')->middleware('can:managePortal,client')->name('clients.portal.enable');
            Route::post('clients/{client}/portal/disable', [ClientController::class, 'disablePortal'])->whereNumber('client')->middleware('can:managePortal,client')->name('clients.portal.disable');

            Route::post('clients/{client}/contacts', [ClientContactController::class, 'store'])->whereNumber('client')->middleware('can:update,client')->name('clients.contacts.store');
            Route::put('clients/{client}/contacts/{contact}', [ClientContactController::class, 'update'])->whereNumber(['client', 'contact'])->middleware('can:update,contact')->name('clients.contacts.update');
            Route::patch('clients/{client}/contacts/{contact}/primary', [ClientContactController::class, 'primary'])->whereNumber(['client', 'contact'])->middleware('can:update,contact')->name('clients.contacts.primary');
            Route::delete('clients/{client}/contacts/{contact}', [ClientContactController::class, 'destroy'])->whereNumber(['client', 'contact'])->middleware('can:delete,contact')->name('clients.contacts.destroy');
        });

        /*
        |------------------------------------------------------------------
        | phase-05 §7 — CRM: private client documents (D21)
        |------------------------------------------------------------------
        | The list (view_any) and the download (download) are independent grants (test 70).
        */
        Route::middleware('module:client_documents')->group(static function (): void {
            Route::get('clients/{client}/documents', [ClientDocumentController::class, 'index'])->whereNumber('client')->middleware('can:client_documents.view_any')->name('clients.documents.index');
            Route::post('clients/{client}/documents', [ClientDocumentController::class, 'store'])->whereNumber('client')->middleware('can:client_documents.upload')->name('clients.documents.store');
            Route::put('clients/{client}/documents/{document}', [ClientDocumentController::class, 'update'])->whereNumber(['client', 'document'])->middleware('can:update,document')->name('clients.documents.update');
            Route::patch('clients/{client}/documents/{document}/visibility', [ClientDocumentController::class, 'visibility'])->whereNumber(['client', 'document'])->middleware('can:changeVisibility,document')->name('clients.documents.visibility');
            Route::delete('clients/{client}/documents/{document}', [ClientDocumentController::class, 'destroy'])->whereNumber(['client', 'document'])->middleware('can:delete,document')->name('clients.documents.destroy');
            Route::get('client-documents/{document}/download', [ClientDocumentController::class, 'download'])->whereNumber('document')->middleware('can:download,document')->name('client-documents.download');
        });
```

Route-parameter ↔ controller-argument names (all match): `lead`, `status` (string), `import`, `conversion`, `activity`,
`followUp`, `client`, `contact`, `document`. Every bound child is re-checked against its parent in the controller
(`{activity}` / `{followUp}` of `{lead}`, `{contact}` / `{document}` of `{client}`) and a mismatch is a 404.

### C.4 `routes/client.php` — replace the whole file (Phase 5 owns it and every `client.*` name, D31)

The Phase 1 dashboard row keeps its name, URI and `can:`; it gains `client.context` with every other row. Nothing
below may be redeclared by a later phase: Phase 6 / 10-12 / 13 / 22 register `ClientPortalSection`s instead.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DocumentController;
use App\Http\Controllers\Client\FileController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\MeetingController;
use App\Http\Controllers\Client\MessageController;
use App\Http\Controllers\Client\MilestoneController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\ProjectController;
use App\Http\Controllers\Client\TaskController;
use App\Http\Controllers\Client\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client panel routes (phase-01 §8, phase-05 §7, D31)
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php inside the `web` middleware group.
|
|   auth                 — a session is required
|   active               — status must be Active
|   panel:client         — one of the user's roles must belong to this panel
|   client.context       — the login resolves to a client whose portal is on and whose status allows it,
|                          re-checked on every request (EnsureClientContext, §6.9)
|   module:<slug>        — the owning phase's module switch
|   can:client_portal.*  — the exact portal permission, per route
|
| Phase 5 owns this file and EVERY `client.*` route name (D31, F-6.2). A later phase never redeclares a name here —
| Laravel's last registration wins silently. It registers a `ClientPortalSection` into ClientPortalRegistry, and the
| controllers below resolve it; an unregistered section 404s and has no nav item ([D-P5-1]). Every row carries
| `client.context`, including the rows a later phase fills in (F-12.3). The client is taken from ClientContext,
| never from the request (CLAUDE.md rule 10).
|
*/

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'active', 'panel:client', 'client.context'])
    ->group(function (): void {

        Route::get('/', [DashboardController::class, 'index'])
            ->middleware('can:client_portal.dashboard')
            ->name('dashboard');

        Route::get('profile', [ProfileController::class, 'edit'])->middleware('can:client_portal.profile')->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->middleware('can:client_portal.profile')->name('profile.update');

        // Phase 6 sections: projects, progress, tasks, milestones, files.
        Route::get('projects', [ProjectController::class, 'index'])->middleware(['module:projects', 'can:client_portal.projects'])->name('projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->middleware(['module:projects', 'can:viewByClient,project'])->name('projects.show');
        Route::get('projects/{project}/tasks', [TaskController::class, 'index'])->whereNumber('project')->middleware(['module:tasks', 'can:client_portal.tasks'])->name('tasks.index');
        Route::get('projects/{project}/milestones', [MilestoneController::class, 'index'])->whereNumber('project')->middleware(['module:project_milestones', 'can:client_portal.milestones'])->name('milestones.index');
        Route::get('projects/{project}/progress', [ProjectController::class, 'progress'])->whereNumber('project')->middleware(['module:projects', 'can:client_portal.projects'])->name('progress.show');
        Route::get('files', [FileController::class, 'index'])->middleware(['module:files', 'can:client_portal.files'])->name('files.index');
        Route::get('files/{file}/download', [FileController::class, 'download'])->whereNumber('file')->middleware(['module:files', 'can:client_portal.download'])->name('files.download');

        // Phase 5's own documents section (private disk, D21).
        Route::get('documents', [DocumentController::class, 'index'])->middleware(['module:client_documents', 'can:client_portal.documents'])->name('documents.index');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereNumber('document')->middleware(['module:client_documents', 'can:client_portal.download'])->name('documents.download');

        // Phase 13 invoices; the spine's payments.
        Route::get('invoices', [InvoiceController::class, 'index'])->middleware(['module:invoices', 'can:client_portal.invoices'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->middleware(['module:invoices', 'can:client_portal.invoices'])->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice')->middleware(['module:invoices', 'can:client_portal.download'])->name('invoices.pdf');
        Route::get('payments', [PaymentController::class, 'index'])->middleware(['module:project_payments', 'can:client_portal.payments'])->name('payments.index');

        // Phase 22 sections: meetings, tickets, messages, notifications.
        Route::get('meetings', [MeetingController::class, 'index'])->middleware(['module:meetings', 'can:client_portal.meetings'])->name('meetings.index');
        Route::get('tickets', [TicketController::class, 'index'])->middleware(['module:support_tickets', 'can:client_portal.tickets'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket')->middleware(['module:support_tickets', 'can:client_portal.tickets'])->name('tickets.show');
        Route::get('messages', [MessageController::class, 'index'])->middleware(['module:messages', 'can:client_portal.messages'])->name('messages.index');
        Route::get('messages/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation')->middleware(['module:messages', 'can:client_portal.messages'])->name('messages.show');
        Route::get('notifications', [NotificationController::class, 'index'])->middleware('can:client_portal.notifications')->name('notifications.index');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->middleware('can:client_portal.notifications')->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->middleware('can:client_portal.notifications')->name('notifications.read');

        // Phase 22: client writes
    });
```

### C.5 `app/Http/Controllers/Client/DashboardController.php` — replace the Phase 1 file (contract §7 names it)

The Phase 1 view variables are kept unchanged (`panel`, `user`, `greeting`, `roleChips`, `currentLogin`,
`previousLogin`, `sessionCount`, `accountLinks`), so the existing `client.dashboard` view keeps rendering; the §8.10
cards arrive as `dashboard` (`DashboardData`) with `client`, `clientName` and `portalSections`.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\PanelType;
use App\Http\Controllers\Client\Concerns\ServesClientPortal;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Session;
use App\Models\User;
use App\Services\Crm\ClientPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Client panel landing page — `client.dashboard` (phase-01 §8, phase-05 §6.9, §8.10).
 *
 * "Where do we stand": every card is a registered, permitted section's `badgeCount()` for the signed-in user's own
 * client (ClientPortalService::dashboard()), plus Phase 5's own documents and notifications counts. A card whose
 * section is not registered is absent — never a zero, never a number this screen cannot prove (D28). The client comes
 * from ClientContext, which `client.context` resolved for this request.
 */
final class DashboardController extends Controller
{
    use ServesClientPortal;

    /**
     * Permission namespace for this panel, as declared in App\Support\PermissionRegistry.
     */
    private const PORTAL = 'client_portal';

    public function __construct(
        private readonly ClientPortalService $portal,
    ) {}

    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    public function index(Request $request): View
    {
        Gate::authorize(self::PORTAL.'.dashboard');

        $user = $this->portalUser($request);
        $client = $this->client();

        $logins = LoginHistory::query()
            ->forUser($user)
            ->successful()
            ->latestFirst()
            ->limit(2)
            ->get();

        return view('client.dashboard', array_merge($this->portalViewData($request, $client), [
            'panel' => PanelType::Client,
            'user' => $user,
            'greeting' => $this->greeting($user),
            'roleChips' => $this->roleChips($user),
            'currentLogin' => $logins->first(),
            'previousLogin' => $logins->get(1),
            'sessionCount' => Session::query()->forUser($user)->count(),
            'accountLinks' => $this->accountLinks(),
            'dashboard' => $this->portal->dashboard($client),
        ]));
    }

    private function greeting(User $user): string
    {
        $hour = (int) now($user->effectiveTimezone())->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function roleChips(User $user): Collection
    {
        return $user->roles
            ->map(static fn (object $role): string => (string) (
                filled($role->label ?? null) ? $role->label : ($role->name ?? '')
            ))
            ->filter(static fn (string $label): bool => $label !== '')
            ->values();
    }

    /**
     * @return array{profile: string|null, password: string|null, sessions: string|null}
     */
    private function accountLinks(): array
    {
        $prefix = PanelType::Client->routePrefix();

        return [
            'profile' => $this->firstUrl(['client.profile.edit', "{$prefix}.account.profile", 'account.profile', 'profile.edit']),
            'password' => $this->firstUrl(["{$prefix}.account.password", 'account.password']),
            'sessions' => $this->firstUrl(["{$prefix}.account.sessions", 'account.sessions']),
        ];
    }

    /**
     * @param  list<string>  $candidates
     */
    private function firstUrl(array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return null;
    }
}
```

### C.6 Permissions the routes and controllers require (verification list for the §4 `PermissionRegistry` block)

| Where | Permissions |
|---|---|
| lead routes / controllers | `leads.view` `leads.view_any` (list scope, "All" follow-ups) `leads.create` `leads.edit` (policy) `leads.delete` `leads.restore` `leads.print` `leads.export` `leads.import` `leads.assign` `leads.change_status`; `clients.create` + `clients.view` (convert, redirect), `clients.assign` (account manager on the wizard), `projects.create` (hand-off) |
| client routes / controllers | `clients.view_any` `clients.view` `clients.create` `clients.edit` (policy) `clients.delete` `clients.restore` `clients.print` `clients.export` `clients.change_status` `clients.assign` `clients.view_financial` **`clients.view_logs`** (Activity tab — §4.2 adds LOGS); `users.create` (enable portal) |
| document routes | `client_documents.view_any` `client_documents.upload` `client_documents.download` `client_documents.edit` `client_documents.change_status` `client_documents.delete` |
| client panel | `client_portal.dashboard` `.profile` `.projects` `.tasks` `.milestones` `.files` `.documents` `.download` `.invoices` `.payments` `.meetings` `.tickets` `.messages` `.notifications` — the §4.3 set. Today's registry still declares the Phase 1 names (`project_tasks`, `project_milestones`, `files_download`, `support_tickets` …); the §4.3 names must be **appended** (D4) or every panel route but the dashboard/profile 403s |

### C.7 Contract deviations and tensions (each is one edit — the contract owner's call)

1. **`admin.leads.conversions.supersede` middleware.** §7 says `can:convert,lead`, but the URI has no `{lead}`, so the
   `Authorize` middleware can only pass the literal string `'lead'` — a 403 for everyone but Super Admin. Registered
   with `can:supersede,conversion` (`LeadConversionPolicy::supersede`, which reaches the lead and needs `leads.edit` +
   `clients.create`); the controller also 404s when the conversion's lead is not visible. The schema role flagged the
   same (S.3 R-S2).
2. **`client.projects.show` is `can:viewByClient,project` with no Project model yet.** Until Phase 6 binds `{project}`
   to a model with a `viewByClient` policy, the middleware sees a bare string and answers **403**, not the 404 of
   [D-P5-1]. Harmless (no client data exists), but test 80's "unregistered section 404s" must not use this row before
   Phase 6.
3. **Test 80 "a registered section whose module is disabled also 404s"** cannot hold on rows carrying `module:<slug>`:
   `EnsureModuleEnabled` answers **403** before the controller runs. The controller 404s only for unregistered sections
   and for a disabled module on the rows without `module:` (the registry's `visibleSection()` checks the module too).
   Either drop `module:` from the §7 client rows (the registry already gates the module) or read test 80's disabled-module
   clause as 403 — not changed here because the route table is binding.
4. **The Phase 1 panel tests will 403 on `/client`.** `client.context` now requires a real `clients` row bound to the
   login: `PanelIsolationTest::a_panel_account_reaches_exactly_one_panel` (client), `PortalModuleProtectionTest`
   (`/client` asserts 200), `SmokeTest` panel homes and `AuthenticationTest`'s client login redirect all use the seeded
   demo Client user, who has no `clients` row. Do **not** loosen them: bind the demo user to a demo `clients` row
   (`portal_enabled = 1`, `status = active`) in `DemoUserSeeder` (non-production only) or in the tests' seeding helper.
5. **Logo upload.** §2.7 `logo_path` / §6.9 profile `logo_path`: the delivered `ClientService` and `ClientData` accept a
   path but publish no upload, and the client views post a `logo` file. The requests deliberately accept **no**
   `logo` / `logo_path` (a typed path must never reach the column); the posted file is ignored until
   `ClientService` gains an upload method (C.8).
6. **Client-panel detail and download routes.** `ClientPortalSection` publishes a list only. `client.projects.show`,
   `.invoices.show`, `.invoices.pdf`, `.tickets.show`, `.messages.show`, `.files.download` and the project-nested lists
   serve a record only when the owning section also implements `find(Client $client, User $user, int $id): ?Model`
   (`null` → 404), `detailView(): string` and, for downloads, `download(Client $client, User $user, int $id, string
   $variant): Response`. Detected with `method_exists()` so an absent capability is a 404, never a fatal. A later phase
   should formalise these as an interface beside `ClientPortalSection` (services/contract owner) — noted, not invented.
7. **Field names follow the delivered views where the contract names none:** assign dialogs post `assigned_to`; bulk
   status posts `lost_reason` (or `reason`); the complete dialog posts `schedule_next` + `next[...]`; the lead form posts
   `referral_code_captured`, `add_follow_up`, `duplicate_of_lead_id` + `duplicate_note`; the conversion wizard posts
   `client_mode` + `client_id` and `project[name|notes]`; the import wizard posts `column_map[<position>]`,
   `defaults[source|assigned_to|status]`, `delimiter=auto|pipe`, `encoding=auto`. Each is translated into the DTO keys
   in the request's `prepareForValidation()`; the canonical DTO keys are accepted too. List links may use
   `created_from` / `created_to` / `created_preset`, `follow_up=next_7_days`, `converted=yes|no`.
8. **Two routes not in §7:** `admin.leads.export.download` and `admin.clients.export.download` (`GET …/export/download?token=`,
   `can:leads.export` / `can:clients.export`). §6.10 / §10.4 deliver an export above `crm.export_max_rows` "by
   notification", and the services role's `BuildCrmExport` job links to exactly these names; `CrmExportFiles::download()`
   refuses anyone but the requester and any expired or tampered token (404). Drop both rows only together with that
   delivery path.

### C.8 Service capabilities — reconciled against the services on disk

Every call resolves (`missing: 0`): `LeadService`, `LeadDuplicateDetector`, `LeadFollowUpService`, `LeadBoardService`
(`board` / `column` / `move` / `summaries` / `visibleStatuses`), `LeadConversionService`, `LeadImportService`
(`stage` / `headers` / `previewRows` / `previousImportOf` / `updateMapping` / `validateRows` / `run` / `cancel` /
`template` / `errorReport`), `LeadExporter::stream()` and `ClientExporter::stream()` (a `StreamedResponse`, or null
after queueing `BuildCrmExport` above `crm.export_max_rows` — the controller then answers "we will notify you", test 84), `CrmExportFiles::download()`, `ClientService`, `ClientContactService`,
`ClientDocumentService` (incl. `update()`, which §6.8 does not list but §7's `admin.clients.documents.update` needs),
`ClientPortalService::dashboard()` / `updateProfile($client, $data, $contact)`. Still **not** provided — listed, not
invented:

| Gap | Where it bites | Owner |
|---|---|---|
| a logo upload for `clients.logo_path` (staff create / update, and the portal profile) | the delivered client form posts `logo` / `remove_logo`; the requests accept neither and the file is ignored (C.7 #5) | services role |
| the `find` / `detailView` / `download` record capability on a `ClientPortalSection` | client detail and download routes 404 until the owning phase's section provides it (C.7 #6) | contract owner / Phases 6, 10-12, 13, 22 |
| free-text search and a sort other than `scheduled_at` in `LeadFollowUpService::worklist()` | the worklist view offers both; the controller passes none (the rows stay correct, only unfiltered/unsorted) | services role |

### C.9 Settings keys read (all `crm.*` keys are §5's; read in PHP and passed to views as data)

`crm.bulk_max_ids`, `crm.stale_lead_days`, `crm.default_lead_source`, `crm.auto_assign_mode`,
`crm.follow_up_default_offset_hours`, `crm.follow_up_reminder_minutes`, `crm.require_follow_up_on_contacted`,
`crm.lost_reasons`, `crm.whatsapp_link_template`, `crm.duplicate_detection_enabled`, `crm.duplicate_block_on_exact`,
`crm.import_max_rows`, `crm.import_file_retention_days`, `crm.client_visible_documents_default`,
`crm.client_portal_enabled` (middleware); plus the declared `security.allowed_file_types`, `security.max_upload_mb`,
`company.name`, `localization.currency`, `finance.payment_terms_days`, `finance.default_tax_rate`, and
`appearance.table_page_size` through `per_page()`. The `crm.*` reads go through `'crm.'.$key` (the services role's
`InteractsWithCrm` pattern), so `SettingsSplitTruthRegressionTest`'s literal scan of `app/Http` stays green while the
`crm` group is not yet declared; once the §5 block is applied every key above must be in `SettingsRegistry`.

### C.10 View-data contract (reconciled with the views on disk at hand-off)

Every admin screen matches the variable list in its view's header comment; additional variables are harmless extras.

| View | Variables |
|---|---|
| `admin.leads.index` | `leads` (assignee, service), `filters`, `sort`, `direction`, `trashed`, `statusOptions`, `sourceOptions`, `serviceOptions`, `isEmptyModule`, `bulkMax`, `staleDays`, `whatsappTemplate` + dialogs: `assigneeOptions`, `statusLabels`, `transitions`, `followUpRequiredStatuses`, `lostReasons`, `activityTypeOptions`, `outcomeOptions`, `followUpTypeOptions`, `followUpDefaultAt`, `followUpReminderMinutes` |
| `admin.leads.create` / `.edit` | `lead`, `serviceOptions`, `sourceOptions`, `duplicateCheckEnabled`, `duplicateBlockOnExact`; create adds `assigneeOptions`, `followUpTypeOptions`, `followUpDefaultAt`, `followUpReminderMinutes`, `defaultSource`, `autoAssignMode`, `referralCode` (`?ref=`) |
| `admin.leads.show` | `lead` (assignee, assigner, converter, creator, service, client, duplicateOf, leadImport), `activities` (paginator `timeline_page`, `?type=`), `activityType`, `followUps`, `openFollowUp`, `conversions`, `duplicateReport` (?DuplicateReport), `referral` {available, code, recorded_at, collaborator_name, collaborator_code}, `allActivityTypeOptions`, `whatsappTemplate`, `staleDays`, `canConvert` + the dialog variables |
| `admin.leads.print` | `lead`, `activities` (25), `followUps` (25), `companyName`, `printedBy` |
| `admin.leads.board` | `board` (BoardData), `columns` list {status, label, color, count, value_sum, value_sum_formatted, with_budget, cards, has_more, next_page, column_url}, `boardIsEmpty`, `filters`, `filterQuery`, `transitions`, `statusLabels`, `followUpRequiredStatuses`, `lostReasons`, `followUpTypeOptions`, `followUpDefaultAt`, `staleDays`, `pageSize`, `sourceOptions`, `assigneeOptions`, `canMove`, `canCreate`. JSON: column `{status, html, has_more, next_page, column}`; move 200 `{lead_id, from_status, to_status, columns, message, card_html, convert_url}`, 422 `{message, errors, current_status?, attempted_status?, allowed?, columns}`, 409 `{message, current_status, expected_status, columns}` |
| `admin.leads.follow-ups.index` | `view`, `followUps` (paginator), `calendarFollowUps` (Collection, ≤ 100), `calendarMode`, `anchorDate`, `gridStart`, `gridEnd`, `weekStartsOn`, `sort`, `direction`, `filters`, `assignee`, `status`, `assigneeOptions`, `canViewAll`, `followUpTypeOptions`, `followUpStatusOptions`, `outcomeOptions`, `followUpDefaultAt`, `followUpReminderMinutes`, `today`. The worklist service supports no free-text search and no other sort than `scheduled_at` |
| `admin.leads.convert` | `lead`, `preview` array {field_map, client_matches, attribution {referral_code, collaborator_name, recorder_available}, project_available, is_won, already_converted}, `conversionPreview` (the DTO), `liveConversion`, `requiresPromotion`, `canPromote`, `accountManagerOptions`, `projectHandOffAvailable`, `canCreateProject`, `clientTypeOptions`, `sourceOptions`, `matchTypeOptions`, `serviceOptions` |
| `admin.leads.import.index` | `imports`, `filters`, `sort`, `direction`, `statusOptions`, `maxRows`, `maxUploadMb`, `fileRetentionDays` |
| `admin.leads.import.show` | `import`, `step`, `headers`, `columnMap` (position ⇒ field), `targetFields`, `previewRows` (positional), `previousImport` (only one the actor may see), `sourceOptions`, `defaultStatusOptions`, `assigneeOptions`, `strategyOptions`, `validation`, `rowErrors` (paginator `errors_page`), `progress` (= the JSON poll) |
| `admin.clients.index` | `clients`, `filters`, `sort` (default `company_name`), `direction`, `trashed`, `statusOptions`, `accountManagerOptions`, `countryOptions`, `portalOptions`, `isEmptyModule`, `projectsAvailable` (false), `projectCounts` ([]), `showFinancial`, `whatsappTemplate`; **`outstanding` only when `showFinancial`** |
| `admin.clients.create` / `.edit` | `client`, `clientTypeOptions`, `sourceOptions`, `defaultCurrency`, `defaultPaymentTermsDays`, `defaultTaxRate`; create adds `accountManagerOptions` |
| `admin.clients.show` (view not written at hand-off) | `client` (accountManager, portalUser, originLead, creator), `contacts` (user), `documents` (10 latest, or empty), `documentCount`, `canSeeDocuments`, `conversions`, `activities`, `canViewActivity`, `canViewFinancial`, `statusOptions`, `accountManagerOptions`, `documentCategoryOptions`, `documentUpload` {extensions, max_kb, visible_default}, `projectsAvailable`, `whatsappTemplate`; **`financialSummary` only with `viewFinancial`** |
| `admin.clients.print` / `.financials` (not written) | `client`, `printedBy`, `canViewFinancial`, `financialSummary` (only when permitted) / `client`, `financialSummary`; JSON `{client_id, summary}` |
| `admin.clients.documents.index` (not written) | `client`, `documents`, `filters`, `sort`, `direction`, `categoryOptions`, `expiryWarningDays`, `upload`, `can` {upload, download, edit, share, delete}; JSON `{data, meta}` |
| client panel (not written) | every screen: `client`, `clientName`, `portalSections`; a list: `section`, `items` (the section's paginator), `filters` (+ `project` / `projectRecord` / `showAmounts` / `canDownload` where relevant), rendered with `$section->view()`; a detail: `section`, `record` with `$section->detailView()`; `client.profile.edit`: `contact`, `isContact`, `readOnly` {client_code, status, tax_number, sales_tax_number, payment_terms_days, currency, account_manager}; `client.dashboard`: C.5 |

Flash key: `toast` (`['type' => success|warning|error|info, 'message' => …]`) on every write; a refused rule also
redirects back with the error bag and the input.

### C.11 Test notes for the verify agent

- Hostile input: every list request answers 422 to `?status[0][]=x`, `?search[]=x`, `?page=abc`; every write request
  to a string where an array is expected and vice versa.
- Test 36 (bulk cap) is enforced by `ValidatesLeadIds` **and** by `LeadService` (`CrmRuleException::tooManyIds`).
- Test 58: without `clients.view_financial` the index view data has no `outstanding` key and the show data no
  `financialSummary`; `admin.clients.financials` is `can:viewFinancial,client`.
- Test 63 / 78: `EnsureClientContext` re-resolves on every request and honours `crm.client_portal_enabled`.
- Test 81: `UpdateClientProfileRequest` lists only the whitelist; everything else never reaches `validated()`.

## Services, events, listeners, jobs, commands, DTOs and support classes (phase-05 role: services)

Verified before hand-off: `php -l` and `./vendor/bin/pint --test` clean on every file below (122 files). A read-only
smoke script booted the app, autoloaded every class, resolved every service from the container and exercised the pure
helpers (normaliser, CSV escape, DTO round trips, notifications, the inquiry field map). A **disposable** scratch schema
(`p5_services_scratch_<random>`, created and dropped by the script — never `my_office`, never `my_office_test`; cache,
session, queue and mail forced to in-memory drivers and files written to the session scratchpad) ran every committed
migration plus the staged phase-04 and phase-05 files and then **93 behavioural assertions** against these services:
numbering (`LD-000001`, `CL-000001`), normalisation, the duplicate detector (exact, cross-field, restricted keys, block on
exact), every §2.11 guard (follow-up rule, 422 illegal pair, 409 stale CAS, lost / reopen reasons, live-conversion
guard), follow-ups (one pending row via `uq_lfu_open`, reschedule, complete-with-next, cancel, `follow_up_at` cache,
reminders sent once, `crm:follow-ups-mark-missed`), the board (exactly two `leads` queries, exact `SUM` string, scoped
counts, move summaries, column page), bulk outcomes (done / forbidden / missing / skipped, cap), the timeline, conversion
(promote in one transaction, double submit `created: false`, snapshot, supersede, reopen), clients (codes never reused,
tax audit, billing nulling, one primary contact, portal enable / idempotent / user bound twice, `ClientContext`
refusals after suspend and disable, financial `unavailable` / `withheld`), documents (private hashed path, `.php`,
`invoice.pdf.php`, PDF-renamed-`.docx` refused, share stamps + notification, audited download, portal dashboard count,
profile whitelist), the CSV import (stage, PHP-in-`.csv` refused, dry run creates no lead, 50 created + 1 invalid,
counters sum, re-run chunk is a no-op, escaped error CSV), the inquiry target (routed, 1062 returns the same lead), the
export (formula escape, queued above the cap) and `reserve()` with a period reset. No test suite was run; the only git
commands were two read-only `git status --short` calls.

### V.1 Files delivered (all new; nothing existing edited)

```
app/Contracts/Portal/ClientPortalSection.php            the §D-P5-1 section contract (key, label, icon, module, permission, sort, badgeCount, paginate, view)
app/Contracts/Portal/ClientPortalRecordSection.php      + find(Client, User, int): ?Model and detailView() — formalises C.7 #6
app/Contracts/Portal/ClientPortalDownloadSection.php    + download(Client, User, int, string $variant): Response — formalises C.7 #6
app/Contracts/Referrals/ReferralRecorder.php            isAvailable(), attach(Model, code, source, ?date, ?visitId, ?notes): ?RecordedReferral, activeReferralFor(Model)
app/Contracts/Projects/ProjectCreator.php               isAvailable(), createFromLead(LeadConversion, ProjectDraftData): int
app/Contracts/Crm/ClientFinancialsProvider.php          tag `crm.client_financials` — Phase 13 / the spine answer financialSummary()
app/Contracts/Crm/ClientReferenceGuard.php              tag `crm.client_references` — Phases 6 / 10 / 13 answer "does anything reference this client"
app/DataObjects/Crm/                                    ActivityData BoardColumn BoardData BoardFilters BulkResult ClientContactData ClientData
                                                        ClientFilters ClientFinancialSummary ClientProfileData ColumnPage ContactCandidate
                                                        ConversionPreview ConversionResult ConvertLeadData DashboardData DocumentData
                                                        DuplicateMatch DuplicateReport FollowUpData FollowUpFilters ImportOptions ImportPreview
                                                        LeadData LeadFilters MoveResult OutcomeData PortalInviteData ProjectDraftData
                                                        RecordedReferral StatusChangeData Concerns/ReadsInput
app/Services/Finance/DocumentNumberService.php          D27 — next(), reserve(), plus assign() (the single 1062 retry) / format() / padFor()
app/Services/Crm/                                       LeadService LeadDuplicateDetector LeadAutoAssigner LeadTimeline LeadFollowUpService
                                                        LeadBoardService LeadConversionService LeadImportService LeadExporter ClientService
                                                        ClientContactService ClientPortalAccess ClientDocumentService ClientPortalService
                                                        ClientExporter CrmExportFiles CapturedReferralService Concerns/InteractsWithCrm
app/Services/Crm/Exceptions/                            CrmRuleException (422) DuplicateLeadException IllegalLeadTransitionException
                                                        StaleLeadStatusException (409, renders itself) FollowUpAlreadyOpenException
app/Support/ContactNormalizer.php                       §6.2 phone() / email() + whatsappLink(raw, country, template)
app/Support/CsvWriter.php                               E12 — streaming, formula-injection escape, rowsFrom() over lazyById
app/Support/ClientContext.php                           #[Scoped] — client(), clientId(), contact(), isContact(), inspect(), message(), forget()
app/Support/ClientPortalRegistry.php                    #[Singleton] — register(), all(), section(), has(), visibleTo(), visibleSection(), allows()
app/Support/Exceptions/NoClientContextException.php
app/Support/Inquiry/CrmLeadInquiryTarget.php            implements App\Contracts\Inquiry\InquiryTarget (key crm_lead)
app/Support/Portal/Sections/DocumentsSection.php        Phase 5's own portal sections
app/Support/Portal/Sections/NotificationsSection.php
app/Support/Referrals/NullReferralRecorder.php          E13 Null bindings
app/Support/Projects/NullProjectCreator.php
app/Events/Crm/                                         the 20 events of §10.1, each ShouldDispatchAfterCommit
app/Listeners/Crm/                                      NotifyLeadAssignee RecordCapturedReferral TouchLeadActivityCaches (§10.2) and
                                                        NotifyStaffOfWebsiteLead NotifyAssigneeOfMissedFollowUp NotifyOfLeadConversion
                                                        NotifyImporterOfCompletion NotifyClientOfSharedDocument (the §10.3 deliveries)
app/Notifications/Crm/                                  LeadAssignedToYou LeadFollowUpDueReminder LeadFollowUpOverdue StaleLeadsDigest
                                                        NewLeadFromWebsite LeadConverted LeadImportCompleted ClientPortalInvitation
                                                        ClientDocumentShared CrmExportReady Concerns/BuildsCrmNotification
app/Jobs/Crm/                                           ProcessLeadImportChunk (unique, afterCommit, 3 tries, 10/30/60) BuildLeadImportErrorReport
                                                        BuildCrmExport SendClientPortalInvitation
app/Console/Commands/Crm/                               SendFollowUpReminders MarkMissedFollowUps RecordCapturedReferrals ImportPendingInquiries
                                                        PruneLeadImports SendStaleLeadDigest — auto-discovered (app/Console/Commands is searched recursively)
```

### V.2 `app/Providers/AppServiceProvider.php` — capability bindings, the inquiry target, the portal sections

Imports — add (alphabetical into the existing list):

```php
use App\Contracts\Projects\ProjectCreator;
use App\Contracts\Referrals\ReferralRecorder;
use App\Enums\InquiryType;
use App\Support\ClientPortalRegistry;
use App\Support\Inquiry\CrmLeadInquiryTarget;
use App\Support\Portal\Sections\DocumentsSection;
use App\Support\Portal\Sections\NotificationsSection;
use App\Support\Projects\NullProjectCreator;
use App\Support\Referrals\NullReferralRecorder;
use Illuminate\Contracts\Foundation\Application;
```

In `register()`, after `$this->app->singleton(InquiryRouter::class);`:

```php
        // phase-05 [D-P5-1] / E13 / D28: the two write-side capability contracts. bindIf, so Phase 6 (ProjectCreator) and
        // Phase 9/10 (ReferralRecorder) rebind them with a plain bind() from their own provider, in either order.
        $this->app->bindIf(ReferralRecorder::class, NullReferralRecorder::class);
        $this->app->bindIf(ProjectCreator::class, NullProjectCreator::class);
```

In `boot()`, after `$this->registerPhase04();`:

```php
        $this->registerPhase05();
```

New private method (beside `registerPhase04()`):

```php
    /**
     * phase-05: the only path from a contact inquiry to a lead (§6.10, F-2.1 — no listener on the inquiry event) and the
     * two client-panel sections Phase 5 owns ([D-P5-1]). Both registries are container singletons, so each fresh
     * application a test boots registers into its own instance; the guards make a second resolution a no-op.
     */
    private function registerPhase05(): void
    {
        $registerTarget = static function (InquiryRouter $router, Application $app): void {
            if ($router->target(InquiryType::TARGET_CRM_LEAD) === null) {
                $router->register($app->make(CrmLeadInquiryTarget::class));
            }
        };

        $registerSections = static function (ClientPortalRegistry $registry): void {
            foreach ([new DocumentsSection, new NotificationsSection] as $section) {
                if (! $registry->has($section->key())) {
                    $registry->register($section);
                }
            }
        };

        $this->app->afterResolving(InquiryRouter::class, $registerTarget);
        $this->app->afterResolving(ClientPortalRegistry::class, $registerSections);

        if ($this->app->resolved(InquiryRouter::class)) {
            $registerTarget($this->app->make(InquiryRouter::class), $this->app);
        }

        if ($this->app->resolved(ClientPortalRegistry::class)) {
            $registerSections($this->app->make(ClientPortalRegistry::class));
        }
    }
```

`ClientContext` is `#[Scoped]` and `ClientPortalRegistry` is `#[Singleton]` by attribute — no further binding needed.

### V.3 `app/Providers/EventListenerServiceProvider.php` — the phase-05 listener map

Discovery is off, so every listener must be listed. Imports — add:

```php
use App\Events\Crm\ClientCreated;
use App\Events\Crm\ClientDocumentSharedWithClient;
use App\Events\Crm\LeadActivityLogged;
use App\Events\Crm\LeadAssigned;
use App\Events\Crm\LeadConverted;
use App\Events\Crm\LeadCreated;
use App\Events\Crm\LeadFollowUpMissed;
use App\Events\Crm\LeadImportCompleted;
use App\Listeners\Crm\NotifyAssigneeOfMissedFollowUp;
use App\Listeners\Crm\NotifyClientOfSharedDocument;
use App\Listeners\Crm\NotifyImporterOfCompletion;
use App\Listeners\Crm\NotifyLeadAssignee;
use App\Listeners\Crm\NotifyOfLeadConversion;
use App\Listeners\Crm\NotifyStaffOfWebsiteLead;
use App\Listeners\Crm\RecordCapturedReferral;
use App\Listeners\Crm\TouchLeadActivityCaches;
```

`LISTENERS` — append after the `BlogPostPublished::class => [...]` row:

```php
        // phase-05 §10.2 / §10.3. No listener on ContactInquirySubmitted: CrmLeadInquiryTarget is the only inquiry path (F-2.1).
        LeadCreated::class => [RecordCapturedReferral::class, NotifyStaffOfWebsiteLead::class],
        ClientCreated::class => [RecordCapturedReferral::class],
        LeadAssigned::class => [NotifyLeadAssignee::class],
        LeadActivityLogged::class => [TouchLeadActivityCaches::class],
        LeadFollowUpMissed::class => [NotifyAssigneeOfMissedFollowUp::class],
        LeadConverted::class => [NotifyOfLeadConversion::class],
        LeadImportCompleted::class => [NotifyImporterOfCompletion::class],
        ClientDocumentSharedWithClient::class => [NotifyClientOfSharedDocument::class],
```

The other twelve §10.1 events (`LeadUpdated`, `LeadStatusChanged`, `LeadFollowUpScheduled`, `LeadFollowUpCompleted`,
`LeadDuplicateDetected`, `LeadDuplicateLinked`, `LeadConversionSuperseded`, `ClientUpdated`, `ClientStatusChanged`,
`ClientPortalEnabled`, `ClientPortalDisabled`, `ClientDocumentUploaded`) are published for later phases and have no
listener in Phase 5.

### V.4 `routes/console.php` — the §10.5 scheduler

Append at the end of the file (times are UTC, D61; see Phase 3's note on business-hours slots):

```php
/*
|--------------------------------------------------------------------------
| CRM (phase-05 §10.5)
|--------------------------------------------------------------------------
| The six commands live in app/Console/Commands/Crm and are auto-discovered. crm:follow-up-reminders stamps
| reminder_sent_at under a row lock, so an overlapping run cannot double-send; withoutOverlapping() is the second net.
*/
Schedule::command('crm:follow-up-reminders')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('crm:follow-ups-mark-missed')->hourly()->withoutOverlapping();
Schedule::command('crm:record-captured-referrals')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('crm:import-pending-inquiries')->everyTenMinutes()->withoutOverlapping();
Schedule::command('crm:prune-imports')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('crm:stale-lead-digest')->dailyAt('09:15')->withoutOverlapping();
```

### V.5 Settings the services read (the §5 `crm` group — declared by the settings block, not here)

Every read goes through `InteractsWithCrm::crmSetting('key', <contract default>)`, so the code behaves per §5 before the
group is seeded. Once the §5 block is applied, each key below must be declared, with **both `*_next_number` keys
`readonly`** (D62 — only `DocumentNumberService` advances them, and `SettingsRepository::set()` refuses them anyway).

| Class | Keys (default coded) |
|---|---|
| `LeadService` | `number_padding` 6, `default_lead_source` website, `duplicate_block_on_exact` false, `require_follow_up_on_contacted` true, `bulk_max_ids` 200, `activity_edit_window_minutes` 1440; numbering keys `crm.lead_number_prefix` / `crm.lead_number_next_number` |
| `ClientService` | `number_padding`; numbering keys `crm.client_code_prefix` / `crm.client_code_next_number` |
| `LeadAutoAssigner` | `auto_assign_mode` off, `auto_assign_user_id`, `auto_assign_roles` ["Sales Executive"] |
| `LeadDuplicateDetector` | `duplicate_detection_enabled` true, `duplicate_match_fields` [phone, whatsapp, email], `duplicate_cross_field` true, `duplicate_check_clients` true, `duplicate_lookback_days` 0 |
| `LeadFollowUpService` | `follow_up_reminder_minutes` 60, `follow_up_reminder_channels` ["database"], `follow_up_overdue_grace_minutes` 120 |
| `LeadBoardService` | `lead_statuses_on_board` (all seven), `kanban_page_size` 25, `stale_lead_days` 7 |
| `LeadImportService` | `import_max_rows` 5000, `import_file_retention_days` 30, `import_row_retention_days` 90; `security.max_upload_mb` |
| `LeadExporter` / `ClientExporter` | `export_max_rows` 20000 |
| `ClientDocumentService` | `client_visible_documents_default` false; `security.allowed_file_types`, `security.max_upload_mb` |
| `SendStaleLeadDigest` | `stale_digest_enabled` false, `stale_lead_days` 7 |
| `CrmLeadInquiryTarget`, `ImportPendingInquiries` | `website.inquiry_default_assignee_id`, `website.inquiry_auto_route` (Phase 4's) |

`DocumentNumberService::next()` falls back to the registry field's `default` when a prefix row is not stored, so
`LD-` / `CL-` appear as soon as the §5 block declares them, seeded or not.

### V.6 Routes and names the services link to (no new route is declared here)

- `admin.leads.export.download` and `admin.clients.export.download` (`?token=`) — `BuildCrmExport` links to them; the
  controllers role declared both (C.3, C.7 #8). `CrmExportFiles::download()` refuses anyone but the requester, a
  tampered token and a link older than 7 days (404).
- `password.reset` (Phase 1) — `ClientPortalInvitation`'s password-set link.
- `admin.leads.show`, `admin.leads.index`, `admin.leads.import.show`, `admin.leads.import.errors`, `admin.clients.show`,
  `client.documents.index` — notification deep links, each behind `Route::has()` with a literal fallback.

### V.7 Additions beyond the letter of §6 and decisions taken — record in `DEVELOPMENT_LOG.md` (verify agent)

1. **`DocumentNumberService::assign($prefixKey, $counterKey, $pad, Closure $write, ?string $uniqueIndex)`** carries the
   spine's "exactly one retry on a 1062" for every caller: the counter advances in the caller's transaction, the write
   runs in a savepoint, a 1062 **on the named number index** reserves the next number once more; a 1062 on any other
   index (e.g. `uq_leads_inquiry`) propagates. This keeps D27's promise that a numbering phase "adds no retry loop".
   `next()` / `reserve()` open their own short transaction when called outside one (the number stays unique; a later
   failure leaves a gap, which nothing assumes away). The counter row is inserted with the registry default when
   missing and the settings cache is flushed after commit.
2. **Two tagged capability contracts** answer the two §6.7 questions Phase 5 cannot: `ClientFinancialsProvider`
   (`crm.client_financials`) for `financialSummary()` and `ClientReferenceGuard` (`crm.client_references`) for
   `delete()`. Phase 13 / the spine / Phase 6 tag their implementations; with none tagged the summary is `unavailable`
   and a client delete is not blocked. Record as a D28 application.
3. **`ClientPortalRecordSection` / `ClientPortalDownloadSection`** formalise the `find()` / `detailView()` / `download()`
   methods the client controllers already detect (C.7 #6). Later phases implement them; no Phase 5 section needs them.
4. **Phase 5 registers two own sections, not three**: `documents` and `notifications`. The profile screen is not a list
   section (it has no `paginate()` rows); §D-P5-1's "three" has no third list screen Phase 5 owns.
5. **Audit discipline**: status moves, assignments, lead / client updates, portal enable / disable, account manager,
   document share / hide / download, conversion and supersede save the model with its automatic activity row
   suppressed and write **one** explicit `activity_log` row (old, new, reason) through
   `App\Services\Core\Concerns\WritesAuditTrail`. Timeline rows (`lead_activities`) are the lead's own record and write
   no generic "Lead activity created" log row; editing or deleting a manual note is audited (test 33).
6. **SKIP LOCKED on MariaDB 10.4**: the server has no `SKIP LOCKED` (MariaDB added it in 10.6). `dueReminders()` uses it
   where the server version supports it and plain `FOR UPDATE` otherwise; correctness does not depend on it because a
   blocked second run re-reads the rows its lock waited on and finds `reminder_sent_at` stamped (test 29 still holds —
   the second run sends nothing). Laravel has no `skipLocked()`; the lock clause is passed as a string.
7. **Import row lifecycle**: `validateRows()` writes every line as `pending` with its `errors` and duplicate resolution;
   `run()` moves each row `pending → final` exactly once under a row lock (a re-run is a no-op, test 39). An invalid row
   ends `skipped_invalid` and counts in `skipped_count`; an exception ends `failed` in `failed_count`; either makes the
   batch `completed_with_errors`. Duplicates are resolved against leads only (a client match is warned on the lead's
   timeline, not skipped). `crm:prune-imports` removes expired `lead_import_rows` with a query-builder mass delete —
   the model forbids deleting a single log row by hand.
8. **`enablePortal()`**: the client-panel role is resolved from the database by `panel = client` and `is_default`, never
   by name (rule 8). A **staff login is refused** as a client login (the two panels would share one account). A new
   user gets a random 48-character password nobody sees, `must_change_password`, and the invitation is a
   password-broker token link. An existing user who has never signed in is also flagged `must_change_password`.
9. **`ClientService::updateLogo(Client, ?UploadedFile, bool $remove)`** (and `ClientPortalService::updateLogo()`)
   close the C.8 logo gap: re-encoded pixels only (`ImageSanitizer`), `public` disk under `clients/logos/`, previous file
   removed after commit. `update()` and `updateProfile()` never write `logo_path` from input.
10. **`LeadFollowUpService::worklist()`** now honours `FollowUpFilters::$search` (`q`: lead number, name, company, notes)
    and `$sort` / `$direction` (`scheduled_at` | `type` | `status` | `lead`) — closes the C.8 worklist gap.
11. **Document visibility default**: an explicit toggle wins; otherwise `crm.client_visible_documents_default` **or** the
    category's `defaultVisibleToClient()`. A document visible at upload is stamped shared and fires
    `ClientDocumentSharedWithClient` like a later toggle.
12. **Conversion `from_status`** records the status the lead held **before** "mark as won and convert" (otherwise the
    column would always read `won`); `lead_snapshot` is taken after the promotion.
13. **Notifications before Phase 22**: `BuildsCrmNotification` reuses phase-04's `BuildsCmsNotification`; a notification
    that wants the `database` channel while the `notifications` table does not exist is **mailed instead**, so a
    reminder is never silently dropped. Phase 22 retrofits these into `NotificationRegistry` (F16).
14. **Website leads are never refused as duplicates**: `CrmLeadInquiryTarget` passes `confirmDuplicate`, so
    `crm.duplicate_block_on_exact` cannot make the router fail an inquiry (a lost lead is worse than a duplicate one).
    A budget label that is not a plain amount ("50,000 – 150,000") is kept at the top of `notes` instead of
    `budget_amount`.
15. **Exporters**: `stream()` returns `?StreamedResponse` — null after queueing `BuildCrmExport` above
    `crm.export_max_rows`; unknown column keys are ignored rather than thrown. The leads export applies
    `LeadVisibilityScope::forUser()` for the requester explicitly, so a queued export is scoped with nobody signed in.
16. **Imports act for the importer**: a row processed by a queue worker creates its lead with `created_by` = the
    importer (`LeadData::$createdBy`), so §9's "created by me" rule shows a `leads.view`-only importer their imports.
17. **DTO namespace** `App\DataObjects\Crm\` (build-order §6's `App\DataObjects\<Domain>\` pattern); every DTO has
    `fromArray()` over the validated payload, which the Form Requests already use.

### V.8 Test notes for the verify agent

- Tests 4 / 6 (numbering): `lead_no` / `client_code` need the §5 prefix keys declared (the registry default is used when
  the row is not seeded); counters are written only by `DocumentNumberService`.
- Test 28 / 29: run `crm:follow-up-reminders` twice; the second run reports 0. On this MariaDB (10.4.32) the lock is
  plain `FOR UPDATE` (V.7 #6).
- Test 37 / 39 / 40: `QUEUE_CONNECTION=sync` runs the chunk chain inline at `run()`; `processChunk()` a second time
  returns 0 and changes no counter; the error CSV is at `lead_imports.error_report_path` on the `local` disk.
- Test 45: the second `convert()` returns `ConversionResult::$created === false` without writing a client.
- Test 50-52: with the Null recorder, `crm:record-captured-referrals` prints "No referral recorder is bound yet" and
  stamps nothing; bind a fake `ReferralRecorder` to prove 51 / 53 (the spine's `collaborator_referrals` table does not
  exist in this release).
- Test 54: nothing in `app/Services/Crm` references a commission or ledger table; the spine tables do not exist yet.
- Test 62: `ClientPortalInvitation::via()` is `['mail']` and the mail carries a `password.reset` link only.
- Test 68: `ClientDocumentShared` goes to the client's primary login and every contact with `portal_access` and
  `receives_notifications` — the Client role needs `client_portal.documents` for the section, per the §4.3 block.
- Test 80: `ClientPortalRegistry::forget('documents')` removes a section for the unregistered-section path.
- Test 83 / 84: `CsvWriter::escape()` prefixes `=`, `+`, `-`, `@`, TAB, CR; with `crm.export_max_rows = 1` the export
  returns null and `BuildCrmExport` notifies `CrmExportReady`.


---

## Blade views (phase-05 role: views)

Every §8 screen (8.1-8.10), the eight §8.11 widget bodies, the two print sheets and the portal "unavailable" page.
Nothing outside `resources/views/` was written and no existing (tracked) view was edited: `layouts.*`, `x-ui.*`,
`client/dashboard.blade.php` (Phase 1) are only consumed. Views read no setting (`setting()` / `settings_repo()` /
`site_setting()` never appear); every date and number goes through `app_date()` / `app_time()` / `app_datetime()` /
`app_number()` / `money()` / `Format::*`; money arithmetic in the browser is BigInt paisa, never a float.

### W.1 Files delivered (52 Blade files, all new)

```
resources/views/admin/crm/partials/     enum-badge contact-links follow-up-chip multi-filter scripts
resources/views/admin/leads/            index board show create edit convert print                       §8.1-8.4
resources/views/admin/leads/partials/   dialogs board-card form overview timeline follow-ups conversion
resources/views/admin/leads/follow-ups/ index                                                             §8.5 (list + month/week grid)
resources/views/admin/leads/import/     index show partials/steps                                         §8.6 (4-step wizard)
resources/views/admin/clients/          index show create edit financials print                           §8.7-8.8
resources/views/admin/clients/partials/ header dialogs form financials portal-chip
resources/views/admin/clients/documents/ index partials/{upload,table,edit-dialog}                        §8.9
resources/views/client/                 dashboard/index profile/edit documents/index notifications/index  §8.10
                                        partials/header unavailable
resources/views/admin/dashboard/widgets/ leads-by-status pipeline-value new-leads-trend leads-by-source
                                        lead-conversion-rate overdue-follow-ups unassigned-leads clients-overview   §8.11
```

Page-local JavaScript lives in `admin/crm/partials/scripts` (`crmBoard`, `crmDuplicateCheck`, `crmImportProgress`),
registered on `alpine:init` from an inline script exactly like `admin/cms/partials/scripts`; no JS dependency and no
edit to `resources/js`. The later-phase client screens (projects, tasks, milestones, progress, files, invoices,
payments, meetings, tickets, messages) are **not** Phase 5 views: `ServesClientPortal` renders each section's own
`view()` / `detailView()`, which the owning phase ships.

### W.2 Verification (read-only: nothing migrated, seeded, registered or tested)

- `php artisan view:cache` then `view:clear`, both with `VIEW_COMPILED_PATH` in the scratchpad (the shared
  `storage/framework/views` was never touched); every compiled phase-05 template passes `php -l` (70 checked, 0 failed).
- The suite's static scans applied to the 52 files — `NoHardcodedFormatsTest` patterns, no `setting(` /
  `settings_repo(` / `site_setting(` / `config(` / `env(`, no `{!! !!}`, no `x-html`, no `(float)`, every
  `target="_blank"` with `rel`, every `bg-white` / `bg-slate-50..200` / `text-slate-700..950` with its `dark:`
  counterpart: **0 hits**. No `@js()` / `@class()` inside an `<x-…>` tag (see W.5 #8).
- Render harness over the **real** models, enums and DTOs on disk (`Lead`, `Client`, `DuplicateReport`,
  `ClientFinancialSummary`, `DashboardData`, `DatabaseNotification`, `Activity` …), the C.3 / C.4 route table registered
  in memory, stub layouts, and the exact variable names of the controllers on disk (C.10): **106 renders, 0 failures**
  (53 cases × allow-all gate and deny-all gate) — lists with rows / empty / trashed, board with and without columns, the
  card partial alone (as `renderCard()` uses it), lead detail full / empty / trashed, convert (not won + promote, won,
  blocked, already converted), follow-ups list / month / week, import at mapping / validated / processing / finished,
  client detail full / minimal, documents with rows / empty, financials unavailable, both prints, every portal screen,
  every widget available / empty / unavailable. No printed Blade directive, every `<table>` inside an `overflow-x-auto`.
- The rendered pages were served locally with the built `app.js` and a scratch Tailwind build and driven in a browser:
  no Alpine expression error on any page; board — drop onto *lost* asks for the reason before moving, a 422 springs
  the card back with a toast and an `aria-live` announcement, a 200 moves the card optimistically (saving state, header
  sums adjusted in BigInt: `1000.00 + 999999999999.99 = 1,000,000,000,999.99`), then takes the server's figures and
  `card_html` and shows the "Convert to client" banner; lead list bulk status names "allowed for 2 of 3" before submit;
  the create form's duplicate panel gates the submit and "Link as duplicate" fills `duplicate_of_lead_id` +
  `duplicate_note`; the portal invitation posts `client_contact_id` + the contact's name/email; the upload switch
  follows `ClientDocumentCategory::defaultVisibleToClient()`. `node` checks of the board / duplicate-check logic:
  17 of 17 (money sample parsing incl. `1.234.567,89 €` and a 0-decimal currency, paisa adjustment, transition gating).

### W.3 Route names the views use

All are in C.3 / C.4 or Phases 1-4; the ones marked *guarded* are only called behind `Route::has()`.

```
admin.leads.{index,board,board.column,board.move,follow-ups.index,create,store,duplicate-check,show,edit,update,
             destroy,restore,print,status,assign,bulk.assign,bulk.status,bulk.destroy,duplicate-link,export,
             convert.form,convert.store,conversions.supersede}
admin.leads.activities.{store,update,destroy}   admin.leads.follow-ups.{store,complete,reschedule,cancel}
admin.leads.import.{index,template,store,show,mapping,validate,run,cancel,errors}
admin.clients.{index,create,store,show,edit,update,destroy,restore,print,export,status,account-manager,financials,
               portal.enable,portal.disable}
admin.clients.contacts.{store,update,primary,destroy}
admin.clients.documents.{index,store,update,visibility,destroy}   admin.client-documents.download
client.{dashboard,profile.edit,profile.update,documents.index,documents.download,notifications.index,
        notifications.read,notifications.read-all}
logout (Phase 1)
guarded:  admin.contact-inquiries.show (Phase 4)   admin.projects.show (Phase 6)
```

### W.4 View-data contract (reconciled with the controllers on disk; C.10 holds, with these precisions)

Every view reads its variables defensively and documents them in its header comment; a missing optional key degrades,
never 500s. Where the controller and the first draft of a view used different names, the view now accepts the
controller's name (and the other).

| View | Reads (beyond C.10) |
|---|---|
| `admin.leads.index` | `isEmptyModule` picks "No leads yet" vs "No leads match". Filter names posted: `status[]`, `source[]`, `assignee`, `follow_up` (`next_7_days`), `converted` (`yes`/`no`), `created_from`, `created_to`, `budget_min`, `budget_max`, `has_duplicate`, `trashed` — all aliased by `CrmListRequest`. Bulk export links `admin.leads.export?ids[]=…` |
| `admin.leads.board` | `columns[*]` as C.10 (`column_url` is not needed: the view builds each column URL from `route()` + the current query). JSON used: column `{html, has_more, next_page, column}`; move `{columns, message, card_html, convert_url}` on 200, `{message, errors?, allowed?, columns?}` on 422, `{message, current_status, columns?}` on 409. A 422 whose `errors` name `follow_up*` / `lost_reason` / `reason` re-opens the details modal for a retry |
| `admin.leads.partials.board-card` | `lead`, `transitions`, `statusLabels`, `staleDays`, `canMove` (exactly `renderCard()`'s) |
| `admin.leads.show` | the timeline type filter is a GET `?tab=timeline&type=…` (server-side, all pages); `?tab=` opens overview / timeline / follow-ups / conversion; `?log=1` opens Log activity. `duplicateReport` is read through `DuplicateMatch::toArray()` |
| `admin.leads.create` / `.edit` | posts `referral_code` (canonical); the duplicate panel shows `last_activity_human` when present (W.5 #3) |
| `admin.leads.convert` | posts the canonical keys: `existing_client_id` + `matched_by`, `client[…]`, `promote_to_won` + optional `promotion_reason`, `create_project` + `project[project_name]` + `project[project_description]`, `notes` |
| `admin.leads.import.index` | adds a status filter bar (`statusOptions`) |
| `admin.clients.show` | `activities` + `canViewActivity` (the Activity tab exists only with `clients.view_logs`), `canViewFinancial` + `financialSummary`, `documents` + `documentCount` + `canSeeDocuments` + `documentCategoryOptions` + `documentUpload` render an inline **Documents tab** (upload card + latest ten + "All documents and filters"), so the document writes' redirect to `?tab=documents` lands on it; `conversions` listed under Origin; `contactOptions` is derived from `contacts` (contacts with an email) when absent |
| `admin.clients.documents.index` | `upload` {extensions, max_kb, visible_default}, `can` {upload, download, edit, share, delete}, `expiryWarningDays`; expiring filter values `soon` / `past`; compact header (Edit / Print / Export only, no dialogs) |
| `admin.clients.financials` | compact header; `financialSummary` via `isAvailable()` / `isWithheld()` — a withheld summary renders nothing, an unavailable figure renders "-" |
| `admin.clients.print` | contacts from the loaded `client.contacts` relation; financial figures only with `canViewFinancial` + `financialSummary` |
| `admin.clients.partials.dialogs` | posts `user_id` (account manager), `status` + `reason`, `client_contact_id` / `name` / `email` / `send_invitation=1` (portal), contact fields without `portal_access` (granted by the invitation) |
| `client.dashboard.index` | `client`, `dashboard` (`DashboardData`; each card `{key, label, icon, count, route}` — a null count shows "—"), optional `greeting`, `recentDocuments`, `accountManager` (else `client.accountManager` when loaded) |
| `client.profile.edit` | `client` (accountManager), `contact`, `isContact`; posts `name, phone, whatsapp, website, address, city, postal_code, about` and, for a contact, `contact_name, contact_designation, contact_phone`. No logo control (the request accepts none) |
| `client.documents.index` | `items` (or `documents`), `canDownload`, `filters`; posts `search`, `category` (W.5 #4) |
| `client.notifications.index` | `items` (or `notifications`), optional `unreadCount`; `?unread=1`; a `data.url` becomes a link only when same-site |
| `client.unavailable` | `reason` (`portal_off` / `no_context` / `portal_disabled` / `status`), optional `message` (W.5 #2) |
| `admin.dashboard.widgets.*` | `$widget` (the descriptor: `key`, `emptyMessage`) and `$data` — see W.5 #10 |

### W.5 Notes and requests for the integrator / other roles

1. **Client dashboard view name (C.5).** Views may not edit the Phase 1 `client/dashboard.blade.php`, which cannot
   render the §8.10 cards. In C.5 change `view('client.dashboard', …)` to `view('client.dashboard.index', …)`; the Phase 1
   variables it also passes are harmless extras. Optionally add `recentDocuments` (the latest five
   `DocumentsSection` rows) and load `client.accountManager:id,name,email`.
2. **Explanatory 403 page (§6.9).** `EnsureClientContext` aborts with a bare 403. For the "explanatory page" the contract
   asks for, answer instead with `response()->view('client.unavailable', ['reason' => $reason, 'message' => $message],
   403)`, `$reason` one of `portal_off` (master switch), `no_context`, `portal_disabled`, `status`.
3. **Duplicate check (controllers role, one line).** Add `'last_activity_human' => $match['last_activity_at'] ?
   Format::forHumans($match['last_activity_at']) : null` in `LeadController::duplicateCheck()`; the panel shows it when
   present and no JS date formatting is done in the browser.
4. **Portal documents search (services role, one word).** `DocumentsSection::paginate()` reads `$filters['q']`, but
   `ClientPortalListRequest` validates and passes `search` — the portal search box filters nothing until the section reads
   `search` (or the request aliases it).
5. **Client panel nav.** `Sidebar::clientTree()` (Phase 1) still names `client.support-tickets.index` and the old
   `client_portal.project_tasks` / `files_download` / `support_tickets` permissions and has no Documents / Notifications /
   Profile entries. §8.10 builds the nav from `ClientPortalRegistry::visibleTo()` (every panel controller already passes
   `portalSections`); that is a `Sidebar` edit for the integrator.
6. **Searches the controllers ignore.** `x-ui.filter-bar` always renders a search box: `LeadImportController@index`
   (file name) and the follow-up worklist (C.8) do not apply `search` yet.
7. **Staff logo upload.** The client form shows the current logo read-only and offers no upload (a control that silently
   does nothing would be a bug) until `ClientService` gains an upload method (C.7 #5 / C.8).
8. **Phase 4 finding — `@js()` inside `<x-…>` component attributes is emitted literally.** Blade compiles directives
   outside component tags only (verified by compiling a probe): 12 tags in `admin/contact-inquiries/{index,show}`,
   `admin/job-applications/{index,show}` and `components/cms/moderation-actions` render `url: @js(route(…))` into the
   `x-on:click`, a JavaScript syntax error, so those assign / status / spam / moderation buttons do nothing. The fix is
   `{{ \Illuminate\Support\Js::from(…) }}` (what every phase-05 view uses). A view scan for `@js(` inside `<x-` would keep
   it from coming back.
9. **Rebuild the CSS after integration** (`npm run build`): the views use utilities no existing view used
   (`has-[:checked]:*`, `border-t-{color}-500` column accents, `max-w-[10rem]` …).
10. **§8.11 widget classes are not on disk yet.** Their bodies are delivered; each class (span / permission / module as
    §8.11) returns `data()` in these shapes (`available` false renders an "unavailable" empty state):
    - `leads_by_status` → `admin.dashboard.widgets.leads-by-status`: `{available, total, statuses: [{value, label, color, count, share?, href?}], range_label}`
    - `pipeline_value` → `…pipeline-value`: `{available, value_sum_formatted, count, with_budget, statuses: [{label, color, count, value_sum_formatted}], href?}`
    - `new_leads_trend` → `…new-leads-trend`: `{available, labels: [app_date strings], series: [{label, data: [int], color}], total, window_label}`
    - `leads_by_source` → `…leads-by-source`: `{available, total, sources: [{value, label, color, count, conversion_rate: ?string (Format::percentage), href?}], range_label}`
    - `lead_conversion_rate` → `…lead-conversion-rate`: `{available, won, lost, win_rate: ?string, converted, total, conversion_rate: ?string, range_label}`
    - `overdue_follow_ups` → `…overdue-follow-ups`: `{available, total, items: [{lead_name, lead_no, scheduled_at, type_label, url}], href?}`
    - `unassigned_leads` → `…unassigned-leads`: `{available, total, oldest: [{name, lead_no, created_at, source_label, url}], href?}`
    - `clients_overview` → `…clients-overview`: `{available, total, active, portal_enabled, new_in_range, range_label, href?}`
11. **Deliberate UI choice (§8.2 "Move to" dropdown).** Each card's "Move to" is a native `<select>` posting the identical
    endpoint: a popover menu is clipped by the board's horizontal scroll container, and a native select is the better
    touch and keyboard control (R-3). The lead list keeps `x-ui.dropdown` row actions, as `admin/users/index` does.
12. **Delete / supersede / cancel reasons.** Each `x-ui.confirm` that needs a reason gets the form `id` through its
    attributes and a `reason` input bound with `form="…"` in the dialog body, so the reason is validated and posted with
    the confirm form (`DeleteRecordRequest`, `SupersedeConversionRequest`, `CancelLeadImportRequest`).
