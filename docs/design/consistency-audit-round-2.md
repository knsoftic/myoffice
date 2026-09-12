# CONSISTENCY AUDIT — ROUND 2 (post-convergence verification)

**Status: advisory.** This document changes nothing. It verifies whether the convergence pass actually
landed, finding by finding, and reports new contradictions the convergence edits created.

Scope: every **critical** and **high** finding in [`consistency-audit.md`](consistency-audit.md) (6 critical
+ 32 high = 38), checked against the current text of the thirteen contracts,
[`resolutions.md`](resolutions.md), `CLAUDE.md` and `DEVELOPMENT_LOG.md`. Medium/low findings were read for
context only; where one produced new drift it is recorded in §4.

Verdicts: **CLOSED** = the contract text now says what the resolution requires, in every document that
touched it · **PARTIAL** = the contract side is done but the defect the finding named still exists in an
owner-applied file · **OPEN** = not done.

---

## 1. Scoreboard

| Finding | Sev | Verdict | Evidence (document · location) |
|---|---|---|---|
| F-2.1 `contact_inquiries` three owners | crit | **CLOSED** | phase-03 §1.3 L74, §6.1 L66 (`contact` type → Phase 4), §10.3, §13.3 L1693 · phase-04 §2.20, §6.10.4 L1217-1219 · phase-05 §1.2 L37, §6.10 L734, §10.2/§10.5 L1165/L1196, §13.1 L1376 · phase-08-09 §13.1 L1085 · phase-14-17 §2.11 L403 |
| F-3.1 `tasks.is_client_visible` | crit | **CLOSED** | phase-06 §2.5 L323 (bool, default true), Keys L333 `INDEX (project_id, is_client_visible)`, §7.7 L1078, §9 L1332, test P6-51 |
| F-4.1 `DocumentNumberService` | crit | **CLOSED** | phase-05 §6.10 L733 (owner, `next()`+`reserve()`) · phase-06 L1624 · phase-07 §5 L1191 [D-HR-14], §13.1 L2222 · phase-10-12 §1.2 L43, §6.3 L456 "reuse, must not re-create" · phase-08-09 L435 · phase-14-17 L60 · phase-18 L42 · phase-19-23 L3523 · spine §6.2 L1521 |
| F-5.1 `ContentStatus` / `PostStatus` | crit | **CLOSED** | phase-04 §3 L594 ("`PostStatus` does not exist"), §2.16 L395, `BlogService::allowedNext()` L945; `blog:publish-scheduled` L1818 keeps `scheduled` live |
| F-5.2 `EmploymentType` | crit | **CLOSED** | phase-07 §3 L1034 (7 cases incl. `freelance`, single owner) · phase-04 §2.18 L448, §3 L595, §13 L2080 |
| F-10.1 decision-number collisions | crit | **PARTIAL** | Contract side clean — per-file scan of §13.2–13.4 shows zero number claimed twice (spine D17/D18/D43 · 03 D22 · 05 D27-D31 · 06 D32-D35 · 07 D36 · 08-09 D37/D38 · 10-12 D39 · 13 D40-D44 · 14-17 D45-D48 · 18 D49/D50 · 19-23 D51-D56 · 24-25 D57-D60). **But `DEVELOPMENT_LOG.md` §4 L131 still holds only the `D17+` pointer row**, so every "`DEVELOPMENT_LOG.md` §4 D19/D21/D27…" citation in thirteen contracts points at rows that do not exist yet. See §3.1 |
| F-2.2 `course_faqs` | high | **CLOSED** | phase-14-17 L161, L393, §2.10, §7.2 · phase-03 §6.13 L1030 (allows `App\Models\Institute\Course`), §13.3 L1688 · no `course_faqs` row survives anywhere |
| F-2.3 per-entity SEO columns | high | **CLOSED** | phase-04: 11 × `morphOne(SeoMeta::class,'seoable')` (L58, 104, 137, 171, 204, 251, 361, 414, 469, 475), §2.3 L402 "No SEO column", §8.13 L1676 writes via `SeoService::save()`. Every column phase-04 now names exists in phase-03 §2.12 (`title`, `meta_description`, `meta_keywords`, `canonical_url`, `robots`, `og_image_media_id`) and `SeoService::for()` is published at phase-03 §6.5 L815 |
| F-2.4 two media subsystems | high | **CLOSED** | phase-04 §2.8 L206 `portfolio_item_media` (+ `uq_pim`, indexes, no `deleted_at`), §6.6 L901 `ImageUploadService` deleted, eleven `*_media_id` FKs (L118-393) · phase-03 §13.3 L1687 states the two tiers and names the six private `*_path` survivors · phase-24-25 L59 |
| F-2.5 two HTML sanitisers | high | **CLOSED** *(but see ND-5)* | phase-04 §6.9 L1131 deletes `HtmlSanitizer`, R7 L2001, Q7 L2017 · phase-03 §6.6 L872, §13.3 L1689 · phase-19-23 INV-21-5 L137 · phase-24-25 §1.2 L59, SEC-04/05 |
| F-3.2 `task_comments.is_client_visible` | high | **CLOSED** | phase-05 §13.1 row deleted (only the convergence log L1431 mentions it) · phase-06 §2.7 L376 `CommentVisibility` unchanged (`internal`/`team`) |
| F-3.3 `clients.assigned_to` | high | **CLOSED** | phase-06 §9 L1329 `clients.account_manager_id = $user->id`, §13.1 L1615 request withdrawn |
| F-3.4 `clients.collaborator_id` | high | **CLOSED** | phase-08-09 §13.1 L1087 (ask deleted, retargeted to `referral_code_captured` + `referral_recorded_at`), §10.2 L934 names both CRM snapshot columns and states neither table carries `collaborator_id` |
| F-3.5 `leads.referral_visit_id` | high | **CLOSED** | phase-05 §2.1 L139 + Keys L153 `INDEX (referral_visit_id)`, deferred guarded FK, §13.1 L1377 · phase-08-09 §13.1 L1088 ask kept, §10.4 prune guard L957 covers `leads.referral_visit_id` |
| F-3.6 `leads.interested_service_id` | high | **CLOSED** | phase-04 §6.10.4 L1226 maps to `leads.service_id`, §13 L2066 request deleted |
| F-3.7 `leads.contact_inquiry_id` unique | high | **CLOSED** | phase-05 §2.1 L145 `UNIQUE uq_leads_inquiry(contact_inquiry_id)`, §6.10 L734 and §10.5 L1196 both rely on the 1062, never a SELECT · phase-04 §13 L2064 states the index |
| F-3.8 `course_inquiries.contact_inquiry_id` | high | **CLOSED** | phase-14-17 §2.11 L425 + Keys L447 `UNIQUE uq_ci_inquiry`, §6.12 L1825 treats 1062 as "already created"; `idempotency_key` kept |
| F-3.9 `expenses.source_type`/`source_id` | high | **CLOSED** | phase-13 §2.6 L321/L333 (`uq_exp_source` + index), §6.4.2 L796-805 `RecordPayrollExpense`, §2.3 reserved `salaries` category L174, §6.7.3 P&L block B L893 · phase-07 fires `PayrollRunPaid` L1294/L2017, Q14 L2205 and §13.1 L2227 marked satisfied |
| F-3.10 `project_milestones.title` | high | **CLOSED** | no `project_milestones.title` occurrence survives in phase-13 (only the log row L1678) |
| F-3.11 `projects.project_manager_id` | high | **CLOSED** | phase-13 §9 L1318 compares `$user->id`, §13.1 L1632 |
| F-4.2 `refund()` two signatures | high | **CLOSED** | spine §6.2 L1486 DTO form + §13.2 L2357 `RefundData` (6 readonly props) · phase-10-12 §6.3 L470 · phase-13 §6.5 L817 named-argument example · phase-18 §6.10.5 L572-580 all three rows |
| F-4.3 `attach()` cannot fill evidence | high | **CLOSED** | spine §6.2 L1490 sixth param + §13.2 L2358 `ReferralContext` · phase-10-12 L480 · phase-08-09 §6.5 L575, §13.2 L1094 · phase-14-17 §6.4 L1518 |
| F-4.4 `recordLosingCandidate()` | high | **CLOSED** | spine §6.2 L1494 · phase-10-12 §6.3 L481 · phase-08-09 §6.3 modifier 7 L527, §13.2 L1095 |
| F-4.5 `copyAttribution()` | high | **CLOSED** | phase-05 §6.4 step (7) L667 calls published `attach()` with the lead's referral date + `lead_conversions.collaborator_referral_id`; §13.1 L1386 request withdrawn; test 53 L1290 unchanged. `ReferralSource::ManualSelection` exists (spine §3 L1222) |
| F-4.6 four `StudentFeeService` methods | high | **CLOSED** | phase-18 §6.1 L275-278 (all four published), `FeeSummary` L971 · phase-14-17 §13.1 satisfied · phase-19-23 §13.1 L3610 SATISFIED |
| F-5.3 three source enums | high | **CLOSED** | phase-04 §3 L611 owner (11 cases) · phase-05 §3 L501 `LeadSource` deleted · phase-14-17 §3 L1307 `CourseInquirySource` deleted |
| F-5.4 `PaymentMethod` / `LedgerEntryType` | high | **CLOSED** | phase-07 §3 L1050-1051 declares both · phase-10-12 §3 L203/L216 "reused, not created", §3 preamble "three of the 30" · spine §3 L1199/L1212 |
| F-5.5 `CommissionCalculationType` | high | **CLOSED** | phase-10-12 §3 L208 reused · spine §3 L1204 "declared by Phase 6" · phase-06 §3 (owner) |
| F-6.1 project-payments gate | high | **CLOSED** | spine §7.2 L1859-1868 and §7.6 L1954-1955 both `module:project_payments`, §9 updated · phase-13 §4.2 L619 `payments` = umbrella only · phase-05 §7 L847 unchanged |
| F-6.2 duplicate route names | high | **CLOSED** | phase-05 §7 L817-823 D31 ownership paragraph + route table L835-847 · phase-06 §7.7 L1068-1082 declares no `client.*` name, `client.attachments.download` → `client.files.download`, `client.files.index` at `/client/files` · phase-10-12 §7.6 contributes through `ClientPortalRegistry` · `ClientPortalRegistry` itself is published at phase-05 §6.9 L726 |
| F-7.1 percentage widths | high | **CLOSED** | zero `decimal(5,2)` percentage columns remain system-wide (only `print_templates.margin_mm` / `qr_size_mm`, which FIN-18's regex does not match) · phase-14-17 INV-I11 + FT-43 L2705 asserts `66.6700` · phase-19-23 §2 preamble · phase-24-25 FIN-18 allowlist closed to `progress`, `rating`, `*_marks` |
| F-8.2 / F-12.2 snapshot scope | high | **CLOSED** | phase-06 §9 L1331 (active `project_members` **or** active `collaborator_referrals`; snapshot grants nothing) + test P6-58 L1563 · phase-08-09 §9 L14 · phase-14-17 §9 / §6.14 + FT-50. `collaborator_referrals.project_id` really exists (spine §2.8 L474) |
| F-9.1 ~50 tables vs `CLAUDE.md` §3 | high | **PARTIAL** | All eleven contracts now cite **D19** (+D16 for money): spine §2.1, phase-03 §2.14, phase-04 §2, phase-05 §2.4/§2.6, phase-06 §2.2/§2.8/§2.11, phase-07 [D-HR-3], phase-08-09 §2.2-2.4, phase-13 §2, phase-14-17 [D-IN-2], phase-19-23 §2, phase-24-25 §2. **`CLAUDE.md` §3 L89 still reads "Every business table carries … `deleted_at`"** — the sentence the finding is about. See §3.2 |
| F-11.1 `users.id` vs `employees.id` | high | **CLOSED** | phase-07 [D-HR-2] L89 narrowed to duties, §1.2 L63 "Nothing", §13.1 request deleted · phase-06 [D-P6-2] cites D32 · phase-13 §9 L1318 · phase-14-17 §2.17 (`teachers.employee_id` = a duty) |
| F-11.2 spine tables two phases early | high | **PARTIAL** | spine §1.2 L72 + Q10 L2320 answered · phase-10-12 §1.3 L68 release-order paragraph · phase-08-09 §1.4 unchanged. **`DEVELOPMENT_LOG.md` §5 L200 (PHASE 8) still carries no note** — the exact defect the finding names. See §3.3 |
| F-11.3 Phase 18 screens not tables | high | **PARTIAL** | phase-18 header L11 "creates no financial table", §1.2, §2.1 · phase-10-12 §2.1 L110, §1.3, §13.2 L1305. **`DEVELOPMENT_LOG.md` §5 L210 (PHASE 18) unchanged.** See §3.3 |
| F-12.1 portal prefixes denied to all | high | **CLOSED** *(contract)* | phase-01 §3 L130 (`null` = "not module-gated"), §6 step 1 L224-226 falls through on null, §10 L319 four-panel test, D20. Code change correctly flagged **pending** at §11 A2 — not drift, a declared remediation item |

**Counts.** 38 critical+high checked · **34 CLOSED** · **4 PARTIAL** · **0 OPEN**.
All four PARTIALs are blocked on the two owner-applied files, not on any contract.

*Scoreboard artefact (pre-existing, not convergence drift):* the audit's §1 table totals 7 criticals but only
six findings carry the `critical` label (F-2.1, F-3.1, F-4.1, F-5.1, F-5.2, F-10.1). The §3 "Undefined
columns" row counts 1 critical + 8 high = 9 while §3 lists 15 findings. Worth correcting if the audit is
ever cited as a count.

---

## 2. Convergence logs versus the documents

Every contract carries a `## Convergence log (2026-09-12)` section. I sampled claims across all thirteen
and tried to break them. **No log claims a change the document does not contain.** Spot-checks that landed:
phase-03 §6.1 L66 and §10.3 L11 (both retargeted, as claimed) · phase-06 test P6-58 L1563 (exists) ·
phase-18 PH18-24 L887 (rewritten to the 1062 path) · phase-14-17 FT-43 L2705 (`66.6700`) ·
phase-19-23 PH22-10 L3371 (asserts `TicketSlaService::multiplier()` and the off-switch) ·
phase-02 `DuplicateWidgetKeyException` L80 + L170.

Two logs overstate a count (ND-9, ND-11) and two record deliberate deviations from the resolution wording
(ND-6, ND-7) — all in §4.

---

## 3. The four PARTIALs — owner-applied files

### 3.1 F-10.1 · `DEVELOPMENT_LOG.md` §4 holds no D17–D60

`DEVELOPMENT_LOG.md` L131 is still `| D17+ | Further decision numbers are assigned by the authoritative
registry in docs/design/resolutions.md …`. D1–D16 are frozen and present (L130 = D16, approved), so
phase-10-12's migration-blocking condition ("D16 recorded before file 01") **is** satisfied. What is not
satisfied: thirteen contracts now cite `DEVELOPMENT_LOG.md` §4 **D19, D20, D21, D22, D25, D27, D31, D32,
D37, D43, D60** as if the rows were there. Paste `resolutions.md` §4.3.

### 3.2 F-9.1 · `CLAUDE.md` §3 still says "every"

L89: *"Every business table carries: `created_at`, `updated_at`, `deleted_at` (soft deletes), `created_by`,
`updated_by`."* Eleven contracts now cite D19 and say "the full category rule is in `CLAUDE.md` §3" — which
is circular until blocks **A**, **B** (the `files` slug → `attachments`, which F-2.8 and phase-05 §13.1
L1392 both forward to) and **C** (the one percentage width, which phase-14-17 FT-43 L2705 cites as
"`CLAUDE.md` §3's one percentage width") are pasted from `resolutions.md` §5. Until then the three
statements point at text that does not exist, and a developer reading §3 will add `deleted_at` to an
append-only table (phase-08-09 R-10 names that exact failure).

### 3.3 F-11.2 / F-11.3 · `DEVELOPMENT_LOG.md` §5 tracker lines

L200 `### [ ] PHASE 8 — Collaborator management …` and L210 `### [ ] PHASE 18 — Student fees, installments,
discounts, scholarships (commission triggers)` are byte-for-byte what the audit quoted. Both findings were
*about* those lines. Paste `resolutions.md` §6.3. Until then the tracker still reads as if Phase 8 can be
ticked before the spine exists and as if Phase 18 creates the fee tables.

---

## 4. New contradictions created by the convergence

### ND-1 · high · phase-13 gates two money routes on a permission nothing registers

phase-13 §6.3 rules 4-5 (L752-753) and §7.1 (L985-986) require **`can:project_payments.edit`** on
`admin.invoices.payments.apply` / `.unapply`, replacing the `project_payments.change_status` they used
before. No registry declares that ability: spine §4.1 L1238 registers `project_payments` as
`READ + create + print + export + STATUS + MONEY + LOGS` — *"no `edit`, no `delete` ever"* — and phase-13
§4.2 L620 appends **only** `view_reports`. The spine's own F-4.10 block (L135) saw this and bound the
conservative reading: *"either §4.1 gains an `edit` ability on a money module … or D43's guard is
re-expressed as an existing ability … Until the owner rules, the conservative reading binds."*
phase-13 ignored that and wrote the literal D43 wording into its routes.
Effect: both apply/unapply routes are unreachable for every role (an unseeded permission in a `can:`
middleware is not a grant), so `invoices.paid_amount` can never be right for an advance — and a contract
edit now widens a money module's permission surface in contradiction of the spine, which wins on money.
Fix (smallest, spine-compatible): phase-13 §6.3 / §7.1 read `can:invoices.edit` **+**
`can:project_payments.change_status`, or the owner rules on D43's wording and the spine's §4.1 is amended
in one place.

### ND-2 · high · D43's concession is unreachable through phase-10-12's model guard

spine §1.4 INV-8 L104 now whitelists `notes` / `reference_no` / `receipt_path` / **`invoice_id`**.
phase-10-12 §2.2 `[D-IMP-2]` L177 still enumerates the `updating` guard as
`{notes, reference_no, receipt_path, status, refunded_amount, commission_state, commission_skip_reason,
commission_skip_detail, commission_attempts, commission_processed_at, collaborator_id,
collaborator_referral_id, updated_by, updated_at}` — **no `invoice_id`**, and the string `invoice_id` does
not occur anywhere in phase-10-12. `InvoiceService::applyPayment()`'s conditional UPDATE therefore throws
on the model that Phase 10 ships.
Cause: `resolutions.md` §7's apply map lists F-4.10 for the spine and phase-13 only; phase-10-12 —
which owns the guard that enforces INV-8 — was never given the row.
Fix: add `invoice_id` to phase-10-12 §2.2's `[D-IMP-2]` whitelist with the D43 citation and the four guards.

### ND-3 · high · F-2.1's retarget reproduced F-3.4/F-3.5 on `contact_inquiries`

phase-08-09 §13.1 L1085 now asks **Phase 4** for `contact_inquiries.collaborator_id`, `.referral_code` and
`.referral_visit_id`, and §10.2 L934 makes `SyncReferralSnapshot` "the only writer" of the
"`contact_inquiries` … equivalents" of the snapshot columns. phase-04 §2.20 defines **none** of the three
(its referral-adjacent columns are `service_id`, `assigned_to`, `read_by`; the words "collaborator" and
"referral" appear nowhere in phase-04's schema), and phase-04 §13 has no **Phase 8-9** block at all
(its blocks are 1, 2, 3, 5, 7, 14, 15, 22, 23, 24).
So the retarget moved the ask from a phase that did not own the table to the phase that does — and the
owner was told "unchanged (owner)" by `resolutions.md` §3.1. The column-referenced-but-never-defined defect
the audit raised as F-3.4/F-3.5 now exists on `contact_inquiries`.
Fix: either phase-04 §2.20 adds the three nullable indexed snapshot columns (deferred FKs, same pattern as
`employee_id`) plus a §13 Phase 8-9 block, or phase-08-09 §13.1 withdraws the ask and the §10.2 listener
line drops `contact_inquiries`.

### ND-4 · high · one `InquiryTarget` interface, two namespaces

| Document | Declaration |
|---|---|
| phase-04 §6.10.1 L1155 (owner) | `interface InquiryTarget    // App\Contracts\Cms\InquiryTarget` |
| phase-05 §6.10 L734 (implementer, new in this pass) | "`implements App\Contracts\Inquiry\InquiryTarget` (Phase 4's interface)" |

Both were written by the F-2.1 convergence. phase-14-17 §2.11 L405 and §13.1 L2778 name the interface
without a namespace, so they are neutral. One of the two must move; phase-04 owns it, so phase-05 §6.10
should read `App\Contracts\Cms\InquiryTarget` (the class itself, `App\Support\Inquiry\CrmLeadInquiryTarget`,
already agrees across both contracts).

### ND-5 · medium · the one sanitiser cannot serve the print templates

F-2.5 collapsed everything onto `App\Support\RichText::sanitize(string $html): string` — phase-03 §6.6 L872
publishes exactly that signature, over `mews/purifier` with **one committed `cms` profile** whose allowlist
is `p br strong em u s h2 h3 h4 ul ol li blockquote a img figure figcaption table thead tbody tr th td hr
span` + `href title target rel src alt width height class`.
phase-19-23 §6.13 L1950 needs a **wider** set for print templates (`div`, `h1`, `h5`, `h6`, `small`, `b`,
`i`, `sub`, `sup`, `style`, `align`, `src` for `data:`) plus CSS sanitisation, and describes itself as
*"a thin wrapper over Phase 3's `RichText::sanitize()` … configured with this wider tag set"* while in the
same sentence declaring *"no second purifier profile of its own"*. With no profile parameter on
`sanitize()`, both halves cannot be true: either the print template silently loses `div` / `style` /
`align` / `data:` images (which breaks D52's snapshot fidelity and D53's token templates), or a second
purifier profile exists and D25 / phase-03 §13.3 L1689 are violated.
Neither contract records an ask. Fix: phase-03 publishes `RichText::sanitize(string $html, string $profile
= 'cms')` with a committed second profile `print`, named in D25 as part of the *one* sanitiser — one class,
one code path, two allowlists — and phase-19-23 §6.13 cites it.

### ND-6 · medium · F-4.8 closed per-collaborator, left open company-wide

spine §6.2 publishes `payoutsPaidTotal(Collaborator, ?DateRange)` and
`commissionAccruedTotal(Collaborator, ?DateRange)` — both per-collaborator. phase-13 §6.7.3 L902-907 records
that P&L block C and its memo are **company-wide**, that it may not close the gap by summing (INV-26,
FT-42), and that *"the company-wide variant has to come from the spine as well; it is recorded as a request
in §13.1 rather than guessed here."* The spine publishes no such variant (no "company-wide" occurrence).
So §99's P&L block C has no legal source today. Fix: spine §6.2 adds
`payoutsPaidTotalAll(?DateRange): string` and `commissionAccruedTotalAll(?DateRange): string` off the same
§6.5.1 canonical SQL, and phase-10-12 §6.3 mirrors the two rows.

### ND-7 · medium · F-4.9 applied two different ways, and the resolution text was not reconciled

phase-10-12's log states plainly that it did **not** add the `refunded_amount` rollback listener, because
`rejectReversal()` already rolls the column back inside its own transaction under the payment's row lock,
and a second `afterCommit` decrement would drive `refunded_amount` below zero. That is the
safer-with-money reading and matches spine §10.1 (the event is the cache/notification leg only) and
phase-18 §6.10.5. It is correct — but `resolutions.md` §3.3 F-4.9 still instructs "add it plus the listener
that rolls `refunded_amount` back", so the canonical file and the three money contracts now disagree in
writing. Fix: amend the resolutions row to the implemented form so a later agent does not "restore" the
double decrement.

### ND-8 · low · `resolutions.md` names a column the spine does not have

`resolutions.md` §3.6 (F-8.2 row) requires phase-06 to scope on "`subject_type = Project`,
**`subject_id` = projects.id**". `collaborator_referrals` has no `subject_id`: spine §2.8 L471-476 carries
`subject_type` plus four explicit nullable FKs (`student_id`, `project_id`, `client_id`, `lead_id`).
phase-06 §9 L1331 applied it correctly (`subject_type = ReferralSubject::Project`, `project_id =
projects.id`), so no contract is wrong — the canonical file is. Fix the resolutions row so it cannot be
re-applied literally.

### ND-9 · low · `website` settings group: 34 keys or 35?

phase-03 §5 L596 ("**34 keys** in total — 13 from Phase 3 and 21 from Phase 4") and phase-04 §5 L680-685
("**21 keys** … giving the group 34 keys") agree with each other. `resolutions.md` §2.4 says "phase-03's 13
keys **and** phase-04's 22 keys merged (35 keys)". I counted both lists: 21 rows in phase-04 §5 and 21 rows
in phase-03 §5.1b, identical keys — so nothing was lost, the canonical count is simply one high. Fix the
resolutions row.

### ND-10 · low · the numbering owner's consumer list is short

phase-05 §6.10 L733 / §13.1 L1388 list the reusing phases as "6, 7, 8-9, 10 and 14-17". Three more phases
call the class: phase-13 §13.3 L1664 (invoice numbers), phase-18 §2.6 L128 (fee and receipt numbers),
phase-19-23 §13.1 L3523 (certificate, ID-card and ticket numbers). Add them so the owner's "do not
re-create" list is complete.

### ND-11 · low · "19 methods" vs 20

phase-24-25's convergence log L1985 says the `Money` surface is "19 methods + `RemainderPlacement`";
phase-01 §3 L132, §11 A1 and phase-24-25's own §13.1 L1917 quote the same list, which has **20** entries.
Cosmetic, but FIN-12 "tests this exact list".

### ND-12 · low · watch · `uq_cr_superseded_by` and the new losing-candidate row

spine §2.8 L488 makes `superseded_by_id` **unique** ("a row has at most one successor"), and phase-10-12
§6.3 L481 leans on it for "one loser per winner". Both `change()` (L1491) and the new
`recordLosingCandidate()` (L1494) write `superseded_by_id = winner`. A subject that both supersedes an
existing referral *and* records a losing candidate against the same winner hits 1062. Phase 9's ladder may
make that unreachable — but nothing says so. Worth one sentence in spine §2.8 stating which of the two
writes owns the index.

### ND-13 · low · one SEO validation contract, two places

phase-04 §8.13 L1676 says `<x-cms.seo-fields>` is "one implementation, one validation contract" writing only
through `SeoService::save()`, yet §6.5's `StoreBlogPostRequest` / `UpdateBlogPostRequest` L1283 still
validate `meta_description` max 255 — a `seo_meta` column validated by an entity Form Request. Harmless
today, but it is how a second SEO write path starts.

---

## 5. Checked and clean — do not "re-fix"

| Checked | Result |
|---|---|
| `course_faqs`, `portfolio_images`, `ImageUploadService`, `HtmlSanitizer`, `PostStatus`, `LeadSource`, `CourseInquirySource`, `TicketPriority`, `project_collaborator`, `files` table, `marks_obtained`, `site.enabled`, `copyAttribution`, `assigned_employee_id`, `files.is_client_visible`, `add_client_visibility_to_attachments_table` | no live reference survives in any contract; every occurrence is a deletion note, a log row, or the audit/resolutions themselves |
| `seo_meta` columns phase-04 now writes | all six exist in phase-03 §2.12; `SeoService::for()` / `save()` / `completeness()` published at phase-03 §6.5 L815; `ImageProfile::Og` / `Logo` / `Thumbnail` / `Icon` all exist (phase-03 §3 L539); `SitemapRegistry` + `SitemapUrlProvider` published at phase-03 §6.5 L850 |
| Cross-phase DTO/service names | `App\DataObjects\Finance\RefundData`, `App\DataObjects\Collaborator\ReferralContext`, `App\DataObjects\Institute\SlotCandidate` / `ClashReport` / `FeeSummary`, `App\Support\ReportResult`, `App\Services\Reporting\ReportExporter`, `App\Enums\RemainderPlacement` — one namespace each, identical property lists in every consuming contract |
| Widget keys | the three fee keys deleted from spine §8.12 and phase-10-12 §8.12, owned by phase-18 §8.10, excluded by name in phase-02 §3 L95 and phase-14-17 L2537; phase-02's registry now throws on a duplicate key |
| Decision-number allocations | no number claimed twice across the thirteen §13 sections (per-file scan in §1) |
| `ReferralSource::ManualSelection`, `PaymentService::isWriting()`, `LedgerWriter::isWriting()`, `collaborator.referral_visit_retention_days`, `PayrollRunPaid`, `attachments.visibility` default `team` | every artefact a converged contract newly calls by name exists in its owner's contract |
| Money guarantees | no invariant, trigger, CHECK or duplicate-prevention layer was removed anywhere; every money edit in this pass was additive (`generation_key`, `uq_exp_source`, the two totals, the DTOs) or a tightening (DTO over positionals, INSERT over SELECT) |

---

## 6. Recommended order

| # | Action | Owner |
|---|---|---|
| 1 | ND-1 — stop gating money routes on an unregistered ability (phase-13 §6.3 / §7.1), per the spine's conservative reading | contract edit |
| 2 | ND-2 — add `invoice_id` to phase-10-12 `[D-IMP-2]`'s `updating` whitelist | contract edit |
| 3 | ND-3 — decide: phase-04 adds the three `contact_inquiries` snapshot columns, or phase-08-09 withdraws the ask | needs a decision |
| 4 | ND-4 — one namespace for `InquiryTarget` | contract edit |
| 5 | §3.1–3.3 — paste `resolutions.md` §4.3, §5 blocks A/B/C and §6.3 into `DEVELOPMENT_LOG.md` / `CLAUDE.md`; closes F-10.1, F-9.1, F-11.2, F-11.3 | **owner only** |
| 6 | ND-5 — `RichText::sanitize()` gains a profile argument, or print templates lose tags | needs a decision |
| 7 | ND-6 — spine publishes the two company-wide totals | spine edit |
| 8 | ND-7, ND-8, ND-9 — correct `resolutions.md` so nothing is re-applied literally | canonical file |
| 9 | ND-10 … ND-13 — tidy | contract edits |
