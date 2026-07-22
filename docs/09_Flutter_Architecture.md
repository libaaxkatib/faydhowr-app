# Flutter Architecture Documentation

## Fayadhowr — Customer Mobile Application (Flutter V1)

| Field | Value |
| --- | --- |
| **Document ID** | `09_Flutter_Architecture` |
| **Product Name** | Fayadhowr |
| **Document Type** | Flutter Architecture & Engineering Standards |
| **Version** | 2.0.1 |
| **Status** | Approved |
| **Architecture State** | Frozen |
| **Implementation Status** | Ready for Flutter Foundation |
| **Date** | 19 July 2026 |
| **Platforms** | Android, iOS |
| **Backend basis** | Backend V1 frozen (Laravel ^13.8 / PHP ^8.3 / PostgreSQL / REST `/api/v1` / Sanctum — per `backend/composer.json`) |
| **Design basis** | `01_Brand_Design_Guide.md`, `05_UI_UX_Design.md`, `08_Figma_Design_System.md`, approved Figma file |
| **API basis** | `06_API_Design.md` (implementation-verified Backend V1) |
| **Audience** | Flutter leads, mobile engineers, QA, architects, product |

---

## Document Rules

This document defines the **Flutter V1 architecture, standards, and engineering policy** for the Fayadhowr customer mobile application.

It intentionally does **not** include:

- Flutter / Dart implementation code
- Generated project scaffolding
- Screen-by-screen widget trees
- OpenAPI client generation artifacts

**Hard constraints:**

1. Backend V1 is **frozen**. Flutter adapts to the API. Do not redesign the backend for mobile convenience.
2. Approved **Figma** is the **only** UI source of truth. Do not redesign layouts, spacing, typography, colors, navigation, or UX.
3. After Architecture Freeze approval, folder structure, architecture style, state management, navigation, DI, and Design System mapping **cannot change without explicit approval**.
4. Documentation updates precede implementation changes (Documentation → Review → Approval → Freeze → Implementation → Testing → Review → Commit → Tag).

---

# 1. Backend Adaptation Summary

Flutter is a **consumer** of Backend V1. The following backend realities are normative for the client.

## 1.1 API surface

| Area | Client obligation |
| --- | --- |
| Base path | HTTPS `/api/v1` |
| Auth | Sanctum **Bearer** personal access tokens (`Authorization: Bearer {token}`) |
| Guest browse | Home, catalog, search, service/product detail — no login |
| Soft auth gate | On `401` for protected actions → login/register → **retry original intent** |
| Identity | Auth principal is `users`; business ownership is `customer_profiles` (server-resolved; never send `customer_profile_id` as a client ownership field) |
| Envelope | Success: `{ success, message, data, meta }` · Error: `{ success, message, error_code, errors? }` |
| Pagination (canonical) | `data.items` + `meta.{current_page, per_page, total, last_page}` |
| Pagination (legacy) | Some older list endpoints nest `pagination` inside `data` — **absorb in one parser**; do not “fix” the API |
| Money / dates | Decimal money with `currency`; ISO-8601 UTC timestamps |
| Keys | JSON `snake_case` |
| Rate limits | Respect `429` / `Retry-After`; notable: `public-catalog` 60/min/IP, auth throttles, uploads throttle |
| Server flags | e.g. quotation `can_accept` / `can_discuss` — **never re-derive** business rules client-side |
| Favorites on Home | Home never returns `is_favorite` or `favorites_count`; resolve hearts from authenticated favorites list |
| Recent search | Device-local only; backend stores nothing |

## 1.2 Authentication model (critical)

- Tokens are Sanctum personal access tokens.
- Backend V1 has **no refresh-token endpoint** and Sanctum expiration may be unset in current deployment.
- Client strategy: store token securely; on **`401` / `UNAUTHENTICATED`**, clear local session and route to login (soft return-to-intent).
- **Do not invent a refresh-token queue** against a frozen API that does not support it.
- Password reset **revokes all tokens** — all devices must re-authenticate.

## 1.3 Core domain flows (client mapping)

| Domain | Client notes |
| --- | --- |
| **Home** | Prefer aggregate `GET /home` for first paint; section endpoints for lazy/partial refresh; use `generated_at` / `cache_expires_at` for cache freshness |
| **Search** | `GET /search`, `/search/suggestions`, `/products/search`; `q` min length 2; suggestions max 10; ranking is server-side |
| **Uploads** | Staged upload (`POST /uploads`) → attach UUIDs to quotation/discussion/etc.; 7-day staged retention; quotas and per-type size caps |
| **Quotations** | Draft → attach → Submit (locks); discuss + accept latest revision per **server flags**; no customer Reject |
| **Bookings** | Soft auth; media rules per API (images/videos); cancel per policy |
| **Store** | Selling price always visible; cost price never; stock states including Out of Stock remain visible |
| **Payments** | Initialize → **no live gateway in V1**, admin-verified confirmation → status view; never trust client for paid state (see §1.3A) |
| **Notifications** | List/read/preferences; device registration for push when enabled |

## 1.3A Payment Confirmation Model (V1 — critical)

- Backend V1 integrates **no real-time payment gateway**. No V1 payment method is confirmed by an instant provider callback.
- Supported V1 methods: **EVC Plus, eDahab, Bank Transfer, Cash on Delivery** (store orders), **Cash on Service** (cleaning services).
- Flow: the customer submits/initiates a payment → the payment sits in an awaiting-confirmation window (backend lifecycle `pending` / `initialized` / `processing`) → the Fayadhowr team manually verifies receipt/collection through the Admin Panel → the backend moves the payment to a terminal state, either **paid** (approved) or **failed** (rejected).
- Client responsibility: treat the awaiting-confirmation window as a **first-class UI state**, not a transient loading spinner. Present it to the customer with clear copy such as "Pending Verification" while the backend status is `pending`/`initialized`/`processing`, and resolve to an approved/paid or rejected/failed outcome once the backend transitions the payment to its terminal state.
- Design payment screens, notifications, and history views for **asynchronous, admin-paced** confirmation — verification may take hours, not seconds. Do not poll aggressively and do not imply gateway-speed confirmation.
- **Do not** build any online/live-gateway payment flow (redirect, SDK checkout, instant callback). None exists in Backend V1 and none is authorized here.

## 1.4 Known API adaptation (not redesign)

| Issue | Flutter response |
| --- | --- |
| Mixed pagination shapes — **confirmed in Backend V1 source**: canonical `meta`+`data.items` (Home/Search) vs. legacy `data.items`+`data.pagination` (Bookings, Orders, Payments, Quotations, Notifications, Products, and most other customer list endpoints) | Single `EnvelopeParser` / `PagedResult` normalizer handling both shapes |
| Some framework errors historically lack full envelope | Map HTTP status + body defensively; prefer `error_code` when present |
| Inactive product visibility quirks for authenticated users | Follow public catalog rules for customer app; do not build admin catalog UI |
| Rate limits on public/search/upload | Client-side pacing, caching, and retry-with-backoff for safe GETs only |

If a **blocking** API defect is discovered during implementation, **document and escalate first**. Do not silently invent client-side business rules or request casual backend redesign.

---

# 2. Recommended Flutter Architecture

## 2.1 Decision: Hybrid (Feature-first + Clean layers + MVVM)

| Layer | Choice |
| --- | --- |
| **Top-level organization** | Feature-first |
| **Intra-feature structure** | Clean Architecture (`data` / `domain` / `presentation`) |
| **Presentation pattern** | MVVM (Riverpod Notifiers as ViewModels) |

### Why this combination

1. **Feature-first** mirrors Backend V1 modules (auth, home, catalog, booking, quotation, store, payment, favorites, notifications, profile) and scales for multi-year parallel delivery.
2. **Clean layers** quarantine Dio/JSON/envelope quirks in `data`, keep business rules in `domain`, and keep UI dumb in `presentation` — essential because the backend is frozen and imperfectly consistent at the wire level.
3. **MVVM** maps naturally to Riverpod `AsyncNotifier` / `Notifier` and Material screen rebuilds without Bloc’s event boilerplate for every CRUD screen.
4. **KISS guardrail:** use-cases are **optional**. Introduce a use-case only when orchestration is non-trivial (checkout, quotation submit/accept, staged upload + attach). Simple reads may go ViewModel → repository.

### Rejected as sole architecture

| Option | Why not alone |
| --- | --- |
| Pure technical layers (`screens/`, `services/`) | Collapses under growth; cross-feature coupling |
| Pure Clean everywhere | Use-case explosion for trivial screens |
| GetX-style all-in-one | Couples DI/nav/state; weak long-term testability |
| Bloc-only | Excellent for event-heavy domains; heavier than needed for this CRUD-over-REST app |

---

# 3. Folder Structure

Target package root: `mobile/` (or `app/` — finalize at project bootstrap; structure below is normative).

```text
lib/
├── main_dev.dart
├── main_staging.dart
├── main_prod.dart
├── app/
│   ├── app.dart                 # MaterialApp.router
│   ├── bootstrap.dart           # flavor init, DI, error zone, storage
│   └── observers.dart           # ProviderObserver / analytics hooks
├── core/
│   ├── config/                  # AppConfig, flavors, feature flags, API base URLs
│   ├── network/                 # Dio, interceptors, envelope parser, API paths
│   ├── error/                   # Failure types, mappers, result types
│   ├── storage/                 # Secure storage, prefs, Drift adapters
│   ├── router/                  # GoRouter, routes, guards, shell
│   ├── localization/            # Locale controller, RTL helpers
│   ├── theme/                   # Tokens, ThemeData, ThemeExtensions (from Figma)
│   ├── di/                      # Root providers / overrides
│   ├── utils/                   # Formatters, validators, extensions
│   ├── constants/               # Durations, keys, limits mirroring API caps
│   └── a11y/                    # Semantics helpers, tap-target constants
├── shared/
│   ├── widgets/                 # Design-system widgets (see doc 10)
│   ├── models/                  # PagedList, Money, cross-feature VOs
│   └── media/                   # Image/video display helpers (cached)
├── features/
│   ├── auth/
│   ├── home/
│   ├── search/
│   ├── catalog/                 # services + categories
│   ├── store/                   # products, cart, checkout
│   ├── booking/
│   ├── quotation/
│   ├── payment/
│   ├── favorites/
│   ├── notifications/
│   ├── profile/                 # profile, addresses, settings, devices
│   └── uploads/                 # staged upload orchestration (shared domain capability)
│       ├── data/
│       │   ├── dtos/
│       │   ├── datasources/     # remote + local
│       │   └── repositories/
│       ├── domain/
│       │   ├── entities/
│       │   ├── repositories/    # abstract
│       │   └── usecases/        # optional
│       └── presentation/
│           ├── providers/       # ViewModels / Notifiers
│           ├── screens/
│           └── widgets/
└── l10n/
    ├── app_en.arb
    ├── app_so.arb
    └── app_ar.arb               # prepared for future Arabic
```

## 3.1 Folder responsibilities

| Folder | Responsibility |
| --- | --- |
| `app/` | Application composition root; no business rules |
| `core/` | Cross-cutting infrastructure; **must not** depend on features |
| `shared/` | Reusable UI + value objects with **no** feature business rules |
| `features/*` | Vertical slices; depend on `core` + `shared` only |
| `features/*/data` | DTOs, Dio data sources, Drift cache, repository implementations |
| `features/*/domain` | Entities, repository contracts, optional use-cases |
| `features/*/presentation` | Notifiers, screens, feature widgets |
| `l10n/` | ARB sources for `gen_l10n` |

### Dependency rule

```text
presentation → domain ← data
       ↘      ↑      ↙
         core / shared
```

Features **must not** import another feature’s `data` or `presentation`. Cross-feature needs go through `domain` contracts in `shared` or a dedicated capability feature (e.g. `uploads`).

---

# 4. State Management

## 4.1 Decision: Riverpod 2 + `riverpod_generator`

**One solution only.** Do not mix Bloc/GetX/Provider with Riverpod.

### Why

- Combines **state + DI** with compile-time safety
- `AsyncValue` models loading/data/error for API screens
- Provider overrides enable clean unit/widget tests
- `family` + auto-dispose fit detail screens (`serviceProvider(slug)`)
- Lower boilerplate than Bloc for CRUD-heavy catalog/commerce apps

### Pros

Type-safe, context-free reads, excellent async ergonomics, strong community longevity, first-class invalidation/caching patterns.

### Cons

Learning curve (`watch` vs `read` vs `listen`); code-gen build step; team must enforce conventions in review.

### Long-term maintenance

Prefer `@riverpod` code generation, immutable state (`freezed` where helpful), and feature-scoped providers. Document naming: `*Controller` / `*Notifier` for ViewModels; never put Dio calls in widgets.

---

# 5. Dependency Injection

## 5.1 Decision: Riverpod as the DI container

Do **not** add `get_it` / `injectable` unless a future shared Dart package cannot depend on Riverpod.

| Binding | Mechanism |
| --- | --- |
| Dio / AppConfig / storage | Root providers in `core/di` |
| Repositories | Interface provider → impl provider (overridable in tests) |
| ViewModels | `@riverpod` Notifiers |
| Flavors | Override `appConfigProvider` in `main_*.dart` |

---

# 6. Networking

## 6.1 Stack

- **HTTP client:** Dio
- **One shared Dio instance** (Riverpod), configured per flavor
- Feature **RemoteDataSources** call Dio; UI never calls Dio

## 6.2 Envelope & pagination

Implement a single **`ApiEnvelope` parser** that:

1. Requires `success` boolean when present
2. Reads `data`, `message`, `error_code`, `errors`
3. Normalizes pagination from either:
   - Canonical: `meta` + `data.items` (confirmed in Backend V1: Home/Search aggregation)
   - Legacy: `data.items` + `data.pagination` (confirmed in Backend V1: Bookings, Orders, Payments, Quotations, Notifications, Products, and most other customer list endpoints — this is the more common shape, not an edge case)
4. Exposes a uniform `PagedResult<T>` to the domain/presentation layers

## 6.3 Interceptor stack (order)

1. **Headers** — `Accept: application/json`, `Accept-Language`, optional `X-App-Version`
2. **Auth** — attach Bearer token from secure storage when present
3. **Retry** — exponential backoff for idempotent **GET** on timeouts / 502 / 503 / 504; for **429** honor `Retry-After`; **never** auto-retry non-idempotent POST/PUT/PATCH/DELETE (bookings, payments, checkout, uploads attach)
4. **Error mapping** — DioException → typed `Failure` (§11)
5. **Logging** — debug/staging only, production-safe by default:
   - Standard log levels (`debug`/`info`/`warning`/`error`); release builds ship `warning`+ only, no verbose/debug output.
   - Never log request/response bodies, Bearer tokens, OTPs, or passwords.
   - Never log personal data (name, phone, email, address) or any other sensitive field.
   - Optionally attach a per-request correlation identifier (e.g. a generated request ID header) to support cross-referencing a support ticket with backend logs — the identifier itself must carry no PII.

## 6.4 Auth handling

| Event | Behavior |
| --- | --- |
| Missing token on protected call | Expect `401` → soft gate UI |
| Invalid/revoked token | Clear secure storage + auth state → login with return-to-intent |
| Successful login/register | Persist token; retry pending intent |

No refresh-token mutex (API does not provide refresh).

## 6.5 Timeouts

| Operation | Guidance |
| --- | --- |
| Connect | ~10s |
| Receive (JSON) | ~20s |
| Upload send | Higher, size-aware; cancelable |
| Search-as-you-type | Short receive + `CancelToken` on new keystroke |

## 6.6 Upload networking

Multipart to staging endpoint; progress via Dio `onSendProgress`; map quota/validation `error_code`s to UX. See §10.

---

# 7. Navigation

## 7.1 Decision: GoRouter

### Why

- Official Navigator 2.0 router with deep links
- Shell routes map cleanly to Figma bottom nav: **Home · Services · Store · Cart · Account**
- Redirect guards integrate with Riverpod auth state
- Long-term first-party support preferred for multi-year enterprise apps

### Architecture

- Named routes + typed path parameters
- Auth redirect: unauthenticated access to protected routes → login → restore location
- Nested stacks per tab where Figma/UX Flow require it
- Deep links — the full approved entry-point matrix (`05_UI_UX_Design.md` §6.3), no new destinations invented:

| Source | Target |
| --- | --- |
| Push / in-app notification | Booking, Quotation, Order, or Payment detail context |
| Payment success CTA | Entity detail (the paid Booking/Order) |
| Search result | Service or Product Details |
| Home section "See all" | Services, Store, Gallery, Reviews, or FAQ list |

  Deep links to protected entities pass through the same soft-auth gate as any other protected route (§6.4) — login, then resume the original deep-link destination.

**Do not** change bottom navigation destinations vs approved Figma / UI/UX Spec.

---

# 8. Local Storage

| Technology | Use for | Do not use for |
| --- | --- | --- |
| **flutter_secure_storage** | Sanctum access token; any secret | Prefs, cache, catalogs |
| **shared_preferences** | Locale, theme mode, onboarding flags, **recent searches**, last UI prefs | Tokens, PII caches that need encryption |
| **Drift (SQLite)** | Offline-read cache: home, catalog, product/service detail, histories snapshots | Source of truth for payments/bookings mutations |

**Why Drift over Isar/Hive:** relational queries, migrations, long-term maintenance certainty for enterprise. Hive may be used only for trivial opaque blobs if ever needed — default is Drift.

---

# 9. Offline Strategy

## 9.1 Offline **read** (supported)

- Home aggregate/sections (respect server cache metadata)
- Service/product catalog and details previously fetched
- FAQ / contact snapshot
- Customer’s previously loaded bookings/quotations/orders lists (stale-while-revalidate)

## 9.2 Offline **write** (not supported in V1)

- Login/register/OTP
- Booking create/cancel
- Quotation create/submit/accept/discuss
- Cart mutations that must sync immediately before checkout
- Checkout / payment initialize
- Uploads and attachments
- Favorites mutations may optimistic-UI **only if** online confirmation is required before trusting server state; prefer online-required for V1 simplicity

## 9.3 Sync strategy

- **Pull-based revalidation** (stale-while-revalidate)
- Use Home `generated_at` / `cache_expires_at` when present
- Connectivity via `connectivity_plus` → clear offline banners; no conflict-resolution engine
- Server remains source of truth for all money and scheduling state

---

# 10. Upload Architecture

Align with Backend Unified Upload Service (staged UUID → attach).

| Concern | Policy |
| --- | --- |
| Types | Images, videos (and PDF where quotation API allows) — enforce server allowlists client-side for UX |
| Caps | Mirror config: image 10MB, document 20MB, video 100MB; max files/request; staged quota |
| Compression | Compress images before upload (`flutter_image_compress`); show size warnings |
| Video | Validate size; thumbnail preview; avoid heavy client transcoding in V1 |
| Progress | Per-file + aggregate in ViewModel |
| Retry | Per-file safe retry for staging; attach only successful UUIDs |
| Background | Background-capable uploader for large videos; respect upload rate limit |
| Expiry UX | Communicate 7-day staged retention and quota |

---

# 11. Global Error Handling

## 11.1 Typed failures

Sealed/exhaustive `Failure` set in `core/error`:

| Type | Typical source |
| --- | --- |
| `NetworkFailure` | Offline, timeout, DNS |
| `ValidationFailure` | `422` + `errors` map |
| `AuthFailure` | `401` / `UNAUTHENTICATED` |
| `ForbiddenFailure` | `403` |
| `NotFoundFailure` | `404` |
| `ConflictFailure` | `409` |
| `RateLimitFailure` | `429` (+ retry-after) |
| `ServerFailure` | `5xx` |
| `UnknownFailure` | Fallback |

Map from `error_code` when present; fall back to HTTP status.

## 11.2 Presentation rules

| Failure | UX |
| --- | --- |
| Validation | Inline field errors (Figma Text Field error state) |
| Auth | Soft login gate + return-to-intent |
| Rate limit | Calm message + retry timing |
| Network | Preserve form data where safe; retry CTA |
| Server/unknown | Localized generic + retry; log to crash reporter |

Global zone + Flutter error hooks → crash reporter (PII scrubbed). Never show red error screens in production.

---

# 12. Theme Architecture

- **Material 3** as component substrate
- **Tokens from Brand + Figma DS** (`08_Figma_Design_System.md`) via `ThemeExtension`
- Light theme required for V1 brand canvas (`#F8FAFC` / white cards)
- Dark theme: implement token mapping only if approved Figma provides dark variants; **do not invent** dark palettes
- Typography: **Plus Jakarta Sans** — Heading 1/2/3, Body, Body Small, Caption, Button, Price, Subtitle
- Spacing: **8px grid**; control height **48**; radius **12** (16 for media cards)
- Elevation: Level 0–1 per Figma (prefer border + light elevation)

The Flutter implementation must follow the approved component definitions, design tokens, spacing, typography, color system, and component specifications documented in `docs/08_Figma_Design_System.md`. Flutter widgets should implement those approved specifications without introducing alternative design definitions.

---

# 13. Responsive Design

| Approach | Decision |
| --- | --- |
| Strategy | Breakpoint + constraint-based layouts |
| Devices | Small phones, large phones, tablets, iPhones |
| Avoid | Global screen-util scaling as primary strategy |
| A11y | Honor system text scale |

Breakpoints (logical): compact phone / medium-large phone / tablet. Adapt structure (list → grid columns, optional master-detail on tablet) **without changing Figma visual language** — scale layout density, not brand tokens arbitrarily.

---

# 14. Localization

| Language | V1 |
| --- | --- |
| English | Required |
| Somali | Required |
| Arabic | Prepared (ARB + RTL) |

- Tooling: Flutter `intl` + ARB + `gen_l10n`
- Persist locale in shared_preferences
- Send `Accept-Language` on API calls
- Build **RTL-safe** widgets from day one (`EdgeInsetsDirectional`, start/end alignment)
- Externalize all user strings including mapped `error_code` messages

---

# 15. Security Strategy

| Control | Policy |
| --- | --- |
| Token storage | `flutter_secure_storage` only |
| Transport | HTTPS only; cleartext disabled |
| Certificate pinning | **Required for production** (SPKI pins + backup pin; per-flavor) |
| Secrets | No secrets in source; flavor/`dart-define` for non-secret config only |
| Logging | Never log tokens, OTPs, passwords, full PAN-like data |
| Screenshots | Optional `FLAG_SECURE` / obscure on payment & sensitive profile screens only |
| Release | Obfuscation + split debug info; upload symbols to crash reporter |
| Root/jailbreak | Optional soft signal on payment; avoid hard-blocking false positives in V1 |

---

# 16. Testing Strategy

| Layer | What to test |
| --- | --- |
| **Unit** | Envelope/pagination parser; Failure mapping; repositories (mocked Dio); use-cases; Notifiers |
| **Widget** | Design-system components; screen states (loading/empty/error/data); forms; RTL; a11y labels |
| **Integration** | Critical journeys against staging/mock: auth soft-gate, home load, book/quote happy paths, staged upload attach, offline catalog read |

**Contract verification (mandatory):** integration testing must also include a periodic, CI-scheduled pass against a **live staging environment running Backend V1** — not mocks alone — asserting that the envelope, pagination shapes (§6.2), and status-code/`error_code` contract this document assumes still hold. This directly mitigates the "Contract drift between Flutter and API" risk named in `07_Project_Roadmap.md` §7.1.

Coverage priority: `domain` + `data` parsers first. Golden tests for core DS components after Figma freeze.

---

# 17. Build Configuration (Flavors)

| Flavor | Purpose |
| --- | --- |
| **dev** | Local/dev API, verbose logs, relaxed pinning |
| **staging** | Staging API, crash reporting on, internal distribution |
| **prod** | Production API, pinning + obfuscation, store builds |

Native Android product flavors + iOS schemes; separate `main_*.dart`; distinct applicationId/bundleId suffixes so all three coexist. Use `--dart-define-from-file` for base URL, pins, DSN.

---

# 18. Package Policy & Recommended Packages

**Install only what is necessary.** Prefer Flutter SDK when sufficient. No duplicate packages for the same concern.

| Package | Purpose | Alternative | Why chosen |
| --- | --- | --- | --- |
| `flutter_riverpod` + generator | State + DI | Bloc, Provider, GetX | One system; best fit for this app |
| `dio` | HTTP + interceptors + upload progress | `http` | Interceptors/retry/progress required |
| `go_router` | Navigation | auto_route | First-party longevity + auth redirects |
| `drift` + sqlite | Offline-read cache | Isar, Hive | Enterprise SQL stability |
| `flutter_secure_storage` | Token vault | — | Platform secure storage |
| `shared_preferences` | Small prefs | — | Standard |
| `intl` / gen_l10n | Localization | easy_localization | Compile-time ARB safety |
| `cached_network_image` | Image cache | Manual | Disk+memory cache |
| `connectivity_plus` | Offline detection | — | Standard |
| `flutter_image_compress` | Upload size control | Server-only | Bandwidth + 10MB cap UX |
| Background upload lib (e.g. `background_downloader`) | Large video uploads | Foreground-only | Survives backgrounding |
| `freezed` + `json_serializable` | Immutable DTOs/state | Hand-written | Fewer serialization bugs |
| Crash reporter (`sentry_flutter` **or** Crashlytics) | Production diagnostics | None | Pick **one** |
| `http_mock_adapter` (dev) | Dio tests | Manual mocks | Dio-native |

**Explicitly avoided:** GetX, get_it/injectable (redundant), flutter_screenutil as primary responsive strategy.

Firebase Messaging (or equivalent) is expected when push is enabled against backend device APIs — add only when implementing notifications push.

---

# 19. Performance Strategy

1. **Lazy loading** — `ListView.builder` / slivers; never build unbounded lists
2. **Pagination** — shared paging Notifier using normalized `PagedResult`
3. **API caching** — Drift SWR + Riverpod keepAlive for hot screens; respect rate limits
4. **Images** — cache + decode at display size; prefer thumbnails from API when available
5. **Rebuilds** — `ref.watch(provider.select(...))`; prefer `const` widgets
6. **Home** — aggregate first paint; section endpoints for secondary loads
7. **Memory** — dispose controllers; cancel Dio tokens; avoid retaining large video bytes

---

# 20. CI/CD

## 20.1 Recommendation

| Track | Tooling |
| --- | --- |
| PR checks | GitHub Actions: analyze, format, unit/widget tests, build dev APK |
| Android release | Fastlane `supply` + signed AAB |
| iOS release | Fastlane `match` + `gym` + TestFlight |
| Alternative | **Codemagic** acceptable if team prefers managed Flutter CI (especially iOS signing) |

## 20.2 Pipeline stages

1. Analyze + format gate  
2. Unit/widget tests + coverage threshold on `domain`/`core`  
3. Flavor builds (dev smoke; staging on `develop`; prod on tags)  
4. Symbol upload for crash reporter  
5. Store/internal distribution  

Coordinate versioning with Backend release tags for integration milestones.

---

# 21. Figma Design Integration (Mandatory)

| Rule | Statement |
| --- | --- |
| Source of truth | **Approved Figma** (built from `08_Figma_Design_System.md` + screen designs) |
| Flutter role | Implement exactly — tokens, components, spacing, type, color, navigation, UX |
| Forbidden | Redesign screens, alter layouts, invent colors, change nav IA, “improve” UX unilaterally |
| Difficulty | If Figma is ambiguous or conflicts with accessibility/platform guidelines, **stop and escalate** — explain first; never silent redesign |
| Design System | Flutter DS widgets map 1:1 to Figma component names/variants |

Basis docs: Brand Guide, UI/UX Spec, Figma Design System Spec, approved Figma library + screens.

---

# 22. Accessibility (A11y)

| Requirement | Practice |
| --- | --- |
| Dynamic text | Do not lock text scale; layouts must reflow |
| Contrast | Meet Brand/Figma colors; verify WCAG AA for text on surfaces |
| Screen readers | Semantics labels on icons, hearts, nav, form fields |
| Tap targets | Minimum ~48×48 logical px (aligns with 48 control height) |
| RTL | Directional layout from day one |
| Forms | Labels always visible; error text associated with fields |
| Bottom nav | Always show labels (per Figma DS) |

---

# 23. Coding Standards

- SOLID, DRY, KISS, Clean Code
- Feature-first separation of concerns
- No business rules in widgets
- No Dio/JSON in presentation
- Reuse Design System widgets — **no one-off duplicated UI**
- Prefer composition over deep inheritance
- Explicit types at public APIs; avoid `dynamic` in domain
- No TODOs left in production paths without ticket reference
- Comments only for non-obvious rationale (prefer self-explanatory names)
- Match repository PR discipline: docs before code for architectural changes

---

# 24. Project Standards

| Standard | Value |
| --- | --- |
| Codebases | **One** Flutter codebase → Android + iOS |
| Platform code | Minimal; isolate behind interfaces |
| Quality bar | Production-grade — no temporary hacks |
| Package policy | Justified, minimal, non-duplicative |
| Documentation policy | Update docs before architectural change |
| Review policy | Documentation → Review → Approval → Freeze → Implementation → Testing → Review → Commit → Tag |

---

# 25. Flutter Architecture Freeze Policy

This Flutter Architecture is frozen following approval of this document together with the approved Figma Design System (`docs/08_Figma_Design_System.md`) and the project's governance process.

**Frozen without approval:**

- Overall architecture (Hybrid Feature-first + Clean + MVVM)
- Folder structure
- State management (Riverpod)
- DI approach (Riverpod)
- Navigation (GoRouter)
- Design System token/component mapping
- Package core set (additions require ADR-style justification)

**Backend remains frozen independently.** Flutter freeze does not authorize API redesign.

---

# 26. Enterprise Readiness Report

| Dimension | Assessment | Notes |
| --- | --- | --- |
| Architecture | Strong | Hybrid scales multi-year |
| Backend alignment | Strong | Explicit adaptation layer for envelope/auth |
| Design fidelity | Strong | Figma-mandatory policy |
| Security | Strong if pinning + secure storage enforced | Prod checklist required |
| Offline | Appropriate | Read-mostly; no dangerous offline writes |
| Testability | Strong | Riverpod overrides + parser tests |
| CI/CD | Ready when Fastlane/Codemagic configured | iOS signing is critical path |
| Maintainability | High | Feature slices + DS reuse |
| **Overall** | **Enterprise-ready for Flutter V1 kickoff after Freeze** | Pending approval |

---

# 27. Risks

| Risk | Impact | Mitigation |
| --- | --- | --- |
| Envelope/pagination inconsistency leaks into UI | Widespread brittle parsing | Single parser + unit tests |
| Invented refresh-token flow | Dead code / false security | Follow Sanctum Bearer + re-login |
| Figma drift vs implementation | UX/brand debt | Component gallery + golden tests + design review |
| Offline writes for payments/bookings | Double charge / bad slots | Forbid offline mutations |
| Upload failures on video | Quotation friction | Compression, background upload, clear quota UX |
| Rate-limit storms on home/search | Poor UX | Cache + debounce + retry policy |
| RTL retrofit cost | Arabic delay | RTL from sprint 1 |
| Over-abstraction | Slow delivery | Optional use-cases rule |

---

# 28. Recommendations

1. Approve and **Freeze** this architecture + Design System mapping before scaffolding.
2. Start with a **walking skeleton**: flavors + Dio envelope + GoRouter shell + theme tokens + Auth feature against real Backend V1.
3. Implement Design System widgets **before** feature screens (Figma library → Flutter DS → screens).
4. Keep a living **API adaptation appendix** for legacy pagination and any non-envelope errors.
5. Prefer Codemagic **or** invest early in Fastlane `match` — do not defer iOS signing.
6. Add push (`firebase_messaging` or equivalent) only with Notifications module, not earlier.

---

# 29. Flutter V1 Architecture Summary

> **Hybrid architecture:** Feature-first modules, each with Clean Architecture layers (`data` / `domain` / `presentation`) and MVVM presentation via **Riverpod**.  
> **Networking:** Dio + envelope/pagination normalizer + typed Failures; Bearer Sanctum; no refresh inventiveness; GET/429 retry only.  
> **Navigation:** GoRouter shell matching Figma bottom nav.  
> **Storage:** Secure storage (token), SharedPreferences (prefs/recent search), Drift (offline reads).  
> **Offline:** Read-mostly SWR; online-only for money, booking, quotation, uploads.  
> **Uploads:** Staged UUID → attach; compression, progress, retry, background for large video.  
> **UI:** Material 3 + ThemeExtensions from Brand/Figma; **Figma is absolute UI truth**.  
> **i18n:** EN + SO required; AR prepared; RTL-safe.  
> **Flavors:** dev / staging / prod.  
> **CI/CD:** GitHub Actions + Fastlane (or Codemagic).  
> **Standards:** SOLID/DRY/KISS, DS reuse, docs-first, production quality only.

---

# 30. Flutter Architecture Freeze

## 30.1 Freeze Declaration

This architecture has completed formal technical review (Flutter Architecture Review Report) and all approved review findings — the V1 payment confirmation model, the confirmed pagination shapes, expanded logging guidance, mandatory contract verification, the full deep-link matrix, and the corrected Laravel/PHP version reference — have been incorporated into this document (v2.0).

This architecture has been **formally approved** and is now **FROZEN** for Backend V1. It is the mandatory implementation reference for all Flutter development on this project. Flutter Foundation and all subsequent feature work must follow this architecture as written. Any future architectural change requires review and approval before implementation — see §30.2 Change Control.

## 30.2 Change Control

Architecture changes are **not permitted** during normal feature development. Feature work that appears to require a change to this document must pause and escalate rather than deviate silently.

Any change to the frozen architecture requires, in order:

1. A documented change proposal (what, why, impact)
2. Technical review
3. Approval
4. A documentation update to this file
5. Architecture re-freeze, if the change is material

## 30.3 Source of Truth & Conflict Resolution

This document is the **authoritative Flutter Architecture reference**. Implementation must remain consistent with: `00_Project_Constitution.md`, `00_AI_PROJECT_CONTEXT.md`, `02_SRS.md`, `06_API_Design.md`, `03_Database_Design.md`, `04_UX_Flow.md`, `05_UI_UX_Design.md`, `01_Brand_Design_Guide.md`, `08_Figma_Design_System.md`.

If a conflict is found between sources, it is resolved in this order, highest first:

1. **Backend implementation (Backend V1)** — the actual, running API
2. **Approved business documentation** (Constitution, SRS, Database Design, API Design, UX Flow)
3. **Approved Figma** — the UI Source of Truth
4. **This Flutter Architecture document**

Developers must **never** resolve a conflict by assumption. Stop and escalate to the Project Owner per the AI/Developer Rules in `00_AI_PROJECT_CONTEXT.md` §19–§20.

## 30.4 Definition of "Frozen"

**Frozen** means:

- The architecture decisions in this document (architecture style, folder structure, state management, DI, networking, navigation, storage, offline strategy, upload architecture, error handling, theming, package selection — see §25 for the itemized list) are **approved and binding**.
- Implementation **must follow** these decisions as written.
- **Clarifications** on how to apply a frozen decision are allowed and expected.
- **Architectural redesign is not allowed** without going through Change Control (§30.2).

---

## Document Control

| Version | Date | Change |
| --- | --- | --- |
| 1.0 | 19 July 2026 | Initial Flutter Architecture Documentation for V1 Freeze review |
| 1.1 | 19 July 2026 | Applied approved Architecture Review findings: clarified V1 payment confirmation model (no live gateway, admin-verified, Pending Verification as first-class state); confirmed and cited the legacy pagination shape against Backend V1 source; expanded logging guidance (levels, no sensitive data, optional correlation ID); added mandatory contract verification to Testing Strategy; documented the full approved deep-link matrix; corrected Laravel/PHP version to match `composer.json` |
| 2.0 | 19 July 2026 | **Architecture Freeze.** Status set to Approved / Frozen / Ready for Flutter Foundation. Added §30 Flutter Architecture Freeze (Freeze Declaration, Change Control, Source of Truth & Conflict Resolution, Definition of Frozen) |
| 2.0.1 | 19 July 2026 | **Documentation reference correction.** Removed the two obsolete forward references to the never-created `docs/10_Flutter_Design_System.md` (§12 Theme Architecture; §25 Flutter Architecture Freeze Policy), replacing both with correct references to `docs/08_Figma_Design_System.md`. No architecture decisions changed |

**Status:** Architecture Freeze complete. **Next gate:** Phase 3 — Flutter Foundation / Flutter Project Setup (per `07_Project_Roadmap.md`).
