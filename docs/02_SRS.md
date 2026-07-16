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
- Quotation-based services cannot be paid until a valid quote is accepted by the customer (unless policy allows deposits earlier — see Quotation Workflow).
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
- Google Sign-In uses native Android/iOS account pickers with accounts already on the device (future implementation — customer does not type a Gmail address).
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
| FR-003 | The system shall allow customers to reset or recover credentials securely (email/password path includes Forgot Password). | Must |
| FR-004 | The system shall allow customers to log out from the mobile app (with confirmation). | Must |
| FR-005 | The system shall restrict transactional features to authenticated customers. | Must |

### 5.2 Discovery & Catalog

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-010 | The system shall present service categories and service listings with name, description, media, optional Starting From price, and both **Book Now** and **Request Quotation** options. | Must |
| FR-011 | The system shall present store categories and product listings with name, description, media, Selling Price, and availability (In Stock / Low Stock / Out of Stock). V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners. | Must |
| FR-012 | The system shall support search and/or filter of services and products. | Should |
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
| FR-029 | Payment method presentation shall prioritize Somali methods: EVC Plus (default), eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, then optional Card and future Digital Wallet. | Must |
| FR-029A | Store Orders shall reuse the Unified Payment Module and follow Order lifecycle: `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`. | Must |

### 5.3A Inventory

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-030I | Inventory shall manage Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Stock Quantity, and Low Stock Alerts as a domain separate from Store commerce. | Must |
| FR-031I | Purchase Order lifecycle shall be: Draft → Submitted → Approved → Partially Received → Completed / Cancelled. Purchase Order alone shall never change stock. Goods Receipts are allowed only when the Purchase Order is `approved` or `partially_received` (never while only `submitted`). | Must |
| FR-032I | Goods Receipt shall increase stock and create Stock Ledger entries. Goods Receipt requires a Purchase Order in `approved` or `partially_received` status. | Must |
| FR-033I | Every stock movement shall be recorded in Stock Ledger with quantity, movement type, reference, user, and timestamp. Movement types include Purchase Receipt, Customer Sale, Stock Adjustment, Correction, Damage, and Loss. | Must |
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
| FR-040 | The customer shall be able to submit a quotation request with requirements, attachments (if supported), and contact context. | Must |
| FR-041 | The system shall allow admins to issue a formal quotation against a request. | Must |
| FR-042 | The customer shall be able to view quotation details (line items, totals, validity period, terms, quotation number, and latest revision indicator). | Must |
| FR-043 | After a quotation is issued, the customer shall have two primary actions: **Accept Quotation** and **Discuss Quotation**. The application shall never offer **Reject Quotation**. | Must |
| FR-044 | Accepted quotations shall unlock the corresponding payment / fulfillment path. | Must |
| FR-045 | **Discuss Quotation** shall keep the workflow open on the **same quotation** (no new quotation created). Customer and team may message, upload additional images/videos/PDFs, and the team may update/revise the quotation. | Must |
| FR-046 | Quotation statuses shall be limited to: `Pending Review`, `Quotation Ready`, `Under Discussion`, `Accepted`, `Expired`, `Cancelled`. Status `Rejected` shall never be used. | Must |
| FR-047 | Every quotation update shall create a new revision (v1, v2, v3…). Only the latest revision may be accepted; older revisions remain read-only for audit. | Must |
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
| FR-060 | The system shall create an in-app notification for every major business event (booking, quotation, discussion, order, payment, delivery, account, general announcements). | Must |
| FR-061 | The customer shall be able to view a searchable, filterable notification list (All / Unread / Read) with category icons. | Must |
| FR-062 | The customer shall be able to mark a notification as read and mark all as read. Customers shall **never** delete notification records (notifications are permanent business history). | Must |
| FR-063 | The system shall support push notifications where device permissions allow. | Must |
| FR-064 | Opening a notification shall deep-link to the correct related record and display the reference number when applicable (`BK-`, `QT-`, `ORD-`, `PAY-`, etc.). Notification Details shall show Read / Unread status. | Must |
| FR-065 | Customers shall be able to manage notification preferences: Push Notifications, Email Notifications, plus Booking, Quotation, Discussion, Order, Payment, and Marketing toggles. | Must |

### 5.8 Customer Profile & Support Context

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-070 | The customer shall be able to view My Account using `customer_profiles` fields (photo, name, **read-only** Customer Reference `CUS-YYYY-######`, preferred language, member since) and `users` fields (email, phone), plus quick stats for Bookings / Quotations / Orders. | Must |
| FR-071 | The customer shall be able to add/edit saved addresses and set a default. Addresses shall **never be permanently deleted**; unused addresses are marked **Inactive**. | Must |
| FR-072 | The customer shall be able to view consolidated history of orders, bookings, quotations, and payments via account navigation. | Must |
| FR-073 | The customer shall be able to manage notification preferences (Push, Email, and category toggles). | Must |
| FR-074 | The customer shall be able to edit profile photo and full name on `customer_profiles`, and email and phone on `users`. Customer Reference Number remains read-only on `customer_profiles`. | Must |
| FR-075 | The customer shall be able to select preferred language: Somali, English, or Arabic; selection updates the entire application. | Must |
| FR-076 | The customer shall be able to manage saved payment methods (add / set default) for EVC Plus, eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, and Debit/Credit Card. Payment **history** is never deleted by the customer. | Must |
| FR-077 | Security screens shall support Change Password, optional Change PIN, and placeholders for Two-Factor Authentication and Active Devices. | Should |
| FR-078 | Help Center shall provide FAQs and Contact Fayadhowr channels (WhatsApp, Phone, Email). About Fayadhowr shall present a company profile (story, mission, vision, years of experience, certificates & licenses, awards, partners & clients, statistics) plus Privacy Policy, Terms & Conditions, and app version. | Must |
| FR-079 | Logout shall require confirmation (Cancel / Log Out) and must never log the customer out immediately on first tap. | Must |

### 5.9 Admin Panel (Functional Summary)

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-080 | Admins shall manage products, services, categories, pricing, and availability (subject to role — **Admin**). | Must |
| FR-081 | Admins shall manage bookings (list/search/filter; view details with media counters, timeline with actor audit, linked records; status updates via controlled dropdown of approved statuses only; read-only Priority High/Medium/Low; booking age; informational manual Assigned To; internal staff notes with name/role/date/time). Booking Number is read-only. Bookings are never permanently deleted. No Booking Value / Estimated Value on this module. | Must |
| FR-082 | Admins shall manage quotations (list/search/filter; view details with price breakdown, revision history with Created By/role/date/time, read-only Compare Revisions between any two versions, discussion with keyword search and attachment counters, timeline with actor audit, linked records; status updates via controlled dropdown; validity countdown on list and details; issue revisions; internal staff notes). Every quotation must originate from a **Booking** or **Product Request** only — Admin / Sales / Accountant shall never create a standalone quotation. QT Number is read-only. Quotations are never permanently deleted. Only the latest revision may be accepted. Discussion history cannot be deleted. | Must |
| FR-083 | Admins shall manage orders (list/search/filter; view details with ordered items, price breakdown with discounts/delivery/tax, business summary cards, timeline with actor audit, order documents with availability status, linked records incl. **Order Documents** shortcut; status updates via controlled dropdown of approved order statuses only; **Current Stage Indicator** compact read-only label above progress tracker showing current workflow stage; **Order Progress Tracker** visual stepper Pending Payment → Confirmed → Processing → Completed highlighting current step; **Order Age** displayed in list and details; **Payment Timeline** with expanded payment events Payment Requested/Received/Confirmed/Refund Processed each with Performed By, Staff Role, Date, Time; **Documents Status** each document shows ✅ Available or ⏳ Not Available Yet; **Financial Summary** compact read-only breakdown Subtotal/Discount/Delivery Fee/Tax/Grand Total/Amount Paid/Remaining Balance; **Latest Note indicator** read-only timestamp of most recent internal note). Every order is created **automatically** from an accepted quotation — no manual Create Order. Order Number is read-only. Orders are never permanently deleted. Every order remains permanently linked to its originating Booking or Product Request and its accepted Quotation. **Payment Status color system** standardized across Admin Panel: Paid (green), Partially Paid (orange), Unpaid (red), Refunded (blue). Order statuses: Pending Payment, Confirmed, Processing, Completed, Cancelled. Internal staff notes with name/role/date/time. | Must |
| FR-084 | Admins shall manage customer profiles (list/search/filter with default **Active Customers**; view profile joined to `users` contact data, Member Since, business summary including **Total Spent**, timeline with icons, linked records; internal staff notes with name/role/date/time audit; set Inactive / suspend per policy). Customer Number is auto-generated and read-only on `customer_profiles`. Classification is **Lead** vs **Active Customer** (no VIP). Customer identities and profiles are never permanently deleted. | Must |
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
- Stock Adjustment
- Correction
- Damage
- Loss

Ledger stores quantity, movement type, reference, user, and timestamp.

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
5. System creates a **Booking** owned by `customer_profile_id`, assigns a unique `BK-YYYY-######` public booking number, snapshots the selected address/service context, and sets initial status **Pending Review** (see §9.6).
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
| Customer cancelation allowed | Transition to cancelled; apply refund rules if paid |
| Customer cancelation not allowed | Block cancel; show policy message |
| Payment failure | Keep booking in payable state; allow retry |
| No-show / operational cancel | Admin updates status; notify customer; apply policy |

### 9.6 Booking Status Model (Logical)

Approved Admin Panel booking statuses (display labels):

- `Pending Review`
- `Quotation Ready`
- `Under Discussion`
- `Accepted`
- `Scheduled`
- `In Progress`
- `Completed`
- `Cancelled`

Notes:

- `Draft` remains client-side only and is not persisted as a server booking.
- **`Rejected` is never used.**
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
2. Customer submits requirements: description, preferred timing, location, attachments (images/videos/PDFs as enabled).
3. System creates the quotation record with a unique Quotation Number (e.g. `QT-2026-000123`) in status **Pending Review**.
4. System notifies operations.
5. Fayadhowr team reviews the request; discussion/clarification occurs **on the same quotation** when needed (in-app, v1).
6. Team issues a formal **Quotation** (revision v1) with line items, total, taxes/fees (if any), validity period, and terms.
7. Status becomes **Quotation Ready**. System notifies the customer (includes Quotation Number).
8. Customer reviews the latest quotation (Latest Version / current revision clearly indicated).
9. Customer primary actions: **Accept Quotation** or **Discuss Quotation**.
10. If **Discuss Quotation**: status becomes **Under Discussion**. Customer and team may exchange messages and additional files; team may update the quotation creating **v2, v3…** on the **same** quotation (never a separate quotation). Each update notifies the customer. Discussion **never closes** the quotation.
11. If **Accept Quotation** (latest revision only, while not Expired/Cancelled): status becomes **Accepted**; system unlocks **Payment**.
12. Customer pays according to quote terms (full or deposit). Linked payment/invoice references are recorded.
13. Operations fulfills; timeline continues through service completion.

### 10.5 Alternate / Exception Flows

| Scenario | Behavior |
| --- | --- |
| Incomplete request | Validation errors; record not created |
| Quote expired | Acceptance blocked; status **Expired**; Discuss may still follow company policy for revival via revision if allowed |
| Cancelled | Status **Cancelled** per company policy; acceptance blocked |
| Customer chooses Discuss | Status **Under Discussion**; workflow remains open on same Quotation Number |
| Quotation updated / revised | New revision (vN); prior revisions read-only; customer notified (“Your quotation has been updated…”) |
| New discussion message | Notification to the other party |
| Payment after accept fails | Acceptance retained; payment remains retryable until cancelled by policy |

### 10.6 Quotation Status Model (Logical)

Use **only** these statuses:

| Status | Meaning |
| --- | --- |
| **Pending Review** | Submitted; awaiting team review / first issue |
| **Quotation Ready** | Latest formal quotation available for customer review |
| **Under Discussion** | Discuss Quotation is active; messaging and/or revisions in progress |
| **Accepted** | Customer accepted the **latest** revision; payment path unlocked |
| **Expired** | Validity ended; cannot be accepted |
| **Cancelled** | Closed per company policy; cannot be accepted |

**Never use** status or action labels: `Rejected`, `Reject Quotation`, or equivalent.

### 10.7 Discussion, Versioning & Timeline Rules

| Rule | Behavior |
| --- | --- |
| Discuss vs Reject | **Discuss Quotation** replaces Reject everywhere |
| Same quotation | Discussion and revisions stay on the same Quotation Number |
| Version control | Each update creates Quotation v1, v2, v3…; only latest may be accepted |
| Latest indicator | UI must show **Latest Version** or **Revision N (Current)** |
| Revision history | Customers may open **View Revision History** (read-only) |
| Timeline | Maintain history: Quotation Created, Customer Discussion, Team Replies, Quotation Updated, Customer Acceptance, Payment, Service Completion |
| Notifications | Every quotation update and every new discussion message notifies the other party |

### 10.8 Commercial Rules

- Only the **latest** revision of a quotation may be accepted.
- Older revisions remain available read-only for transparency and auditing.
- Acceptance creates a binding customer intent subject to terms and moves the customer to **Payment**.
- Discussion never closes a quotation.
- Expired and Cancelled quotations cannot be accepted.

### 10.9 Unified Reference Numbers & Linked Records

Every major business record shall have a unique reference in the unified numbering system (see §10.10). Quotations use `QT-YYYY-######` (example: `QT-2026-000123`).

Linked navigation (admin and support): Customer → Booking (when applicable) → Quotation → Payment → Invoice → Order/History — without re-searching.

### 10.10 Unified Reference Number System (V1)

| Record | Prefix pattern | Example |
| --- | --- | --- |
| Customer | `CUS-YYYY-######` | `CUS-2026-000001` |
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
| Service Order | Payable through the unified Payment module |
| Store Order | Future payable type supported without redesign |

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

### 11.5 Rules & Safeguards

- Amount charged must equal backend-calculated payable amount at initiation time.
- Payment records and one-or-more `payment_transactions` must be unique and reconcilable.
- Webhook/callback handling must be idempotent.
- Repeated callbacks for the same successful gateway transaction must never create duplicate payment, transaction, history, or order state transitions.
- Customers must never mark a payment successful from the client alone.
- Every successful payment produces one receipt with `RCPT-YYYY-######`; receipt PDF generation is outside V1.
- Gateway adapters must remain provider-neutral for EVC Plus, Zaad, Sahal, Stripe, PayPal, and future providers.

### 11.6 Failure & Reconciliation

- Abandoned attempts remain Pending, Initialized, or Processing until approved failure/cancellation policy applies.
- Admin finance views must allow matching provider transactions to internal payment records.
- Refunds, disputes, and chargebacks are outside V1.

---

## 12. Notification Workflow

### 12.1 Purpose

Deliver timely, relevant updates to customers (and optionally admins) about commercial and operational events.

### 12.2 Channels

| Channel | Use |
| --- | --- |
| **Push Notification** | Time-sensitive alerts when app is backgrounded |
| **In-App Notification Center** | Persistent history of events |
| **Email/SMS** (optional) | Configurable for critical events if providers are integrated |

### 12.3 Trigger Events (Minimum Set)

| Domain | Events |
| --- | --- |
| **Booking** | Booking Submitted, Confirmed, Rescheduled, Cleaner Assigned, Cleaning Started, Cleaning Completed, Booking Cancelled |
| **Quotation** | Quotation Ready, Quotation Updated, New Discussion Reply, Quotation Accepted, Quotation Expired, Quotation Cancelled |
| **Discussion** | New message / reply on an open quotation discussion |
| **Order** | Order Placed, Confirmed, Packed, Shipped, Out for Delivery, Delivered, Order Cancelled |
| **Payment** | Payment Received, Payment Failed, Refund Processed |
| **Delivery** | Shipment / delivery progress events (aligned with order fulfillment) |
| **Account** | Password Changed, Email Updated, Phone Updated, Security Alert |
| **General Announcements** | Operational or service announcements (non-transactional) |

### 12.3A Categories

Each notification has a category with a distinct icon and color accent: Booking · Quotation · Discussion · Order · Payment · Delivery · Account · General Announcements.

Reference numbers (`BK-`, `QT-`, `ORD-`, `PAY-`, etc.) are included in the payload/UI wherever applicable.

### 12.4 Notification Flow

1. Domain event occurs in the backend.
2. Notification service selects template, category, and recipients (respecting preference toggles for non-critical types).
3. System persists an in-app notification for the customer (with `reference_type` / `reference_number` for deep links).
4. System attempts push delivery if Push Notifications are enabled and device permissions allow.
5. Customer opens the list or a push → Notification Details → action opens the related record.
6. Customer may mark as read / mark all as read (no delete).

### 12.5 Rules

- Notifications must not expose sensitive secrets (full payment credentials, OTPs beyond dedicated auth flows, etc.).
- Customers see only their own notifications.
- Template content is admin-configurable where practical.
- Delivery failures must not roll back the underlying business transaction.
- Preference controls may suppress non-critical notifications (including Marketing) but not legally/operationally required notices.
- Every major business event automatically creates a notification.
- Notifications must always open the correct related record.

---

## 13. Customer Profile

### 13.1 Purpose

Provide a secure personal space for the authenticated `users` identity, linked `customer_profiles` business data, contact details, preferences, and activity history.

### 13.2 Profile Data (Logical)

- `users`: phone, email, password/credential metadata, provider linkage, verification flags, account status, and last-login metadata
- `customer_profiles`: name, avatar, Customer Reference Number `CUS-YYYY-######` (system-assigned, read-only), preferred language, classification, and notification preferences
- Addresses / service locations (`is_active`; never customer hard-deleted)
- Saved payment methods (masked; distinct from payment history ledger)
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
| Payment methods | Add / set default for Somali-first methods; history never customer-deleted |
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
- Payment history is never deleted by customers.
- Preferred language (Somali / English / Arabic) updates the entire application UI.

---

## 14. Admin Panel Overview

### 14.1 Purpose

The Admin Panel is the operational control plane for Fayadhowr. It is **not** part of the customer mobile app UI, but it is required for the product to function in production.

### 14.2 Core Admin Domains

| Domain | Responsibilities |
| --- | --- |
| **Dashboard** | Executive snapshot: KPIs, business monitoring, customer service metrics, revenue analytics, live activity |
| **Catalog — Services** | Create/edit services, Starting From pricing information, media, schedule rules, visibility, and both customer action paths |
| **Catalog — Store** | Create/edit products, categories, images, Selling Price, Cost Price, Current Stock display, Low Stock Threshold, visibility |
| **Inventory** | Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Low Stock alerts |
| **Bookings** | List/filter bookings, assign, update status, add internal notes |
| **Quotations** | Review requests, issue/revise quotes, set validity and terms |
| **Orders** | Fulfill Store Orders, update processing/completion status |
| **Payments** | View transactions, reconcile exceptions (refunds outside V1) |
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
The system shall provide a Settings Dashboard at `/admin/settings` accessible only to Admin role users. The dashboard shall display 10 setting categories as premium cards: Company Settings, Service Settings, Store Settings, Payment Settings, Notification Settings, Security Settings, Numbering Settings, Language & Localization, Roles & Permissions, System Information. Each card shows an icon, title, description, last updated timestamp, and an "Open" action.

#### FR-091.2 Access Control
- Only Admin role users shall have access to Settings.
- Sales and Accountant roles shall not see the Settings menu item and shall receive 403 Forbidden if accessing settings URLs directly.

#### FR-091.3 Company Settings
The system shall allow Admin to edit: Company Name, Logo (upload PNG/SVG, max 2 MB), Email, Phone, Address, Business Hours (opening/closing), Website, Facebook, Instagram, WhatsApp.

#### FR-091.4 Service Settings
The system shall allow Admin to configure: Booking Working Hours (start/end), Working Days (day toggles), Holidays (table of holiday name, date, status), Booking Availability (open/closed), Default Booking Lead Time (12h/24h/48h/72h).

#### FR-091.5 Store Settings
The system shall allow Admin to manage: Product Categories (Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners), Default Delivery Fee (currency input), Tax Percentage, Inventory Warning Level / Low Stock Threshold (dashboard alerts; Email/SMS outside V1).

#### FR-091.6 Payment Settings
The system shall allow Admin to enable/disable the following payment methods via toggles: EVC Plus, eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, Debit/Credit Card. Additionally: Currency selector (USD/SOS), Payment Instructions (textarea). No payment gateway integration is included in this release.

#### FR-091.7 Notification Settings
The system shall allow Admin to enable/disable notification channels: Push Notifications, Email Notifications, SMS Notifications. The system shall provide editable templates for: Booking Confirmation, Quotation Ready, Payment Received, Order Completed. Templates support placeholders ({customer_name}, {booking_id}, {amount}, etc.).

#### FR-091.8 Security Settings
The system shall allow Admin to configure: Minimum Password Length (6/8/10/12), Password Complexity (letters/letters+numbers/letters+numbers+symbols), Password Expiry (never/30/90/180 days), Session Timeout (15min/30min/1hr/4hr), Login Audit Logging (toggle). Two-Factor Authentication shall be displayed as a future feature, clearly labelled, with disabled/greyed-out toggles.

#### FR-091.9 Numbering Settings
The system shall allow Admin to edit entity number prefixes for: Customers (CUS-), Bookings (BK-), Quotations (QT-), Orders (ORD-), Payments (PAY-). A real-time next-number preview shall be displayed. Changing a prefix only affects future records; existing records retain their original numbers.

#### FR-091.10 Language & Localization
The system shall allow Admin to set: Default Language (English/Somali/Arabic), Currency (USD/SOS), Time Zone (East Africa Time UTC+3 / GMT UTC+0), Date Format (DD MMM YYYY / MM/DD/YYYY / YYYY-MM-DD).

#### FR-091.11 Roles & Permissions
The system shall display a read-only role matrix showing which modules each role (Admin, Sales, Accountant) can access. This matrix is not editable through the UI.

#### FR-091.12 System Information
The system shall display read-only system information: App Version, Database Version, Last Backup (date/time), System Status (operational indicator), Privacy Policy (view link), Terms & Conditions (view link).

#### FR-091.13 Save / Discard
Every editable settings page shall provide: a "Save Changes" button (persists modifications), a "Discard Changes" button (reverts to last saved state), and a "Last Updated By" indicator showing staff name, role, date, and time.

#### FR-091.14 Global Settings Search
The system shall provide a global search bar on the Settings Dashboard. Searchable dimensions: Company, Booking, Payment, Currency, Language, Notification, Security, Prefix, Roles, Backup. Selecting a result navigates to the corresponding settings page. Search is read-only.

#### FR-091.15 Unsaved Changes Protection
If the user attempts to leave a settings page with unsaved modifications, the system shall display a confirmation dialog with three options: "Save Changes" (persist and navigate), "Discard Changes" (revert and navigate), "Continue Editing" (close dialog, remain on page).

#### FR-091.16 Restore Defaults
Every editable settings category shall include a "Restore Defaults" action. Clicking it shows an inline confirmation banner requiring explicit confirmation before applying factory default values. Restore only affects the current category and does not modify historical records.

#### FR-091.17 Settings History
Every editable settings page shall include a "View Change History" section displaying the audit log for that category. Each entry shows: Changed By (staff name/role), setting key, old value, new value, date, time. A "View Full History" action opens the complete change log. This view is read-only.

#### FR-091.18 Maintenance Mode
Under System Information, the system shall display a Maintenance Mode section marked as a future feature. The toggle shall be disabled and non-interactive with a clear "Future" badge and "Coming in a future release" description.

#### FR-091.19 Business Rules
- BR-S01: Settings are available to Admin role only.
- BR-S02: Settings change system configuration only. Settings never modify historical business records.
- BR-S03: All settings changes are logged (who, what, when) in the audit trail.
- BR-S04: Future features (2FA, Maintenance Mode) are clearly labelled and non-interactive.
- BR-S05: Read-only sections (Roles & Permissions, System Info) do not show Save/Discard.
- BR-S06: Numbering prefix changes only affect future records.
- BR-S07: No new business features are introduced.
- BR-S08: Restore Defaults requires confirmation and does not affect historical records.

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
