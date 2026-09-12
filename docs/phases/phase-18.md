# PHASE 18 CONTRACT - Student fees, installments, discounts and scholarships (the student commission trigger)

**Authority.** [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) is binding
for every money table, column, status, service contract and invariant this phase touches. Where this
document and the spine appear to differ, **the spine wins** and the difference is recorded in §12.2 as an
open question - never silently redesigned. [`phase-01.md`](phase-01.md) and [`phase-02.md`](phase-02.md)
win over both. Conventions: [`../../CLAUDE.md`](../../CLAUDE.md). Requirement source:
[`../requirements.md`](../requirements.md) sections **40-44, 41, 76, 77, 78, 68, 69, 88, 97, 112** and
the acceptance tests in **§120**.

**This phase creates no financial table.** `student_fees`, `student_fee_installments`,
`student_fee_discounts` and `student_fee_payments` are created by the spine's single migration set
(spine §1.3, shipped with Phase 10 - the split is **accepted and recorded**: resolutions §2.1, F-11.3).
Phase 18 ships **one** new non-financial table (`student_fee_reminders`), the fee services, the screens
and the printable documents.

Decisions are labelled **[D18-n]** so a code review can cite them.

---

## 1. Goal and dependencies

### 1.1 Goal

After this phase the institute can turn an admission into a complete, numbered set of fee charges (course,
admission, registration, monthly, exam, certificate, other), split any charge into an installment plan
whose lines sum to the net fee **to the paisa**, grant fixed / percentage / scholarship / promotional /
referral discounts and waivers with an approver, a date and a reason, collect full or partial payments at a
desk with gapless receipt numbering, see every charge in exactly one of the four requirement statuses with
overdue detected nightly, print a fee slip and a receipt, and let the student see their own fees and
nothing else. Every rupee actually received flows straight into the spine's commission trigger, so a
referred student's payment credits their collaborator exactly once, and a refund writes the matching
negative commission.

### 1.2 Dependencies

| Phase | What Phase 18 needs from it |
|---|---|
| 1 | `users`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `branches`, `activity_log` (old/new + `reason`), **`App\Support\Money` exactly as phase-01 §3 publishes it** (F-4.11 - nothing is added to it here) and `App\Enums\RemainderPlacement`, `Blameable`, `LogsActivityWithContext`, `x-ui.*`, `layouts/admin`, `layouts/panel` |
| 2 | `SettingsRegistry` groups `institute` / `collaborator` / `finance`, `SettingsService`, `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`) |
| 10 (spine) | **the four fee tables - Phase 10 is their owner and creates all four** (`student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`; resolutions §2.1, F-11.3), all enums of spine §3, `PaymentService` (student side), `StudentCommissionService`, `CommissionReversalService`, `LedgerWriter`, `ProcessStudentFeeCommission`, `ProcessCommissionReversal`, `commissions:sweep`, the record-payment modal, the payments register, spine routes §7.1 |
| 5 | **`App\Services\Finance\DocumentNumberService`** - Phase 5 ships the single numbering implementation (D27, F-4.1); Phase 18 reuses it, passing its own `'%06d'` pad for fee and receipt numbers, and never re-creates it |
| 8 / 9 | `collaborators` (`status`, `referral_code`), `collaborator_referrals` written only through `ReferralService` |
| 12 | `CollaboratorWalletService`, `CollaboratorStatementService` - the only balance sources the fee screens may read |
| 14 | `courses` (`course_fee`, `admission_fee`, `registration_fee`, `installment_available`, `duration`) |
| 15 | `students` (`branch_id`, `status`), `student_admissions` (`course_fee`, `discount_amount`, `scholarship_amount`, `admission_fee`, `registration_fee`, `total_amount`, **`net_payable`**, `admission_date`, `status`) |
| 16 | `batches` (`id`, `start_date`) - default first due date, charge display, batch filters |
| 13 | `payment_methods` (nullable FK already on the payment row; the `PaymentMethod` enum stays the snapshot of record) |
| 13 | `App\Support\ReportResult`, `App\Services\Reporting\ReportExporter`, `resources/views/layouts/print.blade.php` - Phase 13 ships all three (F-4.14); Phase 18 calls them and builds no second print layout or exporter |

Tables read: `students`, `student_admissions`, `courses`, `batches`, `branches`, `collaborators`,
`collaborator_commission_entitlements`, `collaborator_commission_ledger_entries` (read-only, for the
commission trail and the fee slip), `settings`, `users`.

---

## 2. Schema

### 2.1 Ownership map - what Phase 18 may and may not do to each table

| Table | Created by | Phase 18 may | Phase 18 must never |
|---|---|---|---|
| `student_fees` | spine §2.2 (**Phase 10 creates it** - F-11.3) | insert; update the cache, status, plan, cancellation, `due_date` and `batch_id` columns of §2.3; write `generation_key` at generation time | alter the table, soft-delete a charge that holds a receipt, write any `collaborator_*` column except the display snapshot `collaborator_id` at issue time |
| `student_fee_installments` | spine §2.3 (**Phase 10 creates it**) | insert, update `amount` of an **unpaid** line, update caches and status, cancel, waive | delete a line holding money; renumber `installment_no`; write `paid_amount` from anything but its receipts |
| `student_fee_discounts` | spine §2.4 (**Phase 10 creates it**) | insert only (append-only, no `deleted_at` - D16 under D19) | update or delete any row; write a zero `amount`; let non-scholarship + scholarship exceed `gross_amount` |
| `student_fee_payments` | spine §2.5 (**Phase 10 creates it**) | insert **only through `PaymentService::recordStudentFeePayment()`**; update `notes` / `reference_no` / `receipt_path` | insert directly, edit `amount` / `paid_on` / `student_fee_id` / `student_fee_installment_id`, write any `commission_*` column, delete a row |
| `payment_reversals` | spine §2.7 (**Phase 10 creates it**) | create only through `PaymentService::refund($p, RefundData)` / `void()` | insert or edit directly |
| ledger / entitlement / wallet tables | spine | **read only** | any write, any `SUM()` of its own (INV-26) |
| `student_fee_reminders` | **Phase 18 (§2.2)** - the only table this phase creates | everything | - |

### 2.2 `student_fee_reminders` - the one new table

Why it exists: §97 requires fee-due and fee-overdue notifications and §77 schedules them per installment.
A scheduled job that re-runs (retry, a second scheduler tick, a manual "Send reminder now") must not
notify a student twice for the same line on the same day. The dedupe guard is a unique index, not a
`SELECT` (spine §2.19 principle). It carries **no money column**, so it is not one of the spine's
append-only financial tables.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_fee_id` | FK `student_fees.id` | not null | `cascadeOnDelete` - a reminder log has no value without its charge and holds no money |
| `student_fee_installment_id` | FK `student_fee_installments.id` | nullable, null | `cascadeOnDelete`; null = the charge itself has no plan |
| `student_id` | FK `students.id` | not null | `cascadeOnDelete`; denormalised for the student-panel scope and for "how many reminders did this student get" |
| `branch_id` | FK `branches.id` | nullable, null | `nullOnDelete`, D11 |
| `type` | string(32) | not null | cast `FeeReminderType` (§3) |
| `channel` | string(16) | `database` | `database` / `mail` - mail-ready per §97; the value actually used |
| `due_date` | date | not null | the due date the reminder was about - part of the dedupe key |
| `offset_days` | smallint | not null | signed: `+3` = three days before due, `0` = due today, `-5` = five days overdue |
| `amount_due` | decimal(15,2) | 0.00 | snapshot of the outstanding amount at send time, so the message can be reproduced |
| `recipient_email` | string(191) | nullable | snapshot |
| `recipient_phone` | string(32) | nullable | snapshot (no SMS in this phase; kept so a later channel needs no migration) |
| `notification_id` | char(36) | nullable | the `notifications.id` written by the Laravel notification, for the delivery trail |
| `sent_at` | timestamp | not null | |
| `sent_by` | FK `users.id` | nullable | `nullOnDelete` - null when the scheduler sent it, set for a manual send |
| `is_manual` | boolean | false | a staff member pressed "Send reminder now" |
| `run_uuid` | char(36) | nullable | one UUID per scheduled run, so "who did the 09:00 run reach" is answerable |
| `created_at` | timestamp | not null | **no `updated_at`** (written once), **no `deleted_at`** - an append-only log row under `CLAUDE.md` §3's soft-delete category rule, exactly as Phase 1 §1 treats `login_histories` and `activity_log`: cite **D19** (F-9.1), no local exception |

**Keys.**
`UNIQUE uq_sfr_dedupe(student_fee_id, student_fee_installment_id, type, due_date, offset_days)` - the
guard; a retried job, a second scheduler tick and a manual send on the same offset collapse onto one row
and the `UniqueConstraintViolationException` is caught and counted as `skipped_duplicate`. MariaDB ignores
NULLs in a unique index, so that index alone would **not** protect a charge without a plan
(`student_fee_installment_id IS NULL`) - the same trap the spine documents in §2.19. **[D18-1]** The
migration therefore adds the STORED generated column
`dedupe_line bigint unsigned AS (COALESCE(student_fee_installment_id, 0)) STORED` and the real index is
`UNIQUE uq_sfr_dedupe(student_fee_id, dedupe_line, type, due_date, offset_days)`: the guard bites for both
cases and the FK column stays honestly nullable. Writing `0` into the FK column itself is rejected as a
design - it would be a dangling foreign key. The migration writes the generated column as raw SQL and
**fails loudly** if the server rejects it (spine R-3), never silently falling back to a nullable guard.
`INDEX (student_id, sent_at)` student panel / "reminders sent" tab; `INDEX (type, sent_at)` reporting;
`INDEX (run_uuid)`; `INDEX (student_fee_installment_id)`; `INDEX (branch_id)`.
**CHECK** `chk_sfr_amount`: `amount_due >= 0`.

**Relationships.**
`StudentFeeReminder` belongsTo `StudentFee`, `StudentFeeInstallment`, `Student`, `Branch`, `User`
(`sender`). `StudentFee` hasMany `StudentFeeReminder`; `StudentFeeInstallment` hasMany
`StudentFeeReminder`; `Student` hasMany `StudentFeeReminder`.
**No pivot table and no `belongsToMany` is introduced by this phase.**

### 2.3 Columns on spine tables that Phase 18 writes, and the rule for each

Anything not in this table is written by the spine's own services (Phase 10) or never written at all.

| Column | Written by | Rule it must always satisfy |
|---|---|---|
| `student_fees.fee_number` | `StudentFeeService::issue()` | **Phase 5's** `App\Services\Finance\DocumentNumberService::next('institute.fee_record_prefix','institute.fee_record_next_number','%06d')` - reused, never re-created, and the pad is passed explicitly by this caller (D27, F-4.1) - inside the issuing transaction; `uq_sf_number` is the backstop, exactly one retry on 1062 |
| `student_fees.gross_amount` | `issue()` | `> 0` except an explicitly zero-amount charge, which is refused (use a discount, not a zero charge) |
| `student_fees.discount_amount` | `recomputeCaches()` | `= -1 * SUM(student_fee_discounts.amount WHERE NOT type->isScholarship())`, always `>= 0` |
| `student_fees.scholarship_amount` | `recomputeCaches()` | `= -1 * SUM(student_fee_discounts.amount WHERE type->isScholarship())`, always `>= 0` |
| `student_fees.net_amount` | `recomputeCaches()` | `Money::sub(Money::sub(gross, discount), scholarship)`; never negative (`chk_sf_discount_ceiling` is the backstop) |
| `student_fees.paid_amount` | `recomputeCaches()` | `= SUM(p.amount)` over its receipts **excluding** `status = voided` |
| `student_fees.refunded_amount` | `recomputeCaches()` | `= SUM(p.refunded_amount)` over the same non-voided receipts - derived from the payment-level cache the spine already maintains under lock, so there is only ever one interpretation of "refunded" |
| `student_fees.balance_amount` | `recomputeCaches()` | `= net - (paid - refunded)`; **may be negative** = advance |
| `student_fees.status` | `recomputeCaches()` / `markOverdue()` / `cancel()` / `reopen()` | `= deriveStatus()` of §6.4, and the resulting move must be a transition the spine's §2.18.1 table allows |
| `student_fees.due_date` | `recomputeCaches()` | **[D18-3]** with a live plan: the earliest unsettled line's `due_date`; when every line is settled: the last line's; with no plan: the date given at issue (or `issued_on + institute.fee_due_days`). One column drives the sweeper, the widgets and the student panel |
| `student_fees.has_installment_plan`, `.installment_count` | `buildInstallmentPlan()` / `rebuildInstallmentPlan()` | count of **live** (non-cancelled) lines; `has_installment_plan = count > 0` |
| `student_fees.collaborator_id` | `issue()` only | a **display snapshot** of `ReferralService::effectiveOn($student, $issue_date)?->collaborator_id`, written once at issue and never refreshed. The engine never reads it (spine §2.2) |
| `student_fees.batch_id` | `issue()` and `reassignBatch()` only | a **display / filter column**, never an economic one: repointing it moves no money, writes no discount or receipt, and never re-runs the commission engine (F-4.6). Audited with the calling screen's mandatory reason |
| `student_fees.cancelled_at/_by/cancellation_reason` | `cancel()` | reason mandatory; refused while any non-voided receipt exists |
| `student_fee_installments.*` | `buildInstallmentPlan()`, `rebuildInstallmentPlan()`, `redistributePlan()`, `waiveInstallment()`, `recomputeInstallment()`, `markOverdue()` | §6.3 and PI-1 |
| `student_fee_installments.paid_amount` | `recomputeInstallment()` | `= SUM(p.net_received_amount)` over non-voided receipts pointing at the line (the generated column, so a refund can never be forgotten). `paid_amount > amount` is **legal** (spine §2.3) and surfaces as an over-allocation chip |
| `student_fee_discounts.*` | `addDiscount()`, `reverseDiscount()`, `waiveInstallment()` | insert only; `amount` is the **signed delta to net** (reductions negative); `reason` mandatory; `idempotency_key` a ULID generated once per opened modal |
| `student_fee_payments.notes` / `.reference_no` / `.receipt_path` | the payment policy's narrow update path | nothing else on a payment row is ever updated (INV-8) |

### 2.4 One additive column on a spine table - **granted**

`student_fees.generation_key` string(64) nullable with `UNIQUE uq_sf_generation(generation_key)` is part of
the spine's own table and is created by **Phase 10's migration 01** (F-3.15, resolutions §3.2). The
generators are therefore duplicate-proof **by INSERT, never by SELECT**: the generator composes the key
(`structure:{admission}:{head}` / `monthly:{admission}:{YYYY-MM}`), inserts, and treats a 1062 as "already
generated" (§6.1.3). MariaDB ignores NULLs in a unique index, so an ad-hoc charge with no generation key
stacks freely. **There is no degraded SELECT-under-row-lock path in this contract** - the former [D18-2] is
withdrawn, because an INSERT guard beats a SELECT guard under concurrency (resolutions R7).

### 2.5 Relationship map (Phase 18 view)

```
StudentAdmission 1-n StudentFee            (student_fees.student_admission_id)
Student          1-n StudentFee            1-n StudentFeeInstallment
StudentFee       1-n StudentFeeDiscount    1-n StudentFeePayment   1-n StudentFeeReminder
StudentFeeInstallment 1-n StudentFeePayment ; 1-n StudentFeeReminder
StudentFeePayment     1-n PaymentReversal
StudentFeeDiscount    1-1 self (reverses / reversedBy, via reverses_discount_id)
StudentFee       1-n CollaboratorCommissionLedgerEntry   (read-only trail, by student_fee_id)
StudentFee       n-1 Course / Batch / Branch / Collaborator (display only)
```

---

## 3. Enums to add

All in `app/Enums/`, string-backed, `label()` + `color()` + `static options()`, per Phase 1 §2.
**The spine owns `StudentFeeType`, `StudentFeeStatus`, `InstallmentStatus`, `FeeDiscountType`,
`PaymentMethod`, `ReceivedPaymentStatus`, `ReversalType` and every commission enum - this phase adds no
case to them and redefines none.**

| Enum | Cases (values) | Extra members |
|---|---|---|
| `InstallmentInterval` | `monthly`, `fortnightly`, `weekly`, `custom` | `addTo(CarbonInterface $date, int $n): CarbonInterface` - `monthly` uses `addMonthsNoOverflow` so 31 Jan + 1 month is 28/29 Feb and never slips into March; `custom` throws (the UI supplies each date) |
| ~~`RemainderPlacement`~~ | **reused, not declared here** | `App\Enums\RemainderPlacement` (`first`, `last`, `largest`) is declared in **phase-01 §3** beside `Money::distribute()` (F-4.11, R3 - one class, one name). Phase 18 casts `institute.installment_remainder_placement` to it and calls `Money::distribute($amount, $parts, RemainderPlacement::Last)`; the deterministic paisa placement of §6.2 lives in that one tested function |
| `FeeReminderType` | `upcoming_due`, `due_today`, `overdue` | `offsetSign(): int`, `notificationClass(): string` |

---

## 4. PermissionRegistry additions

### 4.1 New module slug (`is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why |
|---|---|---|---|---|
| `fee_reminders` | `Institute` | `bell-alert` | `READ` + `create` + `LOGS` | "Tell a student they owe money" is a different act from editing a fee: a Receptionist may do it, a Course Coordinator may not edit money. No `edit` / `delete`: a sent reminder is a log row |

### 4.2 Abilities added to existing slugs (additive; the registry stays the only place names exist - D4)

| slug | Ability added | Why |
|---|---|---|
| `student_fee_payments` | **`approve`** | spine §6.6 row 6 and spine FT-39 both require "the module's `approve` ability" to post a receipt older than `finance.backdate_limit_days`, but spine §4.1 does not grant the module an `approve` ability. Adding it is the smallest fix and contradicts nothing (§12.2 Q2) |
| `student_fees` | `view_reports` | the collection desk's outstanding / overdue aggregates (§8.8) and the three dashboard widgets; Phase 23 reuses the same gate |

Unchanged and used as the spine defines them: `student_fees` = `CRUD_FULL` + `STATUS` + `MONEY`;
`installments` = `CRUD` + `STATUS`; `fee_discounts` = `READ` + `create` + `APPROVE` + `MONEY` + `LOGS`;
`student_fee_payments` = `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` + `LOGS`.

### 4.3 Portal permissions

Used as the spine §4.3 adds them: `student_portal.fees`, `student_portal.payments`. The Collaborator role
holds **no** `student_fees.*` / `installments.*` / `fee_discounts.*` / `student_fee_payments.*` permission,
so every screen in this phase 403s for a collaborator; §57's "total paid" reaches them only through the
spine's collaborator-panel student list.

---

## 5. SettingsRegistry additions

All ten keys go into Phase 2's **existing** `institute` group - no new group, no new table. Definitions
live in `App\Support\SettingsRegistry` (code), values in `settings` (DB), exactly as Phase 2 §2 requires.

| group.key | type | default | Meaning |
|---|---|---|---|
| `institute.fee_due_days` | number | `7` | default `due_date` offset when the admission, the batch or the wizard gives none |
| `institute.monthly_fee_due_day` | number | `5` | day of month for a generated `monthly_fee` charge, clamped to the month end |
| `institute.installment_remainder_placement` | select `first`\|`last` | `last` | §6.2's deterministic paisa placement ([D18-5]) |
| `institute.fee_overdue_grace_days` | number | `0` | days after `due_date` before the sweeper marks a charge or a line overdue; `0` reproduces the spine literally |
| `institute.discount_approval_required` | boolean | `true` | §78's "approved by"; makes the approver field mandatory and drives [D18-8] |
| `institute.discount_max_percentage` | decimal | `100.0000` | caps a percentage discount in the Form Request before `chk_sfd_pct` has to |
| `institute.fee_structure_fee_types` | multiselect(`StudentFeeType`) | `admission_fee, registration_fee, course_fee` | which heads the structure generator offers by default |
| `institute.auto_generate_fee_structure_on_admission` | boolean | `true` | §68's "registration -> fee collection" step runs automatically when an admission is confirmed |
| `institute.fee_slip_show_commission` | boolean | `false` | §41 vs §112: even with `collaborator_commissions.view_financial`, the slip's commission block prints only when this is on (§6.7.1) |
| `institute.fee_slip_footer_note` | textarea | `''` | printed footer (terms, refund-policy line, bank details) - mirrors `finance.invoice_footer_note` |

Validation rules come from the registry (`rulesFor('institute')`), so the Form Request needs no duplicate
list: `fee_due_days` / `monthly_fee_due_day` `integer|between:1,28`, `fee_overdue_grace_days`
`integer|between:0,60`, `discount_max_percentage` `numeric|between:0,100`,
`installment_remainder_placement` `in:first,last`, `fee_structure_fee_types` `array` of
`StudentFeeType` values.

Phase 2's and the spine's existing keys are used unchanged and **never redefined here**:
`institute.fee_receipt_prefix`, `institute.fee_receipt_next_number`, `institute.fee_record_prefix`,
`institute.fee_record_next_number`, `institute.installment_reminder_days`,
`institute.default_branch_id`, `finance.backdate_limit_days`, `finance.refund_approval_required`,
`finance.refund_approval_threshold`, and every `collaborator.*` commission key (base, approval mode,
hold days, commissionable fee types, overpayment, fixed release, minimum entry amount).

---

## 6. Services

Namespace `App\Services\Institute\`. Every money-adjacent method is one `DB::transaction()` using the
spine's lock order (**payment -> document -> entitlement -> wallet -> ledger rows ascending**; Phase 18
only ever reaches the first two), all arithmetic through `App\Support\Money` (bcmath, intermediate scale 6,
final half-up at 2 - INV-7), every event dispatched through `DB::afterCommit()` (INV-20), every
discretionary act logged with a mandatory reason through `LogsActivityWithContext::withReason()` (INV-24).

### 6.1 `StudentFeeService`

| Method | Guarantees | Events |
|---|---|---|
| `issue(IssueFeeData $data): StudentFee` | (spine contract) `net = gross - discount - scholarship` computed only by `Money`; a `fee_number` unique under concurrency; `gross > 0`; `fee_type` a valid `StudentFeeType`; `student_id` matches the admission's student; `branch_id` from the student (D11); `collaborator_id` snapshotted from `ReferralService::effectiveOn()`; status `pending`; **no installment, discount or payment side effect** | `StudentFeeIssued` |
| `generateStructure(StudentAdmission $a, FeeStructureData $d): FeeStructureResult` | one `student_fees` row per requested head whose amount `> 0`, in the fixed head order of §6.1.1; the admission's discount and scholarship copied as `student_fee_discounts` rows under the cascade of §6.1.2; **asserts `SUM(charges.net_amount) === $a->net_payable` exactly** and aborts the whole transaction otherwise; idempotent (§6.1.3); refused when the admission is cancelled, or when a charge already exists for that admission and head unless `replace_unpaid = true` | `StudentFeeStructureGenerated` + one `StudentFeeIssued` per charge |
| `generateMonthlyCharge(StudentAdmission $a, CarbonInterface $month, string $amount, ?int $dueDay): ?StudentFee` | one `monthly_fee` charge per (admission, calendar month); `due_date` = `$dueDay ?? institute.monthly_fee_due_day` clamped to the month end; returns `null` (not an exception) when the month already has one; refused for a month before the admission date or more than 12 months ahead | `StudentFeeIssued` |
| `addDiscount(StudentFee $f, DiscountData $d): StudentFeeDiscount` | (spine contract) append-only row; `amount` the **signed** delta (`fixed_discount`, `percentage_discount`, `scholarship`, `promotional_discount`, `referral_discount`, `waiver` negative; `correction` positive; `reversal` the opposite sign of its target); `percentage` stored **and** the computed `amount` stored so the arithmetic is never re-done; `reason` mandatory; `approved_by` + `approved_by_name` + `approved_at` recorded per §6.5; caches recomputed under the charge's `lockForUpdate`; `discount + scholarship <= gross` (DB CHECK `chk_sf_discount_ceiling`); **calls `redistributePlan()` when a live plan exists**; **never touches a payment, an entitlement or a ledger row** | `StudentFeeAdjusted` |
| `reverseDiscount(StudentFeeDiscount $d, string $reason): StudentFeeDiscount` | inserts a `reversal` row with `amount = -$d->amount` and `reverses_discount_id = $d->id`; `uq_sfd_reverses` makes a second reversal of the same row impossible; the original row is never edited; plan redistributed; needs `fee_discounts.approve` | `FeeDiscountReversed`, `StudentFeeAdjusted` |
| `waiveInstallment(StudentFeeInstallment $l, string $amount, string $reason): StudentFeeDiscount` | one `waiver` discount row for `$amount` (reducing net) **plus** `l.waived_amount += amount` and status `waived` when `waived >= amount`, in one transaction; refused when `$amount > l.amount - l.paid_amount - l.waived_amount`; keeps PI-1 without a redistribution (§6.3.4); needs `installments.change_status` **and** `fee_discounts.approve` (spine §2.18.2) | `InstallmentWaived`, `StudentFeeAdjusted` |
| `buildInstallmentPlan(StudentFee $f, array $lines): Collection` | (spine contract) refuses a plan whose line sum `<> net_amount`; `installment_no` unique (`uq_sfi_no`); refuses to rebuild a line that already holds money; **[D18-4]** refuses entirely when `paid_amount - refunded_amount <> 0`; every line `amount > 0`; due dates strictly ascending; `2 <= count <= 60` | `InstallmentPlanBuilt` |
| `rebuildInstallmentPlan(StudentFee $f, array $lines, string $reason): Collection` | cancels every **unpaid** live line (status `cancelled`, mandatory reason); keeps paid / partial / waived lines with their numbers; new lines numbered from `MAX(installment_no) + 1` - **numbers are never reused and never renumbered** [D18-6]; asserts PI-1 afterwards | `InstallmentPlanBuilt` |
| `redistributePlan(StudentFee $f, string $signedDelta, string $reason): Collection` | §6.3.3; internal - only `addDiscount`, `reverseDiscount` and `recomputeCaches` may call it; asserts PI-1 before returning | `InstallmentPlanRedistributed` |
| `cancel(StudentFee $f, string $reason): StudentFee` | (spine contract) refused when any non-voided receipt exists (the correct act is a refund); reason mandatory; unpaid lines cancelled with the same reason; discounts untouched (append-only) | `StudentFeeCancelled` |
| `reopen(StudentFee $f, string $reason): StudentFee` | `cancelled -> pending` per spine §2.18.1, reason mandatory; clears `cancelled_at/_by`; recomputes caches so a past due date lands straight on `overdue` | `StudentFeeIssued` (re-opened flag in the payload) |
| `recomputeCaches(StudentFee $f): void` | the six cache columns of §2.3 always equal the sum of their rows, recomputed under the charge's row lock; `status = deriveStatus()`; `due_date` per [D18-3]; idempotent - running it twice changes nothing | - |
| `recomputeInstallment(StudentFeeInstallment $l): void` | `paid_amount` from its receipts' `net_received_amount`; `paid_on` = the `paid_on` of the receipt that completed it; status per §6.4.2 | - |
| `markOverdue(CarbonInterface $asOf, int $limit = 1000): OverdueSweepResult` | §6.6; idempotent; never touches `cancelled`, `paid`, `refunded` or a charge whose balance is `<= 0` | `StudentFeeMarkedOverdue` per row |
| `deriveStatus(StudentFee $f): StudentFeeStatus` | pure, no writes - the **single** definition of a fee status (§6.4.1), called by the payment path, the discount path and the sweeper so they can never disagree | - |
| `summaryFor(Student\|StudentAdmission $subject): FeeSummary` | **published for other phases (F-4.6)**, read-only, writes nothing: returns `App\DataObjects\Institute\FeeSummary` (readonly `string $gross`, `$discount`, `$scholarship`, `$net`, `$paid`, `$refunded`, `$balance`, `StudentFeeStatus $status`, `?CarbonImmutable $nextDueDate`) aggregated over the subject's non-cancelled charges from the §2.3 cache columns only - so phases 14-17 and 19-23 render a fee position **without summing money themselves**. Every figure is a `Money` string, never a float | - |
| `outstandingFor(StudentAdmission\|StudentBatchEnrollment $subject): string` | **published (F-4.6)**, read-only: the single outstanding figure (`SUM(balance_amount)` over live charges, floored at `0.00` for display but returned signed so an advance is visible), as a `Money` string. The one number an enrollment, exam-eligibility or certificate screen may ask for | - |
| `withinServiceContext(): bool` | **published (F-4.6)**: true while the call stack is inside a `StudentFeeService` transaction. Callers that must not be re-entrant (a cache recompute triggered by a listener, a plan assertion) check it instead of guessing; it is the fee-side twin of `PaymentService::isWriting()` | - |
| `reassignBatch(StudentBatchEnrollment $from, Batch $to): int` | **published (F-4.6)**: repoints `student_fees.batch_id` (a display / filter column) for the subject's charges and returns the number of rows touched. **It writes no money**: not `amount`, not a discount, not a receipt, not an installment line, and it never re-runs the commission engine; a batch change is not an economic event. Audited with the mandatory reason of the calling screen | `StudentFeeAdjusted` (batch-only payload) |
| `transferPayment(StudentFeePayment $p, StudentFee $to, string $reason): PaymentResult` | (spine contract) a `cancellation` reversal on the source charge plus a new receipt on the target carrying **the original `paid_on`**, so the rule version and the economics are preserved and the net commission effect is about zero; the carried amount is excluded from the target's collectible; refused across students; needs `payment_reversals.create` + `student_fee_payments.create` | `PaymentReversalRecorded`, `StudentFeePaymentRecorded` |

**6.1.1 Head order.** `admission_fee`, `registration_fee`, `course_fee`, then any extra head passed
explicitly. Fixed, so two runs of the generator produce the same `fee_number` sequence and the same slip.

**6.1.2 Where the admission's discount and scholarship land.** Both are copied onto the **`course_fee`**
charge as one `fixed_discount` row and one `scholarship` row (`effective_on = admission_date`,
`reason = "copied from admission #{number}"`, `approved_by` = the admission's approver when Phase 15
supplies one). If either exceeds that charge's gross, the remainder cascades in the fixed order
`registration_fee -> admission_fee`; if it still does not fit, the generator **aborts** with a named
validation error rather than write a discount the CHECK would reject. One cascade order means
`SUM(net) = net_payable` is reproducible.

**6.1.3 Duplicate-proof generation - the INSERT is the check (F-3.15).** Both generators compose
`generation_key` (`structure:{admission}:{head}` for a fee-structure head, `monthly:{admission}:{YYYY-MM}`
for a monthly charge) and write it to `student_fees.generation_key`, which carries
`UNIQUE uq_sf_generation` from Phase 10's migration 01 (§2.4). They then **INSERT and treat a 1062
(`UniqueConstraintViolationException`) as "already generated"**: the existing charge is re-read and
returned with `created: false`. There is **no SELECT-then-insert existence check and no row-lock guard**
anywhere in the generation path - the former [D18-2] degraded path is withdrawn, because under concurrency
an INSERT guard is a guarantee and a SELECT guard is a hope (resolutions R7). A double-clicked wizard, a
retried job and two concurrent workers all collapse onto one set of charges.

### 6.2 `InstallmentPlanCalculator` - exact, deterministic, never a float

```
generate(string $netAmount, int $count, CarbonInterface $firstDueOn,
         InstallmentInterval $interval, RemainderPlacement $placement): array<InstallmentLine>
redistribute(array $liveUnpaidLines, string $signedDelta, RemainderPlacement $placement): array
```

**Amounts - integer paisa arithmetic, so the sum is exact by construction:**

```
total  = Money::toMinor($netAmount)            // bcmul($net,'100',0) - exact, the column is decimal(15,2)
guard    count >= 2 AND count <= 60
guard    total >= count                        // else ValidationException "net fee too small for N installments"
q = bcdiv(total, count, 0)                     // integer quotient
r = bcmod(total, count)                        // 0 <= r < count
lines[i] = q  for i in 1..count
Money::distribute() places the r remainder paisa per RemainderPlacement  // last: the final r lines; first: the first r lines; largest: the r largest
amounts[i] = Money::fromMinor(lines[i])
assert Money::compare(Money::sum(amounts), $netAmount) === 0        // INVARIANT P-1, asserted in code, not hoped for
assert every amount > 0
```

Worked: `10,000.00 / 3` -> `3,333.33 · 3,333.33 · 3,333.34` (last) or `3,333.34 · 3,333.33 · 3,333.33`
(first); `25,000.00 / 7` -> six lines `3,571.42` and a final `3,571.48`? **no** - `2,500,000 / 7 =
357,142 r 6`, so one line of `3,571.42` and six of `3,571.43`, sum `25,000.00`. `0.05 / 5` -> five lines of
`0.01`. `0.04 / 5` -> refused (`total < count`).
No division ever produces a repeating decimal that has to be rounded, so there is no rounding drift to
distribute - only an integer remainder of at most `count - 1` paisa, placed deterministically by the
setting, through Phase 1's `Money::distribute($amount, $parts, RemainderPlacement)` - the only function
that may split money (F-4.11). `RemainderPlacement::Last` is the default ([D18-5], §12.2 Q5).

**Due dates:** `line[i].due_date = interval->addTo($firstDueOn, i - 1)`; strictly ascending by
construction; `custom` takes the caller's dates and asserts ascending. A first due date before today is
allowed (the line is immediately overdue) and the wizard warns.

**Redistribution** (`$signedDelta` negative = net went down): consume the delta from live **unpaid** lines
in **reverse due-date order** (latest first), in paisa, reducing each to at most zero; a line that reaches
zero is `cancelled` with the reason; a positive delta is added to the latest live unpaid line, or becomes a
new line numbered `MAX + 1` when none is left. The unconsumed remainder of a negative delta is **not**
forced onto a paid line - it simply leaves the charge with a negative `balance_amount` (an advance), which
the screen states in words.

### 6.3 Plan integrity

**PI-1 (asserted at the end of every transaction that touches a plan, a discount or a waiver):**

```
SUM(amount) over lines WHERE status <> 'cancelled'
  - SUM(waived_amount) over the same lines
  == student_fees.net_amount
```

A violation throws `InstallmentPlanOutOfBalance` and rolls the transaction back. `fees:verify-plan-integrity`
(§10.4) re-asserts it nightly for every charge with a live plan, reporting rather than repairing - the same
discipline as the spine's wallet reconciliation.

**6.3.4 Why a waiver needs no redistribution.** A waiver reduces `net_amount` by exactly the amount it
parks in `waived_amount` on one line, so both sides of PI-1 move together. A discount that is *not* tied
to a line moves only the right-hand side, which is precisely why `addDiscount()` must redistribute.

### 6.4 The four fee statuses

**6.4.1 `deriveStatus()` - evaluated in this order, first match wins.** `nr = paid_amount - refunded_amount`
(both over non-voided receipts); `overdue_line` = `due_date < asOf - institute.fee_overdue_grace_days`.

| # | Condition | Status | Requirement status |
|---|---|---|---|
| 1 | `cancelled_at` is not null | `cancelled` | (system) |
| 2 | `net_amount = 0` and `nr = 0` | `paid` | **Paid** - nothing to collect (a fully scholarshiped charge) |
| 3 | `nr > net_amount` | `overpaid` | (system; spine §6.6 row 5) |
| 4 | `nr = net_amount` and `net_amount > 0` | `paid` | **Paid** |
| 5 | `nr = 0` and `refunded_amount > 0` | `refunded` | (system) - reachable only with a real refund; a **voided** receipt is excluded from both legs, so a void returns the charge to row 6/7 instead, which is the honest answer ("that receipt never counted") |
| 6 | `0 < nr < net_amount` | `overdue` if `overdue_line` else `partial` | **Overdue** / **Partial** |
| 7 | `nr = 0` | `overdue` if `overdue_line` else `pending` | **Overdue** / **Pending** |

Every move this produces is a transition spine §2.18.1 permits; the sweeper is still the only thing that
*introduces* `overdue` on a quiet charge, and a receipt landing on an overdue charge moves it to
`partial` / `paid` through the same function. §76's four statuses are rows 4, 6 and 7; `overpaid`,
`cancelled` and `refunded` are the spine's three system states and are rendered with their own badge
colour so a user never mistakes one for another.

**6.4.2 Installment line status.** `settled = paid_amount + waived_amount`.
`cancelled` -> `cancelled`; `waived_amount >= amount` -> `waived`; `settled >= amount` -> `paid` (+`paid_on`);
`settled > 0` -> `overdue` if past due + grace else `partial`; else -> `overdue` if past due + grace else
`pending`. A reversal that drops `settled` below `amount` walks `paid -> partial` (spine §2.18.2).

### 6.5 Discounts, scholarships and their approver (§78)

| `FeeDiscountType` | How `amount` is computed | Cache it feeds | Approval |
|---|---|---|---|
| `fixed_discount` | `-$input` | `discount_amount` | `fee_discounts.create`; approver required when `institute.discount_approval_required` |
| `percentage_discount` | `percentage` stored **and** `amount = -Money::percentage(gross_amount, percentage)` stored | `discount_amount` | same; `percentage <= institute.discount_max_percentage` and `<= 100` (`chk_sfd_pct`) |
| `scholarship` | `-$input` (or `-Money::percentage(gross, pct)`) | **`scholarship_amount`** via `FeeDiscountType::isScholarship()` | same |
| `promotional_discount` | `-$input` | `discount_amount` | same |
| `referral_discount` | `-$input` | `discount_amount` | same. **It is a discount to the student, not a collaborator payment**: it never creates a ledger row, and its only commission effect is the smaller net fee under a non-`paid` base |
| `waiver` | `-$input`, always paired with a line (§6.1) | `discount_amount` | `installments.change_status` + `fee_discounts.approve` |
| `correction` | `+$input` | reduces `discount_amount` | `fee_discounts.approve`; **guard: the signed sum of each bucket must stay `<= 0`**, so a correction can only undo an over-discount and can never push net above gross |
| `reversal` | `-1 *` the target row's amount | the target's bucket | `fee_discounts.approve`; `reverses_discount_id` set; at most one per row (`uq_sfd_reverses`) |

Every row carries `reason` (mandatory), `effective_on` (business date, defaulting to today, never in the
future), `approved_by` + `approved_by_name` + `approved_at`, `idempotency_key` (one ULID per opened modal,
`uq_sfd_idem` makes a double-submitted form impossible), `created_by`. No row is ever edited or deleted
(`trg_sfd_no_delete`). **[D18-8]** When `institute.discount_approval_required` is true the approver must
hold `fee_discounts.approve`, and the Form Request refuses `approved_by === created_by` unless the actor
also holds `fee_discounts.approve` - the same segregation the spine applies to payouts, stated rather than
assumed (§12.2 Q8).

### 6.6 Overdue detection (`fees:mark-overdue`, spine §10.4, implemented here)

One chunked pass, `$limit` rows per run, driven by `INDEX (due_date, status)` on the charge and
`INDEX (due_date, status)` on the line - the sweeper must never table-scan:

```
lines:   WHERE status IN ('pending','partial') AND due_date < :asOf - grace
         AND (amount - paid_amount - waived_amount) > 0          -> status = overdue
charges: WHERE status IN ('pending','partial') AND due_date < :asOf - grace
         AND balance_amount > 0                                   -> status = overdue (via deriveStatus)
```

Idempotent (a row already `overdue` is not rewritten and produces no event), skips `cancelled` / `paid` /
`refunded` / `overpaid` and any charge whose balance is `<= 0`, and writes one `activity_log` row per run
(count + the ids) rather than one per charge, so a 2,000-row night does not flood the log.

### 6.7 `FeeSlipBuilder` - the printable documents (§41, §76, §77)

| Method | Returns | Rules |
|---|---|---|
| `forCharge(StudentFee $f, FeeSlipOptions $o): FeeSlipData` | one charge's slip | §41's fields; installment schedule when a plan exists; payment history; commission block only per §6.7.1 |
| `forAdmission(StudentAdmission $a, FeeSlipOptions $o): FeeSlipData` | the **fee structure slip**: every charge of the admission with a grand total | `SUM(net) = net_payable` printed as a proof line |
| `forReceipt(StudentFeePayment $p, ReceiptOptions $o): ReceiptData` | one receipt | §6.7.2 |

**6.7.1 The commission block (§41 vs §112).** `commissionable amount`, `commission percentage` and
`commission amount` are read **at print time** from the entitlement and the ledger rows of this charge
(spine §8.10) - never from a duplicated column - and are rendered **only** when all three hold: the viewer
has `collaborator_commissions.view_financial`, `institute.fee_slip_show_commission` is true, and the
request did not come from the student panel. The student's copy prints the collaborator's **name** (§41
lists the collaborator) and never a rate or an amount. The builder returns the block as `null`, not as
zeroes, when it is withheld, and the student-panel route passes `FeeSlipOptions::studentCopy()` which
cannot be overridden by a query parameter.

**6.7.2 Receipt contents.** Receipt no · `paid_on` (value date) and `recorded_at` (system date, labelled)
· student (name, student id, registration no) · course / batch · charge `fee_number` and head ·
installment no when allocated · amount received (figures) · method + `reference_no` · received by ·
**"balance as at {print datetime}"** recomputed at print time and explicitly dated, so a receipt reprinted
months later never appears to state the balance of the day it was issued · notes. Two copies per A4
(Student copy / Office copy). A reprint is allowed, is stamped **REPRINT** with the print count, and
writes an `activity_log` row (§106). A receipt whose payment is `voided` prints with a **VOID** watermark
and the reversal number; a partially refunded receipt prints the refunded amount and
`net_received_amount`. No receipt is ever re-numbered.

### 6.8 `FeeReminderService`

`queueDueReminders(CarbonInterface $asOf, ?int $limit = null): ReminderRunResult` - for each offset in
`institute.installment_reminder_days` (Phase 2), find live lines whose `due_date` equals
`asOf + offset` (`upcoming_due`), equals `asOf` (`due_today`), or is before `asOf` at the configured
overdue cadence (`overdue`), insert the `student_fee_reminders` row **first** and send the notification
only when the INSERT was the winner; a 1062 increments `skipped_duplicate`. One `run_uuid` per run,
chunked 500, skipped entirely when the student has no notifiable user. `sendNow(StudentFee|StudentFeeInstallment, FeeReminderType, User $actor)`
is the manual path (`fee_reminders.create`), marked `is_manual`, subject to the same unique guard so
"Send reminder now" twice in a minute sends once.

### 6.9 What Phase 18 services must never do (asserted by PH18-37)

No class under `App\Services\Institute\` may: insert or update
`collaborator_commission_ledger_entries`, `collaborator_commission_entitlements`, `collaborator_wallets`,
`collaborator_referrals`, `student_fee_payments` or `payment_reversals`; write any `commission_*` column on
a payment; call `StudentCommissionService`, `CommissionReversalService` or `LedgerWriter` directly; or
compute a balance with its own `SUM(` over the ledger (INV-26). Money in and money out go through the
spine's `PaymentService`; commission happens in the spine's jobs.

---

### 6.10 The commission integration - requirement 39 to 44

#### 6.10.1 Exactly where a payment is recorded

| Step | Code | Notes |
|---|---|---|
| 1 | `Admin\FeePaymentController@store` (route `admin.fee-payments.store`, spine §7.1) | the **only** student-side money-in entry point in the whole application; Phase 18 adds no second one |
| 2 | `RecordStudentFeePaymentRequest` (spine/Phase 10) | `amount > 0`; `paid_on` not in the future and within `finance.backdate_limit_days` unless the actor holds `student_fee_payments.approve` (§4.2); `student_fee_installment_id` belongs to this charge; `idempotency_key` ULID present; `confirm_duplicate` required when `duplicate_fingerprint` matches; `confirm_over_allocation` required when the amount exceeds the selected line's remainder (the DB deliberately permits it - spine §2.3) |
| 3 | `PaymentService::recordStudentFeePayment(StudentFee, RecordPaymentData)` | **transaction A**, §6.10.2 |
| 4 | `DB::afterCommit()` dispatches `ProcessStudentFeeCommission` | never inside transaction A; `$afterCommit = true` on the job as well, so a worker can never read a payment that later rolls back |
| 5 | `ProcessStudentFeeCommission` -> `StudentCommissionService::handlePayment()` | **transaction B** = the spine's §2.19 boundary, verbatim |

#### 6.10.2 Transaction A - the cash, owned by the spine's `PaymentService` (restated so the implementer can follow it)

1. `StudentFee::whereKey()->lockForUpdate()` - the document lock, first in the spine's lock order.
2. The selected installment line `lockForUpdate()` (when one was chosen).
3. Re-validate under the lock: the charge is not `cancelled`; `paid_on` window; the line's remainder.
4. Phase 5's `DocumentNumberService::next('institute.fee_receipt_prefix','institute.fee_receipt_next_number','%06d')`
   - `SELECT ... FOR UPDATE` on the counter row **inside this transaction**, so a rollback releases the
   number too: receipt numbers are **gapless** except for receipts that were committed and later voided,
   which keep their number for ever. `uq_sfp_receipt` is the backstop, exactly one retry on 1062.
5. INSERT the `student_fee_payments` row - `amount`, `paid_on`, `recorded_at`, `payment_method`,
   `reference_no`, `status = cleared`, `idempotency_key`, `duplicate_fingerprint`, `received_by` +
   `received_by_name`, and the resolved `collaborator_id` / `collaborator_referral_id` snapshot from
   `ReferralService::effectiveOn($student, $paid_on)`. A `uq_sfp_idem` violation is caught, the existing
   receipt re-read, and `PaymentResult{created: false}` returned - a replayed POST is idempotent end to end.
6. `commission_state = queued` (a progress hint and sweeper index only - **never** a duplicate guard).
7. `StudentFeeService::recomputeInstallment()` then `recomputeCaches()` - line cache and status, charge
   caches, `deriveStatus()`, `due_date`.
8. COMMIT. Then, after commit only: `StudentFeePaymentRecorded`, the student's `FeePaymentReceived`
   notification, and the commission job.

Phase 18 owns steps 7's arithmetic and nothing else in this list.

#### 6.10.3 Transaction B - the commission, owned by the spine (spine §2.19 and spine §6.1)

Inside `ProcessStudentFeeCommission` (`ShouldBeUnique` `student-fee-payment:{id}`, `uniqueFor` 3600,
`tries` 5, `backoff [10,30,60,120,300]`), one `DB::transaction(..., attempts: 3)`:

1. lock the **payment** row; return early when `commission_state = processed` (a fast path, not the
   guarantee);
2. guards 1-7 of spine §6.1 - referral system enabled, automatic commission enabled, payment `cleared`,
   a referral effective on **`paid_on`**, referral commission-eligible, collaborator Active and not
   trashed, a rule effective on `paid_on` and enabled, the **fee type commissionable**, amount above the
   rule's floor. The first failure writes `commission_state = skipped` + a `CommissionSkipReason` enum +
   a human `commission_skip_detail` on the payment row and returns. **No ledger row, and never a zero row**
   (INV-2, §120.1);
3. lock the **document** (`student_fees`, or `student_admissions` when the grain is the admission) and read
   the base figures - never a cached `paid_amount`;
4. `CommissionEntitlementService::openOrLoad()` -> `firstOrCreate` + `lockForUpdate`;
5. compute with `Money` (spine §6.1.6 and spine §6.1.7);
6. `LedgerWriter::post()` inside a SAVEPOINT - `uq_cle_dedupe`
   (`student_fee_payment:{id}:student_commission:collab:{id}`) and
   `uq_cle_source(source_type, source_id, collaborator_id, purpose)` make the same receipt paying twice
   physically impossible (§120.3);
7. conditional UPDATE of the entitlement caches (`chk_cce_cap` is the ceiling);
8. wallet row `lockForUpdate`, signed delta, `version + 1`;
9. stamp `commission_state = processed`, `commission_processed_at`.

Events and notifications fire only through `DB::afterCommit()`. If the worker dies between COMMIT and the
stamp, `commissions:sweep` re-queues the row and the unique index makes the retry a no-op. In a `sync`
queue (local dev) the job still runs **after** transaction A commits, because the dispatch is
`afterCommit` - so the two boundaries never merge.

**Phase 18 must not make the commission inline.** The fee screens show the outcome (the commission-state
badge and the skip reason), and the record-payment modal shows a read-only **preview** from
`PaymentService::dryRun()` before posting. A preview writes nothing.

#### 6.10.4 The commissionable amount per configured base (student side only)

`base = rule.base_override ?? setting('collaborator.student_commission_base')`, default **`paid`** (§41).
Document grain from `collaborator.student_commission_document` (default `admission`, falling back to
`student_fee` when the charge has no admission). Figures come from the locked document row.

| Base | `document_base_amount` | `collectible_amount` (release denominator) | What one receipt earns |
|---|---|---|---|
| `paid` (default) | admission `net_payable` / charge `net_amount` - used **only** as the overpayment cap | the same figure | `rate x min(payment.amount, collectible - collected)`; uncapped entitlement (`entitlement_amount` NULL) - one receipt, one percentage (§40, §42) |
| `net_after_discount` | admission `net_payable` / charge `net_amount` | the same figure | promise `= rate x net`; this receipt releases `cumulative_target - released_before` (spine branch C), so the releases sum to the promise exactly and never run ahead of collection (§43) |
| `gross` | admission `course_fee` / charge `gross_amount` | admission `net_payable` / charge `net_amount` | promise `= rate x gross`, released in proportion to money collected against the **net** - the interpretation recorded in spine R-10 and in every row's `rule_snapshot` |
| fixed amount | the same document figures | the same | `fixed_release` = `prorated` (default): the fixed amount spread in proportion to collection, residual on the final receipt (2,000 over 3 x 10,000 = 666.67 + 666.66 + 666.67); `on_first_payment`; or `per_payment` (uncapped) |

`base_amount` is capped at the remaining collectible unless `collaborator.commission_on_overpayment` is
true, so a 35,000 receipt on a 25,000 net charge gives `base_amount 25,000.00`, credit `2,500.00`, and
`gross_amount 35,000.00` on the row so the gap is visible. A commissionable amount of zero produces **no
row** (`overpayment_only` / `base_zero`), as do a computed amount that rounds to `0.00`
(`rounds_to_zero`) and one below `collaborator.commission_min_entry_amount`.

Fee-type commissionability: the allowed set is `rule.applies_to_fee_types` when set, otherwise
`collaborator.commissionable_fee_types` (default `course_fee, monthly_fee, installment`); `admission_fee`
and `registration_fee` are additionally gated by `collaborator.commission_on_admission_fee` /
`commission_on_registration_fee`. An `exam_fee`, `certificate_fee` or `other` charge earns nothing unless
an admin adds it to the set - the skip reason `fee_type_not_commissionable` says so on the receipt row.

#### 6.10.5 Refund, void and reversal - the negative commission path

| Act | Phase 18 screen | Spine call | Commission effect |
|---|---|---|---|
| Partial refund | charge detail -> receipt row -> Refund (reason mandatory) | `PaymentService::refund($p, new RefundData(amount: $amount, reason: $reason, type: ReversalType::PartialRefund, method: $method))` | `ProcessCommissionReversal` posts a `reversal` **debit** of `round(E.amount x cumulative_refunded / payment.amount) - already_undone`, so any number of partial refunds sums to at most the original commission, never a paisa more |
| Full refund | same | `refund($p, new RefundData(amount: $amount, reason: $reason, type: ReversalType::FullRefund))` | the debit equals the residual; when `allocated_amount = 0` and `reversed_amount = amount`, the entry **and all of its reversal rows** move to `reversed` (§120.5) |
| Void a mis-keyed receipt | charge detail -> Void (the only correction path) | `PaymentService::void($p, $reason)` | full-amount reversal, payment `-> voided`, full commission reversal; the charge returns to `pending` / `overdue` (§6.4.1 row 5 note) and the corrected receipt is re-entered with a new idempotency key |
| Bounced cheque | same modal, type `bounced_instrument` | `refund($p, new RefundData(..., type: ReversalType::BouncedInstrument))` | identical mechanics, separate reporting bucket |
| Refund of commission already paid out | - | spine §6.3.5 | a `clawback` debit; the original stays `paid`; the wallet may go legitimately negative. Nothing in Phase 18 blocks a refund because a payout is pending - the allocation is released first |
| Approval required | the refund modal states it | `finance.refund_approval_required` / `_threshold` | `approval_status = pending` and **no commission reversal runs until approved**; a rejection rolls the `refunded_amount` increment back in the same transaction and fires the spine's `PaymentReversalRejected` afterwards (F-4.9), on which this phase's charge caches recompute |

**Every refund call passes the DTO, never positionals (F-4.2).** The spine publishes exactly one form,
`refund(StudentFeePayment|ProjectPayment $p, RefundData $data): PaymentReversal`, with
`App\DataObjects\Finance\RefundData` (readonly `string $amount`, `string $reason`, `ReversalType $type`,
`?string $method = null`, `?string $idempotencyKey = null`, `?CarbonInterface $refundedOn = null`). Two
adjacent `string` positionals - an amount and a reason - are swappable at the call site, and a swapped pair
is a silent money bug, so named arguments on the DTO are the contract and no Phase 18 screen may use any
other shape.

Phase 18's own side effects of a reversal, all inside the spine's transaction via its cache hooks:
the payment's status per spine §2.18.3, `recomputeInstallment()` (a line may walk `paid -> partial`),
`recomputeCaches()` (the charge may walk `paid -> partial -> refunded`, or back to `overdue`), and the
student's notification. **No financial row is deleted, ever** (INV-5; `trg_sfp_no_delete`,
`trg_pr_no_delete`, `trg_sfd_no_delete`).

---

## 7. Routes

Admin routes carry `auth`, `active`, `panel:admin` from Phase 1 §8; student routes carry `panel:student`.
Rows marked **(spine)** already exist in spine §7.1 / §7.6 and are restated only so this phase is
implementable from one document - **do not redeclare them**.

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/student-fees` **(spine)** | `admin.student-fees.index` | `module:student_fees`, `can:student_fees.view_any` |
| GET `/admin/student-fees/create` **(spine)** | `admin.student-fees.create` | `can:student_fees.create` |
| POST `/admin/student-fees` **(spine)** | `admin.student-fees.store` | `can:student_fees.create` |
| GET `/admin/student-fees/{fee}` **(spine)** | `admin.student-fees.show` | `can:student_fees.view` |
| POST `/admin/student-fees/{fee}/cancel` **(spine)** | `admin.student-fees.cancel` | `can:student_fees.change_status` |
| POST `/admin/student-fees/{fee}/reopen` | `admin.student-fees.reopen` | `can:student_fees.change_status` |
| GET `/admin/student-fees/{fee}/slip` | `admin.student-fees.slip` | `can:student_fees.print` |
| GET `/admin/student-fees/export/{format}` | `admin.student-fees.export` | `can:student_fees.export` |
| GET `/admin/admissions/{admission}/fee-structure/preview` | `admin.fee-structures.preview` | `module:student_fees`, `can:student_fees.create` (writes nothing) |
| POST `/admin/admissions/{admission}/fee-structure` | `admin.fee-structures.store` | `can:student_fees.create`, `throttle:10,1` |
| GET `/admin/admissions/{admission}/fee-structure/slip` | `admin.fee-structures.slip` | `can:student_fees.print` |
| POST `/admin/student-fees/generate-monthly` | `admin.student-fees.generate-monthly` | `can:student_fees.create`, `throttle:5,1` |
| GET `/admin/student-fees/{fee}/installments/preview` | `admin.installments.preview` | `can:installments.create` (writes nothing) |
| POST `/admin/student-fees/{fee}/installments` **(spine)** | `admin.student-fees.installments.store` | `can:installments.create` |
| PUT `/admin/student-fees/{fee}/installments` | `admin.student-fees.installments.rebuild` | `can:installments.edit` |
| POST `/admin/installments/{installment}/waive` **(spine)** | `admin.installments.waive` | `can:installments.change_status` (+ `fee_discounts.approve` in the policy) |
| POST `/admin/student-fees/{fee}/discounts` **(spine)** | `admin.student-fees.discounts.store` | `can:fee_discounts.create` |
| POST `/admin/fee-discounts/{discount}/reverse` **(spine)** | `admin.fee-discounts.reverse` | `can:fee_discounts.approve` |
| GET `/admin/fee-collection` | `admin.fee-collection.index` | `module:student_fees`, `can:student_fees.view_reports` |
| GET `/admin/student-fees/{fee}/payments/preview` **(spine)** | `admin.fee-payments.preview` | `can:student_fee_payments.create` (dry run) |
| POST `/admin/student-fees/{fee}/payments` **(spine)** | `admin.fee-payments.store` | `can:student_fee_payments.create`, `throttle:20,1` |
| GET `/admin/fee-payments/{payment}/receipt` **(spine)** | `admin.fee-payments.receipt` | `can:student_fee_payments.print` |
| POST `/admin/fee-payments/{payment}/void` **(spine)** | `admin.fee-payments.void` | `can:student_fee_payments.change_status` |
| POST `/admin/fee-payments/{payment}/refund` **(spine)** | `admin.fee-payments.refund` | `can:payment_reversals.create` |
| POST `/admin/student-fees/{fee}/reminders` | `admin.fee-reminders.store` | `module:fee_reminders`, `can:fee_reminders.create`, `throttle:30,1` |
| GET `/admin/fee-reminders` | `admin.fee-reminders.index` | `module:fee_reminders`, `can:fee_reminders.view_any` |
| GET `/student/fees` **(spine)** | `student.fees.index` | `panel:student`, `can:student_portal.fees` |
| GET `/student/fees/{fee}` **(spine)** | `student.fees.show` | `can:student_portal.fees` + policy ownership (**404**, never 403) |
| GET `/student/fees/{fee}/slip` | `student.fees.slip` | `can:student_portal.fees` + policy ownership; forced `studentCopy()` |
| GET `/student/payments/{payment}/receipt` **(spine)** | `student.payments.receipt` | `can:student_portal.payments` + policy ownership |

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 on every screen: `x-ui.*` components only, search +
filters + sortable headers + pagination + empty state + skeleton loader on every list, money
right-aligned with `tabular-nums` through `money()`, a toast on every write, `x-ui.confirm` with a
**mandatory reason field** on every irreversible action, tables inside `overflow-x-auto`, light and dark.
**No Kanban and no calendar in this phase** - §76-78 ask for neither (the requirement's Kanbans are leads
§18 and tasks §22), so a fee board would be invented scope. The wizards below are the three the
requirement implies: generating a structure (§68, §69), building a plan (§77), and taking money (spine
§8.1).

### 8.1 Charges index - `admin.student-fees.index`

**Purpose.** The institute's money-owed list.
**Components.** `x-ui.page-header`, `x-ui.stat-card` x4 (net billed, collected, outstanding, overdue -
over the filtered set), `x-ui.filter-bar`, `x-ui.table`, `x-ui.th-sortable`, `x-ui.badge`,
`x-ui.pagination-summary`, `x-ui.empty-state`, `x-ui.skeleton`.
**Filters.** Date range (issue date / due date - the toggle states which), status (the seven, with the
four requirement ones first), fee type, course, batch, branch, collaborator (referred / not referred),
"has installment plan", "has discount", "has refund", amount range, overdue bucket (1-7 / 8-30 / 30+
days), free text on fee number / student name / registration no.
**Columns.** Fee # · student (name + student id) · course / batch · head (`fee_type` badge) · gross ·
discount · scholarship · **net** · paid · **balance** · due date (rose when overdue, with the day count) ·
status badge · plan chip (`3/5 paid`) · row actions (view, slip, collect, discount, cancel / reopen).
**Footer.** Sum row for gross, discount, scholarship, net, paid, balance over the filtered set, labelled
with the filter in force.
**Empty state.** "No fee charges match this filter" + **Generate fee structure** and **New charge**.

### 8.2 Charge detail - `admin.student-fees.show`

**Purpose.** One charge, everything about it, nothing recomputed twice.
**Layout.** `x-ui.page-header` (fee number, student, head, status badge, actions) + three `x-ui.stat-card`
(net, paid, balance - balance in rose when positive, in emerald with the word "advance" when negative) +
`x-ui.tabs`:

| Tab | Contents | Empty state |
|---|---|---|
| **Payments** | receipt # · `paid_on` (+ a "back-dated" chip when it differs from `recorded_at`) · method + reference · amount · refunded · net received · commission-state badge (`queued` / `processed` + the amount / `skipped` + the reason on hover / `failed`) · cashier · actions (receipt, refund, void) | "Nothing received yet" + Collect payment |
| **Installments** | no · due date · amount · paid · waived · status badge · actions (waive, view receipts); an "unallocated receipts" strip when `paid - refunded > SUM(lines.paid_amount)`; an "over-allocated" chip on a line whose paid exceeds its amount; the **PI-1 proof line** `lines 30,000.00 - waived 4,000.00 = net 26,000.00` | "No plan - this charge is payable in full" + Build plan |
| **Discounts** | append-only list: type badge · signed amount · percentage · reason · approver · `effective_on` · reversal link; a reversed row is struck through and links to its reversal | "No discounts" + Add discount |
| **Reminders** | type · offset · sent at · channel · sent by (or "scheduler") · amount due at send | "No reminders sent" + Send reminder |
| **Commission trail** | read-only ledger rows this charge produced: `CLE-{id}` · date · collaborator · base chip + base amount · rate · signed amount · status - and, when none exist, the **skip reason in plain words**. Gated by `collaborator_commissions.view_financial`; the whole tab is absent, not blank, without it | "This charge produced no commission: student has no collaborator" |

### 8.3 Generate fee structure wizard (3 steps) - `admin.fee-structures.*`

**Purpose.** Turn an admission (§69) into the charge set, once, with the numbers agreed before anything is
written.
**Components.** `x-ui.modal` (or a full page from the admission screen), `x-ui.form.checkbox`,
`x-ui.form.input`, `x-ui.form.select`, `x-ui.badge`, `x-ui.confirm`, `x-ui.skeleton`.
**Steps.** (1) **Heads** - a checkbox row per head prefilled from the admission and the course, each with
its amount (editable) and due date; heads with a zero amount are disabled with the reason. (2)
**Discounts** - the admission's discount and scholarship shown with the charge they will land on and the
cascade if they overflow (§6.1.2), each needing a reason. (3) **Review** - the charge table with a
**proof line** `SUM(net) = admission net payable 47,500.00`, rendered in rose with the generate button
disabled when they differ; plus an optional "also build an installment plan on the course fee" jump.
**Idempotency.** A hidden ULID generated once per wizard open; re-opening the wizard for an admission that
already has charges shows them read-only with "already generated" and offers only the missing heads.
**Empty/edge.** A cancelled admission disables the wizard with the reason.

### 8.4 Installment plan wizard (3 steps) - `admin.installments.*`

**Purpose.** §77, with the arithmetic visible.
**Steps.** (1) count (2-60), interval (`InstallmentInterval`), first due date (defaults to the batch start
date, else today + `institute.fee_due_days`), remainder placement (defaults from the setting). (2)
**Preview table** from `admin.installments.preview`: no · due date · amount, every amount editable, with a
live running total and the banner `total 30,000.00 = net 30,000.00` in emerald or the difference in rose -
the submit button is disabled until they match, and the server re-asserts it anyway. (3) Review + confirm.
**Rules on screen.** "A plan can only be built before any money is received on this charge" ([D18-4]) with
the current paid amount when it blocks; "installment numbers are never reused" on a rebuild, which
additionally requires a reason.
**Empty state.** Not applicable (the wizard is the creation path); a charge with a live plan opens it in
rebuild mode with paid lines locked.

### 8.5 Discount / scholarship modal and waiver modal

**Components.** `x-ui.modal`, `x-ui.form.select` (type), `x-ui.form.input` (amount **or** percentage,
mutually exclusive), `x-ui.form.textarea` (reason, required), `x-ui.form.select` (approver, required when
`institute.discount_approval_required`), date picker (`effective_on`, never future),
`x-ui.form.checkbox` ("I am the approver" - refused without `fee_discounts.approve`).
**Live preview.** "gross 30,000.00 - discount 5,000.00 - scholarship 0.00 = **net 25,000.00**" and, when a
plan exists, the exact lines the redistribution will change ("installment 5: 10,000.00 -> 5,000.00").
**Waiver modal.** The line, its remaining amount, the waived amount (defaults to the remainder), the
reason, the approver - and the sentence "this reduces the net fee by the same amount, so the plan stays in
balance".
**Reversal.** `x-ui.confirm` naming the row being reversed and requiring a reason; states that the
original row is kept for ever.

### 8.6 Collect payment - the spine's record-payment modal, embedded

Phase 18 embeds spine §8.1 unchanged (4 steps, the commission preview in step 4, the `idempotency_key`
generated once per modal open, the `duplicate_fingerprint` amber panel, the back-date warning) from the
charge detail, the charges index row action and the collection desk. Phase 18 adds only the context strip
above it: student, charge, selected line and its remaining amount, and "nothing outstanding - recording
this will create an advance" on a settled charge. A `cancelled` charge disables the action entirely.

### 8.7 Receipt and fee slip print views

`resources/views/admin/student-fees/slip.blade.php`,
`admin/student-fees/structure-slip.blade.php`,
`admin/fee-payments/receipt.blade.php`, plus `student/fees/slip.blade.php` (the same partials with
`studentCopy()`). They extend **Phase 13's `resources/views/layouts/print.blade.php`** - the one print
layout in the system, shipped by phase-13 §6.9 (F-4.14), never a second one built here - A4, no sidebar,
`@media print` page breaks, institute logo and contact from the `company` /
`branding` settings, `institute.fee_slip_footer_note` in the footer, REPRINT / VOID watermarks per §6.7.2.
PDF is rendered by the same Blade through dompdf when Phase 13 installs it; until then the browser's print
dialog is the supported path and the screen says so.

### 8.8 Fee collection desk - `admin.fee-collection.index`

**Purpose.** The cashier's worklist: who owes what today (§68, §88).
**Components.** `x-ui.tabs` (Due today · Upcoming 7 days · Overdue · Advances), `x-ui.filter-bar`,
`x-ui.table`, `x-ui.stat-card` x3 (expected today, collected today, overdue total),
`x-ui.empty-state`.
**Filters.** Branch, course, batch, fee type, collaborator, overdue bucket, amount range, text search.
**Columns.** Student (+ id) · course / batch · charge + head · installment no · due date · amount due ·
amount paid · balance · days late · **Collect** (opens 8.6) · **Remind** (opens the reminder confirm) ·
slip.
**Empty state.** Per tab: "Nothing due today", "No overdue fees - the institute is current",
"No unapplied advances".

### 8.9 Student panel - `student.fees.*`

**Purpose.** §74: own fees, pending fee, installments, receipts - and nothing else.
**Index.** Four `x-ui.stat-card` (total billed, paid, outstanding, next due date + amount) + a charge list
(fee #, head, course, net, paid, balance, due date, status badge, slip / receipts). Filters: status, fee
type, date range. Empty state: "No fees have been issued yet".
**Detail.** Installment schedule (no · due · amount · paid · status), receipt list with a download per
receipt, discount list showing **type, amount, reason and date** (the student is entitled to know why
their fee changed) - and **no commission column anywhere**: the response body omits `collaborator_id`,
`collaborator_referral_id`, `commission_state`, `commission_skip_reason` and every commission figure
(spine §9). The collaborator's **name** appears only on the slip, per §41.

### 8.10 Dashboard widgets (registered into Phase 2's `DashboardRegistry`)

`FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget` - **Phase 18 owns these three widget
keys and is the only phase that registers them** (F-8.3: the spine §8.12 and phase-10-12 §8.12 dropped
them and keep the eight commission/wallet widgets). §88 needs them and they are pure fee aggregates. Each
declares `module() = 'student_fees'` and `permission() = 'student_fees.view_reports'`, reads `DateRange`,
and computes only from the fee tables; none of them touches a wallet or a ledger (INV-26). A
`DashboardRegistry` key may be declared exactly once, so there is no "whoever ships first" rule any more
(§13.3).

---

## 9. Data isolation

Every rule is an Eloquent **global scope plus a Policy check**, never a hidden form field
(`CLAUDE.md` §1.10), and every rule has a test asserting both the status code **and** the absence of the
forbidden columns from the response body.

| Role | Exact query scoping on this phase's screens |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating (`student_fees` or `fee_reminders` disabled -> 403 for them too, data intact). |
| **Admin** | Unrestricted within Phase 1 §5's grants. |
| **Accountant** | Unrestricted **read** on `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`, `student_fee_reminders`; writes where granted; money columns additionally require `student_fees.view_financial`. Commission figures on the slip need `collaborator_commissions.view_financial`. |
| **Institute Manager / Course Coordinator** | `where branch_id = user.branch_id OR branch_id IS NULL` (D11) on all five tables, applied by the scope, not the controller. No `collaborator_*` table access (403). Course Coordinator holds no `fee_discounts.*`. |
| **Receptionist** | `student_fee_payments.create` and `fee_reminders.create` only; the charges index and the collection desk are additionally scoped to `created_by = auth()->id() OR branch_id = user.branch_id`; 403 on refund, void, discount, cancel and every commission screen. |
| **Teacher** | **403 on every route in this phase**, with no exception for their own batch's students. |
| **Collaborator** | Holds no `student_fees.*` / `installments.*` / `fee_discounts.*` / `student_fee_payments.*` permission -> 403 on every admin route here. `student_fees` is **not** in the spine's `BelongsToAuthenticatedCollaborator` scope, so no collaborator screen may query it; §57's "total paid" and "commission earned" come from the spine's collaborator student list (payment rows scoped by `collaborator_id`, each money column gated by the matching `collaborator_portal.*` permission). |
| **Student** | `where student_id = auth()->user()->student->id` on `student_fees`, `student_fee_installments`, `student_fee_payments`, `student_fee_discounts`, `student_fee_reminders`; route-model binding asserts ownership in the policy and returns **404, not 403**, so ids cannot be probed. The SELECT is an explicit column list omitting `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_skip_detail` and every commission column. Zero access to ledger, entitlement, wallet and payout tables (403). A receipt or slip belonging to another student is 404. |
| **Client** | No access to anything in this phase (403). |
| **Branch (D11)** | When `users.branch_id` is set, every query in this phase adds `where branch_id IS NULL OR branch_id = user.branch_id`; the fee-structure generator stamps `branch_id` from the student, never from the form. |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()`)

Spine-owned, fired by this phase's services: `StudentFeeIssued`, `StudentFeeAdjusted`,
`StudentFeeCancelled`, `StudentFeePaymentRecorded`, `PaymentReversalRecorded`.
Added by Phase 18 (additive to spine §10.1): `StudentFeeStructureGenerated`, `InstallmentPlanBuilt`,
`InstallmentPlanRedistributed`, `InstallmentWaived`, `FeeDiscountReversed`, `StudentFeeMarkedOverdue`,
`FeeReminderSent`.

### 10.2 Queued jobs

| Job | Key properties |
|---|---|
| `GenerateMonthlyFeeCharges` | one job per batch, `ShouldBeUnique` (`monthly-fees:{batch_id}:{YYYY-MM}`, `uniqueFor` 86400); calls `generateMonthlyCharge()` per active admission; a duplicate month returns null and is counted, never thrown |
| `SendFeeDueReminders` | one job per chunk of 500 lines inside a run, carrying the `run_uuid`; `tries` 3; a 1062 on the dedupe index is a skip, not a failure |
| `RecomputeStudentFeeCaches` | `ShouldBeUnique` (`fee-caches:{fee_id}`); the repair path used by `fees:verify-plan-integrity` and by the admin "Recalculate" action; rewrites **caches only**, never a discount, a receipt or a ledger row |
| *(commission)* `ProcessStudentFeeCommission`, `ProcessCommissionReversal` | **spine-owned**; Phase 18 only causes them to be dispatched |

### 10.3 Notifications (database channel now, mail-ready - §97)

To the **student** (and guardian email when Phase 15 supplies one): `FeePaymentReceived` (receipt
attached / linked), `FeeDueReminder` (`upcoming_due`, `due_today`), `FeeOverdue`, `FeeChargeIssued`
(only when `institute.notify_student_on_fee_issue`... **not added** - §97 lists "fee due" and "fee paid"
only, so this phase sends exactly those three).
To **staff**: `FeeCacheDriftDetected` (to holders of `student_fees.view_reports`) when
`fees:verify-plan-integrity` finds a PI-1 or cache mismatch.
Commission notifications (`StudentCommissionAdded`, `CommissionReversed`) are the spine's and are **not**
re-sent here.

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `fees:mark-overdue` | daily 01:00 (spine §10.4) | §6.6; charges and lines past `due_date + institute.fee_overdue_grace_days` with a balance |
| `fees:installment-reminders` | daily 09:00 (spine §10.4) | `FeeReminderService::queueDueReminders()` using Phase 2's `institute.installment_reminder_days` |
| `fees:generate-monthly` | daily 00:20 | dispatches `GenerateMonthlyFeeCharges` for every batch whose active admissions carry a monthly fee amount and whose `monthly_fee_due_day` falls in the next 10 days; a no-op when nothing matches |
| `fees:verify-plan-integrity` | daily 02:10 | re-asserts **PI-1** and the six cache columns of §2.3 for every charge with a live plan or a receipt in the last 60 days; **reports, never silently repairs** (the spine's reconciliation discipline); notifies on drift and links the offending fee numbers |

---

## 11. Acceptance tests

`tests/Feature/Institute/Fees/`. Every money test ends with the spine's shared helper
`assertWalletMatchesLedger($collaborator)` (all eight checks of spine §6.5.3), and every test that posts
money goes through the **real HTTP route** with a real permission set - not the service in isolation.
PH18-01..06 deliberately reuse the spine's test method names; the spine's service-level copies stay in
`tests/Feature/Financial/`, and both must pass.

### 11.1 Requirement §120 - tests 1 to 5 and 9, by name

| # | Test | Asserted |
|---|---|---|
| PH18-01 | `test_student_without_collaborator_creates_no_commission_row` (§120.1) | a student with no referral pays 10,000 through `admin.fee-payments.store`: `assertDatabaseCount('collaborator_commission_ledger_entries', 0)`; **no zero-amount row exists**; no entitlement row; payment `commission_state = skipped`, `commission_skip_reason = no_referral`; the charge still moves to `paid` and the receipt still prints |
| PH18-02 | `test_student_payment_creates_one_commission_at_configured_rate` (§120.2) | 10% rule, base `paid`, receipt 10,000: exactly one row, `amount = 1000.00`, `entry_type = credit`, `purpose = student_commission`, `base_amount = 10000.00`, `gross_amount = 10000.00`, `commission_rate = 10.0000`, `commission_base = paid`, `transaction_date = paid_on`, `rule_snapshot` non-empty; status `pending` under the default manual approval mode (log Q5); wallet `pending 1000.00`, `available 0.00` |
| PH18-03 | `test_same_fee_payment_processed_twice_creates_one_commission` (§120.3) | the same POST replayed with the same `idempotency_key` -> one receipt; the job dispatched twice and the service called twice -> **one** ledger row, the second returning `created: false`; a raw duplicate INSERT throws on `uq_cle_dedupe`, and one with a hand-made `dedupe_key` throws on `uq_cle_source` |
| PH18-04 | `test_three_installments_create_three_commissions` (§120.4) | a 30,000 charge with a 3 x 10,000 plan, each line receipted: three credits of `1000.00` with distinct `student_fee_payment_id` **and** `student_fee_installment_id`; one entitlement with `collected_amount 30000.00`; wallet `3000.00`; all three lines `paid`, the charge `paid`, `balance 0.00` |
| PH18-05 | `test_student_refund_creates_negative_reversal_and_preserves_original` (§120.5) | a full refund of the 10,000 receipt after its 1,000 commission: a new row `amount 1000.00`, `entry_type = debit`, `signed_amount = -1000.00`, `purpose = reversal`, `reverses_entry_id` + `payment_reversal_id` set, rule columns copied from the original; the original re-fetched is **byte-identical** on `amount`, `commission_rate`, `base_amount`, `rule_snapshot`; both rows `reversed`; wallet back to `0.00`; the charge walks `paid -> refunded`, the line `paid -> pending`, and the receipt prints with its refund shown |
| PH18-06 | `test_payout_of_20000_against_50000_wallet` (§120.9) | 50,000 of fee-driven commission made `available`, a 20,000 payout approved and paid: wallet `available 30000.00`, `paid 20000.00`, `reserved 0.00`, `lifetime 50000.00`; the closed identity holds; the payout row **and** its allocation still exist; the entry is still `available` with `allocated_amount = 20000.00` |

### 11.2 Installments, discounts and the arithmetic this phase owns

| # | Test | Asserted |
|---|---|---|
| PH18-07 | `test_installment_amounts_sum_exactly_to_the_net_fee` | for `(net, count)` in `(10000.00,3) (25000.00,7) (33333.33,9) (100.01,2) (0.05,5) (47500.00,12)`: `SUM(lines.amount) === net` as a **string compare**, every line `> 0`, `count` lines exist, due dates strictly ascending; `(0.04,5)` is refused with a named validation error; `count = 1` and `count = 61` are refused |
| PH18-08 | `test_remainder_placement_is_deterministic` | `10000.00 / 3` with `last` -> `3333.33, 3333.33, 3333.34`; with `first` -> `3333.34, 3333.33, 3333.33`; the same input always gives the same array (run 50 times); no float appears in the calculator (static scan) |
| PH18-09 | `test_monthly_due_dates_never_overflow_a_month` | first due 2026-01-31, monthly, 4 lines -> `2026-01-31, 2026-02-28, 2026-03-31, 2026-04-30`; fortnightly and weekly step exactly 14 and 7 days |
| PH18-10 | `test_plan_cannot_be_built_after_money_is_received` | a charge with one 5,000 receipt refuses `installments.store` with a 422 naming the paid amount ([D18-4]); nothing is written; after voiding the receipt the plan builds |
| PH18-11 | `test_plan_sum_must_equal_net` | hand-posted lines summing to `29,999.99` on a 30,000 net charge are refused; lines summing exactly are accepted; a duplicate `installment_no` is refused by `uq_sfi_no` |
| PH18-12 | `test_discount_applied_after_a_payment` | 30,000 net charge, 10,000 received (commission 1,000 under base `paid`), then a 5,000 discount: the discount is an append-only row, `net 25,000.00`, `balance 15,000.00`, **no existing ledger row changes by one paisa**, the next 10,000 receipt earns another 1,000.00; with base `net_after_discount` the open entitlement is **superseded** instead, `entitlement_amount = max(new_promise, released_amount)`, any excess lands in `over_released_amount` and appears on the discrepancies screen, and **no automatic clawback is posted** |
| PH18-13 | `test_discount_redistributes_a_live_plan_and_keeps_pi1` | 3 x 10,000 plan, line 1 paid, then a 5,000 discount: line 3 becomes 5,000.00, PI-1 holds, `SUM(live lines) - SUM(waived) = net 25,000.00`; a 25,000 discount cancels lines 2 and 3, leaves `net 5,000.00` against 10,000.00 already received, and the charge reads `overpaid` with `balance_amount = -5000.00` shown in words as an advance; a `correction` row restores the amounts onto the latest unpaid line |
| PH18-14 | `test_waiver_reduces_net_and_keeps_pi1` | waiving 4,000 of line 3: one `waiver` discount row of `-4000.00`, `line.waived_amount = 4000.00`, net `26,000.00`, PI-1 holds, the line goes `waived` only when fully waived; waiving more than the line's remainder is refused; the waiver needs both abilities |
| PH18-15 | `test_percentage_discount_stores_both_percentage_and_amount` | 10% of a 30,000 gross stores `percentage 10.0000` and `amount -3000.00`; changing `gross` later never re-derives it; `percentage > institute.discount_max_percentage` is refused; `> 100` is refused by `chk_sfd_pct` |
| PH18-16 | `test_discount_can_never_exceed_the_fee` | discount 20,000 + scholarship 15,000 on a 30,000 gross is refused by the service and, on a raw INSERT, by `chk_sf_discount_ceiling`; a correction that would push net above gross is refused |
| PH18-17 | `test_discount_rows_are_append_only` | `$discount->update([...])` and `delete()` throw; a raw `DELETE` raises SQLSTATE 45000 (`trg_sfd_no_delete`); reversal is the only correction path and a second reversal of the same row violates `uq_sfd_reverses` |
| PH18-18 | `test_scholarship_feeds_its_own_cache` | a `scholarship` row moves `scholarship_amount`, not `discount_amount`; a `promotional_discount` and a `referral_discount` move `discount_amount`; a `referral_discount` creates **no** ledger row |

### 11.3 Collection, statuses, overdue and the documents

| # | Test | Asserted |
|---|---|---|
| PH18-19 | `test_partial_payments_walk_the_four_statuses` | a 30,000 charge: 0 paid -> `pending`; 10,000 -> `partial` with `balance 20,000.00`, line 1 `paid`; due date passed -> `overdue`; fully paid -> `paid`; every transition exists in spine §2.18.1 and each writes an activity row |
| PH18-20 | `test_overpayment_attempt_is_accepted_and_capped` | a 35,000 receipt on a 25,000 net charge: the receipt is accepted (the money physically arrived), charge `overpaid`, `balance_amount = -10000.00`, the commission row has `base_amount 25000.00` and `gross_amount 35000.00` and `amount 2500.00`, the skip detail names the non-commissionable portion; with `collaborator.commission_on_overpayment = true` the base is 35,000.00; applying the advance to another charge later creates **no second commission** |
| PH18-21 | `test_back_dated_payment` | `paid_on` 90 days back with `finance.backdate_limit_days = 30` -> 422 and nothing written; the same request from a user holding `student_fee_payments.approve` posts, earns the rate **effective on `paid_on`** (not today's rate), sets `transaction_date = paid_on` with `posted_at` today, and shows the "back-dated" chip; a future `paid_on` is always rejected |
| PH18-22 | `test_receipt_number_never_duplicates_under_concurrency` | 20 parallel posts (separate connections, a latch) against one charge: 20 receipts, 20 **distinct** `receipt_no`, no gap in the sequence, `institute.fee_receipt_next_number` advanced by exactly 20; a forced 1062 on `uq_sfp_receipt` retries once and succeeds; a rolled-back post leaves **no gap**; a voided receipt keeps its number for ever |
| PH18-23 | `test_fee_number_never_duplicates_under_concurrency` | the same proof for `fee_number` / `institute.fee_record_next_number` while two admins generate structures for two admissions simultaneously |
| PH18-24 | `test_fee_structure_generation_is_idempotent_and_balanced` | a double-clicked wizard produces **one** set of charges; `SUM(charges.net_amount) === admission.net_payable`; an admission discount larger than the course-fee charge cascades per §6.1.2; a cancelled admission is refused; **two concurrent workers behind a latch produce one set and the loser's 1062 on `uq_sf_generation` is caught and returned as `created: false`** - the INSERT is the only guard (F-3.15) |
| PH18-25 | `test_voiding_a_receipt_resets_the_charge_and_reverses_commission` | void the only receipt: payment `voided`, a full-amount `void` reversal row, the charge back to `pending` (not `refunded`), the line back to `pending`, a `-1000.00` reversal row, wallet `0.00`; the re-entered receipt needs a new `idempotency_key` and earns once |
| PH18-26 | `test_overdue_sweeper_is_idempotent_and_respects_grace` | `fees:mark-overdue` run twice marks each row once and emits one event per row; `institute.fee_overdue_grace_days = 3` leaves a 2-day-late charge `pending`; `cancelled`, `paid`, `refunded` and zero-balance charges are never touched; a charge whose earliest unsettled line is late becomes `overdue` through `due_date` [D18-3] |
| PH18-27 | `test_zero_net_charge_is_paid_and_earns_nothing` | a charge fully covered by a scholarship: `net 0.00`, status `paid`, no receipt, **no ledger row**; a 0.00 receipt is refused by `chk_sfp_amount` / validation |
| PH18-28 | `test_reminders_are_not_sent_twice` | `fees:installment-reminders` run twice in a day writes one `student_fee_reminders` row per (line, type, due date, offset) and sends one notification; a manual "Send reminder now" for the same offset is a no-op that tells the user so; a charge with no plan is protected by the `dedupe_line` generated column |
| PH18-29 | `test_fee_slip_and_receipt_render_correctly` | the slip shows §41's fields and the proof line; the commission block appears **only** with `collaborator_commissions.view_financial` **and** `institute.fee_slip_show_commission`, and is absent (not zeroed) otherwise; the **student** copy never contains a rate, a commissionable amount or a commission amount in the response body but does name the collaborator; a reprint is watermarked and writes an activity row; a voided receipt prints the VOID watermark and the reversal number; the receipt's balance line carries its own print timestamp |
| PH18-30 | `test_cancel_and_reopen` | cancelling with a reason moves `pending -> cancelled`, cancels unpaid lines, and is **refused** when a non-voided receipt exists; a missing reason fails validation; reopening returns it to `pending` and straight to `overdue` when the due date has passed; a cancelled charge cannot take a payment |

### 11.4 Authorization, isolation and integrity

| # | Test | Asserted |
|---|---|---|
| PH18-31 | `test_authorization_matrix` | no `student_fees.create` -> 403 on store and on the structure wizard; no `installments.create` -> 403 on the plan; no `fee_discounts.create` -> 403 on a discount; no `fee_discounts.approve` -> 403 on reverse and on waive; no `student_fee_payments.create` -> 403 on collecting; no `payment_reversals.create` -> 403 on refund; no `student_fee_payments.change_status` -> 403 on void; no `fee_reminders.create` -> 403 on a manual reminder; no `student_fees.print` -> 403 on the slip. Every 403 asserts **nothing was written** |
| PH18-32 | `test_student_isolation` | student A requesting B's charge, installment, receipt or slip gets **404**; id enumeration over all four routes; A's index never contains B's rows; the response body for A contains none of `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason`, `commission_skip_detail`, `base_amount`, `commission_rate` |
| PH18-33 | `test_role_scoping` | a Teacher is 403 on every route in this phase; a Collaborator is 403 on every admin fee route and cannot reach `student_fees` through any collaborator screen; a Receptionist may collect but is 403 on refund, void, discount and cancel; an Institute Manager with `branch_id` sees only their branch's and the null-branch charges, and cannot read another branch's receipt (404) |
| PH18-34 | `test_module_gating_preserves_data` | disabling `student_fees` 403s every route here for Super Admin too; row counts before and after disable + re-enable are identical; queued commission jobs still complete |
| PH18-35 | `test_payment_rows_are_immutable_and_undeletable` | a payment `update` of `amount` / `paid_on` / `student_fee_id` / `student_fee_installment_id` / any `commission_*` column is refused by the policy; `notes` / `reference_no` / `receipt_path` succeed; `delete()` throws and a raw `DELETE` raises SQLSTATE 45000 |
| PH18-36 | `test_caches_always_equal_their_rows` | after a fixture of 40 charges, 120 receipts, 25 discounts, 15 refunds and 3 voids, `fees:verify-plan-integrity` reports zero drift: every `discount_amount`, `scholarship_amount`, `net_amount`, `paid_amount`, `refunded_amount`, `balance_amount`, `status`, `due_date`, `installment_count` and every line's `paid_amount` / `status` matches its rows, and PI-1 holds on every plan; a hand-corrupted cache is **detected and reported, not silently repaired**, and `RecomputeStudentFeeCaches` then fixes the cache only |
| PH18-37 | `test_institute_services_never_touch_commission_tables` | a static scan of `app/Services/Institute`, `app/Http/Controllers/Admin/StudentFee*`, `Installment*`, `FeeDiscount*` and `app/Http/Controllers/Student` finds no write to a ledger / entitlement / wallet / referral / payment / reversal table, no `commission_*` assignment, no call to `StudentCommissionService` / `CommissionReversalService` / `LedgerWriter`, and no `SUM(` over the ledger (§6.9, INV-26) |
| PH18-38 | `test_no_float_in_any_fee_path` | a static scan of `app/Services/Institute` and the four fee models finds no `+ - * /` applied to a money attribute; `InstallmentPlanCalculator` uses only `bc*` / `Money` (INV-7) |
| PH18-39 | `test_migration_rolls_back_cleanly` | `student_fee_reminders` migrates forward and back on a table holding rows (the generated column and its unique index dropped in the right order); `migrate:fresh --seed` is clean; the migration is a no-op-safe re-run |
| PH18-40 | `test_every_discretionary_act_is_audited_with_a_reason` | discount, waiver, discount reversal, plan rebuild, cancel, reopen, void, refund and a manual reminder each write an `activity_log` row with old/new values, actor, IP, device, module and the **reason**; a missing reason fails validation on every one of them |

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **PI-1 couples discounts to installment plans.** Every discount path must redistribute, and a future developer adding a fifth discount entry point will break the invariant. | PI-1 is asserted inside the transaction (it throws, it does not warn), `fees:verify-plan-integrity` re-proves it nightly, and `addDiscount()` is the only public way to change net - the waiver path goes through it too. |
| R-2 | **[D18-4] refuses a plan on a charge that already holds money**, which some institutes will want. | The alternative would either break the spine's "line sum = net" rule or write `student_fee_installment_id` onto an existing payment row, which INV-8 forbids. The supported paths (collect the remainder ad hoc, or void and re-collect) are stated on screen. Raised as Q7. |
| R-3 | **The monthly fee has no schema home.** §62 and §69 list no monthly amount, so `generateMonthlyCharge()` needs the amount passed in. | The wizard takes it once and the generator repeats it per month idempotently; an optional `courses.monthly_fee` / `student_admissions.monthly_fee` is requested (§13.1) so it can be prefilled. Nothing is invented in the meantime. |
| R-4 | ~~**Without `student_fees.generation_key` the generator relies on a SELECT guard**~~ - **closed (F-3.15).** The column and `uq_sf_generation` ship in Phase 10's migration 01, so generation is duplicate-proof by INSERT. | No check-then-act is left in this phase; PH18-24 proves it with two concurrent workers. Nobody may reintroduce a SELECT guard here. |
| R-5 | **Installment numbers become non-contiguous after a rebuild** (1, 2, 5, 6), which looks like a bug to a user. | Reusing a number would make "the third installment" ambiguous in a fee dispute and would collide with `uq_sfi_no`. The UI labels cancelled lines and orders by due date; [D18-6] is printed on the rebuild screen. |
| R-6 | **A reprinted receipt or slip shows today's balance, not the balance of the issue date.** | Both documents print their own "as at" timestamp and the REPRINT watermark, so the document never lies; printing is logged. Storing a balance snapshot per receipt would duplicate financial state (§109 forbids it). |
| R-7 | **The four requirement statuses are seven in practice** (`overpaid`, `cancelled`, `refunded` are the spine's). A report that filters on four will under-count. | The four are rendered first and documented in §6.4.1; the index's status filter lists all seven with distinct badge colours; Phase 23 reports must use the enum, not strings. |
| R-8 | **A back-dated receipt changes an already-printed fee slip and an already-issued statement.** | Both dates are always stored, `posted_at` explains the movement, and the back-date window plus the new `student_fee_payments.approve` ability bound it. There is no period lock in this phase (spine Q6). |
| R-9 | **The overdue sweeper and the reminder job grow with the student body.** | Both are chunked and index-driven (`(due_date, status)`), bounded per run, and idempotent, so a missed night self-heals the next day. |
| R-10 | **The fee slip is the one screen where commission figures meet a student-facing document** (§41 vs §112). | Three independent gates (permission, setting, forced `studentCopy()` on the panel route) and PH18-29 asserts the absence from the **response body**, not just from the rendered view. |
| R-11 | **A course transfer (`transferPayment`) straddles Phase 15 and Phase 18.** | The service and its guarantees live here; the screen that calls it is Phase 15's, which is recorded in §13.3 so neither phase invents a second money path. |

### 12.2 Open questions (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | **Answered (F-3.15).** `student_fees.generation_key` string(64) nullable + `UNIQUE uq_sf_generation` is added by **Phase 10's migration 01**, because the spine owns the table. | Both generators INSERT and treat 1062 as "already generated"; the degraded [D18-2] path is deleted from this contract. |
| Q2 | Spine §6.6 row 6 and FT-39 require the **`student_fee_payments.approve`** ability that spine §4.1 does not grant. Confirm the registry addition (§4.2) rather than a different gate. | **Answered (F-6.9): confirmed.** `student_fee_payments.approve` exists and is added by Phase 18's registry append (§4.2), matching spine §6.6 row 6 and FT-39. Nothing else changes. |
| Q3 | §41 says "fee slip"; the spine models **one charge per fee head**, so an admission produces several charges. Should the printed slip be the **admission-level structure slip** (one document, all heads, one total) or one slip per head? | Both exist: the structure slip is the default print from an admission, and each charge also prints its own. No schema consequence either way. |
| Q4 | The monthly fee amount has no field in §62 / §69 (R-3). Add optional `courses.monthly_fee` / `student_admissions.monthly_fee`? | Entered in the generator wizard for now; the optional columns are requested, not required. |
| Q5 | Installment rounding remainder on the **last** or the **first** line? | `institute.installment_remainder_placement = last` ([D18-5]) - the residual is collected at the end. One setting flips it; the arithmetic is exact either way. |
| Q6 | Should receipt and fee numbers **reset each year** (`FR-2026-000123`)? | No reset: a single monotonic counter with the Phase 2 prefix. A yearly format would be a `DocumentNumberService` pad/prefix change, not a redesign. |
| Q7 | May an installment plan be built **after** money has been received (R-2)? | No ([D18-4]). If the client insists, the honest implementation is a plan over the **remaining** balance plus a documented exception to the spine's "line sum = net" rule - a spine change, not a Phase 18 change. |
| Q8 | Must a discount be approved by **someone other than** its creator, and is an approver mandatory at all? | `institute.discount_approval_required = true`; the creator may not be the approver unless they themselves hold `fee_discounts.approve` ([D18-8]). No second approver beyond that. |
| Q9 | Does the **student's** copy of the fee slip name the collaborator (§41 lists the collaborator among the slip fields) even though it never shows a commission figure? | Yes - the name only. The student already knows who referred them; the money stays hidden. |
| Q10 | Is `student_admissions.net_payable` defined as `course_fee + admission_fee + registration_fee - discount - scholarship`? The generator's `SUM(net) = net_payable` assertion depends on it. | Yes. Requested from Phase 15 in §13.1; if the client defines it differently, the generator's proof line changes and nothing else. |
| Q11 | Should an `exam_fee` or `certificate_fee` be commissionable by default? | No - `collaborator.commissionable_fee_types` defaults to `course_fee, monthly_fee, installment`, so those charges earn nothing and the receipt says `fee_type_not_commissionable`. |

---

## 13. Requests to other phases

### 13.1 Columns and tables

| Request | Why |
|---|---|
| `student_fees.generation_key` string(64) nullable + `UNIQUE uq_sf_generation(generation_key)` - **Phase 10, satisfied** (spine §2.2 and phase-10-12 §2.2 migration 01) | makes the fee-structure and monthly generators duplicate-proof **by INSERT instead of by SELECT** (§6.1.3, Q1, F-3.15) |
| `student_admissions.net_payable` decimal(15,2) - Phase 15 | the generator asserts `SUM(charges.net_amount) = net_payable`; it is also the spine's collectible denominator (spine §6.1.4) |
| `student_admissions.course_fee`, `.admission_fee`, `.registration_fee`, `.discount_amount`, `.scholarship_amount`, `.total_amount`, `.admission_date`, `.batch_id`, `.status` - Phase 15 | every input of the fee-structure generator (§69) and the refusal on a cancelled admission |
| `student_admissions.monthly_fee` decimal(15,2) nullable **(optional)** - Phase 15; `courses.monthly_fee` decimal(15,2) nullable **(optional)** - Phase 14 | lets `fees:generate-monthly` prefill instead of asking every month (R-3, Q4) |
| `students.branch_id`, `.status`, `.registration_no`, `.guardian_phone` - Phase 15 | D11 branch scoping, the slip header, the reminder recipient |
| `courses.course_fee`, `.admission_fee`, `.registration_fee`, `.installment_available`, `.duration` - Phase 14 | wizard defaults; `installment_available = false` disables the plan wizard with the reason |
| `batches.start_date` - Phase 16 | the default first due date of an installment plan |
| Students and admissions must be **not force-deletable** once a charge exists - Phases 15 | my FKs to them are `restrictOnDelete`; the policy must say *why* |

### 13.2 Support classes and registries

| Request | Why |
|---|---|
| `App\Support\Money` - **satisfied by phase-01 §3** (F-4.11): the one canonical surface published there includes `toMinor`, `fromMinor`, `round($v, $scale = 2)`, `roundTo`, `prorate` and `distribute(string $amount, int $parts, RemainderPlacement $r = RemainderPlacement::First): array`, plus `App\Enums\RemainderPlacement` (`first`, `last`, `largest`) - **bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float**. Phase 18 adds nothing to the class and declares no enum of its own | §6.2's exactness belongs in one tested place; `distribute` is the only function that may split money |
| `App\Support\PermissionRegistry` - Phase 1: the `fee_reminders` module of §4.1 and the two ability additions of §4.2 | the registry is the only place permission names exist (D4) |
| `App\Support\SettingsRegistry` - Phase 2: the ten `institute` keys of §5 | settings definitions in code, values in the DB |
| `App\Support\DashboardRegistry` - Phase 2: **Phase 18 registers** `FeeCollectedTodayWidget`, `PendingFeesWidget` and `OverdueFeesWidget` (F-8.3 - these three widget keys are Phase 18's; spine §8.12 and phase-10-12 §8.12 no longer declare them) | §88's fee cards; one declaration per widget key |
| `App\DataObjects\Institute\FeeSummary` - **published by this phase, not an ask** (F-4.6): readonly `string $gross`, `$discount`, `$scholarship`, `$net`, `$paid`, `$refunded`, `$balance`, `StudentFeeStatus $status`, `?CarbonImmutable $nextDueDate`; returned by `StudentFeeService::summaryFor()` | phases 14-17 and 19-23 read a fee position without summing money themselves (INV-26) |
| `resources/views/layouts/print.blade.php`, `App\Support\ReportResult` and `App\Services\Reporting\ReportExporter::export(ReportResult, ExportFormat): StreamedResponse` - **Phase 13, satisfied** (phase-13 §6.9 ships all three; F-4.14) | one print layout and one streaming exporter for invoices, fee slips, receipts, result cards and certificates - not four. **Phase 18 ships no fallback**: the earlier "or Phase 18 if 13 has not shipped it" clause is deleted now that the artefacts have a named owner |
| `barryvdh/laravel-dompdf` - Phase 13 | the slip and receipt render through the same Blade; until it is installed the browser print dialog is the supported path |

### 13.3 Behavioural asks

| Request | Why |
|---|---|
| **Phase 15 must call `StudentFeeService::generateStructure()`** from the admission workflow and must never insert `student_fees` itself; it must attach the referral through `ReferralService::attach()` | one money path, one referral path (§117, spine §13.1) |
| **Phase 15's course-transfer screen must call `StudentFeeService::transferPayment()`** and must not move money itself | spine §6.6 row 7: the carried receipt keeps its original `paid_on` so commission is neither doubled nor lost |
| **Phases 20 and 21 must charge exam and certificate fees through `StudentFeeService::issue()`** with `fee_type = exam_fee` / `certificate_fee` and must not create their own money tables | §76 already lists both heads; a second fee table would split the student ledger |
| **Phase 13 must not list `student_fee_payments` in an independent income counter**; institute income is `SUM(net_received_amount)` over non-voided receipts | §109 "do not duplicate financial records" |
| **Phase 22 ships the notification classes** `FeePaymentReceived`, `FeeDueReminder`, `FeeOverdue`, `FeeCacheDriftDetected` | §97 |
| **Phase 23's fee reports must query the fee tables through this phase's scopes and the enum**, and must never recompute a commission or a wallet figure (spine FT-42) | §99, INV-26 |
| **Phase 10 does not register** `FeeCollectedTodayWidget` / `PendingFeesWidget` / `OverdueFeesWidget` - **Phase 18 owns the three keys** (F-8.3; spine §8.12 and phase-10-12 §8.12 dropped them and kept the eight commission/wallet widgets) | two classes with one widget key is a silent duplicate card |
| `DEVELOPMENT_LOG.md` §4: cite **D49** (allocated by `docs/design/resolutions.md` §4.1; this contract's former "D19") - an installment plan's live lines minus waivers always equal the charge's net fee (PI-1), and every discount redistributes the plan in reverse due-date order | the invariant a future developer must not break (R-1). F-10.1 |
| `DEVELOPMENT_LOG.md` §4: cite **D50** (formerly this contract's "D20") - installment numbers are never reused and never renumbered; a rebuild continues from `MAX + 1` | R-5, fee disputes. F-10.1 |
| `DEVELOPMENT_LOG.md` §9: add **Q11** (exam / certificate fees are not commissionable by default) next to the existing commission questions | the client should see it beside Q4/Q5 |


---

## Convergence log (2026-09-12)

Applied from `docs/design/resolutions.md` §3 (apply-map row for this file) plus §2 ownership maps.
The financial spine stays authoritative: every edit below tightens or cites a guarantee, none relaxes one.

| Finding | Change made |
|---|---|
| F-3.15 | §2.4 rewritten: `student_fees.generation_key` + `UNIQUE uq_sf_generation` is **granted** and created by Phase 10's migration 01. §6.1.3's **[D18-2] degraded SELECT-under-row-lock path is deleted** - both generators INSERT and treat 1062 as "already generated". R-4 closed, Q1 answered, §13.1 marked satisfied, PH18-24 rewritten to assert the 1062 path under two concurrent workers. |
| F-4.2 | §6.10.5: all three refund rows now call `refund($p, new RefundData(amount:, reason:, type:, method:))`, and a new paragraph names `App\DataObjects\Finance\RefundData` and its six readonly properties as the only permitted shape (two adjacent `string` positionals are a silent money bug). |
| F-4.6 | §6.1 publishes the four methods: `summaryFor(Student\|StudentAdmission): FeeSummary`, `outstandingFor(StudentAdmission\|StudentBatchEnrollment): string`, `withinServiceContext(): bool`, `reassignBatch(StudentBatchEnrollment, Batch): int` (**repoints `student_fees.batch_id` only, writes no money**). `App\DataObjects\Institute\FeeSummary` (9 readonly properties) added to §13.2; §2.1/§2.3 now list `batch_id` as a writable display column. |
| F-4.11 | §1.2 and §13.2: the "add to `Money`" ask replaced by **"satisfied by phase-01 §3"** with the canonical surface quoted. §3's local **`RemainderPlacement` declaration withdrawn** - the enum (`first`, `last`, `largest`) is Phase 1's, and §6.2 now splits money only through `Money::distribute()`. |
| F-4.14 | §1.2, §8.7 and §13.2: `ReportResult`, `ReportExporter::export(ReportResult, ExportFormat)` and `layouts/print.blade.php` are **Phase 13's, satisfied**; the "or Phase 18 if 13 has not shipped it" fallback clause is deleted and this phase builds no second print layout or exporter. |
| F-6.9 | §4.2's `student_fee_payments.approve` registry append **kept unchanged** (it matches spine §6.6 row 6 / FT-39); §12.2 Q2 marked answered/confirmed. No other change. |
| F-8.3 | §8.10 and §13.2/§13.3: **Phase 18 owns** `FeeCollectedTodayWidget`, `PendingFeesWidget` and `OverdueFeesWidget`; the "whoever ships first owns the key" rule is replaced by single ownership (the spine and phase-10-12 dropped the three keys). |
| F-9.1 | §2.2: `student_fee_reminders`' missing `deleted_at` now cites **D19**'s append-only category rule instead of reasoning locally; §2.1's `student_fee_discounts` row cites D16 under D19. |
| F-11.3 | Header, §1.2 and §2.1: the split is recorded as **accepted** - Phase 10 owns and creates all four fee tables, Phase 18 creates only `student_fee_reminders` and ships the services, screens and documents. |
| F-10.1 | §13.3: this contract's claimed **D19 -> D49** and **D20 -> D50** per resolutions §4.2. |
| F-4.1 *(ownership map §2.3)* | §1.2: `DocumentNumberService` moved off the Phase 10 dependency row and onto **Phase 5**, with this phase passing its own `'%06d'` pad; §6.10.2 step 4 unchanged in behaviour. |
| F-4.9 *(spine leg)* | §6.10.5's approval row notes that a rejected refund fires the spine's new `PaymentReversalRejected`, on which this phase's charge caches recompute. The `refunded_amount` rollback stays inside the spine's own transaction. |

**Noted, not applied as worded:** F-4.6 asks for `FeeSummary` "in §13.2". §13.2 here lists support classes
**requested from other phases**, so the DTO is recorded there explicitly marked *"published by this phase,
not an ask"*, and declared beside `summaryFor()` in §6.1. No guarantee changed.
