# HR & Marketing UI audit — remediation checklist (2026-11-05)

Master checklist using the original audit ids. Source of truth for decisions: `docs/FAYADHOWR_HR_OWNER_DECISION_REGISTER.md` sections 13 and 13.1. All work is **uncommitted** on branch `feat/hr-release1c-20261105` (HEAD `033199e`). No migration, no production access, no payroll operation. New wording is `proposed` only; the 161 + 86 + 2 approved messages and the 90 proposals are untouched (verified, see the final report).

Status words: FIXED · FIXED (partial) · RULED OUT (not a genuine defect) · DECLINED (UX, with reason) · DEFERRED (outside the approved scope, not implemented) · OWNER DECISION NEEDED (nothing guessed, nothing built).

## Owner decisions
| Id | Status | Where |
|---|---|---|
| UG-1 Compliance by button only | FIXED | GET read-only; `POST v1/admin/hr/compliance/scan` (hr.manage + HR Manager/Super Admin, throttled, locked); "Run scan" button |
| UG-2 Employee Detail completes all nine workflows | FIXED | End (incl. Stop-with-away), Separation, Change Salary, Assign, Edit Workplace, Replace, Return, Reopen on Employee Detail; End refuses a last day before the start (server `END_BEFORE_ASSIGNMENT_START` + UI) |
| UG-3 Back-dated Temporary cover needs a correction reason | FIXED | create goes through `ReplacementChangeGuard`; `GET temporary-replacements/create-preview`; `correction_reason` |
| UG-4 admins.manage / roles.manage Super-Admin only; Audit Log read-only for HR Manager | FIXED | `AdminPermission::isSuperAdminOnly()`; `EnsureAuditLogReader`; stored role-permission rows untouched |
| UG-5 Marketing commission, manual, existing endpoint | FIXED | "Log commission" dialog on the existing endpoint; no new rule |
| UG-6 Inactive refused while an Active assignment or an active Temporary cover (as replacement) exists | FIXED | `UpdateEmployeeStatusAction` → 422 `EMPLOYEE_HAS_OPEN_ASSIGNMENTS`; nothing closed automatically |
| UG-7 Penalty / Advance form defaults to the current month | FIXED | `RecordDeductionDialog` only; `defaultPayrollMonth` unchanged |

## 35 confirmed defects
| Id | Status | Id | Status |
|---|---|---|---|
| A-1 picker still says "required" | FIXED | C-1 money on superseded correction | FIXED (void kept, see report) |
| A-2 End modal confirm / correction | FIXED | C-2 deduction entry limits | FIXED |
| A-3 End before start | FIXED | C-3 browser date vs Mogadishu | FIXED |
| A-4 Separation confirm | FIXED | C-4 no-company Approve Paid | FIXED |
| A-5 correction reason (salary/assign) | FIXED (set-workplace part RULED OUT) | C-5 Mark paid on net $0 | FIXED |
| A-6 "Only a Super Admin" notice | FIXED | C-6 adjustment bound | FIXED |
| A-7 stale modal state / UTC dates | FIXED | D-1 compliance writes on open | FIXED (= UG-1) |
| A-8 Change salary on Replacement | FIXED | D-2 Data Issue edit reopens | FIXED |
| A-9 leave company choices by date | FIXED | D-3 audit entity filter | FIXED |
| B-1 same notice (4 dialogs) | FIXED | D-4 audit "export" action | FIXED |
| B-2 "÷ days in month" label | FIXED (new key) | D-5 roles checkboxes | FIXED |
| B-3 stale version after 409 | FIXED | D-6 voided inspections/complaints | FIXED |
| B-6 UTC date defaults | FIXED | D-7 Overdue/Due tile | FIXED |
| E-1 commission entries cannot be created | FIXED | D-8 dashboard card links | FIXED |
| E-2 commission list capped at 20 | FIXED | D-9 payroll month label | FIXED |
| E-3 team controls for members | FIXED | D-10 transient failure = logout | FIXED |
| E-4 generic validation toasts | FIXED | E-5 stale after writes | FIXED |
| E-6 follow-up status starts Pending | FIXED | | |

## 14 suspected findings
| Id | Outcome |
|---|---|
| A-10 status override to Inactive | CONFIRMED → FIXED (UG-6) |
| A-11 office staff closed-month reason | CONFIRMED → FIXED |
| B-4 End Coverage blocks on a conflict the server does not check | CONFIRMED → FIXED |
| B-5 back-dated cover in closed payroll | CONFIRMED (baseline run: HTTP 201, no reason) → FIXED (UG-3) |
| C S-1 void a workplace payment after reopen | CONFIRMED → FIXED |
| C S-2 Reopen offered for line-paid company | CONFIRMED → FIXED |
| D-11 role checks vs permission checks | CONFIRMED → FIXED (compliance nav/route by role; Close / Reopen company buttons by role); "reports-only role" sub-point RULED OUT |
| D-12 backup / branch actions not Super-Admin-only on the server | RULED OUT (policies and existing tests cover it) |
| D-13 Data Issues crumbs / links | CONFIRMED → FIXED |
| E-8 employee not tied to team | OWNER DECISION NEEDED |
| E-9 member's record without a team | FIXED (partial: UI default for one team); server rule OWNER DECISION NEEDED |
| E-10 PROJECT work date does not create a follow-up; create forms lack fields | OWNER DECISION NEEDED |
| E-12 no "Brought by" / per-employee rate | FIXED (safe subset: fields the requests already accept) |
| E-13 brought_by unguarded | FIXED (existing `MarketingScope` guard) |

## 21 UX recommendations
| Id | Outcome |
|---|---|
| A-12 rehire confirm | FIXED |
| A-13 error text / fallbacks | FIXED |
| A-14 cross-screen refresh | FIXED |
| A-15 no cancel-assignment UI | DECLINED (not one of the nine approved workflows) |
| B-7 cancel request, one click | FIXED |
| B-8 complete batch, no confirmation | FIXED |
| B-9 need-training picker cap | FIXED |
| B-10 candidates / raw errors | FIXED |
| B-11 stale status after cover | FIXED |
| B-12 history status / inverted range | FIXED |
| B-13 attendance narrow / locked month | FIXED (wrap); locked-month indicator DECLINED (needs a new endpoint) |
| B-14 cycle dialogs / double reason | FIXED (Return/Replace/Reopen in package A; Edit-cover double reason in package B) |
| U-1 blocking stale notice | FIXED |
| U-2 default month | REPLACED by UG-7 |
| U-3 cross-screen refresh after payroll actions | FIXED |
| U-4 company table on narrow screens | FIXED (partial: sticky first column) |
| U-5 loading / error / reversal / range | FIXED |
| D-14 UX bundle | FIXED (nav, reports hub, filters in URL); rest DECLINED as harmless or server-covered |
| E-7 follow-up feedback overwrite | FIXED (visibility); replace vs append OWNER DECISION NEEDED |
| E-11 reports period / employee filter | FIXED |
| E-14 UX bundle | FIXED (all tab, narrow layouts, confirmations, spinners); min date and extra quotation fields DECLINED |

## 6 deferred / out of scope (documented only)
1. Dead `recordWorkplacePayment` client code and its route (C).
2. `MarkPayrollItemPaidAction` `payment_date` has no future / lower bound (C).
3. `PayrollRunController::show` does not filter `voided_at` (latent) (C).
4. `ReopenPayrollRunRequest` still carries the removed month-level name (C).
5. Commission calculation formula (E; SRS §21, §39).
6. Additional marketing statuses beyond PENDING / QUOTATION / DONE / CANCELLED (E; SRS §39).

## Open owner questions (nothing was guessed)
E-8, E-9 (server rule), E-10, E-7 (replace vs append), adding a quotation → QUOTATION status, B "Q2" (a Temporary-typed person given a Permanent assignment), B "Q4" (cancel a Fulfilled workforce request), D "Q6" (translation editor overriding approved wording), C-1 (whether voiding a transaction on a superseded correction should also be refused), and the PUT end-date edit path having no end-before-start check beyond the request rule.

**Update 2026-11-06:** the Owner decided all of these (register section 14): E-7 append, E-8 team optional, E-9 frontend validation only, E-10 automatic follow-up, Q-STATUS approved, B-Q2 allowed, B-Q4 allowed, C-1 allowed when a clean undo, TRANSLATION-POLICY (HR / Marketing Manager propose, Super Admin approves), PUT-END-DATE approved. They are implemented locally and not committed, **except B-Q2**, which was stopped because it conflicts with the Temporary payroll rule (register 14.3).

## Employee Detail browser QA (2026-11-06) — Replace / Return / Reopen Assignment
Local scratch environment only (Vite panel on 127.0.0.1:5191, API on 127.0.0.1:8012, scratch SQLite database, synthetic company "QA Clinic (synthetic)" and synthetic people). No production access, no payroll operation.

| Dialog | Result |
|---|---|
| Replace | Opens on Employee Detail without leaving the page; validation (type, replacement date after the original's last day, Temporary-typed person warning); review step; submit; original assignment stays active, new Replacement assignment inherits the salary; English and Somali checked |
| Return | Opens on the Replacement's page; validation (salary check, return date after the replacement started); review step; submit; the same original assignment resumes, the Replacement's assignment ends the day before and is kept; English and Somali checked |
| Reopen Assignment | Opens from the Assignment History; reason of at least 5 characters; valid reopen restores the assignment and refreshes the page; English and Somali checked |

**Defect found and fixed during the QA (QA-1):** reopening the ended Replacement of a FINISHED cycle (its holder had returned) succeeded, leaving the Replacement and the returned holder active on the same position. Root cause: `ReopenEndedAssignmentAction` only refused when the assignment had a successor, not when it was the Replacement of a finished cycle (the definition `AbsenceCycles::finishedCycleReplacementIds()` already used everywhere else). Fix: the action now refuses with `ASSIGNMENT_COVER_FINISHED` (422), nothing changed. Regression test: `ReplacementCycleTest::test_a_replacement_whose_holder_has_returned_cannot_be_reopened_and_the_refusal_changes_nothing`. Repeated in the browser: the dialog shows the refusal inline and keeps the typed reason.

**Observations, not fixed (no reproducible defect, or an owner question):**
1. After "Replace" (type Replacement) the holder's own Employee Detail page still shows Replace / End assignment and does not say that a Replacement now covers the position; the Replacement's page does show "Replaces: ...". The company page hides Replace for an away holder. Offered as a UX follow-up, not changed.
2. The review step for type Replacement says "Original last day" although the original stays open until Return (audit item B-14 wording).
3. After pressing "Change" on the replacement picker, the previous search text stays in the box.
