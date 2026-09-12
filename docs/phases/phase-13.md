# PHASE 13 CONTRACT — Software-house finance: invoices, project payments, expenses, other income

**Binding.** Column names, class names, enum cases, permission strings, route names and file paths below
are fixed — do not invent alternatives. Conventions: [`../../CLAUDE.md`](../../CLAUDE.md). Progress:
[`../../DEVELOPMENT_LOG.md`](../../DEVELOPMENT_LOG.md). Requirement: [`../requirements.md`](../requirements.md)
**§29, §30, §31, §32**, with the report and export rules of **§99** and the data-isolation rules of **§112**.

**This phase is subordinate to [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md).**
The spine owns `project_payments`, `payment_reversals`, `student_fee_payments`, the whole commission
engine and `DocumentNumberService`. Phase 13 **never** redefines one of its tables, never writes a ledger
row, and never computes a commission. Where this document and the spine disagree, **the spine wins**
(spine §1.3 assigns Phase 13 exactly: `invoices`, `expenses`, `income`, `payment_methods` "tables and
screens; the finance register that lists `project_payments`; invoice `paid_amount` **derived from**
`project_payments` (never an independent counter)"). Where this document and
[`phase-01.md`](phase-01.md) / [`phase-02.md`](phase-02.md) disagree, **those win** and the conflict is
recorded in §12.2 as an open question — never silently redesigned.

---

## Contents

| § | Contents |
|---|---|
| 1 | Goal, dependencies, what this phase does **not** own |
| 2 | Schema (7 tables + 1 guarded FK migration), **the invoice money algorithm**, status derivation, the paid-amount cache |
| 3 | Enums to add |
| 4 | PermissionRegistry additions — and the accountant / project-manager split |
| 5 | SettingsRegistry additions |
| 6 | Services — signatures and invariants, allocation rule, reversal flow, rounding, tax |
| 7 | Routes |
| 8 | UI screens |
| 9 | Data isolation |
| 10 | Events, notifications, jobs, scheduled tasks |
| 11 | Acceptance tests |
| 12 | Risks and open questions |
| 13 | Requests to other phases |

---

## 1. Goal and dependencies

### 1.1 Goal

After Phase 13 the business can bill a client and prove what it was paid: build an invoice from line items
with quantity, rate, per-line discount and tax, issue it under a gap-free settings-driven number, email it
with a PDF, let the client read it in their own panel or through a signed link, receipt client money
against the project / milestone / invoice through the spine's `project_payments` (which fires the project
commission trigger untouched), watch it move Draft → Sent → Partial → Paid → Overdue or Cancelled from one
derived status function, refund or void a receipt without ever deleting it, record and approve expenses
against dynamic categories with receipts, record other income, manage payment methods behind a
gateway-ready driver abstraction, and read four finance reports — income, expenses, profit & loss and
receivables aging — with §99's date filters and print / PDF / CSV exports, all of it visible to an
Accountant and invisible to a Project Manager unless a role is explicitly widened.

### 1.2 Dependencies

| Phase | What Phase 13 needs from it |
|---|---|
| 1 | `users`, `branches`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `activity_log` with old/new values, `App\Support\Money`, `Blameable`, `LogsActivityWithContext`, `layouts/admin`, `layouts/panel`, the `x-ui.*` set |
| 2 | `SettingsRegistry` `finance` group + `SettingsService`, `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`, `app_datetime()`) |
| 5 | `clients` (id, name, company, email, phone, address, tax details, `user_id` nullable) |
| 6 | `projects` (`client_id`, `project_manager_id` - **a `users.id`**, D32 / F-3.11 - `project_value`, `net_value`), `project_milestones` (**`name`**, `amount`) |
| 10 (spine) | `project_payments`, `payment_reversals`, `student_fee_payments`, `Finance\PaymentService`, `Finance\DocumentNumberService`, the `PaymentMethod` / `ReceivedPaymentStatus` / `ReversalType` enums, and the spine's payment / reversal routes of §7.2–7.3 |
| 11 (spine) | `PaymentService::recordProjectPayment()` and `ProcessProjectPaymentCommission` |
| 12 (spine) | `CollaboratorWalletService` / `CollaboratorStatementService` for the P&L's collaborator block (§13.1) |
| 18 (spine) | `student_fees.fee_type` for the income report's fee breakdown |

Package installed in this phase (planned in `DEVELOPMENT_LOG.md` §2): **`barryvdh/laravel-dompdf`** for
invoice and report PDFs. Excel export stays deferred to Phase 23; Phase 13 ships print, PDF and CSV (§99).

### 1.3 What this phase does **not** own

| Never created, written or changed here | Owner |
|---|---|
| `project_payments`, `payment_reversals`, `student_fee_payments`, `student_fees` and every `collaborator_*` table | spine, phases 10–12, 18 |
| Any commission calculation, ledger row, wallet delta, entitlement or payout | spine §6, phases 10–12 |
| `PaymentService::recordProjectPayment()` / `refund()` / `void()` — Phase 13 **calls** them | spine §6.2 |
| The routes `admin.project-payments.*` and `admin.payment-reversals.*` (spine §7.2, §7.3) — Phase 13 adds only the screens listed in §8 and the invoice-scoped recording route of §7.1 | spine |
| `DocumentNumberService` — Phase 13 **calls** it for three more counters | spine §5 |
| An independent `paid_amount` counter on anything | spine §1.3 |

---

## 2. Schema

All tables InnoDB, utf8mb4. `invoices`, `invoice_items`, `expenses`, `incomes`, `payment_methods` and
`finance_categories` carry `created_at`, `updated_at`, `deleted_at`, `created_by` and `updated_by` per
`CLAUDE.md` §3.
**`finance_reversals` deliberately omits `deleted_at`** and carries a `BEFORE DELETE` trigger. This is not a
local exception: it is the soft-delete **category rule D19** (append-only money, audit, log, snapshot,
revision, counter and history-pivot tables carry no `deleted_at`; the full table is in `CLAUDE.md` §3), and
as a money table it additionally cites **D16** — it is the un-doing of money and must never vanish.
**`invoice_items` also omits `deleted_at`**, as an append-only child document line under the same **D19**
category (§12.2 Q3): a line is part of its parent
document, the parent's soft delete preserves the whole document, and an issued invoice's lines are
immutable, so a nullable `deleted_at` would only accumulate orphaned draft lines that every totals query
must remember to exclude.

### 2.1 The seven tables

| # | Table | Soft deletes | Why it exists |
|---|---|---|---|
| 1 | `payment_methods` | yes | §32 + the gateway-ready abstraction; the nullable FK target the spine asked for |
| 2 | `finance_categories` | yes | §30 "category", dynamic for expenses **and** other income |
| 3 | `invoices` | yes (drafts only, by policy) | §31 the billing document |
| 4 | `invoice_items` | **no** | §31 items / quantity / rate |
| 5 | `expenses` | yes (pending only, by policy) | §30 |
| 6 | `incomes` | yes (unvoided only, by policy) | §29 "other income" |
| 7 | `finance_reversals` | **no** | the append-only un-doing of an expense or an other-income row; the expense/income counterpart of the spine's `payment_reversals`, which structurally cannot hold them (`chk_pr_one_target` allows only a student-fee or project payment) |

### 2.2 `payment_methods`

§32's four methods plus the gateway-ready architecture. The **enum value on a payment row stays the
snapshot of record** (spine §2.5/§2.6: `payment_method` string cast `PaymentMethod`); this table adds the
configurable, orderable, deactivatable presentation and gateway wiring around it.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `code` | string(32) | not null | must be a `PaymentMethod` enum value (validated in the Form Request against `PaymentMethod::values()`); UNIQUE |
| `name` | string(100) | not null | editable label ("Bank transfer — HBL") |
| `description` | string(255) | nullable | |
| `type` | string(32) | not null | cast `PaymentMethodType` |
| `is_online` | boolean | false | true only for a real gateway |
| `gateway_driver` | string(32) | nullable | resolved by `PaymentGatewayManager`; null = manual/offline |
| `is_test_mode` | boolean | true | |
| `config_encrypted` | text | nullable | Laravel `encrypted` cast: keys, secrets, merchant ids. **Never rendered, never logged** — an activity diff shows `[encrypted]` |
| `supports_refund` | boolean | false | drives the refund-method options on a reversal form |
| `requires_reference` | boolean | false | forces `reference_no` on any payment, expense or income using it |
| `instructions` | text | nullable | rendered on the client-facing invoice and the public view |
| `usable_for` | json | not null | subset of `["invoice","project_payment","student_fee","expense","income","payout"]`; filters every method dropdown |
| `is_active` | boolean | true, index | |
| `is_default` | boolean | false | |
| `default_guard` | tinyint | **generated STORED** | `CASE WHEN is_default = 1 AND deleted_at IS NULL THEN 1 ELSE NULL END` |
| `sort_order` | int | 0 | |
| `created_at` / `updated_at` / `deleted_at` | | | softDeletes |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `UNIQUE uq_pm_code(code)`; `UNIQUE uq_pm_default(default_guard)` — exactly **one** default method
system-wide, enforced by the database instead of a "clear all others" loop that can half-fail;
`INDEX (is_active, sort_order)`; `INDEX (type)`.
**Relationships.** hasMany `ProjectPayment`, `StudentFeePayment`, `Expense`, `Income` (every one
`nullOnDelete`, per spine §2.5/§2.6). belongsTo `User` (creator, editor).
**Policy.** `delete` is refused while any payment, expense or income references the row — the correct act
is `is_active = false`, which hides it from every dropdown and changes no history.

### 2.3 `finance_categories`

One table for both sides (§30 expense category; the counterpart an "other income" row needs), separated by
`type`, because two near-identical tables would double the CRUD, the policy and the seeder for no gain.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `type` | string(16) | not null | cast `FinanceCategoryType` (`expense` / `income`) |
| `code` | string(32) | not null | slug, stable for reports |
| `name` | string(100) | not null | |
| `description` | string(255) | nullable | |
| `context` | string(24) | nullable | cast `FinanceContext`; null = usable in any context |
| `is_active` | boolean | true | |
| `sort_order` | int | 0 | |
| timestamps / `deleted_at` / blameable | | | |

**Keys.** `UNIQUE uq_fc_code(type, code)`; `INDEX (type, is_active, sort_order)`.
**Relationships.** hasMany `Expense`, `Income` — both `restrictOnDelete`, so a category that carries money
history can never be hard-deleted. **Policy** refuses `delete` while the count is non-zero and offers
deactivation instead.
**Seeder** (`FinanceCategorySeeder`, idempotent `firstOrCreate`): expense — Salaries, Rent, Utilities,
Internet & Telephone, Marketing & Advertising, Software & Subscriptions, Hardware & Equipment, Office
Supplies, Travel & Conveyance, Professional Fees, Taxes & Government Fees, Bank Charges, Repairs &
Maintenance, Training, Miscellaneous; income — Consulting, Training Services, Maintenance & Support, Asset
Sale, Interest, Miscellaneous.
**Reserved category (D44).** The expense category `code = salaries` is **reserved**: the seeder always
writes it and the policy refuses both `delete` and deactivation, because `RecordPayrollExpense` (§6.4.2)
posts every paid payroll run into it and a missing category would silently drop payroll out of §99's P&L.

### 2.4 `invoices`

§31's billing document. It holds **no cash**: every rupee against it is a `project_payments` row owned by
the spine.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `invoice_number` | string(32) | **nullable** | `finance.invoice_prefix` + `finance.invoice_next_number` via `DocumentNumberService`, assigned **once, inside the issue transaction** — NULL while `draft` (§2.9 gap-free rule). UNIQUE |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`, D11 |
| `client_id` | FK `clients.id` | not null | `restrictOnDelete` — a client with billing history is never hard-deletable |
| `project_id` | FK `projects.id` | nullable | `restrictOnDelete`; null = a non-project invoice (retainer, ad-hoc service) |
| `replaces_invoice_id` | FK self | nullable | `nullOnDelete`; set when this invoice replaces a cancelled one, so a correction is traceable |
| `title` | string(150) | nullable | "Phase 2 development — April 2026" |
| `reference` | string(64) | nullable | the client's PO / reference |
| `currency` | char(3) | `PKR` | constant column; single currency today (spine R-12) |
| `issue_date` | date | not null | the document's value date |
| `due_date` | date | not null | defaults `issue_date + payment_terms_days` |
| `payment_terms_days` | smallint unsigned | 0 | SNAPSHOT of `finance.payment_terms_days` |
| `status` | string(32) | `draft`, index | cast `InvoiceStatus`. **Derived** — written only by `InvoiceService::recomputeStatus()` (§2.8) |
| `tax_label` | string(32) | nullable | SNAPSHOT of `finance.tax_label` at creation |
| `tax_rate` | decimal(8,4) | 0.0000 | SNAPSHOT of `finance.default_tax_rate`; the default each new line inherits |
| `discount_mode` | string(16) | `none` | cast `DiscountMode` — the **invoice-level** discount |
| `discount_rate` | decimal(8,4) | nullable | required when mode is `percentage` |
| `discount_fixed` | decimal(15,2) | nullable | required when mode is `fixed` |
| `subtotal_amount` | decimal(15,2) | 0.00 | Σ `invoice_items.gross_amount` |
| `item_discount_amount` | decimal(15,2) | 0.00 | Σ `invoice_items.discount_amount` |
| `discount_amount` | decimal(15,2) | 0.00 | the invoice-level discount actually applied |
| `total_discount_amount` | decimal(15,2) | **generated STORED** | `item_discount_amount + discount_amount` — the single column §31's "discount" prints and every report sums |
| `taxable_amount` | decimal(15,2) | 0.00 | Σ `invoice_items.taxable_amount` |
| `tax_amount` | decimal(15,2) | 0.00 | Σ `invoice_items.tax_amount` |
| `round_off_amount` | decimal(15,2) | 0.00 | **signed**, may be negative; 0.00 unless `finance.invoice_round_off_enabled` |
| `total_amount` | decimal(15,2) | 0.00 | the payable grand total (§2.7 step 11) |
| `paid_amount` | decimal(15,2) | 0.00 | **CACHE of the canonical SQL in §2.8**, already net of refunds. Nothing may `increment()` it |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE, memo only |
| `balance_amount` | decimal(15,2) | 0.00 | CACHE `total_amount - paid_amount`; **may be negative** = client overpaid |
| `payment_method_id` | FK `payment_methods.id` | nullable | `nullOnDelete`; the method offered on the invoice |
| `notes` | text | nullable | §31 notes — **client-visible** |
| `internal_notes` | text | nullable | never rendered to a client, never in the PDF |
| `footer_note` | text | nullable | SNAPSHOT of `finance.invoice_footer_note` at issue |
| `bank_details` | text | nullable | SNAPSHOT of `finance.bank_details` at issue |
| `issued_at` | timestamp | nullable | when the number was assigned |
| `issued_by` | FK `users.id` | nullable | `nullOnDelete` |
| `sent_at` | timestamp | nullable | first successful delivery or manual "mark as sent"; **NULL ⇒ draft** |
| `last_sent_at` | timestamp | nullable | |
| `sent_count` | smallint unsigned | 0 | |
| `last_sent_to` | string(255) | nullable | the recipient list of the last send, for the audit trail |
| `last_reminder_at` | timestamp | nullable | |
| `reminder_count` | smallint unsigned | 0 | |
| `viewed_at` | timestamp | nullable | first time the client opened the panel or public view |
| `public_token` | char(40) | nullable | random; UNIQUE. Regenerating it **revokes** every link already emailed |
| `pdf_path` | string(255) | nullable | cached render of the issued PDF, cleared on any edit. **Private `local` disk under `invoices/`** — never the `public` disk — and streamed only by `admin.invoices.pdf` / `client.invoices.pdf`, which re-run the full permission chain (**D21**) |
| `cancelled_at` | timestamp | nullable | |
| `cancelled_by` | FK `users.id` | nullable | `nullOnDelete` |
| `cancellation_reason` | string(255) | nullable | **mandatory** when cancelling |
| timestamps / `deleted_at` / blameable | | | softDeletes; the policy allows `delete` **only** while `invoice_number IS NULL` and no payment is linked |

**Keys.** `UNIQUE uq_inv_number(invoice_number)` — MariaDB ignores NULLs, so unlimited drafts coexist while
two cashiers can never share an issued number (the loser of the counter race retries);
`UNIQUE uq_inv_public_token(public_token)`; `INDEX (client_id, status)` client panel + aging;
`INDEX (project_id, status)` the project finance tab; `INDEX (status, due_date)` the overdue sweeper and
the aging report; `INDEX (issue_date)`, `INDEX (due_date)`, `INDEX (branch_id)`;
**every remaining FK column is indexed** (F-9.2): `INDEX (replaces_invoice_id)`,
`INDEX (payment_method_id)`, `INDEX (issued_by)`, `INDEX (cancelled_by)` — each with a row in
`tests/Support/index-manifest.php`.
**CHECK** `chk_inv_nonneg`: `subtotal_amount >= 0 AND item_discount_amount >= 0 AND discount_amount >= 0 AND taxable_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0 AND refunded_amount >= 0`.
**CHECK** `chk_inv_discount_payload`: `(discount_mode = 'none' AND discount_rate IS NULL AND discount_fixed IS NULL) OR (discount_mode = 'percentage' AND discount_rate IS NOT NULL AND discount_fixed IS NULL) OR (discount_mode = 'fixed' AND discount_fixed IS NOT NULL AND discount_rate IS NULL)` — an invoice can never be saved without the number its discount mode needs.
**CHECK** `chk_inv_discount_ceiling`: `item_discount_amount + discount_amount <= subtotal_amount` — a discount can never exceed what was billed.
**CHECK** `chk_inv_rates`: `(discount_rate IS NULL OR (discount_rate >= 0 AND discount_rate <= 100)) AND tax_rate >= 0 AND tax_rate <= 100`.
**CHECK** `chk_inv_dates`: `due_date >= issue_date`.
**CHECK** `chk_inv_issued_number`: `status = 'draft' OR invoice_number IS NOT NULL` — a non-draft invoice always carries its number.
**CHECK** `chk_inv_cancel_reason`: `cancelled_at IS NULL OR cancellation_reason IS NOT NULL`.
**Relationships.** belongsTo `Client`, `Project`, `Branch`, `PaymentMethod`, `User` (`issuedBy`,
`canceller`, `creator`, `editor`); belongsTo self (`replaces`) / hasOne self (`replacedBy`);
**hasMany `InvoiceItem`** (ordered by `sort_order`); **hasMany `ProjectPayment`** through
`project_payments.invoice_id` (the spine's table — read-only here except for the one-way link of §6.3);
hasManyThrough `PaymentReversal` via `ProjectPayment`.

### 2.5 `invoice_items`

§31's "items, quantity, rate". One row per billed line; immutable once the invoice is issued **and** has
received money.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `invoice_id` | FK `invoices.id` | not null | `cascadeOnDelete` — lines belong to the document and only a never-issued draft can be deleted |
| `sort_order` | smallint unsigned | 0 | the printed order; also the apportionment order of §2.7 step 6 |
| `project_milestone_id` | FK `project_milestones.id` | nullable | `nullOnDelete`; lets a milestone be billed and pre-fills the milestone on the payment modal |
| `description` | string(255) | not null | |
| `details` | text | nullable | printed under the description |
| `unit` | string(24) | nullable | "hour", "page", "month" — label only, never arithmetic |
| `quantity` | decimal(12,4) | 1.0000 | §31 quantity; 4 dp supports 1.5 h / 0.25 day |
| `unit_price` | decimal(15,2) | not null | §31 rate |
| `gross_amount` | decimal(15,2) | 0.00 | `Money::round(Money::mul(quantity, unit_price), 2)` |
| `discount_mode` | string(16) | `none` | cast `DiscountMode` |
| `discount_rate` | decimal(8,4) | nullable | |
| `discount_fixed` | decimal(15,2) | nullable | |
| `discount_amount` | decimal(15,2) | 0.00 | the line discount actually applied |
| `net_amount` | decimal(15,2) | 0.00 | `gross_amount - discount_amount` |
| `allocated_discount_amount` | decimal(15,2) | 0.00 | this line's share of the **invoice-level** discount (§2.7 step 6) |
| `is_taxable` | boolean | true | false = exempt line |
| `tax_rate` | decimal(8,4) | 0.0000 | inherits `invoices.tax_rate`, overridable per line |
| `taxable_amount` | decimal(15,2) | 0.00 | `net_amount - allocated_discount_amount` when taxable, else `0.00` |
| `tax_amount` | decimal(15,2) | 0.00 | `Money::percentage(taxable_amount, tax_rate)` |
| `line_total` | decimal(15,2) | 0.00 | `net_amount - allocated_discount_amount + tax_amount` |
| `created_at` / `updated_at` | | | **no `deleted_at`** (§2 preamble) |
| `created_by` / `updated_by` | FK `users.id` | nullable | `nullOnDelete`, Blameable |

**Keys.** `INDEX (invoice_id, sort_order)`; `INDEX (project_milestone_id)`.
**CHECK** `chk_ii_quantity`: `quantity > 0`.
**CHECK** `chk_ii_nonneg`: `unit_price >= 0 AND gross_amount >= 0 AND discount_amount >= 0 AND net_amount >= 0 AND allocated_discount_amount >= 0 AND taxable_amount >= 0 AND tax_amount >= 0`.
**CHECK** `chk_ii_discount_payload`: same shape as `chk_inv_discount_payload`.
**CHECK** `chk_ii_discount_ceiling`: `discount_amount + allocated_discount_amount <= gross_amount`.
**CHECK** `chk_ii_tax_rate`: `tax_rate >= 0 AND tax_rate <= 100`.
**CHECK** `chk_ii_exempt`: `is_taxable = 1 OR (tax_amount = 0 AND taxable_amount = 0)` — an exempt line can never carry tax.
**Relationships.** belongsTo `Invoice`, `ProjectMilestone`, `User` (creator, editor).

### 2.6 `expenses`, `incomes`, `finance_reversals`

**`expenses`** — §30 verbatim (category, amount, date, description, payment method, receipt,
project/institute, added by, approval status).

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `expense_no` | string(32) | not null | `finance.expense_prefix` + counter, assigned in-transaction. UNIQUE. An expense is never a draft, so the series has no gaps |
| `idempotency_key` | string(64) | not null | UNIQUE — a double-submitted form cannot record the same expense twice (the spine's layer-0 pattern, §2.19) |
| `finance_category_id` | FK `finance_categories.id` | not null | `restrictOnDelete`; the Form Request requires `type = expense` |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete`, D11 |
| `context` | string(24) | not null | cast `FinanceContext` — §30's "project/institute" made explicit |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete`; required when `context = software_house` and the expense is project-billable |
| `title` | string(150) | not null | |
| `description` | text | nullable | §30 description |
| `paid_to` | string(150) | nullable | the payee, so an expense is identifiable without opening the receipt |
| `amount` | decimal(15,2) | not null | CHECK `> 0` |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(finance_reversals.amount)` |
| `net_amount` | decimal(15,2) | **generated STORED** | `amount - refunded_amount` — **every expense report and the P&L sum this column**, so none of them can forget a refund |
| `expense_date` | date | not null | **VALUE DATE** (§30 date) |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | **SYSTEM DATE**; both are always kept |
| `payment_method` | string(32) | not null | cast `PaymentMethod` — the snapshot of record (§32) |
| `payment_method_id` | FK `payment_methods.id` | nullable | `nullOnDelete` |
| `reference_no` | string(64) | nullable | cheque / transfer reference; mandatory when the method has `requires_reference` |
| `receipt_path` | string(255) | nullable | §30 receipt; MIME-validated upload on the **private `local` disk** under `expenses/` — never the `public` disk — streamed only by `admin.expenses.receipt`, which re-runs the policy check (**D21**) |
| `source_type` | string(32) | nullable | the system object this expense was **derived** from, when it was not typed by a human: today the only value is `payroll_run` (**D44**). NULL for a hand-entered expense |
| `source_id` | unsignedBigInteger | nullable | the derived source's primary key (`payroll_runs.id` for `payroll_run`). No FK: the source table may belong to another phase and the pair is the identity |
| `status` | string(32) | `pending`, index | cast `ExpenseStatus` — §30's "approval status", extended with `voided` |
| `approval_required` | boolean | true | SNAPSHOT of the `finance.expense_approval_required` + `finance.expense_approval_threshold` decision at creation, so a later settings change never rewrites why a row was auto-approved |
| `approved_by` / `approved_at` | FK `users.id` / timestamp | nullable | `nullOnDelete` |
| `rejected_by` / `rejected_at` / `rejection_reason` | FK / timestamp / string(255) | nullable | reason **mandatory** |
| `voided_by` / `voided_at` / `void_reason` | FK / timestamp / string(255) | nullable | reason **mandatory** |
| `corrects_expense_id` | FK self | nullable | `nullOnDelete` — the re-entry that replaces a voided row |
| `notes` | string(255) | nullable | |
| timestamps / `deleted_at` / blameable | | | softDeletes; the policy allows `delete` **only** while `status = pending` (§6.4) |

**Keys.** `UNIQUE uq_exp_no(expense_no)`, `UNIQUE uq_exp_idem(idempotency_key)`;
**`UNIQUE uq_exp_source(source_type, source_id)`** — the INSERT is the guard, so a replayed
`PayrollRunPaid` can never post a second salary expense for the same run (**D44**; MariaDB ignores NULLs,
so hand-entered expenses stack freely); `INDEX (source_type, source_id)`;
`INDEX (status, expense_date)` the approval queue and the expense report;
`INDEX (finance_category_id, expense_date)` the by-category report; `INDEX (project_id)`,
`INDEX (branch_id)`, `INDEX (expense_date)`, `INDEX (payment_method)`,
`INDEX (created_by, status)` ("my expenses"); **every remaining FK column is indexed** (F-9.2):
`INDEX (approved_by)`, `INDEX (rejected_by)`, `INDEX (voided_by)`, `INDEX (corrects_expense_id)`,
`INDEX (payment_method_id)` — each with a row in `tests/Support/index-manifest.php`.
**CHECK** `chk_exp_amount`: `amount > 0`. **CHECK** `chk_exp_refund_ceiling`:
`refunded_amount >= 0 AND refunded_amount <= amount` — cumulative refunds can never exceed what was spent.
**CHECK** `chk_exp_reject_reason`: `rejected_at IS NULL OR rejection_reason IS NOT NULL`.
**CHECK** `chk_exp_void_reason`: `voided_at IS NULL OR void_reason IS NOT NULL`.
**Relationships.** belongsTo `FinanceCategory`, `Branch`, `Project`, `PaymentMethod`, `User` (`approver`,
`rejecter`, `voider`, `creator`, `editor`); belongsTo self (`corrects`) / hasOne self (`correctedBy`);
hasMany `FinanceReversal`.

**`incomes`** — §29 "other income": money received that is **not** a project payment and **not** a student
fee. Identical discipline, so one mental model covers both sides.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `income_no` | string(32) | not null | `finance.income_prefix` + counter. UNIQUE |
| `idempotency_key` | string(64) | not null | UNIQUE |
| `finance_category_id` | FK `finance_categories.id` | not null | `restrictOnDelete`; Form Request requires `type = income` |
| `branch_id` | FK `branches.id` | nullable | `nullOnDelete` |
| `context` | string(24) | not null | cast `FinanceContext` |
| `project_id` | FK `projects.id` | nullable | `nullOnDelete` |
| `client_id` | FK `clients.id` | nullable | `nullOnDelete` |
| `title` | string(150) | not null | |
| `description` | text | nullable | |
| `received_from` | string(150) | nullable | the payer |
| `amount` | decimal(15,2) | not null | CHECK `> 0` |
| `refunded_amount` | decimal(15,2) | 0.00 | CACHE of `SUM(finance_reversals.amount)` |
| `net_amount` | decimal(15,2) | **generated STORED** | `amount - refunded_amount`; the income report sums this |
| `received_on` | date | not null | VALUE DATE |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | SYSTEM DATE |
| `payment_method` | string(32) | not null | cast `PaymentMethod` |
| `payment_method_id` | FK `payment_methods.id` | nullable | `nullOnDelete` |
| `reference_no` | string(64) | nullable | |
| `receipt_path` | string(255) | nullable | |
| `status` | string(32) | `recorded`, index | cast `IncomeStatus` (`recorded` / `voided`) |
| `voided_by` / `voided_at` / `void_reason` | FK / timestamp / string(255) | nullable | reason **mandatory** |
| `corrects_income_id` | FK self | nullable | `nullOnDelete` |
| `notes` | string(255) | nullable | |
| timestamps / `deleted_at` / blameable | | | softDeletes; policy allows `delete` only while nothing references it and it is not `voided` |

**Keys.** `UNIQUE uq_inc_no(income_no)`, `UNIQUE uq_inc_idem(idempotency_key)`;
`INDEX (status, received_on)`, `INDEX (finance_category_id, received_on)`, `INDEX (client_id)`,
`INDEX (project_id)`, `INDEX (branch_id)`, `INDEX (received_on)`.
**CHECK** `chk_inc_amount`: `amount > 0`. **CHECK** `chk_inc_refund_ceiling`:
`refunded_amount >= 0 AND refunded_amount <= amount`. **CHECK** `chk_inc_void_reason`:
`voided_at IS NULL OR void_reason IS NOT NULL`.
**Relationships.** belongsTo `FinanceCategory`, `Branch`, `Project`, `Client`, `PaymentMethod`, `User`
(`voider`, creator, editor); belongsTo/hasOne self; hasMany `FinanceReversal`.

**`finance_reversals`** — append-only, one row per un-doing of an expense or an other-income row. **It is
not, and must never be confused with, the spine's `payment_reversals`**, whose `chk_pr_one_target` allows
only a `student_fee_payment_id` or a `project_payment_id`. No commission engine is involved: an expense or
an other-income row has never produced a ledger entry, so a `finance_reversals` row dispatches **no**
commission job.

| Column | Type | Null/Default | Notes |
|---|---|---|---|
| `id` | bigIncrements | — | PK |
| `reversal_no` | string(32) | not null | `finance.payment_reversal_prefix` + `finance.payment_reversal_next_number` — deliberately the **same series** as the spine's `payment_reversals`, so the business has one reversal-voucher sequence. UNIQUE |
| `idempotency_key` | string(64) | not null | UNIQUE — a double-clicked Refund cannot refund twice |
| `expense_id` | FK `expenses.id` | nullable | `restrictOnDelete` |
| `income_id` | FK `incomes.id` | nullable | `restrictOnDelete` |
| `type` | string(32) | not null | cast `ReversalType` (the spine's enum, reused); only `full_refund`, `partial_refund`, `void`, `correction` are accepted here — the Form Request enforces it |
| `amount` | decimal(15,2) | not null | positive magnitude; direction is implied by the table |
| `reason` | string(255) | not null | mandatory |
| `refund_method` | string(32) | nullable | cast `PaymentMethod` — how the money moved back |
| `reference_no` | string(64) | nullable | |
| `occurred_on` | date | not null | business date |
| `recorded_at` | timestamp | CURRENT_TIMESTAMP | system date |
| `performed_by` | FK `users.id` | nullable | `nullOnDelete` |
| `performed_by_name` | string(150) | not null | snapshot, immune to user deletion |
| `attachment_path` | string(255) | nullable | the credit note / refund voucher scan |
| `notes` | string(255) | nullable | |
| `created_at` / `updated_at` / `created_by` / `updated_by` | | | **no `deleted_at`** (D16) |

**Keys.** `UNIQUE uq_fr_no(reversal_no)`, `UNIQUE uq_fr_idem(idempotency_key)`; `INDEX (expense_id)`,
`INDEX (income_id)`, `INDEX (occurred_on, type)`.
**CHECK** `chk_fr_one_target`: `(expense_id IS NOT NULL) + (income_id IS NOT NULL) = 1` — a reversal can
never dangle or double-target. **CHECK** `chk_fr_amount`: `amount > 0`.
**Trigger** `trg_fr_no_delete` `BEFORE DELETE` → `SIGNAL SQLSTATE '45000'`, plus a model `deleting` hook
that throws first with a readable message (spine R-5).
The ceiling is enforced by the conditional `UPDATE ... WHERE refunded_amount + :x <= amount` of §6.4,
with `chk_exp_refund_ceiling` / `chk_inc_refund_ceiling` as the database backstop.
**Relationships.** belongsTo `Expense`, `Income`, `User` (`performer`, creator).

**No money artefact on the public disk (D21, F-12.5).** Every upload in this section —
`expenses.receipt_path`, `incomes.receipt_path` and `finance_reversals.attachment_path` — lands on the
private **`local`** disk (under `expenses/`, `incomes/` and `finance-reversals/`) and is reachable only
through the controller route that re-runs the full permission chain (`admin.expenses.receipt`,
`admin.income.receipt`, and the parent's detail screen for a reversal attachment). No public path, no
signed URL, and a row in `upload-manifest.php` for each (phase-24-25).

### 2.7 The invoice money algorithm (§31 totals, tax and discount) — binding

`InvoiceService::recalculate(Invoice $invoice): Invoice` runs **exactly** these steps inside the caller's
transaction while holding the invoice row `lockForUpdate`. Every operation is `App\Support\Money` (bcmath,
intermediate scale 6, final quantisation **half-up at 2**). No PHP `+ - * /` ever touches a money value
(`CLAUDE.md` §1.4, spine INV-7).

| Step | Operation |
|---|---|
| 1 | For each line *i* in `sort_order`: `gross_i = Money::round(Money::mul(quantity_i, unit_price_i), 2)` — **quantised per line**, so the printed total is always the sum of the printed lines |
| 2 | Line discount: `percentage` → `d_i = Money::percentage(gross_i, discount_rate_i)`; `fixed` → `d_i = Money::min(discount_fixed_i, gross_i)`; `none` → `0.00` |
| 3 | `net_i = Money::sub(gross_i, d_i)` |
| 4 | `subtotal = Money::sum(gross_i)`; `item_discount_amount = Money::sum(d_i)`; `net_total = Money::sum(net_i)` |
| 5 | Invoice-level discount **D**: `percentage` → `Money::percentage(net_total, discount_rate)`; `fixed` → `Money::min(discount_fixed, net_total)`; `none` → `0.00`. When `net_total` is `0.00`, **D** must be `0.00` (the Form Request refuses a discount on a zero invoice) |
| 6 | Apportion **D** across the lines by **cumulative target**, in `sort_order`: `cum_i = Money::sum(net_1..net_i)`; `target_i = Money::round(Money::div(Money::mul(D, cum_i), net_total), 2)`; `allocated_discount_amount_i = Money::sub(target_i, target_{i-1})`. The last line absorbs the whole rounding residual, so `Σ allocated_i = D` **exactly** — the same technique the spine uses for proportional release ([D-FS-10]) |
| 7 | `taxable_amount_i = is_taxable_i ? Money::sub(net_i, allocated_i) : '0.00'` |
| 8 | `tax_i = Money::percentage(taxable_amount_i, tax_rate_i)` (`0.00` for an exempt line) |
| 9 | `line_total_i = Money::add(taxable_amount_i, tax_i)` when taxable, else `Money::sub(net_i, allocated_i)` |
| 10 | `discount_amount = D`; `taxable_amount = Money::sum(taxable_amount_i)`; `tax_amount = Money::sum(tax_i)` |
| 11 | `pre_round = Money::add(Money::sub(subtotal, Money::add(item_discount_amount, D)), tax_amount)`. When `finance.invoice_round_off_enabled`: `total_amount = Money::roundTo(pre_round, setting('finance.invoice_rounding_precision'))` and `round_off_amount = Money::sub(total_amount, pre_round)` (**signed**); otherwise `total_amount = pre_round` and `round_off_amount = '0.00'` |
| 12 | Persist every line and every header column in one statement batch; then `recomputeStatus()` (§2.8). `pdf_path` is cleared so the next download re-renders |

**Identities a test asserts after every recalculation (F13-06):**

```
total_amount == subtotal_amount - total_discount_amount + tax_amount + round_off_amount
total_amount == SUM(invoice_items.line_total) + round_off_amount
taxable_amount == SUM(invoice_items.taxable_amount)
SUM(invoice_items.allocated_discount_amount) == discount_amount
balance_amount == total_amount - paid_amount
```

**How tax is applied — the complete rule.**

1. Tax is **exclusive** (added on top of the net line). There is no inclusive mode; §12.2 Q2 raises it.
2. `finance.tax_enabled = false` ⇒ every new line is created `is_taxable = false`, `tax_rate = 0.0000`;
   `tax_amount` is `0.00` and the tax row is omitted from the PDF, the print view and the client view.
3. `invoices.tax_label` and `invoices.tax_rate` are **snapshots** taken when the invoice is created.
   Changing `finance.tax_label` or `finance.default_tax_rate` afterwards never alters an existing invoice —
   the same discipline as the spine's `rule_snapshot` (FT-38 / F13-07).
4. The rate lives **per line** (`invoice_items.tax_rate`, defaulted from the invoice snapshot) so a mixed
   taxable / exempt invoice is representable without a second tax table.
5. Tax is computed on the line net **after** the line discount **and after** the apportioned invoice-level
   discount (step 7), which is the only order in which a discount reduces tax correctly and in which an
   exempt line is unaffected by a discount given on a taxable one.
6. There is no withholding tax, no compound tax and no second tax column — §31 names one tax.

**How rounding works — the complete rule.**

| Where | Rule |
|---|---|
| Storage | money `decimal(15,2)`, percentages `decimal(8,4)`, quantity `decimal(12,4)` |
| Arithmetic | `App\Support\Money` only; bcmath, intermediate scale 6, final **half-up at 2** |
| Per line | `gross_i` is quantised at step 1, so `1.5 × 3,333.33 = 5,000.00` (not `4,999.995`) and the invoice total always equals the sum of the displayed lines |
| Percentages | `Money::percentage()` — `10.0000%` of `3,333.33` is `333.33`, of `3,333.00` is `333.30` |
| Apportionment | cumulative target (step 6), residual on the last line, so `Σ allocations = D` to the paisa |
| Grand total | optional whole-unit round-off, stored **signed** in `round_off_amount`, default **off** (`finance.invoice_round_off_enabled = false`) so arithmetic never changes unless the client asks |
| Reports | every aggregate is a SQL `SUM()` over a stored `decimal` column; cross-source totals are combined in PHP with `Money::sum()`. A report never recomputes a percentage |

### 2.8 `status` and `paid_amount` — both derived, never typed

**The canonical paid-amount SQL.** This is the **only** definition in the system
(`InvoiceService::recomputePaid()`); nothing anywhere may `increment()`, `decrement()` or hand-write these
three columns (spine §1.3: "derived from `project_payments`, never an independent counter").

```sql
SELECT
  COALESCE(SUM(pp.net_received_amount), 0.00) AS paid_amount,      -- generated: amount - refunded_amount
  COALESCE(SUM(pp.refunded_amount),     0.00) AS refunded_amount   -- memo only
FROM project_payments pp
WHERE pp.invoice_id = :invoice_id
  AND pp.status <> 'voided';
-- balance_amount = Money::sub(total_amount, paid_amount)
```

`net_received_amount` is already net of every `payment_reversals` row, so a partial refund, a full refund
and a bounced instrument all reduce `paid_amount` without a second code path; a `voided` receipt is
excluded explicitly **and** nets to zero through its own full-amount reversal, so the two rules agree.

**The status function.** `InvoiceService::recomputeStatus()` is a pure function of five stored facts, so it
can be re-run at any time and by any job without changing an answer:

```
cancelled_at IS NOT NULL                     -> cancelled
sent_at IS NULL                              -> draft
Money::compare(balance_amount, '0.00') <= 0  -> paid        // includes an overpaid invoice
due_date < today                             -> overdue     // beats partial and sent
Money::compare(paid_amount,   '0.00') >  0   -> partial
otherwise                                    -> sent
```

Exactly §31's six statuses, no seventh. Consequences, each asserted by a test:

- **Partial payment handling** is a status plus two numbers: `paid_amount > 0` with `balance_amount > 0`
  gives `partial` (or `overdue` when also past due — the register's "partially paid" filter is
  `paid_amount > 0 AND status IN (partial, overdue)`). There is no allocation table and no
  part-payment document.
- An invoice whose `total_amount` is `0.00` **cannot be issued** (`InvoiceService::issue()` requires at
  least one line and `total_amount > 0`), so `paid` can never be reached by arithmetic accident.
- A refund that takes `paid_amount` back to `0.00` on a past-due invoice returns it to `overdue`, not to
  `sent` — the claim is live again.
- An overpaid invoice is `paid` with a **negative** `balance_amount`; the register and the aging report
  show the credit rather than hiding it.
- `cancel()` is refused while `paid_amount > 0`: the correct sequence is refund the receipts through the
  spine first (§6.5), then cancel.
- The scheduled `invoices:mark-overdue` job (§10.4) exists only to let the clock move a status; it calls
  the same function for `status IN (sent, partial, overdue)` so an extended `due_date` moves a row **back**
  out of `overdue` too.

### 2.9 Gap-free invoice numbering under concurrency

| Rule | Why |
|---|---|
| `invoice_number` is **NULL while `draft`**; the screens show the accessor `draft_reference` = `'DRAFT-' . id` | a deleted or abandoned draft must not consume a number, which is the only way a sequence stays gap-free |
| The number is assigned inside the **same transaction** as the first issue (`draft → sent`), or at creation when the invoice is created already issued, by `DocumentNumberService::next('finance.invoice_prefix', 'finance.invoice_next_number')` — which takes `SELECT ... FOR UPDATE` on the settings row inside the caller's transaction (spine §5) | two users issuing simultaneously serialise on one row lock; the increment and the insert commit together, so a crash cannot burn a number |
| `UNIQUE uq_inv_number` is the backstop: a `1062` triggers exactly **one** retry with the next counter value | a hand-edited counter, a restored database or a racing console command cannot create two `INV-000042` |
| Assignment is **once, for ever**. A cancelled issued invoice **keeps** its number; the number is never reused | a reused number makes two different documents answer to one reference in a dispute. The cancelled number is a legitimate, explainable entry in the series, not a gap |
| The series never resets; there is no per-year reset in this phase (§12.2 Q4) | `finance.invoice_prefix` is the only place a year can be encoded, by the admin, with no code change |
| `expenses.expense_no` and `incomes.income_no` use the same service with `finance.expense_*` / `finance.income_*`; neither has a draft state, so both series are gap-free by construction | §30, §29 |
| `finance_reversals.reversal_no` draws from the spine's `finance.payment_reversal_*` counter, so the business has **one** reversal-voucher series across received money and spent money | an auditor follows one sequence, not two that look alike |

### 2.10 The guarded FK migration Phase 13 must ship

The spine declares `project_payments.invoice_id`, `project_payments.payment_method_id` and
`student_fee_payments.payment_method_id` as nullable columns whose FK constraints are added by guarded
follow-up migrations ([D-FS-1], spine §1.2). Because Phase 10 migrates **before** `invoices` and
`payment_methods` exist, those guards are skipped at that point and the constraints would never be
created. Phase 13 therefore ships
`database/migrations/*_add_finance_foreign_keys_to_payment_tables.php`:

| Constraint added | Guard | On delete |
|---|---|---|
| `project_payments.invoice_id` → `invoices.id` | `Schema::hasTable('project_payments') && Schema::hasTable('invoices')` and the constraint is absent from `information_schema` | `nullOnDelete` |
| `project_payments.payment_method_id` → `payment_methods.id` | as above | `nullOnDelete` |
| `student_fee_payments.payment_method_id` → `payment_methods.id` | as above | `nullOnDelete` |
| `expenses.payment_method_id`, `incomes.payment_method_id` | created inline — both tables are born in this phase | `nullOnDelete` |

The migration is idempotent (absent-constraint check before each add) and its `down()` drops only what it
added. It creates **no column** and changes no spine column definition.

---

## 3. Enums to add

All in `app/Enums/`, string-backed, implementing `label(): string` and `color(): string` (a Tailwind token)
and exposing `static options(): array`, exactly as Phase 1 §2 requires. **Reused unchanged from the spine
§3, never redeclared:** `PaymentMethod`, `ReceivedPaymentStatus`, `ReversalType`.

| Enum | Cases (values) | Extra members |
|---|---|---|
| `InvoiceStatus` | `draft`, `sent`, `partial`, `paid`, `overdue`, `cancelled` | §31 verbatim — six cases, no seventh. `isEditable(): bool` (draft, sent, overdue), `isOutstanding(): bool` (sent, partial, overdue), `countsInReceivables(): bool` (sent, partial, overdue), `isTerminal(): bool` (paid, cancelled), `isVisibleToClient(): bool` (false only for `draft`) |
| `DiscountMode` | `none`, `percentage`, `fixed` | `requiresRate(): bool`, `requiresAmount(): bool` — drives `chk_*_discount_payload` and the Form Request |
| `ExpenseStatus` | `pending`, `approved`, `rejected`, `voided` | §30 "approval status" plus the void path. `countsInReports(): bool` (**true only for `approved`**), `isTerminal(): bool` (rejected, voided), `isDeletable(): bool` (true only for `pending`) |
| `IncomeStatus` | `recorded`, `voided` | `countsInReports(): bool` (true only for `recorded`) |
| `FinanceCategoryType` | `expense`, `income` | |
| `FinanceContext` | `software_house`, `institute`, `general` | §30 "project/institute" made a first-class, groupable column. `label()` reads the company / institute name from settings so no brand name is hardcoded |
| `PaymentMethodType` | `cash`, `bank`, `card`, `mobile_wallet`, `cheque`, `gateway`, `manual`, `other` | `isOnlineCapable(): bool` (true only for `gateway`), `defaultCode(): PaymentMethod` |
| `FinanceReportType` | `income`, `expenses`, `profit_loss`, `receivables_aging` | §99 four software-house finance reports. `permissions(): array` returns the exact `can:` pair each report needs (§7.6), so the route list and the UI read one definition |
| `ExportFormat` | `print`, `pdf`, `csv` | §99 export set for this phase; `excel` is deliberately absent until Phase 23 installs the package. `mime(): string`, `extension(): string` |
| `AgingBucket` | `current`, `d1_30`, `d31_60`, `d61_90`, `d90_plus` | the receivables report five columns (§6.7); `range(): array`, `matches(int $daysOverdue): bool` — defined once so the SQL, the CSV and the screen cannot disagree |

---

## 4. PermissionRegistry additions

Ability presets are Phase 1 §4: `READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`,
`MONEY`, `REPORTS`, `LOGS`. The registry stays the only place a permission name exists (D4).

### 4.1 New module slug — one

| slug | ModuleGroup | icon | is_core | Abilities | Why |
|---|---|---|---|---|---|
| `finance_categories` | `Finance` | `tag` | false | `CRUD` + `STATUS` | §30 dynamic expense category, plus the income counterpart. No `MONEY`: the table holds no money, so an Institute Manager can maintain categories without being given sight of a single amount |

No module is created for `invoice_items` (read inside `invoices.view`), for `finance_reversals` (an un-doing
is a status act on its parent — §4.3) or for the gateway abstraction (no permission surface for an
unimplemented driver). Following the spine precedent, **no new `Ability` case is invented**.

### 4.2 Abilities added to Phase 1 slugs (additive only)

| slug | Abilities after this phase | Notes |
|---|---|---|
| `invoices` | `CRUD_FULL` + `STATUS` + `FILES` + `MONEY` + `REPORTS` + `LOGS` | `delete` is policy-narrowed to an unissued draft with no payment. **Emailing an invoice is `invoices.change_status`** — it is the act that moves `draft` to `sent`; no `send` ability is invented (the spine set this precedent by mapping "run a reconciliation" onto `change_status`). `FILES` covers the PDF download |
| `expenses` | `CRUD_FULL` + `APPROVE` + `STATUS` + `FILES` + `MONEY` + `REPORTS` + `LOGS` | `APPROVE` = §30 approval workflow; `STATUS` = void; `FILES` = the receipt |
| `income` | `CRUD_FULL` + `STATUS` + `FILES` + `MONEY` + `REPORTS` + `LOGS` | no approval workflow — §29 does not ask for one |
| `payment_methods` | `CRUD` + `STATUS` + `LOGS` | no `MONEY`, no `export`. Gateway credentials are reachable only through `payment_methods.edit` and are never rendered |
| `payments` | `READ` + `print` + `export` + `MONEY` + `REPORTS` + `LOGS` | the umbrella money-in module, and **only** for the cross-source register of §8.13 / §7.5 (`module:payments`). Every project-payment route — admin **and** client — carries **`module:project_payments`** instead (F-6.1, spine §7.2 / §7.6); `payments` never gates a project-payment screen |
| `project_payments` *(spine slug)* | the spine set **+ `view_reports`** | the income, P&L and aging reports read this table; without the ability they cannot be permissioned honestly |
| `student_fee_payments` *(spine slug)* | the spine set **+ `view_reports`** | the income report fee lines |
| `reports` *(Phase 1, Shared)* | unchanged (`REPORTS`) | the finance report **hub** lives here; each individual report additionally demands its source module `view_reports` **and** `view_financial` (§7.6) |

### 4.3 Where `finance_reversals` is gated

| Act | Required |
|---|---|
| Refund / void an **expense** | `expenses.change_status` (+ `expenses.approve` when `finance.refund_approval_required` and the amount exceeds `finance.refund_approval_threshold`) |
| Refund / void an **other-income** row | `income.change_status` |
| Refund / void a **project payment** | the spine `payment_reversals.create` / `.approve` — unchanged, owned by the spine |

### 4.4 Portal permissions added

| Permission | Panel | Grants |
|---|---|---|
| `client_portal.invoices` | Client | list and read own invoices (never a `draft`), download the PDF |

`client_portal.payments` already exists (spine §4.3) and is used as-is. **No `collaborator_portal.*`,
`student_portal.*` or `teacher_portal.*` permission is added**: a collaborator, student or teacher has no
business in the software house invoices, expenses or other income (§9).

### 4.5 The accountant / project-manager split — the exact mechanism

Phase 1 §5 already grants the **Accountant** `invoices`, `payments`, `expenses`, `income` plus
`view_financial` and the finance reports, and grants the **Project Manager** only projects, milestones,
tasks, time tracking, clients (read), leads (read), meetings and files. Phase 13 keeps that and makes it
enforceable rather than aspirational:

| # | Rule | Implementation |
|---|---|---|
| 1 | **The Project Manager role receives no Phase 13 permission.** `RoleSeeder` is not extended for PM. The only PM-facing change is that the project detail page gains a Finance tab which **does not render** without `invoices.view_any` or `project_payments.view_any`, and `/admin/project-payments?project=` 403s | `RoleSeeder`, `App\Support\Sidebar`, the project show view tab guard |
| 2 | **Reaching a screen and seeing an amount are two different permissions.** Every money column in this phase is additionally gated by its module `view_financial`: a user with `invoices.view_any` but not `invoices.view_financial` gets the register with number, client, dates and status and **no** subtotal, tax, total, paid or balance column | `App\Support\FinanceVisibility::for(User $user, string $module): FinanceFieldSet` with `may(string $field): bool` and `columns(): array`; every finance controller builds its SELECT list from it and passes the set to the view |
| 3 | **A withheld column is absent from the response body, never rendered blank** — the rule the spine sets for the collaborator panel (§8.11) | the Blade iterates `$fields->columns()`; CSV and PDF build their header row from the same set; F13-30 asserts the label and the figure are absent from the HTML, the CSV and the JSON |
| 4 | **Reports are double-gated**: the hub needs `reports.view_reports`; each report needs its source module `view_reports` **and** `view_financial` (§7.6). An Institute Manager holding `reports.view_reports` still cannot open the P&L | stacked `can:` middleware driven by `FinanceReportType::permissions()` |
| 5 | **If the business does want a PM to see project receipts**, the answer is a role edit — grant `project_payments.view_any` + `project_payments.view_financial` (read-only, never `create`) — and the PM-scoped query rule of §9 then applies automatically. No code change, no new permission | §9 `ProjectManagerScope` |
| 6 | **Segregation of duties on approval**: `ExpensePolicy::approve()` returns false when `expense.created_by === $user->id` unless `finance.expense_self_approval_allowed` is true — mirroring the spine rule that a payout approver may not be its creator (spine §9, Accountant row) | policy + setting, default **false** |
| 7 | **Gateway credentials and the encrypted method config are never exposed** by any ability, `view_financial` included; the field renders masked with a "replace" toggle and the activity diff logs `[encrypted]` | `payment_methods` form, following Phase 2 §3 `SettingsService` |

---

## 5. SettingsRegistry additions

All added to Phase 2 existing **`finance`** group — no new group. Phase 2 keys (`invoice_prefix`,
`invoice_next_number`, `tax_enabled`, `tax_label`, `default_tax_rate`, `payment_terms_days`, `bank_details`,
`invoice_footer_note`, `expense_approval_required`) and the spine §5 keys (`backdate_limit_days`,
`refund_approval_required`, `refund_approval_threshold`, `payment_reversal_prefix`,
`payment_reversal_next_number`, …) are used **exactly as defined** and are not redefined here.

| group.key | type | default | Meaning |
|---|---|---|---|
| `finance.expense_prefix` | text | `EXP-` | §30 voucher number |
| `finance.expense_next_number` | number | `1` | counter, locked in-transaction by `DocumentNumberService` |
| `finance.income_prefix` | text | `INC-` | §29 other-income voucher number |
| `finance.income_next_number` | number | `1` | counter |
| `finance.expense_approval_threshold` | decimal | `0.00` | `0.00` = **every** expense needs approval when `expense_approval_required` is on; above `0.00`, only expenses over this amount do. Snapshotted into `expenses.approval_required` |
| `finance.expense_self_approval_allowed` | boolean | `false` | §4.5 rule 6 |
| `finance.expense_receipt_required` | boolean | `false` | forces a receipt upload |
| `finance.expense_receipt_threshold` | decimal | `0.00` | `0.00` = required on every expense when the boolean is on |
| `finance.invoice_round_off_enabled` | boolean | `false` | §2.7 step 11; **off**, so arithmetic never changes unless the client asks |
| `finance.invoice_rounding_precision` | select `1`/`5`/`10` | `1` | the nearest whole currency unit the grand total rounds to |
| `finance.invoice_allow_edit_after_issue` | boolean | `true` | when true, an issued invoice with `paid_amount = 0.00` may be edited (same number, full audit); when false the only correction is cancel + replace |
| `finance.invoice_public_link_enabled` | boolean | `true` | the signed client-facing view of §8.12 — required because D2 allows a client record with no login |
| `finance.invoice_public_link_days` | number | `30` | signed-URL lifetime |
| `finance.invoice_email_subject` | text | `Invoice {invoice_number} from {company_name}` | placeholders resolved by `InvoiceDeliveryService`; an unknown placeholder fails validation |
| `finance.invoice_email_body` | richtext | a neutral default naming the amount and the due date | the emailed message body |
| `finance.invoice_reminders_enabled` | boolean | `false` | **off** by default — the system never mails a client unasked |
| `finance.invoice_reminder_days_after_due` | text | `3,7,14` | comma list of offsets after `due_date` |
| `finance.invoice_show_bank_details` | boolean | `true` | prints Phase 2 `finance.bank_details` on the PDF |
| `finance.report_sync_row_limit` | number | `5000` | above this an export is queued and delivered by notification instead of streamed |
| `finance.reports_include_institute` | boolean | `true` | whether the finance reports include `context = institute` expenses and student-fee income, or show the software house alone (§6.7) |

**Validation** comes from `SettingsRegistry::rulesFor('finance')` as Phase 2 requires: decimals `min:0`,
`invoice_rounding_precision` `in:1,5,10`, `invoice_reminder_days_after_due` `regex:/^\d+(,\d+)*$/`,
counters `integer|min:1`, prefixes `max:16`, `invoice_email_subject` validated against the allowed
placeholder list.

---

## 6. Services

Namespace `App\Services\Finance\`. Every money method runs inside **one** `DB::transaction()`, obeys the
spine lock order **payment → document → … → invoice** (an invoice is a document, so it is locked *after* the
payment row whenever both are involved), and dispatches every event and notification through
`DB::afterCommit()` (spine INV-20). Controllers orchestrate only; validation lives in Form Requests;
row-level rules live in policies.

### 6.1 `InvoiceService`

| Method | Guarantees | Events |
|---|---|---|
| `create(InvoiceData $data): Invoice` | creates a **draft** with `invoice_number = NULL`; snapshots `tax_label`, `tax_rate`, `payment_terms_days`; `due_date` defaults to `issue_date + payment_terms_days`; writes the lines in `sort_order`; calls `recalculate()`; asserts `client_id` matches `project.client_id` when a project is given | `InvoiceCreated` |
| `update(Invoice $i, InvoiceData $data): Invoice` | refused unless `$i->status->isEditable()` **and** (`paid_amount = 0.00` **and** (`status = draft` or `finance.invoice_allow_edit_after_issue`)); lines are replaced wholesale inside the transaction; `recalculate()` + `recomputeStatus()`; `pdf_path` cleared; one activity row carrying **old and new** `total_amount` | `InvoiceUpdated` |
| `recalculate(Invoice $i): Invoice` | the twelve steps of §2.7 and nothing else; the five identities of §2.7 hold on return; no float arithmetic anywhere | — |
| `issue(Invoice $i, User $actor): Invoice` | refused when `status <> draft`, when there is no line, or when `total_amount <= 0.00`; assigns `invoice_number` through `DocumentNumberService` **in this transaction** (one retry on `1062`); stamps `issued_at`, `issued_by`, `sent_at` (when the caller marks it sent), `footer_note` and `bank_details` snapshots; generates `public_token`; `recomputeStatus()` | `InvoiceIssued` |
| `recomputePaid(Invoice $i): Invoice` | rewrites `paid_amount`, `refunded_amount`, `balance_amount` from the canonical SQL of §2.8 under the invoice row lock. **Idempotent, and the only writer of those three columns** | — |
| `recomputeStatus(Invoice $i): Invoice` | the pure function of §2.8; never invents a seventh status; writes an activity row only when the status actually changed | `InvoicePaid` / `InvoicePartiallyPaid` / `InvoiceOverdue` on the matching transition |
| `cancel(Invoice $i, string $reason, User $actor): Invoice` | **refused when `paid_amount > 0.00`** (refund first — §6.5); reason mandatory; keeps `invoice_number` for ever; stamps `cancelled_at/by/reason`; never deletes a row | `InvoiceCancelled` |
| `replace(Invoice $cancelled, InvoiceData $data): Invoice` | creates a new draft with `replaces_invoice_id` set, copying the lines; refused unless the source is `cancelled` | `InvoiceCreated` |
| `duplicate(Invoice $i): Invoice` | a new draft, no number, no payment link, dates reset to today | `InvoiceCreated` |
| `markSent(Invoice $i, array $recipients, User $actor): Invoice` | issues first when still a draft (one transaction), then stamps `sent_at` (first time only), `last_sent_at`, `last_sent_to`, `sent_count + 1` | `InvoiceSent` |
| `regeneratePublicToken(Invoice $i, User $actor): Invoice` | new `public_token`, which **revokes every link already emailed**; audited with the reason | `InvoiceUpdated` |
| `markViewed(Invoice $i): void` | sets `viewed_at` once, from the client panel or the public view; never changes status | — |
| `applyPayment(Invoice $i, ProjectPayment $p, string $reason, User $actor): Invoice` | §6.3 | `InvoicePaymentApplied` |
| `unapplyPayment(ProjectPayment $p, string $reason, User $actor): Invoice` | §6.3 | `InvoicePaymentUnapplied` |
| `recomputeFromPayments(Invoice $i): Invoice` | `recomputePaid()` then `recomputeStatus()` in one transaction — the single entry point used by the controller, the listener of §10.2 and the nightly reconcile | — |

**Deliberately absent:** recurring invoices, credit notes, quotations, multi-currency and a second tax
column. §31 asks for none of them.

### 6.2 Recording a project payment against an invoice — Phase 13 writes no payment code

| Step | Who |
|---|---|
| 1 | The modal of §8.6 posts to `admin.invoices.payments.store` with the spine field set (`amount`, `payment_method`, `payment_method_id`, `paid_on`, `reference_no`, `project_milestone_id`, `notes`, receipt file, `idempotency_key`, `confirm_duplicate`) plus the resolved `invoice_id` |
| 2 | The Form Request asserts `invoice.status` is `sent`, `partial` or `overdue`; `invoice.client_id = project.client_id`; the milestone belongs to the same project; `amount > 0`; and leaves `paid_on` window validation to the spine (`finance.backdate_limit_days`) |
| 3 | The controller calls **`Finance\PaymentService::recordProjectPayment($project, $data)`** (spine §6.2). The spine inserts the `project_payments` row with `invoice_id` set, enforces `uq_pp_idem`, resolves and snapshots `collaborator_id` / `collaborator_referral_id`, and dispatches `ProcessProjectPaymentCommission` **afterCommit** |
| 4 | Inside the same transaction the controller calls `InvoiceService::recomputeFromPayments($invoice)` |
| 5 | After commit: the spine job posts the commission (or records a skip reason); Phase 13 fires `InvoicePaid` / `InvoicePartiallyPaid` and notifies the client |

**The project commission trigger therefore fires exactly as the spine designed it, from the payment row,
never from the invoice.** Phase 13 adds no commission code, no ledger write and no wallet touch, and the
invoice link is invisible to the engine: the commission base comes from the project or the milestone
(spine §6.1.4), never from `invoices.total_amount`.

### 6.3 The invoice-to-payment allocation rule — binding

| # | Rule |
|---|---|
| 1 | **A payment belongs to at most one invoice.** The link is the spine column `project_payments.invoice_id`; there is no allocation pivot and a single receipt is **never** split across two invoices. A client paying two invoices with one transfer is receipted twice, one row per invoice, each with its own `idempotency_key` |
| 2 | **An invoice may hold many payments** (§31 partial payment): `invoices hasMany ProjectPayment`. `paid_amount` is the cache of §2.8 and nothing else |
| 3 | **Allocation is never automatic.** No FIFO sweep, no "apply the oldest open invoice" — deciding which claim a rupee settles is a money decision and belongs to a human. The modal *suggests* the oldest outstanding invoice of that client and shows its balance; the link is still an explicit selection, and `NULL` (an advance) is always offered |
| 4 | **An advance is applied by `applyPayment()`** — the **single concession to spine INV-8, granted as decision D43**: `project_payments.invoice_id` may move NULL → value → NULL, by `InvoiceService` alone, while the payment is not `voided`, reason mandatory and audited (§107), gated by `project_payments.edit` **and** `invoices.edit`, with zero commission effect (no ledger read and no ledger write). A deliberate, audited, one-way act: a conditional `UPDATE project_payments SET invoice_id = :invoice WHERE id = :payment AND invoice_id IS NULL` that must affect exactly **1** row (a second concurrent apply affects 0 and fails with a named error), followed by `recomputeFromPayments()`. It requires `invoices.edit` **and** `project_payments.edit` (the pair D43 names), a mandatory reason, and writes an activity row with old (`null`) and new value. `is_advance` is left exactly as the spine wrote it, as the historical fact that the money arrived before the claim existed |
| 5 | **`unapplyPayment()`** is the mirror: `UPDATE ... SET invoice_id = NULL WHERE id = :payment AND invoice_id = :invoice`, same permissions (`invoices.edit` + `project_payments.edit`), same mandatory reason, refused when the invoice is `cancelled` or the payment is `voided`. Both directions are the **only** mutations of the column, both live in `InvoiceService`, and both are covered by **D43** — nobody may later "tighten" INV-8 back over them |
| 6 | **Over-allocation is allowed, never blocked.** A receipt larger than the balance is recorded in full (the money physically arrived): `balance_amount` goes negative and the invoice reads `paid`, with the credit visible in the register and in the aging report. Validation never refuses money; the modal warns, naming the excess |
| 7 | **Invoice linkage has zero commission effect.** Applying, unapplying or over-applying never creates, moves or reverses a ledger row, because the engine keys off the payment, the project and the milestone. A test asserts the ledger is byte-identical across an apply / unapply cycle (F13-13) |
| 8 | **A voided payment keeps its `invoice_id`** so the document trail survives; §2.8 excludes it from `paid_amount` |

This is the one place Phase 13 touches a spine column, and it needs one concession from the spine: see §13.

### 6.4 `ExpenseService` and `IncomeService`

| Method | Guarantees | Events |
|---|---|---|
| `ExpenseService::record(ExpenseData): ExpenseResult` | exactly one row per `idempotency_key` (a replayed POST returns the existing row with `created: false`); `expense_no` from `DocumentNumberService` in-transaction; `amount > 0`; category `type = expense`; receipt MIME-validated and required when `finance.expense_receipt_required` and the amount exceeds `finance.expense_receipt_threshold`; `reference_no` required when the method demands it; `expense_date` never in the future and not older than `finance.backdate_limit_days` without `expenses.approve`; `status` and `approval_required` set from the **snapshotted** setting + threshold decision — `approved` with `approval_required = false` when approval is off or the amount is under the threshold, otherwise `pending` | `ExpenseRecorded`, `ExpenseAwaitingApproval` when pending |
| `ExpenseService::update(Expense, ExpenseData)` | allowed only while `status = pending`; every changed field written to the activity log with old and new values | `ExpenseUpdated` |
| `ExpenseService::approve(Expense, User)` | forward-only from `pending`; idempotent (approving an approved row is a no-op, so a double-clicked bulk action cannot double-count); refused when `created_by = approver` unless `finance.expense_self_approval_allowed`; stamps `approved_by/at` | `ExpenseApproved` |
| `ExpenseService::reject(Expense, string $reason, User)` | from `pending` only; **reason mandatory**; the row stays for ever and leaves every report | `ExpenseRejected` |
| `ExpenseService::approveMany(array $ids, User): BulkResult` | explicit ids only (never "everything matching the filter"), rows locked in ascending id order, a row whose status moved since the page loaded is reported as skipped and never force-approved | one `ExpenseApproved` per row |
| `ExpenseService::void(Expense, string $reason, User)` | the **only** correction path for an approved expense: `status = voided`, `voided_by/at`, `void_reason` mandatory, excluded from every report, nothing deleted. The corrected expense is re-entered and linked through `corrects_expense_id` | `ExpenseVoided` |
| `ExpenseService::recordReversal(Expense, ReversalData): FinanceReversal` | §6.4.1 below | `ExpenseReversed` |
| `ExpenseService::recomputeCaches(Expense)` | `refunded_amount` equals `SUM(finance_reversals.amount)` under the expense row lock; `net_amount` is generated, so it can never disagree | — |
| `IncomeService::record / update / void / recordReversal / recomputeCaches` | the same contracts, with `IncomeStatus` (`recorded` / `voided`) and no approval workflow | `IncomeRecorded`, `IncomeVoided`, `IncomeReversed` |

**6.4.1 How an expense or other-income refund is recorded — never deleted.**

```
DB::transaction(function () {
  1. lock the target row (expense | income) FOR UPDATE
  2. remaining = Money::sub(target.amount, target.refunded_amount)
     refuse when Money::compare(data.amount, remaining) > 0        // over-refund impossible
  3. INSERT finance_reversals  (reversal_no from the shared counter, idempotency_key UNIQUE,
                                exactly one target FK, type, amount, reason, refund_method,
                                occurred_on, performed_by + performed_by_name snapshot, attachment)
  4. UPDATE target SET refunded_amount = refunded_amount + :amount
      WHERE id = :id AND refunded_amount + :amount <= amount      // conditional; affectedRows must be 1
  5. when cumulative refunded = amount AND type IN (full_refund, void)
       -> target.status = voided with the reversal reason copied into void_reason
}, attempts: 3);
// afterCommit: ExpenseReversed / IncomeReversed
```

`net_amount` is a **generated STORED** column, so every expense report, the P&L and every widget pick the
refund up automatically — there is no second place to remember. No commission job is dispatched: an expense
has never produced a ledger row.

**6.4.2 `RecordPayrollExpense` — one expense row per paid payroll run (D44).**

Phase 7 owns payroll and must not own a finance table; Phase 13 owns `expenses` and must never recompute a
salary. The seam is one listener, registered by Phase 13:

| Piece | Contract |
|---|---|
| Listener | `App\Listeners\Finance\RecordPayrollExpense`, subscribed to **phase-07's `PayrollRunPaid`** (dispatched `afterCommit`), queued, `$afterCommit = true` |
| What it writes | exactly **one** `expenses` row per run: `status = approved` with `approval_required = false` (a paid run has already been authorised by HR), `context = general`, `finance_category_id` = the reserved `salaries` category (§2.3), `source_type = 'payroll_run'`, `source_id = run.id`, `amount` = the run's **net paid total** read from the run and its items through `Money::sum()`, `expense_date` = the run's period end, `payment_method` from the run, `title` naming the run number and period, `created_by` = the actor who paid the run |
| Idempotency | the INSERT is the check: `UNIQUE uq_exp_source(source_type, source_id)` (§2.6). A replay, a retry or a second `PayrollRunPaid` catches the 1062 and returns the existing row — **never** a second salary cost |
| What it never does | it never edits an existing expense, never sums `payroll_run_item_components` itself beyond the run's own stored totals, never writes a ledger row, and never touches the commission engine. A cancelled or corrected run is handled by HR issuing a correction run, which posts its own (signed) expense under its own `source_id` |
| Why | §29-30 and §99's profit and loss must include salaries **exactly once**; without this row the P&L is wrong by the whole payroll (**D44**, F-3.9). It is the **only** salary cost row in finance: no second "payroll" expense is ever typed by hand, which is what the reserved, undeletable category protects |

### 6.5 How a project-payment reversal / refund is recorded, and how it reaches the commission engine

Phase 13 **owns none of this** — it is the spine working, reached from Phase 13 screens. Stated in full
because the brief asks for it, with the spine section that owns each step.

| # | Step | Owner |
|---|---|---|
| 1 | The register or the invoice detail posts `admin.project-payments.refund` (or `.void`) — spine routes, `can:payment_reversals.create` / `can:project_payments.change_status` — with amount, mandatory reason, `ReversalType`, refund method, `occurred_on`, attachment, `idempotency_key` | spine §7.2 |
| 2 | The controller calls the spine in **exactly one form — the DTO** (F-4.2): `refund(StudentFeePayment\|ProjectPayment $p, RefundData $data): PaymentReversal`, e.g. `refund($payment, new RefundData(amount: $amount, reason: $reason, type: ReversalType::PartialRefund, method: $method, idempotencyKey: $key, refundedOn: $occurredOn))`. `App\DataObjects\Finance\RefundData` is the spine's readonly DTO (spine §13.2); **two adjacent `string` positionals are swappable, which is a silent money bug**, so no positional form of this call exists anywhere. `PaymentService::refund()` then recomputes the unrefunded remainder **under the payment row lock**, refuses an over-refund, inserts one `payment_reversals` row (`amount > 0`, reason, `performed_by` + `performed_by_name`, `occurred_on`, `refund_method`, reference, attachment) and raises `project_payments.refunded_amount` with a conditional `UPDATE ... WHERE refunded_amount + :x <= amount` | spine §6.3.1, INV-9 |
| 3 | The payment status moves per the spine table: `cleared → partially_refunded → refunded`, or `cleared → voided` for a full-amount `void` (the **only** edit path — a payment row is never updated, INV-8), or `→ bounced` for a bounced instrument | spine §2.18.3 |
| 4 | **Nothing is ever deleted**: the original receipt row, its amount, its method and its value date stay byte-identical for ever, and the reversal points at it | spine INV-4, INV-5, trigger `trg_pr_no_delete` |
| 5 | When `finance.refund_approval_required` applies (amount over `finance.refund_approval_threshold`), the reversal is written `approval_status = pending` and **no commission reversal runs yet**; `RefundAwaitingApproval` notifies holders of `payment_reversals.approve`. A rejection rolls the `refunded_amount` increment back inside the same transaction | spine §2.18.4, §6.3.1 |
| 6 | **How the commission engine is notified:** on insert (when `approval_status = not_required`) or on approval, the spine dispatches the queued job **`ProcessCommissionReversal`** (`ShouldBeUnique` on `payment-reversal:{id}`, `$afterCommit = true`), which calls `CommissionReversalService::handleReversal()`. Phase 13 dispatches nothing and must never call the engine directly | spine §10.2, §6.3.2 |
| 7 | The engine computes, per earning entry, `target_undone = round(E.amount × cumulative_refunded / payment.amount, 2)` and posts a **negative ledger row** (`purpose = reversal`, or `clawback` when the commission was already paid out), referencing the original entry and the reversal row. The original is never edited; `uq_cle_reversal_pair` makes a replay idempotent | spine §6.3.2–§6.3.5 |
| 8 | **Phase 13 reacts, not drives:** the listener `RecomputeInvoiceOnPaymentChange` (§10.2) hears `PaymentReversalRecorded`, `PaymentReversalApproved` and `PaymentReversalRejected`; for a payment carrying an `invoice_id` it calls `InvoiceService::recomputeFromPayments()`, so `paid_amount`, `balance_amount` and `status` follow the refund within the same request. It is a pure recompute, therefore safe to replay |
| 9 | A refund never deletes a receipt, never unlinks an invoice, never reopens a cancelled invoice, and never changes the commission of any **other** payment | §6.3 rule 7 |

### 6.6 `PaymentMethodService` and the gateway-ready abstraction (§32)

| Piece | Contract |
|---|---|
| `PaymentMethodService::create/update(data)` | `code` must be a `PaymentMethod` enum value and unique; the encrypted config is written through the `encrypted` cast and never logged; `usable_for` validated against the allowed context list |
| `PaymentMethodService::setDefault(PaymentMethod $m)` | one statement inside a transaction; `uq_pm_default` is the guarantee, not a "clear all others" loop |
| `PaymentMethodService::toggle(PaymentMethod $m, bool, ?string $reason)` | deactivating hides the method everywhere and changes no history; audited with the reason |
| `PaymentMethodService::availableFor(string $context): Collection` | the only source of every method dropdown; cached, flushed on save |
| `App\Services\Finance\Gateways\PaymentGateway` *(interface)* | `charge(GatewayChargeRequest): GatewayResult`, `verify(string $reference): GatewayResult`, `refund(string $reference, string $amount): GatewayResult`, `webhookPayload(Request): GatewayEvent`, `supportsRefund(): bool` |
| `ManualGateway` | the **only** driver registered in this phase: `charge()` throws `GatewayNotConfiguredException`, `supportsRefund()` is false. It exists so the contract is real and testable rather than imaginary |
| `PaymentGatewayManager::driver(?string $name): PaymentGateway` | resolves by `payment_methods.gateway_driver` from a container-bound map, falls back to `ManualGateway`, and throws a named exception for an unknown driver |
| Deliberately **not** built | no gateway route, controller, webhook endpoint, redirect flow or stored card. §32 asks for a gateway-**ready** architecture; building an unpaid-for integration would be invented scope. Mirrors Phase 2 `security.two_factor_enabled` ("declared, implemented later") |

### 6.7 `FinanceReportService` (§99) — four reports, one definition each

`report(FinanceReportType, DateRange, ReportFilters): ReportResult`. Every report returns the readonly
`App\Support\ReportResult` of §6.9 — `{rows, groups, totals, meta}` — where `meta` names the **date column used**, the filters in force and the
basis, so a screen, a CSV and a PDF can never disagree. `DateRange` is Phase 2 (today, yesterday, week,
month, quarter, year, custom) with `previous()` for the comparison column (§99 filter list).

**Basis: cash.** Every figure is money that actually moved, on its **value date** (`paid_on`,
`expense_date`, `received_on`), with a documented toggle to `recorded_at` where both exist. An invoice is a
claim, not cash, so it appears only in receivables aging — never as income (§12.2 Q1).

**6.7.1 Income report (§29).** Three sources, unioned, each a single SQL aggregate; the totals are combined
in PHP with `Money::sum()`. A source the user lacks `view_reports` + `view_financial` for is **omitted from
the response**, and `meta.omitted_sources` names it so a partial total is never read as a full one.

| Source | Table | Amount | Date | Filter |
|---|---|---|---|---|
| Project payments (§29 client/project payments) | `project_payments` | `SUM(net_received_amount)` | `paid_on` | `status <> 'voided'` |
| Student fees (§29 course / student / admission fees) | `student_fee_payments` joined to `student_fees` | `SUM(net_received_amount)` | `paid_on` | `status <> 'voided'`; broken out by `student_fees.fee_type`, which is how §29 list of fee kinds is satisfied **without** a fourth source and without double counting |
| Other income (§29) | `incomes` | `SUM(net_amount)` | `received_on` | `status = 'recorded'` |

Group-by options: month, source, category, payment method, project, client, branch, `context`.
`finance.reports_include_institute = false` drops the student-fee source and `context = institute` rows.
**Invoices are not a source.** **Collaborator payouts are not income** — they are an outflow (§6.7.3).

**6.7.2 Expense report (§30).** `expenses` where `status = 'approved'` (`ExpenseStatus::countsInReports()`),
`SUM(net_amount)` on `expense_date`. Group by category, month, `context`, project, payment method, branch,
`created_by`. Filters: category, context, project, method, branch, amount range, approver, free text on
`expense_no` / `title` / `paid_to`. `pending`, `rejected` and `voided` rows are excluded from the totals and
surfaced as a separate counted line ("3 pending approvals worth PKR 41,000 are excluded"), so money awaiting
a decision is visible but never silently counted.

**6.7.3 Profit & loss (§99).** Three blocks and two bottom lines, so nothing is double counted:

```
A  Income (cash)                     = §6.7.1 total, by source
B  Expenses (cash, approved)         = §6.7.2 total, by category
     ... of which Salaries           = the reserved `salaries` category, i.e. one row per paid
                                       payroll run written by RecordPayrollExpense (§6.4.2, D44)
   Net profit before collaborator commission = A - B
C  Collaborator commission (cost)    = the spine's payouts-paid total for the period
                                       (CollaboratorWalletService::payoutsPaidTotal, spine §6.2)
   Net profit                        = A - B - C
   Memo (not in either line): commission accrued in the period
                                      = CollaboratorStatementService::commissionAccruedTotal
```

Rules: the comparison column is `DateRange::previous()`; **commission is never an expense row** — it
reaches the P&L only through block C, and the screen carries the standing note "collaborator payouts come
from the payout register; do not also record them as expenses" (§12.1 R-4). Blocks C and the memo are
obtained **only** by calling the spine services (spine INV-26 / FT-42): Phase 13 never sums the ledger, the
payouts or the allocations itself.

**Block B always contains salaries, exactly once.** The salary line is the reserved `salaries` category of
block B — the `approved` expense rows `RecordPayrollExpense` writes, one per paid payroll run, unique on
(`source_type`, `source_id`). Phase 13 never reads a payroll table and never re-sums a slip, and no second
salary cost row is ever typed by hand (§6.4.2, §12.1 R-4, **D44**).

**Block C is a cost, never income (D-legend, F-13.3).** The screen legend reads: *"Collaborator commission
(cost) — payments **to** collaborators. §29's phrase 'collaborator payments' means money leaving the
business; it is never income."* If the client ever means money received *from* a collaborator, it becomes an
`incomes` category row — `incomes` already carries categories, so that needs no schema change.

**Open point on the two spine signatures (F-4.8).** Blocks C and the memo are **company-wide** figures for
the period. F-4.8 publishes `CollaboratorWalletService::payoutsPaidTotal(Collaborator $c, ?DateRange $r = null): string`
and `CollaboratorStatementService::commissionAccruedTotal(Collaborator $c, ?DateRange $r = null): string`,
which are **per-collaborator**. Phase 13 must not close that gap by summing payouts, allocations or ledger
rows itself (spine INV-26, FT-42), so the company-wide variant has to come from the spine as well; it is
recorded as a request in §13.1 rather than guessed here.

**6.7.4 Receivables aging (§99).** Per client, with an invoice drill-down. Population: invoices where
`status IN (sent, partial, overdue)` and `balance_amount > 0` — `draft` and `cancelled` are excluded by
definition. `days_overdue = today - due_date`; buckets from `AgingBucket`:

| Column | Rule |
|---|---|
| `current` | `due_date >= today` (not yet due) |
| `d1_30` / `d31_60` / `d61_90` / `d90_plus` | 1–30, 31–60, 61–90, 91+ days past `due_date` |
| Per-client columns | client, outstanding total, the five buckets, oldest unpaid invoice date, last payment date, **unapplied credits** = `SUM(net_received_amount)` of that client `project_payments` with `invoice_id IS NULL AND status <> 'voided'`, and `net_exposure = outstanding - unapplied_credits` |
| Totals row | every bucket plus the two credit columns, labelled with the filters in force |

Showing unapplied credits beside the debt is what stops the report overstating exposure when a client has
paid in advance, and it is the screen from which `applyPayment()` (§6.3) is usually reached.

**6.7.5 Exports (§99).**
`App\Services\Reporting\ReportExporter::export(ReportResult $r, ExportFormat $f): StreamedResponse` — the
canonical signature four later phases already assume (§6.9, F-4.14). `print` renders
`resources/views/layouts/print.blade.php`; `pdf` is dompdf over that layout; `csv` is a streamed `fputcsv`
download. **Nothing is ever `->get()` into memory**: every format streams its rows. The header
row is built from the same `FinanceFieldSet` as the screen, so a withheld money column is absent from the
file too. A result above `finance.report_sync_row_limit` rows is never passed to `export()` at all: it is handed to the
queued job `BuildFinanceReportExport` and delivered by notification — the pattern the spine uses for large
statements.
Excel is deferred to Phase 23 (`ExportFormat` has no `excel` case yet).

### 6.8 `InvoicePdfService` and `InvoiceDeliveryService`

| Method | Guarantees |
|---|---|
| `InvoicePdfService::render(Invoice): string` | dompdf over `resources/views/admin/invoices/pdf.blade.php`; reads only stored columns and the invoice **snapshots** (`tax_label`, `tax_rate`, `footer_note`, `bank_details`), never live settings, so re-printing a year-old invoice reproduces it exactly; shows the tax row only when `tax_amount > 0`, the round-off row only when it is non-zero, and never `internal_notes` |
| `InvoicePdfService::store(Invoice): string` | writes `invoices/{invoice_number}.pdf` on the **private `local`** disk (**D21** — an invoice PDF is never on the `public` disk and has no guessable path), sets `pdf_path`, replaces and deletes any previous file. A draft is never stored (it has no number). Every read is streamed by `admin.invoices.pdf`, `client.invoices.pdf` or the signed `site.invoices.pdf`, each re-running its own permission chain |
| `InvoicePdfService::filename(Invoice): string` | `{invoice_number}.pdf`, or `DRAFT-{id}.pdf` for a draft preview |
| `InvoiceDeliveryService::send(Invoice, SendInvoiceData, User $actor): DeliveryResult` | refuses a `cancelled` invoice; **issues the invoice first when it is still a draft, in the same transaction**, so an emailed invoice always carries a number; resolves subject and body placeholders from the settings; validates every recipient as an email; queues `SendInvoiceEmail` afterCommit with the PDF attached and the signed public link when enabled; on success stamps `sent_at` / `last_sent_at` / `last_sent_to` / `sent_count` and writes one activity row naming the recipients; a mailer failure is caught, surfaced as a toast with the real exception message and logged, and **does not** roll the issue back (the invoice is issued; the send is retryable) |
| `InvoiceDeliveryService::sendReminder(Invoice, User|null $actor): DeliveryResult` | only for `status IN (sent, partial, overdue)` with `balance_amount > 0`; respects `finance.invoice_reminders_enabled`; stamps `last_reminder_at`, `reminder_count`; never sends twice on the same offset day |

### 6.9 Support classes

| Class | Responsibility |
|---|---|
| `App\Support\FinanceVisibility` | `for(User, string $module): FinanceFieldSet`; `FinanceFieldSet::may(string $field)`, `columns()`, `moneyColumns()`. The single implementation of §4.5 rules 2 and 3 |
| `App\Support\InvoiceTotals` | a pure, dependency-free value object running §2.7 over an array of line DTOs; used by `recalculate()` **and** by the live-preview endpoint, so the screen and the stored row can never compute differently |
| `App\Support\AgingCalculator` | `bucket(int $daysOverdue): AgingBucket` — used by the SQL `CASE`, the CSV and the screen |
| `App\Support\ReportResult` | **Phase 13 ships it** (F-4.14): a readonly DTO `{array $rows, array $groups, array $totals, array $meta}`. `FinanceReportService::report()` returns one, and phases 18, 19-23 and 24-25 consume this exact shape rather than inventing a second report envelope |
| `App\Services\Reporting\ReportExporter` | **Phase 13 ships it** (F-4.14): `export(ReportResult $r, ExportFormat $f): StreamedResponse` — csv / xlsx / pdf, always **streamed**, never `->get()` into memory. Namespaced `Reporting`, not `Finance`, because phases 18 and 19-23 export non-finance reports through the same class |
| `resources/views/layouts/print.blade.php` | **Phase 13 ships it** (F-4.14): the one print layout (A4, header/footer blocks, `tabular-nums` money, no navigation) that every later printable report and document extends; it sanitises any rich-text block through `App\Support\RichText::sanitize()` |

---

## 7. Routes

Every admin route additionally carries `auth`, `active`, `panel:admin` from the Phase 1 §8 file group; panel
routes carry their own `panel:*`. `module:*` is stated where it differs from the file-level group. Multiple
`can:` middleware entries on one route are **and**-ed.

### 7.1 Admin — invoices

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/invoices` | `admin.invoices.index` | `module:invoices`, `can:invoices.view_any` |
| GET `/admin/invoices/create` | `admin.invoices.create` | `can:invoices.create` |
| POST `/admin/invoices` | `admin.invoices.store` | `can:invoices.create` |
| POST `/admin/invoices/totals/preview` | `admin.invoices.totals.preview` | `can:invoices.create`, `throttle:60,1` — runs `InvoiceTotals` and **writes nothing** |
| GET `/admin/invoices/{invoice}` | `admin.invoices.show` | `can:invoices.view` |
| GET `/admin/invoices/{invoice}/edit` | `admin.invoices.edit` | `can:invoices.edit` |
| PUT `/admin/invoices/{invoice}` | `admin.invoices.update` | `can:invoices.edit` |
| DELETE `/admin/invoices/{invoice}` | `admin.invoices.destroy` | `can:invoices.delete` (policy: unissued draft, no payment) |
| POST `/admin/invoices/{invoice}/issue` | `admin.invoices.issue` | `can:invoices.change_status` |
| POST `/admin/invoices/{invoice}/send` | `admin.invoices.send` | `can:invoices.change_status`, `throttle:10,1` |
| POST `/admin/invoices/{invoice}/reminder` | `admin.invoices.reminder` | `can:invoices.change_status`, `throttle:10,1` |
| POST `/admin/invoices/{invoice}/cancel` | `admin.invoices.cancel` | `can:invoices.change_status` |
| POST `/admin/invoices/{invoice}/replace` | `admin.invoices.replace` | `can:invoices.create` |
| POST `/admin/invoices/{invoice}/duplicate` | `admin.invoices.duplicate` | `can:invoices.create` |
| POST `/admin/invoices/{invoice}/public-link/rotate` | `admin.invoices.public-link.rotate` | `can:invoices.change_status` |
| GET `/admin/invoices/{invoice}/pdf` | `admin.invoices.pdf` | `can:invoices.print`, `can:invoices.view_financial` |
| GET `/admin/invoices/{invoice}/print` | `admin.invoices.print` | `can:invoices.print`, `can:invoices.view_financial` |
| POST `/admin/invoices/{invoice}/payments` | `admin.invoices.payments.store` | `can:project_payments.create`, `throttle:20,1` — delegates to the spine `PaymentService` (§6.2) |
| POST `/admin/invoices/{invoice}/payments/{payment}/apply` | `admin.invoices.payments.apply` | `can:invoices.edit`, `can:project_payments.edit` (the **D43** pair) |
| DELETE `/admin/invoices/{invoice}/payments/{payment}/apply` | `admin.invoices.payments.unapply` | `can:invoices.edit`, `can:project_payments.edit` (the **D43** pair) |
| GET `/admin/invoices/export/{format}` | `admin.invoices.export` | `can:invoices.export`, `can:invoices.view_financial` |

### 7.2 Admin — expenses

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/expenses` | `admin.expenses.index` | `module:expenses`, `can:expenses.view_any` |
| GET `/admin/expenses/approvals` | `admin.expenses.approvals` | `can:expenses.approve` |
| GET `/admin/expenses/create` | `admin.expenses.create` | `can:expenses.create` |
| POST `/admin/expenses` | `admin.expenses.store` | `can:expenses.create` |
| GET `/admin/expenses/{expense}` | `admin.expenses.show` | `can:expenses.view` |
| GET `/admin/expenses/{expense}/edit` | `admin.expenses.edit` | `can:expenses.edit` (policy: `pending` only) |
| PUT `/admin/expenses/{expense}` | `admin.expenses.update` | `can:expenses.edit` |
| DELETE `/admin/expenses/{expense}` | `admin.expenses.destroy` | `can:expenses.delete` (policy: `pending` only) |
| POST `/admin/expenses/{expense}/approve` | `admin.expenses.approve` | `can:expenses.approve` |
| POST `/admin/expenses/{expense}/reject` | `admin.expenses.reject` | `can:expenses.reject` |
| POST `/admin/expenses/bulk-approve` | `admin.expenses.bulk-approve` | `can:expenses.approve`, `throttle:10,1` |
| POST `/admin/expenses/{expense}/void` | `admin.expenses.void` | `can:expenses.change_status` |
| POST `/admin/expenses/{expense}/reversals` | `admin.expenses.reversals.store` | `can:expenses.change_status` |
| GET `/admin/expenses/{expense}/receipt` | `admin.expenses.receipt` | `can:expenses.download` |
| GET `/admin/expenses/export/{format}` | `admin.expenses.export` | `can:expenses.export`, `can:expenses.view_financial` |

### 7.3 Admin — other income

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/income` | `admin.income.index` | `module:income`, `can:income.view_any` |
| GET `/admin/income/create` · POST `/admin/income` | `admin.income.create` · `.store` | `can:income.create` |
| GET `/admin/income/{income}` | `admin.income.show` | `can:income.view` |
| GET `/admin/income/{income}/edit` · PUT `/admin/income/{income}` | `admin.income.edit` · `.update` | `can:income.edit` |
| DELETE `/admin/income/{income}` | `admin.income.destroy` | `can:income.delete` (policy: not voided, no reversal) |
| POST `/admin/income/{income}/void` | `admin.income.void` | `can:income.change_status` |
| POST `/admin/income/{income}/reversals` | `admin.income.reversals.store` | `can:income.change_status` |
| GET `/admin/income/{income}/receipt` | `admin.income.receipt` | `can:income.download` |
| GET `/admin/income/export/{format}` | `admin.income.export` | `can:income.export`, `can:income.view_financial` |

### 7.4 Admin — payment methods and finance categories

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/payment-methods` | `admin.payment-methods.index` | `module:payment_methods`, `can:payment_methods.view_any` |
| GET `/admin/payment-methods/create` · POST | `admin.payment-methods.create` · `.store` | `can:payment_methods.create` |
| GET `/admin/payment-methods/{method}/edit` · PUT | `admin.payment-methods.edit` · `.update` | `can:payment_methods.edit` |
| DELETE `/admin/payment-methods/{method}` | `admin.payment-methods.destroy` | `can:payment_methods.delete` (policy: unused only) |
| POST `/admin/payment-methods/{method}/toggle` | `admin.payment-methods.toggle` | `can:payment_methods.change_status` |
| POST `/admin/payment-methods/{method}/default` | `admin.payment-methods.default` | `can:payment_methods.edit` |
| GET `/admin/finance-categories` | `admin.finance-categories.index` | `module:finance_categories`, `can:finance_categories.view_any` |
| POST `/admin/finance-categories` | `admin.finance-categories.store` | `can:finance_categories.create` |
| PUT `/admin/finance-categories/{category}` | `admin.finance-categories.update` | `can:finance_categories.edit` |
| DELETE `/admin/finance-categories/{category}` | `admin.finance-categories.destroy` | `can:finance_categories.delete` (policy: unused only) |
| POST `/admin/finance-categories/{category}/toggle` | `admin.finance-categories.toggle` | `can:finance_categories.change_status` |
| POST `/admin/finance-categories/reorder` | `admin.finance-categories.reorder` | `can:finance_categories.edit` |

### 7.5 Admin — the cross-source payments register

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/payments` | `admin.payments.index` | `module:payments`, `can:payments.view_any`, `can:payments.view_financial` |
| GET `/admin/payments/export/{format}` | `admin.payments.export` | `can:payments.export`, `can:payments.view_financial` |

The project-payment and reversal detail, receipt, refund and void routes are the spine (§7.2, §7.3) and are
**not** redeclared here.

### 7.6 Admin — finance reports (§99)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/reports/finance` | `admin.reports.finance.index` | `module:reports`, `can:reports.view_reports` |
| GET `/admin/reports/finance/income` | `admin.reports.finance.income` | `can:income.view_reports`, `can:income.view_financial` |
| GET `/admin/reports/finance/expenses` | `admin.reports.finance.expenses` | `can:expenses.view_reports`, `can:expenses.view_financial` |
| GET `/admin/reports/finance/profit-loss` | `admin.reports.finance.profit-loss` | `can:income.view_reports`, `can:income.view_financial`, `can:expenses.view_financial` |
| GET `/admin/reports/finance/receivables-aging` | `admin.reports.finance.receivables-aging` | `can:invoices.view_reports`, `can:invoices.view_financial` |
| GET `/admin/reports/finance/{report}/export/{format}` | `admin.reports.finance.export` | the same pair as the report (resolved by `FinanceReportType::permissions()`), plus `throttle:20,1` |

### 7.7 Client panel (`auth`, `active`, `panel:client`)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/client/invoices` | `client.invoices.index` | `client.context`, `can:client_portal.invoices` |
| GET `/client/invoices/{invoice}` | `client.invoices.show` | `client.context`, `can:client_portal.invoices` + policy ownership (**404**, not 403) |
| GET `/client/invoices/{invoice}/pdf` | `client.invoices.pdf` | `client.context`, `can:client_portal.invoices` + policy ownership |

**Every** client route carries `client.context` (**D31**, F-12.3) — there is no exception, so a revoked or
reassigned client link dies immediately on all three. These three screens are **appended** to Phase 5's
`routes/client.php` through `ClientPortalRegistry`: Phase 5 owns the file and every `client.*` name, and
Phase 13 redeclares none of them (D31). `client.payments.index` and `client.payments.receipt` already exist
(spine §7.6, now also carrying `module:project_payments` and `client.context`).

### 7.8 Public — the signed client-facing invoice view

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/invoices/{token}` | `site.invoices.view` | `signed`, `throttle:30,1`, `EnsureInvoicePublicLinkEnabled` |
| GET `/invoices/{token}/pdf` | `site.invoices.pdf` | `signed`, `throttle:10,1`, `EnsureInvoicePublicLinkEnabled` |

Route-model binding is on `invoices.public_token` (40 random characters), **and** Laravel signed-URL
verification carries the expiry from `finance.invoice_public_link_days`. A `draft` or `cancelled` invoice
returns 404. Rotating the token (`admin.invoices.public-link.rotate`) revokes every link already emailed.
No payment, no comment and no file upload is possible through this route — it reads one document.

---

## 8. UI screens

Phase 1 §9 and `CLAUDE.md` §6 apply to every screen: `x-ui.*` components only, search + filters + sortable
headers + pagination + empty state + skeleton loader on every list, money right-aligned with `tabular-nums`,
a toast on every write, `x-ui.confirm` on every destructive or irreversible action, tables inside
`overflow-x-auto`, light and dark, mobile first.

**No Kanban and no calendar anywhere in this phase** — §29–32 ask for neither (§18 asks for a lead Kanban,
§22 for a task Kanban), so an invoice board would be invented scope. The only multi-step flow is the
record-payment modal, inherited from the spine §8.1. The invoice builder is deliberately **not** a wizard:
a biller needs every line, the totals and the dates on one screen at once.

### 8.1 Invoice register — `admin/invoices/index.blade.php`

**Purpose.** The accountant view of every claim on a client, and the entry point to issue, send and receipt.
**Components.** `x-ui.page-header` (+ "New invoice"), `x-ui.filter-bar`, `x-ui.tabs` (status tabs), `x-ui.table`,
`x-ui.th-sortable`, `x-ui.badge`, `x-ui.stat-card` x 4, `x-ui.pagination-summary`, `x-ui.empty-state`,
`x-ui.skeleton`, `x-ui.confirm`.
**Stat cards** (each requires `invoices.view_financial`): Outstanding · Overdue · Draft · Paid this month.
**Status tabs.** All · Draft · Sent · Partial · Overdue · Paid · Cancelled (the six `InvoiceStatus` cases),
each with a count, plus a "Partially paid" filter chip (`paid_amount > 0 AND status IN (partial, overdue)`).
**Filters.** Date range on `issue_date` with a toggle to `due_date` (stated on screen), client, project,
status, branch, amount range, "has unapplied credit", free text on `invoice_number` / client / `reference`.
**Columns.** Invoice # (`draft_reference` for a draft, in slate) · client · project · `issue_date` ·
`due_date` (+ a rose "N days overdue" chip) · **total** · **paid** · **balance** · status badge ·
sent chip (`sent_count` with `last_sent_at` on hover) · row actions (view, edit, issue, send, record payment,
PDF, duplicate, cancel, delete — each permission-gated, cancel and delete behind `x-ui.confirm` with a
**mandatory reason** field).
**Footer.** Sum of total, paid and balance over the filtered set, always labelled with the filter in force.
**Withheld columns.** Without `invoices.view_financial` the three money columns and the four stat cards are
**absent from the response**, not blank (§4.5 rule 3).
**Empty state.** "No invoices match this filter" + a "New invoice" action; on a truly empty table, a one-line
explanation of what an invoice is and the same action.

### 8.2 Invoice builder — `admin/invoices/form.blade.php` (create and edit)

**Purpose.** Build §31 document: client, project, dates, line items with quantity and rate, discounts, tax,
notes.
**Components.** `x-ui.card` sections, `x-ui.form.select` (client, project, branch, payment method),
`x-ui.form.input`, `x-ui.form.textarea`, `x-ui.form.checkbox` (`is_taxable` per line), `x-ui.button`,
`x-ui.icon-button` (remove line), `x-ui.badge`, `x-ui.confirm` (leaving a dirty form).
**Line editor.** An Alpine repeater: drag handle (`sort_order`), description, details, unit, quantity,
rate, discount mode + value, taxable toggle, tax rate, and a read-only line total. Add line, duplicate
line, remove line, and "insert from milestone" which pulls `project_milestones.name` + `.amount` for the
selected project (phase-06 §2.4 names the column `name`).
**Live totals panel** (sticky on the right, stacked below on mobile): subtotal, line discounts,
invoice discount, taxable amount, `{tax_label} @ {rate}`, round-off (only when non-zero) and the grand
total — all fetched from `admin.invoices.totals.preview`, which runs the **same** `InvoiceTotals` class the
service uses, so the preview can never disagree with what is saved. A skeleton shows while it recalculates.
**Guards on screen.** A zero-total invoice cannot be issued (the Issue button is disabled with the reason);
an invoice with a payment shows a rose notice that lines are locked and the correction path is cancel +
replace; `finance.tax_enabled = false` hides every tax control entirely.
**Actions.** Save draft · Save and issue · Save, issue and send (each a separate submit, each audited).

### 8.3 Invoice detail — `admin/invoices/show.blade.php`

**Purpose.** One page that answers "what do they owe, what did they pay, what did we send them".
**Components.** `x-ui.page-header` (number, status badge, client, action dropdown), `x-ui.stat-card` x 3
(Total · Paid · Balance — `view_financial` only; Balance renders rose when positive and overdue, emerald at
zero, amber when negative/overpaid), `x-ui.tabs`, `x-ui.table`, `x-ui.badge`, `x-ui.modal`, `x-ui.confirm`.
**Tabs.**
- **Items** — the read-only line table with the totals block exactly as it prints.
- **Payments** — receipt # · `paid_on` (+ "back-dated" chip when it differs from `recorded_at`) · method ·
  amount · refunded · **net** · commission state badge (from the spine column, shown only with
  `collaborator_commissions.view_financial`) · actions (view, print receipt, refund, void, unapply). Below
  it, **"Unapplied credits for this client"** listing `project_payments` with `invoice_id IS NULL`, each with
  an Apply action (mandatory reason) — the §6.3 rule 4 path.
- **Delivery** — `sent_at`, `sent_count`, `last_sent_to`, `viewed_at`, the reminder history, the public-link
  state with Copy link and Rotate link (behind confirm, stating that existing links stop working).
- **Activity** — the `activity_log` rows for this invoice: created, issued (old/new number), edited
  (old/new total), sent, payment applied / unapplied, cancelled — with actor, IP and reason.
**Empty states.** Payments tab: "No payments yet" + Record payment. Delivery tab: "Not sent yet" + Send.

### 8.4 Invoice PDF and print view — `admin/invoices/pdf.blade.php`

Company logo and details from Phase 2 `branding` / `company` / `contact`; the invoice's own snapshots for
`tax_label`, `tax_rate`, `footer_note` and `bank_details`; client billing block; number, issue date, due
date, reference; the line table (description, unit, quantity, rate, discount, tax, amount); the totals block
(subtotal, discounts, taxable, tax, round-off, grand total, paid, balance); `notes`; payment instructions
from the selected `payment_methods.instructions`; a large `CANCELLED` watermark when cancelled; a `DRAFT`
watermark and no number on a draft preview. **`internal_notes` never appears.** The print view is the same
Blade with a print stylesheet.

### 8.5 Send-invoice modal

`x-ui.modal` + `x-ui.form.input` (To, CC — prefilled from `clients.email`), `x-ui.form.textarea` (message,
prefilled from `finance.invoice_email_body` with placeholders resolved), a checkbox "attach PDF" (default
on), a checkbox "include secure link" (shown only when `finance.invoice_public_link_enabled`), and a notice
when the invoice is still a draft: "this will issue the invoice and assign number INV-000043". The submit
button disables on click; the result is a toast, and a mailer failure shows the real exception message.

### 8.6 Record-payment modal (from an invoice)

The spine §8.1 four-step modal, reused unchanged, with two Phase 13 additions: `invoice_id` pre-filled and
shown read-only with the invoice balance, and step 1 warning in amber when the amount exceeds the balance
("PKR 5,000.00 more than the balance — the excess stays on this invoice as a credit"). Step 4 still shows
the spine read-only **commission preview** and the hidden `idempotency_key` ULID generated once per modal
open, and the `duplicate_fingerprint` confirmation path is untouched.

### 8.7 Expense register and form — `admin/expenses/`

**Purpose.** §30 day-to-day spending with its approval workflow.
**Components.** `x-ui.page-header`, `x-ui.filter-bar`, `x-ui.tabs` (All · Pending · Approved · Rejected ·
Voided), `x-ui.table`, `x-ui.badge`, `x-ui.stat-card` x 3 (This month · Pending approval · Voided),
`x-ui.modal` (approve / reject / void), `x-ui.confirm`, `x-ui.form.file` (receipt), `x-ui.empty-state`.
**Filters.** Date range on `expense_date` (toggle to `recorded_at`), category, context, project, branch,
payment method, status, added-by, approver, amount range, "has receipt", free text on `expense_no` / title /
`paid_to`.
**Columns.** `expense_no` · `expense_date` · category · title · `paid_to` · context chip · project ·
method · **amount** · **refunded** · **net** · status badge · receipt icon (download) · added by ·
row actions (view, edit, approve, reject, void, refund — permission-gated, each destructive one behind
`x-ui.confirm` with a **mandatory reason**).
**Footer.** Sum of amount, refunded and net over the filtered set, plus the excluded-pending line of §6.7.2.
**Bulk approve.** Checkbox selection + sticky action bar + confirm dialog **naming the total being
approved**; explicit ids are posted; rows whose status moved are reported as skipped.
**Form.** Category (filtered to `type = expense` and the chosen context), context, project (shown only for
`software_house`), branch, title, description, `paid_to`, amount, `expense_date`, payment method, reference
(required when the method demands it), receipt upload with preview, notes. A notice states whether this
expense will need approval, and why (the threshold), before it is saved.
**Approval queue** (`admin.expenses.approvals`). The Pending tab, oldest first, with inline Approve /
Reject, the requester and the receipt thumbnail; a row the current user created is rendered with the Approve
button disabled and the tooltip "you cannot approve your own expense" (§4.5 rule 6).
**Empty state.** "No expenses in this range" + Record expense.

### 8.8 Expense detail

`x-ui.page-header` + three stat cards (amount, refunded, net) + `x-ui.tabs`: **Details** (every field, the
receipt preview, the approval trail with actor, time and reason) · **Refunds** (the append-only
`finance_reversals` list: reversal #, date, type, amount, method, reference, reason, performer, attachment)
· **Activity**. A voided expense renders the whole page in slate behind a banner naming the void reason and
linking the correcting expense.

### 8.9 Other income register and form — `admin/income/`

Same shape as §8.7 without the approval workflow. **Columns.** `income_no` · `received_on` · category ·
title · `received_from` · context · client · project · method · **amount** · **refunded** · **net** ·
status badge · receipt · row actions (view, edit, void, refund). **Filters.** Date range, category, context,
client, project, method, status, amount range, free text. A standing note on the screen: "project payments
and student fees are recorded in their own registers and appear in the income report automatically — record
them here only if they are neither".

### 8.10 Payment methods — `admin/payment-methods/index.blade.php`

Cards grouped by `PaymentMethodType` (not a table: there are few rows and each carries configuration), each
with name, `code` chip, an active toggle, a "default" badge, the contexts it is usable for, and
`requires_reference` / `supports_refund` chips. Core-looking safety: a method in use cannot be deleted and
the Delete action renders disabled with the usage count in the tooltip. The **gateway panel** appears only
for `type = gateway`: driver select, test-mode toggle, and the credential fields rendered **masked with a
"replace" toggle that never echoes the stored value** (the Phase 2 password-field pattern), plus a plain
sentence that no gateway is wired in this release and the driver is recorded for later.
**Empty state.** Cannot occur — the seeder guarantees §32 four methods; the screen still ships one for
safety.

### 8.11 Finance categories — `admin/finance-categories/index.blade.php`

Two panes side by side (Expense · Income), each a drag-to-reorder list with inline create and edit, an
active toggle, the context chip, and a usage count. Delete is disabled with a tooltip when the count is
non-zero, offering Deactivate instead. **Empty state** per pane: "No categories yet" + Add category.

### 8.12 Client panel and the public view

**`client/invoices/index.blade.php`.** `x-ui.stat-card` x 3 (Outstanding · Overdue · Paid this year),
status tabs (Outstanding · Paid · All — **never** a Draft tab), table: invoice # · `issue_date` ·
`due_date` · total · paid · balance · status badge · Download PDF. Filters: date range, status, free text on
number. Empty state: "No invoices yet". Money columns are always visible here (they are the client's own
figures) but the explicit column whitelist omits `internal_notes`, `created_by`, `project_id` cost data and
every `collaborator_*` / commission column (§9).
**`client/invoices/show.blade.php`.** The printable document plus a Payments list (receipt #, date, method,
amount) and Download PDF. No action that writes anything.
**Public view (`site.invoices.view`).** `layouts/site`, the same document partial, a prominent total and due
date, the payment instructions from the method, Download PDF, and nothing else — no login prompt carrying an
error, no other invoice reachable, and `markViewed()` called once. A 404 for a draft, a cancelled invoice, a
rotated token, an expired signature, or when `finance.invoice_public_link_enabled` is off.

### 8.13 Cross-source payments register — `admin/payments/index.blade.php`

**Purpose.** §29 one place where every rupee received is visible, whichever business it came from — the
"finance register that lists `project_payments`" the spine assigns to this phase, widened to student fees and
other income because §29 tracks them together.
**Implementation.** Three `UNION ALL` sub-selects over `project_payments`, `student_fee_payments` and
`incomes`, normalised to (source, reference, date, payer, method, amount, refunded, net, status, link),
paginated by the database; a source the user lacks permission for is **not in the union** and the screen says
so. Read-only — every write action deep-links to the owning register.
**Filters.** Date range on the value date (toggle to `recorded_at`), source, payment method, branch,
context, amount range, "has refund", free text.
**Columns.** Source chip · reference (deep link) · date · payer (client / student / payer name) · method ·
**amount** · **refunded** · **net** · status badge.
**Footer.** Per-source subtotals and a grand total, labelled with the filter. **Empty state.** "No money
received in this range".

### 8.14 The four report screens — `admin/reports/finance/`

One shared layout: `x-ui.page-header`, a `DateRange` selector (today, yesterday, this week, this month, this
year, custom — §99), the report-specific `x-ui.filter-bar`, a group-by select, an `x-ui.chart` where a shape
helps, the table, a totals row, and Print / PDF / CSV buttons (each gated by the matching `export` or `print`
ability). Every screen prints the exact basis and date column it used, and a comparison column against
`DateRange::previous()`.

| Screen | Chart | Table | Empty state |
|---|---|---|---|
| **Income** | stacked bars by month and source | group rows (month / source / category / method / project / client) with amount, share of total, previous period, change | "No income in this range" |
| **Expenses** | doughnut by category | category rows with amount, share, previous period, change; a drill-down to the expense list; the excluded-pending line | "No approved expenses in this range" |
| **Profit & loss** | a single income-vs-expense bar pair per month | the three blocks of §6.7.3 with both bottom lines, the commission memo row in slate, and the standing note about not double-recording payouts | "Nothing to report in this range" |
| **Receivables aging** | horizontal bar of the five buckets | per-client rows (outstanding, five buckets, oldest invoice, last payment, unapplied credits, net exposure) expanding to the invoice list, each invoice linking to its detail and offering Apply credit | "Nothing outstanding — every invoice is settled" |

### 8.15 Dashboard widgets registered into Phase 2 `DashboardRegistry`

`RevenueThisMonthWidget`, `ExpensesThisMonthWidget`, `ProfitThisMonthWidget`,
`OutstandingReceivablesWidget`, `OverdueInvoicesWidget`, `InvoiceStatusBreakdownWidget`,
`IncomeVsExpenseChartWidget` (12-month), `ExpensesByCategoryChartWidget`,
`PendingExpenseApprovalsWidget`. Each declares its `module()` and `permission()` (the `view_financial`
ability, not just `view_any`) so Phase 2 gating applies unchanged, and each reads **only** through
`FinanceReportService` — no widget contains its own `SUM(`. The spine already registers
`ProjectPaymentsThisMonthWidget`; it is referenced, never duplicated. These are §98 "revenue, expenses,
profit" cards for the software house.

---

## 9. Data isolation

Every rule is an Eloquent **global scope** plus a **policy** check — never a hidden form field
(`CLAUDE.md` §1.10) — and every rule has a feature test asserting both the HTTP status **and** the absence
of the forbidden columns from the response body (§112).

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating — disabling `invoices`, `expenses`, `income`, `payment_methods`, `finance_categories` or `payments` 403s those routes for Super Admin too while every row survives (Phase 1 `Gate::before`). |
| **Admin** | Unrestricted within the permissions Phase 1 §5 grants. |
| **Accountant** | Unrestricted read and write across all seven tables within the granted permissions; money columns additionally require `view_financial` (held). `ExpensePolicy::approve()` refuses an expense the same user created unless `finance.expense_self_approval_allowed` (§4.5 rule 6). |
| **Project Manager** | **No Phase 13 permission by default** — every route 403s and no finance sidebar item or project Finance tab renders. If a role is widened to `invoices.view_any` / `project_payments.view_any` / `expenses.view_any`, the global scope `ProjectManagerScope` applies to **`invoices`, `project_payments` and `expenses` alike**: `whereHas('project', fn ($q) => $q->where('project_manager_id', $user->id))` — **`projects.project_manager_id` is a `users.id`**, never an employee id (phase-06 §2.1, **D32**, F-3.11); comparing an `employees.id` here would silently match the wrong project or none. A row with `project_id IS NULL` (a non-project invoice, an overhead expense) is **invisible** to a project-scoped user, which is the same rule on all three tables (F-12.6). Money columns still require the matching `view_financial`, so "may see the claim" and "may see the amount" stay separable. |
| **Institute Manager / Course Coordinator** | No access to `invoices`, `project_payments` or the cross-source register (403) — they are software-house documents. When granted `expenses.*` / `income.*`, both are scoped to `where (context = 'institute' OR context = 'general') AND (branch_id IS NULL OR branch_id = :user_branch)` (D11), and the category select is filtered to the same contexts. |
| **Receptionist / HR / Support Agent / Developer / Designer / SEO / Marketer / Sales** | No Phase 13 permission; every route 403. A Sales Executive seeing a client does **not** see that client's invoices unless a role edit grants it. |
| **Client** | `invoices`: `where client_id = auth()->user()->client->id AND status <> 'draft' AND deleted_at IS NULL` through the global scope `BelongsToAuthenticatedClient`; route-model binding additionally asserts ownership in the policy and returns **404, not 403**, so invoice ids cannot be probed. The SELECT is an **explicit column whitelist** that omits `internal_notes`, `created_by`, `updated_by`, `branch_id`, `replaces_invoice_id`, `public_token` and every spine commission column on the joined payments. `project_payments`: the spine rule (`where client_id = …`, commission columns omitted). **Zero access** to `expenses`, `incomes`, `finance_reversals`, `payment_methods` (beyond the instructions text printed on their own invoice), `finance_categories` and every report route (403). |
| **Student / Teacher / Collaborator** | **403 on every route in this phase.** A collaborator sees money only through the spine collaborator panel; a student only their own fees (spine §9); a teacher nothing. No Phase 13 column is added to any of those panels. |
| **Branch (D11)** | When `users.branch_id` is set, `expenses`, `incomes` and `invoices` queries add `where branch_id IS NULL OR branch_id = :user_branch`; the finance reports add the same predicate and name the branch in the header. A user with no `branch_id` sees every branch. |
| **Public signed view** | No authentication, therefore the hardest scope: binding is by the 40-character random `public_token` **and** a valid, unexpired signature; `draft`, `cancelled`, soft-deleted and rotated-token invoices 404; the response is built from a **fixed** partial that can reach exactly one invoice and its own line items, with no list, no navigation into the app, no client record beyond the billing block on the document, and no write action. `throttle:30,1` caps enumeration. |
| **Every export** | Runs the same scoped query as its screen and builds its header row from the same `FinanceFieldSet`, so no export can leak a column or a row the screen withheld. |

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events (every one dispatched through `DB::afterCommit()`)

`InvoiceCreated`, `InvoiceUpdated`, `InvoiceIssued`, `InvoiceSent`, `InvoiceCancelled`,
`InvoicePartiallyPaid`, `InvoicePaid`, `InvoiceOverdue`, `InvoicePaymentApplied`,
`InvoicePaymentUnapplied`, `ExpenseRecorded`, `ExpenseUpdated`, `ExpenseApproved`, `ExpenseRejected`,
`ExpenseVoided`, `ExpenseReversed`, `IncomeRecorded`, `IncomeVoided`, `IncomeReversed`,
`PaymentMethodToggled`, `FinanceCategoryToggled`.

### 10.2 Listeners on spine events — the only coupling

| Listener | Subscribed to | Behaviour |
|---|---|---|
| `RecomputeInvoiceOnPaymentChange` | `ProjectPaymentRecorded`, `PaymentReversalRecorded`, `PaymentReversalApproved`, `PaymentReversalRejected` (all four are spine §10.1 events; the rejection leg is published by the spine per F-4.9) | when the payment carries an `invoice_id`, calls `InvoiceService::recomputeFromPayments()` inside its own transaction; a pure recompute, therefore idempotent and safe to replay. Does nothing for a student-fee payment |
| `NotifyClientOfInvoicePayment` | `ProjectPaymentRecorded` | defers entirely to the spine `ProjectPaymentReceived` notification; it only attaches the invoice context when one exists. No second notification class is created |

One listener sits outside the spine: **`RecordPayrollExpense`** on phase-07's `PayrollRunPaid` (§6.4.2,
**D44**) — the only non-spine coupling in this phase, and it writes an `expenses` row, never a payment, a
ledger row or a wallet delta.

Phase 13 subscribes to **no** commission event and publishes **no** event the engine listens to.

### 10.3 Queued jobs

| Job | Key properties |
|---|---|
| `SendInvoiceEmail` | `ShouldBeUnique` (`invoice-mail:{invoice_id}:{sha1(recipients)}`, `uniqueFor` 300), `$afterCommit = true`, `tries` 3, `backoff [30,120,300]`; renders the PDF through `InvoicePdfService`, attaches it, includes the signed link when enabled; `failed()` writes an activity row and notifies the actor — it never rolls back the issue |
| `GenerateInvoicePdf` | regenerates and stores `pdf_path` after an edit; idempotent |
| `BuildFinanceReportExport` | for results above `finance.report_sync_row_limit`; carries the **scoped** query definition and the requesting user so the export can never be wider than what that user may see; the file is delivered by notification and expires after 7 days |

### 10.4 Notifications (database channel, mail where stated — §97)

| To | Notification |
|---|---|
| Client | `InvoiceSentToClient` (mail + database, PDF attached, signed link), `InvoiceOverdueReminder` (mail, only when `finance.invoice_reminders_enabled`) |
| Holders of `invoices.view_any` | `InvoiceOverdueDigest` (one daily digest, never one per invoice) |
| Holders of `expenses.approve` | `ExpenseAwaitingApproval` |
| The expense creator | `ExpenseApproved`, `ExpenseRejected` (with the reason) |
| Holders of `payment_reversals.approve` | `RefundAwaitingApproval` — the spine notification, reused, never redefined |

### 10.5 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `invoices:mark-overdue` | daily 01:05 | recompute the status of every invoice `status IN (sent, partial, overdue)`, chunked 500 — so the clock can move a row into `overdue` **and** an extended `due_date` can move it back out; fires `InvoiceOverdue` only on the forward transition |
| `invoices:reconcile-balances` | daily 02:10 | assert `paid_amount` / `refunded_amount` / `balance_amount` equal the canonical SQL of §2.8 for every non-draft invoice. Reports drift, writes an activity row and notifies holders of `invoices.view_any`; it **does not silently repair** (the spine §6.5.4 discipline) — `--repair` rewrites the cache and records before and after |
| `invoices:send-reminders` | daily 09:15 | only when `finance.invoice_reminders_enabled`; one reminder per offset in `finance.invoice_reminder_days_after_due`, never twice for the same offset |
| `expenses:flag-stale-approvals` | weekly Monday 08:00 | list expenses `pending` for more than 14 days to holders of `expenses.approve`; it never auto-approves and never auto-rejects money |

No Phase 13 command touches a payment, a reversal, a ledger row or a wallet.

---

## 11. Acceptance tests

`tests/Feature/Finance/`. Money assertions compare **strings** (`'1000.00'`), never floats. Every test that
records or refunds a project payment also calls the spine helper `assertWalletMatchesLedger($collaborator)`,
so Phase 13 can never be the reason the commission spine drifts.

### 11.1 Invoice totals, tax, discount, rounding

1. `test_line_total_is_quantity_times_rate_quantised_per_line` — qty `1.5000` × rate `3333.33` gives
   `gross_amount = '5000.00'`; the invoice `subtotal_amount` equals the sum of the line `gross_amount`
   values, never a re-multiplied figure.
2. `test_line_discount_modes` — percentage `10.0000` on `10000.00` gives `'1000.00'`; a fixed discount larger
   than the line is clamped to the line; `discount_mode = none` forces both discount columns to NULL and
   `chk_ii_discount_payload` rejects a hand-made row with a rate and a fixed amount together.
3. `test_invoice_level_discount_is_apportioned_exactly` — `D = 1000.00` over three net lines of
   `3333.33 / 3333.33 / 3333.34` yields allocations summing to **exactly** `'1000.00'` with the residual on
   the last line; `SUM(allocated_discount_amount) == discount_amount` (the §2.7 identity).
4. `test_tax_is_exclusive_per_line_after_both_discounts` — a taxable line and an exempt line on one invoice:
   the exempt line has `tax_amount = '0.00'` and `taxable_amount = '0.00'` and is unaffected by the invoice
   discount given on the taxable line; `tax_amount` equals `Money::percentage(taxable_amount, tax_rate)`.
5. `test_tax_disabled_produces_no_tax` — with `finance.tax_enabled = false` every new line is
   `is_taxable = false`, `tax_amount = '0.00'`, and the PDF contains no tax row.
6. `test_the_five_invoice_identities_hold_after_every_recalculation` — the five identities of §2.7 asserted
   across a 20-line fixture with mixed discounts, exempt lines and a negative round-off.
7. `test_changing_tax_settings_does_not_change_an_existing_invoice` — flip `tax_label`,
   `default_tax_rate` and `payment_terms_days` after issue: every stored column of the existing invoice is
   byte-identical and the re-rendered PDF still shows the old label and rate.
8. `test_round_off_is_signed_and_opt_in` — with rounding off, `round_off_amount = '0.00'` and
   `total_amount` equals the unrounded sum; with precision `1`, a `pre_round` of `12345.40` gives
   `total_amount = '12345.00'` and `round_off_amount = '-0.40'`, and the §2.7 identity still holds.
9. `test_money_never_touches_a_float` — a static scan of `app/Services/Finance` and the four finance models
   finds no `+ - * /` applied to a money attribute or a `decimal` cast value (the spine FT-41 pattern).
10. `test_database_checks_reject_bad_rows` — each of `chk_inv_discount_ceiling`, `chk_inv_issued_number`,
    `chk_inv_dates`, `chk_inv_cancel_reason`, `chk_ii_quantity`, `chk_ii_exempt`, `chk_exp_amount`,
    `chk_exp_refund_ceiling`, `chk_fr_one_target` rejects a raw INSERT or UPDATE that breaches it; every
    generated column (`total_discount_amount`, `expenses.net_amount`, `incomes.net_amount`,
    `payment_methods.default_guard`) and the `trg_fr_no_delete` trigger is asserted to exist, so a silently
    dropped constraint fails CI rather than production.

### 11.2 Numbering, status and lifecycle

11. `test_draft_consumes_no_number_and_issue_assigns_one` — a draft has `invoice_number = NULL` and renders
    `DRAFT-{id}`; deleting it leaves `finance.invoice_next_number` untouched; issuing assigns the next
    number, increments the counter by exactly one and stamps `issued_at` / `issued_by`.
12. `test_concurrent_issue_produces_two_distinct_gapless_numbers` — two processes issuing behind a latch get
    `INV-000001` and `INV-000002`, never a duplicate and never a skipped value; a forced `1062` is absorbed
    by exactly one retry.
13. `test_cancelled_invoice_keeps_its_number_and_it_is_never_reused` — cancel, then issue another invoice:
    the new number is the next value, the cancelled document keeps its own for ever, and `replace()` links
    the replacement through `replaces_invoice_id`.
14. `test_status_derivation_covers_all_six_cases` — the table of §2.8 asserted case by case, including
    overdue beating partial, an overpayment landing on `paid` with a negative `balance_amount`, and a refund
    returning a past-due invoice to `overdue` rather than `sent`.
15. `test_zero_total_invoice_cannot_be_issued` — an invoice with no line, or with lines summing to `0.00`,
    is refused by `issue()` with a named validation error and stays a draft.
16. `test_overdue_job_moves_statuses_in_both_directions` — `invoices:mark-overdue` moves a past-due `sent`
    invoice to `overdue` and fires `InvoiceOverdue` once; extending `due_date` and re-running moves it back
    to `sent` and fires nothing.
17. `test_issued_invoice_edit_rules` — with `finance.invoice_allow_edit_after_issue = true` an issued,
    unpaid invoice may be edited (same number, one activity row carrying old and new `total_amount`,
    `pdf_path` cleared); once a payment is linked the edit is refused; with the setting false the edit is
    refused immediately after issue.
18. `test_cancel_is_refused_while_paid_and_requires_a_reason` — cancel without a reason fails validation;
    cancel with `paid_amount > 0` is refused naming the refund path; cancelling an unpaid invoice stamps
    `cancelled_at/by/reason` and deletes nothing.
19. `test_only_an_unissued_draft_can_be_deleted` — `destroy` on an issued invoice, on a draft with a linked
    payment, and on a cancelled invoice each 403/422; deleting an empty draft soft-deletes it and cascades
    its items.

### 11.3 Payments, allocation and the commission boundary

20. `test_recording_a_payment_against_an_invoice_updates_the_cache_and_fires_the_commission_trigger` —
    posting `admin.invoices.payments.store` creates exactly one `project_payments` row with `invoice_id`
    set, the invoice moves to `partial` with `paid_amount` from the canonical SQL, and
    `ProcessProjectPaymentCommission` is dispatched **once, after commit**; with a referred project and a
    15% rule the resulting ledger credit is `'15000.00'` on a `100000.00` payment (spine §120.7 reached
    through a Phase 13 screen).
21. `test_paid_amount_is_a_cache_of_the_canonical_sql` — after two payments, a partial refund and a void,
    `paid_amount`, `refunded_amount` and `balance_amount` equal the §2.8 SQL to the paisa; a deliberately
    corrupted `paid_amount` is detected by `invoices:reconcile-balances`, reported, **not** silently
    repaired, and fixed only by `--repair`.
22. `test_a_payment_is_never_split_across_invoices` — the Form Request rejects two `invoice_id` values;
    `applyPayment()` on a payment already linked affects zero rows and fails with a named error.
23. `test_apply_and_unapply_an_advance` — an advance (`invoice_id IS NULL`, `is_advance = true`) appears in
    the client unapplied-credits list, applies with a mandatory reason (one activity row with old `null` and
    the new id), moves the invoice to `partial`, and unapplies back with another audited act; `is_advance`
    is never rewritten.
24. `test_invoice_linkage_has_no_commission_effect` — the full ledger for the collaborator is byte-identical
    before and after an apply / unapply / re-apply cycle, and `assertWalletMatchesLedger()` passes.
25. `test_overpayment_is_accepted_and_visible` — a receipt larger than the balance posts in full, the invoice
    reads `paid` with a negative `balance_amount`, and the aging report shows the credit instead of hiding
    it.
26. `test_refund_of_an_invoiced_payment_flows_through_the_spine` — refunding through the spine route inserts
    one `payment_reversals` row, leaves the original receipt byte-identical, dispatches
    `ProcessCommissionReversal`, produces a **negative** ledger row referencing the original, and the
    listener returns the invoice to `partial` / `overdue`; nothing is deleted anywhere and
    `assertWalletMatchesLedger()` passes.
27. `test_refund_awaiting_approval_defers_the_commission_reversal` — with `finance.refund_approval_required`
    on, the reversal is `pending`, no ledger debit exists yet, the invoice cache already reflects the
    refund, approval posts the debit, and a rejection rolls `refunded_amount` back and restores the invoice
    cache.
28. `test_voiding_a_payment_removes_it_from_the_invoice_without_deleting_it` — the voided row still carries
    its `invoice_id`, is excluded from `paid_amount`, and the re-entered receipt is a separate row, leaving
    three rows and one reversed commission (the spine FT-35 shape, seen from the invoice).

### 11.4 Expenses, income and reversals

29. `test_expense_approval_workflow` — with `expense_approval_required` on, a new expense is `pending` with
    `approval_required = true` and is **excluded from every report**; approval includes it; rejection
    without a reason fails validation and with one is terminal; approving twice is a no-op; a bulk approve
    with one stale id reports that row as skipped and approves the rest.
30. `test_approval_threshold_is_snapshotted` — an expense under `finance.expense_approval_threshold` is born
    `approved` with `approval_required = false`; raising the threshold afterwards changes neither row.
31. `test_self_approval_is_refused` — the creator cannot approve their own expense (403) until
    `finance.expense_self_approval_allowed` is true.
32. `test_expense_void_and_refund_never_delete` — `void` on an approved expense sets `voided` with a
    mandatory reason and drops it from the report totals; `delete()` on an approved expense is refused by
    the policy; a partial vendor refund inserts a `finance_reversals` row, raises `refunded_amount`, and
    `net_amount` (generated) follows automatically; a refund exceeding the remainder is refused by the
    service **and** by `chk_exp_refund_ceiling`; a raw `DELETE` on `finance_reversals` raises
    SQLSTATE 45000 and the model `deleting` hook throws first.
33. `test_expense_idempotency` — the same `idempotency_key` posted twice creates one row and the second call
    returns `created: false`; the same for `incomes`.
34. `test_other_income_does_not_double_count` — a project payment, a student fee payment and an `incomes`
    row in the same month appear exactly once each in the income report, with the project payment absent
    from the `incomes` table and the fee broken out by `fee_type`.

### 11.5 Reports and exports

35. `test_income_report_sources_and_totals` — the three-source total equals `Money::sum()` of the three
    independent SQL sums; a voided payment and a voided income row are excluded; `net_received_amount`
    makes a partial refund reduce the figure without a second code path;
    `finance.reports_include_institute = false` drops the fee source and the institute rows.
36. `test_expense_report_excludes_non_approved_and_states_it` — `pending`, `rejected` and `voided` rows are
    absent from the totals and the excluded-pending line names their count and amount.
37. `test_profit_and_loss_blocks_and_bottom_lines` — `A - B` and `A - B - C` both asserted; block C comes
    from `CollaboratorWalletService::payoutsPaidTotal()` and the memo from
    `CollaboratorStatementService::commissionAccruedTotal()`; a test asserts the report class contains no
    `SUM(` over the ledger, the allocations or the payouts (the spine FT-42 rule).
38. `test_receivables_aging_buckets_and_credits` — invoices at 0, 15, 45, 75 and 120 days overdue land in
    `current`, `d1_30`, `d31_60`, `d61_90`, `d90_plus`; drafts and cancelled invoices never appear; a
    client's unapplied credits reduce `net_exposure`; the bucket sums equal the outstanding total.
39. `test_report_date_filters` — each of §99 six ranges plus a custom range returns the rows whose **value
    date** falls inside it, the comparison column uses `DateRange::previous()`, and switching to
    `recorded_at` changes the set for a back-dated receipt and says so on screen.
40. `test_exports_match_the_screen` — print, PDF and CSV totals equal the screen to the paisa for all four
    reports; a result above `finance.report_sync_row_limit` is queued instead of streamed and the delivered
    file carries the **requesting user** scope.

### 11.6 Delivery, PDF and the client-facing view

41. `test_sending_a_draft_issues_it_first` — sending a draft assigns the number inside the same transaction
    and queues one `SendInvoiceEmail` after commit with the PDF attached; `sent_at`, `sent_count`,
    `last_sent_to` are stamped and one activity row names the recipients; a mailer failure surfaces the real
    message and leaves the invoice issued and re-sendable.
42. `test_pdf_reproduces_a_historic_invoice` — after changing `tax_label`, `bank_details` and
    `invoice_footer_note`, re-rendering an old invoice still shows the snapshots; `internal_notes` never
    appears in the PDF, the print view, the client panel or the public view.
43. `test_public_invoice_link_security` — a valid signed link renders the document and sets `viewed_at`
    once; an expired signature, a tampered signature, a rotated token, a draft, a cancelled invoice, a
    soft-deleted invoice and `finance.invoice_public_link_enabled = false` each return 404; the route is
    throttled; the page exposes no other invoice and no write action.

### 11.7 Authorization and isolation (each asserts the status **and** that nothing was written)

44. `test_authorization_matrix` — without `invoices.create` the store 403s; without `invoices.change_status`
    issue, send, cancel and rotate-link 403; without `invoices.delete` the destroy 403s; without
    `expenses.approve` approve **and** `bulk-approve` with forged ids 403; without `expenses.change_status`
    void and refund 403; without `income.change_status` void 403; without `payment_methods.edit` the
    gateway config 403s; without `project_payments.create` the invoice payment route 403s. In every case the
    row count before and after is identical.
45. `test_view_financial_withholds_money_columns` — a user with `invoices.view_any` but not
    `invoices.view_financial` gets 200 on the register with no total / paid / balance column and no stat
    card, and the figures are **absent from the HTML, the CSV and the JSON** — not blanked; the same matrix
    for `expenses`, `income` and `payments`.
46. `test_project_manager_cannot_see_finance` — a PM with the Phase 1 role gets 403 on every Phase 13 route,
    no finance sidebar item and no project Finance tab; after being granted `project_payments.view_any` +
    `view_financial` they see only their own projects' payments, and an invoice with `project_id IS NULL` is
    invisible.
47. `test_client_isolation` — a client sees only their own non-draft invoices; another client's invoice id
    returns **404**; id enumeration across `client.invoices.show`, `.pdf` and `client.payments.*` returns
    404 throughout; the body contains no `internal_notes`, no `created_by`, no `public_token` and no
    commission column.
48. `test_student_teacher_collaborator_are_locked_out` — each of the three roles gets 403 on every invoice,
    expense, income, payment-method, category, payments-register and finance-report route.
49. `test_branch_scoping` — a user with `branch_id` set sees only their branch plus `branch_id IS NULL` rows
    in the expense, income and invoice lists **and** in all four reports, and the report header names the
    branch.
50. `test_institute_manager_expense_scope` — granted `expenses.*`, an Institute Manager sees only
    `context IN (institute, general)` within their branch, cannot choose a `software_house` category, and
    403s on every invoice and project-payment route.
51. `test_module_gating_preserves_data` — disabling `invoices`, `expenses`, `income`, `payment_methods`,
    `finance_categories` and `payments` 403s their routes for Super Admin too; row counts before and after
    disable + re-enable are identical; queued `SendInvoiceEmail` jobs still complete.
52. `test_migrations_roll_back_cleanly` — every Phase 13 migration runs forward and back on a database
    holding rows (generated columns, CHECK constraints and the delete trigger dropped in the right order);
    `migrate:fresh --seed` is clean; the guarded `add_finance_foreign_keys_to_payment_tables` migration is a
    no-op when `project_payments` is absent and adds the three FKs exactly once when it is present, and is
    safe to run twice.

---

## 12. Risks and open questions

### 12.1 Risks accepted, with the mitigation

| # | Risk | Mitigation / why it is accepted |
|---|---|---|
| R-1 | **`invoices.paid_amount` is a cache, and a cache can drift.** A developer who writes `$invoice->increment('paid_amount')` breaks the one fact a client argues about. | One canonical SQL (§2.8), one writer (`recomputePaid()`), a nightly `invoices:reconcile-balances` that reports rather than hides drift, and tests 21 and 45. The alternative — summing payments in every query — is slower and still needs the same definition in one place. |
| R-2 | **Phase 13 writes a spine column** (`project_payments.invoice_id`) on apply / unapply, which the spine otherwise treats as append-only (INV-8). | It is a one-way, conditional, audited UPDATE of a **document link**, never of a money column, never of a value date, and with zero commission effect (test 24). The concession is **granted and numbered: D43** — the single exception to INV-8, by `InvoiceService` alone, only while the payment is not `voided`, reason mandatory and audited, gated by `project_payments.edit` + `invoices.edit`. Without it, an advance received before an invoice exists could never be attached, which is a real and common business event; nobody may later "tighten" INV-8 back over it. |
| R-3 | **"Overdue" is a derived status stored in a column.** A status column plus a clock means a row can be stale between midnight and the 01:05 job. | `recomputeStatus()` is a pure function re-run on every money write, by the job in both directions, and by the reconcile command; every screen that matters (aging, register) also filters on `due_date`, so a stale status can delay a badge but never a figure. |
| R-4 | **Commission could be double counted in the P&L** if an accountant also records a collaborator payout as an expense. | Commission is structurally absent from `expenses`; it reaches the P&L only through block C read from the spine services (§6.7.3), labelled "Collaborator commission (cost)", and the screen carries the standing note. A hard block would need a reserved category the requirement never asks for; raised as Q6. **Salaries are the mirror risk and are closed differently:** the one `approved` expense per paid payroll run (§6.4.2, **D44**) is unique on (`source_type`, `source_id`) and lands in the reserved, undeletable `salaries` category, so payroll appears in block B **exactly once** and is never both derived and hand-typed. |
| R-5 | **The income report is cash-basis**, so a business expecting accrual revenue (invoiced, not yet collected) will read a lower number than their accountant does. | Stated on every screen in `meta`, receivables aging carries the accrual view, and Q1 asks the client. Changing basis later is a new report, not a redesign — the sources and date columns are already named. |
| R-6 | **The signed public invoice link is an unauthenticated door.** | 40 random characters **plus** a signature with an expiry, rotation, a 404 for every non-deliverable state, throttling, a fixed single-document partial with no navigation and no write action, and test 43. It exists because D2 allows a client with no login, and emailing an invoice only a logged-in client can open is not a deliverable invoice. |
| R-7 | **A single `payment_methods` row carries encrypted gateway credentials** in a system with no gateway yet, which is a secret stored for no current benefit. | The column is nullable and empty until someone fills it; it is never rendered and never logged; `ManualGateway` is the only driver. The alternative (add the column later) would mean a migration on a live finance table. |
| R-8 | **One shared reversal-number series** across `payment_reversals` and `finance_reversals` means each table's own numbers have gaps. | Intended: the business gets one reversal-voucher sequence an auditor can follow, and `DocumentNumberService` already serialises on the counter row. Each table keeps its own UNIQUE index, so no number exists twice. |
| R-9 | **`invoice_items` has no soft deletes.** | Not a deviation: it is the **D19** category rule (append-only children and history pivots carry no `deleted_at`; `CLAUDE.md` §3 names `invoice_items` explicitly). A line is part of its parent document; only a never-issued draft can lose a line, and the parent's soft delete preserves the whole document. A nullable `deleted_at` would add a predicate every totals query must remember. `finance_reversals` additionally cites **D16** (Q3). |
| R-10 | **The cross-source payments register is a `UNION ALL` over three large tables** and will be the slowest screen in the phase. | Every branch of the union is driven by an existing index on its value date, the page is paginated by the database, the default range is the current month, and the sources a user cannot see are not in the query at all. If it outgrows that, the fix is a reporting view — additive, no definition change. |
| R-11 | **No multi-currency** (spine R-12). A foreign-currency client must be converted before invoicing. | `invoices.currency` is a constant column so a future split is additive rather than archaeological. |
| R-12 | **Excel export is absent** although §99 says "CSV/Excel". | Print, PDF and CSV ship here; `ExportFormat` has no `excel` case until Phase 23 installs the package, so the gap is visible in code rather than promised in a UI button that fails. |

### 12.2 Open questions for the client (defaults assumed, nothing blocked)

| # | Question | Default assumed |
|---|---|---|
| Q1 | Are the income report and the P&L **cash-basis** (money received) or **accrual** (invoiced)? | **Cash**, with receivables aging carrying the accrual view. §29 lists money received, so cash is the faithful reading. |
| Q2 | Is tax **exclusive** only, one rate per line, no withholding and no compound tax? | Yes — §31 names one tax. An inclusive mode would be an additive setting plus one branch in §2.7 step 8. |
| Q3 | Does the client accept that **`invoice_items` and `finance_reversals` omit `deleted_at`**? | **Answered: yes, and it needs no new decision** — this phase **cites D19**, the soft-delete category rule whose table in `CLAUDE.md` §3 already covers both ("append-only children and history pivots" / "money returned"); `finance_reversals`, as money history, additionally cites **D16**. |
| Q4 | Should the invoice number **reset yearly** (`INV-2026-000001`)? | No reset: one continuous series; a year can be encoded in `finance.invoice_prefix` by the admin with no code change. |
| Q5 | A **cancelled issued invoice keeps its number for ever** and a replacement is a new number — correct? | Yes. Reusing a number makes two documents answer to one reference in a dispute. |
| Q6 | Should recording a collaborator payout as an **expense** be hard-blocked, to protect the P&L from double counting? | Not blocked: a standing note on the P&L plus block C. A hard block needs a reserved category the requirement does not describe. |
| Q7 | May an **issued, unpaid** invoice be edited (same number, full audit), or must every correction be cancel + replace? | Editable (`finance.invoice_allow_edit_after_issue = true`); once any payment is linked, editing is refused regardless. |
| Q8 | Should the **signed public invoice link** be enabled, and for how long? | Enabled, 30 days, rotatable. Turn it off with one setting if the client wants panel-only access. |
| Q9 | Should **overdue reminder emails** go out automatically? | No — `finance.invoice_reminders_enabled = false`. The system never mails a client unasked; reminders are manual until the client says otherwise. |
| Q10 | Is a **receipt mandatory** above an expense threshold? | Off by default (`finance.expense_receipt_required = false`); the threshold exists for the day they say yes. |
| Q11 | Should a single client payment be splittable **across several invoices**? | No — one receipt, one invoice (the spine schema). Two invoices paid by one transfer are receipted twice. Splitting would need an allocation pivot and would contradict spine §1.3. |
| Q12 | Should **closed months be lockable** so a back-dated receipt cannot rewrite an issued P&L? | Not in this phase — the spine `finance.backdate_limit_days` (30) plus the `approve` ability plus `recorded_at`. The spine offers a `financial_periods` table to Phase 13 if the client wants hard locks ([D-FS-6], spine Q6); it is deliberately **not** taken up here because the requirement never asks for it. |

---

## 13. Requests to other phases

### 13.1 Columns and behaviour Phase 13 needs

| Request | Why |
|---|---|
| **`project_payments.invoice_id` settable NULL → value → NULL by `InvoiceService` alone — GRANTED as D43**: the spine INV-8 lifecycle whitelist is now `notes` / `reference_no` / `receipt_path` / **`invoice_id`**, with four guards (only `InvoiceService`; only while the payment is not `voided`; reason mandatory and audited per §107; gated by `project_payments.edit` **and** `invoices.edit`) and zero commission effect | §6.3 rules 4 and 5. It is a document link, not money. Without this concession an advance received before its invoice existed could never be attached, and `invoices.paid_amount` could never be right. It is the **single** concession to INV-8 and no other spine money column moves |
| **`PaymentReversalRejected` event — satisfied**: the spine §10.1 and phase-10-12 §10.1 now publish it (`afterCommit`), together with the listener that rolls `refunded_amount` back (F-4.9) | §10.2: when an approver rejects a refund the invoice caches must follow in the same request; `RecomputeInvoiceOnPaymentChange` now has all four legs and recomputes on this one too |
| `projects.client_id` (Phase 6) | the Form Request asserts `invoice.client_id = project.client_id`, so an invoice can never bill the wrong client for a project |
| `projects.project_manager_id` — **a `users.id`** (Phase 6 §2.1, D32) | the `ProjectManagerScope` of §9 compares it to `$user->id`; Phase 13 never translates it through `employees` (F-3.11) |
| **`project_milestones.name`**, **`project_milestones.amount` decimal(15,2)** (Phase 6 — already requested by the spine §13.1) | "insert line from milestone" in the builder, and `invoice_items.project_milestone_id`. The column is `name`: phase-06 §2.4 never had a `title` (F-3.10) |
| `clients.id`, `.name`, `.company`, `.email`, `.phone`, `.address`, `.tax_number` / NTN, `.user_id` nullable (Phase 5); a client with an invoice must not be force-deletable | the invoice billing block, the email recipient default, `restrictOnDelete` on `invoices.client_id`, and the D2 case of a client with no login that makes the signed link necessary |
| **`CollaboratorWalletService::payoutsPaidTotal()` and `CollaboratorStatementService::commissionAccruedTotal()` — published by the spine** (F-4.8, spine §6.2 / phase-10-12 §6.3), both derived from the spine §6.5.1 canonical SQL. The canonical signatures take a collaborator first: `payoutsPaidTotal(Collaborator $c, ?DateRange $r = null): string` / `commissionAccruedTotal(Collaborator $c, ?DateRange $r = null): string`. **Still open:** P&L block C and its memo are **company-wide** period totals, so the spine must also publish the all-collaborator form (a nullable `Collaborator`, or a sibling method) | P&L block C and the commission memo (§6.7.3). Phase 13 must never sum payouts, allocations or ledger rows itself (spine INV-26, FT-42), so it cannot close the company-wide gap locally — it is named here rather than guessed |
| `App\Support\Money` — **satisfied by phase-01 §3** (F-4.11), whose canonical surface already includes **`roundTo($amount, int $nearest)`** (half-up to the nearest 1 / 5 / 10) and a `mul()` that accepts a 4-dp quantity, bcmath only, intermediate scale 6, final half-up at 2, strings in and out | §2.7 steps 1 and 11; this phase adds no `Money` method of its own |
| `student_fees.fee_type` + `student_fee_payments` read access (Phases 10/18) | the income report fee breakdown (§6.7.1) |
| `App\Support\PermissionRegistry` (Phase 1): the one module slug of §4.1, the ability additions of §4.2 — including **`view_reports` on the spine slugs `project_payments` and `student_fee_payments`** — and `client_portal.invoices` | the registry is the only place permission names exist (D4) |
| `App\Support\SettingsRegistry` (Phase 2): the 20 `finance` keys of §5 | settings definitions in code, values in the DB |
| `App\Support\DashboardRegistry` (Phase 2): the nine widgets of §8.15 | §98 revenue / expenses / profit cards |
| `composer require barryvdh/laravel-dompdf` (already planned for Phase 13 in `DEVELOPMENT_LOG.md` §2) | invoice and report PDFs |

### 13.2 Behaviour other phases must respect

| Request | Why |
|---|---|
| **Phase 6**: the project detail page renders its Finance tab only when the viewer holds `invoices.view_any` or `project_payments.view_any`, and reads every figure from `FinanceReportService` / `InvoiceService` — never its own `SUM()` | §4.5 rules 1 and 2; a project screen is the most likely place a PM accidentally gains sight of money |
| **Phase 15/18**: the institute never writes to `invoices`, `expenses` or `incomes`; a student fee is a `student_fees` charge, and an institute cost is an `expenses` row with `context = institute` | one fact, one table (§109) |
| **Phase 22** (notifications): the six notification classes of §10.4 | §97 |
| **Phase 23** (reports): every finance report, export and dashboard figure calls `FinanceReportService`; none re-implements a sum, and the Excel exporter adds an `excel` case to `ExportFormat` rather than a parallel path | §99, test 37 |
| **Phase 7** (HR): `PayrollRunPaid` stays an `afterCommit` event carrying the run and its net paid total, because §6.4.2's listener is the only path by which salaries reach §99's P&L (**D44**). Phase 7 creates no `expenses` row itself | F-3.9; one derived row, sourced from the run |
| **Phase 24** (security / integrity): run tests 10, 12, 21, 26, 43, 45 and 47 as part of the hardening pass; every FK index named in the `Keys` blocks of §2 has a row in `tests/Support/index-manifest.php`, and `expenses.receipt_path`, `incomes.receipt_path`, `finance_reversals.attachment_path` and `invoices.pdf_path` each have an `upload-manifest.php` row naming the **private `local`** disk and the streaming permission | §110, §111, §112; F-9.2, F-12.5 (**D21**, **D60**) |

### 13.3 Documentation and log updates

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4 — **D40**: `invoices.paid_amount` / `refunded_amount` / `balance_amount` are a cache of one canonical SQL derived from `project_payments`; nothing may increment them | spine §1.3, R-1 |
| `DEVELOPMENT_LOG.md` §4 — **D41**: `finance_reversals` is the append-only expense / other-income counterpart of the spine `payment_reversals`; the two are never merged and never confused, and neither is ever deleted | §2.6 |
| `DEVELOPMENT_LOG.md` §4 — **D42**: an invoice number is assigned once at issue, never to a draft and never reused; a cancelled invoice keeps its number | §2.9 |
| `DEVELOPMENT_LOG.md` §4 — **D43**: the single concession to spine INV-8 — `project_payments.invoice_id` may move NULL → value → NULL, by `InvoiceService` alone, reason mandatory, audited, gated by `project_payments.edit` + `invoices.edit`, with zero commission effect | §6.3 rules 4-5, R-2 |
| `DEVELOPMENT_LOG.md` §4 — **D44**: one `approved` expense row per **paid** payroll run (`expenses.source_type` / `source_id`, unique), written by `RecordPayrollExpense` on phase-07's `PayrollRunPaid`, so §99's P&L includes salaries exactly once | §2.6, §6.4.2, §6.7.3 |
| This phase **cites D19** for the two tables without `deleted_at` (and **D16** for `finance_reversals` as money history) and **D32** for `projects.project_manager_id` being a `users.id`; it claims no number of its own. All five numbers above are already allocated in `docs/design/resolutions.md` §4 — the owner pastes them into the log | F-9.1, F-3.11, F-10.1 |
| `CLAUDE.md` §5 — add "a finance document number is assigned inside the transaction that issues the document, through `DocumentNumberService`, and is never reused" and "money columns are gated by `view_financial` and a withheld column is absent from the response, never blank" | §2.9, §4.5 |
| `DEVELOPMENT_LOG.md` §9 — record Q1 (cash basis) and Q11 (one receipt, one invoice) as assumed defaults | §12.2 |

---

## Convergence log (2026-09-12)

Applied from [`../design/resolutions.md`](../design/resolutions.md) §3 / §7 (apply-map row
`docs/phases/phase-13.md`). Nothing else in this contract changed. Where this log and the
[financial spine](../design/finance-commission-spine.md) could be read as disagreeing, the spine wins.

| Finding | Change made |
|---|---|
| F-3.9 | **D44 built.** §2.6 `expenses` gains `source_type` string(32) nullable + `source_id` unsignedBigInteger nullable, `UNIQUE uq_exp_source(source_type, source_id)` and `INDEX (source_type, source_id)`; new **§6.4.2** defines the listener `RecordPayrollExpense` on phase-07's `PayrollRunPaid` (one `approved` expense per paid run, `context = general`, reserved `salaries` category, amount = the run's net paid total via `Money`, 1062 = already posted); §2.3 makes the `salaries` category reserved and undeletable; §6.7.3 block B gains the salary line; §12.1 R-4 and §10.2 record the seam; §13.2 adds the Phase 7 row. |
| F-3.10 | `project_milestones.title` → **`project_milestones.name`** in all three places: §1.2 dependency row, §8.2 "insert from milestone", §13.1 request row. |
| F-3.11 | §9 `ProjectManagerScope` compares `projects.project_manager_id` to **`$user->id`** (a `users.id`, D32), not `$user->employee_id`; §1.2 and §13.1 say so too. |
| F-4.2 | §6.5 step 2 now states the **one DTO form** `refund(StudentFeePayment\|ProjectPayment $p, RefundData $data): PaymentReversal` with a named-argument example; no positional form of the call exists. |
| F-4.8 | §6.7.3 and §13.1 cite the spine's published `payoutsPaidTotal()` / `commissionAccruedTotal()`. **Partially deferred:** the canonical signatures are per-collaborator while P&L block C and its memo are company-wide, so the all-collaborator form is recorded as an open request instead of being guessed — Phase 13 still sums nothing itself (INV-26). |
| F-4.9 | `PaymentReversalRejected` marked **satisfied** in §13.1; §10.2 no longer calls it "requested" — all four legs are spine events and the invoice caches recompute on the rejection. |
| F-4.10 | **D43 cited** in §6.3 rules 4 and 5, §12.1 R-2 and §13.1 (granted, not requested), with the four guards spelled out. The permission pair on apply / unapply is now the pair D43 names — `invoices.edit` **and** `project_payments.edit` — replacing `project_payments.change_status` in §6.3 and in the two §7.1 routes. |
| F-4.11 | §13.1's `Money` request replaced with "**satisfied by phase-01 §3**"; `roundTo()` and the 4-dp `mul()` are part of that canonical surface. |
| F-4.14 | §6.9 ships the three artefacts four later phases assume: `App\Support\ReportResult` (readonly `{rows, groups, totals, meta}`), `App\Services\Reporting\ReportExporter::export(ReportResult, ExportFormat): StreamedResponse` (always streamed, never `->get()`), and `resources/views/layouts/print.blade.php`; §6.7 states that `FinanceReportService::report()` returns the DTO and §6.7.5 uses the canonical signature. |
| F-6.1 | §4.2's `payments` row restated: `payments` is the umbrella **only** for the cross-source register of §7.5 / §8.13; every project-payment route, admin and client, carries `module:project_payments`. `project_payments.view_reports` stays on the `project_payments` slug. |
| F-9.1 | §2's preamble, §12.1 R-9 and §12.2 Q3 now **cite D19** (the soft-delete category rule in `CLAUDE.md` §3) for `invoice_items` and `finance_reversals`, with **D16** additionally cited for `finance_reversals` as money history. No local decision number is claimed. |
| F-9.2 | §2.4 Keys add `INDEX` on `replaces_invoice_id`, `payment_method_id`, `issued_by`, `cancelled_by`; §2.6 Keys add `approved_by`, `rejected_by`, `voided_by`, `corrects_expense_id`, `payment_method_id`; §13.2 requires a row in `tests/Support/index-manifest.php` for each. |
| F-12.3 | §7.7's three client routes each carry **`client.context`** (**D31**), and the section records that they are appended to Phase 5's `routes/client.php` through `ClientPortalRegistry` — Phase 13 redeclares no `client.*` name. |
| F-12.5 | **D21 applied.** `expenses.receipt_path` and `invoices.pdf_path` move to the private **`local`** disk (`expenses/`, `invoices/`), streamed only by `admin.expenses.receipt` / `admin.invoices.pdf` (and the client / signed invoice routes); §6.8 `InvoicePdfService::store()` no longer writes to `public`; a new §2.6 paragraph puts `incomes.receipt_path` and `finance_reversals.attachment_path` on the same private footing, with `upload-manifest.php` rows requested in §13.2. |
| F-12.6 | §9's `ProjectManagerScope` now covers **`expenses`** as well as `invoices` and `project_payments`, with `project_id IS NULL` rows invisible to a project-scoped user. |
| F-13.3 | §6.7.3 block C is labelled **"Collaborator commission (cost)"** with the legend line "payments *to* collaborators; §29's phrase is a cost, never income"; no schema change. |
| F-10.1 (§4.2) | §13.3 renumbered: **D40** (paid-amount cache), **D41** (`finance_reversals`), **D42** (invoice numbering), plus new rows for **D43** and **D44**, and a closing row stating that this phase cites D19, D16 and D32 and invents no number. |
