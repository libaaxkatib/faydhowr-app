# UX Flow Specification

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `04_UX_Flow` |
| **Product Name** | Fayadhowr |
| **Document Type** | UX Flow Specification |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 13 July 2026 |
| **Basis Documents** | `docs/02_SRS.md`, `docs/03_Database_Design.md` |
| **Audience** | Product owners, UX designers, mobile engineers, QA |

---

## Document Rules

This document describes **customer journeys, decision points, and navigation flow** only.

It intentionally does **not** include:

- Visual UI designs, wireframes, or mockups
- Flutter / Dart code
- API contracts
- Database tables or schema changes

All flows align with the approved SRS and Database Design.

---

# 1. UX Philosophy

Fayadhowr’s customer experience is built to let people **discover value immediately**, then authenticate only when a commercial action requires identity, trust, and history.

## 1.1 Core Principles

### Value Before Login

Customers open the app and immediately see services, products, proof of quality, and trust content. Login is deferred until a transactional or personal action requires it. Browsing is never blocked by an auth wall.

### Customer First

Every step prioritizes clarity for the end customer: what the offering is, what it costs (or whether a quote is needed), what happens next, and how to get help. Internal admin complexity is never exposed in the customer path.

### Minimal Steps

Critical journeys (book, quote, checkout, pay) use the fewest necessary screens and fields. Optional steps (extra notes, more images, continue shopping) remain available without forcing them.

### Smart Defaults

Where the system already knows useful context (saved address, last-used contact phone, active cart, preferred fulfillment type), it pre-fills or suggests safely. Defaults never silently change price, stock, or booking eligibility.

### Trust First

The experience surfaces proof early: clear prices on store products, transparent service pricing models, before-and-after evidence, reviews, FAQ, and confirmation states with durable references (booking/order/quote numbers). Payment and status outcomes are explicit—never ambiguous.

### Professional Experience

Copy, hierarchy, and flow pacing remain calm and business-grade. The product feels like a reliable service and commerce brand, not a consumer entertainment feed or cluttered marketplace.

### High Quality Visual Content

Imagery is central to understanding services and products: hero content, service/product galleries, and before-and-after proof. Quotation requests support image uploads so customers can show the real condition of work needed.

### Simple Navigation

A shallow, predictable structure (Home, Services, Store, Activity/Account-oriented destinations) keeps orientation clear. Deep features hang off known entry points rather than inventing parallel menus.

## 1.2 Experience Guardrails

| Guardrail | UX Implication |
| --- | --- |
| Customer-only mobile app | No employee/technician tooling in the customer journey |
| Store products always show Selling Price | Selling Price is visible on list and detail before cart; Cost Price is never customer-facing |
| Optional product quotations | Quote CTA is secondary to Buy / Add to Cart |
| Service fixed vs quote models | Primary CTA matches pricing model (`Book` vs `Request Quotation`) |
| Auth on transactional actions only | Soft gate with return-to-intent after login |

---

# 2. Application Entry Flow

## 2.1 App Launch

1. Customer opens Fayadhowr.
2. Lightweight splash/brand moment (non-blocking).
3. App lands on **Home** in **guest or authenticated** mode as available—**no login requirement**.
4. If a prior session token is still valid, the customer is silently recognized for personalized elements (e.g., cart badge, notification badge) without interrupting Home.

## 2.2 Immediate Home Experience

Home is the primary value surface. It is scrollable and content-led, not a dashboard of internal metrics.

| Home Block | Purpose |
| --- | --- |
| **Hero Banner** | Brand and primary campaign/message; establishes professionalism and atmosphere |
| **Search Bar** | Placed immediately below the Hero Banner; allows customers to quickly search **Services** and **Store Products** |
| **Service Categories** | Fast path into service discovery |
| **Featured Services** | Highlighted bookable / quote-based offerings |
| **Store Products** | Priced product discovery without leaving Home |
| **Before & After Gallery** | Visual proof of work quality (trust) |
| **Customer Reviews** | Social proof from published reviews |
| **FAQ** | Reduce uncertainty before contact or booking |
| **Contact Information** | Clear path to reach the business |

## 2.3 Guest Capabilities on Entry

Without logging in, customers can:

- Browse Home content
- Open service and product lists/details
- View prices, galleries, before/after, FAQ, reviews, contact
- Build a cart (subject to product availability)
- Explore quotation information (submit requires login)

## 2.4 What Does Not Appear as a Hard Stop

- Forced login modal on launch
- Empty “please sign in to continue” Home
- Hidden prices pending authentication

---

# 3. Authentication Flow

## 3.1 Principle

**Browsing never requires login.**  
**Authentication is required only for identity-bound actions.**

## 3.2 Actions That Require Login

Customers must authenticate when they want to:

| Action | Why auth is required |
| --- | --- |
| **Book a Service** | Booking is owned by a customer record |
| **Request a Quotation** | Quote request and follow-up are customer-owned |
| **Place an Order** | Order placement and payment require a customer |
| **View their Profile** | Personal data is private |
| **View Booking History** | History is owner-scoped |
| **View Order History** | History is owner-scoped |

Related transactional follow-ons (Accept Quotation / Discuss Quotation, pay a payable booking/order/quote, manage addresses inside profile) also require an authenticated session.

## 3.3 Soft Auth Gate Pattern

1. Customer taps a protected action (e.g., **Book**, **Request Quotation**, **Checkout**, **Profile**).
2. If already authenticated and active → continue the interrupted intent.
3. If not authenticated → present Auth entry (login / register / recovery) with clear message: why login is needed.
4. On success → **return to the same intent** (same service, product, cart, or destination).
5. On cancel → return to the previous browse context without data loss where possible (e.g., cart retained).

## 3.4 Auth Sub-Flows (Logical)

| Sub-flow | Customer outcome |
| --- | --- |
| **Continue with Phone** (default) | Enter phone (default 🇸🇴 +252) → **Continue** → OTP verify → session → continue intent |
| **Continue with Google** | Native Android/iOS Google Sign-In using device accounts (account picker if multiple; quick sign-in if one). Customer does **not** type Gmail. |
| **Continue with Email** | Email + Password (Show/Hide) → optional Remember Me → Continue; Forgot Password available |
| Register / Create Account | Phone Number (**primary**) + Email (**optional**) + Password + **Confirm Password** (must match); Google may auto-complete registration after successful Google Sign-In |
| Credential recovery | Forgot / Reset password path → return to login (email path) |
| Logout | Confirmation → End session → browsing remains available; protected areas lock again |

### Login method priority

Business rule (not shown as tabs in the UI):

1. Phone Number (primary for Somalia)  
2. Google Sign-In  
3. Email  

Customer UI shows action buttons only: **Continue with Phone**, **Continue with Google**, **Continue with Email**.

Auth copy is **general** and reusable (**Welcome to Fayadhowr** / **Sign in to your account or continue with your preferred method.**) — works for first-time and returning customers; not booking-specific. Soft gate still returns to the interrupted intent after success. 

## 3.5 Explicit Non-Requirements

Login is **not** required to:

- Open the app
- View Home blocks
- Browse categories, services, products
- View product prices
- View media, before/after, FAQ, reviews, contact
- Add items to cart (checkout still requires login)

---

# 4. Service Flow

Complete customer journey for services:

```text
Browse Services
        ↓
Open Service Details
        ↓
View Images
        ↓
Read Description
        ↓
View Before & After
        ↓
Read FAQ
        ↓
Book Service  ——or——  Request Quotation
        ↓
Login (if required)
        ↓
Submit Request
        ↓
Confirmation
```

## 4.1 Browse Services

**Entry points:** Home → Service Categories / Featured Services; or Services tab.

**Customer can:**

- Browse categories and service lists
- Filter/search when available
- Distinguish offerings by pricing model signals (fixed bookable vs quotation)

**Login:** Not required.

## 4.2 Open Service Details

Customer opens a service and can review, in any practical order:

| Detail element | Intent |
| --- | --- |
| **Images** | Understand the offering visually |
| **Description** | Scope, inclusions/exclusions, expectations |
| **Before & After** | Trust and outcome evidence (service-relevant) |
| **FAQ** | Answer common objections before CTA |
| Pricing cue | Fixed price display and/or “quotation required” messaging |

**Login:** Not required to view details.

## 4.3 Primary CTA Branch

| Service pricing model | Primary action | Secondary (if applicable) |
| --- | --- | --- |
| Fixed-price bookable | **Book Service** | — |
| Quotation-based | **Request Quotation** | — |
| Hybrid | Policy-defined primary (typically Book and/or Request Quotation) | Alternate path clearly labeled |

Inactive/unavailable services show a non-actionable state instead of a failing submit.

## 4.4 Book Service Path

1. Customer taps **Book Service**.
2. Soft auth gate if needed.
3. Enter booking details (schedule preference/slot, address if required, notes).
4. Review summary (service, time, location, amount/policy).
5. Submit booking.
6. Confirmation with booking reference and next-step guidance (including payment timing if applicable).

## 4.5 Request Quotation Path (Service)

1. Customer taps **Request Quotation**.
2. Soft auth gate if needed.
3. Enter requirements, preferred timing/location as needed.
4. Upload supporting **images** (see Quotation Flow).
5. Submit quotation request.
6. Confirmation with request reference and expectation of admin response.

## 4.6 Post-Confirmation

Customer may:

- View the booking/request in history (authenticated)
- Receive notifications on status changes
- Proceed to payment when the entity becomes payable

---

# 5. Store Flow

Complete shopping experience for **physical products only** (V1 categories: Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners). Heavy equipment is outside V1.

Store owns catalog → cart → checkout → Store Orders → Unified Payment. Inventory purchasing is a separate admin domain.

```text
Browse Products
        ↓
View Product Details
        ↓
View Selling Price
        ↓
Add to Cart
        ↓
Continue Shopping  ——or——  Checkout
        ↓
Login (if required)
        ↓
Create Store Order (stock NOT decreased)
        ↓
Payment
        ↓
Payment Paid → Inventory Decrease + Stock Ledger
        ↓
Order Confirmation
```

Optional parallel path: **Request Product Quotation** (does not replace priced purchase).

## 5.1 Browse Products

**Entry points:** Home → Store Products; or Store tab; or category navigation.

**Customer can:** browse V1 categories, product lists, In Stock / Low Stock / Out of Stock cues.

**Login:** Not required.

## 5.2 View Product Details & Selling Price

On product detail, customers always see:

- Product media
- Description
- **Selling Price** (mandatory business rule; Cost Price never shown)
- Availability (In Stock / Low Stock / Out of Stock)
- Primary commerce actions

**Login:** Not required to view Selling Price or details.

## 5.3 Add to Cart

1. Customer selects quantity (within stock rules; overselling rejected).
2. Taps **Add to Cart**.
3. Receives lightweight confirmation (e.g., cart updated feedback).
4. May **Continue Shopping** or open **Cart**.

**Login:** Not required to add to cart. Adding to cart never decreases stock.

## 5.4 Cart → Checkout

1. Customer opens Cart.
2. Can update quantities or remove lines.
3. Empty cart shows Empty Cart guidance (see Error Handling).
4. Taps **Checkout**.
5. Soft auth gate if needed (**login required to place an order**).
6. Provide/confirm fulfillment details (delivery/pickup) and address as required.
7. Review order summary with re-validated Selling Prices/stock (overselling rejected).
8. Create Store Order in `pending_payment` — **stock is not decreased**.
9. Proceed to **Payment** via Unified Payment Module.
10. Stock decreases only after Payment = Paid; failed/cancelled payment leaves stock unchanged.
11. **Order Confirmation** with store order reference (`STO-YYYY-######`).

Store Order lifecycle: `pending_payment` → `confirmed` → `processing` → `completed` / `cancelled`.

## 5.5 Optional Product Quotation

When a product allows optional quotation (bulk, custom quantity, special request):

1. Customer may tap **Request Quotation** from product detail (secondary to purchase CTA).
2. Soft auth gate if needed.
3. Describe needs, quantity hint, upload images.
4. Submit → confirmation.
5. Fixed-price **Add to Cart / Buy** path remains available and is not replaced by quotation.

---

# 6. Quotation Flow

Quotations cover **services** (often required by pricing model) and **products** (optional). Both support file uploads before submission and during **Discuss Quotation**.

**V1 primary actions after issue:** **Accept Quotation** and **Discuss Quotation**. Never **Reject Quotation**.

## 6.1 Shared Quotation Principles

| Principle | UX behavior |
| --- | --- |
| Auth required | Soft gate before submit |
| Clarity of target | Customer always knows whether quoting a service or a product |
| Unique Quotation Number | e.g. `QT-2026-000123` shown on details, PDF, notifications, history |
| Files before submit | Images, videos, and/or PDFs per upload rules to help assessment |
| After submit | Clear Quotation Number + “we will respond” expectation; status **Pending Review** |
| After team issues quote | Notification → status **Quotation Ready** → review latest revision |
| Primary customer actions | **Accept Quotation** or **Discuss Quotation** (never Reject) |
| Discuss Quotation | Messaging + extra images/videos/PDFs on the **same** quotation; status **Under Discussion**; does not close the quote |
| Revisions | Updates create v1, v2, v3…; **Latest Version** clearly marked; only latest may be accepted |
| After accept | Status **Accepted** → Payment / fulfillment path unlocks |
| Notifications | Every quotation update and every new discussion message notifies the other party |

**Statuses (only):** Pending Review · Quotation Ready · Under Discussion · Accepted · Expired · Cancelled

## 6.2 Service Quotation Flow

```text
Service Details (quotation / hybrid eligible)
        ↓
Request Quotation
        ↓
Login (if required)
        ↓
Enter requirements + timing/location
        ↓
Upload files (images / videos / PDFs as enabled)
        ↓
Review & Submit  →  Pending Review  (QT-YYYY-######)
        ↓
Request Confirmation
        ↓
(Team issues quotation revision v1)
        ↓
Notification → Quotation Ready → View Quotation (Latest Version)
        ↓
Accept Quotation  ——or——  Discuss Quotation
        ↓
If Discuss → Under Discussion (messages + files + optional revised v2…)
        ↓
If Accept (latest revision only) → Payment / next fulfillment step
```

**Notes:**

- Requirements text is mandatory on initial request.
- Files are strongly encouraged; allow one or more.
- Discuss never creates a new quotation number.
- Customer can track status, timeline, and revision history from Quotation Details / History.

## 6.3 Product Quotation Flow

Product quotations use the **same Quotation Module** as services (same statuses, Accept / Discuss, revisions, timeline, uploads). Do **not** create a separate Product Discussion module.

```text
Product Details (allow_optional_quotation)
        ↓
Request Quotation (secondary CTA; price remains visible)
        ↓
Login (if required)
        ↓
Enter requirements + quantity/special needs
        ↓
Upload files (images / videos / PDFs as enabled)
        ↓
Review & Submit  →  Pending Review  (source = Product, QT-YYYY-######)
        ↓
Request Confirmation
        ↓
(Team issues quotation revision v1)
        ↓
Notification → Quotation Ready → View Quotation (Latest Version)
        ↓
Accept Quotation  ——or——  Discuss Quotation
        ↓
If Discuss → Under Discussion (messages + files + optional revised v2…)
        ↓
If Accept (latest revision only) → Payment / order fulfillment path as applicable
```

**Notes:**

- Does not remove or hide the product’s displayed price.
- Does not block the normal cart checkout journey.
- Used for bulk, custom quantities, or special requests.
- Same Discuss / revision / status rules as service quotations.
- Quotation `source` = `Product` (recorded for business logic / database; not a customer label by default).

## 6.4 File Upload UX Rules (Quotation)

1. Customer may add **one or more** files (up to 10 per upload request) before submission and during Discuss Quotation.
2. Show clear format guidance (images: JPG/JPEG/PNG/WebP; video: MP4/MOV/WebM; PDF as enabled) and size limits (images 10 MB, PDF 20 MB, video 100 MB).
3. Allow remove/replace before submit (staged uploads can be deleted until attached); allow additional uploads in discussion.
4. Block unsupported or oversized files with actionable error copy.
5. Submission remains possible per product policy if files are optional; when files materially help assessment, prompt without dead-ending unless business marks them required for that offering.
6. Staged files not attached to a request expire after 7 days; the app should not rely on staged uploads persisting beyond that window.

## 6.5 Discuss Quotation (V1)

When the customer selects **Discuss Quotation**:

| Party | Can |
| --- | --- |
| **Customer** | Send messages; reply; upload additional images, videos, PDFs; Accept the **latest** quotation |
| **Fayadhowr team** | Reply; request more info/files; update quotation (new revision); upload revised quotation document |

- Discussion remains attached to the **same** Quotation Number.
- Do **not** create another quotation.
- Status becomes **Under Discussion** while discussion is active.
- Example update notification: “Your quotation has been updated. Please review the latest version.”

## 6.6 Quotation Timeline & Revision History

Maintain complete history, including: Quotation Created · Customer Discussion · Team Replies · Quotation Updated · Customer Acceptance · Payment · Service Completion.

Customers may open **View Revision History** (read-only). Older revisions cannot be accepted.

---

# 7. Booking Flow

Describes every customer-facing booking step for eligible bookable services.

## 7.1 End-to-End Steps

```text
1. Discover service (Home / Services)
2. Open Service Details (browse media, description, proof, FAQ)
3. Tap Book Service
4. Login (if required) → return to booking intent
5. Select date/time or available slot
6. Provide required location/address (if service requires)
7. Add customer notes (optional)
8. Review booking summary
9. Submit booking
10. Booking Confirmation (reference + status + next steps)
11. Track status via notifications and Booking History
12. Pay when booking becomes payable (Payment Flow)
13. Reach terminal state (Completed / Cancelled / etc.)
```

## 7.2 Step Detail

| Step | Customer intent | System UX responsibility |
| --- | --- | --- |
| Select schedule | Choose feasible time | Show only valid options; explain unavailable slots |
| Address | Where service happens | Reuse saved addresses when logged in; snapshot at submit |
| Notes | Extra context | Optional free text |
| Review | Confirm before commit | Show service name, schedule, location, pricing/payment timing |
| Submit | Create booking | Disable double-submit; show progress |
| Confirmation | Trust closure | Booking number, status, what happens next |
| Cancel (policy permitting) | Exit booking | Confirm intent; explain refund/payment implications if any |

## 7.3 Booking Failure Points (Customer-Visible)

- Slot no longer available → ask to choose another time
- Service became unavailable → explain and offer alternatives/browse
- Validation errors → inline, field-level guidance
- Network/server failure → see Error Handling; preserve draft inputs where possible

## 7.4 History Access

Booking History is available only when authenticated (Profile / Activity). Guests who booked after login can return later via login to view status.

---

# 8. Payment Flow

## 8.1 When Payment Appears

Customers pay when a payable entity exists:

| Payable entity | Typical entry |
| --- | --- |
| Store order | Checkout completion path |
| Booking | When status/policy makes booking payable |
| Accepted quotation | Immediately after acceptance (full or deposit per terms) |

## 8.2 Payment Journey

```text
Open payable entity (Order / Booking / Accepted Quotation)
        ↓
Review amount, currency, and what is being paid
        ↓
Tap Pay
        ↓
Authenticate session already required (entity is customer-owned)
        ↓
Choose/initiate payment method via provider flow
        ↓
Provider processing
        ↓
Success → Payment success state + entity status update + notification
   or
Failure → Clear failure state + retry guidance (entity remains payable if valid)
```

## 8.3 UX Rules

1. Amount shown must match authoritative backend total at initiation.
2. Never imply success from client optimism alone; wait for confirmed result.
3. On success, show payment/entity references and next fulfillment expectations.
4. On failure, keep the customer oriented on the same payable item with **Retry**.
5. Refunds are communicated via status/notification; customers do not self-approve gateway refunds.

---

# 9. Notification Flow

## 9.1 Purpose

Keep customers and admins informed of lifecycle changes without requiring them to poll history screens.

## 9.2 Channels (Customer UX)

| Channel | Behavior |
| --- | --- |
| **In-app Notification Center** | Filterable list; status/type/channel; Mark as Read / Mark All as Read only (no delete) |
| **Notification Details** | Full message, enterprise lifecycle status, timestamps, deep-link payload |
| **Notification Preferences** | Per-type toggles for in-app / email / SMS |
| **Email / SMS** | Delivered via dedicated queues when preference + template channel allow; provider callbacks may later set delivered |

## 9.3 Customer Journey

```text
Domain publishes NotificationRequested
        ↓
Template rendered (+ translation) + preferences applied
        ↓
Pending notification persisted (event_id idempotent)
        ↓
Channel queue → processing → sent
        ↓
V1 in-app: auto delivered
        ↓
Customer opens Notifications List
        ↓
Notification Details → related record (via data payload)
        ↓
Mark as read / Mark all as read (delivered → read; no delete)
```

## 9.4 Types

Booking · Quotation · Order · Payment · Store Order · Inventory · System

## 9.5 UX Rules

- Notifications never expose secrets (full payment credentials, etc.).
- Preferences may reduce non-critical noise without hiding operationally required notices.
- Badge/count uses unread-count API (`status != read`).
- Deep links use reference fields in notification `data` when present.
- Customers never delete notifications; admins may archive terminal rows.

---

# 10. Profile Flow

## 10.1 Access

Profile and personal history require authentication.

```text
Tap Profile / Account
        ↓
Login (if required)
        ↓
Profile Home
```

## 10.2 My Account Capabilities

| Area | Customer can |
| --- | --- |
| Identity | View photo, name, **read-only** `CUS-YYYY-######`, email, phone, language, member since |
| Quick stats | See Bookings / Quotations / Orders counts (deep-link to histories) |
| Edit Profile | Update photo, name, email, phone; CUS remains read-only |
| Addresses | Add, edit, set default; **mark Inactive** (never permanently delete) |
| Payment methods | Add / set default for EVC Plus, eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, Debit/Credit Card; **payment history never deleted** |
| Language | Select Somali / English / Arabic — updates entire app UI |
| Security | Change Password; Change PIN (if enabled); 2FA & Active Devices placeholders |
| Notifications | Open Notification Center / preferences |
| Help / About | FAQ, WhatsApp / Phone / Email; company profile (story, mission, vision, experience, certificates, awards, partners, stats); Privacy; Terms; app version |
| Booking / Order / Quotation History | List and open commercial activity |
| Session | Log out via confirmation dialog (Cancel / Log Out) — never immediate |
| Account control | Request deactivation/deletion per policy |

## 10.3 Profile Rules in UX

- System-controlled fields (`customer_number` / CUS, account status, verification flags) are visible, not freely editable.
- Editing profile does not silently rewrite historical order/booking address snapshots.
- Addresses are never permanently deleted by customers — mark **Inactive** instead.
- Payment history is never deleted by customers.
- Language selection applies app-wide immediately.
- Suspended accounts see a clear blocked state for new bookings/orders/quotes.

---

# 11. Error Handling Flow

Error UX must be calm, specific, and recoverable. Prefer inline or full-page recovery states over dead ends.

## 11.1 No Internet

| Moment | UX behavior |
| --- | --- |
| Detect offline / request fail due to network | Show clear “No Internet” message |
| Browse attempt | Explain connectivity is required for live catalog/transactions |
| In-progress form (book/quote/checkout) | Preserve entered data locally in-session where possible |
| Action | Provide **Retry**; do not claim success |

## 11.2 Failed Payment

| Moment | UX behavior |
| --- | --- |
| Provider/system failure | Explicit failure message (non-technical) |
| Order/booking/quote state | Remains payable if still valid |
| Actions | **Retry Payment**, view details, contact support via Contact info |
| Ambiguity | If status unknown, show “Confirming payment…” then resolve to success or failure—never silent |

## 11.3 Empty Cart

| Moment | UX behavior |
| --- | --- |
| Open cart with no items | Empty Cart state |
| Message | Explain cart is empty in plain language |
| Actions | **Browse Store** / return Home; hide Checkout as primary |

## 11.4 Booking Failure

| Cause | UX behavior |
| --- | --- |
| Slot unavailable | Explain and return customer to schedule selection |
| Validation errors | Field-level messages |
| Service unavailable | Explain; offer browse other services |
| Server/unknown | Generic booking failure + Retry; keep inputs when safe |
| Cancel not allowed | Explain policy; do not fake cancel success |

## 11.5 Server Error

| Moment | UX behavior |
| --- | --- |
| 5xx / unexpected API failure | Dedicated server error state |
| Copy | Apologize briefly; avoid blameful or debug jargon |
| Actions | **Retry**; optional path to Contact |
| Safety | Do not duplicate submit for payments/bookings without idempotent handling—disable panic re-taps during in-flight requests |

---

# 12. Success States

Success states close the loop: what happened, the reference ID, and what to do next.

## 12.1 Core Success Moments

| Success state | Customer should see |
| --- | --- |
| **Booking Confirmation** | Success message, booking number, schedule summary, status, payment next-step if any, link to Booking History |
| **Quotation Request Confirmation** | Success message, Quotation Number, Pending Review expectation, file count if uploaded, link to quote history |
| **Quotation Accepted** | Acceptance confirmation, Quotation Number, locked commercial terms summary, **Pay** CTA if payable |
| **Discuss Quotation** | Thread on same quotation; messages; additional file uploads; Accept latest revision; never closes via Reject |
| **Order Confirmation** | Success message, order number, item summary, total paid/payable, fulfillment expectations |
| **Payment Success** | Amount, payment reference, updated entity status, fulfillment/next-step copy |
| **Profile Update Success** | Lightweight confirmation that changes were saved |
| **Cart Add Success** | Non-blocking confirmation; option to view cart or continue |

## 12.2 Success UX Rules

1. Always provide a durable reference for commercial documents (booking/order/quote/payment numbers).
2. Prefer one primary next action (View details, Pay, Continue shopping, Back to Home).
3. Trigger/expect a notification for major commercial successes without relying on notification alone as the only confirmation.
4. Success copy must match authoritative server state—not optimistic UI alone.

---

# 13. Navigation Structure

Describe navigation flow only—not visual design of screens.

## 13.1 Bottom Navigation (Primary)

Recommended primary destinations:

| Tab | Role in journey |
| --- | --- |
| **Home** | Value-first landing: hero, categories, featured services, products, gallery, reviews, FAQ, contact |
| **Services** | Service catalog browse → service details → book / request quotation |
| **Store** | Product catalog browse → product details → cart actions |
| **Cart** *(or accessible from Store)* | Review cart → checkout (auth if needed) → payment |
| **Account** | Auth soft gate → profile, histories, notifications entry, settings/legal, logout |

> If Cart is not a root tab, it must remain globally reachable (e.g., from Store/Home header affordance) with clear badge count. Account remains the home for Profile, Booking History, and Order History.

## 13.2 Screen Hierarchy (Logical)

```text
App Launch
 └── Home
      ├── Service Category → Service List → Service Details
      │                         ├── Book Service → Auth? → Booking Form → Confirmation
      │                         └── Request Quotation → Auth? → Quote Form (+ images) → Confirmation
      ├── Featured Service → Service Details → (same as above)
      ├── Store Product → Product Details
      │                         ├── Add to Cart → Cart
      │                         └── Request Quotation (optional) → Auth? → Quote Form (+ images) → Confirmation
      ├── Before & After Gallery (detail optional)
      ├── Reviews (list/detail optional)
      ├── FAQ
      └── Contact

Services Tab
 └── Categories / List → Service Details → Book / Quote flows

Store Tab
 └── Categories / List → Product Details → Cart / Optional Quote
 └── Cart → Auth? → Checkout → Payment → Order Confirmation

Account Tab
 └── Auth? → My Account
      ├── Edit Profile (CUS read-only)
      ├── Saved Addresses (Inactive allowed; never delete)
      ├── Payment Methods (add / set default; history never deleted)
      ├── Language (Somali / English / Arabic — app-wide)
      ├── Security (Password · PIN · 2FA/Devices placeholders)
      ├── Help Center · About Fayadhowr
      ├── Booking History → Booking Details → Pay / Cancel (policy)
      ├── Order History → Order Details → Pay / Track
      ├── Quotation History → Quotation Details → Accept / Discuss → Pay (after Accept)
      ├── Notifications → Entity deep link
      └── Logout
```

## 13.3 Cross-Cutting Entries

| Entry | Opens |
| --- | --- |
| Notification tap | Related booking / quote / order / payment context |
| Payment deep link from confirmation | Payment Flow for payable entity |
| Contact block | Business contact methods (as configured) |

## 13.4 Navigation Rules

1. Back stack should return customers to their browse context after canceled auth.
2. Successful auth resumes the pending intent.
3. Commercial confirmations offer a path back to Home and to the relevant history item.
4. Guests can use Home, Services, and Store freely; Account/History/Book/Quote-submit/Checkout trigger auth.

---

# 14. Admin Bookings Management (Panel)

## 14.1 Objective

Operations staff review and manage all service bookings without permanently deleting records.

## 14.2 Primary Path

1. Admin opens **Bookings** from the sidebar.
2. Searches / applies advanced filters (status, priority, service date, assigned to).
3. Scans **Priority** badges and **booking age** (Created … / Waiting …) for aging work.
4. Opens **View Booking** on a row.
5. Reviews customer, service, property, media (**Images (n)**, **Videos (n)**, **Documents (n)**), customer notes.
6. Updates status only via the **controlled status dropdown** (approved statuses only).
7. Uses **Linked Records** for Customer Profile, Quotations, Orders, Payments, Notifications.
8. Reads **Booking Timeline** (audit only — each event shows actor, e.g. By Sara (Sales) or System).
9. Optionally adds an **Internal Note** (Admin / Sales / Accountant) — never customer-visible.
10. **Assigned To** is displayed as informational manual assignment only (no Staff Management).

## 14.3 Rules

- Booking Number (`BK-…`) is read-only.
- Priority is read-only: High · Medium · Low.
- Statuses: Pending Review · Quotation Ready · Under Discussion · Accepted · Scheduled · In Progress · Completed · Cancelled (never Rejected; no custom values).
- No permanent delete; booking always remains linked to its customer.
- No Booking Value / Estimated Value on this module.

---

# 15. Admin Quotations Management (Panel)

## 15.1 Objective

Operations staff manage all quotations that originate from a Booking or Product Request — never as standalone creates.

## 15.2 Primary Path

1. Admin / Sales opens **Quotations** from the sidebar.
2. Searches / applies advanced filters (status, source, valid until).
3. Opens **View Quotation** on a row (sees Valid Until + countdown e.g. 4 days remaining / Expired · 2 days ago).
4. Confirms **Source** (Booking / Product) and permanent linked record.
5. Reviews customer, price breakdown, total, validity countdown, current revision.
6. Updates status only via the **controlled status dropdown** (approved statuses only).
7. Issues a new revision when required (each revision shows **Created By**, Staff Role, Date, Time permanently).
8. Optionally opens **Compare Revisions** (any two revisions, e.g. v2 ↔ v3) — read-only diff of items, quantities, prices, total, notes.
9. Participates in **Discussion** (search by keyword; attachment counters Images/Videos/PDF Files; messages + files); history never deleted.
10. Reads **Timeline** (audit only — each event shows actor + role or Customer/System).
11. Uses **Linked Records** for Customer Profile, Booking, Orders, Payments, Notifications.
12. Optionally adds an **Internal Note** (Admin / Sales / Accountant) — never customer-visible.

## 15.3 Rules

- Quotation Number (`QT-…`) is read-only.
- Origin: **Booking** or **Product Request** only; link never removed.
- Statuses: Pending Review · Under Discussion · Quotation Ready · Accepted · Expired · Cancelled (never Rejected; no custom values).
- Only the **latest** revision can be accepted.
- No permanent delete; discussion history cannot be deleted.

---

# 16. Admin Orders Management (Panel)

## 16.1 Objective

Operations staff manage all orders that are automatically created from accepted quotations — never manually created.

## 16.2 Origin Rule

Every order is created automatically via: Booking → Quotation → Accepted → Order, or Product Request → Quotation → Accepted → Order. No Create Order button.

## 16.3 Primary Path

1. Admin / Sales / Accountant opens **Orders** from the sidebar.
2. Scans list — each row shows **Order Age** beneath the date (e.g., "Created 1 day ago", "Waiting 3 days" for Pending Payment).
3. Searches / applies advanced filters (order status, payment status, source, order date).
4. Opens **View Order** on a row.
5. Sees the **Current Stage Indicator** — compact read-only label showing "Current Stage: Processing" (or current stage name) above the progress tracker.
6. Sees the **Order Progress Tracker** (visual stepper: Pending Payment → Confirmed → Processing → Completed) with the current step highlighted — visual indicator only.
7. Reviews **Order Age** in the header area (e.g., "Created 1 day ago").
7. Reviews **Business Summary** cards (Total Amount, Amount Paid, Remaining Balance, Payment Status).
8. Reviews **Financial Summary** (compact read-only: Subtotal, Discount, Delivery Fee, Tax, Grand Total, Amount Paid, Remaining Balance).
9. Confirms **Source Chain** (Booking or Product → Quotation → Accepted → Order) and permanent linked records.
10. Reviews customer, ordered items, price breakdown (subtotal, discount, delivery, tax, grand total).
11. Updates order status only via the **controlled status dropdown** (approved statuses only).
12. Checks **Order Documents** — each shows availability: ✅ Available (clickable) or ⏳ Not Available Yet (disabled). Cannot open documents that have not been generated.
13. Reads **Order Timeline** including expanded **Payment Timeline** events (Payment Requested, Payment Received, Payment Confirmed, Refund Processed) — each event shows Performed By, Staff Role (or Customer/System), Date, Time (audit only).
14. Uses **Linked Records** for Customer Profile, Booking, Quotation (with Discussion access), Payments, **Order Documents** (quick access to Order PDF / Invoice PDF / Receipt PDF), Notifications.
15. Sees **Latest Note** indicator above Internal Notes showing the date/time of the most recent note (read-only).
16. Optionally adds an **Internal Note** (Admin / Sales / Accountant) — never customer-visible.

## 16.4 Rules

- Order Number (`ORD-…`) is read-only.
- No manual order creation; every order must originate from an accepted quotation.
- Order statuses: Pending Payment · Confirmed · Processing · Completed · Cancelled (no custom values).
- Payment statuses: Unpaid (red) · Partially Paid (orange) · Paid (green) · Refunded (blue) — standardized colors across Admin Panel.
- No permanent delete; order always remains linked to its originating Booking or Product Request and its accepted Quotation.
- Discussion history remains accessible through the linked quotation.
- Order Progress Tracker is visual indicator only — no actions or logic attached.
- Order Age is calculated from order creation date.
- Payment Timeline events include all payment-related events with complete actor audit.
- Documents display availability status — unavailable documents cannot be opened.
- Financial Summary is read-only.
- Current Stage Indicator is read-only — derived from order status.
- Linked Records include Order Documents shortcut for quick access.
- Latest Note indicator is read-only — shows timestamp of the most recent internal note.

---

# 17. Admin Payments Management (Panel)

## 17.1 Objective

Operations staff manage all payments that originate from existing Orders — never manually created.

## 17.2 Origin Rule

Every payment must originate from an existing Order via: Booking / Product Request → Quotation → Accepted → Order → Payment. No Create Payment button.

## 17.3 Primary Path

1. Admin / Sales / Accountant opens **Payments** from the sidebar.
2. Scans list — each row shows **Payment Age** (e.g., "Received 1 day ago", "Waiting Verification 3 days"), **Verification Badge** (Verified / Pending Verification), and **Payment Method icon**.
3. Notes the Order Number (clickable link to originating order) on each row.
4. Searches / applies advanced filters (payment status, payment method, payment date).
5. Opens **View Payment** on a row.
6. Sees the **Verification Badge** in the header (independent from Payment Status).
7. Sees the **Current Stage Indicator** — compact read-only label showing "Current Stage: Confirmed" (or current stage).
8. Sees the **Payment Progress Tracker** (visual stepper: Pending → Received → Confirmed, or Pending → Failed, or Confirmed → Refunded) with the current step highlighted — visual indicator only.
9. Reviews **Business Summary** cards (Amount Due, Amount Paid, Remaining Balance, Payment Status).
10. Reviews **Financial Audit Summary** (read-only: Payment Requested By, Payment Confirmed By, Confirmation Date, Last Updated).
11. Reviews **Payment Information** (Payment Number, Method with icon, Transaction Reference with **Copy** button, Amount, Currency, Date, Status, Verification Badge).
12. Uses the **Copy** button next to Transaction Reference — sees "Copied" confirmation.
13. Reviews **Customer Information** (Name, Phone, Email, CUS).
14. Confirms **Source Chain** (Order → Quotation → Booking or Product Request) — permanent and always traceable.
15. Updates payment status only via the **controlled status dropdown** (approved statuses only).
16. Checks **Payment Documents** — each shows availability: ✅ Available (clickable) or ⏳ Pending (disabled). Cannot open documents not yet generated.
17. Reads **Payment Timeline** (audit only — each event shows Performed By, Staff Role or Customer/System, Date, Time): Payment Requested, Payment Received, Payment Confirmed, Refund Initiated, Refund Completed.
18. Uses **Linked Records** for Customer Profile, Booking, Quotation, Order, Notifications.
19. Sees **Latest Note** indicator above Internal Notes showing the date/time of the most recent note (read-only).
20. Optionally adds an **Internal Note** (Admin / Sales / Accountant) — never customer-visible.

## 17.4 Rules

- Payment Number (`PAY-…`) is read-only.
- No manual payment creation; every payment must originate from an existing Order.
- Payment statuses: Pending · Received · Confirmed · Failed · Refunded (no custom values).
- Supported methods: EVC Plus · eDahab · Jeeb · Salaam Somali Bank · Bank Transfer · Debit / Credit Card.
- No permanent delete; payment always remains linked to its originating Order.
- Receipt history is permanent.
- Payment Progress Tracker is visual indicator only — no actions or logic attached.
- Current Stage Indicator is read-only — derived from payment status.
- Payment Documents display availability status — unavailable documents cannot be opened.
- Latest Note indicator is read-only — shows timestamp of the most recent internal note.
- Verification Badge is independent from Payment Status — a payment can be Confirmed but still Pending Verification, or Received and already Verified.
- Payment Age uses contextual wording: "Received X days ago" for confirmed/received payments, "Waiting Verification X days" for unverified.
- Transaction Reference Copy button shows "Copied" confirmation — UI-only interaction.
- Payment Method icons are displayed consistently across list and details.
- Financial Audit Summary is read-only — derived from timeline events.

---

## Traceability Summary

| UX area | SRS / Design alignment |
| --- | --- |
| Value Before Login / Entry Home | SRS guest browse; auth on transactional boundaries |
| Service & Store flows | SRS §§7–8 |
| Optional product quotes + required service quotes | SRS Quotation Workflow (Accept / Discuss; never Reject) |
| Quote uploads + Discuss files | Images, videos, PDFs; discussion on same QT number |
| Booking / Payment / Notification / Profile | SRS §§9–13 |
| Errors & success | SRS reliability and customer clarity NFRs |
| Simple bottom navigation | Customer-first, minimal cognitive load |
| Unified references | `CUS` / `BK` / `QT` / `ORD` / `PAY` / `INV` / `REF` |

---

## 18. Reports & Analytics — Admin UX Flow

### 18.1 Entry Point

**Sidebar → Reports** (active state)

The Reports & Analytics module is accessible from the main admin sidebar. The sidebar item shows an active state when the user is on any reports page.

### 18.2 Reports Dashboard Flow

```
Sidebar click "Reports"
  → Load Reports Dashboard
    → Display role badge (Super Admin / Manager / Sales / Inventory / Accountant)
    → Display date range chips (default: Last 7 Days)
    → Render KPI cards (filtered by role)
    → Render interactive charts
    → Render report category cards (filtered by role)
    → Display export buttons (PDF, Excel, Print)
```

### 18.3 Date Range Selection Flow

```
User clicks a date range chip
  → IF preset (Today, Yesterday, Last 7 Days, etc.)
      → Chip becomes active (highlighted)
      → All KPI cards re-calculate for the new period
      → All charts re-render with new data
      → Trend indicators update (compare new period to the prior period)
  → IF "Custom"
      → Open date picker (start date, end date)
      → User selects range → Confirm
      → Same re-calculation flow as presets
```

### 18.4 KPI Card Drill-down Flow

```
User clicks a KPI card (e.g., "Active Bookings")
  → Navigate to the corresponding detail report
  → Report opens pre-filtered by the KPI metric
    (e.g., Bookings Report filtered to Active/In-Progress status)
  → Back button ("← Reports") returns to the dashboard
```

### 18.5 Report Category Navigation Flow

```
User clicks a report category card (e.g., "Revenue Reports")
  → Navigate to the detail report view
  → Display category-specific KPI cards
  → Display category-specific charts with time toggle
  → Display category-specific tables or breakdowns
  → Back button ("← Reports") returns to the dashboard
```

### 18.6 Chart Interaction Flow

```
User clicks a chart time toggle (Daily / Weekly / Monthly / Yearly)
  → Chart re-renders for the selected granularity
  → Date range still applies as the outer boundary

User clicks a chart segment (pie slice, bar section)
  → Drill-down to filtered detail table
  → E.g., Pie "Pending" → table filtered to Pending status
```

### 18.7 Export Flow

```
User clicks "Export PDF"
  → System generates PDF of the current view (current date range, filters)
  → Browser downloads the PDF file

User clicks "Export Excel"
  → System generates XLSX of the current view
  → Browser downloads the Excel file

User clicks "Print"
  → Browser print dialog opens
  → User confirms → page prints
```

### 18.8 Role-Based Access Flow

```
User logs in → Role determined from session

IF Super Admin:
  → All KPI cards and categories visible

IF Manager / Sales / Inventory / Accountant:
  → KPI and category cards filtered by Hybrid RBAC effective permissions / Dual Dashboard module visibility
  → Modules without granted permissions remain hidden
```

### 18.9 Revenue Reports Detail Flow

```
Revenue Reports opened
  → 4 KPI cards: Revenue Today, Weekly, Monthly, Yearly
  → Monthly Revenue Trend chart (bar, 12-month)
  → Time toggle: Daily / Weekly / Monthly / Yearly
  → Revenue Breakdown section:
      → Revenue by Services (horizontal bar + amount, top 5)
      → Revenue by Products (horizontal bar + amount, top 5)
  → Back button → Reports Dashboard
```

### 18.10 Global Report Search Flow

```
User clicks the search bar on Reports Dashboard
  → User begins typing (e.g., "Revenue")
  → Dropdown shows matching suggestions with type badges
      (Report: Revenue Reports, Revenue: Revenue by Services, etc.)
  → User selects a suggestion
  → Navigate directly to the matching report detail view
  → IF no matches: dropdown shows "No results found"
```

### 18.11 Saved Filters Flow

```
User clicks "+ Save Current Filter"
  → Modal/inline form: enter filter name
  → User types: e.g., "Manager Monthly Review"
  → Confirm → Filter saved as a chip on the dashboard

User clicks a saved filter chip (e.g., "⭐ Finance Weekly Report")
  → Date range and report selection are applied immediately
  → Dashboard refreshes with the saved filter's settings
```

### 18.12 Empty State Flow

```
User selects a date range with no matching data
  → KPI cards display "0" or "—" in muted colour
  → Chart area replaced with empty state:
      Icon + "No data available"
      + "Try another date range or adjust your filters."
      + "← Change Date Range" button
  → Table area replaced with empty state
  → User clicks "← Change Date Range"
      → Focus returns to date range selector
```

### 18.13 Last Generated Display

```
Report detail page loads
  → "Last Generated" chip appears at top of content
  → Shows: Date (e.g., 15 Jul 2026) + Time (e.g., 10:42 AM)
  → Read-only — no interaction
```

### 18.14 Report Summary Flow

```
Report detail page loads (with data available)
  → At the bottom of the content, a summary card appears
  → Shows 4 computed metrics in a grid
  → Badge: "Auto-generated"
  → Values are calculated from the report's current data
  → Read-only — no interaction
```

### 18.15 Dashboard Favorites Flow

```
Admin opens Reports Dashboard
  → Pinned Reports section appears above KPI cards
  → Shows pinned report cards with star icon + name + subtitle
  → Admin clicks a pinned card
      → Navigate to the corresponding report detail
  → Admin hovers a pinned card → "✕ Unpin" appears
      → Click Unpin → Report removed from pinned section
  → Admin can pin new reports from report detail views
```

### 18.16 Business Rules

| # | Rule |
| --- | --- |
| BR-R01 | Reports are read-only. No UI action may modify business data. |
| BR-R02 | All values are calculated from source tables at query time. |
| BR-R03 | Role-based access controls which reports are visible and accessible. |
| BR-R04 | Date range applies globally to all data on the current view. |
| BR-R05 | Export captures only the currently filtered/displayed snapshot. |
| BR-R06 | No new business entities or workflows are introduced. |
| BR-R07 | Saved filters and dashboard favorites are user-specific preferences. |
| BR-R08 | Report summaries are computed from report data only. No AI. |

---

## 19. Settings — Admin UX Flow

### 19.1 Entry Point

**Sidebar → Settings** (active state, visible to Super Admin / admins with settings module access when implemented)

### 19.2 Settings Dashboard Flow

```
Admin clicks "Settings" in sidebar
  → Load Settings Dashboard
  → Display 10 setting category cards (3-column grid)
  → Each card shows: icon, title, description, last updated, "Open →"
  → Admin clicks a category card
      → Navigate to the corresponding settings detail page
```

### 19.3 Settings Edit Flow (General Pattern)

```
Admin opens a settings detail page
  → "← Settings" back button available
  → Form fields pre-populated with current values
  → Admin modifies one or more fields
  → Admin clicks "Save Changes"
      → Validate all fields
      → IF valid → Persist changes, update "Last Updated By", log to audit trail
      → IF invalid → Show field-level validation errors, do not save
  → Admin clicks "Discard Changes"
      → Revert all fields to their last saved values
      → No changes persisted
```

### 19.4 Company Settings Flow

```
Settings Dashboard → Company Settings
  → Company Information form (name, email, phone, website, address)
  → Logo upload section (preview + upload button)
  → Business Hours form (opening/closing time)
  → Social Media form (Facebook, Instagram, WhatsApp)
  → Save / Discard footer
```

### 19.5 Service Settings Flow

```
Settings Dashboard → Service Settings
  → Booking Working Hours (start/end)
  → Working Days (day pills: Sat–Thu active, Fri off)
  → Holidays table (add/view holidays)
  → Booking Configuration (availability dropdown, lead time dropdown)
  → Save / Discard footer
```

### 19.6 Store Settings Flow

```
Settings Dashboard → Store Settings
  → Product Categories (Cleaning Chemicals, Cleaning Tools, Cleaning Accessories, PPE, Air Fresheners)
  → Default Delivery Fee, Tax %, Inventory Warning Level (dashboard Low Stock; Email/SMS outside V1)
  → Save / Discard footer
```

### 19.7 Payment Settings Flow

```
Settings Dashboard → Payment Settings
  → 6 payment method cards with enable/disable toggles
  → Currency dropdown + Payment Instructions textarea
  → Info: "No gateway integration yet"
  → Save / Discard footer
```

### 19.8 Notification Settings Flow

```
Settings Dashboard → Notification Settings
  → Manage templates via Admin Notification Templates APIs
      (template_key, type, channel in_app|email|sms, translations so/en/ar)
  → Browse archived notifications (terminal read/failed) when needed
  → Channel delivery uses dedicated queues; push remains future
```

### 19.9 Security Settings Flow

```
Settings Dashboard → Security Settings
  → Password Policy dropdowns (length, complexity, expiry)
  → Session Timeout dropdown + Login Audit toggle
  → Two-Factor Authentication section
      → Marked with "Future" badge
      → Toggles are greyed out and non-interactive
      → Description: "Coming in a future release"
  → Save / Discard footer (applies to non-future settings only)
```

### 19.10 Numbering Settings Flow

```
Settings Dashboard → Numbering Settings
  → Info banner: "Changing a prefix only affects future records"
  → 5 entity rows: label, editable prefix input, next number preview
  → Admin modifies a prefix (e.g., "ORD-" → "ORDER-")
      → Preview updates in real-time: ORDER-2026-001352
  → Save / Discard footer
```

### 19.11 Language & Localization Flow

```
Settings Dashboard → Language & Localization
  → 4 dropdown fields: Language, Currency, Time Zone, Date Format
  → Save / Discard footer
```

### 19.12 Roles & Permissions Flow

```
Settings Dashboard → Roles & Permissions
  → "Read-Only" badge displayed in top bar
  → Role matrix table: modules × roles (✓/✕)
  → Info banner: "Read-only. Contact development team for changes."
  → No Save / Discard buttons
```

### 19.13 System Information Flow

```
Settings Dashboard → System Information
  → "Read-Only" badge displayed in top bar
  → System details: App Version, DB Version, Last Backup, Status
  → Legal section: Privacy Policy link, Terms & Conditions link
  → No Save / Discard buttons
```

### 19.14 Non-Admin Access Flow

```
Sales or Accountant user attempts to access /admin/settings
  → Settings menu item is hidden from sidebar
  → IF direct URL access → 403 Forbidden page
  → User cannot view or modify any settings
```

### 19.15 Global Settings Search Flow

```
Admin types in the search bar on Settings Dashboard
  → Dropdown shows matching suggestions with category badges
      (e.g., "Payment" → Payment Settings, Numbering PAY- prefix)
  → Admin selects a result
      → Navigate to the corresponding settings page
  → IF no matches → "No results found"
```

### 19.16 Unsaved Changes Protection Flow

```
Admin modifies a field on any settings page
  → System tracks "dirty" state (unsaved changes exist)
  → Admin attempts to navigate away (← Settings, sidebar click, browser back)
      → Confirmation dialog appears:
          "You have unsaved changes"
          [Save Changes] → Persist, then navigate
          [Discard Changes] → Revert, then navigate
          [Continue Editing] → Close dialog, stay on page
  → IF no unsaved changes → Navigate immediately (no dialog)
```

### 19.17 Restore Defaults Flow

```
Admin clicks "↩ Restore Defaults" in footer bar
  → Inline confirmation banner appears above footer:
      "Restore Default Settings? This will reset all [Category] Settings
       to their factory defaults. This action requires confirmation."
      [Confirm Restore] → Reset all fields to factory defaults, mark as dirty
      [Cancel] → Dismiss banner, no changes
  → Admin must still click "Save Changes" to persist the restored defaults
```

### 19.18 Settings History Flow

```
Admin views a settings detail page
  → "Change History" panel shown below form sections
  → Displays recent changes: who, what, old→new, when
  → Admin clicks "View Full History →"
      → Full change log page opens (read-only)
      → All changes for the category listed chronologically
      → Back button returns to settings detail page
```

### 19.19 Maintenance Mode Flow

```
Admin opens System Information
  → Maintenance Mode section displayed
  → Toggle is greyed out with "Future" badge
  → Description: "Coming in a future release"
  → No interaction possible
```

### 19.20 Business Rules

| # | Rule |
| --- | --- |
| BR-S01 | Settings are available to Super Admin (and roles with settings permissions when implemented). |
| BR-S02 | Settings change system configuration only; never modify historical records. |
| BR-S03 | All settings changes are logged (who, what, when). |
| BR-S04 | Future features (2FA, Maintenance Mode) are clearly labelled and non-interactive. |
| BR-S05 | Read-only sections have no Save/Discard buttons. |
| BR-S06 | Numbering prefix changes only affect future records. |
| BR-S07 | No new business features are introduced. |
| BR-S08 | Restore Defaults requires confirmation and does not affect historical records. |

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/04_UX_Flow.md` |
| **Does not include** | UI visuals, Flutter code, APIs, database tables |
| **Next typical artifacts** | Wireframes / UI kit (separate), API specification, implementation backlog |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| UX / Product Design Lead |  |  |  |
| Engineering Lead |  |  |  |

---

*End of Document — Fayadhowr UX Flow Specification v1.0*
