## Status of the messages

| Status | Meaning | May it change? |
|---|---|---|
| **locked** (`approved`, 161 messages) | The owner declared these 161 messages FINAL, APPROVED AND LOCKED on 2026-10-27. Exact Somali wording, spelling, capitalization and punctuation, their ids, their English equivalents and their use on screens are protected by `web-panel/src/i18n/lockedApproved.test.ts` against a fixed baseline (`web-panel/src/i18n/baseline/approved-161.json`, SHA-256 `ad500a9affadf2bee600acd67b4827c579bbf7b9a2209e54dde33b5ecdf2cecc`). | **No.** No review or re-approval is requested. |
| **owner-approved addition** (`approved`, 2 messages: `OWN-U1-001`, `OWN-U1-002`) | The two warnings whose Somali the owner supplied and approved under decision U1 (an employee with an active Permanent assignment cannot become Temporary; an employee with an overlapping assignment cannot cover). The same Somali is in the backend; a test keeps the copies identical. Not part of the 161. | No. |
| **owner-term** (23 messages) | The owner supplied the wording; its review is still open. They stay in this category until the owner approves them. | Only by the owner's decision. |
| **proposed** (all others) | Somali written for this implementation from the approved vocabulary. Not reviewed by a native speaker; never to be described as owner-approved. | Yes, after review. |

## Owner decisions applied

- 2026-10-22: Voided is `Laga noqday` (facility status and notices, workday status). The approved buttons `Ka noqo` and `Ka noqo Lacag-bixinta` are unchanged. Finalized payroll cannot be edited **or reopened**. Uploads use `Soo geli`; Excel imports use `Soo geli Excel`, and the confirmation keeps the row count: `Soo geli Excel (safafka diyaarka ah: {n})`. Facility inspection results are `La aqbali karo` / `Lama aqbali karo`. Unchanged on purpose: the Archived variants (`La kaydiyey`, `la dhigay kaydka`, `kaydsan`); Rejected (`Dadka la kansalay`, `La Kansalay`, `Dadka mar hore la kansala`); the three Waiting names (`Shaqa sugayaal`, `Liiska Sugitaanka`, `Falanqaynta Sugitaanka`). The employee status lifecycle is called `wareegga xaaladaha shaqaalaha`. Dates and months follow the language: Somali month names (`Oktoobar 2026`, `1 Okt 2026`) and a 24-hour clock (the Somali month names are proposed wording).
- 2026-10-27: the 161 `approved` messages are final and locked (see above). Somali is shown on **every** screen, sign-in included; English remains the default; the choice is kept in the browser under `fayadhowr.ui.language`.

## Corrections made in this implementation

Five proposed messages described the older payroll rule ("only verified days are charged"). They were corrected to the coverage-period rule (the cost is of the days the cover existed; verified attendance days are only a record). No payroll calculation was changed.

| Key | Correction |
|---|---|
| `confirm.pipeline.approveBody` | text corrected (same key) |
| `warning.pipeline.workdaysNotice` | text corrected (same key); the bold part is now `warning.pipeline.coverageDaysStrong` |
| `warning.payroll.coverSummary.one` / `.other` | "verified day(s)" removed (same keys) |
| `option.pipeline.verifiedDaysCharged` | replaced by `option.pipeline.daysCovered` (old key removed) |
| `warning.pipeline.onlyVerifiedCharged` | replaced by `warning.pipeline.coverageDaysStrong` (old key removed) |

## Open points (none concerns the locked messages)

- Every `proposed` message is a draft written for this implementation. **None has been reviewed by a native Somali speaker**, including warnings and confirmations.
- Waiting vs Waiting List: the sidebar and the status use the owner wording `Shaqa sugayaal`, the move-to-stage choice uses the approved `Liiska Sugitaanka`; the shared phrase was left as the owner approved it.
- `Employee Registration` and `Employees` open the same page; the menu structure was not changed.
- `Facility Report` (HR-NAV-038) was never on the owner's review list; its Somali (`Warbixinta Xarumaha`) is proposed.
- The owner text for `Advance to {Status}` wrote the variable as `{Xaalad}`; the variable name is kept as `{status}`.
- `Void` / `Void day` / `Void payment` share the approved `Ka noqo`; the three actions have different consequences, so their explanations differ.
- Terminology variants among the **proposed** messages (the same English with different Somali, for example Active, Absent, Company, Cancelled, Completed) are documented in `PREVIOUS_SOMALI_TRANSLATIONS_INVENTORY_2026-10-27.md`; a glossary is recommended. Variants that involve a locked message keep the locked wording exactly.
- Left in English on purpose: proper names and technical terms (Excel, CSV, PDF, USD, SMTP, file extensions, identifiers), XARUN, PROJECT and Damiin (the owner's own terms), and text that comes from the server or from users (names, notes, audit-log descriptions, Laravel validation sentences and API messages for which no mapped error code exists). The Excel files the backend produces keep their English column headings.
