# HR & Payroll rules - Permanent, Temporary and Replacement

This is the **canonical rules document** for employment types, assignments, replacements and monthly salary calculation in the Fayadhowr HR & Marketing Web Panel. It replaces the scattered statements in owner messages, audit reports and code comments as the place to look. When a rule changes, change it here first, then the code and tests (the traceability table in section 7 lists both).

- Written 2026-10-26 against `main` = `2f4f96f` (production release) and updated the same day with the implementation on the local branch `feat/hr-permanent-temporary-rules` (not pushed, not deployed). A rule is implemented only where section 7 says **Implemented**.
- Location: `docs/`, topic-named like `FACILITY_DEEP_CLEANING_TRACKING.md`. `HRM_MARKETING_SRS.md` has no payroll or replacement rules (checked: no mention), so there was no earlier source of truth to update.
- Related audit snapshots (dated, not canonical): `docs/EMPLOYMENT_PAYROLL_RULES_RELEASE_READINESS.md` (2026-10-26).

**Evidence labels:** **[OWNER date]** = a written owner decision in the project's conversation records; **[CODE]** = behaviour of the implementation; **[TEST]** = pinned by an automated test; **[ASSUMED]** = implementation choice that no owner statement covers. A rule marked [CODE]/[TEST] only is recorded as behaviour, not as an owner decision.

## 1. Vocabulary (do not mix these)

| Term | Meaning in the system | Where it lives |
|---|---|---|
| **Permanent** | The regular holder of a position. An employee has `employment_type` `permanent` (default) or `temporary`, and each assignment has `assignment_type` `permanent` or `replacement`. | `employees.employment_type`, `employee_work_assignments.assignment_type` |
| **Temporary (employee)** | A person classified `temporary`. A Temporary has no pay line of their own; their pay is the **replacement cost**, worked out from verified worked days and deducted from the Permanent they covered. | `employees.employment_type = temporary` |
| **Temporary replacement** (cover) | A record that a Temporary covered a specific Permanent assignment, with its own start/end dates, a daily list of workdays (recorded, verified, voided), an approval, and payments. The Permanent assignment stays in place. | `temporary_replacements`, `temporary_replacement_workdays`, `ReplacementCostCalculator`, `ReplacementCostService` |
| **Replacement** (assignment type) | Someone who takes over the position: the Permanent's assignment ends and a new assignment of type `replacement` starts, linked by `replaces_assignment_id`. It has no salary of its own; it inherits the replaced Permanent's. | `AssignmentType::Replacement`, `CreateWorkAssignmentAction`, `ChangeAssignmentSalaryAction::applyToReplacements` |
| **Permanent successor** | The person chosen in Replace Employee with type Permanent: a normal Permanent assignment linked to the one it follows. | `ReplaceCompanyEmployeeAction` |

Temporary and Replacement are **not interchangeable** [OWNER 2026-10-19]. Where the owner's wording says "Temporary replacement" or "Replacement", this document maps it as above and flags any ambiguity in section 5.

## 2. Previously approved rules (preserved)

| ID | Rule | Source |
|---|---|---|
| A1 | Salaries are in USD and calculated monthly; payroll is intended to be paid at the end of each month. Non-USD salaries are not converted and are refused for payroll. | [OWNER 2026-10-10] Decision 7; [OWNER 2026-10-19/20] no currency conversion |
| A2 | The daily basis is the monthly salary divided by the **actual calendar days of the month** (28, 29, 30 or 31). Weekends and public holidays are paid days and never change the divisor. | [OWNER 2026-09-04] master requirements s.17 (cited in the absence rule record), [OWNER 2026-10-10] clarification |
| A3 | A mid-month start (or end) is prorated from the assignment's dates, inclusive: `Prorated salary = monthly salary x eligible calendar days / calendar days in the month`. Example: USD 300, start 11 October, 21 days, **203.23**. | [OWNER 2026-10-10] Decision 7 |
| A4 | Money uses integer cents. The whole month is rounded **once**, half-up; cent remainders across lines that share one position go to the last line so totals reconcile. | cent remainder: [OWNER 2026-10-19]; half-up: [CODE]/[TEST] only - the owner asked that the rule be documented, it is recorded here |
| A5 | A Temporary replacement is linked to the **exact Permanent employee, assignment and position** covered, and to the company/workplace. Start and end dates are inclusive. Several Temporaries may cover the same position in separate periods. | [OWNER 2026-10-10] Decision 8 |
| A6 | Temporary replacement cost: `Daily rate = applicable Permanent monthly salary / calendar days of the month`; `Cost = daily rate x verified eligible workdays`. Unworked or unverified days are never charged. The approved cost is **deducted once** from the covered Permanent's pay. | [OWNER 2026-10-10] Decision 8 |
| A7 | The same calendar day is never charged twice for the same position, and the same absence is never deducted twice. An absent day covered by an approved replacement is deducted once (as the replacement cost). | [OWNER 2026-10-10], [OWNER 2026-10-19] |
| A8 | A **Replacement** (assignment type) has no salary of its own: its basis is the monthly salary of the Permanent whose position it takes over (USD 300 over 30 days for 15 days = 150). The replaced Permanent and the Replacement are never both paid for the same days. | [OWNER 2026-10-19] RC5 |
| A9 | **Replacement salary change:** when the Permanent's salary changes during a month, the Replacement's calculation for that month uses the **new Permanent salary for the entire month**, regardless of the effective date. Closed months are not rewritten without an approved correction. | [OWNER 2026-10-19] RC6 update 5; **reaffirmed [OWNER 2026-10-26]** |
| A10 | **Return:** when a replaced Permanent returns, the Replacement's last covered day is the day before the return date; a **new** Permanent assignment is created; every earlier assignment, salary row and payroll month is kept. If only Temporary cover occurred, the original Permanent assignment continues unchanged. | [OWNER 2026-10-19] RC5 |
| A11 | **History:** assignment history, effective dates and salary history are preserved, never overwritten (a salary change adds a row effective from its date). Edits keep before-and-after audit records. | [OWNER 2026-10-19] |
| A12 | **Several companies:** by default an employee has one client company at a time. A Super Admin / HR Manager can switch multi-company assignments on (audited); then each assignment keeps its own company, dates, salary and currency, payroll lines stay **separate per assignment**, and an absence is charged once. (The policy is ON in production since 2026-10-21.) | [OWNER 2026-10-19] RC5 |
| A13 | A Temporary registered as a Replacement triggers a warning and explicit HR confirmation; if confirmed while the data still says Temporary, no payroll is calculated for those days and the month is refused until the record is corrected. Nothing is converted silently. | [OWNER 2026-10-19/20] |
| A14 | Payment number: Account Number first, otherwise the Phone Number used directly. | [OWNER 2026-10-21] |
| A15 | Payroll void/reversal and repayment/payment/write-off default dates follow the **Africa/Mogadishu** calendar day. | [OWNER 2026-10-26] Option A |
| A16 | Debt after departure, underpayment ledger, write-offs: as implemented in RC6. **Write-off reversal must not be built without a new owner decision.** | [OWNER 2026-10-20] |

## 3. Owner decisions of 2026-10-26

| ID | Decision |
|---|---|
| N1 | **Removing a Permanent employee from an assignment requires choosing the replacement first.** Allowed choices: (1) an existing Temporary employee who has been covering that Permanent position; (2) a person selected from the HR Waiting List. The removal must not complete while the choice is missing. No other choice is offered and nobody is selected automatically. It applies to every path that actually ends an open Permanent assignment: Stop, End, setting or shortening the end date, and employee Separation. It does **not** apply to Cancel (only possible for a mistaken assignment with no payroll and no covers, so no position was in service), to a Replacement-type assignment, or to Replace Employee (which names its own successor in the same action). A position whose successor assignment already exists is already resolved. |
| N2 | **Temporary becomes Permanent** when HR/Management selects that Temporary as the permanent replacement. The same employee record becomes Permanent (no duplicate); the new Permanent assignment inherits the departing Permanent's salary automatically (the salary in effect on the last paid day); HR enters and confirms nothing. The audit history records the change, effective date, previous and new type, salary, affected assignments and the actor. The Temporary's cover on that position ends with it; the cover and every earlier record are kept. |
| N3 | A **Waiting List** person is appointed through the existing status workflow (Waiting to Active, with its registration-workflow gate), assigned, and recorded in the same transaction. If any step fails, nothing is left behind. The salary is the one HR enters (the existing rule for a Permanent successor); it is never assumed to be inherited. |
| N4 | **Transfer of a Permanent between assignments** is the current two steps: Stop the old assignment, then Assign the new position, facility or company. The old assignment's dates, salary history and audit trail are kept; it is never overwritten. |
| N5 | **Temporary payment:** the expected amount is calculated automatically and **prefilled**. A different entered amount shows a clear warning with the calculated amount beside it, but is **permitted** (manual override, not blocked). The calculated amount, the amount actually paid, the optional reason, the notes, the payment date and the actor are recorded. A payment approves nothing. |
| N6 | **Temporary cost when the Permanent's salary changes within a month:** split the month by the salary's effective date - the old salary before it, the new salary from that date on - using the real calendar days, without double-counting a date. |
| N7 | **Effective date of a Temporary becoming Permanent:** the new Permanent status and the new Permanent assignment begin on the **day AFTER** the former Permanent's last paid day (the system convention: the end date is the last paid day, inclusive; a successor may not start on or before it). |
| N8 | **Waiting List fallback:** no earlier approved rule combined appointment and assignment, so the workflow does both in one safe transaction, respecting the existing eligibility checks, permissions and validation (a new registration that has not completed the registration workflow is refused exactly as anywhere else). |
| N9 | **The expected Temporary payment counts the full period** during which the assignment actually existed, not only individually verified workdays. Attendance records elsewhere keep their meaning. |
| N10 | **Early termination:** if the Temporary assignment ends before its planned end, the amount covers only the days it actually existed. Both the planned end date and the actual end date are kept. |
| N11 | **Monthly proration of the Temporary payment:** monthly salary / real days of the month (28, 29, 30, 31; leap February included) x the calendar days the assignment existed in that month, salary changes applied by effective date (N6). Example: 600 over 10 days of a 30-day month = 200. |

## 4. Temporary and Replacement calculation rules, side by side

| | Temporary replacement (cover) | Replacement (assignment type) |
|---|---|---|
| What it is | Cover for specific days; the Permanent assignment continues | Takes over the position; the Permanent assignment ends |
| Whose salary | The covered Permanent's, from that assignment's salary history | The replaced Permanent's, copied as the Replacement's own salary row |
| **Expected payment to the person** | **N9-N11: every calendar day of the cover up to its actual last day (and not past today), the salary in effect on each day / real days of the month, each month rounded once, minus what was already paid** | A normal pay line: A3 on the inherited salary |
| **Cost deducted from the Permanent** | **A6: verified workdays only** (unchanged) | Not applicable: the Permanent is paid until the last covered day |
| Permanent salary changes mid-month | **N6: split by effective date** (old salary before, new from that date) | **A9: new salary for the whole month** |
| Own pay line | None; cost shown beneath the Permanent in the payroll Excel; paid by recorded payments | Normal pay line |
| Code | `TemporaryExpectedPayment` (payment); `ReplacementCostCalculator` (deduction) | `ChangeAssignmentSalaryAction::applyToReplacements` |

Worked example (the same facts under each rule): Permanent salary 300 until 24 September and 400 from 25 September, 30-day month. A Temporary who covered 21-30 September: **N6 payment = (4 x 300 + 6 x 400) / 30 = 120.00** (pinned by test). A Replacement holding the position all September: **A9 = 400 for the whole month**, not 300 for 24 days and 400 for 6.

**Two amounts for one cover (open, U8).** The payment to the Temporary now counts every calendar day (N9); the cost deducted from the Permanent still counts only verified workdays (A6, the owner's 2026-10-10 Decision 8, unchanged because N9 speaks about the payment). The payment dialog and the API show both figures beside each other and a warning when they differ. Until the owner says whether the deduction should follow the full period too, an unverified day is paid to the Temporary but not deducted from the Permanent.

## 5. Conversion rules and evidence

### 5.1 Temporary to Permanent - the approved scenario (N2, N7)
- **Approved and implemented:** only the scenario in N1/N2 (a departing Permanent is replaced by an existing Temporary who covered that position). It is not generalised to other conversions.
- **How it works:** one transaction ends the departing assignment, ends the person's cover on it, sets `employment_type` to `permanent` (audited from/to), creates the Permanent successor assignment from the next day with the departing salary, and records the choice in `assignment_successions`.
- **Known limit (U9):** `employment_type` is a current-state column with no effective date or history table. It changes when the choice is made; the effective date is carried by the new assignment's start date and the audit entry. The flag does not affect the cost of an existing cover, and the person has no assignment of their own before the start date, so no payroll line is touched in between.
- **Older paths kept:** a Replacement can still be edited to Permanent (audited); a Temporary confirmed as a Replacement still blocks payroll until corrected (A13).

### 5.2 Permanent to Temporary
- **No approved rule found.** Searched: the SRS, all HR audit reports and rule records, all four project conversation records, Git history and tests. The new flow never converts a Permanent into a Temporary (a Permanent chosen as a "Temporary" successor is refused with `SUCCESSOR_NOT_TEMPORARY`).
- **Existing behaviour (unchanged):** the flag can be edited (audited); the person's open assignments are not touched; payroll then **skips** that person's own assignments with a warning, so their pay line disappears. No guard or workflow exists. Status: **unresolved, owner decision required (U1)**.

## 6. Unresolved questions

Resolved by the owner on 2026-10-26: U2 (the day after the last paid day, N7), U3 (one safe workflow for the Waiting List, N3/N8), U4 (Temporary and Replacement keep separate calculations), U5 (all ending paths audited and covered as in N1), U6 (see N5: the expected amount is the balance on the payment date).

| ID | Question | Why it is open |
|---|---|---|
| U1 | **Permanent to Temporary:** is this ever allowed, and what happens to the person's assignments and pay? | No rule found (5.2); the current behaviour silently drops the pay line. |
| U7 | **Payroll Excel layout** (the owner's own `SALARY SEP 2026.xlsx` versus the system export). | Recorded in the recovery report; unchanged. |
| U8 | Should the cost deducted from the Permanent also count the full period (N9) instead of verified workdays only (A6)? | N9 changes the payment to the Temporary; A6 (Decision 8) still governs the deduction. They now differ for unverified days. |
| U9 | Does the Temporary-to-Permanent type change need an effective-dated record? | `employment_type` has no effective date; it flips when the choice is made. |
| U10 | Should Replace Employee also be limited to the two N1 kinds of successor? | It was approved earlier (2026-10-19) and still accepts any Active employee; N1 speaks about removal. |
| U11 | Is there a payment approval policy for Temporaries? | Payments are allowed at any time and are not gated by the cost approval; the dialog shows "not approved" and nothing is approved by paying. |
| U12 | Overlapping covers of one position: confirm that a day belongs to the cover that started first. | An implementation choice to avoid counting a day twice; no owner statement. |
| U13 | Should the salary of a Waiting List successor default to the departing salary? | N3 says never assume inheritance, so HR enters it. |

## 7. Rule-to-code-to-test traceability

Status: **Implemented**, **Partial**, **Missing**, **Unresolved**. Test results are in the report at the end of the implementation task (section 9).

| Rule | Code | Tests | Status |
|---|---|---|---|
| A1 USD, monthly | `PayrollMoney::CURRENCY`, `GeneratePayrollRunAction`, `PayrollRunCalculator` | `PayrollLifecycleTest`, `MultiCompanyAndCurrencyPolicyTest::payroll_does_not_treat_a_non_usd_salary_as_dollars` | Implemented (month-end timing is not enforced; reminders deferred) |
| A2 Calendar days | `ProrationCalculator::month`, `PayrollRunCalculator::addAbsence` | `ProrationCalculatorTest`, `ReplacementAndTemporaryRulesTest::month_lengths_of_28_29_30_and_31_days_are_used`, `AbsenceDeductionApprovedRuleTest::weekend_days_are_deducted_like_any_other_day` | Implemented |
| A3 Mid-month proration | `ProrationCalculator::monthlyGross` | `PayrollLifecycleTest::the_october_example_gives_203_23` | Implemented |
| A4 Rounding | `PayrollMoney::roundDiv` | `ProrationCalculatorTest::test_round_half_up_rule`, `ReplacementAndTemporaryRulesTest::cent_remainders_go_to_the_last_line_of_the_position_so_the_total_reconciles` | Implemented |
| A5 Linked cover, own dates | `CreateTemporaryReplacementAction`, `TemporaryReplacement` | `TemporaryReplacementTest`, `ReplacementWorkdayLedgerTest::sequential_temporaries_can_cover_the_same_position_on_different_days` | Implemented |
| A6 Cost formula, deducted once | `ReplacementCostCalculator`, `ReplacementCostService` | `ReplacementCostCalculatorTest`, `PayrollLifecycleTest::the_deduction_source_can_only_be_applied_once_for_a_period_in_the_database`, `TemporaryExpectedPaymentTest::every_calendar_day_of_the_cover_counts_not_only_verified_workdays_and_the_deducted_cost_is_unchanged` | Implemented (unchanged) |
| A7 No double charge / absence once | `RecordReplacementWorkdaysAction`, `PayrollRunCalculator` | `ReplacementWorkdayLedgerTest::a_day_cannot_be_charged_twice_for_the_same_position`, `ReplacementAndTemporaryRulesTest::the_same_absence_is_never_deducted_twice` | Implemented |
| A8 Replacement inherits salary | `CreateWorkAssignmentAction::withInheritedSalary` | `ReplacementAndTemporaryRulesTest::300_dollars_over_30_days_for_15_days_pays_150...` | Implemented |
| A9 Replacement whole-month salary change | `ChangeAssignmentSalaryAction::applyToReplacements` | `OwnerDecisionsUpdateTest::a_permanent_salary_change_applies_to_the_replacement_for_the_whole_month_never_prorated_by_the_day` and the four after it | Implemented (reaffirmed, unchanged) |
| A10 Return, new assignment | `ReturnCompanyEmployeeAction` | `CompanyEmployeeManagementTest::the_original_employee_returns_and_every_assignment_is_kept` | Implemented |
| A11 History and audit | `ChangeAssignmentSalaryAction`, `UpdateWorkAssignmentAction` | `AssignmentSalaryHistoryTest`, `PermanentSuccessionTest::a_transfer_is_stop_then_assign_and_the_old_assignment_is_preserved_not_overwritten` | Implemented |
| A12 Several companies | `ConcurrentAssignmentGuard`, `PayrollRunCalculator` | `MultiCompanyAndCurrencyPolicyTest` (13), `PayrollLifecycleTest::an_absent_day_is_charged_once_even_with_two_assignments...` | Implemented (never exercised on real production data) |
| A13 Temporary as Replacement | `CreateWorkAssignmentAction`, `UnresolvedTemporaryReplacements` | `OwnerDecisionsFinalTest` (3 tests) | Implemented |
| A14 Payment number | `PaymentNumber` | `PaymentNumberWorkbookRulesTest` | Implemented |
| A15 Mogadishu dates | `FacilityCalendar`, payroll date defaults | `PayrollReversalDatesTest`, `PayrollSettlementDateTest` | Implemented |
| A16 Write-off reversal withheld | `VoidCorrectionTransactionAction` refuses with `TRANSACTION_NOT_VOIDABLE` | `OwnerDecisionsFinalTest::a_written_off_amount_is_never_deducted_from_salary_again` | By instruction not built |
| **N1** Choice before removal | `PermanentSuccession::demand`; hooks in `EndWorkAssignmentAction`, `StopCompanyAssignmentAction`, `UpdateWorkAssignmentAction`, `CloseAssignmentsOnSeparationAction`; rules in `SuccessorRules`; options at `AssignmentSuccessorOptionsController` | `PermanentSuccessionTest`: `a_permanent_assignment_cannot_be_stopped_without_a_replacement_choice`, `only_a_temporary_who_covered_the_position_or_a_waiting_list_person_can_be_chosen`, `the_end_endpoint_and_the_end_date_edit_demand_the_choice_too`, `a_separation_demands_a_choice_for_every_open_permanent_assignment_and_is_all_or_nothing`, `a_cancelled_mistake_and_a_replacement_type_assignment_need_no_choice`, `a_position_is_removed_once_and_its_choice_is_recorded_once`, `the_options_list_only_the_two_permitted_kinds_and_no_salary` | Implemented |
| **N2 / N7** Temporary becomes Permanent, salary, day after | `PermanentSuccession::complete`, `AssignmentSuccession`, migration `2026_10_26_200000_...` | `PermanentSuccessionTest`: `the_temporary_who_covered_the_position_becomes_permanent_with_the_departing_salary_without_a_duplicate_employee`, `the_new_permanent_starts_the_day_after_the_last_paid_day_across_month_year_and_leap_boundaries`, `the_inherited_salary_is_the_one_in_effect_on_the_last_day_and_a_typed_salary_is_ignored`, `a_separation_can_name_the_temporary_who_takes_over` | Implemented (limit U9) |
| **N3 / N8** Waiting List | `PermanentSuccession::complete` calling `UpdateEmployeeStatusAction` and `CreateWorkAssignmentAction` in one transaction | `PermanentSuccessionTest`: `a_waiting_list_person_is_appointed_and_assigned_in_one_workflow_with_the_salary_hr_enters`, `a_waiting_list_person_needs_a_salary`, `an_incomplete_registration_is_refused_and_nothing_is_left_behind`, `a_failure_late_in_the_workflow_undoes_the_ending_and_the_appointment` | Implemented |
| **N4** Transfer = Stop + Assign | `StopCompanyAssignmentAction`, `CreateWorkAssignmentAction` (no in-place swap of location or company) | `PermanentSuccessionTest::a_transfer_is_stop_then_assign_and_the_old_assignment_is_preserved_not_overwritten`, `CompanyEmployeeManagementTest::stopping_ends_only_the_company_assignment_and_frees_the_position` | Implemented |
| **N5** Prefill, warning, override audit | `RecordTemporaryReplacementPaymentAction::check`, `TemporaryReplacementController::addPayment`, `TemporaryReplacementWorkdayController::expectedPayment`; panel `RecordPaymentModal`, `utils/temporaryPayment.ts` | `TemporaryExpectedPaymentTest`: `the_expected_payment_is_prefilled_and_a_matching_payment_is_recorded_with_its_calculation`, `a_different_amount_warns_with_both_figures_but_is_permitted_and_audited`, `a_payer_without_payroll_visibility_still_records_the_calculation_but_is_not_shown_it`, `the_expected_payment_is_visible_to_payroll_viewers_only`, `the_payment_is_checked_against_what_was_due_on_the_payment_date`; web `temporaryPayment.test.ts` | Implemented |
| N5 Payment list and Temporaries | `MonthlyPaymentReportBuilder` is built from payroll lines; a Temporary has none and "Not in payroll" excludes Temporaries | `MonthlyPaymentReportTest` | **Missing for Temporaries** (open design point; nothing changed) |
| **N6** Split by effective date | `TemporaryExpectedPayment` (payment), `ReplacementCostCalculator` (deduction) | `TemporaryExpectedPaymentTest::a_salary_change_splits_the_cover_by_its_effective_date_without_touching_the_history`, `ReplacementCostCalculatorTest::test_the_salary_in_effect_on_each_worked_day_is_used` | Implemented |
| **N9** Full period | `TemporaryExpectedPayment::forReplacement` | `TemporaryExpectedPaymentTest::every_calendar_day_of_the_cover_counts_not_only_verified_workdays_and_the_deducted_cost_is_unchanged` | Implemented for the payment; deduction unchanged (U8) |
| **N10** Early termination | `TemporaryReplacement::endOn` (keeps `planned_end_date`), `EndTemporaryReplacementAction`, `CloseAssignmentsOnSeparationAction` | `TemporaryExpectedPaymentTest::a_cover_ended_early_counts_only_the_days_it_existed_and_keeps_the_planned_end_date`, `::the_planned_end_date_is_kept_once_and_an_open_ended_cover_has_none`, `::the_cover_never_counts_days_after_today_or_after_the_covered_assignment_ended` | Implemented |
| **N11** Real month lengths | `TemporaryExpectedPayment` | `TemporaryExpectedPaymentTest::600_over_10_eligible_days_of_a_30_day_month_is_200`, `::months_of_28_29_30_and_31_days_use_their_real_length_including_leap_february`, `::the_months_of_a_cover_are_rounded_once_each_and_added`, `::overlapping_covers_of_one_position_never_count_a_day_twice` | Implemented |
| 5.2 Permanent to Temporary | `UpdateEmployeeAction` (flag edit), `PayrollRunCalculator` line 79 | `PermanentSuccessionTest::only_a_temporary_who_covered_the_position_or_a_waiting_list_person_can_be_chosen` (the new flow never creates one) | **Unresolved (U1)** |
| Payroll Excel formulas, values, formatting, reconciliation | `PayrollWorkbookExporter` (22 columns; every cell, including totals, text; no formulas; Permanent-only totals; OK/MISMATCH cell), `MonthlyPaymentReportExporter` (numeric money, `#,##0.00`, 3 sheets, no formulas) | `PayrollSheetTest` (8), `MonthlyPaymentReportTest` (26) | Unchanged by this work; layout question open (U7) |

## 8. Remaining gaps and recommended next steps

| Gap | Recommended step | Affects |
|---|---|---|
| **G1 - U8** Payment and deduction use different day bases | Owner decision. If the deduction should follow the full period, change `ReplacementCostService` to read the cover window instead of the ledger; keep the ledger for attendance. Needs its own tests and a payroll before/after comparison. | Payroll deductions of the Permanent |
| **G2 - Payment list** Temporaries do not appear | Decide whether the payment list should list Temporaries with their balance. | Excel export only |
| **G3 - U10** Replace Employee scope | Decide whether to restrict Replace Employee to the two N1 kinds. | Replace workflow |
| **G4 - U9** Effective-dated type | Only if the owner needs history of employment-type changes: an additive history table. | Reports |
| **G5 - U1** Permanent to Temporary | Owner decision; until then a warning before reclassifying someone with an open Permanent assignment would stop the silent loss of a pay line. | Employee edit |
| **G6 - U7** Excel layout | Owner decision, then the minimum corrections. | Export only |

## 9. How to change this document
Record the owner's wording and date in the relevant rule, update the traceability row (code and test), and mark the status. A rule without a test stays **Missing** or **Partial** until one exists. Implementation status in this version: local branch `feat/hr-permanent-temporary-rules`, not pushed, not deployed, nothing Production-verified.
