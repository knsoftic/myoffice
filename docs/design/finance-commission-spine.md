# PHASE 10 CONTRACT - Financial Spine (money received, referral proof, commission ledger, wallet, payouts)

**Status: binding.** This is the single design that phases **10, 11, 12, 13 and 18** are built from. Where
it conflicts with a later phase contract, this document wins; where it conflicts with
[`phase-01.md`](../phases/phase-01.md) or [`phase-02.md`](../phases/phase-02.md), those win and the
conflict is recorded in §12.2 as an open question, never silently redesigned.

Three independent proposals were judged against [`../requirements.md`](../requirements.md) sections
**E (§29-32)**, **F (§33-60)**, **G (§76-78)**, **I (§109-110)** and the nine acceptance tests in
**J (§120)**. The transaction-safety proposal is the foundation (its ledger/allocation model is the only
one of the three that satisfies §120.9 literally, and its NOT NULL composite unique index is the only
duplicate guard MariaDB actually enforces). The accounting-purist proposal contributed the append-only
money-in discipline, the immutable effective-dated rule versions, the reconciliation proof table, the
reusable encrypted payout destinations and the clawback settlement policy. Everything the operations and
reporting proposal would have covered - statement proof, skip-reason reporting, discrepancy queue,
document numbering, dashboard widgets - is designed here from the requirement.

Every "either could work" has been resolved into a decision. Decisions are labelled **[D-FS-n]** so a
code review can cite them.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, phase ownership, **invariants** |
| 2 | Schema (15 tables), **status lifecycles**, **duplicate prevention** |
| 3 | Enums |
| 4 | PermissionRegistry additions |
| 5 | SettingsRegistry additions |
| 6 | Services: **calculation algorithm**, contracts, **reversal model**, **payout model**, **reconciliation**, **edge cases** |
| 7 | Routes |
| 8 | UI screens |
| 9 | Data isolation |
| 10 | Events, notifications, jobs, scheduled tasks |
| 11 | Acceptance tests / test matrix |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal, dependencies, ownership, invariants

### 1.1 Goal

After this spine is built the business can: issue a student fee charge with discounts, scholarships and
an installment plan; receipt individual student payments (cash, bank, wallet, back-dated) and client
project payments (against a project, a milestone or an invoice); refund, partially refund or void any
receipt; and have **every rupee actually received** deterministically produce - or deterministically
**not** produce - exactly one collaborator commission ledger row that is snapshot-immutable, approvable,
reversible, re-derivable, duplicate-proof and payable through a payout that can never spend the same
commission twice. The collaborator sees a wallet and a statement that balance to the paisa; the
accountant can prove the wallet equals the ledger on any day.

### 1.2 Dependencies

| Phase | What this spine needs from it |
|---|---|
| 1 | `users`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `branches`, `activity_log` with old/new values, `App\Support\Money`, `Blameable`, `x-ui.*`, `layouts/admin`, `layouts/panel` |
| 2 | `SettingsRegistry` groups `collaborator` / `institute` / `finance`, `SettingsService`, `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`) |
| 6 | `projects` (`project_value`, `discount_amount`, `net_value`, commission override columns), `project_milestones` (+ `amount`) |
| 8 | `collaborators` (`status`, `referral_code`), and a wallet-creating observer |
| 9 | `collaborator_referral_visits` and the referral-link capture that calls `ReferralService` |
| 13 | `invoices`, `payment_methods` (both nullable FK targets, added by guarded follow-up migrations) |
| 14-15 | `courses`, `batches`, `students`, `student_admissions` |

Migration timestamps sort **after** phases 6, 8, 14 and 15. Every FK whose target table may not exist
yet is added in a separate `add_fks_to_*` migration guarded by `Schema::hasTable()`, so the spine can be
migrated early without breaking `migrate:fresh`. **[D-FS-1]**

**Release ordering** (§12.2 Q10, F-11.2): the set is applied in the **same release, immediately after Phase
8's own migrations**, so Phase 8's wallet observer and rule screens and Phase 9's referral capture have
their tables. Any target that belongs to a later phase (Phase 13's `invoices` / `payment_methods`, Phase
14-15's `students` / `student_admissions` / `courses` / `batches`) is reached only through the guarded
`add_fks_to_*` follow-ups above — which is exactly what makes the early application safe.

### 1.3 Phase ownership - no table is created twice

The 15 tables in §2 are created by **one atomic migration set shipped by Phase 10**, because Phase 10 is
the earliest phase that cannot function without them (the student commission engine needs
`student_fee_payments`, which the phase plan otherwise does not create until Phase 18). Later phases add
**behaviour and screens only**, plus the additive columns listed in §13. **[D-FS-2]**

| Phase | Owns (code, screens, services) | Must NOT create |
|---|---|---|
| **10** | The whole migration set; all enums in §3; `PaymentService` (student side), `ReferralService`, `CommissionRuleService`, `CommissionBaseResolver`, `CommissionEntitlementService`, `LedgerWriter`, `StudentCommissionService`, `CommissionReversalService`, the commission sweeper, the record-payment modal and payments register, commission ledger index/show, commission approval actions | - |
| **11** | `PaymentService::recordProjectPayment/...` project side, `ProjectCommissionService`, project-payment register + commission trail screens, project-level rate override resolution | any table |
| **12** | `CollaboratorWalletService`, `PayoutService`, `CollaboratorStatementService`, `CommissionReconciliationService`, wallet screens, payout wizard + register, statement + exports, reconciliation screen, collaborator-panel wallet/commissions/statement/payouts | any table |
| **13** | `invoices`, `expenses`, `income`, `payment_methods` tables and screens; the finance register that lists `project_payments`; invoice `paid_amount` **derived from** `project_payments` (never an independent counter) | `project_payments`, `payment_reversals` |
| **18** | `StudentFeeService` (issue charge, installment plan, discounts, scholarships, cancel), fee screens, receipt print, installment reminders, overdue sweep, student-panel fee screens | `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments` |

### 1.4 Invariants - enforced by code review, by the DB, and by the tests forever

| # | Invariant | Enforced by |
|---|---|---|
| INV-1 | A commission row exists only because money was **received**. Never on registration, admission, project creation, invoice issue or milestone completion. | Engine entry points accept only a payment row; tests FT-30/FT-31 |
| INV-2 | **No collaborator reference means no ledger row at all** - not a zero row. The explanation lives on the payment (`commission_state` + `commission_skip_reason`). | FT-01, FT-06; CHECK `amount > 0` |
| INV-3 | At most **one earning row per (source payment, collaborator, purpose)**, for the life of the database, decided by a unique INSERT and never by a SELECT-then-INSERT. | `uq_cle_dedupe`, `uq_cle_source` |
| INV-4 | A ledger row's money columns and snapshots are **never UPDATEd**. Only lifecycle columns change: `status`, `approved_*`, `hold_until`, `available_at`, `paid_at`, `allocated_amount`, `reversed_amount`, `reversed_at`, `clawed_back_amount`, `cancelled_*`, `notes`, `updated_*`. | Model `updating` hook throws `ImmutableLedgerAttributeException`; FT-33 |
| INV-5 | **No financial row is ever deleted** - not even soft-deleted. The nine append-only tables of §2.1 carry no `deleted_at`, and each has a `BEFORE DELETE` trigger raising `SIGNAL SQLSTATE '45000'`. | Trigger + model `deleting` hook; FT-34 |
| INV-6 | After approval nothing about the calculation can move: rate, base, base amount, entitlement total and `rule_snapshot` are fixed. A correction is a new reversing row. | INV-4; FT-21 |
| INV-7 | Money is `decimal(15,2)`; every arithmetic step goes through `App\Support\Money` (bcmath, intermediate scale 6, final **half-up at 2**). No PHP `+ - * /` ever touches a money value. | FT-40, FT-41 (static scan) |
| INV-8 | A payment row is never edited. A wrong receipt is **voided** and re-entered; the two rows stay linked. | Policy `update` allows only `notes` / `reference_no` / `receipt_path` / **`invoice_id`** (the single concession, see below — `invoice_id` additionally demands `project_payments.link_invoice` **and** `invoices.edit`; `project_payments` is **never** granted `edit`, §4.1); FT-35, FT-52 |
| INV-9 | `refunded_amount <= amount` on every payment row, so cumulative refunds can never exceed money received - which is what caps cumulative commission reversal. | CHECK `chk_sfp_refund_ceiling` / `chk_pp_refund_ceiling`; FT-17 |
| INV-10 | `reversed_amount + clawed_back_amount <= amount` on every ledger row. A commission can never be undone by more than it was worth. | CHECK `chk_cle_undo_ceiling`; FT-16 |
| INV-11 | `allocated_amount + reversed_amount <= amount` on every ledger row. A commission can never be paid twice, and never paid after being reversed. | CHECK `chk_cle_allocation_ceiling` + compare-and-swap; FT-25, FT-26 |
| INV-12 | `entitlement_amount IS NULL OR released_amount <= entitlement_amount`. A fixed amount or a document-level base can never over-release, even through a future buggy caller. | CHECK `chk_cce_cap`; FT-11, FT-13 |
| INV-13 | The wallet is a **cache with no truth of its own**: every column equals the SQL in §6.5, `available = payable - live allocations`, and `lifetime_earned = pending + available + reserved + paid`. | `CommissionReconciliationService`; asserted after **every** money test by `assertWalletMatchesLedger()` |
| INV-14 | For any original entry, `signed_amount` summed over it and all of its reversal/clawback rows equals what the collaborator actually keeps. | Reconciliation check R6 |
| INV-15 | A fully reversed **unpaid** entry and **all of its reversal rows** move to `reversed` in the same transaction, so bucket sums computed with and without `reversed` rows are identical. | `CommissionReversalService`; reconciliation check R5 |
| INV-16 | Rule resolution and referral resolution key off the payment's **value date** (`paid_on` / `occurred_on`), never `now()`. | `CommissionRuleService::resolve()`, `ReferralService::effectiveOn()`; FT-21, FT-22 |
| INV-17 | A commission rule row is never edited. A rate change is a new effective-dated version; at most one open-ended version per (collaborator, scope). | `uq_ccs_open`, model hook; FT-22, FT-23 |
| INV-18 | At most one **current** referral per subject; an attribution change supersedes and never re-points an existing ledger row. | `uq_cr_student_current` etc.; FT-28 |
| INV-19 | Commission never accrues on money not yet received and never exceeds `rate x document base` (or the fixed amount). | §6.1 step 9; INV-12 |
| INV-20 | Every money write is one `DB::transaction()` with the fixed lock order **payment -> document -> entitlement -> wallet -> ledger rows by ascending id**; every event and notification is dispatched `afterCommit`. | §6.2; FT-24 (deadlock-free under concurrency) |
| INV-21 | **Only `LedgerWriter` inserts ledger rows.** Any other creator throws. | Model `creating` hook; FT-36 |
| INV-22 | `payout.amount` always equals the sum of its live (`is_released = 0`) allocations. | `PayoutService`; reconciliation check R4 |
| INV-23 | A `paid` entry is fully allocated to a paid payout. "Paid commission" figures are derived from **allocations**, never from `status` alone. | Query scopes `payable()`, `paidPortion()`; FT-27 |
| INV-24 | Every approval, rejection, reversal, clawback, write-off, payout transition, rule version and attribution change writes an `activity_log` row with old/new values, actor, IP and - where the act is discretionary - a **mandatory reason**. | `LogsActivityWithContext`; FT-37 |
| INV-25 | A negative available balance is a real, displayed state. It is cleared only by new earnings, a recorded recovery, or an explicit written-off adjustment - never by editing the wallet. | §6.3.5; FT-19, FT-20 |
| INV-26 | Nothing outside `CollaboratorWalletService` and `CollaboratorStatementService` may compute a balance. Reports, exports, notifications and dashboard widgets call them. | FT-42 |

**The single concession to INV-8 — `project_payments.invoice_id`, recorded as D43** (F-4.10). An advance
receipted before its invoice existed must be attachable to that invoice, and an invoice cancelled in error
must release its payments; both are a change of *which document this receipt belongs to*, never a change of
money. `invoice_id` may therefore move **NULL -> value -> NULL** under all four guards, every one of which
is part of the contract:

| # | Guard |
|---|---|
| 1 | Only `InvoiceService` (Phase 13) writes it. `PaymentService`, a controller, a job, a seeder or a factory writing `invoice_id` on an existing row is a review failure; the policy's `update` whitelist is the enforcement. |
| 2 | Only while the payment is **not `voided`**. A voided receipt's document links are frozen with it. |
| 3 | A **reason is mandatory** and the change is audited (§107) — `activity_log` with old and new `invoice_id`, invoice numbers, actor, IP and reason (INV-24). |
| 4 | Gated by **both** `project_payments.link_invoice` **and** `invoices.edit`. **Resolved — ND-1.** D43's original wording said `project_payments.edit`, but §4.1 registers `project_payments` with **no `edit` ability** ("the same discipline on the software-house side" as `student_fee_payments`, where `edit` is withheld for ever — INV-8, INV-5), and that guarantee stays **true**: no `edit` ability is added to any money module, now or later. The concession instead rides a **dedicated narrow ability, `project_payments.link_invoice`**, declared in §4.1 and belonging to no Phase 1 preset. It authorises **exactly one mutation: moving `invoice_id` between NULL and an invoice id, through `InvoiceService`.** Holding it grants **no other mutation whatsoever** — not an amount, a date, a method, a reference, a status, a collaborator, a commission column, a refund, a void or a delete; it grants nothing on `student_fee_payments` (no such ability exists there) and nothing on any other money table. It is also not part of `MONEY`, so it does not by itself reveal a single amount. |

**Zero commission effect:** the move reads no ledger row and writes none. Commission was decided by money
received on `paid_on` against the *project*, not by which invoice the receipt was later filed under, so
no entitlement, ledger row, wallet column or reversal is touched. No other money column on any payment row
gains an edit path from this concession.

**Canonical whitelist clause — quoted verbatim, never paraphrased (ND-2).** The model-level `updating`
guard that enforces INV-8 on `ProjectPayment` is owned by phase-10-12 §2.3 `[D-IMP-2]` (its Model plan's
Guards row; the round-2 audit refers to it as §2.2), not by this file, so
the two documents could drift apart on what `invoice_id` is whitelisted *for*. They therefore carry one
block of words, quoted:

> `invoice_id` is whitelisted **only** for the D43 document-link move NULL → value → NULL, and only when
> **(1)** the writer is **`InvoiceService`** — `PaymentService`, a controller, a job, a seeder or a factory
> writing `invoice_id` on an existing row is a review failure; **(2)** a **reason is mandatory**;
> **(3)** the change is **audited** (§107, INV-24: `activity_log` with old and new `invoice_id`, both
> invoice numbers, actor, IP and reason); **(4)** it has **zero commission effect** — no ledger row is read
> and none is written. The payment must additionally **not be `voided`**, and the actor must hold
> **`project_payments.link_invoice`** *and* **`invoices.edit`**.

phase-10-12 §2.3 reproduces that quotation inside `[D-IMP-2]`, and phase-13 §6.3 rules 4-5 and §7.1 state
the same ability pair. An edit to any one of the four places is an edit to all four.

---

## 2. Schema

### 2.1 The 15 tables

| # | Table | Soft deletes | Why |
|---|---|---|---|
| 1 | `student_fees` | yes | a charge **document** (§41, §76) |
| 2 | `student_fee_installments` | yes | a schedule line (§77) |
| 3 | `student_fee_discounts` | no | append-only money history (§78) |
| 4 | `student_fee_payments` | **no** | cash received (§39, §42) |
| 5 | `project_payments` | **no** | cash received (§46-48) |
| 6 | `payment_reversals` | **no** | cash returned (§44, §49) |
| 7 | `collaborator_referrals` | **no** | attribution evidence (§37, §38, §45) |
| 8 | `collaborator_commission_settings` | **no** | the rule that authorised money (§35) |
| 9 | `collaborator_commission_entitlements` | **no** | the capped promise |
| 10 | `collaborator_commission_ledger_entries` | **no** | the spine (§51) |
| 11 | `collaborator_wallets` | no | a cache (§50) |
| 12 | `collaborator_payouts` | **no** | money paid out (§54) |
| 13 | `collaborator_payout_allocations` | no | which earnings a payout settled |
| 14 | `collaborator_payout_accounts` | yes | a reusable destination (§55) |
| 15 | `collaborator_wallet_reconciliations` | no | written once, never edited (§50) |

**[D-FS-3] The nine tables whose soft-delete column is marked **no** in bold above deliberately omit `deleted_at`**,
under **`DEVELOPMENT_LOG.md` §4 D16** (the nine append-only financial tables carry no `deleted_at`,
approved 2026-09-12) and the general category rule **D19** (append-only money, audit, log, snapshot,
revision, counter and history-pivot tables carry no `deleted_at`; mutable business tables carry it — the
full rule is in `CLAUDE.md` §3). This is therefore **not** a deviation from `CLAUDE.md` §3 any more: it is
the rule `CLAUDE.md` §3 now states, and D16 is the recorded approval for these nine tables specifically.
The reason stands unchanged: a nullable `deleted_at` on an immutable financial table is an invitation —
one `->delete()` from a future controller would make a ledger row vanish from every aggregate while the
wallet cache keeps the money, and reconciliation would report drift with no obvious cause; on a
NULL-tolerant unique guard it would silently permit a duplicate. Cancellation, voiding and reversal are
statuses and rows, never deletes. The three tables that **keep** `deleted_at` (`student_fees`,
`student_fee_installments`, `collaborator_payout_accounts`) keep it because they are mutable documents and
reusable destinations, not append-only rows.

**[D-FS-4] Naming.** §109 lists `collaborator_commission_ledger` and `student_payments`; `CLAUDE.md` §3
gives the literal example `collaborator_commission_ledger_entries` and the rule "snake_case plural", and
Phase 1 registers the module slugs `student_fees`, `installments`, `fee_discounts`. The convention wins:
`collaborator_commission_ledger_entries`, `student_fees`, `student_fee_installments`,
`student_fee_discounts`, `student_fee_payments`.

**[D-FS-5] Signed money.** Every money magnitude column is positive (`CHECK amount > 0`), direction is
carried by `entry_type`, and the only column anything ever sums is the **stored generated** column
`signed_amount` (`credit -> +amount`, `debit -> -amount`). One column to aggregate means no query can
forget `ABS()` or invert a sign. Written as raw SQL in the migration
(`ALTER TABLE ... ADD COLUMN signed_amount DECIMAL(15,2) AS (CASE WHEN entry_type='credit' THEN amount ELSE -amount END) STORED`);
if the target server rejects it the migration must **fail loudly**, never silently fall back.

**[D-FS-6] Rejected from the proposals, with the reason.**

| Rejected | Why |
|---|---|
| Per-collaborator hash chain (`chain_index`, `previous_entry_hash`, `entry_hash`) | It serialises every write for a collaborator on one wallet row and defends only against an attacker who has raw SQL - who can also recompute the chain. Tamper evidence comes from the append-only ledger, the activity log and the nightly reconciliation. Revisit only if the client asks for cryptographic evidence (§12.2 Q7). |
| `BEFORE UPDATE` triggers on money tables | Lifecycle columns must change; a column-by-column trigger is an invisible landmine that breaks factories, seeders and later migrations. `BEFORE DELETE` triggers are kept (deleting is never legitimate, so there is no false-positive cost). |
| Split-then-reverse for partial refunds (4 extra rows + a net-zero reclassification journal) | It buys nothing once per-entry ceilings (`reversed_amount`, `allocated_amount`) are DB-enforced, and it makes a collaborator's statement unreadable. |
| Whole-entry-only payout allocation | It cannot satisfy §120.9 (20,000 payout against a single 50,000 entry) without an extra manual split step the requirement never asks for. |
| `financial_periods` + period locking | Not in the requirement. Back-dating is controlled by `finance.backdate_limit_days` + an approval ability + storing both value date and system date (§6.6 row 6). Offered to Phase 13 as a future addition (§12.2 Q6). |
| A separate `collaborator_commission_ledger_entry_events` table | D13 already contracts one audit store: the extended `activity_log`. The hot audit facts (`approved_by/at`, `available_at`, `paid_at`, `reversed_at`, `cancelled_*`) live on the row itself; the statement is movement-based (§6.5.4), so no status replay is needed to produce it. |
| A `commission_decision_logs` table | One row per evaluation plus one per retry would be the largest table in the system. The same question ("why did this receipt pay nothing?") is answered by `commission_skip_reason` (an **enum**, so it is groupable in a report) + `commission_skip_detail` on the payment row, plus an activity entry per skipped evaluation. |
| `UNIQUE (collaborator_id, inflight_guard)` - one in-flight payout per collaborator | The compare-and-swap allocation already makes double-spend impossible, so the index adds no money safety, only an unrequested business restriction. Kept as the service-level guard `collaborator.payout_single_inflight` (default true), which is honest because the setting really does control it. |
| A settings-counter sequence for ledger entry numbers | It would serialise **every commission in the system** on one settings row. A ledger entry's human reference is the accessor `reference` = `'CLE-' . id`. Printed documents (fee charge, receipt, reversal, payout) do get settings-counter numbers - they are low-frequency and their transactions are short. |

### 2.2 `student_fees`

The fee charge document: what a student owes for one fee head. It never holds cash.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `fee_number` | string(32) | not null | `institute.fee_record_prefix` + counter, assigned in-transaction |
| `generation_key` | string(64) | nullable | **The INSERT is the check** for the fee-structure and monthly generators (F-3.15). Composed by the generator, never by a caller: `structure:{student_admission_id}:{fee_type}` / `monthly:{student_admission_id}:{YYYY-MM}`. NULL for a hand-entered charge, so manual charges stack freely while a retried or doubly-scheduled generator run collides on `uq_sf_generation` and treats 1062 as "already generated". No generator may guard with a SELECT under a row lock |
| `branch_id` | FK `branches.id` | nullable, null | D11, `nullOnDelete` |
| `student_id` | FK `students.id` | not null | `restrictOnDelete` - a student with financial history is never hard-deletable |
| `student_admission_id` | FK `student_admissions.id` | nullable | `restrictOnDelete`; the default commission document grain |
| `course_id` | FK `courses.id` | nullable | `nullOnDelete`, reporting only |
| `batch_id` | FK `batches.id` | nullable | `nullOnDelete`; a batch transfer updates this and nothing financial |
| `fee_type` | string(32) | not null | cast `StudentFeeType` (§76) |
| `title` | string(150) | nullable | "March 2026 monthly fee" |
| `gross_amount` | decimal(15,2) | 0.00 | §41 gross fee |
| `discount_amount` | decimal(15,2) | 0.00 | CACHE of non-scholarship rows in `student_fee_discounts` |
| `scholarship_amount` | decimal(15,2) | 0.00 | CACHE of scholarship rows (§78) |
| `net_amount` | decimal(15,2) | 0.00 | `gross - discount - scholarship`, written only by `StudentFeeService` via `Money` |
| `paid_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(student_fee_payments.amount)` for non-voided receipts |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(payment_reversals.amount)` through its receipts |
| `balance_amount` | decimal(15,2) | 0.00 | CACHE `net - (paid - refunded)`; **may be negative** = advance/overpayment |
| `has_installment_plan` | boolean | false | §42, §77 |
| `installment_count` | tinyint unsigned | 0 | |
| `due_date` | date | nullable | drives `overdue` |
| `status` | string(32) | `pending` | cast `StudentFeeStatus` |
| `collaborator_id` | FK `collaborators.id` | nullable | `nullOnDelete`. **Display snapshot only** (§41 prints the collaborator). The engine NEVER reads it; it resolves the referral effective on the payment date |
| `notes` | text | nullable | |
| `cancelled_at` | timestamp | nullable | |
| `cancelled_by` | FK `users.id` | nullable | `nullOnDelete` |
| `cancellation_reason` | string(255) | nullable | mandatory when cancelling |
| `created_at` / `updated_at` | timestamps | nullable | |
| `deleted_at` | timestamp | nullable | softDeletes; the Policy returns false once any receipt exists |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_sf_number(fee_number)` - a charge must be quotable in a dispute, and two cashiers
issuing at the same moment cannot share a number (the loser retries with the next counter value).
`UNIQUE uq_sf_generation(generation_key)` - MariaDB unique indexes ignore NULLs, so this bites on
generated charges only and is what makes both Phase 18 generators duplicate-proof by INSERT (F-3.15).
`INDEX (student_id, status)` student panel + pending-fee widget; `INDEX (student_admission_id)` the
entitlement grain lookup; `INDEX (batch_id, status)`; `INDEX (due_date, status)` overdue sweeper;
`INDEX (branch_id)` D11; `INDEX (collaborator_id)` §57 list.
**CHECK** `chk_sf_nonneg`: `gross_amount >= 0 AND discount_amount >= 0 AND scholarship_amount >= 0 AND net_amount >= 0 AND paid_amount >= 0 AND refunded_amount >= 0`.
**CHECK** `chk_sf_discount_ceiling`: `discount_amount + scholarship_amount <= gross_amount` - a discount can never exceed the fee.
**Relationships.** belongsTo `Student`, `StudentAdmission`, `Course`, `Batch`, `Branch`, `Collaborator`,
`User` (`canceller`, `creator`, `editor`); hasMany `StudentFeeInstallment`, `StudentFeePayment`,
`StudentFeeDiscount`; hasManyThrough `PaymentReversal` via `StudentFeePayment`; hasMany
`CollaboratorCommissionLedgerEntry` (by `student_fee_id`, for the commission trail tab).

### 2.3 `student_fee_installments`

A schedule line is a promise, not money. The engine never reads it; receipts point at it so "commission
follows the actual installment payment" (§77) is provable.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_fee_id` | FK `student_fees.id` | not null | `restrictOnDelete` |
| `installment_no` | tinyint unsigned | not null | 1..n |
| `amount` | decimal(15,2) | not null | scheduled amount |
| `due_date` | date | not null | |
| `paid_amount` | decimal(15,2) | 0.00 | CACHE of receipts allocated here, net of reversals |
| `waived_amount` | decimal(15,2) | 0.00 | §78 concession |
| `paid_on` | date | nullable | date the line became fully paid |
| `status` | string(32) | `pending` | cast `InstallmentStatus` |
| `notes` | string(255) | nullable | |
| timestamps / `deleted_at` / blameable | | | softDeletes blocked by policy once `paid_amount > 0` |

**Keys.** `UNIQUE uq_sfi_no(student_fee_id, installment_no)` - a retried "generate plan" cannot create
installment 2 twice, and "the third installment" is an unambiguous phrase in a dispute.
`INDEX (due_date, status)` reminder job; `INDEX (student_fee_id, status)`.
**CHECK** `chk_sfi_amount`: `amount > 0 AND paid_amount >= 0 AND waived_amount >= 0`.
A deliberate non-constraint: `paid_amount <= amount` is **not** enforced - over-allocating an
installment is legal and lands on the charge as an advance; `PaymentService` caps the allocation instead.
**Relationships.** belongsTo `StudentFee`; hasMany `StudentFeePayment`.

### 2.4 `student_fee_discounts`

Append-only history of every discount, scholarship, waiver and correction (§43, §78). It is what lets the
engine answer "what was the net fee on the date of payment X" without mutating anything.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `student_fee_id` | FK `student_fees.id` | not null | `restrictOnDelete` |
| `type` | string(32) | not null | cast `FeeDiscountType` |
| `amount` | decimal(15,2) | not null | **SIGNED delta to `net_amount`**: reductions negative, `correction` positive, `reversal` the opposite sign of the row it undoes |
| `percentage` | decimal(8,4) | nullable | only for `percentage_discount`; `amount` still stores the computed result so the arithmetic is never re-done |
| `reason` | string(255) | not null | §78 requires a reason |
| `approved_by` | FK `users.id` | nullable | `nullOnDelete` (§78 "approved by") |
| `approved_by_name` | string(150) | nullable | snapshot, immune to user deletion |
| `approved_at` | timestamp | nullable | |
| `effective_on` | date | not null | business date; compared against `paid_on` for reporting |
| `reverses_discount_id` | FK self | nullable | `restrictOnDelete` - a wrong discount is reversed, never edited |
| `idempotency_key` | string(64) | not null | a double-submitted discount form cannot discount twice |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_sfd_idem(idempotency_key)`; `UNIQUE uq_sfd_reverses(reverses_discount_id)` - an
adjustment can be reversed at most once; `INDEX (student_fee_id, effective_on)`; `INDEX (type)`.
**CHECK** `chk_sfd_nonzero`: `amount <> 0`. **CHECK** `chk_sfd_pct`: `percentage IS NULL OR (percentage > 0 AND percentage <= 100)`.
**Trigger** `trg_sfd_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `StudentFee`, `User` (approver); belongsTo/hasOne self (`reverses` / `reversedBy`).

### 2.5 `student_fee_payments`

One physical receipt of student money - the **only** student-side commission trigger (§39, §40, §42).
Append-only: a mistake is voided or refunded, never edited.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `receipt_no` | string(32) | not null | `institute.fee_receipt_prefix` + counter |
| `idempotency_key` | string(64) | not null | one ULID per opened modal / per `Idempotency-Key` header; layer 0 of §2.19 |
| `duplicate_fingerprint` | string(64) | not null | `sha1(fee|installment|amount|paid_on|method|reference)`, **non-unique**: warns a second cashier that an identical receipt exists |
| `student_fee_id` | FK `student_fees.id` | not null | `restrictOnDelete` |
| `student_fee_installment_id` | FK `student_fee_installments.id` | nullable | `restrictOnDelete`; null = ad-hoc/unallocated |
| `student_id` | FK `students.id` | not null | `restrictOnDelete`; denormalised for panel scoping - the service asserts it equals the charge's student |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `amount` | decimal(15,2) | not null | money actually received, always positive |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(payment_reversals.amount)` |
| `net_received_amount` | decimal(15,2) | **generated STORED** | `amount - refunded_amount`; income reports sum this so they can never forget the refund |
| `payment_method` | string(32) | not null | cast `PaymentMethod` - the snapshot of record (§32) |
| `payment_method_id` | FK `payment_methods.id` | nullable | `nullOnDelete`, Phase 13, guarded migration |
| `reference_no` | string(64) | nullable | cheque / deposit slip / wallet reference |
| `gateway_txn_id` | string(100) | nullable | external id; UNIQUE so one gateway capture cannot become two receipts |
| `paid_on` | date | not null | **VALUE DATE**; may be back-dated; selects the rule and the referral |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | **SYSTEM DATE**; both are always kept |
| `status` | string(32) | `cleared` | cast `ReceivedPaymentStatus` |
| `collaborator_id` | FK `collaborators.id` | nullable | `restrictOnDelete`; the collaborator actually resolved at posting time (null = none) |
| `collaborator_referral_id` | FK `collaborator_referrals.id` | nullable | `restrictOnDelete`; the exact attribution row used |
| `commission_state` | string(24) | `queued` | cast `CommissionProcessingState`; a **progress hint and sweeper index only**, never the duplicate guard |
| `commission_skip_reason` | string(48) | nullable | cast `CommissionSkipReason` - groupable in a report |
| `commission_skip_detail` | string(191) | nullable | the human sentence shown in the UI |
| `commission_attempts` | smallint unsigned | 0 | |
| `commission_processed_at` | timestamp | nullable | |
| `received_by` | FK `users.id` | nullable | `nullOnDelete`, the cashier |
| `received_by_name` | string(150) | nullable | snapshot |
| `receipt_path` | string(255) | nullable | scan of the signed receipt |
| `notes` | string(255) | nullable | |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_sfp_receipt(receipt_no)`; `UNIQUE uq_sfp_idem(idempotency_key)` - the receipt-level
duplicate guard, so a replayed POST is idempotent end to end; `UNIQUE uq_sfp_gateway(gateway_txn_id)`.
`INDEX (student_fee_id, status)`; `INDEX (student_id, paid_on)`; `INDEX (student_fee_installment_id)`;
`INDEX (commission_state, id)` - the sweeper must never scan; `INDEX (collaborator_id, paid_on)` §57 and
the statement; `INDEX (paid_on)` daily collection report; `INDEX (duplicate_fingerprint)`; `INDEX (branch_id)`.
**CHECK** `chk_sfp_amount`: `amount > 0`.
**CHECK** `chk_sfp_refund_ceiling`: `refunded_amount >= 0 AND refunded_amount <= amount` (INV-9).
**Trigger** `trg_sfp_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `StudentFee`, `StudentFeeInstallment`, `Student`, `Branch`, `Collaborator`,
`CollaboratorReferral`, `PaymentMethod`, `User` (`receivedBy`); hasMany `PaymentReversal`,
`CollaboratorCommissionLedgerEntry`.

### 2.6 `project_payments`

One client payment against a project, optionally a milestone and/or an invoice (§46-48) - the **only**
project-side commission trigger. Deliberately the same shape as `student_fee_payments` so both engines
share one mental model and one set of guarantees.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `payment_no` | string(32) | not null | `finance.project_payment_prefix` + counter |
| `idempotency_key` | string(64) | not null | layer 0 guard |
| `duplicate_fingerprint` | string(64) | not null | `sha1(project|milestone|amount|paid_on|method|reference)`, non-unique |
| `project_id` | FK `projects.id` | not null | `restrictOnDelete` |
| `client_id` | FK `clients.id` | not null | `restrictOnDelete`; denormalised for client-panel scoping |
| `project_milestone_id` | FK `project_milestones.id` | nullable | `nullOnDelete`; **required** when the base is `milestone` |
| `invoice_id` | FK `invoices.id` | nullable | `nullOnDelete`, Phase 13, guarded migration |
| `amount` | decimal(15,2) | not null | positive |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE |
| `net_received_amount` | decimal(15,2) | **generated STORED** | `amount - refunded_amount` |
| `is_advance` | boolean | false | received before any invoice exists |
| `payment_method` | string(32) | not null | cast `PaymentMethod` |
| `payment_method_id` | FK `payment_methods.id` | nullable | `nullOnDelete` |
| `reference_no` | string(64) | nullable | |
| `gateway_txn_id` | string(100) | nullable | UNIQUE |
| `paid_on` | date | not null | VALUE DATE |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | SYSTEM DATE |
| `status` | string(32) | `cleared` | cast `ReceivedPaymentStatus` |
| `collaborator_id` | FK `collaborators.id` | nullable | `restrictOnDelete` |
| `collaborator_referral_id` | FK `collaborator_referrals.id` | nullable | `restrictOnDelete` |
| `commission_state` / `commission_skip_reason` / `commission_skip_detail` / `commission_attempts` / `commission_processed_at` | as §2.5 | | identical semantics |
| `received_by` / `received_by_name` / `receipt_path` / `notes` | as §2.5 | | |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_pp_number(payment_no)`, `UNIQUE uq_pp_idem(idempotency_key)`,
`UNIQUE uq_pp_gateway(gateway_txn_id)`. `INDEX (project_id, status)`; `INDEX (client_id, paid_on)`
client panel; `INDEX (project_milestone_id)`; `INDEX (invoice_id)` - Phase 13 derives
`invoices.paid_amount` from here; `INDEX (commission_state, id)`; `INDEX (collaborator_id, paid_on)` §58;
`INDEX (paid_on)`; `INDEX (duplicate_fingerprint)`.
**CHECK** `chk_pp_amount`: `amount > 0`. **CHECK** `chk_pp_refund_ceiling`: `refunded_amount >= 0 AND refunded_amount <= amount`.
**Trigger** `trg_pp_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `Project`, `Client`, `ProjectMilestone`, `Invoice`, `Collaborator`,
`CollaboratorReferral`, `PaymentMethod`, `User` (`receivedBy`); hasMany `PaymentReversal`,
`CollaboratorCommissionLedgerEntry`.

### 2.7 `payment_reversals`

The single append-only table for every un-doing of received money (§44, §49): full refund, partial
refund, cancellation, void of a mis-keyed receipt, bounced instrument, correction. It is itself a
commission trigger, so reversal commissions get exactly the same idempotency guarantees as earnings.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `reversal_no` | string(32) | not null | `finance.payment_reversal_prefix` + counter |
| `idempotency_key` | string(64) | not null | a double-clicked Refund cannot refund twice |
| `student_fee_payment_id` | FK `student_fee_payments.id` | nullable | `restrictOnDelete` |
| `project_payment_id` | FK `project_payments.id` | nullable | `restrictOnDelete` |
| `type` | string(32) | not null | cast `ReversalType` |
| `amount` | decimal(15,2) | not null | positive magnitude of money going back out; direction is implied by the table |
| `reason` | string(255) | not null | §44 demands a reason |
| `refund_method` | string(32) | nullable | cast `PaymentMethod` - how the money went back |
| `reference_no` | string(64) | nullable | refund transaction reference (§44) |
| `occurred_on` | date | not null | business date; becomes the reversal entry's `transaction_date` |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | system date |
| `approval_status` | string(16) | `not_required` | cast `ReversalApprovalStatus`; `pending` when `finance.refund_approval_required` or the amount exceeds the threshold |
| `approved_by` | FK `users.id` | nullable | `nullOnDelete` |
| `approved_at` | timestamp | nullable | |
| `rejected_at` / `rejection_reason` | timestamp / string(255) | nullable | |
| `performed_by` | FK `users.id` | nullable | `nullOnDelete` - §44 "performer" |
| `performed_by_name` | string(150) | not null | snapshot, immune to user deletion |
| `attachment_path` | string(255) | nullable | refund voucher scan |
| `commission_state` / `commission_skip_reason` / `commission_skip_detail` / `commission_attempts` / `commission_processed_at` | as §2.5 | | |
| `notes` | string(255) | nullable | |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_pr_number(reversal_no)`; `UNIQUE uq_pr_idem(idempotency_key)`;
`INDEX (student_fee_payment_id)`; `INDEX (project_payment_id)`; `INDEX (commission_state, id)`;
`INDEX (occurred_on, type)`; `INDEX (approval_status)`.
**CHECK** `chk_pr_one_target`: `(student_fee_payment_id IS NOT NULL) + (project_payment_id IS NOT NULL) = 1`
- a reversal can never dangle or double-target. **CHECK** `chk_pr_amount`: `amount > 0`.
The per-payment ceiling is enforced on the payment row (INV-9) by a conditional UPDATE in the same
transaction, so the sum of reversals can never exceed what was received.
**Trigger** `trg_pr_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `StudentFeePayment`, `ProjectPayment`, `User` (`performer`, `approver`);
hasMany `CollaboratorCommissionLedgerEntry` (the negative rows it caused).

### 2.8 `collaborator_referrals`

Who referred whom, on what authority, and for which window of time (§37, §38, §45). Append-only and
versioned: changing a subject's collaborator supersedes instead of overwriting, so a ledger row can
always prove which attribution caused it even after an admin switches collaborators.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `subject_type` | string(16) | not null | cast `ReferralSubject` - explicit, for indexes and reports |
| `student_id` | FK `students.id` | nullable | `restrictOnDelete` |
| `project_id` | FK `projects.id` | nullable | `restrictOnDelete` |
| `client_id` | FK `clients.id` | nullable | `restrictOnDelete` |
| `lead_id` | FK `leads.id` | nullable | `nullOnDelete` (a lead is not financial evidence) |
| `commission_for` | string(16) | nullable | cast `CommissionScope`; `student` for student subjects, `project` for project subjects, null for lead/client |
| `referral_code` | string(32) | not null | **SNAPSHOT** of the code used; a later code change never rewrites history |
| `referral_source` | string(32) | not null | cast `ReferralSource` (§37) |
| `referral_date` | date | not null | §37 |
| `effective_from` | date | not null | commission eligibility starts here; a payment with `paid_on >= effective_from` can credit this collaborator |
| `effective_to` | date | nullable | inclusive; set when superseded or revoked; null = open |
| `commission_eligible` | boolean | true | false stops **future** commission and keeps all history |
| `status` | string(16) | `active` | cast `ReferralStatus` (active / superseded / revoked) |
| `landing_url` | string(255) | nullable | §38 `/admission?ref=COL-1024` |
| `ip_address` | string(45) | nullable | |
| `user_agent` | text | nullable | |
| `referral_visit_id` | FK `collaborator_referral_visits.id` | nullable | `nullOnDelete`, Phase 9, guarded migration |
| `previous_referral_id` | FK self | nullable | `nullOnDelete` - §37 "store old and new collaborator" |
| `superseded_by_id` | FK self | nullable | `nullOnDelete` - forward pointer to the winner; **plain non-unique index** (`idx_cr_superseded_by`), because one winner may supersede several rows (ND-12, see Keys) |
| `superseded_at` | timestamp | nullable | |
| `change_reason` | string(255) | nullable | §37 requires a reason on change - mandatory in the Form Request |
| `changed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `current_guard` | tinyint | **generated STORED** | `CASE WHEN status = 'active' THEN 1 ELSE NULL END` - exists only to carry the unique indexes below |
| `notes` | string(255) | nullable | |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_cr_student_current(student_id, current_guard)`,
`uq_cr_project_current(project_id, current_guard)`, `uq_cr_client_current(client_id, current_guard)`,
`uq_cr_lead_current(lead_id, current_guard)` - because MariaDB unique indexes ignore NULLs, superseded
and revoked rows stack freely while **at most one active referral per subject** can exist; a race between
a `?ref=` URL and a receptionist's manual selection therefore resolves to exactly one attribution and the
loser is recorded as a superseded row with its reason.
**`INDEX idx_cr_superseded_by(superseded_by_id)` - a plain index, deliberately NOT unique (ND-12).**
One winner legitimately supersedes **several** rows: `change()` supersedes the previously `active` referral
and points it at the winner, and `recordLosingCandidate()` inserts one superseded row per losing candidate
pointing at the **same** winner. A unique index here would raise 1062 on the second of those perfectly legal
writes and the attribution evidence would be lost - exactly the history this table exists to keep. The
column is a **navigation pointer** (this row -> the row that replaced it), **not a guarantee**, and no
invariant rests on it.
**Which guarantee carries "one active referral per subject":** the four `uq_cr_*_current` indexes over the
generated `current_guard` column above, and only those (INV-18). They are unaffected by how many rows share
a `superseded_by_id`, because a superseded row has `current_guard = NULL` and leaves the active slot free.
`INDEX (collaborator_id, referral_date)`, `(referral_code)`,
`(subject_type, status)`, `(student_id, effective_from)`, `(project_id, effective_from)`.
**CHECK** `chk_cr_one_subject`: exactly one of the four subject FKs is non-null.
**CHECK** `chk_cr_dates`: `effective_to IS NULL OR effective_to >= effective_from`.
**CHECK** `chk_cr_closed`: `status = 'active' OR effective_to IS NOT NULL` - a non-active referral always
has a closed window, which is what makes date-based resolution deterministic.
**Trigger** `trg_cr_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `Collaborator`, `Student`, `Project`, `Client`, `Lead`,
`CollaboratorReferralVisit`, `User` (`changedBy`); belongsTo self (`previousReferral`), hasOne self
(`supersededBy`); hasMany `CollaboratorCommissionEntitlement`, `CollaboratorCommissionLedgerEntry`,
`StudentFeePayment`, `ProjectPayment`.

### 2.9 `collaborator_commission_settings`

Effective-dated, **immutable** commission rule versions per collaborator per scope (§35). A rate change
is a new version, so no past commission can move.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `commission_for` | string(16) | not null | cast `CommissionScope`; the two sides are configured independently (§35) |
| `is_enabled` | boolean | true | §35 "student/project commission enabled" |
| `calculation_type` | string(16) | not null | cast `CommissionCalculationType` (`percentage` / `fixed`) |
| `rate` | decimal(8,4) | nullable | `10.0000` = 10%; required when percentage |
| `fixed_amount` | decimal(15,2) | nullable | required when fixed |
| `fixed_release` | string(24) | nullable | cast `FixedCommissionRelease`; null = inherit `collaborator.fixed_commission_release` |
| `base_override` | string(32) | nullable | cast `CommissionBase`; null = use the global base setting |
| `applies_to_fee_types` | json | nullable | null = use `collaborator.commissionable_fee_types` |
| `applies_to_milestone_ids` | json | nullable | only when the base is `milestone` |
| `min_payment_amount` | decimal(15,2) | nullable | ignore dust receipts; null = no floor |
| `max_commission_amount` | decimal(15,2) | nullable | per-document cap; null = uncapped |
| `effective_from` | date | not null | inclusive (§35) |
| `effective_to` | date | nullable | inclusive; null = open-ended (§35). The only money-adjacent column the model hook permits to change, and only from NULL to a date |
| `open_guard` | tinyint | **generated STORED** | `CASE WHEN effective_to IS NULL THEN 1 ELSE NULL END` |
| `status` | string(32) | `active` | cast `CommissionRuleStatus` (§35 "commission status") |
| `version` | unsignedInteger | 1 | monotonic per (collaborator, scope) |
| `supersedes_id` | FK self | nullable | `nullOnDelete`, the version chain |
| `superseded_at` | timestamp | nullable | |
| `change_reason` | string(255) | nullable | §107 audit ("10% -> 15% because ...") - mandatory in the Form Request when a previous version exists |
| `approved_by` / `approved_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `notes` | string(255) | nullable | |
| timestamps / blameable | | | **no `deleted_at`** (D16); RESTRICT FKs make deletion impossible anyway |

**Keys.** `UNIQUE uq_ccs_start(collaborator_id, commission_for, effective_from)` - two versions cannot
start on the same day, so "the rule on 2026-04-02" is never ambiguous.
`UNIQUE uq_ccs_open(collaborator_id, commission_for, open_guard)` - at most **one** open-ended version per
collaborator per scope; two concurrent edits cannot both leave an open rule, so the resolver can never
find two candidates. `UNIQUE uq_ccs_version(collaborator_id, commission_for, version)`.
`INDEX idx_ccs_lookup(collaborator_id, commission_for, effective_from, effective_to)` - the resolver's
only query.
**CHECK** `chk_ccs_dates`: `effective_to IS NULL OR effective_to >= effective_from`.
**CHECK** `chk_ccs_rate`: `rate IS NULL OR (rate >= 0 AND rate <= 100)`.
**CHECK** `chk_ccs_fixed`: `fixed_amount IS NULL OR fixed_amount >= 0`.
**CHECK** `chk_ccs_payload`: `(calculation_type = 'percentage' AND rate IS NOT NULL AND fixed_amount IS NULL) OR (calculation_type = 'fixed' AND fixed_amount IS NOT NULL AND rate IS NULL)` - a rule can never be saved without the number it needs.
Non-overlap of **closed** ranges is enforced by `CommissionRuleService` inside a transaction holding
`lockForUpdate` on the collaborator row, and closing a version is refused when any ledger row references
it with `transaction_date > effective_to`.
**Trigger** `trg_ccs_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `Collaborator`, `User` (approver); belongsTo/hasOne self
(`supersedes` / `supersededBy`); hasMany `CollaboratorCommissionEntitlement`,
`CollaboratorCommissionLedgerEntry`.

### 2.10 `collaborator_commission_entitlements`

The contract layer between a rule and the releases: **one current row per (collaborator, commission
document)** capturing what was promised - type, rate, base, document figure, collectible figure and the
capped total - so a fixed amount or a document-level base (gross fee, net after discount, project total
value, milestone) is released in proportion to money actually received and can never exceed the promise.
It is also the row that is locked to serialise concurrent payments on the same document.

An entitlement is opened for **every** commission, including the default `paid` base, where
`entitlement_amount` is NULL (uncapped - each receipt simply earns its percentage). One uniform code path
is safer than a conditional one. **[D-FS-7]**

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `collaborator_referral_id` | FK `collaborator_referrals.id` | not null | `restrictOnDelete` - immutable proof of the attribution |
| `commission_setting_id` | FK `collaborator_commission_settings.id` | nullable | `restrictOnDelete`; null when `rule_source` is not `collaborator_rule` |
| `rule_source` | string(24) | not null | cast `CommissionRuleSource` (`collaborator_rule` / `project_override` / `manual`) — **three cases only**: there is no `global_default` case and no silent fallback to a global default rate (F-5.6, [D-FS-9]) |
| `document_type` | string(24) | not null | cast `EntitlementDocumentType` |
| `student_admission_id` | FK `student_admissions.id` | nullable | `restrictOnDelete` |
| `student_fee_id` | FK `student_fees.id` | nullable | `restrictOnDelete` |
| `project_id` | FK `projects.id` | nullable | `restrictOnDelete` |
| `project_milestone_id` | FK `project_milestones.id` | nullable | `restrictOnDelete` (used when the base is `milestone`) |
| `document_key` | string(64) | **generated STORED** | `CONCAT(document_type, ':', COALESCE(student_admission_id, student_fee_id, project_milestone_id, project_id))` - a non-null key so the unique index below actually bites |
| `commission_for` | string(16) | not null | cast `CommissionScope` |
| `calculation_type` | string(16) | not null | SNAPSHOT |
| `commission_rate` | decimal(8,4) | nullable | SNAPSHOT |
| `fixed_amount` | decimal(15,2) | nullable | SNAPSHOT |
| `fixed_release` | string(24) | nullable | SNAPSHOT |
| `commission_base` | string(32) | not null | SNAPSHOT cast `CommissionBase`, resolved from rule override or global setting at opening |
| `approval_mode` | string(16) | not null | SNAPSHOT cast `CommissionApprovalMode` |
| `hold_days` | smallint unsigned | 0 | SNAPSHOT of `collaborator.commission_hold_days` |
| `document_base_amount` | decimal(15,2) | 0.00 | the figure the promise was computed from (gross fee, net fee, project value, milestone amount) |
| `collectible_amount` | decimal(15,2) | 0.00 | the **denominator** of proportional release: what is expected to be collected against this document |
| `entitlement_amount` | decimal(15,2) | nullable | the capped total promise; **NULL = uncapped** (percentage on actual paid) |
| `max_commission_amount` | decimal(15,2) | nullable | SNAPSHOT of the rule cap |
| `released_amount` | decimal(15,2) | 0.00 | CACHE of credit releases posted against this entitlement |
| `reversed_amount` | decimal(15,2) | 0.00 | CACHE of reversals/clawbacks against its entries |
| `collected_amount` | decimal(15,2) | 0.00 | CACHE of commissionable money counted so far (the numerator) |
| `over_released_amount` | decimal(15,2) | 0.00 | set when a supersede floors the promise below what was already released (§6.6 rows 4, 8) |
| `ledger_entry_count` | int unsigned | 0 | |
| `status` | string(32) | `open` | cast `EntitlementStatus` |
| `opened_on` | date | not null | |
| `closed_on` | date | nullable | |
| `supersedes_id` | FK self | nullable | `nullOnDelete` |
| `superseded_at` | timestamp | nullable | |
| `supersede_reason` | string(255) | nullable | mandatory when superseding |
| `current_guard` | tinyint | **generated STORED** | `CASE WHEN superseded_at IS NULL THEN 1 ELSE NULL END` |
| `rule_snapshot` | json | not null | the rule row plus every global setting used, frozen at opening |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_cce_current(document_key, collaborator_id, current_guard)` - at most one current
entitlement per document per collaborator: two concurrent first payments on the same admission cannot
open two promises, so a fixed amount cannot be promised twice.
`INDEX (collaborator_id, status)`, `(document_key)`, `(commission_setting_id)`,
`(collaborator_referral_id)`, `(status, over_released_amount)` - the discrepancy queue (§8 screen 8).
**CHECK** `chk_cce_one_doc`: exactly one of the four document FKs is non-null.
**CHECK** `chk_cce_cap`: `entitlement_amount IS NULL OR released_amount <= entitlement_amount` - **the
structural over-release ceiling (INV-12)**: even a future developer calling the engine from a new place
with a bug in the proportional maths cannot push total commission past the promise; the UPDATE fails.
**CHECK** `chk_cce_nonneg`: `released_amount >= 0 AND collected_amount >= 0 AND reversed_amount >= 0 AND over_released_amount >= 0 AND collectible_amount >= 0`.
**Trigger** `trg_cce_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `Collaborator`, `CollaboratorReferral`, `CollaboratorCommissionSetting`,
`StudentAdmission`, `StudentFee`, `Project`, `ProjectMilestone`; belongsTo/hasOne self; hasMany
`CollaboratorCommissionLedgerEntry`.

### 2.11 `collaborator_commission_ledger_entries`

**The spine** (§51). Every movement of entitlement - earning, reversal, clawback, manual adjustment,
write-off - is one immutable row that permanently records which transaction caused it, which rule and
rate applied, and the exact bcmath trace.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK. The human reference is the accessor `reference` = `'CLE-' . id` (§51 "Ledger ID") |
| `dedupe_key` | string(191) | not null | **THE** duplicate guard, composed only inside `LedgerWriter` (§2.19) |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `collaborator_wallet_id` | FK `collaborator_wallets.id` | not null | `restrictOnDelete`; makes every wallet aggregate a single-table index scan |
| `entitlement_id` | FK `collaborator_commission_entitlements.id` | nullable | `restrictOnDelete`; null only for manual adjustments and write-offs |
| `collaborator_referral_id` | FK `collaborator_referrals.id` | nullable | `restrictOnDelete` - which attribution earned it |
| `commission_setting_id` | FK `collaborator_commission_settings.id` | nullable | `restrictOnDelete` - **the rule that produced money can never be deleted** |
| `rule_source` | string(24) | nullable | cast `CommissionRuleSource` |
| `entry_type` | string(8) | not null | cast `LedgerEntryType` (credit / debit) - §51 |
| `purpose` | string(32) | not null | cast `LedgerEntryPurpose` |
| `source_type` | string(32) | not null | cast `CommissionSourceType` - §51 source types |
| `source_id` | bigint unsigned | not null | the causing row id; **NOT NULL** so the composite unique index cannot be defeated by NULLs |
| `student_fee_payment_id` | FK `student_fee_payments.id` | nullable | `restrictOnDelete` - §52's required immutable reference |
| `project_payment_id` | FK `project_payments.id` | nullable | `restrictOnDelete` - §52 |
| `payment_reversal_id` | FK `payment_reversals.id` | nullable | `restrictOnDelete` |
| `reverses_entry_id` | FK self | nullable | `restrictOnDelete` - §44 "reference to the original commission" |
| `student_id` | FK `students.id` | nullable | `restrictOnDelete` - report filter |
| `student_fee_id` | FK `student_fees.id` | nullable | `restrictOnDelete` |
| `project_id` | FK `projects.id` | nullable | `restrictOnDelete` |
| `project_milestone_id` | FK `project_milestones.id` | nullable | `nullOnDelete` |
| `gross_amount` | decimal(15,2) | 0.00 | §51 gross amount: the source transaction's full amount |
| `commission_base` | string(32) | not null | cast `CommissionBase` - which base rule applied at that moment |
| `base_amount` | decimal(15,2) | 0.00 | §41 commissionable amount actually used |
| `calculation_type` | string(16) | not null | cast `CommissionCalculationType` (`percentage` / `fixed` / `manual`) |
| `commission_rate` | decimal(8,4) | nullable | the rate applied, snapshotted |
| `fixed_amount` | decimal(15,2) | nullable | the fixed promise, snapshotted |
| `entitlement_total` | decimal(15,2) | nullable | the promise at posting time |
| `released_before` | decimal(15,2) | nullable | cumulative released before this row - makes the proportional arithmetic auditable from the row alone |
| `amount` | decimal(15,2) | not null | **positive magnitude**, `CHECK > 0` |
| `signed_amount` | decimal(15,2) | **generated STORED** | `credit -> +amount`, `debit -> -amount`. The only column anything ever sums |
| `rule_snapshot` | json | not null | frozen rule row + every global setting used + the calculation trace (base, rate, entitlement total, prior released, numerator/denominator, released now, rounding residual) |
| `status` | string(32) | `pending` | cast `CommissionStatus` (§51) |
| `approval_mode` | string(16) | not null | snapshot, so a later setting change never rewrites the meaning of a past row |
| `approved_by` / `approved_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `hold_until` | date | nullable | from the snapshotted hold days; null or past = immediately available |
| `available_at` | timestamp | nullable | |
| `paid_at` | timestamp | nullable | set when fully allocated to a paid payout |
| `allocated_amount` | decimal(15,2) | 0.00 | how much a payout has claimed |
| `reversed_amount` | decimal(15,2) | 0.00 | magnitude reversed **while unpaid** |
| `reversed_at` | timestamp | nullable | set when fully reversed |
| `clawed_back_amount` | decimal(15,2) | 0.00 | magnitude reversed **after** it was paid out (§6.3.5) |
| `cancelled_at` / `cancelled_by` / `cancel_reason` | timestamp / FK `users.id` / string(255) | nullable | rejection and cancellation, reason mandatory |
| `transaction_date` | date | not null | BUSINESS date (`paid_on` / `occurred_on`); statements and effective dating use this |
| `posted_at` | timestamp | CURRENT_TIMESTAMP | system time; explains why a closed month's statement changed |
| `currency` | char(3) | `PKR` | single currency today; a constant column so a future split is additive |
| `notes` | string(255) | nullable | §51 notes / reversal reason |
| timestamps / blameable | | | **no `deleted_at`** (D16) |

**Keys and constraints.**

| Key | Reason it exists |
|---|---|
| `UNIQUE uq_cle_dedupe(dedupe_key)` | the canonical duplicate guard - the INSERT **is** the test, so concurrency, HTTP retries and replayed queue jobs all collapse onto one row |
| `UNIQUE uq_cle_source(source_type, source_id, collaborator_id, purpose, source_guard)` | the semantic restatement of §52, with the first four columns NOT NULL so MariaDB actually enforces it. A nullable-column guard such as `(fee_payment_id, project_payment_id, collaborator_id, source_type)` would let **every** project commission through, because they all share `fee_payment_id = NULL` and unique indexes ignore NULLs. This index makes a second commission for the same receipt impossible even if somebody hand-crafts a different `dedupe_key`. `source_guard` is generated — `1` for every purpose that has a causing row, **NULL for `manual_adjustment` and `write_off`** — so those two drop out of the index entirely. They have no source transaction to be unique about (§2.19 gives them a fresh `manual:{ulid}` key each time), and without the guard `source_id` falls back to the collaborator and a partner could receive exactly one adjustment, for ever. Added by migration `2026_09_12_130023` |
| `UNIQUE uq_cle_reversal_pair(payment_reversal_id, reverses_entry_id, purpose)` | one reversal row can undo a given original exactly once **per purpose**. `purpose` is in the key because a single reversal legitimately posts two debits against one original when part of the commission has already been paid out — a `reversal` for the unpaid part and a `clawback` for the rest (§6.6, §2.19). The two-column form shipped first and made that case a 1062; corrected by migration `2026_09_12_130022` |
| `INDEX (collaborator_wallet_id, status)` | the wallet derivation query |
| `INDEX (collaborator_id, status, transaction_date)` | approval queue, ageing, point-in-time movement |
| `INDEX (collaborator_id, purpose, transaction_date)` | the §56 statement grouping |
| `INDEX (collaborator_id, status, id)` | FIFO payout allocation |
| `INDEX (status, hold_until)` | the release-held job |
| `INDEX (student_fee_payment_id)`, `(project_payment_id)`, `(payment_reversal_id)`, `(reverses_entry_id)`, `(entitlement_id)`, `(source_type, source_id)`, `(student_id)`, `(project_id)` | the commission trail on every document and the reversal lookups |
| `CHECK chk_cle_amount` | `amount > 0` |
| `CHECK chk_cle_sign` | `(purpose IN ('student_commission','project_commission','write_off') AND entry_type = 'credit') OR (purpose IN ('reversal','clawback') AND entry_type = 'debit') OR purpose = 'manual_adjustment'` - purpose and direction can never disagree |
| `CHECK chk_cle_reverses` | `purpose NOT IN ('reversal','clawback') OR reverses_entry_id IS NOT NULL` |
| `CHECK chk_cle_debit_clean` | `entry_type = 'credit' OR (allocated_amount = 0 AND reversed_amount = 0 AND clawed_back_amount = 0)` - a debit is never allocated or reversed; undoing a debit is a new credit adjustment |
| `CHECK chk_cle_allocation_ceiling` | `allocated_amount >= 0 AND allocated_amount + reversed_amount <= amount` - **INV-11**: no double payout, and no payout of a reversed amount |
| `CHECK chk_cle_undo_ceiling` | `reversed_amount >= 0 AND clawed_back_amount >= 0 AND reversed_amount + clawed_back_amount <= amount` - **INV-10** |
| `CHECK chk_cle_rate` | `calculation_type <> 'percentage' OR commission_rate IS NOT NULL` |
| `CHECK chk_cle_base` | `base_amount >= 0 AND gross_amount >= 0` |
| `Trigger trg_cle_no_delete` | `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`: no service, migration or DBA can delete a commission and free its unique slot for a second one |

**Relationships.** belongsTo `Collaborator`, `CollaboratorWallet`, `CollaboratorCommissionEntitlement`,
`CollaboratorReferral`, `CollaboratorCommissionSetting`, `StudentFeePayment`, `ProjectPayment`,
`PaymentReversal`, `Student`, `StudentFee`, `Project`, `ProjectMilestone`, `User` (approver, canceller);
belongsTo self (`original`) / hasMany self (`reversals`); hasMany `CollaboratorPayoutAllocation`;
**belongsToMany `CollaboratorPayout` through the pivot `collaborator_payout_allocations`**.

### 2.12 `collaborator_wallets`

A pure **cache** of the ledger (§50), one row per collaborator, so dashboards and lists never aggregate
millions of rows. It holds no truth of its own: every column is defined by an exact SQL expression in
§6.5 and is proven nightly.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete`; created by a Phase 8 observer in the same transaction as the collaborator, so no payment path ever creates a wallet lazily |
| `currency` | char(3) | `PKR` | |
| `pending_balance` | decimal(15,2) | 0.00 | earned, not yet payable (`pending` + `approved`) |
| `available_balance` | decimal(15,2) | 0.00 | spendable; **may be negative** after a clawback |
| `reserved_balance` | decimal(15,2) | 0.00 | allocated to payouts still in flight |
| `paid_balance` | decimal(15,2) | 0.00 | allocated to paid payouts |
| `lifetime_earned` | decimal(15,2) | 0.00 | net of reversals and clawbacks |
| `total_student_commission` | decimal(15,2) | 0.00 | §50 |
| `total_project_commission` | decimal(15,2) | 0.00 | §50 |
| `total_adjustments` | decimal(15,2) | 0.00 | §50 |
| `total_reversed` | decimal(15,2) | 0.00 | positive magnitude memo |
| `total_paid_out` | decimal(15,2) | 0.00 | cross-check against `SUM(paid payouts.amount)` |
| `ledger_entry_count` | bigint unsigned | 0 | catches a row that vanished |
| `last_entry_id` | FK ledger | nullable | `nullOnDelete`, high-water mark |
| `last_entry_at` | timestamp | nullable | §50 "last updated" |
| `version` | bigint unsigned | 0 | bumped on every write; optimistic-lock witness |
| `is_frozen` | boolean | false | blocks new payouts without blocking earning (admin freeze) |
| `frozen_reason` | string(255) | nullable | |
| `recalculated_at` | timestamp | nullable | last full re-derivation |
| `last_reconciled_at` | timestamp | nullable | last time it was **proven** equal |
| `reconciliation_status` | string(16) | `ok` | cast `ReconciliationStatus` |
| `drift_amount` | decimal(15,2) | 0.00 | the difference the last run found |
| timestamps / blameable | | | no `deleted_at` (a derived row is rebuilt, never deleted) |

**Keys.** `UNIQUE uq_cw_collaborator(collaborator_id)` - exactly one wallet per collaborator; this single
row is also the **serialisation point** for the cache delta (`SELECT ... FOR UPDATE`), and the unique
index means two concurrent first commissions cannot create two wallets (the loser re-reads).
`INDEX (reconciliation_status, last_reconciled_at)` drift dashboard; `INDEX (available_balance)`
payout-eligibility lists.
**No CHECK on the balances.** A negative `available_balance` is a legitimate, required state after a
clawback; a CHECK would turn a recoverable accounting state into a 500. The payout guard, not the
schema, refuses to spend a negative balance. `CHECK chk_cw_paid_nonneg`: `paid_balance >= 0` (money
already sent cannot be negative).
**Relationships.** belongsTo `Collaborator`; hasMany `CollaboratorCommissionLedgerEntry`,
`CollaboratorWalletReconciliation`; hasOne `latestReconciliation`.

### 2.13 `collaborator_payouts`

A withdrawal of available balance (§54, §55). The payout never computes a balance itself; it consumes
named ledger entries through `collaborator_payout_allocations`, which is what makes "payout history
preserved" and "a commission is never paid twice" simultaneously true.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `payout_no` | string(32) | not null | `finance.collaborator_payout_prefix` + counter (§54) |
| `idempotency_key` | string(64) | not null | a double-clicked "Request payout" cannot create two requests |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `requested_amount` | decimal(15,2) | nullable | what was asked for, kept for the dispute trail |
| `amount` | decimal(15,2) | 0.00 | `SUM(live allocations.amount)`; **set by the service, never by the form** (INV-22) |
| `entry_count` | int unsigned | 0 | CACHE of the live allocation count |
| `method` | string(32) | not null | cast `PayoutMethod` (§55) |
| `payout_account_id` | FK `collaborator_payout_accounts.id` | nullable | `nullOnDelete` |
| `account_title` | string(150) | nullable | not secret; needed for verification |
| `bank_name` | string(150) | nullable | |
| `account_details_encrypted` | text | nullable | Laravel `encrypted` cast: account number / IBAN / branch code / mobile number, **snapshotted at payment time** because the account row may later change (§55). Never logged; an activity diff shows `[encrypted]` |
| `account_last4` | string(8) | nullable | the only part ever rendered in a list |
| `transaction_id` | string(100) | nullable | §54 transaction ID |
| `status` | string(32) | `requested` | cast `PayoutStatus` - exactly §54's six states |
| `minimum_payout_snapshot` | decimal(15,2) | nullable | the `collaborator.minimum_payout` in force when requested |
| `available_at_request` | decimal(15,2) | nullable | the figure the requester saw; a disagreement with the recomputed figure is logged |
| `requested_by` / `requested_at` | FK `users.id` / timestamp | nullable | §54 requested date |
| `approved_by` / `approved_at` | FK `users.id` / timestamp | nullable | |
| `paid_by` / `paid_at` | FK `users.id` / timestamp | nullable | |
| `paid_on` | date | nullable | business date of the transfer (§54 paid date) |
| `rejected_by` / `rejected_at` / `rejection_reason` | FK / timestamp / string(255) | nullable | reason mandatory |
| `cancelled_by` / `cancelled_at` / `cancellation_reason` | FK / timestamp / string(255) | nullable | reason mandatory; also used for a returned bank transfer (§6.4.5) |
| `statement_from` / `statement_to` | date | nullable | the window the payout settles, for the printed voucher |
| `receipt_path` | string(255) | nullable | proof of transfer |
| `notes` | string(255) | nullable | |
| timestamps / blameable | | | **no `deleted_at`** (D16): §120.9 requires payout history to survive; cancellation is a status |

**Keys.** `UNIQUE uq_cp_number(payout_no)`; `UNIQUE uq_cp_idem(idempotency_key)`;
`UNIQUE uq_cp_txn(method, transaction_id)` - the same bank transaction can never be recorded against two
payouts, which is the database half of "mark paid is idempotent" (NULL `transaction_id` rows do not
collide). `INDEX (collaborator_id, status)`; `INDEX (status, requested_at)` queue + stale-request job;
`INDEX (paid_on)` payout history report and the wallet cross-foot.
**CHECK** `chk_cp_amount`: `amount >= 0 AND (requested_amount IS NULL OR requested_amount > 0)`.
**Trigger** `trg_cp_no_delete` `BEFORE DELETE` -> `SIGNAL SQLSTATE '45000'`.
**Relationships.** belongsTo `Collaborator`, `CollaboratorPayoutAccount`, `User`
(`requestedBy` / `approvedBy` / `paidBy` / `rejectedBy` / `cancelledBy`); hasMany
`CollaboratorPayoutAllocation`; **belongsToMany `CollaboratorCommissionLedgerEntry` through the pivot
`collaborator_payout_allocations`**.

### 2.14 `collaborator_payout_allocations`

The pivot that makes a payout consume named, already-available earnings rather than an abstract number.
It is the difference between "we paid him 20,000" and "we paid him exactly these commissions" - required
for §120.9 (a 20,000 payout against one 50,000 entry), for clawback tracing, for the §56 statement, and
for the DB-level guarantee that a commission is never paid twice.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `payout_id` | FK `collaborator_payouts.id` | not null | `restrictOnDelete` |
| `ledger_entry_id` | FK ledger | not null | `restrictOnDelete` |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete`; denormalised so the collaborator global scope applies to the pivot too |
| `amount` | decimal(15,2) | not null | the slice of that entry this payout claims; **may be less than the entry** (partial withdrawal) |
| `entry_transaction_date` | date | not null | SNAPSHOT, so FIFO order is provable after the fact |
| `entry_status_at_allocation` | string(32) | not null | SNAPSHOT, must be `available` |
| `is_released` | boolean | false | true when a rejected / cancelled / returned payout gives the money back, or when an allocation is released to let a refund through |
| `released_at` | timestamp | nullable | |
| `release_reason` | string(32) | nullable | cast `AllocationReleaseReason` |
| `release_note` | string(255) | nullable | |
| `released_by` | FK `users.id` | nullable | `nullOnDelete` |
| `active_guard` | tinyint | **generated STORED** | `CASE WHEN is_released = 0 THEN 1 ELSE NULL END` |
| timestamps / `created_by` | | | no `deleted_at`; an allocation is released, never deleted |

**Keys.** `UNIQUE uq_cpa_pair(payout_id, ledger_entry_id)` - the same entry can never be added to one
payout twice, so a retried "build allocations" step is idempotent.
`INDEX idx_cpa_entry_active(ledger_entry_id, active_guard)` - the hot "how much of this entry is still
claimed" lookup; `INDEX (collaborator_id, is_released)`; `INDEX (payout_id, is_released)`.
**CHECK** `chk_cpa_amount`: `amount > 0`.
The no-double-payout guarantee lives on the ledger row: every allocation insert is paired with the
compare-and-swap in §6.4.2, with `chk_cle_allocation_ceiling` as the backstop if anyone ever writes a
different UPDATE.
**Relationships.** belongsTo `CollaboratorPayout`, `CollaboratorCommissionLedgerEntry`, `Collaborator`,
`User` (`releasedBy`).

### 2.15 `collaborator_payout_accounts`

Reusable, encrypted payout destinations (§55 "sensitive payout data must be protected"). Kept out of the
payout row so a bank account is typed once and snapshotted at each payment - re-typing an IBAN per
request is both a data-entry risk and a money risk.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `label` | string(60) | not null | "HBL main", "JazzCash personal" |
| `method` | string(32) | not null | cast `PayoutMethod` |
| `account_title` | string(150) | not null | the name on the account |
| `bank_name` | string(150) | nullable | |
| `details_encrypted` | text | not null | `encrypted` cast: account number / IBAN / branch code / mobile number. Never logged, never in an activity diff |
| `account_last4` | string(8) | nullable | plain, for display |
| `is_default` | boolean | false | |
| `is_verified` | boolean | false | |
| `verified_by` / `verified_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `status` | string(16) | `active` | `active` / `disabled` |
| `default_guard` | bigint unsigned | **generated STORED** | `CASE WHEN is_default = 1 AND deleted_at IS NULL THEN collaborator_id ELSE NULL END` |
| timestamps / `deleted_at` / blameable | | | softDeletes allowed - it is a contact detail, not a movement; paid payouts keep their own encrypted snapshot |

**Keys.** `UNIQUE uq_cpacc_default(default_guard)` - exactly one default account per collaborator,
enforced by the database instead of a "clear all others" loop that can half-fail.
`INDEX (collaborator_id, status)`.
**Relationships.** belongsTo `Collaborator`, `User` (`verifiedBy`); hasMany `CollaboratorPayout`.

### 2.16 `collaborator_wallet_reconciliations`

The proof (§50 "do not rely only on a stored balance - all totals must be reproducible"). One row per
collaborator per run, storing the figures re-derived from the ledger beside the stored figures, the
drift, and whether it was repaired. It turns "the wallet should equal the ledger" into a dated,
queryable record an auditor can read.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | - | PK |
| `run_uuid` | char(36) | not null | one UUID per run across all collaborators |
| `run_type` | string(16) | not null | `scheduled` / `manual` / `post_transaction` |
| `collaborator_id` | FK `collaborators.id` | not null | `restrictOnDelete` |
| `collaborator_wallet_id` | FK wallets | not null | `restrictOnDelete` |
| `as_of` | timestamp | not null | the cut-off the derivation covered |
| `expected_pending` / `expected_available` / `expected_reserved` / `expected_paid` / `expected_lifetime` / `expected_student` / `expected_project` / `expected_adjustments` / `expected_reversed` | decimal(15,2) | 0.00 | re-derived from the ledger |
| `stored_pending` / `stored_available` / `stored_reserved` / `stored_paid` / `stored_lifetime` / `stored_student` / `stored_project` / `stored_adjustments` / `stored_reversed` | decimal(15,2) | 0.00 | the wallet row as found |
| `expected_entry_count` / `stored_entry_count` | bigint unsigned | 0 | |
| `drift_total` | decimal(15,2) | 0.00 | sum of absolute differences; `0.00` is the only acceptable value |
| `identity_holds` | boolean | true | R2: `lifetime = pending + available + reserved + paid` |
| `payout_cross_check` | decimal(15,2) | 0.00 | R3: `paid - SUM(paid payouts.amount)`; must be 0.00 |
| `allocation_mismatch_count` | int unsigned | 0 | R4: entries whose `allocated_amount` <> `SUM(live allocations)` |
| `orphan_allocation_count` | int unsigned | 0 | R4b: live allocations on an entry not in `available`/`paid` |
| `reversal_group_mismatch_count` | int unsigned | 0 | R6: a reversal group that does not net correctly |
| `bucket_crossfoot_diff` | decimal(15,2) | 0.00 | R5: bucket sums computed with and without `reversed` rows must agree |
| `last_entry_id` | FK ledger | nullable | `nullOnDelete`, the high-water mark covered |
| `status` | string(16) | `ok` | cast `ReconciliationStatus` |
| `repaired` / `repaired_at` / `repaired_by` | boolean / timestamp / FK `users.id` | false / nullable | a repair rewrites the **cache only**, never the ledger |
| `details` | json | nullable | per-bucket diff, the offending entry / entitlement / payout ids, and the R7 and R8 evidence |
| `duration_ms` | int unsigned | 0 | |
| `checked_at` | timestamp | not null | |
| `created_at` | timestamp | not null | no `updated_at`, no `deleted_at` - written once, never edited |
| `created_by` | FK `users.id` | nullable | `nullOnDelete` (null when the scheduler ran it) |

**Keys.** `UNIQUE uq_cwr_run(run_uuid, collaborator_id)` - a retried reconciliation job writes one row
per collaborator per run, never a pile of duplicates, so "how many wallets drifted on the 3rd" is
answerable. `INDEX (collaborator_id, checked_at)` wallet history tab; `INDEX (status, checked_at)` drift
dashboard and alerting; `INDEX (run_uuid)`.
**Relationships.** belongsTo `Collaborator`, `CollaboratorWallet`, `User` (`repairedBy`).

### 2.17 Relationship map (one line per edge that matters)

```
Collaborator 1-1 CollaboratorWallet
Collaborator 1-n CollaboratorCommissionSetting (versions)       1-n CollaboratorReferral
Collaborator 1-n CollaboratorCommissionEntitlement              1-n CollaboratorCommissionLedgerEntry
Collaborator 1-n CollaboratorPayout 1-n CollaboratorPayoutAllocation n-1 CollaboratorCommissionLedgerEntry
Collaborator 1-n CollaboratorPayoutAccount                      1-n CollaboratorWalletReconciliation
CollaboratorPayout n-n CollaboratorCommissionLedgerEntry         pivot: collaborator_payout_allocations
StudentFee 1-n StudentFeeInstallment / StudentFeePayment / StudentFeeDiscount
StudentFeePayment 1-n PaymentReversal 1-n CollaboratorCommissionLedgerEntry (the negatives)
StudentFeePayment 1-n CollaboratorCommissionLedgerEntry (0 or 1 earning per collaborator)
ProjectPayment   1-n PaymentReversal ; ProjectPayment 1-n CollaboratorCommissionLedgerEntry
CollaboratorCommissionLedgerEntry 1-n self (reversals, via reverses_entry_id)
CollaboratorCommissionEntitlement 1-1 self (supersedes / supersededBy)
CollaboratorReferral 1-1 self (previousReferral / supersededBy)
```

### 2.18 Status lifecycles - explicit transition tables

Any transition not in these tables throws `InvalidStatusTransition`. Every transition writes an
`activity_log` row with old/new status, actor, IP and, where marked, a **mandatory reason** (INV-24).

**2.18.1 `student_fees.status`** (`StudentFeeStatus`)

| From | To | Trigger | Actor / permission |
|---|---|---|---|
| - | `pending` | charge issued | `student_fees.create` |
| `pending` | `partial` | a receipt lands, `paid < net` | system (`PaymentService`) |
| `pending` / `partial` | `paid` | `paid - refunded = net` | system |
| `pending` / `partial` / `paid` | `overpaid` | `paid - refunded > net` | system |
| `pending` / `partial` | `overdue` | `due_date < today` and balance > 0 | scheduled `fees:mark-overdue` |
| `overdue` | `partial` / `paid` | a receipt lands | system |
| `paid` / `partial` / `overpaid` | `refunded` | cumulative reversals bring `paid - refunded` to 0 | system |
| `pending` / `overdue` | `cancelled` | admin cancels, **reason mandatory**; refused when any cleared receipt exists | `student_fees.change_status` |
| `cancelled` | `pending` | re-open, **reason mandatory** | `student_fees.change_status` |

**2.18.2 `student_fee_installments.status`** (`InstallmentStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `pending` | plan built | `installments.create` |
| `pending` | `partial` / `paid` | receipts allocated to the line | system |
| `pending` / `partial` | `overdue` | `due_date < today`, not settled | scheduled job |
| `pending` / `partial` / `overdue` | `waived` | concession, **reason mandatory** (§78) | `installments.change_status` + `fee_discounts.approve` |
| `pending` / `overdue` | `cancelled` | plan rebuilt, **reason mandatory**; refused when `paid_amount > 0` | `installments.change_status` |
| `paid` | `partial` | a reversal reduces the settled amount | system |

**2.18.3 `student_fee_payments.status` / `project_payments.status`** (`ReceivedPaymentStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `cleared` | receipt recorded | `*_payments.create` |
| `cleared` | `partially_refunded` | a `partial_refund` reversal, cumulative `refunded < amount` | `payment_reversals.create` |
| `cleared` / `partially_refunded` | `refunded` | cumulative `refunded = amount` via refunds | `payment_reversals.create` |
| `cleared` | `voided` | a `void` / `cancellation` reversal for the full amount (the only "edit" path, **reason mandatory**) | `*_payments.change_status` |
| `cleared` | `bounced` | a `bounced_instrument` reversal for the full amount | `payment_reversals.create` |
| `voided` / `refunded` / `bounced` | - | **terminal** | - |

**2.18.4 `payment_reversals.approval_status`** (`ReversalApprovalStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `not_required` | `finance.refund_approval_required` is false and the amount is under the threshold; the commission reversal runs immediately | `payment_reversals.create` |
| - | `pending` | approval required; **no commission reversal runs yet** | `payment_reversals.create` |
| `pending` | `approved` | approver accepts; the commission reversal job is dispatched | `payment_reversals.approve` |
| `pending` | `rejected` | **reason mandatory**; the payment's `refunded_amount` increment is rolled back in the same transaction | `payment_reversals.reject` |

**2.18.5 `collaborator_commission_settings.status`** (`CommissionRuleStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `scheduled` | created with `effective_from > today` | `collaborator_commission_settings.create` |
| - | `active` | created with `effective_from <= today` | same |
| `scheduled` | `active` | `effective_from` reached | scheduled job |
| `active` | `superseded` | a new version closes it at `effective_from - 1 day`, **reason mandatory** | `collaborator_commission_settings.create` |
| `active` | `expired` | `effective_to` passed with no successor | scheduled job |
| `scheduled` | `cancelled` | a future version withdrawn before it ever applied; refused if any ledger row references it | `collaborator_commission_settings.change_status` |

**2.18.6 `collaborator_commission_entitlements.status`** (`EntitlementStatus`)

| From | To | Trigger | Actor |
|---|---|---|---|
| - | `open` | first commissionable payment on the document | system |
| `open` | `fully_released` | `released_amount = entitlement_amount` | system |
| `open` / `fully_released` | `closed` | the document is settled, cancelled or transferred; `closed_on` set | system / `collaborator_commissions.change_status` |
| `open` / `fully_released` | `superseded` | the document figure, the rule or the attribution changed, **reason mandatory**; a successor row is inserted | system (audited) |
| `open` | `cancelled` | the document was cancelled before any release | system |
| `superseded` / `cancelled` / `closed` | - | **terminal** | - |

**2.18.7 `collaborator_commission_ledger_entries.status`** (`CommissionStatus`, §51 - the six cases exactly)

| From | To | Event | Trigger | Permission |
|---|---|---|---|---|
| - | `pending` | `created` | payment posted, **manual** approval mode | system |
| - | `approved` | `created` | payment posted, **automatic** mode with `hold_days > 0` (`hold_until = transaction_date + hold_days`) | system |
| - | `available` | `created` | payment posted, **automatic** mode with `hold_days = 0` - §53's "payment -> commission -> available balance" | system |
| `pending` | `approved` | `approved` | admin approves (single or bulk); when `hold_until` is null or past, the same transaction continues to `available` | `collaborator_commissions.approve` |
| `pending` / `approved` | `cancelled` | `rejected` | admin rejects, **reason mandatory**; the amount leaves every bucket, the row stays | `collaborator_commissions.reject` |
| `approved` | `available` | `released` | `hold_until <= today` (job `commissions:release-held`) or approval with no hold | system |
| `available` | `paid` | `paid` | the payout holding **all** of this entry is marked paid (`allocated_amount = amount`) | `collaborator_payouts.change_status` |
| `pending` / `approved` / `available` | `reversed` | `reversed` | cumulative `reversed_amount = amount` from refunds / voids; **all of its reversal rows move with it** (INV-15) | system |
| `available` | `cancelled` | `cancelled` | admin voids an unallocated entry, **reason mandatory** | `collaborator_commissions.reject` |
| `paid` | `available` | `unallocated` | **the single backward transition**: a paid payout was returned by the bank, its allocations released (§6.4.5), **reason mandatory** | `collaborator_payouts.change_status` |
| `reversed` / `cancelled` | - | **terminal forever** | - | - |

There is no path back to `pending`, none from `available` to `approved`, and **no un-pay of a commission
whose payout really settled** - a post-payout refund posts an offsetting `clawback` debit instead
(§6.3.5). Approving is forward-only and idempotent: approving an already-approved entry is a no-op, so a
double-clicked bulk action cannot double-count.

**2.18.8 `collaborator_payouts.status`** (`PayoutStatus`, §54 - the six cases exactly)

| From | To | Trigger | Permission |
|---|---|---|---|
| - | `requested` | collaborator self-service (`collaborator.payout_request_enabled` **and** `collaborator_portal.payout_request`) | `collaborator_portal.payout_request` |
| - | `pending` | staff creates it for the collaborator | `collaborator_payouts.create` |
| `requested` | `pending` | staff picks the request up | `collaborator_payouts.change_status` |
| `requested` / `pending` | `approved` | approver accepts | `collaborator_payouts.approve` |
| `requested` / `pending` / `approved` | `rejected` | **reason mandatory**; every allocation is released | `collaborator_payouts.reject` |
| `requested` / `pending` / `approved` | `cancelled` | the requester withdraws while `requested`, or staff cancels with a **mandatory reason**; allocations released | `collaborator_portal.payout_request` (own, `requested` only) / `collaborator_payouts.change_status` |
| `approved` | `paid` | money sent; requires `paid_on`, and `transaction_id` when `finance.payout_reference_required` | `collaborator_payouts.change_status` |
| `paid` | `cancelled` | **the bank returned the transfer** - `PayoutService::cancelAfterPayment()`, **reason mandatory**; allocations released, entries walk `paid -> available` | `collaborator_payouts.change_status` + `collaborator_payouts.approve` |
| `rejected` | - | **terminal** | - |

**2.18.9 `collaborator_wallets.reconciliation_status`** (`ReconciliationStatus`)

| From | To | Trigger |
|---|---|---|
| any | `ok` | a run found `drift_total = 0.00` and every structural check passed |
| any | `drift` | a cache difference was found; the wallet screen switches to showing **derived** figures and a banner until an admin recalculates |
| `drift` | `repaired` | an admin (or `--repair`) rewrote the cache from the ledger; before/after stored in `details` |
| any | `failed` | a **structural** check failed (identity, allocation, reversal group). Nothing is repaired; every holder of `wallet_reconciliation.view_any` is notified |

### 2.19 Duplicate prevention

**The principle: the INSERT is the check.** No code path anywhere may decide "does a commission already
exist?" with a SELECT and then act on the answer - under MariaDB's default REPEATABLE READ two concurrent
workers both read "no" from their own snapshots and both insert. Uniqueness is declared in the schema and
the duplicate is *discovered* by catching the integrity violation.

**Layer 0 - the source transaction cannot duplicate.** `student_fee_payments`, `project_payments`,
`payment_reversals`, `student_fee_discounts` and `collaborator_payouts` each carry
`idempotency_key string(64) NOT NULL` with a UNIQUE index. **The key is generated server-side, one ULID per
submission**: the Blade modal is rendered with the key already in a hidden field and re-submits that same
value on every retry (the Alpine handler disables the button and never regenerates the key), so the browser
never invents one and a hand-posted form without a key is rejected by the Form Request. **There is no REST
API in this release** (F-13.1, resolutions §6 H1); if an API is ever added it supplies the key through an
`Idempotency-Key` header, which needs no schema change. `PaymentService::record*()` does
the INSERT and catches `Illuminate\Database\UniqueConstraintViolationException`, re-reads by key and
returns `PaymentResult{payment, created: false}`; the controller flashes "This receipt was already
recorded (#FR-000412)". A double-clicked submit, a refreshed POST, a proxy retry and a flaky-network
mobile retry therefore produce **one** receipt, so the commission question never arises twice.

**Layer 1 - the ledger row (§52). The exact DDL:**

```sql
ALTER TABLE collaborator_commission_ledger_entries
  ADD UNIQUE KEY uq_cle_dedupe (dedupe_key),
  ADD UNIQUE KEY uq_cle_source (source_type, source_id, collaborator_id, purpose, source_guard),
  ADD UNIQUE KEY uq_cle_reversal_pair (payment_reversal_id, reverses_entry_id, purpose);
```

`dedupe_key` is `varchar(191) NOT NULL`, composed **only** inside `LedgerWriter`, never by a caller:

| Case | `dedupe_key` |
|---|---|
| student earning | `student_fee_payment:{payment_id}:student_commission:collab:{collaborator_id}` |
| project earning | `project_payment:{payment_id}:project_commission:collab:{collaborator_id}` |
| reversal | `payment_reversal:{reversal_id}:reversal:entry:{original_entry_id}` |
| clawback (a reversal of money already paid out) | the same key plus the suffix `:clawback`, so one reversal row may legitimately produce one `reversal` debit **and** one `clawback` debit for the same original - `uq_cle_source` already separates them by `purpose` |
| manual adjustment / write-off | `manual:{ulid}` |

The first four columns of `uq_cle_source` are NOT NULL. This matters: MariaDB unique indexes ignore NULLs, so
the literal §52 column list `(fee_payment_id, project_payment_id, collaborator_id, commission_source_type)`
with nullable payment columns would permit unlimited duplicate **project** commissions, because they all
share `fee_payment_id = NULL`. The typed nullable FK columns exist alongside for joins and referential
integrity - never as the guard. **[D-FS-8]**

The fifth column, `source_guard`, turns that same NULL behaviour into the exemption the manual purposes
need. It is generated: `1` for every purpose that has a causing row, **NULL for `manual_adjustment` and
`write_off`**. A manual adjustment has no source transaction — which is exactly why its key above is a
fresh `manual:{ulid}` rather than a composite — so `source_id` falls back to the collaborator and the
four-column tuple would be identical for every adjustment that partner ever receives. The first one
would succeed and the second would be a 1062, surfacing as a failed goodwill credit somebody has to
explain rather than as a duplicate anything. With the guard NULL, those rows leave the index and every
receipt and reversal keeps precisely the guarantee it had.

**Layer 2 - the entitlement cap.** `chk_cce_cap` (`released_amount <= entitlement_amount`) plus
`uq_cce_current(document_key, collaborator_id, current_guard)`. Even if a future developer invents a
third commission path with a novel `dedupe_key`, the total released against a fixed amount or a
document-level base cannot exceed the promise - the UPDATE fails at the database.

**Layer 3 - the payout.** `chk_cle_allocation_ceiling` (`allocated_amount + reversed_amount <= amount`),
`uq_cpa_pair(payout_id, ledger_entry_id)`, and the compare-and-swap in §6.4.2.

**The transaction boundary.**

```php
// Queued job ProcessStudentFeeCommission (ShouldBeUnique, $afterCommit = true)
DB::transaction(function () use ($paymentId) {
    // 1. payment row - serialises two workers on the same receipt
    $payment = StudentFeePayment::whereKey($paymentId)->lockForUpdate()->firstOrFail();
    if ($payment->commission_state === CommissionProcessingState::Processed) {
        return CommissionOutcome::alreadyDone();        // fast path, NOT the guarantee
    }
    // 2. guards (§6.1 steps 1-7) - all reads
    // 3. document row (student_fees / projects) lockForUpdate - the collectible figures
    // 4. entitlement: firstOrCreate then lockForUpdate (a 1062 here means a racing worker won; re-read)
    // 5. compute with Money (§6.1 steps 8-12)
    // 6. LedgerWriter::post() inside a SAVEPOINT
    // 7. conditional UPDATE of the entitlement caches
    // 8. wallet row lockForUpdate, apply the signed delta, bump version
    // 9. stamp payment.commission_state = processed, commission_processed_at
}, attempts: 3);   // 3 attempts absorb an InnoDB deadlock (1213) / lock wait (1205)
// events + notifications are dispatched by DB::afterCommit() only
```

`LedgerWriter::post()` wraps its INSERT in a **nested transaction (SAVEPOINT)** so a unique violation
rolls back to the savepoint instead of poisoning the outer transaction, catches
`UniqueConstraintViolationException`, re-reads by `dedupe_key`, and returns
`LedgerPostResult{entry, created: false}`. The fixed lock order **payment -> document -> entitlement ->
wallet -> ledger rows ascending by id** is obeyed by every service, so no deadlock cycle can form
between two different business acts.

**Concurrency proof.** Two workers W1 and W2 both process receipt #77 for collaborator #9.

1. W1 acquires the row lock on payment #77. W2 blocks on the same lock. Nothing below can interleave for
   the same payment, so the common case costs no duplicate work at all.
2. Suppose the lock is unavailable as a guarantee (different connection, `lockForUpdate` skipped by a bug,
   or two *different* payments racing on the same entitlement). W1 and W2 both compute and both call
   `LedgerWriter::post()` with the identical `dedupe_key`. InnoDB serialises the two INSERTs on the unique
   index: exactly one commits; the other receives error 1062, rolls back to its savepoint, re-reads the
   winner's row and returns `created: false`. **The index, not the lock, is the correctness guarantee;
   the lock only saves wasted work.**
3. The entitlement's `released_amount` cannot be lost-updated: step 7 is a conditional UPDATE
   (`WHERE id = :id AND (entitlement_amount IS NULL OR released_amount + :x <= entitlement_amount)`)
   executed while holding the entitlement row lock, and `chk_cce_cap` is the backstop.
4. The wallet cache cannot drift from a race: the signed delta is applied inside the same transaction as
   the INSERT while holding the wallet row lock, and any residual difference is found and reported by
   §6.5.
5. `commission_state` is never trusted as the guarantee, so a stale REPEATABLE READ snapshot of it is
   harmless; the worst case is one wasted recomputation ending in `created: false`.

**Queue replay.** `ProcessStudentFeeCommission` / `ProcessProjectPaymentCommission` /
`ProcessCommissionReversal` are `ShouldBeUnique` (`uniqueId` = `student-fee-payment:{id}` etc.,
`uniqueFor` 3600), `tries` 5, `backoff [10,30,60,120,300]`, dispatched `afterCommit` so a worker can
never read a payment that later rolls back. The queue lock is an optimisation only: the cache lock can
expire, the worker can be SIGKILLed mid-flight, an operator can replay `failed_jobs` weeks later - and
`uq_cle_dedupe` still yields exactly one row. `failed()` writes `commission_state = failed` with the
exception class, and `commissions:sweep` (every 10 minutes, 500 rows max, driven by
`INDEX (commission_state, id)`) re-queues anything `queued` or `failed` older than two minutes, so a
worker that dies after COMMIT but before stamping `processed` causes one harmless retry, never a lost or
doubled commission.

**Single entry point, enforced.** `CollaboratorCommissionLedgerEntry::booted()` registers a `creating`
hook that throws `DirectLedgerWriteException` unless `LedgerWriter::isWriting()` is true; an `updating`
hook that throws `ImmutableLedgerAttributeException` when any attribute outside the INV-4 whitelist is
dirty; and `deleting` / `forceDeleting` hooks that throw unconditionally. A developer who calls
`CollaboratorCommissionLedgerEntry::create()` from a controller in Phase 19 fails on the first request in
local dev, and a test asserts each exception.

**Failure modes a naive implementation hits, and why they cannot happen here.**

| Naive pattern | What goes wrong | Why it cannot happen here |
|---|---|---|
| `if (! exists) create()` | both workers read false | the INSERT is the test; 1062 is caught |
| guard on a nullable column pair | MariaDB ignores NULLs | all four guard columns are NOT NULL |
| `payment.commission_created` boolean as the guard | lost update; a crash between INSERT and flag leaves a permanent hole | the flag is advisory; the sweeper is safe to re-run |
| dispatching the job before COMMIT | the worker reads a row that does not exist yet | every dispatch is `afterCommit` |
| commission computed from the charge's cached `paid_amount` | stale under concurrency | the base comes from the payment row and the locked entitlement; charge caches are display-only |
| `wallet->increment()` outside the transaction | drift | the delta is inside the same transaction and is proven by §6.5 |
| deleting a bad commission and re-creating it | frees the unique slot, history lost | `trg_cle_no_delete` raises SQLSTATE 45000 |

**The one case the database cannot solve, stated honestly.** Two accountants posting the *same physical
receipt* from two browsers have two genuine idempotency keys, and no index can distinguish that from two
legitimate identical installments on the same day. `duplicate_fingerprint` (indexed, **non-unique**)
makes `PaymentService` return a confirmation screen naming the existing receipt; posting anyway requires
`confirm_duplicate = 1`, which is recorded in the activity log with both receipt numbers. If one receipt
was wrong it is **voided**, which reverses its commission. The honest guarantee is "one receipt produces
at most one commission", not "one banknote is receipted at most once".

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` and exposing
`static options(): array`, exactly as Phase 1 §2 requires.

**One class, one name.** Three of the enums below are **declared by an earlier phase** because that phase
migrates first; this section defines their canonical cases and every later phase — this spine included —
only casts to them and never re-declares them (F-5.4, F-5.5):

| Enum | Declared by | This section is |
|---|---|---|
| `PaymentMethod` | **Phase 7** (phase-07 §3) | the definition of its cases, verbatim |
| `LedgerEntryType` | **Phase 7** (phase-07 §3) | the definition of its cases, verbatim |
| `CommissionCalculationType` | **Phase 6** (phase-06 §3) | the definition of its cases, verbatim |

Every other enum in the table is **declared by this spine's migration set (Phase 10)**; Phase 10-12,
Phase 13, Phase 14-17 and Phase 18 reuse them and must not create a second declaration.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `StudentFeeType` | `course_fee`, `admission_fee`, `registration_fee`, `monthly_fee`, `installment`, `exam_fee`, `certificate_fee`, `other` | `isCommissionableByDefault(): bool` |
| `StudentFeeStatus` | `pending`, `partial`, `paid`, `overpaid`, `overdue`, `cancelled`, `refunded` | `isOpen(): bool` |
| `InstallmentStatus` | `pending`, `partial`, `paid`, `overdue`, `waived`, `cancelled` | |
| `FeeDiscountType` | `fixed_discount`, `percentage_discount`, `scholarship`, `promotional_discount`, `referral_discount`, `waiver`, `correction`, `reversal` | `isScholarship(): bool` (feeds `scholarship_amount` instead of `discount_amount`) |
| `PaymentMethod` | `cash`, `bank_transfer`, `card`, `cheque`, `easypaisa`, `jazzcash`, `online_gateway`, `adjustment`, `other` | §32. **Declared by Phase 7; cases defined here** (F-5.4) |
| `ReceivedPaymentStatus` | `cleared`, `partially_refunded`, `refunded`, `voided`, `bounced` | `countsAsReceived(): bool` (false for `voided`) |
| `ReversalType` | `full_refund`, `partial_refund`, `cancellation`, `void`, `bounced_instrument`, `correction` | `isFullVoid(): bool` |
| `ReversalApprovalStatus` | `not_required`, `pending`, `approved`, `rejected` | `allowsCommissionReversal(): bool` |
| `CommissionScope` | `student`, `project` | §35's two independent sides |
| `CommissionCalculationType` | `percentage`, `fixed`, `manual` | `manual` only for adjustments. **Declared by Phase 6 with these exact cases** (F-5.5) |
| `CommissionBase` | `gross`, `net_after_discount`, `paid`, `total_value`, `milestone` | `appliesTo(CommissionScope): bool` - **the values match Phase 2's `student_commission_base` / `project_commission_base` options exactly** |
| `FixedCommissionRelease` | `prorated`, `on_first_payment`, `per_payment` | `isCapped(): bool` (false for `per_payment`) |
| `CommissionRuleStatus` | `scheduled`, `active`, `superseded`, `expired`, `cancelled` | §35 "commission status" |
| `CommissionRuleSource` | `collaborator_rule`, `project_override`, `manual` | §45 stores a rate on the project itself. **Three cases, no `global_default`** — there is no silent fallback to the global default rate ([D-FS-9], F-5.6) |
| `EntitlementDocumentType` | `student_admission`, `student_fee`, `project`, `project_milestone` | |
| `EntitlementStatus` | `open`, `fully_released`, `closed`, `superseded`, `cancelled` | |
| `CommissionSourceType` | `student_fee_payment`, `student_installment_payment`, `project_payment`, `payment_reversal`, `manual_adjustment` | §51's five source types |
| `LedgerEntryType` | `credit`, `debit` | §51. **Declared by Phase 7; cases defined here** (F-5.4) |
| `LedgerEntryPurpose` | `student_commission`, `project_commission`, `reversal`, `clawback`, `manual_adjustment`, `write_off` | `isEarning(): bool`, `isUndo(): bool` |
| `CommissionStatus` | `pending`, `approved`, `available`, `paid`, `reversed`, `cancelled` | §51 verbatim. `countsInBalance(): bool` (false for `reversed`/`cancelled`), `isPayable(): bool` (true only for `available`), `isTerminal(): bool` |
| `CommissionApprovalMode` | `automatic`, `manual` | mirrors Phase 2's `commission_approval_mode` |
| `CommissionProcessingState` | `queued`, `processed`, `skipped`, `failed`, `not_applicable` | a progress hint only, never a guard |
| `CommissionSkipReason` | `referral_system_disabled`, `automatic_commission_disabled`, `payment_not_cleared`, `no_referral`, `referral_not_commission_eligible`, `collaborator_inactive`, `collaborator_deleted`, `commission_disabled`, `no_effective_rule`, `fee_type_not_commissionable`, `milestone_not_commissionable`, `below_minimum_payment`, `base_zero`, `overpayment_only`, `no_collectible_denominator`, `entitlement_cap_reached`, `rounds_to_zero`, `below_minimum_commission`, `source_commission_missing`, `reversal_not_approved` | the set a report groups by ("14 receipts earned nothing: collaborator inactive") |
| `PayoutStatus` | `requested`, `pending`, `approved`, `paid`, `rejected`, `cancelled` | §54 verbatim. `isInFlight(): bool` (requested/pending/approved), `isTerminal(): bool` |
| `PayoutMethod` | `bank_transfer`, `easypaisa`, `jazzcash`, `cash`, `cheque`, `other` | §55 |
| `AllocationReleaseReason` | `payout_rejected`, `payout_cancelled`, `payout_returned`, `released_for_reversal` | |
| `ReferralSubject` | `student`, `project`, `client`, `lead` | |
| `ReferralSource` | `referral_link`, `manual_selection`, `admission_form`, `import`, `api` | §37, §38 |
| `ReferralStatus` | `active`, `superseded`, `revoked` | only `active` fills `current_guard` |
| `ReconciliationStatus` | `ok`, `drift`, `repaired`, `failed` | `isHealthy(): bool` |

---

## 4. PermissionRegistry additions

Ability presets are Phase 1's: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`.

### 4.1 New module slugs (all `is_core = false`)

| slug | ModuleGroup | icon | Abilities | Why these |
|---|---|---|---|---|
| `student_fee_payments` | `Institute` | `receipt-percent` | `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` + `LOGS` | no `edit`, no `delete` ever (INV-8, INV-5); voiding is `change_status` |
| `project_payments` | `Finance` | `banknotes` | `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` + `LOGS` + **`link_invoice`** | the same discipline on the software-house side: **no `edit`, no `delete` ever**; voiding is `change_status`. `link_invoice` is the single narrow addition D43 needs — see below |
| `payment_reversals` | `Finance` | `arrow-uturn-left` | `READ` + `create` + `APPROVE` + `MONEY` + `REPORTS` + `LOGS` | a refund is created and approved, never edited or deleted |
| `wallet_reconciliation` | `Finance` | `scale` | `READ` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | `change_status` is the ability meaning "run a reconciliation / repair the cache" - no new `Ability` case is invented |

`collaborator_commission_entitlements` deliberately gets **no** module of its own: it is viewed inside the
commission screens under `collaborator_commissions.view`, and a separate module would add permission
surface for no gain.

**`project_payments.link_invoice` — the one ability D43 needs (ND-1).** The invoice-link concession of §1.4
is gated by this ability **plus** `invoices.edit`, and by nothing else:

| Property | Statement |
|---|---|
| What it authorises | **Exactly one mutation:** `project_payments.invoice_id` moving NULL → value → NULL through `InvoiceService` (phase-13 §6.3 rules 4-5, routes at phase-13 §7.1). Nothing else on the row, nothing on any other row, nothing on any other table. |
| What it does **not** authorise | Any other column on `project_payments` (amount, `paid_on`, method, reference, status, `collaborator_id`, `collaborator_referral_id`, any commission column), creating or deleting a payment, refunding, voiding, approving, or any write at all on `student_fee_payments`, `payment_reversals` or the ledger. It is **not** an `edit` ability and must never be treated as one. |
| Why not `project_payments.edit` | Because the guarantee "`project_payments` is never granted `edit`" stays literally true (INV-8, INV-5). A registered `edit` on a money module is a permission a later role edit, seeder or policy could quietly reuse for a real edit; a single-purpose ability cannot be reused for anything, because no other code path checks it. |
| Preset membership | **None.** It is not in `READ`, `CRUD`, `CRUD_FULL`, `STATUS`, `MONEY` or any other Phase 1 preset, and it is listed explicitly on this one slug. No other module declares it. Not being in `MONEY`, it reveals no amount by itself. |
| Phase 1 dependency | Phase 1's `Ability` enum gains one case, `LinkInvoice` (`link_invoice`), and `PermissionRegistry` lists it on `project_payments` only — requested in §13.2. An ability name that is not in the registry is not a grant, so this is the one thing that must land before phase-13's apply / unapply routes are reachable. |
| Who holds it | `RoleSeeder` grants it to **Accountant** (the role that already holds `invoices.edit` and records project receipts) and to Admin / Super Admin through their blanket grants. No other seeded role receives it; widening it is an explicit, audited role edit (phase-13 §4.5's precedent). Every use is audited with a mandatory reason (guard 3 of §1.4). |
| Always paired | The route and the policy check `project_payments.link_invoice` **and** `invoices.edit` together; holding one alone is a 403. |

### 4.2 Abilities added to Phase 1 slugs (additive; the registry stays the only place they are declared)

| slug | Abilities after this phase |
|---|---|
| `collaborator_commissions` | `READ` + `create` (manual adjustment / write-off) + `APPROVE` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` - **never `edit` or `delete`** |
| `collaborator_commission_settings` | `READ` + `create` (a new version) + `APPROVE` + `MONEY` + `LOGS` - **never `edit` or `delete`** (INV-17) |
| `collaborator_wallets` | `READ` + `MONEY` + `REPORTS` |
| `collaborator_payouts` | `READ` + `create` + `print` + `export` + `APPROVE` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` - **never `delete`** |
| `collaborator_referrals` | `READ` + `create` + `edit` (attribution change, reason mandatory) + `STATUS` + `LOGS` |
| `student_fees` | `CRUD_FULL` + `STATUS` + `MONEY` (`delete` only while no receipt exists - policy) |
| `installments` | `CRUD` + `STATUS` |
| `fee_discounts` | `READ` + `create` + `APPROVE` + `MONEY` + `LOGS` |

### 4.3 Portal permissions

Already in Phase 1 and used as-is: `collaborator_portal.dashboard`, `.students`, `.student_fee_status`,
`.student_commission`, `.projects`, `.project_client`, `.project_value`, `.project_payments`,
`.project_commission`, `.payout_request`, `.statement_download`.

Added (Phase 1 §4 explicitly allows "plus the read abilities each panel needs"):
`collaborator_portal.wallet`, `collaborator_portal.payouts`, `student_portal.fees`,
`student_portal.payments`, `client_portal.payments`.

Per Phase 1 §5 the Collaborator role receives `collaborator_portal.*` **minus** `payout_request`, so
self-service withdrawal stays an admin decision gated twice: the setting
`collaborator.payout_request_enabled` **and** the permission.

---

## 5. SettingsRegistry additions

All added to Phase 2's existing `collaborator`, `institute` and `finance` groups; no new group. Phase 2's
existing keys (`commission_approval_mode`, `student_commission_base`, `project_commission_base`,
`default_*_commission_type/rate`, `commission_on_admission_fee`, `commission_on_registration_fee`,
`commission_reversal_on_refund`, `referral_system_enabled`, `automatic_commission_enabled`,
`payout_request_enabled`, `minimum_payout`, `payout_methods`, `institute.fee_receipt_prefix`) are used
exactly as defined and are **not** redefined here.

| group.key | type | default | Meaning |
|---|---|---|---|
| `collaborator.commission_hold_days` | number | `0` | days between `approved` and `available`. `0` reproduces §53 literally; `> 0` is the only structural defence against paying out commission that is refunded the next day |
| `collaborator.commissionable_fee_types` | multiselect(`StudentFeeType`) | `course_fee, monthly_fee, installment` | complements the two existing admission/registration booleans |
| `collaborator.student_commission_document` | select `admission`\|`fee` | `admission` | the grain a fixed amount and a document-level base are capped against |
| `collaborator.fixed_commission_release` | select `prorated`\|`on_first_payment`\|`per_payment` | `prorated` | the answer to the installment ambiguity (§6.1.7) |
| `collaborator.commission_on_overpayment` | boolean | `false` | when false, the commissionable amount is capped at the remaining collectible |
| `collaborator.commission_min_entry_amount` | decimal | `0.00` | below this a computed commission produces **no row** (`below_minimum_commission`) |
| `collaborator.clawback_on_paid_commission` | select `offset_future`\|`write_off` | `offset_future` | §6.3.5 settlement policy |
| `collaborator.payout_single_inflight` | boolean | `true` | service-level guard: one in-flight payout per collaborator (the DB never enforces it, so the setting is honest) |
| `collaborator.payout_auto_approve_below` | decimal | `0.00` | `0.00` = never auto-approve |
| `collaborator.statement_show_technical_rows` | boolean | `false` | hides `write_off` / `manual_adjustment` rows in the collaborator's own default view |
| `institute.fee_record_prefix` | text | `FS-` | the fee charge document number |
| `institute.fee_record_next_number` | number | `1` | counter, locked in-transaction |
| `institute.fee_receipt_next_number` | number | `1` | counter for the existing `fee_receipt_prefix` |
| `finance.project_payment_prefix` | text | `PP-` | |
| `finance.project_payment_next_number` | number | `1` | |
| `finance.payment_reversal_prefix` | text | `RV-` | |
| `finance.payment_reversal_next_number` | number | `1` | |
| `finance.collaborator_payout_prefix` | text | `PO-` | §54 payout number |
| `finance.collaborator_payout_next_number` | number | `1` | |
| `finance.backdate_limit_days` | number | `30` | a `paid_on` older than this needs the module's `approve` ability; a future `paid_on` is always rejected |
| `finance.refund_approval_required` | boolean | `false` | mirrors the existing `expense_approval_required` pattern; drives `payment_reversals.approval_status` |
| `finance.refund_approval_threshold` | decimal | `0.00` | `0.00` = every refund needs approval when the boolean is on |
| `finance.payout_reference_required` | boolean | `true` | `transaction_id` mandatory on `markPaid` |
| `finance.wallet_reconcile_enabled` | boolean | `true` | turns the nightly proof on and off |

**Document numbering.** `App\Services\Finance\DocumentNumberService::next(string $prefixKey, string $counterKey, string $pad = '%06d'): string`
takes `SELECT ... FOR UPDATE` on the `settings` row of the counter **inside the caller's transaction**,
increments it, and returns `prefix . sprintf(pad, value)`. The UNIQUE index on the number column is the
backstop: a 1062 triggers exactly one retry. The ledger deliberately has no counter ([D-FS-6]).

---

## 6. Services and algorithms

Namespaces: `App\Services\Finance\` (money in and out), `App\Services\Institute\` (the fee charge),
`App\Services\Collaborator\` (referral, rules, engine, wallet, payouts, statements). Every money method
runs inside one `DB::transaction()` with the lock order of §2.19 and dispatches events only through
`DB::afterCommit()` (INV-20).

### 6.1 The commission calculation algorithm

Written so two developers produce identical behaviour. `StudentCommissionService::handlePayment()` and
`ProjectCommissionService::handlePayment()` run **exactly** these steps; the only differences are the
subject, the document and the base modes, which are called out per step. All arithmetic is
`App\Support\Money` (bcmath, **intermediate scale 6, final quantisation half-up at 2 decimals**).

**Guard sequence (steps 1-7). The first failing guard writes `commission_state = skipped` plus
`commission_skip_reason` and `commission_skip_detail` on the payment row and returns. No ledger row is
created, and never a zero row (INV-2).**

| Step | Check | Skip reason when it fails |
|---|---|---|
| 1 | `setting('collaborator.referral_system_enabled')` is true | `referral_system_disabled` |
| 1b | `setting('collaborator.automatic_commission_enabled')` is true. When false the engine runs only from the explicit `commissions:evaluate` command / admin action | `automatic_commission_disabled` |
| 2 | `payment.status` is `cleared`, `partially_refunded` or `refunded` (a fully refunded receipt still earns and its reversal posts the offset - this is what makes job order irrelevant). `voided` / `bounced` stop here | `payment_not_cleared` |
| 3 | `ReferralService::effectiveOn($subject, $payment->paid_on)` returns a referral (**value date**, never `now()` - INV-16) | `no_referral` |
| 3b | that referral's `commission_eligible` is true | `referral_not_commission_eligible` |
| 4 | the collaborator (loaded `withTrashed`) is not soft-deleted and `status === Active` | `collaborator_deleted` / `collaborator_inactive` |
| 5 | a rule is resolved (§6.1.1) and `is_enabled` is true | `no_effective_rule` / `commission_disabled` |
| 6 | **student:** `fee.fee_type` is commissionable (§6.1.2). **project:** when the base is `milestone`, the payment carries a `project_milestone_id` the rule allows | `fee_type_not_commissionable` / `milestone_not_commissionable` |
| 7 | `payment.amount >= rule.min_payment_amount` (when set) | `below_minimum_payment` |

**6.1.1 Rule resolution, in this precedence order.**

1. **Project override (project side only, §45).** When `projects.commission_type` is not null the rate or
   fixed amount comes from the project row and `rule_source = project_override`. A per-project override is
   an explicit admin act and therefore authorises commission on its own - but when the collaborator also
   has an effective project rule with `is_enabled = false`, the disable wins (step 5 fails).
2. **The collaborator's effective-dated rule**: the single row with
   `collaborator_id = :c AND commission_for = :scope AND status = 'active' AND effective_from <= :paid_on AND (effective_to IS NULL OR effective_to >= :paid_on)`.
   `uq_ccs_start` and `uq_ccs_open` guarantee zero or one row. `rule_source = collaborator_rule`.
3. **Nothing else. [D-FS-9] There is no silent fallback to the global default rate.** Phase 2's
   `default_student_commission_rate` / `default_project_commission_rate` pre-fill the rule form and seed a
   collaborator's first rule version (Phase 8); they never authorise a payment on their own, because
   paying a collaborator nobody configured is the one mistake that cannot be explained to a client.

**6.1.2 Fee-type commissionability (student side).** Allowed set = `rule.applies_to_fee_types` when not
null, otherwise `setting('collaborator.commissionable_fee_types')`. Two existing Phase 2 booleans then
override the set for their own types: `admission_fee` only when `collaborator.commission_on_admission_fee`
is true, `registration_fee` only when `collaborator.commission_on_registration_fee` is true.

**6.1.3 Base resolution (step 8).** `base = rule.base_override ?? setting(student_commission_base | project_commission_base)`.
A base that does not apply to the scope (`CommissionBase::appliesTo()` false) is a configuration error: the
engine uses `paid` (the requirement's default), records `base_fallback: true` in `rule_snapshot` and logs a
warning activity row. Money is never blocked by a misconfiguration.

**6.1.4 Document and figures (step 9).** `CommissionBaseResolver` returns
`{document_type, document_id, document_base_amount, collectible_amount}` and reads **only** the payment row
and the document row - never a cached `paid_amount`.

| Scope | Base | `document_type` | `document_base_amount` | `collectible_amount` (the release denominator) |
|---|---|---|---|---|
| student | `gross` | `student_admission` or `student_fee` per `collaborator.student_commission_document` (falls back to `student_fee` when the charge has no admission) | admission `course_fee` / charge `gross_amount` | admission `net_payable` / charge `net_amount` |
| student | `net_after_discount` | same | admission `net_payable` / charge `net_amount` | the same figure |
| student | `paid` | same | admission `net_payable` / charge `net_amount` (used only for the overpayment cap) | the same figure |
| project | `total_value` | `project` | `projects.project_value` | `projects.net_value` |
| project | `net_after_discount` | `project` | `projects.net_value` | `projects.net_value` |
| project | `paid` | `project` | `projects.net_value` (overpayment cap only) | `projects.net_value` |
| project | `milestone` | `project_milestone` | `project_milestones.amount` | `project_milestones.amount` |

**6.1.5 Entitlement (step 10).** `CommissionEntitlementService::openOrLoad()` does `firstOrCreate` on
`(document_key, collaborator_id, current_guard = 1)` then `lockForUpdate` (a 1062 means a racing worker
opened it first - re-read it). On creation it snapshots the rule row, every global setting used, the
approval mode, the hold days and the two figures above into `rule_snapshot`, and computes the promise:

| Case | `entitlement_amount` |
|---|---|
| `fixed` with release `prorated` or `on_first_payment` | `fixed_amount` |
| `fixed` with release `per_payment` | **NULL** (uncapped - each commissionable receipt earns the fixed amount) |
| `percentage` with base `gross`, `net_after_discount`, `total_value` or `milestone` | `Money::percentage(document_base_amount, rate)` |
| `percentage` with base `paid` | **NULL** (uncapped - each receipt earns its own percentage) |

then, when `rule.max_commission_amount` is set: `entitlement_amount = min(entitlement_amount ?? max, max)`,
so a cap always produces a capped entitlement, even on the `paid` base.

**6.1.6 The commissionable amount of this payment (step 11).**

```
remaining_collectible = Money::max('0.00', Money::sub(collectible_amount, entitlement.collected_amount))
base_amount = setting('collaborator.commission_on_overpayment')
            ? payment.amount
            : Money::min(payment.amount, remaining_collectible)
if (base_amount is zero) -> skip: payment.amount > 0 ? 'overpayment_only' : 'base_zero'
```

**6.1.7 The release amount (step 12).** Exactly one branch applies.

| Branch | Condition | Formula |
|---|---|---|
| **A - uncapped percentage** | `entitlement_amount IS NULL`, `percentage` | `release = Money::percentage(base_amount, rate)` |
| **B - uncapped fixed per payment** | `entitlement_amount IS NULL`, `fixed` (`per_payment`) | `release = fixed_amount` |
| **C - capped, prorated** (the default for every document-level base and for `fixed` + `prorated`) | `entitlement_amount` set, release `prorated` | `collected_after = collected_amount + base_amount`; if `collectible_amount` is zero -> skip `no_collectible_denominator`; `target = Money::round(entitlement_amount * collected_after / collectible_amount, 2)`; `release = target - entitlement.released_amount` |
| **D - capped, on first payment** | `entitlement_amount` set, release `on_first_payment` | `release = entitlement_amount - entitlement.released_amount` |

Then always: `release = Money::min(release, Money::sub(entitlement_amount, released_amount))` when capped,
and `release = Money::max('0.00', release)`. When the clamp yields `0.00` because the promise is exhausted,
the skip reason is `entitlement_cap_reached`.

**Why branch C is the binding default for fixed amounts and document-level bases [D-FS-10].** The
cumulative-target method is the only one that simultaneously (a) never pays ahead of collection (§39, §42:
"never pay full commission before payment is received"), (b) sums to the promise **exactly**, with the
final receipt absorbing every rounding residual, and (c) cannot be inflated by splitting a fee into twelve
installments. Paying the whole fixed amount on the first receipt creates clawback exposure on money the
business has not collected; paying it per installment silently multiplies a "fixed" commission by the
installment count. Both remain available per collaborator via `fixed_release`, and because the mode is
snapshotted on the entitlement and on every ledger row, changing it never rewrites the past.

**Worked examples (these are the fixtures in §11).**

| Input | Result |
|---|---|
| base `paid`, 10%, receipt 10,000 | one credit **1,000.00** (§120.2) |
| base `paid`, 10%, three receipts of 10,000 | three credits of **1,000.00** (§120.4) |
| fixed 2,000 `prorated`, collectible 30,000, three receipts of 10,000 | **666.67 + 666.66 + 666.67 = 2,000.00** exactly |
| base `total_value`, 15%, project value 200,000, receipts 100,000 + 100,000 | **15,000.00 + 15,000.00 = 30,000.00** (§46, §120.7) |
| base `net_after_discount`, 10%, gross 30,000 - discount 5,000, 25,000 collected | **2,500.00** (§43) |
| base `gross`, 10%, gross 30,000 - discount 5,000, 25,000 collected | promise **3,000.00**, released in proportion to the 25,000 actually collected |
| base `paid`, 10%, receipt 35,000 on a 25,000 net charge, `commission_on_overpayment = false` | base **25,000.00**, credit **2,500.00**; `gross_amount` still shows 35,000 so the gap is visible |
| 10% on 3,333.33 | **333.33** (half-up at 2, never a float) |

**6.1.8 Status at posting (step 14).** From the **snapshotted** approval mode and hold days, per §2.18.7:
manual -> `pending`; automatic + `hold_days = 0` -> `available` (`available_at = now`); automatic +
`hold_days > 0` -> `approved` with `hold_until = transaction_date + hold_days`.

**6.1.9 The permanent record of the applied rule (step 15).** Every earning row stores, and never changes
(INV-4, INV-6): `commission_setting_id` (RESTRICT, so the rule row can never be deleted), `rule_source`,
`commission_base`, `base_amount`, `gross_amount`, `calculation_type`, `commission_rate`, `fixed_amount`,
`entitlement_total`, `released_before`, `approval_mode`, `transaction_date`, `posted_at`, and
`rule_snapshot` json holding: the whole rule row as it was; every global setting key and value consulted
(`commission_approval_mode`, the base setting, `commission_hold_days`, `commissionable_fee_types`,
`commission_on_admission_fee`, `commission_on_registration_fee`, `commission_on_overpayment`,
`fixed_commission_release`, `student_commission_document`, `commission_min_entry_amount`); and the
calculation trace `{branch, document_base_amount, collectible_amount, collected_before, base_amount,
collected_after, entitlement_amount, target, released_before, release, rounding_residual, base_fallback}`.
A later settings change, rule version or discount can therefore never alter what a past row means.

**6.1.10 Steps 16-19.** Entitlement caches updated by conditional UPDATE (`released_amount += release`
guarded by `chk_cce_cap`; `collected_amount += base_amount`; `ledger_entry_count + 1`; status
`fully_released` when `released_amount = entitlement_amount`); the wallet delta applied under its row lock
with `version + 1`, `last_entry_id`, `last_entry_at`; the payment stamped `commission_state = processed`,
`commission_processed_at`, `collaborator_id`, `collaborator_referral_id`; then, after commit only,
`CommissionCreated` fires and the collaborator is notified (§97).

### 6.2 Service contracts

Each signature lists what it **guarantees** and which events it fires. Every method is idempotent under
replay unless stated.

| Service / method | Guarantees | Events |
|---|---|---|
| **`Institute\StudentFeeService`** (Phase 18) | | |
| `issue(IssueFeeData): StudentFee` | `net = gross - discount - scholarship` computed only by `Money`; a `fee_number` unique under concurrency | `StudentFeeIssued` |
| `addDiscount(StudentFee, DiscountData): StudentFeeDiscount` | append-only row; caches recomputed under `lockForUpdate`; `discount + scholarship <= gross` (DB CHECK); reason and approver recorded (§78); **never touches a payment or a ledger row** | `StudentFeeAdjusted` |
| `buildInstallmentPlan(StudentFee, array $lines): Collection` | refuses a plan whose line sum `<> net_amount`; `installment_no` unique; refuses to rebuild a line that already holds money | - |
| `cancel(StudentFee, string $reason): StudentFee` | refused when any `cleared` receipt exists (the correct act is a refund); reason mandatory | `StudentFeeCancelled` |
| `recomputeCaches(StudentFee): void` | the five cache columns always equal the sum of their rows, recomputed under the charge's row lock | - |
| `transferPayment(StudentFeePayment, StudentFee $to, string $reason): PaymentResult` | a course/batch transfer of money is a `cancellation` reversal on A plus a new receipt on B carrying **A's original `paid_on`**, so the rule version and the economics are preserved and the net commission effect is about zero | `PaymentReversalRecorded`, `StudentFeePaymentRecorded` |
| **`Finance\PaymentService`** (§117's `PaymentService`; student side Phase 10, project side Phase 11) | | |
| `recordStudentFeePayment(StudentFee, RecordPaymentData): PaymentResult` | exactly one receipt per `idempotency_key` (returns the existing one with `created: false`); `amount > 0`; at most one installment allocated and never beyond its remainder; `paid_on` inside the back-date window and never in the future; the resolved `collaborator_id` / `collaborator_referral_id` snapshotted; **no commission work inline** - the job is dispatched `afterCommit` | `StudentFeePaymentRecorded` |
| `recordProjectPayment(Project, RecordProjectPaymentData): PaymentResult` | the same, plus an optional milestone and invoice; `is_advance` when no invoice | `ProjectPaymentRecorded` |
| `refund(StudentFeePayment\|ProjectPayment $payment, RefundData $data): PaymentReversal` | **One form: the DTO** (F-4.2, R7). Two adjacent `string` positionals (`$amount`, `$reason`) are swappable at the call site and a swapped pair is a silent money bug no type system catches — so the amount, reason, type, method, idempotency key and refund date arrive as one named readonly object (`App\DataObjects\Finance\RefundData`, §13.2). Guarantees unchanged: `$data->amount <= amount - refunded_amount` recomputed under lock (INV-9); one reversal per `idempotency_key`; the payment's caches and status updated in the same transaction; when approval is required nothing reaches the commission engine until `approved` | `PaymentReversalRecorded` |
| `void(StudentFeePayment\|ProjectPayment, string $reason): PaymentReversal` | a full-amount `void` reversal, payment -> `voided`; **the only "edit" path** (INV-8); the re-entered receipt is linked in `notes` and in the activity log | `PaymentReversalRecorded` |
| `dryRun(StudentFee\|Project, RecordPaymentData): CommissionPreview` | read-only: runs §6.1 steps 1-12 and returns the prospective rule, base, rate and amount for the UI preview. **Writes nothing** | - |
| **`Collaborator\ReferralService`** (§117) | | |
| `attach(Model $subject, Collaborator, ReferralSource, ?string $code, ?CarbonInterface $on, ?ReferralContext $context = null): CollaboratorReferral` | exactly one `active` referral per subject (DB-enforced); the code snapshotted; a 1062 surfaces as a domain error naming the existing collaborator. The sixth parameter (F-4.3) is how the **six evidence columns** get filled — `referral_visit_id`, `landing_url`, `ip_address`, `user_agent`, `referral_date`, `notes` — so Phase 9's click capture and Phase 14-17's admission form pass a `ReferralContext` (§13.2) instead of writing `collaborator_referrals` directly; `null` means "no click evidence" (a staff selection), never "lose the evidence" | `ReferralAttached` |
| `change(Model $subject, Collaborator $new, string $reason): CollaboratorReferral` | supersedes, never mutates: old row -> `superseded`, `effective_to = today`, `superseded_by_id`, `change_reason`, `changed_by`; the new row starts today; the **open entitlement is superseded**; **no existing ledger row is ever re-pointed** (INV-18); writes an audit row with old and new collaborator names (§37, §107). `superseded_by_id` is a **non-unique** pointer (§2.8, ND-12): this method and `recordLosingCandidate()` may both point at the same winner in the same transaction, and neither owns the index - the "one active referral per subject" guarantee is `uq_cr_*_current`, never this column | `ReferralChanged` |
| `revoke(Model $subject, string $reason): void` | status `revoked`, `effective_to = today`, `commission_eligible = false`: stops future commission, keeps all history | `ReferralRevoked` |
| `effectiveOn(Model $subject, CarbonInterface $date): ?CollaboratorReferral` | zero or one row resolved by the date window - **this, not `current()`, is what the engine calls** | - |
| `recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral` | **The losing candidate is written by the spine, through a published method** (F-4.4) — never by a phase reaching into the table. Inserts the loser as `status = superseded`, `superseded_by_id = $winner->id`, `commission_eligible = false`, `effective_to = today`, the evidence from `$ctx` and a **mandatory** `$reason`, inside the winner's transaction. This is what makes §2.8's "a race between a `?ref=` URL and a receptionist's manual selection resolves to exactly one attribution and the loser is recorded as a superseded row with its reason" executable, and what Phase 8-9's six-rank precedence ladder (modifier 7, INV-R3) calls. **Several losers may be recorded against one winner** - `superseded_by_id` is a plain index, not unique (§2.8, ND-12), so a subject that both supersedes an existing referral through `change()` and records one or more losing candidates against the same winner writes them all without a 1062 | - |
| `resolveCode(string $code): ?Collaborator` | case-insensitive, trimmed, returns null for an unknown code without throwing (public admission form) | - |
| **`Collaborator\CommissionRuleService`** | | |
| `resolve(Collaborator, CommissionScope, CarbonInterface $on): ?RuleResolution` | zero or one rule, keyed off the **value date**, with the precedence source of §6.1.1 | - |
| `createVersion(Collaborator, CommissionScope, RuleData, string $reason): CollaboratorCommissionSetting` | never UPDATEs a rate: closes the open version at `effective_from - 1 day`, inserts `version + 1` with `supersedes_id`; refuses when a ledger row references the closed version with `transaction_date > effective_to`; no overlapping window can exist | `CommissionRuleVersioned` |
| **`Collaborator\CommissionBaseResolver`** | `forStudentPayment()` / `forProjectPayment()` read only the payment row and the document row; every mode is "money received, bounded by a collectible figure", so commission can never accrue on unreceived money | - |
| **`Collaborator\CommissionEntitlementService`** | | |
| `openOrLoad(EntitlementContext): CollaboratorCommissionEntitlement` | at most one current entitlement per (document, collaborator); snapshots the rule and settings; returns it **locked** | - |
| `supersede(CollaboratorCommissionEntitlement, SupersedeContext, string $reason)` | a document figure, rule or attribution change inserts a successor with `entitlement_amount = max(new_promise, released_amount)` and records the difference in `over_released_amount`; **never posts a clawback by itself** | `EntitlementSuperseded` |
| **`Collaborator\LedgerWriter`** | | |
| `post(LedgerEntryDraft): LedgerPostResult` | the **only** insert path (INV-21); composes `dedupe_key`; INSERTs inside a SAVEPOINT; a unique violation returns the existing row with `created: false`; writes the wallet delta and the activity row in the same transaction | `CommissionCreated` (earnings only) |
| `isWriting(): bool` | the flag the model `creating` hook checks | - |
| **`Collaborator\StudentCommissionService`** (§117) | `handlePayment(StudentFeePayment): CommissionOutcome` - the 7-step guard in order, at most one earning per (payment, collaborator), a skip reason on the payment for every negative outcome, idempotent under unlimited replay | `CommissionCreated` / `CommissionSkipped` |
| **`Collaborator\ProjectCommissionService`** (§117) | `handlePayment(ProjectPayment): CommissionOutcome` - identical contract, project document and bases | `CommissionCreated` / `CommissionSkipped` |
| **`Collaborator\CommissionReversalService`** | `handleReversal(PaymentReversal): CommissionOutcome` - §6.3; never mutates a money column; cumulative-target pro-rata so the sum undone can never exceed the original by one paisa; one reversal row per (reversal, original entry) | `CommissionReversed` / `CommissionClawedBack` |
| | `adjust(Collaborator, string $signedAmount, string $reason, ?Model $context): LedgerEntry` - a `manual_adjustment` or `write_off` row, `calculation_type = manual`, mandatory reason, actor recorded; needs `collaborator_commissions.create` | `CommissionAdjusted` |
| **`Collaborator\CommissionApprovalService`** | `approve(LedgerEntry, User)` / `reject(LedgerEntry, string $reason, User)` / `cancel(...)` / `release(LedgerEntry)` / `approveMany(array $ids, User): BulkResult` - only the transitions of §2.18.7; forward-only and idempotent; bulk takes explicit ids (never "everything matching the filter"), locks rows in ascending id order, and skips a row whose status moved since the page loaded | `CommissionApproved` / `CommissionRejected` |
| **`Collaborator\CollaboratorWalletService`** (§117) | | |
| `derive(Collaborator): WalletSnapshot` | the single canonical SQL of §6.5.1 - the **only** definition of every balance in the system (INV-26) | - |
| `recalculate(Collaborator, bool $persist = true): WalletSnapshot` | idempotent; rewrites the cache only; never invents a ledger row | `WalletRecalculated` |
| `applyDelta(CollaboratorWallet, LedgerDelta): void` | called only from `LedgerWriter` / `PayoutService`, inside their transaction, under the wallet row lock | - |
| `assertConsistent(Collaborator): void` | throws when the stored row differs from `derive()`; the test helper `assertWalletMatchesLedger()` calls it | - |
| `payoutsPaidTotal(?Collaborator $c, ?DateRange $r = null): string` | "total paid out" as a published figure (F-4.8), derived from the §6.5.1 canonical SQL part B over **live allocations (`is_released = 0`) on `paid` payouts** (INV-23) — never from `PayoutStatus` alone and never `SUM(collaborator_payouts.amount)` in a caller; the range filters the payout's business date `paid_on` (indexed, §2.13). **`$c = null` means company-wide (ND-6):** the identical SQL with the `a.collaborator_id = :id` predicate dropped — **one query, never a PHP loop over collaborators** — so the company-wide figure is by construction exactly the sum of the per-collaborator figures to the paisa (FT-42). The first parameter has **no default**: a caller must write `payoutsPaidTotal(null, $range)`, so "every collaborator in the business" is always a deliberate word at the call site and never an omitted argument. When `$c` is null a `DateRange` is **mandatory** (a company-wide all-time payout figure is never what a report means; both-null throws). Phase 13's §6.7.3 P&L block C calls the company-wide form, its finance register and Phase 19-23's collaborator reports the per-collaborator one; a `SUM()` over payouts or allocations in a report class is a review failure (INV-26, FT-42) | - |
| **`Collaborator\PayoutService`** | `request()`, `createFor()`, `approve()`, `reject()`, `cancel()`, `markPaid()`, `cancelAfterPayment()` - §6.4 | `PayoutRequested` / `PayoutApproved` / `PayoutPaid` / `PayoutRejected` / `PayoutCancelled` |
| **`Collaborator\CollaboratorStatementService`** | `build(Collaborator, DateRange, StatementFilters): StatementData` - §6.5.5; **asserts `opening + credits - debits - payouts = closing` before returning** and throws rather than render an unbalanced statement (§56) | - |
| | `commissionAccruedTotal(?Collaborator $c, ?DateRange $r = null): string` - "total commission earned" as a published figure (F-4.8), derived from the §6.5.1 canonical SQL part A over `signed_amount` on earning entries net of reversals and clawbacks, so the figure a report prints is the figure the statement balances to; the range filters `transaction_date`. **`$c = null` means company-wide (ND-6):** the identical SQL with the `e.collaborator_id = :id` predicate dropped — **one query, never a PHP loop** — hence exactly equal to the sum of the per-collaborator figures (FT-42). Same call-site discipline as `payoutsPaidTotal()`: the first parameter has **no default**, and a null collaborator requires a `DateRange`. Phase 13 §6.7.3's P&L commission memo is the company-wide caller. Nothing outside this class and `CollaboratorWalletService` may sum it (INV-26, FT-42) | - |
| **`Collaborator\CommissionReconciliationService`** | `run(?Collaborator, string $runType, bool $repair = false): ReconciliationReport` - §6.5.3; a pure reader unless `repair` is passed; repairs the cache only | `WalletDriftDetected` |
| **`Finance\DocumentNumberService`** (**owned by Phase 5** — it ships the class; this spine **reuses it and must not re-create it**, F-4.1) | `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` - unique under concurrency via the settings row lock plus the column's unique index. **Every caller passes its own pad explicitly**; the spine's callers (`fee_number`, receipt numbers, payout voucher numbers) pass theirs rather than relying on the default | - |

### 6.3 Reversal model

**Rule zero.** No money column, rate, base, snapshot or source link is ever UPDATEd, and no financial row
is ever deleted. Every correction is a **new row that points at what it corrects** (§44, §49, INV-4,
INV-5). A reversal is always two rows: one `payment_reversals` row (money going back out) and one negative
ledger row per affected earning.

**6.3.1 The cash side.** `PaymentService::refund()` / `void()` insert a `payment_reversals` row
(`amount > 0`, mandatory `reason`, `performed_by` + `performed_by_name`, `occurred_on`, `refund_method`,
optional `reference_no` and `attachment_path`), raise the payment's `refunded_amount` with a conditional
`UPDATE ... WHERE refunded_amount + :x <= amount` (INV-9 - over-refunding is impossible even under
concurrency), move the payment's status per §2.18.3, and recompute the charge's caches. When
`finance.refund_approval_required` applies, `approval_status = pending` and **no commission reversal runs
until it is approved**; a rejection rolls the `refunded_amount` increment back in the same transaction.

**6.3.2 The commission side - the exact arithmetic.** `CommissionReversalService::handleReversal()` runs in
one transaction, in the fixed lock order, for each earning entry `E` of the payment
(`purpose IN (student_commission, project_commission)`, `status <> cancelled`):

```
cumulative_refunded = payment.refunded_amount              // already includes this reversal
undone_before       = E.reversed_amount + E.clawed_back_amount
target_undone       = Money::round(E.amount * cumulative_refunded / payment.amount, 2)
delta               = target_undone - undone_before
if (delta <= 0) continue;                                  // nothing to do (idempotent replay)
```

Because the target is always computed from the **cumulative** refund, a sequence of partial refunds can
never drift, and when the cumulative refund equals the payment the target equals the whole entry, so the
last reversal is exactly the residual. Worked example - commission 1,000 on a 10,000 receipt refunded
3,333 / 3,333 / 3,334: reversals **333.30 / 333.30 / 333.40 = 1,000.00 exactly**, never one paisa more.

**6.3.3 Splitting `delta` between reversal and clawback.** Money already **paid out in cash** cannot be
"reversed" - the payout really settled - so it is **clawed back** instead:

```
1. release every live allocation on E belonging to an in-flight payout (requested/pending/approved),
   reason `released_for_reversal`; recompute those payouts' amount and entry_count; a payout left with no
   live allocation is cancelled with a reason. A refund must NEVER be blocked by a pending withdrawal.
2. paid_portion = SUM(live allocations on E whose payout.status = 'paid')
3. reversible   = E.amount - E.reversed_amount - E.clawed_back_amount - paid_portion
4. reverse_now  = Money::min(delta, reversible)
5. clawback_now = delta - reverse_now
```

**6.3.4 Rows written for `reverse_now > 0` (commission not yet paid out).** One debit:

| Column | Value |
|---|---|
| `entry_type` / `purpose` | `debit` / `reversal` |
| `amount` / `signed_amount` | `reverse_now` / `-reverse_now` |
| `source_type` / `source_id` | `payment_reversal` / the reversal row id |
| `payment_reversal_id` / `reverses_entry_id` | the reversal row / `E.id` (§44's "reference to the original commission") |
| `dedupe_key` | `payment_reversal:{id}:reversal:entry:{E.id}` |
| `status` | **the same status as `E`**, so the pair nets to zero inside whichever bucket `E` occupies |
| `commission_base`, `base_amount`, `commission_rate`, `fixed_amount`, `rule_snapshot`, `commission_setting_id`, `rule_source` | **copied from `E`**, so the statement shows the same rule on both legs |
| `transaction_date` | `reversal.occurred_on` |
| `notes` | the reversal reason (§44) |

Then `E.reversed_amount += reverse_now`; and when `E.allocated_amount = 0 AND E.reversed_amount = E.amount`,
`E` **and every one of its reversal rows** move to `reversed` with `reversed_at` (INV-15 - an entry with a
paid portion is never flipped, or its live allocation would be orphaned). §120.5 is satisfied literally: a
**-1,000.00** entry exists, the original is byte-identical on every money column, and available returns to
0.00.

**6.3.5 Rows written for `clawback_now > 0` (commission already paid out).** The original entry **stays
`paid` for ever** - there is no un-pay transition, because the payout really moved money. One debit with
`purpose = clawback` and `dedupe_key = payment_reversal:{id}:reversal:entry:{E.id}:clawback`, and a status
set by `setting('collaborator.clawback_on_paid_commission')`:

| Policy | Status of the clawback debit | Effect |
|---|---|---|
| `offset_future` (**default**) | `available` | the available balance drops, **legitimately below zero** - the collaborator owing the company, stated plainly rather than hidden. New earnings absorb it automatically (available simply nets) and `PayoutService` refuses any payout while `available <= 0` (INV-25) |
| `write_off` | `cancelled` | outside every bucket: the collaborator keeps the money and the company books the loss. Requires `collaborator_commissions.approve` plus a mandatory reason and raises a notification - absorbing a loss is never a quiet default |

`E.clawed_back_amount += clawback_now`. Recovery of a negative balance outside the system is recorded as a
`manual_adjustment` credit with the bank reference in `notes`; an uncollectable balance is closed with a
`write_off` credit. Both need `collaborator_commissions.create`, both carry a reason, both stay visible in
every statement for ever, and **neither deletes anything**.

**6.3.6 Payment deletion and cancellation.** There is no delete path at any layer (INV-5). A mis-keyed
receipt is `void`ed (full-amount reversal, payment -> `voided`, full commission reversal) and re-entered
with a new idempotency key; a bounced cheque is `bounced_instrument` with identical mechanics and a
different reporting bucket; cancelling a whole charge is refused while any cleared receipt exists.
`cancelled` versus `reversed` on a ledger row is a real distinction and is why §51 lists both:
**`reversed`** means money arrived and then left (a genuine refund) and belongs in `total_reversed`;
**`cancelled`** means the commission should never have counted (an approver rejected it, or the source
receipt was voided before approval) and leaves every bucket into a memo total. Both are terminal.

**6.3.7 Entitlement effects of a reversal.** In the same transaction: `reversed_amount += delta`;
`released_amount -= delta` floored at 0 (so `released_amount` is the **net** released, which is what keeps
`chk_cce_cap` meaningful); and `collected_amount` reduced by the refunded share of the entry's
`base_amount`, floored at 0, so a re-paid installment earns correctly instead of being refused as "cap
reached". `status` returns from `fully_released` to `open` when `released_amount < entitlement_amount`.

**6.3.8 Order independence.** If the reversal job runs before the earning job: while the payment's
`commission_state` is still `queued` the reversal stays `queued` with
`commission_skip_detail = 'earning not evaluated yet'` and the sweeper retries it after the earning
commits; when `commission_state` is `processed` and no earning exists (it was skipped) the reversal ends
`skipped: source_commission_missing`; when the payment itself was skipped the reversal ends
`not_applicable`. FT-18 runs both orders and asserts an identical final wallet.

### 6.4 Payout model

**6.4.1 Decision [D-FS-11]: a payout consumes named ledger entries through allocation rows with partial
amounts, not a running balance.** A running balance could answer "we paid him 20,000" but not "we paid him
exactly these commissions", which §56 (the statement), §120.9 (a 20,000 payout against one 50,000 entry)
and the clawback path all require. Allocation rows plus the compare-and-swap make double-spend impossible
**without** holding a lock across the whole allocation, and they keep the payout's amount derived rather
than typed (INV-22).

**6.4.2 Consumption algorithm** - one transaction, the fixed lock order:

```
1. lock the wallet row FOR UPDATE (the serialisation point for the cache).
2. snapshot = CollaboratorWalletService::derive($collaborator)      // never the cached row
   guards, each failing with a named validation error and writing nothing:
     collaborator.status = Active and not trashed
     wallet.is_frozen = false
     snapshot.available > 0                                         // a negative balance blocks payouts
     requested_amount <= snapshot.available
     requested_amount >= setting('collaborator.minimum_payout')      // default PKR 1,000
     no in-flight payout when setting('collaborator.payout_single_inflight')
3. candidates, read with lockForUpdate, FIFO by transaction_date then id:
     WHERE collaborator_id = :c
       AND status = 'available'
       AND entry_type = 'credit'                                    -- a clawback debit is never a candidate:
       AND (amount - allocated_amount - reversed_amount) > 0         -- its effect is already inside `available`
       AND (hold_until IS NULL OR hold_until <= CURRENT_DATE)
   Pending and approved entries are structurally unreachable: the WHERE clause is the guarantee, not a UI
   filter.
4. for each candidate until the request is satisfied:
     slice = min(remaining_request, amount - allocated_amount - reversed_amount)
     UPDATE collaborator_commission_ledger_entries
        SET allocated_amount = allocated_amount + :slice
      WHERE id = :id AND status = 'available' AND entry_type = 'credit'
        AND allocated_amount + :slice + reversed_amount <= amount;   -- compare-and-swap
     assert affectedRows === 1;                                      -- otherwise skip this entry, continue
     INSERT a collaborator_payout_allocations row (snapshotting status and transaction_date)
5. payout.amount = SUM(live allocations); payout.entry_count = count.
   If that sum is less than requested_amount -> abort the whole transaction with a validation error naming
   the shortfall. The amount field is never user input.
6. wallet delta: available down, reserved up. COMMIT. Then notify.
```

Two payouts racing for the same 50,000 entry: the second UPDATE matches zero rows, the service skips that
entry and re-allocates from what is left; `chk_cle_allocation_ceiling` is the backstop if anyone ever
writes a different UPDATE. FIFO by `transaction_date` pays the oldest earnings first, which is both the
fairest order and the one that keeps the clawback window on the newest money.

**6.4.3 Reserved, paid, and the §120.9 arithmetic.** Allocations are never deleted, so the buckets are
derived from them (§6.5.1): `reserved` = live allocations on in-flight payouts, `paid` = live allocations on
paid payouts, `available = payable - reserved - paid`. One 50,000 `available` credit with a paid 20,000
payout therefore gives **available 30,000.00, paid 20,000.00, reserved 0.00, lifetime 50,000.00**, the
payout row and its allocation both still present, and the entry still `available` with
`allocated_amount = 20,000.00` (INV-23: a partially settled entry is **not** `paid`; "paid commission"
always comes from allocations).

**6.4.4 `markPaid(payout, transaction_id, paid_on, ?receipt)`.** Requires `approved`; requires
`transaction_id` when `finance.payout_reference_required`; `UNIQUE (method, transaction_id)` plus the status
guard make a double submit impossible. Allocations stay live (that is how the `paid` bucket is derived);
every entry whose `allocated_amount = amount` moves `available -> paid` with `paid_at`; the wallet moves
reserved -> paid; `paid_by`, `paid_at`, `paid_on` are stamped; `PayoutPaid` fires after commit (§97).

**6.4.5 Rejection, cancellation, and a returned transfer.**

| Act | Rows written | Entry effect |
|---|---|---|
| `reject(reason)` from requested/pending/approved | payout -> `rejected` with `rejection_reason`; every allocation `is_released = 1`, `release_reason = payout_rejected`; the mirror CAS `SET allocated_amount = allocated_amount - :x WHERE id = :id AND allocated_amount >= :x` | entries stay `available`; reserved falls, available rises |
| `cancel(reason)` by the requester while `requested`, or by staff | payout -> `cancelled` with `cancellation_reason`; allocations released with `payout_cancelled` | the same |
| `cancelAfterPayment(reason)` - **the bank returned the money** | payout -> `cancelled`; allocations released with `payout_returned`; **the single backward status transition** (§2.18.7) | entries walk `paid -> available` with an `unallocated` audit row |

A payout row is **never deleted** (§120.9 "payout history preserved"); rejected and cancelled payouts keep
their reason for ever. `cancelAfterPayment` is the most dangerous door in the design - the only way settled
money re-enters a spendable bucket - so it is permission-gated twice, reason-mandatory, audited, and
covered by its own test (FT-26).

### 6.5 Reconciliation - proving `wallet == ledger`

**6.5.1 The canonical derivation.** `CollaboratorWalletService::derive()` runs exactly this, and nothing
else in the system is allowed to compute a balance (INV-26). `e` = ledger entries, `a` = allocations,
`p` = payouts, all for one `collaborator_id`.

```sql
-- A. from the ledger
SELECT
  SUM(CASE WHEN e.status IN ('pending','approved') THEN e.signed_amount ELSE 0 END) AS pending_balance,
  SUM(CASE WHEN e.status IN ('available','paid')   THEN e.signed_amount ELSE 0 END) AS payable_total,
  SUM(CASE WHEN e.status <> 'cancelled'            THEN e.signed_amount ELSE 0 END) AS lifetime_earned,
  SUM(CASE WHEN e.purpose = 'student_commission' AND e.status <> 'cancelled' THEN e.signed_amount ELSE 0 END) AS total_student_commission,
  SUM(CASE WHEN e.purpose = 'project_commission' AND e.status <> 'cancelled' THEN e.signed_amount ELSE 0 END) AS total_project_commission,
  SUM(CASE WHEN e.purpose IN ('manual_adjustment','write_off') AND e.status <> 'cancelled' THEN e.signed_amount ELSE 0 END) AS total_adjustments,
  SUM(CASE WHEN e.purpose IN ('reversal','clawback') AND e.status <> 'cancelled' THEN e.amount ELSE 0 END) AS total_reversed,
  COUNT(*) AS ledger_entry_count
FROM collaborator_commission_ledger_entries e
WHERE e.collaborator_id = :id;

-- B. from the allocations
SELECT
  SUM(CASE WHEN p.status IN ('requested','pending','approved') THEN a.amount ELSE 0 END) AS reserved_balance,
  SUM(CASE WHEN p.status = 'paid'                              THEN a.amount ELSE 0 END) AS paid_balance
FROM collaborator_payout_allocations a
JOIN collaborator_payouts p ON p.id = a.payout_id
WHERE a.collaborator_id = :id AND a.is_released = 0;

-- C. derived
available_balance = payable_total - reserved_balance - paid_balance
total_paid_out    = paid_balance
```

Part of the contract: the only column ever summed is `signed_amount` (so no query can invert a sign);
`reversed` and `cancelled` rows sit **outside** every balance and survive only as memo totals; and
`total_student_commission` is the gross student earning because a reversal debit carries
`purpose = reversal`, which is exactly how §50 and §56 word the two separate lines.

**The company-wide form of this SQL (ND-6).** `payoutsPaidTotal(null, $range)` and
`commissionAccruedTotal(null, $range)` (§6.2) are the **only** two figures allowed to run this derivation
without a `collaborator_id` predicate: query A without `WHERE e.collaborator_id = :id` and query B without
`a.collaborator_id = :id`, each with the range predicate (`transaction_date` for A, the payout's `paid_on`
for B) - same columns, same CASE expressions, same exclusions. That is what makes a company-wide figure
**identical** to the sum of the per-collaborator figures instead of merely close to it, and it is why a
caller must never build the company-wide number by looping: a loop would re-sum money outside these two
services and break INV-26. No other company-wide balance exists: there is no company wallet, no
company `available`, and no company-wide row in `collaborator_wallets`.

**6.5.2 The closed identity.** Because `(pending, approved)` and `(available, paid)` partition every
non-cancelled, non-reversed entry, and `available` is defined as `payable - reserved - paid`:

```
lifetime_earned  ==  pending_balance + available_balance + reserved_balance + paid_balance
```

This is the first assertion the reconciler makes and the line printed on the wallet screen. §120.9 reads
straight off it: `50,000 = 0 + 30,000 + 0 + 20,000`.

**6.5.3 The eight checks `CommissionReconciliationService::run()` performs per collaborator.**

| # | Check | On failure |
|---|---|---|
| R1 | every stored wallet column equals §6.5.1 to the paisa; `drift_total` = sum of absolute differences | `drift` (cache only) |
| R2 | the closed identity of §6.5.2 holds | `failed` (structural) |
| R3 | `paid_balance = SUM(collaborator_payouts.amount) WHERE status = 'paid'` - two independent tables computed from different rows must agree; this is what catches a payout marked paid whose entries were never flipped | `failed` |
| R4 | for every entry `allocated_amount = SUM(live allocations)`; for every payout `amount = SUM(live allocations)` (INV-22); and no live allocation points at an entry whose status is not `available` / `paid` | `failed` |
| R5 | bucket sums computed **including** and **excluding** `reversed` rows are identical (the pair-flip invariant INV-15) | `failed` |
| R6 | for every earning entry `reversed_amount + clawed_back_amount = SUM(amount of its reversal/clawback rows)` and `<= amount` | `failed` |
| R7 | for every entitlement `released_amount = SUM(credits) - SUM(reversals + clawbacks)` of its entries, and `released_amount <= entitlement_amount` | `failed` |
| R8 | every payment / reversal row with `commission_state = processed` has either a ledger row or a recorded skip reason - **no silent skips** | `drift` (reported) |

**6.5.4 Drift handling [D-FS-12].** A run writes one `collaborator_wallet_reconciliations` row whether the
result is ok or not - the history of being provably correct is itself evidence. On a **cache** difference
(R1, R8) the scheduled job **does not repair**: it sets `reconciliation_status = drift`, stores the
offending ids in `details`, fires `WalletDriftDetected`, notifies every holder of
`wallet_reconciliation.view_any`, and **the wallet screen, the collaborator panel and every widget switch
to the derived figures behind a red banner until an admin clicks Recalculate**. This is strictly better
than the two alternatives: silent auto-repair hides the bug that caused the drift, and leaving the cache on
screen shows a collaborator a wrong number. On a **structural** failure (R2-R7) nothing is repaired at all,
the status is `failed`, and only a human with `collaborator_commissions.create` may post a
`manual_adjustment` with a written reason to resolve a genuine shortfall - an invented balancing row would
destroy the one thing the ledger is for.

**6.5.5 The statement (§56) is movement-based and proves itself.**
`CollaboratorStatementService::build()` computes, for `[from, to]`:

```
opening  = SUM(e.signed_amount) WHERE status <> 'cancelled' AND transaction_date <  from
         - SUM(p.amount)        WHERE p.status = 'paid'     AND p.paid_on        <  from
credits  = SUM(e.signed_amount) WHERE purpose IN ('student_commission','project_commission','manual_adjustment','write_off')
                                  AND status <> 'cancelled' AND transaction_date BETWEEN from AND to
                                  -- reported as the four separate §56 lines
debits   = SUM(e.amount)        WHERE purpose IN ('reversal','clawback')
                                  AND status <> 'cancelled' AND transaction_date BETWEEN from AND to
payouts  = SUM(p.amount)        WHERE p.status = 'paid' AND p.paid_on BETWEEN from AND to
closing  = opening + credits - debits - payouts
```

and **asserts** that `closing` equals the same expression evaluated as an opening balance at `to + 1 day`,
throwing rather than rendering an unbalanced statement. A closed period is reproducible years later because
every figure keys off `transaction_date` / `paid_on`, never off a mutable status; `posted_at` explains why
a re-issued statement for a closed month differs (a back-dated receipt).

**6.5.6 Schedule.** `collaborators:reconcile-wallets` daily at 01:30 (one `run_uuid`, chunked 200
collaborators, skipped entirely when `finance.wallet_reconcile_enabled` is false), runnable on demand as
`php artisan collaborators:reconcile-wallets [--collaborator=] [--repair]`, and called by the test helper
`assertWalletMatchesLedger($collaborator)` **after every money scenario in §11**, so the nightly job and
the test suite share one implementation. `financial:verify-constraints` runs daily and asserts that every
unique index, CHECK constraint, generated column and delete trigger named in §2 still exists - a
well-meaning later migration dropping one is a more realistic failure than a race.

### 6.6 Edge-case decision table

| # | Edge case | Decided behaviour |
|---|---|---|
| 1 | **Collaborator suspended (or Pending / Inactive) when a fee is paid** | The cash posts normally - a student's money is never held hostage to a partner dispute. The guard fails at step 4: **no ledger row** (not a zero row, not a pending row); the payment records `commission_state = skipped`, `commission_skip_reason = collaborator_inactive` and the detail "collaborator suspended on 2026-03-04", keeping `collaborator_id` for traceability. Reinstatement does **not** backfill automatically - silent retro-creation is how duplicate money happens. An admin with `collaborator_commissions.approve` runs `commissions:evaluate --payment=` / `--from= --to=` with a written reason; `uq_cle_source` guarantees it can happen only once |
| 2 | **Collaborator soft-deleted after earning** | Every FK from ledger, entitlements, rules, referrals and payouts is RESTRICT, so a hard delete is impossible while money references it, and Phase 8's policy must block `forceDelete`. Ledger, wallet, payouts and statements stay intact and reportable (admin queries load the collaborator `withTrashed()` so no report shows "unknown collaborator"). New earning stops (`collaborator_deleted`). **Reversals of their past commissions still post** - a refund must never be blocked by the partner's state. A payout is refused until an admin restores them, because the debt does not disappear when the relationship does |
| 3 | **Admin changes a student's collaborator after commissions exist (§37)** | `ReferralService::change()` needs the permission and a **mandatory reason**, supersedes the old referral (closing its window today), inserts the new one, supersedes the **open entitlement**, and writes an audit row with old and new collaborator names (§107). **Existing ledger rows are never re-pointed** - A keeps exactly what A earned, because re-pointing would rewrite an already-issued statement. Only payments with `paid_on >= new.effective_from` credit B, so a back-dated receipt keyed in after the switch but dated before it correctly earns for **A**. Moving even unapproved pending entries is deliberately not automated; if the business insists it is two `manual_adjustment` rows (debit A, credit B) sharing one reason, both permanently visible |
| 4 | **Discount applied after a payment (§43)** | The discount is an append-only `student_fee_discounts` row; `net_amount` drops; `chk_sf_discount_ceiling` stops a discount exceeding the fee. Under the default `paid` base **nothing retroactive happens** - past commissions were computed on money received; only the remaining collectible shrinks, so later receipts may produce `base_zero` and **no row**. Under a document-level base the open entitlement is **superseded** with the new figures, `entitlement_amount = max(new_promise, released_amount)`, and the difference recorded in `over_released_amount`, which surfaces on the **commission discrepancies** screen (§8.8) for a human decision. **No automatic clawback**: no money left the company, and silently clawing back what a collaborator already banked because an accountant gave a discount would be worse than a reported discrepancy |
| 5 | **Overpayment** | Accepted and recorded - the money physically arrived. `balance_amount` goes negative (an advance) and the charge moves to `overpaid`. The commissionable amount is capped at the remaining collectible (§6.1.6), so a 35,000 receipt on a 25,000 net charge yields base **25,000**; the row's `gross_amount` still shows 35,000 so the gap is visible rather than hidden, and the skip detail names the non-commissionable portion. `collaborator.commission_on_overpayment` opts in (default false). When the advance is later applied to another charge it carries **no second commission** because the money was already counted once - `transferPayment()` asserts it and FT-29 covers it |
| 6 | **Payment recorded with a back-date** | `paid_on` (business) and `recorded_at` (clock) are both always stored and every report states which it uses. Back-dating is allowed up to `finance.backdate_limit_days` (default 30); beyond that the module's `approve` ability is required; a future `paid_on` is rejected outright. The rule and the referral resolve on `paid_on`, so a back-dated receipt earns the rate effective **then** - the correct accounting answer even when a newer, higher rate exists today. The wallet changes now; `transaction_date` places the entry in the back-dated period and `posted_at` explains why a re-issued statement moved. There is no period lock in this phase (§12.2 Q6) |
| 7 | **Student transferred to another course or batch** | A **batch** transfer updates `student_fees.batch_id` only - no money, no commission, no attribution change (the referral follows the **student**). A **course** transfer closes the old admission and opens a new one: unsettled charges are cancelled with a reason (never deleted), money physically moved goes through `StudentFeeService::transferPayment()` (a `cancellation` reversal on A plus a new receipt on B carrying **A's original `paid_on`**), the old entitlement is `closed`, and the new admission opens a fresh entitlement under the rule effective on the transfer date. Commission already earned on money genuinely received is never reversed merely because the student moved; the carried amount is excluded from the new charge's collectible so the same rupee cannot earn twice |
| 8 | **Project value revised after payments** | Phase 6 must record revisions append-only (`project_value_revisions`: old, new, reason, `effective_on`) per §107. Under the default `paid` base nothing changes. Under `total_value` / `net_after_discount` the open entitlement is **superseded** with the new value and `entitlement_amount = max(new_promise, released_amount)`, `supersede_reason = "project value 200,000 -> 260,000 on 2026-05-02"`: releases already made stand, future payments release against the new promise, and a **downward** revision below what was already released is reported as `over_released_amount`, never auto-clawed back (row 4's reasoning). A change to the project's commission **rate** is a new rule version or an audited change of the project override - never an edit of a posted row |
| 9 | **Fixed-amount commission across installments** | **Decided: a fixed commission is earned once per commission document (default grain: the admission) and released in proportion to money actually collected, with the residual on the final payment** - PKR 2,000 over three 10,000 installments releases 666.67 + 666.66 + 666.67 = **2,000.00 exactly** (§6.1.7 branch C, [D-FS-10]). The alternatives remain per collaborator via `fixed_release` (`on_first_payment`, `per_payment`), are snapshotted on the entitlement and every row, and the default is flagged for client confirmation (§12.2 Q2) |
| 10 | **Rate changed mid-admission** | Versions are immutable and effective-dated; each payment resolves the version covering its `paid_on`. A 10% version (Jan-Mar) and a 15% version (from Apr) give 10% to a receipt dated 15 Mar even when it is keyed in on 5 Apr, and 15% to one dated 2 Apr. Rows posted before the change are byte-identical afterwards. For a **document-level** base the entitlement keeps the version it opened with (the promise was made under that rule); a mid-document rate change applies only to a new entitlement after a supersede with a reason |
| 11 | **Commission type disabled mid-relationship** | A new version with `is_enabled = false` from today stops **future** commission (`commission_disabled`) while every past row still points at the version that authorised it; the previous version closes at today - 1 day so the timeline stays gap-free |
| 12 | **Refund larger than the original receipt** | Blocked: the unrefunded remainder is recomputed under `lockForUpdate` and the Form Request fails; `chk_*_refund_ceiling` is the DB backstop. The cumulative-target rule then means the sum of undone commission can never exceed the original, across any number of refunds and any rounding |
| 13 | **Refund while the commission sits in an in-flight payout** | The allocation is **released first** (`released_for_reversal`), the payout's amount recomputed, and the payout cancelled with a reason when nothing is left; then the reversal posts normally. Money out is never blocked by a pending withdrawal request |
| 14 | **Refund after the commission was paid out** | The clawback path of §6.3.5: the original stays `paid`, a negative `available` debit appears, the wallet legitimately goes negative, payouts are refused until it is absorbed, and the collaborator panel shows the clawback with its source receipt so the conversation is evidenced |
| 15 | **Reversal arriving before its earning (out-of-order queue)** | §6.3.8 - deferred while the earning is still `queued`, terminal once the earning is known to have been skipped. FT-18 runs both orders and asserts an identical final wallet |
| 16 | **Two accountants posting the same physical receipt** | Not solvable by any index (§2.19, final paragraph): `duplicate_fingerprint` triggers a confirmation naming the existing receipt, posting anyway needs `confirm_duplicate = 1`, and the act is logged with both receipt numbers. If one receipt was wrong it is voided, which reverses its commission |
| 17 | **Collaborator has no wallet row yet** | Phase 8's observer creates the wallet in the same transaction as the collaborator, so no money path creates one lazily; `LedgerWriter` still does `firstOrCreate` defensively and `uq_cw_collaborator` makes two concurrent creations impossible |
| 18 | **Two referral claims for the same student** | `uq_cr_student_current` makes a second active referral physically impossible: a race between a `?ref=` URL and a receptionist's manual selection resolves to exactly one attribution and the loser is recorded as a superseded row with its reason |
| 19 | **Payout marked paid twice** | `UNIQUE (method, transaction_id)` plus the status guard: the second attempt fails and the entries flip to `paid` exactly once |
| 20 | **A commission that rounds to 0.00, or falls below the configured minimum** | **No row at all** (`rounds_to_zero` / `below_minimum_commission`) and `collected_amount` is **not** advanced, so a later receipt can still reach the minimum. A 0% rate likewise produces no row |
| 21 | **A rule an admin tries to delete** | Impossible: entitlements and ledger rows reference it with RESTRICT and the policy refuses. Closing a version is refused when a ledger row references it with `transaction_date > effective_to`, so history can never be orphaned or contradicted |
| 22 | **The `collaborator_commissions` module is disabled** | Phase 1's `Gate::before` 403s every route for everyone including Super Admin, while **all data and all queued jobs stay intact**; posted commissions keep their balances and the sweeper keeps working, because money that exists does not stop existing because a screen is hidden |

---

## 7. Routes

Every admin route additionally carries `auth`, `active`, `panel:admin` from Phase 1 §8; panel routes carry
their own `panel:*`. `module:*` is stated where it differs from the file-level group.

### 7.1 Admin - student fees and receipts (screens in Phase 18, routes owned by Phase 10)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/student-fees` | `admin.student-fees.index` | `module:student_fees`, `can:student_fees.view_any` |
| GET `/admin/student-fees/create` | `admin.student-fees.create` | `can:student_fees.create` |
| POST `/admin/student-fees` | `admin.student-fees.store` | `can:student_fees.create` |
| GET `/admin/student-fees/{fee}` | `admin.student-fees.show` | `can:student_fees.view` |
| POST `/admin/student-fees/{fee}/cancel` | `admin.student-fees.cancel` | `can:student_fees.change_status` |
| POST `/admin/student-fees/{fee}/installments` | `admin.student-fees.installments.store` | `can:installments.create` |
| POST `/admin/installments/{installment}/waive` | `admin.installments.waive` | `can:installments.change_status` |
| POST `/admin/student-fees/{fee}/discounts` | `admin.student-fees.discounts.store` | `can:fee_discounts.create` |
| POST `/admin/fee-discounts/{discount}/reverse` | `admin.fee-discounts.reverse` | `can:fee_discounts.approve` |
| GET `/admin/fee-payments` | `admin.fee-payments.index` | `module:student_fees`, `can:student_fee_payments.view_any` |
| GET `/admin/fee-payments/{payment}` | `admin.fee-payments.show` | `can:student_fee_payments.view` |
| GET `/admin/student-fees/{fee}/payments/preview` | `admin.fee-payments.preview` | `can:student_fee_payments.create` (dry run, writes nothing) |
| POST `/admin/student-fees/{fee}/payments` | `admin.fee-payments.store` | `can:student_fee_payments.create`, `throttle:20,1` |
| GET `/admin/fee-payments/{payment}/receipt` | `admin.fee-payments.receipt` | `can:student_fee_payments.print` |
| POST `/admin/fee-payments/{payment}/void` | `admin.fee-payments.void` | `can:student_fee_payments.change_status` |
| POST `/admin/fee-payments/{payment}/refund` | `admin.fee-payments.refund` | `can:payment_reversals.create` |
| GET `/admin/fee-payments/export/{format}` | `admin.fee-payments.export` | `can:student_fee_payments.export` |

### 7.2 Admin - project payments (Phase 11 / 13)

**Every project-payment route carries `module:project_payments`**, admin and client alike (F-6.1): the
permission strings below are already on the `project_payments` slug, and a route whose `can:` sits on one
slug while its `module:` sits on another is gated by two different switches. `payments` stays the umbrella
module for Phase 13's **cross-source** finance register only. The full middleware stack on the index is
therefore `['auth','active','module:project_payments','can:project_payments.view_any']` (plus
`panel:admin` from the route file).

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/project-payments` | `admin.project-payments.index` | `module:project_payments`, `can:project_payments.view_any` |
| GET `/admin/project-payments/{payment}` | `admin.project-payments.show` | `can:project_payments.view` |
| GET `/admin/projects/{project}/payments/preview` | `admin.project-payments.preview` | `can:project_payments.create` |
| POST `/admin/projects/{project}/payments` | `admin.project-payments.store` | `can:project_payments.create`, `throttle:20,1` |
| GET `/admin/project-payments/{payment}/receipt` | `admin.project-payments.receipt` | `can:project_payments.print` |
| POST `/admin/project-payments/{payment}/void` | `admin.project-payments.void` | `can:project_payments.change_status` |
| POST `/admin/project-payments/{payment}/refund` | `admin.project-payments.refund` | `can:payment_reversals.create` |
| GET `/admin/project-payments/export/{format}` | `admin.project-payments.export` | `can:project_payments.export` |

**No route here mutates `invoice_id`.** The two routes that do - `admin.invoices.payments.apply` and
`.unapply` - belong to Phase 13 (phase-13 §7.1) and carry `can:project_payments.link_invoice` **plus**
`can:invoices.edit`, the D43 pair of §1.4 guard 4 (ND-1). They are the only routes in the system that may
write the column, and `project_payments.edit` exists nowhere: it is not registered, not seeded and not
checked by anything.

### 7.3 Admin - reversals, referrals, rules

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/payment-reversals` | `admin.payment-reversals.index` | `can:payment_reversals.view_any` |
| GET `/admin/payment-reversals/{reversal}` | `admin.payment-reversals.show` | `can:payment_reversals.view` |
| POST `/admin/payment-reversals/{reversal}/approve` | `admin.payment-reversals.approve` | `can:payment_reversals.approve` |
| POST `/admin/payment-reversals/{reversal}/reject` | `admin.payment-reversals.reject` | `can:payment_reversals.reject` |
| GET `/admin/referrals` | `admin.referrals.index` | `module:collaborator_referrals`, `can:collaborator_referrals.view_any` |
| POST `/admin/students/{student}/referral` | `admin.referrals.store-student` | `can:collaborator_referrals.create` |
| PUT `/admin/students/{student}/referral` | `admin.referrals.change-student` | `can:collaborator_referrals.edit` |
| PUT `/admin/projects/{project}/referral` | `admin.referrals.change-project` | `can:collaborator_referrals.edit` |
| POST `/admin/referrals/{referral}/revoke` | `admin.referrals.revoke` | `can:collaborator_referrals.change_status` |
| GET `/admin/collaborators/{collaborator}/commission-rules` | `admin.commission-rules.index` | `module:collaborator_commission_settings`, `can:collaborator_commission_settings.view` |
| POST `/admin/collaborators/{collaborator}/commission-rules` | `admin.commission-rules.store` | `can:collaborator_commission_settings.create` |
| POST `/admin/commission-rules/{rule}/close` | `admin.commission-rules.close` | `can:collaborator_commission_settings.create` |

### 7.4 Admin - commissions, wallets, reconciliation, payouts, statements (Phase 12)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/commissions` | `admin.commissions.index` | `module:collaborator_commissions`, `can:collaborator_commissions.view_any` |
| GET `/admin/commissions/{entry}` | `admin.commissions.show` | `can:collaborator_commissions.view` |
| POST `/admin/commissions/{entry}/approve` | `admin.commissions.approve` | `can:collaborator_commissions.approve` |
| POST `/admin/commissions/{entry}/reject` | `admin.commissions.reject` | `can:collaborator_commissions.reject` |
| POST `/admin/commissions/bulk-approve` | `admin.commissions.bulk-approve` | `can:collaborator_commissions.approve`, `throttle:10,1` |
| POST `/admin/commissions/adjustments` | `admin.commissions.adjustments.store` | `can:collaborator_commissions.create` |
| POST `/admin/commissions/evaluate` | `admin.commissions.evaluate` | `can:collaborator_commissions.approve`, `throttle:5,1` |
| GET `/admin/commissions/export/{format}` | `admin.commissions.export` | `can:collaborator_commissions.export` |
| GET `/admin/commission-discrepancies` | `admin.commission-discrepancies.index` | `can:collaborator_commissions.view_any` |
| GET `/admin/commission-skips` | `admin.commission-skips.index` | `can:collaborator_commissions.view_reports` |
| GET `/admin/collaborator-wallets` | `admin.wallets.index` | `module:collaborator_wallets`, `can:collaborator_wallets.view_any` |
| GET `/admin/collaborator-wallets/{collaborator}` | `admin.wallets.show` | `can:collaborator_wallets.view` |
| POST `/admin/collaborator-wallets/{collaborator}/recalculate` | `admin.wallets.recalculate` | `can:wallet_reconciliation.change_status` |
| POST `/admin/collaborator-wallets/{collaborator}/freeze` | `admin.wallets.freeze` | `can:collaborator_payouts.change_status` |
| GET `/admin/wallet-reconciliations` | `admin.wallet-reconciliations.index` | `can:wallet_reconciliation.view_any` |
| GET `/admin/wallet-reconciliations/{reconciliation}` | `admin.wallet-reconciliations.show` | `can:wallet_reconciliation.view` |
| POST `/admin/wallet-reconciliations/run` | `admin.wallet-reconciliations.run` | `can:wallet_reconciliation.change_status`, `throttle:3,1` |
| GET `/admin/payouts` | `admin.payouts.index` | `module:collaborator_payouts`, `can:collaborator_payouts.view_any` |
| GET `/admin/payouts/create` | `admin.payouts.create` | `can:collaborator_payouts.create` |
| POST `/admin/payouts` | `admin.payouts.store` | `can:collaborator_payouts.create` |
| GET `/admin/payouts/{payout}` | `admin.payouts.show` | `can:collaborator_payouts.view` |
| POST `/admin/payouts/{payout}/approve` | `admin.payouts.approve` | `can:collaborator_payouts.approve` |
| POST `/admin/payouts/{payout}/reject` | `admin.payouts.reject` | `can:collaborator_payouts.reject` |
| POST `/admin/payouts/{payout}/mark-paid` | `admin.payouts.mark-paid` | `can:collaborator_payouts.change_status` |
| POST `/admin/payouts/{payout}/cancel` | `admin.payouts.cancel` | `can:collaborator_payouts.change_status` |
| POST `/admin/payouts/{payout}/cancel-after-payment` | `admin.payouts.cancel-after-payment` | `can:collaborator_payouts.change_status`, `can:collaborator_payouts.approve` |
| GET `/admin/payouts/{payout}/voucher` | `admin.payouts.voucher` | `can:collaborator_payouts.print` |
| GET `/admin/payouts/export/{format}` | `admin.payouts.export` | `can:collaborator_payouts.export` |
| GET `/admin/collaborators/{collaborator}/statement` | `admin.statements.show` | `can:collaborator_commissions.view_financial` |
| GET `/admin/collaborators/{collaborator}/statement/export/{format}` | `admin.statements.export` | `can:collaborator_commissions.export` |

### 7.5 Collaborator panel (`auth`, `active`, `panel:collaborator`, `module:collaborators`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/collaborator/wallet` | `collaborator.wallet.index` | `can:collaborator_portal.wallet` |
| GET `/collaborator/commissions` | `collaborator.commissions.index` | `role_or_permission:collaborator_portal.student_commission\|collaborator_portal.project_commission` |
| GET `/collaborator/commissions/{entry}` | `collaborator.commissions.show` | same + policy ownership check (404, not 403) |
| GET `/collaborator/statement` | `collaborator.statement.index` | `can:collaborator_portal.statement_download` |
| GET `/collaborator/statement/export/{format}` | `collaborator.statement.export` | `can:collaborator_portal.statement_download` |
| GET `/collaborator/payouts` | `collaborator.payouts.index` | `can:collaborator_portal.payouts` |
| GET `/collaborator/payouts/create` | `collaborator.payouts.create` | `can:collaborator_portal.payout_request` |
| POST `/collaborator/payouts` | `collaborator.payouts.store` | `can:collaborator_portal.payout_request`, `throttle:3,60` |
| POST `/collaborator/payouts/{payout}/cancel` | `collaborator.payouts.cancel` | `can:collaborator_portal.payout_request` + policy (own, `requested` only) |
| GET `/collaborator/payout-accounts` | `collaborator.payout-accounts.index` | `can:collaborator_portal.payout_request` |
| POST `/collaborator/payout-accounts` | `collaborator.payout-accounts.store` | `can:collaborator_portal.payout_request` |
| GET `/collaborator/students` | `collaborator.students.index` | `can:collaborator_portal.students` (§57) |
| GET `/collaborator/projects` | `collaborator.projects.index` | `can:collaborator_portal.projects` (§58) |

### 7.6 Student and client panels

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/student/fees` | `student.fees.index` | `panel:student`, `can:student_portal.fees` |
| GET `/student/fees/{fee}` | `student.fees.show` | `can:student_portal.fees` + policy ownership |
| GET `/student/payments/{payment}/receipt` | `student.payments.receipt` | `can:student_portal.payments` + policy ownership |
| GET `/client/payments` | `client.payments.index` | `panel:client`, **`client.context`**, **`module:project_payments`**, `can:client_portal.payments` |
| GET `/client/payments/{payment}/receipt` | `client.payments.receipt` | **`client.context`**, **`module:project_payments`**, `can:client_portal.payments` + policy ownership |

Both client rows carry **`client.context`** (D31, F-12.3: *every* `client.*` route does, without exception —
Phase 5 owns `routes/client.php` and the middleware) and **`module:project_payments`** (F-6.1: the same
module switch as the admin project-payment routes, so disabling the module cannot leave the client side
reachable while the admin side is dark). `client_portal.payments` is a **permission namespace, not a module
slug**, so it is never itself module-gated (phase-01 §3, D20).

---

## 8. UI screens

House rules from Phase 1 §9 and `CLAUDE.md` §6 apply to every screen: `x-ui.*` components only, search +
filters + sortable headers + pagination + empty state + skeleton loader on every list, money right-aligned
with `tabular-nums`, toast on every write, `x-ui.confirm` on every destructive or irreversible action,
responsive (tables inside `overflow-x-auto`), light and dark. **No Kanban and no calendar anywhere in this
spine** - section F never asks for one (§18 asks for a lead Kanban, §22 a task Kanban), so a payout board
would be invented scope. The only wizards are the three below, each of which the requirement implies.

### 8.1 Record-payment modal (wizard, 4 steps) - the most important screen in the phase

**Purpose.** Receipt money against a charge or a project in a way that cannot double-post and shows the
cashier the commission consequence before it happens.
**Components.** `x-ui.modal`, `x-ui.form.input`, `x-ui.form.select`, `x-ui.form.file`, `x-ui.badge`,
`x-ui.confirm`, `x-ui.skeleton`.
**Steps.** (1) amount + installment line (the line's remaining amount shown); (2) method, reference, value
date (`paid_on`), with a back-date warning naming the limit and the required ability; (3) attachment +
notes; (4) **review with a read-only commission preview** fetched from `admin.fee-payments.preview`:
"this receipt will credit COL-1024 PKR 1,000.00 - rule v3, 10.0000% of 10,000.00, base: actual paid", or
the exact reason none will be created ("student has no collaborator").
**Duplicate handling.** A hidden `idempotency_key` ULID generated **once per modal open** (never
regenerated on retry) plus the submit button disabled on submit; when `duplicate_fingerprint` matches an
existing receipt the step-4 panel turns amber, names the existing receipt number, and requires the
`confirm_duplicate` checkbox.
**Empty/edge states.** A fully paid charge shows "nothing outstanding - recording this will create an
advance"; a cancelled charge disables the action entirely.

### 8.2 Payments register (student and project variants share one Blade partial)

**Purpose.** The cashier's and accountant's daily view of money in.
**Filters** (`x-ui.filter-bar`): date range (on `paid_on`, with a toggle to filter on `recorded_at`
instead - stated on screen), branch, course, batch, method, status, collaborator, commission state,
"has refund", amount range, free-text on receipt number / student / reference.
**Columns.** Receipt # · `paid_on` (+ a small "back-dated" chip when it differs from `recorded_at`) ·
student or client · charge / project · method · **amount** · refunded · **net received** · commission
state badge (`queued` / `processed` + the amount / `skipped` + the reason on hover / `failed`) · cashier ·
row actions (view, print receipt, refund, void - each permission-gated, each behind `x-ui.confirm` with a
**mandatory reason** field).
**Footer.** Sum row for amount, refunded and net received over the filtered set, always labelled with the
filter in force.
**Empty state.** "No receipts in this range" + a Record payment action.

### 8.3 Commission ledger index and detail

**Filters.** Collaborator, status, purpose, source type, scope, `transaction_date` range, student,
project, amount range, "only reversed", "only adjustments", "unapproved older than N days".
**Columns.** `CLE-{id}` · `transaction_date` · collaborator · source (a deep link to the exact receipt) ·
student / project · base (mode chip + base amount) · rate or fixed · **signed amount** · status badge ·
reversed / clawed back · allocated · payout link.
**Row expand / detail page.** `x-ui.tabs`: **Calculation** (the `rule_snapshot` trace rendered as a
readable formula: base, rate, entitlement total, released before, released now, rounding residual,
`base_fallback` warning when set) · **Related** (the original or its reversals, the entitlement, the rule
version, the payout) · **History** (the activity-log rows for this entry: created, approved, released,
allocated, paid, reversed - actor, IP, reason).
**Bulk approve.** Checkbox selection + a sticky action bar + a confirm dialog **naming the total being
approved**; the request sends explicit ids; rows whose status moved are reported as skipped, never
force-approved.
**Empty state.** "No commissions in this range".

### 8.4 Commission rule timeline (per collaborator)

**Purpose.** Make §35 and §107 visible: one card per immutable version, drawn as a vertical
effective-dated timeline (dates, type, rate or fixed amount, base, release mode, fee-type scope, who
changed it, the reason), with the current open version **locked from editing** and only closable.
**Wizard "Add new version"**: (1) type + rate / fixed amount; (2) base + fee types + caps + floors;
(3) `effective_from` + **mandatory reason**; (4) preview - "a PKR 10,000 receipt today earns 1,000.00 under
the current rule and 1,500.00 under this one", plus a plain warning that nothing already posted will change.

### 8.5 Wallet detail

**Stat cards** (`x-ui.stat-card` x 6): Available (spendable, **rendered in rose when negative**), Pending,
Reserved, Paid, Lifetime, Total reversed.
**The identity is printed on the page**: `lifetime 50,000.00 = pending 0.00 + available 30,000.00 +
reserved 0.00 + paid 20,000.00`.
**Reconciliation banner.** Green chip "proven equal to the ledger, 01:30 today"; **rose banner** on drift
showing the drift amount, the run link, and a Recalculate button behind confirm - and while the status is
`drift` every figure on the page is the **derived** one, labelled as such (§6.5.4).
**Tabs.** Ledger · Payouts · Statement · Rules · Reconciliation history.
**Negative-balance panel.** When available is below zero: the clawback rows that caused it, each with its
source receipt, and the plain sentence "recovered from future earnings" or "written off" per the policy.

### 8.6 Payout wizard and register

**Wizard.** (1) collaborator + requested amount, with "available 30,000.00" and the minimum payout rule
shown and enforced server-side; (2) **FIFO allocation preview** listing the exact entries and slices that
will be consumed, with a running total, and a clear message when the request cannot be met
("available 30,000.00, requested 40,000.00 - short by 10,000.00"); (3) destination account (masked,
last 4 only, chosen from the collaborator's accounts); (4) review + confirm. The amount field becomes
read-only after allocation - it is derived (INV-22).
**Register.** Status tabs (Requested / Pending / Approved / Paid / Rejected / Cancelled) over one table:
payout # · collaborator · amount · entry count · method chip · requested / approved / paid dates ·
transaction id · actions. Filters: collaborator, status, method, date range, amount range.
**Detail.** The allocation table (entry, date, slice, released flag + reason), the status timeline with
actor and reason per step, and a printable voucher (`collaborator_payouts.print`).
**Empty state.** "No payouts yet" + Create payout.

### 8.7 Statement (§56)

Opening-balance row; movements grouped into the five §56 lines (student commissions, project commissions,
manual adjustments, reversals, payouts) with subtotals; closing-balance row; and a **proof footer**:
`opening 12,400.00 + credits 9,000.00 - debits 1,000.00 - payouts 2,000.00 = closing 18,400.00`.
Filters per §56: date range, student, project, source, status. Export: print, PDF, CSV
(`collaborator_commissions.export` / `collaborator_portal.statement_download`). A
"show technical rows" toggle (default from `collaborator.statement_show_technical_rows`) reveals
`write_off` / `manual_adjustment` technical pairs.

### 8.8 Commission discrepancies and skip report (the operations screens)

**Discrepancies.** Every entitlement with `over_released_amount > 0` (a post-payment discount or a
downward project-value revision, §6.6 rows 4 and 8): collaborator, document, promise, released,
over-released, the supersede reason, and the two actions a human may take (post a `manual_adjustment`, or
accept and note it). Without this screen the state of §6.6 rows 4 and 8 would rot unresolved.
**Skip report.** `commission_skip_reason` grouped with counts and amounts over a date range
("14 receipts earned nothing: collaborator inactive - 3; fee type not commissionable - 11"), each row
drilling into the receipts. This is how a misconfigured collaborator is found before the partner complains.

### 8.9 Reconciliation screen

Per-collaborator expected versus stored per bucket with the difference, the identity and payout
cross-check columns, the structural check flags, run history, and "Run now" behind confirm
(`wallet_reconciliation.change_status`, throttled). A `failed` run renders rose with the offending entry
ids linked, and **offers no repair button** (§6.5.4).

### 8.10 Charge detail (Phase 18) and project payment trail (Phase 11/13)

`x-ui.page-header` + three stat cards (net, paid, balance) + `x-ui.tabs`: Payments · Installments
(due-date colouring, waive action) · Discounts (append-only list with reason and approver) ·
**Commission trail** (the ledger rows this document produced, read-only, permission-gated by
`collaborator_commissions.view_financial`). The printable fee slip renders §41's commission percentage and
amount **from the entitlement and the ledger at print time** - never from duplicated columns on the charge
([D-FS-4], §109 "do not duplicate financial records").

### 8.11 Collaborator panel screens

Read-only versions of 8.3, 8.5, 8.6 and 8.7 scoped to self, plus the §57 student list (name, course,
batch, registration date, status, total paid, commission earned) and the §58 project list (project, client,
value, received, rate, commission earned, status). **Every money column is gated per field** by the
matching `collaborator_portal.*` permission, and a column the user lacks is absent from the response
entirely - not rendered blank. No other student's or client's data ever appears.

### 8.12 Dashboard widgets registered into Phase 2's `DashboardRegistry`

**The eight widgets this spine owns:** `ProjectPaymentsThisMonthWidget`,
`CommissionPendingApprovalWidget`, `CommissionPaidThisMonthWidget`, `WalletLiabilityWidget` (total
available + pending across all collaborators - the company's real liability),
`PayoutQueueWidget`, `WalletDriftWidget`, `CommissionBySourceChartWidget`,
`CollaboratorLeaderboardWidget`. Each declares its `module()` and `permission()` so Phase 2's gating
applies unchanged, and each reads **only** through `CollaboratorWalletService` /
`CollaboratorStatementService` (INV-26). §88 and §98 cards that belong to other phases reference the same
widget classes rather than recomputing.

**`FeeCollectedTodayWidget`, `PendingFeesWidget` and `OverdueFeesWidget` are registered by Phase 18**, which
owns the fee screens those cards link to (F-8.3). `DashboardRegistry` is keyed by `key()`, so two classes
claiming one key means one card silently disappears — this spine and Phase 10-12 therefore register **none**
of the three. The fee figures they show come from Phase 18's `StudentFeeService`, not from this spine.

---

## 9. Data isolation

Every rule below is an Eloquent **global scope** plus a **Policy** check - never a hidden form field
(`CLAUDE.md` §1.10) - and every rule has its own feature test asserting both the 403/404 **and** the
absence of the forbidden columns from the response body.

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating (a disabled module 403s Super Admin too). |
| **Admin** | Unrestricted within the permissions Phase 1 §5 grants. |
| **Accountant** | Unrestricted **read** on every table in this spine; writes only where the permission is granted; money columns additionally require `view_financial`. Policy refuses to approve a payout the same user created (`approved_by <> created_by`) whenever both abilities are held. |
| **Institute Manager / Course Coordinator** | `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`: `where branch_id = user.branch_id OR branch_id IS NULL` (D11). **No access to any `collaborator_*` table** (403). |
| **Receptionist** | `student_fee_payments`: `create` only; the index is additionally scoped to `created_by = auth()->id() OR branch_id = user.branch_id`. No commission, wallet, payout or reversal access (403). |
| **Collaborator** | A global scope `BelongsToAuthenticatedCollaborator` adds `where collaborator_id = auth()->user()->collaborator->id` to `CollaboratorCommissionLedgerEntry`, `CollaboratorCommissionEntitlement`, `CollaboratorCommissionSetting`, `CollaboratorWallet`, `CollaboratorWalletReconciliation`, `CollaboratorPayout`, `CollaboratorPayoutAllocation`, `CollaboratorPayoutAccount`, `CollaboratorReferral`, **and to both payment tables**. Route-model binding additionally asserts ownership in the policy and returns **404, not 403**, so ids cannot be probed. Payment rows expose a whitelisted column list only (no other student's contact data, no project cost fields), each money column withheld unless the matching `collaborator_portal.*` permission is held (§57, §58, §59). |
| **Student** | `student_fees`, `student_fee_installments`, `student_fee_payments`: `where student_id = auth()->user()->student->id`. The SELECT is an explicit column list that **omits** `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason` and every commission column. Zero access to ledger, entitlement, wallet, payout tables (403). |
| **Client** | `project_payments`: `where client_id = auth()->user()->client->id`, with `collaborator_id` and the commission columns omitted. Zero access to ledger, wallet, payout tables (403). |
| **Teacher** | No access to any table in this spine - every route 403. |
| **Branch (D11)** | When `users.branch_id` is set, institute-side queries add `where branch_id IS NULL OR branch_id = user.branch_id`. Collaborator ledger, wallet and payouts are **global**: a collaborator is not branch-bound. |
| **Module gating** | Disabling `collaborator_commissions`, `collaborator_wallets`, `collaborator_payouts`, `student_fees`, `student_fee_payments` or **`project_payments`** 403s those routes for everyone via Phase 1's `Gate::before` while leaving every row and every queued job intact (§6.6 row 22). Project payments are gated by `project_payments`, **not** the `payments` umbrella (F-6.1), on the admin **and** the client side; the four `*_portal` permission namespaces are never module-gated (D20). |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (all dispatched through `DB::afterCommit()` - a rolled-back transaction can never notify anyone)

`StudentFeeIssued`, `StudentFeeAdjusted`, `StudentFeeCancelled`, `StudentFeePaymentRecorded`,
`ProjectPaymentRecorded`, `PaymentReversalRecorded`, `PaymentReversalApproved`,
**`PaymentReversalRejected`** (F-4.9 - the rejection leg exists: a reversal that is refused must roll the
payment's `refunded_amount` back and let every cache that listened to `PaymentReversalRecorded` recompute,
so the invoice, charge and wallet figures cannot be left holding a refund that never happened; dispatched
`afterCommit` like every other event here), `ReferralAttached`,
`ReferralChanged`, `ReferralRevoked`, `CommissionRuleVersioned`, `EntitlementSuperseded`,
`CommissionCreated`, `CommissionSkipped`, `CommissionApproved`, `CommissionRejected`, `CommissionReleased`,
`CommissionReversed`, `CommissionClawedBack`, `CommissionAdjusted`, `PayoutRequested`, `PayoutApproved`,
`PayoutPaid`, `PayoutRejected`, `PayoutCancelled`, `WalletRecalculated`, `WalletDriftDetected`.

### 10.2 Queued jobs

| Job | Key properties |
|---|---|
| `ProcessStudentFeeCommission` | `ShouldBeUnique` (`student-fee-payment:{id}`, `uniqueFor` 3600), `$afterCommit = true`, `tries` 5, `backoff [10,30,60,120,300]`; `failed()` writes `commission_state = failed` with the exception class |
| `ProcessProjectPaymentCommission` | `project-payment:{id}`, otherwise identical |
| `ProcessCommissionReversal` | `payment-reversal:{id}`, otherwise identical; honours `approval_status` |
| `RecalculateCollaboratorWallet` | `wallet:{collaborator_id}` - dispatched after a manual adjustment, a clawback, or from the wallet screen |
| `ReconcileCollaboratorWalletChunk` | one job per 200 collaborators inside a run, carrying the `run_uuid` |
| `BuildCollaboratorStatementExport` | PDF/CSV generation for large ranges; the file is delivered through the notification |

### 10.3 Notifications (database channel now, mail-ready - §97)

To the **collaborator**: `StudentCommissionAdded`, `ProjectCommissionAdded`, `CommissionApproved`,
`CommissionReversed` (includes the clawback case and its policy in plain language), `PayoutApproved`,
`PayoutPaid`, `PayoutRejected`.
To the **student**: `FeePaymentReceived` (with the receipt), `FeeDueReminder`, `FeeOverdue`.
To the **client**: `ProjectPaymentReceived`.
To staff: `PayoutRequested` (to holders of `collaborator_payouts.approve`),
`WalletDriftDetected` and `CommissionGenerationFailed` (to holders of `wallet_reconciliation.view_any`),
`RefundAwaitingApproval` (to holders of `payment_reversals.approve`).

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `commissions:sweep` | every 10 minutes | re-queue `student_fee_payments`, `project_payments` and `payment_reversals` whose `commission_state` is `queued` or `failed` and older than 2 minutes, bounded to 500 rows, ordered by `paid_on` then id so an earning always precedes its reversal. Covers a dead worker, a lost queue lock and an out-of-order delivery |
| `commissions:release-held` | daily 00:10 | `approved -> available` where `hold_until <= today` |
| `fees:mark-overdue` | daily 01:00 | charges and installment lines past `due_date` with a balance |
| `collaborators:reconcile-wallets` | daily 01:30 | §6.5.6 - one `run_uuid`, a reconciliation row per collaborator, notify on drift |
| `financial:verify-constraints` | daily 02:00 | assert every unique index, CHECK, generated column and delete trigger of §2 still exists; alert loudly if one vanished |
| `fees:installment-reminders` | daily 09:00 | uses Phase 2's `institute.installment_reminder_days` |
| `payouts:expire-stale-requests` | weekly | flag `requested` payouts older than 30 days for staff attention (never auto-rejects money) |
| `commission-rules:activate` | daily 00:05 | `scheduled -> active` when `effective_from` is reached; `active -> expired` when `effective_to` passed with no successor |

---

## 11. Acceptance tests / test matrix

`tests/Feature/Financial/`. Every money test ends with the shared helper
`assertWalletMatchesLedger($collaborator)` (which calls `CommissionReconciliationService` and asserts all
eight checks of §6.5.3), so the nightly proof and the suite share one implementation.

### 11.1 The nine requirement tests (§120), expanded to concrete rows

| # | Test name | Setup | Asserted rows |
|---|---|---|---|
| FT-01 | `test_student_without_collaborator_creates_no_commission_row` | student with no referral pays 10,000 | `assertDatabaseCount('collaborator_commission_ledger_entries', 0)`; **no zero-amount row exists** (`where amount = 0` is empty); payment `commission_state = skipped`, `commission_skip_reason = no_referral`; no entitlement row (§120.1) |
| FT-02 | `test_student_payment_creates_one_commission_at_configured_rate` | 10% rule, base `paid`, receipt 10,000 | exactly 1 row: `amount = 1000.00`, `entry_type = credit`, `purpose = student_commission`, `base_amount = 10000.00`, `gross_amount = 10000.00`, `commission_rate = 10.0000`, `commission_base = paid`, `status = pending` (manual mode, per D-Q5), `rule_snapshot` non-empty, `transaction_date = paid_on`; wallet `pending 1000.00`, `available 0.00` (§120.2) |
| FT-03 | `test_same_fee_payment_processed_twice_creates_one_commission` | call the service twice, dispatch the job twice, **and** attempt a raw duplicate INSERT | `assertDatabaseCount(... , 1)`; the second service call returns `created: false`; the raw INSERT throws `UniqueConstraintViolationException` on `uq_cle_dedupe`; a second raw INSERT with a hand-made different `dedupe_key` throws on `uq_cle_source` (§120.3) |
| FT-04 | `test_three_installments_create_three_commissions` | three 10,000 receipts on three installment lines | three rows of `1000.00` with distinct `student_fee_payment_id` and `student_fee_installment_id`; one entitlement with `collected_amount 30000.00`; wallet total `3000.00` (§120.4) |
| FT-05 | `test_student_refund_creates_negative_reversal_and_preserves_original` | full refund after a 1,000 commission | a new row `amount = 1000.00`, `entry_type = debit`, `signed_amount = -1000.00`, `purpose = reversal`, `reverses_entry_id` and `payment_reversal_id` set, rule columns copied; the original re-fetched is **byte-identical** on `amount`, `commission_rate`, `base_amount`, `rule_snapshot`; both rows `status = reversed`, `reversed_at` set; wallet available and lifetime back to `0.00` (§120.5) |
| FT-06 | `test_project_without_collaborator_creates_no_commission` | project with no referral receives 100,000 | zero ledger rows; `commission_skip_reason = no_referral` (§120.6) |
| FT-07 | `test_project_payment_creates_commission_at_configured_rate` | 15% rule, base `paid`, payment 100,000 | one credit `15000.00`, `purpose = project_commission`. Variant with base `total_value` on a 200,000 project: entitlement `30000.00`, first 100,000 releases `15000.00`, second releases exactly the residual `15000.00` and **no more** (§120.7, §46) |
| FT-08 | `test_project_payment_refund_creates_reversal_entry` | refund the 15,000-commission payment | a `-15000.00` reversal referencing the original and the `payment_reversals` row; original preserved (§120.8) |
| FT-09 | `test_payout_of_20000_against_50000_wallet` | one 50,000 `available` credit, payout 20,000 approved and paid | wallet `available 30000.00`, `paid 20000.00`, `reserved 0.00`, `lifetime 50000.00`; the closed identity holds; the payout row **and** its allocation still exist; the entry is still `available` with `allocated_amount = 20000.00` (§120.9) |

### 11.2 Money, rounding and algorithm tests

| # | Test | Expectation |
|---|---|---|
| FT-10 | `test_percentage_uses_bcmath_half_up` | `Money::percentage('10000.00','10.0000') === '1000.00'`; `('3333.00','10.0000') === '333.30'`; `('3333.33','10.0000') === '333.33'` |
| FT-11 | `test_fixed_commission_prorates_across_installments_to_exact_total` | fixed 2,000, collectible 30,000, three 10,000 receipts -> `666.67`, `666.66`, `666.67`; `SUM = 2000.00`; entitlement `released_amount = 2000.00`, `status = fully_released`; a fourth receipt yields **no row** (`entitlement_cap_reached`) |
| FT-12 | `test_fixed_release_modes` | `on_first_payment` releases 2,000 on receipt 1 and nothing after; `per_payment` releases 2,000 per receipt with `entitlement_amount` NULL |
| FT-13 | `test_document_base_cannot_over_release` | base `gross` 10% on gross 30,000 (promise 3,000) with collectible 25,000: releases sum to exactly 3,000.00 when 25,000 is collected; a raw UPDATE pushing `released_amount` past the promise is rejected by `chk_cce_cap` |
| FT-14 | `test_overpayment_caps_the_commissionable_amount` | 35,000 receipt on a 25,000 net charge: `base_amount 25000.00`, `gross_amount 35000.00`, credit `2500.00`, charge `overpaid` with negative `balance_amount`; with `commission_on_overpayment = true` the base is 35,000 |
| FT-15 | `test_commission_that_rounds_to_zero_creates_no_row` | 10% of 0.04 -> no row, `rounds_to_zero`, `collected_amount` unchanged; a 0% rate -> no row |
| FT-16 | `test_partial_refunds_never_over_reverse` | commission 1,000 on 10,000 refunded 3,333 / 3,333 / 3,334 -> reversals `333.30 / 333.30 / 333.40`, `SUM = 1000.00` exactly; `reversed_amount + clawed_back_amount = amount`; a raw UPDATE breaching it is rejected by `chk_cle_undo_ceiling` |
| FT-17 | `test_refund_larger_than_payment_is_rejected` | validation error; a raw INSERT breaching the ceiling is rejected by `chk_sfp_refund_ceiling`; nothing written |
| FT-18 | `test_earning_and_reversal_jobs_are_order_independent` | run (earning, reversal) and (reversal, earning) on identical fixtures -> identical final wallet, identical row count; the deferred path leaves the reversal `queued` and the sweeper completes it |
| FT-19 | `test_partial_refund_leaves_the_correct_remainder` | 4,000 refund of a 10,000 receipt with a 1,000 commission -> one `-400.00` reversal, original still `available` with `reversed_amount 400.00`, available `600.00`; a payout can allocate at most 600.00 |
| FT-20 | `test_refund_after_payout_claws_back_and_goes_negative` | commission 1,000 paid out, then a full refund -> original stays `paid`, a `clawback` debit of `1000.00` in `available`, wallet available `-1000.00`, a new payout refused; a later 5,000 earning makes available `4000.00`; `write_off` policy instead posts the clawback as `cancelled` and leaves available at 0.00 |

### 11.3 Rules, referrals and attribution

| # | Test | Expectation |
|---|---|---|
| FT-21 | `test_rule_is_resolved_on_the_value_date` | v1 10% (Jan 1 - Mar 31), v2 15% (from Apr 1). A receipt dated Mar 15 posted on Apr 5 earns **10%** and points at v1; one dated Apr 2 earns 15%. Rows posted before the change are byte-identical afterwards |
| FT-22 | `test_rule_versions_are_immutable_and_non_overlapping` | a direct `update(['rate' => ...])` throws; `createVersion` closes v1 at `effective_from - 1 day`; a second open-ended version violates `uq_ccs_open`; two versions starting the same day violate `uq_ccs_start` |
| FT-23 | `test_rule_referenced_by_a_ledger_row_cannot_be_deleted` | delete is refused by the policy and by RESTRICT; closing it before a referencing entry's `transaction_date` is refused |
| FT-24 | `test_concurrent_workers_create_one_commission` | two processes with a latch call `handlePayment` on the same receipt -> exactly one row; the loser gets the existing entry and no exception surfaces; no deadlock after 3 attempts |
| FT-25 | `test_two_concurrent_payouts_cannot_allocate_the_same_amount` | two payout creations racing on one 50,000 entry -> allocations are disjoint, `SUM(allocated) <= amount`, one request fails with a named shortfall |
| FT-26 | `test_payout_lifecycle_and_bank_return` | reject releases allocations and restores `available`; `markPaid` twice fails on `UNIQUE (method, transaction_id)`; `cancelAfterPayment` walks entries `paid -> available`, releases allocations with `payout_returned`, keeps the payout row, and writes an audit row with the reason |
| FT-27 | `test_partially_allocated_entry_is_not_marked_paid` | after a 20,000 payout on a 50,000 entry, `status` is still `available`; the "paid commission" report (derived from allocations) shows 20,000.00 while a naive `where status = 'paid'` shows 0 - asserted so the footgun stays documented |
| FT-28 | `test_attribution_change_does_not_move_existing_commissions` | A -> B with a reason: A's rows untouched and still point at A and at referral #1; the old referral is `superseded` with `effective_to`; only receipts dated on or after B's `effective_from` credit B; a back-dated receipt before the switch credits **A**; an activity row holds old and new values plus the reason (§37, §107) |
| FT-53 | `test_one_winner_may_supersede_many_losers` (ND-12) | `superseded_by_id` carries a **non-unique** index: `change()` superseding the previously active referral and two successive `recordLosingCandidate()` calls against the **same** winner all succeed (no 1062, three rows sharing the winner id, each with its own mandatory reason); `uq_cr_*_current` still refuses a second **active** referral for that subject, and `information_schema` shows `idx_cr_superseded_by` as non-unique |
| FT-29 | `test_transfer_between_charges_does_not_double_commission` | money moved from charge A to charge B carries A's `paid_on`, reverses A's commission pro-rata and earns on B once; the net commission change is 0.00 |
| FT-30 | `test_no_commission_on_registration_admission_or_project_creation` | creating a student, an admission, a charge, a project, a milestone and an invoice produces **zero** ledger rows (INV-1) |
| FT-31 | `test_no_commission_from_an_unpaid_installment_plan` | a 3-line plan with no receipts produces zero rows |

### 11.4 Approval, settings and state machine

| # | Test | Expectation |
|---|---|---|
| FT-32 | `test_approval_modes` | manual: posted `pending`; `collaborator_commissions.view` only -> 403 on approve; with `.approve` -> `approved` then `available` in one transaction (two activity rows, `available_at` set); reject -> `cancelled`, out of every bucket, reason stored, and the payment stays `cleared`. Automatic + hold 0 -> posted `available`. Automatic + hold 7 -> `approved` with `hold_until`, released by the job on day 7 and not before |
| FT-33 | `test_ledger_money_columns_are_immutable` | `$entry->update(['amount' => ...])` throws `ImmutableLedgerAttributeException`; the same for `commission_rate`, `base_amount`, `rule_snapshot`, `dedupe_key`, `source_id`; updating `status` / `notes` succeeds |
| FT-34 | `test_financial_rows_cannot_be_deleted` | `$entry->delete()` and `forceDelete()` throw; a raw `DELETE` raises SQLSTATE 45000 on each of the nine append-only tables; no `deleted_at` column exists on them |
| FT-35 | `test_payment_cannot_be_edited_and_void_is_the_correction_path` | a payment `update` of `amount` / `paid_on` / `student_fee_id` is refused by the policy; `void` + re-record leaves three rows (receipt, reversal, corrected receipt) and one reversed commission |
| FT-36 | `test_ledger_can_only_be_written_through_the_writer` | `CollaboratorCommissionLedgerEntry::create()` outside `LedgerWriter` throws `DirectLedgerWriteException` |
| FT-37 | `test_every_discretionary_act_is_audited_with_a_reason` | approve, reject, reverse, clawback, write-off, rule version, attribution change, payout reject/cancel each write an `activity_log` row with old/new, actor, IP and the reason; a missing reason fails validation |
| FT-38 | `test_settings_changed_after_posting_change_nothing_already_posted` | flip `student_commission_base` gross->paid, `commission_approval_mode` automatic->manual, the default rate 10->15, `fixed_commission_release`, `commission_on_overpayment`: every existing row and its `rule_snapshot` compare byte-for-byte |
| FT-39 | `test_backdate_window` | 90 days back is rejected at `backdate_limit_days = 30`; with the `approve` ability it posts and earns the **then-effective** rate; a future `paid_on` is always rejected |

### 11.5 Wallet, reconciliation, statement

| # | Test | Expectation |
|---|---|---|
| FT-40 | `test_wallet_equals_ledger_after_every_scenario` | the helper runs after FT-01..FT-39: all eight checks pass, `drift_total = 0.00`, `identity_holds = true`, `payout_cross_check = 0.00` |
| FT-41 | `test_no_float_in_any_money_path` | a static scan of `app/Services/{Finance,Institute,Collaborator}` and the models finds no `+ - * /` applied to a money attribute or a `decimal` cast value |
| FT-42 | `test_only_the_wallet_and_statement_services_compute_balances` | a static assertion that controllers, widgets, exports and notifications depend on `CollaboratorWalletService` / `CollaboratorStatementService` and contain no `SUM(` over the ledger. **Plus the company-wide identity (ND-6):** for a fixture with several collaborators, `payoutsPaidTotal(null, $r)` equals the sum of `payoutsPaidTotal($c, $r)` over every collaborator **to the paisa**, and the same for `commissionAccruedTotal`; a query count proves the company-wide call is **one** query, not a loop; both throw when `$c` and `$r` are both null |
| FT-43 | `test_drift_is_detected_reported_and_not_silently_repaired` | a corrupted wallet row is detected, status `drift`, a notification sent, **the cache not rewritten**, and the wallet screen renders the derived figures behind the banner; `--repair` then rewrites the cache and records before/after; a corrupted allocation (structural) sets `failed` and repairs nothing |
| FT-44 | `test_statement_balances_and_exports_match` | a 200-entry fixture: `opening + credits - debits - payouts = closing`; the service throws if forced out of balance; CSV and PDF totals match the screen to the paisa; §56's five lines and all filters are present |
| FT-45 | `test_db_guarantees_actually_exist` | each CHECK rejects a bad INSERT (`chk_cle_amount`, `chk_cle_sign`, `chk_cle_allocation_ceiling`, `chk_cle_undo_ceiling`, `chk_cce_cap`, `chk_sfp_refund_ceiling`, `chk_pr_one_target`, `chk_sf_discount_ceiling`); each NULL-tolerant unique guard rejects the second INSERT (`uq_cr_student_current`, `uq_ccs_open`, `uq_cce_current`, `uq_cpacc_default`); every generated column and delete trigger exists - so a silently dropped constraint fails CI, not production |

### 11.6 Authorization matrix (each asserts the HTTP status **and** that nothing was written)

| # | Test | Expectation |
|---|---|---|
| FT-46 | `test_authorization_matrix` | no `student_fee_payments.create` -> 403 on recording; no `collaborator_commissions.approve` -> 403 on approve **and** on `bulk-approve` with forged ids; no `payment_reversals.create` -> 403 on refund; no `collaborator_payouts.approve` -> 403 on approve; no `wallet_reconciliation.change_status` -> 403 on run and recalculate |
| FT-47 | `test_collaborator_isolation` | collaborator A requesting B's ledger entry, wallet, payout, allocation, statement or referral gets **404**; id enumeration on every route; A's list queries never contain B's rows |
| FT-48 | `test_portal_permission_gates_money_columns` | a collaborator without `collaborator_portal.student_commission` sees no student-commission figures **in the response body**; without `.project_value` no project value; without `.payout_request` a POST payout is 403 even when the setting is on |
| FT-49 | `test_student_and_client_isolation` | a student sees only own charges and receipts and the body contains no `collaborator_id` / commission column; another student's receipt is 404; a client sees only own project payments; a teacher is 403 everywhere in this spine |
| FT-50 | `test_module_gating_preserves_data` | disabling `collaborator_commissions` 403s the routes for Super Admin too; row counts before and after disable + re-enable are identical; queued jobs still complete |
| FT-51 | `test_migrations_roll_back_cleanly` | every spine migration rolls forward and back on a database holding rows (generated columns, CHECKs and triggers dropped in the right order); `migrate:fresh --seed` is clean; the guarded `add_fks_to_*` migrations are no-ops when the target table is absent |
| FT-52 | `test_link_invoice_is_narrow_and_project_payments_has_no_edit` (ND-1) | `PermissionRegistry` contains **`project_payments.link_invoice`** and contains **no `project_payments.edit`** and no `student_fee_payments.link_invoice`; `link_invoice` appears in no ability preset. A user holding `link_invoice` + `invoices.edit` may apply and unapply a payment (200, one activity row with old/new `invoice_id` and the reason); holding only one of the pair is **403**; holding `link_invoice` alone cannot change `amount`, `paid_on`, `payment_method_id`, `reference_no`, `status`, `collaborator_id` or any commission column (the model guard throws and the row is byte-identical afterwards), cannot create, refund, void or delete a payment, and is 403 on every `student_fee_payments` write. A seeded Accountant holds the pair; no other seeded non-admin role does |

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **The entitlement layer is conceptual overhead.** For the default configuration (percentage on actual paid) it does almost nothing, yet every developer must understand it and every fixture must create it. | It is the only place a fixed amount or a document-level base can be capped **atomically**, and `chk_cce_cap` is the one constraint that protects against a future buggy caller. The alternative - summing the ledger in PHP - is exactly the check-then-act race this design exists to eliminate. `openOrLoad()` hides it behind one call, and factories create it implicitly. |
| R-2 | **Partial allocation means `status = 'available'` no longer tells you whether the collaborator was paid** - only `allocated_amount` does. A developer writing `where status = 'paid'` will silently under-report. | Required by §120.9. Mitigated by the query scopes `payable()` / `paidPortion()`, by INV-23, by FT-27 asserting the footgun explicitly, and by the rule that paid figures come from allocations. |
| R-3 | **Three guarantees rest on MariaDB 10.4 features Laravel's schema builder does not express**: enforced CHECK constraints, STORED generated columns, and unique indexes over generated columns. On MySQL 5.7 CHECKs are silently ignored. | The migrations write raw SQL and must **fail loudly** rather than skip a constraint; FT-45 asserts every CHECK, guard index, generated column and trigger actually exists, so a silently dropped or unsupported constraint fails CI instead of surfacing in production. |
| R-4 | **The NULL-tolerant unique trick** (`current_guard`, `open_guard`, `active_guard`, `default_guard`) is clever, and clever is a liability: a developer who reopens a superseded row, or writes the generated expression slightly differently in a later migration, breaks a guarantee no happy-path test notices. | Each guard has its own test asserting the **second** INSERT throws (FT-45), and `financial:verify-constraints` re-asserts them daily. |
| R-5 | **Delete triggers are invisible to Laravel developers.** A factory, seeder or cleanup script that deletes a money row gets a raw SQL error with no Eloquent explanation. | Model `deleting` hooks throw first with a clear message, so the trigger is only the last line of defence; this needs a loud paragraph in `CLAUDE.md` (§13 request) or someone will "fix" it by dropping the trigger. |
| R-6 | **`over_released_amount` can sit unresolved for ever.** Nothing forces anyone to clear a post-discount discrepancy. | §8.8 gives it a dedicated queue screen with counts on the dashboard, which is the honest fix short of inventing an approval workflow the requirement does not ask for. |
| R-7 | **`cancelAfterPayment` (`paid -> available`) is the weakest point in the state machine** - the one door through which settled money re-enters a spendable bucket, and the first thing an attacker would target. | Two permissions, a mandatory reason, a full audit row, allocation release with an explicit reason code, and FT-26. It exists because bank transfers really are returned; the alternative (no path at all) would force someone to edit the ledger by hand. |
| R-8 | **A negative available balance can persist for ever** if the collaborator never earns again. Nothing in this design chases the debt. | Stated plainly rather than hidden (INV-25); the wallet screen and the liability widget show it; recovery or write-off are explicit, audited acts. Dunning is out of scope. |
| R-9 | **Reserved balance is derived from payout status, so a payout stuck in `approved` silently locks a balance** with no timeout. | `payouts:expire-stale-requests` flags stale rows for staff; it never auto-rejects money. |
| R-10 | **"Gross" and "net after discount" are implemented as "money received, released against a capped promise"** - an interpretation, not the client's words. A client choosing "gross fee" expecting the whole commission on the first receipt will find it spread across installments. | It is the only reading compatible with §39 and §42 ("never pay full commission before payment is received"), it is recorded in `rule_snapshot` on every row, and it is raised as Q3 below rather than buried in a resolver class. |
| R-11 | **Wallet derivation is O(entries per collaborator)** on every recalculation and the nightly run is O(all entries). Past roughly 100k entries for one collaborator the nightly job will be slow. | Fine at the scale this business plausibly reaches with the stated indexes. The formulas are written so an additive monthly snapshot table (`collaborator_wallet_snapshots`, opening balance per month) can be introduced later without changing a single definition - deliberately deferred, not designed in now. |
| R-12 | **No multi-currency.** A USD-paying client must be converted before posting; no rate or original amount is stored. | The `currency` column is a constant so a future split is additive rather than archaeological. The day the software house takes a foreign project this spine needs a schema change, not a setting. |
| R-13 | **The design is large**: 15 tables, ~16 services, 30 enums before a single screen exists, and the real risk is partial implementation - the CHECKs, the triggers or the reconciliation "skipped for now", leaving the guarantees in this document stated but not true. | It must be built and migrated as one atomic unit (§1.3), and the tests that prove the DB-level guarantees (FT-33, FT-34, FT-45) are the ones most likely to be deferred and **must not be**. |
| R-14 | **Two accountants receipting the same banknote is not solved by the database.** | Stated honestly in §2.19 and §6.6 row 16: fingerprint warning + explicit confirmation + audit. Any reviewer expecting "duplicate-proof" to cover two genuinely separate submissions should read that paragraph. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | ~~**Does the client accept `DEVELOPMENT_LOG.md` decision D16** - that the nine append-only financial tables omit `deleted_at`?~~ **ANSWERED 2026-09-12.** | **Yes, omit it.** **D16 is approved and frozen** in `DEVELOPMENT_LOG.md` §4, and the general category rule is **D19** (`CLAUDE.md` §3). A nullable `deleted_at` on an immutable ledger is a loaded gun ([D-FS-3]). Nothing is blocked; the migration may be written. |
| Q2 | Is a fixed commission **per admission** or **per installment**, and is it earned up front or in proportion to collection? | Per admission, released in proportion to money collected, residual on the final receipt ([D-FS-10]). The other two behaviours are a per-collaborator dropdown. |
| Q3 | For the `gross` / `net_after_discount` / `total_value` bases, is the commission released in proportion to collection (never ahead of it), as §42 implies? | **Yes** (R-10). If the client truly wants the whole commission on the first receipt, that is `fixed_release = on_first_payment` semantics extended to percentages and would need a new release mode, not a redesign. |
| Q4 | Should a **hold period** separate `approved` from `available` (the only structural defence against paying out commission that is refunded the next day)? | `commission_hold_days = 0`, which reproduces §51/§53 literally. If the client confirms Approved and Available are synonyms, the setting simply stays at 0; the clawback path is then the only remedy. |
| Q5 | Does a refund claw back commission **already paid out in cash**, or is it written off? | Claw back via a negative available balance (`offset_future`); `write_off` is available per act, permissioned and notified. |
| Q6 | Should closed months be **lockable** so a back-dated receipt cannot rewrite an issued statement? | Not in this spine: a 30-day back-date window plus the `approve` ability plus `posted_at`. A `financial_periods` table is offered to Phase 13 if the client wants hard locks ([D-FS-6]). |
| Q7 | Is cryptographic tamper evidence (a per-collaborator hash chain over the ledger) wanted? | No - rejected for throughput and for defending only against an attacker who could also recompute it ([D-FS-6]). The append-only ledger + activity log + nightly proof is the evidence. |
| Q8 | Who may post a `manual_adjustment` or a `write_off`, and does it need a **second** approver? | `collaborator_commissions.create` plus a mandatory reason; no second approver. Segregation of duties is enforced only on payouts (§9, Accountant row). |
| Q9 | One in-flight payout per collaborator? | Yes by default (`payout_single_inflight = true`), as a **service** guard so the setting is honest ([D-FS-6]); the DB never enforces it. |
| Q10 | ~~Phase ordering: Phase 10 (student commission engine) precedes Phase 18 (student fees) in `DEVELOPMENT_LOG.md` §5, which is why Phase 10 must ship the fee tables ([D-FS-2]). Should the tracker instead move the fee **tables** into a "Phase 10 foundation" line?~~ **ANSWERED 2026-09-12** (F-11.2). | **No re-numbering.** Phase 10 ships the spine migration set and §1.3 stays the ownership map; **the set is applied in the same release, immediately after Phase 8's own migrations**, so `collaborators` and its wallet observer exist before the first guarded FK migration runs. The spine-dependent screens stay hidden behind their module switches until their own phase, and `collaborators:backfill-wallets` + `collaborators:seed-initial-rules` run once afterwards (phase-08-09 §1.4 [D-P8-1]). The tracker carries a one-line note under Phase 8 and a second under Phase 18 (resolutions §6.3); Phase 18 creates **no** financial table. |

---

## 13. Requests to other phases

Stated as `table.column - why`, plus the behavioural asks.

### 13.1 Columns and tables this spine needs

| Request | Why |
|---|---|
| `collaborators.status` (enum Pending/Active/Inactive/Suspended) - Phase 8 | guard step 4; only `Active` earns |
| `collaborators.referral_code` string(32) UNIQUE, immutable once any referral exists - Phase 8 | snapshotted into `collaborator_referrals.referral_code`; §38 |
| `collaborators.deleted_at` - Phase 8 | guard step 4 treats trashed as inactive; every financial screen loads it `withTrashed()` |
| A Phase 8 **observer** creating the `collaborator_wallets` row in the same transaction as the collaborator | so no money path ever creates a wallet lazily (§6.6 row 17) |
| A Phase 8 **policy** blocking `forceDelete` on a collaborator that has any ledger row, entitlement, payout or referral | my FKs are RESTRICT; the policy must say *why* |
| `students.id`, `students.branch_id` - Phase 15; students not force-deletable once a charge exists | charge and receipt scoping, D11 |
| `student_admissions.id`, `.course_fee`, `.discount_amount`, `.scholarship_amount`, `.total_amount`, **`.net_payable` decimal(15,2)** - Phase 15 | the default commission document: `net_payable` is the collectible denominator of §6.1.4 and does not exist in §69's field list |
| `courses.id`, `courses.admission_fee`, `courses.registration_fee`, `batches.id` - Phases 14, 16 | fee-type exclusion settings and charge display |
| `projects.project_value`, `.discount_amount`, **`.net_value` decimal(15,2)** - Phase 6 | the project collectible denominator |
| `projects.commission_type` string(16) nullable, `.commission_rate` decimal(8,4) nullable, `.commission_fixed_amount` decimal(15,2) nullable - Phase 6 | §45 stores the commission type and rate **on the project**; this is the `project_override` rule source of §6.1.1, which neither earlier proposal handled |
| `project_value_revisions` (id, project_id, old_value, new_value, reason, effective_on, created_by) append-only - Phase 6 | §107 audit; §6.6 row 8 reads it, and a value change must never UPDATE a posted payment row |
| **`project_milestones.amount` decimal(15,2)** - Phase 6 | the `milestone` commission base cannot exist without it; §21 lists no amount |
| `clients.id` - Phase 5 | `project_payments.client_id` scoping |
| `leads.id` - Phase 5 | nullable referral subject |
| `invoices.id` - Phase 13, **and `invoices.paid_amount` must be DERIVED from `project_payments`**, never an independent counter | two counters for one fact is how an invoice and a ledger disagree in front of a client |
| `payment_methods.id` - Phase 13 | nullable FK on both payment tables; the enum stays the snapshot of record |
| `collaborator_referral_visits` - **Phase 9 owns this table** (it is not in my 15); Phase 9 must call `ReferralService::attach()` rather than writing `collaborator_referrals` directly | §38 click evidence; my `referral_visit_id` is a guarded nullable FK |
| `branches.id` - Phase 1 | nullable `branch_id` on the institute-side tables (D11) |

### 13.2 Support classes

| Request | Why |
|---|---|
| ~~`App\Support\Money` (Phase 1) additions: `min`, `max`, `abs`, `isNegative`, `sum(array)`, `round($value, $scale = 2)`, `prorate($amount, $part, $total)`~~ — **satisfied by phase-01 §3** (F-4.11) | phase-01 §3 now publishes the one canonical `Money` surface for the whole system, including these seven, with the contract this spine depends on stated there: **bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float**. §6.1 and §6.3 are written in terms of those methods; `prorate` and `round` are what make the cumulative-target method reproducible. No phase adds a second money helper |
| `App\Services\Finance\DocumentNumberService` — **owned by Phase 5**; this spine **reuses it, and must not re-create it** (F-4.1) | `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string`, every caller passing its own pad. `fee_number`, receipt numbers and payout voucher numbers all go through it: one numbering implementation means one concurrency proof |
| `App\DataObjects\Finance\RefundData` — shipped with this spine's services (F-4.2) | readonly: `string $amount`, `string $reason`, `ReversalType $type`, `?string $method = null`, `?string $idempotencyKey = null`, `?CarbonInterface $refundedOn = null`. The named form of `PaymentService::refund()` (§6.2); two adjacent `string` positionals are a silent money bug waiting for a careless call site |
| `App\DataObjects\Collaborator\ReferralContext` — shipped with this spine's services (F-4.3) | readonly: `?int $referralVisitId`, `?string $landingUrl`, `?string $ipAddress`, `?string $userAgent`, `?CarbonInterface $referralDate`, `?string $notes`. The six evidence columns of §2.8, carried as one object into `ReferralService::attach()` and `recordLosingCandidate()` so Phase 9 and Phase 14-17 never write `collaborator_referrals` directly |
| `App\Support\PermissionRegistry` (Phase 1): the four module slugs of §4.1, the ability additions of §4.2, the five portal permissions of §4.3 | the registry is the only place permission names exist (D4) |
| `App\Enums\Ability` (Phase 1) gains **one** case, `LinkInvoice` → `link_invoice`, and `PermissionRegistry` lists it on the **`project_payments` slug only**, in no preset (§4.1, ND-1). `RoleSeeder` grants `project_payments.link_invoice` to **Accountant** and to nobody else below Admin. **No `edit` ability is ever added to `project_payments` or `student_fee_payments`** | D43's guard 4 needs a permission that actually exists: an unseeded name inside a `can:` middleware is not a grant, it is a route no role can reach, and the two routes it gates are what make `invoices.paid_amount` correct for an advance. A single-purpose ability keeps the INV-8 discipline intact where a registered `edit` would quietly become reusable |
| `App\Support\SettingsRegistry` (Phase 2): the 24 keys of §5, inside the existing `collaborator` / `institute` / `finance` groups | settings definitions live in code, values in the DB |
| `App\Support\DashboardRegistry` (Phase 2): the **eight** widgets of §8.12 - the widget contract must stay stable; the three fee widgets are **Phase 18's** keys and this spine registers none of them (F-8.3) | §88, §98 |
| `App\Models\Concerns\LogsActivityWithContext` (Phase 1): `withReason(string)` used by every discretionary act | §106, §107, INV-24 |

### 13.3 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: **D16** - the nine append-only financial tables carry no soft deletes. **Approved and frozen 2026-09-12** (Q1) | a deliberate, recorded decision, not a silent one |
| `DEVELOPMENT_LOG.md` §4: **D17** - DB CHECK constraints, STORED generated columns and `BEFORE DELETE` triggers are part of the contract, so developers must expect raw SQL errors on an illegal DELETE and factories must not delete money rows | R-5 |
| `DEVELOPMENT_LOG.md` §4: **D18** - a payout allocates named ledger entries with partial amounts (not a running balance), and "paid commission" is always derived from allocations | R-2, INV-23 |
| `DEVELOPMENT_LOG.md` §4: **D19** - the general soft-delete **category** rule (append-only money, audit, log, snapshot, revision, counter and history-pivot tables carry no `deleted_at`; mutable business tables carry it), with the full text in `CLAUDE.md` §3. This spine **cites** D19 and adds nothing to it; [D-FS-3] is D16 + D19 applied to these 15 tables (F-9.1) | one rule for thirteen contracts instead of thirteen local decision numbers |
| `DEVELOPMENT_LOG.md` §4: **D43** - the single concession to INV-8: `project_payments.invoice_id` may move NULL -> value -> NULL, by `InvoiceService` alone, reason-mandatory, audited, with zero commission effect, gated by the dedicated narrow ability **`project_payments.link_invoice`** **and** `invoices.edit` - **not** by `project_payments.edit`, which does not exist and never will (§1.4 guard 4, §4.1, F-4.10, ND-1) | so nobody later "tightens" INV-8 back and breaks Phase 13's advance-to-invoice attachment, and nobody widens it to a money column or to a general `edit` on a payment table. When the owner pastes D43 into the log, this is its wording |
| `CLAUDE.md` §5: add "commission is released against a capped entitlement, never beyond `rate x document base`, and never ahead of collection" and "the wallet is a cache; only `CollaboratorWalletService` and `CollaboratorStatementService` may compute a balance" | INV-12, INV-19, INV-26 |
| `CLAUDE.md` §4 (owner): add **`link_invoice`** to the list of abilities used across modules, with the one-line note "declared on `project_payments` only, for the D43 invoice-link move; **no payment table ever gets `edit`**" | §4.1, §1.4 guard 4, ND-1. The ability list in `CLAUDE.md` §4 is the first place a developer looks, and an ability that exists in `PermissionRegistry` but not there reads as a typo |
| Phase 22 (notifications): the fourteen notification classes of §10.3 | §97 |
| Phase 23 (reports): every collaborator and fee report **must** call `CollaboratorStatementService` / `CollaboratorWalletService` and must never re-implement a sum (FT-42) | §99, INV-26 |
| Phase 24 (security/integrity testing): run FT-24, FT-25, FT-40, FT-43, FT-45 as part of the hardening pass | §110 |

---

## Convergence log (2026-09-12)

This file is **authoritative on money**: every edit below either adds a column another phase asked this
spine for, fixes naming / ownership drift, or records an ownership decision. **No invariant, no
duplicate-prevention layer and no release formula was changed.** INV-1 … INV-26 are intact; the only
movement in §1.4 is the INV-8 concession the resolutions allocated as **D43**, which touches a document
link and explicitly nothing financial.

| Finding | Change made |
|---|---|
| F-3.15 | §2.2: added `generation_key` string(64) **nullable** + `UNIQUE uq_sf_generation(generation_key)` (composed by the generator: `structure:{student_admission_id}:{fee_type}` / `monthly:{student_admission_id}:{YYYY-MM}`), so both Phase 18 generators are duplicate-proof **by INSERT** and no generator guards with a SELECT under a row lock. NULL for hand-entered charges, so manual charges stack freely. |
| F-4.1 | §6.2: `DocumentNumberService` marked **owned by Phase 5 — reuse, do not re-create**; signature corrected to `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` with every caller passing its own pad. §13.2: added the reuse row. |
| F-4.2 | §6.2: `refund()` replaced with the DTO form `refund(StudentFeePayment\|ProjectPayment $payment, RefundData $data): PaymentReversal` (two adjacent `string` positionals were a swappable money bug, R7). §13.2: added `App\DataObjects\Finance\RefundData` (readonly `$amount`, `$reason`, `$type`, `?$method`, `?$idempotencyKey`, `?$refundedOn`). Every INV-9 guarantee carried over verbatim. |
| F-4.3 | §6.2: `ReferralService::attach()` gained a sixth parameter `?ReferralContext $context = null` that fills the six §2.8 evidence columns (`referral_visit_id`, `landing_url`, `ip_address`, `user_agent`, `referral_date`, `notes`). §13.2: added `App\DataObjects\Collaborator\ReferralContext`. |
| F-4.4 | §6.2 `ReferralService` block: added `recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral` — `superseded`, `superseded_by_id = winner`, `commission_eligible = false`, reason mandatory, inside the winner's transaction. The losing candidate is now written **through a published spine method**, never by a phase reaching into the table. |
| F-4.8 | §6.2: added `CollaboratorWalletService::payoutsPaidTotal(Collaborator, ?DateRange): string` (derived from live allocations on `paid` payouts — INV-23, never `PayoutStatus` alone) and `CollaboratorStatementService::commissionAccruedTotal(Collaborator, ?DateRange): string` (derived from `signed_amount` net of reversals/clawbacks). Both from the §6.5.1 canonical SQL, so INV-26 holds and no phase sums money itself. **Superseded by ND-6 below:** both first parameters are now `?Collaborator`, a null meaning company-wide. |
| F-4.9 | §10.1: added **`PaymentReversalRejected`**, dispatched `afterCommit`, as the published rejection leg — the §2.18 `pending -> rejected` transition already rolls `refunded_amount` back; the event is how invoice, charge and wallet caches learn to recompute. |
| F-4.10 | §1.4 INV-8: whitelist becomes `notes` / `reference_no` / `receipt_path` / **`invoice_id`**, plus a new block stating the four guards (only `InvoiceService`; only while not `voided`; reason mandatory + audited per §107/INV-24; gated by both `project_payments.edit` and `invoices.edit`) and **zero commission effect** (no ledger read or write — commission was decided by money received on `paid_on` against the project). Recorded as **D43** in §13.3. No other money column gains an edit path. **One part deferred to the owner:** §4.1 registers `project_payments` with **no `edit` ability** (INV-8 discipline), so D43's literal `project_payments.edit` names a permission this spine does not declare; guard 4 records both readings and binds the conservative one (`invoices.edit` + an existing project-payment ability) rather than widening a money module's permission surface on its own authority. **Superseded by ND-1 below:** the deferred part is decided — guard 4 now reads `project_payments.link_invoice` + `invoices.edit`, a dedicated narrow ability declared in §4.1, and `project_payments` still has no `edit`. **ND-2** additionally made the whitelist clause a verbatim quotation shared with phase-10-12 §2.3. |
| F-4.11 | §13.2: the seven-method `Money` ask struck through and replaced with "**satisfied by phase-01 §3**", which now publishes the one canonical surface with this spine's contract stated there (bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float). |
| F-5.4 | §3: `PaymentMethod` and `LedgerEntryType` marked "**declared by Phase 7; cases defined here**", plus a new ownership table above the enum list. Cases unchanged. |
| F-5.5 | §3: `CommissionCalculationType` marked "**declared by Phase 6 with these exact cases**". Cases unchanged. |
| F-5.6 | §2.10: `global_default` deleted from the `rule_source` column note (three cases only). §3's `CommissionRuleSource` row restated the same way. [D-FS-9] "no silent fallback to the global default rate" is untouched and is now cited from both places. |
| F-6.1 | §7.2: `module:payments` -> **`module:project_payments`** on the project-payment index, with the full stack `['auth','active','module:project_payments','can:project_payments.view_any']` stated and a note that the rule covers **every** project-payment route, admin and client; `payments` stays the umbrella for Phase 13's cross-source register only. §9 module-gating row updated to match (`project_payments`, not `payments`). |
| F-8.3 | §8.12: `FeeCollectedTodayWidget`, `PendingFeesWidget` and `OverdueFeesWidget` **deleted** — Phase 18 owns those keys because it owns the fee screens. The **eight** commission/wallet widgets stay. §13.2: "eleven widgets" corrected to "eight". |
| F-9.1 | §2.1 [D-FS-3]: the local framing ("deviating from `CLAUDE.md` §3 … this needs a new decision D16") replaced with a citation of **D16** (approved 2026-09-12, these nine tables) **and D19** (the general category rule, full text in `CLAUDE.md` §3); added the one-line reason the three mutable tables keep `deleted_at`. §13.3: added the D19 citation row. Not one table's soft-delete choice changed. |
| F-11.2 | §12.2 **Q10 answered**: no re-numbering — the migration set is applied in the **same release, immediately after Phase 8's own migrations**, spine-dependent screens stay behind their module switches, and the two idempotent backfills run once afterwards (phase-08-09 §1.4 [D-P8-1]); Phase 18 creates no financial table. §1.2: added the matching "Release ordering" note, pointing every later-phase FK at the existing guarded `add_fks_to_*` follow-ups ([D-FS-1] unchanged). |
| F-12.3 | §7.6: both client payment rows gained **`client.context`** (D31 — *every* `client.*` route carries it) alongside `module:project_payments`, with a note that `client_portal.payments` is a permission namespace and is therefore never itself module-gated (D20). |
| F-13.1 | §2.19 Layer 0: the idempotency key is now stated as **generated server-side, one ULID per submission** (rendered into the modal's hidden field; a posted form without a key is rejected by the Form Request), and "the API takes it from an `Idempotency-Key` header" became "**there is no REST API in this release**; if one is ever added it supplies the key through an `Idempotency-Key` header" — no schema change either way. The four duplicate-prevention layers are untouched. |
| F-10.1 (§4.2) | Per the registry, the spine's own numbers are **unchanged**: D16 -> D16, D17 -> D17, D18 -> D18. §13.3 now also cites **D19** (F-9.1) and allocates **D43** (F-4.10), and §12.2 Q1 is marked answered because D16 is frozen. No local decision number was invented. |

---

## Drift fixes (round 2)

Closing the new contradictions the convergence pass itself created, recorded as ND-1 … ND-13 in
[`consistency-audit-round-2.md`](consistency-audit-round-2.md) §4. **This file stays authoritative on
money: no invariant was relaxed, no guarantee weakened and no money column gained a write path.** The only
permission surface that moved is one deliberately narrow, single-purpose ability that *replaces* a wider one
the convergence text had implied.

| ND | Change made |
|---|---|
| ND-1 | **`project_payments` is still never granted `edit` — the spine's rule stays literally true.** §1.4 guard 4 no longer records an unresolved choice: D43's gate is now the dedicated narrow ability **`project_payments.link_invoice`** **plus** `invoices.edit`. §4.1 declares `link_invoice` on the `project_payments` slug (in **no** Phase 1 preset, not in `MONEY`) and adds a seven-row property table stating that it authorises **exactly one mutation** — `invoice_id` moving NULL → value → NULL through `InvoiceService` — and **no other mutation of any kind**: no amount, date, method, reference, status, collaborator or commission column, no create / refund / void / delete, nothing on `student_fee_payments`. INV-8's enforcement cell, §7.2's closing note, §13.2 (a Phase 1 `Ability::LinkInvoice` case + the Accountant-only `RoleSeeder` grant) and §13.3's D43 wording all say the same thing, and new test **FT-52** asserts `project_payments.edit` exists nowhere in the registry while the pair reaches the two routes. |
| ND-2 | §1.4 gained the **canonical whitelist clause**, written once and **quoted verbatim** by phase-10-12 §2.3 `[D-IMP-2]` (which owns the model `updating` guard that enforces INV-8): `invoice_id` is whitelisted **only** for the D43 move, and only when (1) the writer is `InvoiceService`, (2) a reason is mandatory, (3) the change is audited per §107 / INV-24, (4) it has zero commission effect — plus "not `voided`" and the `link_invoice` + `invoices.edit` pair. The block is marked as quoted in both files so the two cannot be paraphrased apart again. |
| ND-6 | §6.2 now publishes the **company-wide** figures the P&L needs beside the per-collaborator ones: `payoutsPaidTotal(?Collaborator $c, ?DateRange $r = null): string` and `commissionAccruedTotal(?Collaborator $c, ?DateRange $r = null): string`, where **`$c = null` means company-wide**. §6.5.1 gained the paragraph defining the company-wide form as *the same canonical SQL with the `collaborator_id` predicate dropped* (A ranged on `transaction_date`, B on the payout's `paid_on`), **one query, never a PHP loop** — so the company-wide total is exactly equal to the sum of the per-collaborator totals and INV-26 still holds with nobody summing money outside these two services. The first parameter has no default (company-wide is always an explicit `null` at the call site) and a null collaborator requires a `DateRange`. **FT-42** gained the paisa-exact identity assertion and the query-count check. |
| ND-12 | `collaborator_referrals.superseded_by_id` is **no longer unique**: §2.8 Keys replace `UNIQUE uq_cr_superseded_by` with **`INDEX idx_cr_superseded_by`** and state why — one winner legitimately supersedes several rows (the previously active referral from `change()` **plus** every losing candidate from `recordLosingCandidate()`), so a unique index would 1062 on a legal write and destroy attribution evidence. The "**at most one active referral per subject**" guarantee is carried **solely** by the four `uq_cr_*_current` indexes over the generated `current_guard` column (INV-18) and depends on this column in no way. Both writers' §6.2 rows say so explicitly, and new test **FT-53** asserts three rows may share one winner while `uq_cr_*_current` still refuses a second active referral. |
