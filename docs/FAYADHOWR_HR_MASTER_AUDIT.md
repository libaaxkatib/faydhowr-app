# Fayadhowr HR & Marketing — Master Audit (Stage A, read-only)

> **UPDATE 2026-11-05 (scope reconciliation).** The owner removed **employee-level Manual Payments and payment verification** from the scope and approved the **company-level payroll payment workflow** (Approve Paid / Approve Partial, several partial payments, per-company status, month report derived from companies). Everything in this audit about employee Manual Payments (MPA-03, MPA-06 as a feature to build, the registered → verified design, Q1–Q5, the Manual-Payment migration and the "Manual Payments" dashboard figure) is **superseded / not applicable** and is marked so below. The authoritative scope and decisions are in `FAYADHOWR_HR_OWNER_DECISION_REGISTER.md` (v2); section 15 of this file summarizes what changed. Findings about the *existing* employee-payment code are kept as facts.

Date: 2026-11-05. Status: **audit only. No code, commit, push, merge, deployment, migration or production write was made for this document.** The only production access was read-only (`SET default_transaction_read_only = on`), plus public HTTP reads of the panel bundle and `/up`. This file is intentionally left **untracked and uncommitted** in the worktree `faydhowr-repl` until the owner approves a Git action.

Evidence labels: **VERIFIED** (checked today against the repository or production, read-only), **FROM REPORT** (taken from an earlier report in `C:\Users\hp\hr-audit\`, not re-run today), **NOT VERIFIED**.

Earlier conclusive reports are reused, not repeated: `correction_recovery_20261104\reports\` (00 executive summary, WS1–WS6, audit_hr_other / audit_marketing_other / audit_access_audit with the 384-route permission matrix, audit_manual_payments_penalties_advances with 28 reproduction tests, IMPLEMENTATION_PLAN_manual_payments_penalties_advances, RESET plans, FINAL_REPORT_reset_execution_and_P1) and `release_20261105\` (baseline and verification JSON).

---

## 1. Verdicts

| Question | Answer |
|---|---|
| Live production commit | **`0654492b3cab0067e33351f308983f80daba4ec1`** — VERIFIED: Railway lists all four services SUCCESS on it (final update 2026-11-05 06:04 UTC) and the running API container reports the same hash. Equals `origin/main`. |
| Migrations | 224 files in the repository, **224 applied** on production; latest `2026_11_05_110000_add_supersession_to_payroll_corrections`. Nothing pending. VERIFIED |
| Protected records | The 112 legacy-approved November sections, corrections #2–#13 and all 20 payment rows are **byte-identical** to the 2026-11-04 baseline (same hashing method). VERIFIED |
| Money modules in production | `employee_payments`, `employee_penalties`, `employee_advances` all have **0 rows**; `payroll_section_reopenings` 9; `payroll_workplace_reconciliations`, `company_closures`, `company_closure_assignments`, `recovery_corrections` all 0. VERIFIED. So Release 1/2 has **no legacy data to migrate**. |
| Is the Recovery Center a full recovery system? | **No.** It corrects Permanent-assignment history only (4 workflows + a draft payroll preview + 32 blocked-action explanations), Super Admin only. Payroll, payment, company and penalty/advance recovery live as contextual actions elsewhere. No unified history, no health view. |
| Ready to implement? | The code base is ready; **business decisions are still missing** (section 7). Implementation of Release 1/2 and the Recovery Center is **not started** and waits for the owner's approval of this audit and plan. |

---

## 2. Repository, branches, worktrees (VERIFIED)

* `origin/main` = `0654492` (220 commits). Railway service source: repo `libaaxkatib/faydhowr-app`, branch `main`, root `/backend`, **auto-deploys every push to `main`**, `checkSuites:false` (it does not wait for CI), pre-deploy `php artisan migrate --force`. **Process risk:** a push to `main` is itself a production deployment and runs migrations. Consequence for this project: work only on feature branches; merging to `main` is the deployment approval step.
* The **local** `main` ref in `faydhowr-app` is stale at `2f4f96f`. Never merge or push from it. Use `origin/main`.
* Worktrees (14). Relevant ones:

| Worktree | Branch @ HEAD | Dirty files | Verdict |
|---|---|---|---|
| `faydhowr-repl` | `feat/correction-recovery-20261104` @ `0654492` = main | 0 (+ this untracked doc) | released work; the base for the next branch |
| `faydhowr-app` (primary) | `feat/web-panel-i18n` @ `f714c66` | **12** (7 modified, 5 untracked) | stranded; see below |
| `faydhowr-i18n-somali` | `feat/web-panel-somali` @ `2a4ecc2` | 0 | "recovery snapshot, not released"; 2 commits ahead of main |
| `faydhowr-i18n-clean` | `feat/web-panel-i18n-clean` @ `c2fcc20` | 0 | **0 commits ahead of main** — its content is in production |
| `faydhowr-ui-theme` | `feat/web-panel-theme-mode` @ `494437a` | 0 | 1 commit ahead (superseded, see 2.2) |
| others (`-oct`, `-qa`, `-sched`, `-tr`, `-uiux`, `-reports`, `-integration`, `-security`, `-ci-fix`) | released or CI/security side branches | not inspected one by one | no outstanding product work found |

### 2.1 The 12 uncommitted files in `faydhowr-app`
They are an **older draft of the payment-list preview** (`PaymentListPreviewDialog.tsx`, `paymentListPreview.test.ts`, `MonthlyPaymentListPreviewTest.php`, controller/route/api/PayrollRunsPage changes) plus two docs (`docs/RELEASE_AUDIT_HR_MARKETING_PANEL_2026-10-25.md`, `docs/_audit_report_2026-10-09.html`). `origin/main` contains evolved versions of every code file (they differ). The two docs are **not in main** (they exist only as untracked files). **Nothing was changed or discarded.** A safety copy was written to `C:\Users\hp\hr-audit\worktree_backups_20261105\` (tracked-diff patch + the 5 untracked files). Recommendation, subject to approval: commit the two docs on a docs branch; leave the code drafts alone.

### 2.2 Historical commits vs production
Ancestry check against `origin/main`: `2f4f96f`, `028b992`, `ae3123b`, `ae17ab4`, `a65ec0f`, `0654492` are **in** main. `494437a` (dark mode), `98105c5` (Permanent→Temporary guard, ten-column sheet, filters), `15ff586`, `8631977`, `f714c66` (i18n, Dark Mode on the i18n branch) are **not ancestors** — they were re-applied through the release branches (`release/hr-i18n-darkmode-excel-20261028` → `c4c61a0`, and later releases). Content proof (VERIFIED): `web-panel/src/theme/ThemeProvider.tsx`, `components/ui/ThemeToggle.tsx`, `i18n/messages/theme.ts`, `i18n/baseline/approved-161.json` (161 entries) + `lockedApproved.test.ts`, 34 message files (~3.7k key lines), `support/Hr/EmploymentTypeChange.php` (code `EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT`) all exist in `origin/main`. **Conclusion: Dark Mode, the Somali/English layer and the Permanent/Temporary guard are in production; no separate outstanding product work was found in these branches.** Not proven line-by-line: a diff of every stranded commit against main (NOT VERIFIED); the old "~2,025 messages / 68 files" figure is historical and no longer comparable.
Locked translations: baseline SHA-256 `ad500a9affadf2bee600acd67b4827c579bbf7b9a2209e54dde33b5ecdf2cecc`, unchanged by the 4 Nov release (FROM REPORT, re-verified before the release).

### 2.3 Import Part Two — status CORRECTED
Earlier notes say "production load not authorized". **That is outdated.** `PART2_SCOPED_PRODUCTION_COMMIT_report_20261028T1406Z.csv` records a real commit (24 companies created, 75 activated + 1 already active, 76 assigned, 1 shift set), and today's read-only check confirms: all 24 companies exist, all 76 people are Active, 76 of the 77 plan rows have an Oct-1 assignment (the 77th is the shift-only row on an existing assignment), 251 audit rows in the 13:50–14:30 UTC window of 2026-10-28. Production: 128 companies, 319 active employees. **Not re-read in this session: the owner's explicit approval message for that load.** The 44 unmatched Permanent rows and 17 excluded rows remain manual entry. Nothing further is to be imported without a new plan, backup and authorization.

---

## 3. Production state, read-only, 2026-11-05 06:13 UTC (VERIFIED)

| Item | State |
|---|---|
| Runs | September: draft, 0 sections. **October: draft run (generated 2026-11-04 13:32 UTC), sections 109 draft + 13 finalized.** **November: draft run (generated 2026-11-03 11:44 UTC), 112 `approved` + 9 `draft`.** |
| Nine companies (11, 19, 32, 44, 66, 92, 116, 125, 126) | November: all 9 **draft** (lines still the earlier stored ones until Generate). |
| October 116 and 126 | Both draft: 116 net $530.00 (8 lines), 126 net $86.20 (3 lines). Unpaid. Not touched. |
| Corrections | 13 rows: #1 `superseded` ($150.00), 12 `open` = **$878.00** outstanding (#2–#13). |
| Workplace payments | 20 rows, 7 voided (#5–#11), active total $2,270.97 (the 4 Nov total of $2,894.97 includes the $624.00 voided), max id 24. Unchanged since the voids. |
| Employees | applicant 4,028 · waiting 862 · inactive 598 · active 319. Companies 128. |
| Pending release work | none on production. |

The 112 sections are **protected by design**: `approved` and `finalized` are in `PayrollRunSection::LOCKED_STATUSES`; Generate rewrites only draft/reviewed sections and merely warns when the live calculation differs. A legacy `approved` section can still be **finalized directly** (`TransitionPayrollRunSectionAction::BEFORE_FINAL` accepts reviewed or approved) and then paid with Approve Paid/Partial. Consequence of the owner's "keep": their lines are early-November snapshots; every change made during November reaches them only as a **Payroll Correction**.

---

## 4. What exists today (summary; details in the earlier reports)

* **Payroll:** per-company sections (draft → reviewed → finalized), approval derived (finalized and paid in full), Approve Paid/Partial (`payroll_workplace_payments`, void by flag with reason), reopen of a finalized company with supersession of that company/month's open corrections (P1), reconciliation of overpaid companies, closed-period correction records (`PayrollCorrection`), recovery ledger (`RecoveryLedger`), company close/reopen, End Assignment without replacement, Separation with an optional replacement, Temporary Replacements module. `CorrectionAuthority` holds the HR Manager / Super Admin permission matrix.
* **Manual payments / penalties / advances:** create-only. No edit, void or reverse; no idempotency; penalty/advance recorded after Generate is lost at Finalize (MPA-05); a duplicate request doubles the deduction (MPA-04); a salary manual payment does not touch payroll paid status and can be counted twice (MPA-06); advances larger than pay create a negative net and the shortfall disappears (MPA-10); currency is any 3 letters and non-USD is silently not deducted (MPA-09); leavers cannot get last-month records (MPA-12). 18 findings, 28 reproduction tests. **The code of these actions is unchanged since that audit** (`git diff 944ed6f..0654492` touches only `PayrollCorrection.php` among the relevant files), so the findings still apply. Production has never used them (0 rows).
* **Recovery Center** (`/system/recovery`, `RequireSuperAdmin`, API `v1/admin/recovery/*` with `auth:sanctum` + `admin` + `super_admin`): workflows `reopen_assignment`, `restore_cancelled_assignment`, `correct_assignment_dates` (within one month), `correct_absence`, read-only `draft_payroll_preview`; preview (always rolled back, recalculates payroll with the production calculator) → apply needs a 15-minute token, written reason, explicit confirmation and an idempotency key, row locks and a stale check (409); history = `recovery_corrections` (0 rows). 32 plain-language blocked-action explanations (`RecoveryErrorCatalogue`) — the sentences come from the server **in English**. Never writes payroll.
* **Compliance report** (`/hr/reports/compliance`, HR Manager and Super Admin): read-only detector of HR/payroll accountability findings (last run: 0 confirmed, 690 needs-review; FROM REPORT). This is the nearest thing to a health view today.
* **Audit log** (`audit_logs`, Audit Logs page) with before/after only for some events; the whole-panel audit lists the gaps (ACC-01..04, 10, 12–14).
* **Policies:** `HrPolicy::CATALOG` holds one switch (`multi_company_assignments`, Super Admin only). A new switch for Release 2 would be added there.

---

## 5. Gap analysis against the owner's decisions

### 5.1 Release 1 (requirement → current state → work)

*Rows 1–4 (Manual Payment purpose/reason, payroll-period association, salary payment paid-status integration, manual-payment void/reverse) are **superseded — not applicable** since 2026-11-05. Row 13 is replaced by the company-level dashboard/report in register section 6.5. The company payment workflow itself already exists (register section 6).*

| # | Requirement | Current | Work |
|---|---|---|---|
| 1 | Manual Payment purpose + reason | none | new required fields; purposes `salary, advance_payout, final_settlement, bonus, reimbursement, other` |
| 2 | Payroll-period association | none | `payroll_period`, `client_company_id` |
| 3 | Salary Manual Payment affects paid status | none (MPA-06) | allocation rule — **decision needed (Q1)** |
| 4 | Void / correction / reversal of manual payments | none | offset-row pattern, `employee_payment_voids`; HR Manager void only when no money moved, Super Admin reverses money (FROM earlier owner decision — **confirm Q8**) |
| 5 | Penalty correction and void | none | offset + replacement chain |
| 6 | Penalty carryover | none | `payroll_deduction_carryovers`; cap definition **Q5** |
| 7 | Idempotency | none on the three modules | key + replay/409 on every new write |
| 8 | Payroll freshness guard | none | `PAYROLL_INPUTS_CHANGED_AFTER_GENERATION` on Review/Finalize |
| 9 | Server-side validation | partial | USD only, `gt:0`, max, no future date, real month |
| 10 | Audit trail | creation event only | before/after/actor/role/reason |
| 11 | One-month Advance correction/void | none | same offset machinery as penalties — **scope Q4** |
| 12 | No duplicate salary payment / deduction | none | by 3, 7, 8 |
| 13 | Dashboard/payment consistency | company payments absent, manual double counted (MPA-07/08) | typed ledger rows, separate KPIs |
| 14 | Closed-period safeguards | existing `ClosedPayrollCorrection` | route void/correct of a locked month through it |

### 5.2 Release 2

Installment schedule (≤ 24, editable amounts/dates), balance, reschedule, 60% cap, 100% leaver cap, leaver advance/penalty debt, Final Settlement, advance void/waive/reverse, audit history, policy `advance_installments` **off by default**. Nothing exists today (employee_advances has no schedule, status or balance).

### 5.3 Full Recovery Center

| Requirement | Today | Needed |
|---|---|---|
| A. Unified read-only history | per-feature pieces only (`audit_logs`, reopenings table + "Reopening history" row, workplace payment history, corrections panel, `recovery_corrections`) | one timeline API + page over those sources, filterable by employee / company / month / action; before/after where recorded |
| B. Health & integrity | Compliance report only | validated checks (list in 8.2); no check may be shown without a real query |
| C. Recovery actions | contextual actions + 4 assignment workflows | decide which are *launched* from the hub; separation correction and marketing commissions need rules (Stage A Q9 — **deferred, X-1**) |
| D. Safety | preview/token/reason/idempotency/stale check exist for assignment workflows | extend the same protocol to any financial recovery launched from the hub |
| E. Access | Super Admin only (route + API middleware) | unchanged unless the owner approves (Stage A Q9 — **deferred, X-1**) |
| F. Limitations to report | see 8.3 | report only |

---

## 6. Payroll calculation trace (needed before the Advance rules)

Source: `PayrollRunCalculator::compute()` lines 236–330, `addPenaltiesAndAdvances()` 887–948, `applyOverpaymentRecovery()` 556–660, `finalPay()` 661.

1. Per assignment line: `net_cents = gross − replacement_deduction (capped by ReplacementDeductionCap) − other_deduction + adjustment`. `other_deduction` is the sum of every non-replacement deduction: **penalty, advance, unpaid leave, absence**, and any other kind. `adjustment` is the signed sum of pending payroll adjustments for that assignment.
2. A person's penalties and advances belong to a **company** (`InputOwnership`); they are attached to the **first line of that company** only (`$firstItemForEmployee`), so several lines of the same company carry them once.
3. **There is no floor today**: penalties and advances can drive a line negative (MPA-10). A negative line is excluded from the payable total and cannot be marked paid.
4. Overpayment recovery (open `overpayment` corrections, not superseded) runs **after** step 1 as a post-process on the finished lines: available = `max(0, net)`; it is limited to the company that was overpaid; it adds to `other_deduction` and lowers `net`.
5. "Final pay" (`finalPay`) is recognized when the **assignment's `end_date` falls inside the month** (not cancelled): salary × calendar days up to and including the last paid day. A separate `employee_separations` record exists for employment-level separation.

### 6.1 Proposed definitions (technical translation of the owner's rules — **to be confirmed where marked**)

For one employee and one company in one month (all that company's lines together):

* `P` = penalty deductions actually applied this month (including carried-over penalty balance), limited so that the base never goes below zero (Q5).
* `B` = **Eligible Net After Penalties** = `max(0, Σ lines[ gross − replacement − absence − unpaid leave + adjustments ] − P)`. (Positive/negative adjustments are inside the base — **Q3**.)
* Normal employee: `AdvanceCap = floor(0.60 × B)` in cents; `AdvanceDeduction = min(Σ installments due + carried shortfall, AdvanceCap, outstanding balance)`.
* Leaver in the **final payroll month** (rule applies only that month): `AdvanceDeduction = min(outstanding balance, B)`; the remainder stays as a **debt** in the existing correction/recovery ledger.
* Order on a line: absence/leave/replacement → penalties (incl. carryover) → advance → overpayment recovery (existing post-process, limited to what is left) → resulting net ≥ 0.
* The advance amount is **spread across that company's lines** (not only the first line) so no single line becomes negative while the company total is positive.

Worked examples (independently checkable; cents):

| Case | Gross | Absence | Penalty due | B | Advance due / outstanding | Cap | Deducted | Net | Carried / debt |
|---|---|---|---|---|---|---|---|---|---|
| Normal | 30,000 | 3,000 | 2,000 | 25,000 | due 20,000 | 15,000 | 15,000 | 10,000 | shortfall 5,000 carried |
| Penalty > pay | 30,000 | 0 | 35,000 | 0 | due 10,000 | 0 | 0 | 0 | penalty carry 5,000; advance carried 10,000 |
| Leaver, large balance | 30,000 | 0 | 0 | 30,000 | outstanding 40,000 | 100 % | 30,000 | 0 | debt 10,000 |
| Leaver, small balance | 30,000 | 0 | 0 | 30,000 | outstanding 8,000 | 100 % | 8,000 | 22,000 | none |
| Employee stays, base 0 | 10,000 | 0 | 10,000 | 0 | due 5,000 | 0 | 0 | 0 | advance carried 5,000 |

These become the independent test oracle required before Release 2 is enabled.

---

## 7. Conflicts, ambiguities and decisions the owner must make (nothing below was silently decided)

*Status 2026-11-05: **Q1–Q5 are closed as not applicable** (employee payment verification no longer exists). **Q6–Q11 remain open** under the same numbers (Q12, the dashboard change, is replaced by the company-level figures C-7). New points N-1…N-6 from the company-payment inspection are in the register, section 5.2. The old text below is kept for history.*

**ID mapping (the original Stage A numbering below differs from the register's Q-numbers; the register is authoritative).** The register's Q6–Q11 are *not* the same questions as Stage A's Q6–Q11:

| Stage A (this section, historical) | Where it stands now |
|---|---|
| Q1 salary manual payment vs "registered ≠ cash" | not applicable (employee Manual Payments removed) |
| Q2 definition of leaver | resolved — A-2 (assignment at that company ends) |
| Q3 base of the cap | resolved — A-2 (adjustments included, advance excluded, overpayment recovery later) |
| Q4 Release 1 advance scope | A-1 + register **Q7 (PENDING OWNER DECISION)** |
| Q5 penalty cap | resolved — P-1 (up to 100 % of net) |
| Q6 Final Settlement | resolved — F-1 (Release 2) |
| Q7 installment rules | resolved — A-2 (consecutive months, ≥ 1, ≤ 24, editable before saving) |
| Q8 void/correct authority | R-1 + register **Q8 (PENDING OWNER DECISION)** |
| Q9 Recovery Center scope | **DEFERRED** — X-1 (not the register's Q9) |
| Q10 "missing audit events" health check | **DEFERRED** — X-1 |
| Q11 sequencing | register **Q10 (PENDING OWNER DECISION)** |
| Q12 KPI change | resolved — C-7 |
| *(not in Stage A)* | register **Q6** leaver penalty and **Q11** separated employees (PENDING OWNER DECISION); register **Q9** = no new database trigger (**APPROVED**) |

1. **Q1 — Salary Manual Payment vs "registered ≠ cash paid".** The prompt says a Salary manual payment must affect payroll paid status (4.1.6) *and* that a registered payment is not proof of cash unless the workflow confirms it. Payroll lines have only paid/unpaid (no partial); approval is per company (finalized AND paid in full), while manual payments are per employee. Decide: (a) does a Salary manual payment mark the employee's line paid (full) or allow partial amounts per line; (b) how it combines with Approve Paid/Partial of the same company (proposal: total paid = company payments + line payments; a payment above the remaining outstanding needs an explicit overpayment confirmation and shows as Overpaid); (c) what in the manual-payment form counts as "confirming cash was paid".
2. **Q2 — Definition of "leaver".** (a) the assignment at the **advance's company** ends in this month (company-level, matches `finalPay`, covers End Assignment and company closure) or (b) the **employee is separated from employment** (`employee_separations`). An employee ending at company A but continuing at B is a leaver for A's advance under (a), not under (b). Proposal: (a).
3. **Q3 — Base of the cap.** Do positive/negative payroll adjustments and the overpayment-recovery deduction belong in "net after penalties"? Proposal: adjustments yes; overpayment recovery comes **after** the advance (current order), so it does not reduce the base.
4. **Q4 — Release 1 Advance scope.** Release 1 corrects/voids single-month advances but the 60% cap and "never negative" protection belong to Release 2. Between the two releases a single-month advance can still push a line negative (MPA-10). Accept that gap until Release 2, or include the zero-floor + carry for single-month advances in Release 1 (it activates a payroll rule early)?
5. **Q5 — Penalty cap.** "Cannot be deducted in the current month" is read as: the penalty may take the line down to zero (100 % of the base before penalties) and only the excess carries. Confirm (the 60 % applies to advances only).
6. **Q6 — Final Settlement.** Not specified: trigger (employee separation, any assignment end, company closure?), who confirms, whether it is a statement/calculation or also the payout (via Manual Payment purpose `final_settlement`), how a debt is collected after the person has left (existing ledger repayments exist for Super Admin/HR Manager).
7. **Q7 — Installment edit rules.** Allowed periods (any ascending distinct months? gaps?), minimum amount, edits after saving (= reschedule: allowed; installments already applied must stay immutable).
8. **Q8 — Void/correct authority.** Not restated in the prompt. Confirm the earlier decisions: void/correct penalties and advances = HR Manager + Super Admin (plain `hr.manage` cannot); void manual payment "no money moved" = HR Manager + Super Admin; reverse "money was paid" = Super Admin only; waive/cancel debt = Super Admin only; recording needs only `hr.manage` with no threshold (4.1.1–2).
9. **Q9 — Recovery Center scope.** (a) Roles: Super Admin only today; should HR Manager get read-only History/Health? (b) The 4 Nov decision said corrections must be reachable from the page where the issue appears and **not only** from a central center; "centralize" in this prompt is read as *adding* a hub, keeping the contextual actions — confirm. (c) **Separation correction/undo (HRO-06)** and **Marketing commission correction (MKT-01/02)** have no approved business rules: what exactly is undone, effects on payroll, closed months, status and replacements; commission rules. Not implemented without answers. (d) Month-level reopen of a finalized month does not exist by design (owner decision 2026-11-03, company by company); report only.
10. **Q10 — "Missing audit events" health check** needs a definition (which actions must have an audit row).
11. **Q11 — Sequencing.** A November Generate after 30 Nov would run on the current create-only modules unless Release 1 is live first. Recommended order: implement → test → approvals → deploy Release 1 → only then Generate. Needs the owner's timing.
12. **Q12 — KPI change.** Release 1 shows workplace payments and manual payments as separate dashboard/ledger figures (today company payments are absent). Confirm the change in what the owner sees.

Prompt-internal consistency check: 4.3.2 (editable installments) **supersedes** the earlier equal-installment decision; the older plan text is superseded and will not be implemented. 4.5 (100 % for leavers) supersedes the earlier "60 % everywhere" and the plan's D-2. Nothing else conflicts; 3.3 (112 locked) is compatible with Release 1 because locked companies are corrected through recorded corrections, never reopened.

---

## 8. Recovery Center — full-scope audit

### 8.1 Unified history: available sources (VERIFIED by code)
`audit_logs` (actor, role where recorded, entity, metadata with `event` names such as `payroll_section_reopened_from_finalized`, `payroll_correction_superseded`, `workplace_payment_voided`, `payroll_company_approved/withdrawn`), `payroll_section_reopenings` (who/when/why, totals before/after), `payroll_workplace_payments` (+ void fields), `payroll_corrections` + `payroll_correction_transactions`, `payroll_workplace_reconciliations`, `company_closures` + `company_closure_assignments`, `recovery_corrections`, `assignment_cycle_events`, `employee_work_assignments` history, `employee_separations`. Gaps: manual payments / penalties / advances record only a create event; admin and role changes log field names only (ACC-01/02); audit rows lose the actor if an admin is deleted (ACC-03); 36 mutating routes and downloads/failed logins are unaudited (ACC-12–14). A truthful unified timeline is therefore possible for payroll/assignment/company events and **will remain partial for the three money modules until Release 1/2 add proper events**.

### 8.2 Candidate health checks (each backed by a real query; none shown without one)
Duplicate manual payments / penalties / advances (same employee, period, amount, reason in a window); duplicate deductions on one line; run/section freshness (inputs created or voided after `payroll_runs.generated_at`); finalized lines with zero or negative net; lines paid while the section is draft; section paid > payable (Overpaid) and unreconciled; open corrections and debts by age; outstanding penalty carryovers; outstanding advance balances and installments past due; assignments overlapping or without an owner company; Temporary/Replacement inconsistencies (`UnresolvedTemporaryReplacements`); reopened sections not regenerated; voided payments whose approval flags disagree; payroll items without section; audit-gap check for the actions in 8.1. Each check returns a count, a sample and a link; a check that cannot run says so.

### 8.3 Status of the items the prompt asks to report

| Item | Status |
|---|---|
| Separation correction / undo (HRO-06) | **Not implemented**; no business rule. Separation can be created (replacement optional since `08926db`) but not edited or undone. |
| Marketing commission correction (MKT-01/02) | **Not implemented**; no business rule. |
| Reopen an entire finalized month | **Not available by design**; companies are reopened one at a time. |
| Remaining audit gaps | ACC-01..04, 10, 12–14 open (see `audit_access_audit.md`). |
| ACC-06 | A Super Admin can override any of the 161 locked messages through the translation editor. Open; report only. |
| Recovery Center messages | English blocker text from the server; UI chrome Somali "proposed", reviewable in Translations; 66 new correction messages also proposed. |

---

## 9. Database / migration plan (draft; each migration will be separately explained, tested on a disposable PostgreSQL copy and reviewed)

Existing schema inspected: `employee_payments` (has `payroll_item_id`, `reverses_payment_id`), `employee_penalties`, `employee_advances` (single amount, `payroll_period`, `client_company_id`), `payroll_corrections` (CHECKs on `kind/action` in PostgreSQL), `payroll_workplace_payments`. No conflicting duplicate migration found (224 = 224). All changes additive, nullable or defaulted; rollback = ignore the new columns (old code only sums rows).

Release 1 (revised 2026-11-05): record-integrity columns (`idempotency_key` unique, offset/supersede self-FKs, `entry_kind`, `recorded_by_role`, void fields) on **`employee_penalties` and `employee_advances` only**; penalty/advance indexes; `payroll_deduction_carryovers`. **No manual-payment fields, no `employee_payment_voids`, no change to `employee_payments`.** Added by the v3 scope (2026-11-05): `payroll_run_sections.generated_at` / `generated_by` (nullable, no backfill) for **per-company Generate** (register 4.2); and, only if the owner approves N-5, one nullable `reference` column on `payroll_workplace_payments`.
Release 2: advance schedule columns + `employee_advance_installments` + append-only `employee_advance_events`; `employee_final_settlements`; widen the PostgreSQL CHECK on `payroll_corrections` for a debt kind (additive). Policy row `advance_installments` (default off).
Recovery Center: read-only history/health need **no** new table (queries over existing sources); recovery actions beyond assignments would reuse `recovery_corrections`.

---

## 10. Security and risk register

* Re-used from `audit_access_audit.md` (28 findings, 384-route matrix): no API path assigns Super Admin; last-Super-Admin guard holds; Marketing team isolation enforced server-side; open: ACC-01..04, 06, 10, 12–14, **ACC-16 (30-day tokens, no MFA)**.
* **MPA-15 / D-1:** recording money needs only `hr.manage` with no threshold (owner decision, 4.1.1–2). Compensating controls to build: mandatory purpose + reason, idempotency, audit with role, health check on large or duplicate manual payments, void/reverse only by HR Manager/Super Admin.
* **MPA-13 (potential race):** recording is check-then-insert without a transaction; to confirm with a two-connection PostgreSQL test (planned in Release 1 tests).
* **CI is SQLite-only** (`.github/workflows/ci.yml`: "Run test suite (SQLite in-memory)"); production is PostgreSQL 18. Local PostgreSQL runs are the only PG evidence. Recommendation (needs approval, may add Actions minutes — existing GitHub Actions, no new service): add a PostgreSQL job for the HR directories.
* **Auto-deploy on push to `main`** with `checkSuites:false` (section 2).
* New correction-release endpoints were permission-tested (HR Manager / Super Admin / other roles); IDOR/mass-assignment/CSRF re-audit of the **new** Release 1/2 endpoints is part of Stage C.

---

## 11. Tests (VERIFIED totals from the 2026-11-04 release gate on `0654492`)

* SQLite full suite: 3,260 tests, 3,221 passed, 39 skipped, **0 failed** (917 s).
* PostgreSQL 18, complete `tests/Feature/Api/V1/Admin/Hr` + `tests/Unit`: 1,668 tests, 1,667 passed, 1 skipped, **0 failed** (990 s).
* Web: 616 / 616; ESLint, `tsc`, Pint clean.
* **PostgreSQL full `tests/` baseline (all modules), run today for this audit:** 3,260 tests, 3,225 passed, 34 failing/erroring, all outside HR/Unit; details in section 12.
* Browser QA of the correction release was done locally (Somali UI); **the new correction screens were not clicked on production** (no login available to the assistant): NOT VERIFIED in production UI.

---

## 12. PostgreSQL full-suite baseline

Run on `origin/main` (`0654492`), PostgreSQL **18.6** (isolated local server 127.0.0.1:5433, disposable database `fayadhowr_pgtest_20261105_audit_all`, `PGTZ=UTC`), command `vendor/bin/phpunit tests` via `pg_run_full.ps1` (refuses any other target), 1,279 s. Result file: `C:\Users\hp\hr-audit\correction_recovery_20261104\audit_pg_full_tests.txt`.

| Total | Passed | Failed (assertions) | Errors | Skipped |
|---|---|---|---|---|
| 3,260 | 3,225 | 5 | 29 | 1 |

**All 34 problems are outside HR and `tests/Unit`; HR + Unit = 0 failures** (this is the full-directory run that the 4 Nov report had not repeated). By module: Quotations 16 (`Admin/Quotations` 8, `Quotation/QuotationWorkflowTest` 8), Settings persistence 3, Dashboard 3, Reports 3 (`ReportSummaryApiTest` 1, `RevenueReportTest` 2), Booking payment gate 2, Admin Operations 2, Product image management 2, Store-order cash on delivery 2, Customer registration 1. Causes: PostgreSQL CHECK violations (SQLSTATE 23514) 22, unique violations (23505) 6, `json = unknown` (comparing a JSON column in the test query) 3, feature not supported (0A000) 2, one assertion on `customer_profiles`. **Difference from SQLite: the same 3,260 tests give 0 failures on SQLite** (SQLite does not enforce those CHECK/unique/JSON rules). None of these modules is touched by the HR work (no file overlap); "pre-existing" remains an inference because the same run was not made on an older commit. A local branch `test/pg-fixture-repairs-quotations-dashboard-reports-settings` (`fa4a37e`, "repair 25 stale PostgreSQL fixtures", **not in main**) already targets the Quotations/Dashboard/Reports/Settings part. Not touched; it is a separate, optional cleanup.
**Release 1 gating rule proposed:** the HR + Unit directories on PostgreSQL must stay at 0 failures, and the complete run must not show any *new* failure compared with this baseline (34).

---

## 13. Proposed work plan (nothing started)

Branch `feat/hr-money-recovery-20261105` from `origin/main`, three isolated workstreams with their own reports, tests and rollback:

0. **WS-0 foundation** (Release 1): idempotency helper, offset-entry helper, freshness guard, authority additions in `CorrectionAuthority`, audit shape, validation.
1. **Release 1 (revised 2026-11-05, v3)**: ~~A Manual Payments~~ (removed) → **per-company Generate** (N-2, explicit requirement; shared generation service + section stamps) → **company-level reports/dashboard** (N-4, explicit requirement) → B Penalties (+carryover, correction, void) → C one-month Advance void/correct with zero-floor and carry. The company payment workflow (C-1…C-7) is preserved; the Review → Finalize gate stays (N-1). Scope, dependencies, risks, migrations and tests: register section 6.
2. **Release 2**: advance installments → caps (60 % / leaver 100 %) → debts → Final Settlement → void/correct/waive.
3. **Recovery Center full scope**: history API + page → health checks → launch points for supported recovery actions (assignment workflows already exist) → English/Somali messages (proposed, not locked).

Stage gates (each needs its own owner approval, separately): (1) Release 1 implementation, (2) Release 2 implementation, (3) Recovery Center implementation, (4) Git commit/push/merge, (5) production migration, (6) production deployment, (7) any production data operation. Cost: no new service; PostgreSQL CI job (optional) uses existing GitHub Actions.

November protection for any future Generate (owner list, 8): complete assignments, attendance/absence, temporary-replacement records, penalties and advances, no unresolved Temporary/Replacement, employee end dates verified, freshness verified, fresh backup (restore-checked), protected hash of the 112 sections (method recorded in `release_20261105\baseline_ro.sh` / `verify_ro.sh`), and a dry-run report. **November Generate is not run.**

---

## 14. Protected-record integrity checklist (for every later stage)
112 `approved` November sections hash `52bd8dbc63d0cd2d93396e2473c54693` (method: md5 of `[id, md5(row)]` for approved sections of period 2026-11, as in `baseline_ro.sh`); corrections #2–#13 (core md5 `99bedce205096a17c78ba53d77be3262` on id/status/outstanding); payments 20 rows / 289,497 cents / voided ids 5–11; 161-message baseline hash; nine-company November sections draft; October 116 and 126 drafts.

---

## 15. Scope update 2026-11-05 — company-level payroll payments (read-only inspection result)

**Recorded as owner-approved:** company-by-company workflow (select month and company → review/correct employees → generate → `Approve Paid` / `Approve Partial` with an amount field); several partial payments per company with cumulative paid, remaining = total − cumulative, `Partially Paid` until cumulative = total, never above the obligation, append-only history, duplicate protection; **no global October approval** — the month report is derived from the companies' statuses (Paid / Partially Paid / Unpaid, totals, cumulative payments, outstanding); dashboard = Company Payroll Total, Company Payments Received, Outstanding balance, counts of Paid / Partially Paid / Unpaid companies.
**Removed / not applicable:** employee Manual Payments, payment verification, employee payment purposes, employee payment ledger, attaching payments to employee lines.

**Code inspection (no change made), details in the register section 6:**
* Approve Paid / Approve Partial: `RecordCompanyPaymentAction` → `RecordWorkplacePaymentAction`; UI `CompanyPaymentDialog.tsx`; route `POST v1/admin/hr/payroll-run-sections/{section}/payments`. **Implemented**, including the partial amount field.
* **Multiple partial payments: supported** (append-only rows in `payroll_workplace_payments`; cumulative and remaining derived in `WorkplacePayroll`/`PayrollApproval`; `AMOUNT_EXCEEDS_OUTSTANDING`, idempotency key, run-row lock, audit event on each payment and on each approval flip). Tested; **not yet used in production** (13 active October payments, one per company).
* **Month report aggregation: correct on the payroll page** (derived from sections; no global approve action). Gaps: label "Month approved" vs "October — All Companies Paid"; the month list row shows finalization progress, not payment counts; Payroll Rollup shows only finalization counts; the dashboard KPI reads **employee payments** and omits company payments (MPA-07).
* **Points raised against the stated workflow (status as of v3.1):** Review → Finalize required before payment (N-1 — **APPROVED: kept, Finalize mandatory**); Generate is month-wide, not per company (N-2 — **explicit requirement, a gap to close in Release 1**); employee-level "Record payment" and per-line "Mark as paid" code still exists (N-3 — **approved constraint: no employee-level manual salary workflow is built; legacy code untouched**); no separate payment reference field (N-5 — **PENDING OWNER DECISION**).
* **Effect on Release 1 (superseded by section 16):** no change to payroll payment arithmetic, approval or the Excel payment list is needed for payments; the v3 scope adds **per-company Generate** and the **company-level reports** to Penalties + one-month Advances.

---

## 16. Update v3.2 (2026-11-05) — statuses, investigation findings, effect on Release 1 (v3.1 findings kept; v3.2 decisions in section 17)

Authoritative detail: `FAYADHOWR_HR_OWNER_DECISION_REGISTER.md` v3.2 (status index 1A, sections 2–11). C-1…C-7 untouched. Both documents are untracked; nothing is staged or committed.

**Status of every item**

| Item | Category / status |
|---|---|
| C-1 … C-7 company-level payment workflow | **APPROVED** |
| N-1 Review → Finalize → Approve Paid/Partial (Finalize mandatory before payment approval) | **APPROVED** (v3.1) — the investigation below is rationale |
| N-2 per-company Generate | **EXPLICIT REQUIREMENT** → Release 1A; sub-decisions **N-2a** (company-only Generate) and **N-2b** (warning only) are **APPROVED** (v3.2) |
| N-4 company-level reports and dashboard | **EXPLICIT REQUIREMENT** → Release 1A; sub-decision **N-4a** (every assigned company, generated or not) is **APPROVED** (v3.2) |
| Q9 no new database trigger | **APPROVED** (v3.1) |
| N-3 employee-level manual salary workflow must not be built | **APPROVED / CONSTRAINT** (v3.1); legacy code untouched, hiding/removal needs a separate approval |
| S-1 112 sections and protected payroll data untouched · S-2 161 Somali translations locked, approved verbatim | **CONSTRAINT** |
| X-1 Recovery Center Full Vision | **DEFERRED** — excluded from Release 1 |
| D-1 · D-2 · D-3 · D-4 · D-5 · D-8 | **APPROVED** (v3.2, see section 17) |
| D-7 PostgreSQL CI job | **DEFERRED** (v3.2) |
| **Q6 leaver penalty · Q7 advance remainder · Q8 void/correction authority · Q10 timing · Q11 separated employees · N-5 payment reference · N-6 payroll changes after payment** | **PENDING OWNER DECISION** (D-6; working assumptions exist for planning only; **not approved, not confirmed, not accepted**; analysis in register sections 9–10) |

**Technical findings (read-only)**
1. *Finalize before payment* is structural: Generate deletes and recreates lines (and a line payment has a `restrictOnDelete` link), finalize fixes the payable total and locks the company's inputs, approval is derived from `finalized AND outstanding = 0`, and the correction machinery (reopen, P1, reconciliation) assumes payments exist only against locked lines. Moving the gate is a redesign of live payroll arithmetic.
2. *Generate is month-wide and run-level*: `payroll_runs.generated_at/by`, run `totals`/`warnings`, a month-wide Temporary-replacement pre-check, and **month-wide Generate resets every Reviewed company to Draft**. `payroll_run_sections` has **no** generation stamp. Per-company Generate therefore needs an additive migration (section stamps), a shared write service, per-section freshness / `REGENERATE_AFTER_REOPEN` checks, and a re-test of `RecoveryLedger::syncFromRun`. Calculation stays month-wide (cross-assignment rules) and only the selected company is written; approved/finalized companies (incl. the 112) are refused.
3. *Protected-record hash*: adding columns to `payroll_run_sections` changes the text of every row, so the whole-row md5 baseline (`52bd8dbc…`) is valid only before that migration; Release 1 must compare **column-level** hashes captured before the migration.
4. *Legacy employee-level payment code* (Employee "Record payment", per-line "Mark as paid", correction transactions, Financial Ledger, dashboard KPI) is inventoried in register 4.4; all tables are empty in production (`employee_payments` 0 rows).
5. *N-6* would in effect close the **reopen** route for a company that has an active payment, making the Overpaid/reconciliation feature largely unreachable; it needs a precise definition first.
6. *Reports*: the month status is already derived correctly; the gaps (label, month-list row, Payroll Rollup, dashboard KPI reading employee payments) are listed in register 4.6; "every company" needs a definition once companies can be generated one at a time.

**Outstanding risks and dependencies:** see register 6.2–6.3 (shared generation service and section stamps; `RecoveryLedger::syncFromRun` re-test; column-level hash after the stamp migration; month-wide Generate resetting Reviewed companies; report label while companies are not yet generated; Release 1 size versus the Q10 timing proposal; CI is SQLite-only; a push to `main` is a production deployment). Approved implementation constraints: register 6.6.

**Remaining owner decisions (v3.2):** choose an option for each of the seven PENDING OWNER DECISION items (Q6, Q7, Q8, Q10, Q11, N-5, N-6; N-6 has its own options in register section 10); answer the minor open points O-1 and O-2; and give the separate Stage C authorization. (Q9, N-1, N-3, N-2a, N-2b, N-4a and D-1…D-5, D-7, D-8 are decided.)


---

## 17. Update v3.2 (2026-11-05) — owner decisions recorded, pending items analysed

Authoritative text: `FAYADHOWR_HR_OWNER_DECISION_REGISTER.md` v3.2 (2.2A, 5, 9, 10, 11). Nothing was implemented; both documents remain untracked.

**ID distinction preserved.** *Register Q9* = **no new database trigger (APPROVED)**. *Stage A Q9* in section 7 of this audit (historical, not rewritten) = **Recovery Center scope, DEFERRED (X-1)**. They are different items; the mapping table in section 7 keeps them apart.

**Decisions recorded as APPROVED:** N-2a (company-only Generate, no month-wide Generate), N-2b (Temporary Replacement check is a warning only, Generate proceeds), N-4a (all assigned companies, generated or not), D-1 (Review and Finalize: warning only), D-2 (month-level bulk Review removed), D-3 (empty company → visible Draft section "Generated — Nothing Payable"), D-4 (Release 1 split into 1A and 1B), D-5 (dashboard payroll KPIs need `hr.dashboard.view` + `hr.payroll.view`; default month = previous month), D-8 (all four Temporary Replacement warning conditions). **D-7** (PostgreSQL CI job) is **DEFERRED**. **D-6:** the seven items below stay **PENDING OWNER DECISION**.

**Still PENDING OWNER DECISION (not approved):** Q6 leaver penalty, Q7 advance remainder, Q8 void/correction authority mapping, Q10 release timing, Q11 separated employees, N-5 payment reference, N-6 payroll changes after payment. For each, register section 9 gives the purpose, what changes, roles, implications, options, a technical recommendation, dependencies and what can proceed or must wait.

**N-6 finding (register section 10).** A Paid company can be reopened today by an HR Manager or Super Admin (reason ≥ 10 characters); its payment rows are kept untouched, the reopening is recorded (who, when, why, amount paid at reopen), the derived approval is withdrawn and restored on re-finalize, only that company is affected, and a total lower than the paid amount shows **Overpaid** until a reconciliation. The only path that rewrites a paid company's stored lines is *reopen → regenerate → refinalize*; Payroll Correction, adjustments, voids and reconciliation never rewrite lines. Options recorded: (1) block reopen when an active payment exists, (2) keep reopen with safeguards only, (3) Super Admin-only reopen for companies with an active payment while the HR Manager uses Payroll Correction (3b: request/approve workflow later). Recommended: **Option 3**; **the decision stays with the owner**.

**Conflicts with already-approved requirements (register 11.3):** N-6 Option 1 vs the 4 Nov capability; Q11 literal wording vs per-company Generate; N-5 vs C-5 overlap; D-2 vs the month-level reopen route (open point O-1); D-1/N-2b/D-8 supersede the older "Generate blocked while a Temporary is registered as a Replacement" rule (to be removed consistently from code, tests, messages and `HR_PAYROLL_RULES.md` in Stage C). No conflict between Q8 and R-1, or D-5 and C-7. The Temporary daily rate stays **Permanent monthly salary ÷ 30** (`TemporaryRate`).

**Release 1A / 1B (D-4):** the dependency matrix with Ready / Must-wait statuses is register section 11. Safe now: 1A-1…1A-11 and 1B-1, 1B-2, 1B-3, 1B-5, the unapplied part of 1B-4, and the design of 1B-8. Must wait: 1A-12 (N-5), 1B-6 and 1B-10 (Q8), 1B-7 (Q7), 1B-8 enabling (Q6), 1B-9 (Q11), 1B-11 (Q10), 1B-12 (N-6).

**Protected data and constraints (unchanged):** the 112 `approved` November sections, corrections #2–#13, existing payments, the 161 locked Somali translations, all worktrees (including the 12 dirty files in `faydhowr-app` and the 3 untracked files in `faydhowr-oct`) are untouched; no new database trigger; no employee-level manual salary payment workflow; Recovery Center Full Vision deferred.
