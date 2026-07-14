# UI/UX Design Specification

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `05_UI_UX_Design` |
| **Product Name** | Fayadhowr |
| **Document Type** | UI/UX Design Specification |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 14 July 2026 |
| **Basis Documents** | `docs/01_Brand_Design_Guide.md`, `docs/02_SRS.md`, `docs/03_Database_Design.md`, `docs/04_UX_Flow.md` |
| **Audience** | Product design, UX, mobile UI architecture, engineering, QA |

---

## Document Rules

This specification defines **screen structure, component behavior, interaction states, and experience patterns** for the Fayadhowr customer mobile app.

It intentionally does **not** include:

- Figma designs, mockups, or wireframe visuals
- Flutter / Dart code
- CSS / stylesheet implementations
- Generated images or asset files

All UI/UX decisions must remain aligned with the approved Brand Design Guide, SRS, Database Design, and UX Flow.

---

# 1. Design Overview

## 1.1 Purpose

The Fayadhowr UI/UX Design Specification translates approved brand and journey rules into a complete, implementable screen and component system for a **customer-only** mobile application.

The product combines:

- Professional cleaning **services** (bookable and quotation-based)
- A priced **store** with optional product quotations
- **Booking**, **quotation**, **payment**, **notification**, and **profile** experiences

## 1.2 Overall Design Approach

| Approach | Description |
| --- | --- |
| **Value-first shell** | App opens on Home; browsing never requires login |
| **Content on soft canvas** | `#F8FAFC` background with white cards (`#FFFFFF`) |
| **Brand-led actions** | Primary CTA uses `#0E339D`; secondary accent `#0694AC` |
| **Trust surfaces early** | Prices, galleries, before/after, reviews, FAQ on Home |
| **Soft auth gates** | Login appears only when booking, quoting, ordering, or opening personal areas |
| **One primary action per focused view** | Quotation stays secondary on priced products |
| **Calm transactional closure** | Confirmations always show durable references and next steps |

## 1.3 Visual System Anchors (from Brand Guide)

| Element | Spec |
| --- | --- |
| Primary | `#0E339D` |
| Secondary | `#0694AC` |
| Background | `#F8FAFC` |
| Surfaces | `#FFFFFF` |
| Text | `#1F2937` / `#6B7280` |
| Border | `#E5E7EB` |
| Status | Success `#22C55E`, Warning `#F59E0B`, Error `#EF4444` |
| Typeface | Plus Jakarta Sans |
| Spacing | 8 px grid |
| Default control radius | 12 px |

## 1.4 Out of Scope for Customer UI

- Admin panel screens
- Employee / technician tooling
- Multi-vendor seller interfaces

---

# 2. Design Principles

| Principle | UI/UX application |
| --- | --- |
| **Clean** | Uncluttered Home blocks, generous spacing, restrained chrome |
| **Modern** | Contemporary type, soft cards, clear iconography, subtle motion |
| **Premium** | High-quality photography, polished states, no clipart or gimmicks |
| **Trustworthy** | Visible prices, explicit statuses, honest errors, durable references |
| **Minimal** | Few CTAs, shallow navigation, no decorative badge stacks |
| **Customer First** | Fast browse, soft auth, recoverable errors, obvious next actions |

### Supporting rules

1. Never hide store product prices.
2. Match service primary CTA to pricing model (`Book` vs `Request Quotation`).
3. Preserve enterable form data across recoverable network failures where safe.
4. Prefer border + light elevation over heavy shadows.
5. Keep motion subtle; never block critical feedback.

---

# 3. Screen Inventory

Complete customer-facing screen list for Version 1.

## 3.1 System & Entry

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-001 | Splash | No |
| S-002 | Home | No |
| S-003 | Search Results | No |
| S-004 | No Internet / Offline | No |
| S-005 | Server Error | No |

> Onboarding is **not required** for v1. Value-before-login Home replaces multi-step onboarding.

## 3.2 Discovery — Services

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-010 | Services (catalog) | No |
| S-011 | Service Category List | No |
| S-012 | Service Details | No |
| S-013 | Service Gallery / Image Viewer | No |
| S-014 | Service Before & After Viewer | No |

## 3.3 Discovery — Store

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-020 | Store (catalog) | No |
| S-021 | Product Category List | No |
| S-022 | Product Details | No |
| S-023 | Product Gallery / Image Viewer | No |

## 3.4 Trust & Info (also reachable from Home)

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-030 | Before & After Gallery (global) | No |
| S-031 | Reviews List | No |
| S-032 | FAQ | No |
| S-033 | Contact | No |
| S-034 | About | No |
| S-035 | Privacy Policy | No |
| S-036 | Terms of Use | No |

## 3.5 Cart & Commerce

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-040 | Cart | No (checkout yes) |
| S-041 | Checkout | Yes |
| S-042 | Order Confirmation | Yes |

## 3.6 Booking

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-050 | Booking Form | Yes |
| S-051 | Booking Review / Summary | Yes |
| S-052 | Booking Confirmation | Yes |
| S-053 | Booking History | Yes |
| S-054 | Booking Details | Yes |

## 3.7 Quotation

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-060 | Quotation Request (Service) | Yes |
| S-061 | Quotation Request (Product) | Yes |
| S-062 | Quotation Request Confirmation | Yes |
| S-063 | Quotation History | Yes |
| S-064 | Quotation Request Details | Yes |
| S-065 | Quotation Details (Issued Quote) | Yes |
| S-066 | Discuss Quotation | Yes |
| S-067 | Quotation Revision History | Yes |

## 3.8 Payment

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-070 | Payment | Yes |
| S-071 | Payment Processing / Confirming | Yes |
| S-072 | Payment Success | Yes |
| S-073 | Payment Failure | Yes |

## 3.9 Account & Notifications

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-080 | My Account | Yes |
| S-081 | Edit Profile | Yes |
| S-082 | Saved Addresses | Yes |
| S-082B | Payment Methods | Yes |
| S-082C | Add / Edit Payment Method | Yes |
| S-083 | Address Form (Add/Edit) | Yes |
| S-084 | Order History | Yes |
| S-085 | Order Details | Yes |
| S-086 | Notifications Center | Yes |
| S-086A | Notification Details | Yes |
| S-086B | Notification Settings | Yes |
| S-087 | Language | Yes |
| S-087B | Security Hub | Yes |
| S-088 | Change Password | Yes |
| S-088B | Change PIN | Yes (if enabled) |
| S-088C | Two-Factor Auth (placeholder) | Yes |
| S-088D | Active Devices (placeholder) | Yes |
| S-089 | Favorites | Yes |
| S-034 | Help Center | Guest/Yes |
| S-035 | About Fayadhowr | Guest/Yes |
| S-036 | Privacy / Terms | Guest/Yes |

## 3.10 Authentication

| Screen ID | Screen Name | Auth |
| --- | --- | --- |
| S-090 | Login (Phone default · Google · Email) | Soft gate |
| S-090A | OTP Verification (Phone) | Soft gate |
| S-091 | Create Account / Register | Soft gate |
| S-092 | Forgot Password | Soft gate |
| S-093 | Reset Password | Soft gate |
| S-094 | Logout Confirmation | Yes |

---

# 4. Screen Specifications

For each screen: Purpose, Components, Buttons, Inputs, Navigation, Empty / Loading / Error / Success states.

---

## 4.1 Splash (S-001)

| Field | Specification |
| --- | --- |
| **Purpose** | Brief brand moment during app bootstrap |
| **Components** | Official house symbol / logo on brand or white surface; optional short progress |
| **Buttons** | None |
| **Inputs** | None |
| **Navigation** | Auto-routes to Home; no user control |
| **Empty** | N/A |
| **Loading** | Implicit startup load |
| **Error** | If bootstrap fails → Server Error / Offline with Retry |
| **Success** | Lands on Home |

---

## 4.2 Home (S-002)

| Field | Specification |
| --- | --- |
| **Purpose** | Value-first landing; discovery and trust without login |
| **Components** | Hero Banner; Search Bar; Service Categories; Featured Services; Store Products; Before & After Gallery; Customer Reviews; FAQ; Contact Information; optional cart/notification badges |
| **Buttons** | Section “See all”; card CTAs (open detail); Contact actions |
| **Inputs** | Search Bar (tap focuses search experience) |
| **Navigation** | Bottom nav Home; deep into Services/Store/Gallery/FAQ/Contact; Account/Cart affordances |
| **Empty** | Hide empty sections or show restrained placeholders without breaking layout |
| **Loading** | Section skeletons on `#F8FAFC` / white cards |
| **Error** | Inline section retry; global offline banner if needed |
| **Success** | Content rendered; guest and signed-in both valid |

**Home block order (mandatory):**

1. Hero Banner  
2. Search Bar  
3. Service Categories  
4. Featured Services  
5. Store Products  
6. Before & After Gallery  
7. Customer Reviews  
8. FAQ  
9. Contact Information  

---

## 4.3 Search Results (S-003)

| Field | Specification |
| --- | --- |
| **Purpose** | Quick search across Services and Store Products |
| **Components** | Search field; result tabs or grouped sections (Services / Products); Service Cards; Product Cards |
| **Buttons** | Clear query; open result; optional filter chips if available |
| **Inputs** | Search query |
| **Navigation** | Back to Home; open Service/Product Details |
| **Empty** | No-results state with browse CTAs |
| **Loading** | Result list skeletons |
| **Error** | Retry search |
| **Success** | Ranked/grouped results |

---

## 4.4 Services Catalog (S-010) & Category List (S-011)

| Field | Specification |
| --- | --- |
| **Purpose** | Browse all or category-filtered services |
| **Components** | App bar; category chips/list; Service Cards; optional search entry |
| **Buttons** | Open service; category selectors |
| **Inputs** | Optional local filter/search |
| **Navigation** | Bottom nav Services; back; Service Details |
| **Empty** | “No services in this category” + browse other categories |
| **Loading** | Grid/list skeletons |
| **Error** | Full-panel retry |
| **Success** | Scrollable catalog |

---

## 4.5 Service Details (S-012)

| Field | Specification |
| --- | --- |
| **Purpose** | Full evaluation before Book or Request Quotation |
| **Components** | Image gallery; title; pricing model cue / price if fixed; description; inclusions/exclusions; Before & After teaser; FAQ teaser; sticky CTA bar |
| **Buttons** | Primary: **Book Service** or **Request Quotation** (by model); secondary gallery/FAQ; share optional |
| **Inputs** | None (detail is read-only) |
| **Navigation** | Back; Image Viewer; Before & After Viewer; FAQ; Booking Form / Quotation Request; soft auth if needed |
| **Empty** | N/A for core content; hide missing optional blocks |
| **Loading** | Detail skeleton |
| **Error** | Retry load; unavailable service state without dead CTA |
| **Success** | Detail ready; CTA enabled when bookable/quote-enabled |

---

## 4.6 Store Catalog (S-020) & Product Details (S-022)

### Store Catalog

| Field | Specification |
| --- | --- |
| **Purpose** | Browse priced products |
| **Components** | Categories; Product Cards with **visible price**; stock cues |
| **Buttons** | Open product; optional quick add if policy allows |
| **Empty / Loading / Error** | Same pattern as Services catalog |
| **Success** | Priced catalog visible without login |

### Product Details

| Field | Specification |
| --- | --- |
| **Purpose** | Inspect product, see price, purchase or optionally quote |
| **Components** | Swipeable image gallery (placeholders + pagination; zoom-ready); name; **price with unit** (e.g. `12.00 / Bottle`); optional **tier pricing** table; availability badge; optional marketing badge (New / Best Seller / Popular / Limited Stock); SKU; rating & reviews; description; specifications; quantity control **`−  Qty  +`**; related products |
| **Buttons** | **Add to Cart** (primary; disabled when Out of Stock); **Request Quotation** (secondary/outlined when enabled); Favorite; open cart |
| **Inputs** | Quantity (`−` / `+`) |
| **Navigation** | Cart; shared Quotation Module (same as Services); Image Viewer / zoom; back |
| **Empty** | Out of Stock / Available on Request CTAs per status rules |
| **Loading** | Detail skeleton |
| **Error** | Retry; stock conflict messaging on add |
| **Success** | Snackbar “Added to cart” with View Cart action |

**Availability badges (required):** In Stock · Low Stock · Out of Stock · Available on Request  

**Product quotation:** Uses the **same** Quotation Module as Services (Request → Quotation Ready → Accept **or** Discuss). Do **not** create a separate Product Discussion module. Source recorded as `Product` server-side.

---

## 4.7 Cart (S-040)

| Field | Specification |
| --- | --- |
| **Purpose** | Review lines before checkout |
| **Components** | Line items (image, name, unit price, qty); totals summary; empty state |
| **Buttons** | Quantity +/-; Remove; **Checkout** (primary); Continue shopping |
| **Inputs** | Quantity steppers |
| **Navigation** | Store/Home; Checkout (auth gate); Product Details |
| **Empty** | Empty cart illustration/copy + **Browse Store** (Checkout hidden/disabled) |
| **Loading** | Recalculating totals indicator |
| **Error** | Price/stock revalidation messages; retry |
| **Success** | Ready cart with enabled Checkout |

---

## 4.8 Checkout (S-041) & Order Confirmation (S-042)

### Checkout

| Field | Specification |
| --- | --- |
| **Purpose** | Collect fulfillment details and confirm order totals |
| **Components** | Order summary; fulfillment type; **saved address selector**; **Contact Phone Number** (for delivery team); notes; payment entry point |
| **Buttons** | Place order / Proceed to payment; edit address; back to cart |
| **Inputs** | Fulfillment fields; address (reuse saved — never re-ask full address if already collected); contact phone; notes |
| **Navigation** | Requires auth; Payment; soft auth return-to-intent |
| **Empty** | Guard: if cart empty, redirect to Empty Cart |
| **Loading** | Submitting / validating overlay |
| **Error** | Field errors; stock/price change dialog; server/offline retry |
| **Success** | Moves to Payment or Order Confirmation per payment timing policy |

**Contact Phone Number:** Shown on Checkout for delivery coordination. Prefill from profile phone when available; do not ask again elsewhere in this flow if already on file.

### Order Confirmation

| Field | Specification |
| --- | --- |
| **Purpose** | Close commerce loop with order reference |
| **Components** | Success banner; **Order Reference Number** (`ORD-YYYY-######`); item summary; totals; next-step copy |
| **Buttons** | **View Order**; **Download Receipt (PDF)**; **Continue Shopping** |
| **Inputs** | None |
| **Navigation** | Order Details; Home; Store |
| **Do not show** | Estimated Delivery section |
| **Empty** | N/A |
| **Loading** | Brief confirm fetch if needed |
| **Error** | If confirmation uncertain, “Confirming…” then resolve |
| **Success** | Authoritative order placed state |

---

## 4.9 Booking Form (S-050), Review (S-051), Confirmation (S-052)

| Screen | Purpose | Key UI |
| --- | --- | --- |
| Booking Form | Capture schedule, address, notes | Date/slot picker; address; notes; service summary |
| Booking Review | Confirm before submit | Snapshot of service, time, location, amount/policy |
| Booking Confirmation | Trust closure | Booking number, status, Pay CTA if payable, View History |

**Buttons:** Continue / Submit Booking / Pay / View Booking  
**Inputs:** Schedule, address fields, notes  
**Navigation:** Soft auth → form → review → confirmation → Booking Details / Payment  
**Empty:** No slots → explain + alternate time  
**Loading:** Slot fetch; submit spinner on primary button  
**Error:** Validation inline; slot taken; service unavailable; retry  
**Success:** Confirmation with reference  

---

## 4.10 Quotation Request Screens (S-060 / S-061 / S-062)

| Field | Specification |
| --- | --- |
| **Purpose** | Submit service or product quote request with optional files |
| **Components** | Target summary (service/product); requirements; **Description** multiline text area; timing/location/qty; file uploader; format hint |
| **Buttons** | Add files; Remove file; Submit Request |
| **Inputs** | Requirements (required); **Description (Optional)** multiline text area; preferred timing; location; quantity hint; files |
| **Navigation** | Soft auth; Confirmation; History |
| **Empty** | No files yet — prompt that uploads help assessment |
| **Loading** | Upload progress; submit loading button |
| **Error** | Unsupported file type; size limit; validation; network retry |
| **Success** | Request confirmation with request number |

**Description field (Optional):**

- **Label:** Description (Optional)
- **Control:** Multiline text area
- **Placeholder:** Describe your quotation request, explain your uploaded files, or add any special instructions.
- **Examples:**
  - "The attached PDF contains the products I need."
  - "The uploaded video shows the entire office."
  - "The kitchen requires deep cleaning."

**Product quotation:** secondary path; priced purchase remains available.

---

## 4.11 Quotation History & Details (S-063–S-067)

| Field | Specification |
| --- | --- |
| **Purpose** | Track quotations; Accept or Discuss; review timeline and revisions |
| **Components** | List with status chips; Quotation Number; Latest Version / Revision N (Current); line items; totals; validity; terms; timeline; **View Revision History** |
| **Buttons** | **Accept Quotation** / **Discuss Quotation** (when Quotation Ready or Under Discussion & valid); **Pay** after Accept; View Revision History |
| **Inputs** | Discuss screen: messages + additional image/video/PDF uploads; Accept confirmation dialog |
| **Navigation** | Account → Quotation History → Details → Discuss or Accept → Payment |
| **Empty** | No quotations yet + browse Services/Store |
| **Loading** | List/detail skeletons |
| **Error** | Expired/Cancelled blocking accept; retry load |
| **Success** | Acceptance confirmation → Pay CTA |

**Statuses (chips — only):** Pending Review · Quotation Ready · Under Discussion · Accepted · Expired · Cancelled  

**Never show:** Reject Quotation · Rejected

**Discuss Quotation (S-066):** Thread attached to the same Quotation Number; customer and team messaging; additional uploads; Accept latest revision; does not close the quotation.

**Revision History (S-067):** Read-only list of Quotation v1, v2, v3…; only latest may be accepted from Details.

---

## 4.12 Payment Screens (S-070–S-073)

| Screen | Purpose | States |
| --- | --- | --- |
| Payment | Review amount and start provider flow | Loading provider; Error init |
| Confirming | Ambiguous/pending capture | Indeterminate progress; no false success |
| Success | Authoritative paid | Amount, payment ref, entity status, next steps |
| Failure | Failed capture | Message, Retry Payment, Contact |

**Buttons:** Confirm Payment / Pay; Retry; View entity; Home  
**Inputs:** Provider-managed; Fayadhowr shows amount summary only  
**Navigation:** From order/booking/accepted quote  

**Order Summary on Payment (required lines):** Subtotal · Delivery Fee · Tax (default `0.00`) · Total  

### Payment method display order (customer app)

Prioritize Somali payment methods in this order:

1. **EVC Plus** (Default)  
2. **eDahab**  
3. **Jeeb**  
4. **Salaam Somali Bank**  
5. **Bank Transfer**  
6. **Debit / Credit Card** (Optional)  
7. **Digital Wallet** (Future-ready placeholder)

---

## 4.13 Booking / Order History & Details (S-053–S-054, S-084–S-085)

| Field | Specification |
| --- | --- |
| **Purpose** | Owner-scoped tracking and actions |
| **Components** | Filterable lists; status chips; detail header; timeline/status history; Pay/Cancel when allowed |
| **Buttons** | Pay; Cancel (policy); Contact; Back |
| **Inputs** | Optional list filters |
| **Navigation** | Account only (auth required) |
| **Empty** | Empty history + Browse CTA |
| **Loading** | List skeletons |
| **Error** | Retry; cancel-not-allowed message |
| **Success** | Detail with current status |

---

## 4.14 Notifications Module (S-086 / S-086A / S-086B)

### Notifications List (S-086)

| Field | Specification |
| --- | --- |
| **Purpose** | In-app lifecycle inbox for all customer notifications |
| **Components** | Search; filters All / Unread / Read; category accents; rows with icon, title, short message, date/time, read/unread indicator |
| **Buttons** | Open notification; **Mark as Read**; **Mark All as Read** |
| **Inputs** | Search query |
| **Navigation** | Notification Details → related record (Booking / Quotation / Order / Payment / Account) |
| **Empty** | “No notifications yet” |
| **Loading** | List skeletons |
| **Error** | Retry |
| **Success** | Opens details / related entity |
| **Do not include** | Delete Notification; Clear All Read (notifications are permanent history) |

**Categories (icon + color accent each):** Booking · Quotation · Discussion · Order · Payment · Delivery · Account · General Announcements

### Notification Details (S-086A)

| Field | Specification |
| --- | --- |
| **Purpose** | Full message + deep-link action |
| **Components** | Title; date/time; **status Read / Unread**; full message; related reference number (`BK-` / `QT-` / `ORD-` / `PAY-`…); primary action |
| **Buttons** | View Booking / View Quotation / View Order / View Payment (context-specific); Mark as Read (when Unread) |
| **Navigation** | Related entity details |
| **Do not include** | Delete Notification |

### Notification Settings (S-086B)

| Field | Specification |
| --- | --- |
| **Purpose** | Customer preference toggles |
| **Components** | Switches for **Push Notifications**; **Email Notifications**; Booking; Quotation; Discussion; Order; Payment; Marketing |
| **Buttons** | Save (or auto-save toggles) |
| **Navigation** | Back to Notifications / Settings |

---

## 4.15 Profile / My Account Module (S-080–S-089, S-034–S-036)

### My Account (S-080)

| Field | Specification |
| --- | --- |
| **Purpose** | Account hub |
| **Components** | Profile photo; full name; **CUS-YYYY-######** (read-only); email; phone; preferred language; member since; quick stats (Bookings / Quotations / Orders); quick actions menu |
| **Quick actions** | Edit Profile · Saved Addresses · Payment Methods · Notifications · Language · Security · Help Center · About Fayadhowr |
| **Buttons** | Quick action rows; Logout (opens confirmation — never immediate) |
| **Navigation** | Soft auth; all account sub-screens; Favorites / Histories via hub or menus |
| **Loading** | Profile skeleton |
| **Error** | Retry; suspended banner if applicable |

### Edit Profile (S-081)

Editable: profile photo, full name, email, phone. **Customer Reference Number read-only.** Primary: **Save Changes**.

### Saved Addresses (S-082 / S-083)

List + Add / Edit / Set Default / Mark Inactive. **Visible `Default` badge** on the current default address; **Active** / **Inactive** status badges. Non-default active addresses show **Set Default**. **Never permanently delete.**

### Payment Methods (S-082B)

Saved methods for EVC Plus, eDahab, Jeeb, Salaam Somali Bank, Bank Transfer, Debit/Credit Card. **Visible `Default` badge** on the current default method; other methods show **Set Default**. Add + Change Default. **Payment history never deleted.**

### Language (S-087B)

Somali · English · Arabic — selection updates entire app UI.

### Security (S-088+)

Change Password; Change PIN (if enabled); Two-Factor Authentication (placeholder); Active Devices (placeholder).

### Help Center / About

FAQ; Contact Fayadhowr (WhatsApp, Phone, Email).

**About Fayadhowr (company profile):** Company Story, Mission, Vision, Years of Experience, Certificates & Licenses, Awards & Recognition, Partners & Clients, Company Statistics (Completed Projects, Happy Customers, Team Members), Privacy Policy, Terms & Conditions, App Version. Premium trust-building layout with cards, icons, and branding.

### Logout Confirmation (S-094)

Triggered from My Account → Log out. Title: **Log Out**. Message: **Are you sure you want to log out?** Buttons: **Cancel** · **Log Out**. Session ends only after confirm.

### Favorites (S-089)

| Field | Specification |
| --- | --- |
| **Purpose** | Allow customers to view all their saved favorite services and products |
| **Components** | App bar title Favorites; grouped or tabbed lists of favorite Service Cards and Product Cards; heart controls on cards |
| **Buttons** | Open service/product detail; remove favorite (heart); Browse Services / Browse Store from empty state |
| **Navigation** | Account → Favorites (auth required); opens Service Details or Product Details; back to Account |
| **Empty** | Empty Favorites state with guidance to save items from Services/Store |

---

## 4.16 Authentication (S-090–S-094)

| Field | Specification |
| --- | --- |
| **Purpose** | Soft gate; resume original intent after success |
| **Login actions** | **Continue with Phone** (default) · **Continue with Google** · **Continue with Email** — method order is a business rule, not UI tabs |
| **Phone login** | Default country 🇸🇴 Somalia (+252); Phone Number field; primary **Continue with Phone** → OTP verification |
| **Google login** | Secondary **Continue with Google**; native device Google accounts / account picker (documented implementation detail only — not shown in customer UI) |
| **Email login** | Email Address, Password, Show/Hide Password, Remember Me, Forgot Password; primary **Continue with Email** |
| **Create Account** | Phone Number (primary), Email (optional), Password, **Confirm Password** (Show/Hide; must match before submit); Google button available |
| **Components** | Brand mark; method action stack; general message (**Welcome to Fayadhowr** / **Sign in to your account or continue with your preferred method.**) — reusable for first-time and returning customers |
| **Buttons** | Continue with Phone; Continue with Google; Continue with Email; Create Account; Forgot password; dismiss / back to browse |
| **Navigation** | Return-to-intent on success; dismiss returns to prior browse |
| **Loading** | Button loading; disable double submit |
| **Error** | Invalid credentials / OTP; password mismatch on register; field validation; network retry |
| **Success** | Resume Book / Quote / Checkout / Account / Favorites / other protected intent |

Forgot Password, Reset Password, and Logout Confirmation screens remain as previously approved — not redesigned by this refinement.

---

## 4.17 Global Utility States (S-004, S-005)

| Screen | Empty | Loading | Error | Success |
| --- | --- | --- | --- | --- |
| No Internet | N/A | N/A | Message + Retry; preserve form drafts | Resume previous screen |
| Server Error | N/A | N/A | Apology + Retry + Contact | Resume on recovery |

---

# 5. Component Library

All components inherit Brand Design Guide tokens (colors, type, radius, spacing, shadows).

## 5.1 Buttons

| Variant | Use |
| --- | --- |
| Primary | Main action (`#0E339D`, white label, 48 px height, 12 px radius) |
| Secondary | Supporting filled action (`#0694AC`) |
| Outlined | Alternatives (Request Quotation, Cancel, Not now) |
| Text / Tertiary | Inline low-emphasis actions |
| Disabled | Muted; non-interactive |
| Loading | Stable size; spinner replaces/ besides label; blocks re-tap |

## 5.2 Cards

White surface, `#E5E7EB` border, 12–16 px radius, 12–16 px padding, Level 0–1 elevation.

## 5.3 Search Bar

- Placed immediately below Hero on Home
- Placeholder: search services and products
- Leading search icon; trailing clear when query present
- Tap opens Search Results experience
- No login required

## 5.4 Product Card

- Image, name, **mandatory visible price with unit** (e.g. `12.00 / Bottle`)
- **Availability badge:** In Stock · Low Stock · Out of Stock · Available on Request
- Optional marketing badge: New · Best Seller · Popular · Limited Stock
- Opens Product Details
- Clean retail layout; no hidden pricing
- **Favorite (Heart) icon** in the top-right corner
- Customers can tap the icon to save or remove a product from Favorites
- Saving favorites requires login (soft auth gate); browsing cards does not

## 5.5 Service Card

- Image, name, short value line, pricing-model cue (fixed price and/or Quotation)
- Opens Service Details
- **Favorite (Heart) icon** in the top-right corner
- Customers can tap the icon to save or remove a service from Favorites
- Saving favorites requires login (soft auth gate); browsing cards does not

## 5.6 Review Card

- Rating, optional title, comment excerpt, metadata
- Published reviews only
- Used on Home and Reviews List

## 5.7 Gallery

- Horizontal thumbnail strip or grid of high-quality photos
- Tap opens Image Viewer
- Used on service/product detail and Home teasers

## 5.8 Image Viewer

- Full-screen / immersive pager for images
- Close control; swipe between images
- No decorative filters

## 5.9 Before & After Viewer

- Paired comparison (same framing when possible)
- Labels Before / After
- Honest imagery; no sensational effects
- Reachable from Home gallery and service detail

## 5.10 FAQ Item

- Expandable row: question (header) / answer (body)
- Single or accordion expand pattern kept consistent
- Secondary text for answers

## 5.11 Dialogs

- Centered modal for confirmations (logout, Accept Quotation, remove cart item, cancel booking)
- Title, body, Primary + Outlined actions
- One destructive action max, emphasized with error color only when irreversible

## 5.12 Bottom Sheets

- For filters, address picker, fulfillment type, share/optional menus
- White surface, large radius top corners, drag handle optional
- Primary action pinned at bottom when needed

## 5.13 Snackbars

- Transient feedback (Added to cart, Profile saved)
- Optional action (View Cart)
- Do not use snackbars as sole confirmation for payments or bookings

## 5.14 Loading Indicators

| Type | Use |
| --- | --- |
| Skeleton | Lists, Home sections, details |
| Circular / linear | Inline section refresh |
| Button spinner | Submit/Pay |
| Full-screen blocker | Rare; only for non-idempotent critical commits if required |

## 5.15 Badges

- Cart count on Cart entry
- Unread count on Notifications / Account
- Primary color; keep numeric, not noisy

## 5.16 Status Chips

| Status family | Color language |
| --- | --- |
| Success / Paid / Completed / Accepted | `#22C55E` soft surface + text |
| Warning / Pending Review / Quotation Ready / Expiring | `#F59E0B` soft surface + text |
| Error / Failed / Cancelled / Expired | `#EF4444` soft surface + text |
| Neutral / Processing / Under Discussion | Border `#E5E7EB` + secondary text (or soft secondary tint) |
| Brand / Confirmed | Soft primary tint + `#0E339D` text |

Quotation chips must use **only** the six V1 statuses (never Rejected).

Always pair chip color with text label.

---

# 6. Navigation Design

## 6.1 Bottom Navigation

| Tab | Destination |
| --- | --- |
| Home | S-002 |
| Services | S-010 |
| Store | S-020 |
| Cart | S-040 (or Store-linked entry with badge if Cart not root—must remain globally reachable) |
| Account | S-080 (soft auth) |

**Style:** White bar; inactive `#6B7280`; active `#0E339D`; icon + label always visible.

## 6.2 Back Navigation

- Platform back / app bar back returns to previous browse context
- After canceling auth, return to prior screen without losing cart when possible
- After confirmation screens, prefer explicit primary next step plus path to Home
- Avoid trapping users in payment failure—always offer Retry and entity detail

## 6.3 Deep Links

| Source | Target |
| --- | --- |
| Push / in-app notification | Booking, Quotation, Order, or Payment context |
| Payment success CTA | Entity detail |
| Search result | Service or Product Details |
| Home section “See all” | Services, Store, Gallery, Reviews, FAQ |

Deep links to protected entities must soft-gate auth, then continue to the target.

---

# 7. Forms

## 7.1 Shared Form Rules

- Labels above fields; 48 px inputs; focus border `#0E339D`; error `#EF4444` + text
- Helper captions in secondary text
- Primary submit 48 px; loading disables double submit
- Preserve in-session drafts across soft network errors when safe

## 7.2 Booking Form

| Fields | Notes |
| --- | --- |
| Service summary | Read-only |
| Date / slot | Required; only valid options |
| Address | Required when service requires address; reuse saved |
| Notes | Optional |

**Submit path:** Review → Confirmation  

## 7.3 Quotation Form

| Fields | Notes |
| --- | --- |
| Target summary | Service or product |
| Requirements | Required |
| Description (Optional) | Multiline text area; placeholder: “Describe your quotation request, explain your uploaded files, or add any special instructions.” Examples: explain PDF contents, uploaded video context, or special cleaning needs |
| Preferred timing / location | As applicable |
| Quantity hint | Product bulk/custom |
| Files | One or more; images, videos, and/or PDF per upload rules |

**Secondary on products;** does not replace Add to Cart.

## 7.4 Checkout Form

| Fields | Notes |
| --- | --- |
| Line summary | Read-only snapshots |
| Fulfillment type | Delivery / pickup |
| Address | Required for delivery |
| Notes | Optional |

Revalidate price/stock before pay/place.

## 7.5 Profile Form

| Fields | Notes |
| --- | --- |
| Full name | Editable |
| Phone / email | Editable per policy |
| System status | Visible, not editable |

Success: snackbar. Sensitive changes may require re-auth.

## 7.6 Address Form

| Fields | Notes |
| --- | --- |
| Label, contact, phone | Optional/required per rules |
| Line1, city | Required |
| Line2, region, postal, country | Optional |
| Default flag | One default per customer |
| Map coords | Optional |

Used by booking and checkout; does not rewrite historical snapshots.

---

# 8. Search Experience

## 8.1 Behavior

1. Entry from Home Search Bar (below Hero).
2. Query searches **Services** and **Store Products**.
3. Results grouped or tabbed by type.
4. Tap opens the relevant detail screen.
5. No login required to search or open results.

## 8.2 Suggestions

- Show recent searches (device-local) when focusing empty field (optional v1)
- Optional category shortcuts while query empty
- Do not fabricate product prices in suggestions—only real catalog entities

## 8.3 No Results

- Clear “No results” message repeating the query
- Actions: Clear search; Browse Services; Browse Store; Back to Home
- Never show a fake success state

## 8.4 Error

- Network/server failure: Retry + Offline guidance
- Keep query text intact

---

# 9. Gallery Experience

## 9.1 Service Gallery

- Embedded on Service Details
- High-quality real cleaning imagery
- Opens Image Viewer on tap

## 9.2 Before & After Gallery

- Home teaser + dedicated viewer
- Paired images with Before / After labels
- Supports trust proof for cleaning outcomes

## 9.3 Image Preview (incl. quotation uploads)

- Local preview thumbnails before quote submit
- Ability to remove/replace
- Unsupported format: actionable error (v1 images only; no PDF/video)

## 9.4 Image Style Compliance

Follow Brand Guide: bright, real, professional uniforms; no clipart, cartoons, or dark low-quality assets.

---

# 10. Cart Experience

## 10.1 Shopping Cart

- Lists product image, name, unit price, line total, quantity
- Shows cart-level subtotal
- Accessible without login; **Checkout requires login**

## 10.2 Quantity

- Standard control: **`−`  quantity  `+`** (never a free-typed primary control on Product Details / Cart)
- Minimum 1
- Respect stock ceilings when tracking stock
- Recalculate totals immediately
- On Product Details, quantity applies to Add to Cart

## 10.3 Remove Item

- Confirm via dialog or undo snackbar (pick one pattern app-wide; prefer confirm for clarity)
- If last item removed → Empty Cart state

## 10.4 Checkout

- Primary Checkout button only when cart has valid items
- Soft auth → Checkout Form → Payment / Confirmation
- On price/stock change: explain and ask to review cart

---

# 11. Booking Experience

## 11.1 Booking Process

```text
Service Details → Book → Auth? → Booking Form → Review → Submit → Confirmation
```

## 11.2 Confirmation

- Success message + booking number
- Schedule and status summary
- Payment next-step if payable
- Link to Booking Details / History

## 11.3 Tracking

- Booking History list with status chips
- Booking Details with status history
- Notifications push status changes
- Cancel only when policy allows, with confirmation dialog

---

# 12. Payment Experience

## 12.1 Payment Screen

- Entity context (order / booking / accepted quotation)
- Amount and currency (authoritative)
- What is being paid (short summary)
- Primary **Pay** launches provider flow
- No card PAN fields stored in Fayadhowr UI beyond provider requirements

## 12.2 Payment Success

- Success color accent restrained
- Payment reference + updated entity status
- Next fulfillment guidance
- CTAs: View entity; Home

## 12.3 Payment Failure

- Clear non-technical message
- Entity remains payable when valid
- **Retry Payment** primary
- Secondary: View details; Contact
- Never leave status ambiguous—use Confirming state until resolved

---

# 13. Profile Experience

## 13.1 My Account

- Auth required (soft auth from Account tab)
- Profile photo, name, read-only **CUS-YYYY-######**, email, phone, preferred language, member since
- Quick stats: Bookings / Quotations / Orders
- Quick actions: Edit Profile, Saved Addresses, Payment Methods, Notifications, Language, Security, Help Center, About Fayadhowr
- Logout opens confirmation (Cancel / Log Out) — never immediate
- Addresses: visible **Default** badge + Active/Inactive; payment methods: visible **Default** badge + Set Default on others
- Addresses never permanently deleted (Inactive); payment history never deleted; language is app-wide
- About Fayadhowr is a trust-building company profile (story, mission, vision, experience, certificates, awards, partners, stats, legal, version)
- Suspended users see blocking banner for new transactions

## 13.2 Orders

- Order History → Order Details
- History tabs/filters: **Active** · **Completed** · **Cancelled**
- Search orders; filter by status
- Pay / track fulfillment per status
- Order Details actions: **Buy Again** (primary alternate), **Track Order** (secondary placeholder — UI only, no backend logic in v1 design), **Download Receipt (PDF)** when available

## 13.3 Bookings

- Booking History → Booking Details
- Pay / cancel per policy

## 13.4 Quotations

- Quotation history with Quotation Number and status chips
- Quotation Details: Latest Version indicator, timeline, **View Revision History**
- Primary actions: **Accept Quotation** / **Discuss Quotation** (never Reject)
- Discuss: messages + additional images/videos/PDFs on the same quotation
- Pay after acceptance

## 13.5 Logout

- Tapping **Log out** never ends the session immediately
- Confirmation dialog: title **Log Out**; message **Are you sure you want to log out?**; buttons **Cancel** · **Log Out**
- After confirmed logout: browsing remains available; protected areas re-gate

## 13.6 About Fayadhowr (Company Profile)

- Brand header + years of experience
- Company Story, Mission, Vision
- Statistics: Completed Projects, Happy Customers, Team Members
- Certificates & Licenses, Awards & Recognition, Partners & Clients
- Privacy Policy, Terms & Conditions, App Version

### Favorites Experience

Favorites let customers save services and products for later. This feature does **not** change booking, quotation, cart, checkout, or payment workflows.

| Moment | Behavior |
| --- | --- |
| **Save Product** | Tap the heart on a Product Card → soft auth if needed → product saved to Favorites; heart shows saved state |
| **Save Service** | Tap the heart on a Service Card → soft auth if needed → service saved to Favorites; heart shows saved state |
| **Remove Favorite** | Tap a filled heart on a card (or from the Favorites list) → item removed from Favorites; heart returns to unsaved state |
| **Empty Favorites State** | When no saved items exist, show clear empty copy and CTAs to browse Services and Store |
| **Favorite List** | Authenticated Favorites screen lists all saved services and products; tap a row/card to open its detail screen |
| **Navigation** | Account → Favorites |
| **Login required** | Login is required to **save** favorites and to **view** the Favorites screen; browsing catalog cards never requires login |

---

# 14. Accessibility

Aligned with Brand Design Guide §14.

## 14.1 Touch Targets

- Minimum **44×44 px**; prefer **48 px** button height
- At least **8 px** separation between adjacent targets
- Expand hit areas for checkboxes/radios via labels

## 14.2 Contrast

- Primary text `#1F2937` on white / `#F8FAFC`
- White text on `#0E339D` / `#0694AC` filled buttons
- Do not use color alone for errors or status—include text labels

## 14.3 Readable Typography

- Plus Jakarta Sans
- Body ≥ 14 sp; captions ≥ 12 sp; buttons ≥ 14 sp
- Support dynamic type where layout allows without clipping primary CTAs
- Bottom navigation always includes text labels with icons

## 14.4 Additional

- Visible focus/pressed states
- Error text adjacent to fields
- Reference numbers as selectable/readable text, not image-only

---

# 15. Motion & Animations

Keep all motion **subtle**, purposeful, and fast.

## 15.1 Page Transitions

| Transition | Guidance |
| --- | --- |
| Push/pop | Standard platform slide/fade; 200–300 ms |
| Modal/dialog | Soft fade + minimal scale; no bounce |
| Bottom sheet | Ease upward; dismiss downward |
| Tab switch | Instant or very light cross-fade; no heavy animation |

## 15.2 Loading Animations

- Skeletons prefer gentle shimmer (low contrast)
- Button spinners replace label without layout jump
- Avoid decorative looped brand animations on transactional screens

## 15.3 Success Animations

- Light check accent or short success fade-in
- No confetti, fireworks, or loud celebration for cleaning-commerce flows
- Payment/booking success emphasizes clarity of reference over spectacle

## 15.4 Motion Don’ts

- No parallax distraction on Home
- No continuous animated gradients
- Never delay payment result behind long animation

---

# 16. Responsive Behavior

Design for phones first; adapt by **spacing, type scale, and column count**—not by inventing new information architecture.

## 16.1 Width Classes (Logical)

| Class | Approx. width | Behavior |
| --- | --- | --- |
| Compact | ~320–374 dp | Single column; 16 px horizontal inset; stacked CTAs |
| Standard | ~375–428 dp | Default phone layout; Home cards in horizontal carousels or 2-col product grids as fits |
| Expanded phone | >428 dp / large phones | Slightly wider cards; keep single-column forms; do not stretch logos |

## 16.2 Adaptive Rules

1. **Home:** Maintain mandatory block order; carousels may show more peek cards on wider phones.
2. **Grids:** Product/service grids may move from 1–2 columns based on width; never crush text.
3. **Forms:** Full-width inputs; sticky CTA bars must clear system gesture insets / safe areas.
4. **Typography:** Prefer wrapping to extreme downscaling; headings may step down one size on compact.
5. **Touch:** Targets never shrink below accessibility minimums on small devices.
6. **Images:** Preserve aspect ratios; use letterboxing/cropping rules that protect faces and product focal points.
7. **Landscape:** Supported for Image Viewer; primary commerce flows optimize for portrait.
8. **Safe areas:** Respect notch, status bar, and home indicator; bottom nav and sticky CTAs pad accordingly.
9. **Cart badge / nav:** Remain visible and tappable on all sizes.
10. **Tables/list densities:** Prefer cards over dense multi-column tables on compact widths.

## 16.3 Consistency Mandate

Responsive changes adjust **layout density only**. They must not change:

- Auth rules
- Home content order
- Price visibility
- Primary vs secondary CTA hierarchy
- Brand colors or logo rules

---

## Traceability

| UI/UX area | Source documents |
| --- | --- |
| Colors, type, radius, spacing, buttons | Brand Design Guide |
| Screens & journeys | UX Flow |
| Business rules (prices, quotes, auth) | SRS |
| Entities & attachments (image formats) | Database Design |

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/05_UI_UX_Design.md` |
| **Excludes** | Figma, Flutter code, CSS, generated images/mockups |
| **Next typical artifacts** | Visual design in design tool (optional), Flutter implementation, API integration |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Product / UX Design Lead |  |  |  |
| Mobile UI Architect |  |  |  |
| Engineering Lead |  |  |  |

---

*End of Document — Fayadhowr UI/UX Design Specification v1.0*
