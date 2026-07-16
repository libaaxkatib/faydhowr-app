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
| Customer reviews of completed experiences | |

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
| Public references | Unified unique codes: `CUS-YYYY-######`, `BK-YYYY-######`, `QT-YYYY-######`, `ORD-YYYY-######` (service orders), `STO-YYYY-######` (store orders), `PAY-YYYY-######`, `RCPT-YYYY-######`, `PO-YYYY-######`, `GR-YYYY-######`, `INV-YYYY-######`, `REF-YYYY-######` (stored in `*_number` fields in addition to internal `id`) |

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
| **User Management** | Mobile user identity, customer profiles, addresses, saved payment methods, devices, auth recovery; admin operators & Hybrid RBAC | `users`, `customer_profiles`, `customer_addresses`, `customer_payment_methods`, `customer_devices`, `password_reset_tokens`, `admins`, `permissions`, `admin_role_permissions`, `admin_permissions` |
| **Service Management** | Official services, modes, coverage, media, and schedule constraints | `service_categories`, `services`, `service_modes`, `service_coverage_cities`, `service_media`, `service_blackout_dates` |
| **Store Management** | Product catalog, categories, images, cart, store orders | `product_categories`, `products`, `product_images`, `product_price_tiers`, `carts`, `cart_items`, `store_orders`, `store_order_items`, `store_order_status_histories` |
| **Inventory Management** | Suppliers, purchase orders, goods receipts, stock ledger, adjustments, low-stock | `suppliers`, `purchase_orders`, `purchase_order_items`, `purchase_order_status_histories`, `goods_receipts`, `goods_receipt_items`, `stock_ledgers`, `stock_adjustments` |
| **Booking Management** | Customer-profile-owned service bookings and status history | `bookings`, `booking_status_histories` |
| **Quotation Management** | Quote requests, issued quote revisions, discussion messages, attachments, line items, timeline | `quotation_requests`, `quotation_request_attachments`, `quotations`, `quotation_items`, `quotation_messages`, `quotation_message_attachments`, `quotation_status_histories` |
| **Payment Management** | Unified payment lifecycle, gateway transactions, and receipts | `payments`, `payment_transactions`, `receipts` |
| **Order Management** | Store orders and line items | `orders`, `order_items`, `order_status_histories` |
| **Notification Management** | Templates, in-app notifications | `notification_templates`, `notifications` |
| **Review Management** | Customer reviews after eligible completed activity | `reviews` |
| **Settings** | System configuration and audit | `settings`, `audit_logs` |

## 2.2 Module Notes

### User Management

- **Users** are the only mobile authentication identities. Each mobile customer has one linked `customer_profiles` record for business and profile data.
- **Admins** exist solely for the web admin panel (SRS §14). They are separate from `users` and are not field employees, technicians, or drivers.
- Guests may browse catalogs without a `users` row; an authenticated active user is required before booking or ordering.

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

- Lightweight customer feedback after completed bookings/orders (or on purchased products/services).
- Does not introduce workforce rating of employees (employees are out of scope).

### Settings

- Key/value operational configuration (currency, feature flags, provider config references).
- Audit log for sensitive admin actions (SRS NFR-024).

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
| `customer_number` | VARCHAR(40) | R | Unique public reference `CUS-YYYY-######` (read-only to customers) |
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
- Suspended/deactivated users cannot create bookings, orders, or quotation requests.
- Guest browsing does not require a `users` row; authentication is enforced at transactional boundaries.

### 3.1.2 `customer_profiles`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_profiles` |
| **Purpose** | One-to-one customer business/profile data; not an authentication identity. |
| **Primary Key** | `id` |
| **Foreign Keys** | `user_id` → `users.id` (UNIQUE, required) |
| **Relationships** | 1:1 → `users`; 1:N → addresses, saved payment methods, internal customer notes, bookings, quotation requests, orders, payments, notifications, reviews |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | Surrogate PK |
| `user_id` | BIGINT UNSIGNED | R | Unique one-to-one link to `users` |
| `customer_number` | VARCHAR(40) | R | Unique public reference `CUS-YYYY-######`; read-only |
| `full_name` | VARCHAR(150) | R | Display name |
| `avatar_url` | VARCHAR(500) | O | Profile photo |
| `preferred_language` | VARCHAR(10) | R | `so` · `en` · `ar` |
| `notification_preferences` | JSON | O | Push/email and category toggles |
| `classification` | VARCHAR(30) | R | `lead` or `active_customer`; not an authentication role |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | R/O | Profile lifecycle and soft deletion |

#### Constraints and Rules

- Unique: `user_id`, `customer_number`.
- Check/enum: `preferred_language` in `so|en|ar`; `classification` in `lead|active_customer`.
- `customer_number` is generated by the system and is not customer-editable.
- `customer_profiles` cannot authenticate, own Sanctum tokens, or replace `users`.

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
| `state_region` | VARCHAR(100) | O | |
| `postal_code` | VARCHAR(30) | O | |
| `country_code` | CHAR(2) | O | ISO-ish code |
| `latitude` | DECIMAL(10,7) | O | |
| `longitude` | DECIMAL(10,7) | O | |
| `is_default` | BOOLEAN | R | Default false |
| `is_active` | BOOLEAN | R | Default true; unused addresses marked **Inactive** (never hard-deleted by customer) |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- FK cascade behavior: restrict preferred; **do not hard-delete** customer addresses from the customer app.
- At most one `is_default = true` among **active** addresses per customer profile.

#### Validation Rules

- Address required fields must be present when used for delivery/service booking.
- Customers may Add / Edit / Set Default / Mark Inactive — never permanently delete.
- Updating an address must not rewrite historical order/booking address snapshots.

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

- Visible only in Admin Panel customer profile context.
- Customers never see these notes.
- Does not rewrite commercial history or timeline events.

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
| **Purpose** | Optional files supporting a quote request. |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_request_id` → `quotation_requests.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `quotation_request_id` | BIGINT UNSIGNED | R | FK |
| `file_url` | VARCHAR(500) | R | |
| `file_name` | VARCHAR(255) | R | |
| `mime_type` | VARCHAR(100) | O | |
| `file_size_bytes` | INT | O | |
| `created_at` | TIMESTAMP | R | |

#### Validation Rules

- Allowed mime types and max size enforced by application policy.
- Supported customer upload formats:
  - **Images:** JPG, JPEG, PNG, WebP
  - **Videos:** MP4, MOV, WebM
  - **Documents:** PDF
- Customers may upload one or more files when requesting quotations.
- Supported quotation attachments include images, videos, and PDF documents.
- The table remains generic and stores all supported attachment types via existing columns (`file_url`, `file_name`, `mime_type`, `file_size_bytes`) without type-specific tables.

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

Additional images/videos/PDFs uploaded during Discuss Quotation (same allowed mime types as request attachments). Linked to `quotation_messages.id`.

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
| `code` | VARCHAR(100) | R | Unique event key |
| `channel` | VARCHAR(30) | R | `push`, `in_app`, `email`, `sms` |
| `title_template` | VARCHAR(255) | R | |
| `body_template` | TEXT | R | |
| `is_active` | BOOLEAN | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Templates must not include secret placeholders (card data, raw OTPs outside auth flows).

---

### 3.8.2 `notifications`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `notifications` |
| **Purpose** | Persisted in-app notifications for customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `user_id` → `users.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `user_id` | BIGINT UNSIGNED | R | FK |
| `template_code` | VARCHAR(100) | O | |
| `title` | VARCHAR(255) | R | Rendered |
| `body` | TEXT | R | Rendered |
| `event_type` | VARCHAR(50) | R | booking/order/quote/payment/account |
| `reference_type` | VARCHAR(30) | O | Related entity type |
| `reference_id` | BIGINT UNSIGNED | O | Related entity id |
| `is_read` | BOOLEAN | R | Default false |
| `read_at` | TIMESTAMP | O | |
| `push_status` | VARCHAR(30) | O | `pending`, `sent`, `failed`, `skipped` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- Users may only read their own notifications.
- Delivery failure must not roll back the business transaction that emitted the event.

#### Notes

- Deep link targets use `reference_type` + `reference_id`.

---

## 3.9 Review Management

### 3.9.1 `reviews`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `reviews` |
| **Purpose** | Customer ratings/comments after eligible completed store or service experiences. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_profile_id` → `customer_profiles.id`; optional `order_id` / `booking_id`; optional `product_id` / `service_id` |
| **Relationships** | N:1 customer profile; optionally linked to completed order/booking and catalog entity |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_profile_id` | BIGINT UNSIGNED | R | FK |
| `review_target_type` | VARCHAR(20) | R | `product`, `service`, `order`, `booking` |
| `product_id` | BIGINT UNSIGNED | O | |
| `service_id` | BIGINT UNSIGNED | O | |
| `order_id` | BIGINT UNSIGNED | O | |
| `booking_id` | BIGINT UNSIGNED | O | |
| `rating` | TINYINT | R | 1–5 |
| `title` | VARCHAR(150) | O | |
| `comment` | TEXT | O | |
| `status` | VARCHAR(20) | R | `pending`, `published`, `hidden` |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- `rating` between 1 and 5
- Unique review per customer profile per target entity (e.g., unique (`customer_profile_id`, `order_id`) when order review)

#### Validation Rules

- Only owning user may create review for their completed order/booking.
- No employee/technician review entity (workforce out of scope).
- Admin may hide abusive content via `status`.

#### Notes

- Included to support customer feedback; does not invent a separate social network module.

---

## 3.10 Settings

### 3.10.1 `settings`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `settings` |
| **Purpose** | System-wide configuration (currency, business info, feature flags, provider keys references). |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `key` | VARCHAR(100) | R | Unique |
| `value` | TEXT | R | Serialized/string value |
| `type` | VARCHAR(30) | R | `string`, `number`, `boolean`, `json` |
| `is_sensitive` | BOOLEAN | R | Mask in admin UI/logs |
| `updated_by_admin_id` | BIGINT UNSIGNED | O | FK logical |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Sensitive values (API secrets) never returned to mobile clients.
- Currency and tax/fee display rules centrally configurable (SRS NFR-070).

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
| `customer_profiles` | `customer_addresses`, `customer_payment_methods`, `customer_notes`, `bookings`, `quotation_requests`, `orders`, `payments`, `reviews` | Customer business/profile data and approved business-module ownership |
| `service_categories` | `services` | Category catalog |
| `services` | `service_modes`, `service_coverage_cities`, `service_media`, `service_blackout_dates`, `bookings`, `quotation_requests` | Service definition, availability, and usage |
| `product_categories` | `products` | Store taxonomy (V1: Chemicals, Tools, Accessories, PPE, Air Fresheners) |
| `products` | `product_images`, `cart_items`, `store_order_items`, `quotation_requests`, `stock_ledgers`, `purchase_order_items`, `stock_adjustments` | Product definition, commerce, and on-hand stock |
| `suppliers` | `purchase_orders` | Inventory procurement |
| `purchase_orders` | `purchase_order_items`, `goods_receipts` | PO lifecycle; never changes stock alone |
| `goods_receipts` | `goods_receipt_items`, `stock_ledgers` | Stock increase source |
| `carts` | `cart_items` | Cart composition |
| `bookings` | `booking_status_histories` | Status audit |
| `quotation_requests` | `quotation_request_attachments`, `quotations` | Request lifecycle |
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
| `reviews` | `review_target_type` | product/service/order/booking | Feedback targets |

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
| `notifications` | (`user_id`, `is_read`, `created_at`) | Inbox |
| `notifications` | (`reference_type`, `reference_id`) | Entity-related notices |

## 5.5 Reviews / Settings / Audit

| Table | Index | Purpose |
| --- | --- | --- |
| `reviews` | (`review_target_type`, `product_id`, `status`) | Product rating aggregate |
| `reviews` | (`customer_profile_id`, `created_at`) | Customer history |
| `settings` | UNIQUE(`key`) | Config lookup |
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

1. Reviews tied to eligible completed customer activity.
2. Rating range 1–5.
3. Moderation via status without deleting audit value (prefer hide).

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
| Provider secrets / API keys | `settings` with `is_sensitive=true`; encrypt at rest if supported; never send to mobile app |
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
| Notifications | Very high | Per-customer composite indexes; retention/purge policy for old read notifications |
| Audit logs | High write | Append-only; partition by time when volume requires |

## 8.2 Vertical & Horizontal Strategies

1. **Start monolithic DB** with clear module boundaries (matches SRS modular domains).
2. **Read replicas** for heavy catalog browse and customer history reads as traffic grows.
3. **Partition/archive** large append tables (`notifications`, `audit_logs`, old `payments`) by month/year.
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

A key-value store for all system configuration settings.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Unique setting ID |
| `category` | VARCHAR(50) | NOT NULL, indexed | Setting category (company, service, store, payment, notification, security, numbering, localization) |
| `key` | VARCHAR(100) | NOT NULL, UNIQUE with category | Setting key (e.g., "company_name", "booking_start_time") |
| `value` | TEXT | NULLABLE | Setting value (serialised as string; JSON for complex values) |
| `default_value` | TEXT | NULLABLE | Factory default value for Restore Defaults feature |
| `updated_by` | BIGINT | FK → `users.id`, NULLABLE | Last user who modified this setting |
| `updated_at` | TIMESTAMP | NOT NULL | Last modification timestamp |
| `created_at` | TIMESTAMP | NOT NULL | Initial creation timestamp |

**Compound unique:** (`category`, `key`)

### `settings_audit_log` Table

Tracks every settings change for compliance and traceability.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Log entry ID |
| `category` | VARCHAR(50) | NOT NULL | Setting category |
| `key` | VARCHAR(100) | NOT NULL | Setting key |
| `old_value` | TEXT | NULLABLE | Previous value |
| `new_value` | TEXT | NULLABLE | New value |
| `changed_by` | BIGINT | FK → `users.id`, NOT NULL | Admin who made the change |
| `changed_at` | TIMESTAMP | NOT NULL | When the change occurred |
| `ip_address` | VARCHAR(45) | NULLABLE | IP address of the change request |

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

Stores editable notification message templates.

| Column | Type | Constraints | Description |
| --- | --- | --- | --- |
| `id` | BIGINT | PK, auto-increment | Template ID |
| `template_key` | VARCHAR(50) | NOT NULL, UNIQUE | Template identifier (e.g., "booking_confirmation") |
| `title` | VARCHAR(100) | NOT NULL | Display name |
| `body` | TEXT | NOT NULL | Template body with placeholders ({customer_name}, {booking_id}, etc.) |
| `updated_by` | BIGINT | FK → `users.id`, NULLABLE | Last editor |
| `updated_at` | TIMESTAMP | NOT NULL | Last modification timestamp |

### Settings Category → Key Mapping

| Category | Sample Keys |
| --- | --- |
| company | company_name, email, phone, address, website, logo_url, business_hours_open, business_hours_close, facebook, instagram, whatsapp |
| service | booking_start_time, booking_end_time, working_days (JSON array), booking_availability, default_lead_time |
| store | default_delivery_fee, tax_percentage, inventory_warning_level |
| payment | enabled_methods (JSON array), currency, payment_instructions |
| notification | push_enabled, email_enabled, sms_enabled |
| security | min_password_length, password_complexity, password_expiry, session_timeout, login_audit_enabled |
| numbering | prefix_customer, prefix_booking, prefix_quotation, prefix_order, prefix_payment |
| localization | default_language, currency, timezone, date_format |

### Design Notes

- **No historical impact:** Changing a setting value (e.g., tax percentage) does NOT retroactively modify existing orders or payments. Historical records retain their original values.
- **Numbering:** Changing a prefix only affects the next generated number. The `next_number` counter is maintained in `system_settings` (e.g., key `next_customer_number`, value `1843`).
- **Roles & Permissions and System Information** are not stored in `system_settings` — they are derived from application code (roles) and runtime environment (system info).
- **Product categories** for Store Settings are stored in the existing `product_categories` table (if present) or as a JSON array in `system_settings`.
- **Restore Defaults** uses the `default_value` column in `system_settings`. When Admin confirms a restore, the `value` column is set to `default_value` for all keys in the selected category, and each change is logged in `settings_audit_log`.
- **Unsaved Changes Protection** is a UI-only feature (dirty state tracking in the browser). No database changes needed.
- **Maintenance Mode** is a future feature with no database impact at this stage. When implemented, it may use a `maintenance_mode` key in `system_settings`.

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
