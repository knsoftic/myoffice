export const meta = {
  name: 'build-all-phases',
  description: 'Build phases 3-25 end to end in build-order slices: parallel domain code, serial integration, tests, verify + commit, review + fix, log update per unit',
  phases: [
    { title: 'Domain', detail: 'per unit: schema+models, services, controllers, views, handover (new paths only)' },
    { title: 'Integrate', detail: 'per unit, serial: registries, wiring+routes+seeders, reconcile' },
    { title: 'Tests', detail: 'per unit: acceptance tests written in parallel' },
    { title: 'Verify', detail: 'per unit: migrate, seed, build, full suite twice, smoke, commit, log' },
    { title: 'Review', detail: 'per unit: adversarial security + contract review, fix loop' },
  ],
}

// ================================================================================================
// Units, in docs/design/build-order.md release-slice order
// ================================================================================================
const SPINE = 'docs/design/finance-commission-spine.md'
const UNITS = [
  { id: '03', title: 'Public website CMS', phases: [3], contracts: ['docs/phases/phase-03.md'], domainDone: true,
    note: 'Domain code already exists on disk (models, policies, controllers, requests, 85 views, most services). docs-pending/phase-03-integration.md is the precise integration plan (steps A-M): follow it. Its step A gate needs the five missing services (PageService, MenuService, CtaBlockService, FaqService, StatisticsProvider) and two middleware (CachePublicResponse, ResolvePreviewMode) written first, exactly per its sections A.1 and A.2.' },
  { id: '04', title: 'Services, portfolio, team, testimonials, blog, careers, contact inquiries', phases: [4], contracts: ['docs/phases/phase-04.md'],
    note: 'Ships early: App\\Enums\\EmploymentType file with the seven canonical cases (E9, owned semantically by Phase 7), contact_inquiries + ContactInquirySubmitted + InquiryRouter + App\\Contracts\\Inquiry\\InquiryTarget (E19), services + service_categories + technologies (E20), SlugGenerator, SpamGuard. Public routes must be ordered before Phase 3 CMS catch-all.' },
  { id: '05', title: 'CRM: leads (Kanban), clients, client panel', phases: [5], contracts: ['docs/phases/phase-05.md'],
    note: 'Ships early: App\\Services\\Finance\\DocumentNumberService with next() and reserve() (E10, D27 - the single numbering implementation for the whole system), routes/client.php + every client.* route name + ClientPortalRegistry + EnsureClientContext/client.context (E11, D31), App\\Support\\CsvWriter (E12), the ReferralRecorder and ProjectCreator contracts with Null bindings (E13).' },
  { id: '06-07', title: 'Projects, tasks, time tracking; HR, attendance, leave, payroll', phases: [6, 7], contracts: ['docs/phases/phase-06.md', 'docs/phases/phase-07.md'],
    note: 'Phases 6 and 7 share no table, enum or file (D32): their domain code is built in parallel. Phase 6 ships attachments (the one polymorphic file table, E21), CommissionCalculationType (E22), Priority, AttachmentVisibility, CommentVisibility (E23). Phase 7 ships PaymentMethod and LedgerEntryType with cases VERBATIM from the financial spine section 3 (E24) and reuses EmploymentType unchanged; D36 payroll runs are immutable from lock().' },
  { id: '08', title: 'Collaborators + the financial spine schema', phases: [8], contracts: ['docs/phases/phase-08-09.md', SPINE],
    note: 'Release slice S7 - MONEY. Order is binding and atomic: (a) Phase 8 tables + activity_log.collaborator_id; (b) IMMEDIATELY after, the spine migration set (all 15 spine tables across its 21 files), its 27 enums, and students + student_admissions verbatim from phase-14-17 sections 2.14/2.15 (E5-E8) - one unit, never partially merged; (c) add_collaborator_fks_to_project_tables; (d) the collaborators:backfill-wallets and collaborators:seed-initial-rules commands. Payout account details encrypted at rest (D21/§55). Spine invariants INV-1..INV-26, D16-D18, D37 bind.' },
  { id: '09', title: 'Referral codes, referral URLs, referral tracking', phases: [9], contracts: ['docs/phases/phase-08-09.md', SPINE],
    note: 'collaborator_referral_visits, ReferralLinkService, ReferralTrackingService, ReferralAttributionResolver (six-rank precedence ladder, D38), CaptureReferral, the referral field component, and the guarded referral_visit_id FK promotions (phase-08-09 section 2.5a). The retention prune must guard every referencing column (INV-R6).' },
  { id: '10-11', title: 'Student and project commission engines', phases: [10, 11], contracts: ['docs/phases/phase-10-12.md', SPINE],
    note: 'MONEY. Code and screens only - the schema landed in unit 08. D39: exactly one calculation site per commission side, reached only through an afterCommit event -> unique job chain; payments and ledger rows inserted only through PaymentService / LedgerWriter. Build the section 6.3 shared services first, then 10 and 11 in parallel. The nine requirement-120 acceptance tests (docs/requirements.md section J, spine FT list) are MANDATORY here and must pass: no collaborator -> no row; 10,000 at 10% -> 1,000; same payment twice -> one row; installments -> one per payment; refund -> negative reversal; project without collaborator -> none; 100,000 at 15% -> 15,000; project refund -> reversal. Plus concurrency (two workers, one row), partial refund, rate change mid-admission.' },
  { id: '12', title: 'Collaborator wallet, ledger screens, payouts, statements', phases: [12], contracts: ['docs/phases/phase-10-12.md', SPINE],
    note: 'MONEY. CollaboratorWalletService (payoutsPaidTotal with nullable collaborator = company-wide), PayoutService (D18 named allocations with partial slices via compare-and-swap), CollaboratorStatementService, CommissionReconciliationService with the scheduled wallet == SUM(ledger) proof. Requirement-120 test 9 (wallet 50,000, payout 20,000 -> available 30,000, history preserved) must pass, plus payout-then-reversal (clawback drives the wallet negative) and a randomised wallet == ledger property test.' },
  { id: '13', title: 'Software house finance: invoices, payments, expenses, income, P&L', phases: [13], contracts: ['docs/phases/phase-13.md', SPINE],
    note: 'MONEY. Ships ReportResult + ReportExporter + resources/views/layouts/print.blade.php (E25) used by 18, 21, 23. D40 invoice paid/refunded/balance are a cache of one canonical SQL; D41 finance_reversals append-only; D42 invoice number assigned once at issue via DocumentNumberService; D43 project_payments.invoice_id NULL->value->NULL only via InvoiceService gated by project_payments.link_invoice + invoices.edit (add the Ability::LinkInvoice enum case now - BT-1); D44 one approved expense per paid payroll run. Install barryvdh/laravel-dompdf if PDF generation is contracted.' },
  { id: '14', title: 'Institute: course categories, courses, outline', phases: [14], contracts: ['docs/phases/phase-14-17.md'],
    note: 'Course FAQs use Phase 3 faqs via faqable morph (no course_faqs table). Public course pages /courses/{slug} preserve the referral parameter.' },
  { id: '15', title: 'Institute: inquiries, online admission, admission pipeline, students', phases: [15], contracts: ['docs/phases/phase-14-17.md', SPINE],
    note: 'REUSES students and student_admissions created in unit 08 - never recreate them. D45: the admission pipeline is carried by student_admissions.stage with students.status and course_inquiries.status advanced in lockstep by AdmissionService only. Ships add_institute_fks_to_fee_tables. Referral code capture from the public admission form (D38).' },
  { id: '16', title: 'Institute: teachers, batches, timetable, class sessions, demo classes', phases: [16], contracts: ['docs/phases/phase-14-17.md'],
    note: 'Ships ScheduleClashDetector::check(SlotCandidate) + DTOs (E26, D47 - enforced under parent row locks plus a nightly verifier, never a unique index). D46 attendance attaches to dated class_sessions. D48 capacity by locked recount. Teacher panel with assigned-batches-only isolation.' },
  { id: '17', title: 'Institute: student attendance and course progress', phases: [17], contracts: ['docs/phases/phase-14-17.md'],
    note: 'Attendance per class_session; daily/monthly/percentage/batch reports; course progress per module and topic. Student panel sees only own data.' },
  { id: '18', title: 'Student fees, installments, discounts, scholarships (commission trigger)', phases: [18], contracts: ['docs/phases/phase-18.md', SPINE],
    note: 'MONEY. Creates NO financial table except student_fee_reminders (the fee tables landed in unit 08). StudentFeeService + summaryFor/reassignBatch/withinServiceContext/outstandingFor + FeeSummary, InstallmentPlanCalculator (D49 plan lines minus waivers always equal net fee; D50 installment numbers never reused), FeeSlipBuilder, reminders, fee widgets. Requirement-120 tests 1-5 and 9 at the HTTP level must pass, plus installments summing exactly, discount after payment, overpayment refused, back-dated payment, receipt numbers never duplicated under concurrency.' },
  { id: '19', title: 'Course materials and assignments', phases: [19], contracts: ['docs/phases/phase-19-23.md'],
    note: 'Ships App\\Services\\Files\\SecureFileService FIRST (E27, D21 - private disk, streamed through a controller that re-runs the permission chain) - 20, 21 and 22 reuse it. D51 mark ceilings enforced three times.' },
  { id: '20', title: 'Exams, results, grade scales, result cards', phases: [20], contracts: ['docs/phases/phase-19-23.md'],
    note: 'grade_scales and grade_scale_bands, the guarded courses.grade_scale_id, ResultCardBuilder on the Phase 13 print layout, marks never exceed totals (D51).' },
  { id: '21', title: 'Certificates with public QR verification, student ID cards', phases: [21], contracts: ['docs/phases/phase-19-23.md'],
    note: 'D52 issued certificates and ID cards are snapshots (revoke + reissue, never edit); BT-2 decided: certificates and student_id_cards are APPEND-ONLY (no deleted_at, deleting guard) per CLAUDE.md section 3. D53 print templates are sanitised HTML with {{tokens}} via a token replacer, never Blade or eval. Install simplesoftwareio/simple-qrcode. The public verification page reveals only what is safe.' },
  { id: '22', title: 'Support tickets, meetings, internal messaging, notifications', phases: [22], contracts: ['docs/phases/phase-19-23.md'],
    note: 'D54 MessagingMatrix enforces the six role pairs on every send; D55 every notification declared in NotificationRegistry and delivered by NotificationService (a disabled module is a logged no-op); register every earlier phase notification (requirement section 97). Ticket SLA behind support.sla_enabled.' },
  { id: '23', title: 'Reports, analytics, activity log and audit trail viewers, global search', phases: [23], contracts: ['docs/phases/phase-19-23.md'],
    note: 'D56 a report is a declaration in ReportRegistry delegating every figure to the owning service (a SUM() in a report class is a review failure); a withheld column is absent from the query and the file. Global search across eleven entity types with per-entity permission filtering. report_exports table and ExportFormat::excel.' },
  { id: '24', title: 'Hardening: security matrix, financial integrity suite, performance, accessibility', phases: [24], contracts: ['docs/phases/phase-24-25.md'],
    note: 'Executable plan, not prose: the security test matrix (CSRF, XSS, SQLi, mass assignment, uploads, rate limits, sessions, every route authorized, five-panel isolation, horizontal escalation, IDOR on every id route), the financial integrity suite FIN-01..FIN-22 including a randomised wallet == ledger property test, D59 Model::shouldBeStrict() outside production, query budgets and indexes, D60 the four manifests and audit:manifest --check, integrity_check_runs. Fix every defect the matrix finds. Closes only after 23.' },
  { id: '25', title: 'Deployment preparation: backups, runbook, queue and scheduler, production notes', phases: [25], contracts: ['docs/phases/phase-24-25.md'],
    note: 'backup_runs and backup_restores with backup and restore services (spatie/laravel-backup), a restore wizard with scratch verification, D57 directory-swap deploys with rename rollback, D58 three separated MySQL users documented, queue worker and scheduler service definitions, go-live checklist, INSTALL.md updated. Prepare only - never deploy, never create real production users, never touch a remote host.' },
]

const MONEY_UNITS = new Set(['08', '10-11', '12', '13', '18'])

// ================================================================================================
// Schemas
// ================================================================================================
const RESULT = {
  type: 'object',
  properties: {
    summary: { type: 'string', description: 'MAX 8 sentences. Detail belongs in your files, not here.' },
    files: { type: 'array', items: { type: 'string' } },
    issues: { type: 'array', items: { type: 'string' }, description: 'One short line each' },
  },
  required: ['summary', 'files'],
}
const VERIFY = {
  type: 'object',
  properties: {
    summary: { type: 'string', description: 'MAX 8 sentences' },
    green: { type: 'boolean', description: 'true only if the full suite passed twice (sequential and random order) and the smoke checks passed' },
    tests: { type: 'string', description: 'e.g. "1480 passed / 45210 assertions"' },
    commit: { type: 'string', description: 'the commit sha you created, or empty' },
    issues: { type: 'array', items: { type: 'string' } },
  },
  required: ['summary', 'green', 'tests', 'commit'],
}
const FINDINGS = {
  type: 'object',
  properties: {
    summary: { type: 'string', description: 'MAX 6 sentences' },
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          severity: { type: 'string', description: 'critical | high | medium | low' },
          title: { type: 'string' },
          location: { type: 'string' },
          scenario: { type: 'string', description: 'MAX 3 sentences' },
          fix: { type: 'string', description: 'MAX 2 sentences' },
        },
        required: ['severity', 'title', 'location', 'scenario', 'fix'],
      },
    },
  },
  required: ['summary', 'findings'],
}

// ================================================================================================
// Shared brief
// ================================================================================================
const TRAILER = 'Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>'

const BRIEF = `PROJECT: Software House ERP + IT Training Institute Management System - one Laravel app, five panels (admin, collaborator,
student, teacher, client) and a dynamic public website. Laravel 12.69.2, PHP 8.2.12, MariaDB 10.4 (db my_office, user root, no password,
session pinned to UTC), Blade + Tailwind 3.4 + Alpine 3 + Vite 7.
Working directory: C:/xampp/htdocs/my office   (contains a space - quote it in every shell command)

THIS IS A LONG, UNATTENDED BUILD OF PHASES 3 TO 25, unit by unit in docs/design/build-order.md release-slice order. Phases 1 and 2 are
committed and green. Each unit is built, integrated, tested, verified, committed and reviewed before the next unit integrates.

ALWAYS READ FIRST: CLAUDE.md (golden rules, naming, soft-delete categories), DEVELOPMENT_LOG.md section 4 (decisions D1-D65 are binding),
docs/requirements.md (the client's 120 sections), docs/design/build-order.md (sections 1, 3 and 9), docs/design/resolutions.md section 8
("do not change"), your unit's contract files, and the existing code you extend. Contracts are binding on names and behaviour.

GLOBAL RULES
1. Own ONLY the paths your brief gives you. Other agents work in parallel.
2. Never edit .env, CLAUDE.md, docs/** or vendor/** by hand. DEVELOPMENT_LOG.md is edited only by a verify agent, and only additively.
3. The dev database my_office holds real data: NEVER migrate:fresh/reset/rollback, db:wipe, or delete a user, role, permission, module,
   setting or any financial row. Forward migrations only, and only by a verify agent.
4. Only a verify or fix agent runs "php artisan test" (one shared test database - never two runners). Only a verify or fix agent runs git,
   and then ONLY: git status, git diff, git log, git add <explicit paths>, git commit. Never git add -A, push, reset, checkout, stash,
   rebase, amend, or clean.
5. Money: decimal(15,2) columns, decimal(8,4) rates, bcmath through App\\Support\\Money only, never a float; financial rows are append-only and
   corrected by reversing rows (D8, D16, D19); commission only from received payments inside a transaction with a NOT NULL unique guard.
6. Authorization on the backend: every route has auth/active/panel/module/can: middleware with a permission that exists in
   PermissionRegistry; every non-admin query is scoped to the owner (CLAUDE.md rule 10).
7. php -l and ./vendor/bin/pint --test every PHP file you write. Write multi-line PHP or SQL scripts with a file-writing tool, never a shell
   heredoc (the shell strips backslashes from heredocs and breaks class names).
8. IDEMPOTENT: the network to the API drops sometimes and your task may be a retry. Before writing, inspect what already exists; continue
   from there; never duplicate a file, a migration, a route or a registry entry.
9. Never loosen a rule, a validation or a test assertion. Keep your structured return short.
`

const DOMAIN_RULES = `DOMAIN-STAGE RULES (you are building code for a unit that is NOT integrated yet, while another unit may be integrating and running
the test suite in this same directory):
- Write ONLY new files on your unit's own domain paths (namespaces and folders the contract names; otherwise CLAUDE.md section 2's domain
  folders: Crm, Project, Hr, Finance, Collaborator, Institute, Cms, Support). Never edit a file that already exists outside your unit.
- MIGRATIONS go to database/migrations-staged/phase-NN/ (NN = your phase number, zero-padded), NEVER to database/migrations/ - the test suite
  migrates everything in database/migrations and must not see an unintegrated table. Name them with a timestamp prefix that sorts after
  every existing migration and in dependency order. The integration stage moves them.
- NEVER edit: app/Support/PermissionRegistry.php, SettingsRegistry.php, Sidebar.php, Modules.php, helpers.php, routes/**, bootstrap/**,
  config/**, app/Providers/**, database/seeders/**, database/migrations/**, resources/views/layouts/**, resources/views/components/ui/**,
  composer.json, package.json, tests/**.
- VIEWS must pass the global view scans that already run in the suite: dates and numbers only through app_date(), app_time(), app_datetime(),
  app_number() and money() (no ->format(, date( or number_format( in Blade); and NO setting(), settings_repo() or site_setting() call in any
  view - a view receives settings as data from its controller.
- Enums follow the existing enum contract (string-backed, label(), color(), static options()) - read two existing enums first.
- Everything this unit needs in a forbidden file (permissions, modules, settings keys, routes, sidebar entries, policy registrations,
  middleware aliases, bindings, scheduled tasks, seed rows, packages) goes into docs-pending/phase-NN-integration.md as exact,
  copy-pasteable blocks, appended under a heading with your role name.
`

function unitHead(u) {
  return `\nYOUR UNIT: ${u.id} - ${u.title} (phase${u.phases.length > 1 ? 's' : ''} ${u.phases.join(' and ')}).\nCONTRACTS: ${u.contracts.join(', ')}\nUNIT NOTES: ${u.note}\n`
}
const pad = (n) => String(n).padStart(2, '0')

// ================================================================================================
// Retry wrapper - network failures return null
// ================================================================================================
async function run(prompt, opts, tries = 4) {
  for (let i = 0; i < tries; i++) {
    const retryNote = i === 0 ? '' : `\n\nRETRY NOTE: attempt ${i + 1}. A previous attempt of this exact task was interrupted by a network failure. Inspect what already exists on disk and continue from there.`
    const r = await agent(prompt + retryNote, { ...opts, label: i === 0 ? opts.label : `${opts.label} (retry ${i + 1})` })
    if (r) return r
    log(`${opts.label}: no result on attempt ${i + 1}`)
  }
  return null
}

// ================================================================================================
// Stage: domain (parallel, new paths only)
// ================================================================================================
async function buildDomainForPhase(u, phaseNo) {
  const P = pad(phaseNo)
  const head = BRIEF + DOMAIN_RULES + unitHead(u) + `\nYOU ARE BUILDING PHASE ${phaseNo}. Your integration file is docs-pending/phase-${P}-integration.md and your staged migrations go to database/migrations-staged/phase-${P}/.\n\n`
  const workers = await parallel([
    () => run(head + `YOUR ROLE: schema, enums, models, policies for phase ${phaseNo}.
Write every table the contract assigns to phase ${phaseNo} as staged migrations (exact columns, types, nullability, defaults, indexes, FKs with
their on-delete behaviour, CHECK constraints, generated columns, append-only tables without deleted_at plus a deleting guard, correct down()
dropping FKs before columns). Guard every cross-phase FK with Schema::hasTable as the contract says. Enums the contract assigns to this phase
(never redeclare an enum another phase owns - resolutions.md section 2.2). One model per table with explicit fillable, casts() with enums,
relationships, scopes the contract names, SoftDeletes + Blameable per CLAUDE.md section 3, moduleSlug(). Policies deferring to module.ability
permissions first, then the row rules and data-isolation scoping the contract states.`, { label: `P${P}:schema-models`, phase: 'Domain', schema: RESULT }),

    () => run(head + `YOUR ROLE: services, events, listeners, jobs, commands, DTOs and support classes for phase ${phaseNo}.
Implement every service the contract assigns to this phase with the exact public method signatures and the invariants it states, each write in
one transaction, audit entries through the existing activity log concerns, events dispatched after commit, jobs unique where the contract says.
Read the models the schema agent is writing (they may still be arriving - code against the contract's names). Reuse shared foundations that
earlier phases shipped (Money, DocumentNumberService, SecureFileService, ReportExporter, ScheduleClashDetector, CsvWriter, RichText,
MediaService, NotificationService - whichever exist) instead of writing a second one.`, { label: `P${P}:services`, phase: 'Domain', schema: RESULT }),

    () => run(head + `YOUR ROLE: controllers, Form Requests and middleware for phase ${phaseNo}.
Thin controllers for every screen and action the contract lists, across every panel it names (admin, client, student, teacher, collaborator,
public). Explicit authorization in each action matching the route's can: permission, owner scoping for every non-admin panel, paginated
indexes with search and filters using per_page(), writes delegated to the real services (list any missing method rather than inventing it).
Form Requests validating every writable field server side; hostile arrays give 422, never 500. Settings a view needs are passed as view data.
Append EVERY route you expect (method, URI, name, controller@method, middleware including panel/module/can:) to the integration file.`, { label: `P${P}:controllers`, phase: 'Domain', schema: RESULT }),

    () => run(head + `YOUR ROLE: Blade views for phase ${phaseNo}.
Every screen the contract lists, in every panel it names, using @extends('layouts.admin') or @extends('layouts.panel') (public pages use the
Phase 3 public layout) and the existing x-ui.* components (read, never edit). Lists: search, filters, sortable headers, pagination, empty state,
skeleton. Destructive actions behind x-ui.confirm. Kanban, calendar, wizard or print behaviour exactly where the contract asks. Correct in light
and dark, responsive, accessible. Obey the view scans in the domain rules. Compile with php artisan view:cache then view:clear. Append to the
integration file every route() name you use and every variable each view expects from its controller.`, { label: `P${P}:views`, phase: 'Domain', schema: RESULT }),
  ])

  const handover = await run(head + `YOUR ROLE: turn phase ${phaseNo}'s domain work into ONE ordered, copy-pasteable integration list at docs-pending/phase-${P}-integration.md
(rewrite the file; keep the domain agents' appended blocks as an appendix).
THE FOUR DOMAIN AGENTS REPORTED: ` + JSON.stringify(workers.filter(Boolean)) + `
Read the code on disk and trust it over the reports. Produce, in an order where applying top to bottom never references something missing:
(1) the staged migration files and the order to move them into database/migrations; (2) PermissionRegistry module definitions with abilities and
depends_on in that file's array shape; (3) SettingsRegistry keys in its field shape; (4) policy registrations, middleware aliases, bindings,
event listeners, scheduled tasks; (5) routes as real PHP lines per route file with exact middleware and can: permissions - cross-check every
can: against (2) and every controller@method against the files on disk; (6) Sidebar entries; (7) seeder rows; (8) packages; (9) reconciliation:
every mismatch between routes, controllers, requests, views, services and models, with the exact fix and which file; (10) the contract's
acceptance test list mapped to what must be tested; (11) blunt risks.`, { label: `P${P}:handover`, phase: 'Domain', schema: RESULT })

  return { phase: phaseNo, workers: workers.filter(Boolean), handover }
}

async function buildDomain(u) {
  if (u.domainDone) return { unit: u.id, skipped: true }
  log(`Unit ${u.id}: domain stage`)
  const perPhase = await parallel(u.phases.map((p) => () => buildDomainForPhase(u, p)))
  return { unit: u.id, phases: perPhase.filter(Boolean) }
}

// ================================================================================================
// Stage: integrate -> tests -> verify+commit -> review -> fix
// ================================================================================================
async function integrateUnit(u, domain) {
  const head = BRIEF + unitHead(u) + `\nINTEGRATION FILES: ${u.phases.map((p) => 'docs-pending/phase-' + pad(p) + '-integration.md').join(', ')}\nDOMAIN REPORT: ${JSON.stringify(domain).slice(0, 12000)}\n\n`
  log(`Unit ${u.id}: integration`)

  if (u.id === '03') {
    await parallel([
      () => run(head + `YOUR ROLE: integration step A.1 for Phase 3 - write the five missing services exactly per docs-pending/phase-03-integration.md section A.1.
YOUR FILES: app/Services/Cms/PageService.php, MenuService.php, CtaBlockService.php, FaqService.php, StatisticsProvider.php, plus the named fixes
in SitemapGenerator.php and SectionService.php. Follow the existing Cms services' patterns. Run the plan's gate script and report its result.`, { label: 'U03:gate-services', phase: 'Integrate', schema: RESULT }),
      () => run(head + `YOUR ROLE: integration step A.2 for Phase 3 - write CachePublicResponse and ResolvePreviewMode exactly per docs-pending/phase-03-integration.md
section A.2 and the contract. YOUR FILES: app/Http/Middleware/CachePublicResponse.php, app/Http/Middleware/ResolvePreviewMode.php.`, { label: 'U03:gate-middleware', phase: 'Integrate', schema: RESULT }),
    ])
  }

  const integ = await parallel([
    () => run(head + `YOUR ROLE: registries and support wiring for unit ${u.id}.
YOUR FILES: app/Support/PermissionRegistry.php, app/Support/SettingsRegistry.php, app/Support/Modules.php, app/Support/Sidebar.php,
app/Support/helpers.php, and any new app/Support class the integration files name.
Apply every registry, settings, module-map, sidebar and helper item from the integration files. No existing permission, setting or module may be
removed. Verify afterwards that every can: string in the integration files' routes exists in the patched registry.`, { label: `U${u.id}:registries`, phase: 'Integrate', schema: RESULT }),

    () => run(head + `YOUR ROLE: wiring, routes, seeders and packages for unit ${u.id}.
YOUR FILES: app/Providers/**, bootstrap/app.php, routes/**, config/** (only what the integration files name), database/seeders/**,
database/migrations/** (only by MOVING this unit's staged files from database/migrations-staged/phase-NN/ in the order given - never write a new
migration here and never run migrate), composer.json/lock and package.json/lock (only through composer require / npm install).
Apply the policy registrations, middleware aliases, bindings, listeners, scheduled tasks, routes (keeping every existing route unchanged and every
public route ordered before the Phase 3 CMS catch-all), seed rows (idempotent; D65 additive role grants) and packages. Verify with php artisan
route:list: no duplicate names, every new route resolves. Do not run tests, seeders or migrate.`, { label: `U${u.id}:wiring-routes`, phase: 'Integrate', schema: RESULT }),

    () => run(head + `YOUR ROLE: reconcile unit ${u.id}'s domain files with each other.
YOUR FILES: this unit's domain paths only (its models, policies, services, controllers, requests, middleware, views, components).
Apply every reconciliation item in the integration files and fix any remaining mismatch between routes, controllers, requests, views, services
and models you find by reading the code. Compile views with view:cache then view:clear. Do not run tests.`, { label: `U${u.id}:reconcile`, phase: 'Integrate', schema: RESULT }),
  ])

  log(`Unit ${u.id}: tests`)
  const moneyNote = MONEY_UNITS.has(u.id) ? '\nTHIS IS A MONEY UNIT: every money path needs duplicate, partial, reversal and concurrency tests, and every requirement-120 scenario the unit notes name must be a real test asserting the exact rows and amounts.' : ''
  const tests = await parallel([
    () => run(head + `YOUR ROLE: acceptance tests for unit ${u.id} - behaviour, money and data isolation.${moneyNote}
YOUR FILES: new files under tests/Feature/ and tests/Unit/ in folders named for this unit's domain. Do NOT run tests.
From each contract's acceptance test list, write the tests for business behaviour, services, invariants, state transitions, money, and data
isolation (a client/student/teacher/collaborator sees only their own rows). Name each test after the contract's test id. List covered ids.`, { label: `U${u.id}:tests-behaviour`, phase: 'Tests', schema: RESULT }),
    () => run(head + `YOUR ROLE: acceptance tests for unit ${u.id} - authorization, routes, validation and screens.
YOUR FILES: new files under tests/Feature/ in folders named for this unit's domain, plus edits to existing tests ONLY where the integration files
say an existing test must change (never loosening an assertion). Do NOT run tests.
Write the permission matrix for every new route (403 without the ability, 200 with it, a disabled module denies everyone including Super Admin),
Form Request validation including hostile input, and that every screen renders. Name each test after the contract's test id. List covered ids.`, { label: `U${u.id}:tests-authz`, phase: 'Tests', schema: RESULT }),
  ])

  return { integ: integ.filter(Boolean), tests: tests.filter(Boolean) }
}

async function verifyUnit(u, context, round) {
  const head = BRIEF + unitHead(u)
  return run(head + `YOUR ROLE: verify, repair and COMMIT unit ${u.id}${round ? ' after review fixes (round ' + round + ')' : ''}. You are the ONLY agent running tests, migrations,
seeders and git right now. You MAY edit any file of this unit and any file its integration touched, and you may make view-scan compliance edits
(format helper swaps only) in another unit's in-progress view files if a global scan test fails because of them.
CONTEXT: ` + JSON.stringify(context).slice(0, 14000) + `

IN ORDER, fixing every failure before moving on:
1. composer dump-autoload && php artisan optimize:clear && php artisan permission:cache-reset
2. ${MONEY_UNITS.has(u.id) ? 'BACKUP FIRST: "C:/xampp/mysql/bin/mysqldump.exe" -u root --routines --triggers my_office > "C:/Users/QADRIL~1/AppData/Local/Temp/claude/C--xampp-htdocs-my-office/86973dbc-30fd-4cb3-a63c-75b5adc160d5/scratchpad/my_office_before_unit_' + u.id + '.sql" and confirm it is non-empty. ' : ''}php artisan migrate (forward). Report what ran.
3. Run this unit's seeders (and ModuleSeeder, PermissionSeeder, RoleSeeder, SettingSeeder) on my_office. Prove with SQL that no existing setting
   value, module is_enabled or role grant was removed (D65).
4. php artisan route:list (no duplicate names) and npm run build.
5. php artisan test, then php artisan test --order-by=random. Both must be green. Every contract acceptance test id of this unit must map to a
   passing test - write any that are missing. List every test you changed and why.
6. Smoke through the real HTTP kernel in a rolled-back transaction (delete the probe after): every new admin index screen 200 for Super Admin and
   403 for a user without the ability; every non-admin panel screen shows only the owner's rows; public pages (if any) render.
7. If and only if steps 5 and 6 are green: update DEVELOPMENT_LOG.md additively for this unit (tick its section 5 tracker rows, add a dated
   section 6 change-log entry, add section 7 test-result rows with the real counts; never delete existing content), then commit with
   "git add" of EXPLICIT paths - this unit's domain files, the files its integration changed, its moved migrations, its tests, DEVELOPMENT_LOG.md -
   and NOTHING from database/migrations-staged/ or another unit's in-progress paths (check git status --porcelain line by line). Commit message:
   first line "Phase ${u.phases.join(' + ')}: ${u.title}${round ? ' - review fixes' : ''}", a short body of what landed and the test counts, and the
   final line "${TRAILER}".
8. Return green=true only if the suite passed twice and the smoke passed; return the commit sha.`, { label: `U${u.id}:verify${round ? '-r' + round : ''}`, phase: 'Verify', schema: VERIFY }, 3)
}

async function reviewUnit(u, verify) {
  const head = BRIEF + unitHead(u) + `\nREAD-ONLY: edit nothing, run no tests, no git. Read-only mysql SELECTs are fine. VERIFY REPORT: ${JSON.stringify(verify).slice(0, 4000)}\n\n`
  const reviews = await parallel([
    () => run(head + `YOUR ROLE: adversarial security review of unit ${u.id}. Attack it with file:line evidence: authorization gaps and IDOR on every new
route, cross-owner data leakage in every non-admin panel, mass assignment, XSS through every user-controlled field, file upload and serving
(D21), CSRF, rate limits, SQL built from input, and ${MONEY_UNITS.has(u.id) ? 'MONEY: duplicate commission or payment under concurrency and retries, a float anywhere, a financial row updated or deleted, a reversal that does not reference its original, a wallet that can drift from SUM(ledger), a counter that can be reused' : 'unbounded queries and N+1 on list screens'}. Verified findings only.`, { label: `U${u.id}:review-security`, phase: 'Review', schema: FINDINGS }),
    () => run(head + `YOUR ROLE: contract compliance review of unit ${u.id}. Walk the contract sections for this unit's phases against the code and the real
database: every table and column, every invariant, every enum, every service guarantee, every route with its exact middleware and permission,
every UI behaviour, every acceptance test id mapped to a real passing test, and the D-decisions that apply. Critical for anything a later
phase would inherit.`, { label: `U${u.id}:review-contract`, phase: 'Review', schema: FINDINGS }),
  ])
  return reviews.filter(Boolean)
}

const SEV = { critical: 0, high: 1, medium: 2, low: 3 }

async function fixUnit(u, findings, round) {
  const head = BRIEF + unitHead(u)
  return run(head + `YOUR ROLE: fix every critical and high finding below for unit ${u.id} (review round ${round}), plus any medium finding whose fix is small and
clearly scoped. You MAY edit this unit's files and the files its integration touched. Fix root causes, add a regression test for each fix, never
loosen a rule or an assertion. Do not run the full suite and do not commit - the verify agent that follows does both. For any finding you judge
wrong, explain why in issues instead of changing code.
FINDINGS: ` + JSON.stringify(findings).slice(0, 14000), { label: `U${u.id}:fix-r${round}`, phase: 'Review', schema: RESULT })
}

async function processUnit(u, domain) {
  const integration = await integrateUnit(u, domain)
  let verify = await verifyUnit(u, { domain, integration }, 0)
  if (!verify || !verify.green) {
    log(`Unit ${u.id}: first verify not green - one more repair pass`)
    verify = await verifyUnit(u, { domain, integration, previousVerify: verify }, 0)
  }
  if (!verify || !verify.green) return { unit: u.id, ok: false, stage: 'verify', verify }

  let reviews = await reviewUnit(u, verify)
  const history = []
  for (let round = 1; round <= 2; round++) {
    const serious = reviews.flatMap((r) => r.findings || []).filter((f) => (SEV[String(f.severity).toLowerCase()] ?? 9) <= 1)
    history.push({ round, serious: serious.length })
    if (!serious.length) break
    log(`Unit ${u.id}: ${serious.length} critical/high findings - fix round ${round}`)
    const fix = await fixUnit(u, reviews.flatMap((r) => r.findings || []), round)
    const v2 = await verifyUnit(u, { fix, findings: serious }, round)
    if (!v2 || !v2.green) return { unit: u.id, ok: false, stage: `fix-round-${round}`, verify: v2, history }
    verify = v2
    if (round < 2) reviews = await reviewUnit(u, verify)
  }
  const open = reviews.flatMap((r) => r.findings || []).map((f) => `${f.severity}: ${f.title} (${f.location})`)
  return { unit: u.id, ok: true, tests: verify.tests, commit: verify.commit, reviewRounds: history, openFindings: open.slice(0, 40) }
}

// ================================================================================================
// Orchestration: domain lookahead + serial integration
// ================================================================================================
const domainPromises = []
const unitPromises = []

for (let i = 0; i < UNITS.length; i++) {
  const u = UNITS[i]
  // Domain for unit i starts once unit i-1's domain is done AND unit i-2 is committed (bounded lookahead).
  const prevDomain = i > 0 ? domainPromises[i - 1] : Promise.resolve(null)
  const twoBack = i > 1 ? unitPromises[i - 2] : Promise.resolve({ ok: true })
  const domainP = Promise.all([prevDomain, twoBack]).then(([, back]) => {
    if (back && back.ok === false) return { unit: u.id, skipped: true, reason: 'an earlier unit failed' }
    return buildDomain(u)
  })
  domainPromises.push(domainP)

  // Integration for unit i waits for unit i-1 to be committed and its own domain to be done.
  const prevUnit = i > 0 ? unitPromises[i - 1] : Promise.resolve({ ok: true })
  const unitP = Promise.all([prevUnit, domainP]).then(async ([prev, domain]) => {
    if (!prev || prev.ok === false) return { unit: u.id, ok: false, stage: 'blocked', reason: `unit ${prev ? prev.unit : '?'} did not finish green` }
    const out = await processUnit(u, domain)
    log(`Unit ${u.id}: ${out.ok ? 'COMMITTED ' + (out.commit || '') + ' - ' + (out.tests || '') : 'STOPPED at ' + out.stage}`)
    return out
  })
  unitPromises.push(unitP)
}

const results = await Promise.all(unitPromises)
return { units: results }
