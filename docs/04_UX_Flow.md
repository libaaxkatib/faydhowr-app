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
| Store products always show price | Price is visible on list and detail before cart |
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
| Register | Create customer account → optional verification per policy → continue intent |
| Login | Authenticate → continue intent |
| Credential recovery | Reset path → return to login |
| Logout | End session → browsing remains available; protected areas lock again |

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

Complete shopping experience:

```text
Browse Products
        ↓
View Product Details
        ↓
View Price
        ↓
Add to Cart
        ↓
Continue Shopping  ——or——  Checkout
        ↓
Login (if required)
        ↓
Payment
        ↓
Order Confirmation
```

Optional parallel path: **Request Product Quotation** (does not replace priced purchase).

## 5.1 Browse Products

**Entry points:** Home → Store Products; or Store tab; or category navigation.

**Customer can:** browse categories, product lists, availability cues.

**Login:** Not required.

## 5.2 View Product Details & Price

On product detail, customers always see:

- Product media
- Description
- **Displayed price** (mandatory business rule)
- Availability
- Primary commerce actions

**Login:** Not required to view price or details.

## 5.3 Add to Cart

1. Customer selects quantity (within stock rules).
2. Taps **Add to Cart**.
3. Receives lightweight confirmation (e.g., cart updated feedback).
4. May **Continue Shopping** or open **Cart**.

**Login:** Not required to add to cart.

## 5.4 Cart → Checkout

1. Customer opens Cart.
2. Can update quantities or remove lines.
3. Empty cart shows Empty Cart guidance (see Error Handling).
4. Taps **Checkout**.
5. Soft auth gate if needed (**login required to place an order**).
6. Provide/confirm fulfillment details (delivery/pickup) and address as required.
7. Review order summary with re-validated prices/stock.
8. Proceed to **Payment**.
9. **Order Confirmation** with order reference.

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

1. Customer may add **one or more** files before submission and during Discuss Quotation.
2. Show clear format guidance (images: JPG/JPEG/PNG/WebP; video: MP4/MOV/WebM; PDF as enabled).
3. Allow remove/replace before submit; allow additional uploads in discussion.
4. Block unsupported types with actionable error copy.
5. Submission remains possible per product policy if files are optional; when files materially help assessment, prompt without dead-ending unless business marks them required for that offering.

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

Keep customers informed of lifecycle changes without requiring them to poll history screens.

## 9.2 Channels (Customer UX)

| Channel | Behavior |
| --- | --- |
| **Push** | Time-sensitive alert when permissions allow |
| **In-app Notification Center** | Persistent list of events; mark read |
| Optional email/SMS | If configured later; not required to understand core in-app flow |

## 9.3 Customer Journey

```text
Domain event occurs (booking/quote/order/payment/account)
        ↓
In-app notification created
        ↓
Push attempted (if token + permission)
        ↓
Customer opens notification
        ↓
Lands on related entity detail (booking, quote, order, payment context)
        ↓
Mark as read (on open and/or manual)
```

## 9.4 Key Events Customers Should Feel

- Booking created / confirmed / status changed / cancelled / payment due or received
- Quotation request updates / Quotation Ready / quotation updated (revision) / Under Discussion messages / Accepted / Expired / Cancelled
- Order placed / paid / fulfillment update / cancelled
- Payment success / failure / refund

## 9.5 UX Rules

- Notifications never expose secrets (full payment credentials, etc.).
- Preference controls may reduce non-critical noise without hiding legally/operationally required notices.
- Badge/count on navigation entry to Notification Center when unread items exist.

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

## 10.2 Profile Home Capabilities

| Area | Customer can |
| --- | --- |
| Identity | View/update name, phone, email (per editable rules) |
| Security | Change password / re-authenticate for sensitive changes |
| Addresses | Add, edit, delete, set default |
| Booking History | List and open bookings |
| Order History | List and open orders |
| Quotation History | List quotations; Accept / Discuss when applicable; open timeline & revision history |
| Payments context | Reach payable items via related entities |
| Notifications entry | Open Notification Center / preferences if offered |
| Legal | Access privacy policy and terms |
| Session | Log out |
| Account control | Request deactivation/deletion per policy |

## 10.3 Profile Rules in UX

- System-controlled fields (account status, verification flags) are visible as status, not freely editable.
- Editing profile does not silently rewrite historical order/booking address snapshots.
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
 └── Auth? → Profile Home
      ├── Edit Profile
      ├── Addresses
      ├── Booking History → Booking Details → Pay / Cancel (policy)
      ├── Order History → Order Details → Pay / Track
      ├── Quotation History → Quotation Details → Accept / Discuss → Pay (after Accept)
      ├── Notifications → Entity deep link
      ├── Legal
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
