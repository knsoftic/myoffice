# PHASE 10-12 CONTRACT - Student commission engine, project commission engine, wallet / ledger / payouts

**Read [`../design/finance-commission-spine.md`](../design/finance-commission-spine.md) first and in full.**
That document is the authoritative financial design and owns the schema, the enums, the invariants
(INV-1..INV-26), the calculation algorithm, the reversal and payout models and the reconciliation proof.
This contract is the **implementation plan** for phases 10, 11 and 12 on top of it: wiring, order of
operations, routes, screens, notifications, jobs and tests. Where this document and the spine appear to
disagree, **the spine wins** and the disagreement is recorded in §12.2 as an open question.

It creates **no table and no column**. Every table reference below is a citation (`spine §2.x`).
Conventions: [`../../CLAUDE.md`](../../CLAUDE.md). Foundation: [`phase-01.md`](phase-01.md),
[`phase-02.md`](phase-02.md). Requirement: [`../requirements.md`](../requirements.md) §39-58 and §120.

New decisions introduced by this plan are labelled **[D-IMP-n]** so a code review can cite them.

---

## 1. Goal and dependencies

### 1.1 Goal

After phases 10-12 the business can: receipt a student fee payment or a client project payment and have
that single act deterministically produce - or deterministically **not** produce - exactly one collaborator
commission ledger row, computed in one place, snapshot-immutable, duplicate-proof under retry and
concurrency, with an auditable reason whenever nothing was earned; approve or reject pending commissions
singly or in bulk with a per-entry audit entry; reverse commission automatically when a receipt is
refunded, voided or bounced, including clawback of commission already paid out; see a wallet whose every
figure is provably equal to the ledger; pay a collaborator through a payout that consumes named earnings,
records method and transaction ID, and can be rejected, cancelled or returned without losing history; and
hand a collaborator a statement with opening balance, the five §56 movement lines, closing balance,
date-range and source filters, and print / PDF / CSV export.

### 1.2 Dependencies

| Phase | Needed before build starts | Hard or soft |
|---|---|---|
| 1 | `users`, `branches`, RBAC + `PermissionRegistry` + `Gate::before` module gating, `activity_log` with old/new values, `Blameable`, `LogsActivityWithContext::withReason()`, **`App\Support\Money` as published in phase-01 §3** (the full canonical surface - F-4.11, nothing is added here), `layouts/admin`, `layouts/panel`, the `x-ui.*` set | **hard** |
| 2 | `SettingsRegistry` groups `collaborator` / `institute` / `finance`, `SettingsService`, `DashboardRegistry`, `DateRange`, `Format` (`money()`, `app_date()`) | **hard** |
| 8 | `collaborators` (`user_id`, `status`, `referral_code`, `deleted_at`), the wallet-creating observer, the `forceDelete` policy | **hard** for every screen; the migration set can ship before it via the guarded FK migrations |
| 9 | `collaborator_referral_visits`, and referral-link capture that calls `ReferralService::attach()` | soft (nullable FK) |
| 6 | `projects.project_value` / `.discount_amount` / `.net_value` / `.commission_type` / `.commission_rate` / `.commission_fixed_amount`, `project_milestones.amount`, `project_value_revisions` | **hard for Phase 11** |
| 5 | `clients`, `leads` | soft |
| 5 | **`App\Services\Finance\DocumentNumberService`** - Phase 5 ships the single numbering implementation (D27, F-4.1); phases 10-12 **reuse it and must not re-create it** | **hard** (every receipt, payment, reversal and payout number) |
| 7 | `App\Enums\PaymentMethod` and `App\Enums\LedgerEntryType` - declared by Phase 7, cases verbatim from the spine §3 (F-5.4) | **hard** |
| 6 | `App\Enums\CommissionCalculationType` - declared by Phase 6 (F-5.5) | **hard** |
| 13 | `invoices`, `payment_methods` | soft (nullable FK, guarded migration) |
| 14-15 | `courses`, `batches`, `students`, `student_admissions` (+ `.net_payable`) | **hard for Phase 10 screens**, soft for the migration set |
| 18 | nothing - Phase 18 depends on **this** (it consumes `PaymentService` and `StudentFeeService`) | - |

### 1.3 Build split (matches spine §1.3 exactly - no table is created twice)

| Phase | Ships |
|---|---|
| **10** | The whole migration set (§2.2) - including the four student-fee tables, which Phase 18 then ships screens and services over (F-11.3) - the **27** enums §3 declares (§3; `PaymentMethod`, `LedgerEntryType` and `CommissionCalculationType` are **reused, not created** - F-5.4, F-5.5), the `PermissionRegistry` and `SettingsRegistry` additions (§4, §5), `ReferralService`, `CommissionRuleService`, `CommissionBaseResolver`, `CommissionEntitlementService`, `LedgerWriter`, `PaymentService` (student side), `StudentCommissionService`, `CommissionReversalService`, `CommissionApprovalService`, the three commission jobs, the sweeper + release-held + verify-constraints commands, the commission ledger index/show/approve/reject/bulk screens, the skip report, the record-payment and refund modals, the payments register (student), the commission rule timeline |
| **11** | `PaymentService` (project side), `ProjectCommissionService`, project-override rule resolution, the project payments register + project payment trail tab, the project-side record-payment and refund modals, the project commission widgets |
| **12** | `CollaboratorWalletService`, `PayoutService`, `CollaboratorStatementService`, `CommissionReconciliationService`, wallet index/detail, reconciliation screens + command, payout wizard/register/detail/voucher, payout accounts, the statement + its three exports, the discrepancy queue, every collaborator-panel screen |

Phases 10 and 11 may be built in parallel only after §6.3's shared services exist; Phase 12 needs the
ledger to contain rows, so it is built last. **No phase may start its screens before the migration set and
the enums are merged** - the engines are meaningless without the schema guarantees (spine R-13).

**Reused, must not be re-created** (resolutions §2.2-2.3, R1/R3 - one class, one name): Phase 5's
`App\Services\Finance\DocumentNumberService` (D27, F-4.1), Phase 7's `PaymentMethod` and
`LedgerEntryType` (F-5.4), Phase 6's `CommissionCalculationType` (F-5.5), Phase 1's `App\Support\Money`
(F-4.11). Phase 10 casts and calls them; a second declaration of any of the four is a merge conflict,
not a style question.

**Release order** (F-11.2): this migration set is applied in the **same release**, immediately after
Phase 8's own migrations - its timestamps already sort after them and the `add_*_fks_*` files are
`Schema::hasTable()` guarded, so `migrate` and `migrate:fresh` are both legal. The Phase 8/9 surfaces
that touch these tables stay behind their module switches until it lands
(phase-08-09 §1.4 [D-P8-1]; resolutions §6.3 carries the tracker line).

---

## 2. Schema

### 2.1 This contract defines no schema

Every table, column, index, CHECK, generated column, trigger and relationship is defined by the spine.
Restating a `Column | Type | Null/Default | Notes` table here would create a second source of truth for
financial DDL, which is exactly the failure mode `CLAUDE.md` §1.3 and spine [D-FS-4] exist to prevent.
What follows is therefore the **citation index**, the **migration plan**, and the **model plan** - the
implementation layer the spine deliberately leaves to the phase contract.

| # | Table | Spine § | Grain (one row per ...) | Append-only | Soft deletes | Built on by |
|---|---|---|---|---|---|---|
| 1 | `student_fees` | §2.2 | fee charge document | no | yes | 10 (tables), 18 (screens + services) |
| 2 | `student_fee_installments` | §2.3 | schedule line | no | yes | 10 (table), 18 (screens + services) |
| 3 | `student_fee_discounts` | §2.4 | discount / scholarship / waiver / correction | **yes** | **no** | 10 (table), 18 (screens + services) |
| 4 | `student_fee_payments` | §2.5 | physical student receipt | **yes** | **no** | **10** |
| 5 | `project_payments` | §2.6 | client payment | **yes** | **no** | **11** (13 lists it) |
| 6 | `payment_reversals` | §2.7 | refund / void / bounce / cancellation | **yes** | **no** | **10** (+11) |
| 7 | `collaborator_referrals` | §2.8 | attribution version | **yes** | **no** | **10** (+9) |
| 8 | `collaborator_commission_settings` | §2.9 | immutable rule version | **yes** | **no** | **10** (+8) |
| 9 | `collaborator_commission_entitlements` | §2.10 | current promise per (document, collaborator) | **yes** | **no** | **10** |
| 10 | `collaborator_commission_ledger_entries` | §2.11 | movement of entitlement | **yes** | **no** | **10** (+11) |
| 11 | `collaborator_wallets` | §2.12 | collaborator (cache) | no (rebuildable) | no | **12** (+8 observer) |
| 12 | `collaborator_payouts` | §2.13 | withdrawal | **yes** | **no** | **12** |
| 13 | `collaborator_payout_allocations` | §2.14 | (payout, ledger entry) slice | released, never deleted | no | **12** |
| 14 | `collaborator_payout_accounts` | §2.15 | reusable destination | no | yes | **12** |
| 15 | `collaborator_wallet_reconciliations` | §2.16 | (run, collaborator) proof | written once | no | **12** |

Relationships are the spine's map in §2.17 verbatim, including the two `belongsToMany` edges through the
pivot **`collaborator_payout_allocations`** (`CollaboratorPayout` n-n `CollaboratorCommissionLedgerEntry`).
No new relationship, pivot or polymorphic edge is introduced by this plan.

**The four student-fee tables are Phase 10's (F-11.3, resolutions §2.1).** Rows 1-4 above
(`student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`) are created
by the migration set in §2.2 and by nothing else; **Phase 18 creates no financial table** - it ships the
fee screens, the fee services and `student_fee_reminders` only. Phase 13 ships invoices, expenses and
incomes. No table in this list is created twice.

### 2.2 Migration plan - one atomic set, Phase 10, 21 files

**[D-IMP-1] The 15 `create_*` migrations declare columns and indexes only; every foreign key is added
afterwards.** In-spine FKs form cycles across creation order (a receipt references a referral created in
file 7; the ledger references a wallet and an entitlement; a payout references an account) and several
targets live in phases 5, 6, 9, 13, 14 and 15. Splitting FKs out makes the forward order trivial, makes
`down()` deterministic, and lets the set migrate on a database where the institute and project tables do
not exist yet (spine [D-FS-1]).

| # | Migration | Contents |
|---|---|---|
| 01 | `create_student_fees_table` | spine §2.2 columns + indexes + `chk_sf_nonneg`, `chk_sf_discount_ceiling`, **plus `generation_key` string(64) nullable and `UNIQUE uq_sf_generation(generation_key)`** - the generator composes the key (`structure:{admission}:{head}`, `monthly:{admission}:{YYYY-MM}`) and the **INSERT is the duplicate check**: a 1062 means "already generated" (F-3.15, R7; MariaDB ignores NULLs, so an ad-hoc charge stacks freely) |
| 02 | `create_student_fee_installments_table` | §2.3 + `uq_sfi_no`, `chk_sfi_amount` |
| 03 | `create_student_fee_discounts_table` | §2.4 + `uq_sfd_idem`, `uq_sfd_reverses`, `chk_sfd_nonzero`, `chk_sfd_pct` |
| 04 | `create_student_fee_payments_table` | §2.5 + `uq_sfp_receipt`, `uq_sfp_idem`, `uq_sfp_gateway`, `chk_sfp_amount`, `chk_sfp_refund_ceiling` |
| 05 | `create_project_payments_table` | §2.6 + `uq_pp_number`, `uq_pp_idem`, `uq_pp_gateway`, `chk_pp_amount`, `chk_pp_refund_ceiling` |
| 06 | `create_payment_reversals_table` | §2.7 + `uq_pr_number`, `uq_pr_idem`, `chk_pr_one_target`, `chk_pr_amount` |
| 07 | `create_collaborator_referrals_table` | §2.8 + `chk_cr_one_subject`, `chk_cr_dates`, `chk_cr_closed` |
| 08 | `create_collaborator_commission_settings_table` | §2.9 + `uq_ccs_start`, `uq_ccs_version`, all four CHECKs |
| 09 | `create_collaborator_commission_entitlements_table` | §2.10 + `chk_cce_one_doc`, `chk_cce_nonneg` |
| 10 | `create_collaborator_wallets_table` | §2.12 + `uq_cw_collaborator`, `chk_cw_paid_nonneg` |
| 11 | `create_collaborator_commission_ledger_entries_table` | §2.11 + `uq_cle_dedupe`, `uq_cle_source`, `uq_cle_reversal_pair`, every INDEX, every CHECK of the §2.11 key table |
| 12 | `create_collaborator_payout_accounts_table` | §2.15 |
| 13 | `create_collaborator_payouts_table` | §2.13 + `uq_cp_number`, `uq_cp_idem`, `uq_cp_txn`, `chk_cp_amount` |
| 14 | `create_collaborator_payout_allocations_table` | §2.14 + `uq_cpa_pair`, `chk_cpa_amount` |
| 15 | `create_collaborator_wallet_reconciliations_table` | §2.16 + `uq_cwr_run` |
| 16 | `add_generated_columns_to_financial_tables` | raw SQL STORED columns: `student_fee_payments.net_received_amount`, `project_payments.net_received_amount`, `collaborator_commission_ledger_entries.signed_amount`, `collaborator_referrals.current_guard`, `collaborator_commission_settings.open_guard`, `collaborator_commission_entitlements.document_key` + `current_guard`, `collaborator_payout_allocations.active_guard`, `collaborator_payout_accounts.default_guard` |
| 17 | `add_guard_unique_indexes_to_financial_tables` | the indexes that need file 16's columns: `uq_cr_student_current`, `uq_cr_project_current`, `uq_cr_client_current`, `uq_cr_lead_current`, `uq_cr_superseded_by`, `uq_ccs_open`, `uq_cce_current`, `idx_cpa_entry_active`, `uq_cpacc_default` |
| 18 | `add_internal_fks_to_financial_tables` | every FK whose target is one of the 15 tables, with the exact on-delete behaviour of §2.2-§2.16 (`restrictOnDelete` on every money edge, `nullOnDelete` only where the spine says so) |
| 19 | `add_no_delete_triggers_to_financial_tables` | the nine `BEFORE DELETE ... SIGNAL SQLSTATE '45000'` triggers of spine INV-5 |
| 20 | `add_external_fks_to_financial_tables` | guarded by `Schema::hasTable()`: `students`, `student_admissions`, `courses`, `batches`, `branches`, `collaborators`, `users`, `projects`, `clients`, `project_milestones`, `leads` |
| 21 | `add_deferred_fks_to_financial_tables` | guarded: `invoices`, `payment_methods`, `collaborator_referral_visits` - re-runnable, so phases 9 and 13 simply re-run it (`migrate` is idempotent because each `addForeignKey` is wrapped in a `hasColumn` + index-existence check) |

Rules binding on all 21 files:

1. **Raw SQL must fail loudly.** CHECKs, STORED generated columns, generated-column unique indexes and
   triggers are written with `DB::statement()`. If the server rejects one, the migration throws - no
   try/catch, no silent skip (spine R-3). `financial:verify-constraints` re-asserts them daily.
2. **`down()` order is the exact inverse**: triggers -> generated-column unique indexes -> generated
   columns -> FKs -> tables. MariaDB refuses to drop a STORED column while an index uses it, so 17 must
   roll back before 16.
3. Money is `decimal(15,2)` default `0.00`; percentages `decimal(8,4)`; statuses `string(32)` with an enum
   cast; `branch_id` nullable on the four institute-side tables (D11).
4. No `deleted_at` on the nine append-only tables - **recorded and approved as D16** (2026-09-12), under
   the general category rule **D19** in `CLAUDE.md` §3 (F-9.1). This contract states no local exception
   and no local decision number: a money, audit, log, snapshot or history-pivot table carries no
   `deleted_at`, and `deleted_at` is **never** added back to one of them.
5. The set is merged as one unit. A partial merge leaves the invariants stated but untrue (spine R-13).

### 2.3 Model plan

`app/Models/Institute/` : `StudentFee`, `StudentFeeInstallment`, `StudentFeeDiscount`,
`StudentFeePayment`. `app/Models/Finance/` : `ProjectPayment`, `PaymentReversal`.
`app/Models/Collaborator/` : `CollaboratorReferral`, `CollaboratorCommissionSetting`,
`CollaboratorCommissionEntitlement`, `CollaboratorCommissionLedgerEntry`, `CollaboratorWallet`,
`CollaboratorPayout`, `CollaboratorPayoutAllocation`, `CollaboratorPayoutAccount`,
`CollaboratorWalletReconciliation`.

| Concern | Implementation |
|---|---|
| Casts | every status / type column casts to its §3 enum; every money column casts to `decimal:2` (string in, string out - never `float`); `rule_snapshot` / `details` to `array`; `account_details_encrypted` and `details_encrypted` to `encrypted`; dates to `immutable_date`, timestamps to `immutable_datetime` |
| Traits | `Blameable` everywhere; `SoftDeletes` **only** on the six tables that have it; `LogsActivityWithContext` on all 15 with `logOnly()` whitelists that exclude encrypted columns |
| Guards | `CollaboratorCommissionLedgerEntry::booted()`: `creating` throws `DirectLedgerWriteException` unless `LedgerWriter::isWriting()`; `updating` throws `ImmutableLedgerAttributeException` when a dirty attribute is outside the INV-4 whitelist; `deleting` / `forceDeleting` throw always (INV-21, INV-4, INV-5) |
| Guards | **[D-IMP-2]** `StudentFeePayment`, `ProjectPayment`, `PaymentReversal`: `creating` throws `DirectPaymentWriteException` unless `PaymentService::isWriting()`; `updating` throws when a dirty attribute is outside `{notes, reference_no, receipt_path, status, refunded_amount, commission_state, commission_skip_reason, commission_skip_detail, commission_attempts, commission_processed_at, collaborator_id, collaborator_referral_id, updated_by, updated_at}` (INV-8). Factories, seeders and a future historical import use the single greppable escape hatch `PaymentService::allowDirectWrites(fn () => ...)`; CI greps for it outside `database/` and `tests/` |
| Guards | `CollaboratorCommissionSetting::updating` throws unless the only dirty columns are `effective_to`, `status`, `superseded_at`, `superseded_by`-side fields (INV-17) |
| Scopes | ledger: `payable()` (`status = available AND entry_type = credit AND amount - allocated_amount - reversed_amount > 0 AND (hold_until IS NULL OR hold_until <= CURRENT_DATE)`), `paidPortion()`, `earnings()`, `undos()`, `forCollaborator()`, `inRange()` (on `transaction_date`, Phase 2 `DateRange` convention); payouts: `inFlight()`, `settled()`; referrals: `current()`, `effectiveOn($date)` |
| Accessors | ledger `reference` = `'CLE-' . id`; payout `masked_account` = `'****' . account_last4`; every model exposes `status_label` / `status_color` through the enum, never a Blade `match` |
| Global scope | `BelongsToAuthenticatedCollaborator` on the nine collaborator-owned models **and both payment tables** - see §9 |

---

## 3. Enums to add

All 30 live in `app/Enums/`, string-backed, each with `label()`, `color()` and `static options()` exactly
as Phase 1 §2 requires. **Cases and values are spine §3 verbatim** - reproduced here so the set can be
implemented without a second pass, not re-decided.

**Three of the 30 are declared by an earlier phase and are marked "reused, not created" in the table
below** (resolutions §2.2, R3 - one class, one name): `PaymentMethod` and `LedgerEntryType` are Phase 7's
(F-5.4) and `CommissionCalculationType` is Phase 6's (F-5.5). Phase 10 **casts to them and must not
re-declare them**; their cases are nonetheless listed here because the spine defines the cases and the
engines are written against them. The remaining **27** are created by Phase 10.

| Enum | Cases (values) |
|---|---|
| `StudentFeeType` | `course_fee`, `admission_fee`, `registration_fee`, `monthly_fee`, `installment`, `exam_fee`, `certificate_fee`, `other` (+ `isCommissionableByDefault()`) |
| `StudentFeeStatus` | `pending`, `partial`, `paid`, `overpaid`, `overdue`, `cancelled`, `refunded` (+ `isOpen()`) |
| `InstallmentStatus` | `pending`, `partial`, `paid`, `overdue`, `waived`, `cancelled` |
| `FeeDiscountType` | `fixed_discount`, `percentage_discount`, `scholarship`, `promotional_discount`, `referral_discount`, `waiver`, `correction`, `reversal` (+ `isScholarship()`) |
| `PaymentMethod` **(reused, not created - declared by Phase 7, F-5.4)** | `cash`, `bank_transfer`, `card`, `cheque`, `easypaisa`, `jazzcash`, `online_gateway`, `adjustment`, `other` |
| `ReceivedPaymentStatus` | `cleared`, `partially_refunded`, `refunded`, `voided`, `bounced` (+ `countsAsReceived()`) |
| `ReversalType` | `full_refund`, `partial_refund`, `cancellation`, `void`, `bounced_instrument`, `correction` (+ `isFullVoid()`) |
| `ReversalApprovalStatus` | `not_required`, `pending`, `approved`, `rejected` (+ `allowsCommissionReversal()`) |
| `CommissionScope` | `student`, `project` |
| `CommissionCalculationType` **(reused, not created - declared by Phase 6, F-5.5)** | `percentage`, `fixed`, `manual` |
| `CommissionBase` | `gross`, `net_after_discount`, `paid`, `total_value`, `milestone` (+ `appliesTo(CommissionScope)`) |
| `FixedCommissionRelease` | `prorated`, `on_first_payment`, `per_payment` (+ `isCapped()`) |
| `CommissionRuleStatus` | `scheduled`, `active`, `superseded`, `expired`, `cancelled` |
| `CommissionRuleSource` | `collaborator_rule`, `project_override`, `manual` - **three cases only; there is no `global_default`** and no silent fallback to a global default rate (F-5.6, [D-FS-9]) |
| `EntitlementDocumentType` | `student_admission`, `student_fee`, `project`, `project_milestone` |
| `EntitlementStatus` | `open`, `fully_released`, `closed`, `superseded`, `cancelled` |
| `CommissionSourceType` | `student_fee_payment`, `student_installment_payment`, `project_payment`, `payment_reversal`, `manual_adjustment` |
| `LedgerEntryType` **(reused, not created - declared by Phase 7, F-5.4)** | `credit`, `debit` |
| `LedgerEntryPurpose` | `student_commission`, `project_commission`, `reversal`, `clawback`, `manual_adjustment`, `write_off` (+ `isEarning()`, `isUndo()`) |
| `CommissionStatus` | `pending`, `approved`, `available`, `paid`, `reversed`, `cancelled` (+ `countsInBalance()`, `isPayable()`, `isTerminal()`) |
| `CommissionApprovalMode` | `automatic`, `manual` |
| `CommissionProcessingState` | `queued`, `processed`, `skipped`, `failed`, `not_applicable` |
| `CommissionSkipReason` | the 20 cases of spine §3 verbatim: `referral_system_disabled`, `automatic_commission_disabled`, `payment_not_cleared`, `no_referral`, `referral_not_commission_eligible`, `collaborator_inactive`, `collaborator_deleted`, `commission_disabled`, `no_effective_rule`, `fee_type_not_commissionable`, `milestone_not_commissionable`, `below_minimum_payment`, `base_zero`, `overpayment_only`, `no_collectible_denominator`, `entitlement_cap_reached`, `rounds_to_zero`, `below_minimum_commission`, `source_commission_missing`, `reversal_not_approved` |
| `PayoutStatus` | `requested`, `pending`, `approved`, `paid`, `rejected`, `cancelled` (+ `isInFlight()`, `isTerminal()`) |
| `PayoutMethod` | `bank_transfer`, `easypaisa`, `jazzcash`, `cash`, `cheque`, `other` |
| `AllocationReleaseReason` | `payout_rejected`, `payout_cancelled`, `payout_returned`, `released_for_reversal` |
| `ReferralSubject` | `student`, `project`, `client`, `lead` |
| `ReferralSource` | `referral_link`, `manual_selection`, `admission_form`, `import`, `api` |
| `ReferralStatus` | `active`, `superseded`, `revoked` |
| `ReconciliationStatus` | `ok`, `drift`, `repaired`, `failed` (+ `isHealthy()`) |

Colour convention for the money enums, so every badge in the system reads the same: earned/healthy
`emerald`, in-progress/waiting `amber`, terminal-negative `rose`, settled `sky`, inert `slate`.
`CommissionSkipReason::color()` is `slate` for configuration reasons and `amber` for the six that indicate
an operator should act (`collaborator_inactive`, `no_effective_rule`, `commission_disabled`,
`no_referral`, `source_commission_missing`, `reversal_not_approved`).

---

## 4. PermissionRegistry additions

Presets are Phase 1 §4's (`READ`, `CRUD`, `CRUD_FULL`, `APPROVE`, `STATUS`, `ASSIGN`, `FILES`, `MONEY`,
`REPORTS`, `LOGS`). Registered in Phase 10 so phases 11 and 12 add screens only. All four new modules are
`is_core = false`.

### 4.1 New module slugs (spine §4.1)

| slug | ModuleGroup | icon | is_core | Abilities | Deliberately absent |
|---|---|---|---|---|---|
| `student_fee_payments` | `Institute` | `receipt-percent` | false | `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` + `LOGS` | `edit`, `delete` (INV-8, INV-5); voiding is `change_status` |
| `project_payments` | `Finance` | `banknotes` | false | `READ` + `create` + `print` + `export` + `STATUS` + `MONEY` + `LOGS` | `edit`, `delete` |
| `payment_reversals` | `Finance` | `arrow-uturn-left` | false | `READ` + `create` + `APPROVE` + `MONEY` + `REPORTS` + `LOGS` | `edit`, `delete` |
| `wallet_reconciliation` | `Finance` | `scale` | false | `READ` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` | everything else; `change_status` **is** "run a reconciliation / repair the cache" - no new `Ability` case is invented |

`collaborator_commission_entitlements` gets no module of its own (spine §4.1): entitlements are read inside
the commission screens under `collaborator_commissions.view`.

### 4.2 Abilities added to existing Phase 1 slugs (spine §4.2)

| slug | Abilities after this phase |
|---|---|
| `collaborator_commissions` | `READ` + `create` + `APPROVE` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` - never `edit`, never `delete` |
| `collaborator_commission_settings` | `READ` + `create` + `APPROVE` + `MONEY` + `LOGS` - never `edit`, never `delete` (INV-17) |
| `collaborator_wallets` | `READ` + `MONEY` + `REPORTS` |
| `collaborator_payouts` | `READ` + `create` + `print` + `export` + `APPROVE` + `STATUS` + `MONEY` + `REPORTS` + `LOGS` - never `delete` |
| `collaborator_referrals` | `READ` + `create` + `edit` + `STATUS` + `LOGS` |
| `student_fees` | `CRUD_FULL` + `STATUS` + `MONEY` (`delete` only while no receipt exists - policy) |
| `installments` | `CRUD` + `STATUS` |
| `fee_discounts` | `READ` + `create` + `APPROVE` + `MONEY` + `LOGS` |

### 4.3 Portal permissions (spine §4.3)

Used as-is from Phase 1: `collaborator_portal.dashboard`, `.students`, `.student_fee_status`,
`.student_commission`, `.projects`, `.project_client`, `.project_value`, `.project_payments`,
`.project_commission`, `.payout_request`, `.statement_download`.

Added: `collaborator_portal.wallet`, `collaborator_portal.payouts`, `student_portal.fees`,
`student_portal.payments`, `client_portal.payments`.

**Request to Phase 1 (§13):** `RoleSeeder` must grant the Collaborator role the two new
`collaborator_portal.*` permissions (still minus `payout_request`), the Student role `student_portal.fees`
and `.payments`, and the Client role `client_portal.payments` - otherwise the panels these phases build
are 403 for every collaborator, student and client on day one.

---

## 5. SettingsRegistry additions

All 24 keys go into Phase 2's existing `collaborator`, `institute` and `finance` groups; **no new group**.
Phase 2's existing keys (`referral_system_enabled`, `automatic_commission_enabled`,
`commission_approval_mode`, `student_commission_base`, `project_commission_base`,
`default_*_commission_type` / `_rate`, `commission_on_admission_fee`, `commission_on_registration_fee`,
`commission_reversal_on_refund`, `payout_request_enabled`, `minimum_payout`, `payout_methods`,
`institute.fee_receipt_prefix`) are consumed exactly as defined and are **not** redefined.

| group.key | type | default | Consumed by |
|---|---|---|---|
| `collaborator.commission_hold_days` | number | `0` | engine step 14, `commissions:release-held` |
| `collaborator.commissionable_fee_types` | multiselect(`StudentFeeType`) | `course_fee, monthly_fee, installment` | guard G10 |
| `collaborator.student_commission_document` | select `admission`\|`fee` | `admission` | `CommissionBaseResolver` |
| `collaborator.fixed_commission_release` | select `prorated`\|`on_first_payment`\|`per_payment` | `prorated` | entitlement opening, release branch |
| `collaborator.commission_on_overpayment` | boolean | `false` | step 11 |
| `collaborator.commission_min_entry_amount` | decimal | `0.00` | step 13 (`below_minimum_commission`) |
| `collaborator.clawback_on_paid_commission` | select `offset_future`\|`write_off` | `offset_future` | `CommissionReversalService` |
| `collaborator.payout_single_inflight` | boolean | `true` | `PayoutService` guard |
| `collaborator.payout_auto_approve_below` | decimal | `0.00` | `PayoutService::request()` |
| `collaborator.statement_show_technical_rows` | boolean | `false` | statement default filter |
| `institute.fee_record_prefix` | text | `FS-` | `DocumentNumberService` |
| `institute.fee_record_next_number` | number | `1` | counter |
| `institute.fee_receipt_next_number` | number | `1` | counter for the existing prefix |
| `finance.project_payment_prefix` / `_next_number` | text / number | `PP-` / `1` | counter |
| `finance.payment_reversal_prefix` / `_next_number` | text / number | `RV-` / `1` | counter |
| `finance.collaborator_payout_prefix` / `_next_number` | text / number | `PO-` / `1` | counter |
| `finance.backdate_limit_days` | number | `30` | `PaymentService` validation |
| `finance.refund_approval_required` | boolean | `false` | reversal approval gate |
| `finance.refund_approval_threshold` | decimal | `0.00` | same |
| `finance.payout_reference_required` | boolean | `true` | `markPaid` |
| `finance.wallet_reconcile_enabled` | boolean | `true` | nightly job |

Validation rules live in `SettingsRegistry::rulesFor()` as Phase 2 requires: every decimal
`numeric|min:0`, `commission_hold_days` `integer|between:0,365`, `backdate_limit_days`
`integer|between:0,365`, every select `in:` its enum values, `commissionable_fee_types` `array` +
`in:StudentFeeType::values()`.

**Counters are read and written only by Phase 5's `DocumentNumberService`** (spine §5, D27): `SELECT ...
FOR UPDATE` on the `settings` row inside the caller's transaction, increment, return
`prefix . sprintf($pad, $value)` - **each caller passes its own pad**, the class default being `'%06d'`
(F-4.1); the column's UNIQUE index is the backstop and a 1062 triggers exactly one retry. No other code
touches a `*_next_number` key, there is no second counter implementation anywhere, and the ledger has no
counter at all ([D-FS-6]).

---

## 6. Services

Namespaces: `App\Services\Finance\`, `App\Services\Institute\`, `App\Services\Collaborator\`.
DTOs: `App\DataObjects\Finance\` and `App\DataObjects\Collaborator\` (readonly classes, never arrays).
Every money method is one `DB::transaction(..., attempts: 3)` with the spine's fixed lock order
**payment -> document -> entitlement -> wallet -> ledger rows ascending by id**, and dispatches events
only through `DB::afterCommit()` (INV-20).

### 6.1 The wiring - how a received payment reaches the engine, and why there is only one calculation site

**[D-IMP-3] Exactly one call chain exists per side, and the calculation lives only in
`StudentCommissionService::handlePayment()` / `ProjectCommissionService::handlePayment()`.**

```
Phase 18 screen / Phase 13 screen / import / API
        |  (controller -> Form Request -> service; never a direct model insert, see D-IMP-2)
        v
Finance\PaymentService::recordStudentFeePayment()        Finance\PaymentService::recordProjectPayment()
  DB::transaction:
    1 DocumentNumberService::next(receipt/payment number)
    2 INSERT the payment row  (idempotency_key UNIQUE = layer 0 guard)
    3 ReferralService::effectiveOn($subject, $paid_on) -> snapshot collaborator_id + collaborator_referral_id
    4 recompute the document caches under its row lock
    5 commission_state = queued
  DB::afterCommit:
        v
Event  StudentFeePaymentRecorded                        Event  ProjectPaymentRecorded
        |                                                       |
        +-- Listener QueueStudentFeeCommission (sync)            +-- Listener QueueProjectPaymentCommission (sync)
        |     dispatch(new ProcessStudentFeeCommission($id))     |     dispatch(new ProcessProjectPaymentCommission($id))
        +-- Listener NotifyStudentOfFeePayment (queued)          +-- Listener NotifyClientOfProjectPayment (queued)
        v                                                       v
Job ProcessStudentFeeCommission (ShouldBeUnique)        Job ProcessProjectPaymentCommission (ShouldBeUnique)
        v                                                       v
Collaborator\StudentCommissionService::handlePayment()   Collaborator\ProjectCommissionService::handlePayment()
        |  <-- the ONLY place a commission amount is computed, on either side
        +-- CommissionRuleService::resolve()          (which rule)
        +-- CommissionBaseResolver::forXPayment()     (which figures)
        +-- CommissionEntitlementService::openOrLoad()(the capped promise, locked)
        +-- LedgerWriter::post()                     (the ONLY insert path, INV-21)
        +-- CollaboratorWalletService::applyDelta()  (the cache, same transaction)
```

Three other entry points exist and **all three call the same service method**, never their own maths:

| Entry point | Owner | Purpose |
|---|---|---|
| `commissions:sweep` (scheduler, every 10 min) | 10 | re-queues `queued` / `failed` rows older than 2 minutes (dead worker, lost queue lock, out-of-order delivery) |
| `commissions:evaluate --payment= \| --from= --to= [--force]` + `POST /admin/commissions/evaluate` | 10 | the audited manual re-evaluation of §6.6 row 1 (a collaborator reinstated after a suspension) |
| `ProcessCommissionReversal` -> `CommissionReversalService::handleReversal()` | 10 | the negative side; it reads the earning rows, it never recomputes a rate |

**What is forbidden, and how it is enforced**

| Forbidden | Enforcement |
|---|---|
| Any controller, job, listener, observer, widget, export or report computing a commission amount | FT-IMP-01 static scan: `Money::percentage` and the string `commission_rate` may appear only in `app/Services/Collaborator/` and `app/Support/Money.php` |
| Any insert into the ledger outside `LedgerWriter` | model `creating` hook -> `DirectLedgerWriteException` (INV-21), FT-36 |
| Any insert into the three payment tables outside `PaymentService` | model `creating` hook -> `DirectPaymentWriteException` ([D-IMP-2]), FT-IMP-02 |
| A Phase 18 or Phase 13 screen calling the commission service directly | the services are `final` and their `handlePayment()` accepts only a persisted payment model; a static test asserts no reference to `StudentCommissionService` / `ProjectCommissionService` outside `app/Jobs`, `app/Console`, `app/Services/Collaborator` and `tests/` |
| An observer on the payment models firing commission | no observer is registered on those models at all; the event is dispatched by the service after commit, so a rolled-back receipt can never notify or earn (INV-20) |
| Commission inline in the request | the listener only dispatches; the cashier's response never waits on commission, and a queue outage degrades to "commission pending" rather than a failed receipt |

**Queue contract.** Jobs are `ShouldBeUnique` (`uniqueId` = `student-fee-payment:{id}` /
`project-payment:{id}` / `payment-reversal:{id}`, `uniqueFor` 3600), `$afterCommit = true`, `tries` 5,
`backoff [10,30,60,120,300]`, `queue: 'financial'`. The uniqueness lock is an optimisation, never the
guarantee: `uq_cle_dedupe` is (spine §2.19). `failed()` writes `commission_state = failed` plus the
exception class into `commission_skip_detail` and notifies holders of `wallet_reconciliation.view_any`
(`CommissionGenerationFailed`). With `QUEUE_CONNECTION=sync` (tests, first install) the job runs inline
after commit - the same code path, which is why the suite proves production behaviour.

### 6.2 The guard sequence - precise order, and exactly what each failure writes

`handlePayment()` runs these in this order. **The first failure returns immediately.** Both services share
the sequence through `App\Services\Collaborator\Concerns\RunsCommissionGuards`; only G10 differs by scope.

| # | Check | Reads | Skip reason on failure |
|---|---|---|---|
| G0 | `commission_state` is not already `processed` / `skipped` / `not_applicable` | payment (locked) | **none - returns `CommissionOutcome::alreadyDone()` and writes absolutely nothing** |
| G1 | `setting('collaborator.referral_system_enabled')` | settings | `referral_system_disabled` |
| G2 | `setting('collaborator.automatic_commission_enabled')` - bypassed **only** by `--force` from `commissions:evaluate` **[D-IMP-4]** | settings | `automatic_commission_disabled` |
| G3 | `payment.status` in `cleared` / `partially_refunded` / `refunded` (a fully refunded receipt still earns; its reversal posts the offset, which is what makes job order irrelevant). `voided` / `bounced` stop here | payment | `payment_not_cleared` |
| G4 | `ReferralService::effectiveOn($subject, $payment->paid_on)` returns a referral - **value date, never `now()`** (INV-16) | referrals | `no_referral` |
| G5 | that referral's `commission_eligible` is true | referral | `referral_not_commission_eligible` |
| G6 | the collaborator (loaded `withTrashed()`) is not soft-deleted | collaborators | `collaborator_deleted` |
| G7 | `collaborator.status === Active` | collaborators | `collaborator_inactive` |
| G8 | `CommissionRuleService::resolve()` returns a rule (project override, else the effective-dated version; **no global-default fallback**, [D-FS-9]) | rules / project | `no_effective_rule` |
| G9 | the resolved rule's `is_enabled` is true | rule | `commission_disabled` |
| G10 | **student:** `fee.fee_type` is in the commissionable set (§6.4). **project:** when the base is `milestone`, the payment carries a `project_milestone_id` the rule allows | fee / payment / rule | `fee_type_not_commissionable` / `milestone_not_commissionable` |
| G11 | `payment.amount >= rule.min_payment_amount` when set | payment / rule | `below_minimum_payment` |

Then the computation, where five further skip outcomes are possible:

| # | Step | Skip outcome |
|---|---|---|
| C1 | resolve the base (`rule.base_override ?? setting`); a base that does not apply to the scope falls back to `paid`, records `base_fallback: true` in `rule_snapshot` and logs a warning activity row - **money is never blocked by a misconfiguration** | - |
| C2 | `CommissionBaseResolver` returns `{document_type, document_id, document_base_amount, collectible_amount}` from the payment row and the document row only - never a cached `paid_amount` | - |
| C3 | `CommissionEntitlementService::openOrLoad()` - `firstOrCreate` then `lockForUpdate` (a 1062 means a racing worker won; re-read) | - |
| C4 | the commissionable amount of this payment (spine §6.1.6) | `base_zero` (amount was 0) / `overpayment_only` (nothing collectible remains) |
| C5 | the release amount, branch A-D (spine §6.1.7) | `no_collectible_denominator`, `entitlement_cap_reached` |
| C6 | quantise half-up at 2 | `rounds_to_zero` |
| C7 | `release >= setting('collaborator.commission_min_entry_amount')` | `below_minimum_commission` |
| C8 | status from the snapshotted approval mode + hold days; `LedgerWriter::post()`; entitlement caches; wallet delta; stamp the payment | - |

**What a failure does, precisely.** Two classes, and nothing in between:

| Class | When | Financial rows | Payment row | Activity log | Notification | Re-evaluable |
|---|---|---|---|---|---|---|
| **Silent** | G0 only (idempotent replay from the sweeper, a queue retry, a replayed `failed_jobs` entry) | none | **untouched** | **none** - otherwise every sweep would flood the audit trail | none | n/a |
| **Skipped with a reason** | G1-G11, C4-C7 | **none at all: no ledger row, no zero row (INV-2), no wallet write, no wallet version bump** | `commission_state = skipped`, `commission_skip_reason`, `commission_skip_detail` (the human sentence shown in the UI, e.g. "collaborator COL-1024 suspended on 2026-03-04"), `commission_attempts + 1`, `commission_processed_at = now()`; `collaborator_id` + `collaborator_referral_id` are stamped when resolution reached G6 or later, so a skip is still traceable to a partner | **exactly one** `commission.skipped` row on the payment subject: `module = collaborator_commissions`, properties `{step, reason, detail, collaborator_id}`, IP + device from `LogsActivityWithContext` | none | yes, by `commissions:evaluate` (audited, `collaborator_commissions.approve`) |

Two consequences stated plainly:

1. **A late skip (C4-C7) leaves the entitlement row open** with every cache unchanged - in particular
   `collected_amount` is **not** advanced, so a receipt that rounded to zero or fell below the minimum does
   not consume the promise and a later receipt can still earn (spine §6.6 row 20). An open entitlement with
   zero releases is a promise, not a commission, and breaks no invariant.
2. **`commissions:sweep` never re-queues a `skipped` row** - a skip is a decision, not a failure. Undoing a
   decision is an explicit, permissioned, audited act **[D-IMP-4]**, which is what stops a reinstated
   collaborator from being silently back-paid twice (`uq_cle_source` would stop the second row anyway).

### 6.3 Service contracts

Signatures are binding. Each row states what the method **guarantees**; events are fired `afterCommit`.

**`App\Services\Finance\DocumentNumberService`** - **Phase 5 ships it; phases 10-12 reuse it and must
not re-create it** (D27, F-4.1). Restated here only so the guarantees the engines rely on are visible:

| Method | Guarantees |
|---|---|
| `next(string $prefixKey, string $counterKey, string $pad = '%06d'): string` | unique under concurrency (settings row lock inside the caller's transaction + the column's UNIQUE index); one retry on 1062; never called outside a transaction (asserts `DB::transactionLevel() > 0`). **Every caller in this contract passes its own pad explicitly** - receipt, project-payment, reversal and payout numbers each keep the width their §2 unique index was designed around |
| `reserve(string $counterKey, ?string $periodKey = null, ?string $periodValue = null): int` | the same counter class, with the period reset (the period row is a settings key) - **there is no second `FOR UPDATE` counter anywhere in the system** (F-4.12) |

**`App\Services\Finance\PaymentService`** (student side Phase 10, project side Phase 11)

| Method | Guarantees |
|---|---|
| `recordStudentFeePayment(StudentFee $fee, RecordPaymentData $data): PaymentResult` | one receipt per `idempotency_key` (a replay returns `created: false` and the existing row); `amount > 0`; at most one installment line allocated and never beyond its remainder; `paid_on` within `finance.backdate_limit_days` unless the actor holds `student_fee_payments.approve`, and never in the future; the resolved `collaborator_id` / `collaborator_referral_id` snapshotted from the **value date**; the charge's five caches and status recomputed under its row lock; `duplicate_fingerprint` computed and, when it matches an existing receipt, the call is refused unless `$data->confirmDuplicate` (the override is logged with both receipt numbers); **no commission work inline** |
| `recordProjectPayment(Project $project, RecordProjectPaymentData $data): PaymentResult` | the same, plus optional milestone and invoice, `client_id` denormalised from the project, `is_advance` when no invoice is linked; a milestone must belong to the project |
| `refund(StudentFeePayment\|ProjectPayment $p, RefundData $data): PaymentReversal` - the **DTO is the only form** (F-4.2, R7): `App\DataObjects\Finance\RefundData` (readonly `string $amount`, `string $reason`, `ReversalType $type`, `?string $method = null`, `?string $idempotencyKey = null`, `?CarbonInterface $refundedOn = null`), because two adjacent `string` positionals are swappable and that is a silent money bug | `amount <= amount - refunded_amount` recomputed under `lockForUpdate` (INV-9, `chk_*_refund_ceiling` as backstop); one reversal per `idempotency_key`; the payment's `refunded_amount` raised by a conditional UPDATE and its status moved per spine §2.18.3 in the same transaction; `approval_status` set from `finance.refund_approval_required` + `_threshold`; **nothing reaches the commission engine while `pending`** |
| `void(StudentFeePayment\|ProjectPayment $p, string $reason): PaymentReversal` | a full-amount `void` reversal, payment -> `voided`; the only "edit" path (INV-8); the replacement receipt is linked in `notes` and in the activity row |
| `approveReversal(PaymentReversal $r, User $by): PaymentReversal` / `rejectReversal(PaymentReversal $r, string $reason, User $by)` | approval dispatches `ProcessCommissionReversal` and fires `PaymentReversalApproved`; rejection rolls the `refunded_amount` increment back **in the same transaction, under the payment's row lock**, restores the payment status, and fires **`PaymentReversalRejected`** `afterCommit` (F-4.9) so the downstream caches that assumed the refund - an invoice's derived figures, the charge's five caches - recompute from their canonical SQL. The event is the notification and cache leg only; it never repeats the money rollback |
| `dryRun(StudentFee\|Project $doc, RecordPaymentData $data): CommissionPreview` | read-only: runs G1-C7 and returns the prospective rule version, base, rate, base amount and commission amount, or the exact skip reason. **Writes nothing** - asserted by a test that counts queries and rows |
| `isWriting(): bool` / `allowDirectWrites(Closure $c): mixed` | the model-guard flag of [D-IMP-2] |

**`App\Services\Collaborator\ReferralService`** (Phase 10; Phase 9 calls it)

| Method | Guarantees |
|---|---|
| `attach(Model $subject, Collaborator $c, ReferralSource $s, ?string $code = null, ?CarbonInterface $on = null, ?ReferralContext $context = null): CollaboratorReferral` | exactly one `active` referral per subject (DB-enforced by `uq_cr_*_current`); the code snapshotted; a 1062 surfaces as a domain error naming the existing collaborator. **The sixth parameter fills the six evidence columns** `referral_visit_id`, `landing_url`, `ip_address`, `user_agent`, `referral_date`, `notes` from `App\DataObjects\Collaborator\ReferralContext` (readonly: `?int $referralVisitId`, `?string $landingUrl`, `?string $ipAddress`, `?string $userAgent`, `?CarbonInterface $referralDate`, `?string $notes`) - without it Phase 9's click evidence could never reach the attribution row (F-4.3) |
| `recordLosingCandidate(CollaboratorReferral $winner, Collaborator $loser, ReferralContext $ctx, string $reason): CollaboratorReferral` | the loser of a URL-versus-receptionist race, written **only here** because only the spine may write `collaborator_referrals` (F-4.4, spine §2.8): `status = superseded`, `superseded_by_id = $winner->id`, `effective_from = effective_to = today`, `commission_eligible = false`, `change_reason = $reason` (**mandatory**), `changed_by` = the actor, evidence from `$ctx`; `uq_cr_superseded_by` keeps it to one loser per winner. Phase 9's `ReferralAttributionResolver` (phase-08-09 §6.3 modifier 7) is the caller |
| `change(Model $subject, Collaborator $new, string $reason): CollaboratorReferral` | supersedes, never mutates: old row -> `superseded`, `effective_to = today`, `superseded_by_id`, `change_reason`, `changed_by`; the new row starts today; the open entitlement is superseded; **no existing ledger row is ever re-pointed** (INV-18); writes an audit row carrying old and new collaborator names (§37, §107) |
| `revoke(Model $subject, string $reason): void` | `revoked`, `effective_to = today`, `commission_eligible = false`: future commission stops, history stays |
| `effectiveOn(Model $subject, CarbonInterface $date): ?CollaboratorReferral` | zero or one row by the date window - **this, not `current()`, is what the engine calls** |
| `resolveCode(string $code): ?Collaborator` | trimmed, case-insensitive, null for unknown without throwing (public admission form) |

**`App\Services\Collaborator\CommissionRuleService`** (Phase 10)

| Method | Guarantees |
|---|---|
| `resolve(Collaborator $c, CommissionScope $scope, CarbonInterface $on, ?Project $p = null): ?RuleResolution` | zero or one rule, keyed off the value date, precedence project override -> collaborator version -> **nothing** ([D-FS-9]); returns the rule row, `rule_source`, and the resolved numbers |
| `createVersion(Collaborator $c, CommissionScope $scope, RuleData $d, string $reason): CollaboratorCommissionSetting` | never UPDATEs a rate: closes the open version at `effective_from - 1 day`, inserts `version + 1` with `supersedes_id`, inside a transaction holding `lockForUpdate` on the collaborator; refuses when a ledger row references the closed version with `transaction_date > effective_to`; `uq_ccs_open` makes two open versions impossible |
| `close(CollaboratorCommissionSetting $r, CarbonInterface $on, string $reason)` / `cancelScheduled(...)` | the §2.18.5 transitions only; cancelling is refused when any ledger row references the version |

**`App\Services\Collaborator\CommissionBaseResolver`** (Phase 10 student, Phase 11 project)

`forStudentPayment(StudentFeePayment $p, CommissionBase $base): BaseResolution` and
`forProjectPayment(ProjectPayment $p, CommissionBase $base, RuleResolution $r): BaseResolution` - read only
the payment row and the document row, return the spine §6.1.4 quadruple, and guarantee that every mode is
"money received bounded by a collectible figure", so commission can never accrue on unreceived money
(INV-19).

**`App\Services\Collaborator\CommissionEntitlementService`** (Phase 10)

| Method | Guarantees |
|---|---|
| `openOrLoad(EntitlementContext $ctx): CollaboratorCommissionEntitlement` | at most one current entitlement per (document, collaborator) - `uq_cce_current`; snapshots the rule row, every global setting consulted, the approval mode, the hold days and both figures into `rule_snapshot`; computes `entitlement_amount` per spine §6.1.5 (NULL = uncapped); returns it **locked** |
| `release(CollaboratorCommissionEntitlement $e, string $amount, string $baseAmount): void` | conditional UPDATE guarded by `chk_cce_cap`; advances `released_amount`, `collected_amount`, `ledger_entry_count`; flips `fully_released` on equality |
| `unrelease(CollaboratorCommissionEntitlement $e, string $amount, string $baseAmountShare): void` | the reversal side of spine §6.3.7: `reversed_amount +=`, `released_amount -=` floored at 0, `collected_amount -=` floored at 0, status back to `open` |
| `supersede(CollaboratorCommissionEntitlement $e, SupersedeContext $ctx, string $reason): CollaboratorCommissionEntitlement` | inserts a successor with `entitlement_amount = max(new_promise, released_amount)`, records the difference in `over_released_amount`, marks the old row `superseded`; **never posts a clawback by itself** |

**`App\Services\Collaborator\LedgerWriter`** (Phase 10)

| Method | Guarantees |
|---|---|
| `post(LedgerEntryDraft $d): LedgerPostResult` | the only insert path (INV-21); composes `dedupe_key` itself (never from a caller); INSERT inside a SAVEPOINT so a 1062 rolls back to the savepoint instead of poisoning the outer transaction, then re-reads by `dedupe_key` and returns `created: false`; writes the wallet delta and the activity row in the same transaction; fires `CommissionCreated` afterCommit for earnings only |
| `isWriting(): bool` | the flag the model `creating` hook checks |

**[D-IMP-5] `dedupe_key` is composed from the source *table token*, never from `CommissionSourceType`.**
A student receipt allocated to an installment line is recorded with `source_type =
student_installment_payment` (§51 lists Student Fee and Student Installment as separate source types) but
its key is still `student_fee_payment:{id}:student_commission:collab:{id}`. Without this rule the same
receipt could produce two rows - one per source_type - and `uq_cle_source` would not catch it, because
`source_type` is part of that index. Keys (spine §2.19):

| Case | `dedupe_key` |
|---|---|
| student earning | `student_fee_payment:{payment_id}:student_commission:collab:{collaborator_id}` |
| project earning | `project_payment:{payment_id}:project_commission:collab:{collaborator_id}` |
| reversal | `payment_reversal:{reversal_id}:reversal:entry:{original_entry_id}` |
| clawback | the same key with the suffix `:clawback` |
| manual adjustment / write-off | `manual:{ulid}` |

**`App\Services\Collaborator\StudentCommissionService`** (Phase 10) and
**`ProjectCommissionService`** (Phase 11)

`handlePayment(StudentFeePayment|ProjectPayment $p, bool $force = false): CommissionOutcome` - the §6.2
sequence in order, at most one earning per (payment, collaborator), a recorded skip reason for every
negative outcome, idempotent under unlimited replay, `CommissionCreated` / `CommissionSkipped` afterCommit.
`$force` bypasses **G2 only**.

**`App\Services\Collaborator\CommissionReversalService`** (Phase 10)

| Method | Guarantees |
|---|---|
| `handleReversal(PaymentReversal $r): CommissionOutcome` | spine §6.3 exactly: cumulative-target pro-rata so the sum undone can never exceed the original by one paisa across any number of partial refunds; releases live allocations on in-flight payouts first (`released_for_reversal`) so a refund is never blocked by a pending withdrawal; splits `delta` into `reverse_now` (unpaid) and `clawback_now` (already paid out); one debit per (reversal, original entry) per purpose; flips a fully reversed unpaid entry **and all of its reversal rows** to `reversed` in the same transaction (INV-15); never mutates a money column |
| `adjust(Collaborator $c, string $signedAmount, string $reason, ?Model $context = null, bool $writeOff = false): CollaboratorCommissionLedgerEntry` | `manual_adjustment` or `write_off`, `calculation_type = manual`, mandatory reason, actor recorded, `collaborator_commissions.create` required; `write_off` additionally requires `collaborator_commissions.approve` and raises a notification |

**`App\Services\Collaborator\CommissionApprovalService`** (Phase 10)

| Method | Guarantees |
|---|---|
| `approve(LedgerEntry $e, User $by): LedgerEntry` | only §2.18.7 transitions; forward-only and **idempotent** (approving an approved entry is a no-op, so a double-clicked bulk action cannot double-count); when `hold_until` is null or past the same transaction continues to `available` and stamps `available_at`, writing **two** activity rows (`approved`, `released`) |
| `reject(LedgerEntry $e, string $reason, User $by): LedgerEntry` | `pending`/`approved` -> `cancelled`, reason mandatory, the amount leaves every bucket, the row stays; the source payment is untouched and stays `cleared` |
| `cancel(LedgerEntry $e, string $reason, User $by): LedgerEntry` | `available` -> `cancelled`, refused when `allocated_amount > 0` |
| `release(LedgerEntry $e): LedgerEntry` | `approved` -> `available` when `hold_until <= today`; the only caller is `commissions:release-held` |
| `approveMany(array $ids, User $by): BulkResult` / `rejectMany(array $ids, string $reason, User $by): BulkResult` | **[D-IMP-6]** explicit ids only (never "everything matching the filter"), de-duplicated, capped at 500 per request, rows locked in ascending id order to match the global lock order, a row whose status moved since the page loaded is **skipped and reported**, never forced; one activity row **per entry** plus one batch summary row (`commission.bulk_approved` / `.bulk_rejected`, properties `{count, skipped, total_amount, entry_ids}`); the whole batch is one transaction so a failure leaves nothing half-approved |

**`App\Services\Collaborator\CollaboratorWalletService`** (Phase 12)

| Method | Guarantees |
|---|---|
| `derive(Collaborator $c): WalletSnapshot` | the canonical SQL of spine §6.5.1 and **the only balance definition in the system** (INV-26); returns the eleven figures plus `identityHolds()` |
| `recalculate(Collaborator $c, bool $persist = true): WalletSnapshot` | idempotent; rewrites the cache only, never a ledger row; stamps `recalculated_at`, `version + 1`; fires `WalletRecalculated` |
| `applyDelta(CollaboratorWallet $w, LedgerDelta $d): void` | called only from `LedgerWriter` and `PayoutService`, inside their transaction, under the wallet row lock; bumps `version`, `last_entry_id`, `last_entry_at` |
| `freeze(Collaborator $c, bool $frozen, ?string $reason): CollaboratorWallet` | blocks new payouts without blocking earning; reason mandatory when freezing; audited |
| `assertConsistent(Collaborator $c): void` | throws when the stored row differs from `derive()`; the test helper `assertWalletMatchesLedger()` calls it |
| `payoutsPaidTotal(Collaborator $c, ?DateRange $r = null): string` | total **paid out**, derived from the spine §6.5.1 canonical SQL over `collaborator_payout_allocations` on `paid` payouts - never `SUM(collaborator_payouts.amount)` and never `status = paid` on the payout row (INV-23). Published so phase-13's P&L and phase-19-23's §99 reports can read a total **without summing money themselves** (F-4.8, INV-26) |

**`App\Services\Collaborator\PayoutService`** (Phase 12)

| Method | Guarantees |
|---|---|
| `request(Collaborator $c, PayoutRequestData $d): CollaboratorPayout` | only when `collaborator.payout_request_enabled` **and** the actor holds `collaborator_portal.payout_request`; status `requested`; the §6.4.2 guard list and allocation run in one transaction; `requested_amount`, `minimum_payout_snapshot`, `available_at_request` stored; `amount` derived from live allocations, **never from the form** (INV-22); auto-approved when `0 < amount <= collaborator.payout_auto_approve_below` |
| `createFor(Collaborator $c, PayoutRequestData $d, User $by): CollaboratorPayout` | the staff path; status `pending`; same guards and allocation |
| `plan(Collaborator $c, string $amount): AllocationPlan` | read-only FIFO preview for wizard step 2 - the exact entries and slices, the running total, and the shortfall; writes nothing |
| `approve(CollaboratorPayout $p, User $by)` | `requested`/`pending` -> `approved`; the policy refuses when `approved_by === created_by` and the actor holds both abilities (spine §9 Accountant row) |
| `markPaid(CollaboratorPayout $p, MarkPaidData $d, User $by)` | requires `approved`; requires `transaction_id` when `finance.payout_reference_required`; `uq_cp_txn(method, transaction_id)` + the status guard make a double submit impossible; allocations stay live; every entry with `allocated_amount = amount` moves `available -> paid` with `paid_at`; the wallet moves reserved -> paid; `paid_by` / `paid_at` / `paid_on` stamped; `PayoutPaid` afterCommit |
| `reject(CollaboratorPayout $p, string $reason, User $by)` | reason mandatory; every allocation released (`payout_rejected`) with the mirror CAS `SET allocated_amount = allocated_amount - :x WHERE id = :id AND allocated_amount >= :x`; entries stay `available` |
| `cancel(CollaboratorPayout $p, string $reason, ?User $by)` | the requester while `requested` (own row only) or staff with `collaborator_payouts.change_status`; allocations released (`payout_cancelled`) |
| `cancelAfterPayment(CollaboratorPayout $p, string $reason, User $by)` | the **only** backward money transition: payout -> `cancelled`, allocations released (`payout_returned`), entries walk `paid -> available` with an `unallocated` audit row; gated by `collaborator_payouts.change_status` **and** `.approve`, reason mandatory (spine R-7) |
| `releaseForReversal(LedgerEntry $e, string $amount, User|null $by): void` | called only by `CommissionReversalService`; releases live allocations on in-flight payouts and cancels a payout left with none |

**`App\Services\Collaborator\CollaboratorStatementService`** (Phase 12)

`build(Collaborator $c, DateRange $range, StatementFilters $f): StatementData` - spine §6.5.5 exactly:
opening balance, the five §56 movement groups with subtotals, closing balance, and it **asserts**
`opening + credits - debits - payouts = closing` (cross-checked against the same expression evaluated as an
opening balance at `to + 1 day`) and **throws rather than render an unbalanced statement**.
`StatementFilters`: date range, student, project, source type, purpose, status, `showTechnicalRows`.
**[D-IMP-7]** the screen, the print view, the PDF and the CSV all render the same `StatementData` - one
builder, four presenters - and FT-44 asserts the four totals agree to the paisa.

| Method | Guarantees |
|---|---|
| `commissionAccruedTotal(Collaborator $c, ?DateRange $r = null): string` | total commission **accrued** (earnings, net of reversals and clawbacks) over the range, derived from the spine §6.5.1 canonical SQL. Published for the same reason as `payoutsPaidTotal()`: phase-13 §6.7.3's P&L and phase-19-23 §6.20's reports call it instead of writing a `SUM()` (F-4.8, INV-26) |

**`App\Services\Collaborator\CommissionReconciliationService`** (Phase 12)

`run(?Collaborator $c, string $runType, bool $repair = false): ReconciliationReport` - the eight checks of
spine §6.5.3 (R1 cache equality, R2 the closed identity, R3 the payout cross-check, R4 allocation
integrity, R5 the bucket cross-foot, R6 reversal groups, R7 entitlement releases, R8 no silent skips); a
pure reader unless `$repair`; writes one `collaborator_wallet_reconciliations` row per collaborator per run
whether the result is ok or not; never repairs a structural failure (spine §6.5.4).

### 6.4 Installment behaviour

| Question | Decided behaviour |
|---|---|
| What triggers commission | **only** a `student_fee_payments` row. A three-line plan with no receipts produces zero ledger rows (FT-31). Building, editing or waiving a plan never touches the engine |
| How many commissions | one per **received** installment: 3 x 10,000 at 10% = 3 x 1,000.00 (§42, §120.4). Each row carries its own `student_fee_payment_id` and `student_fee_installment_id` |
| Duplicate safety across lines | the guard is the **receipt**, not the line: one receipt -> at most one earning, whichever line it pays ([D-IMP-5]) |
| Source type | `student_installment_payment` when `student_fee_installment_id` is not null, `student_fee_payment` otherwise - a reporting distinction only (§51) |
| A receipt that over-pays its line | `PaymentService` caps the allocation to the line's remainder; the surplus lands on the charge and is commissionable only up to the remaining collectible (spine §6.6 row 5) |
| A receipt with no line (ad-hoc) | allowed; `student_fee_installment_id` null; identical commission treatment |
| Fixed commission across installments | branch C: one promise per commission document, released in proportion to money collected, residual on the final receipt - 2,000 over 3 x 10,000 = 666.67 + 666.66 + 666.67 = **2,000.00 exactly** ([D-FS-10]); `on_first_payment` and `per_payment` remain per-collaborator options and are snapshotted, so changing the mode never rewrites the past |
| A fourth receipt after the promise is exhausted | **no row**, skip reason `entitlement_cap_reached` |
| Waiving a line | a `student_fee_discounts` row (`waiver`) - it reduces what is collectible, never what was earned |
| A re-paid installment after a refund | `collected_amount` was reduced by the refunded share (spine §6.3.7), so the new receipt earns correctly instead of being refused as "cap reached" |
| Reminders and overdue | `fees:installment-reminders` (09:00) and `fees:mark-overdue` (01:00) are Phase 18 behaviour; neither ever touches the ledger |

### 6.5 Discount and scholarship interaction

Discounts are append-only `student_fee_discounts` rows with a signed `amount` (reductions negative) and a
mandatory reason; scholarships feed `scholarship_amount`, everything else `discount_amount`; both reduce
`net_amount`, and `chk_sf_discount_ceiling` stops the total exceeding the gross.

| Base in force | Effect of a discount / scholarship **before** any payment | Effect **after** a payment exists |
|---|---|---|
| `paid` (default) | nothing to do: the next receipt's remaining collectible is simply smaller. Example §43: 30,000 - 5,000 = net 25,000; 10% of the 25,000 collected = **2,500.00** | **nothing retroactive.** Past commissions were computed on money received; only the remaining collectible shrinks, so later receipts may yield `base_zero` / `overpayment_only` and **no row**. No clawback |
| `net_after_discount` | the entitlement has not been opened yet, so the promise is simply computed from the new net | the open entitlement is **superseded** with the new figures, `entitlement_amount = max(new_promise, released_amount)`, the difference recorded in `over_released_amount`, which surfaces on the discrepancy queue (§8.8) for a human decision. **No automatic clawback** - no money left the company |
| `gross` | unaffected: the promise is `rate x gross`; the discount only changes the collectible denominator | the entitlement is superseded with the new `collectible_amount`; the promise stands, so the remaining releases simply arrive faster per rupee collected |
| `total_value` / `milestone` (project) | same as `net_after_discount`, driven by `projects.net_value` / `project_milestones.amount` | same supersede + `over_released_amount` path (spine §6.6 row 8) |

Wiring rule, owned by Phase 18 and asserted by a test here: `StudentFeeService::addDiscount()` must, in the
**same transaction**, call `CommissionEntitlementService::supersede()` for every open entitlement on the
charge (or its admission) whose snapshotted `commission_base` is document-level - and must **not** call it
when the base is `paid`, because there is nothing to re-promise. A `referral_discount` is an ordinary
discount: it changes the fee, never the commission rate. A scholarship behaves identically to a discount
for commission purposes; the split exists for institute reporting (§78). A discount row is never edited: it
is reversed by an opposite-signed row (`uq_sfd_reverses` allows that at most once).

### 6.6 Reversal flows triggered by a refund

```
Payments register -> Refund modal (mandatory reason, ReversalType, amount <= unrefunded remainder)
   PaymentService::refund()  [one transaction]
     reversal_no from DocumentNumberService
     INSERT payment_reversals (idempotency_key UNIQUE)
     conditional UPDATE payment.refunded_amount  (INV-9)
     payment.status -> partially_refunded | refunded | voided | bounced
     charge caches recomputed
     approval_status = not_required | pending            <- finance.refund_approval_required / _threshold
   afterCommit: PaymentReversalRecorded
        |
        +-- not_required -> Listener QueueCommissionReversal -> Job ProcessCommissionReversal
        +-- pending      -> Notification RefundAwaitingApproval to payment_reversals.approve holders
                            (the job is dispatched only by approveReversal(); a rejection rolls the
                             refunded_amount increment back and posts nothing)
        v
CommissionReversalService::handleReversal()   [one transaction, fixed lock order]
  for each earning entry E of the payment (purpose student_commission|project_commission, status <> cancelled):
    target_undone = round(E.amount * payment.refunded_amount / payment.amount, 2)   <- cumulative, never drifts
    delta         = target_undone - (E.reversed_amount + E.clawed_back_amount);  delta <= 0 -> skip (idempotent)
    release live allocations on in-flight payouts (released_for_reversal); cancel an emptied payout
    paid_portion  = live allocations on PAID payouts;  reversible = E.amount - reversed - clawed - paid_portion
    reverse_now   = min(delta, reversible);  clawback_now = delta - reverse_now
    reverse_now  > 0 -> one debit, purpose reversal,  status = E.status, rule columns copied from E
    clawback_now > 0 -> one debit, purpose clawback, status = available (offset_future) | cancelled (write_off)
    entitlement unreleased; wallet delta; E fully reversed & unallocated -> E and all its reversal rows -> reversed
  afterCommit: CommissionReversed / CommissionClawedBack -> collaborator notification
```

| Case | Outcome |
|---|---|
| Full refund of a 10,000 receipt with a 1,000 commission | one `-1,000.00` debit referencing the original and the reversal row; the original byte-identical on every money column; both rows `reversed`; available back to `0.00` (§120.5) |
| Partial refund 4,000 of 10,000 | one `-400.00` debit; the original stays `available` with `reversed_amount = 400.00`; a payout may allocate at most 600.00 |
| Three partial refunds 3,333 / 3,333 / 3,334 | `333.30 / 333.30 / 333.40` = **1,000.00 exactly** |
| Refund larger than the receipt | blocked in the Form Request against the remainder recomputed under lock; `chk_*_refund_ceiling` is the DB backstop |
| Refund while a payout is in flight | the allocation is released first, the payout recomputed or cancelled with a reason; the refund is never blocked |
| Refund after the commission was paid out | the original stays `paid` for ever; a `clawback` debit lands in `available`; the wallet legitimately goes negative; new payouts refused while `available <= 0`; recovery is a `manual_adjustment` credit, a loss is a `write_off` (permissioned, notified) |
| Void of a mis-keyed receipt | full-amount `void` reversal -> full commission reversal -> re-enter with a new idempotency key: three payment rows, one reversed commission |
| Bounced cheque | `bounced_instrument`, identical mechanics, separate reporting bucket |
| Reversal job runs before its earning | stays `queued` with detail "earning not evaluated yet"; the sweeper retries after the earning commits; `source_commission_missing` once the earning is known to have been skipped; `not_applicable` when the payment itself was skipped |

---

## 7. Routes

All admin routes additionally carry Phase 1's `auth`, `active`, `panel:admin`; panel routes carry their own
`panel:*`. `module:*` is stated where it differs from the file-level group. **+** marks a route this plan
adds to spine §7, each with its justification; everything else is spine §7 verbatim.

### 7.1 Student fees and receipts (routes owned by Phase 10, most screens by Phase 18)

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

### 7.2 Project payments (Phase 11; the finance register is Phase 13)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/project-payments` | `admin.project-payments.index` | **`module:project_payments`**, `can:project_payments.view_any` - every project-payment route, admin and client, carries the `project_payments` slug; `payments` stays the umbrella for phase-13's cross-source register only (F-6.1) |
| GET `/admin/project-payments/{payment}` | `admin.project-payments.show` | `can:project_payments.view` |
| GET `/admin/projects/{project}/payments/preview` | `admin.project-payments.preview` | `can:project_payments.create` |
| POST `/admin/projects/{project}/payments` | `admin.project-payments.store` | `can:project_payments.create`, `throttle:20,1` |
| GET `/admin/project-payments/{payment}/receipt` | `admin.project-payments.receipt` | `can:project_payments.print` |
| POST `/admin/project-payments/{payment}/void` | `admin.project-payments.void` | `can:project_payments.change_status` |
| POST `/admin/project-payments/{payment}/refund` | `admin.project-payments.refund` | `can:payment_reversals.create` |
| GET `/admin/project-payments/export/{format}` | `admin.project-payments.export` | `can:project_payments.export` |

### 7.3 Reversals, referrals, commission rules (Phase 10)

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
| **+** GET `/admin/collaborators/{collaborator}/commission-rules/preview` | `admin.commission-rules.preview` | `can:collaborator_commission_settings.create` - the wizard step-4 comparison of spine §8.4 needs a read-only endpoint; writes nothing |

### 7.4 Commissions, wallets, reconciliation, payouts, statements (Phase 12, except the commission screens which are Phase 10)

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/admin/commissions` | `admin.commissions.index` | `module:collaborator_commissions`, `can:collaborator_commissions.view_any` |
| GET `/admin/commissions/{entry}` | `admin.commissions.show` | `can:collaborator_commissions.view` |
| POST `/admin/commissions/{entry}/approve` | `admin.commissions.approve` | `can:collaborator_commissions.approve` |
| POST `/admin/commissions/{entry}/reject` | `admin.commissions.reject` | `can:collaborator_commissions.reject` (also serves `available -> cancelled`; the service picks the transition) |
| POST `/admin/commissions/bulk-approve` | `admin.commissions.bulk-approve` | `can:collaborator_commissions.approve`, `throttle:10,1` |
| **+** POST `/admin/commissions/bulk-reject` | `admin.commissions.bulk-reject` | `can:collaborator_commissions.reject`, `throttle:10,1` - §53 and the `APPROVE` preset give reject equal standing; the queue screen would otherwise force 200 single clicks |
| POST `/admin/commissions/adjustments` | `admin.commissions.adjustments.store` | `can:collaborator_commissions.create` |
| POST `/admin/commissions/evaluate` | `admin.commissions.evaluate` | `can:collaborator_commissions.approve`, `throttle:5,1` |
| GET `/admin/commissions/export/{format}` | `admin.commissions.export` | `can:collaborator_commissions.export` |
| GET `/admin/commission-discrepancies` | `admin.commission-discrepancies.index` | `can:collaborator_commissions.view_any` |
| **+** POST `/admin/commission-discrepancies/{entitlement}/accept` | `admin.commission-discrepancies.accept` | `can:collaborator_commissions.approve` - spine §8.8 names "accept and note it" as one of the two actions; without a route `over_released_amount` can never be cleared (spine R-6). Writes a note and `closed_on`, never a money row |
| GET `/admin/commission-skips` | `admin.commission-skips.index` | `can:collaborator_commissions.view_reports` |
| GET `/admin/collaborator-wallets` | `admin.wallets.index` | `module:collaborator_wallets`, `can:collaborator_wallets.view_any` |
| GET `/admin/collaborator-wallets/{collaborator}` | `admin.wallets.show` | `can:collaborator_wallets.view` |
| POST `/admin/collaborator-wallets/{collaborator}/recalculate` | `admin.wallets.recalculate` | `can:wallet_reconciliation.change_status` |
| POST `/admin/collaborator-wallets/{collaborator}/freeze` | `admin.wallets.freeze` | `can:collaborator_payouts.change_status` (payload `{frozen: bool, reason}` - the same route unfreezes) |
| GET `/admin/wallet-reconciliations` | `admin.wallet-reconciliations.index` | `can:wallet_reconciliation.view_any` |
| GET `/admin/wallet-reconciliations/{reconciliation}` | `admin.wallet-reconciliations.show` | `can:wallet_reconciliation.view` |
| POST `/admin/wallet-reconciliations/run` | `admin.wallet-reconciliations.run` | `can:wallet_reconciliation.change_status`, `throttle:3,1` |
| GET `/admin/payouts` | `admin.payouts.index` | `module:collaborator_payouts`, `can:collaborator_payouts.view_any` |
| GET `/admin/payouts/create` | `admin.payouts.create` | `can:collaborator_payouts.create` |
| **+** GET `/admin/payouts/plan` | `admin.payouts.plan` | `can:collaborator_payouts.create` - the FIFO allocation preview of wizard step 2 (spine §8.6); read-only |
| POST `/admin/payouts` | `admin.payouts.store` | `can:collaborator_payouts.create` |
| GET `/admin/payouts/{payout}` | `admin.payouts.show` | `can:collaborator_payouts.view` |
| POST `/admin/payouts/{payout}/approve` | `admin.payouts.approve` | `can:collaborator_payouts.approve` |
| POST `/admin/payouts/{payout}/reject` | `admin.payouts.reject` | `can:collaborator_payouts.reject` |
| POST `/admin/payouts/{payout}/mark-paid` | `admin.payouts.mark-paid` | `can:collaborator_payouts.change_status` |
| POST `/admin/payouts/{payout}/cancel` | `admin.payouts.cancel` | `can:collaborator_payouts.change_status` |
| POST `/admin/payouts/{payout}/cancel-after-payment` | `admin.payouts.cancel-after-payment` | `can:collaborator_payouts.change_status`, `can:collaborator_payouts.approve` |
| GET `/admin/payouts/{payout}/voucher` | `admin.payouts.voucher` | `can:collaborator_payouts.print` |
| GET `/admin/payouts/export/{format}` | `admin.payouts.export` | `can:collaborator_payouts.export` |
| **+** GET `/admin/collaborators/{collaborator}/payout-accounts` | `admin.payout-accounts.index` | `can:collaborator_payouts.view` - the wizard must choose a destination; masked display only |
| **+** POST `/admin/payout-accounts/{account}/verify` | `admin.payout-accounts.verify` | `can:collaborator_payouts.approve` - spine §2.15 carries `is_verified` / `verified_by` / `verified_at`; without a route those columns are dead and §55's protection is unenforceable |
| GET `/admin/collaborators/{collaborator}/statement` | `admin.statements.show` | `can:collaborator_commissions.view_financial` |
| GET `/admin/collaborators/{collaborator}/statement/export/{format}` | `admin.statements.export` | `can:collaborator_commissions.export` (`format` in `pdf\|csv\|print`) |

### 7.5 Collaborator panel (`auth`, `active`, `panel:collaborator`, `module:collaborators`) - Phase 12

| Method + URI | Route name | Middleware |
|---|---|---|
| GET `/collaborator/wallet` | `collaborator.wallet.index` | `can:collaborator_portal.wallet` |
| GET `/collaborator/commissions` | `collaborator.commissions.index` | `role_or_permission:collaborator_portal.student_commission\|collaborator_portal.project_commission` |
| GET `/collaborator/commissions/{entry}` | `collaborator.commissions.show` | same + policy ownership (**404**, not 403) |
| GET `/collaborator/statement` | `collaborator.statement.index` | `can:collaborator_portal.statement_download` |
| GET `/collaborator/statement/export/{format}` | `collaborator.statement.export` | `can:collaborator_portal.statement_download` |
| GET `/collaborator/payouts` | `collaborator.payouts.index` | `can:collaborator_portal.payouts` |
| **+** GET `/collaborator/payouts/{payout}` | `collaborator.payouts.show` | `can:collaborator_portal.payouts` + policy ownership (404) - §36 and §54 require visible payout history; the register alone cannot show allocations and the status timeline |
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
| GET `/client/payments` | `client.payments.index` | `panel:client`, **`client.context`**, **`module:project_payments`**, `can:client_portal.payments` (F-6.1, D31) |
| GET `/client/payments/{payment}/receipt` | `client.payments.receipt` | **`client.context`**, **`module:project_payments`**, `can:client_portal.payments` + policy ownership (F-6.1, D31) |

**The two `client.*` rows are contributed, not declared.** Phase 5 owns `routes/client.php` and every
`client.*` route **name** (**D31**); these two screens are appended through its `ClientPortalRegistry`,
because Laravel's last registration silently wins and two URIs under one name is a live bug. The
`student.*` rows above are declared here.

Controllers: `Admin/Institute/FeePaymentController`, `Admin/Finance/ProjectPaymentController`,
`Admin/Finance/PaymentReversalController`, `Admin/Collaborator/{ReferralController,
CommissionRuleController, CommissionController, CommissionDiscrepancyController, CommissionSkipController,
WalletController, WalletReconciliationController, PayoutController, PayoutAccountController,
StatementController}`, `Collaborator/{WalletController, CommissionController, StatementController,
PayoutController, PayoutAccountController, StudentController, ProjectController}`,
`Student/FeeController`, `Client/PaymentController`. Form Requests in `app/Http/Requests/Finance/` and
`/Collaborator/`: `RecordFeePaymentRequest`, `RecordProjectPaymentRequest`, `RefundPaymentRequest`,
`VoidPaymentRequest`, `StoreCommissionAdjustmentRequest`, `BulkApproveCommissionsRequest`,
`BulkRejectCommissionsRequest`, `EvaluateCommissionsRequest`, `StoreCommissionRuleRequest`,
`ChangeReferralRequest`, `RequestPayoutRequest`, `StorePayoutRequest`, `MarkPayoutPaidRequest`,
`RejectPayoutRequest`, `CancelPayoutRequest`, `StorePayoutAccountRequest`, `RunReconciliationRequest`.
Every request that performs a discretionary act validates `reason` as `required|string|min:5|max:255`.

---

## 8. UI screens

House rules (Phase 1 §9, `CLAUDE.md` §6) apply to all of them: `x-ui.*` components only - this plan adds no
new component to the Phase 1 set, shared markup lives as a Blade partial under the owning panel folder;
search + filters + sortable headers + pagination + empty state + skeleton loader on every list; money
right-aligned with `tabular-nums` through `money()`; a toast on every write; `x-ui.confirm` on every
irreversible action; tables inside `overflow-x-auto`; light and dark.

**No Kanban and no calendar anywhere in phases 10-12** - requirement section F never asks for one (§18 asks
for a lead Kanban, §22 a task Kanban), so a payout board would be invented scope. The only wizards are the
three the requirement implies: record payment, commission rule version, payout.

### 8.1 Record-payment modal (wizard, 4 steps) - student and project variants, one partial

**Purpose.** Receipt money in a way that cannot double-post and shows the cashier the commission
consequence before it happens. **Components.** `x-ui.modal`, `x-ui.form.input`, `.select`, `.file`,
`x-ui.badge`, `x-ui.skeleton`, `x-ui.confirm`.
**Steps.** (1) amount + installment line (or milestone / invoice on the project variant), with the line's
remaining amount shown; (2) method, reference, `paid_on`, with a back-date warning naming the limit and the
ability required; (3) attachment + notes; (4) **review with a read-only commission preview** from
`*.preview`: "this receipt will credit COL-1024 PKR 1,000.00 - rule v3, 10.0000% of 10,000.00, base:
actual paid", or the exact reason none will be created ("student has no collaborator").
**Duplicate handling.** A hidden `idempotency_key` ULID generated **once per modal open** and never
regenerated on retry; the submit button disables on submit; a `duplicate_fingerprint` match turns step 4
amber, names the existing receipt, and requires the `confirm_duplicate` checkbox.
**Edge states.** A fully paid charge: "nothing outstanding - recording this will create an advance". A
cancelled charge disables the action entirely.

### 8.2 Payments register (student and project variants share one partial)

**Filters** (`x-ui.filter-bar`): date range on `paid_on` with an on-screen toggle to filter `recorded_at`
instead, branch, course, batch (project variant: client, project, milestone), method, status, collaborator,
commission state, "has refund", amount range, free text on receipt number / student or client / reference.
**Columns.** Receipt # - `paid_on` (+ a "back-dated" chip when it differs from `recorded_at`) - student or
client - charge or project - method - **amount** - refunded - **net received** - commission state badge
(`queued` / `processed` + the amount / `skipped` + the reason on hover / `failed`) - cashier - row actions
(view, print receipt, refund, void; each permission-gated, each behind `x-ui.confirm` with a **mandatory
reason** field).
**Footer.** Sum of amount, refunded and net received over the filtered set, labelled with the filter in
force. **Empty state.** "No receipts in this range" + Record payment.

### 8.3 Commission ledger index + the pending approval queue

One screen, two presets selected by `x-ui.tabs`: **All** and **Pending approval** (`status = pending`,
oldest first, the default landing tab when the user holds `collaborator_commissions.approve`).
**Filters.** Collaborator, status, purpose, source type, scope, `transaction_date` range, student, project,
amount range, "only reversed", "only adjustments", "unapproved older than N days".
**Columns.** `CLE-{id}` - `transaction_date` - collaborator - source (deep link to the exact receipt) -
student / project - base (mode chip + base amount) - rate or fixed - **signed amount** - status badge -
reversed / clawed back - allocated - payout link.
**Bulk approve / reject.** Checkbox selection (header checkbox selects the loaded page only, never "all
matching"), a sticky action bar showing the count and **the total being approved**, and a confirm dialog
that names that total; reject additionally requires a reason textarea. The request sends explicit ids.
Rows whose status moved since the page loaded come back in a "skipped" toast listing their references, and
are never force-approved. One audit entry per entry plus one batch summary row ([D-IMP-6]).
**Detail page.** `x-ui.tabs`: **Calculation** - the `rule_snapshot` trace rendered as a readable formula
(base, rate, entitlement total, released before, released now, rounding residual, a `base_fallback`
warning when set); **Related** - the original or its reversals, the entitlement, the rule version, the
payout; **History** - the activity rows for this entry (created, approved, released, allocated, paid,
reversed) with actor, IP and reason. Approve / reject / cancel buttons sit in the header, permission-gated.
**Empty state.** "No commissions in this range" (All) / "Nothing waiting for approval" (Pending).

### 8.4 Commission rule timeline (per collaborator)

One card per immutable version on a vertical effective-dated timeline: dates, type, rate or fixed amount,
base, release mode, fee-type scope, floors and caps, who changed it, the reason. The current open version
renders **locked** with a tooltip explaining that a rate change is a new version (INV-17); only "Close" is
offered. **Wizard "Add new version":** (1) type + rate / fixed amount; (2) base + fee types + caps +
floors; (3) `effective_from` + **mandatory reason**; (4) preview from `commission-rules.preview` - "a PKR
10,000 receipt today earns 1,000.00 under the current rule and 1,500.00 under this one" - plus the plain
warning that nothing already posted will change. **Empty state.** "No commission rule yet - this
collaborator earns nothing until one exists" (the honest statement of [D-FS-9]).

### 8.5 Wallet index and detail

**Index.** Columns: collaborator (+ status chip, `withTrashed`) - **available** (rose when negative) -
pending - reserved - paid - lifetime - entries - last entry - reconciliation chip (`ok` / `drift` /
`failed`). Filters: status, reconciliation status, "available > 0", "negative balance", frozen, search.
Footer: the company's total liability (available + pending). Empty state: "No collaborators yet".
**Detail.** Six `x-ui.stat-card`s - Available (rose when negative), Pending, Reserved, Paid, Lifetime,
Total reversed - and **the identity printed on the page**: `lifetime 50,000.00 = pending 0.00 + available
30,000.00 + reserved 0.00 + paid 20,000.00`.
**Reconciliation banner.** Green chip "proven equal to the ledger, 01:30 today"; on drift a **rose banner**
showing the drift amount, a link to the run and a Recalculate button behind confirm - and while the status
is `drift` **every figure on the page is the derived one, labelled as such** (spine §6.5.4).
**Tabs.** Ledger - Payouts - Statement - Rules - Reconciliation history.
**Negative-balance panel.** When available is below zero: the clawback rows that caused it, each with its
source receipt, and the plain sentence "recovered from future earnings" or "written off" per the policy.
Freeze / unfreeze sits in the header behind confirm with a mandatory reason.

### 8.6 Payout wizard, register, detail

**Wizard.** (1) collaborator + requested amount, with "available 30,000.00" and the minimum-payout rule
shown and enforced server-side; (2) **FIFO allocation preview** from `payouts.plan` listing the exact
entries and slices to be consumed with a running total, and a clear shortfall message ("available
30,000.00, requested 40,000.00 - short by 10,000.00"); (3) destination account, chosen from the
collaborator's accounts and rendered **masked, last 4 only**, with an unverified-account warning; (4)
review + confirm. The amount field is read-only after allocation - it is derived (INV-22).
**Register.** Status tabs (Requested / Pending / Approved / Paid / Rejected / Cancelled) over one table:
payout # - collaborator - **amount** - entry count - method chip - requested / approved / paid dates -
transaction id - actions (approve, reject, mark paid, cancel, cancel-after-payment, voucher; each
permission-gated, each behind confirm, each with a mandatory reason where §2.18.8 demands one). Filters:
collaborator, status, method, date range, amount range. Empty state: "No payouts yet" + Create payout.
**Detail.** The allocation table (entry reference, `entry_transaction_date`, slice, released flag +
reason), the status timeline with actor, timestamp and reason per step, the masked destination, the
transaction id, the receipt attachment, and a printable voucher (`collaborator_payouts.print`).
**Mark-paid modal.** Method (pre-filled from the payout), `transaction_id` (required when
`finance.payout_reference_required`), `paid_on` (never future), optional receipt upload.

### 8.7 Statement (§56)

Opening-balance row; movements grouped into the five §56 lines - student commissions, project commissions,
manual adjustments, reversals, payouts - each with a subtotal; closing-balance row; and a **proof footer**
`opening 12,400.00 + credits 9,000.00 - debits 1,000.00 - payouts 2,000.00 = closing 18,400.00`.
**Filters** per §56: date range (Phase 2 `DateRange` presets + custom), student, project, source, status,
plus a "show technical rows" toggle defaulting from `collaborator.statement_show_technical_rows`.
**Columns.** Date (`transaction_date` / `paid_on`) - reference (`CLE-…` / `PO-…`) - description (source
document, deep-linked where the viewer may see it) - rate / base - credit - debit - running balance.
**Exports.** `print` (a clean print stylesheet, no sidebar, company header from settings), `pdf`
(dompdf, Phase 13's dependency, landscape, repeated header, page numbers, "generated at" + the filter in
force), `csv` (the same rows, `Content-Disposition: attachment`, UTF-8 BOM for Excel). A range wider than
12 months or over 5,000 rows is handed to `BuildCollaboratorStatementExport` and delivered by
notification. All four presenters render one `StatementData` ([D-IMP-7]).
**Empty state.** "No movements in this range - opening and closing balance both 12,400.00" (an empty
statement still states the balances; a blank page would look like a bug).

### 8.8 Commission discrepancies and skip report

**Discrepancies.** Every entitlement with `over_released_amount > 0` (a post-payment discount or a
downward project-value revision): collaborator, document, promise, released, over-released, the supersede
reason, `opened_on`, and the two actions a human may take - post a `manual_adjustment` (opens the
adjustment modal pre-filled with the difference and a reason), or accept and note it. Without this screen
the states of spine §6.6 rows 4 and 8 rot unresolved (spine R-6). Empty state: "No discrepancies".
**Skip report.** `commission_skip_reason` grouped with counts and summed payment amounts over a date range
("14 receipts earned nothing: collaborator inactive - 3; fee type not commissionable - 11"), filterable by
collaborator, reason, source side and range, each row drilling into the receipts, with an "Evaluate
selected" action (`collaborator_commissions.approve`, mandatory reason) that calls
`commissions:evaluate` for exactly those ids. This is how a misconfigured collaborator is found before the
partner complains.

### 8.9 Reconciliation screen

Per collaborator: expected versus stored per bucket with the difference, the identity and payout
cross-check columns, the five structural flags (R4 allocation mismatches, orphan allocations, R5 cross-foot
diff, R6 reversal group mismatches, R7 entitlement mismatches), `duration_ms`, run history, and "Run now"
behind confirm (`wallet_reconciliation.change_status`, `throttle:3,1`). A `failed` run renders rose with
the offending entry / entitlement / payout ids linked and **offers no repair button** (spine §6.5.4); a
`drift` run offers Recalculate. Filters: status, run, date range, collaborator.

### 8.10 Document trails

**Charge detail** (Phase 18) and **project detail** (Phase 11/13) each gain a **Commission trail** tab:
the ledger rows this document produced, read-only, gated by `collaborator_commissions.view_financial`,
showing reference, date, base, rate, amount, status and the receipt that caused it. The printable fee slip
renders §41's commission percentage and amount **from the entitlement and the ledger at print time** -
never from duplicated columns on the charge ([D-FS-4], §109).

### 8.11 Collaborator panel screens

Read-only versions of 8.3, 8.5, 8.6 and 8.7 scoped to self, plus the §57 student list (name, course, batch,
registration date, status, total paid, commission earned) and the §58 project list (project, client,
value, received, rate, commission earned, status). **Every money column is gated per field** by the
matching `collaborator_portal.*` permission and a column the viewer lacks is **absent from the response**,
not rendered blank. The payout request button renders only when `collaborator.payout_request_enabled` **and**
`collaborator_portal.payout_request`, and the server re-checks both. **[D-IMP-8] The collaborator panel
never shows `commission_skip_reason`** - why the company earned nothing for a partner is internal
configuration and a partner-relations conversation, not a UI string (§12.2 Q3).

### 8.12 Dashboard widgets (registered into Phase 2's `DashboardRegistry`)

The **eight** widgets this contract registers: `ProjectPaymentsThisMonthWidget`,
`CommissionPendingApprovalWidget`, `CommissionPaidThisMonthWidget`, `WalletLiabilityWidget`,
`PayoutQueueWidget`, `WalletDriftWidget`, `CommissionBySourceChartWidget`, `CollaboratorLeaderboardWidget`.
Each declares `module()` and `permission()` so Phase 2's gating applies unchanged, each reads **only**
through `CollaboratorWalletService` / `CollaboratorStatementService` (INV-26), and each renders charts
through Phase 2's `x-ui.chart`.

**`FeeCollectedTodayWidget`, `PendingFeesWidget` and `OverdueFeesWidget` are not registered here
(F-8.3).** The three fee widget keys belong to **Phase 18**, which owns the fee screens and
`StudentFeeService`; a `DashboardRegistry` key may be declared exactly once, so this contract declares
none of the three.

---

## 9. Data isolation

Every rule is an Eloquent **global scope plus a Policy check** - never a hidden form field
(`CLAUDE.md` §1.10) - and every rule has a feature test asserting the status code **and** the absence of
the forbidden columns from the response body.

**[D-IMP-9] `BelongsToAuthenticatedCollaborator`** is applied to `CollaboratorCommissionLedgerEntry`,
`CollaboratorCommissionEntitlement`, `CollaboratorCommissionSetting`, `CollaboratorWallet`,
`CollaboratorWalletReconciliation`, `CollaboratorPayout`, `CollaboratorPayoutAllocation`,
`CollaboratorPayoutAccount`, `CollaboratorReferral` **and both payment tables**. It binds
`where collaborator_id = :id` when `App\Support\CollaboratorContext::currentId()` returns non-null, which
happens only when the authenticated user has a linked `collaborators.user_id` row **and** the current route
name starts with `collaborator.`. Admin screens therefore see everything their permissions allow without
`withoutGlobalScope` appearing anywhere, and a collaborator who is also staff cannot leak across panels.
Defence in depth: every collaborator-panel controller **also** filters explicitly by the resolved id, and
the policy asserts ownership; route-model binding failures return **404, not 403**, so ids cannot be
probed. A test asserts the scope is registered on all eleven models.

| Role | Exact query scoping |
|---|---|
| **Super Admin** | Unrestricted, still subject to module gating (a disabled module 403s Super Admin too) |
| **Admin** | Unrestricted within the permissions Phase 1 §5 grants |
| **Accountant** | Unrestricted **read** on every table here; writes only where the permission is granted; money columns additionally require `view_financial`. The policy refuses to approve a payout the same user created (`approved_by <> created_by`) whenever both abilities are held |
| **Institute Manager / Course Coordinator** | `student_fees`, `student_fee_installments`, `student_fee_discounts`, `student_fee_payments`: `where branch_id = user.branch_id OR branch_id IS NULL` (D11). **No access to any `collaborator_*` table** (403) |
| **Receptionist** | `student_fee_payments`: `create` only; the index additionally scoped to `created_by = auth()->id() OR branch_id = user.branch_id`. No commission, wallet, payout or reversal access (403) |
| **Collaborator** | The global scope above. Payment rows expose a whitelisted column list only - no other student's contact data, no project cost fields - and each money column is withheld unless the matching `collaborator_portal.*` permission is held (§57, §58, §59). `commission_skip_reason`, `commission_skip_detail`, `created_by`, internal notes and `rule_snapshot` are never exposed |
| **Student** | `student_fees`, `student_fee_installments`, `student_fee_payments`: `where student_id = auth()->user()->student->id`, selected through an explicit column list that **omits** `collaborator_id`, `collaborator_referral_id`, `commission_state`, `commission_skip_reason` and every commission column. Zero access to ledger, entitlement, wallet, payout tables (403) |
| **Client** | `project_payments`: `where client_id = auth()->user()->client->id`, with `collaborator_id` and the commission columns omitted. Zero access to ledger, wallet, payout tables (403) |
| **Teacher** | No access to any table in this contract - every route 403 |
| **Branch (D11)** | When `users.branch_id` is set, institute-side queries add `where branch_id IS NULL OR branch_id = user.branch_id`. Collaborator ledger, wallet and payouts are **global** - a collaborator is not branch-bound |
| **Module gating** | Disabling `collaborator_commissions`, `collaborator_wallets`, `collaborator_payouts`, `student_fees`, `project_payments`, `payment_reversals` or `payments` 403s those routes for everyone via Phase 1's `Gate::before`, while every row and every queued job stays intact (spine §6.6 row 22) |

Policies: `StudentFeePolicy`, `StudentFeePaymentPolicy` (no `update`, no `delete`; `void` under
`change_status`), `ProjectPaymentPolicy`, `PaymentReversalPolicy`, `CollaboratorReferralPolicy`,
`CollaboratorCommissionSettingPolicy` (no `update`, no `delete`),
`CollaboratorCommissionLedgerEntryPolicy` (no `update`, no `delete`; `approve` / `reject` / `cancel`),
`CollaboratorWalletPolicy`, `CollaboratorPayoutPolicy` (including the same-user-approval refusal and the
"own + requested only" cancel), `CollaboratorPayoutAccountPolicy`, `CollaboratorWalletReconciliationPolicy`.

---

## 10. Events, notifications, jobs, scheduled tasks

### 10.1 Events and listeners

All dispatched through `DB::afterCommit()` - a rolled-back transaction can never notify anyone (INV-20).

| Event | Fired by | Listeners |
|---|---|---|
| `StudentFeePaymentRecorded` | `PaymentService::recordStudentFeePayment` | `QueueStudentFeeCommission` (sync, dispatches the job), `NotifyStudentOfFeePayment` (queued) |
| `ProjectPaymentRecorded` | `PaymentService::recordProjectPayment` | `QueueProjectPaymentCommission` (sync), `NotifyClientOfProjectPayment` (queued) |
| `PaymentReversalRecorded` | `PaymentService::refund` / `void` | `QueueCommissionReversal` (sync, **only when `approval_status = not_required`**), `NotifyRefundApprovers` (queued, when `pending`) |
| `PaymentReversalApproved` | `PaymentService::approveReversal` | `QueueCommissionReversal` |
| `PaymentReversalRejected` **(F-4.9 - the rejection leg)** | `PaymentService::rejectReversal` | `RecomputeCachesAfterReversalRejected` (queued): recomputes the document caches that a rejected refund invalidates - phase-13's `invoices.paid_amount` / `refunded_amount` / `balance_amount` from the one canonical SQL over `project_payments` (**D40**, never `increment()`), and the charge's five `student_fees` caches. **The payment's own `refunded_amount` rollback and status restore are NOT done here**: `rejectReversal()` already performs them inside its own transaction under the payment's row lock (§6.3), so an `afterCommit` listener doing it again would decrement twice. `NotifyReversalRequester` (queued) |
| `CommissionCreated` | `LedgerWriter::post` (earnings only) | `NotifyCollaboratorOfCommission` |
| `CommissionSkipped` | the two engines | `LogCommissionSkip` (writes the single activity row of §6.2) |
| `CommissionApproved` / `CommissionRejected` / `CommissionReleased` | `CommissionApprovalService` | `NotifyCollaboratorOfApproval` (approved only) |
| `CommissionReversed` / `CommissionClawedBack` | `CommissionReversalService` | `NotifyCollaboratorOfReversal` |
| `CommissionAdjusted` | `CommissionReversalService::adjust` | `RecalculateWalletAfterAdjustment` (dispatches `RecalculateCollaboratorWallet`) |
| `PayoutRequested` / `PayoutApproved` / `PayoutPaid` / `PayoutRejected` / `PayoutCancelled` | `PayoutService` | `NotifyPayoutApprovers` (requested) / `NotifyCollaboratorOfPayout` (the other four) |
| `ReferralAttached` / `ReferralChanged` / `ReferralRevoked` | `ReferralService` | `AuditReferralChange` |
| `CommissionRuleVersioned` | `CommissionRuleService` | `AuditRuleVersion` |
| `EntitlementSuperseded` | `CommissionEntitlementService` | `FlagOverReleasedEntitlement` (feeds the discrepancy queue) |
| `StudentFeeIssued` / `StudentFeeAdjusted` / `StudentFeeCancelled` | `StudentFeeService` (Phase 18) | Phase 18 listeners; none of them may touch the ledger |
| `WalletRecalculated` / `WalletDriftDetected` | `CollaboratorWalletService` / `CommissionReconciliationService` | `NotifyWalletDrift` |

### 10.2 Notifications (database channel now, mail-ready - §97)

`NotificationRecipient` resolution: a collaborator notification is delivered to `collaborator.user_id`;
when it is null the notification is **skipped** and an activity row records "collaborator has no login".

| Class | Trigger | Recipient | Content |
|---|---|---|---|
| `StudentCommissionAdded` | `CommissionCreated`, purpose `student_commission` | collaborator | amount, student name, receipt number, status ("awaiting approval" / "available"), link to the entry |
| `ProjectCommissionAdded` | `CommissionCreated`, purpose `project_commission` | collaborator | amount, project name, payment number, status, link |
| `CommissionApproved` | `CommissionApproved` | collaborator | amount, new status, `available_at` or the hold date |
| `CommissionReversed` | `CommissionReversed`, `CommissionClawedBack` | collaborator | amount reversed, the source receipt, the reason, and for a clawback the plain-language policy ("recovered from future earnings" / "written off") |
| `PayoutRequested` | `PayoutRequested` | holders of `collaborator_payouts.approve` | collaborator, amount, entry count |
| `PayoutApproved` / `PayoutPaid` / `PayoutRejected` | the matching events | collaborator | amount, method, `paid_on`, transaction id (`PayoutPaid`), reason (`PayoutRejected`) |
| `FeePaymentReceived` / `FeeDueReminder` / `FeeOverdue` | Phase 18 | student | receipt, amount, balance, due date |
| `ProjectPaymentReceived` | `ProjectPaymentRecorded` | client | amount, project, balance |
| `RefundAwaitingApproval` | `PaymentReversalRecorded` (pending) | holders of `payment_reversals.approve` | payment, amount, reason |
| `WalletDriftDetected` / `CommissionGenerationFailed` | reconciliation / job `failed()` | holders of `wallet_reconciliation.view_any` | collaborator, drift amount or exception class, run link |

Every notification is queued, carries a deep link, and renders the amount through `money()`. A notification
failure never rolls back money (they are dispatched after commit).

### 10.3 Jobs

| Job | Properties |
|---|---|
| `ProcessStudentFeeCommission` | `ShouldBeUnique` (`student-fee-payment:{id}`, `uniqueFor` 3600), `$afterCommit = true`, `tries` 5, `backoff [10,30,60,120,300]`, queue `financial`; `failed()` -> `commission_state = failed` + exception class + `CommissionGenerationFailed` |
| `ProcessProjectPaymentCommission` | `project-payment:{id}`, otherwise identical |
| `ProcessCommissionReversal` | `payment-reversal:{id}`, otherwise identical; honours `approval_status`; defers while the earning is still `queued` |
| `RecalculateCollaboratorWallet` | `wallet:{collaborator_id}`; dispatched after a manual adjustment, a clawback or from the wallet screen; run type `post_transaction` |
| `ReconcileCollaboratorWalletChunk` | one job per 200 collaborators inside a run, carrying the `run_uuid` |
| `BuildCollaboratorStatementExport` | PDF / CSV for large ranges; the file is delivered through a notification and deleted after 7 days by an existing Phase 25 cleanup |

### 10.4 Scheduler

| Command | Cadence | Purpose |
|---|---|---|
| `commissions:sweep` | every 10 minutes | re-queue `student_fee_payments`, `project_payments` and `payment_reversals` whose `commission_state` is `queued` or `failed` and older than 2 minutes; bounded to 500 rows; ordered by `paid_on` then id so an earning always precedes its reversal. **Never touches `skipped`** ([D-IMP-4]) |
| `commissions:release-held` | daily 00:10 | `approved -> available` where `hold_until <= today` |
| `commission-rules:activate` | daily 00:05 | `scheduled -> active` at `effective_from`; `active -> expired` when `effective_to` passed with no successor |
| `fees:mark-overdue` | daily 01:00 | charges and installment lines past `due_date` with a balance (Phase 18) |
| **`collaborators:reconcile-wallets`** | **daily 01:30** | the scheduled proof: one `run_uuid`, chunked 200 collaborators through `ReconcileCollaboratorWalletChunk`, one `collaborator_wallet_reconciliations` row per collaborator whether ok or not, the eight checks of spine §6.5.3, `reconciliation_status` + `drift_amount` written to the wallet, `WalletDriftDetected` fired and `wallet_reconciliation.view_any` holders notified on drift, **no automatic repair**; skipped entirely when `finance.wallet_reconcile_enabled` is false. Flags: `--collaborator=`, `--repair`, `--run-type=`. `withoutOverlapping(3600)`, `onOneServer()`, queue `financial`. The test helper `assertWalletMatchesLedger()` calls the same service, so the nightly job and the suite share one implementation |
| `financial:verify-constraints` | daily 02:00 | assert every unique index, CHECK, generated column and delete trigger of spine §2 still exists; alert loudly if one vanished (spine R-3, R-4) |
| `fees:installment-reminders` | daily 09:00 | uses Phase 2's `institute.installment_reminder_days` (Phase 18) |
| `payouts:expire-stale-requests` | weekly | flag `requested` payouts older than 30 days for staff attention; **never auto-rejects money** |
| `commissions:evaluate` | on demand only | the audited manual re-evaluation; not scheduled |

---

## 11. Acceptance tests

`tests/Feature/Financial/`. Every money test ends with the shared helper
`assertWalletMatchesLedger($collaborator)`, which runs `CommissionReconciliationService` and asserts all
eight checks of spine §6.5.3. Spine FT ids are kept so there is one numbering across both documents; tests
this plan adds carry **IMP** ids. Phases 10-12 are not done until all of them pass.

### 11.1 The nine requirement tests (§120)

| # | Test name | Asserts |
|---|---|---|
| 1 | `test_student_without_collaborator_creates_no_commission_row` (FT-01) | `assertDatabaseCount('collaborator_commission_ledger_entries', 0)`; no zero-amount row exists; no entitlement row; payment `commission_state = skipped`, `commission_skip_reason = no_referral`; exactly one `commission.skipped` activity row |
| 2 | `test_student_payment_creates_one_commission_at_configured_rate` (FT-02) | one row: `amount 1000.00`, `credit`, `student_commission`, `base_amount 10000.00`, `gross_amount 10000.00`, `commission_rate 10.0000`, `commission_base paid`, `status pending` (manual mode), `rule_snapshot` non-empty, `transaction_date = paid_on`; wallet pending 1000.00, available 0.00 |
| 3 | `test_same_fee_payment_processed_twice_creates_one_commission` (FT-03) | service called twice, job dispatched twice, and a raw duplicate INSERT: count stays 1; the second call returns `created: false`; the raw INSERT throws on `uq_cle_dedupe`; a hand-made different `dedupe_key` throws on `uq_cle_source` |
| 4 | `test_three_installments_create_three_commissions` (FT-04) | three 1000.00 rows with distinct `student_fee_payment_id` and `student_fee_installment_id`; one entitlement with `collected_amount 30000.00`; wallet total 3000.00 |
| 5 | `test_student_refund_creates_negative_reversal_and_preserves_original` (FT-05) | a `debit` of 1000.00, `signed_amount -1000.00`, `purpose reversal`, `reverses_entry_id` + `payment_reversal_id` set, rule columns copied; the original byte-identical on `amount`, `commission_rate`, `base_amount`, `rule_snapshot`; both rows `reversed` with `reversed_at`; wallet available and lifetime 0.00 |
| 6 | `test_project_without_collaborator_creates_no_commission` (FT-06) | zero ledger rows; `commission_skip_reason = no_referral` |
| 7 | `test_project_payment_creates_commission_at_configured_rate` (FT-07) | 15% of 100,000 -> one credit 15000.00, `purpose project_commission`; variant with base `total_value` on a 200,000 project: entitlement 30000.00, first payment releases 15000.00, second releases exactly the residual 15000.00 and no more |
| 8 | `test_project_payment_refund_creates_reversal_entry` (FT-08) | a `-15000.00` reversal referencing the original and the `payment_reversals` row; original preserved |
| 9 | `test_payout_of_20000_against_50000_wallet` (FT-09) | wallet available 30000.00, paid 20000.00, reserved 0.00, lifetime 50000.00; the closed identity holds; the payout row and its allocation both still exist; the entry is still `available` with `allocated_amount 20000.00` |

### 11.2 Money, rounding, installments, discounts

| # | Test name | Asserts |
|---|---|---|
| 10 | `test_percentage_uses_bcmath_half_up` (FT-10) | `Money::percentage('10000.00','10.0000') === '1000.00'`; `('3333.00','10.0000') === '333.30'`; `('3333.33','10.0000') === '333.33'` |
| 11 | `test_fixed_commission_prorates_across_installments_to_exact_total` (FT-11) | 666.67 / 666.66 / 666.67, sum 2000.00; entitlement `fully_released`; a fourth receipt yields no row (`entitlement_cap_reached`) |
| 12 | `test_fixed_release_modes` (FT-12) | `on_first_payment` releases 2000 on receipt 1 and nothing after; `per_payment` releases 2000 per receipt with `entitlement_amount` NULL |
| 13 | `test_document_base_cannot_over_release` (FT-13) | base `gross` 10% of 30,000 with collectible 25,000: releases sum to exactly 3000.00; a raw UPDATE past the promise is rejected by `chk_cce_cap` |
| 14 | `test_overpayment_caps_the_commissionable_amount` (FT-14) | 35,000 on a 25,000 net charge: `base_amount 25000.00`, `gross_amount 35000.00`, credit 2500.00, charge `overpaid` with negative `balance_amount`; with `commission_on_overpayment = true` the base is 35,000 |
| 15 | `test_commission_that_rounds_to_zero_creates_no_row` (FT-15) | 10% of 0.04 -> no row, `rounds_to_zero`, `collected_amount` unchanged; a 0% rate -> no row |
| 16 | `test_partial_refunds_never_over_reverse` (FT-16) | 3,333 / 3,333 / 3,334 -> 333.30 / 333.30 / 333.40, sum exactly 1000.00; `reversed + clawed_back = amount`; a raw UPDATE breaching it is rejected by `chk_cle_undo_ceiling` |
| 17 | `test_refund_larger_than_payment_is_rejected` (FT-17) | validation error; a raw INSERT breaching the ceiling is rejected by `chk_sfp_refund_ceiling`; nothing written |
| 18 | `test_earning_and_reversal_jobs_are_order_independent` (FT-18) | both orders give an identical final wallet and row count; the deferred path leaves the reversal `queued` and the sweeper completes it |
| 19 | `test_partial_refund_leaves_the_correct_remainder` (FT-19) | a 4,000 refund of a 10,000 receipt with a 1,000 commission -> one `-400.00` reversal, original still `available` with `reversed_amount 400.00`, available 600.00; a payout can allocate at most 600.00 |
| 20 | `test_refund_after_payout_claws_back_and_goes_negative` (FT-20) | the **payout-then-reversal** case: the original stays `paid`, a `clawback` debit of 1000.00 sits in `available`, wallet available `-1000.00`, a new payout is refused, a later 5,000 earning makes available 4000.00; under `write_off` the clawback posts as `cancelled` and available stays 0.00 |
| 21 | `test_late_skip_leaves_entitlement_untouched` (IMP-01) | a receipt that skips at C4-C7 leaves the entitlement `open` with `collected_amount`, `released_amount` and `ledger_entry_count` unchanged, and a later qualifying receipt still earns |
| 22 | `test_installment_receipt_uses_one_dedupe_key_regardless_of_source_type` (IMP-02) | a receipt allocated to an installment records `source_type = student_installment_payment` but `dedupe_key` starting `student_fee_payment:`; a forged second row with `source_type = student_fee_payment` is rejected by `uq_cle_dedupe` ([D-IMP-5]) |
| 23 | `test_discount_after_payment_does_not_claw_back_under_paid_base` (IMP-03) | a 5,000 discount after a 10,000 receipt: net drops, past commission byte-identical, no reversal row, the next receipt reports `overpayment_only` when nothing collectible remains |
| 24 | `test_discount_after_payment_supersedes_a_document_level_entitlement` (IMP-04) | under base `net_after_discount` the open entitlement is superseded, `entitlement_amount = max(new_promise, released_amount)`, `over_released_amount` set when the promise drops below what was released, a row appears on the discrepancy queue, and **no clawback is posted** |
| 25 | `test_scholarship_behaves_as_a_discount_for_commission` (IMP-05) | a scholarship row feeds `scholarship_amount`, reduces `net_amount`, and produces the same commission outcome as an equal discount; `chk_sf_discount_ceiling` rejects a discount + scholarship above gross |

### 11.3 Rules, referrals, attribution, concurrency

| # | Test name | Asserts |
|---|---|---|
| 26 | `test_rule_is_resolved_on_the_value_date` (FT-21) | v1 10% (Jan-Mar), v2 15% (from Apr): a receipt dated 15 Mar posted on 5 Apr earns 10% and points at v1; one dated 2 Apr earns 15%; earlier rows byte-identical afterwards |
| 27 | `test_rate_change_mid_admission_does_not_rewrite_prior_installments` (IMP-06) | three installments across a rate change: 1000.00 / 1000.00 / 1500.00 under base `paid`; under a document-level base the open entitlement keeps the version it opened with and a mid-document change applies only after an audited supersede |
| 28 | `test_rule_versions_are_immutable_and_non_overlapping` (FT-22) | a direct `update(['rate' => ...])` throws; `createVersion` closes v1 at `effective_from - 1 day`; a second open-ended version violates `uq_ccs_open`; two versions starting the same day violate `uq_ccs_start` |
| 29 | `test_rule_referenced_by_a_ledger_row_cannot_be_deleted` (FT-23) | refused by the policy and by RESTRICT; closing it before a referencing entry's `transaction_date` is refused |
| 30 | `test_concurrent_workers_create_one_commission` (FT-24) | two processes with a latch call `handlePayment` on the same receipt: exactly one row, the loser gets the existing entry, no exception surfaces, no deadlock after 3 attempts |
| 31 | `test_two_concurrent_payouts_cannot_allocate_the_same_amount` (FT-25) | allocations are disjoint, `SUM(allocated) <= amount`, one request fails with a named shortfall |
| 32 | `test_payout_lifecycle_and_bank_return` (FT-26) | reject releases allocations and restores available; `markPaid` twice fails on `uq_cp_txn`; `cancelAfterPayment` walks entries `paid -> available`, releases allocations with `payout_returned`, keeps the payout row, and writes an audit row with the reason |
| 33 | `test_partially_allocated_entry_is_not_marked_paid` (FT-27) | after a 20,000 payout on a 50,000 entry the status is still `available`; the allocation-derived "paid commission" figure shows 20000.00 while `where status = 'paid'` shows 0 - asserted so the footgun stays documented |
| 34 | `test_attribution_change_does_not_move_existing_commissions` (FT-28) | A -> B with a reason: A's rows untouched and still point at A and referral #1; the old referral `superseded` with `effective_to`; only receipts dated on or after B's `effective_from` credit B; a back-dated receipt before the switch credits A; an activity row holds old and new values plus the reason |
| 35 | `test_transfer_between_charges_does_not_double_commission` (FT-29) | money moved from charge A to B carries A's `paid_on`, reverses A's commission pro-rata, earns on B once, net change 0.00 |
| 36 | `test_no_commission_on_registration_admission_or_project_creation` (FT-30) | creating a student, an admission, a charge, a project, a milestone and an invoice produces zero ledger rows (INV-1) |
| 37 | `test_no_commission_from_an_unpaid_installment_plan` (FT-31) | a 3-line plan with no receipts produces zero rows |
| 38 | `test_suspended_collaborator_earns_nothing_and_is_not_backfilled` (IMP-07) | the receipt posts, no ledger row, `collaborator_inactive` recorded with `collaborator_id` kept; reinstatement alone creates nothing; `commissions:evaluate` with a reason creates exactly one row and a second run creates none |

### 11.4 Wiring, approval, immutability, settings

| # | Test name | Asserts |
|---|---|---|
| 39 | `test_recording_a_payment_dispatches_exactly_one_commission_job_after_commit` (IMP-08) | the job is dispatched once per receipt, never before COMMIT (a rolled-back transaction dispatches nothing), and a second identical POST with the same idempotency key dispatches nothing new |
| 40 | `test_commission_amount_is_computed_in_exactly_one_place` (IMP-09) | a static scan: `Money::percentage` and `commission_rate` appear only under `app/Services/Collaborator/` and `app/Support/Money.php`; no controller, listener, widget, export or Blade file computes a commission |
| 41 | `test_payments_cannot_be_inserted_outside_the_payment_service` (IMP-10) | `StudentFeePayment::create()` and `ProjectPayment::create()` throw `DirectPaymentWriteException`; `PaymentService::allowDirectWrites()` permits it and appears nowhere outside `database/` and `tests/` ([D-IMP-2]) |
| 42 | `test_dry_run_preview_writes_nothing` (IMP-11) | `dryRun()` / the preview endpoints return the expected rule, base, rate and amount (or skip reason) and leave every table row count unchanged |
| 43 | `test_approval_modes` (FT-32) | manual: posted `pending`; `.view` only -> 403 on approve; with `.approve` -> `approved` then `available` in one transaction (two activity rows, `available_at` set); reject -> `cancelled`, out of every bucket, reason stored, payment still `cleared`. Automatic + hold 0 -> posted `available`. Automatic + hold 7 -> `approved` with `hold_until`, released by the job on day 7 and not before |
| 44 | `test_bulk_approve_and_reject_are_explicit_idempotent_and_audited` (IMP-12) | bulk approve of 50 ids writes 50 `commission.approved` rows plus one batch row; re-submitting the same ids changes nothing and reports them as skipped; an id whose status moved is skipped and named; bulk reject requires a reason and stores it on every row; a forged id belonging to another filter is still authorised individually |
| 45 | `test_ledger_money_columns_are_immutable` (FT-33) | `update(['amount' => ...])` throws `ImmutableLedgerAttributeException`; same for `commission_rate`, `base_amount`, `rule_snapshot`, `dedupe_key`, `source_id`; updating `status` / `notes` succeeds |
| 46 | `test_financial_rows_cannot_be_deleted` (FT-34) | `delete()` and `forceDelete()` throw; a raw `DELETE` raises SQLSTATE 45000 on each of the nine append-only tables; no `deleted_at` column exists on them |
| 47 | `test_payment_cannot_be_edited_and_void_is_the_correction_path` (FT-35) | an `update` of `amount` / `paid_on` / `student_fee_id` is refused by the policy; `void` + re-record leaves three payment rows and one reversed commission |
| 48 | `test_ledger_can_only_be_written_through_the_writer` (FT-36) | `CollaboratorCommissionLedgerEntry::create()` outside `LedgerWriter` throws `DirectLedgerWriteException` |
| 49 | `test_every_discretionary_act_is_audited_with_a_reason` (FT-37) | approve, reject, reverse, clawback, write-off, rule version, attribution change, payout reject / cancel / cancel-after-payment each write an `activity_log` row with old/new, actor, IP and the reason; a missing reason fails validation |
| 50 | `test_settings_changed_after_posting_change_nothing_already_posted` (FT-38) | flipping `student_commission_base`, `commission_approval_mode`, the default rate, `fixed_commission_release` and `commission_on_overpayment` leaves every existing row and its `rule_snapshot` byte-for-byte identical |
| 51 | `test_backdate_window` (FT-39) | 90 days back rejected at `backdate_limit_days = 30`; with the `approve` ability it posts and earns the then-effective rate; a future `paid_on` is always rejected |
| 52 | `test_refund_approval_gate` (IMP-13) | with `refund_approval_required` on, a refund posts `approval_status = pending`, dispatches **no** commission reversal and notifies the approvers; approval dispatches it; rejection rolls the `refunded_amount` increment back and restores the payment status, leaving the commission untouched |

### 11.5 Wallet, reconciliation, statement

| # | Test name | Asserts |
|---|---|---|
| 53 | `test_wallet_equals_ledger_after_every_scenario` (FT-40) | the helper runs after every test above: all eight checks pass, `drift_total = 0.00`, `identity_holds = true`, `payout_cross_check = 0.00` |
| 54 | `test_no_float_in_any_money_path` (FT-41) | a static scan of `app/Services/{Finance,Institute,Collaborator}` and the models finds no `+ - * /` applied to a money attribute or a decimal-cast value |
| 55 | `test_only_the_wallet_and_statement_services_compute_balances` (FT-42) | controllers, widgets, exports and notifications depend on `CollaboratorWalletService` / `CollaboratorStatementService` and contain no `SUM(` over the ledger |
| 56 | `test_drift_is_detected_reported_and_not_silently_repaired` (FT-43) | a corrupted wallet row is detected, status `drift`, a notification sent, **the cache not rewritten**, and the wallet screen renders derived figures behind the banner; `--repair` rewrites the cache and records before/after; a corrupted allocation sets `failed` and repairs nothing |
| 57 | `test_scheduled_reconciliation_writes_one_row_per_collaborator_per_run` (IMP-14) | the 01:30 command writes one `collaborator_wallet_reconciliations` row per collaborator with a shared `run_uuid` whether ok or not; a retried run does not duplicate (`uq_cwr_run`); `finance.wallet_reconcile_enabled = false` skips everything; the command and `assertWalletMatchesLedger()` call the same service |
| 58 | `test_statement_balances_and_exports_match` (FT-44) | a 200-entry fixture: `opening + credits - debits - payouts = closing`; the service throws if forced out of balance; the screen, print, PDF and CSV totals agree to the paisa; §56's five lines and every filter are present |
| 59 | `test_statement_is_reproducible_after_a_backdated_receipt` (IMP-15) | a statement for a closed month re-issued after a back-dated receipt shows the new row with the old `transaction_date`, still balances, and `posted_at` explains the change |
| 60 | `test_db_guarantees_actually_exist` (FT-45) | each CHECK rejects a bad INSERT; each NULL-tolerant unique guard rejects the second INSERT; every generated column and delete trigger exists - a silently dropped constraint fails CI, not production |

### 11.6 Authorization and isolation

| # | Test name | Asserts |
|---|---|---|
| 61 | `test_authorization_matrix` (FT-46) | no `student_fee_payments.create` -> 403 on recording; no `collaborator_commissions.approve` -> 403 on approve **and** on `bulk-approve` with forged ids; no `.reject` -> 403 on `bulk-reject`; no `payment_reversals.create` -> 403 on refund; no `collaborator_payouts.approve` -> 403 on approve; no `wallet_reconciliation.change_status` -> 403 on run and recalculate; every 403 asserts **nothing was written** |
| 62 | `test_collaborator_cannot_see_another_collaborators_ledger` (FT-47) | collaborator A requesting B's ledger entry, wallet, payout, allocation, payout account, statement or referral gets **404** on every route; id enumeration across the full id range returns 404 every time; A's index and export queries contain zero B rows; A's statement totals are unchanged by B's activity; the global scope is asserted present on all eleven models |
| 63 | `test_portal_permission_gates_money_columns` (FT-48) | without `collaborator_portal.student_commission` no student-commission figure appears **in the response body**; without `.project_value` no project value; without `.payout_request` a POST payout is 403 even when the setting is on; `commission_skip_reason` never appears in any collaborator response ([D-IMP-8]) |
| 64 | `test_student_and_client_isolation` (FT-49) | a student sees only own charges and receipts and the body contains no `collaborator_id` or commission column; another student's receipt is 404; a client sees only own project payments; a teacher is 403 on every route in this contract |
| 65 | `test_module_gating_preserves_data` (FT-50) | disabling `collaborator_commissions` 403s the routes for Super Admin too; row counts before and after disable + re-enable are identical; queued jobs still complete |
| 66 | `test_migrations_roll_back_cleanly` (FT-51) | every one of the 21 migrations rolls forward and back on a database holding rows (triggers, guard indexes, generated columns and FKs dropped in the right order); `migrate:fresh --seed` is clean; the guarded FK migrations are no-ops when the target table is absent and complete when it appears |
| 67 | `test_same_user_cannot_create_and_approve_a_payout` (IMP-16) | an Accountant holding both abilities is refused on approving their own payout; a second approver succeeds; the refusal is a policy denial, not a UI omission |

---

## 12. Risks and open questions

### 12.1 Risks this plan accepts

The spine's risks R-1 to R-14 are inherited unchanged and not repeated. Additional risks created by the
implementation choices above:

| # | Risk | Mitigation |
|---|---|---|
| IR-1 | **The payment-model write guard ([D-IMP-2]) will break any code that legitimately bulk-inserts receipts** - a historical data import, a seeder, a factory. | One greppable escape hatch (`PaymentService::allowDirectWrites()`), a CI grep limiting it to `database/` and `tests/`, and test IMP-10. If a future import genuinely needs volume, it calls the service in a chunked transaction rather than removing the guard. |
| IR-2 | **Commission lives behind a queue.** With no worker running, receipts post and commissions do not, and the cashier sees `queued`. | The register shows the state per row, `commissions:sweep` drains the backlog within 10 minutes of a worker returning, and the skip report plus the `CommissionGenerationFailed` notification make a stuck queue visible. `QUEUE_CONNECTION=sync` remains valid for a single-server install. |
| IR-3 | **`commissions:evaluate` is a money-creating admin action.** | Permissioned (`collaborator_commissions.approve`), throttled, reason-mandatory, audited, bounded by `--payment=` or a date range, and structurally incapable of duplicating (`uq_cle_source`). |
| IR-4 | **Bulk approve of 500 rows is one transaction** holding 500 row locks plus 500 wallet-delta writes; a slow run could block earning for those collaborators. | Cap 500, ascending-id lock order matching every other service, and the approval path touches no entitlement; if it proves slow the cap is lowered in one place. |
| IR-5 | **The statement is built in PHP from up to 5,000 rows** for the screen and more for an export. | Over 5,000 rows or 12 months the work moves to `BuildCollaboratorStatementExport`; the index `(collaborator_id, purpose, transaction_date)` serves the grouping; R-11's monthly snapshot table remains the additive escape. |
| IR-6 | **`BelongsToAuthenticatedCollaborator` keys off the route-name prefix.** A future API or console route outside `collaborator.` would not be scoped. | The scope is defence in depth, not the only defence: every collaborator controller filters explicitly and every policy asserts ownership; FT-47 enumerates ids on every route, and any new collaborator-facing route must be added to that test. |
| IR-7 | **Three screens (discrepancies, skip report, reconciliation) are operational screens nobody is required to visit.** | Each has a dashboard widget with a count, and drift additionally notifies. |

### 12.2 Open questions

The spine's Q1-Q10 stand and are not re-asked; Q1 is **closed**: decision **D16** (the nine append-only
financial tables carry no `deleted_at`) was approved on 2026-09-12 and sits under the general category
rule **D19** in `CLAUDE.md` §3, so the migration set is no longer blocked (F-9.1). New questions raised by
this plan:

| # | Question | Default assumed |
|---|---|---|
| Q-A | Should the Collaborator role receive `collaborator_portal.wallet` and `.payouts` by default (Phase 1 seeds `collaborator_portal.*` minus `payout_request`)? | **Yes** - a collaborator who cannot see their own wallet cannot use the panel at all. `payout_request` stays off, gated twice (§4.3). |
| Q-B | Is a **commission-added** notification wanted per entry, or as a daily digest? A collaborator with twelve installment students receives twelve notifications a day. | Per entry for the first release (§97 lists the events individually); a digest is an additive Phase 22 setting if volume complains. |
| Q-C | May a collaborator see **why** a referred student's payment earned nothing (`commission_skip_reason`)? | **No** ([D-IMP-8]): internal configuration, and "your commission is disabled" is a conversation, not a UI string. Admin-only on the skip report. |
| Q-D | Must a payout be **approved by someone other than its creator**? The spine's §9 says yes when one user holds both abilities. | Enforced (test 67). If the client runs a one-accountant office this becomes a setting, not a code change - flagged now rather than discovered at go-live. |
| Q-E | Does a **bulk reject** take one reason for the whole batch, or one per entry? | One reason applied to, and stored on, every entry in the batch, plus the batch summary row. Per-entry reasons would make the screen unusable at 50 rows. |
| Q-F | Who signs the **payout voucher** and the **statement** PDF (name, designation, a signature image)? | Company name, address and logo from Phase 2 `branding` / `company`; a blank signature block. A signature image would need a new setting. |
| Q-G | Should the collaborator panel show a payout's **allocation detail** (which commissions it settled), or only the total? | Show it - §56 and §54 both imply traceability, and hiding it invites "which commissions did you pay me for?" emails. Each money column still obeys its `collaborator_portal.*` gate. |
| Q-H | Phase ordering: this contract makes **Phase 18 depend on Phase 10** (`PaymentService` and the four fee tables) rather than the reverse. Should the tracker say so explicitly? | **Answered (F-11.2, F-11.3).** Yes, and both lines are written: `DEVELOPMENT_LOG.md` §5 gains a note under **Phase 8** (this migration set ships in the same release, immediately after Phase 8's migrations, with the two backfills run once afterwards) and a note under **Phase 18** (it consumes Phase 10's `PaymentService` and the four fee tables and creates no financial table; it ships `student_fee_reminders`, the fee services and all fee screens). The exact text is `docs/design/resolutions.md` §6.3; spine Q10 is answered by the same pair. |

---

## 13. Requests to other phases

The spine's §13 requests stand in full and are not repeated except where this plan makes one blocking.
Stated as `table.column - why` plus the behavioural asks.

### 13.1 Blocking - without these, phases 10-12 cannot be built as specified

| Request | Phase | Why |
|---|---|---|
| `App\Support\Money` - **satisfied by phase-01 §3** (F-4.11). The one canonical surface (`add`, `sub`, `mul`, `div`, `percentage`, `percentageOf`, `compare`, `isZero`, `isNegative`, `abs`, `min`, `max`, `sum(array)`, `round($v, $scale = 2)`, `roundTo`, `prorate($amount, $part, $total)`, `distribute($amount, int $parts, RemainderPlacement $r = RemainderPlacement::First): array`, `toMinor`, `fromMinor`, `format`) is published there - **bcmath only, intermediate scale 6, final half-up at 2, strings in and out, never a float** - plus `App\Enums\RemainderPlacement` (`first`, `last`, `largest`). Nothing is added here | 1 | §6.2 C1-C7 and §6.6 are written in these terms; `prorate`, `round` and `distribute` are what make the cumulative-target method reproducible to the paisa |
| `PermissionRegistry`: the four module slugs of §4.1, the ability additions of §4.2, the five portal permissions of §4.3 | 1 | the registry is the only place a permission name exists (D4) |
| `RoleSeeder`: grant the Collaborator role `collaborator_portal.wallet` + `.payouts`, the Student role `student_portal.fees` + `.payments`, the Client role `client_portal.payments` | 1 | otherwise every panel screen this contract builds is 403 on day one (Q-A) |
| `LogsActivityWithContext::withReason(string)` | 1 | every discretionary act (INV-24, test 49) |
| `SettingsRegistry`: the 24 keys of §5 inside the existing `collaborator` / `institute` / `finance` groups | 2 | settings definitions in code, values in the DB |
| `DashboardRegistry` + `DateRange::previous()` + `Format::money()` / `app_date()` | 2 | the **eight** widgets of §8.12 and every money/date rendering (the three fee widgets are Phase 18's - F-8.3) |
| `collaborators.user_id` nullable unique FK to `users.id` | 8 | the collaborator panel resolves `auth()->user()->collaborator`; without it there is no isolation key and no notification recipient (§9, §10.2) |
| `collaborators.status` (Pending/Active/Inactive/Suspended), `.referral_code` string(32) UNIQUE immutable once referenced, `.deleted_at` | 8 | guards G6/G7 and the snapshotted referral code |
| A Phase 8 **observer** creating the `collaborator_wallets` row in the same transaction as the collaborator, and a **policy** blocking `forceDelete` while any ledger row, entitlement, payout or referral exists | 8 | no money path may create a wallet lazily; my FKs are RESTRICT and the policy must say why |
| `students.id`, `.branch_id`; `student_admissions.id`, `.course_fee`, `.discount_amount`, `.scholarship_amount`, `.total_amount`, **`.net_payable` decimal(15,2)** | 15 | `net_payable` is the collectible denominator of the default commission document and is absent from §69's field list |
| `projects.project_value`, `.discount_amount`, **`.net_value` decimal(15,2)**, `.commission_type` string(16) nullable, `.commission_rate` decimal(8,4) nullable, `.commission_fixed_amount` decimal(15,2) nullable; **`project_milestones.amount` decimal(15,2)**; `project_value_revisions` append-only (id, project_id, old_value, new_value, reason, effective_on, created_by) | 6 | the project collectible denominator, §45's project-level override (`rule_source = project_override`), the `milestone` base, and the §107 audit that §6.6 row 8 reads |
| `clients.id`, `leads.id` | 5 | `project_payments.client_id` scoping and the nullable referral subject |

### 13.2 Behavioural contracts other phases must honour

| Request | Phase | Why |
|---|---|---|
| Phase 18 records every student receipt through `PaymentService::recordStudentFeePayment()` and **never** inserts `student_fee_payments` directly | 18 | the single calculation site ([D-IMP-3], [D-IMP-2]) |
| Phase 18 **creates no financial table**: `student_fees`, `student_fee_installments`, `student_fee_discounts` and `student_fee_payments` are created by §2.2's migration set and Phase 18 ships the screens, the fee services and `student_fee_reminders` only. Phase 18 also owns the three fee dashboard widget keys `FeeCollectedTodayWidget` / `PendingFeesWidget` / `OverdueFeesWidget` | 18 | F-11.3, F-8.3 - one owner per table and one declaration per widget key |
| Phase 18's fee generators rely on `student_fees.generation_key` + `uq_sf_generation`: they **INSERT and treat a 1062 as "already generated"**, never a SELECT-then-insert guard | 18 | F-3.15, R7 - an INSERT guard beats a SELECT guard under concurrency |
| Phase 13 and Phase 23 read collaborator totals through `CollaboratorWalletService::payoutsPaidTotal()` and `CollaboratorStatementService::commissionAccruedTotal()` - **never a `SUM()` of their own** | 13, 23 | F-4.8, INV-26 |
| Phase 18's `StudentFeeService::addDiscount()` calls `CommissionEntitlementService::supersede()` in the same transaction for every open entitlement whose snapshotted base is document-level, and **not** when the base is `paid` | 18 | §6.5; otherwise a discounted charge silently over-promises |
| Phase 18 never writes a commission percentage or amount onto `student_fees`; the fee slip reads them from the entitlement and the ledger at print time | 18 | §109 "do not duplicate financial records", [D-FS-4] |
| Phase 13 records every client payment through `PaymentService::recordProjectPayment()`; `invoices.paid_amount` is **derived** from `project_payments`, never an independent counter; `payment_methods.id` exists as a nullable FK target | 13 | two counters for one fact is how an invoice and a ledger disagree in front of a client |
| Phase 9 attaches referrals only through `ReferralService::attach()` and owns `collaborator_referral_visits` | 9 | `uq_cr_*_current` and the superseding history depend on one write path |
| Phase 6 exposes a "Referred by collaborator" control on the project form that calls `ReferralService`, and records a project-value change as a revision row, never as an edit of a posted payment | 6 | §45, §107, §6.6 row 8 |
| Phase 22 implements the fourteen notification classes of §10.2 on the database channel with the mail channel ready | 22 | §97 |
| Phase 23's collaborator and fee reports call `CollaboratorStatementService` / `CollaboratorWalletService` and never re-implement a sum | 23 | INV-26, test 55 |
| Phase 24 runs tests 30, 31, 53, 56 and 60 as part of the hardening pass | 24 | §110 |

### 13.3 Documentation

| Request | Why |
|---|---|
| `DEVELOPMENT_LOG.md` §4: cite **D16** (the nine append-only financial tables carry no soft deletes - **approved 2026-09-12**, under the general category rule **D19**), **D17** (DB CHECKs, STORED generated columns and `BEFORE DELETE` triggers are part of the contract - expect raw SQL errors on an illegal DELETE; factories must not delete money rows), **D18** (a payout allocates named ledger entries with partial amounts; "paid commission" is always derived from allocations) | the spine's §13.3 requests; all three numbers are unchanged citations (F-10.1) and D16 no longer blocks the migration set |
| `DEVELOPMENT_LOG.md` §4: cite **D39** (allocated by `docs/design/resolutions.md` §4.1; this contract's former "D19") - exactly one calculation site per commission side, reached only through an `afterCommit` event -> unique job chain; payments and ledger rows may be inserted only through `PaymentService` / `LedgerWriter` | [D-IMP-2], [D-IMP-3] - the rule a future phase is most likely to break. F-10.1 |
| `CLAUDE.md` §5: add "commission is released against a capped entitlement, never beyond rate x document base, and never ahead of collection", "the wallet is a cache; only `CollaboratorWalletService` and `CollaboratorStatementService` may compute a balance", and "a guard failure writes a skip reason on the payment and an activity row - never a zero-amount ledger row" | INV-12, INV-19, INV-26, INV-2 |
| `DEVELOPMENT_LOG.md` §5 *(text supplied - resolutions §6.3)*: the note under **Phase 8** (this migration set ships in the same release, immediately after Phase 8's migrations) and the note under **Phase 18** (it consumes Phase 10's `PaymentService` and the four fee tables, creates no financial table, and ships `student_fee_reminders` + the fee services + all fee screens); plus the standing note under Phase 13 that `project_payments` is owned by this spine | Q-H, spine Q10, **F-11.2**, **F-11.3** |

---

## Convergence log (2026-09-12)

Applied from `docs/design/resolutions.md` §3 (apply-map row for this file) plus §2 ownership maps.
The financial spine stays authoritative: no guarantee was relaxed and no money path was widened.

| Finding | Change made |
|---|---|
| F-3.15 | §2.2 migration **01** adds `student_fees.generation_key` string(64) nullable + `UNIQUE uq_sf_generation(generation_key)`; §13.2 states Phase 18's generators INSERT and treat 1062 as "already generated" (the INSERT is the check, R7). |
| F-4.1 | §1.2, §1.3, §5, §6.3: `DocumentNumberService` moved from Phase 10's **Ships** list to a **"reuses, must not re-create"** dependency on **Phase 5** (D27); `next()`'s default pad is `'%06d'` with every caller passing its own; `reserve()` documented on the same class (no second `FOR UPDATE` counter). |
| F-4.2 | §6.3: `refund()` already took the DTO - confirmed unchanged, and the DTO is now named in full (`App\DataObjects\Finance\RefundData`, six readonly properties) so no caller can drift back to positionals. |
| F-4.3 | §6.3: `ReferralService::attach()` gains the sixth parameter `?ReferralContext $context = null` and the DTO's six readonly properties are listed; it fills the six evidence columns. |
| F-4.4 | §6.3: new published method `ReferralService::recordLosingCandidate($winner, $loser, $ctx, $reason): CollaboratorReferral` (status `superseded`, `superseded_by_id`, `commission_eligible = false`, reason mandatory) - the only write path for a losing candidate. |
| F-4.8 | §6.3: `CollaboratorWalletService::payoutsPaidTotal(Collaborator, ?DateRange): string` and `CollaboratorStatementService::commissionAccruedTotal(Collaborator, ?DateRange): string` added, both derived from the spine §6.5.1 canonical SQL; §13.2 names phases 13 and 23 as callers that never re-sum (INV-26 intact). |
| F-4.9 | §10.1: event **`PaymentReversalRejected`** added (`afterCommit`) with the cache-recompute listener; §6.3's `rejectReversal()` row now names the event. The `refunded_amount` rollback **stays inside `rejectReversal()`'s transaction** - see the note below. |
| F-4.11 | §1.2 and §13.1: the "add to `Money`" ask is replaced by **"satisfied by phase-01 §3"**, with the canonical 20-method surface and `App\Enums\RemainderPlacement` quoted; bcmath only, intermediate scale 6, final half-up at 2, strings in and out. |
| F-5.4 | §1.2, §1.3, §3: `PaymentMethod` and `LedgerEntryType` marked **"reused, not created - declared by Phase 7"**; Phase 10 casts to them. |
| F-5.5 | §1.2, §1.3, §3: `CommissionCalculationType` marked **"reused, not created - declared by Phase 6"**. The enum count reads 30 listed / **27 created**. |
| F-6.1 | §7.2 `admin.project-payments.index` moved from `module:payments` to **`module:project_payments`**; §7.6's two client payment rows gained `module:project_payments`. §9 already listed both slugs - unchanged. |
| F-8.3 | §8.12: `FeeCollectedTodayWidget`, `PendingFeesWidget`, `OverdueFeesWidget` **deleted** (Phase 18 registers them); the eight commission/wallet widgets kept; §13.1's "eleven widgets" corrected to eight. |
| F-9.1 | §2.2 rule 4 and §12.2: the local "blocked until D16 is recorded" wording replaced by citations of **D16** (approved 2026-09-12) under the general category rule **D19**; §13.3's D16 row reads as a citation. |
| F-11.2 | §1.3 gains the release-order paragraph (this migration set applies in the same release, immediately after Phase 8's migrations); Q-H and spine Q10 marked answered with the §6.3 tracker text. |
| F-11.3 | §2.1 states the four student-fee tables are Phase 10's and **Phase 18 creates no financial table**; §1.3 and §13.2 say the same; the per-table "Built on by" column distinguishes table from screens. |
| F-10.1 | §13.3: this contract's claimed **D19 -> D39**; **D16 / D17 / D18** remain unchanged citations (resolutions §4.2). |
| F-5.6 *(do-not-change item 17)* | §3: the existing three `CommissionRuleSource` cases annotated "no `global_default`, no silent fallback" - the rule was already correct; nothing was removed. |
| D31 *(route ownership)* | §7.6: a line recording that the two `client.*` rows are **contributed through Phase 5's `ClientPortalRegistry`**, not redeclared, and both carry `client.context`. |

**Deliberately not applied as literally worded (F-4.9).** The resolution says to add
`PaymentReversalRejected` "plus the listener that rolls `refunded_amount` back". `PaymentService::rejectReversal()`
already rolls that column back **inside its own transaction, under the payment's row lock** (§6.3), which is
the safer-with-money form; a second rollback in an `afterCommit` listener would decrement the same column
twice and leave `refunded_amount` below zero on every rejection. The event was therefore added as the
**notification and cache-recompute** leg (invoice caches per **D40**, the charge's five caches), and §6.3 and
§10.1 both state explicitly that the money rollback is not repeated there. Flagged for the owner.
