# Software Requirements Specification (SRS)

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `02_SRS` |
| **Product Name** | Fayadhowr |
| **Document Type** | Software Requirements Specification |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 13 July 2026 |
| **Audience** | Product owners, architects, engineers, QA, stakeholders |
| **Related Stack (planned)** | Flutter (customer mobile), Laravel (API & admin), relational database |

---

## 1. Project Overview

### 1.1 Purpose

This Software Requirements Specification defines the functional and non-functional requirements for **Fayadhowr**, a professional **customer-only** mobile application. Fayadhowr enables registered customers to discover and purchase products from a digital store, request and manage service bookings, obtain quotations for custom or complex work, complete payments, receive operational notifications, and manage their personal account.

This document is the authoritative requirements baseline **before** any UI design, Flutter implementation, Laravel API development, or database schema design proceeds.

### 1.2 Product Scope

Fayadhowr consists of two primary surfaces:

| Surface | Audience | Purpose |
| --- | --- | --- |
| **Customer Mobile App** | End customers | Browse services and store catalog, book services, request quotations, pay, track orders/bookings, manage profile, receive notifications |
| **Admin Panel (Web)** | Internal staff / administrators | Manage catalog, services, bookings, quotations, orders, payments, customers, content, and system configuration |

Out of scope for the **customer mobile app**:

- Staff, technician, or driver mobile apps
- Multi-vendor marketplace seller portals
- Public unauthenticated commerce beyond limited browsing (if enabled by policy)
- Social networking features unrelated to service delivery and commerce

### 1.3 Product Vision

Fayadhowr is a unified digital channel where customers can:

1. Shop for products through a curated store.
2. Book standardized services with clear scheduling and status tracking.
3. Request quotations when services require assessment, customization, or variable pricing.
4. Pay securely and receive confirmation and progress updates.
5. Maintain a trusted profile and history of activity with the business.

### 1.4 Assumptions

- The business operates a single brand (Fayadhowr), not a multi-tenant SaaS marketplace in v1.
- Backend services will be exposed via authenticated APIs consumed by the Flutter app.
- An admin panel will exist to operate catalog, fulfillment, and customer support workflows.
- Payments will integrate with one or more payment providers approved for the target market(s).
- Push and in-app notifications are required for operational communication.
- Localization (language/currency) may be introduced; v1 requirements assume a primary language and currency configurable at system level.

### 1.5 Constraints

- Customer mobile experience only (no customer web portal required in v1 unless separately approved).
- No UI, code, or database design is authorized until this SRS (and subsequent approved design docs) are accepted.
- Security, privacy, and payment compliance constraints apply from day one.
- Offline capability is limited; core transactional flows require network connectivity.

### 1.6 Definitions and Acronyms

| Term | Definition |
| --- | --- |
| **Customer** | An end user who registers and uses the Fayadhowr mobile app; authenticated through `users` with linked business/profile data in `customer_profiles` |
| **User** | The sole customer authentication identity, stored in `users` |
| **Customer Profile** | The one-to-one business/profile record linked to `users`; it owns customer business references and approved profile-scoped records |
| **Service** | An offering delivered by Fayadhowr that supports both **Book Now** and **Request Quotation** |
| **Store Product** | A sellable item in the e-commerce catalog |
| **Booking** | A customer reservation for a service at a requested date/time or slot |
| **Quotation (Quote)** | A priced proposal issued by the business in response to a customer request |
| **Order** | A commercial transaction for store products (and optionally related fees) |
| **Admin** | An authorized internal user of the admin panel |
| **SRS** | Software Requirements Specification |
| **NFR** | Non-Functional Requirement |
| **FR** | Functional Requirement |

---

## 2. Business Objectives

### 2.1 Primary Objectives

1. **Centralize customer engagement** — Provide one trusted mobile channel for store purchases, service bookings, and quotation requests.
2. **Increase conversion** — Reduce friction from discovery → request/order → payment → confirmation.
3. **Improve operational visibility** — Give customers clear status on bookings, quotes, orders, and payments; give admins tools to fulfill them.
4. **Build customer retention** — Maintain profiles, history, and timely notifications that encourage repeat use.
5. **Support scalable growth** — Design modules so catalog, services, payments, and notifications can grow without rewriting core flows.

### 2.2 Success Metrics (Indicative)

| Metric | Intent |
| --- | --- |
| Successful booking completion rate | Measure friction in booking workflow |
| Quotation request → acceptance rate | Measure quote quality and turnaround |
| Store checkout completion rate | Measure commerce conversion |
| Payment success rate | Measure payment reliability |
| Notification delivery/open engagement | Measure communication effectiveness |
| Support tickets related to “status unknown” | Measure clarity of tracking |

Exact numeric targets shall be defined by product leadership after baseline analytics are available.

### 2.3 Business Rules (High Level)

- Only authenticated customers may complete bookings, quotation requests, store checkouts, and payments.
- Prices shown to customers must match the authoritative backend price at confirmation time.
- Quotation-based services cannot be paid until a valid quote is accepted by the customer. Payment timing after acceptance follows the **service payment policy** (`full_before_service`, `deposit`, `pay_after_service`) — see Quotation Workflow and §23.
- Cancelation and refund eligibility follow configured business policies enforced by the backend.
- Admins are the source of truth for catalog availability, schedule capacity, quote issuance, and fulfillment status.

---

## 3. Target Users

### 3.1 Primary Users

| Persona | Description | Primary Goals |
| --- | --- | --- |
| **Retail Customer** | Individual buying products from the store | Browse, purchase, track orders, pay |
| **Service Customer** | Individual or household needing bookable services | Book slots, track progress, pay, communicate via notifications |
| **Quote Seeker** | Customer needing custom/assessed work | Submit requirements, review quotes, Accept or Discuss, pay after accept |

### 3.2 Secondary Users (Non-Mobile)

| Persona | Description | Primary Goals |
| --- | --- | --- |
| **Super Admin** | Full Admin Panel control; implicit all permissions | Admin CRUD, role/direct permissions, all modules, audit |
| **Manager** | Operations Admin Panel operator | Modules granted via Hybrid RBAC |
| **Sales** | Commerce-facing Admin Panel operator | Modules granted via Hybrid RBAC |
| **Inventory** | Inventory / store operations operator | Modules granted via Hybrid RBAC |
| **Accountant** | Finance-facing Admin Panel operator | Modules granted via Hybrid RBAC |

Admin users share **one Admin Login** and **one Admin Panel**. After authentication, Dual Dashboard Architecture selects Super Admin vs Operations dashboard; sidebar/module visibility follows effective permissions.

### 3.3 User Characteristics

- Mobile-first users on Android and/or iOS.
- Expect clear pricing, transparent status, and reliable payment confirmation.
- May have intermittent connectivity; the app must fail gracefully and recover safely.
- Varying digital literacy; flows must be guided and unambiguous.

---

## 4. User Roles

### 4.1 Role Model

| Role | Surface | Capabilities (Summary) |
| --- | --- | --- |
| **Guest** | Mobile | Limited browse of public catalog/content (if enabled); cannot transact |
| **Customer** | Mobile | Full customer features after authentication |
| **Super Admin** | Admin Panel | Implicit all permissions; Super Admin Dashboard; admin/role management |
| **Manager** | Admin Panel | Operations Dashboard; modules from Hybrid RBAC |
| **Sales** | Admin Panel | Operations Dashboard; modules from Hybrid RBAC |
| **Inventory** | Admin Panel | Operations Dashboard; modules from Hybrid RBAC |
| **Accountant** | Admin Panel | Operations Dashboard; modules from Hybrid RBAC |

> Admin Panel roles are exactly these five: **Super Admin**, **Manager**, **Sales**, **Inventory**, **Accountant**. There is no Staff Management (field workforce) module in v1.

> **Identity architecture:** Customer is a mobile application role backed by `users` authentication and a linked `customer_profiles` record. Admin-panel roles are backed by the separate `admins` identity. These authentication realms do not share credentials, guards, or tokens.

### 4.1A Admin Panel Authentication & RBAC

- **One Admin Login** page only — no separate login pages per role.
- **One Admin Panel** application with **Dual Dashboard Architecture**: Super Admin Dashboard vs Operations Dashboard (Manager / Sales / Inventory / Accountant).
- After authentication, inactive admins are rejected on protected admin routes (existing tokens included).
- **Hybrid RBAC:** effective permissions = role permissions ∪ direct admin permissions (additive). Super Admin permissions are implicit and not persisted.
- Permission catalog keys must align with protected admin routes; do not invent keys for unimplemented modules.
- Header displays **Welcome, {User Name}** and **Role: {Role Name}**.
- Dashboard statistics are cached per admin and invalidated on relevant Admin mutations.
- Sensitive admin mutations dispatch **AuditEvent** (event-driven audit logs).

### 4.2 Authentication & Authorization Principles

- Login method priority: **(1) Phone Number (default)** → **(2) Google Sign-In** → **(3) Email**.
- Phone remains the primary authentication method for Somalia; default country is **Somalia (+252)**.
- Phone login verifies via **OTP**.
- Google Sign-In uses native Android/iOS account pickers with accounts already on the device (customer does not type a Gmail address). The server verifies the provider ID token and links the account via `users.google_subject`.
- Email login remains fully supported (email + password, Show/Hide, Forgot Password, Remember Me).
- Sessions/tokens must expire and be refreshable according to security policy.
- Hybrid RBAC applies to the admin panel (role + direct permissions).
- Customers may only access their own data (bookings, quotes, orders, payments, profile).
- Admin-panel authentication operates on `admins` only; admin roles do not exist on `users`.
- Cross-realm privilege escalation must be impossible: `users` cannot obtain admin roles or tokens, and `admins` cannot authenticate as customers on mobile endpoints.

### 4.2A Identity Architecture

- `users` is the only customer authentication principal.
- `customer_profiles` owns customer business and profile data.
- Business modules reference `customer_profiles` where approved by their domain architecture; Booking uses `customer_profile_id`.
- There is no standalone `customers` authentication identity table.

### 4.2B Authentication Flows

Success and failure conditions below apply to the endpoints specified in the API Design document (§2). Detailed limits live in FR-002A–FR-003B.

**Phone + OTP login**

1. Customer enters phone number (default country +252) → client calls OTP request.
2. System generates a 6-digit OTP, stores only its hash with expiry and purpose, invalidates prior unconsumed OTPs for that phone/purpose, and dispatches it through the SMS abstraction.
3. Customer enters the code → client calls OTP verify.
4. Success: OTP valid, unexpired, unconsumed, attempts under cap, `users.status` allows authentication, linked customer profile is not `BLOCKED`/`INACTIVE`/deleted → OTP marked consumed, `phone_verified_at` set (first time), token issued, `last_login_at` updated, `login` activity recorded.
5. Failure: wrong code (attempt counter increments), expired/consumed code, attempt cap reached (OTP invalidated), resend cooldown or hourly cap active, or blocked account status → authentication refused with a stable error code; no token issued.
6. Resend: allowed only after the cooldown; issues a fresh OTP and invalidates the previous one.

**Google Sign-In**

1. Client obtains an ID token from the native Google account picker and sends it to the server.
2. Success: token signature/audience/expiry verified → account resolved by `google_subject`, else linked by verified email, else auto-provisioned (identity + customer profile) → token issued subject to the same status gates as other methods.
3. Failure: invalid/expired/wrong-audience token, or blocked/suspended account → refused; no partial account creation.

**Forgot / Reset Password**

1. Customer submits identifier → system responds generically (no account-existence leak). Both recovery paths are supported in V1: the email path issues a hashed single-use reset token (60 min); the phone path issues an OTP with `password_reset` purpose.
2. Customer submits token/OTP + new password + confirmation.
3. Success: credential updated (one-way hash), token/OTP consumed, **all** access tokens revoked, `password_reset` activity recorded.
4. Failure: invalid/expired/used token, password confirmation mismatch, or rate limit exceeded → refused; existing password and sessions unchanged.

### 4.3 Account Lifecycle

- Registration creates a `users` identity and linked `customer_profiles` record → verification (as required) → active use.
- Profile updates may change `users` authentication/contact fields or `customer_profiles` business/display fields according to ownership rules.
- Account deactivation / deletion request applies to the `users` identity and linked profile, subject to legal and retention policy.
- Admin accounts have a separate lifecycle on `admins`.

---

## 5. Functional Requirements

Requirements are identified as **FR-xxx**. Priority: **Must** / **Should** / **Could**.

### 5.1 Account & Access

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-001 | The system shall allow a customer to register by creating a **`users`** identity with **Phone Number (primary)** and optional Email, plus Password and **Confirm Password** (must match), and a linked **`customer_profiles`** record. Google users may provision both after Google Sign-In when required. | Must |
| FR-002 | The system shall authenticate customers against **`users`** in this priority: (1) Continue with Phone + OTP (default country Somalia +252), (2) Continue with Google (native device accounts), (3) Continue with Email + password; and maintain a secure session bound to the `users` principal. | Must |
| FR-002A | The system shall issue one-time passwords (OTP) for phone authentication on request, delivered via the SMS provider abstraction. Each OTP shall be numeric (6 digits), single-use, bound to one phone number and one purpose, and shall expire after a short validity window (5 minutes). Requesting a new OTP invalidates any previous unconsumed OTP for the same phone and purpose. | Must |
| FR-002B | The system shall enforce OTP abuse controls: a resend cooldown (60 seconds between requests per phone), a request cap (maximum 5 OTP requests per phone per hour), and a verification attempt cap (maximum 5 failed attempts per OTP, after which the OTP is invalidated and a new one must be requested). All OTP endpoints shall additionally be rate limited per IP. | Must |
| FR-002C | The system shall verify OTPs server-side against a stored hash (raw OTP values are never persisted), mark the OTP consumed on success, set `users.phone_verified_at` when applicable, and issue a session token bound to the `users` principal. Expired, consumed, or unknown OTPs shall never authenticate. | Must |
| FR-002D | The system shall support Google Sign-In by verifying the provider ID token server-side (signature, expiry, and audience), then resolving the account in this order: (1) existing `users.google_subject` match, (2) existing verified-email match (link `google_subject`), (3) auto-provision a new `users` identity and linked `customer_profiles` record per FR-001. Google authentication respects the same `users.status` and `customer_profiles.status` gates as all other login methods. | Must |
| FR-002E | SMS delivery for OTPs shall go through a provider-independent SMS abstraction (single send contract). No specific SMS provider is selected in this specification; the implementation must allow swapping providers via configuration without changing business logic. | Must |
| FR-003 | The system shall allow customers to reset or recover credentials securely (email/password path includes Forgot Password). | Must |
| FR-003A | Forgot Password shall accept the account identifier (email or phone). **Backend V1 supports both recovery paths: Email Password Recovery and Phone OTP Password Recovery.** The system shall always return a generic success response without disclosing whether an account exists. Email recovery issues a single-use, hashed, time-limited reset token (60 minutes); phone recovery reuses the OTP lifecycle (FR-002A/FR-002B) with a distinct `password_reset` purpose. | Must |
| FR-003B | Reset Password shall require the valid unconsumed token (or OTP) plus the new password with confirmation, hash the new password using the framework's one-way hashing, mark the token/OTP consumed, and revoke all existing access tokens for the account so every device must re-authenticate. | Must |
| FR-004 | The system shall allow customers to log out from the mobile app (with confirmation). | Must |
| FR-005 | The system shall restrict transactional features to authenticated customers. | Must |

### 5.2 Discovery & Catalog

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-010 | The system shall present service categories and service listings with name, description, media, optional Starting From price, and both **Book Now** and **Request Quotation** options. | Must |
| FR-010A | Public services catalog APIs shall be guest-accessible (no authentication) with per-IP rate limiting of 60 requests per minute per IP. The public identifier for a service is its `slug`; numeric IDs remain internal. Catalog listings shall expose only active, non-deleted services; the categories listing shall return only categories having at least one active service. Sorting is limited to `display_order` (default) and `name`. Pagination defaults to 20 items per page with a maximum of 100. Service images are returned as `thumbnail`, `hero_image`, and `gallery[]` using absolute URLs only. Catalog payloads shall not include `is_favorite` — it appears only in authenticated customer-specific responses (Favorites Module, §22). Catalog payloads shall not include `before_after` or `faq`; these fields are introduced when their own modules are implemented. | Must |
| FR-010B | The system shall ship an Official V1 Services Catalog Seeder provisioning the official catalog: service categories, services, service modes, service subtypes, and coverage cities. The seeder uses official placeholder images for `thumbnail`, `hero_image`, and `gallery` until production assets are available. The catalog is seeder-managed until Admin Services CRUD is delivered (deferred to a later Backend V1 sprint — split out of Sprint 29, which delivers Home + Global Search and only the featured curation toggle); the seeder also provisions each service's payment policy (`payment_type`, `deposit_percentage` — §23.4). | Must |
| FR-011 | The system shall present store categories and product listings with name, description, media, Selling Price, and availability (In Stock / Low Stock / Out of Stock). V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners. | Must |
| FR-012 | The system shall support search and/or filter of services and products. Full Home & Global Search requirements are specified in §26 (Sprint 29). | Should |
| FR-012A | Service search shall match against service name and short description only, and shall require a minimum query length of 2 characters. Service listings shall support filtering by category, mode (`one_time` / `monthly_contract`), and coverage city. Result ordering follows the documented search ranking rules (§26.3). | Must |
| FR-013 | The system shall show product/service detail pages with complete customer-facing information. | Must |

### 5.3 Store Commerce

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-020 | The customer shall be able to add products to a cart with quantity using the standard `−` / `+` control. | Must |
| FR-021 | The customer shall be able to update or remove cart items. | Must |
| FR-022 | The system shall validate stock availability before Store Order creation and reject overselling. Creating a Store Order shall never decrease stock. | Must |
| FR-022A | Stock shall decrease only after the related Payment becomes `paid`. Failed or cancelled payments shall leave stock unchanged. Negative stock is never allowed. | Must |
| FR-023 | The customer shall be able to place a store order and receive a store order reference (`STO-YYYY-######`). Service Orders continue to use `ORD-YYYY-######`. | Must |
| FR-024 | The customer shall be able to view order history and order detail/status (Active / Completed / Cancelled; search and filter). | Must |
| FR-025 | Every product shall expose current stock and low-stock state derived from Current Stock and Low Stock Threshold. | Must |
| FR-026 | Every product shall have a unique SKU, Selling Price, Cost Price, Currency, Current Stock, Low Stock Threshold, and Status. Selling Price is customer-facing; Cost Price is for inventory valuation and profit reporting. | Must |
| FR-027 | Products may optionally define quantity tier pricing for Selling Price; when configured, tiers are displayed and applied by quantity. | Should |
| FR-028 | Checkout shall capture a Contact Phone Number for delivery coordination (prefill from profile when available). | Must |
| FR-029 | Payment method presentation shall offer exactly the V1 methods: EVC Plus (default), eDahab, Bank Transfer, and Cash on Delivery (store orders) / Cash on Service (cleaning services, per service payment policy). Jeeb and Salaam Somali Bank are removed from V1. Cards, Apple Pay, Google Pay, Zaad, Sahal, Premier Wallet, and any online gateway integration are deferred (§23). | Must |
| FR-029A | Store Orders shall reuse the Unified Payment Module and follow the Order lifecycle: `pending_payment` → `confirmed` → `preparing` → `out_for_delivery` → `delivered` → (`payment_pending` for Cash on Delivery) → `completed` / `cancelled`. Prepaid methods require confirmed payment before `confirmed`; Cash on Delivery confirms the order at checkout and enters `payment_pending` after delivery until an admin confirms cash collection (§23.3). | Must |

### 5.3A Inventory

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-030I | Inventory shall manage Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Stock Quantity, and Low Stock Alerts as a domain separate from Store commerce. | Must |
| FR-031I | Purchase Order lifecycle shall be: Draft → Submitted → Approved → Partially Received → Completed / Cancelled. Purchase Order alone shall never change stock. Goods Receipts are allowed only when the Purchase Order is `approved` or `partially_received` (never while only `submitted`). | Must |
| FR-032I | Goods Receipt shall increase stock and create Stock Ledger entries. Goods Receipt requires a Purchase Order in `approved` or `partially_received` status. | Must |
| FR-033I | Every stock movement shall be recorded in Stock Ledger with quantity, movement type, reference, user, and timestamp. Movement types include Purchase Receipt, Customer Sale, Sale Reversal (automatic restock after COD payment rejection — §24.4), Stock Adjustment, Correction, Damage, and Loss. | Must |
| FR-034I | Manual stock adjustments shall require quantity and reason (`Damaged`, `Lost`, `Correction`, `Physical Count`) and shall create Stock Ledger entries. | Must |
| FR-035I | Dashboard shall display Low Stock alerts from Current Stock vs Low Stock Threshold. Email/SMS low-stock notifications are outside V1. | Must |

### 5.4 Bookings

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-030 | The customer shall be able to choose **Book Now** for every active service. Every active service shall also provide a separate **Request Quotation** option. | Must |
| FR-031 | The system shall capture required booking details: service, requested date, requested time window, location/notes as applicable, and the linked `customer_profile_id`. | Must |
| FR-032 | The customer shall be able to view booking list and booking detail with current status. | Must |
| FR-033 | The customer shall be able to cancel a booking when policy allows. | Must |
| FR-034 | The system shall prevent double-booking beyond configured capacity rules. | Must |

### 5.5 Quotations

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-040 | The customer shall be able to create a quotation request as a **Draft** (requirements description, optional booking/product-request origin, contact context), attach staged uploads, and then **Submit** it for review. The request shall contain **no pricing fields of any kind**: the customer never submits subtotal, discount, tax, total, payment type, deposit percentage, or remaining balance. Pricing is **admin-only** (Sprint 28 — final). | Must |
| FR-040C | Quotation request attachments (images/videos/PDFs via the Unified Upload Service) may be attached or detached **only while the request is in `Draft`**. Upon **Submit**, the request and its attachment set become **permanently immutable**. Additional files after submission shall be provided **only through the Discussion workflow** (discussion messages referencing upload UUIDs). The original quotation request is never modified. | Must |
| FR-040A | The system shall provide a Unified File Upload Service for **authenticated customers only** (admin uploads remain module-specific). Customers stage files via `POST /api/v1/uploads` before attaching them to business records (first consumer: quotation requests). The public identifier for an upload is a **UUID**; numeric IDs remain internal. The owner is resolved server-side from the authenticated customer and is never client-supplied. File content is read only via owner-scoped backend streaming (`GET /api/v1/uploads/{uuid}`); storage paths are never exposed. The owner may list their **unattached, non-expired** staged uploads via `GET /api/v1/uploads` (paginated: default 20, maximum 100). Allowed types: images (JPG/JPEG/PNG/WebP), videos (MP4/MOV/WebM), PDF. Limits: image 10 MB, PDF 20 MB, video 100 MB per file; maximum 10 files per request; 20 uploads/minute/customer; total unattached staged storage capped at **500 MB per customer** (exceeding it → `409` `UPLOAD_STORAGE_LIMIT_EXCEEDED`). Limits are configuration-driven with **no Settings UI in V1**. | Must |
| FR-040B | Unattached staged uploads shall expire **7 days** after upload; a scheduled job removes expired uploads (file content and record). The owner may delete an **unattached** upload via `DELETE /api/v1/uploads/{uuid}`; deleting an attached upload shall return `409 Conflict`. Storage is local/private in V1 with S3-compatible object storage later via configuration only. Virus scanning and EXIF stripping are **deferred** beyond V1. Attachment tables (e.g. quotation request attachments) reference uploads via **FK** without duplicating upload metadata. Legacy upload implementations (customer attachments, product images, company logo) remain unchanged; their migration is **deferred until after Backend V1**. | Must |
| FR-041 | The system shall allow admins to issue a formal quotation against a submitted request. A single reviewer is assigned per quotation via `assigned_admin_id` (Sprint 28 V1: one reviewer only, no pools, no multiple reviewers; reassignment is allowed and audited). First assignment moves the request `Submitted` → `Under Review`. Only administrators may create or revise pricing (subtotal, discount, tax, total, payment type, deposit, validity). | Must |
| FR-042 | The customer shall be able to view quotation details (line items, totals, validity period, terms, quotation number, and latest revision indicator). | Must |
| FR-043 | After a quotation is issued, the customer shall have two primary actions: **Accept Quotation** and **Discuss Quotation**. The application shall never offer **Reject Quotation**. | Must |
| FR-044 | Accepted quotations shall unlock the corresponding payment / fulfillment path. | Must |
| FR-045 | **Discuss Quotation** shall keep the workflow open on the **same quotation** (no new quotation created). Customer and team may message, upload additional images/videos/PDFs, and the team may update/revise the quotation. | Must |
| FR-046 | Quotation statuses shall be limited to: `Draft`, `Submitted`, `Under Review`, `Quotation Ready`, `Under Discussion`, `Accepted`, `Expired`, `Cancelled` (Sprint 28 — final). Status `Rejected` shall never be used. | Must |
| FR-047 | Every quotation issue/update shall create a new **immutable revision** (Version 1, Version 2, Version 3…). Revisions are never edited or deleted. Only the **latest revision** may be accepted; acceptance requests must reference the latest revision, and an attempt to accept an older revision shall return **`409 Conflict`**. Older revisions remain read-only for comparison and audit. | Must |
| FR-047A | The customer may accept from **`Quotation Ready` or `Under Discussion`** — there is no requirement to first return the quotation to `Quotation Ready` (Sprint 28 — final). | Must |
| FR-047B | `Expired` is **not terminal**: an admin may issue a new revision on an expired quotation, which automatically transitions it `Expired` → `Quotation Ready`. The customer never creates a replacement request. Every new revision **must include `valid_until`**. | Must |
| FR-047C | Legacy migration: every quotation existing before Sprint 28 shall automatically receive **Revision 1** created from its current pricing (source: `system_migration`). No quotation history is lost; no manual migration steps. | Must |
| FR-048 | Every quotation shall have a unique Quotation Number (e.g. `QT-2026-000123`) displayed on details, PDF, notifications, revision history, admin panel, payments, support views, and receipts. | Must |
| FR-049 | The system shall maintain a full quotation timeline (created, discussion, team replies, updates, acceptance, payment, service completion) and allow customers to view read-only revision history. | Must |
| FR-049A | Quotation updates and new discussion messages shall generate notifications for the other party (customer or team, as applicable). | Must |
| FR-049B | Every quotation shall record its **source** as originating from a **Booking** or **Product Request** (Admin display: Booking / Product). The quotation remains permanently linked to that origin. Product quotations shall use the **same** Quotation Module as booking-origin quotations (Accept / Discuss / revisions / uploads) — no separate Product Discussion module. Standalone quotations are forbidden. | Must |

### 5.6 Payments

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-050 | The unified Payment V1 module shall initiate customer-profile-owned payments for Service Orders and Store Orders using `payable_type` and `payable_id`. | Must |
| FR-051 | The system shall record the authoritative lifecycle: Pending, Initialized, Processing, Paid, Failed, or Cancelled. Refunds are outside V1. | Must |
| FR-051A | A payable entity may have only one active Payment at a time. Active statuses are `pending`, `initialized`, and `processing`; initialization shall return that active Payment. A new Payment may be initialized only after the prior Payment is `paid`, `failed`, or `cancelled`. | Must |
| FR-052 | When a payment becomes Paid, the originating Order shall become `confirmed`; Failed or Cancelled payments shall not automatically cancel Orders. For Store Orders, Payment = Paid shall also decrease stock and create a Stock Ledger customer-sale entry. | Must |
| FR-053 | The system shall handle payment failures with clear retry guidance. | Must |
| FR-054 | Every successful payment shall produce one receipt with public number `RCPT-YYYY-######`; receipt PDF generation is outside V1. | Must |

### 5.7 Notifications

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-060 | The system shall create notifications for major business events using templates and polymorphic recipients (`Admin` \| `CustomerProfile`), with types: booking, quotation, order, payment, store_order, inventory, system. | Must |
| FR-061 | The customer (and admin recipient) shall be able to view a filterable notification list with status/type/channel filters and pagination. Unread count shall be available. | Must |
| FR-062 | Recipients shall be able to mark a delivered notification as read and mark all delivered as read. Customers shall **never** delete notification records from the live inbox (permanent history; terminal rows may be archived by admin process). | Must |
| FR-063 | The system shall support channel delivery for `in_app`, `email`, and `sms` via dedicated queues. Push remains a future enhancement. | Must |
| FR-064 | Opening a notification shall deep-link using payload/`data` reference fields when present. Notification details shall expose enterprise lifecycle status and timestamps. | Must |
| FR-065 | Recipients shall manage per-type notification preferences for `in_app`, `email`, and `sms` (defaults: in_app/email on, sms off). | Must |

### 5.8 Customer Profile & Support Context

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-070 | The customer shall be able to view My Account using `customer_profiles` fields (photo, name, **read-only** Customer Code `CUS-######`, e.g. `CUS-000001`, preferred language, member since) and `users` fields (email, phone), plus quick stats for Bookings / Quotations / Orders. | Must |
| FR-071 | The customer shall be able to add/edit saved addresses and set a default. Addresses shall **never be permanently deleted**; unused addresses are marked **Inactive**. | Must |
| FR-072 | The customer shall be able to view consolidated history of orders, bookings, quotations, and payments via account navigation. | Must |
| FR-073 | The customer shall be able to manage notification preferences (Push, Email, and category toggles). | Must |
| FR-074 | The customer shall be able to edit profile photo and full name on `customer_profiles`, and email and phone on `users`. Customer Reference Number remains read-only on `customer_profiles`. | Must |
| FR-075 | The customer shall be able to select preferred language: Somali, English, or Arabic; selection updates the entire application. | Must |
| FR-076 | **Saved payment methods are removed from V1** (no saved instruments, no saved cards, no PCI storage). Customers select a payment method at pay time only. Payment **history** is never deleted by the customer. Saved payment methods are deferred to a future version (§23.7). | — |
| FR-077 | Security screens shall support Change Password, optional Change PIN, and placeholders for Two-Factor Authentication and Active Devices. | Should |
| FR-078 | Help Center shall provide FAQs and Contact Fayadhowr channels (WhatsApp, Phone, Email). About Fayadhowr shall present a company profile (story, mission, vision, years of experience, certificates & licenses, awards, partners & clients, statistics) plus Privacy Policy, Terms & Conditions, and app version. | Must |
| FR-079 | Logout shall require confirmation (Cancel / Log Out) and must never log the customer out immediately on first tap. | Must |

### 5.9 Admin Panel (Functional Summary)

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-080 | Admins shall manage products, services, categories, pricing, and availability (subject to role — **Admin**). | Must |
| FR-081 | Admins shall manage bookings (list/search/filter; view details with media counters, timeline with actor audit, linked records; status updates via controlled dropdown of approved statuses only; read-only Priority High/Medium/Low; booking age; informational manual Assigned To; internal staff notes with name/role/date/time). Booking Number is read-only. Bookings are never permanently deleted. No Booking Value / Estimated Value on this module. | Must |
| FR-082 | Admins shall manage quotations (list/search/filter; view details with price breakdown, revision history with Created By/role/date/time, read-only Compare Revisions between any two versions, discussion with keyword search and attachment counters, timeline with actor audit, linked records; **single reviewer assignment/reassignment via `assigned_admin_id` (Sprint 28 V1 — one reviewer, no pools)**; status transitions via server-enforced workflow actions only; validity countdown on list and details; issue Version 1 and revisions (pricing is admin-only); internal staff notes). Every quotation must originate from a **Booking** or **Product Request** only — Admin / Sales / Accountant shall never create a standalone quotation. QT Number is read-only and never changes across revisions. Quotations and revisions are never edited or permanently deleted. Only the latest revision may be accepted (older → `409 Conflict`). Discussion history cannot be deleted. Expired quotations are revived only by issuing a new revision (mandatory `valid_until`). | Must |
| FR-083 | Admins shall manage orders (list/search/filter; view details with ordered items, price breakdown with discounts/delivery/tax, business summary cards, timeline with actor audit, order documents with availability status, linked records incl. **Order Documents** shortcut; status updates via controlled dropdown of approved order statuses only; **Current Stage Indicator** compact read-only label above progress tracker showing current workflow stage; **Order Progress Tracker** visual stepper Pending Payment → Confirmed → Processing → Completed highlighting current step; **Order Age** displayed in list and details; **Payment Timeline** with expanded payment events Payment Requested/Received/Confirmed/Refund Processed each with Performed By, Staff Role, Date, Time; **Documents Status** each document shows ✅ Available or ⏳ Not Available Yet; **Financial Summary** compact read-only breakdown Subtotal/Discount/Delivery Fee/Tax/Grand Total/Amount Paid/Remaining Balance; **Latest Note indicator** read-only timestamp of most recent internal note). Every order is created **automatically** from an accepted quotation — no manual Create Order. Order Number is read-only. Orders are never permanently deleted. Every order remains permanently linked to its originating Booking or Product Request and its accepted Quotation. **Payment Status color system** standardized across Admin Panel: Paid (green), Partially Paid (orange), Unpaid (red), Refunded (blue). Order statuses: Pending Payment, Confirmed, Processing, Completed, Cancelled. Internal staff notes with name/role/date/time. | Must |
| FR-084 | Admins shall manage customer profiles (list/search/filter with default **Active Customers**; view profile joined to `users` contact data, Member Since, business summary including **Total Spent**, timeline with icons, linked records; internal staff notes with name/role/date/time audit; manage Customer Status per the canonical set `ACTIVE` / `INACTIVE` / `BLOCKED` / `DELETED` — see FR-092). Customer Code is auto-generated and read-only on `customer_profiles`. Classification is **Lead** vs **Active Customer** (no VIP). Customer identities and profiles are never permanently deleted (soft delete only). Full Customer Management requirements are specified in FR-092. | Must |
| FR-085 | Admins shall configure notification templates and operational settings (**Admin**). | Should |
| FR-086 | Admins shall manage payments (list/search/filter; view details with payment information, transaction reference with **Copy** button, business summary cards Amount Due/Amount Paid/Remaining Balance/Payment Status, **Financial Audit Summary** Payment Requested By/Payment Confirmed By/Confirmation Date/Last Updated, source chain & linkage, payment documents with availability status, payment timeline with actor audit, linked records, internal staff notes with name/role/date/time; **Payment Verification Badge** Verified/Pending Verification independent from status displayed in list and details; **Payment Age** displayed in list and details e.g. "Received 1 day ago"/"Waiting Verification 3 days"; **Payment Method Icons** consistent branded icons in list and details; **Current Stage Indicator** compact read-only label above progress tracker; **Payment Progress Tracker** visual stepper Pending → Received → Confirmed / Pending → Failed / Confirmed → Refunded highlighting current step; **Payment Documents** Payment Receipt/Invoice/Order PDF with ✅ Available or ⏳ Pending; **Latest Note indicator** read-only timestamp). Every payment must originate from an existing **Order** — no manual Create Payment. Payment Number (`PAY-…`) is read-only. Payments are never permanently deleted. Every payment remains permanently linked to its originating Order. Approved payment statuses: Pending, Received, Confirmed, Failed, Refunded. Supported payment methods: EVC Plus, eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, Debit/Credit Card. Receipt history is permanent. Status updates via controlled dropdown only. | Must |
| FR-087 | The Admin Panel shall use **one login** and **one panel**; after sign-in Dual Dashboard Architecture loads Super Admin or Operations dashboard, sidebar, and Hybrid RBAC permissions for **Super Admin**, **Manager**, **Sales**, **Inventory**, or **Accountant** only. | Must |
| FR-088 | The Admin Dashboard shall expose role-appropriate **Dashboard Statistics** (cached per admin) and navigation from Dual Dashboard Architecture — without staff-performance analytics (no Staff Management / field workforce in v1). | Must |
| FR-088A | Admin mutations that change accounts, role permissions, or direct permissions shall dispatch event-driven **AuditEvent** records and invalidate the actor’s (and affected admin’s) dashboard statistics cache. | Must |
| FR-088B | Inactive admin accounts shall be rejected at login and on every authenticated admin request (including existing tokens). | Must |

---

## 6. Non-Functional Requirements

### 6.1 Performance

| ID | Requirement |
| --- | --- |
| NFR-001 | Core screens (home, catalog lists, detail) should load interactive content within acceptable mobile latency under normal network conditions (target: under 3 seconds for primary API responses on typical 4G). |
| NFR-002 | Checkout, booking submission, quote acceptance, and payment initiation must provide immediate UI feedback and definitive success/failure states. |
| NFR-003 | List endpoints should support pagination to keep payloads bounded. |

### 6.2 Reliability & Availability

| ID | Requirement |
| --- | --- |
| NFR-010 | API and admin services should target high availability suitable for a production commerce/service business (e.g., 99.5%+ monthly, subject to hosting plan). |
| NFR-011 | Payment and order/booking state transitions must be idempotent where retries can occur. |
| NFR-012 | The system must recover gracefully from transient network failures without duplicating paid transactions. |

### 6.3 Security

| ID | Requirement |
| --- | --- |
| NFR-020 | All client–server communication shall use TLS. |
| NFR-021 | Authentication tokens/credentials shall be stored securely on device. |
| NFR-022 | APIs shall enforce authorization checks on every protected resource. |
| NFR-023 | Sensitive payment details shall not be stored in the mobile app beyond what the payment provider requires; PCI scope must be minimized. |
| NFR-024 | Admin actions that change money, status, or catalog shall be auditable. |
| NFR-025 | Input validation and protection against common web/API attacks (injection, XSS on admin, CSRF where applicable) are required. |

### 6.4 Privacy & Compliance

| ID | Requirement |
| --- | --- |
| NFR-030 | Personal data collection shall be limited to what is necessary for service delivery and commerce. |
| NFR-031 | Customers shall be able to request account deletion / data export according to applicable policy. |
| NFR-032 | Privacy policy and terms of use shall be accessible from the app. |

### 6.5 Usability

| ID | Requirement |
| --- | --- |
| NFR-040 | Critical flows (booking, quote request, checkout, payment) must be completable without training. |
| NFR-041 | Error messages must be actionable and non-technical for customers. |
| NFR-042 | The app must support standard accessibility practices for mobile (contrast, scalable text where feasible). |

### 6.6 Compatibility

| ID | Requirement |
| --- | --- |
| NFR-050 | Customer app shall support current mainstream Android and iOS versions as defined in the mobile engineering baseline. |
| NFR-051 | Admin panel shall support modern evergreen browsers. |

### 6.7 Maintainability & Observability

| ID | Requirement |
| --- | --- |
| NFR-060 | Backend modules should be separable by domain (catalog, booking, quotation, order, payment, notification, identity). |
| NFR-061 | Structured logging and error reporting are required for production support. |
| NFR-062 | Configuration (payment keys, feature flags) must not be hard-coded in client releases where avoidable. |

### 6.8 Localization & Currency (Forward-Looking)

| ID | Requirement |
| --- | --- |
| NFR-070 | System currency and tax/fee display rules shall be centrally configurable. |
| NFR-071 | Multi-language support may be added without redesigning core workflows. |

---

## 7. Service Modules

### 7.1 Purpose

Service modules represent Fayadhowr offerings that support both **Book Now** and **Request Quotation**. They are distinct from store products: services are fulfilled through scheduling, assessment, and/or on-site/remote delivery rather than simple SKU shipment alone.

### 7.2 Official Services Catalog (V1)

#### Service Modes

| Service | Supported mode(s) |
| --- | --- |
| Deep Cleaning | One-Time · Monthly Contract |
| Pest Control | One-Time · Monthly Contract |
| Carpet Cleaning | One-Time |
| Sofa & Chair Cleaning | One-Time |
| Post Construction Cleaning | One-Time |
| Window Cleaning | One-Time · Monthly Contract |
| Fumigation Services | One-Time · Monthly Contract |
| Housekeeper | Monthly Contract |
| Monthly Cleaning Staff | Monthly Contract |

#### Housekeeper Subtypes

- Full-Time
- Part-Time
- Live-In
- Live-Out

#### Monthly Cleaning Staff Subtypes

- Office
- Hotel
- Restaurant
- School
- Hospital / Clinic
- Other Business

### 7.3 Booking and Quotation Options

Every service supports both customer options:

- **Book Now**
- **Request Quotation**

Services must not be classified as booking-only or quotation-only. The customer chooses the appropriate path for the service need, and Fayadhowr determines the final commercial outcome through assessment/review where required.

### 7.4 Service Module Capabilities

- Category hierarchy for discovery.
- Service detail: description, inclusions/exclusions, media, duration estimates, pricing information, prerequisites, and related services.
- Eligibility rules (e.g., service area, minimum lead time).
- Capacity / scheduling rules (slots, blackout dates, max concurrent bookings).
- Required customer inputs (address, notes, attachments, preferred time).
- Linkage to booking records and quotation requests.
- Admin-managed activation/deactivation without deleting historical records.

### 7.5 Service Detail Template

Every service detail follows the same customer-facing structure:

1. Hero Banner
2. Service Overview
3. What's Included
4. What's Not Included
5. Before & After Gallery
6. How It Works
7. Estimated Duration
8. Pricing Information
9. Things to Prepare Before We Arrive
10. Service Coverage
11. FAQs
12. Customer Reviews
13. Related Services
14. Book Now
15. Request Quotation

### 7.6 Service Coverage

V1 supported service cities are:

- Mogadishu
- Hargeisa

### 7.7 Pricing Strategy

- A **Starting From** price may be displayed when available.
- The final price is determined after Fayadhowr assessment or review.
- Customer-facing service content must not promise a fixed final price before the approved assessment/review outcome.

### 7.8 Service Module Rules

- Every active service must expose both **Book Now** and **Request Quotation** paths.
- Inactive services must not accept new bookings or quotation requests.
- Historical bookings/quotes remain visible to the customer even if a service is later deactivated.
- Service content shown in the app is admin-authored and versioned operationally (content updates do not rewrite past commercial agreements).

---

## 8. Store Module

### 8.1 Purpose

The Store Module is a separate physical-product commerce domain. It is not a service module and does not represent service fulfillment or scheduling. Store is also not the Inventory purchasing domain.

V1 Store supports **physical products only** in these categories:

- Cleaning Chemicals
- Cleaning Tools
- Cleaning Accessories
- Personal Protective Equipment (PPE)
- Air Fresheners

Heavy cleaning equipment and machines are outside V1.

### 8.2 Store Responsibilities

Store is responsible for:

- Product catalog
- Categories
- Product images
- Cart
- Checkout
- Store Orders
- Unified Payment integration

Store is **not** responsible for inventory purchasing, suppliers, purchase orders, goods receipts, stock ledger maintenance, or stock adjustments. Those belong to Inventory.

### 8.3 Store Capabilities

- Product categories and catalog browsing.
- Product detail: title, description, **swipeable image gallery**, **Selling Price** with unit, optional **tier pricing**, SKU, stock/low-stock cues, optional marketing badge, specifications, related products.
- Quantity control **`−` / `+`** on detail and cart.
- Cart management (add, update quantity, remove).
- Checkout preview that re-validates Selling Price and stock (does not create the Store Order).
- Store Order creation via dedicated Store Order API after checkout preview, with unique store order reference (`STO-YYYY-######`).
- Unified Payment initialization/processing for Store Orders.
- Order confirmation and order history (Active / Completed / Cancelled).

### 8.4 Store Business Rules

- Cart prices are indicative until checkout confirmation against backend Selling Prices (including applicable quantity tiers).
- Checkout preview must re-validate Selling Price and available stock, and must prevent overselling.
- Store Order creation follows checkout preview and never decreases stock.
- Stock decreases only after Payment status becomes `paid`.
- Failed or cancelled payments leave stock unchanged.
- Negative stock is not allowed.
- Store Orders are persisted in `store_orders` (separate from service `orders`), reuse the Unified Payment Module, and follow: `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`.
- Store orders are commercially separate from service bookings unless an explicit bundle offering is introduced later.
- Optional product badges: New, Best Seller, Popular, Limited Stock.
- Selling units include Piece, Pack, Box, Carton, Bottle, Liter, Kg (extensible by admin).
- Product gallery images are stored in `product_images`.

### 8.5 Product Pricing

Every Product stores two prices:

- **Cost Price** — amount Fayadhowr paid the supplier.
- **Selling Price** — amount charged to customers.

Business rules:

- Cost Price supports future inventory valuation, profit reporting, and accounting.
- Selling Price is used for customer purchases, cart, checkout, and payments.
- Changing Selling Price never changes Cost Price.
- Future purchase receipts may update Cost Price according to future inventory costing policies.
- Inventory costing methods are outside V1.

### 8.6 Optional Product Quotation

- Customers may request a quotation for bulk/custom/special product needs through the **shared Quotation Module** (same Accept / Discuss / revision rules as Services). Source = `Product`.
- This quotation process is optional and does not replace the normal fixed-price purchasing workflow.

### 8.7 Store ↔ Other Modules

| Interaction | Description |
| --- | --- |
| **Payments** | Store Orders use the Unified Payment Module (`payable_type` / `payable_id`) |
| **Inventory** | Paid Store Order sales decrease stock and create Stock Ledger sale entries |
| **Notifications** | Order placed, paid, cancelled (and future fulfillment events) |
| **Profile** | Orders appear in customer history; addresses may be reused |
| **Admin** | Catalog and Store Order fulfillment managed in admin panel |

---

## 8A. Inventory Module

### 8A.1 Purpose

Inventory is a separate business domain from Store. It manages stock acquisition, stock quantity integrity, and stock movement history for physical products.

### 8A.2 Inventory Responsibilities

Inventory manages:

- Suppliers
- Purchase Orders
- Goods Receipts
- Stock Ledger
- Stock Adjustments
- Stock Quantity
- Low Stock Alerts

### 8A.3 Stock Flow

```text
Supplier
  ↓
Purchase Order
  ↓
Goods Receipt
  ↓
Inventory Increase
  ↓
Store Product
  ↓
Customer Purchase
  ↓
Payment Paid
  ↓
Inventory Decrease
  ↓
Stock Ledger Entry
```

### 8A.4 Purchase Order Lifecycle

`Draft` → `Submitted` → `Approved` → `Partially Received` → `Completed` / `Cancelled`

Purchase Order alone never changes stock. Inventory may be received only after approval (`approved` or subsequent `partially_received`).

### 8A.5 Goods Receipt

- Goods Receipt is allowed only for Purchase Orders in `approved` or `partially_received` status.
- Goods Receipt increases stock.
- Every Goods Receipt creates Stock Ledger entries (`purchase_receipt`) in `stock_ledgers`.
- Submitted Purchase Orders must not receive inventory.

### 8A.6 Stock Ledger

Every stock movement is recorded. Movement types include:

- Purchase Receipt
- Customer Sale
- Sale Reversal (Sprint 27 — used **only** for automatic inventory restoration after a COD payment rejection, §24.4)
- Stock Adjustment
- Correction
- Damage
- Loss

Ledger stores quantity, movement type, reference, user, and timestamp. A Sale Reversal entry records a **positive** quantity referencing the cancelled store order, mirroring the original Customer Sale deduction line for line.

### 8A.7 Inventory Adjustment

Manual stock adjustments require quantity and reason. Reasons include Damaged, Lost, Correction, and Physical Count. Every adjustment creates Stock Ledger entries.

### 8A.8 Low Stock

Each product defines Current Stock and Low Stock Threshold. Dashboard displays Low Stock alerts. Email/SMS low-stock notifications are outside V1.

---

## 9. Booking Workflow

### 9.1 Objective

Allow a customer to use **Book Now** for any active service, track the resulting booking lifecycle, and proceed to payment according to policy. Every service also retains the separate **Request Quotation** option.

### 9.2 Actors

- Customer (mobile)
- Admin / Operations (admin panel)
- System (validation, notifications, payment hooks)

### 9.3 Preconditions

- Customer is authenticated through `users` and has a linked `customer_profiles` record.
- Selected service is active and available in the requested supported city.
- Required scheduling capacity exists for the requested time window.
- Required fields (location, notes, etc.) are provided.

### 9.3A Schedule Model

| Schedule type | Fields | Meaning |
| --- | --- | --- |
| **Requested Schedule** | `requested_date`, `requested_time_window` | Customer preference submitted with the booking request. |
| **Confirmed Schedule** | `scheduled_start_at`, `scheduled_end_at` | Operationally confirmed service window. |

### 9.4 Main Flow

1. Customer selects an active service and chooses **Book Now**.
2. Customer reviews the standard service details and pricing information.
3. Customer provides a **Requested Schedule**: `requested_date` and `requested_time_window`, then enters required location and notes.
4. System validates eligibility, lead time, and capacity.
5. System creates a **Booking** owned by `customer_profile_id`, assigns a unique `BK-YYYY-######` public booking number, snapshots the selected address/service context, and sets initial status **Submitted** (see §9.6).
6. System notifies customer and operations of the new booking.
7. Operations reviews/fulfills as needed, sets the **Confirmed Schedule** (`scheduled_start_at`, `scheduled_end_at`), and updates status.
8. Customer pays when the booking becomes payable (immediately, on confirmation, or on completion — per service policy).
9. System updates booking and payment statuses and notifies the customer.
10. Booking reaches a terminal status (`Completed`, `Cancelled`, or equivalent).

### 9.5 Alternate / Exception Flows

| Scenario | Behavior |
| --- | --- |
| Requested time window unavailable | Reject creation; prompt customer to choose another requested time window |
| Service deactivated mid-flow | Prevent submission; show unavailable message |
| Customer cancelation allowed | Transition to cancelled. Paid payments remain `paid` (refunds are out of V1 scope — V2); active payments (`initialized` / `pending` / `processing`) are automatically failed in the same transaction (§24.3) |
| Customer cancelation not allowed | Block cancel; show policy message |
| Payment failure | Keep booking in payable state; allow retry |
| No-show / operational cancel | Admin updates status; notify customer; apply policy |

### 9.6 Booking Status Model (Logical)

Approved Admin Panel booking statuses (display labels):

- `Submitted` (system-set initial status at booking creation — never admin-selectable)
- `Pending Review`
- `Quotation Ready`
- `Under Discussion`
- `Accepted`
- `Scheduled`
- `In Progress`
- `Completed`
- `Closed`
- `Cancelled`

**`Closed`** means the service is completed **and** all required payments for the booking are completed (per the service payment policy — §23.4). Bookings for `pay_after_service` and `deposit` services remain `Completed` until the final payment is confirmed by an admin, then become `Closed`.

Notes:

- `Draft` remains client-side only and is not persisted as a server booking.
- **`Rejected` is never used.**
- **`Accepted` is automatic (Sprint 27 — final):** customer acceptance of the quotation automatically moves the booking to `Accepted` in the same transaction. There is **no separate admin acceptance step**. Flow: Submitted → Quotation Issued → Customer Accepts Quotation → **Booking Accepted (automatic)** → Payment (if required) → Scheduled.
- `Scheduled`, `In Progress`, `Completed`, `Closed`, and admin `Cancelled` transitions are performed through the Admin Operations APIs (§24).
- **Cancellation payment rules (Sprint 27 — final):** cancelling a booking never reverses a `paid` payment (deposit stays `paid`, booking becomes `Cancelled`; refunds belong to V2). Any still-active payment (`initialized` / `pending` / `processing`) is automatically set to `failed` inside the same cancellation transaction — no active payment may remain attached to a cancelled booking.
- Payment events appear on the booking timeline and in linked Payments/Orders; they are not separate booking status values.
- Booking media in V1 supports **Images** and **Videos** only. Documents are not Booking Media V1.

### 9.7 Postconditions

- Booking exists with immutable public booking number (`BK-YYYY-######`), `customer_profile_id` ownership, requested schedule, and confirmed schedule fields.
- Customer can view status history and key details.
- Notifications have been emitted for state changes of customer relevance.
- Payment records are linked when payment occurs.

---

## 10. Quotation Workflow

### 10.1 Objective

Support assessment/review pricing for every service by allowing customers to choose **Request Quotation**, receive a formal price proposal, **Accept** or **Discuss** it (never Reject), and proceed to payment only after acceptance.

### 10.2 Actors

- Customer
- Admin / Estimator / Operations (Fayadhowr team)
- System

### 10.3 Preconditions

- Customer is authenticated.
- Target service is active and available in the requested supported city.
- Customer provides sufficient requirement details.

### 10.4 Main Flow

1. Customer selects **Request Quotation** from any active service (or an approved product request entry point).
2. System creates the quotation record as a **Draft** with a permanent, unique Quotation Number (e.g. `QT-2026-000123`). The request carries **no pricing fields** — requirements description, preferred timing, location, and origin only.
3. Customer stages files through the Unified Upload Service (images/videos/PDFs) and attaches them to the Draft by upload UUID. Attach/detach is allowed **only while in Draft**.
4. Customer **Submits** the request: status becomes **Submitted**; the request and its attachment set become **permanently immutable**. System notifies operations.
5. An admin is assigned as the single reviewer (`assigned_admin_id`): status becomes **Under Review**. Reassignment is allowed; clarification occurs **on the same quotation** via Discussion (in-app, v1).
6. Team issues a formal **Quotation** (**Version 1**) with line items, total, taxes/fees (if any), **mandatory validity period (`valid_until`)**, and terms. Pricing is admin-only.
7. Status becomes **Quotation Ready**. System notifies the customer (includes Quotation Number).
8. Customer reviews the latest quotation (Latest Version / current revision clearly indicated).
9. Customer primary actions: **Accept Quotation** or **Discuss Quotation**.
10. If **Discuss Quotation**: status becomes **Under Discussion**. Customer and team may exchange messages and additional files (via discussion-message upload UUIDs); team may revise the quotation creating **Version 2, Version 3…** on the **same** quotation (never a separate quotation; the Quotation Number never changes). Each revision notifies the customer. Discussion **never closes** the quotation.
11. If **Accept Quotation** (allowed from **Quotation Ready or Under Discussion**; must reference the **latest revision**, otherwise `409 Conflict`; blocked while Expired/Cancelled): status becomes **Accepted**; the system snapshots the service payment policy onto the quotation (`payment_type`, `deposit_percentage`, `deposit_amount`, `remaining_amount`), **automatically moves the linked booking to `Accepted` in the same transaction (Sprint 27 — no admin acceptance step)**, and unlocks **Payment** according to that policy.
12. Customer pays according to the snapshotted service payment policy (§23.4):
    - `full_before_service` — the full quotation total is payable; the booking becomes **Scheduled** only after full payment is confirmed.
    - `deposit` — the customer pays `quotation_total × deposit_percentage`; the booking becomes **Scheduled** only after the deposit payment is confirmed. After service completion, the remaining balance becomes payable; the booking becomes **Closed** after an admin confirms the final payment.
    - `pay_after_service` — no payment is required before scheduling; the full amount becomes payable after service completion and the booking becomes **Closed** after an admin confirms the payment.
13. Operations fulfills; timeline continues through service completion and payment closure.

### 10.5 Alternate / Exception Flows

| Scenario | Behavior |
| --- | --- |
| Incomplete request | Validation errors; Draft not submitted |
| Customer edits after Submit | Blocked (`409 Conflict`); request and attachments are immutable after Submit |
| Customer cancels own request | Allowed only in **Draft** or **Submitted** (pre-pricing); status **Cancelled** |
| Quote expired | Customer acceptance and discussion blocked; status **Expired**. **Not terminal**: an admin revision (with mandatory `valid_until`) automatically transitions **Expired → Quotation Ready**; the customer never creates a new request |
| Cancelled | Status **Cancelled** per company policy (admin-only after pricing); acceptance blocked; terminal |
| Customer chooses Discuss | Status **Under Discussion**; workflow remains open on same Quotation Number |
| Quotation updated / revised | New immutable revision (Version N); prior revisions read-only; customer notified (“Your quotation has been updated…”) |
| Customer accepts an older revision | **`409 Conflict`**; only the latest revision is acceptable; client refetches and re-confirms |
| New discussion message | Notification to the other party |
| Payment after accept fails | Acceptance retained; payment remains retryable until cancelled by policy |

### 10.6 Quotation Status Model (Logical)

Use **only** these statuses:

| Status | Meaning |
| --- | --- |
| **Draft** | Request being composed; editable; attachments mutable |
| **Submitted** | Request sent for review; request and attachments permanently immutable |
| **Under Review** | Single reviewer assigned (`assigned_admin_id`); evaluation in progress |
| **Quotation Ready** | Latest formal quotation (revision) available for customer review |
| **Under Discussion** | Discuss Quotation is active; messaging and/or revisions in progress; **acceptance remains allowed** |
| **Accepted** | Customer accepted the **latest** revision; payment path unlocked; terminal |
| **Expired** | Validity ended; customer acceptance/discussion blocked; **revivable by admin revision → Quotation Ready** |
| **Cancelled** | Closed per company policy; cannot be accepted; terminal |

Allowed transitions: `Draft → Submitted | Cancelled` · `Submitted → Under Review | Cancelled` · `Under Review → Quotation Ready | Cancelled` · `Quotation Ready → Under Discussion | Accepted | Expired | Cancelled` · `Under Discussion → Quotation Ready | Accepted | Expired | Cancelled` · `Expired → Quotation Ready (admin revision only) | Cancelled`. Terminal states: `Accepted`, `Cancelled`.

Legacy migration (Sprint 28): existing `Pending Review` quotations map to **Submitted**; every existing quotation automatically receives **Revision 1** from its current pricing (source `system_migration`) so no history is lost.

**Never use** status or action labels: `Rejected`, `Reject Quotation`, or equivalent.

### 10.7 Discussion, Versioning & Timeline Rules

| Rule | Behavior |
| --- | --- |
| Discuss vs Reject | **Discuss Quotation** replaces Reject everywhere |
| Same quotation | Discussion and revisions stay on the same Quotation Number; the Quotation Number is **immutable** — generated once, it never changes (`QT-2026-000001` → Version 1, Version 2, Version 3…) |
| Version control | Each issue/revise creates an **immutable** revision with a **strictly increasing** version number (Version 1 → 2 → 3 → 4 …); version numbers are **never reused and never reset** (even future archive/delete operations must not reuse them); revisions are never edited or deleted; only the latest may be accepted (older → `409 Conflict`) |
| Latest indicator | UI must show **Latest Version** or **Revision N (Current)**; the quotation tracks its latest revision (`latest_revision_id`), which must always reference the **highest version number** — database and application layers prevent out-of-sync revisions |
| Action flags | The API returns server-calculated `can_accept` / `can_discuss` flags on every quotation payload; these flags are **authoritative** — clients (Flutter, Web, future) never recreate the business logic and only display what the server returns |
| Revision history | Customers may open **View Revision History** (read-only); admins may compare any two versions |
| Timeline | Maintain history keyed by Quotation Number + Version: Request Created, Submitted, Reviewer Assigned, Quotation Issued (Version N), Customer Discussion, Team Replies, Quotation Revised (Version N), Customer Acceptance, Payment, Service Completion |
| Notifications | Every quotation revision and every new discussion message notifies the other party |
| Attachments | Request attachments freeze at Submit; post-submit files travel only via Discussion messages (upload UUIDs) |

### 10.8 Commercial Rules

- Customers **never** submit pricing: no subtotal, discount, tax, total, payment type, deposit percentage, or remaining balance. Pricing is created and revised **only by administrators**.
- Only the **latest** revision of a quotation may be accepted; accepting an older revision returns **`409 Conflict`**.
- Acceptance is allowed from **Quotation Ready** or **Under Discussion**.
- Older revisions remain available read-only for transparency and auditing.
- Acceptance creates a binding customer intent subject to terms and moves the customer to **Payment**.
- Discussion never closes a quotation.
- Expired and Cancelled quotations cannot be accepted. Expired quotations may be revived only by an admin revision (mandatory `valid_until`), returning them to **Quotation Ready**.

### 10.9 Unified Reference Numbers & Linked Records

Every major business record shall have a unique reference in the unified numbering system (see §10.10). Quotations use `QT-YYYY-######` (example: `QT-2026-000123`).

Linked navigation (admin and support): Customer → Booking (when applicable) → Quotation → Payment → Invoice → Order/History — without re-searching.

### 10.10 Unified Reference Number System (V1)

| Record | Prefix pattern | Example |
| --- | --- | --- |
| Customer | `CUS-######` | `CUS-000001` |
| Booking | `BK-YYYY-######` | `BK-2026-000001` |
| Quotation | `QT-YYYY-######` | `QT-2026-000001` |
| Order (Service) | `ORD-YYYY-######` | `ORD-2026-000001` |
| Store Order | `STO-YYYY-######` | `STO-2026-000001` |
| Payment | `PAY-YYYY-######` | `PAY-2026-000001` |
| Receipt | `RCPT-YYYY-######` | `RCPT-2026-000001` |
| Purchase Order | `PO-YYYY-######` | `PO-2026-000001` |
| Goods Receipt | `GR-YYYY-######` | `GR-2026-000001` |
| Invoice | `INV-YYYY-######` | `INV-2026-000001` |
| Refund | `REF-YYYY-######` | `REF-2026-000001` |

Reference numbers must be unique within their type (and globally unique in recommended implementation). Display Quotation Number on Quotation Details, PDF, notifications, revision history, admin panel, payment records, customer support views, and receipts.

---

## 11. Payment Workflow

### 11.1 Purpose

Provide one gateway-independent payment lifecycle for Service Orders and Store Orders. Payment owns payment records, gateway transactions, receipts, and lifecycle state; originating domains retain their own commercial rules.

### 11.2 Payable Entities

| Entity | Payment V1 treatment |
| --- | --- |
| Service Order | Payable through the unified Payment module; installments follow the service payment policy (§23.4) |
| Store Order | Payable through the unified Payment module; Cash on Delivery follows the COD lifecycle (§23.3) |

### 11.3 Main Flow

1. Customer opens a payable entity and chooses **Pay**.
2. System creates a customer-profile-owned Payment with `payable_type` and `payable_id`.
3. Customer completes payment via integrated provider/method.
4. Provider returns success/failure (synchronous and/or webhook/callback).
5. System verifies payment authenticity and callback validity server-side in this order: gateway signature/authentication, gateway transaction reference, Payment resolution, active-Payment check, duplicate-callback check, then one atomic database transaction.
6. On success:
   - Change Payment from `Processing` to `Paid`.
   - Assign a unique receipt number `RCPT-YYYY-######` once on `payments.receipt_number` (duplicate paid webhooks never regenerate it).
   - Change the originating Service Order from `pending_payment` to `confirmed`, or confirm the Store Order and decrease stock with a Stock Ledger `customer_sale` entry, in the same transaction.
   - Publish `PaymentPaid` for future Notification consumption.
7. On failure:
   - Mark payment `Failed` or `Cancelled`.
   - Keep the Order unchanged and payable if still valid.
   - Publish `PaymentFailed` for future Notification consumption.

### 11.4 Payment Status Model (Logical)

- `Pending`
- `Initialized`
- `Processing`
- `Paid`
- `Failed`
- `Cancelled`

### 11.4A Payment Stage (V1)

Every payment record carries a **`payment_stage`**: `deposit`, `balance`, or `full`. The stage determines the server-calculated installment amount:

| Stage | Amount |
| --- | --- |
| `full` | Full payable total |
| `deposit` | `quotation_total × deposit_percentage` (snapshotted at acceptance) |
| `balance` | `remaining_amount` (snapshotted at acceptance), payable after service completion |

### 11.4B Offline Methods (V1)

Bank Transfer, Cash on Delivery, and Cash on Service are **offline methods**: no gateway handoff and no provider webhook exist. Their payments remain `Pending` until an **admin confirms** receipt/collection, which moves them to `Paid` with full audit (who confirmed, when). EVC Plus and eDahab are V1 methods whose confirmation is likewise admin-verified until gateway integration (deferred) exists.

### 11.5 Rules & Safeguards

- The amount charged must always equal the server-calculated **installment** according to `payment_stage` and the service payment policy (`payment_type`) — never a client-supplied amount.
- Payment records and one-or-more `payment_transactions` must be unique and reconcilable.
- Webhook/callback handling must be idempotent.
- Repeated callbacks for the same successful gateway transaction must never create duplicate payment, transaction, history, or order state transitions.
- Customers must never mark a payment successful from the client alone.
- Every successful payment produces one receipt with `RCPT-YYYY-######`; receipt PDF generation is outside V1.
- Gateway adapters must remain provider-neutral so future providers can be added without redesign; V1 ships with no online gateway integration (all confirmations are admin-verified/offline — §11.4B).

### 11.6 Failure & Reconciliation

- Abandoned attempts remain Pending, Initialized, or Processing until approved failure/cancellation policy applies.
- Admin finance views must allow matching provider transactions to internal payment records.
- Refunds, disputes, and chargebacks are outside V1.

---

## 12. Notification Workflow

### 12.1 Purpose

Deliver timely, relevant updates to customers and admins about commercial and operational events using the Sprint 12 Notification Architecture.

### 12.2 Channels

| Channel | Use |
| --- | --- |
| **In-App** | Persistent inbox; dedicated queue `notifications-in-app` |
| **Email** | Queued delivery (`notifications-email`); V1 stub pending provider |
| **SMS** | Queued delivery (`notifications-sms`); default preference off; V1 stub pending provider |

Push remains a future enhancement (not a V1 channel enum).

### 12.3 Types

`booking` · `quotation` · `order` · `payment` · `store_order` · `inventory` · `system`

### 12.4 Notification Flow

1. Domain code publishes `NotificationRequested` (never dispatches jobs directly).
2. Listener renders active template (with translation fallback), resolves preference-allowed channels, and persists pending notification(s) with `event_id` idempotency.
3. `DispatchNotificationJobAction` places `ProcessNotificationJob` on the channel-specific queue.
4. Processing lifecycle: `pending` → `processing` → `sent` (or `failed`). V1 in-app then auto-transitions `sent` → `delivered`. Email/SMS stay at `sent` until provider callbacks.
5. Recipient lists/filters notifications; marks **delivered** → `read` (idempotent if already read).
6. Terminal rows (`read` / `failed`) may be archived to `archived_notifications` (admin only); never hard-deleted from inbox without archive.

### 12.5 Rules

- Notifications must not expose sensitive secrets.
- Recipients see only their own notifications; admins with `notifications.manage` manage templates/translations/archives.
- Template content is admin-configurable; multi-language via translation rows (`so` / `en` / `ar`).
- Delivery failures must not roll back the underlying business transaction.
- Unique (`recipient`, `channel`, `event_id`) enforces dispatch idempotency.

---- Preference controls may suppress non-critical notifications (including Marketing) but not legally/operationally required notices.
- Every major business event automatically creates a notification.
- Notifications must always open the correct related record.

---

## 13. Customer Profile

### 13.1 Purpose

Provide a secure personal space for the authenticated `users` identity, linked `customer_profiles` business data, contact details, preferences, and activity history.

### 13.2 Profile Data (Logical)

- `users`: phone, email, password/credential metadata, provider linkage, verification flags, account status, and last-login metadata
- `customer_profiles`: name, avatar, Customer Code `CUS-######` (system-assigned, read-only, e.g. `CUS-000001`), preferred language, classification, and notification preferences
- Addresses / service locations (`is_active`; never customer hard-deleted)
- Notification preferences
- Account eligibility is enforced on `users`; Customer classification is held by `customer_profiles`
- Activity summaries: orders, bookings, quotations, payments
- Member since (`created_at`)

### 13.3 Profile Capabilities

| Capability | Description |
| --- | --- |
| View profile | Customer reads current personal data |
| Update profile | Customer edits allowed fields with validation |
| Manage addresses | Add / edit / set default; mark **Inactive** (never hard-delete) |
| Language | Somali · English · Arabic (app-wide) |
| Security | Change password; optional PIN; 2FA & Active Devices placeholders; re-auth for sensitive changes |
| Help / About | FAQ & Contact Fayadhowr; full company profile (story, mission, vision, experience, trust blocks, stats); Privacy; Terms; app version |
| View history | Access lists and details of past/present commercial activity |
| Account control | Logout with confirmation dialog; request deactivation/deletion |

### 13.4 Profile Rules

- Customers cannot edit system-controlled fields (`customer_profiles.customer_number` / CUS reference, `users` verification flags, classification, or suspension state).
- Profile updates must not alter historical invoice/order legal snapshots incorrectly (e.g., past order address remains as fulfilled).
- Suspended customers cannot create new bookings, quotes, or orders.
- Personal data access is strictly owner-scoped on customer APIs.
- Addresses are never permanently deleted by customers; mark Inactive instead.
- Payment history is never deleted by customers. Saved payment methods do not exist in V1 (deferred — §23.7).
- Preferred language (Somali / English / Arabic) updates the entire application UI.

---

## 14. Admin Panel Overview

### 14.1 Purpose

The Admin Panel is the operational control plane for Fayadhowr. It is **not** part of the customer mobile app UI, but it is required for the product to function in production.

### 14.2 Core Admin Domains

| Domain | Responsibilities |
| --- | --- |
| **Dashboard** | Executive snapshot: KPIs, business monitoring, customer service metrics, revenue analytics, live activity |
| **Catalog — Services** | Create/edit services, Starting From pricing information, media, schedule rules, visibility, both customer action paths, and the per-service payment policy (`payment_type`, `deposit_percentage` — §23.4) |
| **Catalog — Store** | Create/edit products, categories, images, Selling Price, Cost Price, Current Stock display, Low Stock Threshold, visibility |
| **Inventory** | Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Low Stock alerts |
| **Bookings** | List/filter bookings, assign, update status, add internal notes |
| **Quotations** | Review requests, issue/revise quotes, set validity and terms |
| **Orders** | Fulfill Store Orders, update processing/completion status |
| **Payments** | View transactions, confirm offline payments (bank transfer / cash — V1 admin verification), reject payments with reason (COD rejection cancels the order and restocks — §24.4), reconcile exceptions (refunds outside V1) |
| **Customers** | Search/filter `customer_profiles` with joined `users` contact data; view profile, business summary, timeline, linked records; internal staff notes; Inactive/suspend per policy — never permanent delete |
| **Notifications** | Templates, manual broadcast (optional), delivery diagnostics |
| **Settings** | Business info, currency, payment provider config, roles/permissions |
| **Audit / Logs** | Sensitive action history for accountability |

### 14.3 Admin Panel Non-Goals (v1)

- Full CRM/marketing automation suite
- Advanced BI/warehouse analytics (basic reports may suffice)
- Customer-facing chat unless separately scoped
- Multi-company tenancy

### 14.4 Admin Security Requirements

- Strong authentication for admin users through the separate **`admins`** identity and one shared Admin Login.
- RBAC with least privilege using only: **Admin**, **Sales**, **Accountant**.
- Role-based **sidebar**, **dashboard statistics**, and **actions** after login.
- Session timeout and access logging.
- Separation between production configuration and routine operations where feasible.

### 14.5 Role Access Matrix (Foundation)

| Module / Area | Admin | Sales | Accountant |
| --- | --- | --- | --- |
| Executive Dashboard | Yes (full) | Yes (scoped) | Yes (scoped) |
| Customers | Yes | Yes | No |
| Bookings | Yes | No | No |
| Quotations | Yes | Yes | No |
| Orders | Yes | Yes | Yes |
| Payments | Yes | No | Yes |
| Invoices | Yes | No | Yes |
| Receipts | Yes | No | Yes |
| Reports | Yes | No | No |
| Services (Catalog) | Yes | No | No |
| Store (Catalog) | Yes | No | No |
| Notifications | Yes | No | No |
| Settings | Yes | No | No |
| Audit / Logs | Yes | No | No |

### 14.6 Executive Dashboard Contents

- **Overview KPIs:** Total Customers, Active Bookings, Pending Quotations, Orders, Payments, Revenue — each card is clickable and navigates to its module/report; each shows a compact green/red trend indicator  
- **Business monitoring:** Pending / In Progress / Completed Today / Delayed Bookings; Pending / Under Discussion / Accepted / Expired Quotations — each widget opens the filtered module  
- **Customer service:** Unanswered discussions, replies waiting, open requests, new customers today — each metric is clickable  
- **Revenue analytics:** Today / Weekly / Monthly; Services vs Store revenue — drill-down to Daily / Weekly / Monthly / Service / Store reports  
- **Recent activity:** Booking Created, Quotation Updated, Payment Received, Order Placed, Order Delivered  

**Excluded (v1):** Staff Performance, Staff on Duty, Jobs Assigned, Team Workload — Staff Management is out of scope; assignments are handled manually outside the system.

---

## 15. Future Scalability

### 15.1 Architectural Scalability

- Modular backend domains (identity, catalog, booking, quotation, order, payment, notification) to allow independent evolution.
- Stateless API tier horizontally scalable behind load balancing.
- Asynchronous processing for notifications, webhooks, and heavy admin jobs.
- Pagination, filtering, and indexed queries for growing catalogs and histories.
- CDN/object storage readiness for media assets.

### 15.2 Product Scalability (Roadmap Candidates)

| Area | Future Capability |
| --- | --- |
| **Services** | Recurring bookings, subscriptions, multi-technician assignment |
| **Store** | Variants at scale, promotions/coupons, wishlists, bundles; heavy cleaning equipment/machines (outside V1) |
| **Quotations** | Multi-option parallel quote packages, e-signature (In-app Discuss Quotation messaging + revision on the same quotation is **in scope for V1** — not future work) |
| **Payments** | Multiple gateways, wallets, split payments, escrow-like holds |
| **Notifications** | Preference center, marketing campaigns with consent |
| **Users** | Family/shared accounts, corporate customers |
| **Geography** | Multi-branch, service-area maps, multi-currency/language |
| **Channels** | Customer web portal, WhatsApp/bot intake, partner APIs |
| **Workforce** | Dedicated staff/technician mobile apps |
| **Platform** | Multi-vendor marketplace (only if business model changes) |

### 15.3 Technical Debt Prevention

- Keep commercial state machines explicit and documented.
- Avoid embedding business rules only in the mobile client.
- Design APIs for versioning from early releases.
- Ensure auditability of money and status transitions before adding automation.

### 15.4 Scalability Success Criteria

The system should support growth in customers, catalog size, and transaction volume without redesigning the core Booking, Quotation, Payment, and Notification workflows described in this SRS.

---

## 16. Requirements Traceability (Summary)

| Business Need | Primary SRS Sections |
| --- | --- |
| Sell products | Store Module, Payment Workflow |
| Deliver bookable services | Service Modules, Booking Workflow |
| Price custom work | Quotation Workflow, Payment Workflow |
| Keep customers informed | Notification Workflow |
| Maintain trust & account continuity | Customer Profile, Security NFRs |
| Operate the business | Admin Panel Overview |
| Grow without rewrite | Future Scalability, modular NFRs |

---

## 17. Document Control

---

## 18. Reports & Analytics Module (Admin)

### FR-090 Reports & Analytics

#### FR-090.1 Reports Dashboard
The system shall provide a Reports Dashboard at `/admin/reports` that displays:
- Six premium KPI cards: Total Customers, Active Bookings, Pending Quotations, Orders, Payments, Revenue.
- Each KPI card shall show a trend indicator comparing the current period to the previous period.
- Interactive charts: a Revenue & Orders Trend chart (line/area/bar) and a Booking Status Distribution chart (pie).
- A report categories section with six navigable cards: Customer, Booking, Quotation, Order, Payment, Revenue Reports.
- All dashboard data shall respond to the selected date range.

#### FR-090.2 Date Range Filtering
The system shall support the following date range presets:
- Today, Yesterday, Last 7 Days, Last 30 Days, This Month, Last Month.
- Custom Date Range with a manual start/end date picker.
- Selecting a date range shall re-calculate all KPI values and chart data on the current view.

#### FR-090.3 Role-Based Access
- **Admin** role: full access to all report categories.
- **Sales** role: access to Customer, Booking, Quotation, Order reports only. Payment and Revenue reports are hidden.
- **Accountant** role: access to Payment and Revenue/Financial reports only. Customer, Booking, Quotation, and Order reports are hidden.
- Restricted categories shall not be rendered; a warning banner shall inform the user of the restriction.

#### FR-090.4 Customer Reports
The system shall provide a Customer Reports detail view showing:
- KPI cards: New Customers, Active Customers, Leads, Customer Growth %, Total Customers.
- Charts: Customer Growth Trend (area), Customer Segments (pie).
- A "Top Customers by Total Spent" table with columns: Rank, Customer, Phone, Total Orders, Total Spent, Status, Last Activity.
- Accessible by Admin and Sales roles.

#### FR-090.5 Booking Reports
The system shall provide a Booking Reports detail view showing:
- KPI cards: Total Bookings, Completed, Cancelled, Pending, Average Completion Time.
- Charts: Booking Trends (bar), Booking Status (pie).
- Drill-down table filtered by the clicked KPI card (e.g., Completed bookings).
- Accessible by Admin and Sales roles.

#### FR-090.6 Quotation Reports
The system shall provide a Quotation Reports detail view showing:
- KPI cards: Total Quotations, Accepted, Under Discussion, Expired, Conversion Rate.
- Charts: Quotation Funnel (bar), Conversion Rate Trend (bar/line).
- Accessible by Admin and Sales roles.

#### FR-090.7 Order Reports
The system shall provide an Order Reports detail view showing:
- KPI cards: Total Orders, Completed Orders, Cancelled Orders, Average Order Value.
- Charts: Order Volume & Value (line/bar), Order Status (pie).
- Drill-down table: Order ID, Customer, Items, Total, Status, Date.
- Accessible by Admin and Sales roles.

#### FR-090.8 Payment Reports
The system shall provide a Payment Reports detail view showing:
- KPI cards: Total Payments, Confirmed, Pending, Failed, Refunded.
- Charts: Payment Trends (area), Payment Distribution (pie).
- Accessible by Admin and Accountant roles.

#### FR-090.9 Revenue Reports
The system shall provide a Revenue Reports detail view showing:
- KPI cards: Revenue Today, Weekly Revenue, Monthly Revenue, Yearly Revenue.
- Chart: Monthly Revenue Trend (bar, 12-month view).
- Revenue Breakdown: Revenue by Services (top services with bar indicators), Revenue by Products (top products with bar indicators).
- Accessible by Admin and Accountant roles.

#### FR-090.10 Interactive Drill-down
- Every KPI card shall be clickable; clicking shall navigate to the corresponding detail report filtered by the card's metric.
- Chart segments (pie slices, bar sections) shall support drill-down to filtered views.

#### FR-090.11 Export
The system shall support the following export actions from any report view:
- Export PDF: generates a downloadable PDF snapshot.
- Export Excel: generates a downloadable XLSX file.
- Print Report: opens the browser print dialog.
Export shall produce a snapshot of the currently filtered/displayed data only.

#### FR-090.12 Global Report Search
The system shall provide a global search bar on the Reports Dashboard that allows searching by: report name, customer name, booking reference, order reference, payment reference, revenue category, and date. Search results shall appear as suggestions in a dropdown. Selecting a result navigates directly to the matching report. Search is read-only.

#### FR-090.13 Saved Filters
The system shall allow users to save frequently used report filter combinations (date range + report selection) as named saved filters. Saved filters are user-specific and stored per user account. Clicking a saved filter chip applies its settings immediately. Examples: "Manager Monthly Review", "Finance Weekly Report", "Revenue This Month".

#### FR-090.14 Empty State
Whenever a report query returns no matching data, the system shall display a professional empty state with: an illustrative icon, heading ("No data available"), a descriptive message suggesting an alternative date range, and a "Change Date Range" action. KPI cards shall show "0" or "—" with muted styling.

#### FR-090.15 Last Generated
Every report detail page shall display a read-only "Last Generated" indicator showing the date and time the report data was last computed. This is informational only and not user-editable.

#### FR-090.16 Report Summary
At the bottom of every report detail view, the system shall display a computed summary section with 4 key metrics derived from the report data. Each metric shows a label, value (colour-coded for trend direction), and contextual detail. The summary is auto-generated from existing report data only — no AI or external data sources. A badge labelled "Auto-generated" is displayed.

#### FR-090.17 Dashboard Favorites
Admin users shall be able to pin favourite reports to the top of the Reports Dashboard. Pinned reports appear first as cards with star icon, report name, and subtitle. Users can unpin reports on hover. Pinning is user-specific. This is a convenience feature and does not modify business data.

#### FR-090.18 Business Rules
- BR-R01: Reports are read-only. No report endpoint or UI action may create, update, or delete business data.
- BR-R02: All report values must be calculated from existing system records at query time. No separate reporting tables are required (aggregation queries on source tables).
- BR-R03: Reports must adapt based on the logged-in user's role.
- BR-R04: Date range selection applies globally to all KPI cards and charts.
- BR-R05: No new business features are introduced by this module.
- BR-R06: Saved filters and dashboard favorites are user-specific preferences.
- BR-R07: Report summaries are computed from existing report data only. No AI.

---

## 19. Settings Module (Admin)

### FR-091 Settings Module

#### FR-091.1 Settings Dashboard
The system shall provide a Settings Dashboard at `/admin/settings` accessible only to Admin role users. The dashboard shall display the setting categories as premium cards, in this order: Company, Branches, Currency, Tax, Numbering, SMTP, Notifications, Storage, Localization, Backup, Audit Logs — followed by Service Settings, Store Settings, Payment Settings, Security Settings, Roles & Permissions, and System Information. Each card shows an icon, title, description, last updated timestamp, and an "Open" action.

#### FR-091.2 Access Control
- Only Admin role users shall have access to Settings.
- Sales and Accountant roles shall not see the Settings menu item and shall receive 403 Forbidden if accessing settings URLs directly.

#### FR-091.3 Company Settings
The system shall allow Admin to edit: Company Name, Logo (upload PNG/SVG, max 2 MB), Email, Phone, Website, Address, Tax ID (company tax identification number — not tax configuration; see FR-091.6), Business Hours (opening/closing), Facebook, Instagram, WhatsApp.

#### FR-091.4 Branch Management
The system shall provide a Branch Management screen listing all company branches. Each branch has: Code, Name, City, Status (`ACTIVE` / `INACTIVE` / `COMING_SOON`), and a Default flag.

Current Version (V1):
- Mogadishu (MGQ) is the only operational branch: Status `ACTIVE`, Default `YES`.
- All transactions belong to the Mogadishu branch.
- Hargeisa (HGA) is displayed as `COMING_SOON`, Default `NO`.
- Hargeisa cannot participate in any transaction.
- A `COMING_SOON` or `INACTIVE` branch cannot become the default branch.
- Exactly one branch is the default branch at any time, and it must be `ACTIVE`.
- Only Super Admin may activate Hargeisa in a future release. Branch activation is not exposed to any other role.
- Multi-branch support may be introduced in a future version without redesigning the module.

#### FR-091.5 Currency Settings
Currency configuration exists only in this category. The system shall allow Admin to configure: Default Currency (USD/SOS), Currency Symbol ($ / Sh), Decimal Places (0/2), Thousand Separator (comma / period / space / none). These settings control display formatting only; stored monetary amounts are never mutated by settings changes.

#### FR-091.6 Tax Settings
Tax configuration exists only in this category. The system shall allow Admin to configure: Default Tax enabled/disabled, Tax Rate percentage (0–100, up to 2 decimal places), and Tax Mode (Inclusive / Exclusive). Tax settings apply to future documents only; existing documents retain the tax values captured at creation time.

#### FR-091.7 Numbering Settings (Document Numbering)
The system shall allow Admin to edit entity number prefixes for: Customers (CUS-), Bookings (BK-), Quotations (QT-), Invoices (INV-), Receipts (RCT-), Orders (ORD-), Payments (PAY-). The system shall provide an Auto Numbering toggle (enabled by default); when enabled, document numbers are generated automatically and are not manually editable. A real-time next-number preview shall be displayed. Changing a prefix only affects future records; existing records retain their original numbers.

#### FR-091.8 SMTP Settings
The system shall allow Admin to configure outbound mail: Host, Port, Encryption (None/SSL/TLS), Username, Password (write-only; masked and never returned in responses), and a "Send Test Email" action that dispatches a test message to a specified address and reports success or failure.

#### FR-091.9 Notification Settings
The system shall allow Admin to enable/disable notification channels: Push Notifications, Email Notifications, Browser Notifications, SMS Notifications. The system shall additionally provide per-event alert toggles: Booking Alerts, Quotation Alerts, Payment Alerts. The system shall provide editable templates for: Booking Confirmation, Quotation Ready, Payment Received, Order Completed. Templates support placeholders ({customer_name}, {booking_id}, {amount}, etc.).

#### FR-091.10 Storage Settings
The system shall allow Admin to configure: Upload Limits (max file size in MB per upload), Allowed File Types (extension whitelist), and Storage Driver (Local / S3-compatible). Changing the storage driver affects future uploads only. These settings do **not** govern the Unified File Upload Service in V1; its limits are configuration-driven with no Settings UI (FR-040A).

#### FR-091.11 Language & Localization
The system shall allow Admin to set: Default Language (English/Somali/Arabic), Time Zone (East Africa Time UTC+3 / GMT UTC+0), Date Format (DD MMM YYYY / MM/DD/YYYY / YYYY-MM-DD), Time Format (12-hour / 24-hour). Currency configuration is managed exclusively by Currency Settings (FR-091.5).

#### FR-091.12 Backup & Restore
The system shall allow Admin to: trigger a Manual Backup, view the backup list, Download a Backup archive, and Restore from a selected backup. Restore is a destructive operation and shall require explicit confirmation and Super Admin authority. Backup files shall not be publicly accessible.

#### FR-091.13 Settings Audit Logs
The system shall display a read-only audit log of all settings changes. Each entry records: User (staff name and role), Date/time, Setting key, Old Value, and New Value. The audit log cannot be edited or deleted through the UI.

#### FR-091.14 Service Settings
The system shall allow Admin to configure: Booking Working Hours (start/end), Working Days (day toggles), Holidays (table of holiday name, date, status), Booking Availability (open/closed), Default Booking Lead Time (12h/24h/48h/72h).

#### FR-091.15 Store Settings
The system shall allow Admin to manage: Product Categories (Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners), Default Delivery Fee (currency input), Inventory Warning Level / Low Stock Threshold (dashboard alerts; Email/SMS outside V1). Tax configuration is managed exclusively by Tax Settings (FR-091.6).

#### FR-091.16 Payment Settings
The system shall allow Admin to enable/disable the V1 payment methods via toggles: EVC Plus, eDahab, Bank Transfer, Cash on Delivery (store orders), Cash on Service (cleaning services). Additionally: Payment Instructions (textarea). Currency configuration is managed exclusively by Currency Settings (FR-091.5). No payment gateway integration is included in this release. Jeeb, Salaam Somali Bank, cards, and wallets are not part of V1 (§23.7).

#### FR-091.17 Security Settings
The system shall allow Admin to configure: Minimum Password Length (6/8/10/12), Password Complexity (letters/letters+numbers/letters+numbers+symbols), Password Expiry (never/30/90/180 days), Session Timeout (15min/30min/1hr/4hr), Login Audit Logging (toggle). Two-Factor Authentication shall be displayed as a future feature, clearly labelled, with disabled/greyed-out toggles.

#### FR-091.18 Roles & Permissions
The system shall display a read-only role matrix showing which modules each role (Admin, Sales, Accountant) can access. This matrix is not editable through the UI.

#### FR-091.19 System Information
The system shall display read-only system information: App Version, Database Version, Last Backup (date/time), System Status (operational indicator), Privacy Policy (view link), Terms & Conditions (view link).

#### FR-091.20 Save / Discard
Every editable settings page shall provide: a "Save Changes" button (persists modifications), a "Discard Changes" button (reverts to last saved state), and a "Last Updated By" indicator showing staff name, role, date, and time.

#### FR-091.21 Global Settings Search
The system shall provide a global search bar on the Settings Dashboard. Searchable dimensions: Company, Branch, Currency, Tax, Prefix, SMTP, Notification, Storage, Language, Backup, Audit, Booking, Payment, Security, Roles. Selecting a result navigates to the corresponding settings page. Search is read-only.

#### FR-091.22 Unsaved Changes Protection
If the user attempts to leave a settings page with unsaved modifications, the system shall display a confirmation dialog with three options: "Save Changes" (persist and navigate), "Discard Changes" (revert and navigate), "Continue Editing" (close dialog, remain on page).

#### FR-091.23 Restore Defaults
Every editable settings category shall include a "Restore Defaults" action. Clicking it shows an inline confirmation banner requiring explicit confirmation before applying factory default values. Restore only affects the current category and does not modify historical records.

#### FR-091.24 Settings History
Every editable settings page shall include a "View Change History" section displaying the audit log entries for that category (sourced from the Settings Audit Logs, FR-091.13). Each entry shows: Changed By (staff name/role), setting key, old value, new value, date, time. A "View Full History" action opens the complete change log. This view is read-only.

#### FR-091.25 Maintenance Mode
Under System Information, the system shall display a Maintenance Mode section marked as a future feature. The toggle shall be disabled and non-interactive with a clear "Future" badge and "Coming in a future release" description.

#### FR-091.26 Business Rules
- BR-S01: Settings are available to Admin role only.
- BR-S02: Settings change system configuration only. Settings never modify historical business records.
- BR-S03: All settings changes are logged (who, what, when) in the audit trail.
- BR-S04: Future features (2FA, Maintenance Mode) are clearly labelled and non-interactive.
- BR-S05: Read-only sections (Roles & Permissions, System Info) do not show Save/Discard.
- BR-S06: Numbering prefix changes only affect future records.
- BR-S07: No new business features are introduced.
- BR-S08: Restore Defaults requires confirmation and does not affect historical records.
- BR-S09: Mogadishu (MGQ) is the only operational branch in V1; it is `ACTIVE` and the default branch. All transactions belong to the Mogadishu branch.
- BR-S10: Hargeisa (HGA) is `COMING_SOON`; it cannot participate in any transaction and cannot become the default branch.
- BR-S11: Only Super Admin may activate Hargeisa (change its status to `ACTIVE`) in a future release.
- BR-S12: Exactly one default branch exists at any time, and the default branch must be `ACTIVE`.
- BR-S13: Currency, tax, and numbering changes affect future records only; historical documents are immutable.
- BR-S14: SMTP passwords and other sensitive settings values are write-only: masked in the UI and never returned by APIs or written to logs.
- BR-S15: Restore from backup requires Super Admin authority and explicit confirmation.
- BR-S16: Multi-branch support may be introduced in a future version without redesigning the module.

---

## 20. Customer Management Module (Admin)

### FR-092 Customer Management

This section specifies the complete Customer Management module used in the Admin Panel. It extends FR-084 and reuses the approved identity architecture (ADR-001): a Customer is the pair of a `users` authentication identity and its one-to-one `customer_profiles` business record. Customers may register from the Mobile Application, the Website, or be created by staff from the Admin Panel.

#### FR-092.1 Authentication Boundary

- Browsing the application does NOT require login.
- Login is REQUIRED before a customer can: Book a Service, Request a Quotation, or Place a Store Order.
- Guest Customers are NOT supported. Every booking, quotation, order, payment, and review must belong to a registered customer account.

#### FR-092.2 Customer Account Uniqueness

- Each customer may have ONLY ONE account; duplicate customer accounts are not allowed.
- Customer Code is generated automatically by the system using the format `CUS-######` (zero-padded sequential): `CUS-000001`, `CUS-000002`, `CUS-000003`. It is read-only and never editable.
- Phone Number must be unique across all customer accounts.
- Email must be unique when provided (email is optional).

#### FR-092.3 Customer Profile

The system shall store and display the following customer profile data:

- Customer Code (`CUS-######`, auto-generated, read-only)
- Full Name
- Mobile Number
- Email
- Gender
- Date of Birth
- Profile Photo
- Preferred Language (Somali / English / Arabic)
- Status (see FR-092.5)
- Registration Date
- Last Login
- Internal Notes (staff-only; see FR-092.8)
- Tags (free-form staff labels for segmentation)

#### FR-092.4 Customer Addresses

The system shall support multiple addresses per customer. Each address contains:

- Label (e.g., Home, Office)
- Contact Person
- Phone Number
- Country
- State
- District
- Address (street/detail text)
- GPS Latitude
- GPS Longitude
- Default Address flag

At most one default address exists per customer among active addresses. Addresses are never permanently deleted (marked Inactive instead, per FR-071).

#### FR-092.5 Customer Status

The customer account status shall use ONLY these values (no additional statuses may be introduced):

| Status | Definition |
| --- | --- |
| `ACTIVE` | Customer has full access. |
| `INACTIVE` | Customer account is temporarily inactive and cannot use customer services until reactivated. |
| `BLOCKED` | Customer cannot Login, Book Services, Request Quotations, or Place Store Orders. |
| `DELETED` | Soft Deleted customer. Hidden from normal customer lists. All business history remains available. |

These values are stored and enforced on `customer_profiles.status` only. They are distinct from `users.status` (identity / authentication lifecycle). See Status Field Responsibilities below.

##### Status Field Responsibilities

| Field | Responsibility |
| --- | --- |
| `users.status` | Identity / Authentication lifecycle. Controls account authentication state and identity-related lifecycle. Not used for customer business operations. |
| `customer_profiles.status` | Customer business status. Controls operational permissions such as booking services, requesting quotations, and placing store orders. |

- Business operations MUST use **`customer_profiles.status`**.
- Authentication lifecycle MUST use **`users.status`**.
- These two fields serve different purposes and must never be treated as interchangeable.

#### FR-092.6 Customer Relationships

A customer may have: Bookings, Quotations, Store Orders, Payments, Reviews, Addresses, Notes, Attachments, and Activity Logs. All relationships shall remain permanently linked to the customer account, including after soft deletion.

#### FR-092.7 Customer Activity Timeline

The system shall record and display a chronological activity timeline per customer, covering these events:

- Registration
- Login
- Profile Update
- Password Reset
- Address Added
- Address Updated
- Booking Created
- Booking Updated
- Booking Completed
- Quotation Requested
- Quotation Accepted
- Store Order Created
- Payment Recorded
- Review Submitted

Timeline entries are read-only audit history and must be presented in chronological order.

#### FR-092.8 Customer Notes

Internal notes only, visible only to authorized staff (never exposed to customers). Each note contains: Note (body), Created By (staff name/role), Created At.

#### FR-092.9 Customer Attachments

The system shall support attaching files to a customer record: Images, PDF, and Documents. For each attachment the system stores: File Name, File Type, File Size, Uploaded By, Uploaded At.

#### FR-092.10 Search

Staff shall be able to search customers by: Customer Code, Full Name, Phone Number, Email.

#### FR-092.11 Filters

Staff shall be able to filter the customer list by: Status, Registration Date, Last Login, Country, State, District.

#### FR-092.12 Customer Deletion Policy

- Soft Delete ONLY. Customer records are never permanently deleted during normal operations.
- Soft Deleted customers must retain: Bookings, Quotations, Orders, Payments, Reviews, and Audit History. No business records may be lost.

#### FR-092.13 Customer Restore Policy

- Only Super Admin may restore deleted customers.
- Restored customers may return to `ACTIVE` or `INACTIVE`.
- Historical records must remain intact through delete and restore.

#### FR-092.14 Permissions

The Customer Management module uses the following Hybrid RBAC permission keys:

| Permission | Grants |
| --- | --- |
| `customers.view` | View customer list, details, timeline, and activity history |
| `customers.create` | Create customer accounts from the Admin Panel |
| `customers.update` | Edit profile data, manage addresses, change status (`ACTIVE` / `INACTIVE` / `BLOCKED`) |
| `customers.delete` | Soft delete customers |
| `customers.restore` | Restore soft-deleted customers (exercisable by Super Admin only in V1) |
| `customers.notes` | View and add internal customer notes |
| `customers.attachments` | View, upload, and remove customer attachments |

#### FR-092.15 Validation Rules

- Phone Number must be unique.
- Email must be unique (when provided).
- GPS coordinates must be valid (latitude −90..90, longitude −180..180).
- Required fields must be validated.
- Customer Code is generated automatically and cannot be supplied or edited.

#### FR-092.16 Business Rules

- BR-C01: Guest customers are not supported; every transactional record belongs to a registered customer account.
- BR-C02: One account per customer; phone unique, email unique when provided.
- BR-C03: Customer Code format is `CUS-######` (e.g., `CUS-000001`), auto-generated, sequential, read-only.
- BR-C04: Customer status uses only `ACTIVE`, `INACTIVE`, `BLOCKED`, `DELETED`.
- BR-C05: `BLOCKED` customers cannot login, book services, request quotations, or place store orders.
- BR-C06: `INACTIVE` customers cannot use customer services until reactivated.
- BR-C07: Deletion is soft delete only; `DELETED` customers are hidden from normal lists and all business history remains available.
- BR-C08: Only Super Admin may restore deleted customers, and only to `ACTIVE` or `INACTIVE`.
- BR-C09: Internal notes and attachments are staff-only and never exposed on customer-facing APIs.
- BR-C10: The activity timeline is read-only, chronological audit history.
- BR-C11: Access to every Customer Management capability is governed by the `customers.*` permission keys (FR-092.14).

---

## 21. Customer Reviews Module

### 21.1 Objective

Allow registered customers to rate and comment on **completed bookings**, with admin moderation controlling public visibility, so that trust surfaces (service details, reviews lists) display genuine, verified feedback.

### 21.2 Functional Requirements

#### FR-093.1 V1 Scope

Customer Reviews V1 supports **completed booking reviews only**. Product reviews, order reviews, and direct service-target reviews are **out of V1 scope** and may be introduced in a future version.

#### FR-093.2 Review Submission & Eligibility

- Only authenticated, registered customers may submit reviews (no guest reviews, per FR-092.1).
- A review may be submitted only for a booking that belongs to the submitting customer and has status `Completed`.
- **One completed booking → one review.** A second review for the same booking is rejected while a review exists. Multiple completed bookings allow multiple reviews (one each).
- If a customer deletes their **pending** review, they may submit another review for the same booking.
- There is **no review deadline**: a completed booking remains reviewable indefinitely.
- Each submission records a `Review Submitted` activity timeline event (FR-092.7).

#### FR-093.3 Review Content

- `rating`: required integer 1–5.
- `title`: optional, max 150 characters.
- `comment`: **optional**; when provided, it must be **10–1000 characters**.

#### FR-093.4 Moderation Lifecycle

- Every review is created in status **`pending`**.
- Only admin moderation changes status: **Approve** → `published`, **Hide** → `hidden`. Re-moderation between `published` and `hidden` is allowed.
- Public surfaces (service details, reviews lists) display **`published` reviews only**.
- Admins never edit review content and never reply to reviews in V1.
- Moderation never deletes reviews; abusive content is hidden, preserving audit history.
- If a completed booking is reverted to a non-completed status, its review automatically becomes **`hidden`**. The review is never deleted automatically.
- Review moderation notifications (informing the customer of publish/hide outcomes) are **deferred until a future version**.

#### FR-093.5 Customer Edit / Delete Policy

- A customer may edit or delete their review **only while it is `pending`**.
- Once a review is `published` or `hidden`, the customer can no longer edit or delete it.

#### FR-093.6 Public Reviewer Identity

- Public review payloads display the reviewer as **First Name + Initial** (e.g., "Hodan A.").
- Reviews authored by soft-deleted customers remain visible (when `published`) and display **"Verified Customer"** instead of a name.
- Full names, phone numbers, emails, and Customer Codes are never exposed on public review surfaces.

#### FR-093.7 Rating Aggregates

- Each service carries cached aggregates: `average_rating` and `reviews_count`, computed from `published` reviews only.
- Aggregates are recalculated whenever a review is **published or hidden** — never on submission.
- `average_rating` is stored as DECIMAL(3,2); clients display it rounded to **one decimal place**.
- There is **no minimum review threshold**: ratings are visible starting from the first published review.

#### FR-093.8 Rate Limiting

Review submission is limited to **5 submissions per minute per customer**.

#### FR-093.9 Home Reviews Section

The Home reviews endpoint (`GET /api/v1/home/reviews`) is delivered with the Home module (**Sprint 29**, §26); it returns a preview of `published` reviews only.

#### FR-093.10 Permissions

| Permission | Grants |
| --- | --- |
| `reviews.view` | View reviews in any status (Admin Panel) |
| `reviews.moderate` | Approve (publish) and hide reviews |

### 21.3 Business Rules

- BR-R01: V1 reviews target completed bookings only; no product/order/service-target reviews.
- BR-R02: One review per completed booking; multiple completed bookings → multiple reviews.
- BR-R03: Reviews begin `pending`; only admin moderation publishes or hides them.
- BR-R04: Customers may edit/delete only `pending` reviews; `published`/`hidden` reviews are immutable to the customer.
- BR-R05: No review deadline after booking completion.
- BR-R06: Comments are optional; when provided, 10–1000 characters; ratings are always 1–5.
- BR-R07: Public reviewer identity is First Name + Initial; soft-deleted authors display "Verified Customer".
- BR-R08: Admins never edit review content and never reply to reviews in V1.
- BR-R09: Service `average_rating` / `reviews_count` are cached and recalculated on publish/hide from `published` reviews only.
- BR-R10: Reviews are permanently retained through customer soft-deletion (FR-092.12).
- BR-R11: Reverting a completed booking to a non-completed status automatically hides its review; the review is never deleted automatically.
- BR-R12: Deleting a pending review re-opens the booking for a new review submission.
- BR-R13: Ratings display from the first published review (no minimum threshold); clients render the average to one decimal place.

---

## 22. Favorites Module

> **Disambiguation:** this module covers **customer service favorites** (mobile heart/save feature). It is unrelated to admin Reports Dashboard favorites (FR-090.17), which are internal admin preferences.

### 22.1 Objective

Allow authenticated customers to save services for later retrieval, without altering booking, quotation, cart, checkout, or payment workflows.

### 22.2 Functional Requirements

#### FR-094.1 V1 Scope

Favorites V1 supports **services only**. Product favorites are **out of V1 scope** and deferred to a future version.

#### FR-094.2 Add / Remove / List

- Only authenticated customers with an **active** account may add, remove, or list favorites. Inactive (non-active status or soft-deleted) customers cannot use any favorites capability.
- **One favorite per customer per service.** Adding an already-favorited service is idempotent and returns success (`200 OK`) without creating a duplicate.
- Adding requires an **active** service; unknown or inactive services are rejected as not found.
- Removal is keyed by **service** (heart-toggle semantics), not by favorite record id. Removal is **idempotent**: removing a service that exists and is accessible but is not currently favorited succeeds (`200 OK`); not-found responses are reserved for services that do not exist or are not accessible to the customer.
- The favorites list returns the customer's favorited services as **full Service Card payloads** (catalog card contract), newest-favorited first, paginated (default 20, max 100).

#### FR-094.3 `is_favorite` Visibility

- **Guest responses shall never include `is_favorite`.** Public catalog and Home payloads are cacheable guest payloads and never carry it, even when a token is supplied.
- `is_favorite` may appear **only** in authenticated, customer-specific responses (e.g., the favorites list itself).
- Heart state on public catalog cards is resolved client-side from the customer's favorites list.

#### FR-094.4 Automatic Removal

When a service becomes **inactive or is deleted**, all related favorites are **automatically removed** for all customers. Favorites of unavailable services are never surfaced.

#### FR-094.5 Favorites Aggregate

Each service maintains a cached **`favorites_count`**, updated on favorite add, remove, and automatic removal. The aggregate is internal and is not exposed in public catalog payloads.

#### FR-094.6 Limits & Rate Limiting

- There is **no maximum favorites count** per customer; the list is controlled by pagination only.
- Favorites endpoints are limited to **30 requests per minute per customer**.

#### FR-094.7 Exclusions (V1)

- No activity-log/timeline events are recorded for favorites actions.
- No admin favorites management APIs or screens.
- Favorites trigger no notifications.

### 22.3 Business Rules

- BR-F01: V1 favorites target services only; product favorites are deferred.
- BR-F02: `is_favorite` never appears in guest responses; only authenticated customer-specific responses may include it.
- BR-F03: One favorite per customer per service; add and remove are both idempotent (`200 OK` when already favorited on add, and when not currently favorited on remove; `404` only for non-existent or inaccessible services).
- BR-F04: Service deactivation or deletion automatically removes related favorites.
- BR-F05: Favorites never mutate booking, quotation, cart, checkout, or payment state.
- BR-F06: Inactive customers cannot add, remove, or list favorites.
- BR-F07: Services carry a cached `favorites_count`, maintained on add/remove/automatic removal; it is not a public catalog field.
- BR-F08: No favorites limit; pagination only. Favorites endpoints are rate limited to 30 requests/minute/customer.
- BR-F09: Favorites produce no activity-log events and have no admin management in V1.

---

## 23. Payment Methods & Customer Devices Module

### 23.1 Objective

Define the final V1 payment method set, per-service payment policies, the Cash on Delivery order lifecycle, payment stages, customer device registration for future push notifications, and multi-device authentication rules.

### 23.2 Payment Methods (V1)

#### FR-095.1 Supported Methods

V1 supports exactly these payment methods:

| Method | Applies to |
| --- | --- |
| EVC Plus (default) | Store orders and cleaning services |
| eDahab | Store orders and cleaning services |
| Bank Transfer | Store orders and cleaning services |
| Cash on Delivery | Store products only |
| Cash on Service | Cleaning services only (per service payment policy) |

#### FR-095.2 Removed from V1

Jeeb, Salaam Somali Bank, Visa, Mastercard, Apple Pay, Google Pay, Zaad, Sahal, and Premier Wallet are **removed from V1 completely**. No saved payment methods, no saved cards, no PCI storage.

### 23.3 Store Products Payment (COD Lifecycle)

#### FR-095.3 Checkout Flow

Store purchase flow: Cart → Checkout → **Payment Method Selection** → Order Confirmed.

- Prepaid methods (EVC Plus, eDahab, Bank Transfer): the order remains `pending_payment` until payment is confirmed, then becomes `confirmed`.
- Cash on Delivery: selecting COD confirms the order immediately; the payment is recorded and remains pending until collected.

#### FR-095.4 COD Order Status Flow

```text
Confirmed → Preparing → Out for Delivery → Delivered
        → Payment Pending → (Admin Confirms Payment) → Completed
```

A COD order is never `completed` until an admin confirms cash collection.

### 23.4 Cleaning Services Payment Policy

#### FR-095.5 Per-Service Policy

Each service stores its own payment policy:

- `payment_type` — one of `full_before_service`, `deposit`, `pay_after_service`.
- `deposit_percentage` — required **only** when `payment_type = deposit`; allowed range **1–99**; NULL otherwise. Configurable by the administrator per individual service (e.g., Deep Cleaning 30%, Office Cleaning 50%).

#### FR-095.6 Quotation Acceptance & Payment Gates

```text
Quotation Accepted
   → (deposit) customer pays quotation_total × deposit_percentage
   → Deposit Payment Confirmed
   → Booking Scheduled
   → Service Completed
   → Remaining Balance Payable
   → Admin Confirms Final Payment
   → Booking Closed
```

- `full_before_service`: full payment confirmed before the booking becomes Scheduled.
- `deposit`: deposit confirmed before Scheduled; balance payable after completion.
- `pay_after_service`: no pre-payment; full amount payable after completion.
- **Booking `Closed`** = service completed **and** all required payments completed.

#### FR-095.7 Snapshot Rules

When a quotation is accepted, the system snapshots onto the quotation: `payment_type`, `deposit_percentage`, `deposit_amount`, `remaining_amount`. Future changes to the service's payment policy **must not** change already-accepted quotations.

#### FR-095.8 Payment Stages

Every payment record carries `payment_stage` ∈ {`deposit`, `balance`, `full`}. The payment amount must always equal the server-calculated installment according to `payment_stage` and the snapshotted `payment_type` (§11.4A).

### 23.5 Customer Devices

#### FR-095.9 Device Registration

The system shall register customer devices to support **future** push notifications. Notification sending is out of this module's scope.

- Ownership: devices belong to **`users`** (authentication principal), not `customer_profiles`.
- Stored fields only: `device_id` (client-generated installation identifier), `user_id`, `platform`, `push_token`, `app_version`, `last_seen_at`, `is_active`.
- Explicitly **not** stored: device name, model, GPS, IP history, battery, trusted-device flags.
- Registration is an authenticated upsert keyed by (`user_id`, `device_id`); the push token is updatable.
- Invalid/expired tokens are deactivated, never failing business transactions.

### 23.6 Authentication (Multi-Device)

#### FR-095.10 Sessions

- Multi-device login is **allowed**: one customer account may hold valid tokens on multiple devices simultaneously.
- Logout revokes the **current device token only**; other devices stay signed in.
- **Logout All Devices** is deferred to a future version.
- Password reset **continues to revoke all tokens** for the account (security behavior, unchanged).

### 23.7 Deferred (Future Versions)

- Payment gateway integration (any online provider)
- Saved payment methods / saved cards / PCI storage
- Refunds, disputes, chargebacks
- Multiple currencies
- Recurring payments
- Customer device management screen (Active Devices remains a placeholder)
- Logout All Devices

### 23.8 Business Rules

- BR-P01: Only the five V1 methods are selectable; admin can toggle each via Payment Settings (FR-091.16).
- BR-P02: COD applies to store orders only; Cash on Service applies to cleaning services only.
- BR-P03: Payment amounts are always server-calculated installments (`payment_stage` × snapshotted policy); clients never set amounts.
- BR-P04: Offline confirmations (bank transfer, cash) are admin-verified with full audit.
- BR-P05: Accepted quotations are immutable with respect to later service payment-policy changes (snapshot rule).
- BR-P06: A booking is `Closed` only when the service is completed and all required payments are confirmed.
- BR-P07: Devices are user-owned, minimal-field records for future push delivery only.

---

## 24. Admin Operations Module (Sprint 27)

### 24.1 Objective

Expose the operational payment, booking, and store-order lifecycles through secure Admin APIs so administrators can confirm/reject offline payments, run bookings from scheduling to closure, and advance store-order fulfilment — with RBAC, auditing, and mandatory customer notifications.

### 24.2 Payment Administration

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-096.1 | Admins shall list and view payments in any status with filters (status, method, stage, payable type, customer, date range, payment/receipt number search), paginated. | Must |
| FR-096.2 | Admins shall confirm offline payments (`pending`/`initialized`/`processing` → `paid`) with full audit (confirming admin, timestamp) and a once-generated receipt number. Confirming an already-`paid` payment is idempotent. Terminal payments (`failed`/`cancelled`) can never be confirmed. | Must |
| FR-096.3 | Admins shall reject active payments with a required reason (→ `failed`). Rejecting a **prepaid** payment leaves the payable unchanged; the customer may retry with a new payment. | Must |
| FR-096.4 | Confirming a payment shall apply the payable side effects atomically: prepaid store order → stock deduction + `confirmed`; COD store order at `payment_pending` → `completed`; service order → `confirmed`; final service payment → booking `Completed` → `Closed`. | Must |

### 24.3 Booking Administration

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-096.5 | Admins shall list and view bookings in any status with filters (status, service, customer, date range, booking number search), paginated, including computed payment-gate indicators (`can_schedule`, `can_close`). | Must |
| FR-096.6 | Booking acceptance is **automatic** on customer quotation acceptance. There shall be **no admin acceptance endpoint**. | Must |
| FR-096.7 | Admins shall schedule an `Accepted` booking (confirmed schedule window required) only when the snapshotted payment policy's gate is satisfied (deposit paid / full paid / no payment for `pay_after_service`). | Must |
| FR-096.8 | Admins shall transition `Scheduled` → `In Progress` → `Completed`; completing a booking makes the remaining balance payable per the snapshotted policy. | Must |
| FR-096.9 | Admins shall close a `Completed` booking only when **all** required payments are `paid`; closure also happens automatically when the final payment is confirmed. | Must |
| FR-096.10 | Admins shall cancel a booking from any state before `Completed` with a required reason. Paid payments stay `paid` (refunds are V2); active payments are automatically failed in the same transaction — no active payment remains attached to a cancelled booking. Status reversions are not supported. | Must |

### 24.4 Store Order Administration

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-096.11 | Admins shall list and view store orders in any status with filters (status, customer, payment status, date range, STO number search), paginated. | Must |
| FR-096.12 | Admins shall advance store-order fulfilment through server-enforced transitions only: `confirmed` → `preparing` (COD) / `processing` (prepaid); `preparing` → `out_for_delivery` → `delivered` → `payment_pending`; `processing`/`delivered` → `completed` only when no active payment exists. A COD order completes **only** via payment confirmation. | Must |
| FR-096.13 | **COD payment rejection cascade (official V1 rule):** rejecting a COD payment shall, in one database transaction, set the payment `failed`, the store order `cancelled`, and automatically restock inventory — each order line writes a positive `sale_reversal` Stock Ledger entry restoring product stock. The cascade applies only while the order is not already `completed`/`cancelled`. | Must |

### 24.5 Mandatory Customer Notifications (V1)

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-096.14 | The system shall notify the customer on: **Payment Confirmed**, **Payment Rejected**, **Booking Scheduled**, **Booking Completed**, **Booking Cancelled**. | Must |
| FR-096.15 | Notifications shall be dispatched **after successful database commit**. Notification failures shall **never** roll back or block business transactions. | Must |

### 24.6 Security

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-096.16 | Admin Operations permissions: `payments.view`, `payments.confirm`, `bookings.view`, `bookings.manage`, `store_orders.view`, `store_orders.manage` — enforced per route via permission middleware within Hybrid RBAC. | Must |
| FR-096.17 | Every mutation shall run in a database transaction with row-level locking on the aggregate, write the domain status history with the acting admin, and emit an Audit Event (admin, action, entity, prior → new status, reason, IP, user agent) queryable in Audit Logs. | Must |
| FR-096.18 | Admin Operations mutation endpoints shall be rate-limited (60 requests per minute per admin). Confirmation shall be safe under concurrent double-submission (idempotent). | Must |

### 24.7 Business Rules

- BR-O01: Booking acceptance is a customer-driven automatic transition; admins never accept bookings.
- BR-O02: Cancelling a booking never reverses money: `paid` stays `paid`; refunds belong to V2.
- BR-O03: No active payment may remain attached to a cancelled booking (auto-fail in the same transaction).
- BR-O04: COD rejection always cancels the order and restocks inventory via `sale_reversal` ledger entries.
- BR-O05: `sale_reversal` is used exclusively for automatic COD-rejection restock — never for manual adjustments.
- BR-O06: A store order never reaches `completed` while an active payment exists.
- BR-O07: The five V1 notifications are mandatory and dispatched after commit only.

---

## 25. Quotation Request Workflow Administration (Sprint 28)

### 25.1 Permissions

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-097.1 | Quotation permissions: `quotations.view` (queue, detail, attachments), `quotations.review` (assign/reassign reviewer, discussion reply, close discussion), `quotations.issue` (issue Version 1 and revisions), `quotations.manage` (expire, cancel, accept on customer's behalf) — enforced per route via permission middleware within Hybrid RBAC. | Must |

### 25.2 Audit Events

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-097.2 | Audit Events shall be emitted for: Assign Reviewer, Issue Quotation, Revision, Close Discussion, Expire, Cancel, and Admin Accept. Every audit payload shall include `quotation_number`, `version_number` (where applicable), `admin_id`, `previous_status`, `new_status`, `timestamp`, and `reason` (where applicable). | Must |

### 25.3 Notifications

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-097.3 | The system shall notify the relevant party on: **Quotation Submitted** (to operations), **Quotation Issued**, **Quotation Revised**, **Discussion Reply**, **Quotation Expired**, **Quotation Cancelled**. | Must |
| FR-097.4 | Quotation notifications shall be dispatched **after successful database commit** (`DB::afterCommit()` pattern); notification failures shall never roll back or block business transactions. | Must |

### 25.4 Security

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-097.5 | Every quotation mutation shall run in a database transaction with row-level locking on the quotation aggregate; revision `version_number` values shall be assigned under the row lock (revision concurrency protection). | Must |
| FR-097.6 | Customer acceptance shall validate the referenced revision is the **latest** inside the locked transaction; stale references return `409 Conflict` (latest-revision validation). | Must |
| FR-097.7 | Customer quotation endpoints enforce ownership scoping (non-owned records resolve as `404`); admin mutation endpoints are rate-limited per the Admin Operations limiter. | Must |

---

## 26. Home & Global Search Module (Sprint 29)

> **Scope (final):** guest Home APIs (aggregate + section endpoints), global search across services and products, search suggestions, admin Home Content management (hero banners, Before & After gallery, FAQ, featured curation), and the Home caching strategy. **Announcements are out of scope for Backend V1.** Full Admin Services CRUD is split out of this sprint and deferred.

### 26.1 Home APIs

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-098.1 | The system shall provide **both** a Home aggregate endpoint (`GET /api/v1/home`) **and** per-section endpoints (`hero-banners`, `service-categories`, `featured-services`, `store-products`, `before-after`, `reviews`, `faq`, `contact`). Clients (Flutter, Web, future mobile) may use either approach; both return identical section content. | Must |
| FR-098.2 | All Home endpoints shall be guest-accessible (no authentication) and throttled by the shared `public-catalog` rate limiter (60 requests per minute per IP). | Must |
| FR-098.3 | Hero banners shall support `action_type` ∈ {`service`, `product`, `category`, `url`, `none`} with an `action_reference` where applicable. Only **active** banners **inside their schedule window** appear publicly. | Must |
| FR-098.4 | Featured Services shall use `services.is_featured` with **manual admin curation only**, ordered by `sort_order`. Inactive services shall never appear in any Home section. | Must |
| FR-098.5 | Popular Services ranking shall use the internal `favorites_count` aggregate; `favorites_count` shall **never** be exposed through public APIs. | Must |
| FR-098.6 | Out-of-stock products shall **remain visible** in Home and search payloads with an **Out of Stock** availability state; they are never hidden. | Must |
| FR-098.7 | The public contact endpoint shall expose **only approved company fields** and never SMTP settings, API keys, internal settings, authentication settings, or any sensitive configuration. | Must |
| FR-098.8 | `GET /api/v1/home` shall return `generated_at`, `cache_expires_at`, and `version` metadata. These fields are **informational only**; clients shall not derive business logic from them. | Must |
| FR-098.9 | Home responses shall be served from a server-side cache with a **5-minute TTL**, invalidated by the `HomeCacheInvalidator` when any of the following change: hero banners, featured services, featured products, FAQ, gallery items, review moderation, service status, or product status. | Must |

### 26.2 Global Search

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-099.1 | The system shall provide unified search (`GET /api/v1/search`), service search, and product search — all guest-accessible, minimum query length 2, throttled by the `public-catalog` rate limiter, and paginated using the standard `meta` format. | Must |
| FR-099.2 | Search suggestions (`GET /api/v1/search/suggestions`) shall return at most **10 results** with exactly: `type`, `name`, `slug` (or product identifier), and `thumbnail`. Suggestions shall **not** include prices, discounts, or stock. | Must |
| FR-099.3 | Recent searches shall remain **device-local**. The backend shall store nothing: no search-history table and no user search tracking. | Must |
| FR-099.4 | Search shall use PostgreSQL `pg_trgm` indexes in production; the SQLite automated-test environment uses a `LIKE` fallback with an identical API contract. | Must |

### 26.3 Search Ranking Rules

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-099.5 | Results shall be ranked by match tier: **1)** exact name match, **2)** prefix match, **3)** word match, **4)** description match — with tie-breakers, in order: `sort_order`, featured flag, alphabetical name. Ranking is server-authoritative and identical for all clients. | Must |

### 26.4 Home Content Administration

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-100.1 | Admins shall manage hero banners, Before & After gallery items, FAQ entries, and the featured curation toggle through permission-guarded APIs: `content.view` (read) and `content.manage` (mutations), within Hybrid RBAC. | Must |
| FR-100.2 | Every Home Content mutation shall emit an Audit Event (acting admin, entity, changed fields) and shall invalidate Home caches via the `HomeCacheInvalidator`. | Must |

---

### 17.1 Out of Scope for This Document

- UI/UX wireframes or visual design
- Flutter widget/code structure
- Laravel code or route definitions
- Database ERDs or table schemas
- API endpoint contracts (to be covered in a subsequent API specification)
- Vendor selection for payment/SMS/push providers

### 17.2 Next Recommended Documents

1. `03` — System Architecture Overview  
2. `04` — Domain Model & State Machines (Booking / Quote / Order / Payment)  
3. `05` — API Specification  
4. `06` — Admin Panel Requirements Detail  
5. `07` — UI/UX Requirements (still design, not implementation)

### 17.3 Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Software Architect |  |  |  |
| Engineering Lead |  |  |  |
| QA Lead |  |  |  |

---

*End of Document — Fayadhowr SRS v1.0*
