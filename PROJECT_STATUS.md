# Project status — phase by phase

**What this file is.** A single answer to "what is finished and what is left", written for a reader who
does not want to walk the 1,700-line [`DEVELOPMENT_LOG.md`](DEVELOPMENT_LOG.md). The log stays the
authority: every decision, defect and test result lives there, and this page is a map of it.

Last updated **20 September 2026**, at the end of Phase 10's services and commission screens.

---

## 1. The short version

| | Phases | State |
|---|---|---|
| ✅ | 0, 1, 2, 3, 4, 5 | Built, tested, committed. Each is a rollback point. |
| 🟡 | 6, 7, 8, 9 | Core built and committed; each has a named tail of deferred work (below). |
| 🟠 | **10** | Schema, enums, registries, **all 11 services** and the commission screens done. Commands and the acceptance suite left. |
| ⬜ | 11 – 25 | Not started. |

**Roughly 40 % of the twenty-six phases are complete**, but the hard half of the build — the money
engine — is now standing. Everything from Phase 11 onwards either consumes it or sits beside it.

The test suite stands at **1,399 tests / 33,288 assertions** across the regression slice that runs
today. That is not the whole suite: see §4.

---

## 2. Done, and safe to build on

### Phase 0 — Bootstrap ✅
Laravel 12.69.2, MariaDB, spatie permission + activitylog, Breeze with Tailwind 3 and Alpine, the three
governing documents.

### Phase 1 — Auth, roles, permissions ✅
14 migrations. `PermissionRegistry` as the single declaration of every permission. `Gate::before` in its
deliberate order — module-disabled first, Super Admin second. Login history, forced password change,
the admin shell, ~35 `x-ui` components. Two HIGH findings from an adversarial review closed and
hand-verified.

### Phase 2 — Dashboard, settings, modules ✅
`SettingsRegistry` (13 groups, ~110 typed fields) driving the seeder, the form **and** the validation
from one declaration. Module dependency resolution with an impact preview. `DashboardRegistry` and 10
widgets. Runtime brand colour through CSS variables.

### Phase 3 — Public website CMS ✅
14 tables, 78 admin routes + 7 public. Holding and maintenance modes, the preview ribbon, the page
cache with a bounded variant count. Four manifest files that CI keeps equal to the code. Closed two
review rounds; suite green at 1,389 / 41,436 at the time.

### Phase 4 — Services, portfolio, blog, careers ✅

### Phase 5 — CRM: leads, clients, client panel ✅
9 tables, 66 admin + 24 client-panel routes, every one of the latter carrying `client.context`. 23
services. `DocumentNumberService` established here as **the** numbering implementation — every later
phase reuses it rather than writing a second counter.

---

## 3. Built, with a named tail

These four phases are committed and working. What is listed is what was deliberately deferred, not what
was forgotten.

### Phase 6 — Projects, milestones, tasks, time tracking 🟡
Still owed:
- §7.6 collaborator panel and §8.10 its screens — *was* blocked on Phase 8, now unblocked
- §10.1–§10.3 notifications and queued jobs; the §8.12 dashboard widgets
- §11 acceptance tests P6-01 … P6-55
- The four manifest files and their test — **the Phase 6 tables are covered by no manifest today**

### Phase 7 — Employees, attendance, leave, payroll 🟡
Still owed:
- §11 acceptance suite FT-HR-01 … FT-HR-62
- §10 events, jobs, notifications, and the scheduler (`hr:close-attendance-day`, leave accrual, the
  document-expiry reminder)
- §7.1 employee documents, §8.2's create wizard, §8.21 widgets, the two importers

### Phase 8 — Collaborator management 🟡
Still owed:
- §6.2 `CollaboratorPayoutAccountService` and §6.6 `CollaboratorPortalMetricsService` — deferred because
  they touch spine tables. **Those tables now exist**, so both are unblocked
- §6.5 linking / re-linking and the §8.8 change-attribution wizard — `ReferralService` now exists, so
  these are unblocked too
- §7.2–§7.5 payout-account screens and the collaborator panel
- §10 events, notifications, jobs; §11's FT-C01 … FT-C29

### Phase 9 — Referral codes, URLs, tracking 🟡
Core built. §11's FT-R01 … FT-R15 still owed.

---

## 4. Phase 10 — where the work actually is 🟠

The financial spine. This is the phase everything downstream leans on.

### Done

| Part | What landed |
|---|---|
| §3 Enums | 27 enums; three **reused**, not re-created. `CommissionRuleSource` deliberately has no `global_default` |
| §2 Schema | 21 migrations / 15 tables. **126/126 objects verified** against `information_schema` on both databases; rollback leaves 0 tables and 0 triggers |
| Guards | 58 assertions proving every unique index, CHECK and trigger actually bites |
| §2.3 Models | 15 models, 201 assertions over 141 relations |
| Write guards | 52 assertions: seven models refusing inserts outside their owning service, INV-4 / INV-8 / INV-17 refusing edits |
| §4 / §5 Registries | **105 modules / 1,037 permissions**, 24 settings keys. Every money module has no `edit` and no `delete`, permanently |
| §6 Services | **11 services**, 15 DTOs, 6 events, 2 listeners, 2 jobs — the full chain from receipt to ledger to wallet |
| §7 Routes | 14 routes. No `edit`, no `destroy` on the ledger |
| §8 Screens | Ledger + approval queue, entry detail with the calculation trace, skip report, rule timeline |

### Left

| | Item |
|---|---|
| ⬜ | §10.4 commands: `commissions:sweep`, `commissions:release-held`, `commissions:evaluate`, `finance:verify-constraints`, and the scheduler entries |
| ⬜ | §7.1 / §8.1 / §8.2 — the four student-fee payment routes and the record-payment wizard that belong to Phase 10 (Phase 18 owns the rest of the fee screens) |
| ⬜ | §11 acceptance suite FT-01 … FT-55 |

### What was proved, and how

Three probes, **220 checks**, every one green:

| Probe | Checks | Proves |
|---|---|---|
| Referral + rules | 71 | versioned attribution, value-date resolution, rule supersession |
| The engine | 66 | every worked example in spine §6.1.7 |
| End to end | 83 | receipt → event → listener → job → ledger → wallet, on a **committed** path |

The arithmetic the whole system rests on, confirmed to the paisa:

- 10 % of a 10,000 receipt = **1,000.00**
- A fixed 2,000 prorated across 3 × 10,000 = 666.67 + 666.66 + 666.67 = **2,000.00 exactly**
- Three partial refunds undoing 333.30 + 333.30 + 333.40 = **1,000.00 exactly**
- 35,000 received against a 25,000 net charge earns on **25,000**, not 35,000

After every scenario, each wallet equals the sum of its ledger and the closed identity
`lifetime = pending + available + reserved + paid` holds.

### Six defects the probes found — all of them money

1. **`uq_cle_reversal_pair` forbade the case the reversal algorithm is built around.** A refund of a
   commission already partly paid out posts *two* debits against one original; the index allowed one.
   The whole refund rolled back and the receipt kept its commission. → migration 22
2. **`uq_cle_source` allowed each partner exactly one manual adjustment, for ever.** → migration 23
3. A superseded referral stopped earning **inside its own window**, so a back-dated receipt silently
   earned nothing
4. A revoked attribution fell through to its predecessor on the changeover day
5. A `max_commission_amount` turned per-receipt partners into prorated ones — "10 % of each receipt up
   to 1,500" was becoming "1,500 spread across the charge"
6. `rule_snapshot` — the permanent record of what a past entry meant — was double-encoded and read back
   as a string

---

## 5. Not started

| Phase | Scope | Notes |
|---|---|---|
| 11 | Project commission engine | Slots into the existing shared guard sequence; only four hooks differ |
| 12 | Wallet, ledger, payouts, statements | `CollaboratorWalletService` already exists with its `applyDelta()` half (D72) |
| 13 | Software-house finance: invoices, payments, expenses | |
| 14–17 | Institute: courses, admissions, teachers, batches, attendance | |
| 18 | Student fees, installments, discounts | **Creates no financial table** — consumes Phase 10's four fee tables and `PaymentService` |
| 19–21 | Course material, exams, certificates | |
| 22 | Tickets, meetings, messaging, notifications | |
| 23 | Reports, analytics, audit, search, exports | Every figure must come from the owning service — no `SUM()` in a report class |
| 24 | Security, financial integrity, responsive and performance testing | |
| 25 | Deployment: install guide, backups, queue, scheduler | |

---

## 6. Things worth knowing before the next session

**The full test suite has not run end to end recently.** The 1,399-test regression slice passes. The
`Cms`, `Crm`, `Hr`, `Project`, `Rbac`, `Settings` and `Modules` suites have not been re-run since the
reversal work landed. That is a real gap, not a formality.

**Browser passes are owed on Phases 1, 3 and 6–9.** No PHPUnit test can see a broken dark-mode
contrast or a console error, and the one time a browser check was done properly it found a five-hour
timezone bug in the attendance register that every probe had confirmed as correct — because every probe
built its expectation the same wrong way (D69).

**Two conventions have now bitten twice.** Phase 8 wrote a `createVersion()` call against a guessed
signature and a sidebar entry against a guessed route name; both sat silently broken until the real
thing arrived. Writing against a contract that has not shipped yet costs more than waiting.

**The dev database holds demo commission data** — 3 partners, 9 receipts, 8 earnings, 1 refund, 1
skipped receipt. Ledger rows cannot be deleted by design, so it stays until the next
`migrate:fresh --seed`.

**Sign in as `admin@myoffice.test`, not `superadmin@`.** The Super Admin carries
`must_change_password`, so every request as that user redirects to the password screen before it
reaches a route.
