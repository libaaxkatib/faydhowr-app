# Fayadhowr Technical Architecture Document

## 1. Project Overview

Fayadhowr is a mobile-first business system supported by a Laravel backend and Flutter client applications. This document is the official technical architecture reference and defines the approved architectural boundaries for the project.

## 2. Technology Stack

| Area | Technology |
| --- | --- |
| Backend framework | Laravel 12 |
| Backend language | PHP 8.x |
| Database | PostgreSQL |
| Mobile application | Flutter |
| Application interface | REST API |
| Authentication | Laravel Sanctum |
| Source control | Git and GitHub |

## 3. High-Level System Architecture

The system consists of Flutter clients communicating with a versioned Laravel REST API over HTTPS. Laravel contains application logic, authorization, validation, and integrations. PostgreSQL is the system of record. File storage, notifications, logging, backups, and monitoring are supporting infrastructure concerns.

## 4. Backend Architecture

### Folder Structure

The backend shall use Laravel's standard structure and organize application-specific code by responsibility under `app`, including controllers, services, models, policies, requests, resources, middleware, jobs, events, and notifications.

### Layers

- **Presentation layer:** Routes, controllers, API resources, and middleware.
- **Application layer:** Services and use-case orchestration.
- **Domain and data layer:** Models, relationships, policies, and persistence rules.
- **Infrastructure layer:** Storage, notifications, queues, logging, and external integrations.

### Controllers

Controllers shall remain thin: accept requests, invoke services, and return API resources or standardized responses.

### Services

Services shall contain reusable business workflows and coordinate domain operations. Business logic shall not be duplicated across controllers.

### Models

Eloquent models shall represent persisted entities, relationships, casts, and narrowly scoped domain behavior.

### Policies

Policies shall enforce authorization for protected resources and actions.

### Requests

Form Requests shall define validation and request-level authorization for input-bearing endpoints.

### Resources

API Resources shall provide stable, consistent JSON transformations and prevent unintended data exposure.

### Middleware

Middleware shall handle cross-cutting concerns, including authentication, authorization context, rate limiting, request handling, and security headers.

## 5. Flutter Architecture

- Use a feature-first project structure.
- Select and document a state-management solution before implementation; no state-management package is mandated by this document.
- Use repositories to isolate data access from presentation and state logic.
- Use services for API communication, secure storage, notifications, and platform integrations.
- Centralize routing and guard protected customer routes according to the authenticated `users` principal and linked `customer_profiles` record. Admin routes use a separate `admins` guard and RBAC.

## 6. Database Architecture

### Schema Strategy

PostgreSQL is the authoritative data store. The schema shall be normalized where practical and designed around approved business entities and workflows.

### Relationships

Relationships shall be explicitly modeled with foreign keys and Laravel Eloquent relationships.

### Identity & Persistence Model

- `users` is the sole authentication identity for mobile customers. It owns credentials, verification state, provider linkage, account eligibility, and Sanctum token ownership.
- `customer_profiles` is a one-to-one business/profile extension of `users` (`customer_profiles.user_id` is unique). It owns the public Customer Reference (`CUS-YYYY-######`), display/profile fields, classification, and customer business context.
- There is no standalone `customers` authentication identity table.
- Authentication-adjacent records use `user_id`; approved customer business records, including payments, use `customer_profile_id`.
- `admins` and future staff identities are separate from `users`, with independent guards, authorization policies, and tokens.

### Migrations

All schema changes shall be introduced through version-controlled Laravel migrations. Existing schema changes require approval.

### Constraints

Use primary keys, foreign keys, unique constraints, check constraints where appropriate, and non-null requirements to protect data integrity.

### Indexes

Indexes shall support primary and foreign keys, frequent filters, sorting, joins, and approved performance requirements. Indexes shall be reviewed for query benefit and write cost.

## 7. API Architecture

### REST Standards

The API shall use RESTful resources, HTTP methods, consistent naming, JSON payloads, and appropriate HTTP status codes.

### Versioning Strategy

All public API routes shall be versioned under a path prefix, beginning with `/api/v1`.

### Response Format

Successful responses shall use a consistent JSON envelope containing the relevant `data` and, when needed, `meta` or `message` fields.

### Error Response Format

Error responses shall use a consistent JSON envelope containing an error message, a stable error identifier when applicable, and field-level validation details when applicable.

## 8. Authentication & Authorization

Laravel Sanctum shall provide token-based authentication with separate customer and admin realms.

### 8.1 Customer Mobile Authentication

- Principal: `users`, linked one-to-one to `customer_profiles`.
- Sanctum customer tokens are issued to `users` only.
- Customer APIs authenticate through `users.id`; approved business records are owner-scoped through the linked `customer_profiles` record.

### 8.2 Admin and Staff Authentication

- Principal: `admins`, separate from `users`.
- Five roles: **Super Admin**, **Manager**, **Sales**, **Inventory**, **Accountant**.
- **Hybrid RBAC:** effective permissions = role permissions ∪ direct admin permissions; Super Admin has all permissions implicitly.
- **Dual Dashboard Architecture:** Super Admin Dashboard vs Operations Dashboard; module visibility from effective permissions; Dashboard Statistics cached per admin.
- Inactive admins are rejected by admin middleware on protected routes (including existing tokens).
- Sensitive admin mutations dispatch `AuditEvent` for event-driven audit log persistence.
- Future staff identities remain separate from customer identities and must not share customer guards or token scopes.

### 8.3 Cross-Realm Rules

- A `users` record must never be promoted to an admin role through the mobile API.
- An `admins` record must never authenticate as a customer on mobile endpoints.
- Policies, middleware, and service-level rules enforce permissions. Clients are never the authority for authorization decisions.

## 9. File Storage Architecture

Files shall be stored through Laravel's filesystem abstraction using an approved storage provider. Database records shall store file metadata and references rather than unmanaged file-system paths. Access to private files shall require authorization.

## 10. Payment Domain Architecture

### 10.1 Module Boundary

Payment V1 is one unified, gateway-independent module for Service Orders and Store Orders. A payment uses a polymorphic payable reference (`payable_type`, `payable_id`) to identify the originating payable domain record. The originating domain retains its commercial and workflow rules; Payment owns only the payment lifecycle, payment records, gateway integration, transactions, and receipt records.

Payments follow ADR-001 ownership:

```text
users
  ↓
customer_profiles
  ↓
payments
```

`users` remains the authentication principal. `payments.customer_profile_id` records the business owner; no payment authenticates through a customer profile.

### 10.2 Payment Lifecycle and Order Integration

The approved Payment V1 lifecycle is: Pending → Initialized → Processing → Paid, Failed, or Cancelled. Refunds are outside V1.

Orders begin in `pending_payment`. When a payment becomes Paid, the originating Order becomes `confirmed`. For prepaid Store Orders, Payment = Paid triggers the stock decrease and writes a Stock Ledger customer-sale entry; Cash on Delivery Store Orders (Sprint 26) confirm and deduct stock at order creation, with the COD payment remaining `pending` until admin confirmation. Failed or Cancelled prepaid payments do not change stock and do not automatically cancel an Order. Payment status transitions must be transactional and leave the originating domain responsible for its own allowed transitions.

**Sprint 27 — Admin verification and rejection:**

- Admin **confirmation** (`pending`/`initialized`/`processing` → `paid`) and **rejection** (→ `failed`, required reason) are exposed through the Admin Operations APIs, each executed in one database transaction with row-level locking and idempotent confirm behavior.
- **COD rejection cascade (official V1 rule):** rejecting a COD payment atomically sets the payment `failed`, cancels the store order, and restocks inventory via positive `sale_reversal` Stock Ledger entries (§10B.4).
- **Booking cancellation:** paid payments are never reversed (refunds are V2); payments still `initialized`/`pending`/`processing` for a cancelled booking's order(s) are automatically set to `failed` inside the same cancellation transaction.

### 10.3 Payment Persistence and Receipts

- `payments` stores the customer-profile-owned payable record and current payment lifecycle state.
- `payment_transactions` stores one or more gateway transaction attempts or updates for one Payment.
- Every successful payment produces exactly one receipt with public number `RCPT-YYYY-######`.
- Receipt PDF generation is explicitly outside the current scope.

### 10.4 Gateway Abstraction

The Payment domain shall define a provider-neutral gateway abstraction so future gateway providers can be added without coupling Payment models or originating domains to a specific provider. **V1 ships with no online gateway integration** (Sprint 26 — final): all V1 methods (EVC Plus, eDahab, Bank Transfer, Cash on Delivery, Cash on Service) are confirmed through admin verification with full audit; gateway adapters are a future-version concern.

### 10.5 Events and Notifications

Payment does not send notifications directly. It publishes domain events such as `PaymentPaid` and `PaymentFailed`; the Notification Module consumes those via `NotificationRequested` according to approved notification rules.

## 10A. Store Domain Architecture

### 10A.1 Module Boundary

Store V1 is a physical-product commerce domain separate from Services and separate from Inventory purchasing. Store owns product catalog, categories, product images, cart, checkout, Store Orders, and Unified Payment integration. Store does not own suppliers, purchase orders, goods receipts, stock ledger maintenance, or stock adjustments.

V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, Personal Protective Equipment (PPE), Air Fresheners. Heavy cleaning equipment and machines are outside V1.

### 10A.2 Product Entity

Products remain a single business entity shared across Store and Inventory. Product stores SKU, Name, Description, Selling Price, Cost Price, Currency, Current Stock, Low Stock Threshold, and Status. Inventory movements are stored separately in Stock Ledger. Changing Selling Price never changes Cost Price. Inventory costing methods are outside V1.

### 10A.3 Store Order and Stock Rules

Store Orders reuse the Unified Payment Module. Prepaid orders follow `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`; stock decreases only after Payment = Paid. Cash on Delivery orders (Sprint 26) confirm and deduct stock at creation, then follow `confirmed` → `preparing` → `out_for_delivery` → `delivered` → `payment_pending` → `completed` (completion only via admin payment confirmation). Admin fulfilment transitions are server-enforced through the Admin Operations status endpoint (Sprint 27); an order never reaches `completed` while an active payment exists. If an admin rejects a COD payment, the order is cancelled and stock is restored via `sale_reversal` ledger entries in the same transaction. Negative stock and overselling are not allowed.

## 10B. Inventory Domain Architecture

### 10B.1 Module Boundary

Inventory is a separate business domain that manages Suppliers, Purchase Orders, Goods Receipts, Stock Ledger, Stock Adjustments, Stock Quantity, and Low Stock Alerts.

### 10B.2 Stock Flow

```text
Supplier → Purchase Order → Goods Receipt → Inventory Increase → Store Product
→ Customer Purchase → Payment Paid → Inventory Decrease → Stock Ledger Entry
```

Purchase Order alone never changes stock. Goods Receipt is allowed only after Purchase Order approval (`approved` or `partially_received`) and increases stock while creating Stock Ledger entries in `stock_ledgers`. Manual adjustments require quantity and reason (`Damaged`, `Lost`, `Correction`, `Physical Count`) and create Stock Ledger entries.

### 10B.3 Purchase Order Lifecycle

`Draft` → `Submitted` → `Approved` → `Partially Received` → `Completed` / `Cancelled`

Submitted Purchase Orders must not receive inventory. Approval is required before Goods Receipt.

### 10B.4 Stock Ledger

Every stock movement is recorded in `stock_ledgers` with quantity, movement type, polymorphic reference, and timestamp. Movement types include Purchase Receipt, Customer Sale, Sale Reversal, Adjustment, Correction, Damage, and Loss.

**Sale Reversal (Sprint 27):** `sale_reversal` is a dedicated movement type used **only** for automatic inventory restoration after an admin rejects a Cash on Delivery payment. It writes positive entries referencing the cancelled Store Order, mirroring the original customer-sale deduction line for line, inside the same transaction as the payment rejection and order cancellation. It is never created manually and is not available to Inventory Adjustments.

### 10B.5 Low Stock

Each product defines Current Stock and Low Stock Threshold. Dashboard displays Low Stock alerts. Email/SMS low-stock notifications are outside V1.

## 10C. Admin Operations Architecture (Sprint 27)

### 10C.1 Module Boundary

Admin Operations exposes the existing payment, booking, and store-order domain actions through secure Admin APIs: payment confirm/reject, booking schedule/start/complete/close/cancel, and store-order fulfilment transitions. Controllers stay thin; every mutation delegates to a single-responsibility domain action (e.g. offline payment confirmation, payment rejection, booking scheduling/closure, store-order status advancement) executed inside a database transaction.

### 10C.2 Booking Acceptance

Booking acceptance is **automatic**: when a customer accepts a quotation, the same transaction that accepts the quotation and applies the payment-policy snapshot also moves the linked booking `submitted` → `accepted`. There is no admin acceptance action or endpoint. Admin booking transitions begin at scheduling and are gated by the snapshotted payment policy (deposit/full paid before `scheduled`; all required payments paid before `closed`).

### 10C.3 Transactions, Concurrency, and Audit

- Every mutation runs in one database transaction with row-level locking (`lockForUpdate`) on the aggregate row; receipt-number generation uses the yearly-sequence advisory lock.
- Payment confirmation is idempotent under concurrent double-submission; terminal payment states are never resurrected.
- Every mutation writes the domain status history with the acting admin and dispatches an `AuditEvent` (actor, action, entity, prior → new status, reason/metadata, IP, user agent) persisted by the audit listener.
- Routes enforce Hybrid RBAC via permission middleware with the Sprint 27 keys: `payments.view`, `payments.confirm`, `bookings.view`, `bookings.manage`, `store_orders.view`, `store_orders.manage`. Mutation endpoints are rate-limited (60 requests/minute/admin).

### 10C.4 Mandatory V1 Notifications

Five customer notifications are mandatory in V1 — Payment Confirmed, Payment Rejected, Booking Scheduled, Booking Completed, Booking Cancelled. They are published as domain events consumed by the Notification Module (§11) and dispatched **after the business transaction commits**; notification failures never roll back or block business transactions.

## 10D. Quotation Request Workflow Architecture (Sprint 28)

### 10D.1 Module Boundary

The Quotation module separates the **customer request** (quotation head: requirements, attachments, lifecycle status, reviewer assignment, latest-revision pointer) from **admin-issued pricing** (immutable `quotation_revisions`). Customers never submit pricing — no pricing field exists on any customer-facing request, DTO, or Form Request. All pricing enters the system exclusively through admin issue/revise actions.

### 10D.2 Layering (existing patterns)

- **Repositories** — quotation and revision repositories behind contracts; the revision repository is append-only (create + read; no update/delete methods).
- **Service Layer / Domain Actions** — single-responsibility actions per workflow step: create draft, update draft, submit, customer cancel, attach/detach draft attachments, assign reviewer, issue revision (Version 1 and n+1, including expired revival), admin discussion reply, close discussion, expire, admin cancel, accept (customer and admin-on-behalf). Controllers stay thin.
- **readonly DTOs** — request payloads and admin list filters (status, assignee, customer, origin, dates) travel as readonly DTOs.
- **Form Requests / API Resources** — validation at the HTTP boundary; resources expose the head + latest revision + revision history + timeline; storage paths never leave the backend.
- **Server-calculated business flags** — every quotation resource includes `can_accept` and `can_discuss`, computed server-side from status, latest revision, and validity. These flags are **authoritative**: Flutter, Web, and any future client must never recreate the business logic and only display the values returned by the API; the server independently re-validates every action.

### 10D.3 Revision System

- Revisions are immutable rows versioned per quotation (`version_number` starting at 1, **strictly increasing**, enforced by `UNIQUE (quotation_id, version_number)`); version numbers are **never reused and never reset** — even future archive/delete operations must not free them. Revisions are created only by admin actions.
- The head's `latest_revision_id` must always reference the revision with the **highest `version_number`**; the pointer is advanced in the same transaction that inserts the revision, so the database constraints and the application layer jointly prevent out-of-sync revisions.
- `version_number` is assigned **inside the row-locked transaction** on the quotation head, preventing duplicate versions under concurrent revising.
- Acceptance validates the referenced revision is the latest inside the same lock; stale references return `409 Conflict`. Acceptance is allowed from `quotation_ready` or `under_discussion`.
- Issuing a revision on an `expired` quotation automatically revives it to `quotation_ready`; every revision requires `valid_until`.
- The permanent quotation number is generated once at draft creation (existing yearly-sequence advisory lock); revisions never receive numbers.
- **Legacy migration:** a data migration creates Revision 1 (`source = system_migration`, null issuer) from each existing quotation's pricing columns and maps `pending_review` to `submitted`; no history is lost.

### 10D.4 Attachments (Upload Service integration)

Request attachments reference staged uploads by FK/UUID via the Unified Upload Service; attaching sets `uploads.attached_at`. Ownership, media-type allow-list, and size caps are enforced at staging. Attach/detach is restricted to `draft`; on submit the set becomes immutable — post-submit files flow only through discussion-message attachments using the same upload references.

### 10D.5 Transactions, Concurrency, Audit, Events

- Every mutation runs in one database transaction with `lockForUpdate` on the quotation head and writes `quotation_status_histories` with the acting party.
- Audit Events are emitted for Assign Reviewer, Issue, Revision, Close Discussion, Expire, Cancel, and Admin Accept, carrying `quotation_number`, `version_number` (where applicable), `admin_id`, `previous_status`, `new_status`, `timestamp`, and `reason` (where applicable).
- Domain events (Quotation Submitted, Issued, Revised, Discussion Reply, Expired, Cancelled) are consumed by notification listeners and dispatched via `DB::afterCommit()`; listener failures are logged and never roll back business transactions.
- Routes enforce Hybrid RBAC with the Sprint 28 keys — `quotations.view`, `quotations.review`, `quotations.issue`, `quotations.manage` — plus customer ownership scoping; admin mutations share the admin-operations rate limiter.

## 11. Notification Architecture

Sprint 12 Notification Architecture (Option B):

1. **Event-driven dispatch:** Domain modules publish `NotificationRequested`. Listeners persist notifications; business modules never dispatch notification jobs directly.
2. **Templates:** Admin-managed `notification_templates` with optional `notification_template_translations` (`so`/`en`/`ar`) and `{{placeholder}}` rendering.
3. **Preferences:** Polymorphic `notification_preferences` gate channels per type (defaults: in_app/email on, sms off).
4. **Channel queues:** Dedicated queues `notifications-in-app`, `notifications-email`, `notifications-sms` selected from `Notification.channel`.
5. **Enterprise lifecycle:** `pending` → `processing` → `sent` → `delivered` → `read`, with `processing` → `failed`. V1 in-app auto-marks `delivered` after successful send; email/SMS await provider callbacks for `delivered`.
6. **Archive:** Terminal `read`/`failed` rows move atomically to `archived_notifications` (admin browse with `notifications.manage`).
7. **Idempotency:** Unique (`recipient_type`, `recipient_id`, `channel`, `event_id`).
8. **After-commit dispatch (Sprint 27, extended by Sprint 28):** transactional business events (payment confirmed/rejected, booking scheduled/completed/cancelled, quotation submitted/issued/revised/discussion reply/expired/cancelled) publish their notification events only **after the database transaction commits** (`DB::afterCommit()`). A notification failure is recorded through the notification lifecycle (`failed`) and never rolls back or blocks the business transaction.

## 12. Logging & Error Handling

The backend shall use structured application logging with appropriate severity levels and correlation context where available. Exceptions shall be handled centrally, reported safely, and translated into consistent API errors. Sensitive data shall not be logged.

## 13. Security Architecture

- Enforce HTTPS in production.
- Validate and authorize every protected request.
- Use Laravel Sanctum for authenticated API access.
- Apply least-privilege access through roles and policies.
- Enforce strict separation between customer (`users`) and admin/staff authentication guards, token issuers, and authorization policies.
- Protect secrets through environment configuration.
- Prevent sensitive data exposure through API Resources and secure storage.
- Apply rate limiting and security headers where appropriate.
- Keep dependencies updated according to approved maintenance practices.

## 14. Performance Strategy

Performance shall be supported by efficient database queries, appropriate indexes, pagination, eager loading where needed, caching of approved read-heavy data, background jobs for long-running work, and measured optimization based on observed bottlenecks.

## 15. Scalability Strategy

The application shall remain stateless at the API layer where practical, support horizontal scaling, move long-running work to queues, use managed storage for files, and isolate integrations behind services. Database growth and cache strategies shall be reviewed as usage grows.

## 16. Backup & Recovery Strategy

Production databases and critical files shall have scheduled backups, retention policies, access controls, and documented recovery procedures. Backup restoration shall be tested periodically in a safe environment.

## 17. Development Environment

Development shall use version-controlled configuration templates, local environment variables, isolated databases, migrations, seed data only when approved, automated tests, code formatting, static analysis where adopted, and Git-based collaboration.

## 18. Production Environment

Production shall use secure environment configuration, HTTPS, managed PostgreSQL backups, queue workers where required, centralized logging and monitoring, controlled deployments, rollback capability, and restricted operational access.

## 19. Architecture Principles

- Follow the approved architecture.
- Keep modules cohesive and loosely coupled.
- Separate presentation, application, domain, and infrastructure concerns.
- Prefer reusable, testable, and maintainable components.
- Protect data integrity, security, and backward compatibility.
- Make changes traceable to approved documentation.

## 20. Future Expansion Strategy

Future features, integrations, roles, channels, and services shall be added through approved scope and architecture review. New capabilities shall preserve modular boundaries, API versioning commitments, data integrity, security controls, and documented traceability.
