# Fayadhowr Onboarding Checklist

| Field | Value |
| --- | --- |
| **Document ID** | `00_ONBOARDING_CHECKLIST` |
| **Document Type** | Mandatory Onboarding Checklist |
| **Version** | 1.0 |
| **Status** | Draft — pending Product Owner / Architect approval |
| **Audience** | Every AI assistant and every engineer, before any engineering work |

## Purpose

This checklist exists to guarantee that every contributor — human or AI — has actually absorbed the project's approved context before touching the system. Fayadhowr is a document-first, gate-governed project (see `docs/00_AI_PROJECT_CONTEXT.md`); skipping straight to code is how business rules get silently reinvented, UI gets silently redesigned, and approved contracts get silently broken.

**No architecture work, implementation, bug fixing, refactoring, or feature development may begin until every item below is checked.**

---

## Phase 1 – Project Understanding

- [ ] Read Project Constitution (`docs/00_Project_Constitution.md`)
- [ ] Read AI Project Context (`docs/00_AI_PROJECT_CONTEXT.md`)
- [ ] Understand Business Model (`docs/02_SRS.md` §1–§2)
- [ ] Understand Project Scope (`docs/02_SRS.md` §1.2–§1.5, `docs/07_Project_Roadmap.md`)
- [ ] Understand Customer Journey (`docs/04_UX_Flow.md`)
- [ ] Understand Backend V1 (`docs/03_Technical_Architecture_Document.md`, `docs/04_System_Design_Document.md`)
- [ ] Understand Flutter objectives (`docs/09_Flutter_Architecture.md`)

---

## Phase 2 – Documentation

- [ ] Read SRS (`docs/02_SRS.md`)
- [ ] Read Database Design (`docs/03_Database_Design.md`)
- [ ] Read Technical Architecture (`docs/03_Technical_Architecture_Document.md`)
- [ ] Read System Design (`docs/04_System_Design_Document.md`)
- [ ] Read API Design (`docs/06_API_Design.md`)
- [ ] Read UX Flow (`docs/04_UX_Flow.md`)
- [ ] Read UI/UX Design (`docs/05_UI_UX_Design.md`)
- [ ] Read Brand Guide (`docs/01_Brand_Design_Guide.md`)
- [ ] Read Figma Design System (`docs/08_Figma_Design_System.md`)
- [ ] Read Flutter Architecture (`docs/09_Flutter_Architecture.md`)

---

## Phase 3 – Governance

- [ ] Understand Documentation First (`docs/00_Project_Constitution.md`, `docs/00_AI_PROJECT_CONTEXT.md` §3)
- [ ] Understand Documentation Freeze (`docs/05_Documentation_Freeze.md`)
- [ ] Understand Architecture Freeze (`docs/09_Flutter_Architecture.md` §25)
- [ ] Understand Approval Gates (`docs/07_Project_Roadmap.md` §5)
- [ ] Understand Definition of Done (`docs/07_Project_Roadmap.md` §12)
- [ ] Understand Review Process (`docs/02_AI_Development_Playbook.md` §5)
- [ ] Understand AI Rules (`docs/01_Master_AI_Prompt.md`, `docs/02_AI_Development_Playbook.md`)

---

## Phase 4 – Technical Validation

- [ ] Confirm Backend V1 status with the Project Owner
- [ ] Confirm the current API Contract (`docs/06_API_Design.md`, `backend/routes/api.php`)
- [ ] Confirm the current Flutter phase (`docs/09_Flutter_Architecture.md`, `docs/07_Project_Roadmap.md`)
- [ ] Confirm the current project milestone (`docs/07_Project_Roadmap.md` §6)
- [ ] Confirm the latest approved documentation set with the Project Owner (`docs/05_Documentation_Freeze.md` is a starting point, not a guarantee it is still current)

---

## Phase 5 – Before Implementation

Verify that:

- [ ] Requirements are approved
- [ ] Architecture is approved
- [ ] No assumptions remain
- [ ] Questions have been answered
- [ ] Scope is clear
- [ ] Required documents have been reviewed
- [ ] Approval has been received

---

## Completion Statement

Only after every checklist item above has been completed may implementation begin. If any item cannot be checked, stop and raise it with the Project Owner rather than proceeding on assumption — per `docs/00_AI_PROJECT_CONTEXT.md` §19–§20, asking is always the correct default over guessing.

---

## Document Control

| Version | Date | Change |
| --- | --- | --- |
| 1.0 | 19 July 2026 | Initial onboarding checklist |
