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

> **Implementation note (Sprint 21, approved limitation):** the current registration implementation does not yet collect `phone`. Bringing registration up to this spec is outside Sprint 21 scope; until then, phone OTP login applies only to accounts that already have a phone number on file.

## 2.2 Login — Phone (OTP)

Primary login method for Somalia. OTP lifecycle rules: SRS FR-002A–FR-002C; storage: Database Design §3.1.7A (`phone_otps`).

### 2.2.1 Request OTP

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/phone/request` |
| **Auth** | Guest |
| **Purpose** | Issue a 6-digit single-use OTP to the given phone via the SMS abstraction. Also serves as **resend**: a repeat call after the cooldown issues a fresh OTP and invalidates the previous one. |
| **Body** | `phone` (required, E.164; UI default country Somalia `+252`) |
| **Validation** | `phone` — required, valid E.164 format |
| **Response `200`** | Generic acknowledgement: `{ "success": true, "message": "If this phone number is registered, a code has been sent.", "data": { "expires_in": 300, "resend_after": 60 } }` — identical for known and unknown phones (no account-existence leak) |
| **Errors** | `422` `VALIDATION_ERROR` (malformed phone); `429` `OTP_COOLDOWN` (resend within 60 s); `429` `RATE_LIMITED` (over 5 requests/phone/hour or per-IP limit) |
| **Rate limits** | 1 request / 60 s per phone (cooldown); 5 / hour per phone; stricter per-IP throttle |
| **Security** | OTP stored as hash only; previous unconsumed OTP for the same phone/purpose invalidated on each issue; OTP value never included in the response or logs |

> **Implementation note (Sprint 21 final patch):** no SMS is dispatched to phone numbers that have no customer account (delivery cost control). The full OTP lifecycle (record, cooldown, hourly cap) still runs for unknown phones so the response and throttling behavior remain byte-identical — no account enumeration.

### 2.2.2 Verify OTP

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/phone/verify` |
| **Auth** | Guest |
| **Purpose** | Verify the OTP and authenticate |
| **Body** | `phone` (required, E.164), `otp` (required, 6 digits) |
| **Validation** | `phone` — required, valid E.164; `otp` — required, exactly 6 digits |
| **Response `200`** | Access token + customer summary (same shape as email login) |
| **Errors** | `422` `VALIDATION_ERROR`; `401` `OTP_INVALID` (wrong code — attempt counter increments); `401` `OTP_EXPIRED` (past 5-minute window, consumed, or superseded); `401` `OTP_ATTEMPTS_EXCEEDED` (5 failed attempts — OTP invalidated, new request required); `403` `ACCOUNT_SUSPENDED` / blocked business status (same gates as all login methods); `429` `RATE_LIMITED` (per-IP) |
| **Rate limits** | Max 5 failed verification attempts per OTP; per-IP throttle on the endpoint |
| **Security** | Constant-time hash comparison; OTP marked consumed on success (replay protection); sets `phone_verified_at` on first successful verification; updates `last_login_at`; records `login` activity |

## 2.2B Login — Google

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/google` |
| **Auth** | Guest |
| **Purpose** | Authenticate (or auto-register) with a Google ID token from **native** Google Sign-In (Android/iOS); account picker when multiple device accounts |
| **Body** | `id_token` (required — provider ID token) |
| **Validation** | `id_token` — required string; server verifies signature, expiry, and audience (app client IDs) |
| **Account resolution** | (1) existing `users.google_subject` match → login; (2) verified-email match → link `google_subject`, then login; (3) no match → auto-provision `users` + `customer_profiles` (per FR-001), then login |
| **Response `200`** | Access token + customer summary; newly provisioned accounts return the same shape |
| **Errors** | `422` `VALIDATION_ERROR`; `401` `GOOGLE_TOKEN_INVALID` (bad signature / expired / wrong audience); `403` `ACCOUNT_SUSPENDED` / blocked business status; `429` `RATE_LIMITED` |
| **Rate limits** | Per-IP throttle (auth-tier limits, §16.6) |
| **Security** | ID token verified server-side against Google's published keys; `google_subject` is the immutable link key (unique, nullable); no partial account creation on failure; client must never collect Gmail passwords — device Google accounts only |

> **Implementation note (Sprint 21):** server-side verification currently uses Google's `tokeninfo` endpoint (signature and expiry validated by Google; audience checked locally against configured client IDs). Offline JWKS verification (validating signatures locally against Google's published keys) may replace it in the future behind the same verifier interface — no change to authentication business logic.

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
| **Purpose** | Start credential recovery. **Both paths are supported in V1.** Email path issues a single-use hashed reset token (valid 60 minutes) delivered by email. Phone path issues an OTP with purpose `password_reset` (full OTP lifecycle per §2.2 / FR-002A–FR-002B). |
| **Body** | Exactly one of: `email` (valid email) or `phone` (E.164) |
| **Validation** | One identifier required; `email` — valid format; `phone` — valid E.164 |
| **Response `200`** | Generic acknowledgement: `{ "success": true, "message": "If this account exists, recovery instructions have been sent." }` — identical whether or not the account exists (no account-existence leak) |
| **Errors** | `422` `VALIDATION_ERROR`; `429` `RATE_LIMITED` (per-identifier and per-IP throttle); `429` `OTP_COOLDOWN` (phone path resend within cooldown) |
| **Rate limits** | Auth-tier limits (§16.6); phone path additionally inherits OTP cooldown / hourly caps |
| **Security** | Raw tokens/OTPs never persisted (hash only) or logged; issuing a new token invalidates prior unconsumed tokens for the same account |

## 2.5 Reset Password

| Item | Spec |
| --- | --- |
| **Method / Path** | `POST /api/v1/auth/reset-password` |
| **Auth** | Guest |
| **Purpose** | Complete recovery and set a new password |
| **Body** | Identifier (`email` or `phone`, matching the forgot-password request), `token` (email reset token **or** the 6-digit OTP for the phone path), `password`, `password_confirmation` |
| **Validation** | Identifier required; `token` — required; `password` — required, project password policy, must match `password_confirmation` |
| **Response `200`** | `{ "success": true, "message": "Password has been reset." }` — no token issued; the customer logs in with the new password |
| **Errors** | `422` `VALIDATION_ERROR` (including confirmation mismatch); `401` `RESET_TOKEN_INVALID` (unknown/expired/used token or OTP); `429` `RATE_LIMITED` |
| **Rate limits** | Auth-tier limits (§16.6); attempt caps on the underlying token/OTP |
| **Security** | Token compared against stored hash; marked used on success (single-use / replay protection); new password stored via one-way hashing; **all existing access tokens for the account are revoked** so every device must re-authenticate; `password_reset` activity recorded |

## 2.5A SMS Provider Abstraction

OTP delivery is provider-independent by design. No SMS provider is selected in this specification.

| Rule | Detail |
| --- | --- |
| **Contract** | A single application-level SMS sending contract (send to E.164 phone, message body); business logic depends only on this contract |
| **Provider selection** | Configuration-driven; swapping providers must require no changes to authentication business logic |
| **Failure handling** | Provider failures are logged and surfaced as delivery failures; they never leak OTP values and never bypass rate limiting |
| **Non-production** | Local/test environments may use a null/log driver; OTPs remain hash-stored and are never echoed in API responses in any environment |

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

All catalog endpoints in §6.1–§6.4 are **guest endpoints**: authentication is never required, and per-IP rate limiting is required — **60 requests per minute per IP** (§16.6 public tier). The public identifier for a service is its **`slug`**; numeric IDs remain internal and are never used in public paths. Favorites are deferred to the Favorites module sprint — **no catalog payload includes `is_favorite`**. `service_blackout_dates` is out of catalog scope (booking-time concern only).

**Service images contract (all catalog payloads):** images are returned as an `images` object with `thumbnail`, `hero_image`, and `gallery[]` — **absolute URLs only** — derived from `service_media` (Database Design §3.2.5): the primary media supplies `thumbnail` and `hero_image`; remaining media form `gallery[]` in `sort_order`.

## 6.1 List Services

| Item | Spec |
| --- | --- |
| **Method / Path** | `GET /api/v1/services` |
| **Auth** | Guest (no authentication) |
| **Query** | `category_id` (optional, integer); `mode` (optional, `one_time` \| `monthly_contract`); `city` (optional, `Mogadishu` \| `Hargeisa`); `sort` (optional, `display_order` default \| `name` — **no other sort options**); `page`; `per_page` (default **20**, maximum **100**) |
| **Visibility** | Only active (`is_active`), non-soft-deleted services with at least one active mode; default ordering follows catalog display order |
| **Response `200`** | Paginated service cards (§3.4 meta): `slug`, `name`, `short_description`, optional `starting_from_price` + `currency`, active `modes` (with `subtype` where applicable), coverage cities, and `images` (`thumbnail`, `hero_image`, `gallery[]` — absolute URLs) |
| **Errors** | `422` `VALIDATION_ERROR` (invalid filter, sort, or pagination values); `429` `RATE_LIMITED` (per-IP) |
| **Rate limits** | Public per-IP throttle (§16.6) |

## 6.2 Service Details

| Item | Spec |
| --- | --- |
| **Method / Path** | `GET /api/v1/services/{slug}` |
| **Auth** | Guest (no authentication) |
| **Identifier** | `slug` is the public identifier; numeric IDs remain internal |
| **Response `200`** | Full detail — `images` (`thumbnail`, `hero_image`, `gallery[]` — absolute URLs), description, inclusions/exclusions, optional `starting_from_price`, `currency`, service modes/subtypes, coverage cities, and both Book Now and Request Quotation actions |
| **Errors** | `404` `NOT_FOUND` (unknown slug, inactive, or soft-deleted service); `429` `RATE_LIMITED` (per-IP) |
| **Rate limits** | Public per-IP throttle (§16.6) |

> **Deferred fields:** `before_after` and `faq` are **not returned** by Sprint 22 catalog payloads. They will be introduced when the Gallery and FAQ modules are implemented.

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

> **Official V1 Services Catalog Seeder:** the backend ships a seeder that provisions the full official catalog above — service categories, services, service modes, service subtypes, and coverage cities — so the public catalog APIs are never empty on a fresh install. The seeder uses **official placeholder images** for `thumbnail`, `hero_image`, and `gallery` until production assets are available.
>
> **Catalog management:** Admin Services CRUD belongs to **Sprint 29**. Until then, the Official Seeder manages the catalog.

## 6.3 Service Categories

| Item | Spec |
| --- | --- |
| **Method / Path** | `GET /api/v1/service-categories` |
| **Auth** | Guest (no authentication) |
| **Visibility** | Returns **only categories having at least one active service**; empty categories are excluded |
| **Response `200`** | Category list: `slug`, `name`, ordered by catalog display order |
| **Errors** | `429` `RATE_LIMITED` (per-IP) |

## 6.4 Search Services

| Item | Spec |
| --- | --- |
| **Method / Path** | `GET /api/v1/services/search` |
| **Auth** | Guest (no authentication) |
| **Query** | `q` (required, **minimum length 2**); `page`; `per_page` (default 20, maximum 100) |
| **Search fields** | Service **name** and **short description** only |
| **Visibility** | Same rules as §6.1 (active, non-deleted services) |
| **Response `200`** | Paginated service cards (same shape as §6.1); empty result list is `200`, not an error |
| **Errors** | `422` `VALIDATION_ERROR` (missing `q` or fewer than 2 characters); `429` `RATE_LIMITED` (per-IP) |

See also §15 unified search (separate, later scope).

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

Or pre-upload: `POST /api/v1/uploads` then reference the returned upload UUIDs on create (§14).  

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
- `attachment_ids` (optional — upload UUIDs returned by §14 staging)

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

Sprint 12 Notification Architecture is authoritative. Recipients are the authenticated `Admin` or the `CustomerProfile` linked to the authenticated `User`.

## 13.1 List Notifications

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications` | Required |

Paginated; newest first. Query: `status`, `type`, `channel`, `per_page`.  
Payload includes `type`, `channel`, `status`, `title`, `message`, `data`, lifecycle timestamps (`processing_started_at`, `sent_at`, `delivered_at`, `read_at`, `failed_at`).

**Types:** `booking` | `quotation` | `order` | `payment` | `store_order` | `inventory` | `system`  
**Channels:** `in_app` | `email` | `sms`  
**Statuses:** `pending` | `processing` | `sent` | `delivered` | `read` | `failed`

## 13.2 Notification Details

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications/{id}` | Required |

Owner-scoped; non-owned → `NOTIFICATION_NOT_FOUND`.

## 13.3 Mark As Read

| Method | Path | Auth |
| --- | --- | --- |
| `PATCH` | `/api/v1/notifications/{id}/read` | Required |
| `PATCH` | `/api/v1/notifications/read-all` | Required |

Valid transition: `delivered` → `read` (idempotent if already `read`). Mark-all updates delivered rows only.

## 13.4 Unread Count

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notifications/unread-count` | Required |

**Returns:** `{ "unread_count": 3 }` inside `data` (count of owner notifications where `status != read`).

> **V1 rule:** There is **no** customer delete/clear API for notifications. Notification records are permanent business history. Terminal rows may be archived by admin process.

## 13.5 Notification Preferences

| Method | Path | Auth |
| --- | --- | --- |
| `GET` | `/api/v1/notification-preferences` | Required |
| `PUT` | `/api/v1/notification-preferences` | Required |

Per-type channel toggles: `in_app`, `email`, `sms`.

## 13.6 Admin Notification APIs

Require `auth:sanctum` + `admin` + `permission:notifications.manage`.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET/POST/PUT` | `/api/v1/admin/notification-templates` | Template CRUD (no delete) |
| `GET/POST/PUT` | `/api/v1/admin/notification-templates/{id}/translations` | Translation CRUD |
| `GET` | `/api/v1/admin/archived-notifications` | Browse archived terminal notifications |

---

# 14. File Upload APIs

Unified File Upload Service (Sprint 23, approved). **Uploader: authenticated customers only** — admin uploads remain module-specific, and legacy upload implementations (customer attachments, product images, company logo) remain unchanged pending a future migration.

## 14.1 Endpoints

Replace any previous multi-endpoint or image-only upload strategy with a **single** unified upload service:

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/v1/uploads` | Required (customer) | Stage one or more files before attaching them |
| `GET` | `/api/v1/uploads` | Required (owner only) | List the owner's **unattached, non-expired** staged uploads |
| `GET` | `/api/v1/uploads/{uuid}` | Required (owner only) | Stream the file content back to its owner |
| `DELETE` | `/api/v1/uploads/{uuid}` | Required (owner only) | Delete an **unattached** staged upload |

Upload Content-Type: `multipart/form-data`.

**Public identifier:** UUID only. Numeric IDs remain internal and are never exposed in routes or payloads.

**Ownership:** the owner is resolved server-side from the authenticated customer (ADR-001) and is never client-supplied.

### Purpose

Upload files **before** submitting quotation requests.

The upload endpoint returns **uploaded file UUIDs** that are later attached to quotation requests.

### List semantics (`GET /api/v1/uploads`)

- Owner only: returns exclusively the authenticated customer's staged uploads.
- Returns **unattached, non-expired** uploads only (attached and expired uploads are never listed).
- Items use the §14.4 metadata shape (`uuid`, `file_name`, `mime_type`, `media_type`, `file_size_bytes`, `created_at`).
- Paginated per §3.4: default **20** per page, maximum **100**.

### Read semantics (`GET /api/v1/uploads/{uuid}`)

- Owner only; other identities receive `404` (existence is not disclosed).
- File bytes are **streamed by the backend**. Storage paths and disk details are never exposed.

### Delete semantics (`DELETE /api/v1/uploads/{uuid}`)

- Owner only; only **unattached** uploads may be deleted.
- Deleting an upload that is already attached returns `409 Conflict` (`UPLOAD_ATTACHED`).

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

Successful upload returns file metadata including **file UUIDs** used when creating quotation requests.

Logical response shape (inside standard success envelope `data`):

- One or more uploaded file objects, each with at least: `uuid`, `file_name`, `mime_type`, `media_type` (`image` / `video` / `document`), `file_size_bytes`, `created_at`

Storage paths, disks, and numeric IDs are never returned. File content is read exclusively through `GET /api/v1/uploads/{uuid}`.

## 14.5 Limits (Approved V1 Values)

| Limit | Value |
| --- | --- |
| Image, per file | **10 MB** |
| PDF, per file | **20 MB** |
| Video, per file | **100 MB** |
| Maximum files per request | **10** |
| Upload rate limit | **20 uploads/minute/customer** |
| Staged storage quota per customer | **500 MB** (total unattached staged storage; exceeding it → `409` `UPLOAD_STORAGE_LIMIT_EXCEEDED`) |

Limits are **configuration-driven** (environment/config). There is **no Settings UI** for these limits in V1; the Storage Settings category (SRS FR-091.9) does not govern the Unified File Upload Service in V1. The API documents effective limits in validation errors.

## 14.6 Validation Rules

1. MIME type and extension must match the allow-list (images, videos, PDF only).
2. Reject executable or mismatched content sniffing failures.
3. Authenticated customer required for all uploads.
4. EXIF stripping: **deferred** (not in V1).
5. Virus/malware scanning: **deferred** (not in V1); recommended at the storage boundary when introduced.

## 14.7 Storage Strategy

| Concern | Approach |
| --- | --- |
| Storage (V1) | **Local/private disk** — never inside the public web root; S3-compatible object storage later via configuration only |
| Staging | Uploaded files retained as reusable file records identified by returned UUIDs |
| Attachment | File UUIDs referenced when creating quotation requests; persisted as **FK references** (`upload_id`) on `quotation_request_attachments` — upload metadata is never duplicated |
| Access | Owner-only backend streaming (`GET /api/v1/uploads/{uuid}`); storage paths never exposed |
| CDN | Optional for public catalog media (not required for private quotation uploads) |

Catalog service/product media remain admin-managed (not customer upload APIs in v1).

## 14.8 Retention & Cleanup

- Unattached uploads **expire 7 days** after upload.
- A **scheduled job** removes expired uploads (file content and metadata record).
- Attached uploads follow the lifecycle of the record they are attached to and are not subject to staging expiry.
- Expired or deleted UUIDs return `404` on read.

## 14.9 Legacy Upload Implementations

Existing module-specific uploads — customer attachments (admin), product images (admin), company logo (Settings) — **remain unchanged** in V1. Their migration onto the Unified File Upload Service is **deferred until after Backend V1** and is out of Sprint 23 scope.

---

# 15. Search APIs

Powers Home Search Bar under the Hero (Services + Store Products).

## 15.1 Search Services

`GET /api/v1/services/search?q={query}`

Full spec in §6.4 — searches service name and short description; `q` minimum length 2.

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
| Wrong OTP code | `401` | `OTP_INVALID` |
| Expired / consumed / superseded OTP | `401` | `OTP_EXPIRED` |
| OTP failed-attempt cap reached | `401` | `OTP_ATTEMPTS_EXCEEDED` |
| OTP resend requested within cooldown | `429` | `OTP_COOLDOWN` |
| Invalid Google ID token | `401` | `GOOGLE_TOKEN_INVALID` |
| Invalid / expired / used password reset token | `401` | `RESET_TOKEN_INVALID` |

## 16.3 Authorization Errors

| Case | Status | Code |
| --- | --- | --- |
| Not resource owner | `403` | `FORBIDDEN` |
| Suspended customer | `403` | `ACCOUNT_SUSPENDED` |
| Cancel not allowed | `403` or `409` | `ACTION_NOT_ALLOWED` |

## 16.4 Not Found (404)

Unknown ids or inactive public catalog resources not exposed → `404` `NOT_FOUND`.  
Do not leak existence of other customers’ private resources (prefer `404` over `403` where appropriate).

## 16.4A Upload Errors (§14)

| Case | Status | Code |
| --- | --- | --- |
| File type not in allow-list (MIME/extension mismatch included) | `422` | `VALIDATION_ERROR` |
| File exceeds per-type size cap | `422` | `VALIDATION_ERROR` |
| More than 10 files in one request | `422` | `VALIDATION_ERROR` |
| Unknown, expired, or not-owned upload UUID | `404` | `NOT_FOUND` |
| Delete attempted on an attached upload | `409` | `UPLOAD_ATTACHED` |
| Staged storage quota (500 MB) exceeded | `409` | `UPLOAD_STORAGE_LIMIT_EXCEEDED` |
| Upload rate limit exceeded | `429` | `RATE_LIMITED` |

## 16.5 Server Error (500)

Unexpected failures → `500` `SERVER_ERROR` with safe message; detailed diagnostics only in server logs.

## 16.6 Rate Limiting

HTTP `429` `RATE_LIMITED` with `Retry-After` when possible. Stricter limits on auth, payment initialize, and uploads. Public guest catalog endpoints (services, categories, search) require a per-IP throttle even though no authentication is required: **public tier = 60 requests per minute per IP**. Unified upload endpoint: **20 uploads per minute per customer** (§14.5).

---

# 17. Security

## 17.1 Authentication

- Bearer tokens over TLS only
- Secure password hashing (one-way)
- Token revocation on logout
- Lockout / rate limit brute-force login
- OTPs and reset tokens stored as hashes only; raw values never persisted, logged, or echoed in responses
- OTPs are single-use, purpose-bound, expire after 5 minutes, invalidated on reissue, and capped at 5 failed attempts (replay protection)
- Google ID tokens verified server-side (signature, expiry, audience) before any account action
- Successful password reset revokes all existing access tokens for the account
- Recovery and OTP-request endpoints return generic responses that never disclose account existence

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

Allow-list MIME/extensions; size caps; content sniffing; store outside public web root (local/private disk in V1); no scriptable types in v1 images. Uploads are owner-scoped, publicly identified by UUID only, and read exclusively via backend streaming — storage paths are never exposed. Virus scanning and EXIF stripping are deferred beyond V1.

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

## 18.6 Settings & System Configuration (Documentation Only — Not Implemented)

All endpoints require `auth:sanctum` + `admin` middleware. Permissions:

| Permission | Grants |
| --- | --- |
| `settings.view` | Read settings, branches, settings audit logs |
| `settings.manage` | Update settings, upload logo, send SMTP test, create/download backups |
| Super Admin only | Branch activation / set default branch (future), restore from backup |

Sensitive keys (e.g., `smtp.password`) are write-only: accepted on update, never returned by any read endpoint, and masked in audit logs.

Setting keys use the fully-qualified dotted convention `category.key` (e.g., `company.name`, `currency.default`, `tax.rate`, `smtp.host`, `storage.max_upload_size`, `backup.retention_days`), matching the Database Design. Categories follow the canonical order: `company`, `branch`, `currency`, `tax`, `numbering`, `smtp`, `notifications`, `storage`, `localization`, `backup` — with audit logs exposed via the Audit Logs endpoint.

### 18.6.1 Settings

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/settings` | `settings.view` | All categories, grouped in canonical order |
| GET | `/api/v1/admin/settings/{category}` | `settings.view` | One category: `company`, `currency`, `tax`, `numbering`, `smtp`, `notifications`, `storage`, `localization`, `backup` |
| PUT | `/api/v1/admin/settings/{category}` | `settings.manage` | Partial update of keys in the category; every change is audit-logged |
| POST | `/api/v1/admin/settings/{category}/restore-defaults` | `settings.manage` | Resets category values to `default_value` |

**Validation (PUT `/settings/{category}`):**
- `{category}` must be a known category; unknown keys in the payload → 422.
- Per-key type validation against JSON value types (e.g., `tax.rate`: numeric 0–100, max 2 decimals; `currency.decimal_places`: integer in {0, 2}; `storage.allowed_file_types`: array of known extensions; `numbering.*_prefix`: string, max 10, uppercase letters/digits/hyphen; `numbering.auto_numbering`: boolean).
- Sensitive keys are accepted but never echoed back.

**Example — GET `/api/v1/admin/settings/currency`:**

```json
{
  "success": true,
  "message": "Settings retrieved successfully.",
  "data": {
    "category": "currency",
    "settings": {
      "currency.default": "USD",
      "currency.symbol": "$",
      "currency.decimal_places": 2,
      "currency.thousand_separator": ","
    },
    "last_updated_by": { "name": "Ayaan Ali", "role": "admin" },
    "updated_at": "2026-07-17T14:05:00Z"
  }
}
```

**Example — PUT `/api/v1/admin/settings/tax`:**

Request:

```json
{ "tax.default": true, "tax.rate": 5, "tax.mode": "exclusive" }
```

Response `200`:

```json
{
  "success": true,
  "message": "Tax settings updated successfully.",
  "data": {
    "category": "tax",
    "settings": { "tax.default": true, "tax.rate": 5, "tax.mode": "exclusive" }
  }
}
```

### 18.6.2 Logo Upload (Company)

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| POST | `/api/v1/admin/settings/company/logo` | `settings.manage` | Multipart upload; replaces `company.logo` |

**Validation:** `logo` — required file, mime PNG/SVG, max 2 MB, recommended 512×512 px.

Response `200`:

```json
{ "success": true, "message": "Logo uploaded successfully.", "data": { "company.logo": "https://cdn.fayadhowr.com/settings/logo.png" } }
```

### 18.6.3 Branches

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/branches` | `settings.view` | All branches with status and default flag |
| GET | `/api/v1/admin/branches/{branch}` | `settings.view` | Branch detail |
| PATCH | `/api/v1/admin/branches/{branch}/activate` | Super Admin only | Future release; audit-logged; 403 for all other roles |
| PATCH | `/api/v1/admin/branches/{branch}/default` | Super Admin only | Target must be `ACTIVE`, else 422 `BRANCH_NOT_ACTIVE` |

**Business rules (Current Version — V1):**
- Mogadishu (MGQ) is the only operational branch; all transactions belong to the Mogadishu branch. No transaction endpoint accepts a branch parameter in V1.
- Hargeisa (HGA) is displayed as `COMING_SOON` and cannot participate in any transaction.
- Setting a non-`ACTIVE` branch as default → 422 `BRANCH_NOT_ACTIVE`.
- No create/delete endpoints in V1; MGQ and HGA are seeded.
- Multi-branch support may be introduced in a future version without redesigning the module.

**Example — GET `/api/v1/admin/branches`:**

```json
{
  "success": true,
  "message": "Branches retrieved successfully.",
  "data": [
    { "id": 1, "code": "MGQ", "name": "Mogadishu", "city": "Mogadishu", "status": "ACTIVE", "is_default": true },
    { "id": 2, "code": "HGA", "name": "Hargeisa", "city": "Hargeisa", "status": "COMING_SOON", "is_default": false }
  ]
}
```

### 18.6.4 SMTP Test

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| POST | `/api/v1/admin/settings/smtp/test` | `settings.manage` | Sends a test email using the saved SMTP configuration |

**Validation:** `to_email` — required, valid email.

Response `200`:

```json
{ "success": true, "message": "Test email sent successfully.", "data": { "to": "admin@fayadhowr.com" } }
```

Response `502` (provider failure):

```json
{ "success": false, "message": "SMTP connection failed: connection refused.", "error_code": "SMTP_TEST_FAILED" }
```

### 18.6.5 Backup

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/backups` | `settings.view` | List backups (date, size, created by) |
| POST | `/api/v1/admin/backups` | `settings.manage` | Trigger manual backup (async job) |
| GET | `/api/v1/admin/backups/{backup}/download` | `settings.manage` | Signed, time-limited download of the archive |
| POST | `/api/v1/admin/backups/{backup}/restore` | Super Admin only | Destructive; requires `confirmation: "RESTORE"` in the body, else 422 |

**Example — POST `/api/v1/admin/backups`:**

```json
{ "success": true, "message": "Backup started.", "data": { "id": 12, "status": "in_progress", "started_at": "2026-07-17T02:00:00Z" } }
```

### 18.6.6 Settings Audit Logs

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/settings/audit-logs` | `settings.view` | Settings change history; filters: `category`, `changed_by`, `from`, `to` |

**Example — GET `/api/v1/admin/settings/audit-logs`:**

```json
{
  "success": true,
  "message": "Settings audit logs retrieved successfully.",
  "data": [
    {
      "id": 88,
      "category": "tax",
      "key": "tax.rate",
      "old_value": 0,
      "new_value": 5,
      "changed_by": { "name": "Ayaan Ali", "role": "admin" },
      "changed_at": "2026-07-17T14:05:00Z"
    }
  ]
}
```

### 18.6.7 Error Codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `SETTINGS_CATEGORY_NOT_FOUND` | 404 | Unknown settings category |
| `VALIDATION_ERROR` | 422 | Invalid keys or value types |
| `BRANCH_NOT_ACTIVE` | 422 | Non-active branch cannot be set as default |
| `SMTP_TEST_FAILED` | 502 | SMTP provider rejected the test message |
| `BACKUP_RESTORE_NOT_CONFIRMED` | 422 | Missing/incorrect restore confirmation phrase |
| 403 Forbidden | 403 | Missing `settings.*` permission or non–Super Admin on restricted actions |

## 18.7 Customer Management (Documentation Only — Not Implemented)

Admin Panel APIs for the Customer Management module (SRS FR-092). All endpoints require `auth:sanctum` + `admin` middleware and follow the Standard API Response Format (§3). A "Customer" is the `users` + `customer_profiles` pair (ADR-001); resources expose the merged view keyed by Customer Code.

Permissions:

| Permission | Grants |
| --- | --- |
| `customers.view` | List/search customers, view details, timeline, activity history |
| `customers.create` | Create customer accounts from the Admin Panel |
| `customers.update` | Update profile, manage addresses, change `customer_profiles.status` (`ACTIVE` / `INACTIVE` / `BLOCKED`) |
| `customers.delete` | Soft delete customers |
| `customers.restore` | Restore soft-deleted customers (Super Admin only in V1) |
| `customers.notes` | List/add internal customer notes |
| `customers.attachments` | List/upload/download/remove customer attachments |

### 18.7.1 Customer CRUD

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/customers` | `customers.view` | List with search, filters, sorting, pagination (§18.7.2) |
| POST | `/api/v1/admin/customers` | `customers.create` | Create account; Customer Code auto-generated |
| GET | `/api/v1/admin/customers/{customer}` | `customers.view` | Full details incl. business summary and linked-record counts |
| PUT | `/api/v1/admin/customers/{customer}` | `customers.update` | Update profile fields |
| PATCH | `/api/v1/admin/customers/{customer}/status` | `customers.update` | Body `status`: `ACTIVE` / `INACTIVE` / `BLOCKED` only — writes `customer_profiles.status` only |
| DELETE | `/api/v1/admin/customers/{customer}` | `customers.delete` | Soft delete → `customer_profiles.status` `DELETED`; business history retained |
| POST | `/api/v1/admin/customers/{customer}/restore` | `customers.restore` (Super Admin) | Body `status`: `ACTIVE` or `INACTIVE` — restores `customer_profiles.status` only |

**Customer Status clarification:** Customer Status endpoints (`PATCH …/status`, soft delete, and restore) modify **ONLY** `customer_profiles.status`. They **MUST NOT** modify `users.status`. Authentication lifecycle remains managed by the Identity / User Management module. Business operations (booking, quotation, store order) MUST use `customer_profiles.status`; authentication lifecycle MUST use `users.status`. These two fields serve different purposes and must never be treated as interchangeable.

**Validation (create/update):** `full_name` — required, max 150; `phone` — required, unique across customers; `email` — optional, valid email, unique when provided; `gender` — optional, `male`/`female`; `date_of_birth` — optional, valid past date; `preferred_language` — `so`/`en`/`ar`; `tags` — optional array of strings. `customer_number` is never accepted as input (auto-generated `CUS-######`).

**Example — GET `/api/v1/admin/customers/{customer}`:**

```json
{
  "success": true,
  "message": "Customer retrieved successfully.",
  "data": {
    "id": 15,
    "customer_number": "CUS-000015",
    "full_name": "Hodan Abdi",
    "phone": "+252611234567",
    "email": "hodan@example.com",
    "gender": "female",
    "date_of_birth": "1994-03-12",
    "avatar_url": "https://cdn.fayadhowr.com/customers/15/avatar.jpg",
    "preferred_language": "so",
    "status": "ACTIVE",
    "classification": "active_customer",
    "tags": ["priority", "corporate"],
    "registered_at": "2026-01-04T09:30:00Z",
    "last_login_at": "2026-07-15T18:22:00Z",
    "summary": { "bookings": 4, "quotations": 2, "orders": 3, "payments": 5, "total_spent": 1240.00 }
  }
}
```

### 18.7.2 Search, Filters, Sorting, Pagination

`GET /api/v1/admin/customers` query parameters:

| Parameter | Notes |
| --- | --- |
| `search` | Matches Customer Code, Full Name, Phone Number, Email |
| `status` | `ACTIVE` / `INACTIVE` / `BLOCKED` / `DELETED` (`DELETED` returns soft-deleted customers; requires `customers.view`) |
| `registered_from`, `registered_to` | Registration Date range |
| `last_login_from`, `last_login_to` | Last Login range |
| `country`, `state`, `district` | Address-based filters |
| `sort` | `customer_number`, `full_name`, `registered_at`, `last_login_at` (prefix `-` for descending) |
| `page`, `per_page` | Standard pagination (§3.4); soft-deleted customers are excluded unless `status=DELETED` |

### 18.7.3 Customer Timeline & Activity History

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/customers/{customer}/timeline` | `customers.view` | Chronological activity feed; paginated |
| GET | `/api/v1/admin/customers/{customer}/activity-logs` | `customers.view` | Same source with filters: `event_type`, `from`, `to` |

Event types: `registration`, `login`, `profile_update`, `password_reset`, `address_added`, `address_updated`, `booking_created`, `booking_updated`, `booking_completed`, `quotation_requested`, `quotation_accepted`, `store_order_created`, `payment_recorded`, `review_submitted`. Entries are read-only.

**Example — GET `/api/v1/admin/customers/{customer}/timeline`:**

```json
{
  "success": true,
  "message": "Customer timeline retrieved successfully.",
  "data": [
    { "id": 301, "event_type": "payment_recorded", "description": "Payment PAY-2026-000101 recorded", "created_at": "2026-07-10T11:00:00Z" },
    { "id": 287, "event_type": "booking_created", "description": "Booking BK-2026-000045 created", "created_at": "2026-07-01T08:15:00Z" },
    { "id": 122, "event_type": "registration", "description": "Account registered", "created_at": "2026-01-04T09:30:00Z" }
  ]
}
```

### 18.7.4 Addresses

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/customers/{customer}/addresses` | `customers.view` | All addresses incl. inactive |
| POST | `/api/v1/admin/customers/{customer}/addresses` | `customers.update` | Add address |
| PUT | `/api/v1/admin/customers/{customer}/addresses/{address}` | `customers.update` | Edit address |
| PATCH | `/api/v1/admin/customers/{customer}/addresses/{address}/default` | `customers.update` | Set default (clears previous default) |
| PATCH | `/api/v1/admin/customers/{customer}/addresses/{address}/deactivate` | `customers.update` | Mark inactive — addresses are never hard-deleted |

**Validation:** `label` — optional, max 50; `contact_name` — optional, max 150; `phone` — optional; `country`, `state`, `district` — optional strings; `address` — required detail text; `latitude` — optional, numeric −90..90; `longitude` — optional, numeric −180..180; `is_default` — boolean.

### 18.7.5 Notes

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/customers/{customer}/notes` | `customers.notes` | Chronological, newest first |
| POST | `/api/v1/admin/customers/{customer}/notes` | `customers.notes` | Body `note` — required text |

Each note returns: `note`, `created_by` (staff name + role), `created_at`. Notes are internal only and never exposed on customer mobile APIs.

### 18.7.6 Attachments

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/admin/customers/{customer}/attachments` | `customers.attachments` | File Name, File Type, File Size, Uploaded By, Uploaded At |
| POST | `/api/v1/admin/customers/{customer}/attachments` | `customers.attachments` | Multipart upload — Images / PDF / Documents; size per Storage Settings |
| GET | `/api/v1/admin/customers/{customer}/attachments/{attachment}/download` | `customers.attachments` | Authorized download (files are never publicly accessible) |
| DELETE | `/api/v1/admin/customers/{customer}/attachments/{attachment}` | `customers.attachments` | Remove attachment |

**Validation:** `file` — required; mime in allowed Images/PDF/Documents set; max size per `storage.max_upload_size`.

### 18.7.7 Error Codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `CUSTOMER_NOT_FOUND` | 404 | Unknown customer id |
| `CUSTOMER_PHONE_TAKEN` | 422 | Phone number already belongs to another customer |
| `CUSTOMER_EMAIL_TAKEN` | 422 | Email already belongs to another customer |
| `CUSTOMER_INVALID_STATUS` | 422 | Status outside `ACTIVE` / `INACTIVE` / `BLOCKED` (or restore target outside `ACTIVE` / `INACTIVE`) |
| `CUSTOMER_ALREADY_DELETED` | 422 | Delete requested on an already soft-deleted customer |
| `CUSTOMER_NOT_DELETED` | 422 | Restore requested on a customer that is not soft-deleted |
| `VALIDATION_ERROR` | 422 | Field validation failure (standard §3.3 payload) |
| 403 Forbidden | 403 | Missing `customers.*` permission or non–Super Admin on restore |

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
