# Project Roadmap

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `07_Project_Roadmap` |
| **Product Name** | Fayadhowr |
| **Document Type** | Project Roadmap |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 14 July 2026 |
| **Audience** | Product owners, delivery managers, architects, engineering leads, stakeholders |
| **Basis Documents** | `01`–`06` approved design & specification set |

---

## Document Rules

This roadmap defines **how Fayadhowr Version 1 will be delivered**.

It intentionally does **not** include:

- Flutter / Dart implementation code
- Laravel / PHP implementation code
- Database migrations or SQL scripts
- UI mockups or Figma file contents

All phases must remain aligned with:

- Customer-first design  
- Do not ask customers for the same information twice  
- Login only when required  
- Keep Version 1 focused and production-ready  

---

# 1. Project Vision

Fayadhowr is a **modern, professional, trustworthy cleaning company** customer mobile application that unifies:

- Discovery of cleaning **services** (fixed-price booking and quotation-based work)
- A priced **store** for products (with optional product quotations)
- Secure **bookings**, **quotations**, **orders**, **payments**, **favorites**, **notifications**, and **profile** management

### Vision outcomes for Version 1

1. Customers see **value before login** — Home, services, products, galleries, reviews, FAQ, and contact without forced authentication.
2. Customers complete commercial actions with **minimal steps** and **clear trust signals** (prices, references, statuses, confirmations).
3. The business operates through a coherent digital channel backed by a solid API and database design.
4. The product ships as a **focused, production-ready v1** — not an unfinished platform of future modules.

---

# 2. Development Methodology

## 2.1 Agile Scrum

Fayadhowr delivery uses **Agile Scrum**:

| Ceremony | Purpose |
| --- | --- |
| Sprint Planning | Select backlog items for the sprint; confirm DoD and dependencies |
| Daily Stand-up | Surface blockers; align on progress |
| Sprint Review | Demo working increments to stakeholders; collect feedback |
| Sprint Retrospective | Improve process quality and delivery predictability |
| Backlog Refinement | Keep stories ready: clear acceptance criteria, estimates, and document links |

## 2.2 Sprint-Based Development

| Practice | Application |
| --- | --- |
| Fixed sprint length | Recommended **2 weeks** (adjustable by team agreement) |
| Increment | Each sprint produces a potentially shippable slice where feasible |
| Document-first gates | UI/build/API work starts only after prior phase approvals (see §5) |
| Cross-functional teams | Mobile, backend, QA, design, and product collaborate within sprints |
| Continuous integration | Code integrates frequently; automated checks grow from Phase 17 |

Stories must reference approved SRS / UX / UI / API / DB requirements. Scope creep is deferred to **Future Versions (§10)** unless explicitly re-prioritized by the Product Owner.

---

# 3. Project Phases

Phases are sequential for **major dependencies**, with limited parallelization only where gates allow (for example Flutter and Laravel setup after documentation and Figma approvals).

| Phase | Name | Objective |
| --- | --- | --- |
| **1** | Documentation | Approve Brand, SRS, Database, UX Flow, UI/UX Design, API Design, Roadmap |
| **2** | Figma UI Design | Produce visual designs/components aligned to Brand + UI/UX Spec |
| **3** | Flutter Project Setup | App shell, navigation, theming, environments, CI basics |
| **4** | Laravel Backend Setup | API project, auth scaffolding baseline, environments, CI basics |
| **5** | Database Implementation | Implement approved schema (tables, indexes, integrity) |
| **6** | Authentication | Register, login, logout, forgot/reset password, tokens, soft auth |
| **7** | Home Module | Hero, search, categories, featured services, products, gallery, reviews, FAQ, contact |
| **8** | Services Module | Catalog, details, search, book/quote CTAs |
| **9** | Store Module | Catalog (V1 physical categories), Selling Price, `product_images`, cart, checkout **preview**, `store_orders` (`STO-…`) + Unified Payment; stock decrease only after Payment Paid + one-time `RCPT-…` |
| **9A** | Inventory Module | Suppliers (`status` active/inactive), POs (Draft→Submitted→**Approved**→…), Goods Receipts only after approval, `stock_ledgers`, adjustments, low-stock dashboard (separate from Store) |
| **10** | Quotation Module | Requests, uploads (`POST /uploads`), description, Discuss Quotation, revisions, timeline, Accept (no Reject) |
| **11** | Booking Module | Create, details, history, cancel (policy) |
| **12** | Cart & Checkout | Cart management, checkout, order create/confirm |
| **13** | Payment Integration | Initialize, webhook/callback, success/failure, history |
| **14** | Favorites | Add/remove/list; heart on service/product cards |
| **15** | Notifications | List, mark read, unread count, push wiring |
| **16** | Profile | Profile, addresses, histories entry, settings, legal, logout |
| **17** | Testing | Functional, integration, regression, device/QA cycles |
| **18** | Bug Fixes | Resolve blockers, criticals, and high-priority defects |
| **19** | Performance Optimization | API/app performance, caching, payload and media tuning |
| **20** | Production Release | Soft launch / production cutover, monitoring, support readiness |

### Phase dependency summary

```text
Phase 1 Docs
    → Phase 2 Figma
        → Phase 3 Flutter Setup  ║  Phase 4 Laravel Setup
                                 ╚→ Phase 5 Database
                                      → Phase 6 Auth
                                          → Phases 7–16 Feature Modules
                                              → Phase 17 Testing
                                                  → Phase 18 Bugs
                                                      → Phase 19 Performance
                                                          → Phase 20 Release
```

Feature modules (7–16) follow the order above for dependency clarity; Favorites (14) and Notifications (15) may overlap with Profile (16) after core commerce paths exist, still under Go/No-Go review.

---

# 4. Sprint Planning

Assumes **2-week sprints**. Exact sprint count may flex by team velocity; order remains binding.

| Sprint | Focus | Primary phases / modules |
| --- | --- | --- |
| **Sprint 0** | Mobilization | Phase 1 completion sign-off; backlog creation; environment planning |
| **Sprint 1** | Design baseline | Phase 2 Figma (core flows: Home, Auth, Services, Store) |
| **Sprint 2** | Design completion | Phase 2 remaining screens (Quote, Booking, Cart, Payment, Profile, Favorites, Notifications) + design QA |
| **Sprint 3** | Foundations | Phase 3 Flutter setup + Phase 4 Laravel setup (parallel) |
| **Sprint 4** | Data & Auth | Phase 5 Database implementation + Phase 6 Authentication (API + app soft gate) |
| **Sprint 5** | Home + Browse shell | Phase 7 Home Module; navigation shell; search entry |
| **Sprint 6** | Services | Phase 8 Services Module (list, detail, search, CTA wiring) |
| **Sprint 7** | Store | Phase 9 Store Module (list, Selling Price, cart; stock decrease only after Payment Paid) |
| **Sprint 8** | Quotation | Phase 10 Quotation Module (uploads, description, request, Discuss, revisions, Accept) |
| **Sprint 9** | Booking | Phase 11 Booking Module |
| **Sprint 10** | Cart & Checkout | Phase 12 Cart & Checkout / orders |
| **Sprint 11** | Payments | Phase 13 Payment Integration |
| **Sprint 12** | Account extras | Phase 14 Favorites + Phase 15 Notifications + Phase 16 Profile polish |
| **Sprint 13** | System test | Phase 17 Testing (full regression pass 1) |
| **Sprint 14** | Stabilize | Phase 18 Bug Fixes + start Phase 19 Performance |
| **Sprint 15** | Release candidate | Phase 19 complete + Phase 20 Production Release |

### Sprint planning rules

1. No feature sprint starts without approved docs (Phase 1) and approved Figma for that module (Phase 2 gate).
2. Backend contracts follow `06_API_Design.md`; schema follows `03_Database_Design.md`.
3. Soft auth and guest browse must be preserved in every sprint review demo involving catalog screens.
4. Quotation uploads in v1 already include **images, videos, and PDF** — do not schedule these as future work.

---

# 5. Go / No-Go Gates

**The project must not advance to the next phase until the current phase is reviewed and approved.**

| Gate ID | After phase | Go criteria | No-Go if |
| --- | --- | --- | --- |
| **G1** | Phase 1 Documentation | All `01`–`07` docs reviewed; Product Owner + Architect approve | Gaps vs business rules; conflicting docs |
| **G2** | Phase 2 Figma | Brand compliance; UI/UX coverage of inventory; stakeholder UI approval | Missing critical screens; brand color/type violations |
| **G3** | Phase 3 Flutter Setup | Runnable app shell, theme tokens, env configs, CI smoke | Unstable tooling; no agreed structure |
| **G4** | Phase 4 Laravel Setup | API boots; `/api/v1` routing baseline; env/secrets strategy | Insecure defaults; no deploy path |
| **G5** | Phase 5 Database | Schema matches approved DB design; migrations reviewed | Missing tables/fields (e.g., quotation `description`, attachments) |
| **G6** | Phase 6 Authentication | Register/login/logout/recovery works; token security reviewed | Guest browse broken by forced login |
| **G7** | Phase 7 Home | Home block order correct; search available; guest access verified | Login wall; missing Search under Hero |
| **G8** | Phase 8 Services | Details + Book/Quote CTAs match pricing model | Wrong primary CTA; broken soft auth |
| **G9** | Phase 9 Store | Selling Price always visible; cart add works; checkout is preview; Store Order create (`STO-…`) does not decrease stock | Hidden Selling Price; Cost Price exposed to customers; stock decreased at order create; checkout creates order without preview split |
| **G9A** | Phase 9A Inventory | PO alone does not change stock; GR only after PO approval; Goods Receipt + Paid sale write `stock_ledgers`; low-stock dashboard | Stock changed by PO; GR on submitted PO; missing ledger; Email/SMS required for V1 low-stock |
| **G10** | Phase 10 Quotation | Uploads + description + Accept/Discuss + revisions + QT references | Missing file types; `/reject` present; cannot attach file IDs |
| **G11** | Phase 11 Booking | Create/history/cancel policy verified | Capacity/validation bugs; duplicate bookings |
| **G12** | Phase 12 Cart & Checkout | Order create + confirmation; overselling rejected; stock unchanged until Payment Paid | Stock decreased at checkout; negative stock allowed |
| **G13** | Phase 13 Payments | Initialize + webhook idempotency + success/failure UX | Client-only “success”; amount mismatches |
| **G14** | Phase 14 Favorites | Auth save/list; hearts on cards | Favorites mutating checkout/booking flows |
| **G15** | Phase 15 Notifications | List/read/count + deep links | Secrets in payloads; missing owner scope |
| **G16** | Phase 16 Profile | Profile/addresses/histories/logout complete | Asking duplicate data already known |
| **G17** | Phase 17 Testing | Test plan executed; critical paths green | Untested payments/booking/quote |
| **G18** | Phase 18 Bug Fixes | No open critical/blocker bugs | Critical defects remain |
| **G19** | Phase 19 Performance | Agreed NFR targets met or waived | Severe latency on Home/checkout |
| **G20** | Phase 20 Release | Runbook, monitoring, support ready | No rollback / secrets / store listing readiness |

### Gate conduct

- Each gate requires a short review artifact (checklist + demo notes).
- **No-Go** returns work to the current phase with explicit remediation items.
- Partial module demos do not bypass module gates.

---

# 6. Milestones

| Milestone | Description | Target phase completion |
| --- | --- | --- |
| **M1 — Spec Freeze** | Approved documentation set (`01`–`07`) | Phase 1 |
| **M2 — Design Freeze** | Approved Figma for v1 screens/components | Phase 2 |
| **M3 — Engineering Kickoff** | Flutter + Laravel foundations ready | Phases 3–4 |
| **M4 — Data & Identity Ready** | Database + Authentication live in non-prod | Phases 5–6 |
| **M5 — Browse MVP** | Home + Services + Store guest browse | Phases 7–9 |
| **M6 — Quote & Book MVP** | Quotation + Booking end-to-end in non-prod | Phases 10–11 |
| **M7 — Commerce MVP** | Cart, checkout, payments end-to-end | Phases 12–13 |
| **M8 — Account MVP** | Favorites, notifications, profile complete | Phases 14–16 |
| **M9 — Release Candidate** | Testing + bug fixes + performance signed off | Phases 17–19 |
| **M10 — Production Launch** | Fayadhowr customer app live | Phase 20 |

---

# 7. Risks

## 7.1 Technical Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Payment provider delays or webhook unreliability | Blocked Phase 13 / launch | Early sandbox integration; idempotent webhooks; confirming state UX |
| Large media uploads (video) strain mobile/API | Failures in quotation flow | Size limits; progress UX; background upload; storage quotas |
| Scope creep beyond v1 | Schedule slip | Gate process; Future Versions backlog only |
| Soft auth regressions (forced login) | Violates UX/SRS | Gate checklists; automated guest-path tests |
| Contract drift between Flutter and API | Integration rework | API Design as source of truth; contract reviews each sprint |

## 7.2 Business Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Unclear quotation turnaround expectations | Customer distrust | Clear confirmation copy + notifications |
| Catalog/pricing not ready operationally | Empty store/services | Content readiness as Phase 7–9 dependency |
| Policy gaps (cancel/refund) | Support load | Document policies before Phase 11–13 gates |
| Underestimating admin operations need | Ops bottleneck | Parallel admin readiness planning (admin UI may be separate track) |

## 7.3 Schedule Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Figma approval slips | Cascade delay on all build sprints | Time-box design reviews; prioritize critical path screens |
| Parallel Flutter/Laravel misalignment | Rework in Sprint 4+ | Shared API contract walkthroughs |
| Extended bug bash | Launch delay | Continuous testing from Sprint 5; Definition of Done enforced |
| Resource unavailability | Missed sprint goals | Cross-train; reduce WIP; protect critical path phases 10–13 |

---

# 8. Success Criteria

Version 1 is successful when measurable criteria are met:

| # | Criterion | Measure |
| --- | --- | --- |
| 1 | Guest value before login | Home and catalogs usable without authentication |
| 2 | Soft auth correctness | Book, quote, checkout, favorites save, profile require login; browse does not |
| 3 | Store price transparency | 100% of active products expose Selling Price in list and detail APIs/UI; Cost Price never customer-facing |
| 4 | Quotation completeness | Customers can submit service/product quotes with description + images/videos/PDF via single upload endpoint |
| 5 | Booking path | Customer can create, view, and (policy permitting) cancel bookings |
| 6 | Commerce path | Cart → checkout preview → Store Order (`STO-…`) → payment confirmation works end-to-end |
| 7 | Payments | Server-authoritative success/failure; no client-only paid state |
| 8 | Favorites | Save/remove/list without affecting booking/cart/payment workflows |
| 9 | Notifications | Customer receives and can open key lifecycle events |
| 10 | Quality gate | Zero open critical/blocker defects at release |
| 11 | Documentation alignment | Implemented behavior matches approved `01`–`06` specs |
| 12 | Production readiness | Monitoring, support contacts, and rollback plan in place |

---

# 9. Deliverables

| # | Deliverable | Phase |
| --- | --- | --- |
| 1 | Brand Design Guide | 1 (complete) |
| 2 | Software Requirements Specification (SRS) | 1 (complete) |
| 3 | Database Design Specification | 1 (complete) |
| 4 | UX Flow Specification | 1 (complete) |
| 5 | UI/UX Design Specification | 1 (complete) |
| 6 | API Design Specification | 1 (complete) |
| 7 | Project Roadmap (this document) | 1 |
| 8 | Approved Figma UI kit & screens | 2 |
| 9 | Flutter application codebase (v1) | 3–16 |
| 10 | Laravel API codebase (v1) | 4–16 |
| 11 | Implemented database schema | 5 |
| 12 | Integrated payment configuration (sandbox + production) | 13, 20 |
| 13 | Test plans, test evidence, defect log | 17–18 |
| 14 | Performance report / tuning notes | 19 |
| 15 | Production release package & runbook | 20 |
| 16 | App store / distribution listings (as applicable) | 20 |
| 17 | Support & maintenance handover notes | 20–11 |

---

# 10. Future Versions

The following are **out of Version 1 scope** and may be considered later.

### Explicitly not future work (already in Version 1)

- PDF quotation attachments  
- Video quotation attachments  
- **Discuss Quotation** messaging and additional file uploads on the **same** quotation (never Reject Quotation)  
- Quotation revision control (v1, v2, v3…) and timeline  
- Unified reference numbers (`CUS`, `BK`, `QT`, `ORD`, `PAY`, `INV`, `REF`)

These are already supported in v1 quotation uploads and related quotation documentation (`02_SRS`, `03`–`06`).

### Candidate future enhancements

- Live order tracking  
- Loyalty & rewards program  
- Promotional coupons  
- Multi-language support  
- AI-assisted quotation recommendations  
- Multi-option parallel quote packages / e-signature  
- Advanced analytics  
- Push notification preference center / marketing campaigns  

Future items enter the backlog only after v1 launch priorities and Product Owner approval. They must not delay Phases 17–20 unless explicitly re-scoped.

---

# 11. Maintenance Plan

Post-launch, Fayadhowr enters continuous improvement under a lightweight support rhythm.

| Area | Practice |
| --- | --- |
| **Monitoring** | API health, payment webhook failures, crash reporting, upload error rates |
| **Support** | Triage customer issues; severity SLA for critical commerce defects |
| **Patch releases** | Hotfixes for blockers; scheduled minor releases for improvements |
| **Security** | Dependency updates, token/rotation hygiene, upload allow-list reviews |
| **Content & catalog** | Ongoing service/product/media freshness via admin operations |
| **Backlog** | Convert production learnings into prioritized stories; protect v1 stability |
| **Documentation** | Update specs when behavior changes (Definition of Done) |
| **Performance** | Periodic review of Home, search, checkout, and media upload latency |

Maintenance does not silently expand scope into Future Versions without roadmap revision and stakeholder approval.

---

# 12. Definition of Done

A feature is considered **complete** only when **all** of the following are true:

1. **Business requirements are satisfied** (SRS / approved stories).  
2. **UI/UX is approved** (matches UI/UX Spec + Brand + Figma where applicable).  
3. **API implementation is complete** (matches API Design contracts).  
4. **Database implementation is complete** (matches Database Design).  
5. **Testing is completed** (unit/integration/UI as agreed; critical paths verified).  
6. **No critical bugs remain** for that feature.  
7. **Documentation is updated** (specs/changelog/runbooks as needed).  

Additionally for Fayadhowr v1:

- Guest browse remains intact where required.  
- Soft auth return-to-intent works for protected actions.  
- Customers are not asked to re-enter information the system already holds (e.g., saved addresses) without reason.

---

# 13. Documentation Summary

| Doc ID | File | Role |
| --- | --- | --- |
| **01** | `docs/01_Brand_Design_Guide.md` | Brand colors, type, components, logo/app icon rules |
| **02** | `docs/02_SRS.md` | Business & functional requirements, workflows |
| **03** | `docs/03_Database_Design.md` | Logical schema, integrity, attachments, quotation `description` |
| **04** | `docs/04_UX_Flow.md` | Customer journeys, soft auth, Home order, navigation |
| **05** | `docs/05_UI_UX_Design.md` | Screen inventory, components, favorites, quotation UI |
| **06** | `docs/06_API_Design.md` | REST `/api/v1` contracts, uploads, security |
| **07** | `docs/07_Project_Roadmap.md` | Delivery plan, gates, milestones, DoD (this document) |

Every phase must align with these approved documents. Conflicts are resolved by Product Owner + Architect update to the controlling spec **before** implementation proceeds.

---

## Alignment Checklist (Version 1 Focus)

| Principle | Roadmap enforcement |
| --- | --- |
| Customer-first | Gate demos prioritize clarity, trust, and recovery |
| No duplicate questions | Profile/address reuse in booking & checkout acceptance tests |
| Login only when required | Explicit G6–G9 and G14 checks |
| Production-ready v1 | Phases 17–20 mandatory; Future Versions quarantined |
| Spec alignment | G1 freeze + DoD documentation clause |
| Quotation V1 | Accept + Discuss only; six statuses; QT / revision rules; no Reject |

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/07_Project_Roadmap.md` |
| **Excludes** | Flutter code, Laravel code, migrations, UI mockups |
| **Governance** | Go/No-Go gates (§5); Definition of Done (§12) |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Agile Delivery Manager |  |  |  |
| Technical Architect |  |  |  |
| Engineering Lead |  |  |  |

---

*End of Document — Fayadhowr Project Roadmap v1.0*
