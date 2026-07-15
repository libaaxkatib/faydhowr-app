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

# 17. Admin Panel UI (Foundation)

> The Admin Panel is **web / desktop-first** and separate from the customer mobile app (SRS §14). This section covers the approved **Dashboard Foundation** plus **role-based architecture**. Management modules are designed later.

## 17.0 Admin Authentication & Roles

| Rule | Specification |
| --- | --- |
| **Login** | **One** Admin Login page only (email + password). No per-role login pages. |
| **Panel** | **One** Admin Panel application for all roles. |
| **Roles** | **Admin** · **Sales** · **Accountant** only |
| **After login** | System detects role and loads permitted dashboard, sidebar, statistics, and actions |
| **Header** | `Welcome, {Name}` and `Role: {Role Name}` (e.g., Welcome, Asad · Role: Admin) |
| **Purpose** | Executive Dashboard — business health at a glance |

### Sidebar by role (approved modules only — no “Later” placeholders)

| Role | Visible navigation |
| --- | --- |
| **Admin** | Dashboard, Customers, Bookings, Quotations, Orders, Payments, Invoices, Receipts, Reports, Services, Store, Notifications, Settings, Audit / Logs |
| **Sales** | Dashboard, Customers, Quotations, Orders |
| Accountant | Dashboard, Payments, Invoices, Receipts, Orders; may add/view **internal customer notes** when profile is opened from a linked finance record |

## 17.1 Desktop Shell

| Region | Specification |
| --- | --- |
| **Left Sidebar** | Brand; **role-adapted** approved modules only |
| **Top Navigation** | Welcome + Role; search; notifications; session |
| **Main Area** | Executive Dashboard content |

## 17.2 Dashboard Overview (KPI Cards)

Total Customers · Active Bookings · Pending Quotations · Orders · Payments · Revenue

Each KPI card is a **navigation shortcut** (hover + click states):

| KPI | Opens |
| --- | --- |
| Total Customers | Customers Module |
| Active Bookings | Bookings Module |
| Pending Quotations | Quotations Module (Pending filter) |
| Orders | Orders Module |
| Payments | Payments Module |
| Revenue | Reports → Revenue |

Every KPI shows a compact **trend indicator** (green ▲ positive / red ▼ negative), e.g. `▲ +8 Today`, `▲ +12% vs Last Month`, `▼ -3 Yesterday`.

## 17.3 Business Monitoring

Widgets/charts for: Pending Bookings, In Progress Bookings, Completed Today, Delayed Bookings, Pending Quotations, Under Discussion Quotations, Accepted Quotations, Expired Quotations.

Each widget opens the related **filtered** module (hover + click), e.g. Delayed Bookings → Bookings (Delayed); Under Discussion → Quotations (Under Discussion).

## 17.4 Customer Service Monitoring

Unanswered Customer Discussions · Customer Replies Waiting · Open Customer Requests · New Customers Today

All metrics are clickable (e.g. Unanswered Discussions → Discussion Module · Unanswered; New Customers Today → Customers · today’s registrations).

## 17.5 Revenue Analytics

Today’s · Weekly · Monthly revenue; Services Revenue vs Store Revenue (clean charts).

Drill-down: Today → Daily Revenue Report; Weekly → Weekly Report; Monthly → Monthly Report; Services / Store segments → respective revenue reports.

## 17.6 Recent Activity

Live feed examples: Booking Created, Quotation Updated, Payment Received, Order Placed, Order Delivered.

## 17.7 Explicit Exclusions (v1)

Do **not** show Staff Performance, Staff on Duty, Jobs Assigned, or Team Workload. Staff Management is not in v1.

## 17.8 Out of Scope for This Foundation

Detailed management UIs for Invoices, Receipts, Catalog, Settings — designed separately.

---

# 18. Admin Customers Management Module

Desktop-first Admin Panel module. Accessible to **Admin** and **Sales** (list/profile). **Accountant** may add/view internal notes when on the profile context. Customers never see staff notes.

## 18.1 Customers List

| Element | Specification |
| --- | --- |
| **Purpose** | Browse registered customers; prioritize business activity |
| **Default filter** | ⭐ **Active Customers** (at least one Booking **or** Quotation **or** Order) |
| **Filters** | Active Customers · Leads (Registered Only) · All Customers · Active · Inactive · Advanced Filters |
| **Search** | Name, CUS number, phone, email |
| **Columns** | Customer Number (`CUS-…`, auto-generated, read-only), Full Name, **Classification** (Lead / Active Customer), Phone, Email, Registration Date, Status (Active / Inactive) |
| **Row action** | **View Profile** only |
| **Forbidden** | Permanent delete · VIP status |

### Classification (computed, no VIP)

| Classification | Rule |
| --- | --- |
| **Lead** | Account registered only — Bookings = 0 **and** Quotations = 0 **and** Orders = 0 |
| **Active Customer** | At least one Booking **or** Quotation **or** Order completed/created |

Every successful registration is a Customer with an automatic unique `CUS-…` number. Classification is business prioritization only — leads remain in the system.

## 18.2 Customer Profile

| Block | Content |
| --- | --- |
| **Identity** | Profile photo, Customer Number (read-only), Full Name, Phone, Email, Preferred Language, Registration Date, Current Status, Classification, **Member Since** badge (registration date) |
| **Business Summary** | Clickable cards: Total Bookings, Total Quotations, Total Orders, Total Payments, **Total Spent** (sum of completed/successful payments only) |
| **Timeline** | Read-only chronological audit with category icons (e.g. 👤 Registered, 📅 Booking, 💬 Quotation Requested, ✅ Accepted, 🛒 Order, 💳 Payment) |
| **Linked Records** | Shortcuts: Bookings, Quotations, Orders, Payments, Notifications (customer-filtered) |
| **Customer Notes** | Internal notes with full audit: **Staff Name**, **Staff Role**, **Date**, **Time**, then body. Admin / Sales / Accountant. **Never** visible to customers |

## 18.3 Business Rules

- Customer records are never permanently deleted (Inactive / suspend / deactivate per policy).
- Customer Number (`CUS-YYYY-######`) is auto-generated and read-only.
- Related commercial records remain linked to the customer.
- Timeline is read-only audit history.
- No VIP tier.

---

# 19. Admin Bookings Management Module

Desktop-first. Primary access: **Admin**. Linked modules for Sales/Accountant via Related Records. No Staff Management.

## 19.1 Bookings List

| Element | Specification |
| --- | --- |
| **Search** | Booking number, customer, service |
| **Advanced filters** | Status, priority, service date, assigned to, etc. |
| **Columns** | Booking Number (`BK-…`), Customer, Service, Booking Date (+ **booking age** e.g. Created 2 days ago / Waiting 5 days), Preferred Service Date, Status, **Priority** (High / Medium / Low, read-only badge), Assigned To (Manual), Last Updated |
| **Row action** | **View Booking** only |
| **Forbidden** | Permanent delete |

### Booking statuses (Admin display — only; controlled dropdown)

Pending Review · Quotation Ready · Under Discussion · Accepted · Scheduled · In Progress · Completed · Cancelled  

(`Rejected` is never used. No custom status values.)

### Priority (read-only)

High · Medium · Low — Fayadhowr color badges on list and details.

## 19.2 Booking Details

| Block | Content |
| --- | --- |
| **Header** | Booking Number (read-only), service title, status, **Priority** badge, **booking age**, customer/CUS, dates, **Assigned To** (manual informational name) |
| **Status update** | Controlled dropdown limited to the eight approved statuses (no free-text) |
| **Customer Information** | Name, phone, email, CUS |
| **Service Details** | Service, pricing model, duration, linked quotation when applicable |
| **Property Details** | Address / property snapshot |
| **Media** | Uploaded images, videos, documents with **counters** e.g. Images (12), Videos (3), Documents (2) |
| **Customer Notes** | Notes from the booking request (customer-origin) |
| **Timeline** | Read-only audit; each event shows action, **actor** (e.g. By Sara (Sales) or System), date · time |
| **Linked Records** | Customer Profile, Quotations, Orders, Payments, Notifications |
| **Internal Notes** | Staff notes with Name, Role, Date, Time — Admin / Sales / Accountant; never customer-visible |

## 19.3 Business Rules

- Booking Number is read-only and auto-generated.
- Booking records are never permanently deleted.
- Every booking remains linked to its customer.
- Timeline is read-only audit history (includes actor).
- Status changes use the approved enum only.
- Priority is read-only display (High / Medium / Low).
- Manual assignment (`Assigned To`) is informational only — no Staff Management module in v1.
- No Booking Value / Estimated Value on this module.

---

# 20. Admin Quotations Management Module

Desktop-first. Access: **Admin**, **Sales** (primary); **Accountant** (notes / linked finance views as permitted). No standalone quotation create.

## 20.1 Origin Rule (mandatory)

Every quotation **must** originate from exactly one approved source:

| Source (Admin display) | Meaning |
| --- | --- |
| **Booking** | Quotation linked to a service booking (`BK-…`) |
| **Product** | Quotation linked to a product quotation request |

**Forbidden:** Admin / Sales / Accountant creating a standalone quotation with no originating Booking or Product Request. The source link is permanent.

## 20.2 Quotations List

| Element | Specification |
| --- | --- |
| **Search** | QT number, customer, service or product |
| **Advanced filters** | Status, source, valid until, etc. |
| **Columns** | Quotation Number (`QT-…`), Source (Booking / Product), Customer, Service or Product, Current Revision, Amount, **Valid Until** (date + countdown e.g. 4 days remaining / Expired · 2 days ago), Status, Last Updated |
| **Row action** | **View Quotation** only |
| **Forbidden** | Permanent delete; Create Quotation (standalone) |

### Approved statuses (only)

Pending Review · Under Discussion · Quotation Ready · Accepted · Expired · Cancelled  

(`Rejected` is never used. Status updates use a controlled dropdown.)

## 20.3 Quotation Details

| Block | Content |
| --- | --- |
| **Header** | QT Number (read-only), Source, Status, Current Revision, **Valid Until** + countdown, linked Booking or Product, Total Amount |
| **Customer Information** | Name, phone, email, CUS |
| **Source & Linkage** | Booking or Product origin; permanent link |
| **Price Breakdown** | Line items + Total Amount (latest revision) |
| **Revision History** | Revision Number, Summary of Changes, **Created By**, Staff Role, Date, Time (permanent audit). Only **latest** accept-eligible; older revisions read-only |
| **Compare Revisions** | Action to compare any two revisions (e.g. v2 ↔ v3). Read-only side-by-side: Added Items, Removed Items, Quantity Changes, Unit Price Changes, Total Amount Difference, Notes Changes |
| **Discussion** | Keyword **search** across messages; attachment counters **Images (n)**, **Videos (n)**, **PDF Files (n)** above attachments; full thread with Sender, Role, Date, Time. History never deleted |
| **Timeline** | Read-only audit with Performed By, Staff Role (or Customer/System), Date, Time |
| **Linked Records** | Customer Profile, Booking, Orders, Payments, Notifications |
| **Internal Notes** | Staff notes with Name, Role, Date, Time — Admin / Sales / Accountant; never customer-visible |

## 20.4 Business Rules

- Quotation Number is read-only and auto-generated.
- Quotations are never permanently deleted.
- Every quotation remains permanently linked to its source (Booking or Product Request).
- Timeline is read-only audit history.
- Discussion history cannot be deleted.
- Only the latest revision can be accepted.

---

# 21. Admin Orders Management Module

Desktop-first. Access: **Admin**, **Sales**, **Accountant** (as permitted). No manual order creation.

## 21.1 Origin Rule (mandatory)

Every order is created **automatically** after a quotation is accepted. There is no Create Order action.

| Source chain | Flow |
| --- | --- |
| **Booking** | Booking → Quotation → Accepted → Order (Automatic) |
| **Product Request** | Product Request → Quotation → Accepted → Order (Automatic) |

The source link is permanent and always traceable.

## 21.2 Orders List

| Element | Specification |
| --- | --- |
| **Search** | ORD number, customer, service or product |
| **Advanced filters** | Order status, payment status, source, order date, etc. |
| **Columns** | Order Number (`ORD-…`), Source (Booking / Product), Customer, Service or Product, Order Date + **Order Age**, Total Amount, Payment Status, Order Status, Last Updated |
| **Order Age** | Display order age beneath the date (e.g., "Created Today", "Created 2 days ago", "Waiting 5 days"). "Waiting" style uses warning colour for orders in Awaiting Payment status |
| **Row action** | **View Order** only |
| **Forbidden** | Permanent delete; Create Order (manual) |

### Approved order statuses (only — controlled dropdown)

Awaiting Payment · Paid · Processing · Ready · Out for Delivery · Completed · Cancelled

### Payment statuses (only) — standardized color system

| Status | Color |
| --- | --- |
| Paid | Green (`--ok`) |
| Partially Paid | Orange (`--warn`) |
| Unpaid | Red (`--danger`) |
| Refunded | Blue (`#1D4ED8`) |

These colors must remain consistent everywhere Payment Status appears across the Admin Panel.

## 21.3 Order Details

| Block | Content |
| --- | --- |
| **Header** | ORD Number (read-only), Source, Order Status, Payment Status, linked Booking or Product Request, linked Quotation (version + Accepted), Customer/CUS, dates, **Order Age** (e.g., "Created 1 day ago") |
| **Current Stage Indicator** | Compact read-only summary above the progress tracker: label "Current Stage" + current stage name (e.g., "Processing") with a primary-coloured dot. Allows immediate stage recognition |
| **Order Progress Tracker** | Visual horizontal stepper: Awaiting Payment → Paid → Processing → Ready → Out for Delivery → Completed. Completed steps show ✓ with green; current step highlighted in primary; future steps muted. Visual indicator only (read-only) |
| **Business Summary** | Summary cards: Total Amount, Amount Paid, Remaining Balance, Payment Status |
| **Financial Summary** | Compact read-only breakdown below Business Summary: Subtotal, Discount, Delivery Fee, Tax, Grand Total, Amount Paid, Remaining Balance |
| **Customer Information** | Name, phone, email, CUS |
| **Source Chain & Linkage** | Source (Booking / Product), linked Booking, linked Quotation (revision + accepted), permanent origin |
| **Ordered Items & Price Breakdown** | Line items, qty, unit price, line total; Subtotal, Discount, Delivery Fee, Tax, Grand Total |
| **Order Documents** | Order PDF, Invoice PDF, Receipt PDF — each with availability indicator: ✅ Available (clickable) or ⏳ Not Available Yet (disabled/dashed) |
| **Timeline (incl. Payment Timeline)** | Read-only audit with Performed By, Staff Role (or Customer/System), Date, Time. Payment events expanded: Payment Requested, Payment Received, Payment Confirmed, Refund Processed — each with Performed By, Role, Date, Time |
| **Linked Records** | Customer Profile, Booking, Quotation (with Discussion access), Payments, **Order Documents** (Order PDF · Invoice PDF · Receipt PDF — navigates to documents section), Notifications |
| **Internal Notes** | Staff notes with Name, Role, Date, Time — Admin / Sales / Accountant; never customer-visible. **Latest Note indicator** displayed above notes: "Latest Note · 15 Jul 2026 · 09:20" (read-only) |

## 21.4 Business Rules

- Order Number is read-only and auto-generated.
- Orders are never permanently deleted.
- Orders can never exist without an accepted quotation (no manual create).
- Every order remains permanently linked to its originating Booking or Product Request and its accepted Quotation.
- Timeline is read-only audit history.
- Discussion history remains accessible through the linked quotation.

---

# 22. Admin Payments Management Module

Desktop-first. Access: **Admin**, **Sales**, **Accountant** (as permitted). No manual payment creation.

## 22.1 Origin Rule (mandatory)

Every payment must originate from an existing **Order**. Payments can never be created manually.

| Source chain | Flow |
| --- | --- |
| **Full chain** | Booking / Product Request → Quotation → Accepted → Order → Payment (Automatic) |

The payment link to its originating Order is permanent and always traceable.

## 22.2 Payments List

| Element | Specification |
| --- | --- |
| **Search** | PAY number, order number, customer, payment method |
| **Advanced filters** | Payment status, payment method, payment date, etc. |
| **Columns** | Payment Number (`PAY-…`), Order Number (clickable link), Customer, Payment Method (with icon), Amount, Payment Status, **Verification** (Verified / Pending Verification badge), Payment Date + **Payment Age** (e.g., "Received 1 day ago", "Waiting Verification 3 days"), Last Updated |
| **Row action** | **View Payment** only |
| **Forbidden** | Permanent delete; Create Payment (manual) |

### Approved payment statuses (only — controlled dropdown)

Pending · Received · Confirmed · Failed · Refunded

### Supported payment methods (with icons)

| Method | Icon Label | Color |
| --- | --- | --- |
| EVC Plus | E | Green |
| eDahab | eD | Orange |
| Jeeb | J | Primary Blue |
| Salaam Somali Bank | SB | Teal |
| Bank Transfer | BT | Grey |
| Debit / Credit Card | DC | Blue |

Icons displayed consistently in both list and details.

## 22.3 Payment Details

| Block | Content |
| --- | --- |
| **Header** | PAY Number (read-only), Payment Status, Payment Method (with icon), **Verification Badge** (Verified / Pending Verification — independent from Payment Status), linked Order, linked Quotation, linked Booking or Product Request, Customer/CUS, dates, **Payment Age** (e.g., "Received 1 day ago") |
| **Current Stage Indicator** | Compact read-only label: "Current Stage" + current stage name (e.g., "Confirmed") |
| **Payment Progress Tracker** | Visual stepper showing payment flow: Pending → Received → Confirmed (normal), Pending → Failed (failure), or Confirmed → Refunded (refund). Current step highlighted |
| **Business Summary** | Summary cards: Amount Due, Amount Paid, Remaining Balance, Payment Status |
| **Financial Audit Summary** | Compact read-only section below Business Summary: Payment Requested By, Payment Confirmed By, Confirmation Date, Last Updated |
| **Payment Information** | Payment Number, Payment Method (with icon), Transaction Reference (with **Copy** button — shows "Copied" confirmation), Amount Paid, Currency, Payment Date, Payment Status, Verification Badge |
| **Customer Information** | Name, phone, email, CUS |
| **Source Chain & Linkage** | Linked Order, Linked Quotation, Linked Booking, Source, Origin Rule — permanent |
| **Payment Documents** | Payment Receipt, Invoice, Order PDF — each with availability indicator: ✅ Available (clickable) or ⏳ Pending (disabled/dashed) |
| **Payment Timeline** | Read-only audit: Payment Requested, Payment Received, Payment Confirmed, Refund Initiated, Refund Completed — each with Performed By, Staff Role (or Customer/System), Date, Time |
| **Linked Records** | Customer Profile, Booking, Quotation, Order, Notifications |
| **Internal Notes** | Staff notes with Name, Role, Date, Time — Admin / Sales / Accountant; never customer-visible. Latest Note indicator displayed above notes (read-only) |

## 22.4 Business Rules

- Payment Number (`PAY-…`) is read-only and auto-generated.
- Payments are never permanently deleted.
- Payments can never exist without an Order (no manual create).
- Every payment remains permanently linked to its originating Order.
- Timeline is read-only audit history.
- Receipt history is permanent.
- Payment status colors standardized: Confirmed (green), Received (teal), Pending (orange), Failed (red), Refunded (blue).

---

## Traceability

| UI/UX area | Source documents |
| --- | --- |
| Colors, type, radius, spacing, buttons | Brand Design Guide |
| Screens & journeys | UX Flow |
| Business rules (prices, quotes, auth) | SRS |
| Entities & attachments (image formats) | Database Design |

---

## 23. Reports & Analytics Module (Admin)

### 23.1 Overview

The Reports & Analytics Module is a **read-only** reporting layer that aggregates and visualises data from Customers, Bookings, Quotations, Orders, and Payments. No report may create, update, or delete business data. All values are calculated from existing system records at query time.

### 23.2 Role-Based Access

| Role | Visible Reports |
| --- | --- |
| **Admin** | All reports (Customer, Booking, Quotation, Order, Payment, Revenue) |
| **Sales** | Customer, Booking, Quotation, Order reports only |
| **Accountant** | Payment, Revenue / Financial reports only |

- A role badge is displayed in the top bar showing the current role and access level.
- Report categories the user cannot access are hidden; a subtle warning bar explains the restriction.

### 23.3 Screen 1 — Reports Dashboard

**Route:** `/admin/reports`

#### Top Bar
- Page title: "Reports & Analytics"
- Role badge (Admin · Full Access / Sales · Limited Access / Accountant · Financial Access)
- Date range selector
- Export buttons (PDF, Excel, Print)

#### Date Range Selector
Chip-style filter bar supporting:
| Preset | Description |
| --- | --- |
| Today | Current calendar day |
| Yesterday | Previous calendar day |
| Last 7 Days | Rolling 7-day window |
| Last 30 Days | Rolling 30-day window |
| This Month | Current calendar month |
| Last Month | Previous calendar month |
| Custom Date Range | Manual start/end date picker |

The selected date range applies to all KPI cards and charts on the dashboard.

#### KPI Cards (6 cards, single row)
| # | Card | Sample Value | Colour | Trend |
| --- | --- | --- | --- | --- |
| 1 | Total Customers | 1,842 | Primary | ↑ 12% vs last period |
| 2 | Active Bookings | 347 | Teal | ↑ 8% |
| 3 | Pending Quotations | 89 | Warn/Amber | ↓ 3% |
| 4 | Orders | 1,340 | Primary | ↑ 15% |
| 5 | Payments | 2,180 | Green | ↑ 18% |
| 6 | Revenue | $124,650 | Green | ↑ 22% |

- Each card shows a trend indicator (up/down + percentage vs. previous period).
- Each card supports **drill-down**: clicking opens the corresponding detail report.

#### Interactive Charts (2-column layout)
**Left — Revenue & Orders Trend (Line / Area / Bar)**
- Time toggle: Daily · Weekly · Monthly · Yearly
- Legend: Revenue, Orders, Payments
- Responds to the selected date range

**Right — Booking Status Distribution (Pie Chart)**
- Segments: Completed, In Progress, Scheduled, Pending, Other
- Centre label shows total count

#### Report Categories (3-column grid, 6 cards)
Each card displays:
- Category icon with gradient background
- Category title and description
- Metric chips (quick summary of available sub-reports)
- Role indicators showing which roles can access
- Chevron for navigation to the detail report

| # | Category | Icon | Accessible By |
| --- | --- | --- | --- |
| 1 | Customer Reports | ☺ | Admin, Sales |
| 2 | Booking Reports | BK | Admin, Sales |
| 3 | Quotation Reports | QT | Admin, Sales |
| 4 | Order Reports | ORD | Admin, Sales |
| 5 | Payment Reports | $ | Admin, Accountant |
| 6 | Revenue Reports | $ | Admin, Accountant |

#### Export Bar
| Action | Icon | Format |
| --- | --- | --- |
| Export PDF | 📄 | Generates a downloadable PDF of the current view |
| Export Excel | 📊 | Generates a downloadable XLSX file |
| Print Report | 🖨️ | Opens browser print dialog |

### 23.4 Customer Reports Detail

**Route:** `/admin/reports/customers`

#### KPI Cards (5 cards)
| Card | Description |
| --- | --- |
| New Customers | Count of newly registered customers in the selected period |
| Active Customers | Customers with at least one order/booking in the period |
| Leads | Customers registered but with no completed order yet |
| Customer Growth | Percentage growth vs. previous period |
| Total Customers | Overall customer count |

#### Charts
- **Customer Growth Trend** — Area chart with Daily/Weekly/Monthly/Yearly toggle
- **Customer Segments** — Pie chart (Active, Leads, New, Inactive)

#### Top Customers by Total Spent (table)
| Column | Description |
| --- | --- |
| # | Rank |
| Customer | Full name |
| Phone | Contact number |
| Total Orders | Lifetime order count |
| Total Spent | Lifetime spend (currency) |
| Status | Active / Lead pill |
| Last Activity | Most recent activity date |

Pagination: standard table footer with page controls.

### 23.5 Booking Reports Detail

**Route:** `/admin/reports/bookings`

#### KPI Cards (5 cards)
| Card | Description |
| --- | --- |
| Total Bookings | All bookings in the selected period |
| Completed | Bookings with status Completed |
| Cancelled | Bookings with status Cancelled |
| Pending | Bookings with status Pending |
| Avg Completion Time | Mean days from creation to completion |

#### Charts
- **Booking Trends** — Bar chart with Daily/Weekly/Monthly/Yearly toggle
- **Booking Status** — Pie chart (Completed, Pending, Cancelled)

#### Drill-down Table
Clicking a KPI card (e.g., Completed) opens a filtered table showing the matching bookings with columns: Booking ID, Customer, Service, Date, Status, Completion Time.

### 23.6 Quotation Reports Detail

**Route:** `/admin/reports/quotations`

#### KPI Cards (5 cards)
| Card | Description |
| --- | --- |
| Total Quotations | All quotations in the selected period |
| Accepted | Quotations accepted by customer |
| Under Discussion | Quotations currently in discussion |
| Expired | Quotations past validity without acceptance |
| Conversion Rate | Accepted / Total × 100 (percentage) |

#### Charts
- **Quotation Funnel** — Bar chart showing Total → Accepted → Under Discussion → Expired → Rejected
- **Conversion Rate Trend** — Bar/line chart showing monthly conversion percentage

### 23.7 Order Reports Detail

**Route:** `/admin/reports/orders`

#### KPI Cards (4 cards)
| Card | Description |
| --- | --- |
| Total Orders | All orders in the selected period |
| Completed Orders | Orders with status Completed |
| Cancelled Orders | Orders with status Cancelled |
| Avg Order Value | Mean order total in the period |

#### Charts
- **Order Volume & Value** — Line/bar chart with time toggle
- **Order Status** — Pie chart (Completed, Processing, Cancelled)

#### Drill-down Table
Filtered table: Order ID, Customer, Items, Total, Status, Date. Pagination.

### 23.8 Payment Reports Detail

**Route:** `/admin/reports/payments`

#### KPI Cards (5 cards)
| Card | Description |
| --- | --- |
| Total Payments | All payments in the selected period |
| Confirmed | Payments with status Confirmed |
| Pending | Payments awaiting verification |
| Failed | Payments that failed processing |
| Refunded | Payments that were refunded |

#### Charts
- **Payment Trends** — Area chart with time toggle
- **Payment Distribution** — Pie chart (Confirmed, Pending, Failed, Refunded)

### 23.9 Revenue Reports Detail

**Route:** `/admin/reports/revenue`

#### KPI Cards (4 cards)
| Card | Description |
| --- | --- |
| Revenue Today | Sum of confirmed payments today |
| Weekly Revenue | Sum of confirmed payments in the current week |
| Monthly Revenue | Sum of confirmed payments in the current month |
| Yearly Revenue | Sum of confirmed payments in the current year |

#### Charts
- **Monthly Revenue Trend** — Bar chart, 12-month view with time toggle
- **Legend:** Services Revenue, Products Revenue

#### Revenue Breakdown (2-column grid)
| Section | Content |
| --- | --- |
| Revenue by Services | Top services ranked by revenue with horizontal progress bars and amounts |
| Revenue by Products | Top products ranked by revenue with horizontal progress bars and amounts |

### 23.10 Interactive Drill-down Rules

- Every KPI card is clickable; clicking opens the corresponding detail report filtered by the card's metric.
- Chart segments (pie slices, bar sections) support drill-down to filtered views.
- Drill-down examples:
  - "Completed Orders" KPI → Orders Report filtered to Completed status
  - "Revenue" KPI → Revenue Reports Detail
  - Pie chart "Pending" segment → Booking list filtered to Pending status

### 23.11 Business Rules

| # | Rule |
| --- | --- |
| BR-R01 | Reports are **read-only**. No report may create, update, or delete business data. |
| BR-R02 | All report values must be **calculated from existing system records** at query time. |
| BR-R03 | Reports must adapt based on the logged-in user's role (see §23.2). |
| BR-R04 | Date range selection applies globally to all KPI cards and charts on the current view. |
| BR-R05 | Export generates a snapshot of the current filtered view only. |
| BR-R06 | No new business features are introduced by this module. |

### 23.12 Global Report Search

A search bar is placed at the top of the Reports Dashboard allowing users to search across all reports.

**Searchable dimensions:**
- Report name (e.g., "Revenue Reports", "Booking Reports")
- Customer name
- Booking ID / reference
- Order ID / reference
- Payment ID / reference
- Revenue category
- Date

**Behaviour:**
- As the user types, a dropdown shows matching suggestions with a type badge (Report, Revenue, Payment, etc.).
- Selecting a suggestion navigates directly to the matching report detail view.
- Search is read-only and does not modify any data.

### 23.13 Saved Filters

Users can save frequently used report filter combinations for quick access.

**Display:**
- A row of saved filter chips appears below the search bar on the dashboard.
- Each chip shows a star icon and the filter name.
- A "+ Save Current Filter" button allows saving the current date range + report selection.

**Examples:**
| Filter Name | Description |
| --- | --- |
| Manager Monthly Review | This Month, all report categories |
| Finance Weekly Report | Last 7 Days, Payment + Revenue reports |
| Revenue This Month | This Month, Revenue Reports only |

**Rules:**
- Saved filters are **user-specific** (stored per user account).
- Clicking a saved filter chip applies its settings immediately.
- Filters are read-only shortcuts; they do not modify business data.

### 23.14 Empty State

Whenever a report has no matching data for the selected date range or filters, a professional empty state is displayed.

**Components:**
| Element | Description |
| --- | --- |
| Icon | A chart/graph icon in a rounded container with subtle gradient background |
| Heading | "No data available" |
| Description | "There are no [entity] records matching the selected date range. Try another date range or adjust your filters." |
| Action | "← Change Date Range" button |

**Rules:**
- KPI cards show "0" or "—" values with muted colour.
- Charts show the empty state in place of the visualisation.
- Tables show the empty state in place of rows.
- The empty state never suggests the system is broken — it conveys that the filter simply returned no results.

### 23.15 Last Generated

Every report detail page displays a read-only "Last Generated" indicator.

**Display:** A subtle chip showing:
- Label: "Last Generated"
- Date: e.g., "15 Jul 2026"
- Time: e.g., "10:42 AM"

**Rules:**
- Positioned at the top of the content area, above KPI cards.
- Read-only — no user interaction.
- Timestamp reflects when the report data was last computed/refreshed.

### 23.16 Report Summary

At the bottom of every report detail view, a computed summary section is displayed.

**Layout:** A card with a 4-column grid of summary items.

**Each item contains:**
| Element | Description |
| --- | --- |
| Metric label | e.g., "Revenue Trend", "Top Service", "Completion Rate" |
| Value | The computed result, colour-coded (green for positive, red for negative) |
| Detail | Additional context (e.g., "vs last month", "$28,400 revenue") |

**Badge:** "Auto-generated" — indicates the summary is computed from report data, not AI-generated.

**Example summaries per report:**
| Report | Summary Items |
| --- | --- |
| Revenue | Revenue Trend, Top Service, Top Product, Growth Rate |
| Customer | Customer Growth, New Registrations, Top Customer, Active Rate |
| Booking | Bookings Trend, Completion Rate, Cancellation Rate, Avg Completion |
| Quotation | Quotations Trend, Conversion Rate, Expired Rate, Active Discussions |
| Order | Orders Trend, Completion Rate, Avg Order Value, Cancellations |
| Payment | Payments Trend, Confirmation Rate, Failed Rate, Refund Rate |

**Rules:**
- All values are calculated from existing report data only. No AI or external data.
- Read-only — no user interaction.

### 23.17 Dashboard Favorites

Admin users can pin favourite reports to the top of the Reports Dashboard.

**Display:**
- A "Pinned Reports" section appears above the KPI cards.
- Shows a label with pin icon and a count badge (e.g., "3 pinned").
- Each pinned report is displayed as a card with: star icon, report name, subtitle (sub-metrics).
- An "Unpin" action appears on hover.

**Examples:**
| Pinned Report | Subtitle |
| --- | --- |
| Revenue Reports | Revenue Today · Weekly · Monthly · Yearly |
| Payment Reports | Total · Confirmed · Pending · Failed |
| Booking Reports | Total · Completed · Cancelled · Pending |

**Rules:**
- Pinned reports appear first on the dashboard for quick access.
- Clicking a pinned card navigates to the corresponding report detail.
- Pinning is user-specific (Admin only).
- This is a convenience feature — no business data is modified.

### 23.18 Responsive Behaviour

| Breakpoint | Layout Changes |
| --- | --- |
| > 1200 px | 6-column KPI row, 3-column category grid, 2-column chart row, 4-column summary |
| 768–1200 px | 3-column KPI row, 2-column category grid, single-column charts, 2-column summary |
| < 768 px | Single-column KPI, single-column categories, stacked charts, single-column summary |

### 23.19 Design Preview Reference

`design-previews/admin-reports-analytics-v1.html` — contains 10 visual flows:
1. Reports Dashboard (Admin full access + Search + Saved Filters + Favorites)
2. Revenue Reports Detail (drill-down + Last Generated + Report Summary)
3. Customer Reports Detail (drill-down + Last Generated + Report Summary)
4. Booking Reports Detail (drill-down + Last Generated + Report Summary)
5. Quotation Reports Detail (drill-down + Last Generated + Report Summary)
6. Order Reports Detail (drill-down + Last Generated + Report Summary)
7. Payment Reports Detail (drill-down + Last Generated + Report Summary)
8. Sales Role view (limited access)
9. Accountant Role view (financial access)
10. Empty State (no data available)

---

## 24. Settings Module (Admin)

### 24.1 Overview

The Settings Module provides system configuration management for Admin users only. Sales and Accountant roles have no access. Settings change system configuration only and never modify historical business records. All settings changes are logged with the actor, date, and time.

### 24.2 Access Control

| Role | Access |
| --- | --- |
| **Admin** | Full access to all settings |
| **Sales** | No access (hidden from sidebar or 403 if direct URL) |
| **Accountant** | No access (hidden from sidebar or 403 if direct URL) |

### 24.3 Screen 1 — Settings Dashboard

**Route:** `/admin/settings`

Displays 10 premium settings category cards in a 3-column grid. Each card shows:
| Element | Description |
| --- | --- |
| Icon | Category-specific gradient icon |
| Title | Category name |
| Description | Brief description of what the category configures |
| Last Updated | Date and time of last change |
| Open button | "Open →" link to the detail screen |

#### Settings Categories

| # | Category | Icon | Description |
| --- | --- | --- | --- |
| 1 | Company Settings | 🏢 | Company name, logo, contact, address, business hours, social media |
| 2 | Service Settings | 🛠 | Booking hours, working days, holidays, availability, lead time |
| 3 | Store Settings | 🛒 | Product categories, delivery fee, tax, inventory warning |
| 4 | Payment Settings | 💳 | Payment methods enable/disable, currency, instructions |
| 5 | Notification Settings | 🔔 | Push/email/SMS toggles, editable templates |
| 6 | Security Settings | 🔒 | Password policy, session timeout, login audit, 2FA (future) |
| 7 | Numbering Settings | # | Editable prefixes, next-number preview |
| 8 | Language & Localization | 🌐 | Default language, currency, time zone, date format |
| 9 | Roles & Permissions | 👥 | Read-only role matrix |
| 10 | System Information | ℹ | App version, database, backup, privacy, terms, status |

### 24.4 Company Settings

**Route:** `/admin/settings/company`

#### Editable Fields
| Field | Type | Example Value |
| --- | --- | --- |
| Company Name | Text input | Fayadhowr Cleaning Services |
| Logo | File upload (PNG/SVG, max 2 MB, 512×512 px recommended) | FD logo |
| Email | Email input | info@fayadhowr.com |
| Phone | Text input | +252 61 234 5678 |
| Address | Textarea | Mogadishu, Somalia — Hodan District, KM-4 |
| Business Hours (Opening) | Time input | 08:00 AM |
| Business Hours (Closing) | Time input | 06:00 PM |
| Website | URL input | https://fayadhowr.com |
| Facebook | URL input | https://facebook.com/fayadhowr |
| Instagram | URL input | https://instagram.com/fayadhowr |
| WhatsApp | Text input | +252 61 234 5678 |

### 24.5 Service Settings

**Route:** `/admin/settings/services`

| Section | Fields |
| --- | --- |
| Booking Working Hours | Start Time, End Time |
| Working Days | Day toggles (Sat–Thu active, Friday off by default) |
| Holidays | Table: Holiday name, Date, Status (Active) |
| Booking Availability | Dropdown: Open / Closed |
| Default Booking Lead Time | Dropdown: 12h / 24h / 48h / 72h |

### 24.6 Store Settings

**Route:** `/admin/settings/store`

| Field | Type | Example |
| --- | --- | --- |
| Product Categories | Tag chips with "+ Add Category" | Cleaning Supplies, Equipment, Detergents, Floor Care, Sanitizers |
| Default Delivery Fee | Currency input | $5.00 |
| Tax Percentage | Percentage input | 5% |
| Inventory Warning Level | Number input | 10 units |

### 24.7 Payment Settings

**Route:** `/admin/settings/payments`

#### Payment Methods (Enable / Disable toggles)
| Method | Default State | Brand Colour |
| --- | --- | --- |
| EVC Plus | Enabled | #E8401A |
| eDahab | Enabled | #00A651 |
| Jeeb | Enabled | #1E3A5F |
| Salaam Somali Bank | Disabled | #004B87 |
| Bank Transfer | Enabled | #475569 |
| Debit / Credit Card | Disabled | #1D4ED8 |

#### Additional Fields
| Field | Type |
| --- | --- |
| Currency | Dropdown (USD / SOS) |
| Payment Instructions | Textarea |

Note: No payment gateway integration yet. Payment methods are listed for customer reference only.

### 24.8 Notification Settings

**Route:** `/admin/settings/notifications`

#### Notification Channels (Enable / Disable toggles)
| Channel | Default |
| --- | --- |
| Push Notifications | Enabled |
| Email Notifications | Enabled |
| SMS Notifications | Disabled |

#### Editable Templates
| Template | Placeholders |
| --- | --- |
| Booking Confirmation | {customer_name}, {booking_id}, {date}, {time} |
| Quotation Ready | {customer_name}, {quotation_id} |
| Payment Received | {customer_name}, {amount}, {order_id} |
| Order Completed | {customer_name}, {order_id} |

Each template card shows the current message body and an "Edit Template" action.

### 24.9 Security Settings

**Route:** `/admin/settings/security`

#### Password Policy
| Setting | Options |
| --- | --- |
| Minimum Length | 6 / 8 / 10 / 12 characters |
| Complexity | Letters only / Letters+Numbers / Letters+Numbers+Symbols |
| Password Expiry | Never / 30 days / 90 days / 180 days |

#### Session & Access
| Setting | Options |
| --- | --- |
| Session Timeout | 15 min / 30 min / 1 hour / 4 hours |
| Login Audit Logging | Toggle (enabled by default) |

#### Two-Factor Authentication
- Clearly marked with a **Future** badge (purple).
- Enable 2FA for Admin Accounts — disabled, greyed out.
- Enforce 2FA for All Staff — disabled, greyed out.
- Description: "Coming in a future release."

### 24.10 Numbering Settings

**Route:** `/admin/settings/numbering`

| Entity | Prefix | Next Number Preview |
| --- | --- | --- |
| Customers | CUS- | CUS-2026-001843 |
| Bookings | BK- | BK-2026-00348 |
| Quotations | QT- | QT-2026-00257 |
| Orders | ORD- | ORD-2026-001352 |
| Payments | PAY- | PAY-2026-002181 |

- Prefix is editable via text input.
- Next number preview updates in real-time.
- Info banner: "Changing a prefix only affects future records. Existing records retain their original numbers."

### 24.11 Language & Localization

**Route:** `/admin/settings/localization`

| Setting | Options |
| --- | --- |
| Default Language | English / Somali (Af Soomaali) / Arabic (العربية) |
| Currency | USD ($) / SOS (Sh) |
| Time Zone | East Africa Time (UTC+3) / GMT (UTC+0) |
| Date Format | DD MMM YYYY / MM/DD/YYYY / YYYY-MM-DD |

### 24.12 Roles & Permissions

**Route:** `/admin/settings/roles`

Read-only role matrix showing module access for Admin, Sales, and Accountant roles.

| Module | Admin | Sales | Accountant |
| --- | --- | --- | --- |
| Dashboard | ✓ | ✓ | ✓ |
| Customers | ✓ | ✓ | ✕ |
| Bookings | ✓ | ✓ | ✕ |
| Quotations | ✓ | ✓ | ✕ |
| Orders | ✓ | ✓ | ✕ |
| Payments | ✓ | ✕ | ✓ |
| Reports (Customer/Booking/Quotation/Order) | ✓ | ✓ | ✕ |
| Reports (Payment/Revenue) | ✓ | ✕ | ✓ |
| Services | ✓ | ✓ | ✕ |
| Store | ✓ | ✓ | ✕ |
| Settings | ✓ | ✕ | ✕ |
| Notifications | ✓ | ✓ | ✓ |
| Audit / Logs | ✓ | ✕ | ✕ |

Info banner: "This matrix is read-only. Role definitions are managed at the system level."

### 24.13 System Information

**Route:** `/admin/settings/system`

Read-only display.

| Field | Example Value |
| --- | --- |
| App Version | v1.0.0 |
| Database Version | PostgreSQL 16.2 |
| Last Backup | 15 Jul 2026 · 02:00 AM |
| System Status | ● Operational (green) |
| Privacy Policy | View link |
| Terms & Conditions | View link |

### 24.14 Global Settings UX

Every settings detail page includes:
| Element | Description |
| --- | --- |
| Save Changes | Primary button — saves all modified fields |
| Discard Changes | Danger/ghost button — reverts to last saved state |
| Last Updated By | Shows: staff name (role) + date + time |

### 24.15 Business Rules

| # | Rule |
| --- | --- |
| BR-S01 | Settings are available to Admin role only. Sales and Accountant must not have access. |
| BR-S02 | Settings change system configuration only. Settings never modify historical business records. |
| BR-S03 | All settings changes are logged (who, what, when). |
| BR-S04 | Future features (e.g., 2FA) are clearly labelled and non-interactive. |
| BR-S05 | Read-only sections (Roles & Permissions, System Info) do not have Save/Discard buttons. |
| BR-S06 | Numbering prefix changes only affect future records. |
| BR-S07 | No new business features are introduced by this module. |

### 24.16 Global Settings Search

A search bar is placed at the top of the Settings Dashboard allowing Admin to search across all settings.

**Searchable dimensions:** Company, Booking, Payment, Currency, Language, Notification, Security, Prefix, Roles, Backup.

**Behaviour:**
- As the user types, a dropdown shows matching suggestions with a category badge.
- Selecting a result navigates to the corresponding settings page.
- Search is read-only.

### 24.17 Unsaved Changes Protection

If the user attempts to leave a settings page with unsaved modifications, a confirmation dialog is displayed.

**Dialog contents:**
| Element | Description |
| --- | --- |
| Icon | Warning icon (amber) |
| Heading | "You have unsaved changes" |
| Description | "You've made changes to [Category] Settings that haven't been saved. What would you like to do?" |
| Action 1 | **Save Changes** — primary button, saves and navigates away |
| Action 2 | **Discard Changes** — danger button, discards and navigates away |
| Action 3 | **Continue Editing** — ghost button, closes the dialog and returns to editing |

### 24.18 Restore Defaults

Every editable settings category includes a "Restore Defaults" button in the footer bar.

**Behaviour:**
- Clicking "↩ Restore Defaults" shows an inline confirmation banner.
- The banner displays: warning icon, description ("This will reset all [Category] Settings to their factory defaults"), "Confirm Restore" and "Cancel" buttons.
- Confirmation is required before applying default values.
- Restore only affects the current category and does not modify historical records.

### 24.19 Settings History

Every editable settings page includes a "View Change History" section displaying the audit log for that category.

**Display per entry:**
| Field | Description |
| --- | --- |
| Changed By | Staff name and role |
| Setting Key | Which setting was changed |
| Old Value | Previous value (struck through, red) |
| New Value | New value (green) |
| Date | Change date |
| Time | Change time |

- A "View Full History →" button opens the complete change log.
- The full history view shows all changes for the category in chronological order.
- Read-only — no user interaction can modify the audit log.
- This is a UI for the existing `settings_audit_log` table.

### 24.20 Maintenance Mode

Under System Information, a Maintenance Mode section is displayed.

**Display:**
- Section title: "Maintenance Mode" with a **Future** badge (purple).
- Toggle: "Enable Maintenance Mode" — disabled, greyed out, non-interactive.
- Description: "Coming in a future release."
- Info banner describing future capabilities: schedule downtime windows, display custom maintenance messages, automatic service restoration.

### 24.21 Responsive Behaviour

| Breakpoint | Layout Changes |
| --- | --- |
| > 1200 px | 3-column settings grid, 2-column form grids |
| 768–1200 px | 2-column settings grid, 2-column form grids |
| < 768 px | Single-column settings grid, single-column form grids |

### 24.22 Design Preview Reference

`design-previews/admin-settings-v1.html` — contains 13 visual flows:
1. Settings Dashboard (10 category cards + Global Search)
2. Company Settings (forms + Change History + Restore Defaults confirmation)
3. Service Settings (hours, days, holidays, availability, lead time + Restore Defaults)
4. Store Settings (categories, delivery fee, tax, inventory warning + Restore Defaults)
5. Payment Settings (6 methods with toggles, currency, instructions + Restore Defaults)
6. Notification Settings (channel toggles, 4 editable templates + Restore Defaults)
7. Security Settings (password policy, session, audit, 2FA future + Restore Defaults)
8. Numbering Settings (5 entity prefixes with next-number preview + Restore Defaults)
9. Language & Localization (language, currency, timezone, date format + Restore Defaults)
10. Roles & Permissions (read-only role matrix)
11. System Information (version, database, backup, status, legal + Maintenance Mode future)
12. Unsaved Changes Protection (confirmation dialog)
13. Settings Change History (full audit log view)
11. System Information (version, database, backup, status, legal)

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
