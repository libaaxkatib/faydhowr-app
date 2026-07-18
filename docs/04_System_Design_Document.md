# Fayadhowr System Design Document

## 1. System Overview

Fayadhowr is a Flutter-based client application supported by a Laravel REST API and PostgreSQL database. The system coordinates customer interactions, service bookings, quotations, discussions, Store purchases, orders, payments, notifications, reports, and administrative settings. Laravel is the authoritative application layer for validation, business rules, authorization, workflow transitions, and data persistence.

### 1.1 Identity Architecture

```text
users
  ↓
customer_profiles
  ↓
approved business modules
```

`users` is the sole customer authentication identity and owns credentials, tokens, and authentication state. `customer_profiles` stores customer business/profile data and is not an authentication identity. Approved business modules resolve the authenticated User's linked profile and use `customer_profile_id` for business ownership; authentication is never performed through `customer_profiles`.

### 1.2 Official Service Architecture

The V1 service catalog consists of Deep Cleaning, Pest Control, Carpet Cleaning, Sofa & Chair Cleaning, Post Construction Cleaning, Window Cleaning, Fumigation Services, Housekeeper, and Monthly Cleaning Staff. Every active service supports both Book Now and Request Quotation, may show an optional Starting From price, and has a final operational price determined after assessment/review.

| Service | Supported modes | Supported subtype choices |
| --- | --- | --- |
| Deep Cleaning | One-Time, Monthly Contract | — |
| Pest Control | One-Time, Monthly Contract | — |
| Carpet Cleaning | One-Time | — |
| Sofa & Chair Cleaning | One-Time | — |
| Post Construction Cleaning | One-Time | — |
| Window Cleaning | One-Time, Monthly Contract | — |
| Fumigation Services | One-Time, Monthly Contract | — |
| Housekeeper | Monthly Contract | Full-Time, Part-Time, Live-In, Live-Out |
| Monthly Cleaning Staff | Monthly Contract | Office, Hotel, Restaurant, School, Hospital / Clinic, Other Business |

Services are available in Mogadishu and Hargeisa in V1. Service modes, subtype choices, and coverage are configuration data, allowing additional services and cities without a core-system redesign.

## 2. End-to-End Business Flow

```text
Customer
  ↓
Authentication (`users`)
  ↓
Customer Profile (`customer_profiles`)
  ↓
Service Booking (Book Now / Request Quotation)
  ↓
Quotation / Discussion
  ↓
Order (when Store checkout or quotation-derived)
  ↓
Payments
  ↓
Notifications
  ↓
Admin
  ↓
Reports / Settings

Store (separate flow): Products → Categories → Cart → Checkout → Store Orders → Payments
Inventory (admin): Suppliers → Purchase Orders → Goods Receipts → Stock ↑ → Products
On Payment Paid (Store): Inventory Decrease → Stock Ledger Entry
```

Each transition is governed by approved business rules, authorization, validation, and recorded system state. The exact availability of each path depends on the approved workflow and user role.

## 3. Module Interaction Diagram

```text
Authentication (`users`) ── Customer Profiles (`customer_profiles`) ── Services ── Bookings ── Quotations ── Payments
                                         │                              │              │             │
                                         │                              │              └─ Discussions ┘
                                         │                              │
                                         │                              └───────── Notifications
                                         │
                                         └── Store (separate): Products → Categories → Cart → Checkout → Orders → Payments
                                                                                         │
                                                                                         └─ (Payment Paid) → Inventory stock decrease + Stock Ledger
Inventory (admin): Suppliers → Purchase Orders → Goods Receipts → Stock ↑ + Stock Ledger → Products.current_stock
Reports ◀──────────────────── Customer Profiles / Bookings / Quotations / Orders / Payments / Inventory ──┘
Settings ────────────────────────────────────────────────────────────────────────────────────── Admin

Admin Authentication (`admins`) ── Admin Panel / Settings / Reports / Inventory
```

- **Authentication (`users`)** validates customer credentials and issues mobile tokens; protected modules resolve the linked customer profile for business context.
- **Customer Profiles (`customer_profiles`)** provides the one-to-one customer business context used by bookings, quotations, orders, payments, reviews, reports, and notification preferences. Notifications are addressed polymorphically to `Admin` or `CustomerProfile` (customer auth resolves the linked profile). Customer profiles are not authentication identities.
- **Admin Authentication (`admins`)** is separate from customer authentication and gates admin-panel modules, settings, inventory operations, and operational reports.
- **Services** provide the official catalog, supported modes/subtypes, coverage, optional Starting From information, and both Book Now / Request Quotation entry points.
- **Bookings** captures approved customer-profile-owned booking activity and may initiate quotation processing.
- **Quotations** records proposed commercial terms and supports the approved discussion and acceptance lifecycle.
- **Store** is a separate physical-product commerce flow for catalog, categories, images, cart, checkout, and Store Orders; it integrates Unified Payment and is not responsible for purchasing or stock management.
- **Inventory** is a separate domain for suppliers, purchase orders, goods receipts, stock ledger, adjustments, stock quantity, and low-stock alerts.
- **Orders** are created by Store checkout or approved quotation-derived workflows; Store Order creation never decreases stock.
- **Payments** record payment activity for Service Orders and Store Orders; when Payment becomes Paid for a Store Order, Inventory decreases stock and writes a Stock Ledger sale entry.
- **Notifications** communicate approved workflow events to authorized recipients.
- **Reports** read authorized, aggregated operational data without becoming a source of transactional truth.
- **Settings** governs approved system configuration and authorized administrative controls.

## 4. Data Flow

```text
Flutter Client
  ↓ HTTPS JSON request
REST API
  ↓ routing, authentication, validation, authorization
Laravel Application
  ↓ approved business workflow and persistence operations
PostgreSQL Database
  ↓ committed workflow event
Notifications
  ↓ authorized operational data
Reports
```

Flutter submits requests and renders API responses. The REST API routes requests to Laravel, where customer or admin authentication is selected by endpoint realm, then validation, authorization, and approved business workflows are applied. Customer requests authenticate `users` and resolve linked `customer_profiles`; admin requests authenticate `admins`. Laravel persists authoritative data to PostgreSQL. Approved workflow events may produce notifications. Reporting reads authorized transactional data and presents aggregated information without bypassing the application’s access controls.

## 5. Authentication Flow

1. A user submits approved credentials through Flutter.
2. Flutter sends the request to the authentication API endpoint over HTTPS.
3. Laravel validates `users` credentials and account eligibility, then loads or creates the linked `customer_profiles` record when required.
4. Laravel Sanctum issues or manages a token bound to the authenticated `users` principal according to the approved session policy.
5. Flutter securely stores and sends the token for protected requests.
6. Laravel authenticates each protected customer request as `users`, applies owner-scoped authorization, and loads customer business context from `customer_profiles`.

## 6. Booking Flow

1. An authenticated customer selects an active service, a supported mode/subtype, and a covered city.
2. Flutter collects property information, address/GPS location, `requested_date`, `requested_time_window`, optional images/videos, and notes.
3. Laravel validates the request and resolves the authenticated `users` record to its linked `customer_profiles` record.
4. The booking is stored with `customer_profile_id` ownership and a system-generated public `booking_number` in format `BK-YYYY-######`; numeric `id` remains the primary key.
5. `scheduled_start_at` and `scheduled_end_at` remain unset until operations confirms the schedule.
6. The customer chooses Book Now or Request Quotation. For the quotation path, the booking becomes the permanent service-quotation origin.
7. Authorized staff review/process the booking; workflow changes may produce notifications and become available to reporting.

## 7. Quotation Flow (Sprint 28)

1. An authorized customer creates a quotation request as a **Draft** from an approved booking or product request. The draft receives its permanent Quotation Number (`QT-YYYY-######`) and contains **no pricing fields** — pricing is admin-only.
2. The customer stages files through the Unified Upload Service (images, videos, PDFs) and attaches them to the draft; attach/detach is allowed only while in Draft.
3. On **Submit**, Laravel validates required details and locks the request and its attachments permanently (`draft` → `submitted`). The quotation is saved with `customer_profile_id` business ownership and its permanent booking/product source context.
4. An admin reviewer is assigned (single reviewer via `assigned_admin_id`; `submitted` → `under_review`), reviews the request and attachments, and **issues Version 1** — an immutable revision with line items, totals, and mandatory validity (`under_review` → `quotation_ready`).
5. Authorized participants conduct discussions through the approved quotation workflow (`quotation_ready` ↔ `under_discussion`); admins may revise the quotation, creating immutable Version 2, 3… on the same Quotation Number, and may revive an `expired` quotation with a new revision.
6. Acceptance is recorded only by an authorized action and approved business rule: allowed from `quotation_ready` or `under_discussion`, valid only for the **latest revision** (stale references return `409 Conflict`), and executed in a row-locked transaction that snapshots the payment policy and auto-accepts the linked booking.
7. An accepted quotation becomes eligible to create an order according to the approved process.

## 7A. Home & Global Search Flow (Sprint 29)

1. A guest (or authenticated) client requests `GET /api/v1/home` or any Home section endpoint; no authentication is required and the shared `public-catalog` rate limiter (60 requests/minute/IP) applies.
2. Laravel serves the response from a server-side cache (**TTL 5 minutes**). On a cache miss, the Home aggregation service composes the sections: active in-schedule hero banners, service categories with at least one active service, featured services (`is_featured`, manual curation, `sort_order`), popular services (ranked internally on `favorites_count`, which is never exposed), store products with `selling_price` (out-of-stock products visible with the Out of Stock state), Before & After gallery, `published` reviews preview, active FAQs, and whitelisted public contact fields.
3. The aggregate response includes informational metadata (`generated_at`, `cache_expires_at`, `version`); clients render sections without deriving business logic from metadata or recalculating server flags.
4. The **`HomeCacheInvalidator`** flushes Home caches whenever hero banners, featured services, featured products, FAQ, gallery items, review moderation, service status, or product status change (admin mutations and moderation actions call it inside their workflow).
5. For search, the client calls unified search, service search, product search, or suggestions (minimum query length 2, `public-catalog` throttle). PostgreSQL `pg_trgm` indexes match case-insensitively (SQLite tests use a `LIKE` fallback), and results are ranked server-side: exact name → prefix → word → description match, with `sort_order`, featured, then alphabetical tie-breakers.
6. Suggestions return at most 10 items (`type`, `name`, `slug`/product identifier, `thumbnail`) with no prices, discounts, or stock. Recent searches remain device-local — the backend stores no search history.
7. Admins manage hero banners, gallery items, FAQs, and featured curation through `content.view` / `content.manage` guarded APIs; every mutation is transactional, emits an `AuditEvent`, and invalidates Home caches.

## 8. Order Flow

1. For Store commerce, Laravel validates checkout preview eligibility, then the client creates a Store Order via `POST /api/v1/store-orders` (checkout itself does not create the order).
2. The system creates the Store Order in `store_orders` with `customer_profile_id` ownership, `STO-YYYY-######`, and cart line snapshots.
3. For Store Orders, Selling Price and available stock are re-validated; overselling is rejected. Creating the Store Order never decreases stock.
4. Authorized users process Store Order status changes through approved transitions (`pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`).
5. Store Order events may initiate Unified Payment handling, notifications, and reporting updates.
6. Service Orders remain on `orders` (`ORD-YYYY-######`) and stay commercially separate from Store Orders.

## 8A. Inventory Flow

1. Admin creates or maintains a Supplier (`status`: `active` / `inactive`).
2. Admin creates a Purchase Order (`Draft` → `Submitted` → `Approved`). Purchase Order alone never changes stock. Submitted POs must not receive inventory.
3. After approval, Admin records a Goods Receipt against the Purchase Order (`approved` or `partially_received` only). Stock increases and Stock Ledger `purchase_receipt` entries are written to `stock_ledgers`. PO may become `Partially Received` or `Completed`.
4. Store Products reflect updated `current_stock` for catalog and checkout availability.
5. After a customer Store Order Payment becomes `paid`, Inventory decreases stock and writes a Stock Ledger `customer_sale` entry. Failed/cancelled payments leave stock unchanged. Paid payments also receive a one-time `RCPT-YYYY-######` on `payments.receipt_number`.
6. Manual Stock Adjustments require quantity and reason (`Damaged`, `Lost`, `Correction`, `Physical Count`) and always write Stock Ledger entries (future).
7. Dashboard Low Stock alerts use each product’s Current Stock and Low Stock Threshold. Email/SMS low-stock notifications are outside V1.

## 9. Payment Flow

1. An authorized customer-profile-owned payment references an originating payable record through `payable_type` and `payable_id` (Service Orders and Store Orders).
2. Laravel validates customer-profile ownership, amount, and payable state through the originating domain.
3. Laravel returns the existing active Payment when the payable has one in `pending`, `initialized`, or `processing`; it creates a new Payment only after the preceding Payment is `paid`, `failed`, or `cancelled`. This rule is enforced at the polymorphic Payment domain boundary, independent of gateway behavior.
4. Payment records the lifecycle (Pending, Initialized, Processing, Paid, Failed, Cancelled) and one-or-more provider-neutral `payment_transactions`.
5. Callback/webhook processing verifies gateway signature/authentication, gateway transaction reference, Payment resolution, active-Payment status, and duplicate-callback status before any state mutation. Payment update, transaction update, status history insert, receipt number assignment (`RCPT-YYYY-######`, once), and Order/Store Order confirmation (plus Store stock/ledger when applicable) execute atomically in one database transaction.
6. A successful callback changes Payment from `processing` to `paid` and then confirms the originating Service Order or Store Order; Failed or Cancelled payments do not cancel Orders, and failed callbacks leave the Order unchanged. Duplicate paid webhooks never regenerate `receipt_number`.
7. For Store Orders, Payment = `paid` is also the sole trigger that decreases product stock and writes the customer-sale Stock Ledger entry in `stock_ledgers`.
8. Every Paid payment creates one `RCPT-YYYY-######` receipt on `payments.receipt_number`. Payment publishes `PaymentPaid` and `PaymentFailed` events for future notification consumption; it does not notify directly. Refunds and receipt PDF generation are outside V1.

## 10. Notification Flow

1. A supported workflow publishes `NotificationRequested` for an `Admin` or `CustomerProfile` recipient with `template_key` + variables.
2. Laravel renders the active template (translated when available), applies preference channel gates, and persists a pending notification with an `event_id`.
3. `DispatchNotificationJobAction` enqueues `ProcessNotificationJob` on the channel-specific queue.
4. Processing advances `pending` → `processing` → `sent` or `failed`. For V1 in-app, `sent` → `delivered` immediately after success.
5. Recipients retrieve owner-scoped notifications via API; mark delivered notifications as read; unread count excludes `read`.
6. Admins with `notifications.manage` manage templates/translations and browse archived terminal notifications.
7. Delivery outcomes and failures are recorded without exposing sensitive information.

## 11. Reporting Flow

1. An authorized user requests a report.
2. Laravel verifies the `admins` role, reporting scope, and requested filters. Customer-scoped reports aggregate through `users` and `customer_profiles`.
3. The reporting layer reads the required approved transactional data.
4. Laravel returns aggregated, authorized report data through the REST API.
5. Flutter displays the returned information without modifying source records.

## 12. Admin Workflow

1. An administrator authenticates against the separate `admins` identity (Sanctum admin realm). Inactive admins are rejected on login and on authenticated admin requests.
2. Dual Dashboard Architecture returns Super Admin or Operations dashboard; Hybrid RBAC (role ∪ direct permissions) gates modules and `permission:` protected routes.
3. The administrator manages approved settings, operational records, inventory, and reports within effective permissions.
4. Admin CRUD, role permission updates, and direct permission updates dispatch `AuditEvent` and invalidate per-admin dashboard statistics cache.
5. Administrative access does not permit unapproved changes to business rules, schema, or UI/UX.

## 13. Error Flow

1. Flutter submits a request to the REST API.
2. Laravel validates authentication, authorization, input, and workflow state.
3. If processing fails, Laravel returns a consistent JSON error response with an appropriate HTTP status code.
4. Flutter displays an approved, user-safe error state.
5. Laravel logs actionable technical details without logging sensitive data.
6. Unexpected errors are handled centrally and do not expose internal implementation details.

## 14. File Upload Flow

1. An authorized user selects a permitted file through Flutter.
2. Flutter sends the file and required metadata to the REST API over HTTPS.
3. Laravel validates authorization, file type, size, and other approved restrictions. Booking Media V1 permits images and videos only; documents are excluded from booking media.
4. Laravel stores the file through the approved filesystem abstraction.
5. The database stores authorized file metadata and references.
6. File retrieval requires authorization and uses the approved access mechanism.

## 15. Security Flow

1. All client-to-server communication uses HTTPS.
2. Laravel Sanctum authenticates protected API requests.
3. Middleware, policies, and request validation enforce role and resource authorization.
4. Laravel validates all input before business processing.
5. API Resources limit responses to approved data.
6. Sensitive configuration and credentials remain in protected environment configuration.
7. Security-relevant failures are logged safely and monitored through approved operational processes.

## 16. Sequence Diagrams (Text-Based)

### Authentication

```text
Flutter → REST API: Submit credentials
REST API → Laravel: Route authentication request
Laravel → Database: Verify `users` credentials; load/create linked `customer_profiles`
Database → Laravel: User and customer-profile result
Laravel → REST API: Sanctum token bound to `users` and profile context
REST API → Flutter: Authenticated JSON response
```

### Quotation to Order

```text
Flutter → REST API: Accept quotation (references latest revision)
REST API → Laravel: Validate request and authorization
Laravel → Database: Verify quotation state and latest revision (stale → 409 Conflict)
Laravel → Database: Record acceptance and create eligible order
Laravel → Notifications: Dispatch approved event
Laravel → REST API: Return quotation and order state
REST API → Flutter: Updated JSON response
```

### Payment

```text
Flutter → REST API: Submit payment action
REST API → Laravel: Validate request and authorization
Laravel → Database: Record Payment and gateway transaction
Laravel → Database: On Paid, confirm originating Order and create receipt
Laravel → Domain Events: Publish payment lifecycle event
Laravel → REST API: Return payment and order state
REST API → Flutter: Updated JSON response
```

## 17. Design Principles

- Preserve approved business rules and workflow states.
- Maintain separation between Flutter presentation, REST API transport, Laravel application logic, and database persistence.
- Keep modules cohesive, reusable, and independently maintainable.
- Enforce validation and authorization at system boundaries.
- Use consistent API contracts and traceable state changes.
- Avoid duplicate logic and undocumented behavior.
- Preserve backward compatibility whenever possible.

## 18. Future Scalability and Extensibility

The system shall scale through modular Laravel services, feature-first Flutter organization, API versioning, database indexing, pagination, caching of approved read-heavy data, queued background work, managed file storage, and horizontally scalable API infrastructure.

| Future module or capability | Extension path without redesigning the core system |
| --- | --- |
| Workforce Management | Add workforce identities, availability, and assignment records; current Housekeeper and Monthly Cleaning Staff service choices describe demand only |
| Delivery | Add delivery/fulfillment records to Store orders while retaining existing cart, checkout, order, and payment boundaries |
| Equipment Sales | Heavy cleaning equipment/machines are outside V1; when approved later, extend Store categories/products and Inventory without redesigning core commerce or ledger boundaries |
| Additional Services | Add service catalog, mode/subtype, and coverage configuration without a second service architecture |
| New Cities | Add coverage configuration beyond Mogadishu and Hargeisa |

Future expansion must be approved, documented, secure, and compatible with the architecture defined in the Technical Architecture Document.
