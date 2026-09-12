# CONSISTENCY AUDIT — ROUND 3 (closing the convergence drift)

**Status: advisory.** This document changes no contract. It checks each of `ND-1 … ND-13` from
[`consistency-audit-round-2.md`](consistency-audit-round-2.md) §4 against the **current** text of the
fourteen phase contracts, [`resolutions.md`](resolutions.md),
[`finance-commission-spine.md`](finance-commission-spine.md), [`build-order.md`](build-order.md), the four
`data-model/` files, `CLAUDE.md` and `DEVELOPMENT_LOG.md`; then hunts specifically for drift the ND fixes
themselves could have produced.

Verdicts: **CLOSED** = every document that touches the item now says the same thing · **PARTIAL** = the
owning contracts are right but another file still carries the superseded text · **OPEN** = not done.
New drift is numbered **RD-1 … RD-8**.

---

## 1. Scoreboard — ND-1 … ND-13

| ND | Sev | Verdict | Evidence (document · section · line) |
|---|---|---|---|
| ND-1 project-payment route gated on an unregistered ability | high | **PARTIAL** | **Decided and applied:** a dedicated narrow ability `project_payments.link_invoice`. spine §4.1 L1265 (slug row) + L1273-1290 (seven-row property table: one mutation only, no preset, not in `MONEY`, Accountant-only, always paired), §1.4 INV-8 L104 + guard 4 L135 ("Resolved — ND-1"), §7.2 L1928-1930, §13.2 L2418 (Phase 1 `Ability::LinkInvoice` + `RoleSeeder`), §13.3 L2431, FT-52 L2340 · phase-13 §4.1 L610 ("Phase 13 invents no `Ability` case"), §4.2 L623, §4.5 rule 5 L658, §6.3 rules 4/5/**5a** L755-757, §7.1 L999-1011, §8.3 L1181, §12.1 R-2 L1631, §13.1 L1668-1669, §13.3 L1701, test 53 L1613 · phase-10-12 §13.2 L1340. **Residual: see RD-1 (phase-10-12 §4.1 never registers the ability) and RD-2 (`resolutions.md` and `DEVELOPMENT_LOG.md` §4 D43 still say `project_payments.edit`).** |
| ND-2 D43 unreachable through the model guard | high | **CLOSED** | spine §1.4 L142-155 publishes the **canonical whitelist clause** as a quoted block · phase-10-12 §2.3 Guards row L177 admits `invoice_id` "**on `ProjectPayment` only**", and L188-203 reproduces the clause. I extracted both quoted blocks and diffed them: **7 lines each, byte-identical.** Also §13.2 L1340 (Phase 13 the only permitted user, one service, one ability pair), test 41a L1217-area. `StudentFeePayment` explicitly gains nothing; no money column joins the whitelist. |
| ND-3 `contact_inquiries` snapshot columns referenced but undefined | high | **PARTIAL** | **Defined by the owner:** phase-04 §2.20 L566-568 (`collaborator_id` unsignedBigInteger/null/index/**no FK, deferred Phase 8**; `referral_code` string(32)/null/index; `referral_visit_id` unsignedBigInteger/null/index/**deferred Phase 9**), Keys L588 (three indexes), five-rule snapshot block L594-618, §2.1 L82-91 (deferred list 12 → **14**, "All fourteen follow the identical deferred pattern"), §6.10.5 L1312-1314 (snapshot recorded; a browser-posted `collaborator_id` **discarded**), §9.3 L1845-1847, tests 61-64 L2067-2077, §13 **Phase 8-9 block** L2182-2185 opening "Requests honoured", §13 Phase 24 manifest row L2222 · phase-08-09 §13.1 L1085 and §10.2 L934 both now cite **phase-04 §2.20** as the definer. **Residual: RD-3 — the retention-sweep guard phase-04 §13 asks for does not exist in phase-08-09.** |
| ND-4 two `InquiryTarget` namespaces | high | **CLOSED** | phase-04 §6.10.1 L1199 `// App\Contracts\Inquiry\InquiryTarget  (ND-4)` + rationale L1218-1222 ("`App\Contracts\Cms\InquiryTarget` does not exist") · phase-05 §6.10 L735 unchanged and canonical · phase-03 §13.3 L1737 and phase-14-17 §2.11 / §13.1 name it without a namespace (neutral). A repo-wide grep finds **no** surviving `Contracts\Cms\InquiryTarget`. `InquiryRouter` stays `App\Services\Cms\InquiryRouter`. |
| ND-5 one sanitiser cannot serve the print templates | med | **CLOSED** | phase-03 §6.6 L895 publishes `RichText::sanitize(string $html, string $profile = 'cms'): string`; L897-909 the closed class-level `PROFILES` map (exactly two: `cms`, `material`), `UnknownRichTextProfileException` on anything else, the caller's string never forwarded to `mews/purifier` as a config key; L910-917 the non-weakenable common core; INV-13 L92 restated "under any profile"; §13.3 L1733; §13.4 L1746 commits both profiles in `config/purifier.php`; FT-36b L1655 · phase-19-23 §1.2 L84, INV-21-5 L137, §6.13 L1950, §13.1 L3541 all call `RichText::sanitize($html, 'material')` and the self-contradicting "configured with this wider tag set … no second purifier profile of its own" sentence is gone · resolutions §2.3 L93 and §9 L532 record the same signature. Allowlist cross-check: phase-19-23's enumerated `material` set is exactly phase-03's "everything in `cms`, minus the iframe hosts, plus `div h1 h5 h6 small b i sub sup`, `style`, `align`, `data:` images" — no tag is claimed in one file and absent in the other. |
| ND-6 company-wide totals missing | med | **CLOSED** *(contracts)* | spine §6.2 L1556 / L1559 `payoutsPaidTotal(?Collaborator $c, ?DateRange $r = null)` / `commissionAccruedTotal(?Collaborator $c, ?DateRange $r = null)`, null = company-wide, no default on the first parameter, range mandatory when the collaborator is null; §6.5.1 L1778-1786 (same canonical SQL minus the `collaborator_id` predicate, **one query, never a loop**, no company wallet); FT-42 L2325 · phase-13 §6.7.3 L883-918 + §13.1 L1675 + test 37 L1545 · phase-10-12 §6.3 L595/L623 + §13.2 L1336 + test 55 L1249. **One name in all three files; no `*All` sibling survives in any contract.** Stale elsewhere: RD-4, RD-5. |
| ND-7 F-4.9 applied two ways | med | **CLOSED** | resolutions §3.3 F-4.9 L161 now instructs cache-recompute-and-notify only (`RecomputeCachesAfterReversalRejected` over D40's SQL + `NotifyReversalRequester`), states the rollback and status restore stay inside `PaymentService::rejectReversal()`'s transaction, and spells out the double-decrement / INV-9 failure; §8 row 21 L514 forbids "restoring" the listener; §9 L529. Matches spine §10.1, phase-10-12 §10.1 and phase-18 §6.10.5 as built. |
| ND-8 `collaborator_referrals.subject_id` named but absent | low | **CLOSED** | resolutions §3.6 F-8.2 / F-12.2 L204 corrected to `status = active` + `subject_type = ReferralSubject::Project` + `project_id = projects.id` + `collaborator_id = mine`, names the four explicit FKs and `chk_cr_one_subject`, and records that the old wording "must not be re-applied literally"; §9 L530. phase-06 §9 L1331 already correct. `data-model/03` L534 independently records the same correction. |
| ND-9 `website` group 34 keys or 35 | low | **PARTIAL** | **Counted independently, not taken on trust:** phase-03 §5.1a = **13** rows, §5.1b = **21** rows, phase-04 §5 = **21** rows, and a sorted name-by-name diff of §5.1b against phase-04 §5 is **identical** — so 13 + 21 = **34** and nothing was lost in the merge. Stated at phase-03 §5 L596-603 and phase-04 §5 L723-731, both naming the canonical file as the error. **Residual: RD-6 — `resolutions.md` still says 22 / 35 in two places.** |
| ND-10 numbering owner's consumer list short | low | **CLOSED** | phase-05 §1.3 L75, §6.10 L734, §13.1 L1405 all read **6, 7, 8-9, 10, 13, 14-17, 18, 19-23**, plus a nine-row per-phase table at §6.10 L744-756 naming what each phase numbers and the pad it passes. Spot-checked the three added consumers: phase-13 (§1.2, §6.5), phase-18 (§2.6), phase-19-23 (§13.1 L3523) each call the class by name. |
| ND-11 "19 methods" vs 20 | low | **CLOSED** | phase-24-25 §13.1 L1917 (list + "**20 methods**"), log row L1985 corrected, drift row L2003 · agrees with phase-01 §3 / §11 A1 L336, build-order L121 / L292 and resolutions §9 L533. I counted the published list: **20** methods + the `RemainderPlacement` **enum** (not a method). |
| ND-12 `uq_cr_superseded_by` vs the losing-candidate row | low | **PARTIAL** | spine §2.8 L492 (column row) + Keys L506 now carry the plain **`INDEX idx_cr_superseded_by`**, "deliberately NOT unique", with the one-active-referral guarantee resting **solely** on the four `uq_cr_*_current` indexes over `current_guard` (L515); both writers say so (§6.2 L1531 `change()`, L1534 `recordLosingCandidate()`); FT-53 L2301 · phase-10-12 §2.2 migration 17 L141 (non-unique, outside the guard-unique list), §6.3 L508/L509, test 34a L1217. **Residual: RD-7 (phase-08-09 §6.3 modifier 7 still asserts the removed unique index) and RD-8 (`data-model/04` still tags the column UQ).** |
| ND-13 one SEO validation contract, two places | low | **CLOSED** | phase-04 §6.11 L1349-1363 — the Form Request row drops `meta_description max:255`, a delegation rule forbids any request here from declaring a rule for any of the six `seo_meta` columns, and the `array_merge($own, app(SeoService::class)->rules())` form is shown; §8.13 L1750 "one validation contract" made literal; test 65 L2078-2081 · phase-03 §6.5 L829 publishes `rules(string $prefix = 'seo'): array` and L832-843 takes every `max` from the real column width — cross-checked against §2.12 L383: `meta_description` is `string(320)`, so the deleted rule was also a **wrong** rule. |

**Counts.** 13 checked · **8 CLOSED** · **5 PARTIAL** (ND-1, ND-3, ND-9, ND-12 and, for files outside the
contracts, ND-6) · **0 OPEN**. Every PARTIAL is a *non-owning* file still carrying superseded text; no
phase contract contradicts another on any of the thirteen items.

---

## 2. The five targeted drift checks

| Check | Result |
|---|---|
| **`project_payments.link_invoice` declared in one place, gated in another** | **Real drift found.** The spine declares it; phase-13 gates two routes on it; **phase-10-12 §4.1 — the contract that registers the four spine module slugs, and whose heading literally reads "New module slugs (spine §4.1)" — does not list it** (L276: `READ + create + print + export + STATUS + MONEY + LOGS`, "Deliberately absent: `edit`, `delete`"). See **RD-1**. The canonical registry rows still name the old ability: **RD-2**. |
| **`RichText` profile argument published with two signatures** | **Clean.** Exactly one signature occurs system-wide — `sanitize(string $html, string $profile = 'cms'): string` — in phase-03 §6.6 L895 and §13.3 L1733, phase-19-23 §1.2 L84 / §6.13 L1950 / §13.1 L3541, resolutions §2.3 L93. The single-argument form survives only in prose that quotes no signature (`data-model.md` L773, `data-model/02` L404/L432, `data-model/05` L110, build-order E16 L136) and in the round-2 audit itself — all neutral. phase-19-23 §6.13's own `PrintTemplateService::sanitize(string $html): string` is a different method on a different class, and its body calls the profiled one. The profile is named **`material`** everywhere (phase-03's FT-36b deliberately uses `'print'` as the *unknown-profile* example, which is consistent, not a second name). |
| **Company-wide totals named differently in the spine and phase-13** | **Clean between them.** spine §6.2, phase-13 §6.7.3 / §13.1 and phase-10-12 §6.3 / §13.2 use the identical two names and the identical `?Collaborator` shape; the audit's suggested `payoutsPaidTotalAll` / `commissionAccruedTotalAll` appear in **no** contract. The divergence is in two non-contract files: **RD-4** (build-order still prescribes the sibling method and still calls the item open) and **RD-5** (`data-model/04` still records it missing). |
| **`contact_inquiries` columns defined by phase-04, described differently by phase-08-09** | **Substantively consistent.** phase-08-09 §13.1 L1085 opens "`collaborator_id` FK nullable `nullOnDelete` … `referral_visit_id` FK nullable `nullOnDelete`" where phase-04 §2.20 ships `unsignedBigInteger` with **no FK yet**; the same cell immediately says "with deferred guarded FKs in the same pattern as `job_applications.employee_id`", which is exactly phase-04's pattern, so the two read as one design. Types and names match (`referral_code` string(32)); both call all three D37 display snapshots that no engine and no access scope reads; §10.2 L934 scopes its claim to "the three columns defined at phase-04 §2.20", so it no longer over-claims `referral_source` / `referral_date` on that table. The one real gap is the retention sweep: **RD-3**. |
| **A convergence log or drift-fix table claiming a change its own document does not contain** | **None found.** I resolved every row of all ten "Drift fixes (round 2)" tables against its own document, including the section locators: spine L2472ff (ND-1/2/6/12), phase-03 L1776ff (ND-5/9/13), phase-04 L2254ff (ND-3/4/9/13), phase-05 L1464ff (ND-4/10), phase-08-09 L1143ff (ND-3), phase-10-12 L1394ff (ND-2/6/12), phase-13 L1737ff (ND-1/6), phase-19-23 L3607ff (ND-5), phase-24-25 L1996ff (ND-11), resolutions L518ff (ND-5/7/8/11). Named artefacts that exist where claimed: spine's seven-row property table (L1273-1290), phase-04 §2.20's three columns and §13 Phase 8-9 block, phase-05 §6.10's nine-row table, phase-13 §8.3 (heading L1168) and §7.1 (heading L977), phase-04 §6.10.5 (block label L1296), tests FT-52/FT-53/FT-36b/41a/34a/53/65/test 37/test 55. Round-1 `F-` rows that the round-2 edits overtook (spine F-4.8 L2455, F-4.10 L2457; phase-13 F-4.8 L1721, F-4.10 L1723; phase-10-12 F-4.4 L1369, F-4.8 L1370; phase-19-23 F-2.5 L3582) each carry an explicit "Superseded by ND-n" pointer in the same cell, so no log sentence stands as a live false claim. |

---

## 3. New drift (round 3)

### RD-1 · high · the ability is declared by the spine and registered by nobody

`phase-10-12.md` §4.1 L276 (heading L272: *"New module slugs (spine §4.1)"*) registers

```
| project_payments | Finance | banknotes | false | READ + create + print + export + STATUS + MONEY + LOGS | edit, delete |
```

The spine's own §4.1 L1265 row for the same slug now ends **`+ link_invoice`**, and phase-13 §4.2 L623 says
the ability arrives as part of "the spine set (which already includes `link_invoice`, spine §4.1)". But the
four spine slugs are registered in **Phase 10**, from phase-10-12 §4.1 — that table is the build artefact —
and the string `link_invoice` occurs in phase-10-12 only inside the ND-2 quotation (L196) and two §13 rows
(L1340, L1405). Effect: the permission is never created, so phase-13's `can:project_payments.link_invoice`
routes are unreachable for every role — **the exact failure ND-1 was opened to remove**, moved one phase
earlier. Fix (one line): add `+ link_invoice` to phase-10-12 §4.1's `project_payments` row with the spine
§4.1 / ND-1 citation, keeping `edit`/`delete` in the "deliberately absent" cell. The spine §13.2 L2418
request should also name phase-10-12 §4.1 beside Phase 1: the `Ability` **enum case** is Phase 1's, but the
per-slug ability **list** for `project_payments` belongs to the Phase 10 registration.

### RD-2 · high · the superseded D43 wording is now in the two highest-authority files

`resolutions.md` still gates D43 on `project_payments.edit` in three places — §3.3 F-4.10 L162, the §4.1
registry row L273, and the §4.3 paste text L342 — and that paste has **already been applied**:
`DEVELOPMENT_LOG.md` §4 **L157** now reads *"gated by `project_payments.edit` + `invoices.edit`"*. The
decision log is the file a developer trusts last-in-wins, and it now names an ability the spine guarantees
will never exist (spine §4.1 L1280, FT-52). `data-model/04-finance-collaborator.md` §7 item 1 L417 still
presents the whole thing as an open question whose "conservative reading" is `project_payments.change_status`.
Fix: correct the three `resolutions.md` rows to `project_payments.link_invoice` + `invoices.edit` (spine
§13.3 L2431 and phase-13 §13.3 L1701 carry the identical approved wording to paste), then re-paste D43 into
`DEVELOPMENT_LOG.md` §4 L157, and retire item 1 of `data-model/04` §7. Related pending owner request, still
unapplied: `CLAUDE.md` §4's ability list (L129-132) does not include `link_invoice` (asked for at spine
§13.3 L2433).

### RD-3 · med · ND-3's new evidence column can be pruned away

phase-04 §2.20 rule 4 (L613-615) states that phase-09's retention sweep "must not prune a visit an inquiry
still points at", and phase-04 §13's Phase 8-9 block L2184 formally requests that extension. phase-08-09
does not contain it: **INV-R6 L111**, the `referrals:prune-visits` row **§10.4 L957** and **FT-R15 L1031**
name only `collaborator_referrals.referral_visit_id` and `leads.referral_visit_id`. So phase-04 asserts a
guarantee its owner has not granted, and `contact_inquiries.referral_visit_id` is prunable after 365 days.
The same block also asks Phase 8 and Phase 9 for the two guarded FK-promotion migrations (L2183-2184);
phase-08-09 records neither — `contact_inquiries` appears there only at L934, L1085, L1124, L1150.
*Wider than ND-3:* the same sweep already ignores `students.referral_visit_id`,
`student_applications.referral_visit_id` and `course_inquiries.referral_visit_id` (phase-14-17 §2.11 L436,
§2.13/§2.14, §13.1 L2784), so INV-R6 names two of at least five referencing columns. Fix: restate INV-R6,
§10.4 and FT-R15 as "**any** column that references `collaborator_referral_visits.id`" and list the five,
rather than enumerating two; add the two FK-promotion rows to phase-08-09's migration plan.

### RD-4 · med · `build-order.md` still prescribes the rejected design for ND-6

L177 (F17) records the company-wide totals as *"open; see §10"*, and L381 (B-H2) answers the question with
**"A sibling method on each service (`payoutsPaidTotalAll(?DateRange)`, `commissionAccruedTotalAll(?DateRange)`)
… a nullable parameter on a money total is easy to pass by accident."* The spine decided the opposite and
three contracts implemented it (nullable first parameter, no default, range mandatory when null). Since
`build-order.md` is the sequencing document a builder reads before the contracts, it will produce two method
names. Fix: F17 → satisfied, pointing at spine §6.2 / §6.5.1; B-H2 → answered, recording *why* the nullable
form is safe here (no default on parameter 1, so `null` is always typed at the call site; both-null throws).

### RD-5 · low · `data-model/04` records three closed items as open

§7 item 1 L417 (ND-1, see RD-2) · item 8 L424 *"Company-wide totals are still missing … phase-13 §13.1
(marked **Still open**)"* — phase-13 §13.1 L1675 now reads SATISFIED · and L51's `collaborator_referrals`
row (see RD-8). Read-only summary files going stale is expected, but these three are the kind a later agent
"re-fixes".

### RD-6 · low · `resolutions.md` is the last file counting 35 `website` keys

§2.4 L107 *"phase-03's 13 keys and phase-04's 22 keys merged (35 keys)"* and §3.3 F-6.3 L189 *"list all 35
keys"*. Both contracts now say 34 and name this row as the error (phase-03 L601-603, phase-04 L729-731), and
I verified 13 + 21 with a name-level diff. F-6.3's apply instruction is the dangerous half: re-applied
literally it tells phase-03 §5.1 to list a 22nd Phase-4 key that does not exist.

### RD-7 · med · phase-08-09 still leans on the index ND-12 removed

§6.3 modifier 7 L531: *"`uq_cr_superseded_by` means only one loser may point at a given winner, which is
exactly the one-submission case."* That index no longer exists (spine §2.8 L506), and phase-08-09 is the
**caller** of `recordLosingCandidate()` — it is the document most likely to encode "one loser per winner"
into the resolver's ladder. Fix: delete or invert the sentence, citing `idx_cr_superseded_by` (non-unique)
and `uq_cr_*_current` as the only uniqueness that binds. phase-08-09's own drift-fix table (L1143ff) covers
ND-3 only, so nothing there contradicts this — the sentence is simply untouched.

### RD-8 · low · `data-model/04` L51 still tags `superseded_by_id` "UQ"

`… previous_referral_id / superseded_by_id **UQ** / superseded_at …` — the one-line model inventory is where
a migration author checks index kinds. Fix: drop the UQ tag, note the plain `idx_cr_superseded_by`.

---

## 4. The four round-2 PARTIALs — now closed by the owner

| Round-2 item | State today |
|---|---|
| F-10.1 `DEVELOPMENT_LOG.md` §4 held only the `D17+` pointer | **Closed.** D17 L131 … D60 L174 are all present as individual rows. Thirteen contracts' "`DEVELOPMENT_LOG.md` §4 D19/D20/D21/D22/D25/D27/D31/D32/D37/D43/D60" citations now resolve. **But the D43 row carries the pre-ND-1 wording — RD-2.** |
| F-9.1 `CLAUDE.md` §3 said "every business table … `deleted_at`" | **Closed.** L88-89 no longer claims `deleted_at`; the append-only category rule is at L93-107 with "Never add `deleted_at` back to one of these tables", the `files` → `attachments` block at L111-116, and the one percentage width at L118 (`decimal(8,4)`, marks excluded). |
| F-11.2 `DEVELOPMENT_LOG.md` §5 PHASE 8 | **Closed.** Release note at L248 (spine's 15 tables applied in the same release, screens hidden behind module switches, the two backfill commands). |
| F-11.3 `DEVELOPMENT_LOG.md` §5 PHASE 18 | **Closed.** Release note at L261 ("Phase 18 creates no financial table"). |

---

## 5. Checked and clean — do not "re-fix"

| Checked | Result |
|---|---|
| Money guarantees across every ND edit | **Nothing weakened.** ND-1 *narrows* a money module's write surface (a single-purpose ability instead of the `edit` D43's literal wording asked for; `project_payments` and `student_fee_payments` still carry no `edit`, ever). ND-2 is the document link D43 already granted, now with the enforcing guard named and a reason/audit/zero-commission clause quoted in both files. ND-6 moves an aggregate *into* the two services that own the canonical SQL (INV-26 intact, one query, paisa-exact identity asserted). ND-12 **removes a unique index**, which is the one change worth challenging — it removes no guarantee: "at most one active referral per subject" was always `uq_cr_*_current` over `current_guard` (INV-18, INV-R5, FT-R02), and the unique index it replaces would have raised 1062 on a legal `change()` + `recordLosingCandidate()` pair and destroyed attribution evidence. No CHECK, trigger, generated column or invariant was dropped anywhere. |
| `invoice_id` whitelist scope | `ProjectPayment` only; `StudentFeePayment` has no such column; `amount`, `paid_on`, `payment_method_id` and every commission column stay outside the whitelist (phase-10-12 §2.3 L204-212). |
| `RichText` common core under the wider profile | phase-03 L910-917 binds `script`/`object`/`embed`/`link`/`meta`/`form`/`input`/`base`/`applet`, every `on*`, `javascript:`/`vbscript:`/`file:`, every Blade and PHP construct and every non-allowlisted `<iframe>` under **both** profiles; `material` loses the iframe hosts rather than gaining any; `data:` is an `<img src>` concession for four image types and never an `href` (§6.6 Link-fields row L893); FT-36b asserts it. D25 holds: one class, one code path. |
| `DocumentNumberService` consumer list | nine rows, and each named phase really calls the class (spot-checked 13, 18, 19-23). |
| `Money` surface | 20 methods + `RemainderPlacement` in phase-01 §3, phase-24-25 §13.1, build-order and resolutions — one list, one count. |
| `SeoService::rules()` maxes | every `max` equals the phase-03 §2.12 column width; `meta_description` 320 (not 255). |
| Decision numbers D17-D60 | no number claimed twice; the log rows and the thirteen contracts' §13.3 wordings agree except D43 (RD-2). |

---

## 6. Recommended order

| # | Action | Owner |
|---|---|---|
| 1 | **RD-1** — add `link_invoice` to phase-10-12 §4.1's `project_payments` row (one cell); without it ND-1's fix does not exist at runtime | contract edit |
| 2 | **RD-2** — correct D43 in `resolutions.md` §3.3 / §4.1 / §4.3, re-paste into `DEVELOPMENT_LOG.md` §4 L157, add `link_invoice` to `CLAUDE.md` §4, retire `data-model/04` §7 item 1 | canonical + **owner** |
| 3 | **RD-3** — generalise phase-08-09 INV-R6 / §10.4 / FT-R15 to every column referencing `collaborator_referral_visits.id`, and record the two FK-promotion migrations | contract edit |
| 4 | **RD-7** — delete phase-08-09 §6.3 modifier 7's `uq_cr_superseded_by` sentence | contract edit |
| 5 | **RD-4** — build-order F17 / B-H2 to the implemented nullable-parameter form | design file |
| 6 | **RD-6**, **RD-5**, **RD-8** — the remaining stale counts and tags (`resolutions.md` §2.4 / F-6.3; `data-model/04` L51, L424) | canonical / data-model |

---

## 7. Verdict

**Yes, with two exceptions that are one cell each:** the fourteen phase contracts and the financial spine are
now internally consistent on all thirteen ND items — every remaining mismatch lives in a *derived* file
(`resolutions.md`, `build-order.md`, `data-model/*`, the decision log) except **RD-1** (phase-10-12 §4.1 must
register `project_payments.link_invoice`) and **RD-3/RD-7** (phase-08-09 must grant the retention guard it is
asked for and drop the unique index it no longer has) — so phases 3+ can be built from these contracts once
RD-1 and RD-2 land, because until they do the two D43 routes are unreachable and the decision log names an
ability that will never exist.

---

## Drift fixes (round 2)

*This file is a findings list and changes no contract; the column records the verdict reached for each
round-2 item and where the round-3 follow-up is filed.*

| ND | Change made |
|---|---|
| ND-1 | Verified in spine §4.1/§1.4/§7.2/§13.2-13.3 + phase-13 §4.1/§4.2/§4.5/§6.3/§7.1/§8.3/§12.1/§13 — recorded **PARTIAL**; residual drift filed as **RD-1** (phase-10-12 §4.1 never registers the ability) and **RD-2** (`resolutions.md` + `DEVELOPMENT_LOG.md` §4 D43 still say `project_payments.edit`). No contract text edited here. |
| ND-2 | Verified **CLOSED** — the two quoted whitelist blocks extracted and diffed byte-for-byte (7 lines, identical); guard admits `invoice_id` on `ProjectPayment` only. |
| ND-3 | Verified **PARTIAL** — all three columns defined at phase-04 §2.20 with the snapshot rules, tests 61-64 and the §13 Phase 8-9 block; the retention-sweep guard and the two FK promotions phase-04 requests are absent from phase-08-09, filed as **RD-3**. |
| ND-4 | Verified **CLOSED** — one namespace (`App\Contracts\Inquiry\InquiryTarget`); no `Contracts\Cms\InquiryTarget` survives anywhere. |
| ND-5 | Verified **CLOSED** — one signature system-wide, two committed profiles (`cms`, `material`), closed `PROFILES` map, non-weakenable common core, FT-36b; phase-19-23's enumerated `material` set matches phase-03's definition tag for tag. |
| ND-6 | Verified **CLOSED** in the spine, phase-13 and phase-10-12 (identical names, null = company-wide, one query, paisa-exact identity); the rejected `*All` design survives only in `build-order.md` (**RD-4**) and a stale `data-model/04` row (**RD-5**). |
| ND-7 | Verified **CLOSED** — resolutions §3.3 F-4.9 now matches the implemented rollback-inside-the-transaction form and §8 row 21 locks it. |
| ND-8 | Verified **CLOSED** — resolutions §3.6 names `subject_type` + the four explicit subject FKs under `chk_cr_one_subject`. |
| ND-9 | Counted independently (13 + 21, names diffed) — **34** confirmed; recorded **PARTIAL** because `resolutions.md` §2.4 and F-6.3 still say 22 / 35, filed as **RD-6**. |
| ND-10 | Verified **CLOSED** — nine consumers in §1.3, §6.10 and §13.1 plus the per-phase table; the three added phases really call the class. |
| ND-11 | Verified **CLOSED** — 20 methods counted from the published list; all four citing files agree. |
| ND-12 | Verified **PARTIAL** — spine §2.8 and phase-10-12 carry the non-unique `idx_cr_superseded_by` and rest the guarantee on `uq_cr_*_current`; phase-08-09 §6.3 modifier 7 (**RD-7**) and `data-model/04` L51 (**RD-8**) still assert the removed unique index. |
| ND-13 | Verified **CLOSED** — phase-04 §6.11 delegates to `SeoService::rules()` (phase-03 §6.5), whose maxes match the real column widths; test 65 asserts no `seo_meta` rule survives in `app/Http/Requests/Cms/**`. |
