# Monthly Deep Cleaning tracking (Facility Inspections)

Every facility (a company or guri in Companies & Workplaces) should be deep cleaned at least once per calendar month. This feature records
completions inside the existing facility inspection form and shows, for any month, which facilities were and were not deep cleaned.
Pre-production implementation; nothing here has been deployed or run against production.

## What is recorded

- A **Deep Cleaning** section in the inspection form. Default: *Not recorded in this inspection*. Choosing *Deep Cleaning completed* requires
  the **date it was completed** (never in the future, not before 2020-01-01) and optionally notes.
- A routine inspection records nothing. "No row" means "no Deep Cleaning recorded", never "completed". Nothing is backfilled or assumed.
- Storage: table `facility_deep_cleanings` (migration `2026_10_25_100000_create_facility_deep_cleanings_table`). It is **not** a thirteenth
  checklist item: the checklist is exactly twelve items (database CHECK constraint and request rule) and an inspection edit replaces its items,
  which would erase a recorded completion.
- One record per inspection (UNIQUE), and at most one **counted** record per company and day (partial unique index `WHERE voided_at IS NULL`),
  so a repeated submission or a second inspection naming the same day cannot count the same cleaning twice (the request is refused, 422
  `DEEP_CLEANING_ALREADY_RECORDED`, and the whole inspection is rolled back).
- The completion date is its own column, separate from `inspected_on` and from the created/updated timestamps. `recorded_by` is the signed-in admin.

## Editing, correcting, withdrawing

- Saving an inspection **without** the `deep_cleaning` section never touches an existing record.
- Sending the same date and notes again changes nothing and writes no audit entry.
- Changing a recorded date/notes is a **correction** and needs `change_reason` (at least 5 characters, 422 `DEEP_CLEANING_CHANGE_REASON_REQUIRED`
  otherwise). `changed_by` and `change_reason` are stored; the audit log keeps before and after.
- `completed = false` on a recorded completion **withdraws** it (needs a reason). It is kept (`voided_at`, `voided_by`, `void_reason`) but counted nowhere.
  Recording it again after a withdrawal also needs a reason.
- Voiding the inspection withdraws its completion with it.

## Monthly rules

Calendar months of plain dates (the completion date is a DATE, so 31 October is October and 1 November is November).

**Timezone.** Deep Cleaning uses the business calendar **Africa/Mogadishu (UTC+3, no daylight saving)** for "today", the default and the
latest selectable month, month boundaries and the future-date check, whatever timezone the server or the browser is in. The application itself
stays on UTC and database timestamps stay UTC instants. `FacilityCalendar` (app/Support/Hr/Facility) holds the zone as a constant (not the
editable Settings value, so an edit cannot move a month boundary); the browser mirrors it in `web-panel/src/utils/facility.ts`
(`FACILITY_TIME_ZONE`, `facilityToday`, `facilityMonth`). October therefore ends at `2026-10-31 21:00:00 UTC`. Dates are never converted through
UTC; Deep Cleaning screens show a date-only value with `formatCalendarDate` (the shared `formatDate` is unchanged).

For the selected month **M**, per facility:

| State | Rule |
|---|---|
| `completed` | a counted completion dated inside M |
| `no_history` | no counted completion dated on or before the end of M |
| `overdue_multiple` | none in M, and the last one on or before M's end is **2 or more** calendar months before M (`OVERDUE_AFTER_MISSED_MONTHS = 2`; last in August, M = October: September and October missed = 2) |
| `not_completed` | none in M, and the last one was in the previous month (1 month missed) |

A completion in M always wins. Earlier gaps stay visible: select an earlier month, or read the six-month strip in each row. `last_completed_on`
is the latest counted completion on or before the end of the selected month (for the current month: the latest overall).

### Who is listed for month M

- Active facilities registered before the Mogadishu month ended: `created_at` (a UTC instant) is compared with the first instant after M, so a facility
  created at `2026-10-31 22:00 UTC` (01:00 on 1 November in Mogadishu) is eligible from November, not October.
- Inactive facilities are never dropped silently: one with a counted completion in M is always listed (flagged *Inactive*); with
  `include_inactive=1` every inactive facility that existed by then is listed; otherwise the rest are reported as `inactive_hidden`.
  No deactivation date is stored, so a facility that is inactive today is treated as inactive for every month.
- Withdrawn completions and those of voided inspections count nowhere.

## API

- `GET /api/v1/admin/hr/facility-inspections/deep-cleaning?month=YYYY-MM&state=&search=&category=&client_company_id=&include_inactive=`
  (permission `hr.inspections.view`; month defaults to the current one; a future or malformed month is refused).
  `data` = one row per facility; `meta.counts` = the four states plus `total` for the whole month, whatever filter is applied.
- Writing uses the existing inspection endpoints (`hr.inspections.manage`): the optional `deep_cleaning` object
  `{ completed, completed_on?, notes?, change_reason? }`. The facility always comes from the inspection, never from this object.
- Inspection responses carry `deep_cleaning_completed_on` (list) and `deep_cleaning` (detail).

## Permissions and audit

No new permission, role or Super Admin rule. Read: `hr.inspections.view`. Write: `hr.inspections.manage`. RBAC has no per-facility scope, so any
user who may record inspections may record them for any facility; the Deep Cleaning always belongs to the facility of its inspection.
Audit log entries (entity `FacilityDeepCleaning`): create, update (correction or withdrawal, with before/after and reason).

## Where it appears

Human Resources > Companies & Workplaces > Facility Inspections: the **Monthly Deep Cleaning** overview (month picker, state filters with counts,
facility search, include-inactive switch, last Deep Cleaning date, six-month strip), the **Deep Cleaning** section of the Record/Edit inspection
form, and a Deep Cleaning line in the inspection detail.

## Scope of the Mogadishu calendar (what it does and does not change)

Changed: everything above, plus the future-date check of the inspection **create and edit** requests (`inspected_on`) and of complaints entered
**inside the inspection form** (`complaints.*.reported_on` / `responded_on`), so a valid Mogadishu "today" is not refused during the three hours
(21:00-24:00 UTC) when UTC is still on the previous day. Only the "not in the future" comparison moved; no stored date is reinterpreted.
The default response, resolution and documented-feedback dates of a complaint entered inside the inspection form are the Mogadishu date, never earlier than the
complaint's report date (dates typed by the user are kept; a response date before the report date is refused). The inspection form opens on today in Mogadishu.

Not changed: the stand-alone Complaints screen and its defaults, the inspection status board and its "today", the Facility report, the
global application timezone, historical inspection dates, the 12-item checklist, and the shared `formatDate`.
