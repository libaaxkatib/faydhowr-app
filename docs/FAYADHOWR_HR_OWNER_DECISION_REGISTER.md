# Fayadhowr HR — Owner Decision Register and Release 1 Plan

Version 3.2 — 2026-11-05 (owner decisions D-1…D-8 recorded; pending items analysed). Status: **documentation, planning and read-only code/production inspection only. No application code, tests, schema or production data changed; nothing committed, pushed, merged, migrated or deployed.** Both documents (`FAYADHOWR_HR_MASTER_AUDIT.md` and this one) are untracked files in the worktree `faydhowr-repl` (branch `feat/correction-recovery-20261104` = `origin/main` `0654492`). Stage B (implementation) is **not** authorized.

Legend for item status: **APPROVED** (owner decision, implement/obey), **EXPLICIT REQUIREMENT** (owner requirement, to be planned and built in Release 1), **PENDING OWNER DECISION** (formerly written "PROPOSED — AWAITING OWNER"; the original meaning is preserved, a working assumption may be written down for planning, but the item is **not approved, not confirmed, not accepted**; analysis in sections 9–10), **DEFERRED** (excluded from HR Release 1), **CONSTRAINT** (protection/implementation rule), **OPEN SUB-DECISION** (detail of an approved requirement that still needs an answer; any default shown is not approved), **INVESTIGATED** (technical finding kept as rationale). Decision IDs are stable across versions.

---

## 1. What changed in each version

* **v2:** employee-level Manual Payments and payment verification removed; the company-level payroll payment workflow (C-1…C-7) approved.
* **v3 (this):** proposed defaults written for Q6, Q7, Q8, Q10, Q11, N-5, N-6 (all still awaiting approval); N-1 and N-3 investigated; N-2 (per-company Generate) and N-4 (company-level reports) **converted to confirmed Release 1 requirements**; Release 1 scope, dependencies, risks, migration implications and tests updated; list of remaining decisions added. **C-1…C-7 are untouched.**
* **v3.1 (this reconciliation):** the owner's latest instructions recorded exactly: **N-1** confirmed (Review → Finalize → Approve Paid/Partial; Finalize mandatory before payment approval), **Q9** approved (no new database trigger), **N-3** confirmed (employee-level manual salary workflow must not be built), N-2 and N-4 kept as explicit Release 1 requirements, the 112 sections / protected data / 161 translations restated as constraints, Recovery Center Full Vision restated as deferred and excluded. **Q6, Q7, Q8, Q10, Q11, N-5, N-6 stay PENDING OWNER DECISION, unchanged.** A status index (1A) was added for traceability; no decision ID was renumbered or removed.
* **v3.2 (this update):** the owner's decisions **D-1, D-2, D-3, D-4, D-5, D-7, D-8** and the sub-decisions **N-2a, N-2b, N-4a** are recorded as APPROVED (2.2A). **D-6:** Q6, Q7, Q8, Q10, Q11, N-5 and N-6 stay **PENDING OWNER DECISION**; each now has a decision analysis (section 9) and **N-6** a separate detailed investigation with options (section 10); a Release 1A / 1B dependency matrix with safe-to-proceed and must-wait lists is in section 11. The historical distinction is kept: **register Q9 = no new database trigger (approved)**; **Stage A Q9 (in the Master Audit) = Recovery Center scope, deferred**. The status label "PROPOSED — AWAITING OWNER" was renamed **PENDING OWNER DECISION** everywhere (same meaning).

## 1A. Status index (every ID, one line)

| ID | Subject | Category |
|---|---|---|
| C-1 … C-7 | Company-level payment workflow | **APPROVED** |
| N-1 | Review → Finalize → Approve Paid/Partial; Finalize mandatory before payment approval | **APPROVED** (v3.1) |
| N-2 | Per-company Generate | **EXPLICIT REQUIREMENT** (Release 1) |
| N-4 | Company-level reports and dashboard | **EXPLICIT REQUIREMENT** (Release 1) |
| Q9 | No new database trigger | **APPROVED** (v3.1) |
| N-3 | Employee-level manual salary payment workflow must not be built | **APPROVED / CONSTRAINT** (v3.1) |
| S-1 | 112 `approved` November sections and all protected payroll data untouched | **CONSTRAINT** |
| S-2 | 161 Somali translations locked, approved verbatim | **CONSTRAINT** |
| X-1 | Recovery Center Full Vision | **DEFERRED** (excluded from Release 1) |
| P-1, A-1, A-2, F-1, R-1, U-1, G-1/N-0 | Penalty, advance, final-settlement, permission, audit, process rules | **APPROVED** (section 2.2) |
| **Q6, Q7, Q8, Q10, Q11, N-5, N-6** | see 3.1, analysis 9–10 | **PENDING OWNER DECISION** (not approved) |
| N-2a | Company-only Generate (no month-wide Generate action) | **APPROVED** (v3.2) |
| N-2b | Temporary Replacement check is warning only; Generate proceeds | **APPROVED** (v3.2) |
| N-4a | "All Companies" = every company assigned to the month, generated or not | **APPROVED** (v3.2) |
| D-1 | Review and Finalize: warning only | **APPROVED** (v3.2) |
| D-2 | Remove the month-level bulk Review | **APPROVED** (v3.2) |
| D-3 | Empty company gets a visible Draft section "Generated — Nothing Payable" | **APPROVED** (v3.2) |
| D-4 | Release 1 split into 1A and 1B | **APPROVED** (v3.2) |
| D-5 | Dashboard payroll KPIs need `hr.dashboard.view` + `hr.payroll.view`; default month = previous month | **APPROVED** (v3.2) |
| D-6 | The seven items above stay pending (this analysis) | **PROCESS** — pending |
| D-7 | PostgreSQL CI job | **DEFERRED** (v3.2) |
| D-8 | All four Temporary Replacement warning conditions | **APPROVED** (v3.2) |
| Stage A "Q9" (Master Audit, historical) | Recovery Center scope | **DEFERRED** (X-1) — a different item from register Q9 |

## 2. Decision register — APPROVED (do not reopen)

### 2.1 Company Payroll Payment Workflow (owner-approved 2026-11-05) — unchanged

| ID | Decision |
|---|---|
| C-1 | Month and company are selected first; every company is handled independently. |
| C-2 | HR opens each company, reviews and corrects employee changes and checks the payroll amounts. |
| C-3 | HR generates payroll **for that individual company** (explicit Release 1 requirement, see N-2). |
| C-4 | `Approve Paid` when the company paid the entire amount; `Approve Partial` when it paid part — an input shows for the actual amount paid. |
| C-5 | Several partial payments per company; each its own immutable record (amount, date, actor, reference/history); cumulative paid; remaining = total − cumulative; `Partially Paid` while cumulative < total, `Paid` when equal; **never above the obligation**; earlier records never overwritten or deleted; duplicate submissions/counting prevented; the existing workflow and its rules preserved where they already satisfy this. |
| C-6 | **No global month approval.** The month report is derived from the companies' statuses (Paid / Partially Paid / Unpaid, totals, cumulative payments, outstanding); "October — All Companies Paid" only when every company is fully paid. |
| C-7 | Dashboard/reporting: Company Payroll Total, Company Payments Received, Outstanding Company Payroll Balance, number of Paid / Partially Paid / Unpaid companies. **No employee Manual Payment or Verified Payment metrics.** No double counting. |

### 2.2 Other approved decisions

| ID | Decision | Release |
|---|---|---|
| P-1 | Penalties: up to 100 % of available net, never negative, max = amount bringing net to $0; excess carries to the next month; a leaver's remaining balance becomes debt (timing: Q6); duplicate prevention; correction + void with audit; closed periods only through an explicit correction workflow; no silent historical recalculation. | R1 |
| A-1 | Release 1 Advance: one-month correction and void, idempotency/duplicate protection, **zero-floor**, outstanding balance preserved (carry rule: Q7). | R1 |
| A-2 | Release 2 Advance: ≤ 24 installments, consecutive months, ≥ 1, editable before saving, post-save changes only via correction/reschedule, applied installments never rewritten; 60 % normal cap (base = net after penalties incl. approved adjustments, advance excluded; overpayment recovery later); leaver final month 100 % of the actual eligible final payable (leaver = the assignment at that company ends); remainder debt; USD. | R2 |
| F-1 | Final Settlement (R2): initiated at company-assignment end → calculation → preview → HR Manager confirms → payment registered separately → debt stays owed. | R2 |
| R-1 | Register Penalty/Advance = `hr.manage`; void/correct **unpaid** = HR Manager + Super Admin; reverse **paid/applied** and **waive** = Super Admin only; confirm Final Settlement = HR Manager; history/audit = existing RBAC; Super Admin never assignable; company-payment void keeps its existing rule. (Refinement: Q8.) | R1/R2 |
| U-1 | Audit event for every change to money, payroll, employees, permissions (before/after, actor, time, reason, operation reference). | R1 |
| N-1 | **Workflow order: Review → Finalize → Approve Paid / Approve Partial. Finalize is mandatory before payment approval.** The gate is not changed, bypassed or relaxed. (Technical rationale: 4.1.) | R1 (preserved) |
| Q9 | **No new database trigger** (none on `payroll_run_sections` or elsewhere). Protection is by new-table constraints, service-level guards, transactions, tests proving Release 1 never writes a locked section, and a before/after production hash (column-level, 6.3). | R1 constraint |
| N-3 | **The employee-level manual salary payment workflow must not be built** (no addition, no expansion). The existing legacy features are documented (4.4) and are **not deleted, hidden or modified** without a separate owner approval. | R1 constraint |
| S-1 | **Protected, untouched:** all 112 `approved` November sections, corrections #2–#13 (12 open, $878.00), nine November drafts, October 116 ($530) and 126 ($86.20) drafts, the 20 payment rows. | all |
| S-2 | **The 161 owner-approved Somali translations are LOCKED and APPROVED VERBATIM** — no wording change, no reinterpretation, no further review. Also: Part Two not re-imported; dirty `faydhowr-app` worktree + backup preserved; stale local `main` never pushed. | all |
| X-1 | **Recovery Center Full Vision is DEFERRED** to a separate future project and **excluded from HR Release 1**; existing Recovery Center and page-level corrections preserved. | deferred |
| G-1 / N-0 | Stages A–H separately approved; a push to `main` is a production deployment; no cost without prior notice. | process |

### 2.2A Latest owner decisions (v3.2) — APPROVED, apply exactly

| ID | Decision | Release |
|---|---|---|
| **N-2a** | **Company-only Generate.** Generate operates per company. **No month-wide Generate action** is introduced or kept; nothing generates all companies together silently; the company-specific action is explicit and understandable. | 1A |
| **N-2b** | **Temporary Replacement validation = warning only.** Before Generate the unresolved/incomplete Temporary Replacement situations of that company are checked; a clear warning names the company and the situation and explains what remains unresolved; **Generate may continue; it is never blocked; no separate confirmation is required because of the warning; the warning is never hidden.** (Recording the warning in the audit event is a *technical recommendation*, not an approved requirement.) | 1A |
| **N-4a** | **"All Companies" = every company assigned to the selected month, generated or not.** Generated and Not Generated are shown distinctly; no company is omitted for lack of generated payroll; totals stay accurate; a missing generation is never treated as a payment or as a completed payroll. | 1A |
| **D-1** | **Review and Finalize show the same Temporary Replacement warning and are not blocked by it.** | 1A |
| **D-2** | **The month-level bulk Review is removed**: Review is per company only. *(Not covered by D-2: the month-level reopen route `PATCH payroll-runs/{id}/reopen`; see open point O-1 in section 5.)* | 1A |
| **D-3** | A company with nothing payable receives a **visible Draft section labelled "Generated — Nothing Payable"** (it is no longer deleted/omitted). | 1A |
| **D-4** | **Release 1 is split into 1A and 1B** (definition in section 11: 1A = company workflow, warnings, reports/dashboard; 1B = penalties and one-month advances). | process |
| **D-5** | The dashboard payroll KPIs require **both** `hr.dashboard.view` **and** `hr.payroll.view` (enforced on the server), and the **default month is the previous month**. | 1A |
| **D-7** | **The PostgreSQL CI job is deferred.** PostgreSQL verification stays a manual gate (baseline: 3,260 tests, 34 failures outside HR/Unit; HR + Unit 0). | deferred |
| **D-8** | **All four Temporary Replacement warning conditions are approved:** (A) a Temporary worker registered as a Replacement and still classified Temporary (no payroll line is calculated for those days); (B) Temporary cover with workdays recorded but not verified in the month; (C) Temporary cover not yet cost-approved; (D) the calculator's existing Temporary-related warnings (covered days outside paid dates, days already unpaid leave, covered days with no payroll line, uncovered absence). | 1A |
| **D-6** | **Q6, Q7, Q8, Q10, Q11, N-5, N-6 remain PENDING OWNER DECISION**; the owner authorised them only as working assumptions for planning. They are analysed in sections 9 and 10. | process |

*Conflict resolved in favour of the newer decision:* the earlier approved rule that Generate is blocked until an unresolved Temporary-as-Replacement is corrected (`HR_PAYROLL_RULES.md`, `GeneratePayrollRunAction`, `OwnerDecisionsFinalTest`) is superseded by N-2b/D-1. The Temporary daily-rate formula is untouched: **Permanent monthly salary ÷ 30** (`TemporaryRate`).

### 2.3 Superseded / not applicable (removed from scope)
Employee-level Manual Payments (M-1, M-2, M-3), their purposes, registered→verified→paid lifecycle, verifier, void/correct/reverse, ledger, `employee_payment_voids`/manual-payment migration, line-level partial status from verified amounts, KPIs "Manual Payments"/"Verified Payments"; old Q1–Q5 (closed); equal-installments-only; one 60 % cap for everybody; HR-Manager "attestation"; Recovery Center full scope (deferred to X-1).

---

## 3. Status of every open item

### 3.1 Pending owner decisions — **PENDING OWNER DECISION** (Q6, Q7, Q8, Q10, Q11, N-5, N-6); proposals are **not approved, not confirmed, not accepted**
*(Q9 left this table on 2026-11-05: it is now an approved decision, see 2.2. The numbering of the remaining items is unchanged. Full decision analysis: **section 9**; N-6 has a separate investigation: **section 10**. Nothing in the right-hand column is approved.)*

| Item | Status | Original proposal / working assumption (planning only, **not approved**) |
|---|---|---|
| **Q6** Leaver penalty | **PENDING OWNER DECISION** | In Release 1 the outstanding penalty balance is **retained and shown visibly** (never lost). **Formal debt conversion is deferred to Release 2.** |
| **Q7** Advance remainder | **PENDING OWNER DECISION** | **Carry the remaining balance forward to the next month.** Deductions must **never reduce net pay below zero.** (No 60 % cap in Release 1; same carryover mechanism as penalties.) |
| **Q8** Void/correction authority | **PENDING OWNER DECISION** | HR Manager and Super Admin may correct or void **eligible unpaid transactions**. **Super Admin alone** may reverse applied/paid amounts or waive balances. **Closed-period corrections must use an audited correction workflow** (the existing `ClosedPayrollCorrection`, reason mandatory). |
| **Q10** Timing | **PENDING OWNER DECISION** | Release 1 is **deployed and verified in production before HR begins entering Penalties/Advances and before November payroll generation.** |
| **Q11** Separated employees | **PENDING OWNER DECISION** | **Allow** Penalty/Advance registration for an inactive or separated employee **when a valid payroll line exists for the relevant month**, subject to the approved rules. |
| **N-5** Payment reference | **PENDING OWNER DECISION** | Add a **dedicated, optional payment reference field** to company payments, **keeping the existing note field**. Reference is set at entry and then immutable (payments are immutable records). |
| **N-6** Payroll changes after payment | **PENDING OWNER DECISION** | **Prevent ordinary payroll edits after any company payment has been recorded;** any required change uses an **audited correction workflow.** (Impact analysis in 4.3.) |

### 3.2 Explicit requirements (N-2, N-4) — in Release 1 scope

| Item | Status | Requirement |
|---|---|---|
| **N-2** Per-company Generate | **EXPLICIT REQUIREMENT (owner)** | HR reviews/corrects an individual company's employee changes and **generates payroll for that company independently.** Current month-wide Generate is a **gap** to close in Release 1. Technical plan in 4.2. **Sub-decisions N-2a (company-only Generate) and N-2b (warning only) are APPROVED** (2.2A). |
| **N-4** Company-level reports | **EXPLICIT REQUIREMENT (owner)** | Company Payroll Total; Company Payments Received; Outstanding Company Payroll Balance; Paid / Partially Paid / Unpaid company counts; the monthly report aggregates individual company statuses; "October — All Companies Paid" **only when every company is fully paid**; **no employee manual-payment or verified-payment KPIs.** **Sub-decision N-4a is APPROVED** (every company assigned to the month, generated or not), with D-5 for the dashboard (2.2A). |

### 3.3 Confirmed decisions with their technical rationale (investigation kept for traceability)

| Item | Status | Summary (details in section 4) |
|---|---|---|
| **N-1** Review → Finalize → Approve Paid/Partial | **APPROVED (v3.1) — Finalize mandatory before payment approval** | Rationale: payroll lines are deleted and rebuilt on every Generate; finalize fixes the payable total and locks the company's inputs; approval is derived from `finalized AND outstanding = 0`. The investigation (4.1) is rationale, not an open question. |
| **N-3** Employee-level manual salary workflow | **APPROVED / CONSTRAINT (v3.1) — must not be built** | The legacy employee-level code is documented in 4.4 and left untouched; hiding/removing it needs a **separate** owner approval. |
| **Q9** No new database trigger | **APPROVED (v3.1)** | See 2.2 and 6.6. |

---

## 4. Technical investigation (read-only; `faydhowr-repl` @ `0654492`)

### 4.1 N-1 — why Finalize comes before payment, and the impact of changing it
**Current requirement:** a company payment is accepted only when that company's section is `finalized` (`RecordCompanyPaymentAction` and `RecordWorkplacePaymentAction` → `PAYROLL_RUN_NOT_FINALIZED`; `MarkPayrollItemPaidAction` the same; owner decision 2026-11-03/04, `HR_PAYROLL_RULES.md` §13 "Payments follow the company").
**Technical reasons found in code:**
1. `GeneratePayrollRunAction` **deletes and recreates every line** of a draft/reviewed company (`PayrollItemDeduction`/`PayrollItem` `delete()` then re-insert). A payment taken against a draft total would be measured against a total that the next Generate changes (silent Overpaid or negative outstanding).
2. A line paid one by one is linked to an `employee_payments` row (`payroll_item_id`, **`restrictOnDelete`**): paying a draft line would make the next Generate fail on the foreign key.
3. Finalizing is the moment the company's **inputs lock** (`PayrollPeriodLock`: attendance, penalty, advance, leave, Temporary workday for that company), so the payable cannot move under a payment.
4. **Approval is derived** as `finalized AND outstanding = 0` (`PayrollApproval`), and the recovery ledger flips scheduled → applied at the lock (`RecoveryLedger`).
5. The correction machinery (reopen, supersession P1, reconciliation of Overpaid, closed-period corrections) assumes payments only ever exist against locked lines.
**Impact of moving the gate earlier (e.g., allow payment at Reviewed or Draft):** every item above has to be redesigned — Generate would have to refuse or protect any company that has a payment (a new lock state with the same effect as finalize), reviewed-company payments would need a payable that cannot change, approval derivation and the Overpaid/reconciliation path change, the P1 reopen rule and `REGENERATE_AFTER_REOPEN` change, and the Excel payment list/`WorkplacePayroll.lines()` (net > 0 lines) would need a stable snapshot. **This is a large change to approved, live payroll arithmetic, and it is not being made.** **Owner decision (N-1, confirmed v3.1):** the sequence is **Review → Finalize → Approve Paid / Approve Partial**; Finalize is mandatory before payment approval; the gate stays as it is. The HR UI presents it as the company's normal sequence ("Generate → Review → Finalize → Approve Paid/Partial"). Per-company Generate (N-2) feeds this sequence and never skips it.

### 4.2 N-2 — per-company Generate: current state, gap and Release 1 plan
**Current:** `POST v1/admin/hr/payroll-runs` → `GeneratePayrollRunAction::handle($period)` is **month-wide**: one `payroll_runs` row per month; calculation runs **once for everyone** (`PayrollRunCalculator::calculate($period)`) so rules that look across a person's assignments hold; only draft/reviewed sections are rewritten (reviewed → draft); approved/finalized sections (including the 112) are **kept and merely warned about**. There is **no** per-company Generate and **no** `generated_at` on `payroll_run_sections` — the stamp lives on the run (`payroll_runs.generated_at/by`), as do `totals` and `warnings`. The pre-check `UnresolvedTemporaryReplacements` is month-wide. A company with no section yet cannot be selected for generation individually.
**Gap:** (a) no way to generate a single company; (b) month-wide Generate **resets every Reviewed company to Draft** — dangerous in a company-by-company workflow because it silently discards HR's review of other companies; (c) freshness/regenerate checks (`CorrectionSupersession::assertRegenerated`, the proposed freshness guard) compare against the **run's** `generated_at`, which is ambiguous once companies are generated at different times.
**Release 1 design (technical; owner decisions N-2a, N-2b, D-1, D-2, D-3, D-8 applied — see 2.2A; the text below keeps the Stage A/B analysis and is read together with them):**
* **Calculation stays month-wide and is written for one company** (approach A): run `PayrollRunCalculator::calculate($period)` once (≈36 queries after the N+1 fix), keep every cross-assignment rule, and write only the selected company's lines/section. Calculating only the company's employees (approach B) is **not** recommended: position chains, absence-once-per-company, recovery spread and cent rounding are position/person-wide.
* New action (e.g. `GenerateCompanyPayrollAction`) sharing the writing code with `GeneratePayrollRunAction` (refactor into one service; no duplicated logic): lock run → refuse when that company's section is approved/finalized (including the 112 legacy `approved`; message points to a Payroll Correction) → delete/recreate only that section's lines and reset only its pending adjustments → create the section if missing → recompute the run's stored totals (`storedTotals`) and merge only that company's warnings → run-level `RecoveryLedger::syncFromRun` (it already preserves locked sections; to be re-tested for recovery spread across companies) → `PayrollSections::syncRun` → audit event naming the company.
* **API:** one new route, `hr.payroll.manage` (same as Generate), idempotent. **UI:** a "Generate" action on each company row of `CompanySectionsPanel` plus a way to start a company that has no section yet (company picker limited to companies with assignments in that month).
* **Migration (additive):** `payroll_run_sections.generated_at` and `generated_by` (nullable; **no backfill**; readers fall back to `payroll_runs.generated_at` when null). Month-wide Generate also sets them for every section it writes. `REGENERATE_AFTER_REOPEN` and the Release 1 freshness guard then compare per section.
* The 112 `approved` sections, corrections #2–#13, payments and all other companies are **never written** by a per-company Generate; this is proven by tests and by the before/after hash.
* **Owner decisions applied (v3.2):** **N-2a** — the month-wide Generate (`POST v1/admin/hr/payroll-runs`, the `PayrollRunsPage` button and its 68 test call-sites) is **retired**, not kept behind a confirmation; **D-2** — the month-level bulk Review (`PATCH payroll-runs/{id}/review`, its UI button) is removed too (Review stays per company); **N-2b/D-1/D-8** — the pre-check no longer throws: the four approved Temporary Replacement conditions are computed **for the company**, shown before Generate and at Review/Finalize, stored with the section (`generation_warnings`) and never block; **D-3** — a company with no payable lines keeps a visible empty Draft section labelled "Generated — Nothing Payable" instead of being deleted. The locked label `action.generateDraft` ("Generate / refresh draft") is reused verbatim on each company row; any new wording is a *proposed* message.

### 4.3 N-6 — "no ordinary payroll edits after a company payment": current paths and impact (**N-6 is PENDING OWNER DECISION**; the full investigation and options are in section 10)
**What can change a company's payroll after a payment today:** (1) **Reopen** of a finalized company (`ReopenFinalizedPayrollSectionAction`, HR Manager/Super Admin, reason ≥ 10; payments are **kept**; refused only if lines were marked paid one by one) → regenerate → refinalize → possible **Overpaid** → reconciliation; (2) closed-period **corrections** (assignment/salary/replacement/separation changes recorded as `PayrollCorrection`, lines never rewritten); (3) payroll **adjustments** on finalized lines (`hr.payroll.approve`); (4) void of a payment. Generate cannot touch a finalized company.
**What the proposal would change:** in effect the **reopen** gate: a company with any active (non-voided) payment could no longer be reopened by the ordinary route; changes would go through corrections (underpayment/overpayment records) or an explicitly audited path.
**Impact:** the approved 4 Nov correction release deliberately lets HR Manager/Super Admin reopen a paid company and reconcile an excess; N-6 would make that route unavailable (the **Overpaid/reconciliation** feature becomes almost unreachable), and the reset technique used on 4 Nov (void payments, then reopen) would remain possible only through the Super Admin void. It also needs a precise definition of "ordinary payroll edit" and of the "audited correction workflow" that replaces reopen. **Awaiting the owner's definition before any design.**

### 4.4 N-3 — legacy employee-level payment features (inventory and risks; nothing changed)
| Feature | Where | Behaviour | Production use |
|---|---|---|---|
| Employee page **Payments card + "Record payment"** | `EmployeeDetailPage.tsx` (card ~L1004, `RecordPaymentModal`), `hrApi` `POST admin/hr/employees/{id}/payments`, `RecordEmployeePaymentAction` | create-only `employee_payments` row (date, amount, USD-only not enforced, notes); no purpose, no void/edit, no idempotency; needs `hr.manage` only | **0 rows** |
| Per-line **"Mark as paid" / "Void payment"** | `PayrollRunsPage.tsx` (~L736/739), `MarkPayrollItemPaidAction`, `VoidPayrollPaymentAction`, routes `payroll-items/{id}/pay` and `/void-payment` | writes an employee payment linked to the line; counted in the company's `paid` (`WorkplacePayroll.lines()`); void by HR Manager/Super Admin | none (company payments are used) |
| **Correction transactions** (underpayment paid, debt repayment, reversal) | `RecordCorrectionTransactionAction`, `VoidCorrectionTransactionAction` | write employee payments tied to a correction; Super Admin for underpayments | 0 correction transactions so far |
| **Financial Ledger** page and dashboard KPI | `FinancialLedgerPage.tsx`, `ListFinancialLedgerAction`, `GetHrDashboardAction.php:130` (`month_payments_total`) | read `employee_payments` only; **omit company payments** | shows $0 for company payments (MPA-07) |
**Risks:** the card and endpoint could be used by any `hr.manage` user to type a "salary payment" that changes nothing in payroll and shows up as paid money in the ledger/dashboard (MPA-06, MPA-15); per-line Mark-as-paid and company payments interact (checked against each other: `WORKPLACE_PAYMENT_COVERS_LINE`) but give HR two ways to pay the same salary; the correction-transaction path is a **separate, approved** employee payment of money owed after a correction and must not be confused with a manual salary payment. **Owner decision (N-3, confirmed v3.1):** the employee-level manual salary payment workflow **must not be built**; Release 1 neither adds nor expands any of it, and does not delete, hide or modify the legacy code above. Removal/hiding needs a separate approval.

### 4.5 N-5 — payment reference (if approved)
Add `reference` (nullable, ≤ 100 chars) to `payroll_workplace_payments` (additive; the 20 existing rows stay null), request/resource/audit-metadata/UI field next to the note, shown in the history; immutable once recorded (a mistake = void + new payment, as today). No change to totals or approval logic.

### 4.6 N-4 — company-level reports: current state and Release 1 work
Already derived correctly on the payroll page (`PayrollApproval::month()` → `approval` on the run resource → month card: counts Paid/Unpaid/Partially paid/not finalized, total/paid/outstanding, "Month approved" only when every section is Paid). Release 1 work (read-only views over existing data, no new calculation of money): (1) month card label "October — All Companies Paid" only when every company is fully paid, otherwise the counts; (2) **monthly list row** shows Paid/Partially Paid/Unpaid counts, total, received, outstanding (today it shows finalization progress); (3) **Payroll Rollup report** adds company payment status/received/outstanding (`GetPayrollRollupAction` has finalization counts only); (4) **HR dashboard**: Company Payroll Total, Company Payments Received, Outstanding, counts; the employee-payment KPI `month_payments_total` is **not** used; (5) all figures from `PayrollApproval`/`WorkplacePayroll` so nothing is counted twice and locked sections are only read. **N-4a (APPROVED v3.2):** with per-company Generate a month may have companies that have **no section yet**; "every company" = **every company assigned to the selected month, whether generated or not** (non-cancelled assignment overlapping the month, via `client_company_id` or the work location's company, plus any company that already has a section). Not-generated companies are listed and labelled **Not Generated**, their payroll total is shown as not available ("—"), never as 0 paid, and they keep "October — All Companies Paid" from appearing. Production sizing (read-only, 2026-11-05): October 123 assigned vs 122 sections (1 not generated), November 123 vs 121 (2 not generated); 100 companies + 28 guri. **D-5:** dashboard payroll KPIs need `hr.dashboard.view` **and** `hr.payroll.view`; default month = previous month (the other dashboard KPIs keep their own period).

---

## 5. Decisions still required from the owner
*(Q9, N-1 and N-3 are **no longer open**: approved/confirmed 2026-11-05, see 2.2.)*
*(N-2a, N-2b, N-4a and D-1…D-5, D-7, D-8 were decided on 2026-11-05 and are recorded in 2.2A.)*
1. **PENDING OWNER DECISION — Q6, Q7, Q8, Q10, Q11, N-5, N-6:** choose an option for each (analysis and options: section 9; N-6: section 10). None is approved today and none may be implemented as if approved.
2. **Clarifications inside the pending items** (asked in section 9/10): Q11 — is the test "a payroll line exists" or "an assignment overlapped that month at that company"; N-6 — which option, and whether an HR Manager request / Super Admin approval step is wanted; Q7 — automatic deduction of the carried advance next month, or visible hold until Release 2.
3. **O-1 (new, minor, not blocking):** D-2 removes the month-level bulk **Review**; the month-level **reopen** route (`PATCH payroll-runs/{id}/reopen`, reviewed → draft for every company) is also a bulk action and is not covered. Leave it unchanged in Release 1A (default) or remove it as well?
4. **O-2 (new, minor, not blocking):** the HR dashboard keeps its other KPIs on the current calendar month while the new payroll KPIs default to the previous month (D-5): confirm that two periods on one page is acceptable (the payroll block gets its own visible month selector).
5. The **Stage C** authorization itself (separate, explicit). Nothing in this register starts Stage C.

## 6. Release 1 scope, dependencies, risks, migrations, tests

### 6.1 Scope (reflects per-company Generate and report corrections)
| # | Item | Source | Status |
|---|---|---|---|
| 1 | **Per-company Generate** (4.2) incl. section generation stamps | N-2 | to build |
| 2 | Company payment workflow preserved; reference field if N-5 approved | C-1…C-7, N-5 | exists; optional small addition |
| 3 | **Company-level reports/dashboard** (4.6) | N-4, C-6, C-7 | to build |
| 4 | Penalties: company + month, up to $0, carryover, correction, void, idempotency, duplicate guard, freshness guard, validation | P-1 | to build |
| 5 | One-month Advance correction/void, zero-floor, carry (Q7), idempotency | A-1 | to build |
| 6 | Audit before/after for items 1, 4, 5 | U-1 | to build |
| 7 | PostgreSQL QA, regression, protected-record checks, production verification | process | — |
Not in Release 1: employee Manual Payments (removed), changes to N-3 legacy code, the Finalize gate (N-1), **any month-wide Generate (N-2a) or month-level bulk Review (D-2)**, Release 2 items, Recovery Center. Release 1 is delivered as **1A** (rows 1, 3 and the company workflow) and **1B** (rows 4, 5 and their foundation) per D-4 — dependency matrix in section 11.

### 6.2 Dependencies
Foundation (idempotency helper, audit shape, authority methods, validation) → **section stamps migration + shared generation service** → per-company Generate → freshness guard (per section) and `REGENERATE_AFTER_REOPEN` rewiring → penalties/advances guards → reports/dashboard (read-only, can proceed in parallel after the status helpers are fixed) → UI. N-2a/N-2b/N-4a/D-1…D-5/D-8 are **decided** and no longer gate anything; the **still-pending** Q6/Q7/Q8/Q11 (and N-5, N-6, Q10) gate only specific penalty/advance rules, the payment reference field, the reopen rule and the release timing (section 11 shows exactly which tasks).

### 6.3 Risks
1. **Generate refactor touches approved payroll code** (`GeneratePayrollRunAction`, `RecoveryLedger::syncFromRun`, run totals/warnings). Mitigation: one shared write path, golden comparison on a production copy (month-wide Generate output must stay byte-identical for months with no per-company use), tests below.
2. **Hash method for protected records must change.** Adding columns to `payroll_run_sections` changes the text of every row, so the whole-row md5 used so far (`md5(s::text)`) would differ after the migration even though no data changed. For Release 1, capture a **column-level** baseline (id, client_company_id, status, totals, reviewed/approved/finalized stamps and ids, item lines) **before** the migration and compare the same columns after.
3. ~~Month-wide Generate can reset reviewed companies~~ — removed by N-2a/D-2; residual risk: a per-company Generate of a **Reviewed** company resets that company's review to draft (the UI confirms it; a stale-status guard prevents a silent reset).
4. ~~Report label could mislead~~ — resolved by N-4a (not-generated companies are included and labelled).
5. Release 1 is larger; the pending Q10 (deploy before HR starts / before November Generate) puts a time constraint on it; the D-4 split (1A/1B) is the mitigation.
6. **D-7:** the PostgreSQL CI job is deferred, so PostgreSQL verification stays a manual gate (baseline: 3,260 tests, 34 failures outside HR/Unit); a PostgreSQL-only defect would not be caught by CI.
7. **Removing month-wide Generate and bulk Review** changes 68 test call-sites in 42 files and one UI page; the API/panel deploy mismatch window (~1 min) must be handled by deploying at a quiet time.
8. **N-2b/D-1:** a payroll generated or finalized with an unresolved Temporary-as-Replacement has **no line for those days**; the warning is the only protection, so it must be impossible to miss and persisted on the section.
9. A push to `main` is a production deployment (migrations run automatically).

### 6.4 Migration implications (all additive, nullable, no data rewrite; each to be explained, rehearsed on a disposable PostgreSQL copy and approved before any production run)
1. Record-integrity columns on `employee_penalties` and `employee_advances` (idempotency key unique, offset/supersede links, entry kind, recorder role, void fields).
2. `payroll_deduction_carryovers` (penalties and advance remainder).
3. `payroll_run_sections.generated_at`, `generated_by` (**no backfill**; fallback to the run's stamp).
4. *(only if N-5 is approved)* `payroll_workplace_payments.reference`.
No CHECK-constraint change; no change to `employee_payments`; rollback = ignore the new columns/table. Protected rows: the 121 existing sections get null stamps; no existing value is rewritten.

### 6.5 Test requirements
* **Per-company Generate:** writes only the target company; other draft companies byte-identical; a **reviewed** company stays reviewed; approved/finalized/legacy-`approved` companies refused; a company without a section is created; an employee working for two companies gets correct lines in each; absence charged once, recovery spread, position cents rounding unchanged versus month-wide; adjustments reset only for that company's lines; run totals/warnings correct; per-section stamps; `REGENERATE_AFTER_REOPEN` per section; two concurrent Generates on two companies; month-wide Generate output unchanged (golden); idempotent replay.
* **Company payments and reports:** partial → partial → paid sequence; cumulative/remaining; cannot exceed; duplicate key; void withdraws status; "All Companies Paid" only when every company is Paid (and per N-4a); counts correct; dashboard figures; no employee-payment metric; reference field if approved.
* **Penalties/Advances:** to-$0 deduction, never negative, carry over several months, correction/void chains, closed-period via correction workflow, duplicates, freshness guard, role matrix (Q8), separated employee with a line (Q11), audit before/after.
* **Protection:** the 112, corrections #2–#13, payments, nine drafts, October drafts unchanged (column-level hash) after every new action; 161 hash.
* **Environments:** SQLite, **PostgreSQL 18 full run** (HR + Unit must stay at 0 failures; no new failure beyond the 34 baseline), two-connection race tests, browser QA in Somali and English, production-copy rehearsal, read-only production verification.

### 6.6 Approved implementation constraints (apply to every stage)
1. **N-1:** Review → Finalize → Approve Paid/Partial; Finalize stays mandatory before payment approval. No change to that gate.
2. **Q9:** no new database trigger. Protection = new-table constraints (unique idempotency keys, FKs, CHECKs on *new* objects only), transactions and row locks, service guards, tests proving Release 1 never writes a locked section, and a column-level before/after hash (6.3).
3. **S-1:** the 112 `approved` November sections, corrections #2–#13, the 20 payments, the nine November drafts and October 116/126 drafts are never reopened, regenerated, recalculated, voided or overwritten by Release 1 code or by deployment/verification steps.
4. **S-2:** the 161 Somali translations stay byte-identical (baseline SHA-256 `ad500a9affadf2bee600acd67b4827c579bbf7b9a2209e54dde33b5ecdf2cecc`, `lockedApproved.test.ts`); any new Release 1 text is added as *proposed* messages only.
5. **N-3:** no employee-level manual salary payment workflow; legacy code untouched.
6. **X-1:** nothing from the Recovery Center Full Vision (no new Recovery APIs, tables, UI, permissions); existing Recovery Center and page-level corrections preserved; Release 1 has no dependency on it.
7. **G-1/N-0:** no code, commit, push, merge, migration or deployment without the separate stage approval; no cost without prior notice.
8. The seven **PENDING OWNER DECISION** items (Q6, Q7, Q8, Q10, Q11, N-5, N-6) are **not** constraints and must not be implemented as if approved.
9. **N-2a/D-2:** no month-wide Generate and no month-level bulk Review exist after Release 1A. **N-2b/D-1/D-8:** the Temporary Replacement check is a warning only (four conditions), never a block and never hidden. **N-4a/D-5:** reports include every assigned company; dashboard payroll KPIs need `hr.dashboard.view` + `hr.payroll.view`; default month = previous month. **D-3:** an empty company keeps a visible "Generated — Nothing Payable" Draft section. **D-7:** no PostgreSQL CI job. **Temporary rate:** Permanent monthly salary ÷ 30, unchanged.

## 7. Existing company payment workflow — code inspection (unchanged from v2; read-only)
Approve Paid / Approve Partial: `RecordCompanyPaymentAction` → `RecordWorkplacePaymentAction`, route `POST v1/admin/hr/payroll-run-sections/{section}/payments`, UI `CompanyPaymentDialog.tsx` (partial amount field). Storage: `payroll_workplace_payments` (append-only; void = flag + reason; `note`, idempotency key unique per run). Status: `WorkplacePayroll` + `PayrollApproval::sections()`; month: `PayrollApproval::month()`. Multiple partial payments: **supported and tested** (`CompanyPaymentApprovalTest`, `WorkplacePaymentsTest`), **not yet used in production** (13 active October payments, one per company). Cumulative cannot exceed (`AMOUNT_EXCEEDS_OUTSTANDING`); duplicates prevented (idempotency key, run-row lock); an excess can only arise when payroll is corrected after payment and is shown as Overpaid until reconciled.

## 8. Protected-record baseline (read-only, 2026-11-05 06:13 UTC; re-verify before any production step)
Live commit `0654492`; 224/224 migrations; 112 `approved` November sections (whole-row hash `52bd8dbc63d0cd2d93396e2473c54693` — valid only **before** the section-stamp migration, see 6.3); corrections #2–#13 core md5 `99bedce205096a17c78ba53d77be3262` (12 open, $878.00); payments 20 rows, 7 voided (#5–#11), 289,497 cents; nine November sections draft; October 116 ($530) and 126 ($86.20) draft; `employee_payments`, `employee_penalties`, `employee_advances` 0 rows.

---

## 9. Decision analysis — the seven items that are PENDING OWNER DECISION (D-6)

**Status of every item in this section: PENDING OWNER DECISION.** The "working assumption" is the text the owner authorised for Stage B planning only; it is **not approved**. "Safe before approval" lists work that does not depend on the answer; "Must wait" lists work that does. IDs and original meanings are preserved.

### 9.1 Q6 — Leaver penalty
* **Original meaning / working assumption:** in Release 1 the outstanding penalty balance of a leaver is **retained and shown visibly**; formal debt conversion is deferred to Release 2.
* **Business purpose:** P-1 (approved) says the part of a penalty that payroll cannot take becomes debt when the employee leaves. Q6 decides **when** that balance becomes a formal debt record (Release 1 or Release 2), so it is never lost and never silently forgiven. "Leaver" follows the approved A-2 definition: the employee's assignment at that company ends.
* **What it changes:** for a penalty carry-over of an employee whose assignment at that company has ended, the balance stays `open`, marked as a leaver balance, and appears in a read-only list of outstanding penalty balances. No debt-ledger row, no repayment channel, no waive action in Release 1.
* **Users/roles:** registering = `hr.manage`; viewing the balances = `hr.view` + `hr.payroll.view` (amounts are salary data); correct/void = HR Manager + Super Admin (R-1); waive = Super Admin only (Release 2).
* **Implications:** *Financial* — the balance is recorded but cannot be collected through payroll once the person has no line; collection tooling arrives with the debt ledger and Final Settlement (R2). *Operational* — HR must watch the list until R2. *Security* — balances identify people and money: restrict the list. *Data integrity* — the carry-over must never apply twice (unique per source/run, applied at Finalize, unapplied on reopen) and must not convert itself into pay or into debt without an explicit step.
* **Options:** **A** visible carry-over only in R1, formal debt in R2 *(working assumption)*; **B** formal debt in R1 (needs a PostgreSQL CHECK widening on the live approved `payroll_corrections` kinds, ledger integration and a Super Admin waive path); **C** forgive at separation (contradicts approved P-1).
* **Technical recommendation:** **A.** It loses nothing, needs no change to the approved correction ledger, and the formal debt belongs with the R2 Final Settlement design (F-1). B should be chosen only if the owner needs collectable debts before R2.
* **Dependencies:** P-1, A-2, F-1, R-1, Q7 (same carry-over table), Q11 (leavers' records).
* **Safe before approval:** the carry-over table and the carry logic for **active** employees (approved in P-1), the derived leaver marker, the read-only balances list. **Must wait:** any conversion to debt, any forgive/waive action, any collection from later pay.

### 9.2 Q7 — Advance remainder
* **Original meaning / working assumption:** carry the remaining balance forward to the next month; deductions must **never reduce net pay below zero**.
* **Business purpose:** A-1 (approved) already requires the zero-floor and "outstanding balance preserved" for the one-month advance. Q7 decides what happens to the preserved remainder: is it **deducted automatically next month** (up to the floor) or only **held outstanding**?
* **What it changes:** with the working assumption, an advance larger than the month's net-after-penalties deducts up to that net and the rest becomes a scheduled deduction in the following month(s) until cleared. No 60 % cap (Release 2).
* **Users/roles:** advance registered by `hr.manage`; void/correct by HR Manager + Super Admin; reverse of an applied deduction and waive = Super Admin (R-1).
* **Implications:** *Financial* — an employee can receive $0 for consecutive months (today a single advance can already take the whole month); the protective 60 % cap arrives only in R2 — the owner should accept that. *Operational* — the payroll line must show "advance carried forward" clearly. *Security* — none beyond existing roles. *Data integrity* — carry applied at Finalize, unapplied on reopen, unique per (advance, run); a leaver's unfinished carry stays visible (Q6).
* **Options:** **A** carry and deduct next month up to the zero-floor *(working assumption)*; **B** hold outstanding with **no** automatic deduction until Release 2 (HR must act manually); **C** carry with an interim cap (activates an R2 rule early — conflicts with "do not implement R2 rules in R1"); **D** refuse an advance above the month's net (changes approved A-1).
* **Technical recommendation:** **A**, plus a visible warning at registration when the amount exceeds the expected net; **B** is the fallback if the owner does not want automatic multi-month deductions before the R2 caps exist.
* **Dependencies:** A-1, A-2 (R2 replaces the mechanism), P-1, Q6, Q8 (void of a carried amount), Q11.
* **Safe before approval:** the **zero-floor** and the "not deducted ⇒ stays outstanding on the advance" status (both already approved in A-1), idempotency, duplicate guard, one-month correct/void. **Must wait:** automatic deduction of the carried remainder in the next month.

### 9.3 Q8 — Void/correction authority
* **Original meaning / working assumption:** HR Manager and Super Admin may correct or void **eligible unpaid transactions**; **Super Admin alone** may reverse applied/paid amounts or waive balances; **closed-period corrections must use an audited correction workflow**.
* **Business purpose:** R-1 (**approved, unchanged**) fixes the principle. Q8 is the pending **precise mapping**: which states of a penalty/advance count as "eligible unpaid" and which as "applied/paid", and which route a change to a closed month takes.
* **What it changes (proposed mapping):** *Registered / Scheduled* (not yet in a finalized payroll) → void/correct by HR Manager or Super Admin; *Applied* (deducted in a finalized payroll) → a change is a **Payroll Correction** (`ClosedPayrollCorrection`, reason mandatory, HR Manager or Super Admin may record it; an amount owed to the employee is paid only by a Super Admin as today); *reversing an applied deduction* and *waiving a balance* → Super Admin only.
* **Users/roles:** plain `hr.manage` users: create only; HR Manager and Super Admin: void/correct and record corrections; Super Admin: reverse applied, waive. New server-side methods in `CorrectionAuthority`; no UI-only checks.
* **Implications:** *Financial* — reversing an applied deduction creates money owed to the employee, payable only through the existing Super Admin transaction. *Operational* — HR Manager can fix mistakes before finalization without waiting for the Super Admin. *Security* — backend enforcement and a role-matrix test; Super Admin never assignable. *Data integrity* — offset/replacement rows, never delete or overwrite; idempotent; stale-version check on every correction.
* **Options:** **A** the mapping above *(working assumption)*; **B** Super Admin only for every void/correct (stricter, bottleneck, narrower than approved R-1); **C** HR Manager may also reverse applied deductions with a Super Admin co-approval (wider than R-1, needs an approval workflow).
* **Technical recommendation:** **A**: it matches R-1 and the authority pattern already in `CorrectionAuthority`.
* **Dependencies:** R-1, P-1, A-1, U-1, the closed-period correction workflow, Q6/Q7 (voids of carried amounts).
* **Safe before approval:** void/correct of **not-yet-applied** penalties and one-month advances by HR Manager + Super Admin (covered by approved R-1), audit shape, idempotency, version checks. **Must wait:** the exact "applied" state definitions, the reverse-applied route and anything that waives a balance.

### 9.4 Q10 — Release 1 timing
* **Original meaning / working assumption:** Release 1 is **deployed and verified in production before HR begins entering Penalties/Advances and before November payroll generation**.
* **Business purpose:** the three money modules are create-only today (no duplicate guard, a deduction entered after Generate is lost at Finalize). Q10 makes sure HR never starts using them before the protections exist, and that November is generated with the per-company workflow.
* **What it changes:** release gates, not code. With the approved split (D-4): **1A** live and verified before the post-30-November Generate; **1B** live and verified before the first penalty/advance is entered. Until 1B, HR is instructed not to use "Record penalty/advance".
* **Users/roles:** owner (gate approvals), HR Manager and HR users (operational instruction), release manager.
* **Implications:** *Financial* — if HR enters penalties/advances early, wrong or duplicated deductions are possible (MPA-04/05). *Operational* — a written interim instruction to HR; the Record buttons are **not** hidden (that would be a production UI change). *Schedule* — today is 5 Nov, November ends on 30 Nov, and every stage needs its own approval; the plan cannot promise a date. *Security* — none. *Data integrity* — rows created earlier stay valid (new columns are nullable).
* **Options:** **A** 1A before November Generate, 1B before any entry *(working assumption + D-4)*; **B** everything together before November Generate (one larger release, higher risk); **C** after November Generate (November would be generated with the old workflow, contradicting N-2/N-2a).
* **Technical recommendation:** **A** with a read-only production check of the three tables (0 rows today) as evidence before 1B is deployed and again before HR is told to start.
* **Dependencies:** D-4, Stage C–H approvals, completion of N-2/N-4 (1A) and of the foundation (1B).
* **Safe before approval:** all planning, sequencing and read-only monitoring. **Must wait:** any statement to HR that they may start, and any date commitment.

### 9.5 Q11 — Separated employees
* **Original meaning / working assumption:** allow Penalty/Advance registration for an **inactive or separated employee when a valid payroll line exists for the relevant month**, subject to the approved rules.
* **Business purpose:** today `EMPLOYEE_NOT_ACTIVE` blocks any record for an inactive employee even though a leaver still has a final-pay line (audit MPA-12), so a last-month deduction cannot be recorded or fixed.
* **What it changes:** the Active-employee rule becomes "Active, **or** Inactive/separated with a payroll line for that month and company", with company ownership resolved as today and closed months routed through the correction workflow.
* **Users/roles:** same `hr.manage` to register; HR Manager/Super Admin to correct.
* **Implications:** *Financial* — deductions can reach a leaver's final pay (P-1 limits them to net $0; the leaver 100 % advance rule is Release 2). *Operational* — under per-company Generate a line may **not exist yet** when HR records the deduction; requiring an existing line forces "Generate first", which then conflicts with the freshness guard (record after Generate ⇒ regenerate). *Security* — risk of erroneous or retaliatory deductions on ex-employees; mitigated by mandatory reason, audit and the line/assignment requirement. *Data integrity* — the record must carry the company of that line.
* **Options:** **A** literal: an existing, generated payroll line is required *(working assumption)*; **B** eligibility from the **assignment**: the employee's assignment at that company overlapped the month (the line may not exist yet); **C** keep refusing and treat leavers only through the Release 2 Final Settlement.
* **Technical recommendation:** **B** — "a line exists" is a transient state under per-company Generate, while "the assignment overlapped that month at that company" is stable and is exactly what produces the line. It deviates from the literal wording, so the owner must choose between A and B.
* **Dependencies:** A-2 (leaver definition), Q6, P-1, N-2/N-2a (Generate flow), the freshness guard.
* **Safe before approval:** the validation helper design and its tests. **Must wait:** enabling the relaxed rule.

### 9.6 N-5 — Payment reference
* **Original meaning / working assumption:** add a **dedicated payment reference field** to company payments while **retaining the existing note field**.
* **Business purpose:** C-5 (approved) requires each payment to keep "amount, date, actor and reference/history". Today the only free text is `note` (500 characters); a dedicated field makes receipts, transfers or cheque numbers findable.
* **What it changes:** a nullable `reference` (≤ 100 characters) on `payroll_workplace_payments`, entered in the Approve Paid / Approve Partial dialog, shown in the payment history and in the audit metadata; set at entry and immutable (a mistake is voided and re-recorded, as today). The 20 existing rows stay empty. No change to totals or approval.
* **Users/roles:** entered by `hr.payroll.approve` users; viewed with the payment panel (`hr.payroll.view`).
* **Implications:** *Financial* — easier reconciliation, no effect on amounts. *Operational* — one more optional field in the dialog. *Security* — free text may hold account or transfer numbers: length-limited, not logged in plain error messages, shown only to payroll viewers. *Data integrity* — additive nullable column on a live table (negligible lock); optionally a **soft** duplicate-reference warning, no uniqueness constraint (the same receipt can cover several payments).
* **Options:** **A** dedicated optional field *(working assumption)*; **B** keep `note` only with a written convention (no migration); **C** dedicated field **required** for every payment.
* **Technical recommendation:** **A.** C could block entry when no number exists; B does not make the reference searchable.
* **Dependencies:** C-4, C-5, proposed Somali/English messages (never the 161 locked ones).
* **Safe before approval:** nothing else depends on it; it is isolated as the last 1A item. **Must wait:** the migration and the field.

### 9.7 N-6 — Payroll changes after payment
* **Original meaning / working assumption:** **prevent ordinary payroll edits after any company payment has been recorded**; any required change must use an **audited correction workflow**.
* **Status: PENDING OWNER DECISION.** The full investigation, at least two options and the recommended approach are in **section 10**. Summary: the only action that rebuilds a paid company's stored lines is *reopen → regenerate → refinalize*; the proposal therefore restricts reopen for companies that have an active payment.
* **Safe before approval:** nothing is coded; existing behaviour and tests stay as they are; 1A is unaffected (per-company Generate never touches a finalized company). **Must wait:** any change to the reopen rule.

---

## 10. N-6 — detailed investigation (PENDING OWNER DECISION; nothing implemented)

All statements below were verified in the code at `0654492` (`ReopenFinalizedPayrollSectionAction`, `PayrollApproval`, `WorkplacePayroll`, `RecordCompanyReconciliationAction`, `VoidWorkplacePaymentAction`, `ClosedPayrollCorrection`, `CreatePayrollAdjustmentAction`, `CorrectionSupersession`, and the tests of `FinalizedPayrollReopenTest`).

### 10.1 Can a Paid company be reopened today, and what happens?
* **Yes.** A finalized company (including one that is fully Paid) can be reopened by an **HR Manager or a Super Admin**, with a reason of at least 10 characters. It is refused only if lines were marked paid one by one (`PAYROLL_LINES_PAID`) or if an open correction of that company/month already has money moved (`CORRECTION_HAS_MONEY_MOVEMENT`).
* **Payment records:** untouched. The `payroll_workplace_payments` rows stay, active and in the history; nothing is voided, edited or deleted. The reopening writes a `payroll_section_reopenings` row (who, when, why, `paid_cents_at_reopen`, `payment_count_at_reopen`, totals before) and an audit event; the derived approval is **withdrawn** automatically with its own audit event.
* **After reopening:** the company is Draft; its stored lines are unchanged until that company is generated again; **no payment can be recorded while it is not finalized**; finalizing again is refused until the company has been regenerated after the reopening (`REGENERATE_AFTER_REOPEN`); open corrections of that company and month are superseded (kept, audited), not deleted.
* **If the corrected total differs from what was paid** (tests `…leaves_the_company_overpaid_not_approved`, reconciliation tests): total **lower** than paid → the company is **Overpaid**, not Approved, until an HR Manager or Super Admin records a **reconciliation** (returned / carried forward / other, never moving or deleting a payment, voidable, idempotent); total **higher** → the difference becomes outstanding and is paid through Approve Paid/Partial, capped by `AMOUNT_EXCEEDS_OUTSTANDING`.
* **Duplicate / accidental repayment:** the outstanding balance is always `total − active payments`, so a re-finalized company never asks for the money already paid; a payment above the balance is refused; the idempotency key turns a double click into a no-op; no code path re-pays automatically.
* **Other companies:** unaffected. Reopen acts on **one** section; the month status is derived; the recovery ledger is re-synchronised run-wide but only that company's lines change; the test "only the reopened company and month are affected" covers it.

### 10.2 Which paths change a paid company's payroll today? (what "ordinary payroll edit" can mean)
| Path | Rewrites the company's stored lines? | Roles |
|---|---|---|
| **Reopen → Generate → Finalize** | **Yes** (lines rebuilt after the reopening) | HR Manager, Super Admin |
| **Payroll Correction** (assignment / salary / cover / separation change reaching a closed month) | **No** — recorded as a `PayrollCorrection` (underpayment owed to the employee, paid only by Super Admin; overpayment recovered from later pay; remainder stays debt) | HR Manager, Super Admin (reason mandatory) |
| **Payroll adjustment** on a finalized line (`CreatePayrollAdjustmentAction`) | **No** — applied in a **later** month | `hr.payroll.approve` |
| **Void of a company payment** | **No** (payment flag only) | HR Manager, Super Admin (reason) |
| **Reconciliation** of an excess | **No** (settlement entry only) | HR Manager, Super Admin |
So the only "ordinary payroll edit" that rewrites a paid company's lines is **reopen**; N-6 is, in effect, a decision about **reopen for a company with an active payment**. Today the request, approval and execution of a reopen are one actor (no two-person step exists).

### 10.3 Options
**Option 1 — Block reopen for any company with an active payment (literal N-6).** Changes go only through Payroll Correction (no line rewrite) or, if the lines truly must be rebuilt, by first **voiding the payments**.
* *Advantages:* paid amounts and stored lines can never diverge; simplest invariant.
* *Disadvantages / risks:* it **withdraws a capability the owner approved on 4 Nov** (HR Manager reopens a paid company); to rebuild a mis-generated payroll the payments must be **voided although the money was really paid** (void is meant for payments recorded in error), then re-recorded — confusing history, a real risk of duplicate payment records or accidental repayment; the live **Overpaid/reconciliation** feature becomes almost unreachable; wrong totals can only be carried as debts/underpayments.
**Option 2 — Keep the current reopen (payments kept, Overpaid/reconciliation) and add safeguards only.** Safeguards: a confirmation that shows the paid amount and payment count, and a mandatory acknowledgement; no change in who may do it.
* *Advantages:* nothing is withdrawn; no payment is ever voided; the full audit trail already exists.
* *Disadvantages / risks:* an HR Manager alone can reopen a company whose payments are real; one actor requests, approves and executes; a mistake can create an Overpaid state that needs reconciliation.
**Option 3 — Controlled reopen:** a company with an active payment can be reopened **only by a Super Admin**; the HR Manager uses Payroll Correction for such a company. Payments are never voided or edited; the dialog shows the paid amount; reason ≥ 10 characters plus an acknowledgement; regenerate-then-finalize and the reconciliation path stay as they are.
* *Advantages:* keeps the rebuild capability for the rare case that needs it, narrows it to the highest role, requires **no** voiding of real payments, needs no new tables (one role check in the reopen action, UI text, tests), reuses the existing audit, reopening record and reconciliation.
* *Disadvantages / risks:* the HR Manager loses reopen for **paid** companies (still has it for unpaid ones and Payroll Correction for paid ones); a bottleneck if the Super Admin is unavailable.
**Option 3b (larger, not in Release 1):** an HR Manager *requests* and a Super Admin *approves* a reopen of a paid company — needs a request/approval workflow (tables, UI, audit), i.e. a new feature.

### 10.4 Recommendation (the decision is the owner's)
**Option 3 (Super Admin-only reopen when an active payment exists; HR Manager uses Payroll Correction).** It is the safest way to protect payment history and prevent repayment mistakes **without** forcing real payments to be voided and without deleting a capability that already works. Option 1 is the strictest on paper but pushes the real-world fix toward voiding genuine payments; Option 2 changes nothing about who can rebuild paid payroll. N-6 stays **PENDING OWNER DECISION** until the owner explicitly approves an option.

### 10.5 Interaction with the approved workflow
| Step | Effect |
|---|---|
| **Finalize** (N-1) | mandatory before any payment; reopening returns the company to Draft; re-finalize needs a regenerate (`REGENERATE_AFTER_REOPEN`) |
| **Approve Paid / Partial** | only on a finalized company; amounts capped by the outstanding balance; payments survive a reopen |
| **Payroll Correction** | records the difference without reopening; underpayments are paid only by Super Admin; the correct route for a paid company under Options 1 and 3 |
| **Per-company Generate** (N-2a) | skips finalized companies; after a reopen it is the step that rebuilds only that company |
| **Audit** | reopening record (paid at reopen, who, why), audit events (reopen, approval withdrawn/restored), payment rows, reconciliation entries, corrections — all kept |

### 10.6 Questions for the owner
(1) Which option (1, 2, 3, or 3b later)? (2) Is voiding a **genuinely paid** company payment ever acceptable, or only payments recorded in error? (3) Should HR Manager keep the right to reconcile an excess (today: HR Manager and Super Admin)? (4) Should a reopen of a **partially** paid company be treated like a fully paid one (default: yes, any active payment)?
**Safe now:** nothing about N-6 is coded; existing behaviour and tests remain. **Isolated until approval:** any change to `ReopenFinalizedPayrollSectionAction`, its UI and tests.

---

## 11. Release 1A / 1B — dependency matrix, safe tasks and tasks that must wait

Release 1A = the company workflow (per-company Generate, warnings, reports and dashboard). Release 1B = penalties and one-month advances with their foundation (D-4). "Ready" = fully specified by approved decisions and independent of every pending item.

### 11.1 Release 1A
| Task | Decision IDs | Depends on approved | Depends on PENDING | Status |
|---|---|---|---|---|
| 1A-1 Migration: `payroll_run_sections.generated_at/by`, `generation_warnings` | N-2, D-3 | — | — | **Ready** |
| 1A-2 Shared generation service, month-wide output unchanged (golden proof) | N-2 | 1A-1 | — | **Ready** |
| 1A-3 Per-company Generate (API, stale-status guard, audit) | N-2, N-2a | 1A-2 | — | **Ready** |
| 1A-4 Retire month-wide Generate; migrate 68 test call-sites | N-2a | 1A-3 | — | **Ready** |
| 1A-5 Remove month-level bulk Review (route + UI) | D-2 | — | O-1 (month-level reopen, not blocking) | **Ready** |
| 1A-6 Temporary Replacement warnings (four conditions) before Generate and at Review/Finalize, never blocking | N-2b, D-1, D-8 | 1A-1 | — | **Ready** |
| 1A-7 "Generated — Nothing Payable" visible Draft section | D-3 | 1A-3 | — | **Ready** |
| 1A-8 Company reports, month card, monthly list, Payroll Rollup, Excel Summary line | N-4, N-4a, C-6 | — | — | **Ready** |
| 1A-9 Dashboard company KPIs (permission pair, previous-month default) | C-7, D-5 | 1A-8 | O-2 (not blocking) | **Ready** |
| 1A-10 Proposed messages, register regeneration, rules docs update | S-2 | 1A-3…1A-9 | — | **Ready** |
| 1A-11 Column-level baseline/hash tooling and golden tests | Q9, S-1 | — | — | **Ready** |
| 1A-12 Payment `reference` field | C-5 | — | **N-5** | **Must wait** |

### 11.2 Release 1B
| Task | Decision IDs | Depends on approved | Depends on PENDING | Status |
|---|---|---|---|---|
| 1B-1 Foundation: idempotency helper, audit shape (before/after), USD/amount/date/month validation | U-1, P-1, A-1 | — | — | **Ready** |
| 1B-2 Penalties: register (company + month), duplicate guard, deduction up to net $0, carry-over to next month for employees who stay | P-1 | 1B-1 | — | **Ready** |
| 1B-3 Freshness guard per section (Review/Finalize refuse stale inputs) | U-1 | 1A-1 | — | **Ready** |
| 1B-4 Void/correct of **not-yet-applied** penalties and advances by HR Manager + Super Admin | R-1, P-1, A-1 | 1B-1 | (Q8 for the exact state names) | **Ready for the unapplied case**; the rest **waits** |
| 1B-5 One-month advance: idempotent registration, zero-floor, remainder stays outstanding on the advance | A-1 | 1B-1 | — | **Ready** |
| 1B-6 Closed-period change of an applied penalty/advance through the existing correction workflow | P-1, R-1 | 1B-4 | **Q8** (state definitions) | **Must wait** |
| 1B-7 Automatic deduction of the carried advance remainder next month | A-1 | 1B-5 | **Q7** | **Must wait** |
| 1B-8 Leaver balances: visible open balance list (formal debt is Release 2) | P-1, A-2 | 1B-2 | **Q6** | **Design ready; enabling waits** |
| 1B-9 Registration for inactive/separated employees | P-1 | 1B-2 | **Q11** | **Must wait** |
| 1B-10 Reverse an applied deduction; waive a balance | R-1 | 1B-4 | **Q8** (and Release 2) | **Must wait** |
| 1B-11 Release gates and HR instruction | G-1 | — | **Q10** | **Must wait** (planning only) |
| 1B-12 Change to the reopen rule | — | — | **N-6** | **Isolated — must wait** |

### 11.3 Cross-checks
* **Safe now (specified, independent of every pending item):** 1A-1 … 1A-11; 1B-1, 1B-2, 1B-3, 1B-5, the unapplied part of 1B-4, and the design of 1B-8.
* **Must wait for the owner:** 1A-12 (N-5); 1B-6, 1B-10 (Q8); 1B-7 (Q7); 1B-8 enabling (Q6); 1B-9 (Q11); 1B-11 (Q10); 1B-12 (N-6).
* **Conflicts with already-approved requirements found:** (1) N-6 Option 1 would reverse the capability approved on 4 Nov (HR Manager reopen of a paid company); (2) Q11's literal wording ("a payroll line exists") conflicts in practice with per-company Generate (a line may not exist yet); (3) N-5 and C-5 overlap (C-5 already requires "reference/history", which the note field partly satisfies); (4) D-2 does not cover the month-level reopen route (O-1); (5) D-1/N-2b/D-8 supersede the earlier approved "Generate blocked" rule, which must be removed consistently from code, tests, messages and `HR_PAYROLL_RULES.md`. **No conflict** between Q8 and R-1 (R-1 stays approved; Q8 only refines the mapping), nor between D-5 and C-7.
* **Protected data and rules (unchanged):** the 112 sections, corrections #2–#13, existing payments, the 161 translations, the existing worktrees; no new database trigger; no employee-level manual salary workflow; Temporary rate = Permanent monthly salary ÷ 30.

## 12. Stage C execution status (2026-11-05)

**Owner decisions of Stage C (all APPROVED in the Stage C authorization of 2026-11-05; this supersedes the "PENDING OWNER DECISION" labels in sections 3.1 and 9, which are kept as the analysis record):**

| ID | Approved option | Where implemented |
|---|---|---|
| Q6 | A — a leaver's outstanding penalty balance is shown, not forgiven, no formal debt in Release 1 | Release 1B: `DeductionBalances`, `GET payroll/deduction-balances` |
| Q7 | A — outstanding balances carry into later months until zero, zero-floor, never more than eligible, balances and history kept. *The owner's text names penalty balances; the register's Q7 concerned the advance remainder. Both are implemented with the same rule so neither is silently dropped.* | Release 1B: `PayrollRunCalculator::capOwedDeductions`, `DeductionBalances` |
| Q8 | A — HR Manager + Super Admin void / correct what no finalized payroll has deducted; only a Super Admin reverses an applied deduction or waives a balance; closed periods only through reopening / Payroll Correction; audited | Release 1B: `CorrectionAuthority`, `ManageDeductionRecordAction` |
| Q10 | A — split 1A / 1B | process |
| Q11 | B — payable amounts follow the company-month assignment; eligibility from the assignment overlapping the month. *The owner's text also says "calculate by the actual eligible employment period" (already how proration works) and "never silently delete or rewrite a payroll line" (void / correct keep the row).* | Release 1B: `RegisterDeductionRecordAction` |
| N-5 | A — optional payment reference | Release 1A (live, commit 46b4fc8) |
| N-6 | 3 — once any payment exists only a Super Admin reopens the company; the HR Manager uses a Payroll Correction. Request → approval workflow (3b) NOT authorized and NOT built. | Release 1A (live) |

**Status:** Release 1A is LIVE and verified on production (main 46b4fc8, 2026-11-05 10:24 UTC). Release 1B (penalties / one-month advances) is built, tested and documented in `HR_PAYROLL_RULES.md` section 20; its release status is recorded in the release report. **O-1 (month-level Reopen) and O-2 (dashboard periods) are still undecided by the owner** and were not decided by the implementation.
