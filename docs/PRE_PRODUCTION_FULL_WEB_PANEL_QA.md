# Pre-production QA gate — Fayadhowr HR & Marketing Web Panel

Date: 2026-11-06. Production baseline: `main` = `010005a` (live). Candidate: branch `feat/hr-release1c-20261105`, working tree **uncommitted** (41 modified + 16 untracked paths on top of `010005a`; nothing staged, committed, pushed, merged or deployed).

## 1. Verdict

**Update 2026-11-07 (F-A implemented and release readiness, sections 15–17): PASS for the local candidate** (the earlier batch report, section 14, was HOLD because of the undecided F-A; the owner approved F-A and it is implemented and verified). Release only on the owner's explicit instruction.

**Earlier update 2026-11-07 (batch completion, section 14): still HOLD.** The remaining browser workflows, O-1 in both directions, payroll regression, the role matrices and all suites were completed; the one open item that keeps the HOLD is the undecided, potentially money-affecting rule F-A (section 14.10). The text below is the original 2026-11-06 verdict.

**HOLD — do not release yet.** No critical or high-severity defect was found in the product, no test fails, no approved rule or translation changed, and the protected payroll rules held in every workflow driven. But the gate's own PASS CONDITIONS ("every implemented HR workflow has been tested", browser evidence documented) are **not fully met**: several modules were only load-checked, one workflow (Stop-with-away) and the Replace / Return / Reopen dialogs were not driven in the browser in this gate, and screenshots exist only as session captures. Section 8 lists exactly what is missing. The release authorisation is conditional on a complete pass, so the release sequence was **not** started.

## 2. Environment and method (nothing here touched production)

- App under test: the working-tree code served locally — API `127.0.0.1:8012` (SQLite scratch file), panel `127.0.0.1:5191` (Vite). PostgreSQL 18.6 (`127.0.0.1:5433`, disposable databases) for the PG test runs. No Railway, no production URL, no production data.
- Scratch database: backed up first (`pay_scratch_before_gate.sqlite`), then the one missing migration (`generation_inputs`) applied to that disposable file only. Synthetic data only: companies `GATE Alpha / Beta / Gamma Co`, people `GATE …`, month under test **October 2026**.
- Role accounts (random throw-away passwords in a local file, never printed in reports): Super Admin, HR Manager (permission set equal to the production HR Manager set recorded in `RoleAccessMatrixTest`), Marketing Manager, Mobile App Manager, Marketing Employee (same grants as the real "Add Team Member" flow).
- Evidence types, always stated: **B** = driven and observed in the real browser (Chrome, accessibility tree / DOM / on-screen text / a few screenshots); **A** = real HTTP against the local API (direct URLs, so it proves server enforcement); **T** = automated test run.
- Tooling notes: screenshots intermittently time out in the browser extension (renderer stalls after a scroll inside a modal); the page itself stayed responsive and no console error was ever logged. Several controls were therefore clicked by script (DOM click events), which exercises the same handlers as a mouse click.

## 3. Payroll lifecycle (October 2026, company GATE Alpha Co) — PASS

| # | Step | Evidence | Result |
|---|---|---|---|
| 1–2 | Select month and company | B | Month 2026-10 and per-company rows; "Generate" is per company only |
| 3 | Generate company payroll (confirmation says only that company is generated, nothing paid) | B + A | 4 employees, gross **$1,650.00** = 300 + 450 + 600 + 300 |
| 4–5 | Eligibility, Permanent calculation | A | 31/31 days, gross = net, no deductions |
| 7 | Temporary Replacement | B + A | Cover Temp One → Perm Two, 5–10 Oct, **6 days, divisor 30 (31-day month), $15.00/day, $90.00**; cost is deducted only after "Approve cost" (confirmation dialog) |
| 8–9 | Penalty, advance, carry-forward | B + A | Penalty $50 → Perm Three net $550. Advance $400 → Perm Four net **$0**, **$300 deducted, $100 carried forward**, warning shown, never below $0 |
| — | Freshness Guard | A + B | Review of the stale draft refused `409 PAYROLL_INPUTS_CHANGED`; the Generate dialog lists *what* changed (penalties/advances, the new Temporary Replacement with its details) |
| 10 | Review | B + A | `reviewed` |
| 12 (guard) | Payment before Finalize | A | `422 PAYROLL_RUN_NOT_FINALIZED` |
| 11 | Finalize (confirmation text explains reopening and payments) | B | `finalized`, `can_pay = true` |
| 13–14 | Approve Partial / Approve Paid | B + A | Over-payment ($5,000 vs $1,210) and $0 refused with clear messages; **$400.00 partial with reference "GATE-REF-001"** → partially paid, outstanding $810; **Approve Paid $810.00** with reference → paid $1,210.00, outstanding $0, status Approved |
| 20 | Repeated actions | B | Double-click on Record penalty, partial payment and Approve Paid created **exactly one** record each (totals prove it: paid 400, then 1,210) |
| 15 | Reports / totals | A | Run totals reconcile (replacement $90, other deductions $650, paid $1,360 = $150 + $1,210, outstanding $450 = $200 + $250); payroll roll-up, payment-list preview and Excel export respond |
| 16 | History / audit | A | Payments, corrections and cancellations audited |
| 17–19 | Correction and reopening | B + A | HR Manager "Change salary" of Perm One from 1 Oct (reaches the closed, paid month) demands a correction reason; recorded as an **underpayment correction of $50.00** by the HR Manager; the closed lines are **unchanged**. HR Manager reopen of the paid company refused `403 PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY`; there is no month-level reopen route (404) |
| — | Outstanding balances | A | Pagination metadata consistent (3 rows, per_page 1 → 3 pages); carried advance listed |

Rules confirmed: fixed ÷30 divisor; zero-floor with carry-forward; Finalize before payment; company-level Generate only; only a Super Admin reopens a paid company; closed payroll never rewritten.

## 4. B-Q2 (Temporary employee with a Permanent assignment) — PASS

Browser: the Assign dialog shows the approved notice; the employee stays **Temporary** (assignment type Permanent, id 17); Generate for GATE Beta shows the approved warning ("Assignment #17 belongs to a Temporary employee … no payroll line was created"); Beta's only line is the genuine Permanent employee ($300); the payment-list preview has 1 Temporary row (the cover, $90) and **no** row for the Temporary with the Permanent assignment.

## 5. Replacement lifecycle — PASS, with one observation (O-1)

Driven over the API as HR Manager on clean synthetic people (company GATE Gamma Co) — **evidence A, not browser**:
Replace (200) → closed-month back-dated cover without a reason refused `CORRECTION_REASON_REQUIRED`, with a reason recorded (201), duplicate cover refused `TEMPORARY_COVER_OVERLAPS_POSITION` → Return (200; the Replacement ended the day before) → Reopen of the finished Replacement refused **`ASSIGNMENT_COVER_FINISHED`** → a second Replacement after the cycle refused (`ABSENCE_OVERLAPS_EARLIER_CYCLE`) → holder and Replacement histories intact (`#18 permanent active`, `#19 replacement ended 2026-10-20 → 2026-10-27`).

**O-1 (observation at the time of the gate — since DECIDED and IMPLEMENTED locally, see section 13):** a Temporary cover created on days a *Replacement* already holds the position was accepted. Payroll then charges the holder nothing for those days (cost view: `not_chargeable`, deduction $0.00) while the Temporary's expected payment is $40.00, so the company bears it. At the time of the gate no approved rule covered this case (`CoverConflicts` only refused cover-vs-cover, same-worker and own-assignment overlaps). The owner approved the rule on 2026-11-06; section 13 records the implementation and its verification. (Not to be confused with the older register item "O-1 month-level Reopen", which was decided and implemented in Release 1C.)

## 6. Other rules and Employee Detail

| Area | Evidence | Result |
|---|---|---|
| UG-6: Inactive refused with an open assignment, and while covering a position | A | `422 EMPLOYEE_HAS_OPEN_ASSIGNMENTS`, nothing changed |
| End Assignment before start | A + B | `END_BEFORE_ASSIGNMENT_START`; the dialog shows "The assignment started on 2026-09-01; the last day cannot be before it" |
| PUT end earlier than start; with a correction reason; start moved after stored end | A + T | all `END_BEFORE_ASSIGNMENT_START`, no write |
| Penalty / advance bounds | B + A | future date, amount 0, > 1,000,000, reason < 3 characters, foreign currency, negative amount all refused; defaults are the current month (UG-7) |
| Workforce Request: Fulfilled → Cancel | B + A | Cancel offered, confirmation says what is kept; request `cancelled`, quantity and match kept, employee unchanged, audit "(it was fulfilled; 1 match(es) kept)" |
| E-7 feedback append | B + A | editor starts empty, saved feedback shown above it; record holds both entries separated by a blank line, follow-up history keeps each |
| E-8 team optional | B + A | member created with no team, role fixed to Marketing Employee |
| E-10 Work Date → follow-up | B + A | each PROJECT with a Work Date got one follow-up "Created automatically from the Work Date" |
| Q-STATUS | B + A | quotation of $750 → record status `quotation` |
| UG-5 commission manual entry | B + A | dialog says nothing is calculated; empty employee / negative amount refused; one record created |
| Dialogs opened (not submitted): Set workplace, Separation | B | open with the expected fields and explanatory text |

## 7. Roles, permissions and shared modules

- **Role matrix over real HTTP: 103 checks, 0 failed** (5 roles × direct URLs): `admins.manage` / `roles.manage` Super Admin only (a deliberately invalid body proves nothing is written); Audit Log GET for Super Admin and HR Manager only, and no write verb exists (404/405); HR, payroll, compliance, recovery, settings, marketing, commission, customers each limited to the intended roles; unauthenticated → 401.
- **Translations over HTTP and in the browser:** HR Manager and Marketing Manager can propose; Super Admin, Mobile App Manager and Marketing Employee cannot; HR/Marketing cannot approve or override; a **locked** key (`action.save`) cannot be proposed or overridden even by the Super Admin (`TRANSLATION_LOCKED`); the Super Admin approved an eligible proposal in the UI and the wording took effect; locked messages show a "Locked (owner-approved)" badge with no button. The QA override was reset afterwards.
- **Browser, HR Manager:** 42 sidebar pages load (no error text, no console error); admin users, roles, recovery, settings, marketing, customers show "Access restricted"; compliance, audit log, translations, payroll open.
- **Browser, Marketing Manager:** sees only Marketing and System; all 8 Marketing pages load; HR pages and the Audit Log are restricted; Translations open (propose).
- **Browser, Super Admin:** admin users, roles, recovery, settings, translations, audit log, compliance, imports, marketing and mobile pages load. Opening Compliance created **no** scan audit entry.

## 8. Automated checks (all on the exact working tree)

| Check | Result |
|---|---|
| Full backend suite, SQLite — run 1 | 3,448 tests, 3,409 passed, 39 skipped, **0 failed** |
| Full backend suite, SQLite — run 2 (consecutive) | 3,448 tests, 3,409 passed, 39 skipped, **0 failed** |
| PostgreSQL 18.6, 19 touched / related files | 205 / 205 passed |
| Previously intermittent payroll test, repeated | repaired fixture passed **60 / 60** (the original failed 1 in 25; cause proven, see the remediation notes in the Owner Decision Register 14.6) |
| Web panel tests | 816 / 816 passed |
| TypeScript, ESLint, untranslated-text scan | clean (0 items) |
| Laravel Pint, `php -l` | clean on 29 PHP files |
| Translation integrity | 161 hash `ad500a9a…cecc` unchanged; 86 pinned approvals identical; 90 others still `proposed`; 249 approved; 0 existing messages changed or removed; 34 new messages all `proposed` |

## 9. Findings

**Defects in the product: none confirmed.** No code was changed during this gate.

| Id | Kind | Detail | Disposition |
|---|---|---|---|
| O-1 | Observation → owner decision → implemented locally | Temporary cover accepted on days a Replacement holds (section 5) | Rule approved 2026-11-06 and implemented; see section 13 (uncommitted) |
| L-1 | Hardening note (low) | The Marketing create endpoints (XARUN / PROJECT) have no server-side idempotency key. Two clicks fired in the *same instant* by script created two PROJECT records; a realistic double click (40 ms apart) was blocked by the disabled button and created one. Payroll, penalties, advances and payments do carry idempotency keys | Not a release blocker; optional follow-up |
| T-1 | Test-only (resolved earlier) | Flaky fixture in `PayrollGenerationBatchedTest` (random company order); fixed without changing any assertion | Resolved, 60/60 |
| Q-1 | QA tooling | My role-matrix script once sent a *valid* body to the role-permission update and wrote a row into the disposable scratch DB; restored from the pre-gate copy, verified identical | Scratch DB only |

## 10. What this gate did NOT cover (why the verdict is HOLD)

1. Employee registration and editing forms, the recruitment pipeline pages (Damiin, contracts, uniform, training, practical, rejected), training / practical batches, attendance, departments / positions / categories, facility inspections / complaints, imports, supervisor pool, office staff: **page-load only** (no error, no console error), no data entry driven.
2. **Stop-with-away** was not driven in the browser or over the API in this gate (covered by automated tests only).
3. **Replace / Return / Reopen Assignment** were driven over the API here; the browser dialogs were last exercised in the earlier local QA of this branch (defect QA-1, fixed), not re-driven in this gate. **Change salary, Assign, Separation, End Assignment, Edit workplace**: Change salary and Assign were driven in the browser; End Assignment (validation), Edit workplace and Separation only had their dialogs opened.
4. Somali display was checked in earlier QA; this gate re-checked it only through the Translations screens.
5. Mobile App module, Notifications and the dashboard widgets: load-checked only.
6. Super Admin direct Payroll Correction settlement (repayment / payment / write-off dialogs) was covered by tests, not driven in the browser.
7. Screenshots: a handful were captured during the session; they are not saved as files in the repository.

## 11. Outstanding owner decisions

Q-STATUS conservative reading (Done / Cancelled records are not moved back to Quotation); C-1 follow-up (should a fully voided correction stop blocking a reopening — currently it still blocks, which the Owner confirmed is correct unless an approved rule says otherwise); E-10 not back-filling existing PROJECT records; production override rows on locked translations (cannot be inspected here, they would be ignored).

## 13. O-1 — a Temporary cover may not share a day with a Replacement of the same position (implemented locally 2026-11-07, UNCOMMITTED)

**Owner decision (2026-11-06):** reject a new Temporary Replacement if another Replacement already holds the same position on any overlapping day. Mandatory, enforced by the server (not a warning, not frontend-only).

**Rule.** Inclusive calendar days: refused when `new_start <= existing_end AND new_end >= existing_start`; an open end means "still holding". Same position only (`replaces_assignment_id` = the covered position). A *Replacement* here is a Replacement assignment (type `replacement`, status not `cancelled`); a Permanent successor, a cancelled Replacement and a Replacement of another position never block. Adjacent days (new start = the day after the Replacement's last day, or new end = the day before its first day) are accepted.

**Backend enforcement (one place, `App\Support\Hr\CoverConflicts`).**
- `find()` now also looks for a holding Replacement (`replacementHolding`), after the existing own-assignment and cover-versus-cover checks. Code `TEMPORARY_COVER_OVERLAPS_REPLACEMENT`, HTTP 422, with the conflicting Replacement in `errors.conflicts[]` (`kind: replacement`, id, dates, status, company). Every caller of `find`/`assertFree` inherits it: create, edit dates/person, extend the last day (End Coverage with extension), and the conflict-check / change-preview endpoints (so the form warns before saving). The expected-payment code path that reads `find()` for its `OVERLAPPING_COVER` warning shares the same function (not separately tested for the new case).
- Reopen of an ended Replacement (the reverse direction): `ReopenEndedAssignmentAction` locks the position row and calls `CoverConflicts::assertReplacementFreeOfCovers` before anything is written; refusal code `REPLACEMENT_OVERLAPS_TEMPORARY_COVER`. The existing `ASSIGNMENT_COVER_FINISHED` guard still runs first and is unchanged.
- A refusal throws before any write: no cover, assignment, employee, audit or payroll row changes (asserted by comparing full table snapshots).
- **No migration, no trigger.**

**Concurrency.** Create and edit already lock the position row (`lockForUpdate`) inside a DB transaction before judging the overlap; reopen now locks the same row. On PostgreSQL a second request for the same position therefore waits and then sees the first one's result. A test proves the lock statement runs before the overlap query (PG only). SQLite ignores row locks (single writer), so there the check is sequential only. Limitation: there is no database-level constraint, so a writer that bypasses these actions (raw SQL, a future code path that does not call `CoverConflicts`) is not stopped; I did not run a real two-process race, only the lock-order proof plus sequential tests.

**Not changed (by design).** Replace Employee creating a Replacement while a cover already overlaps was *not* part of the approved wording and is not blocked (open question below). Generate/Review/Finalize still only warn. Existing records were not touched.

**Tests** — new file `backend/tests/Feature/Api/V1/Admin/Hr/TemporaryCoverOverlapsReplacementTest.php` (12 tests): A overlap 5–15 vs 1–10 refused; B identical refused; C shared boundary day refused on both ends (cover 25 Oct–1 Nov and cover 10–20 Nov against a 1–10 Nov Replacement); plus inside / surrounding / open-ended cases; D adjacent days accepted (both sides); E other position accepted and the old cover-vs-cover rule intact; open-ended Replacement blocks later days but not days before it started; cancelled Replacement, Permanent successor and other-position Replacement do not block; F edit own dates (no self-conflict) accepted, edit into the Replacement refused with nothing changed, extending the last day into it refused, ending earlier / up to the day before accepted; preview endpoint reports it; G reopen refused with `REPLACEMENT_OVERLAPS_TEMPORARY_COVER` and nothing written, reopen with no cover in the way still works, finished-cycle Replacement still refused `ASSIGNMENT_COVER_FINISHED` after the real Replace → Return lifecycle; H every refusal compares full snapshots; I lock-before-judge (PostgreSQL) and sequential second attempt refused.

| Check | Result |
|---|---|
| New O-1 file, SQLite | 12 tests: 11 passed, 1 skipped (PostgreSQL-only lock-order test) |
| New O-1 file + `TemporaryCoverConflictTest`, `TemporaryReplacementCorrectionTest`, `ReplacementCycleTest`, `TemporaryReplacementTest`, `TemporaryExpectedPaymentTest` on a brand-new disposable PostgreSQL 18.6 database | 117 / 117 passed, 0 skipped |
| Full backend suite, SQLite | 3,460 tests (3,448 + 12), 3,420 passed, 40 skipped, **0 failed** |
| Web panel tests | 817 / 817 (816 + 1 new `coverConflict` test; the Somali register test passes after regenerating the register) |
| TypeScript, ESLint, untranslated-text scan | clean (0 items) |
| Laravel Pint `--test`, `php -l` | clean on all 32 touched / new PHP files |
| Translation integrity | 161 hash `ad500a9a…cecc` unchanged; 86 R1C approvals identical; 249 approved; 90 others still `proposed`; 0 existing messages changed or removed; 37 new messages, all `proposed` (3 added for O-1: `warning.ownerDecisions1c.coverOverlapsReplacementHeading`, `…coverOverlapsReplacement`, `…conflictRecordReplacement`) |

**Browser evidence (isolated scratch data, nothing saved).** New Temporary Replacement dialog, Permanent employee "QA Holder One", position held by Replacement #10 (6–7 Nov): with start 7 Nov – end 9 Nov the live preview shows "This position is already held by a Replacement on some of these days — … Replacement #10 at QA Clinic (synthetic), from 2026-11-06 until 2026-11-07 …" and the **Create button is disabled**; the scratch database still holds its original 5 covers. Changing the start to 8 Nov (the day after the Replacement ended) clears the notice and enables Create. Server refusal on submit, edit, end-extension and reopen is proven by the API tests above (the button cannot be pressed while the conflict shows).

**Existing conflicting records (reported, not modified).** In the scratch database cover #4 (position #18, 22–25 Oct) overlaps Replacement #19 (20–27 Oct). Production data was not read. Consequence of the rule: because the overlap check runs on every edit of a cover, a cover that already conflicts cannot be saved again (even a notes-only edit) until its dates are corrected; ending it earlier is still allowed.

### 13.1 Symmetrical extension (owner-approved 2026-11-07, implemented locally, UNCOMMITTED)

**Owner decision:** a Replacement and a Temporary cover must not hold the same position on overlapping calendar days, whichever was recorded first. Both directions are blocked; the cover-first direction above is unchanged.

**Reverse direction — where it is enforced.** One function, `CoverConflicts::findCoverInTheWayOfReplacement` (read-only, inclusive dates, open end = no end, every cover counts — active or ended — for the days it really covers, cut to the position's own dates). Refusal: HTTP 422, code **`REPLACEMENT_OVERLAPS_TEMPORARY_COVER`**, `errors.conflicts[0]` = the cover (id, dates, company), message names the cover; nothing is written. Entry points:

| Entry point | Where | When it is judged |
|---|---|---|
| Replace Employee (create a Replacement; the cover-swap path goes through the same two checks but has no test of its own) | `ReplaceCompanyEmployeeAction` (read-only pre-check, before anything is touched) and `CreateWorkAssignmentAction` (authoritative, inside the transaction after the position row is locked) | always for assignment type Replacement: the new open-ended period from the replacement date |
| Edit a Replacement's dates / extend its last day | `UpdateWorkAssignmentAction` (pre-check, then repeated under lock inside a transaction) | only when the start or end date really changes; judged over the whole new period; the edited record never conflicts with itself |
| Reopen an ended Replacement | `ReopenEndedAssignmentAction` (under lock) | the days it takes back (after its old last day) |
| Reopen a company (restores closed assignments) | `ReopenCompanyAction::conflict()` (the preview shows the row as not restorable, the apply re-checks under lock) | the days it gets back (after the closure's end day; a cancelled one gets all its days back) |
| Recovery Center, "correct assignment dates" on a Replacement row | `AssignmentRecovery::planDates` (blocker `COVER_OVERLAPS_REPLACEMENT`) | when its dates change |

Not changed: the Recovery Center "reopen" / "restore cancelled" workflows already refuse every Replacement row; the CLI `MonthBaselineRestorer` (an operator disaster-restore tool) restores a recorded baseline and is deliberately not subject to the rule. `ASSIGNMENT_COVER_FINISHED` still takes precedence on reopen (it runs first).

**Locking and deadlock analysis.** Writers now take row locks in one global order: **the Replacement's own row → the position (holder) row → the Temporary worker's employee row**. Cover create/edit: position → worker (as before). Replace Employee: holder row (cover) or the Replacement row being swapped → holder row. Date edit, reopen and company reopen: Replacement row → holder row. No path takes the holder row and then a Replacement row, so there is no lock cycle. Lock-wait ordering is proven by PostgreSQL-only tests (lock statement precedes the cover/Replacement judgement) and by the real race below. No trigger and no migration were needed.

**Consequence worth knowing.** Replace Employee always starts an open-ended Replacement, so it is refused while a Temporary cover of the same position exists for ANY day on or after the replacement date — including a cover booked in the future. End or correct that cover first (or choose a later replacement date after the cover ends).

**Metadata-only edits (the earlier "notes-only" limitation) — fixed.** `UpdateTemporaryReplacementAction` re-ran the overlap check on every edit, so a cover that already overlapped a Replacement (a legacy record) could not even have its note corrected. The check now runs only when the dates or the Temporary person change; an edit of reason/notes alone is allowed, still requires the version and the change reason, and is audited as before. Any date change is judged in full, so it can never keep or widen an overlap (it can repair one by moving clear). Same for a Replacement: an edit that changes no date (notes, shift) is not judged.

**Legacy conflicts (reported, not modified).** Scratch database: cover #4 (position #18, 22–25 Oct) ↔ Replacement #19 (20–27 Oct) overlaps from before the rule. Verified after all QA: both rows exactly as before (dates, status). A date edit of cover #4 that keeps the overlap is refused (`TEMPORARY_COVER_OVERLAPS_REPLACEMENT`, nothing changed); its note could be corrected (automated test; not done on the scratch row to keep it untouched). Production data was not read, so whether production has any such pair is unknown; the existing expected-payment view already reports old overlaps.

**Tests (new file `ReplacementOverlapsTemporaryCoverTest.php`, 12 tests, + 2 earlier files).** Replace Employee refused for a partial overlap, for the cover's first and last day (exact boundaries), and for an open-ended Replacement that would run into a cover; nothing written (absences, cycle events, assignments, employees, audit, payroll compared as snapshots); adjacent day accepted; an open-ended cover blocks every later date; an ended cover still blocks the days it covered but not the days after; another position is never blocked; editing a Replacement into a cover (partial / boundary / identical days / surrounding) refused with no writes, the adjacent day and shortening accepted, no self-conflict; an edit that changes no date is not judged even next to an old overlap, and the next real date change is; reopen refused and clean reopen still works; `ASSIGNMENT_COVER_FINISHED` keeps precedence; company reopen shows the Replacement as not restorable with the cover named; metadata-only cover edit allowed next to a legacy overlap, date edits refused, repair accepted; PostgreSQL lock-before-judge on reopen; sequential second attempt refused.

**Real two-process race on PostgreSQL (disposable database `fayadhowr_pgrace_*`, PG 18.6).** 24 rounds; in each, one process creates a Temporary cover (5–15 Dec) and another runs Replace Employee (10 Dec) on the same position at the same moment (cover start staggered 0–132 ms to cover both orders). Result: **24 / 24 rounds exactly one winner and one `…OVERLAPS…` refusal** (19 Replacements won, 5 covers won), 0 errors, 0 deadlocks, **0 overlapping pairs** in the committed data. Harness: scratchpad `race_run.ps1`. Not raced: reopen / date edit against a cover (covered by the lock-order tests and the same locking order).

| Check | Result |
|---|---|
| Both new O-1 files, SQLite | 12 + 12 tests: 22 passed, 2 skipped (PostgreSQL-only lock-order tests) |
| Existing Replacement / cover / recovery / payroll-correction suites (15 files + the cover-first O-1 file), run before the new reverse-direction file was written | 265 tests: 264 passed, 1 skipped |
| PostgreSQL 18.6, both O-1 files + 15 related files | **277 / 277 passed, 0 skipped** (includes both lock-order tests) |
| Full backend suite, SQLite | 3,472 tests (3,460 + 12), 3,431 passed, 41 skipped (the 39 earlier ones + the PostgreSQL-only lock-order test of each O-1 file), **0 failed** |
| Web panel tests | 818 / 818 (adds the Replacement-side conflict message test; Recovery Center blocker title) |
| TypeScript, ESLint, untranslated-text scan | clean (0 items) |
| Laravel Pint `--test`, `php -l` | clean on all 37 touched / new PHP files |
| Translation integrity | 161 hash `ad500a9a…cecc` unchanged; 86 R1C approvals identical; 249 approved; 90 others still `proposed`; 0 existing messages changed or removed; 39 new messages, all `proposed` (this step added `warning.ownerDecisions1c.replacementOverlapsCover` and `warning.recovery.blocker.COVER_OVERLAPS_REPLACEMENT`) |

**Browser evidence (isolated scratch data; screenshots saved: `…\claude-chrome-screenshots-oeNXQi\screenshot-1794001992761-1.jpg` (English) and `…\screenshot-1794002028958-2.jpg` (Somali)).** Company GATE Beta Co → "Replace GATE Perm Five" (position #16 has Temporary cover #2, 22–25 Oct): Replacement date 24 Oct → the dialog stays open on the form with a red banner naming the cover — English: "This Replacement cannot be saved: the position already has a Temporary cover over some of these days: cover #2 at GATE Beta Co, from 2026-10-22 until 2026-10-25. … Nothing was changed."; after switching the panel to Soomaali and submitting again: "Beddelaaddan lama keydin karo: jagada waxaa hore u haysta beddelaad ku-meel-gaar ah maalmahan qaar ah: beddelaadda #2 ee GATE Beta Co, laga bilaabo 2026-10-22 ilaa 2026-10-25. …". Date 26 Oct passed the overlap rule and was stopped by a different, unrelated rule (the candidate already has an assignment at another company on those days). Date 28 Oct (after the cover) **saved** ("Shaqaalaha waa la beddelay"), creating one scratch Replacement (#20) — the only record the QA saved. Direct API (HTTP): Replace on position #12 on 3 Oct (cover #5, 2–3 Oct) → 422 `REPLACEMENT_OVERLAPS_TEMPORARY_COVER` naming cover #5; a new cover on position #16 for 1–5 Nov (Replacement #20 open from 28 Oct) → 422 `TEMPORARY_COVER_OVERLAPS_REPLACEMENT`; a date edit of legacy cover #4 → 422, rows unchanged. Cover-first form (from 13): Create disabled with the conflict notice for 7–9 Nov, enabled for 8 Nov. Known display detail: a refusal banner that is already on screen stays in the language it was produced in until the form is submitted again (the same for every message of these dialogs); it is not retranslated on a language switch.

**Remaining limitations.**
1. No database constraint: a writer that bypasses these actions (raw SQL, a future code path, the baseline-restore CLI) is not stopped; the race was run for create-vs-create only.
2. SQLite ignores row locks, so on SQLite the guarantee is sequential only (production is PostgreSQL).
3. Legacy overlaps stay until HR corrects one of the two records; a legacy cover that overlaps a Replacement cannot have its dates saved unchanged-overlapping, and editing the *holder's* own dates (or shift) re-judges its covers against Replacements, so an old overlap on that position can block such an edit until corrected (`assertCoversStillFree`, unchanged behaviour of the cover-first half).
4. The refusal wording shown after a failed save is not retranslated live on a language switch (see above).
5. New wording is proposed, not owner-approved.

**Status:** O-1 (both directions) implemented and verified locally. Nothing staged, committed, pushed, merged or deployed; production untouched. The release stays on HOLD until the complete pre-production gate passes.

## 14. Batch completion of the remaining pre-production QA (2026-11-07) — LIVE CHECKLIST and consolidated result

Scope: everything local (scratch API `127.0.0.1:8012` on SQLite, panel `127.0.0.1:5191`, disposable PostgreSQL 18.6 on `127.0.0.1:5433`). Production was neither read nor written; nothing was staged, committed, pushed, merged or deployed. Evidence key: **B** browser, **A** HTTP against the local API, **T** automated test, **DB** read-only query of the scratch database. Three agents drove disjoint areas in parallel (HR operations / recruitment, Marketing + roles, payroll + correction + Replacement), each on its own records (`QB`, `QC`, `QD` prefixes); the lead drove Employee Detail, O-1 and the role-navigation matrix and re-read every claim that matters below.

### 14.1 Live checklist

Legend: ✅ completed and passed · ⚠ completed and passed, with a finding · ❌ failed · ⛔ blocked · ◻ not tested (reason given). Totals of the 30 rows after the 2026-11-07 completion (section 17.2): ✅ 23, ⚠ 5, ❌ 0, ⛔ 0, ◻ 2 (row 27: no such function; row 30: production, by instruction). Rows 26, 28 and 29 were ◻ at the first batch report.

| # | Workflow | Evidence | Status |
|---|---|---|---|
| **HR – people** | | | |
| 1 | Employee registration (create, edit, validation, duplicate phone, role denial) | B+A | ✅ |
| 2 | Recruitment pipeline end to end (guarantor → contract → uniform → training → practical → Waiting → Active → assigned; failed path Rejected / Practical Repeat; stage gates; Super Admin override) | B+A | ⚠ F1, F2, F3 (pre-existing) |
| 3 | Training batches and practical batches (create, participants, complete, assessments) | B+A | ⚠ F2 |
| 4 | Attendance and leaves | B+A | ⚠ F7, F8 (owner questions) |
| 5 | Departments, positions, categories, work locations (CRUD, in-use blocks, system category) | B+A | ✅ (F9 cosmetic) |
| 6 | Facility inspections, schedule, complaints | B+A | ⚠ F4, F5 (owner questions) |
| 7 | Employee imports (preview, confirm, process, cancel, bad files) | B+A | ✅ |
| 8 | Office staff, supervisor pool | B+A | ✅ |
| 9 | Mobile App pages (Customers built; 12 other pages "Not built yet") | B+A | ✅ (scratch Mobile App Manager grant is view-only, see 14.6) |
| **Employee Detail** | | | |
| 10 | Assign, Change salary, Set/Edit workplace | B+DB | ✅ |
| 11 | End Assignment (validation, ending), Separation ("Mark Inactive") | B+DB | ✅ |
| 12 | Stop, Stop-with-away, Reopen | B+DB | ✅ (one display defect found and fixed: Stop summary, 14.3) |
| 13 | Replace, Return, Reopen of a finished-cycle Replacement (refused) | B+DB | ✅ |
| **Replacement / O-1** | | | |
| 14 | O-1 cover-first (Temporary cover refused next to a Replacement) | T+PG+B+A | ✅ |
| 15 | O-1 Replacement-first (Replace Employee, date edit, extension, reopen, company reopen, Recovery Center correction refused next to a cover) | T+PG+B+A | ✅ |
| 16 | EN + SO notices | B+T | ✅ |
| 17 | Legacy conflict cover #4 ↔ Replacement #19 not silently modified | DB | ✅ (re-read 2026-11-07: both rows' `updated_at` still 2026-11-06 20:17) |
| 18 | Real two-process race on PG | T | ✅ (result in 14.2) |
| **Payroll** | | | |
| 19 | Generate → Review → Finalize → payment approval; partial then full payment; HR Manager reopen refused | B+A | ✅ |
| 20 | Super Admin correction settlement (underpayment payment, debt repayment, write-off), HR Manager limits | B+A | ✅ |
| 21 | Temporary daily pay = Permanent monthly salary ÷ 30 × eligible days (6 days × 320/30 = $64.00 deducted) | B+DB | ✅ |
| **Marketing** | | | |
| 22 | Records XARUN / PROJECT, optional team, feedback append-only, Work Date follow-up, Quotation status, manual commission | A+B | ⚠ D1–D6 (low/cosmetic, outside the E-series items) |
| 23 | Fulfilled Workforce Request cancel (B-Q4) | A | ✅ |
| **Roles and permissions** | | | |
| 24 | Server enforcement: 115 HR write routes + 32 Marketing endpoints × roles + HR Manager vs Super-Admin-only actions | A | ✅ |
| 25 | Browser role navigation: Super Admin, HR Manager, Marketing Manager, Marketing Employee, Mobile App Manager (sidebar + direct URLs) | B | ✅ (14.7) |
| **Not exercised** | | | |
| 26 | HR Manager *performing writes in the browser* | B+A | ✅ (2026-11-07, section 17.2) |
| 27 | Practical-batch "complete" | – | ◻ no such function exists in the panel or API (feature gap N3) |
| 28 | Browser: facility inspection edit/void, complaint create/status change, "Need Training" list, in-use work-location delete | B+A | ✅ (2026-11-07, section 17.2) |
| 29 | Marketing Manager / Mobile App Manager / Marketing Employee writes in the browser | B+A | ✅ (2026-11-07, section 17.2) |
| 30 | Production | – | ◻ by instruction (untouched) |

### 14.2 Exact automated results on the final working tree

| Check | Result |
|---|---|
| Full backend suite, SQLite (`php vendor/bin/phpunit`) | **3,473 tests, 3,432 passed, 41 skipped, 0 failed, 0 errors** (1,614 s). 3,472 → 3,473: the Recovery Center cover-overlap blocker test. |
| PostgreSQL 18.6, every Hr / Payroll / Replacement / Cover / Temporary / Succession test file (159 files, fresh disposable database) | **2,026 tests: 2,018 passed, 1 skipped, 7 failed.** All 7 failures are one data-provider test, `PayrollReversalDatesTest::test_a_line_paid_without_a_date_is_dated_with_the_mogadishu_day_and_paid_at_stays_a_utc_instant` (`paid_at` read back 3 h earlier on this Windows host, UTC+03:00). **Not caused by this candidate:** the same 7 fail identically on a pristine checkout of production `010005a` (temporary worktree, removed afterwards), no payroll-pay code is modified, and the same test passes on SQLite in the full suite. Environment-specific (host / PG time zone); recorded here, not fixed (a test-environment issue, not a behaviour change). |
| PostgreSQL 18.6, both O-1 files + 15 related files (section 13.1) | 277 / 277 passed |
| Concurrency: real two-process race on PostgreSQL 18.6, 24 rounds (new database `fayadhowr_pgrace_final…`) | **24 / 24 rounds exactly one winner and one `…OVERLAPS…` refusal** (21 Replacements won, 3 covers won), 0 errors, 0 deadlocks, **0 overlapping pairs** in the committed data |
| Web panel tests (`npm test`) | **819 / 819** passed (818 + the Stop-summary test) |
| TypeScript (`tsc -b --noEmit`) | clean |
| ESLint (`--max-warnings 0`) | clean |
| i18n scan (untranslated text) | 0 items (339 allowlisted, 269 files scanned) |
| Somali register freshness | regenerated; test passes |
| Laravel Pint `--test`, `php -l` | clean on all 38 touched / new PHP files |
| Translation integrity | see 14.8 (161 locked identical, 86 identical, 249 approved, 0 changed, 39 new all proposed) |
| Browser workflows | 25 of 30 checklist rows driven (rows 1–25); 5 not exercised (rows 26–30) |
| Browser role matrix | 5 roles × sidebar + 9–18 direct URLs each (14.7) |

### 14.3 Findings from the batch (none changes an approved rule)

**Found and fixed in this batch (in scope, regression test added)**
- **Stop dialog summary** picked the replacement line inline and could show the wrong / a raw-key line when a replacement type was chosen. It is now chosen by `stopSummaryReplacementKey()` (`web-panel/src/utils/successor.ts`) and used by `AssignmentDialogs.tsx`; unit test added in `utils/successor.test.ts`. No new message text.
- **Recovery Center blocker `COVER_OVERLAPS_REPLACEMENT`** (the new O-1 blocker) needed a title in `recovery.ts` (proposed) and a test (`RecoveryCenterTest`, 31/31).

**Open — nothing was changed. F1–F11 are pre-existing behaviour in code this candidate does not touch.**

| ID | Area | Finding | Severity (QA view) |
|---|---|---|---|
| F-A | Replacement | `PUT end_date` on a *finished-cycle* Replacement can extend past the holder's return date, so two people are counted for the same days. API-only; **payroll effect not verified**. | owner decision; potentially money-affecting |
| F1 | Pipeline | Contract can be signed (and uniform received) before the guarantor is verified; the stage then waits until the contract is signed again. | medium |
| F2 | Training batch | Adding the same person to a training batch twice returns **HTTP 500** (UNIQUE violation, no pre-check in `AddTrainingBatchParticipantAction`) instead of 422. | medium |
| F3 | Pipeline | Super Admin override to Waiting leaves the old `pipeline_stage`, so the person still appears in that queue page. | low/medium |
| F4 | Facility | Standalone complaints do not enforce the rules the inspection form enforces (staff complaint without employee; resolve without response/corrective action; satisfaction on an open complaint). | owner question |
| F5 | Facility | A second inspection for the same company and day is allowed. | owner question |
| F6 | Pipeline UI | Uniform "Mark Purchased/Received" send only the status, so the dates stay empty. | low |
| F7 | Attendance | Future dates and dates before the assignment are accepted. | owner question |
| F8 | Leave | Only unpaid leave is overlap-guarded; annual and sick may overlap. | low / owner question |
| F9 | API | Missing-record 404s return Laravel's raw model message without `error_code`. | low |
| F10 | i18n | Business-rule server messages with no catalogue entry stay English in Somali mode (known, documented limitation). | owner question |
| F11 | i18n | Two Somali page titles (Training / Practical Batches) start in lower case; raw field paths such as `items.1.issue_description` appear in API messages. | cosmetic |
| D1 | Marketing | Cancelling an already-cancelled Workforce Request returns 200 and writes another audit row. | low |
| D2 | Follow-ups | A completed follow-up can be rescheduled (reopens it) or completed again (duplicate history row); past dates are accepted. | medium / owner question |
| D3 | Marketing team | `PATCH marketing/employees/{id}` silently ignores `team_id`. | low |
| D4 | Marketing team | Removing a non-member from a team returns 200. | low |
| D5 | Marketing | A Marketing Employee gets 422 instead of 403 on team-admin POST with an empty body (validation before scope guard; no data exposed). | low |
| D6 | i18n | Marketing breadcrumb leaf "Dashboard" stays English in Somali. | cosmetic |

**Owner questions collected in this batch**
1. Contract / uniform out of order (F1), attendance date limits (F7), overlapping annual/sick leave (F8), standalone-complaint rules and same-day inspections (F4, F5): enforce or leave as is?
2. F-A: should a finished-cycle Replacement's end date be capped at the holder's return date? (Money-affecting; QA could not confirm the payroll effect.)
3. Marketing: which record-status transitions must be refused (today all are allowed); is a commission of 0 valid; should a quotation without an amount move a Pending record to Quotation; may a completed follow-up be rescheduled / completed again and may past dates be used (D2)?
4. Should business-rule server messages get Somali (F10)?
5. Does production's Mobile App Manager need to create/edit customers? (The scratch grant — view only — is a seeding artefact; production grants were not checked.)
6. Should HR have a sidebar queue for new registrations waiting for a guarantor ("Damiin Needed" lists people past that stage by design)?

### 14.4 Payroll regression (QD Co, October 2026) — PASS

Generate → Review → Finalize → approve; gross $2,380, cover deduction $64.00 (6 days × 320 ÷ 30), net $2,316; partial $1,000 then $1,316 → Paid; HR Manager reopen of the paid section refused with `PAID_COMPANY_REOPEN_SUPER_ADMIN_ONLY`; Super Admin correction settlement (underpayment payment, debt repayment, write-off) completed in the browser; HR Manager refused for payment / void / write-off with `PAYROLL_CORRECTION_NOT_ALLOWED` and allowed to record a repayment. The finalized lead section was re-read after every refusal and stayed $1,210.00. A Replacement extension into a cover's days is refused (`REPLACEMENT_OVERLAPS_TEMPORARY_COVER`) for Super Admin and HR Manager with nothing written. The ÷30 divisor, B-Q2, zero-floor carry-forward and the freshness guard were not touched by any change in this batch.

### 14.5 O-1 two-direction results — PASS

Cover → refused when a Replacement holds the days (`TEMPORARY_COVER_OVERLAPS_REPLACEMENT`); Replacement → refused when a cover holds the days (`REPLACEMENT_OVERLAPS_TEMPORARY_COVER`) through Replace Employee, date edit, extension, reopen, company reopen and Recovery Center correction; adjacent days and other positions allowed; metadata-only edits allowed; inclusive dates; no writes on refusal; Somali and English notices verified in the browser; lock order Replacement → holder → worker; legacy pair #4 / #19 untouched. Details and numbers in sections 13 and 13.1.

### 14.6 Role and permission results (server) — PASS

All 115 HR write routes probed with an empty body: Marketing Manager, Mobile App Manager and Marketing Employee got 403 on 114 and unauthenticated 401 on 114; the remaining route (`payroll/{period}/companies/{company}/generate`) needs a real company id and was covered by the payroll workflow. Marketing: 6 callers × 32 endpoints, 10 write endpoints with valid bodies and 20 extra scope checks as expected; a Marketing Employee is limited to their own / team records and cannot administer teams, commission rates, reports or other employees (`MARKETING_SCOPE_FORBIDDEN`, `FORBIDDEN`). HR Manager is refused for admins, roles, settings, backups / restore, translation overrides and approval, Recovery Center, paid-section reopen, debt write-off and underpayment payment. The scratch Mobile App Manager holds `customers.view` only, so customer create / edit is 403 for that account (grant artefact, not a defect; production grants unchecked).

### 14.7 Browser role navigation — PASS

Scratch accounts, token swapped in `localStorage`, sidebar links read and 9–18 direct URLs per role loaded; Super Admin restored and verified afterwards (57 links incl. Admins; language English).

| Role | Sidebar | Direct URLs |
|---|---|---|
| Super Admin | everything | all load |
| HR Manager | Dashboard, all HR pages and HR reports, Audit log, Translations, Data issues | HR pages, Audit log, Translations (**propose only**; approval is Super Admin) and Data issues open; Recovery Center, Admins, Roles, Settings, Marketing, Mobile App → "Access restricted" |
| Marketing Manager | Dashboard, 8 Marketing pages, Translations | Marketing pages and Translations open; every HR, system (other than Translations) and Mobile App URL restricted |
| Marketing Employee | 7 Marketing pages (no Reports) | Marketing pages open; Marketing Reports, HR, Mobile App, Admins, Translations restricted |
| Mobile App Manager | Dashboard, Customers | Customers open; Marketing, HR, Payroll, Admins, Recovery, Audit log, Translations restricted |

### 14.8 Translation integrity — PASS

161 locked messages identical to baseline (hash `ad500a9a…cecc`); 86 R1C approvals identical to the text committed in `888d978`; 249 approved in total (161 + 2 named additions + 86); 0 existing messages changed or removed; 39 new messages in this candidate, **all `proposed`, none approved**; the 90 reviewed-but-unapproved proposals are still `proposed`; catalogue 4,036 messages (3,787 proposed). The Somali register was regenerated (the Stop-summary change moved one message's "screens" list) and its freshness test passes.

### 14.9 Production safety and scratch-data notes

Production was not contacted (no Railway call, no production URL, no production data); no seeders, migrations or configuration changes outside the disposable scratch / PG databases; no staging, commit, push, merge or deploy; worktrees A–E and the preserved production backup untouched; `generation_inputs` untouched. One agent reported overwriting scratch `work_locations` id 2 (a QD record: renamed to "QB Site A2" and back to "QD Site", capacity set to null); the seed created that row without a capacity, so null is its original value — checked against the audit trail (create, rename, rename back). A probe `PUT customers/1` with an empty body as Super Admin returned 200 and only touched `updated_at` of that scratch customer (every other empty-body probe was refused with 422 and changed nothing).

### 14.10 Verdict of the batch — HOLD

**HOLD.** Every approved rule held and the candidate's own changes are well evidenced: no test fails because of them, no approved message changed, payroll regression, O-1 in both directions, Employee Detail and role matrices all passed, and no production access occurred. The batch nevertheless cannot honestly be called PASS, for one reason and two lesser ones:

1. **F-A is an undecided, potentially money-affecting rule.** Payroll pays a Replacement for its own assignment dates and excludes the holder's away days only; a finished-cycle Replacement whose end date is edited past the holder's return would therefore be paid for the same days as the returned holder. QA did not run that scenario through payroll (so the effect is *probable*, not proven), and capping that date would be a new business rule that only the owner can approve. It is pre-existing behaviour (the edit path is not part of this candidate), reachable through the API, and it stands outside the O-1 rule (which protects against cover/Replacement overlap, not against holder/Replacement overlap after a return).
2. **F2 (HTTP 500 on adding a person to a training batch twice)** and the other pre-existing recruitment findings (F1, F3) are real defects in code this candidate does not touch; they were recorded, not fixed (out of scope).
3. **Five checklist rows were not exercised in the browser** (26–29, plus production by instruction); each is covered by server-side (HTTP) evidence, which proves enforcement but not the screen.

What closes the HOLD, all local and cheap: the owner answers F-A (cap the end date at the return date, or allow it), after which the fix and a regression test are small; the owner chooses whether F2 is fixed before or after this release (a one-line validation, no rule change); optionally a short browser pass of rows 26–29. The seven PostgreSQL failures of `PayrollReversalDatesTest` are a host time-zone artefact that also occurs on production `010005a`; they do not block.

### 14.11 Git state at the end of the batch

Branch `feat/hr-release1c-20261105`, `HEAD` = `010005a` (the live production `main`); the working tree is the uncommitted candidate: 54 modified and 18 untracked paths (72 in total, including this document). Nothing is staged (0 files in the index); no commit, push, merge or deploy was made. Worktrees A–E, the preserved production backup and `generation_inputs` were not touched. The only worktree created by this batch (a temporary checkout of `010005a` used to prove the PostgreSQL failure pre-exists) was removed after use.

## 15. F-A implemented (owner-approved 2026-11-07) and the payroll duplicate-payment verification

### 15.1 The rule

**A Replacement must end no later than the day before the original holder's return date.** Holder returns 10 November → latest last day 9 November; 10 November or later is refused. Not silent: the dates are never shortened or changed for the user. Server-side validation for every role; the browser only explains.

- Error: HTTP 422, code `REPLACEMENT_RUNS_PAST_HOLDER_RETURN`, with `errors.holder_return_date`, `errors.latest_end_date`, `errors.requested_end_date` and the message *"A Replacement must end no later than the day before the original employee returns. {Holder} returned on {date}, so this Replacement can last until {date} at the latest, not {requested}. Nothing was changed."* A refusal writes nothing.
- Wording shown in the panel (`replacementReturnMessage`, `warning.ownerDecisions1c.replacementPastReturn`) in English and Somali — **new messages are `proposed`, not approved**.
- Single definition: `AbsenceCycles::returnLimitOf()` / `replacementReturnConflict()` / `assertReplacementEndsBeforeReturn()`. "Holder's return date" = `returned_on` of the cycle the Replacement was recorded in. A Replacement with no cycle record (older data), a cycle that is still open, or an original ended while away (the cover keeps the position) has no return and is not limited.

| Entry point | How F-A is enforced | Evidence |
|---|---|---|
| Replacement date edit / extension (`PUT work-assignments/{id}`) | judged over the whole new period, before any write and again under the row locks (own row → holder row) | T: boundary 18/19 Oct allowed, 20 Oct, 21 Oct, +1 month, +1 year refused, nothing written; A: live scratch API, Super Admin and HR Manager both refused (return 10 Nov: 10 Nov and 30 Nov refused) |
| Return Original Employee | ends the Replacement on exactly `return − 1`; lock order changed to Replacement row → original row → absence so Return cannot deadlock with a date edit | T + PG lock-order test; B: Return dialog states "assignment ends Nov 7, returns Nov 8", confirmed, row shows Nov 6–Nov 7 |
| Replace Employee | always starts an open-ended Replacement in a new open cycle (no return yet); a swap ends the old one the day before | T (existing cycle suites) |
| Recovery Center — date correction | blocker `REPLACEMENT_RUNS_PAST_HOLDER_RETURN` (preview, nothing applied) | T; B: preview shows "A Replacement would last until or after the day its holder returned … can last until 2026-11-09 at the latest, not on 2026-11-12" |
| Recovery Center — correct a cycle (return date moved earlier) | blocker when another Replacement of that absence would then last past the new return | T |
| Company reopen (restore) | a Replacement is not restorable when its restored last day is on/after the holder's return; the whole reopening is refused | T |
| Reopen an ended Replacement | already refused for a finished cycle (`ASSIGNMENT_COVER_FINISHED`, which keeps precedence) | T (existing) |
| Browser | no screen lets a user type a Replacement's end date except Return (always compliant) and Recovery Center (previews the refusal); any dialog that receives the code shows the translated message through `friendlyErrorMessage` | web unit tests (EN + SO), B |

Existing historical records are never rewritten: a Replacement that already runs past the return keeps its dates; a notes-only edit is allowed; any real change of dates is judged in full, so it can repair the overrun but never keep or widen it (test `…already_ran_past_the_return_is_left_alone_and_can_only_be_repaired`). Compliance report: the existing finding `REPLACEMENT_NOT_IN_ABSENCE` already lists such records.

### 15.2 Payroll duplicate-payment risk assessment (the owner's specific request)

1. **What F-A prevented — and a correction to the earlier QA note.** The earlier note said a Replacement running past the return would make payroll count two people for the same days. Measured, this is not so: payroll removes both the absence windows *and* every Replacement's own range from the holder's days (a union), so a day is paid once. The harm is **misallocation**: with a Replacement extended past the return, the returned holder lost the days they were back at work and the Replacement kept them (test: holder 11 eligible days instead of 23; legacy Replacement 20 days; total still $310 = one month). No duplicate, but the wrong person is paid. F-A closes it.
2. **A real duplicate-payment path was found by the verification and closed (owner rule A7).** Two Replacements of one position (a swapped-out Replacement and its successor) could be given the same days through (a) a date edit of the first one into the successor's days and (b) **Reopen assignment on the swapped-out Replacement** — reachable from the panel's "Reopen assignment" menu. Measured before the fix on the scratch database (October, $310/month): (a) holder 23 + R1 7 + R2 4 = 34 paid days; (b) holder 11 + R1 20 + R2 16 = 47 paid days for a 31-day month, i.e. 16 days ($160) paid twice. Rule A7 (docs/HR_PAYROLL_RULES.md: *"The same calendar day is never charged twice for the same position"*, owner 2026-10-10 / 2026-10-19) already forbids it, and Replace Employee already refuses it at creation; the edit/reopen/restore paths simply did not apply it. **Fixed under A7, not a new business rule:** `PositionSuccession::assertReplacementClearOfSiblings()` — error `REPLACEMENT_OVERLAPS_REPLACEMENT` (422, names the other Replacement, nothing written) — on the date edit, reopen, company reopen (restore) and the Recovery Center date correction; judged under the same locks (own row → holder row, so two writers serialise). Verified: T (edit, boundary, reopen, Recovery blocker, company reopen refusing a pair, payroll 11 + 4 + 16 = 31 days = $310 after the refusals, no writes); B: Reopen on the swapped-out Replacement in English and Somali shows the refusal and the dialog stays open; PG lock-order test.
3. **Temporary and Replacement pay are independent.** Temporary pay = Permanent monthly salary ÷ 30 × eligible days (fixed divisor) and is a Temporary-cover deduction; a Replacement is a payroll line of its own assignment on the real days of the month. Verified together: Temporary cover vs Replacement overlap is refused both ways (O-1, 24/24 race rounds), a Replacement's own lines reconcile to one month, and the QD payroll lifecycle ($64.00 = 6 × 320 ÷ 30) is unchanged.
4. **Finalized payroll history is never silently rewritten.** With October finalized: an F-A refusal leaves assignments, absences, cycle events, audit rows and every payroll line byte-identical; a permitted edit that would reach the finalized month is refused (a recorded Super Admin correction with a reason is required) and changes nothing. The ÷30 divisor, B-Q2, zero-floor carry-forward and the freshness guard were not touched.
5. **Remaining exposure.** Historical records already in production that break F-A or A7 are not corrected by this release (by instruction) — the compliance report lists the F-A kind (`REPLACEMENT_NOT_IN_ABSENCE`); a read-only production query for both kinds is recommended before/after release but was **not run** (production untouched). Until the release is deployed, production still has the A7 hole through "Reopen assignment" (see 16.3).

### 15.3 Tests added

`ReplacementEndsBeforeHolderReturnTest` (15 tests): Return ends on `return − 1`; edit boundaries and refusals with no writes; HR Manager refused identically; no-return Replacements not limited; older overrun left alone / repairable; Recovery Center (date + cycle correction); company reopen; payroll once per day; characterization of the old misallocation; two Replacements: edit, reopen, Recovery, company-reopen pair; finalized payroll untouched; PostgreSQL lock order (Return, reopen). Web: `coverConflict.test.ts` (+2: F-A and A7 messages EN + SO, 821 total).

## 16. Release triage of the other findings (F1–F11, D1–D6, new items)

Classes: **B** release blocker · **E** non-blocking existing production defect · **C** cosmetic / low · **O** needs a new owner decision · **K** deferred backlog. No recruitment, attendance, facility or Marketing business rule was changed.

| ID | Finding | Class | Impact on HR starting normal operations / workaround |
|---|---|---|---|
| F-A | Replacement past holder's return | **closed** (15) | — |
| A7 gap | Two Replacements of a position could share days (edit, **reopen**) | **was B** (money); **fixed locally** | Reachable from "Reopen assignment" on production today. Interim instruction until deployed: do not reopen a Replacement that was swapped out while its successor is still working. |
| F1 | Contract signed / uniform received before guarantor verified; stage waits | E + O | Person appears in the wrong queue until the contract is signed again; workaround: verify the guarantor first. Owner: refuse out-of-order or auto-advance? |
| F2 | Adding the same person to a training batch twice → HTTP 500 | **E** (assessed separately, below) | None for data (the unique constraint holds and the transaction rolls back); the panel's picker already hides people in the batch, so only a double-submit / stale second tab shows "Server Error". |
| F3 | Super Admin override to Waiting leaves the old pipeline stage | E + O | Super Admin only, rare; person also shows in the old queue; clear by moving the stage. |
| F4 | Standalone complaints skip the inspection-form rules | O | Rules can be applied by HR practice meanwhile. |
| F5 | Second inspection for the same company and day allowed | O (may be intended) | None. |
| F6 | Uniform buttons send no purchased/received dates | C/K | Dates stay empty; status is correct. |
| F7 | Attendance accepts future dates and dates before the assignment | O | HR must mark real days; no payroll effect found. |
| F8 | Overlapping annual/sick leave allowed | O / C | Only unpaid leave is overlap-guarded. |
| F9 | Missing-record 404 shows Laravel's raw message | C/K | None. |
| F10 | Business-rule server messages stay English in Somali | K (+O wording) | Known limitation; panel text around them is Somali. |
| F11 | Two lower-case Somali titles; raw field paths in API messages | C | None. |
| D1 | Cancelling a cancelled Workforce Request writes a second audit row | C/K | None. |
| D2 | Completed follow-up can be rescheduled/completed again; past dates accepted | O | History shows duplicates; no money. |
| D3 | `PATCH marketing/employees` ignores `team_id` | K | Team changes go through the team screens. |
| D4 | Removing a non-member from a team returns 200 | C/K | None. |
| D5 | Marketing Employee gets 422 not 403 on team POST with empty body | C | No data exposed. |
| D6 | Marketing breadcrumb leaf not translated | C | None. |
| N1 | (browser agent) empty required fields give no visible message in "Register complaint", satisfaction evidence and "Add work location" | C/K (low–medium UX) | Nothing is saved; users need to know the field is required. |
| N2 | (browser agent) payroll "Generate / refresh draft" row not refreshed until reload; Marketing Employees list stale ~1.5 s | C/K | Reload shows the truth. |
| N3 | Voided inspection has no edit control; Practical Batch has no complete/cancel; "Need Training" shows only search | K (feature gaps) | None for daily use; the API refuses edits of voided inspections (`FACILITY_INSPECTION_VOIDED`). |
| Q | Marketing: record-status transitions, commission 0, quotation without amount | O | As in section 14.3. |

**F2 assessment.** Cause: `AddTrainingBatchParticipantAction` creates the participant row with no pre-check, so a repeat hits the `training_batch_participants(training_batch_id, employee_id)` unique index and Laravel answers 500. The rule itself (one row per person per batch) is already enforced by the database, so a fix is **error handling only**, not a new business rule: check for the existing row and answer 422 with a clear code. Not applied (instruction); recommended for this release or the next at the owner's choice — one pre-check, one test.

**Do any remaining defects stop HR starting normal operations safely?** No. Nothing found corrupts money or data except the A7/F-A pair, which are closed in this candidate; every remaining item is an inconvenience with a workaround, a feature gap, or a rule the owner may want to add later.

## 17. Final release readiness (2026-11-07, after F-A)

### 17.1 What was run on the final working tree

| Check | Result |
|---|---|
| Full backend suite, SQLite | **3,488 tests, 3,446 passed, 42 skipped (the PostgreSQL-only tests), 0 failed, 0 errors**, exit code 0 (779 s) |
| PostgreSQL 18.6, 159 HR / Payroll / Replacement / Cover / Temporary / Succession files | 2,040 tests, 2,032 passed, 1 skipped, **7 failed** — all 7 the known `PayrollReversalDatesTest` data-provider test (`paid_at` read 3 h early on this UTC+03:00 host); identical on production `010005a` (section 14.2); **the whole PostgreSQL suite is not reported as passing** |
| PostgreSQL, focused: F-A/A7 file + both O-1 files + Recovery Center (with the PostgreSQL-only lock-order tests) | 70 / 70 passed, 0 skipped |
| Concurrency: real two-process race, 24 rounds, cover vs Replace Employee | 24 / 24 exactly one winner (22 Replacements, 2 covers), 0 errors, 0 overlapping pairs; PG lock-order tests for Return and reopen added; **not raced with two real processes: Return vs date edit, reopen vs swap** (covered by the lock-order proofs only) |
| Web panel tests | 821 / 821 |
| TypeScript, ESLint, i18n scan | clean; 0 untranslated items |
| `php -l`, Pint `--test` | clean on all 42 touched / new PHP files (one Pint alignment fix applied) |
| Translation integrity | 161 locked identical; 86 R1C identical; 249 approved; 0 existing changed; **43 new messages, all `proposed`** (4 added for F-A / A7) |
| Exit codes | every suite above exited 0 except the PostgreSQL broad run (exit 1, the 7 baseline failures only) |

### 17.2 Rows 26–30 and role checks (browser, local)

- **26 HR Manager writes in the browser — PASS** (agent, own origin; read back over the API): register + edit employee; department and position create/edit; guarantor, contract, uniform; training and practical batch, Waiting → Active; assign, change salary, end assignment; Replace and Return on the company page; attendance Present; leave; payroll Generate → Review → Finalize of a company section. Super Admin-only controls: no Recovery Center, Admins, Roles, Settings; translations Propose only; no Override status; no Reopen on a paid section (Paid section: only "Show its lines"). By design shown: Reopen for correction / Approve Paid / Partial on a finalized unpaid section; void of a debt repayment; Audit Log. *Not rendered, so API-only:* write-off and underpayment payment buttons (no open balance existed); the API refuses HR Manager (`PAYROLL_CORRECTION_NOT_ALLOWED`, section 14.4).
- **27 Practical-batch "complete" — not testable: no such function exists** in the panel or API (a scheduled batch has only "Create Practical Batch"); recorded as a feature gap (N3).
- **28 Facility / complaints / Need Training / work locations — PASS in the browser** (Super Admin): inspection created, edited, voided (reason ≥ 5 chars enforced), voided state shown; complaints registered, status changed, resolved with a note, satisfaction recorded, voided; "Need Training" loads (empty, no console errors); in-use work location refused ("has employee work-assignment history"), unused one deleted.
- **29 Marketing Manager / Mobile App Manager / Marketing Employee writes — PASS in the browser:** Marketing Manager created XARUN and PROJECT records, appended feedback (append-only), created and completed follow-ups, logged a commission, created a team and a team member; Marketing Employee created and moved own XARUN and has no team administration, commission rates, reports or assignment save; Mobile App Manager sees Customers with no Add/Edit/Delete.
- **30 Production — intentionally untested.**
- Server permission matrix re-run on this tree: all **115** HR write routes — Marketing Manager, Mobile App Manager and Marketing Employee 403 (114 empty-body probes + the payroll generate route with a real company id), unauthenticated 401; HR Manager 403 on admins, settings, recovery, translation approval. Browser navigation for all roles: section 14.7.
- F-A and both O-1 directions re-confirmed on the final tree (sections 13, 15).

### 17.3 Production safety

Production was not contacted, read or written by any step. No production seeders, migrations, configuration changes or data edits; no deployment; no staging, commit, push or merge; worktrees A–E, the preserved production backup, `generation_inputs` and every approved/locked translation untouched. The only worktree created in this phase (a temporary checkout of `010005a` to prove the PostgreSQL baseline failures) was removed (its vendor junction first); the other agents wrote only to the local scratch database (QX / QY / FA records).

### 17.4 Git state

Branch `feat/hr-release1c-20261105`, `HEAD` = `010005a` (the live production `main`). Working tree: **57 modified and 19 untracked paths (76 in total, including this document); 0 staged.** No commit, push, merge or deploy was made. Worktrees A–E: still present (5 of 5).

### 17.5 Verdict and recommendation

**PASS (for the local candidate; release only on the owner's explicit instruction).**

Why PASS: F-A is implemented on the server for every role and entry point, with regression tests, PostgreSQL lock-order proofs, browser evidence in English and Somali and no change to any approved rule; the payroll duplicate-payment verification found one more real gap (two Replacements of one position sharing days through a date edit or **Reopen assignment**), which breaks the existing owner rule A7, and it is closed with tests; every payroll rule, both directions of O-1, the role and permission matrices (server 115/115 routes, browser navigation and writes for all five roles) and translation integrity hold; the full SQLite suite (3,488 tests), the web suite (821), TypeScript, ESLint, Pint and `php -l` are green; no unresolved release-blocking defect remains. Remaining items (section 16) are existing production defects or owner questions with workarounds, none of which corrupts money or data.

What this PASS does **not** claim: (1) the PostgreSQL suite is **not** passing in full — the same 7 `PayrollReversalDatesTest` cases fail on this host (UTC+03:00) and on pristine production `010005a`; (2) production was never touched, so how many historical records in production already break F-A or A7 is unknown (a read-only check is recommended first, the existing compliance report lists the F-A kind); (3) Return vs a concurrent date edit and reopen vs swap were proven by lock-order tests, not by a real two-process race; (4) write-off / underpayment buttons could not be rendered for an HR Manager in the browser (no open balance) — enforced and tested on the server; (5) all new Somali wording is `proposed`.

**May HR safely begin using the production panel?** Yes, for normal daily work: the recruitment pipeline, attendance, facility, Marketing and payroll workflows all behaved correctly in local QA, and no remaining defect needs more than a workaround (section 16). Two cautions: (a) the **currently deployed** build still has the A7 gap, reachable from "Reopen assignment" on a Replacement that was swapped out while its successor is still working — until this candidate is deployed, HR should not use that action (the cost is paid-twice days); (b) deploy this candidate when the owner decides, and run the read-only check for old F-A / A7 records afterwards. Recommended release steps remain the owner's call: commit/push/merge/deploy were **not** started and wait for the owner's explicit production release instruction.

## 12. Release recommendation

**Superseded 2026-11-07 (sections 15–17): PASS for the local candidate; awaiting the owner's explicit production release instruction.** Previously: Hold (re-confirmed 2026-11-07, section 14.10). Close section 10 items 1–3 and 6 (all local, no production access needed), then re-run the automated checks once; if nothing new fails, the PASS CONDITIONS are met and the release sequence (commit, push, merge, deploy, read-only production QA) can start. Note for the release itself: the deployment will run the `generation_inputs` migration only if production does not already have it — production already ran it on 2026-11-06 (migration 229), and this candidate adds **no** migration.
