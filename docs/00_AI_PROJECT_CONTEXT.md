# AI Project Context

## Fayadhowr — Permanent Onboarding Guide

| Field | Value |
| --- | --- |
| **Document ID** | `00_AI_PROJECT_CONTEXT` |
| **Product Name** | Fayadhowr |
| **Document Type** | AI / Engineer Onboarding Summary |
| **Version** | 1.1 |
| **Status** | Draft — pending Product Owner / Architect approval |
| **Audience** | Every AI assistant and every engineer, before any other action on this repository |

---

## How to Use This Document

This is a **summary index, not a replacement** for the detailed documentation. Every section below points to the document that is actually authoritative. If anything here ever appears to conflict with the document it references, **the referenced document wins** — update this file, not the other way around.

**Read this file first, in full, before touching code, docs, or Figma, on every new session.**

---

## Mandatory Reading

Every future AI assistant and every software engineer joining Fayadhowr must read this document **before beginning any work** on the project. This document is the project's **primary onboarding guide** and should be read **before** consulting the detailed documentation listed throughout it. It orients a new contributor to what already exists and how decisions are governed here, so that the detailed documents are read with the correct context rather than in isolation.

---

## 1. Project Overview

Fayadhowr is a customer-facing mobile commerce/services platform for a cleaning company operating in Somalia (Mogadishu, Hargeisa), built on a Laravel REST API backend and a Flutter customer mobile app, with a separate web Admin Panel for internal operations. The system is being delivered under a document-first, gated Agile Scrum process. Full detail: `02_SRS.md`, `07_Project_Roadmap.md`.

## 2. Business Overview

Two commercial lines: **Services** (Deep Cleaning, Pest Control, Carpet Cleaning, Sofa & Chair Cleaning, Post-Construction Cleaning, Window Cleaning, Fumigation, Housekeeper, Monthly Cleaning Staff — every active service supports both **Book Now** and **Request Quotation**) and a **Store** (Cleaning Chemicals, Tools, Accessories, PPE, Air Fresheners). Customers are individuals/households/small businesses; internal staff operate through the Admin Panel (Super Admin / Manager / Sales / Inventory / Accountant). Full detail: `02_SRS.md` §1–§8, §7–§8A.

## 3. Engineering Philosophy

Documentation-first, gate-driven, approved-scope-only delivery under 2-week Agile Scrum sprints. No implementation begins before the governing document is approved and frozen. Full detail: `00_Project_Constitution.md`, `07_Project_Roadmap.md` §2–§5.

## 4. Governance Rules

Every technical decision traces to an approved document. Business rules, UI/UX, architecture, and database schema may not change without explicit approval. Ambiguity is resolved by asking, never by guessing. Full detail: `00_Project_Constitution.md`, `01_Master_AI_Prompt.md`, `02_AI_Development_Playbook.md`.

## 5. Constitution Summary

`00_Project_Constitution.md` is the supreme governing document — its §12 Final Rule states it **overrides every other project document**. It sets rule areas for: vision/goals, business rules protection, UI/UX protection, architecture protection, code quality (SOLID, clean, non-duplicated), database rules (migrations only, no unapproved schema changes), API rules (REST only, consistent JSON, correct HTTP codes), security rules (validate input, authorize, protect sensitive data), documentation rules (traceability, sync), and AI rules (never guess, ask when unclear, explain before coding).

## 6. Documentation Hierarchy

Two numbering tracks coexist — a governance track and a product-design track:

| Governance track | Product-design track |
| --- | --- |
| `00_Project_Constitution.md` | `01_Brand_Design_Guide.md` |
| `01_Master_AI_Prompt.md` | `02_SRS.md` |
| `02_AI_Development_Playbook.md` | `03_Database_Design.md` |
| `05_Documentation_Freeze.md` | `03_Technical_Architecture_Document.md` |
| `ADR-001-Identity-Architecture.md` | `04_UX_Flow.md` |
| | `04_System_Design_Document.md` |
| | `05_UI_UX_Design.md` |
| | `06_API_Design.md` |
| | `07_Project_Roadmap.md` |
| | `08_Figma_Design_System.md` |
| | `09_Flutter_Architecture.md` |

`05_Documentation_Freeze.md` is the master record of which documents are formally approved. Consult it — not a document's own self-reported `Status:` header — to determine current approval state.

## 7. Source of Truth Hierarchy

1. **`00_Project_Constitution.md`** — overrides everything.
2. **Approved business/requirements documents** (`02_SRS.md`, ADR-001) — govern *what* the system does and *who* owns *what* data.
3. **Approved Figma file** — the **only** source of truth for UI (`09_Flutter_Architecture.md` §21). `08_Figma_Design_System.md` is a written description of it, not a substitute for it.
4. **Backend REST API** (`06_API_Design.md` as specified, `backend/routes/api.php` as actually implemented) — the **only** source of truth for the client-server contract. Flutter adapts to the API; the API is not redesigned for client convenience (`09_Flutter_Architecture.md` §1).
5. **`03_Database_Design.md`** — governs schema; changed only through approved migrations.
6. **`09_Flutter_Architecture.md`** — governs client structure, once formally frozen.

## 8. Technology Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel (`composer.json` pins `^13.8`), PHP `^8.3` |
| Database | PostgreSQL |
| API | REST, versioned `/api/v1`, Sanctum bearer tokens |
| Mobile | Flutter (Android + iOS), Riverpod 2, Dio, GoRouter, Drift |
| Admin Panel | Separate web surface, backed by the same Laravel app |

**Known drift:** `03_Technical_Architecture_Document.md` §2 states "Laravel 12" — stale versus `composer.json`. Treat `composer.json` as authoritative for installed versions.

## 9. Backend Overview

Layering: Controllers (thin) → Actions (single-responsibility use cases) → Services (cross-cutting domain workflows) → Repositories (data access) → Eloquent Models. Two authentication realms — `users`(+`customer_profiles`) for customers, `admins` for staff — are fully separate at the guard, middleware, and model level with no shared tokens or escalation path (ADR-001). Modules: Auth, Home/Search, Services Catalog, Store, Inventory, Booking, Quotation, Payment, Notification, Favorites, Reviews, Uploads, Customer Profile, Admin/RBAC, Reports, Accounting, Settings. Full detail: `03_Technical_Architecture_Document.md`, `04_System_Design_Document.md`.

## 10. Flutter Overview

Not yet started as an implementation. An architecture has been **drafted** (`09_Flutter_Architecture.md`): Feature-first modules with Clean Architecture layers (`data`/`domain`/`presentation`) and MVVM via Riverpod; Dio + single envelope/pagination normalizer; GoRouter; `flutter_secure_storage` for the Sanctum token; Drift for offline **read-only** caching; Material 3 themed from Figma tokens. This document is explicitly unfrozen — treat its contents as proposed, not binding, until Flutter Architecture Freeze is formally declared (§18 below).

## 11. Database Overview

Single PostgreSQL database as the system of record; Flutter and the Admin Panel both reach it only through the Laravel API — no direct client access. Design principles: SRS-first, modular domains, authoritative server-side state, soft deactivation over hard delete, commercial immutability of confirmed line items, polymorphic links only where justified (e.g. `payments.payable_type`/`payable_id`), least-privilege identity separation. Full detail: `03_Database_Design.md`.

## 12. API Overview

REST, `/api/v1`, stateless, Sanctum bearer auth. Standard envelope: success `{success, message, data, meta}`, error `{success, message, error_code, errors}`. Public catalog/search/home endpoints are guest-accessible under rate limiting; transactional endpoints require an authenticated, active customer. Server-computed business flags (e.g. quotation `can_accept`/`can_discuss`) are authoritative and must never be re-derived client-side. Full detail: `06_API_Design.md`; the living contract is `backend/routes/api.php`.

## 13. Figma Overview

`08_Figma_Design_System.md` specifies the intended Figma library structure: color/typography styles, buttons, cards, search bar, navigation, chips, badges, dialogs, and full component variants.

Approved Figma exists and is the official **UI Source of Truth** for the Flutter application. If a formal Figma Freeze document is not present in the repository, future contributors should verify the latest approval status with the Project Owner before proposing any UI or UX changes. The absence of a dedicated Freeze document must not be interpreted as permission to redesign the UI.

## 14. Security Principles

TLS everywhere; separate guards/tokens for customer (`users`) vs admin (`admins`) realms with no cross-realm escalation; Hybrid RBAC (role ∪ direct permissions) with least privilege; PCI minimization (no card PAN/CVV storage); server-side authorization on every protected resource; input validation and injection-safe querying (e.g. LIKE-wildcard escaping on search filters); rate limiting on auth/payment/public endpoints; audit logging on sensitive admin mutations; no secrets shipped in client builds. Full detail: `00_Project_Constitution.md` §9, `03_Database_Design.md` §7, `03_Technical_Architecture_Document.md` §13, `09_Flutter_Architecture.md` §15.

## 15. Development Workflow

Read the relevant approved documentation → explain the implementation plan → wait for clarification on anything unclear → implement only the approved scope → review before marking complete. List affected files before starting; summarize created/modified/pending work and risks afterward. Full detail: `01_Master_AI_Prompt.md`, `02_AI_Development_Playbook.md` §2, §7.

## 16. Review Workflow

Per-task: the Code Review Checklist — business rules preserved, approved UI/UX unchanged, database changes safe, security checked, performance considered, error handling included, validation included. Per-phase: Sprint Review demos to stakeholders, plus a short review artifact (checklist + demo notes) at every Go/No-Go gate. Full detail: `02_AI_Development_Playbook.md` §5, `07_Project_Roadmap.md` §5.

## 17. Approval Gates

Twenty phase gates (G1–G20) defined in `07_Project_Roadmap.md` §5, each with explicit Go criteria and named No-Go conditions. A phase may not be entered until the prior gate passes; partial/incomplete demos never bypass a gate; a No-Go returns work to the current phase with explicit remediation items rather than blocking indefinitely. Flutter Architecture Freeze functions as an additional gate between Figma approval and Flutter Foundation work.

## 18. Freeze Policy

Three freeze layers exist:

| Freeze | Record | Status |
| --- | --- | --- |
| **Documentation Freeze** | `05_Documentation_Freeze.md` | FINAL — Constitution, AI Prompt/Playbook, Technical Architecture, System Design, SRS, UI/UX Design, UX Flow, Database Design, API Design |
| **Figma Freeze** | *(no dedicated freeze document found in the repository)* | Approved Figma is the UI Source of Truth; verify latest approval status with the Project Owner before proposing UI/UX changes — the absence of a freeze document is not permission to redesign |
| **Flutter Architecture Freeze** | Defined in `09_Flutter_Architecture.md` §25 | Not yet declared as of last review |

Once frozen, the covered decisions (business rules, UI/UX, backend architecture, Flutter architecture/folder structure/state management/DI/navigation/Design System mapping) require explicit approval to change — they are not incidentally editable during feature work.

## 19. AI Rules

Never guess; never assume undocumented requirements; never redesign approved UI/UX; never change business rules or database schema without approval; ask when unclear; explain the plan before coding; list affected files before implementation; implement only the requested scope; summarize created/modified/pending work and risks after; keep implementation traceable to documentation; never generate fake data structures or APIs; treat AI coding tools (e.g. Laravel Boost) as assistants whose output is always reviewed, never auto-accepted. Full detail: `01_Master_AI_Prompt.md` (18 rules), `02_AI_Development_Playbook.md` §1, §6.

## 20. Developer Rules

Same governance obligations as AI rules, applied to human engineers: implement only approved scope; never bypass a Go/No-Go gate; schema changes only via reviewed migrations; REST-only API surface with consistent envelopes and correct HTTP status codes; keep code modular and traceable to the SRS/API Design/DB Design; escalate blocking defects in a frozen contract rather than silently working around them.

## 21. Coding Principles

SOLID, DRY, KISS, clean code. Feature-first separation of concerns. Thin controllers; business logic lives in Actions/Services, not controllers or widgets. Reuse existing components/Design System widgets — no one-off duplicated UI or logic. Explicit types at public boundaries. Comments only where the *why* is non-obvious. Full detail: `02_AI_Development_Playbook.md` §3, `09_Flutter_Architecture.md` §23.

## 22. Git Workflow

Observed convention (not a separately documented policy): single `main` branch, Conventional-Commits-style messages scoped by module (`feat(module): complete X module v1`, `fix(module): ...`, `design: finalize ... module`), one commit per completed sprint/module increment. Treat this as the established pattern to follow until/unless a dedicated Git policy document says otherwise.

## 23. Testing Philosophy

Backend tests are organized under `tests/Feature` and `tests/Unit`, mirrored by module (Booking, Customer, Inventory, Notification, Report, Settings, Uploads, Accounting, Dashboard, Favorites, Home, Reviews, Services, Auth, Sms, Admin). Definition of Done requires critical paths verified before a feature is considered complete, not just code merged. Flutter testing strategy (unit / widget / integration, coverage priority on `domain` + `data` parsers first) is specified in `09_Flutter_Architecture.md` §16, pending freeze. Full detail: `07_Project_Roadmap.md` Phase 17.

## 24. Definition of Done

A feature is done only when **all** are true: business requirements satisfied (SRS/approved stories); UI/UX approved (matches spec + Brand + Figma); API implementation complete against API Design; database implementation complete against DB Design; testing completed with critical paths verified; zero remaining critical bugs for that feature; documentation updated. Plus, project-wide: guest browse remains intact where required; soft-auth return-to-intent actually works; customers are never asked to re-enter information the system already holds. Full detail: `07_Project_Roadmap.md` §12.

## 25. Things That Must Never Be Changed Without Approval

- Approved business rules (`02_SRS.md`)
- Approved UI/UX and the approved Figma file
- Approved backend architecture (`03_Technical_Architecture_Document.md`) and, once frozen, approved Flutter architecture (`09_Flutter_Architecture.md`)
- Database schema outside of reviewed migrations (`03_Database_Design.md`)
- The API contract (`06_API_Design.md` / `backend/routes/api.php`)
- Identity architecture (ADR-001) — no second customer authentication identity, no cross-realm token sharing
- `00_Project_Constitution.md` itself

## Project Memory

Long-term context every future contributor should carry forward:

- **Backend V1 has already been completed.** This is not a greenfield project — substantial, working Laravel backend implementation exists across auth, catalog, booking, quotation, store, inventory, payments, notifications, admin, and more.
- **Flutter development begins after Backend V1 completion**, not in parallel with backend design. The backend was not designed around Flutter's convenience; Flutter is designed around the backend's reality.
- **Flutter must consume the existing Laravel Backend V1 as-is.** The API contract is a given, not a proposal.
- **The existing documentation represents accumulated project decisions**, not draft ideas open for reinterpretation. Each document was produced, reviewed, and (per `05_Documentation_Freeze.md`) frozen in sequence.
- **Previously approved business rules must be preserved** (`02_SRS.md`).
- **Previously approved API contracts must be preserved** (`06_API_Design.md`, `backend/routes/api.php`).
- **Previously approved UI decisions must be preserved** (approved Figma, `08_Figma_Design_System.md`, `05_UI_UX_Design.md`).
- Every future AI assistant should understand that **this project is continuing from an existing foundation**, not starting from scratch — new work extends and respects what has already been decided and built.

---

## Document Control

| Version | Date | Change |
| --- | --- | --- |
| 1.0 | 19 July 2026 | Initial AI Project Context onboarding summary |
| 1.1 | 19 July 2026 | Revised Figma status wording to reflect approved Figma as UI Source of Truth; added Project Memory section; added Mandatory Reading section |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner | | | |
| Technical Architect | | | |
