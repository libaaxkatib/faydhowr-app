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
| **Customer** | An end user who registers and uses the Fayadhowr mobile app |
| **Service** | A bookable offering delivered by Fayadhowr (fixed-price or quotation-based) |
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
| **Operations Admin** | Manages bookings, quotes, orders | Fulfill requests, update statuses |
| **Catalog Admin** | Manages products and services | Keep catalog accurate and available |
| **Finance / Payment Operator** | Reviews payments and refunds | Reconcile transactions, handle exceptions |
| **Support Agent** | Assists customers | View customer history and resolve issues |
| **System Administrator** | Configures system settings | Roles, payment config, notification templates |

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
| **Admin (Super)** | Admin Panel | Full administrative control |
| **Admin (Operations)** | Admin Panel | Bookings, quotations, orders, status updates |
| **Admin (Catalog)** | Admin Panel | Products, services, categories, media, pricing |
| **Admin (Finance)** | Admin Panel | Payments, refunds, settlement views |
| **Admin (Support)** | Admin Panel | Read customer profiles/history; limited status actions as permitted |

### 4.2 Authentication & Authorization Principles

- Customers authenticate via secure credentials (e.g., phone/email + password or OTP — exact method to be confirmed in auth design).
- Sessions/tokens must expire and be refreshable according to security policy.
- Role-based access control (RBAC) applies to the admin panel.
- Customers may only access their own data (bookings, quotes, orders, payments, profile).
- Privilege escalation between customer and admin roles must be impossible via the mobile API.

### 4.3 Account Lifecycle

- Registration → verification (as required) → active use.
- Profile update and password/credential reset.
- Account deactivation / deletion request subject to legal and retention policy.

---

## 5. Functional Requirements

Requirements are identified as **FR-xxx**. Priority: **Must** / **Should** / **Could**.

### 5.1 Account & Access

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-001 | The system shall allow a customer to register an account with required identity fields. | Must |
| FR-002 | The system shall authenticate customers and maintain a secure session. | Must |
| FR-003 | The system shall allow customers to reset or recover credentials securely. | Must |
| FR-004 | The system shall allow customers to log out from the mobile app. | Must |
| FR-005 | The system shall restrict transactional features to authenticated customers. | Must |

### 5.2 Discovery & Catalog

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-010 | The system shall present service categories and service listings with name, description, media, and pricing model (fixed or quote-based). | Must |
| FR-011 | The system shall present store categories and product listings with name, description, media, price, and availability. | Must |
| FR-012 | The system shall support search and/or filter of services and products. | Should |
| FR-013 | The system shall show product/service detail pages with complete customer-facing information. | Must |

### 5.3 Store Commerce

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-020 | The customer shall be able to add products to a cart with quantity. | Must |
| FR-021 | The customer shall be able to update or remove cart items. | Must |
| FR-022 | The system shall validate stock/availability before checkout confirmation. | Must |
| FR-023 | The customer shall be able to place a store order and receive an order reference. | Must |
| FR-024 | The customer shall be able to view order history and order detail/status. | Must |

### 5.4 Bookings

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-030 | The customer shall be able to create a booking for an eligible fixed-price or bookable service. | Must |
| FR-031 | The system shall capture required booking details (service, schedule preference, location/notes as applicable). | Must |
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

### 5.6 Payments

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-050 | The customer shall be able to initiate payment for payable bookings, accepted quotations, and store orders. | Must |
| FR-051 | The system shall record payment status (pending, successful, failed, refunded) authoritatively. | Must |
| FR-052 | The system shall confirm successful payment to the customer and update related entity status. | Must |
| FR-053 | The system shall handle payment failures with clear retry guidance. | Must |
| FR-054 | Admins shall be able to view payment records and process refunds per policy. | Must |

### 5.7 Notifications

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-060 | The system shall send notifications for key lifecycle events (booking, quote, order, payment). | Must |
| FR-061 | Customers shall be able to view an in-app notification list. | Must |
| FR-062 | Customers shall be able to mark notifications as read. | Should |
| FR-063 | The system shall support push notifications where device permissions allow. | Must |

### 5.8 Customer Profile & Support Context

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-070 | The customer shall be able to view and update profile information. | Must |
| FR-071 | The customer shall be able to manage saved addresses (if address is required for delivery/service). | Should |
| FR-072 | The customer shall be able to view consolidated history of orders, bookings, quotations, and payments. | Must |
| FR-073 | The customer shall be able to manage notification preferences (where legally/operationally allowed). | Could |

### 5.9 Admin Panel (Functional Summary)

| ID | Requirement | Priority |
| --- | --- | --- |
| FR-080 | Admins shall manage products, services, categories, pricing, and availability. | Must |
| FR-081 | Admins shall manage bookings and update booking statuses. | Must |
| FR-082 | Admins shall review quotation requests and issue/revise quotations. | Must |
| FR-083 | Admins shall manage store orders and fulfillment statuses. | Must |
| FR-084 | Admins shall manage customers (view profile/history; suspend if policy requires). | Must |
| FR-085 | Admins shall configure notification templates and operational settings. | Should |
| FR-086 | Admins shall access payment/refund views for reconciliation. | Must |

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

Service modules represent Fayadhowr’s bookable and quotation-based offerings. They are distinct from store products: services are fulfilled through scheduling, assessment, and/or on-site/remote delivery rather than simple SKU shipment alone.

### 7.2 Service Types

| Type | Description | Customer Path |
| --- | --- | --- |
| **Fixed-Price Bookable Service** | Published price; customer selects schedule/details and books | Booking Workflow → Payment (per policy) |
| **Quotation-Based Service** | Price depends on scope/assessment | Quotation Workflow → Acceptance → Payment |
| **Hybrid Service** | May require deposit on booking and final amount after assessment | Booking + Quotation rules as configured |

### 7.3 Service Module Capabilities

- Category hierarchy for discovery.
- Service detail: description, inclusions/exclusions, media, duration estimates, pricing model, prerequisites.
- Eligibility rules (e.g., service area, minimum lead time).
- Capacity / scheduling rules (slots, blackout dates, max concurrent bookings).
- Required customer inputs (address, notes, attachments, preferred time).
- Linkage to booking records and/or quotation requests.
- Admin-managed activation/deactivation without deleting historical records.

### 7.4 Service Module Rules

- A service must declare its pricing model before it is customer-visible.
- Inactive services must not accept new bookings or quote requests.
- Historical bookings/quotes remain visible to the customer even if a service is later deactivated.
- Service content shown in the app is admin-authored and versioned operationally (content updates do not rewrite past commercial agreements).

---

## 8. Store Module

### 8.1 Purpose

The Store Module enables customers to browse and purchase physical or digital products offered by Fayadhowr.

### 8.2 Store Capabilities

- Product categories and catalog browsing.
- Product detail: title, description, images, price, variants (if any), stock/availability.
- Cart management (add, update quantity, remove).
- Checkout with delivery/pickup details as applicable.
- Order creation with unique order reference.
- Order status tracking (e.g., pending payment, paid, processing, shipped/ready, completed, cancelled).
- Order history in the customer account.

### 8.3 Store Business Rules

- Cart prices are indicative until checkout confirmation against backend prices.
- Checkout must re-validate availability and price.
- Out-of-stock items cannot be purchased.
- Partial fulfillment policies (if any) must be defined by operations; v1 may assume whole-order fulfillment unless otherwise approved.
- Store orders are commercially separate from service bookings unless an explicit bundle product/service offering is introduced later.

### Store Product Pricing

- All store products shall display their prices to customers.
- Customers can browse products, view prices, add products to the cart, and complete purchases.
- If a customer needs a quotation for products (such as bulk orders, custom quantities, or special requests), they can submit a quotation request through the application.
- This quotation process is optional and does not replace the normal fixed-price purchasing workflow.

### 8.4 Store ↔ Other Modules

| Interaction | Description |
| --- | --- |
| **Payments** | Store orders create payable amounts |
| **Notifications** | Order placed, paid, shipped/ready, cancelled |
| **Profile** | Orders appear in customer history; addresses may be reused |
| **Admin** | Catalog and fulfillment managed in admin panel |

---

## 9. Booking Workflow

### 9.1 Objective

Allow a customer to reserve a bookable service, track its lifecycle, and proceed to payment according to policy.

### 9.2 Actors

- Customer (mobile)
- Admin / Operations (admin panel)
- System (validation, notifications, payment hooks)

### 9.3 Preconditions

- Customer is authenticated.
- Selected service is active and bookable.
- Required scheduling capacity exists for the requested slot/window.
- Required fields (location, notes, etc.) are provided.

### 9.4 Main Flow

1. Customer selects a bookable service.
2. Customer reviews service details and pricing model.
3. Customer chooses preferred date/time (or available slot) and enters required details.
4. System validates eligibility, lead time, and capacity.
5. System creates a **Booking** in an initial status (e.g., `Requested` or `Confirmed` — exact status model to be finalized in process design).
6. System notifies customer and operations of the new booking.
7. Operations reviews/assigns/fulfills as needed and updates status.
8. Customer pays when the booking becomes payable (immediately, on confirmation, or on completion — per service policy).
9. System updates booking and payment statuses and notifies the customer.
10. Booking reaches a terminal status (`Completed`, `Cancelled`, or equivalent).

### 9.5 Alternate / Exception Flows

| Scenario | Behavior |
| --- | --- |
| Slot unavailable | Reject creation; prompt customer to choose another time |
| Service deactivated mid-flow | Prevent submission; show unavailable message |
| Customer cancelation allowed | Transition to cancelled; apply refund rules if paid |
| Customer cancelation not allowed | Block cancel; show policy message |
| Payment failure | Keep booking in payable state; allow retry |
| No-show / operational cancel | Admin updates status; notify customer; apply policy |

### 9.6 Booking Status Model (Logical)

Illustrative statuses (final enum to be approved in design):

- `Draft` (optional, client-side only)
- `Requested`
- `Confirmed`
- `In Progress`
- `Awaiting Payment` / `Paid`
- `Completed`
- `Cancelled`
- `Rejected` (by operations, if used)

### 9.7 Postconditions

- Booking exists with immutable reference ID.
- Customer can view status history and key details.
- Notifications have been emitted for state changes of customer relevance.
- Payment records are linked when payment occurs.

---

## 10. Quotation Workflow

### 10.1 Objective

Support variable-scope services by allowing customers to request a quote, receive a formal price proposal, **Accept** or **Discuss** it (never Reject), and proceed to payment only after acceptance.

### 10.2 Actors

- Customer
- Admin / Estimator / Operations (Fayadhowr team)
- System

### 10.3 Preconditions

- Customer is authenticated.
- Target service (or generic quote intake) is active and quote-enabled.
- Customer provides sufficient requirement details.

### 10.4 Main Flow

1. Customer selects a quotation-based service (or “Request a Quote” entry point).
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
| Order | `ORD-YYYY-######` | `ORD-2026-000001` |
| Payment | `PAY-YYYY-######` | `PAY-2026-000001` |
| Invoice | `INV-YYYY-######` | `INV-2026-000001` |
| Refund | `REF-YYYY-######` | `REF-2026-000001` |

Reference numbers must be unique within their type (and globally unique in recommended implementation). Display Quotation Number on Quotation Details, PDF, notifications, revision history, admin panel, payment records, customer support views, and receipts.

---

## 11. Payment Workflow

### 11.1 Purpose

Collect funds for store orders, payable bookings, and accepted quotations through approved payment methods, with reliable status tracking and reconciliation.

### 11.2 Payable Entities

| Entity | When Payable |
| --- | --- |
| Store Order | At checkout / after order creation per policy |
| Booking | On creation, confirmation, or completion per service policy |
| Accepted Quotation | Immediately after acceptance (full or deposit) |

### 11.3 Main Flow

1. Customer opens a payable entity and chooses **Pay**.
2. System creates a **Payment Attempt** with amount, currency, and reference to the entity.
3. Customer completes payment via integrated provider/method.
4. Provider returns success/failure (synchronous and/or webhook/callback).
5. System verifies payment authenticity (server-side verification).
6. On success:
   - Mark payment `Successful`.
   - Update related entity status.
   - Notify customer and operations.
7. On failure:
   - Mark payment `Failed`.
   - Keep entity payable if still valid.
   - Notify customer with retry guidance.

### 11.4 Payment Status Model (Logical)

- `Pending`
- `Successful`
- `Failed`
- `Cancelled`
- `Refunded` (full)
- `Partially Refunded` (if supported)

### 11.5 Rules & Safeguards

- Amount charged must equal backend-calculated payable amount at initiation time.
- Payment references must be unique and reconcilable.
- Webhook/callback handling must be idempotent.
- Customers must never mark a payment successful from the client alone.
- Refunds are admin-initiated (or provider-driven) and must update both payment and related entity state.
- Partial payments/deposits are supported only where explicitly configured on the service/quote terms.

### 11.6 Failure & Reconciliation

- Abandoned checkouts leave payments in `Pending` until timeout/cancel policy applies.
- Admin finance views must allow matching provider transactions to internal payment records.
- Disputes/chargebacks are handled operationally with status notes and audit entries.

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
| **Account** | Registration/verification success, security alerts |
| **Booking** | Created, confirmed, status changed, cancelled, payment due/received |
| **Quotation** | Pending Review (ops), Quotation Ready, updated (revision), Under Discussion message, expiring, Accepted, Expired, Cancelled |
| **Store** | Order placed, paid, fulfillment update, cancelled |
| **Payment** | Success, failure, refund |

### 12.4 Notification Flow

1. Domain event occurs in the backend.
2. Notification service selects template and recipients.
3. System persists an in-app notification for the customer.
4. System attempts push delivery if device token and permissions exist.
5. Customer opens notification and navigates to the related entity (deep link / in-app route).
6. Customer marks as read (manual or on open).

### 12.5 Rules

- Notifications must not expose sensitive secrets (full payment credentials, OTPs beyond dedicated auth flows, etc.).
- Customers see only their own notifications.
- Template content is admin-configurable where practical.
- Delivery failures must not roll back the underlying business transaction.
- Preference controls may suppress non-critical notifications but not legally required notices.

---

## 13. Customer Profile

### 13.1 Purpose

Provide a secure personal space for identity, contact details, preferences, and activity history.

### 13.2 Profile Data (Logical)

- Identity: name, phone, email (as applicable)
- Authentication metadata (not displayed secrets)
- Addresses / service locations (if used)
- Notification preferences
- Account status (active, suspended, pending verification)
- Activity summaries: orders, bookings, quotations, payments

### 13.3 Profile Capabilities

| Capability | Description |
| --- | --- |
| View profile | Customer reads current personal data |
| Update profile | Customer edits allowed fields with validation |
| Manage addresses | Add/edit/delete saved locations |
| View history | Access lists and details of past/present commercial activity |
| Security | Change password / re-authenticate for sensitive changes |
| Account control | Logout; request deactivation/deletion |

### 13.4 Profile Rules

- Customers cannot edit system-controlled fields (account ID, verification flags, suspension state).
- Profile updates must not alter historical invoice/order legal snapshots incorrectly (e.g., past order address remains as fulfilled).
- Suspended customers cannot create new bookings, quotes, or orders.
- Personal data access is strictly owner-scoped on customer APIs.

---

## 14. Admin Panel Overview

### 14.1 Purpose

The Admin Panel is the operational control plane for Fayadhowr. It is **not** part of the customer mobile app UI, but it is required for the product to function in production.

### 14.2 Core Admin Domains

| Domain | Responsibilities |
| --- | --- |
| **Dashboard** | Operational snapshot: open bookings, pending quotes, unpaid orders, recent payments |
| **Catalog — Services** | Create/edit services, pricing model, media, schedule rules, visibility |
| **Catalog — Store** | Create/edit products, stock, categories, visibility, pricing |
| **Bookings** | List/filter bookings, assign, update status, add internal notes |
| **Quotations** | Review requests, issue/revise quotes, set validity and terms |
| **Orders** | Fulfill store orders, update shipping/pickup status |
| **Payments** | View transactions, initiate refunds, reconcile exceptions |
| **Customers** | Search customers, view history, suspend/reinstate per policy |
| **Notifications** | Templates, manual broadcast (optional), delivery diagnostics |
| **Settings** | Business info, currency, payment provider config, roles/permissions |
| **Audit / Logs** | Sensitive action history for accountability |

### 14.3 Admin Panel Non-Goals (v1)

- Full CRM/marketing automation suite
- Advanced BI/warehouse analytics (basic reports may suffice)
- Customer-facing chat unless separately scoped
- Multi-company tenancy

### 14.4 Admin Security Requirements

- Strong authentication for admin users.
- RBAC with least privilege.
- Session timeout and access logging.
- Separation between production configuration and routine operations where feasible.

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
| **Store** | Variants at scale, promotions/coupons, wishlists, bundles |
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
