# Database Design Specification

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `03_Database_Design` |
| **Product Name** | Fayadhowr |
| **Document Type** | Database Design Specification |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 13 July 2026 |
| **Basis Document** | `docs/02_SRS.md` (Approved SRS) |
| **Audience** | Solution architects, database architects, backend engineers, QA |
| **Planned Persistence** | Relational database (e.g., MySQL / PostgreSQL) behind a Laravel API |

---

## Document Rules

This specification defines the **logical and physical table design** for Fayadhowr.

It intentionally does **not** include:

- SQL DDL scripts
- Laravel migrations
- Eloquent models
- API contracts
- Flutter / UI code

### Scope Alignment with SRS

| In Scope (v1) | Out of Scope (v1) |
| --- | --- |
| User authentication identities and customer profiles | Employee / technician / driver workforce tables |
| Admin panel operator accounts (RBAC) | Multi-vendor seller portals |
| Services, store, bookings, quotations, orders, payments, notifications | Multi-tenant SaaS tenancy |
| Optional product quotations and service quotations | Marketplace / partner inventory |
| Discuss Quotation messaging + additional file uploads on the **same** quotation | Multi-option parallel quote packages / e-signature |
| Customer reviews of completed bookings (V1) | Product/order/service-target reviews (future version) |

**Business rules reflected in this design:**

- The mobile application is **customer-only**; employees are not modeled in this version.
- Store products **always display Selling Prices**; fixed-price purchase is the default path.
- Every product also stores **Cost Price** for inventory valuation and profit reporting (not customer-facing checkout).
- Customers may **optionally** request quotations for products (bulk, custom quantity, special requests).
- Every active service supports both **Book Now** and **Request Quotation**. Services may display an optional Starting From price; final operational price is determined after assessment/review.
- **Browse/catalog access** may occur without login; **authentication is required** to create a booking or place an order (and for related transactional actions such as quotation requests and payments, consistent with the SRS).

---

# 1. Database Overview

## 1.1 Architecture

Fayadhowr uses a **single relational database** as the system of record for:

- User authentication identity and one-to-one customer profile data
- Admin identity and authorization (admin panel only)
- Service and store catalogs
- Bookings, quotation requests/quotations, store carts/orders
- Payments and refunds
- In-app notifications and templates
- Customer reviews
- System settings and operational audit trails

The Flutter customer app and the Laravel admin/API layer share this database through the backend. Clients never access the database directly.

## 1.2 Design Philosophy

| Principle | Application |
| --- | --- |
| **SRS-first** | Tables and relationships map to approved SRS modules and workflows. |
| **Modular domains** | Logical module boundaries (users, services, store, booking, quotation, payment, order, notification, review, settings) keep growth manageable. |
| **Authoritative server state** | Prices, stock, statuses, and payment outcomes are stored and enforced server-side. |
| **Soft deactivation over hard delete** | Catalog entities can be deactivated while historical transactions remain intact. |
| **Commercial immutability** | Order/booking/quotation line snapshots preserve what the customer agreed to at confirmation time. |
| **Polymorphic commercial links where justified** | Payments and quotations may reference more than one payable/requestable entity type without inventing duplicate payment tables. |
| **Least privilege data** | `users` is the sole authentication principal; `customer_profiles` owns customer business/profile data and approved business-module ownership; admins operate through separate RBAC identities; no employee workforce schema in v1. |
| **PCI minimization** | No full card PAN/CVV storage; only provider references and internal payment status. |

## 1.3 Naming Conventions

| Convention | Rule |
| --- | --- |
| Table names | `snake_case`, plural (`users`, `customer_profiles`, `order_items`) |
| Primary keys | `id` (unsigned big integer, surrogate) |
| Foreign keys | `{referenced_table_singular}_id`; use `user_id` for authentication-adjacent records and `customer_profile_id` for approved customer business-module ownership |
| Timestamps | `created_at`, `updated_at`; soft delete via `deleted_at` where noted |
| Money | `DECIMAL(12,2)` (or equivalent) with explicit `currency` where amounts are stored |
| Status fields | Constrained string/enumeration values documented per table |
| Public references | Unified unique codes: `CUS-######` (customers, e.g. `CUS-000001`), `BK-YYYY-######`, `QT-YYYY-######`, `ORD-YYYY-######` (service orders), `STO-YYYY-######` (store orders), `PAY-YYYY-######`, `RCPT-YYYY-######`, `PO-YYYY-######`, `GR-YYYY-######`, `INV-YYYY-######`, `REF-YYYY-######` (stored in `*_number` fields in addition to internal `id`) |

## 1.4 Common Column Patterns

Most transactional tables include:

- Surrogate `id`
- Status field aligned to SRS state models
- `created_at` / `updated_at`
- Optional `deleted_at` for soft-deletable master data (not for immutable payment ledgers)

---

# 2. Database Modules

## 2.1 Module Map

| Module | Responsibility | Primary Tables |
| --- | --- | --- |
| **User Management** | Mobile user identity, customer profiles, addresses, saved payment methods, devices, auth recovery (reset tokens + phone OTPs); customer notes, attachments, activity logs (Customer Management); admin operators & Hybrid RBAC | `users`, `customer_profiles`, `customer_addresses`, `customer_payment_methods`, `customer_devices`, `customer_notes`, `customer_attachments`, `customer_activity_logs`, `password_reset_tokens`, `phone_otps`, `admins`, `permissions`, `admin_role_permissions`, `admin_permissions` |
| **Service Management** | Official services, modes, coverage, media, and schedule constraints | `service_categories`, `services`, `service_modes`, `service_coverage_cities`, `service_media`, `service_blackout_dates` |
| **Store Management** | Product catalog, categories, images, cart, store orders | `product_categories`, `products`, `product_images`, `product_price_tiers`, `carts`, `cart_items`, `store_orders`, `store_order_items`, `store_order_status_histories` |
| **Inventory Management** | Suppliers, purchase orders, goods receipts, stock ledger, adjustments, low-stock | `suppliers`, `purchase_orders`, `purchase_order_items`, `purchase_order_status_histories`, `goods_receipts`, `goods_receipt_items`, `stock_ledgers`, `stock_adjustments` |
| **Booking Management** | Customer-profile-owned service bookings and status history | `bookings`, `booking_status_histories` |
| **Quotation Management** | Quote requests, issued quote revisions, discussion messages, attachments, line items, timeline | `quotation_requests`, `quotation_request_attachments`, `quotations`, `quotation_items`, `quotation_messages`, `quotation_message_attachments`, `quotation_status_histories` |
| **File Upload Service** | Unified customer file upload staging (Sprint 23): reusable, UUID-identified, owner-scoped file records uploaded before attachment to business records | `uploads` |
| **Payment Management** | Unified payment lifecycle, gateway transactions, and receipts | `payments`, `payment_transactions`, `receipts` |
| **Order Management** | Store orders and line items | `orders`, `order_items`, `order_status_histories` |
| **Notification Management** | Templates, translations, preferences, lifecycle delivery, archive | `notification_templates`, `notification_template_translations`, `notification_preferences`, `notifications`, `archived_notifications` |
| **Review Management** | Customer reviews of completed bookings (V1) | `reviews` |
| **Favorites** | Customer-saved services (V1: services only; product favorites deferred) | `favorites` |
| **Settings** | System configuration and audit | `system_settings`, `branches`, `settings_audit_log`, `audit_logs` |

## 2.2 Module Notes

### User Management

- **Users** are the only mobile authentication identities. Each mobile customer has one linked `customer_profiles` record for business and profile data.
- **Admins** exist solely for the web admin panel (SRS §14). They are separate from `users` and are not field employees, technicians, or drivers.
- Guests may browse catalogs without a `users` row; an authenticated active user is required before booking or ordering.
- **Customer Management (SRS FR-092):** the "Customer" entity is the `users` + `customer_profiles` pair per ADR-001 — there is no standalone `customers` table. Customer Code format is `CUS-######` (e.g. `CUS-000001`), auto-generated, sequential, read-only. Customer business status uses only `ACTIVE` / `INACTIVE` / `BLOCKED` / `DELETED` on **`customer_profiles.status`** (`DELETED` via soft delete `deleted_at`); **`users.status`** remains identity / authentication lifecycle and must never be treated as interchangeable. Customers are never hard-deleted; only Super Admin may restore a deleted customer (to `ACTIVE` or `INACTIVE` on `customer_profiles.status`). Registration Date = `customer_profiles.created_at`; Last Login = `users.last_login_at`.

### Service Management

- Every active service supports both **Book Now** and **Request Quotation**. Service pricing may show an optional Starting From amount; final operational price follows assessment/review.
- Inactive services remain in history but reject new bookings/quote requests.

### Store Management

- Store is a separate physical-product commerce module; products are not services.
- V1 category seed scope: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, Personal Protective Equipment (PPE), Air Fresheners.
- Heavy cleaning equipment and machines are outside V1.
- Store owns catalog, categories, product images, cart, checkout, Store Orders, and Unified Payment integration.
- Store does **not** own suppliers, purchase orders, goods receipts, stock ledger, or stock adjustments.
- Every product has **Selling Price** (customer-facing) and **Cost Price** (inventory/accounting).
- Normal path: cart → Store Order (`pending_payment`) → Payment → on `paid`, confirm order and decrease stock.
- Optional path: product-related **quotation request** (does not replace fixed-price purchase).

### Inventory Management

- Inventory is a separate business domain from Store.
- Inventory manages suppliers, purchase orders, goods receipts, stock ledger, stock adjustments, stock quantity, and low-stock alerts.
- Purchase Order alone never changes stock; Goods Receipt increases stock and writes Stock Ledger entries.
- Store Order creation never decreases stock; stock decreases only after Payment = `paid`.
- Negative stock and overselling are not allowed.
- Low-stock alerts appear on the Admin dashboard; Email/SMS low-stock notifications are outside V1.

### Booking / Quotation / Order / Payment

- Aligns to SRS workflows §§9–11.
- Payments attach to a payable entity through polymorphic `payable_type` / `payable_id` (Service Orders and Store Orders in V1).

### Review Management

- Lightweight customer feedback for **completed bookings only** in V1 (product/order/service-target reviews deferred to a future version).
- Reviews begin `pending`; admin moderation publishes or hides them. Public surfaces show `published` reviews only.
- Does not introduce workforce rating of employees (employees are out of scope).

### Favorites

- Customer-saved **services only** in V1 (product favorites deferred to a future version).
- One favorite per customer per service; adds are idempotent.
- When a service becomes inactive or is deleted, related favorites are **automatically removed**; services maintain a cached `favorites_count`.
- Favorites never mutate booking, quotation, cart, checkout, or payment state; no admin management and no activity-log events in V1.

### Settings

- Key/value operational configuration in `system_settings` (JSON values; see "Settings Module — Database Design").
- Relational `branches` table for branch management (MGQ / HGA).
- Settings change history in `settings_audit_log`; operational audit log for sensitive admin actions in `audit_logs` (SRS NFR-024).

### ADR-001 Identity Mapping

ADR-001 is authoritative for every identity and ownership reference in this specification:

| Legacy reference | Approved replacement |
| --- | --- |
| `customers` authentication table | `users` is the sole customer authentication identity; no standalone `customers` table exists |
| Customer business/profile row | `customer_profiles` with unique `user_id` → `users.id` |
| Customer business-module ownership | `customer_profile_id` → `customer_profiles.id` where approved; Booking explicitly uses `customer_profile_id` |
| Customer profile-scoped child ownership | `customer_profile_id` → `customer_profiles.id` |
| Customer actor in polymorphic histories/messages | `user` with `users.id` for authentication actor audit; business records use `customer_profile_id` |
| Admin/staff ownership or preferences | `admin_id` → `admins.id`; never `users.id` unless explicitly customer-owned |

The historical `customers` references that remain in workflow prose describe the customer persona only. They must not be interpreted as a table, authentication principal, or foreign-key target.

---

# 3. Table Specifications

Legend for **Required**: `R` = required (NOT NULL), `O` = optional (NULL allowed).

---

## 3.1 User Management

### Superseded: `customers` standalone identity table

> **Superseded by ADR-001.** This historical combined identity/profile design must not be implemented. `users` is the only mobile authentication identity and `customer_profiles` is the one-to-one business/profile extension.

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customers` |
| **Purpose** | Registered mobile customers (identity, profile, account status). |
| **Primary Key** | `id` |
| **Foreign Keys** | None |
| **Relationships** | 1:N → addresses, payment_methods, devices, carts, bookings, quotation_requests, orders, payments, notifications, reviews |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | Surrogate PK |
| `customer_number` | VARCHAR(40) | R | Unique public reference (historical format `CUS-YYYY-######`; superseded — the approved Customer Code format is `CUS-######`, see §3.1.2) |
| `full_name` | VARCHAR(150) | R | Display name |
| `email` | VARCHAR(191) | O | Unique when present |
| `phone` | VARCHAR(30) | R | Unique; primary login identifier candidate |
| `password_hash` | VARCHAR(255) | R | One-way hash only |
| `avatar_url` | VARCHAR(500) | O | Profile photo |
| `preferred_language` | VARCHAR(10) | R | `so` · `en` · `ar` (app-wide UI language) |
| `google_subject` | VARCHAR(191) | O | Unique Google account subject when linked via Google Sign-In |
| `email_verified_at` | TIMESTAMP | O | Verification marker |
| `phone_verified_at` | TIMESTAMP | O | Verification marker |
| `status` | VARCHAR(30) | R | `pending_verification`, `active`, `suspended`, `deactivated` |
| `notification_preferences` | JSON | O | Push/email + category toggles |
| `last_login_at` | TIMESTAMP | O | Audit/support |
| `created_at` | TIMESTAMP | R | Member since |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | Soft delete / account closure |

#### Constraints

- Unique: `customer_number`, `phone`
- Unique: `email` (nullable unique)
- Unique: `google_subject` (nullable unique)
- Check/enum: `status` in defined set; `preferred_language` in `so|en|ar`

#### Validation Rules

- Phone format validated at application layer for target market.
- Password stored only as strong one-way hash (never plaintext).
- Suspended/deactivated customers cannot create bookings, orders, or quotation requests.

#### Notes

- Guest browsing does not require a row here.
- Login is enforced at booking/order (and related transactional) boundaries.

### 3.1.1 `users`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `users` |
| **Purpose** | Sole mobile-customer authentication identity. |
| **Primary Key** | `id` |
| **Relationships** | 1:1 → `customer_profiles`; 1:N → carts, customer devices, authentication recovery, Sanctum tokens; customer business records are reached through `customer_profiles` |

#### Identity Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | Surrogate PK |
| `email` | VARCHAR(191) | O | Unique when present; email login/recovery |
| `phone` | VARCHAR(30) | R | Unique; primary login identifier candidate |
| `password` | VARCHAR(255) | R | One-way hash only |
| `google_subject` | VARCHAR(191) | O | Unique provider subject when linked |
| `email_verified_at`, `phone_verified_at` | TIMESTAMP | O | Verification markers |
| `status` | VARCHAR(30) | R | `pending_verification`, `active`, `suspended`, `deactivated` |
| `last_login_at` | TIMESTAMP | O | Audit/support |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | R/O | Account lifecycle and soft deletion |

#### Constraints and Rules

- Unique: `phone`, nullable `email`, nullable `google_subject`.
- Check/enum: `status` in the defined set.
- `users.status` governs identity / authentication lifecycle only (e.g. suspended or deactivated identities cannot authenticate). It is **not** used for customer business operations.
- Guest browsing does not require a `users` row; authentication is enforced at transactional boundaries.

##### Status Field Responsibilities

| Field | Responsibility |
| --- | --- |
| `users.status` | Identity / Authentication lifecycle. Controls account authentication state and identity-related lifecycle. Not used for customer business operations. |
| `customer_profiles.status` | Customer business status. Controls operational permissions such as booking services, requesting quotations, and placing store orders. |

- Business operations MUST use **`customer_profiles.status`**.
- Authentication lifecycle MUST use **`users.status`**.
- These two fields serve different purposes and must never be treated as interchangeable.

### 3.1.2 `customer_profiles`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_profiles` |
| **Purpose** | One-to-one customer business/profile data; not an authentication identity. Primary record of the Customer Management module (SRS FR-092). |
| **Primary Key** | `id` |
| **Foreign Keys** | `user_id` → `users.id` (UNIQUE, required) |
| **Relationships** | 1:1 → `users`; 1:N → addresses, saved payment methods, internal customer notes, customer attachments, customer activity logs, bookings, quotation requests, orders, payments, notifications, reviews |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | Surrogate PK |
| `user_id` | BIGINT UNSIGNED | R | Unique one-to-one link to `users` |
| `customer_number` | VARCHAR(40) | R | Unique public Customer Code `CUS-######` (e.g. `CUS-000001`); auto-generated, sequential, read-only |
| `full_name` | VARCHAR(150) | R | Display name |
| `avatar_url` | VARCHAR(500) | O | Profile photo |
| `gender` | VARCHAR(20) | O | `male` · `female` (optional profile data) |
| `date_of_birth` | DATE | O | Optional profile data |
| `preferred_language` | VARCHAR(10) | R | `so` · `en` · `ar` |
| `status` | VARCHAR(20) | R | `ACTIVE` · `INACTIVE` · `BLOCKED` (see status rules; `DELETED` is represented by `deleted_at`) |
| `tags` | JSON | O | Free-form staff labels for segmentation (Admin Panel only) |
| `notification_preferences` | JSON | O | Push/email and category toggles |
| `classification` | VARCHAR(30) | R | `lead` or `active_customer`; not an authentication role |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | R/O | Registration date, profile lifecycle, and soft deletion |

#### Constraints and Rules

- Unique: `user_id`, `customer_number`.
- Check/enum: `preferred_language` in `so|en|ar`; `classification` in `lead|active_customer`; `status` in `ACTIVE|INACTIVE|BLOCKED`.
- Indexes: `status`, `created_at` (registration-date filtering).
- `customer_number` is generated by the system (`CUS-######`) and is not customer-editable.
- `customer_profiles` cannot authenticate, own Sanctum tokens, or replace `users`.

#### Customer Status Rules (SRS FR-092.5)

- `ACTIVE`: full access.
- `INACTIVE`: temporarily inactive; cannot use customer services until reactivated.
- `BLOCKED`: cannot login, book services, request quotations, or place store orders.
- `DELETED`: soft-deleted (`deleted_at` set); hidden from normal customer lists; all business history (bookings, quotations, orders, payments, reviews, audit history) remains available and linked.
- No additional statuses may be introduced.
- Restore (Super Admin only) clears `deleted_at` and sets `customer_profiles.status` to `ACTIVE` or `INACTIVE`.
- Business operations (booking / quotation / store order) MUST enforce **`customer_profiles.status`**. Authentication lifecycle remains on **`users.status`** (see Status Field Responsibilities under §3.1.1). These fields must never be treated as interchangeable.

---

### 3.1.3 `customer_addresses`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_addresses` |
| **Purpose** | Saved delivery/service locations for a customer. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id` |
| **Relationships** | N:1 → `customer_profiles` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `label` | VARCHAR(50) | O | e.g., Home, Office |
| `contact_name` | VARCHAR(150) | O | |
| `phone` | VARCHAR(30) | O | |
| `line1` | VARCHAR(255) | R | |
| `line2` | VARCHAR(255) | O | |
| `city` | VARCHAR(100) | R | |
| `state_region` | VARCHAR(100) | O | State |
| `district` | VARCHAR(100) | O | District (e.g., Hodan) |
| `postal_code` | VARCHAR(30) | O | |
| `country_code` | CHAR(2) | O | ISO-ish code (Country) |
| `latitude` | DECIMAL(10,7) | O | GPS latitude; valid range −90..90 |
| `longitude` | DECIMAL(10,7) | O | GPS longitude; valid range −180..180 |
| `is_default` | BOOLEAN | R | Default false |
| `is_active` | BOOLEAN | R | Default true; unused addresses marked **Inactive** (never hard-deleted by customer) |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- FK cascade behavior: restrict preferred; **do not hard-delete** customer addresses from the customer app.
- At most one `is_default = true` among **active** addresses per customer profile.

#### Validation Rules

- Address required fields must be present when used for delivery/service booking.
- GPS coordinates, when provided, must be valid (latitude −90..90, longitude −180..180).
- Customers may Add / Edit / Set Default / Mark Inactive — never permanently delete.
- Updating an address must not rewrite historical order/booking address snapshots.
- Country / State / District support the Admin Panel customer filters (SRS FR-092.11).

#### Notes

- Orders/bookings should copy address text into snapshot fields at confirmation time.

---

### 3.1.4 `customer_payment_methods`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_payment_methods` |
| **Purpose** | Saved checkout instruments for a customer (separate from immutable `payments` history). |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id` |
| **Relationships** | N:1 → `customer_profiles` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `type` | VARCHAR(40) | R | `evc_plus`, `edahab`, `jeeb`, `salaam_somali_bank`, `bank_transfer`, `card` |
| `display_label` | VARCHAR(100) | O | Customer-facing nickname |
| `masked_account` | VARCHAR(60) | R | Masked phone / account / last4 |
| `provider_token` | VARCHAR(191) | O | Tokenized card/wallet reference (never PAN/CVV) |
| `is_default` | BOOLEAN | R | Default false |
| `is_active` | BOOLEAN | R | Default true |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- At most one `is_default = true` among active methods per customer.
- Check/enum: `type` in defined set.

#### Validation Rules

- Customers may Add / Set Default. **Payment history** in `payments` is never customer-deleted.
- No full card PAN or CVV stored.

#### Notes

- Distinct from `payments` ledger rows (`PAY-…` references).

---

### 3.1.5 `customer_devices`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_devices` |
| **Purpose** | Push notification device tokens for customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `user_id` → `users.id` |
| **Relationships** | N:1 → `users` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `user_id` | BIGINT UNSIGNED | R | FK |
| `platform` | VARCHAR(20) | R | `ios`, `android` |
| `device_token` | VARCHAR(255) | R | Push token |
| `app_version` | VARCHAR(30) | O | |
| `is_active` | BOOLEAN | R | |
| `last_seen_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique: (`user_id`, `device_token`) or globally unique `device_token`

#### Validation Rules

- Inactive/invalid tokens disabled rather than failing business transactions.

#### Notes

- Supports SRS push notification channel.

---

### 3.1.6 `customer_notes` (Admin internal)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_notes` |
| **Purpose** | Internal staff notes on a customer. **Never** exposed on customer mobile APIs. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`, `admin_id` → `admins.id` |
| **Relationships** | N:1 → `customer_profiles`, N:1 → `admins` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `admin_id` | BIGINT UNSIGNED | R | Author (Admin / Sales / Accountant operator) |
| `body` | TEXT | R | Note content |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Visible only in Admin Panel customer profile context (permission `customers.notes`).
- Customers never see these notes.
- Does not rewrite commercial history or timeline events.

---

### 3.1.6A `customer_attachments` (Admin internal)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_attachments` |
| **Purpose** | Staff-uploaded files attached to a customer record (Images, PDF, Documents). **Never** exposed on customer mobile APIs. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`, `admin_id` → `admins.id` |
| **Relationships** | N:1 → `customer_profiles`, N:1 → `admins` (uploader) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `admin_id` | BIGINT UNSIGNED | R | Uploaded By (staff) |
| `file_name` | VARCHAR(255) | R | Original file name |
| `file_type` | VARCHAR(100) | R | MIME type / extension category (image, pdf, document) |
| `file_size` | BIGINT UNSIGNED | R | Bytes |
| `file_path` | VARCHAR(500) | R | Storage path (not publicly accessible) |
| `created_at` | TIMESTAMP | R | Uploaded At |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Index: `customer_profile_id`.
- FK behavior: restrict on `customer_profile_id` (attachments follow the customer record; the customer record is never hard-deleted).

#### Validation Rules

- Allowed types: Images, PDF, Documents; size limited per Storage Settings (FR-091.10).
- Visible only in Admin Panel customer context (permission `customers.attachments`).
- Files are stored outside the public web root and served through authorized endpoints only.

---

### 3.1.6B `customer_activity_logs`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_activity_logs` |
| **Purpose** | Chronological, read-only activity timeline per customer (SRS FR-092.7). |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id` |
| **Relationships** | N:1 → `customer_profiles`; optional polymorphic link to the subject record (booking, quotation, order, payment, review, address) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `event_type` | VARCHAR(50) | R | See approved event set below |
| `description` | VARCHAR(255) | O | Human-readable summary |
| `subject_type` | VARCHAR(150) | O | Polymorphic subject class (e.g., booking, quotation) |
| `subject_id` | BIGINT UNSIGNED | O | Polymorphic subject id |
| `metadata` | JSON | O | Additional event context |
| `created_at` | TIMESTAMP | R | Event time (timeline ordering) |

#### Approved Event Types

`registration`, `login`, `profile_update`, `password_reset`, `address_added`, `address_updated`, `booking_created`, `booking_updated`, `booking_completed`, `quotation_requested`, `quotation_accepted`, `store_order_created`, `payment_recorded`, `review_submitted`.

#### Constraints

- Indexes: `customer_profile_id`, (`customer_profile_id`, `created_at`) for chronological reads, `event_type`.
- Check/enum: `event_type` in the approved set; no other event types may be introduced.

#### Validation Rules

- Rows are append-only audit history: never updated or deleted through the application.
- Timeline is presented in chronological order.
- Retained permanently, including for soft-deleted customers.

---

### 3.1.7 `password_reset_tokens`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `password_reset_tokens` |
| **Purpose** | Credential recovery tokens for `users` (and separately for `admins` when approved). |
| **Primary Key** | Composite or `id` |
| **Foreign Keys** | Logical link via email/phone (implementation choice) |
| **Relationships** | Associated to `users` or `admins` by subject identity |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK (recommended) |
| `subject_type` | VARCHAR(30) | R | `user` (v1); `admin` only for a separate approved admin recovery path |
| `subject_id` | BIGINT UNSIGNED | R | `users.id` or `admins.id`, matching `subject_type` |
| `token_hash` | VARCHAR(255) | R | Store hash, not raw token |
| `expires_at` | TIMESTAMP | R | |
| `used_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |

#### Constraints

- Token single-use after `used_at` set
- Expired tokens invalid

#### Validation Rules

- Raw tokens never persisted.
- Short expiry window required.

#### Notes

- Aligns with FR-003 credential recovery.

---

### 3.1.7A `phone_otps`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `phone_otps` |
| **Purpose** | Hashed one-time passwords for phone login and phone-based password recovery (SRS FR-002A–FR-002C, FR-003A). |
| **Primary Key** | `id` |
| **Foreign Keys** | None — logical link to `users` via `phone` (an OTP may be requested before the identity is resolved) |
| **Relationships** | Associated to `users` by phone number at verification time |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `phone` | VARCHAR(30) | R | E.164 normalized target phone |
| `purpose` | VARCHAR(30) | R | `login` · `password_reset` |
| `otp_hash` | VARCHAR(255) | R | Hash of the 6-digit code; raw OTP never persisted |
| `attempts` | SMALLINT UNSIGNED | R | Failed verification count; default 0, cap 5 |
| `expires_at` | TIMESTAMP | R | 5 minutes after issue |
| `consumed_at` | TIMESTAMP | O | Set on successful verification (single-use) |
| `invalidated_at` | TIMESTAMP | O | Set when superseded by a newer OTP or attempt cap reached |
| `created_at` | TIMESTAMP | R | Issue time; drives resend cooldown and hourly caps |

#### Constraints

- At most one *active* OTP per (`phone`, `purpose`): issuing a new OTP sets `invalidated_at` on any prior unconsumed row.
- An OTP is valid only when unexpired, unconsumed, not invalidated, and `attempts` < 5.
- Single-use: `consumed_at` set exactly once; consumed OTPs never verify again (replay protection).

#### Validation Rules

- Raw OTP values are never stored or logged.
- Resend cooldown (60 s) and hourly request cap (5 per phone) are enforced from `created_at` history.
- Expired/terminal rows are prunable by scheduled cleanup; no business data depends on them.

#### Notes

- Aligns with FR-002A–FR-002C (login) and FR-003A (phone recovery).
- Delivery goes through the provider-independent SMS abstraction (SRS FR-002E); no provider-specific columns belong in this table.

---

### 3.1.8 `admins`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `admins` |
| **Purpose** | Admin panel operators (not mobile customers; not field employees). Separate Sanctum authenticatable from `users`. |
| **Primary Key** | `id` |
| **Foreign Keys** | None (role is an enum column; permissions via pivots) |
| **Relationships** | M:N → `permissions` via `admin_permissions` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `full_name` | VARCHAR(150) | R | |
| `email` | VARCHAR(191) | R | Unique login |
| `phone` | VARCHAR(30) | R | Unique |
| `password` | VARCHAR(255) | R | Hashed via Eloquent `hashed` cast |
| `role` | VARCHAR(30) | R | `super_admin`, `manager`, `sales`, `inventory`, `accountant` |
| `status` | VARCHAR(30) | R | `active`, `inactive` |
| `last_login_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | Soft delete |

#### Constraints

- Unique `email`, unique `phone`
- Role and status constrained
- Inactive admins cannot authenticate or use existing tokens on protected admin routes

#### Validation Rules

- Admins cannot authenticate via customer mobile APIs as `users`.
- Privilege separation mandatory (SRS §4.2).
- Password hashing uses a single strategy (`Admin` model `hashed` cast).

#### Notes

- **Employees / technicians are not modeled.** Assignment of field staff is out of scope for v1.
- Five admin roles only (Sprint 11). Super Admin has implicit permissions (not stored).

---

### 3.1.9 `permissions`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `permissions` |
| **Purpose** | Fine-grained admin permission catalog aligned to protected admin routes. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `key` | VARCHAR(100) | R | Unique machine key (e.g. `products.create`) |
| `name` | VARCHAR(150) | R | Display label |
| `group` | VARCHAR(50) | R | e.g. Products, Inventory, Admins |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Notes

- Catalog is seeded from `AdminPermission` enum. Keys without implemented protected routes must not be invented.

---

### 3.1.10 `admin_role_permissions` (Hybrid RBAC — role grants)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `admin_role_permissions` |
| **Purpose** | Role → permission grants for assignable roles (`manager`, `sales`, `inventory`, `accountant`). |
| **Primary Key** | `id` |
| **Foreign Keys** | `permission_id` → `permissions.id` (cascade delete) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `role` | VARCHAR(30) | R | Assignable role slug (not `super_admin`) |
| `permission_id` | BIGINT UNSIGNED | R | FK |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique (`role`, `permission_id`)
- Super Admin permissions are never persisted here

---

### 3.1.11 `admin_permissions` (Hybrid RBAC — direct grants)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `admin_permissions` |
| **Purpose** | Direct admin → permission grants. Additive to role grants. |
| **Primary Key** | `id` |
| **Foreign Keys** | `admin_id` → `admins.id`, `permission_id` → `permissions.id` (cascade delete) |

#### Columns

| Column | Data Type | Required |
| --- | --- | --- |
| `id` | BIGINT UNSIGNED | R |
| `admin_id` | BIGINT UNSIGNED | R |
| `permission_id` | BIGINT UNSIGNED | R |
| `created_at` | TIMESTAMP | R |
| `updated_at` | TIMESTAMP | R |

#### Constraints / Rules

- Unique (`admin_id`, `permission_id`)
- Effective permissions = role permissions ∪ direct permissions
- Super Admin direct permissions cannot be persisted

---

## 3.2 Service Management

### 3.2.1 `service_categories`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `service_categories` |
| **Purpose** | Hierarchical grouping for service discovery. |
| **Primary Key** | `id` |
| **Foreign Keys** | `parent_id` → `service_categories.id` (self-FK, optional) |
| **Relationships** | 1:N → `services`; optional tree via `parent_id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `parent_id` | BIGINT UNSIGNED | O | Self-FK |
| `name` | VARCHAR(150) | R | |
| `slug` | VARCHAR(160) | R | Unique |
| `description` | TEXT | O | |
| `sort_order` | INT | R | Default 0 |
| `is_active` | BOOLEAN | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

#### Constraints / Validation

- Slug unique; inactive categories hidden from customer browse but retained.

---

### 3.2.2 `services`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `services` |
| **Purpose** | Official service catalog items; every active service supports both Book Now and Request Quotation. |
| **Primary Key** | `id` |
| **Foreign Keys** | `category_id` → `service_categories.id` |
| **Relationships** | N:1 category; 1:N modes, coverage cities, media, bookings, quotation requests, blackout dates |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `category_id` | BIGINT UNSIGNED | R | FK |
| `name` | VARCHAR(200) | R | |
| `slug` | VARCHAR(220) | R | Unique |
| `short_description` | VARCHAR(500) | O | |
| `description` | TEXT | O | |
| `inclusions` | TEXT | O | |
| `exclusions` | TEXT | O | |
| `starting_from_price` | DECIMAL(12,2) | O | Optional customer-facing “Starting From” amount; not the final operational price |
| `currency` | CHAR(3) | R | System default typically |
| `duration_minutes` | INT | O | Estimate |
| `min_lead_hours` | INT | O | Eligibility |
| `max_concurrent_bookings` | INT | O | Capacity rule |
| `requires_address` | BOOLEAN | R | |
| `is_active` | BOOLEAN | R | |
| `sort_order` | INT | R | |
| `average_rating` | DECIMAL(3,2) | O | Cached aggregate from `published` reviews only; recalculated on review publish/hide (§3.9.1); NULL when no published reviews; clients display one decimal place; visible from the first published review (no minimum threshold) |
| `reviews_count` | INT UNSIGNED | R | Cached count of `published` reviews; default 0; recalculated on review publish/hide |
| `favorites_count` | INT UNSIGNED | R | Cached count of favorites (§3.12.1); default 0; updated on favorite add/remove and automatic removal; internal aggregate — not exposed in public catalog payloads |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

#### Constraints

- `starting_from_price`, when present, must be >= 0.
- Inactive services cannot accept new bookings/quote requests.

#### Validation Rules

- The V1 official service catalog is limited to: Deep Cleaning; Pest Control; Carpet Cleaning; Sofa & Chair Cleaning; Post Construction Cleaning; Window Cleaning; Fumigation Services; Housekeeper; and Monthly Cleaning Staff.
- Each service must have at least one active `service_modes` row and coverage in at least one supported city before customer visibility.
- Historical bookings remain readable after deactivation.

#### Notes

- Final service price is set only after Fayadhowr operational assessment/review. A Starting From amount is informational and optional.
- Service availability is not classified as booking-only or quotation-only; both customer actions are available for every active service.

---

### 3.2.3 `service_modes`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `service_modes` |
| **Purpose** | Configures the approved delivery/contract modes and optional workforce subtype for each service. |
| **Primary Key** | `id` |
| **Foreign Keys** | `service_id` → `services.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `mode` | VARCHAR(30) | R | `one_time` or `monthly_contract` |
| `subtype` | VARCHAR(40) | O | Only applicable to Housekeeper and Monthly Cleaning Staff |
| `is_active` | BOOLEAN | R | Controls customer visibility/selection |
| `created_at`, `updated_at` | TIMESTAMP | R | |

#### Constraints and Rules

- Unique (`service_id`, `mode`, `subtype`) prevents duplicate choices.
- Approved V1 modes are: Deep Cleaning, Pest Control, Window Cleaning, and Fumigation Services — `one_time` and `monthly_contract`; Carpet Cleaning, Sofa & Chair Cleaning, and Post Construction Cleaning — `one_time`; Housekeeper and Monthly Cleaning Staff — `monthly_contract`.
- Housekeeper subtypes are `full_time`, `part_time`, `live_in`, and `live_out`.
- Monthly Cleaning Staff subtypes are `office`, `hotel`, `restaurant`, `school`, `hospital_clinic`, and `other_business`.
- Other services must have a null `subtype` in V1.

---

### 3.2.4 `service_coverage_cities`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `service_coverage_cities` |
| **Purpose** | Records city-level availability for each service without coupling service coverage to customer addresses. |
| **Primary Key** | `id` |
| **Foreign Keys** | `service_id` → `services.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `city` | VARCHAR(100) | R | V1: `Mogadishu` or `Hargeisa` |
| `is_active` | BOOLEAN | R | |
| `created_at`, `updated_at` | TIMESTAMP | R | |

#### Constraints and Rules

- Unique (`service_id`, `city`).
- V1 supported cities are Mogadishu and Hargeisa only; new cities can be added as coverage data without redesigning the service schema.

---

### 3.2.5 `service_media`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `service_media` |
| **Purpose** | Images/media for services. |
| **Primary Key** | `id` |
| **Foreign Keys** | `service_id` → `services.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `media_type` | VARCHAR(30) | R | `image` (v1) |
| `url` | VARCHAR(500) | R | Object storage path/URL |
| `alt_text` | VARCHAR(255) | O | |
| `sort_order` | INT | R | |
| `is_primary` | BOOLEAN | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- One primary media recommended per service.

---

### 3.2.6 `service_blackout_dates`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `service_blackout_dates` |
| **Purpose** | Dates/windows when a service cannot be booked. |
| **Primary Key** | `id` |
| **Foreign Keys** | `service_id` → `services.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `starts_at` | TIMESTAMP | R | |
| `ends_at` | TIMESTAMP | R | |
| `reason` | VARCHAR(255) | O | Internal |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- `ends_at` > `starts_at`
- Booking creation must reject overlapping blackout windows

---

## 3.3 Store Management

### 3.3.1 `product_categories`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `product_categories` |
| **Purpose** | Store catalog categories. V1 seeds: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners. |
| **Primary Key** | `id` |
| **Foreign Keys** | `parent_id` → `product_categories.id` (optional) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `parent_id` | BIGINT UNSIGNED | O | |
| `name` | VARCHAR(150) | R | |
| `slug` | VARCHAR(160) | R | Unique |
| `description` | TEXT | O | |
| `sort_order` | INT | R | |
| `is_active` | BOOLEAN | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

---

### 3.3.2 `products`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `products` |
| **Purpose** | Single sellable product entity shared by Store (catalog/commerce) and Inventory (stock quantity). |
| **Primary Key** | `id` |
| **Foreign Keys** | `category_id` → `product_categories.id` |
| **Relationships** | 1:N product_images, cart_items, store_order_items, stock_ledgers; optional quotation_requests |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `category_id` | BIGINT UNSIGNED | R | FK — V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners |
| `name` | VARCHAR(200) | R | |
| `slug` | VARCHAR(220) | R | Unique |
| `sku` | VARCHAR(64) | R | Unique inventory SKU (e.g. `CLN-000245`) |
| `short_description` | VARCHAR(500) | O | |
| `description` | TEXT | O | |
| `selling_price` | DECIMAL(12,2) | R | Customer-facing unit price — always displayed when no active tier applies |
| `cost_price` | DECIMAL(12,2) | R | Amount paid to supplier; used for valuation/profit reporting; never changed by Selling Price edits |
| `currency` | CHAR(3) | R | |
| `unit` | VARCHAR(30) | R | Selling unit: `piece`, `pack`, `box`, `carton`, `bottle`, `liter`, `kg` (display with Selling Price, e.g. `12.00 / Bottle`) |
| `current_stock` | INT | R | Authoritative on-hand quantity; never negative |
| `low_stock_threshold` | INT | R | Dashboard Low Stock alert when `current_stock` <= threshold |
| `status` | VARCHAR(30) | R | Product operational status (e.g. `active`, `inactive`); catalog visibility also uses `is_active` |
| `availability_status` | VARCHAR(30) | R | Derived/display cue: `in_stock`, `low_stock`, `out_of_stock` (from Current Stock vs Low Stock Threshold) |
| `badge` | VARCHAR(30) | O | Optional marketing badge: `new`, `best_seller`, `popular`, `limited_stock` |
| `has_tier_pricing` | BOOLEAN | R | When true, use `product_price_tiers` for quantity bands on Selling Price |
| `allow_optional_quotation` | BOOLEAN | R | Enables optional product quote via **shared** Quotation Module |
| `is_active` | BOOLEAN | R | |
| `sort_order` | INT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

#### Constraints

- `selling_price` >= 0 and NOT NULL
- `cost_price` >= 0 and NOT NULL
- `current_stock` >= 0 (negative stock never allowed)
- `low_stock_threshold` >= 0
- Unique `slug`; unique `sku` (required)
- Purchases blocked when `availability_status = out_of_stock` or `current_stock` insufficient for requested quantity

#### Validation Rules

- Customers can browse, view Selling Price (+ unit), add to cart, and purchase at fixed or tier Selling Price.
- Changing Selling Price never changes Cost Price.
- Future purchase receipts may update Cost Price according to future inventory costing policies; costing methods are outside V1.
- Optional quotation for bulk/custom/special requests uses the **same** Quotation Module as services (`source = product`); does **not** hide Selling Price.
- Checkout / Store Order creation re-validates Selling Price (including applicable tier) and available stock; creation never decreases `current_stock`.
- Stock decreases only after related Payment becomes `paid`; failed/cancelled payments leave stock unchanged.
- Inventory movements are recorded in Stock Ledger; `current_stock` is the product’s on-hand balance.

#### Notes

- Gallery images live in `product_images` (ordered); client supports swipe + pagination + future zoom.
- Variants at scale remain a future item; v1 keeps one sellable row per product with optional tier bands.
- Heavy cleaning equipment and machines are outside V1 category scope.

### 3.3.2A `product_price_tiers` (optional quantity pricing)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `product_price_tiers` |
| **Purpose** | Optional quantity-based unit prices configured by admin. |
| **Primary Key** | `id` |
| **Foreign Keys** | `product_id` → `products.id` |

| Column | Notes |
| --- | --- |
| `min_qty` / `max_qty` | Inclusive band (`max_qty` null = open-ended, e.g. 50+) |
| `unit_price` | Price per product unit within this band |
| `sort_order` | Display order |

If a product has only one fixed Selling Price (`has_tier_pricing=false`), show the single Selling Price. If tier pricing is enabled, display all tiers and apply the matching band to cart/checkout.

---

### 3.3.3 `product_images`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `product_images` |
| **Purpose** | Product gallery images (primary + additional). |
| **Primary Key** | `id` |
| **Foreign Keys** | `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `image_path` | VARCHAR(500) | R | Storage path |
| `sort_order` | INT | R | |
| `is_primary` | BOOLEAN | R | Exactly one primary per product |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.3.4 `carts`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `carts` |
| **Purpose** | Shopping cart header. |
| **Primary Key** | `id` |
| **Foreign Keys** | `user_id` → `users.id` (required at checkout; may be set when user logs in) |
| **Relationships** | 1:N `cart_items` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `user_id` | BIGINT UNSIGNED | O | Required before placing order |
| `session_token` | VARCHAR(100) | O | Optional guest cart bridge |
| `status` | VARCHAR(20) | R | `active`, `converted`, `abandoned` |
| `currency` | CHAR(3) | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- Placing an order requires authenticated `user_id` (login required to place order).
- Guest may build a cart only if product policy allows; conversion requires login.

#### Notes

- Cart Selling Prices are indicative until checkout confirmation.

---

### 3.3.5 `cart_items`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `cart_items` |
| **Purpose** | Line items in a cart. |
| **Primary Key** | `id` |
| **Foreign Keys** | `cart_id` → `carts.id`, `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `cart_id` | BIGINT UNSIGNED | R | FK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `quantity` | INT | R | > 0 |
| `unit_price_snapshot` | DECIMAL(12,2) | R | Last seen Selling Price |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique (`cart_id`, `product_id`) recommended
- `quantity` > 0

---

## 3.3A Inventory Management

Inventory is a separate domain from Store. Store owns catalog commerce; Inventory owns stock acquisition and stock movement integrity. Products remain a single entity; movements live in Stock Ledger while `products.current_stock` holds on-hand quantity.

### 3.3A.1 `suppliers`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `suppliers` |
| **Purpose** | Vendors from whom Fayadhowr purchases stock. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `name` | VARCHAR(200) | R | |
| `contact_person` | VARCHAR(150) | O | |
| `phone` | VARCHAR(40) | O | |
| `email` | VARCHAR(150) | O | |
| `address` | TEXT | O | |
| `status` | VARCHAR(30) | R | `active`, `inactive` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

---

### 3.3A.2 `purchase_orders`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `purchase_orders` |
| **Purpose** | Inventory purchase commitments. Alone, never changes stock. |
| **Primary Key** | `id` |
| **Foreign Keys** | `supplier_id` → `suppliers.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `po_number` | VARCHAR(40) | R | Unique public reference |
| `supplier_id` | BIGINT UNSIGNED | R | FK |
| `status` | VARCHAR(30) | R | `draft`, `submitted`, `approved`, `partially_received`, `completed`, `cancelled` |
| `currency` | CHAR(3) | R | |
| `notes` | TEXT | O | |
| `submitted_at` | TIMESTAMP | O | |
| `approved_at` | TIMESTAMP | O | |
| `completed_at` | TIMESTAMP | O | |
| `cancelled_at` | TIMESTAMP | O | |
| `created_by_admin_id` | BIGINT UNSIGNED | O | FK → `admins.id` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Lifecycle

`Draft` → `Submitted` → `Approved` → `Partially Received` → `Completed` / `Cancelled`

#### Notes

- Purchase Order never increments or decrements `products.current_stock`.
- Stock changes only through Goods Receipt (increase) or paid customer sale / adjustment (decrease or correction).

---

### 3.3A.3 `purchase_order_items`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `purchase_order_items` |
| **Purpose** | Ordered product lines on a Purchase Order. |
| **Primary Key** | `id` |
| **Foreign Keys** | `purchase_order_id` → `purchase_orders.id`, `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `purchase_order_id` | BIGINT UNSIGNED | R | FK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `quantity_ordered` | INT | R | > 0 |
| `quantity_received` | INT | R | Default 0; updated by Goods Receipts |
| `unit_cost` | DECIMAL(12,2) | R | Expected Cost Price for this PO line |
| `line_total` | DECIMAL(12,2) | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.3A.4 `goods_receipts`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `goods_receipts` |
| **Purpose** | Confirmed receipt of goods against a Purchase Order; increases stock. |
| **Primary Key** | `id` |
| **Foreign Keys** | `purchase_order_id` → `purchase_orders.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `receipt_number` | VARCHAR(40) | R | Unique public reference |
| `purchase_order_id` | BIGINT UNSIGNED | R | FK |
| `received_at` | TIMESTAMP | R | |
| `received_by_admin_id` | BIGINT UNSIGNED | O | FK → `admins.id` |
| `notes` | TEXT | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Goods Receipt is allowed only when Purchase Order status is `approved` or `partially_received`.
- Submitted Purchase Orders must not receive inventory.
- Every Goods Receipt increases `products.current_stock` for received quantities.
- Every Goods Receipt creates Stock Ledger entries in `stock_ledgers` (`movement_type = purchase_receipt`).
- May move Purchase Order to `partially_received` or `completed`.

---

### 3.3A.5 `goods_receipt_items`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `goods_receipt_items` |
| **Purpose** | Quantities received per product on a Goods Receipt. |
| **Primary Key** | `id` |
| **Foreign Keys** | `goods_receipt_id` → `goods_receipts.id`, `purchase_order_item_id` → `purchase_order_items.id`, `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `goods_receipt_id` | BIGINT UNSIGNED | R | FK |
| `purchase_order_item_id` | BIGINT UNSIGNED | R | FK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `quantity_received` | INT | R | > 0 |
| `unit_cost` | DECIMAL(12,2) | R | Actual received unit cost; may inform future Cost Price updates |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.3A.6 `stock_ledgers`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `stock_ledgers` |
| **Purpose** | Append-only record of every stock movement. |
| **Primary Key** | `id` |
| **Foreign Keys** | `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `movement_type` | VARCHAR(40) | R | `purchase_receipt`, `customer_sale`, `adjustment`, `correction`, `damage`, `loss` |
| `quantity` | INT | R | Signed delta applied to stock (positive = increase, negative = decrease); nonzero |
| `reference_type` | VARCHAR(40) | O | Polymorphic source (e.g. GoodsReceipt, StoreOrder) |
| `reference_id` | BIGINT UNSIGNED | O | Polymorphic reference to source record |
| `created_at` | TIMESTAMP | R | Movement timestamp |

#### Notes

- Customer Sale ledger entries are created only when Payment becomes `paid` (not at Store Order creation).
- Purchase Receipt entries are created on Goods Receipt.
- Adjustment / Correction / Damage / Loss entries are created from Inventory Adjustments (future).
- Table name in implementation is `stock_ledgers` (not `stock_ledger_entries`).

---

### 3.3A.7 `stock_adjustments`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `stock_adjustments` |
| **Purpose** | Manual inventory corrections requiring quantity and reason. |
| **Primary Key** | `id` |
| **Foreign Keys** | `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `quantity` | INT | R | Signed adjustment delta; resulting stock must remain >= 0 |
| `reason` | VARCHAR(40) | R | `damaged`, `lost`, `correction`, `physical_count` |
| `notes` | TEXT | O | |
| `adjusted_by_admin_id` | BIGINT UNSIGNED | O | FK → `admins.id` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Quantity and reason are required.
- Every adjustment creates Stock Ledger entries with matching movement type (`damage`, `loss`, `correction`, or `stock_adjustment` for physical count).

---

## 3.4 Booking Management

### 3.4.1 `bookings`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `bookings` |
| **Purpose** | Customer service booking records. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`, `service_id` → `services.id`, `service_mode_id` → `service_modes.id`, optional `quotation_id` → `quotations.id` |
| **Relationships** | N:1 customer profile/service/service mode; 1:N status histories; optional link to accepted quotation; payments via polymorphic payable |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `booking_number` | VARCHAR(40) | R | Unique, system-generated public reference: `BK-YYYY-######`; not the primary key |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK — business ownership; authenticated through linked `users` record |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `service_mode_id` | BIGINT UNSIGNED | R | FK — selected approved mode/subtype for this booking |
| `quotation_id` | BIGINT UNSIGNED | O | When booking follows accepted quote |
| `status` | VARCHAR(30) | R | Admin/customer operational statuses (see below) |
| `priority` | VARCHAR(10) | R | Read-only operational badge: `high`, `medium`, `low` |
| `requested_date` | DATE | R | Customer-requested service date |
| `requested_time_window` | VARCHAR(100) | R | Customer-requested time window |
| `scheduled_start_at` | TIMESTAMP | O | Confirmed service start; null until operations confirms |
| `scheduled_end_at` | TIMESTAMP | O | Confirmed service end; null until operations confirms |
| `service_name_snapshot` | VARCHAR(200) | R | Immutable commercial snapshot |
| `starting_from_price_snapshot` | DECIMAL(12,2) | O | Optional informational Starting From amount shown when the booking was created; not an assessed final price |
| `currency` | CHAR(3) | R | |
| `address_snapshot` | JSON/TEXT | O | Copied address / property details |
| `service_city` | VARCHAR(100) | R | Requested service city; must be covered by the selected service |
| `customer_notes` | TEXT | O | Customer-visible request notes |
| `assigned_to_name` | VARCHAR(150) | O | **Manual** informational assignee (v1 — no Staff Management module) |
| `cancellation_reason` | VARCHAR(255) | O | |
| `cancelled_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `booking_number`
- `customer_profile_id` NOT NULL
- Status in: `pending_review`, `quotation_ready`, `under_discussion`, `accepted`, `scheduled`, `in_progress`, `completed`, `cancelled`  
  (Admin display labels: Pending Review · Quotation Ready · Under Discussion · Accepted · Scheduled · In Progress · Completed · Cancelled)  
  **Never** `rejected`. No custom status values. Status updates use a controlled dropdown only.
- Priority in: `high`, `medium`, `low` (Admin badges: High · Medium · Low).

#### Validation Rules

- Customer must authenticate as an active `users` record with a linked `customer_profiles` record; the booking is owned by `customer_profile_id`.
- Service and selected `service_mode_id` must be active, mutually consistent, and cover `service_city`.
- `requested_date` and `requested_time_window` are required when a booking is created. `scheduled_start_at` and `scheduled_end_at` are populated only after confirmation and must form a valid range.
- Capacity / blackout / lead-time checks are enforced before insert/update of the confirmed schedule.
- Customer cancel only when policy allows.
- Booking records are never permanently deleted.
- `assigned_to_name` is informational only (manual assignment outside the system).

#### Notes

- Draft status is client-side only and not persisted as a server booking (SRS §9.6).
- Internal staff notes use `booking_notes` (not a single `admin_notes` blob) for audit (name/role/date/time).
- Booking Media V1 is limited to images and videos; documents are explicitly excluded. This specification intentionally defines no booking-media storage table or storage implementation in V1.

---

### 3.4.1B `booking_notes` (Admin internal)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `booking_notes` |
| **Purpose** | Internal staff notes on a booking. Never visible to customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `booking_id` → `bookings.id`, `admin_id` → `admins.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `booking_id` | BIGINT UNSIGNED | R | FK |
| `admin_id` | BIGINT UNSIGNED | R | Author |
| `body` | TEXT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.4.2 `booking_status_histories`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `booking_status_histories` |
| **Purpose** | Audit trail of booking status transitions. |
| **Primary Key** | `id` |
| **Foreign Keys** | `booking_id` → `bookings.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `booking_id` | BIGINT UNSIGNED | R | FK |
| `from_status` | VARCHAR(30) | O | |
| `to_status` | VARCHAR(30) | R | |
| `changed_by_type` | VARCHAR(20) | R | `user`, `admin`, `system` |
| `changed_by_id` | BIGINT UNSIGNED | O | |
| `note` | VARCHAR(255) | O | |
| `created_at` | TIMESTAMP | R | |

#### Notes

- Admin Booking Timeline is read-only and must show **who** performed each event (resolved actor name + role for admins, customer name, or **System**).
- Prefer a general booking activity/timeline feed when events are broader than status-only (e.g. Images Uploaded, Payment Received); status transitions still land here.

## 3.5 Quotation Management

Supports:

1. **Service quotations** initiated from a booking; this is available for every active service and complements Book Now.
2. **Optional product quotations** for bulk/custom/special requests; these do not replace fixed-price purchase.

### 3.5.1 `quotation_requests`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_requests` |
| **Purpose** | Customer-submitted request for a formal quote. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`; optional `service_id` / `product_id` |
| **Relationships** | 1:N attachments, quotations |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `request_number` | VARCHAR(40) | R | Unique public reference; may match or link to canonical `QT-YYYY-######` once quotation family is created |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK — business owner; authenticated through linked user |
| `request_target_type` | VARCHAR(20) | R | Quotation **origin** for Admin: `booking` (service booking path) or `product` (product request). Display: Booking / Product. Standalone quotes forbidden. |
| `booking_id` | BIGINT UNSIGNED | O | Required when origin is Booking — permanent FK to `bookings.id` |
| `service_id` | BIGINT UNSIGNED | O | Service context (often via booking) |
| `product_id` | BIGINT UNSIGNED | O | Required if target=product |
| `status` | VARCHAR(30) | R | Aligns to V1 quotation lifecycle: `pending_review`, `quotation_ready`, `under_discussion`, `accepted`, `expired`, `cancelled` (never `rejected` / `declined`) |
| `title` | VARCHAR(200) | O | |
| `requirements` | TEXT | R | Customer requirements summary |
| `description` | TEXT | O | Optional customer notes in their own words (cleaning requirements detail, uploaded-file explanation, special instructions) |
| `preferred_timing` | VARCHAR(255) | O | |
| `quantity_hint` | INT | O | Useful for product bulk requests |
| `location_snapshot` | JSON/TEXT | O | |
| `admin_notes` | TEXT | O | Internal |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Exactly one origin populated: `booking_id` when `request_target_type=booking`, or `product_id` when `request_target_type=product`
- For `product` target: product should allow optional quotation (`allow_optional_quotation=true`)
- For `booking` origin: linked booking must exist and remain permanently referenced
- Origin link is permanent — never null after create; Admin/Sales/Accountant cannot create a quotation without an origin
- Status never `rejected` / `declined`

#### Validation Rules

- Authenticated active customer required.
- Incomplete requirements rejected.
- `description` is optional; when provided, store as free-text customer notes.
- Product quotation is optional and parallel to normal priced purchase workflow.

#### Notes

- `description` allows customers to describe the quotation request in their own words—for example explain cleaning requirements, explain uploaded files, or add special instructions.
- Closing via **Cancelled** (company policy) notifies customer per notification module. Never use Rejected/Declined.

---

### 3.5.2 `quotation_request_attachments`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_request_attachments` |
| **Purpose** | Optional files supporting a quote request. Links a quotation request to staged files in the Unified File Upload Service (`uploads`, §3.11.1). |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_request_id` → `quotation_requests.id`; `upload_id` → `uploads.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `quotation_request_id` | BIGINT UNSIGNED | R | FK |
| `upload_id` | BIGINT UNSIGNED | R | FK → `uploads.id`; UNIQUE (an upload is attached to at most one request) |
| `created_at` | TIMESTAMP | R | |

#### Validation Rules

- **Approved (Sprint 23):** attachments reference the `uploads` table via FK; upload metadata (name, mime type, size, path) is **not duplicated** here — it is read from the referenced `uploads` row.
- The referenced upload must be owned by the requesting customer, unattached, and non-expired at attach time; attaching sets `uploads.attached_at`.
- Allowed mime types and per-type size caps are enforced at upload time by the Unified File Upload Service (§3.11.1):
  - **Images:** JPG, JPEG, PNG, WebP
  - **Videos:** MP4, MOV, WebM
  - **Documents:** PDF
- Customers may upload one or more files when requesting quotations.

#### Notes

##### Supported Attachments

- Customers may upload one or more files when requesting quotations.
- Supported quotation attachments include:
  - Images
  - Videos
  - PDF documents

###### Images

- JPG  
- JPEG  
- PNG  
- WebP  

###### Videos

- MP4  
- MOV  
- WebM  

###### Documents

- PDF  

##### Business Usage

- **Service Quotation** — Customers may upload images and/or videos.
- **Product Quotation** — Customers may upload PDF product lists/catalogs and optional images.
- **Mixed Quotation** — Customers may upload any combination of images, videos, and PDF documents.

These files help administrators accurately assess the requested work and prepare quotations. The `quotation_request_attachments` table must remain generic so it can store all supported attachment types.

---

### 3.5.3 `quotations`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotations` |
| **Purpose** | Formal priced proposal issued by admin against a request. |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_request_id` → `quotation_requests.id`, `issued_by_admin_id` → `admins.id` |
| **Relationships** | 1:N `quotation_items`; payable via `payments`; may unlock booking/order fulfillment |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `quotation_number` | VARCHAR(40) | R | Unique; format `QT-YYYY-######` (example `QT-2026-000123`). Same number across all revisions of one quotation family |
| `quotation_request_id` | BIGINT UNSIGNED | R | FK |
| `version_no` | INT | R | Starts at 1; each update creates v2, v3… |
| `is_latest` | BOOLEAN | R | True only for the current accept-eligible revision |
| `status` | VARCHAR(30) | R | Customer-facing lifecycle (shared): `pending_review`, `quotation_ready`, `under_discussion`, `accepted`, `expired`, `cancelled` — **never** `rejected` |
| `currency` | CHAR(3) | R | |
| `subtotal_amount` | DECIMAL(12,2) | R | |
| `tax_amount` | DECIMAL(12,2) | R | Default 0 |
| `total_amount` | DECIMAL(12,2) | R | |
| `deposit_amount` | DECIMAL(12,2) | O | If partial payment allowed |
| `valid_until` | TIMESTAMP | R | Acceptance window |
| `terms` | TEXT | O | |
| `issued_by_admin_id` | BIGINT UNSIGNED | O | FK |
| `accepted_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique (`quotation_number`, `version_no`); display number `QT-YYYY-######` unique per quotation family
- At most one row with `is_latest=true` per `quotation_number`
- Acceptance blocked when `now > valid_until`, status is `expired`/`cancelled`, or `is_latest=false`

#### Validation Rules

- **Accept Quotation** and **Discuss Quotation** only by owning customer (never Reject).
- Only the **latest** revision may be accepted; older revisions read-only.
- Accepted quotation unlocks payment / fulfillment path.
- Discuss / team updates create a new `version_no` on the **same** `quotation_number` (do not create a new quotation).
- Status becomes `under_discussion` while discussion is active.

#### Notes

- Payment targets the accepted latest quotation revision.
- `currency` is the quotation's commercial currency. It is set when the quotation is created and is immutable thereafter; quotation-derived orders copy this value.
- Remove any `rejected_at` column / `rejected` status from V1 implementations.
- Quotation records are never permanently deleted.
- Admin timeline events must record actor (admin name + role, customer, or System).
- Discussion messages and attachments are append-only (cannot be deleted).
- Each revision permanently retains **Created By** via `issued_by_admin_id` (+ role at read time) and `created_at` for audit.
- Admin **Compare Revisions** is a read-only derived view between any two `version_no` rows (line items / qty / unit price / totals / notes).
- Admin UI shows Valid Until countdown derived from `valid_until` vs now.

---

### 3.5.3Z `quotation_notes` (Admin internal)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_notes` |
| **Purpose** | Internal staff notes on a quotation. Never visible to customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_number` family or `quotation_id` → latest/`quotations.id`; `admin_id` → `admins.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `quotation_id` | BIGINT UNSIGNED | R | FK — typically points at latest revision row or stable family key |
| `admin_id` | BIGINT UNSIGNED | R | Author |
| `body` | TEXT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.5.3A `quotation_messages` (Discuss Quotation)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_messages` |
| **Purpose** | Discussion thread attached to a quotation (same Quotation Number). |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_number` / `quotation_request_id`; optional `user_id` or `admin_id` as author |

#### Columns (logical)

| Column | Notes |
| --- | --- |
| `id` | PK |
| `quotation_request_id` | FK — discussion parent |
| `quotation_number` | Canonical public reference |
| `author_type` | `customer` or `admin` |
| `author_id` | Customer or admin id |
| `body` | Message text |
| `created_at` | Timestamp |

### 3.5.3B `quotation_message_attachments`

Additional images/videos/PDFs uploaded during Discuss Quotation (same allowed mime types as request attachments). Linked to `quotation_messages.id` and, following the same approved pattern as §3.5.2, referencing staged files via FK to `uploads.id` without duplicating upload metadata.

### 3.5.3C `quotation_status_histories`

Timeline events: Quotation Created, Customer Discussion, Team Replies, Quotation Updated, Customer Acceptance, Payment, Service Completion. Supports admin linked-record navigation and customer read-only timeline.

---

### 3.5.4 `quotation_items`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_items` |
| **Purpose** | Line items on an issued quotation. |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_id` → `quotations.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `quotation_id` | BIGINT UNSIGNED | R | FK |
| `description` | VARCHAR(500) | R | |
| `quantity` | DECIMAL(12,2) | R | |
| `unit_price` | DECIMAL(12,2) | R | |
| `line_total` | DECIMAL(12,2) | R | |
| `sort_order` | INT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- `line_total` must equal `quantity * unit_price` (within rounding rules).
- Sum of line totals must reconcile to quotation subtotal.

---

## 3.6 Payment Management

### 3.6.1 `payments`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `payments` |
| **Purpose** | Customer-profile-owned authoritative payment lifecycle for Service Orders and Store Orders. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`; polymorphic payable reference |
| **Relationships** | N:1 customer profile; 1:N gateway transactions; 1:1 receipt after successful payment |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `payment_number` | VARCHAR(40) | R | Unique public payment reference |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `payable_type` | VARCHAR(30) | R | Polymorphic originating payable type; V1 Service Order and Store Order |
| `payable_id` | BIGINT UNSIGNED | R | Originating payable entity id |
| `amount` | DECIMAL(12,2) | R | Charged amount |
| `currency` | CHAR(3) | R | |
| `status` | VARCHAR(30) | R | `pending`, `initialized`, `processing`, `paid`, `failed`, `cancelled` |
| `idempotency_key` | VARCHAR(100) | R | Unique for safe retries |
| `failure_code` | VARCHAR(50) | O | |
| `failure_message` | VARCHAR(255) | O | Safe customer/ops message |
| `paid_at` | TIMESTAMP | O | Set when status becomes `paid` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `payment_number`
- Unique `idempotency_key`
- `amount` > 0
- Enforce at most one active Payment for each (`payable_type`, `payable_id`) using a PostgreSQL partial unique index scoped to statuses `pending`, `initialized`, and `processing`.

#### Validation Rules

- Amount must match backend-calculated payable amount at initiation.
- When an active Payment already exists for the payable entity, initialization returns it and does not create another Payment. A new Payment is permitted only after the previous Payment is `paid`, `failed`, or `cancelled`.
- Client cannot mark payment Paid; only gateway verification may do so.
- Callback/webhook verification order is mandatory before state change: gateway signature/authentication, gateway transaction reference, Payment resolution, active-Payment validation, duplicate-callback check, then one atomic database transaction.
- When Payment becomes Paid, the originating Order transitions from `pending_payment` to `confirmed` in the same approved transaction; Failed or Cancelled payments do not cancel Orders.
- Payment publishes `PaymentPaid` or `PaymentFailed`; it does not notify customers directly.

#### Notes

- No PAN/CVV/full card data stored.
- Gateway implementation is provider-neutral; provider-specific metadata belongs to `payment_transactions`, not `payments`.
- Refunds are outside V1.

---

### 3.6.2 `payment_transactions`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `payment_transactions` |
| **Purpose** | One or more provider gateway attempts, callbacks, or reconciliation records for one Payment. |
| **Primary Key** | `id` |
| **Foreign Keys** | `payment_id` → `payments.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `payment_id` | BIGINT UNSIGNED | R | FK |
| `gateway` | VARCHAR(50) | R | Provider-neutral adapter identifier |
| `gateway_transaction_reference` | VARCHAR(191) | O | Provider transaction reference |
| `status` | VARCHAR(30) | R | Gateway transaction lifecycle/result |
| `amount` | DECIMAL(12,2) | R | Transaction amount |
| `payload` | JSON | O | Minimal sanitized gateway payload |
| `processed_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Provider references are unique per gateway when present.
- One Payment may have multiple transactions; only verified transaction results may change the parent Payment status.
- Gateway transaction references from callbacks must match the stored `payment_transactions` record before any state transition is applied.
- Duplicate successful callbacks must be idempotent and must never create duplicate transaction updates, payment status histories, or order confirmations.

---

### 3.6.3 `receipts`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `receipts` |
| **Purpose** | One receipt produced for every successful payment. Receipt PDF generation is outside V1. |
| **Primary Key** | `id` |
| **Foreign Keys** | `payment_id` → `payments.id` (UNIQUE) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `payment_id` | BIGINT UNSIGNED | R | FK |
| `receipt_number` | VARCHAR(40) | R | Unique public reference `RCPT-YYYY-######` |
| `issued_at` | TIMESTAMP | R | Receipt issuance timestamp |
| `created_at`, `updated_at` | TIMESTAMP | R | |

---

## 3.7 Order Management

### 3.7.1 `orders`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `orders` |
| **Purpose** | Customer-profile-owned commercial transactions created by store checkout or an accepted quotation. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`, optional `quotation_id` → `quotations.id`, optional `booking_id` → `bookings.id`, optional `cart_id` → `carts.id` |
| **Relationships** | 1:N `order_items`, `order_status_histories`, `order_notes`; payments via payable link |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `order_number` | VARCHAR(40) | R | Unique `ORD-YYYY-######` |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK — authenticated through linked user |
| `quotation_id` | BIGINT UNSIGNED | O | FK — accepted quotation when the order follows quotation acceptance |
| `booking_id` | BIGINT UNSIGNED | O | FK — populated when source chain passes through a booking |
| `source_type` | VARCHAR(30) | R | `store_cart`, `product_quotation`, or `booking` |
| `cart_id` | BIGINT UNSIGNED | O | Required for `store_cart`; source cart when applicable |
| `status` | VARCHAR(30) | R | Order status: `pending_payment`, `confirmed`, `processing`, `completed`, `cancelled` |
| `payment_status` | VARCHAR(20) | R | `unpaid`, `partially_paid`, `paid`, `refunded` |
| `currency` | CHAR(3) | R | |
| `subtotal_amount` | DECIMAL(12,2) | R | |
| `discount_amount` | DECIMAL(12,2) | R | Default 0 |
| `delivery_fee` | DECIMAL(12,2) | R | Default 0 |
| `tax_amount` | DECIMAL(12,2) | R | Default 0 |
| `total_amount` | DECIMAL(12,2) | R | Grand total |
| `fulfillment_type` | VARCHAR(30) | O | `delivery`, `pickup` |
| `shipping_address_snapshot` | JSON/TEXT | O | Immutable |
| `customer_notes` | TEXT | O | |
| `placed_at` | TIMESTAMP | R | |
| `cancelled_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `order_number`
- `customer_profile_id` required
- `currency` is copied from the accepted quotation at order creation and is immutable thereafter; payments copy it from the order.
- `quotation_id` is required only for `product_quotation` or quotation-derived `booking` orders.
- `source_type` must be `store_cart`, `product_quotation`, or `booking`; `cart_id` is required for `store_cart`, and `booking_id` is required for `booking`.
- Totals must equal sum of item snapshots + discount + delivery + tax (policy)
- Order status in: `pending_payment`, `confirmed`, `processing`, `completed`, `cancelled`
- Payment status in: `unpaid`, `partially_paid`, `paid`, `refunded`

#### Validation Rules

- Orders are created by the system at authenticated store-cart checkout or when an accepted quotation requires an order; never manually by Admin/Sales/Accountant.
- Selling Price and available stock re-validated at Store Order placement; overselling is rejected.
- Creating a Store Order never decreases `products.current_stock`.
- Stock decreases only after related Payment becomes `paid`, and then a Stock Ledger `customer_sale` entry is written.
- Failed or cancelled payments leave stock unchanged.
- Suspended customers cannot place orders.
- Order records are never permanently deleted.
- Source link (cart, quotation, and/or booking as applicable) is permanent.
- Store Orders reuse the Unified Payment Module and lifecycle: `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`.

#### Notes

- Admin timeline events must record actor (admin name + role, customer, or System).
- Internal staff notes use `order_notes` (not a single `admin_notes` blob) for audit (name/role/date/time).
- Discussion history is accessed via the linked quotation, not duplicated on the order.
- **Order Progress Tracker** is a UI-only visual indicator computed from the current `status`; no additional database field required.
- **Order Age** is computed at query time from `placed_at` / `created_at`; no stored column.
- **Payment Timeline** events (Payment Requested, Payment Received, Payment Confirmed, Refund Processed) are stored in `order_status_histories` (or linked payment audit tables) with `performed_by`, `performed_by_role`, date, and time.
- **Documents Status** (Available / Not Available Yet) is determined at runtime from document generation records; no additional column on `orders`.
- **Financial Summary** values (Subtotal, Discount, Delivery Fee, Tax, Grand Total, Amount Paid, Remaining Balance) are derived from existing `orders` columns and linked `payments`; all read-only presentation.
- **Payment Status Color System** is UI-only (Paid=green, Partially Paid=orange, Unpaid=red, Refunded=blue); no database change — colors are mapped from the existing `payment_status` enum.
- **Current Stage Indicator** is UI-only; derived from the existing `status` column at query time.
- **Linked Records — Order Documents** shortcut navigates to the documents section; no new database relationship required.
- **Latest Note indicator** is derived at query time from the most recent `order_notes.created_at`; no additional stored column.

---

### 3.7.2 `order_items`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `order_items` |
| **Purpose** | Immutable purchased line snapshots. |
| **Primary Key** | `id` |
| **Foreign Keys** | `order_id` → `orders.id`, `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `order_id` | BIGINT UNSIGNED | R | FK |
| `product_id` | BIGINT UNSIGNED | R | FK (reference; snapshot is source of truth) |
| `product_name_snapshot` | VARCHAR(200) | R | |
| `sku_snapshot` | VARCHAR(64) | O | |
| `unit_price_snapshot` | DECIMAL(12,2) | R | Confirmed Selling Price |
| `quantity` | INT | R | > 0 |
| `line_total_snapshot` | DECIMAL(12,2) | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Snapshots must not change after order placement except via formal correction/audit process.

---

### 3.7.2B `order_notes` (Admin internal)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `order_notes` |
| **Purpose** | Internal staff notes on an order. Never visible to customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `order_id` → `orders.id`, `admin_id` → `admins.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `order_id` | BIGINT UNSIGNED | R | FK |
| `admin_id` | BIGINT UNSIGNED | R | Author |
| `body` | TEXT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.7.3 `order_status_histories`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `order_status_histories` |
| **Purpose** | Order status transition audit. |
| **Primary Key** | `id` |
| **Foreign Keys** | `order_id` → `orders.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `order_id` | BIGINT UNSIGNED | R | FK |
| `from_status` | VARCHAR(30) | O | |
| `to_status` | VARCHAR(30) | R | |
| `changed_by_type` | VARCHAR(20) | R | `customer`, `admin`, `system` |
| `changed_by_id` | BIGINT UNSIGNED | O | |
| `note` | VARCHAR(255) | O | |
| `created_at` | TIMESTAMP | R | |

#### Notes

- Admin Order Timeline is read-only and must show **who** performed each event (resolved actor name + role for admins, customer name, or **System**).
- Order statuses: Pending Payment · Confirmed · Processing · Completed · Cancelled.
- Payment statuses: Unpaid · Partially Paid · Paid · Refunded.

---

## 3.8 Notification Management

Sprint 12 Notification Architecture (Option B) is authoritative for schema and lifecycle.

### 3.8.1 `notification_templates`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `notification_templates` |
| **Purpose** | Admin-configurable templates for lifecycle notifications. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `template_key` | VARCHAR(100) | R | Unique template key |
| `name` | VARCHAR(150) | R | Admin display name |
| `type` | VARCHAR(30) | R | `booking`, `quotation`, `order`, `payment`, `store_order`, `inventory`, `system` |
| `channel` | VARCHAR(30) | R | `in_app`, `email`, `sms` |
| `language` | VARCHAR(10) | R | Base/default language code |
| `title` | VARCHAR(255) | R | Base title template (`{{placeholders}}`) |
| `message` | TEXT | R | Base message template |
| `subject` | VARCHAR(255) | O | Email subject template |
| `status` | VARCHAR(30) | R | `active`, `inactive` |
| `variables` | JSON | O | Declared placeholder names |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Templates must not include secret placeholders (card data, raw OTPs outside auth flows).
- Only `active` templates may be rendered for dispatch.

### 3.8.2 `notification_template_translations`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `notification_template_translations` |
| **Purpose** | Per-language overrides for templates (`so`, `en`, `ar`). |
| **Primary Key** | `id` |
| **Foreign Keys** | `notification_template_id` → `notification_templates.id` (cascade delete) |

Unique (`notification_template_id`, `language`). Render fallback: requested language → base template language → `en` → template body.

### 3.8.3 `notification_preferences`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `notification_preferences` |
| **Purpose** | Per-recipient, per-type channel toggles (polymorphic recipient). |
| **Primary Key** | `id` |

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `recipient_type` | VARCHAR(100) | R | `App\Models\Admin` or `App\Models\CustomerProfile` |
| `recipient_id` | BIGINT UNSIGNED | R | |
| `notification_type` | VARCHAR(30) | R | Same catalog as notification `type` |
| `in_app` | BOOLEAN | R | Default true when unresolved |
| `email` | BOOLEAN | R | Default true when unresolved |
| `sms` | BOOLEAN | R | Default false when unresolved |

Unique (`recipient_type`, `recipient_id`, `notification_type`). Missing rows use dynamic defaults (no auto-seeded preference rows).

### 3.8.4 `notifications`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `notifications` |
| **Purpose** | Persisted notifications for polymorphic recipients (`Admin` \| `CustomerProfile`). |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `recipient_type` | VARCHAR(100) | R | Morph type |
| `recipient_id` | BIGINT UNSIGNED | R | Morph id |
| `type` | VARCHAR(30) | R | Business notification type |
| `channel` | VARCHAR(30) | R | `in_app`, `email`, `sms` |
| `status` | VARCHAR(30) | R | Enterprise lifecycle (below) |
| `title` | VARCHAR(255) | R | Rendered |
| `message` | TEXT | R | Rendered |
| `data` | JSON | O | Includes `event_id`, template metadata, variables |
| `event_id` | VARCHAR(100) | R | Dispatch idempotency key (also mirrored in `data`) |
| `processing_started_at` | TIMESTAMPTZ | O | |
| `sent_at` | TIMESTAMPTZ | O | |
| `delivered_at` | TIMESTAMPTZ | O | |
| `read_at` | TIMESTAMPTZ | O | |
| `failed_at` | TIMESTAMPTZ | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Enterprise lifecycle

`pending` → `processing` → `sent` → `delivered` → `read`  
`processing` → `failed`

V1 in-app: after successful channel processing, `sent` → `delivered` immediately. Email/SMS remain at `sent` until future provider callbacks move them to `delivered`.

#### Constraints / Validation

- Unique (`recipient_type`, `recipient_id`, `channel`, `event_id`).
- Recipients may only read their own notifications.
- Delivery failure must not roll back the business transaction that emitted the domain event.
- Customers never delete live notifications; terminal rows may be moved to archive.

### 3.8.5 `archived_notifications`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `archived_notifications` |
| **Purpose** | Cold storage for terminal notifications (`read`, `failed`). Never delete without archiving. |

Preserves recipient, type, channel, status, title, message, data, lifecycle timestamps, `original_notification_id`, `archived_at`, and original `created_at`. Unique `original_notification_id`. Admin browse only (`notifications.manage`).

---

## 3.9 Review Management

### 3.9.1 `reviews`

> **V1 scope (Sprint 24 — final):** Customer Reviews V1 supports **completed booking reviews only**. Product, order, and service-target reviews are out of V1 scope and may be reintroduced in a future version.

| Attribute | Detail |
| --- | --- |
| **Table Name** | `reviews` |
| **Purpose** | Customer ratings/comments for **completed bookings** (V1). |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`; `booking_id` → `bookings.id` (UNIQUE); `service_id` → `services.id` (denormalized from the booking) |
| **Relationships** | N:1 customer profile; 1:1 booking (one review per completed booking); N:1 service (read/aggregate paths) |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `booking_id` | BIGINT UNSIGNED | R | FK; UNIQUE — one review per completed booking |
| `service_id` | BIGINT UNSIGNED | R | FK; copied from the booking's service at creation (denormalized for public listing and aggregates) |
| `rating` | TINYINT | R | 1–5 |
| `title` | VARCHAR(150) | O | |
| `comment` | TEXT | O | When provided: 10–1000 characters |
| `status` | VARCHAR(20) | R | `pending` (default on creation), `published`, `hidden` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- `rating` between 1 and 5
- UNIQUE(`booking_id`) — one review per completed booking; multiple completed bookings allow multiple reviews
- `comment`, when provided, must be 10–1000 characters
- `status` in: `pending`, `published`, `hidden`

#### Status Lifecycle

- Every review is created as **`pending`**.
- Only admin moderation changes status: approve → `published`; hide → `hidden`.
- Admin may re-moderate: `published` → `hidden` and `hidden` → `published`.
- Customers may **edit or delete a review only while `pending`**; `published` and `hidden` reviews are immutable to the customer.
- Deleting a `pending` review frees its `booking_id` — the customer may submit a new review for the same booking.
- If a completed booking is reverted to a non-completed status, its review is **automatically set to `hidden`** (aggregates recalculate). Reviews are never deleted automatically.
- There is **no review deadline** — a completed booking remains reviewable indefinitely.
- Moderation outcome notifications to customers are deferred until a future version.

#### Validation Rules

- Only the owning customer may create a review, and only for their own booking with status `completed`.
- No employee/technician review entity (workforce out of scope).
- Admin moderation is approve/hide only — admins never edit review content and never reply (no reply structure in V1).
- Public reviewer identity is rendered as **First Name + Initial**; soft-deleted customers display **Verified Customer**. Reviews are retained when the author is soft-deleted.

#### Notes

- Included to support customer feedback; does not invent a separate social network module.
- Rating aggregates are **not** computed from this table at read time on hot paths — see the cached `average_rating` / `reviews_count` columns on `services` (§3.2.2), recalculated on publish/hide.

---

## 3.10 Settings

### 3.10.1 `system_settings` (authoritative spec below)

The single source of truth for system configuration is the `system_settings` table, fully specified in **"Settings Module — Database Design"** later in this document. The previous standalone `settings` table specification is superseded and removed; no `settings` table exists in this design.

Notes retained from the superseded spec:

- Sensitive values (API secrets, `smtp.password`) are never returned to mobile clients or read APIs (`is_sensitive = TRUE`).
- Currency and tax/fee display rules are centrally configurable (SRS NFR-070) via the `currency.*` and `tax.*` keys.

---

### 3.10.2 `audit_logs`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `audit_logs` |
| **Purpose** | Event-driven operational audit for sensitive admin actions. Rows are written by listeners on `AuditEvent`, not by direct controller writes. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `admin_id` | BIGINT UNSIGNED | O | FK → `admins.id` (actor) |
| `action` | VARCHAR(50) | R | e.g. `login`, `logout`, `create`, `update`, `delete`, `role_update`, `permission_update` |
| `entity_type` | VARCHAR(100) | O | |
| `entity_id` | BIGINT UNSIGNED | O | |
| `description` | VARCHAR(255) | R | |
| `metadata` | JSON | O | |
| `ip_address` | VARCHAR(45) | O | |
| `user_agent` | VARCHAR(255) | O | |
| `created_at` | TIMESTAMP | R | |

#### Validation Rules

- Append-oriented; no customer update of audit rows.
- Required for money/status/catalog-critical admin actions (SRS NFR-024) and Admin Module mutations (create/update/delete admin, role permission update, direct permission update, login/logout).

---

## 3.11 File Upload Service

Unified File Upload Service (Sprint 23, approved). Customer-uploaded files are staged as reusable records identified publicly by **UUID only**; numeric IDs, storage disks, and paths remain internal and are never exposed through the API. Legacy module-specific upload tables (`customer_attachments`, `product_images`, `booking_media`, `service_media`) remain unchanged; their migration onto this service is **deferred until after Backend V1**.

### 3.11.1 `uploads`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `uploads` |
| **Purpose** | Staged customer file uploads (images / videos / PDF documents), uploaded before being attached to business records (first consumer: quotation requests). |
| **Primary Key** | `id` (internal only) |
| **Public Identifier** | `uuid` (the only identifier exposed in routes and payloads) |
| **Owner** | `customer_profile_id`, resolved server-side from the authenticated customer (ADR-001); never client-supplied |
| **Relationships** | N:1 → `customer_profiles`; referenced via FK (`upload_id`) by attachment tables (`quotation_request_attachments`, `quotation_message_attachments`) which never duplicate upload metadata |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK, internal only — never exposed |
| `uuid` | CHAR(36) | R | UNIQUE; public identifier |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK → `customer_profiles.id` (owner) |
| `disk` | VARCHAR(100) | R | Storage disk (internal; local/private in V1, S3-compatible later) |
| `path` | VARCHAR(500) | R | Storage path (internal; never exposed — files are streamed by the backend) |
| `original_name` | VARCHAR(255) | R | Client file name (display only) |
| `media_type` | VARCHAR(20) | R | `image` \| `video` \| `document` |
| `mime_type` | VARCHAR(100) | R | Allow-list validated (JPG/JPEG/PNG/WebP; MP4/MOV/WebM; PDF) |
| `file_size_bytes` | BIGINT UNSIGNED | R | Caps: image 10 MB, PDF 20 MB, video 100 MB |
| `attached_at` | TIMESTAMP | O | NULL = staged (unattached); set when consumed by a business record |
| `expires_at` | TIMESTAMP | O | Staging expiry (`created_at` + 7 days); not applicable once attached |
| `created_at` / `updated_at` | TIMESTAMP | R | Standard |

#### Validation Rules

- Uploader: authenticated customers only (admin uploads remain module-specific).
- MIME type and extension must match the allow-list; per-type size caps and max 10 files per request enforced at the application layer (configuration-driven; no Settings UI in V1).
- **Staged storage quota:** total unattached staged storage per customer is capped at **500 MB**; uploads exceeding the quota are rejected with `409` `UPLOAD_STORAGE_LIMIT_EXCEEDED`.
- Owner-only access: reads stream file content through the backend; deletes allowed only while unattached (attached → `409 Conflict`).
- Unattached uploads expire after **7 days**; a scheduled job deletes expired file content and rows.
- Attaching an upload sets `attached_at` and removes it from staging expiry; an upload may be attached only by its owner.

---

## 3.12 Favorites

> **V1 scope (Favorites Module — final):** Favorites support **services only**. Product favorites are out of V1 scope and deferred to a future version (no product reference exists in this design).

### 3.12.1 `favorites`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `favorites` |
| **Purpose** | Customer-saved services (mobile heart/save feature). |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`; `service_id` → `services.id` |
| **Relationships** | N:1 customer profile; N:1 service |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK (owner, resolved server-side from the authenticated customer per ADR-001) |
| `service_id` | BIGINT UNSIGNED | R | FK; must reference an **active** service at creation |
| `created_at` | TIMESTAMP | R | Drives newest-favorited-first list ordering |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- UNIQUE(`customer_profile_id`, `service_id`) — one favorite per customer per service; add and remove are idempotent at the API layer (`200 OK` when already favorited on add, and when not currently favorited on remove).
- No soft delete: favorites are hard-deleted rows.

#### Lifecycle & Integrity

- Adding requires an authenticated customer with **active** account status and an **active** service; inactive customers cannot add, remove, or list favorites.
- **Automatic removal:** when a service becomes inactive or is deleted, all related `favorites` rows are removed and the service's cached `favorites_count` is recalculated. Favorites of unavailable services are never surfaced.
- Removal is keyed by service (heart-toggle semantics): the delete contract is `DELETE /api/v1/favorites/{service}`. Removal is **idempotent** — removing an existing accessible service that is not currently favorited succeeds; not-found applies only to non-existent or inaccessible services.
- `services.favorites_count` (§3.2.2) is maintained on add, remove, and automatic removal; it is internal and not part of public catalog payloads.
- No favorites cap per customer — list access is paginated only.
- No activity-log/timeline events and no admin management tables or APIs in V1.

---

# 4. Relationships

## 4.1 One-to-One

| Parent | Child | Description |
| --- | --- | --- |
| `users` | `customer_profiles` | Exactly one business/profile record per authenticated mobile customer (`customer_profiles.user_id` UNIQUE) |
| `quotation_requests` | Current accept-eligible `quotations` row | Logical 1:1 “active quote” via status/version rules (multiple historical versions may exist) |
| `carts` | Converted `orders` | A cart may convert to at most one order (`status=converted`) |

> Strict physical 1:1 tables are minimized; commercial “current” relationships are enforced by status rules.

## 4.2 One-to-Many

| Parent | Children | Description |
| --- | --- | --- |
| `users` | `customer_profiles`, `customer_devices`, `carts`, `notifications` | Sole customer authentication principal; authentication-adjacent children remain user-scoped |
| `customer_profiles` | `customer_addresses`, `customer_payment_methods`, `customer_notes`, `uploads`, `bookings`, `quotation_requests`, `orders`, `payments`, `reviews`, `favorites` | Customer business/profile data and approved business-module ownership |
| `service_categories` | `services` | Category catalog |
| `services` | `service_modes`, `service_coverage_cities`, `service_media`, `service_blackout_dates`, `bookings`, `quotation_requests`, `reviews` (denormalized `service_id`), `favorites` (auto-removed on deactivation/deletion) | Service definition, availability, usage, published review aggregates, and favorites |
| `product_categories` | `products` | Store taxonomy (V1: Chemicals, Tools, Accessories, PPE, Air Fresheners) |
| `products` | `product_images`, `cart_items`, `store_order_items`, `quotation_requests`, `stock_ledgers`, `purchase_order_items`, `stock_adjustments` | Product definition, commerce, and on-hand stock |
| `suppliers` | `purchase_orders` | Inventory procurement |
| `purchase_orders` | `purchase_order_items`, `goods_receipts` | PO lifecycle; never changes stock alone |
| `goods_receipts` | `goods_receipt_items`, `stock_ledgers` | Stock increase source |
| `carts` | `cart_items` | Cart composition |
| `bookings` | `booking_status_histories`, `reviews` (max one per booking) | Status audit; post-completion review |
| `quotation_requests` | `quotation_request_attachments`, `quotations` | Request lifecycle |
| `uploads` | `quotation_request_attachments`, `quotation_message_attachments` | Staged file references via `upload_id` FK (no metadata duplication) |
| `quotations` | `quotation_items` | Quote breakdown |
| `orders` | `order_items`, `order_status_histories` | Order composition and audit |
| `payments` | `payment_transactions`, `receipts` | Gateway transaction history and one successful-payment receipt |
| `admins` | `admin_permissions`, `audit_logs`; also appear as actors on histories/refunds | Admin operations |
| `permissions` | `admin_role_permissions`, `admin_permissions` | Hybrid RBAC catalog |

## 4.3 Many-to-Many

| Entity A | Entity B | Pivot | Description |
| --- | --- | --- | --- |
| assignable roles | `permissions` | `admin_role_permissions` | Role permission grants (Hybrid RBAC) |
| `admins` | `permissions` | `admin_permissions` | Direct admin permission grants (Hybrid RBAC) |

## 4.4 Polymorphic / Discriminated Relationships

| Source | Discriminator | Targets | Purpose |
| --- | --- | --- | --- |
| `payments` | `payable_type` + `payable_id` | Service Orders and Store Orders | Unified payment ledger |
| `quotation_requests` | `request_target_type` | `bookings` or `products` | Booking-origin service and optional product quotes |
| `notifications` | `reference_type` + `reference_id` | booking/order/quote/payment/etc. | Deep links |

> `reviews` is **not** polymorphic in V1: it references `bookings` directly (completed booking reviews only). Polymorphic review targets (product/order/service) may return in a future version.

## 4.5 Relationship Diagram (Logical)

```text
users ──┬── customer_profiles (1:1) ──┬── customer_addresses
        │                              ├── customer_payment_methods
        │                              ├── customer_notes
        │                              ├── bookings ──── booking_status_histories
        │                              │       └── services ──┬── service_modes
        │                              │                       └── service_coverage_cities
        │                              ├── quotation_requests ──┬── quotation_request_attachments
        │                              │                        └── quotations ──── quotation_items
        │                              ├── orders ──── order_items
        │                              ├── payments ──── payment_transactions / receipts
        │                              └── reviews
        ├── customer_devices
        ├── carts ──── cart_items ──── products
        ├── notifications
        └── authentication recovery/tokens

products  <── product_categories
products  <── stock_ledgers / stock_adjustments / purchase_order_items
suppliers ── purchase_orders ── purchase_order_items
purchase_orders ── goods_receipts ── goods_receipt_items
customer_profiles ── store_orders ── store_order_items
store_orders / goods_receipts ── stock_ledgers (polymorphic reference)
services  <── service_categories

admins ── role (enum) ── admin_role_permissions ── permissions
admins ── admin_permissions ── permissions
admins ── audit_logs (via AuditEvent)
```

---

# 5. Index Strategy

Indexes below are recommendations for common access paths. Exact index types depend on the chosen RDBMS.

## 5.1 Identity & Auth

| Table | Index | Purpose |
| --- | --- | --- |
| `users` | UNIQUE(`phone`) | Login lookup |
| `users` | UNIQUE(`email`) | Login/recovery |
| `users` | (`status`, `created_at`) | Account eligibility and admin customer lists |
| `customer_profiles` | UNIQUE(`user_id`) | Enforce one-to-one profile relationship |
| `customer_profiles` | UNIQUE(`customer_number`) | Customer public-reference lookup |
| `admins` | UNIQUE(`email`) | Admin login |
| `customer_devices` | (`user_id`, `is_active`) | Push fan-out |
| `customer_devices` | UNIQUE(`device_token`) | Token upsert |
| `password_reset_tokens` | (`subject_type`, `subject_id`, `expires_at`) | Recovery cleanup |
| `phone_otps` | (`phone`, `purpose`, `created_at`) | OTP lookup, cooldown and hourly-cap checks |
| `phone_otps` | (`expires_at`) | Scheduled pruning of expired rows |

## 5.2 Catalog

| Table | Index | Purpose |
| --- | --- | --- |
| `service_categories` | UNIQUE(`slug`) | SEO/API lookup |
| `services` | UNIQUE(`slug`) | Detail lookup |
| `services` | (`is_active`, `category_id`, `sort_order`) | Browse/filter |
| `service_modes` | (`service_id`, `mode`, `is_active`) | Supported service-mode lookup |
| `service_coverage_cities` | UNIQUE(`service_id`, `city`) | Service coverage validation |
| `product_categories` | UNIQUE(`slug`) | Lookup |
| `products` | UNIQUE(`slug`) | Detail lookup |
| `products` | UNIQUE(`sku`) | Inventory / catalog ops |
| `products` | (`is_active`, `category_id`, `sort_order`) | Store browse |
| `products` | (`current_stock`, `low_stock_threshold`) | Low-stock dashboard alerts |
| `products` | (`allow_optional_quotation`, `is_active`) | Quote-enabled products |
| `purchase_orders` | UNIQUE(`po_number`) | PO lookup |
| `purchase_orders` | (`status`, `supplier_id`, `created_at`) | Inventory ops lists |
| `goods_receipts` | UNIQUE(`receipt_number`) | Receipt lookup |
| `stock_ledgers` | (`product_id`, `created_at`) | Product movement history |
| `stock_ledgers` | (`reference_type`, `reference_id`) | Trace source document |
| `store_orders` | UNIQUE(`store_order_number`) | Store order lookup |
| `store_orders` | (`customer_profile_id`, `status`, `created_at`) | Customer order lists |

## 5.3 Cart / Order / Booking

| Table | Index | Purpose |
| --- | --- | --- |
| `carts` | (`user_id`, `status`) | Active cart |
| `cart_items` | UNIQUE(`cart_id`, `product_id`) | Line upsert |
| `bookings` | UNIQUE(`booking_number`) | Customer reference |
| `bookings` | (`customer_profile_id`, `created_at`) | Customer history |
| `bookings` | (`service_id`, `service_city`, `scheduled_start_at`, `status`) | Coverage and capacity checks |
| `bookings` | (`status`, `created_at`) | Admin queues |
| `orders` | UNIQUE(`order_number`) | Reference |
| `orders` | (`customer_profile_id`, `placed_at`) | Customer history |
| `orders` | (`status`, `placed_at`) | Fulfillment queues |
| `order_items` | (`order_id`) | Order detail |
| `order_items` | (`product_id`) | Product sales queries |

## 5.4 Quotation / Payment / Notification

| Table | Index | Purpose |
| --- | --- | --- |
| `quotation_requests` | UNIQUE(`request_number`) | Reference |
| `quotation_requests` | (`customer_profile_id`, `created_at`) | Customer history |
| `quotation_requests` | (`status`, `created_at`) | Admin queue |
| `quotation_requests` | (`request_target_type`, `booking_id`) | Booking-origin service request lookup |
| `quotation_requests` | (`request_target_type`, `product_id`) | Product request lookup |
| `quotations` | UNIQUE(`quotation_number`) | Reference |
| `quotations` | (`quotation_request_id`, `version_no`) | Version chain |
| `quotations` | (`status`, `valid_until`) | Expiry jobs |
| `payments` | UNIQUE(`payment_number`) | Reference |
| `payments` | UNIQUE(`idempotency_key`) | Safe retries |
| `payments` | (`payable_type`, `payable_id`) | Entity payment history |
| `payments` | (`customer_profile_id`, `created_at`) | Customer payments |
| `payments` | (`status`, `created_at`) | Finance queues |
| `payments` | (`provider`, `provider_reference`) | Webhook reconciliation |
| `notifications` | (`recipient_type`, `recipient_id`, `status`) | Inbox |
| `notifications` | (`recipient_type`, `recipient_id`, `channel`, `event_id`) UNIQUE | Dispatch idempotency |
| `archived_notifications` | (`archived_at`), (`recipient_type`, `recipient_id`) | Admin archive browse |

## 5.4A File Uploads

| Table | Index | Purpose |
| --- | --- | --- |
| `uploads` | UNIQUE(`uuid`) | Public identifier lookup |
| `uploads` | (`customer_profile_id`, `created_at`) | Owner-scoped access checks and listings |
| `uploads` | (`attached_at`, `expires_at`) | Scheduled cleanup of expired unattached uploads |

## 5.5 Reviews / Favorites / Settings / Audit

| Table | Index | Purpose |
| --- | --- | --- |
| `reviews` | UNIQUE(`booking_id`) | One review per completed booking |
| `reviews` | (`service_id`, `status`, `created_at`) | Public published listing per service; aggregate recalculation on publish/hide |
| `reviews` | (`customer_profile_id`, `created_at`) | Customer history |
| `reviews` | (`status`, `created_at`) | Admin moderation queue (pending first) |
| `favorites` | UNIQUE(`customer_profile_id`, `service_id`) | One favorite per customer per service; idempotent add; delete-by-service |
| `favorites` | (`customer_profile_id`, `created_at`) | Customer favorites list (newest first) |
| `favorites` | (`service_id`) | Automatic removal on service deactivation/deletion; `favorites_count` recalculation |
| `system_settings` | UNIQUE(`category`, `key`); INDEX(`category`) | Config lookup |
| `branches` | UNIQUE(`code`); INDEX(`status`); partial UNIQUE(`is_default`) WHERE `is_default = TRUE` | Branch lookup, single default |
| `audit_logs` | (`entity_type`, `entity_id`, `created_at`) | Entity audit trail |
| `audit_logs` | (`actor_type`, `actor_id`, `created_at`) | Actor activity |

## 5.6 Indexing Guidelines

- Prefer composite indexes matching real `WHERE` + `ORDER BY` patterns.
- Avoid over-indexing write-heavy tables (`payments`, `audit_logs`) beyond reconciliation needs.
- Use covering indexes only after query evidence from production-like loads.

---

# 6. Data Integrity Rules

## 6.1 Authentication Boundaries

1. Catalog browse may occur without a `users` or `customer_profiles` row.
2. Creating a **booking** requires an authenticated, active `users` record and its linked `customer_profiles` record; the booking itself is owned by `customer_profile_id`.
3. Placing an **order**, submitting a **quotation request**, and initiating **payment** requires an authenticated, active user and linked customer profile (SRS transactional restriction).
4. Suspended/deactivated users cannot create new transactional records.

## 6.2 Catalog Integrity

1. Every **product** must have a non-null `selling_price` (>= 0) and `cost_price` (>= 0).
2. Changing Selling Price never changes Cost Price.
3. Fixed-price purchase remains available even when `allow_optional_quotation = true`.
4. Inactive products/services are excluded from new carts/bookings/quote requests.
5. Historical order/booking/quote snapshots remain valid after catalog edits.
6. Every active service supports both Book Now and Request Quotation; the optional Starting From amount is not a final assessed price.
7. V1 Store categories are limited to Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, and Air Fresheners.

## 6.3 Stock & Pricing Integrity

1. At Store Order creation, re-validate Selling Price and available stock against `products`; reject overselling.
2. Persist confirmed Selling Price amounts on `order_items` snapshots.
3. Cart snapshots are indicative only.
4. Creating a Store Order never decreases stock.
5. Stock decreases only after Payment = `paid`; failed or cancelled payments leave stock unchanged.
6. Negative stock is never allowed; every movement leaves `quantity_after` >= 0.
7. Purchase Order alone never changes stock; Goods Receipt increases stock and writes Stock Ledger entries.
8. Manual adjustments require quantity + reason and write Stock Ledger entries.
9. Payment `amount` must equal server-calculated payable total at initiation.

## 6.4 Booking Integrity

1. Reject bookings overlapping service blackout windows.
2. Validate the requested city against the selected service's active `service_coverage_cities` row; V1 supports Mogadishu and Hargeisa.
3. Require `requested_date` and `requested_time_window` at creation. Confirmed schedule fields remain null until operations confirms a valid start/end range.
4. Enforce `min_lead_hours` and `max_concurrent_bookings` where configured.
5. Status transitions recorded in `booking_status_histories`.
6. Cancelation eligibility enforced before status change to `cancelled`.

## 6.5 Quotation Integrity

1. `request_target_type` must match populated origin FK (`booking_id` or `product_id`).
2. Product quote requests allowed only when product opts in.
3. Every active service supports a quotation request through its booking-origin path; no service pricing classification gates this action.
4. Only one accept-eligible quotation active per request.
5. Acceptance locked after `valid_until` or status outside `quotation_ready` / `under_discussion`.
6. Accepted **latest** quotation required before quotation-targeted payment. Never use Rejected status.

## 6.6 Payment Integrity

1. Idempotent payment and gateway-transaction processing is required.
2. Payment becomes Paid only after server-side gateway verification.
3. A Paid payment changes its originating Order from `pending_payment` to `confirmed`; Failed and Cancelled payments leave the Order unchanged.
4. Every successful payment creates exactly one receipt with public `RCPT-YYYY-######`.
5. Refunds are outside V1.

## 6.7 Notification Integrity

1. Notification persistence is best-effort relative to the business commit (emit after successful domain write).
2. Customers can access only their notifications.
3. Mark-read updates cannot alter unrelated rows.

## 6.8 Review Integrity

1. Reviews are tied to **completed bookings only** (V1); the booking must belong to the reviewing customer.
2. One review per completed booking — UNIQUE(`booking_id`); multiple completed bookings allow multiple reviews.
3. Rating range 1–5; comment, when provided, 10–1000 characters.
4. Reviews are created `pending`; only admin moderation transitions to `published` / `hidden`.
5. Customer edit/delete is allowed **only while `pending`** — `published` and `hidden` reviews are immutable to the customer.
6. Moderation via status without deleting audit value (prefer hide); admins never edit content or reply.
7. Cached `services.average_rating` / `services.reviews_count` are recalculated from `published` reviews on every publish/hide.
8. Reviews survive customer soft-deletion; public identity falls back to "Verified Customer".
9. Reverting a completed booking to a non-completed status automatically sets its review to `hidden`; reviews are never deleted automatically.
10. Deleting a `pending` review releases UNIQUE(`booking_id`) — the booking becomes reviewable again.

## 6.8A Favorites Integrity

1. Favorites target **services only** in V1; the referenced service must be active at add time.
2. One favorite per customer per service — UNIQUE(`customer_profile_id`, `service_id`); add and remove are both idempotent (removing a not-currently-favorited accessible service succeeds).
3. Only authenticated customers with **active** account status may add, remove, or list favorites.
4. Service deactivation or deletion **automatically removes** related favorites; favorites of unavailable services are never surfaced.
5. `services.favorites_count` is a cached aggregate maintained on add, remove, and automatic removal; it is never exposed in public catalog payloads.
6. Favorites never mutate booking, quotation, cart, checkout, or payment state.
7. Favorites rows are hard-deleted (no soft delete); no activity-log events are recorded.

## 6.9 Referential Integrity

1. Foreign keys enforced for all core relationships listed in §3.
2. Prefer restrict/soft-delete for master data referenced by commercial documents.
3. Hard delete of payments/orders/bookings is disallowed in normal operations.

---

# 7. Security Considerations

## 7.1 Sensitive Fields

| Data | Storage Guidance |
| --- | --- |
| Customer/admin passwords | One-way password hash only (`password_hash`) |
| Reset tokens | Hash only; short TTL |
| Payment card PAN/CVV | **Never store** |
| Provider secrets / API keys | `system_settings` with `is_sensitive=true`; encrypt at rest if supported; never send to mobile app |
| Admin notes | Internal-only; excluded from customer APIs |
| Webhook payloads | Store minimized/sanitized JSON |
| Device push tokens | Protect as personal device identifiers |

## 7.2 Password Storage

- Use a modern adaptive hashing algorithm (e.g., bcrypt/Argon2) via the application framework.
- Never log passwords or raw tokens.
- Credential change and reset flows invalidate prior recovery tokens.

## 7.3 Payment Security

- Minimize PCI scope: redirect/SDK tokenization with provider; store only `provider_reference`, amount, status.
- All payment state changes are server-authoritative.
- Idempotency keys prevent duplicate captures on retries.
- Refunds are admin-authorized and audited.

## 7.4 Customer Privacy

- Collect only data needed for commerce and service delivery (SRS NFR-030).
- Owner-scoped queries for bookings, orders, quotes, payments, notifications, reviews, addresses.
- Support account deactivation/deletion requests subject to legal retention of commercial records.
- Mask sensitive settings and limit PII in notifications.
- Audit admin access to customer profiles where required by policy.

## 7.5 Admin Security

- Separate `admins` identity from customer `users`.
- Hybrid RBAC (role permissions ∪ direct permissions) with least privilege; Super Admin implicit.
- Inactive admins rejected on authenticated admin requests.
- Session timeout and event-driven audit logging for money/status/catalog and Admin Module mutations.
- No privilege escalation path from mobile customer tokens to admin permissions.

## 7.6 Application-Level Controls

- TLS in transit for all clients.
- Server-side authorization on every protected resource.
- Input validation for all writes.
- Rate-limit authentication and payment initiation endpoints (implementation concern; supported by token/payment indexes).

---

# 8. Scalability

## 8.1 Growth Dimensions

| Dimension | Expected Growth | Database Response |
| --- | --- | --- |
| Customers | High | Indexed identity lookups; soft-delete/archive strategy |
| Catalog (products/services) | Medium | Category + active composite indexes; media in object storage |
| Orders / bookings / quotes | High | Number-based lookups; status queues indexed; paginated history |
| Payments | High | Idempotent unique keys; archive successful old rows to cold storage later if needed |
| Notifications | Very high | Per-recipient composite indexes; archive terminal (`read`/`failed`) rows to `archived_notifications`; retention via archive foundation |
| Audit logs | High write | Append-only; partition by time when volume requires |

## 8.2 Vertical & Horizontal Strategies

1. **Start monolithic DB** with clear module boundaries (matches SRS modular domains).
2. **Read replicas** for heavy catalog browse and customer history reads as traffic grows.
3. **Partition/archive** large append tables (`notifications` via `archived_notifications`, `audit_logs`, old `payments`) by month/year.
4. **Object storage** for media/attachments; database stores URLs/metadata only.
5. **Async workers** for push sending and webhook processing to keep OLTP writes lean.
6. **Avoid premature sharding**; if needed later, shard customer business data by `customer_profile_id`.

## 8.3 Extensibility Without Redesign

| Future Need | How Current Design Extends |
| --- | --- |
| Future equipment/products | Heavy cleaning equipment and machines are outside V1; when approved later, add categories/products without redesigning core product or inventory ledger tables; add `product_variants` later if needed |
| Future service expansion | Add catalog rows, `service_modes`, and coverage rows; new service modes/subtypes do not require a second service schema |
| Promotions/coupons | Add discount tables; keep order snapshot totals |
| Multi-currency | Already have `currency` fields; extend settings + FX tables later |
| Expanded service coverage | Add city coverage rows beyond V1 Mogadishu and Hargeisa; add location entities later only if operational granularity requires them |
| Recurring bookings | Add recurrence rules table linked to `bookings` |
| Workforce module | Add staff/technician/housekeeper assignment tables later; existing `service_modes.subtype` captures service demand only and does not create workforce identities |
| Delivery module | Add delivery/fulfillment entities later; store orders already retain fulfillment and address snapshots |
| Multi-vendor | Not supported; would require seller bounded contexts beyond this schema |

## 8.4 Scalability Success Criteria

The schema must support increasing customers, catalog size, and transaction volume while preserving:

- Fixed-price store checkout
- Optional product quotations
- Service booking and service quotations
- Authoritative payments
- Customer notifications
- Admin operability

without redesigning the core entity relationships defined in this document.

---

## Reports & Analytics — Database Design Notes

### No New Tables Required

The Reports & Analytics Module is a **read-only aggregation layer**. All report values are calculated at query time from existing system tables. No new database tables, columns, or relationships are introduced by this module.

### Source Tables for Each Report Category

| Report Category | Primary Source Table(s) | Key Columns / Aggregations |
| --- | --- | --- |
| Customer Reports | `users` + `customer_profiles` | Growth from `users.created_at`; customer profile metrics through the one-to-one join |
| Booking Reports | `bookings` | `COUNT(*)` by status, `AVG(completed_at - created_at)` for avg completion time |
| Quotation Reports | `quotation_requests` | `COUNT(*)` by status, `COUNT(accepted) / COUNT(total)` for conversion rate |
| Order Reports | `orders` | `COUNT(*)` by status, `AVG(total_amount)` for avg order value |
| Payment Reports | `payments` | `COUNT(*)` by status |
| Revenue Reports | `payments` (status = 'Confirmed') | `SUM(amount)` grouped by time period; `JOIN` with `order_items` and `services`/`products` for revenue by category |

### Date Range Filtering

All queries apply a `WHERE created_at BETWEEN :start_date AND :end_date` filter based on the user's selected date range. Trend comparisons use the same-length prior period (e.g., if "Last 7 Days" is selected, the trend compares to the 7 days before that).

### Role-Based Query Scoping

No database-level row filtering is needed for role-based access. The API layer determines which report endpoints a role can call:
- **Admin**: all endpoints
- **Sales**: `/reports/customers`, `/reports/bookings`, `/reports/quotations`, `/reports/orders`
- **Accountant**: `/reports/payments`, `/reports/revenue`

Unauthorized requests return `403 Forbidden` at the API level.

### User Preference Tables (Refinements)

Two lightweight tables are introduced for user-specific report preferences. These do **not** store business data — only UI convenience settings.

#### `saved_report_filters`

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Unique filter ID |
| `user_id` | BIGINT | FK → `users.id`, NOT NULL | Owner of the saved filter |
| `name` | VARCHAR(100) | NOT NULL | Display name (e.g., "Manager Monthly Review") |
| `filter_config` | JSON | NOT NULL | Serialised filter settings (date range, report category, etc.) |
| `created_at` | TIMESTAMP | NOT NULL | When the filter was saved |
| `updated_at` | TIMESTAMP | NOT NULL | Last modified |

#### `pinned_reports`

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Unique pin ID |
| `user_id` | BIGINT | FK → `users.id`, NOT NULL | Owner of the pin (Admin only) |
| `report_category` | VARCHAR(50) | NOT NULL | e.g., "revenue", "payment", "booking" |
| `pinned_at` | TIMESTAMP | NOT NULL | When the report was pinned |

**Notes:**
- Both tables are user-specific. A user can only see and manage their own records.
- `saved_report_filters` stores the filter as a JSON object for flexibility (date range type, custom dates, selected category).
- `pinned_reports` uses a simple category identifier — no complex relationships.
- These tables contain no business data and do not affect any existing entity tables.

### UI-Only / Derived Elements (No Database Impact)

| Element | Source |
| --- | --- |
| Global Report Search | Queries existing tables based on search terms; no new table needed |
| Empty State | UI-only rendering when query returns zero rows |
| Last Generated | Timestamp of the API response; can be derived from query execution time or a simple cache timestamp |
| Report Summary | Computed at query time from the same aggregation queries that power KPI cards |

### Performance Considerations

- Reports on large datasets should use indexed columns (`created_at`, `status`, `user_id`).
- Materialised views or caching may be added in future optimisation phases but are **not required** at this stage.
- No reporting-specific denormalised tables are introduced (except the two user-preference tables above).

---

## Settings Module — Database Design

### `system_settings` Table

A key-value store for all system configuration settings. This is the **only** settings table in the design. All values are stored as JSON (scalars are stored as JSON scalars, e.g. `"USD"`, `2`, `true`; complex values as JSON objects/arrays).

**Key naming convention:** every setting is addressed by a fully-qualified dotted key `category.key` (e.g., `company.name`, `currency.default`, `tax.rate`, `smtp.host`, `storage.max_upload_size`, `backup.retention_days`). The `category` column stores the namespace; the `key` column stores the segment after the dot. APIs and documentation always use the fully-qualified form.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Unique setting ID |
| `category` | VARCHAR(50) | NOT NULL, indexed | Setting category, in canonical order: company, branch, currency, tax, numbering, smtp, notifications, storage, localization, backup — plus service, store, payment, security |
| `key` | VARCHAR(100) | NOT NULL, UNIQUE with category | Key segment (e.g., `name` for `company.name`, `rate` for `tax.rate`) |
| `value` | JSON | NULLABLE | Setting value (JSON) |
| `default_value` | JSON | NULLABLE | Factory default value for Restore Defaults feature |
| `is_sensitive` | BOOLEAN | NOT NULL, default FALSE | Masked in UI, never returned by read APIs (e.g., `smtp.password`) |
| `updated_by` | BIGINT | FK → `admins.id`, NULLABLE | Last admin who modified this setting |
| `updated_at` | TIMESTAMP | NOT NULL | Last modification timestamp |
| `created_at` | TIMESTAMP | NOT NULL | Initial creation timestamp |

**Compound unique:** (`category`, `key`)

**Indexes:**
- UNIQUE (`category`, `key`) — primary lookup path
- INDEX (`category`) — category page loads ("all keys of one category")

### `branches` Table

Company branches. Branch data is relational (not JSON settings) because branches are structured business entities with statuses and lifecycle rules.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Branch ID |
| `code` | VARCHAR(10) | NOT NULL, UNIQUE | Short branch code (e.g., "MGQ", "HGA") |
| `name` | VARCHAR(100) | NOT NULL | Branch display name |
| `city` | VARCHAR(100) | NOT NULL | City the branch operates in |
| `status` | VARCHAR(20) | NOT NULL, CHECK IN (`ACTIVE`, `INACTIVE`, `COMING_SOON`) | Branch lifecycle status |
| `is_default` | BOOLEAN | NOT NULL, default FALSE | Default branch flag |
| `activated_at` | TIMESTAMP | NULLABLE | When the branch became `ACTIVE` |
| `activated_by` | BIGINT | FK → `admins.id`, NULLABLE | Super Admin who activated the branch |
| `created_at` | TIMESTAMP | NOT NULL | |
| `updated_at` | TIMESTAMP | NOT NULL | |

**Indexes:**
- UNIQUE (`code`)
- INDEX (`status`)
- Partial UNIQUE (`is_default`) WHERE `is_default = TRUE` — enforces exactly one default branch (PostgreSQL partial unique index)

**Constraints:**
- CHECK: `status IN ('ACTIVE', 'INACTIVE', 'COMING_SOON')`
- CHECK: `is_default = FALSE OR status = 'ACTIVE'` — a non-active branch can never be the default
- FK `activated_by` → `admins.id` (ON DELETE SET NULL)

**Relationships:**
- `branches.activated_by` N — 1 `admins`.
- No transactional tables reference `branches` in V1: all transactions belong to the Mogadishu branch by business rule, so no `branch_id` columns exist anywhere in this design.

**Seed Data:**

| code | name | city | status | is_default |
| --- | --- | --- | --- | --- |
| MGQ | Mogadishu | Mogadishu | ACTIVE | TRUE |
| HGA | Hargeisa | Hargeisa | COMING_SOON | FALSE |

**Business Rules (Current Version — V1):**
- Mogadishu (MGQ) is the only operational branch.
- All transactions belong to the Mogadishu branch.
- Hargeisa (HGA) is displayed as `COMING_SOON`.
- Hargeisa cannot participate in any transaction and cannot become the default branch.
- Only Super Admin may change a branch status to `ACTIVE` (future release); the action is audit-logged.
- Exactly one default branch exists at any time and must be `ACTIVE` (enforced by the partial unique index + CHECK constraint).
- Multi-branch support may be introduced in a future version without redesigning the module.

### `settings_audit_log` Table

Tracks every settings change for compliance and traceability.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Log entry ID |
| `category` | VARCHAR(50) | NOT NULL | Setting category |
| `key` | VARCHAR(100) | NOT NULL | Setting key |
| `old_value` | JSON | NULLABLE | Previous value (masked for sensitive keys) |
| `new_value` | JSON | NULLABLE | New value (masked for sensitive keys) |
| `changed_by` | BIGINT | FK → `admins.id`, NOT NULL | Admin who made the change |
| `changed_at` | TIMESTAMP | NOT NULL | When the change occurred |
| `ip_address` | VARCHAR(45) | NULLABLE | IP address of the change request |

**Indexes:** INDEX (`category`, `key`), INDEX (`changed_by`), INDEX (`changed_at`).

Branch status changes (e.g., future Hargeisa activation) are also recorded here under category `branch`, in addition to the operational `audit_logs` table (§3.10.2).

### `holidays` Table

Stores company holidays for Service Settings.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Holiday ID |
| `name` | VARCHAR(100) | NOT NULL | Holiday name (e.g., "Eid Al-Fitr") |
| `date` | DATE | NOT NULL | Holiday date |
| `is_active` | BOOLEAN | NOT NULL, default TRUE | Whether the holiday is currently active |
| `created_at` | TIMESTAMP | NOT NULL | Creation timestamp |
| `updated_at` | TIMESTAMP | NOT NULL | Last update timestamp |

### `notification_templates` Table

Authoritative schema is §3.8.1 (Sprint 12). Settings UI manages the same table via Admin Notification Template APIs (`notifications.manage`): `template_key`, `name`, `type`, `channel` (`in_app`|`email`|`sms`), base language body, status, variables, plus optional `notification_template_translations`.

### Settings Category → Key Mapping

Fully-qualified dotted keys (`category.key`), listed in the canonical category order:

| # | Category | Keys |
| --- | --- | --- |
| 1 | company | company.name, company.logo, company.email, company.phone, company.website, company.address, company.tax_id, company.business_hours_open, company.business_hours_close, company.facebook, company.instagram, company.whatsapp |
| 2 | branch | branch.default (default branch code, e.g. `"MGQ"`; mirrors `branches.is_default` — the `branches` table is authoritative) |
| 3 | currency | currency.default, currency.symbol, currency.decimal_places, currency.thousand_separator |
| 4 | tax | tax.default, tax.rate, tax.mode (`inclusive` / `exclusive`) |
| 5 | numbering | numbering.customer_prefix, numbering.booking_prefix, numbering.quotation_prefix, numbering.invoice_prefix, numbering.receipt_prefix, numbering.order_prefix, numbering.payment_prefix, numbering.auto_numbering |
| 6 | smtp | smtp.host, smtp.port, smtp.encryption (`none` / `ssl` / `tls`), smtp.username, smtp.password (`is_sensitive = TRUE`) |
| 7 | notifications | notifications.email, notifications.browser, notifications.booking_alerts, notifications.quotation_alerts, notifications.payment_alerts; templates managed via Notification Module tables/APIs (templates, translations, preferences, archive) |
| 8 | storage | storage.driver (`local` / `s3`), storage.max_upload_size, storage.allowed_file_types (JSON array) |
| 9 | localization | localization.language, localization.timezone, localization.date_format, localization.time_format |
| 10 | backup | backup.enabled, backup.retention_days, backup.last_run_at |
| — | service | service.booking_start_time, service.booking_end_time, service.working_days (JSON array), service.booking_availability, service.default_lead_time |
| — | store | store.default_delivery_fee, store.inventory_warning_level |
| — | payment | payment.enabled_methods (JSON array), payment.instructions |
| — | security | security.min_password_length, security.password_complexity, security.password_expiry, security.session_timeout, security.login_audit_enabled |

Audit Logs (category 11 in the canonical order) are stored in the `settings_audit_log` table, not as settings keys. Branch records live in the relational `branches` table; only the `branch.default` pointer is kept in `system_settings`.

### Design Notes

- **No historical impact:** Changing a setting value (e.g., tax percentage) does NOT retroactively modify existing orders or payments. Historical records retain their original values.
- **Numbering:** Changing a prefix only affects the next generated number. The next-number counter is maintained in `system_settings` (e.g., key `numbering.customer_next`, value `1843`).
- **Roles & Permissions and System Information** are not stored in `system_settings` — they are derived from application code (roles) and runtime environment (system info).
- **Product categories** for Store Settings are stored in the existing `product_categories` table (if present) or as a JSON array in `system_settings`.
- **Restore Defaults** uses the `default_value` column in `system_settings`. When Admin confirms a restore, the `value` column is set to `default_value` for all keys in the selected category, and each change is logged in `settings_audit_log`.
- **Unsaved Changes Protection** is a UI-only feature (dirty state tracking in the browser). No database changes needed.
- **Maintenance Mode** is a future feature with no database impact at this stage. When implemented, it may use a `system.maintenance_mode` key in `system_settings`.
- **JSON values:** All `system_settings.value` / `default_value` columns are JSON. Readers must not assume string scalars; booleans, numbers, arrays, and objects are stored natively.
- **Sensitive keys** (`smtp.password`): stored encrypted at the application layer, `is_sensitive = TRUE`, masked in `settings_audit_log`, and never returned by read APIs.
- **Currency/tax display rules:** `currency.*` and `tax.*` keys drive formatting and defaults for future documents only; stored monetary amounts and historical documents are never rewritten.
- **Backups** are stored on disk/object storage, not in the database. `system_settings` only tracks backup metadata (e.g., `backup.last_run_at`).

---

## Document Control

| Item | Value |
| --- | --- |
| **Source of truth for requirements** | `docs/02_SRS.md` |
| **This document** | `docs/03_Database_Design.md` |
| **Explicitly excluded** | SQL, migrations, Eloquent models, APIs, Flutter/UI |
| **Next recommended artifacts** | Domain state-machine detail; API specification; migration plan after approval |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Solution / Database Architect |  |  |  |
| Engineering Lead |  |  |  |
| Security Reviewer |  |  |  |

---

*End of Document — Fayadhowr Database Design Specification v1.0*
