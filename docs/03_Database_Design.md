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
| In Scope (v1) | Out of Scope (v1) |
| --- | --- |
| Customer accounts and profiles | Employee / technician / driver workforce tables |
| Admin panel operator accounts (RBAC) | Multi-vendor seller portals |
| Services, store, bookings, quotations, orders, payments, notifications | Multi-tenant SaaS tenancy |
| Optional product quotations and service quotations | Marketplace / partner inventory |
| Discuss Quotation messaging + additional file uploads on the **same** quotation | Multi-option parallel quote packages / e-signature |
| Customer reviews of completed experiences | |

**Business rules reflected in this design:**

- The mobile application is **customer-only**; employees are not modeled in this version.
- Store products **always display prices**; fixed-price purchase is the default path.
- Customers may **optionally** request quotations for products (bulk, custom quantity, special requests).
- Services may be **fixed-price bookable** or **quotation-required**.
- **Browse/catalog access** may occur without login; **authentication is required** to create a booking or place an order (and for related transactional actions such as quotation requests and payments, consistent with the SRS).

---

# 1. Database Overview

## 1.1 Architecture

Fayadhowr uses a **single relational database** as the system of record for:

- Customer identity and profile data
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
| **Least privilege data** | Customers own only their rows; admins operate through RBAC; no employee workforce schema in v1. |
| **PCI minimization** | No full card PAN/CVV storage; only provider references and internal payment status. |

## 1.3 Naming Conventions

| Convention | Rule |
| --- | --- |
| Table names | `snake_case`, plural (`customers`, `order_items`) |
| Primary keys | `id` (unsigned big integer, surrogate) |
| Foreign keys | `{referenced_table_singular}_id` (e.g., `customer_id`) |
| Timestamps | `created_at`, `updated_at`; soft delete via `deleted_at` where noted |
| Money | `DECIMAL(12,2)` (or equivalent) with explicit `currency` where amounts are stored |
| Status fields | Constrained string/enumeration values documented per table |
| Public references | Unified unique codes: `CUS-YYYY-######`, `BK-YYYY-######`, `QT-YYYY-######`, `ORD-YYYY-######`, `PAY-YYYY-######`, `INV-YYYY-######`, `REF-YYYY-######` (stored in `*_number` fields in addition to internal `id`) |

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
| **User Management** | Customers, addresses, saved payment methods, devices, auth recovery; admin operators & roles | `customers`, `customer_addresses`, `customer_payment_methods`, `customer_devices`, `password_reset_tokens`, `admins`, `roles`, `admin_role`, `permissions`, `role_permission` |
| **Service Management** | Service categories, services, media, schedule constraints | `service_categories`, `services`, `service_media`, `service_blackout_dates` |
| **Store Management** | Product categories, products, media, cart | `product_categories`, `products`, `product_media`, `carts`, `cart_items` |
| **Booking Management** | Service bookings and status history | `bookings`, `booking_status_histories` |
| **Quotation Management** | Quote requests, issued quote revisions, discussion messages, attachments, line items, timeline | `quotation_requests`, `quotation_request_attachments`, `quotations`, `quotation_items`, `quotation_messages`, `quotation_message_attachments`, `quotation_status_histories` |
| **Payment Management** | Payment attempts, refunds | `payments`, `payment_refunds` |
| **Order Management** | Store orders and line items | `orders`, `order_items`, `order_status_histories` |
| **Notification Management** | Templates, in-app notifications | `notification_templates`, `notifications` |
| **Review Management** | Customer reviews after eligible completed activity | `reviews` |
| **Settings** | System configuration and audit | `settings`, `audit_logs` |

## 2.2 Module Notes

### User Management

- **Customers** are the only mobile end users.
- **Admins** exist solely for the web admin panel (SRS §14). They are not field employees, technicians, or drivers.
- Guests may browse catalogs without a `customers` row; a customer record is required before booking or ordering.

### Service Management

- Each service declares a **pricing model**: fixed-price bookable, quotation-based, or hybrid (per SRS §7).
- Inactive services remain in history but reject new bookings/quote requests.

### Store Management

- Every product has a **display price**.
- Normal path: cart → order → payment.
- Optional path: product-related **quotation request** (does not replace fixed-price purchase).

### Booking / Quotation / Order / Payment

- Aligns to SRS workflows §§9–11.
- Payments attach to exactly one payable entity type: `order`, `booking`, or `quotation`.

### Review Management

- Lightweight customer feedback after completed bookings/orders (or on purchased products/services).
- Does not introduce workforce rating of employees (employees are out of scope).

### Settings

- Key/value operational configuration (currency, feature flags, provider config references).
- Audit log for sensitive admin actions (SRS NFR-024).

---

# 3. Table Specifications

Legend for **Required**: `R` = required (NOT NULL), `O` = optional (NULL allowed).

---

## 3.1 User Management

### 3.1.1 `customers`

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
- Check/enum: `status` in defined set; `preferred_language` in `so|en|ar`

#### Validation Rules

- Phone format validated at application layer for target market.
- Password stored only as strong one-way hash (never plaintext).
- Suspended/deactivated customers cannot create bookings, orders, or quotation requests.

#### Notes

- Guest browsing does not require a row here.
- Login is enforced at booking/order (and related transactional) boundaries.

---

### 3.1.2 `customer_addresses`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_addresses` |
| **Purpose** | Saved delivery/service locations for a customer. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id` |
| **Relationships** | N:1 → `customers` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | R | FK |
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
- At most one `is_default = true` among **active** addresses per customer.

#### Validation Rules

- Address required fields must be present when used for delivery/service booking.
- Customers may Add / Edit / Set Default / Mark Inactive — never permanently delete.
- Updating an address must not rewrite historical order/booking address snapshots.

#### Notes

- Orders/bookings should copy address text into snapshot fields at confirmation time.

---

### 3.1.3 `customer_payment_methods`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_payment_methods` |
| **Purpose** | Saved checkout instruments for a customer (separate from immutable `payments` history). |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id` |
| **Relationships** | N:1 → `customers` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | R | FK |
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

### 3.1.4 `customer_devices`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `customer_devices` |
| **Purpose** | Push notification device tokens for customers. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id` |
| **Relationships** | N:1 → `customers` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | R | FK |
| `platform` | VARCHAR(20) | R | `ios`, `android` |
| `device_token` | VARCHAR(255) | R | Push token |
| `app_version` | VARCHAR(30) | O | |
| `is_active` | BOOLEAN | R | |
| `last_seen_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique: (`customer_id`, `device_token`) or globally unique `device_token`

#### Validation Rules

- Inactive/invalid tokens disabled rather than failing business transactions.

#### Notes

- Supports SRS push notification channel.

---

### 3.1.5 `password_reset_tokens`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `password_reset_tokens` |
| **Purpose** | Credential recovery tokens for customers (and optionally admins if co-located by design). |
| **Primary Key** | Composite or `id` |
| **Foreign Keys** | Logical link via email/phone (implementation choice) |
| **Relationships** | Associated to `customers` by identifier |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK (recommended) |
| `subject_type` | VARCHAR(30) | R | `customer` (v1) |
| `subject_id` | BIGINT UNSIGNED | R | Customer id |
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

### 3.1.6 `admins`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `admins` |
| **Purpose** | Admin panel operators (not mobile customers; not field employees). |
| **Primary Key** | `id` |
| **Foreign Keys** | None directly (roles via pivot) |
| **Relationships** | M:N → `roles` via `admin_role` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `full_name` | VARCHAR(150) | R | |
| `email` | VARCHAR(191) | R | Unique login |
| `password_hash` | VARCHAR(255) | R | |
| `status` | VARCHAR(30) | R | `active`, `suspended` |
| `last_login_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

#### Constraints

- Unique `email`
- Status constrained

#### Validation Rules

- Admins cannot authenticate via customer mobile APIs as customers.
- Privilege separation mandatory (SRS §4.2).

#### Notes

- **Employees / technicians are not modeled.** Assignment of field staff is out of scope for v1.

---

### 3.1.7 `roles`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `roles` |
| **Purpose** | Admin RBAC roles (Super, Operations, Catalog, Finance, Support). |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `name` | VARCHAR(100) | R | Unique |
| `slug` | VARCHAR(100) | R | Unique machine key |
| `description` | VARCHAR(255) | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- Slug unique; system roles protected from accidental deletion.

---

### 3.1.8 `permissions`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `permissions` |
| **Purpose** | Fine-grained admin permissions. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `name` | VARCHAR(100) | R | |
| `slug` | VARCHAR(100) | R | Unique |
| `module` | VARCHAR(50) | R | e.g., bookings, catalog |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.1.9 `admin_role` (pivot)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `admin_role` |
| **Purpose** | Many-to-many: admins ↔ roles |
| **Primary Key** | (`admin_id`, `role_id`) or surrogate `id` |
| **Foreign Keys** | `admin_id` → `admins.id`, `role_id` → `roles.id` |

#### Columns

| Column | Data Type | Required |
| --- | --- | --- |
| `admin_id` | BIGINT UNSIGNED | R |
| `role_id` | BIGINT UNSIGNED | R |
| `created_at` | TIMESTAMP | R |

---

### 3.1.10 `role_permission` (pivot)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `role_permission` |
| **Purpose** | Many-to-many: roles ↔ permissions |
| **Primary Key** | (`role_id`, `permission_id`) |
| **Foreign Keys** | `role_id` → `roles.id`, `permission_id` → `permissions.id` |

#### Columns

| Column | Data Type | Required |
| --- | --- | --- |
| `role_id` | BIGINT UNSIGNED | R |
| `permission_id` | BIGINT UNSIGNED | R |
| `created_at` | TIMESTAMP | R |

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
| **Purpose** | Bookable / quotation-based service catalog items. |
| **Primary Key** | `id` |
| **Foreign Keys** | `category_id` → `service_categories.id` |
| **Relationships** | N:1 category; 1:N media, bookings, quotation_requests, blackout dates |

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
| `pricing_model` | VARCHAR(30) | R | `fixed`, `quotation`, `hybrid` |
| `base_price` | DECIMAL(12,2) | O | Required when `fixed` or for hybrid deposit display |
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

- `pricing_model` constrained to allowed values
- If `pricing_model = fixed`, `base_price` must be present and >= 0
- Inactive services cannot accept new bookings/quote requests

#### Validation Rules

- Pricing model must be declared before customer visibility (SRS §7.4).
- Historical bookings remain readable after deactivation.

#### Notes

- Quotation-based services use Quotation Management before payment.
- Hybrid may combine booking + later quotation per configured policy.

---

### 3.2.3 `service_media`

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

### 3.2.4 `service_blackout_dates`

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
| **Purpose** | Store catalog categories. |
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
| **Purpose** | Sellable store products with **always-visible prices**. |
| **Primary Key** | `id` |
| **Foreign Keys** | `category_id` → `product_categories.id` |
| **Relationships** | 1:N media, cart_items, order_items; optional quotation_requests |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `category_id` | BIGINT UNSIGNED | R | FK |
| `name` | VARCHAR(200) | R | |
| `slug` | VARCHAR(220) | R | Unique |
| `sku` | VARCHAR(64) | R | Unique inventory SKU (e.g. `CLN-000245`) |
| `short_description` | VARCHAR(500) | O | |
| `description` | TEXT | O | |
| `price` | DECIMAL(12,2) | R | Base unit price — **always displayed** when no active tier applies |
| `currency` | CHAR(3) | R | |
| `unit` | VARCHAR(30) | R | Selling unit: `piece`, `pack`, `box`, `carton`, `bottle`, `liter`, `kg` (display with price, e.g. `12.00 / Bottle`) |
| `availability_status` | VARCHAR(30) | R | `in_stock`, `low_stock`, `out_of_stock`, `available_on_request` |
| `stock_qty` | INT | R | Inventory quantity when tracking |
| `track_stock` | BOOLEAN | R | If false, stock qty checks may be skipped; status still required |
| `badge` | VARCHAR(30) | O | Optional marketing badge: `new`, `best_seller`, `popular`, `limited_stock` |
| `has_tier_pricing` | BOOLEAN | R | When true, use `product_price_tiers` for quantity bands |
| `allow_optional_quotation` | BOOLEAN | R | Enables optional product quote via **shared** Quotation Module |
| `is_active` | BOOLEAN | R | |
| `sort_order` | INT | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |
| `deleted_at` | TIMESTAMP | O | |

#### Constraints

- `price` >= 0 and NOT NULL (store product pricing rule)
- Unique `slug`; unique `sku` (required)
- Purchases blocked when `availability_status = out_of_stock` (and when `track_stock=true` and `stock_qty <= 0`)

#### Validation Rules

- Customers can browse, view price (+ unit), add to cart, and purchase at fixed or tier price.
- Optional quotation for bulk/custom/special requests uses the **same** Quotation Module as services (`source = product`); does **not** hide `price`.
- Checkout re-validates price (including applicable tier), availability, and stock at confirmation time.

#### Notes

- Gallery images live in `product_media` (ordered); client supports swipe + pagination + future zoom.
- Variants at scale remain a future item; v1 keeps one sellable row per product with optional tier bands.

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

If a product has only one fixed price (`has_tier_pricing=false`), show the single price. If tier pricing is enabled, display all tiers and apply the matching band to cart/checkout.

---

### 3.3.3 `product_media`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `product_media` |
| **Purpose** | Product images/media. |
| **Primary Key** | `id` |
| **Foreign Keys** | `product_id` → `products.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `product_id` | BIGINT UNSIGNED | R | FK |
| `media_type` | VARCHAR(30) | R | |
| `url` | VARCHAR(500) | R | |
| `alt_text` | VARCHAR(255) | O | |
| `sort_order` | INT | R | |
| `is_primary` | BOOLEAN | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

---

### 3.3.4 `carts`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `carts` |
| **Purpose** | Shopping cart header. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id` (required at checkout; may be set when user logs in) |
| **Relationships** | 1:N `cart_items` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | O | Required before placing order |
| `session_token` | VARCHAR(100) | O | Optional guest cart bridge |
| `status` | VARCHAR(20) | R | `active`, `converted`, `abandoned` |
| `currency` | CHAR(3) | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints / Validation

- Placing an order requires authenticated `customer_id` (login required to place order).
- Guest may build a cart only if product policy allows; conversion requires login.

#### Notes

- Cart unit prices are indicative until checkout confirmation.

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
| `unit_price_snapshot` | DECIMAL(12,2) | R | Last seen price |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique (`cart_id`, `product_id`) recommended
- `quantity` > 0

---

## 3.4 Booking Management

### 3.4.1 `bookings`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `bookings` |
| **Purpose** | Customer service booking records. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id`, `service_id` → `services.id`, optional `quotation_id` → `quotations.id` |
| **Relationships** | N:1 customer/service; 1:N status histories; optional link to accepted quotation; payments via polymorphic payable |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `booking_number` | VARCHAR(40) | R | Unique public reference |
| `customer_id` | BIGINT UNSIGNED | R | FK — **login required** |
| `service_id` | BIGINT UNSIGNED | R | FK |
| `quotation_id` | BIGINT UNSIGNED | O | When booking follows accepted quote |
| `status` | VARCHAR(30) | R | See SRS booking statuses |
| `scheduled_start_at` | TIMESTAMP | O | Requested/confirmed slot start |
| `scheduled_end_at` | TIMESTAMP | O | |
| `service_name_snapshot` | VARCHAR(200) | R | Immutable commercial snapshot |
| `pricing_model_snapshot` | VARCHAR(30) | R | |
| `quoted_or_base_amount` | DECIMAL(12,2) | O | Amount due context |
| `currency` | CHAR(3) | R | |
| `address_snapshot` | JSON/TEXT | O | Copied address |
| `customer_notes` | TEXT | O | |
| `admin_notes` | TEXT | O | Internal only |
| `cancellation_reason` | VARCHAR(255) | O | |
| `cancelled_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `booking_number`
- `customer_id` NOT NULL
- Status in: `requested`, `confirmed`, `in_progress`, `awaiting_payment`, `paid`, `completed`, `cancelled`, `rejected`

#### Validation Rules

- Customer must be authenticated and `active`.
- Service must be `is_active` and bookable under its pricing model rules.
- Capacity / blackout / lead-time checks enforced before insert/update of schedule.
- Customer cancel only when policy allows.

#### Notes

- Draft status is client-side only and not persisted as a server booking (SRS §9.6).

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
| `changed_by_type` | VARCHAR(20) | R | `customer`, `admin`, `system` |
| `changed_by_id` | BIGINT UNSIGNED | O | |
| `note` | VARCHAR(255) | O | |
| `created_at` | TIMESTAMP | R | |

---

## 3.5 Quotation Management

Supports:

1. **Service quotations** (required path for quotation-based services)
2. **Optional product quotations** (bulk/custom/special; does not replace fixed-price purchase)

### 3.5.1 `quotation_requests`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_requests` |
| **Purpose** | Customer-submitted request for a formal quote. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id`; optional `service_id` / `product_id` |
| **Relationships** | 1:N attachments, quotations |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `request_number` | VARCHAR(40) | R | Unique public reference; may match or link to canonical `QT-YYYY-######` once quotation family is created |
| `customer_id` | BIGINT UNSIGNED | R | FK — authenticated |
| `request_target_type` | VARCHAR(20) | R | Quotation **source**: `service` or `product` (business/admin use; not a customer-facing field unless needed) |
| `service_id` | BIGINT UNSIGNED | O | Required if target=service |
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

- Exactly one of `service_id` / `product_id` populated according to `request_target_type`
- For `product` target: product should allow optional quotation (`allow_optional_quotation=true`)
- For `service` target: service pricing model should allow quotation (`quotation` or `hybrid`)

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
- Remove any `rejected_at` column / `rejected` status from V1 implementations.

---

### 3.5.3A `quotation_messages` (Discuss Quotation)

| Attribute | Detail |
| --- | --- |
| **Table Name** | `quotation_messages` |
| **Purpose** | Discussion thread attached to a quotation (same Quotation Number). |
| **Primary Key** | `id` |
| **Foreign Keys** | `quotation_number` / `quotation_request_id`; optional `customer_id` or `admin_id` as author |

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
| **Purpose** | Authoritative payment attempts/results for payable entities. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id`; polymorphic payable reference |
| **Relationships** | N:1 customer; 1:N refunds |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `payment_number` | VARCHAR(40) | R | Unique internal/public reference |
| `customer_id` | BIGINT UNSIGNED | R | FK |
| `payable_type` | VARCHAR(30) | R | `order`, `booking`, `quotation` |
| `payable_id` | BIGINT UNSIGNED | R | Target entity id |
| `amount` | DECIMAL(12,2) | R | Charged amount |
| `currency` | CHAR(3) | R | |
| `status` | VARCHAR(30) | R | `pending`, `successful`, `failed`, `cancelled`, `refunded`, `partially_refunded` |
| `provider` | VARCHAR(50) | O | Gateway name |
| `provider_reference` | VARCHAR(191) | O | External id |
| `idempotency_key` | VARCHAR(100) | R | Unique for safe retries |
| `failure_code` | VARCHAR(50) | O | |
| `failure_message` | VARCHAR(255) | O | Safe customer/ops message |
| `paid_at` | TIMESTAMP | O | |
| `raw_provider_payload` | JSON | O | Minimal, sanitized webhook snapshot |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `payment_number`
- Unique `idempotency_key`
- Unique (`provider`, `provider_reference`) when provider reference present
- `amount` > 0

#### Validation Rules

- Amount must match backend-calculated payable amount at initiation.
- Client cannot mark payment successful; only server verification/webhooks.
- Webhook handling must be idempotent.
- Quotation payments allowed only when quotation status is `accepted` (unless approved deposit exception — not default).

#### Notes

- No PAN/CVV/full card data stored.
- Abandoned pending payments expire per policy.

---

### 3.6.2 `payment_refunds`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `payment_refunds` |
| **Purpose** | Refund records against successful payments. |
| **Primary Key** | `id` |
| **Foreign Keys** | `payment_id` → `payments.id`, `processed_by_admin_id` → `admins.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `payment_id` | BIGINT UNSIGNED | R | FK |
| `amount` | DECIMAL(12,2) | R | |
| `currency` | CHAR(3) | R | |
| `status` | VARCHAR(30) | R | `pending`, `successful`, `failed` |
| `reason` | VARCHAR(255) | O | |
| `provider_reference` | VARCHAR(191) | O | |
| `processed_by_admin_id` | BIGINT UNSIGNED | O | FK |
| `processed_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Refund total cannot exceed original successful payment amount.
- Parent payment status updated to `refunded` or `partially_refunded`.

---

## 3.7 Order Management

### 3.7.1 `orders`

| Attribute | Detail |
| --- | --- |
| **Table Name** | `orders` |
| **Purpose** | Store purchase transactions. |
| **Primary Key** | `id` |
| **Foreign Keys** | `customer_id` → `customers.id`, optional `cart_id`, optional `quotation_id` |
| **Relationships** | 1:N `order_items`, status histories; payments via payable link |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `order_number` | VARCHAR(40) | R | Unique |
| `customer_id` | BIGINT UNSIGNED | R | FK — **login required to place order** |
| `cart_id` | BIGINT UNSIGNED | O | Source cart |
| `quotation_id` | BIGINT UNSIGNED | O | If order fulfills accepted product quote |
| `status` | VARCHAR(30) | R | `pending_payment`, `paid`, `processing`, `shipped_or_ready`, `completed`, `cancelled` |
| `currency` | CHAR(3) | R | |
| `subtotal_amount` | DECIMAL(12,2) | R | |
| `tax_amount` | DECIMAL(12,2) | R | Default 0 |
| `shipping_amount` | DECIMAL(12,2) | R | Default 0 |
| `total_amount` | DECIMAL(12,2) | R | |
| `fulfillment_type` | VARCHAR(30) | O | `delivery`, `pickup` |
| `shipping_address_snapshot` | JSON/TEXT | O | Immutable |
| `customer_notes` | TEXT | O | |
| `admin_notes` | TEXT | O | |
| `placed_at` | TIMESTAMP | R | |
| `cancelled_at` | TIMESTAMP | O | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Constraints

- Unique `order_number`
- `customer_id` required
- Totals must equal sum of item snapshots + tax + shipping (policy)

#### Validation Rules

- Stock and price re-validated at placement.
- Suspended customers cannot place orders.
- Store orders remain commercially separate from service bookings unless future bundle offering is approved.

#### Notes

- Normal fixed-price path does not require `quotation_id`.

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
| `unit_price_snapshot` | DECIMAL(12,2) | R | Confirmed price |
| `quantity` | INT | R | > 0 |
| `line_total_snapshot` | DECIMAL(12,2) | R | |
| `created_at` | TIMESTAMP | R | |
| `updated_at` | TIMESTAMP | R | |

#### Validation Rules

- Snapshots must not change after order placement except via formal correction/audit process.

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
| **Foreign Keys** | `customer_id` → `customers.id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | R | FK |
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

- Customers may only read their own notifications.
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
| **Foreign Keys** | `customer_id` → `customers.id`; optional `order_id` / `booking_id`; optional `product_id` / `service_id` |
| **Relationships** | N:1 customer; optionally linked to completed order/booking and catalog entity |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `customer_id` | BIGINT UNSIGNED | R | FK |
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
- Unique review per customer per target entity (e.g., unique (`customer_id`, `order_id`) when order review)

#### Validation Rules

- Only owning customer may create review for their completed order/booking.
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
| **Purpose** | Tamper-evident operational audit for sensitive admin/system actions. |
| **Primary Key** | `id` |

#### Columns

| Column | Data Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | BIGINT UNSIGNED | R | PK |
| `actor_type` | VARCHAR(20) | R | `admin`, `system` |
| `actor_id` | BIGINT UNSIGNED | O | |
| `action` | VARCHAR(100) | R | e.g., `payment.refund`, `booking.status_change` |
| `entity_type` | VARCHAR(50) | O | |
| `entity_id` | BIGINT UNSIGNED | O | |
| `before_data` | JSON | O | |
| `after_data` | JSON | O | |
| `ip_address` | VARCHAR(45) | O | |
| `created_at` | TIMESTAMP | R | |

#### Validation Rules

- Append-oriented; no customer update of audit rows.
- Required for money/status/catalog-critical admin actions (SRS NFR-024).

---

# 4. Relationships

## 4.1 One-to-One

| Parent | Child | Description |
| --- | --- | --- |
| `quotation_requests` | Current accept-eligible `quotations` row | Logical 1:1 “active quote” via status/version rules (multiple historical versions may exist) |
| `carts` | Converted `orders` | A cart may convert to at most one order (`status=converted`) |

> Strict physical 1:1 tables are minimized; commercial “current” relationships are enforced by status rules.

## 4.2 One-to-Many

| Parent | Children | Description |
| --- | --- | --- |
| `customers` | `customer_addresses`, `customer_payment_methods`, `customer_devices`, `carts`, `bookings`, `quotation_requests`, `orders`, `payments`, `notifications`, `reviews` | Customer owns transactional and profile data |
| `service_categories` | `services` | Category catalog |
| `services` | `service_media`, `service_blackout_dates`, `bookings`, `quotation_requests` | Service definition and usage |
| `product_categories` | `products` | Store taxonomy |
| `products` | `product_media`, `cart_items`, `order_items`, `quotation_requests` | Product definition and commerce |
| `carts` | `cart_items` | Cart composition |
| `bookings` | `booking_status_histories` | Status audit |
| `quotation_requests` | `quotation_request_attachments`, `quotations` | Request lifecycle |
| `quotations` | `quotation_items` | Quote breakdown |
| `orders` | `order_items`, `order_status_histories` | Order composition and audit |
| `payments` | `payment_refunds` | Refund history |
| `admins` | (via pivots) roles; also appear as actors on histories/refunds/audit | Admin operations |
| `roles` | (via pivots) permissions | RBAC |

## 4.3 Many-to-Many

| Entity A | Entity B | Pivot | Description |
| --- | --- | --- | --- |
| `admins` | `roles` | `admin_role` | Admin panel RBAC |
| `roles` | `permissions` | `role_permission` | Permission grants |

## 4.4 Polymorphic / Discriminated Relationships

| Source | Discriminator | Targets | Purpose |
| --- | --- | --- | --- |
| `payments` | `payable_type` + `payable_id` | `orders`, `bookings`, `quotations` | Single payment ledger |
| `quotation_requests` | `request_target_type` | `services` or `products` | Service & optional product quotes |
| `notifications` | `reference_type` + `reference_id` | booking/order/quote/payment/etc. | Deep links |
| `reviews` | `review_target_type` | product/service/order/booking | Feedback targets |

## 4.5 Relationship Diagram (Logical)

```text
customers ──┬── customer_addresses
            ├── customer_devices
            ├── carts ──── cart_items ──── products
            ├── bookings ──── booking_status_histories
            │       └── services
            ├── quotation_requests ──┬── quotation_request_attachments
            │                        └── quotations ──── quotation_items
            ├── orders ──── order_items
            ├── payments ──── payment_refunds
            ├── notifications
            └── reviews

products  <── product_categories
services  <── service_categories

admins ── admin_role ── roles ── role_permission ── permissions
```

---

# 5. Index Strategy

Indexes below are recommendations for common access paths. Exact index types depend on the chosen RDBMS.

## 5.1 Identity & Auth

| Table | Index | Purpose |
| --- | --- | --- |
| `customers` | UNIQUE(`phone`) | Login lookup |
| `customers` | UNIQUE(`email`) | Login/recovery |
| `customers` | (`status`, `created_at`) | Admin customer lists |
| `admins` | UNIQUE(`email`) | Admin login |
| `customer_devices` | (`customer_id`, `is_active`) | Push fan-out |
| `customer_devices` | UNIQUE(`device_token`) | Token upsert |
| `password_reset_tokens` | (`subject_type`, `subject_id`, `expires_at`) | Recovery cleanup |

## 5.2 Catalog

| Table | Index | Purpose |
| --- | --- | --- |
| `service_categories` | UNIQUE(`slug`) | SEO/API lookup |
| `services` | UNIQUE(`slug`) | Detail lookup |
| `services` | (`is_active`, `category_id`, `sort_order`) | Browse/filter |
| `services` | (`pricing_model`, `is_active`) | Quote vs bookable lists |
| `product_categories` | UNIQUE(`slug`) | Lookup |
| `products` | UNIQUE(`slug`) | Detail lookup |
| `products` | UNIQUE(`sku`) | Inventory ops |
| `products` | (`is_active`, `category_id`, `sort_order`) | Store browse |
| `products` | (`allow_optional_quotation`, `is_active`) | Quote-enabled products |

## 5.3 Cart / Order / Booking

| Table | Index | Purpose |
| --- | --- | --- |
| `carts` | (`customer_id`, `status`) | Active cart |
| `cart_items` | UNIQUE(`cart_id`, `product_id`) | Line upsert |
| `bookings` | UNIQUE(`booking_number`) | Customer reference |
| `bookings` | (`customer_id`, `created_at`) | History |
| `bookings` | (`service_id`, `scheduled_start_at`, `status`) | Capacity checks |
| `bookings` | (`status`, `created_at`) | Admin queues |
| `orders` | UNIQUE(`order_number`) | Reference |
| `orders` | (`customer_id`, `placed_at`) | History |
| `orders` | (`status`, `placed_at`) | Fulfillment queues |
| `order_items` | (`order_id`) | Order detail |
| `order_items` | (`product_id`) | Product sales queries |

## 5.4 Quotation / Payment / Notification

| Table | Index | Purpose |
| --- | --- | --- |
| `quotation_requests` | UNIQUE(`request_number`) | Reference |
| `quotation_requests` | (`customer_id`, `created_at`) | History |
| `quotation_requests` | (`status`, `created_at`) | Admin queue |
| `quotation_requests` | (`request_target_type`, `service_id`) | Service request lookup |
| `quotation_requests` | (`request_target_type`, `product_id`) | Product request lookup |
| `quotations` | UNIQUE(`quotation_number`) | Reference |
| `quotations` | (`quotation_request_id`, `version_no`) | Version chain |
| `quotations` | (`status`, `valid_until`) | Expiry jobs |
| `payments` | UNIQUE(`payment_number`) | Reference |
| `payments` | UNIQUE(`idempotency_key`) | Safe retries |
| `payments` | (`payable_type`, `payable_id`) | Entity payment history |
| `payments` | (`customer_id`, `created_at`) | Customer payments |
| `payments` | (`status`, `created_at`) | Finance queues |
| `payments` | (`provider`, `provider_reference`) | Webhook reconciliation |
| `notifications` | (`customer_id`, `is_read`, `created_at`) | Inbox |
| `notifications` | (`reference_type`, `reference_id`) | Entity-related notices |

## 5.5 Reviews / Settings / Audit

| Table | Index | Purpose |
| --- | --- | --- |
| `reviews` | (`review_target_type`, `product_id`, `status`) | Product rating aggregate |
| `reviews` | (`customer_id`, `created_at`) | Customer history |
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

1. Catalog browse may occur without a customer row.
2. Creating a **booking** requires an authenticated, `active` customer.
3. Placing an **order** requires an authenticated, `active` customer.
4. Submitting a **quotation request** and initiating **payment** require an authenticated, `active` customer (SRS transactional restriction).
5. Suspended/deactivated customers cannot create new transactional records.

## 6.2 Catalog Integrity

1. Every **product** must have a non-null display `price` (>= 0).
2. Fixed-price purchase remains available even when `allow_optional_quotation = true`.
3. Inactive products/services are excluded from new carts/bookings/quote requests.
4. Historical order/booking/quote snapshots remain valid after catalog edits.

## 6.3 Stock & Pricing Integrity

1. At checkout, re-validate product price and stock against `products`.
2. Persist confirmed amounts on `order_items` snapshots.
3. Cart snapshots are indicative only.
4. Payment `amount` must equal server-calculated payable total at initiation.

## 6.4 Booking Integrity

1. Reject bookings overlapping service blackout windows.
2. Enforce `min_lead_hours` and `max_concurrent_bookings` where configured.
3. Status transitions recorded in `booking_status_histories`.
4. Cancelation eligibility enforced before status change to `cancelled`.

## 6.5 Quotation Integrity

1. `request_target_type` must match populated FK (`service_id` or `product_id`).
2. Product quote requests allowed only when product opts in.
3. Service quote requests allowed only for quotation/hybrid services.
4. Only one accept-eligible quotation active per request.
5. Acceptance locked after `valid_until` or non-`issued` status.
6. Accepted **latest** quotation required before quotation-targeted payment. Never use Rejected status.

## 6.6 Payment Integrity

1. Idempotent creation/webhook application via `idempotency_key` / provider reference uniqueness.
2. Success only after server-side verification.
3. Refunds cannot exceed captured amount.
4. Payable entity status updates only after successful payment confirmation.

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

- Separate `admins` identity from `customers`.
- RBAC via roles/permissions with least privilege.
- Session timeout and audit logging for money/status/catalog changes.
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
6. **Avoid premature sharding**; if needed later, shard by `customer_id` for customer-owned transactional data.

## 8.3 Extensibility Without Redesign

| Future Need | How Current Design Extends |
| --- | --- |
| Product variants | Add `product_variants` child table; point cart/order items to variant_id |
| Promotions/coupons | Add discount tables; keep order snapshot totals |
| Multi-currency | Already have `currency` fields; extend settings + FX tables later |
| Multi-branch geography | Add location entities; reference from services/orders |
| Recurring bookings | Add recurrence rules table linked to `bookings` |
| Staff/technician apps | New workforce module later — intentionally absent now |
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
