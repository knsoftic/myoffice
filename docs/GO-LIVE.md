# GO-LIVE.md — the go-live checklist

The checklist of **phase-24-25 section 6.13**: fifty-two items, each with an id, how it is verified, and
whether it blocks. It is the last gate before real clients, real students and real money reach this system,
and it is deliberately boring — every row is either a command that returns an exit code or a person who
puts their name against a sentence.

```bash
php artisan golive:check
```

The command evaluates every **machine** row and prints every **manual** row as an explicit tick to be
confirmed by a named person. **Exit code 2 while any blocker is open.** The phase is not done, and the
client does not go live, until it exits 0. `--json` emits the same result for a script or a dashboard; the
admin screen renders the same rows in the same groups.

| | Count |
|---|---|
| Items | 52 |
| **Blockers** | **45** |
| Non-blockers (fix, or record why not) | 7 — GL-05, GL-10, GL-23, GL-39, GL-43, GL-45, GL-46 |
| Machine-checkable | 37 |
| Machine plus a human confirmation | 7 — GL-07, GL-10, GL-17, GL-39, GL-43, GL-45, GL-48 |
| Manual only | 8 — GL-28, GL-38, GL-44, GL-46, GL-47, GL-50, GL-51, GL-52 |

**A blocker is fixed, never documented away** (invariant HD-1). The only legitimate way an open blocker
becomes an acceptable state is a written client decision recorded in `DEVELOPMENT_LOG.md` section 9, naming
the risk in plain words and the person who accepted it. Loosening a check to make the command green is a
review failure, not a configuration change.

**A non-blocker is not "optional"** — it is an item whose absence will not hurt anyone on day one. It still
gets an answer, either done or recorded with a date by which it will be.

---

## Environment

Nothing below this group can be trusted until this group is green: a system running with `APP_DEBUG=true`
or with uncached configuration is not the system that was tested.

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-01 | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set and not the example value | machine | `config('app.*')`; DEP-10 `test_production_configuration_sanity` | **yes** |
| GL-02 | `APP_URL` is the real HTTPS host and matches the request host | machine | DEP-10 | **yes** |
| GL-03 | Config, routes, views and events cached | machine | `php artisan about` reports all four CACHED (INSTALL step 13) | **yes** |
| GL-04 | No `env()` call outside `config/` | machine | DEP-04 `test_no_env_call_outside_config` | **yes** |
| GL-05 | `ops.app_version` stamped and matching the deployed release | machine | `settings:set ops.app_version`, stamped by the deploy script | no |
| GL-06 | PHP extensions present; opcache on with `validate_timestamps=0`; `max_input_vars >= 5000` | machine | `php -m`, `php artisan security:audit`; PRODUCTION.md section 7 | **yes** |

GL-04 is the subtle one. Once configuration is cached, an `env()` call outside `config/` returns `null` —
silently, in production only, on the one code path nobody exercised locally. GL-06's `max_input_vars` has
the same shape: below 5000 the role editor's permission matrix is truncated by PHP itself and a role saves
with permissions missing, with no error anywhere.

## Security

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-07 | HTTPS serves, HTTP 301s, no mixed content on home, admin and a print view | machine + manual | PRODUCTION.md section 2 step 3; browser console on the three pages | **yes** |
| GL-08 | `security.force_https` on; HSTS on **after** GL-07 is green | machine | settings; PRODUCTION.md section 2 step 4 | **yes** |
| GL-09 | Every security header present on an authenticated and a public response | machine | SEC-06 `test_security_headers_present` | **yes** |
| GL-10 | CSP enforcing (not report-only) and the report log clean for 48 hours | machine + manual | `security.csp_report_only = false`; the CSP report log | no |
| GL-11 | `php artisan security:audit` exits 0 | machine | `security:audit` | **yes** |
| GL-12 | `audit:manifest --check` exits 0 — every route, upload and index accounted for | machine | `audit:manifest --check` | **yes** |
| GL-13 | Exactly one Super Admin; no account on a seeded or demo password; every staff account's `must_change_password` cleared by its owner | machine | DEP-06 `test_the_first_super_admin_is_created_securely` | **yes** |
| GL-14 | `/register` returns 404 (**D15**); password reset and verification throttled | machine | route test plus the rate limiters | **yes** |
| GL-15 | `.env` not web-reachable and not world-readable; `storage/logs`, `vendor/`, `composer.json`, `/docs`, `/.git` not web-reachable | machine | DEP-09 `test_nothing_outside_public_is_web_reachable` | **yes** |
| GL-16 | Only `storage/` and `bootstrap/cache/` writable by the web user | machine | `security:audit` directory sweep; PRODUCTION.md section 4 | **yes** |
| GL-17 | An uploaded `.php` under the storage alias is served as text or denied, never executed | machine + manual | DEP-08 `test_upload_directories_cannot_execute_code`, plus one manual request against the real Apache | **yes** |
| GL-18 | `composer audit` and `npm audit --omit=dev`: no high or critical advisory | machine | both commands, also run by `security:audit` | **yes** |

GL-17 keeps its manual half on purpose. DEP-08 proves the application refuses the upload and that the
`.htaccess` directives are present; only a real request to the real web server proves Apache is honouring
them. Do that request by hand, from a browser, and record the result.

GL-10 is a non-blocker because a report-only CSP still reports. Leave it report-only until the log is clean
for two days, then enforce — flipping it early breaks working screens for real users, which is a worse
outcome than a header that only observes.

## Data

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-19 | Three least-privilege MySQL users; root has a password; anonymous users dropped; the app user cannot migrate | machine | DEP-07 `test_database_privileges_are_separated`; PRODUCTION.md section 3 | **yes** |
| GL-20 | `migrate:status` clean; `integrity:verify --suite=constraints` exits 0 — every CHECK, generated column, unique guard and delete trigger present | machine | `php artisan integrity:verify --suite=constraints` | **yes** |
| GL-21 | Every money column `decimal(15,2)`, every `*_rate` / `*_percentage` `decimal(8,4)`, no float or double anywhere, and the allowlist still holds exactly its three entries | machine | FIN-18 `test_money_columns_are_decimal_everywhere`; `integrity:verify --suite=schema` | **yes** |
| GL-22 | Demo data **absent** from production; `demo:seed` refuses to run | machine | `demo:seed` refuses when `APP_ENV=production` | **yes** |
| GL-23 | One default branch exists; `institute.default_branch_id` set (**D11**) | machine | settings plus the `branches` table | no |

GL-20 and GL-21 are where the money rules stop being a coding convention and become a property of the
database. The nine `BEFORE DELETE` triggers make an append-only table append-only even against a hand-typed
`DELETE` (**D19**, **D16**); the column-type sweep makes float arithmetic on money impossible to introduce
by accident. FIN-18's allowlist is closed at exactly three entries — `progress`, `rating` and `*_marks` —
and a fourth entry is a review failure, not a configuration change.

## Financial

This group is the evidence behind requirements section 120. It is the group to refuse to compromise on.

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-24 | The nine section-120 tests green, by name | machine | `php artisan test --group=financial-120` | **yes** |
| GL-25 | `collaborators:reconcile-wallets` over every collaborator: zero drift, zero structural failure, the closed identity holds | machine | `php artisan collaborators:reconcile-wallets` (read-only; never `--repair` as a go-live step) | **yes** |
| GL-26 | `fees:verify-plan-integrity` zero drift | machine | `php artisan fees:verify-plan-integrity` | **yes** |
| GL-27 | The property suite green on the committed seeds **and** one fresh random seed | machine | FIN-15 `test_wallet_always_equals_the_ledger` | **yes** |
| GL-28 | Commission settings confirmed with the client **in writing**: base, approval mode, minimum payout, payout request on/off, fixed release mode | manual | recorded in `DEVELOPMENT_LOG.md` section 9 | **yes** |
| GL-29 | `commissions:sweep`, `commissions:release-held`, `collaborators:reconcile-wallets` and `financial:verify-constraints` all listed in `schedule:list` | machine | `php artisan schedule:list` | **yes** |

GL-25's closed identity is `lifetime = pending + available + reserved + paid`, and the wallet balance is a
cache that must always be re-derivable by summing the ledger. Drift is never "rounding" and is never fixed
by adjusting a wallet: a wrong figure is corrected by a reversing entry that references the original
(`CLAUDE.md` section 1, rule 3).

GL-28 is manual and blocking because no test can tell you whether the client agreed to the commission base.
Getting it wrong is not a bug that surfaces in a log — it is money paid to the wrong person for months.

GL-29 matters more than it looks. The suites in it are what make invariant HD-10 true: the proof runs every
night, so a regression introduced in month seven is found that night rather than by a client.

## Performance

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-30 | `perf:budget --all` exits 0 on the volume fixture | machine | `php artisan perf:budget --all` | **yes** |
| GL-31 | No lazy-loading violation in the suite; none logged in the last 24 hours of staging | machine | the suite plus the staging log | **yes** |
| GL-32 | Assets built, hashed, inside budget, no source maps; `build/` served with a one-year immutable cache | machine | PRF-11 `test_asset_budget`; PRODUCTION.md section 1 | **yes** |
| GL-33 | Every index route paginates; exports stream | machine | PRF-05 `test_everything_paginates` | **yes** |

These are blockers because they are cheap now and expensive later. A page that issues a query per row is
fine with fifty rows and unusable with five thousand, and the volume fixture is the only place you will meet
five thousand before the client does.

## Backup

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-34 | Database backup scheduled and a `completed` row exists | machine | `backup_runs`; `schedule:list` | **yes** |
| GL-35 | Files backup scheduled and a `completed` row exists | machine | `backup_runs`; `schedule:list` | **yes** |
| GL-36 | The latest database archive has `verification_status = restore_ok` | machine | DEP-14 `test_deep_verification_restores_and_reconciles`; `backup:verify --latest --deep` | **yes** |
| GL-37 | An offsite copy exists (`offsite_copied_at`) while `backup.offsite_required_for_go_live` is true | machine | `backup_runs.offsite_copied_at` | **yes** |
| GL-38 | A full restore rehearsal into the scratch database performed **by the client's operator**, timed, and the recovery time recorded | manual | recorded in `DEVELOPMENT_LOG.md` | **yes** |
| GL-39 | Retention policy reviewed and the archive store has headroom below `max_storage_gb` | machine + manual | `backup:prune --dry-run`; disk free space | no |

GL-36 is invariant HD-6 in one row: **a backup is not a backup until it has been restored.** The deep verify
restores the archive into `backup.restore_scratch_database`, checks for pending migrations, re-runs the
financial constraint proof on the restored copy and reconciles the wallets, then drops the scratch database.
A checksum alone proves the file is intact, not that it contains a working system.

GL-38 is manual and blocking for a reason that has nothing to do with software: on the day it is needed, the
person restoring will be the client's operator, under pressure, possibly at night. They must have done it
once already, calmly, with somebody to ask. Record the elapsed time — that number is the recovery time
objective, and it is the only honest answer to "how long would we be down?".

## Operations

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-40 | Queue worker running as a service, auto-restarting, heartbeat fresh | machine | `php artisan ops:health`; PRODUCTION.md section 5 | **yes** |
| GL-41 | Scheduler running every minute, heartbeat fresh, every scheduled command listed | machine | `schedule:list`; `ops.scheduler_heartbeat`; PRODUCTION.md section 6 | **yes** |
| GL-42 | `failed_jobs` empty, or every row read and explained | machine | `php artisan queue:failed` | **yes** |
| GL-43 | `/health` answers with the token and 404s without it; the client's monitoring points at it | machine + manual | `ops:health --json`; the monitoring configuration | no |
| GL-44 | Error notifications reach a real person: `ops:digest` delivered, `backup.notify_emails` verified, SMTP test mail received | manual | the recipient confirms receipt | **yes** |
| GL-45 | Log rotation verified: application dailies pruned at `ops.log_retention_days`, Apache logs rotating | machine + manual | `storage/logs` contents; the OS rotation job | no |
| GL-46 | Maintenance mode tested with the secret URL, and the 503 page is the branded one | manual | `php artisan down --secret=...` then the secret URL | no |

GL-42 says "read and explained", not "cleared". `queue:flush` on a table nobody read discards work —
including commission jobs, which is lost money until the sweeper re-queues it. Read the rows, fix the cause,
retry them.

GL-44 is blocking because unmonitored monitoring is worse than none: it produces the belief that somebody
would be told. Send the digest and the failure notification to the real address and have the real person say
they received it.

## UX

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-47 | The responsive matrix ticked for every screen at five widths in both themes | manual | the per-screen matrix of the phase contract section 8.7 | **yes** |
| GL-48 | The accessibility matrix ticked; `a11y:scan` exits 0; contrast pairs pass in both themes | machine + manual | `php artisan a11y:scan`; the matrix of section 8.8 | **yes** |
| GL-49 | Every error page (403, 404, 419, 429, 500, 503) is branded and leaks nothing | machine | SEC-37 `test_error_pages_are_branded_and_silent` | **yes** |

GL-48 has a machine half and a human half, and the human half is the one that decides it: a scan can prove
a label exists, but only a person can prove a screen is operable end to end without a mouse. Run
`a11y:scan` first so the manual pass is not spent on findings a command would have caught. GL-47 is manual
in full — five widths, two themes, every screen — and is blocking because a panel that is unusable on a
phone is unusable for most of the people who will use it.

## Content

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-50 | Company details, logo, favicon, currency PKR, timezone Asia/Karachi, date format, SEO defaults, sitemap and robots all set **by the client** | manual | the settings screens; the rendered public site | **yes** |

"By the client" is the operative phrase. Placeholder branding on a public site on launch day is the one
failure every visitor sees, and it is the client who knows which logo is current.

## Sign-off

| # | Item | Verified by | Evidence | Blocks |
|---|---|---|---|---|
| GL-51 | `DEVELOPMENT_LOG.md` section 5 shows phases 1-25 `[x]` with test notes; section 7 carries the dated results of every suite; section 8 lists every accepted risk; section 9 every client decision | manual | the log itself | **yes** |
| GL-52 | The client names the people who hold Super Admin, `backups.restore` and `collaborator_payouts.approve`, and confirms the separation of duties | manual, recorded | `DEVELOPMENT_LOG.md` section 9 | **yes** |

GL-52 is the last row because it is the one that outlives the project. Three permissions can each cause
irreversible harm — a Super Admin sees and does everything, `backups.restore` can overwrite today's data
with last night's, and `collaborator_payouts.approve` releases money. Name the holders, write them down, and
confirm that the person who approves a payout is not the person who requests it.

---

## How the manual rows are recorded

A manual tick is a sentence with a name and a date, in `DEVELOPMENT_LOG.md`, not a nod in a meeting:

> **GL-38** — full restore rehearsal into `my_office_restore_test` performed by `<operator name>` on
> `<date>`; elapsed 41 minutes; proof: constraints pass, reconciliation drift 0.00. Recovery time
> objective recorded as 45 minutes.

`golive:check` prints each manual row so it cannot be quietly skipped, but it cannot know whether the tick
is true. That is the point of writing it down: in eight months, when somebody asks whether the restore was
ever rehearsed, the answer is a line with a name on it rather than a recollection.

## When a blocker cannot be closed before launch

1. Write it in `DEVELOPMENT_LOG.md` section 8 (known issues) with its GL id and what it actually risks —
   in the client's language, not "GL-37 open" but "we have no copy of the data outside this building".
2. Get the client's decision in writing and quote it in section 9, with a date.
3. Give it an owner and a date by which it will be closed.
4. Leave `golive:check` red. Never edit a check to make the command green; the command's value is that it
   disagrees with people.

---

| Related | Document |
|---|---|
| Installation, step by step | [`INSTALL.md`](INSTALL.md) |
| Web server, TLS, database users, permissions, queue, scheduler, PHP | [`PRODUCTION.md`](PRODUCTION.md) |
| Backups, verification, retention, restore | phase-24-25 section 6.10 |
| Deploy runbook, rollback ladder | phase-24-25 sections 6.11 and 6.12 |
