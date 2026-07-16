# API Design Specification

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `06_API_Design` |
| **Product Name** | Fayadhowr |
| **Document Type** | API Design Specification |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 14 July 2026 |
| **API Base Path** | `/api/v1` |
| **Basis Documents** | `docs/01_Brand_Design_Guide.md`, `docs/02_SRS.md`, `docs/03_Database_Design.md`, `docs/04_UX_Flow.md`, `docs/05_UI_UX_Design.md` |
| **Audience** | Solution architects, Laravel/API engineers, mobile engineers, QA |

---

## Document Rules

This specification defines the **HTTP API contract** for the Fayadhowr customer mobile application.

It intentionally does **not** include:

- Laravel code, routes, controllers, Form Requests, or policies
- Database migrations or Eloquent models
- Flutter / Dart client code
- OpenAPI / Swagger export files

The design is **implementation-independent**. Backend technology may realize these contracts using Laravel or another stack, provided behavior remains consistent with this document and the approved SRS / Database Design.

**Business guardrails reflected in the API:**

- Customer-only mobile surface (no employee workforce APIs in v1)
- Browse/catalog endpoints are guest-accessible
- Authentication required for booking, quotation submit, checkout/order, favorites save/list, profile, and histories
- Store products always expose Selling Price (Cost Price never on customer APIs)
- Product quotation is optional and does not replace fixed-price purchase
- Quotation uploads support images, videos, and PDF documents under the quotation contract; Booking Media V1 is limited to images and videos
- Favorites do not alter booking, quotation, cart, checkout, or payment workflows

---

# 1. API Overview

## 1.1 Purpose

Provide a secure, versioned REST JSON API that enables the Fayadhowr Flutter customer app to:

- Discover Home content, services, and store products without login
- Authenticate customers with soft return-to-intent
- Create bookings, quotation requests, carts/orders, and payments
- Manage profile, addresses, favorites, notifications, and commercial histories

Admin-panel APIs are documented in **§18 Admin Panel APIs** (Sprint 11). Customer APIs remain the primary focus of this document; payment webhooks remain system-facing.

## 1.2 Architecture

| Layer | Role |
| --- | --- |
| **Mobile Client** | Flutter app consumes `/api/v1` over HTTPS |
| **API Gateway / Web Server** | TLS termination, routing, rate limits |
| **Application API** | Stateless REST resources, auth middleware, validation |
| **Domain Services** | Booking, quotation, cart/order, payment, notification, favorites |
| **Persistence** | Relational database per Database Design |
| **Object Storage** | Media and quotation image files (URLs returned by API) |
| **Payment Provider** | External gateway; Fayadhowr stores references/status only |

The API is **stateless**: each protected request carries an access token. Session state is not stored server-side beyond token revocation/blacklist policy.

Identity and ownership follow ADR-001: `users` is the only authentication principal, while `customer_profiles` owns customer business/profile data. Authentication APIs operate on `users`; approved business APIs resolve the authenticated user's linked `customer_profile_id` server-side and never accept it as a client-controlled ownership field.

## 1.3 REST Principles

1. Resources are nouns (`/services`, `/orders/{id}`), not verbs.
2. HTTP methods express intent (`GET`, `POST`, `PUT`/`PATCH`, `DELETE`).
3. Collections support filtering, sorting, and pagination where lists can grow.
4. Mutations return the updated resource or a clear action result envelope.
5. Side-effecting payment/booking creates use idempotency keys where retries are likely.
6. Soft-deleted or inactive catalog items are omitted from public list endpoints but remain readable in historical documents owned by the customer.

## 1.4 JSON Standards

| Rule | Standard |
| --- | --- |
| Content-Type | `application/json` (except multipart uploads) |
| Encoding | UTF-8 |
| Keys | `snake_case` |
| Money | Decimal strings or numbers with fixed 2-scale; always include `currency` |
| Dates | ISO-8601 UTC (`2026-07-14T12:00:00Z`) |
| Booleans | `true` / `false` |
| Nulls | Omitted or explicit `null` for optional fields—be consistent per resource |
| IDs | Integer or string resource ids; public numbers (`booking_number`, etc.) also returned |

## 1.5 Versioning Strategy

| Item | Value |
| --- | --- |
| **URL versioning** | `/api/v1/...` |
| **Compatibility** | Additive non-breaking changes preferred within `v1` |
| **Breaking changes** | Require `/api/v2` and a deprecation window |
| **Headers (optional)** | `Accept: application/json`; client may send `X-App-Version` for support diagnostics |

---

# 2. Authentication

Authentication APIs operate on `users` only. Registration creates the authentication identity and provisions its linked `customer_profiles` record transactionally; business/profile data does not become a second authentication identity.

## 2.1 Register

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/register` |
| **Auth** | Guest |
| **Purpose** | Create a `users` authentication account and its linked customer profile |
| **Body (logical)** | `full_name`, `phone` (**required**), `email` (**optional**), `password`, `password_confirmation` (must match `password`) |
| **Result** | Customer profile + access token (or require phone OTP verification per policy) |
| **Google** | After successful Google Sign-In, if no customer exists, server may auto-provision / complete registration |

## 2.2 Login — Phone (OTP)

| Item | Spec |
| --- | --- |
| **Start** | `POST /api/v1/auth/phone/start` — body: `phone` (E.164; UI default country Somalia `+252`) |
| **Verify** | `POST /api/v1/auth/phone/verify` — body: `phone`, `otp` |
| **Auth** | Guest |
| **Result** | Access token (+ refresh if used) + customer summary |
| **Notes** | Primary login method for Somalia |

## 2.2B Login — Google (Future Implementation)

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/google` |
| **Auth** | Guest |
| **Body** | Provider ID token from **native** Google Sign-In (Android/iOS); account picker when multiple device accounts |
| **Result** | Access token + customer summary; may auto-register if new |
| **Client rule** | Do not collect Gmail password or require typing email — use device Google accounts only |

## 2.2C Login — Email

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/login` |
| **Auth** | Guest |
| **Purpose** | Authenticate with email + password |
| **Body** | `email`, `password`, optional `remember_me` |
| **Result** | Access token (+ refresh token if used) + customer summary |
| **Errors** | Invalid credentials → `401`; suspended → `403` |

## 2.3 Logout

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/logout` |
| **Auth** | Required |
| **Purpose** | Revoke the current access token only; tokens issued to other devices remain valid |

## 2.4 Forgot Password

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/forgot-password` |
| **Auth** | Guest |
| **Purpose** | Start credential recovery (email/password path) |
| **Body** | `email` (or `phone` where recovery policy allows) |
| **Result** | Generic success message (do not leak account existence excessively) |

## 2.5 Reset Password

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/reset-password` |
| **Auth** | Guest |
| **Purpose** | Complete recovery with token |
| **Body** | `token`, identifier, `password`, `password_confirmation` |

## 2.6 Token Authentication

| Item | Spec |
| --- | --- |
| **Scheme** | Bearer token in `Authorization: Bearer {access_token}` |
| **Token type** | Opaque API token or JWT (implementation choice); must be revocable on logout |
| **Storage (client)** | Secure device storage (not documented as code here) |
| **Expiry** | Access tokens expire; refresh endpoint optional (`POST /api/v1/auth/refresh`) if design uses refresh tokens |

## 2.7 Protected Endpoints

Require a valid customer token **and** active account status, including:

- Profile, addresses, password change
- Favorites list / add / remove
- Create booking; booking history/details; cancel
- Create quotation request; uploads; history; Accept Quotation; Discuss Quotation (messaging + files); revision history; never Reject
- Cart checkout / create order; order history/details; cancel (policy)
- Payment initialization and customer payment history
- Notifications list / mark read / unread count

## 2.8 Guest Endpoints

Accessible **without** authentication:

- Home aggregate / home section endpoints
- Service list, categories, details, search
- Product list, categories, details, search (prices included)
- Gallery, reviews, FAQ, contact, about, legal content
- Cart read/update for guest session carts (if guest carts enabled); **checkout remains protected**
- Auth register / phone OTP / Google / email login / forgot / reset

## 2.9 Soft Authentication Concept

Soft authentication is a **client UX pattern supported by the API**:

1. Guest calls a guest endpoint freely.
2. When the user attempts a protected action, the client presents login/register.
3. After `login`/`register` succeeds, the client **retries the same protected request** (return-to-intent).
4. The API does not require login for browse; it returns `401` only when a protected resource is requested without a valid token.

Optional: guest `session_token` header/body for carts prior to login; on login, cart is merged into the authenticated customer cart.

---

# 3. Standard API Response Format

## 3.1 Success Response

```json
{
  "success": true,
  "message": "Optional human-readable message",
  "data": {},
  "meta": null
}
```

`data` may be an object, array, or null for empty acknowledgements.

## 3.2 Error Response

```json
{
  "success": false,
  "message": "Something went wrong",
  "error_code": "SERVER_ERROR",
  "errors": null
}
```

## 3.3 Validation Error Response

HTTP `422`

```json
{
  "success": false,
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

## 3.4 Pagination Response

```json
{
  "success": true,
  "message": null,
  "data": {
    "items": []
  },
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 100,
    "last_page": 7
  }
}
```

Query parameters: `page`, `per_page` (capped server-side).

## 3.5 HTTP Status Codes

| Code | Meaning |
| --- | --- |
| `200` | OK — successful GET/PATCH/action |
| `201` | Created — resource created |
| `202` | Accepted — async processing started (optional for payments) |
| `204` | No Content — successful delete with empty body (optional; prefer envelope) |
| `400` | Bad Request — malformed request |
| `401` | Unauthorized — missing/invalid token |
| `403` | Forbidden — authenticated but not allowed (suspended, ownership) |
| `404` | Not Found |
| `409` | Conflict — slot taken, stock race, duplicate idempotency mismatch |
| `422` | Unprocessable Entity — validation |
| `429` | Too Many Requests — rate limit |
| `500` | Internal Server Error |
| `503` | Service Unavailable |

---

# 4. Customer APIs

Base: authenticated customer scope; customers only access **their own** data.

## 4.1 Profile

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/customer/profile` | Retrieve profile + quick stats |
| `PUT` / `PATCH` | `/api/v1/customer/profile` | Update editable fields |
| `POST` | `/api/v1/customer/change-password` | Change password |
| `POST` | `/api/v1/customer/change-pin` | Change PIN (when enabled) |
| `PUT` / `PATCH` | `/api/v1/customer/preferred-language` | Set `so` \| `en` \| `ar` (app-wide) |

**Data includes:** `customer_number` (CUS, **read-only**), `full_name`, `avatar_url`, `preferred_language`, `notification_preferences`, `classification`, `member_since` / `created_at`, and quick stats (`bookings_count`, `quotations_count`, `orders_count`). Authentication fields (`email`, phone credentials, password, verification state, tokens, and account status) remain on `users` and are not profile payload fields. System fields, `classification`, and `customer_number` are never writable by the customer.

## 4.2 Addresses

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/customer/addresses` | List addresses (include Inactive) |
| `POST` | `/api/v1/customer/addresses` | Create address |
| `GET` | `/api/v1/customer/addresses/{id}` | Address detail |
| `PUT` / `PATCH` | `/api/v1/customer/addresses/{id}` | Update |
| `POST` | `/api/v1/customer/addresses/{id}/default` | Set default (active only) |
| `POST` | `/api/v1/customer/addresses/{id}/inactive` | Mark Inactive (never hard-delete) |
| `POST` | `/api/v1/customer/addresses/{id}/reactivate` | Reactivate inactive address |

**Rule:** Customer APIs must **not** expose permanent delete for addresses.

## 4.2B Payment Methods (Saved Instruments)

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/customer/payment-methods` | List saved methods |
| `POST` | `/api/v1/customer/payment-methods` | Add method (tokenized / masked) |
| `POST` | `/api/v1/customer/payment-methods/{id}/default` | Change default |

Supported `type` values: `evc_plus`, `edahab`, `jeeb`, `salaam_somali_bank`, `bank_transfer`, `card`.

**Rules:** No full PAN/CVV storage. Customer cannot delete payment **history** (`payments` ledger). Deactivating a saved instrument (if offered later) must not erase ledger rows.

## 4.3 Notifications

See §13. Nested under customer or `/api/v1/notifications` — both acceptable if ownership-enforced; this design uses `/api/v1/notifications`.

## 4.4 Favorites

See §12.

## 4.5 Booking History

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/bookings` | Paginated booking history |
| `GET` | `/api/v1/bookings/{id}` | Booking details |

## 4.6 Quotation History

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/quotation-requests` | Customer quotation requests |
| `GET` | `/api/v1/quotations` | Issued quotations visible to customer (optional alternate) |
| `GET` | `/api/v1/quotation-requests/{id}` | Request detail (+ related quotes) |
| `GET` | `/api/v1/quotations/{id}` | Quotation detail |

## 4.7 Order History

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/orders` | Paginated orders |
| `GET` | `/api/v1/orders/{id}` | Order detail + items |

---

# 5. Home APIs

Home may be loaded via a single aggregate endpoint and/or section endpoints. Both patterns are valid; aggregate is preferred for first paint.

## 5.1 Aggregate (Recommended)

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/home` | Guest |

**Returns sections in UX order:**

1. Hero banners  
2. (Client renders Search Bar locally; search calls §15)  
3. Service categories  
4. Featured services  
5. Store products (with prices)  
6. Before & after gallery teasers  
7. Reviews  
8. FAQ teasers  
9. Contact information  

## 5.2 Section Endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/home/hero-banners` | Hero Banner |
| `GET` | `/api/v1/home/service-categories` | Service Categories |
| `GET` | `/api/v1/home/featured-services` | Featured Services |
| `GET` | `/api/v1/home/store-products` | Store Products |
| `GET` | `/api/v1/home/before-after` | Before & After Gallery |
| `GET` | `/api/v1/home/reviews` | Reviews |
| `GET` | `/api/v1/home/faq` | FAQ |
| `GET` | `/api/v1/home/contact` | Contact Information |

All guest-accessible. Optional auth may enrich `is_favorite` flags on service/product teasers.

---

# 6. Service APIs

## 6.1 List Services

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/services` | Guest |

**Query:** `category_id`, `mode` (`one_time` | `monthly_contract`), `city` (`Mogadishu` | `Hargeisa`), `page`, `per_page`, `sort`  
**Returns:** Service cards including optional `starting_from_price`, `currency`, available `modes`, coverage cities, media, and optional `is_favorite` if authenticated.

## 6.2 Service Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/services/{id}` | Guest |

**Returns:** Full detail — media, description, inclusions/exclusions, optional `starting_from_price`, `currency`, service modes/subtypes, coverage cities, before/after refs, FAQ refs, both Book Now and Request Quotation actions, and `is_favorite` if authenticated.

**Official V1 catalog and modes:**

| Service | Supported modes | Supported subtypes |
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

The service payload uses `mode` values `one_time` and `monthly_contract`; subtype values follow the Database Design naming. Service coverage in V1 is Mogadishu and Hargeisa. A Starting From price is optional and informational; final operational price follows Fayadhowr assessment/review.

## 6.3 Service Categories

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/service-categories` | Guest |

## 6.4 Search Services

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/services/search` | Guest |

**Query:** `q`, pagination. See also §15 unified search.

## 6.5 Book Service

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/services/{id}/bookings` | Required |

Or `POST /api/v1/bookings` with `service_id`.  

**Body:** `service_mode_id`, `requested_date`, `requested_time_window`, `service_city`, address/address_id, `customer_notes`, optional `idempotency_key`  
**Rules:** service and selected mode active; service covers `service_city`; capacity/blackout/lead-time validation; active authenticated user with linked customer profile.  
**Result:** `201` + booking resource. The API resolves `customer_profile_id` from the authenticated user; callers must not submit or change it.

## 6.6 Request Service Quotation

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/bookings/{id}/quotation-requests` | Required |

**Body:** `requirements` (required), `description` (optional but highly recommended), preferred timing/location, optional attachment ids  
**Rules:** the booking must be owned by the authenticated customer's `customer_profile_id`. Every active service supports Request Quotation; no pricing-model classification gates this action.

## 6.7 Upload Service Images (Quotation)

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/quotation-requests/{id}/attachments` | Required |

Or pre-upload: `POST /api/v1/uploads` then reference IDs on create.  

See §14 for formats and limits. Ownership of quotation request required.

---

# 7. Store APIs

Store is a separate physical-product commerce module. Products are not services and Store endpoints never share service resources or service-mode fields. Store owns catalog, categories, images, cart, checkout, Store Orders, and Unified Payment integration. Inventory purchasing and stock-ledger management are outside Store APIs.

V1 product categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, Personal Protective Equipment (PPE), Air Fresheners. Heavy cleaning equipment and machines are outside V1.

## 7.1 Product List

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/products` | Guest |

**Always includes `selling_price` and `currency`.** May include `current_stock` / low-stock cues for display. Query: `category_id`, `page`, `per_page`. Cost Price is never exposed on customer Store APIs.

## 7.2 Product Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/products/{id}` | Guest |

Includes `selling_price`, `currency`, `unit`, `sku`, `current_stock` / `availability_status` (`in_stock`, `low_stock`, `out_of_stock`), optional `badge`, optional `price_tiers[]`, media gallery (ordered), specifications, `allow_optional_quotation`, `is_favorite` if authenticated.

## 7.3 Categories

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/product-categories` | Guest |

## 7.4 Search Products

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/products/search` | Guest |

## 7.5 Cart

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api/v1/cart` | Guest* / Required | Get active cart |
| `POST` | `/api/v1/cart/items` | Guest* / Required | Add item |
| `PATCH` | `/api/v1/cart/items/{id}` | Guest* / Required | Update quantity |
| `DELETE` | `/api/v1/cart/items/{id}` | Guest* / Required | Remove item |
| `DELETE` | `/api/v1/cart` | Guest* / Required | Clear cart |

\*Guest cart allowed via `session_token` if enabled; merges on login.

**Rules:** Reprice against Selling Price on read; reject adds that would oversell available stock. Cart and Store Order creation never decrease stock.

## 7.6 Checkout

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/checkout` | Required |

**Body:** `address_id`, optional notes  
**Behavior:** Validates stock/Selling Prices and address ownership; returns a **checkout preview summary only** (priced lines, totals, address). Does **not** create a Store Order and does **not** decrease stock.  
**Next step:** Client creates the Store Order via `POST /api/v1/store-orders`, then initializes payment via the Unified Payment Module.

**Stock rule:** Stock decreases only after Payment = `paid`. Failed or cancelled payments leave stock unchanged. Negative stock is not allowed.

## 7.6A Store Orders

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/store-orders` | Required |
| `GET` | `/api/v1/store-orders/{id}` | Required (owner) |
| `POST` | `/api/v1/store-orders` | Required |
| `PATCH` | `/api/v1/store-orders/{id}/cancel` | Required (owner) |

**Create behavior:** Creates a `store_orders` record in `pending_payment` with public number `STO-YYYY-######`, line snapshots, and clears cart items. Does **not** decrease stock.  
**Cancel:** Allowed only while `pending_payment`.

Service Orders remain under `/api/v1/orders` with `ORD-YYYY-######` and are commercially separate from Store Orders.

## 7.7 Product Quotation

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/products/{id}/quotation-requests` | Required |

**Rules:** `allow_optional_quotation = true`; does not remove fixed-price cart path; uses **shared** Quotation Module with `source = product` (Accept / Discuss — never Reject).

## 7.8 Upload Product Images (Quotation)

Same attachment endpoints as §6.7 / §14, scoped to the product quotation request owned by the customer.

Checkout also accepts `contact_phone` for delivery coordination (prefill from profile when available).

## 7.9 Inventory Admin APIs (Architecture)

Inventory Admin APIs (suppliers, purchase orders, goods receipts, stock adjustments, stock ledger, low-stock dashboard) are admin-realm endpoints and are separate from customer Store APIs.

**Purchase Order lifecycle endpoints (implemented shape):**

| Method | Path | Notes |
| --- | --- | --- |
| `POST` | `/api/v1/purchase-orders` | Create draft |
| `PUT` | `/api/v1/purchase-orders/{id}` | Update draft only |
| `PATCH` | `/api/v1/purchase-orders/{id}/submit` | `draft` → `submitted` |
| `PATCH` | `/api/v1/purchase-orders/{id}/approve` | `submitted` → `approved` |
| `PATCH` | `/api/v1/purchase-orders/{id}/cancel` | `draft` or `submitted` → `cancelled` |
| `POST` | `/api/v1/goods-receipts` | Allowed only when PO is `approved` or `partially_received` |

Stock movements are written to `stock_ledgers`. Supplier status uses `active` / `inactive`. Product gallery uses `product_images`.

---

# 8. Booking APIs

## 8.1 Create Booking

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/bookings` | Required |

**Body:** `service_id`, `service_mode_id`, `requested_date`, `requested_time_window`, `service_city`, address fields/address id, `customer_notes`, optional `idempotency_key`  
**Result:** Booking with system-generated public `booking_number` in format `BK-YYYY-######`, status, requested schedule, and nullable confirmed schedule (`scheduled_start_at`, `scheduled_end_at`). The numeric `id` remains the primary key.

**Ownership:** The server derives `customer_profile_id` from the authenticated `users` identity. It is the business owner of the booking and is never accepted as a client-writeable field.

## 8.2 Booking Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/bookings/{id}` | Required (owner) |

## 8.3 Booking History

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/bookings` | Required |

Supports `status` filter + pagination.

## 8.4 Cancel Booking

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/bookings/{id}/cancel` | Required (owner) |

**Body:** optional `cancellation_reason`  
**Errors:** `403`/`409` when policy disallows cancel.

## 8.5 Booking Media

Booking Media V1 is limited to **images** and **videos**. Documents are not accepted or returned as booking media. This API design intentionally does not define a separate Booking Media upload/storage endpoint in V1; any future endpoint must preserve these type restrictions and owner-scoped booking access.

---

# 9. Quotation APIs

Covers **service** and **product** quotation requests and issued quotations.

## 9.1 Create Quotation Request

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/quotation-requests` | Required |

**Body:**

- `request_target_type`: `booking` | `product`
- `booking_id` (service quotation origin) or `product_id`
- `requirements` (required)
- `description` (optional but highly recommended — free-text notes to explain the request, uploaded files, or special instructions)
- `preferred_timing`, `location`, `quantity_hint`
- `attachment_ids` (optional)

**Result:** Request with public reference aligning to Quotation Number family (`QT-YYYY-######`), status `pending_review`.

> Note: Customers create a **quotation request**. Formal **quotations** are issued by the Fayadhowr team (admin API out of scope for full write). Customers consume them via details, **Accept Quotation**, and **Discuss Quotation** (never Reject).

## 9.2 Upload Files (Request)

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/quotation-requests/{id}/attachments` | Required |
| `DELETE` | `/api/v1/quotation-requests/{id}/attachments/{attachment_id}` | Required |

Multipart upload (images / videos / PDFs per §14). These are quotation attachments, not Booking Media.

## 9.3 Quotation Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/quotations/{id}` | Required (owner of parent request) |

Includes `quotation_number` (`QT-YYYY-######`), `version_no`, `is_latest` / Latest Version indicator, line items, totals, `valid_until`, terms, status (`pending_review` | `quotation_ready` | `under_discussion` | `accepted` | `expired` | `cancelled`), timeline summary.

## 9.4 Quotation History

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/quotation-requests` | Required |
| `GET` | `/api/v1/quotation-requests/{id}` | Required |
| `GET` | `/api/v1/quotations/{id}/revisions` | Required |

Request/detail embeds revision chain (v1, v2, v3…) read-only. Only latest may be accepted.

## 9.5 Accept Quotation

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/quotations/{id}/accept` | Required |

**Rules:** Target must be **latest** revision (`is_latest=true`); status `quotation_ready` or `under_discussion`; within `valid_until`; not `expired`/`cancelled`. Unlocks payment path.  
**Result:** Status `accepted` + payable payment hint + Quotation Number.

## 9.6 Discuss Quotation

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/quotations/{id}/discuss` | Required |
| `GET` | `/api/v1/quotations/{id}/messages` | Required |
| `POST` | `/api/v1/quotations/{id}/messages` | Required |
| `POST` | `/api/v1/quotations/{id}/messages/{message_id}/attachments` | Required |

**Behavior:**

- Starts or continues discussion on the **same** Quotation Number (never creates a new quotation).
- Sets status to `under_discussion` while discussion is active.
- Customer may send messages and upload additional images, videos, and PDFs.
- Team replies and quotation revisions are visible on the same thread/timeline (team write via admin API).
- Customer may still **Accept** the latest revision from this context.
- Discussion **never** closes the quotation; there is **no** `/reject` endpoint in V1.

**Notifications:** New messages notify the other party; quotation updates notify the customer (e.g. “Your quotation has been updated. Please review the latest version.”).

## 9.7 Quotation Timeline

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/quotations/{id}/timeline` | Required |

Returns ordered events: Quotation Created, Customer Discussion, Team Replies, Quotation Updated, Customer Acceptance, Payment, Service Completion.

---

# 10. Order APIs

## 10.1 Create Order

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/orders` | Required |

Typically invoked for **service** orders from quotation acceptance / booking fulfillment. Store commerce uses checkout preview (`POST /api/v1/checkout`) then `POST /api/v1/store-orders` (not this endpoint).

**Optional:** `quotation_id` when fulfilling an accepted service quotation.

## 10.2 Order Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/orders/{id}` | Required (owner) |

Includes items snapshots, totals, status, fulfillment.

## 10.3 Order History

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/orders` | Required |

## 10.4 Cancel Order

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/orders/{id}/cancel` | Required (owner) |

Allowed only when business policy permits (e.g., before fulfillment / unpaid). Otherwise `409` with policy message.

---

# 11. Payment APIs

Payment V1 is a unified, customer-profile-owned module for Service Orders and Store Orders. Requests identify the originating payable record through `payable_type` and `payable_id`; Payment does not own originating-domain rules.

## 11.1 Payment Initialization

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/payments/initialize` | Required |

**Body:**

- `payable_type`: Service Order and Store Order in V1 use the same polymorphic contract
- `payable_id`
- `payment_method`: preferred Somali-first enum — `evc_plus` (default) | `edahab` | `jeeb` | `salaam_somali_bank` | `bank_transfer` | `card` (optional) | `digital_wallet` (future placeholder)
- `idempotency_key` (required)

**Result:** The existing active Payment (`pending`, `initialized`, or `processing`) for the payable is returned when one exists; otherwise, initialization creates and returns a Payment record `pending`/`initialized` plus a provider-neutral gateway handoff payload.

**UI Order Summary fields:** `subtotal`, `delivery_fee`, `tax` (default `0.00`), `total`.

**Rules:** Amount equals the server-calculated payable total; the authenticated customer owns the payable entity. A payable has at most one active Payment (`pending`, `initialized`, or `processing`); a new Payment can be initialized only after the prior Payment is `paid`, `failed`, or `cancelled`. This domain rule applies to Service Orders and Store Orders through the polymorphic payable reference. For Store Orders, Payment = `paid` decreases stock and writes a Stock Ledger customer-sale entry; failed/cancelled payments leave stock unchanged. Gateway adapters remain provider-neutral for EVC Plus, Zaad, Sahal, Stripe, PayPal, and future providers.

## 11.2 Payment Callback

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/payments/webhook` | Provider signature (not customer token) |
| `GET`/`POST` | `/api/v1/payments/callback` | Provider return URL pattern |

**Behavior:** Before any business state changes, webhook/callback handling verifies in this order: gateway signature/authentication, gateway transaction reference, Payment resolution, active-Payment status, and duplicate-callback status. The resulting Payment update, `payment_transactions` update, `payment_status_histories` insert, and Order confirmation (only on success) execute in one database transaction. A successful callback changes Payment `processing -> paid` and then Order `pending_payment -> confirmed`; failed callbacks leave the Order unchanged. Repeated callbacks for the same successful transaction are idempotent and must not create duplicate state transitions. Payment publishes domain events (`PaymentPaid`, `PaymentFailed`) rather than sending notifications directly.

## 11.3 Payment Success

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/payments/{id}` | Required (owner) |

When `status = paid`, the client shows success UI and the returned payment includes its receipt public number (`RCPT-YYYY-######`). Receipt PDF generation is outside V1. Optional convenience:

| Method | Path |
| --- | --- |
| `GET` | `/api/v1/payments/{id}/success-view` |

Returns success summary only if paid; otherwise `409`.

## 11.4 Payment Failure

Failure is represented by payment `status = failed` on:

`GET /api/v1/payments/{id}`

Client offers retry via a new `initialize` (new idempotency key) while the payable entity remains valid.

## 11.5 Payment History

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/payments` | Required |

Paginated customer payments; filter by `status`, `payable_type`.

---

# 12. Favorites APIs

Favorites are independent of booking, quotation, cart, checkout, and payment.

## 12.1 Add Favorite

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/favorites` | Required |

**Body:** `favorite_type`: `service` | `product`; `service_id` or `product_id`  
**Result:** `201` or `200` if already favorited (idempotent).

## 12.2 Remove Favorite

| Method | Path | Auth |
| --- | --- | --- |
| `DELETE` | `/api/v1/favorites/{id}` | Required |
| `DELETE` | `/api/v1/favorites` | Required |

Alternate body/query delete by `favorite_type` + target id for heart-toggle UX.

## 12.3 List Favorites

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/favorites` | Required |

**Query:** `type=service|product|all`, pagination  
**Returns:** Favorited services and products with card payloads (product Selling Price always present).

Guest heart tap without token → API `401` → client soft auth → retry add.

---

# 13. Notifications APIs

## 13.1 List Notifications

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications` | Required |

Paginated; newest first. Query: `status=all|unread|read`, `category`, `q` (search).  
Payload includes `category`, `title`, `body`, `is_read`, `created_at`, `reference_type`, `reference_id`, `reference_number` (e.g. `QT-2026-000041`) for deep links.

**Categories:** `booking` | `quotation` | `discussion` | `order` | `payment` | `delivery` | `account` | `announcement`

## 13.2 Notification Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications/{id}` | Required |

Returns full message + deep-link target metadata.

## 13.3 Mark As Read

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/notifications/{id}/read` | Required |
| `POST` | `/api/v1/notifications/read-all` | Required |

## 13.4 Unread Count

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications/unread-count` | Required |

**Returns:** `{ "unread_count": 3 }` inside `data`.

> **V1 rule:** There is **no** customer delete/clear API for notifications. Notification records are permanent business history. Only mark-as-read actions are supported.

## 13.5 Notification Preferences

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications/preferences` | Required |
| `PUT` | `/api/v1/notifications/preferences` | Required |

Toggles: `push_enabled`, `email_enabled`, `booking`, `quotation`, `discussion`, `order`, `payment`, `marketing`.

---

# 14. File Upload APIs

## 14.1 Single Upload Endpoint

Replace any previous multi-endpoint or image-only upload strategy with a **single** upload endpoint:

| Method | Path | Auth |
| --- | --- | --- |
| `POST` | `/api/v1/uploads` | Required |

Content-Type: `multipart/form-data`.

### Purpose

Upload files **before** submitting quotation requests.

The upload endpoint returns **uploaded file IDs** that are later attached to quotation requests.

## 14.2 Supported File Types

### Images

- JPG  
- JPEG  
- PNG  
- WebP  

### Videos

- MP4  
- MOV  
- WebM  

### Documents

- PDF  

## 14.3 Business Usage

| Quotation type | Typical uploads |
| --- | --- |
| **Service Quotation** | Images + Videos |
| **Product Quotation** | PDF + Images |
| **Mixed Quotation** | PDF + Images + Videos |

Customers may upload one or more files. Supported quotation attachments include images, videos, and PDF documents.

## 14.4 Response Contract

Successful upload returns file metadata including **file IDs** used when creating quotation requests.

Logical response shape (inside standard success envelope `data`):

- One or more uploaded file objects, each with at least: `id`, `file_name`, `mime_type`, `file_size_bytes`, `file_url` (or storage key), `created_at`

## 14.5 Maximum Size

| Limit | Guidance |
| --- | --- |
| Per file | Configurable by type (images/documents vs larger video caps) |
| Per request | Configurable max file count (one or more files allowed) |

Exact caps live in settings; API documents effective limits in validation errors.

## 14.6 Validation Rules

1. MIME type and extension must match the allow-list (images, videos, PDF only).
2. Reject executable or mismatched content sniffing failures.
3. Authenticated customer required for all uploads.
4. Strip/skip EXIF or similar metadata persistence policy as a security/privacy choice where applicable.
5. Virus/malware scanning recommended at storage boundary.

## 14.7 Storage Strategy

| Concern | Approach |
| --- | --- |
| Storage | Private or signed object storage |
| Staging | Uploaded files retained as reusable file records identified by returned IDs |
| Attachment | File IDs referenced when creating quotation requests; persisted on `quotation_request_attachments` |
| Access | Authenticated/signed URLs for reading sensitive uploads |
| CDN | Optional for public catalog media (not required for private quotation uploads) |

Catalog service/product media remain admin-managed (not customer upload APIs in v1).

---

# 15. Search APIs

Powers Home Search Bar under the Hero (Services + Store Products).

## 15.1 Search Services

`GET /api/v1/services/search?q={query}`

## 15.2 Search Products

`GET /api/v1/products/search?q={query}`

## 15.3 Unified Search (Recommended for Home)

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/search` | Guest |

**Query:** `q`, optional `type=all|service|product`, pagination  

**Returns:** Grouped `services` and `products` arrays (products include prices).

## 15.4 Search Suggestions

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/search/suggestions` | Guest |

**Query:** `q` (may be empty for popular/recent server-side suggestions)  

**Returns:** Lightweight suggestion list (names/ids/types). Must not invent fake prices. Client may also show local recent searches.

**Empty query / no results:** `200` with empty lists — not an error.

---

# 16. Error Handling

## 16.1 Validation Errors

HTTP `422` + field map (`error_code: VALIDATION_ERROR`).

## 16.2 Authentication Errors

| Case | Status | Code |
| --- | --- | --- |
| Missing/invalid token | `401` | `UNAUTHENTICATED` |
| Expired token | `401` | `TOKEN_EXPIRED` |

## 16.3 Authorization Errors

| Case | Status | Code |
| --- | --- | --- |
| Not resource owner | `403` | `FORBIDDEN` |
| Suspended customer | `403` | `ACCOUNT_SUSPENDED` |
| Cancel not allowed | `403` or `409` | `ACTION_NOT_ALLOWED` |

## 16.4 Not Found (404)

Unknown ids or inactive public catalog resources not exposed → `404` `NOT_FOUND`.  
Do not leak existence of other customers’ private resources (prefer `404` over `403` where appropriate).

## 16.5 Server Error (500)

Unexpected failures → `500` `SERVER_ERROR` with safe message; detailed diagnostics only in server logs.

## 16.6 Rate Limiting

HTTP `429` `RATE_LIMITED` with `Retry-After` when possible. Stricter limits on auth, payment initialize, and uploads.

---

# 17. Security

## 17.1 Authentication

- Bearer tokens over TLS only
- Secure password hashing (one-way)
- Token revocation on logout
- Lockout / rate limit brute-force login

## 17.2 Authorization

- Owner checks on every customer resource
- No privilege escalation to admin APIs via customer tokens
- Suspended users blocked from transactional POSTs

## 17.3 HTTPS

All production traffic must use HTTPS/TLS. Reject insecure cleartext for tokens.

## 17.4 Input Validation

Validate types, lengths, enums (`pricing` targets, statuses, payable types), and business rules server-side—never trust the client.

## 17.5 SQL Injection Protection

Use parameterized queries / ORM bindings exclusively; never concatenate raw user input into SQL.

## 17.6 XSS Protection

Store and return untrusted text safely; admin-originated HTML (if any) sanitized. Mobile clients treat API strings as text unless explicitly marked safe.

## 17.7 CSRF Considerations

Bearer-token mobile APIs are typically not cookie-session browser forms; CSRF risk is low if tokens are not in cookies. If cookie-based SPA admin shares domain patterns, apply CSRF tokens there (admin out of scope). Do not place access tokens in query strings.

## 17.8 File Upload Security

Allow-list MIME/extensions; size caps; content sniffing; store outside public web root or with signed access; no scriptable types in v1 images.

## 17.9 Rate Limiting

Apply per-IP and per-user limits on authentication, search abuse, uploads, and payment initialization.

## 17.10 Payment Security

- No PAN/CVV storage
- Server-side amount calculation
- Webhook signature verification
- Idempotency keys on payment initialize

---

# 18. Performance

## 18.1 Caching

| Resource | Guidance |
| --- | --- |
| Home aggregate, categories | Short TTL HTTP/cache or server cache |
| Service/product details | Cache publicly with invalidation on admin updates |
| Customer-private data | No shared public cache; `Cache-Control: private` |
| Favorites / notifications | Low TTL or no cache |

Use `ETag` / `If-None-Match` optionally for catalog.

## 18.2 Pagination

All list endpoints that can grow (`services`, `products`, `orders`, `bookings`, `notifications`, `favorites`, `payments`, search) **must** paginate. Default `per_page` 15–20; hard max enforced.

## 18.3 Lazy Loading

- Home aggregate returns teasers; details fetched on demand
- Nested histories (status histories, large attachment sets) load with detail endpoints or nested paginated relations
- Avoid N+1 by batching card relations (primary image, price)

## 18.4 Compression

Enable HTTP response compression (e.g., gzip/brotli) for JSON responses at the reverse proxy or application layer.

---

# 19. API Naming Standards

## 19.1 Resource Naming

| Rule | Example |
| --- | --- |
| Plural nouns | `/services`, `/orders` |
| Kebab-case paths | `/quotation-requests`, `/before-after` |
| Nested actions as sub-resources | `/quotations/{id}/accept`, `/quotations/{id}/discuss`, `/quotations/{id}/messages` |
| snake_case JSON keys | `booking_number`, `payable_type` |

## 19.2 HTTP Methods

| Method | Use |
| --- | --- |
| `GET` | Read |
| `POST` | Create or non-idempotent action |
| `PUT`/`PATCH` | Update |
| `DELETE` | Remove |

Prefer `POST .../cancel`, `POST .../accept` for state transitions with clear business meaning.

## 19.3 Status Codes

Use §3.5 consistently. Creating resources → `201`. Validation → `422`. Auth → `401`/`403`.

## 19.4 Consistency Rules

1. Always wrap payloads in the standard envelope (`success`, `message`, `data`, `meta`).
2. Public catalog responses include inactive-filtered lists only.
3. Money fields always accompany `currency`.
4. Protected writes include ownership checks.
5. Idempotency keys for payment initialize and recommended for booking/checkout.
6. Favorites endpoints never mutate cart, booking, quotation commercial state, or payment state.
7. Product payloads always expose `selling_price` for sellable catalog products; Cost Price is admin/inventory only.
8. Error `error_code` values remain stable machine identifiers for mobile handling.

---

## Endpoint Map (Quick Reference)

| Domain | Prefix |
| --- | --- |
| Auth | `/api/v1/auth/*` |
| Customer profile/addresses | `/api/v1/customer/*` |
| Home | `/api/v1/home`, `/api/v1/home/*` |
| Services | `/api/v1/services`, `/api/v1/service-categories` |
| Products | `/api/v1/products`, `/api/v1/product-categories` |
| Cart / Checkout | `/api/v1/cart`, `/api/v1/checkout` |
| Bookings | `/api/v1/bookings` |
| Quotations | `/api/v1/quotation-requests`, `/api/v1/quotations` |
| Orders | `/api/v1/orders` |
| Payments | `/api/v1/payments/*` |
| Favorites | `/api/v1/favorites` |
| Notifications | `/api/v1/notifications` |
| Search | `/api/v1/search`, `/api/v1/search/suggestions` |
| Uploads | `/api/v1/uploads` |

---

## Traceability

| API area | Source documents |
| --- | --- |
| Auth & soft gate | UX Flow, UI/UX Design, SRS |
| Home sections & search | UX Flow Home; UI/UX Search |
| Services / store / cart | SRS §§7–8; Database Design |
| Booking / quotation / payment | SRS §§9–11 |
| Favorites | UI/UX Design Favorites Experience |
| Uploads image rules | Database Design attachments |
| Security / NFR | SRS §6 |

---

# 20. Future Versions

Future API and product enhancements may include the following. These items are **not** part of Version 1.

**Do not treat as future work** (already supported in Version 1 quotation uploads via `POST /api/v1/uploads`):

- PDF quotation attachments  
- Video quotation attachments  

## 20.1 Planned Future Enhancements

- Live order tracking  
- Customer ratings & reviews  
- Loyalty & rewards program  
- Promotional coupons  
- Multi-language support  
- AI-assisted quotation recommendations  
- Advanced analytics  
- Push notification improvements  

## 20.2 Versioning Note

Additive future capabilities should prefer non-breaking extensions within `/api/v1` where possible. Breaking contract changes require a new major API version (for example `/api/v2`) with a defined deprecation window.

---

# 18. Admin Panel APIs (Sprint 11)

Admin APIs use Sanctum tokens issued to `admins`, middleware `auth:sanctum` + `admin`, and Hybrid RBAC via `permission:<key>` where applicable. Inactive admins receive `ADMIN_ACCOUNT_INACTIVE` (403).

## 18.1 Authentication

| Method | Path | Notes |
| --- | --- | --- |
| POST | `/api/v1/admin/auth/login` | Email + password against `admins` |
| POST | `/api/v1/admin/auth/logout` | Revoke current token; AuditEvent logout |
| GET | `/api/v1/admin/auth/me` | Current admin profile |

## 18.2 Hybrid RBAC

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/api/v1/admin/permissions` | `roles.manage` |
| PUT | `/api/v1/admin/roles/{role}/permissions` | Super Admin; AuditEvent `role_update` |
| GET | `/api/v1/admin/admins/{admin}/permissions` | `roles.manage` |
| PUT | `/api/v1/admin/admins/{admin}/permissions` | Super Admin; AuditEvent `permission_update` |

Effective permissions = role permissions ∪ direct permissions. Super Admin permissions are implicit and not persisted.

Permission catalog (route-aligned): `products.create`, `products.update`, `products.delete`, `suppliers.manage`, `purchase_orders.manage`, `goods_receipts.manage`, `admins.manage`, `roles.manage`.

## 18.3 Admin CRUD

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/admins` | List/filter; `admins.manage` |
| GET | `/api/v1/admin/admins/{admin}` | Detail + effective permissions |
| POST | `/api/v1/admin/admins` | Super Admin; AuditEvent create; cache forget |
| PUT | `/api/v1/admin/admins/{admin}` | Super Admin; AuditEvent update; cache forget |
| DELETE | `/api/v1/admin/admins/{admin}` | Super Admin soft-delete; AuditEvent delete; cache forget |

## 18.4 Dual Dashboard & Statistics

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/dashboard` | `dashboard_type`, `visible_modules`, `visible_navigation`, cached `statistics` |

## 18.5 Audit Logs

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/v1/admin/audit-logs` | `admins.manage`; event-sourced rows |

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/06_API_Design.md` |
| **Versioned base** | `/api/v1` |
| **Excludes** | Laravel code, migrations, controllers, routes, Flutter, OpenAPI files |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Solution / API Architect |  |  |  |
| Backend Engineering Lead |  |  |  |
| Mobile Engineering Lead |  |  |  |

---

*End of Document — Fayadhowr API Design Specification v1.0*
