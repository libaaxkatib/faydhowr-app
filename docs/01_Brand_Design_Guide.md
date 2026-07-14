# Brand Design Guide

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `01_Brand_Design_Guide` |
| **Product Name** | Fayadhowr |
| **Document Type** | Brand Design Guide |
| **Version** | 1.0 |
| **Status** | Draft |
| **Date** | 13 July 2026 |
| **Basis Documents** | `docs/02_SRS.md`, `docs/03_Database_Design.md`, `docs/04_UX_Flow.md` |
| **Audience** | Brand, product design, UX, mobile engineering, marketing |

---

## Document Rules

This guide defines Fayadhowr’s **visual brand system and design language** for the customer mobile application.

It intentionally does **not** include:

- UI screen layouts or wireframes
- Flutter / Dart implementation
- CSS / web stylesheets
- Figma file structures or component libraries as deliverable files

**Brand colors in this document are fixed.** Do not replace them or propose alternate brand palettes.

---

# 1. Brand Philosophy

Fayadhowr is a **modern, professional, trustworthy cleaning company**. The brand promises reliable cleaning services and related store products through a calm, high-clarity digital experience that feels as clean as the work itself.

The product experience must communicate competence before persuasion: clear offerings, visible prices, proof of quality (before & after, reviews), and frictionless journeys that respect the customer’s time.

## 1.1 Core Values

| Value | Meaning in brand & product |
| --- | --- |
| **Clean** | Visual clarity, uncluttered layouts, bright imagery, orderly hierarchy |
| **Trust** | Transparent pricing, honest status messaging, consistent confirmations |
| **Quality** | Premium photography, careful typography, polished interactive states |
| **Professionalism** | Business-grade tone; no gimmicks, cartoons, or noisy promotional clutter |
| **Simplicity** | Minimal steps, obvious actions, restrained decoration |

## 1.2 Brand Promise (Experience)

Customers should feel that Fayadhowr is:

- Easy to understand at a glance
- Safe to book, quote, and pay with
- Visually aligned with real, high-standard cleaning work

---

# 2. Brand Personality

## 2.1 Personality Traits

| Trait | Expression |
| --- | --- |
| **Reliable** | Steady patterns, predictable navigation, clear success/error states |
| **Approachable** | Plain language; helpful, never condescending |
| **Precise** | Exact prices, references, schedules, and statuses |
| **Calm** | Soft surfaces, measured motion, no aggressive urgency UI |
| **Confident** | Strong primary actions; no hesitation in hierarchy |

## 2.2 Tone of Voice

| Do | Don’t |
| --- | --- |
| Clear, respectful, professional | Hype, slang, or overly casual jokes |
| Short sentences focused on next action | Long marketing paragraphs in transactional flows |
| Reassure with facts (reference numbers, timing) | Vague “magic” claims without proof |
| Use plain error recovery language | Blame the user or show technical jargon |

**Example tone:** “Your booking is confirmed. Reference BK-2026-000001. We’ll notify you when it’s time to pay.” Quotation references use `QT-YYYY-######` (example `QT-2026-000123`). Unified prefixes: `CUS`, `BK`, `QT`, `ORD`, `PAY`, `INV`, `REF`.

## 2.3 Emotional Target

The brand should feel like a well-run professional cleaning service: **fresh, orderly, and trustworthy**—never cold, clinical, or chaotic.

---

# 3. Color System

All brand colors below are **approved and mandatory**. Do not substitute.

## 3.1 Core Palette

| Token | Name | HEX | Role |
| --- | --- | --- | --- |
| `color.primary` | Primary Brand Color | `#0E339D` | Primary actions, key emphasis, active navigation, brand identity accents |
| `color.secondary` | Secondary Brand Color | `#0694AC` | Supporting accents, secondary highlights, complementary emphasis |
| `color.white` | White | `#FFFFFF` | Surfaces, cards, app bars (light), input backgrounds |
| `color.background` | Background | `#F8FAFC` | App canvas / page background behind cards and sections |
| `color.text.primary` | Primary Text | `#1F2937` | Headings and primary body copy |
| `color.text.secondary` | Secondary Text | `#6B7280` | Supporting text, captions, metadata, placeholders |
| `color.border` | Border | `#E5E7EB` | Dividers, input borders, card outlines |
| `color.success` | Success | `#22C55E` | Success states, confirmations, positive status |
| `color.warning` | Warning | `#F59E0B` | Warnings, attention without failure |
| `color.error` | Error | `#EF4444` | Errors, destructive emphasis, failed payment/validation |

## 3.2 Usage Guidance

### Primary `#0E339D`

Use for:

- Primary buttons
- Active bottom-navigation item
- Important links and selected controls
- Brand marks / logo accents where applicable
- Key progress indicators

Avoid:

- Large full-screen fills that reduce readability of photography
- Using primary for error or success meanings

### Secondary `#0694AC`

Use for:

- Secondary emphasis (chips, tags, illustrative accents)
- Supporting icons or highlights beside primary
- Optional gradient accents **only** when subtle and brand-aligned (primary → secondary), never as a loud background theme

Avoid:

- Competing with primary on the same primary CTA
- Replacing success/warning/error semantics

### White `#FFFFFF`

Use for:

- Card surfaces
- Sheets / dialogs
- Input fields
- App bar surface in the default light theme

### Background `#F8FAFC`

Use for:

- Default screen background
- Separating content sections behind white cards
- Creating a clean, airy cleaning-brand atmosphere

### Primary Text `#1F2937`

Use for:

- Titles and headings
- Primary paragraph text
- High-emphasis labels

### Secondary Text `#6B7280`

Use for:

- Subtitles and helper text
- Timestamps, SKUs, subtle metadata
- Placeholder text (at sufficient contrast)

### Border `#E5E7EB`

Use for:

- Card and list dividers
- Input outlines
- Subtle separators between Home sections

### Success `#22C55E`

Use for:

- Booking/order/payment success indicators
- Positive status badges (e.g., Paid, Completed)
- Success toasts/banners

### Warning `#F59E0B`

Use for:

- Attention states (pending review, expiring quotation)
- Non-blocking alerts

### Error `#EF4444`

Use for:

- Validation errors
- Failed payment states
- Destructive actions emphasis (with confirmation)

## 3.3 Color Application Rules

1. Prefer **white cards on `#F8FAFC` background** for content grouping.
2. Keep **one primary CTA color** (`#0E339D`) per view whenever possible.
3. Status colors communicate state only—do not use them as decorative brand fills.
4. Maintain text contrast against intended surfaces (see Accessibility).

---

# 4. Typography

## 4.1 Recommended Font Family

**Primary recommendation (Google Fonts, Flutter-friendly):** **Plus Jakarta Sans**

| Why Plus Jakarta Sans | Benefit |
| --- | --- |
| Modern geometric sans | Matches a premium, clean service brand |
| Excellent UI readability | Strong in mobile headings and buttons |
| Professional, not generic | Avoids default system-only look while remaining practical |
| Broad weight range | Supports clear hierarchy |

**Fallback stack (logical):** Plus Jakarta Sans → system UI sans (platform default) if the font fails to load.

> Alternative acceptable modern Google Font if Plus Jakarta Sans cannot be licensed/bundled for any reason: **Manrope**. Do not mix multiple display families in v1.

## 4.2 Type Roles

| Role | Weight | Size guidance (mobile) | Color | Usage |
| --- | --- | --- | --- | --- |
| **Headings** | SemiBold / Bold (600–700) | 24–32 sp | `#1F2937` | Screen titles, Home hero headline, section titles |
| **Subtitles** | Medium (500) | 16–18 sp | `#6B7280` or `#1F2937` | Section supporting lines, card subtitles |
| **Body Text** | Regular (400) | 14–16 sp | `#1F2937` | Descriptions, forms, FAQ answers |
| **Captions** | Regular (400) | 12–13 sp | `#6B7280` | Metadata, timestamps, helper text, legal footnotes |
| **Buttons** | SemiBold (600) | 14–16 sp | White on primary / primary on outlined | All button labels |

## 4.3 Typography Rules

1. Prefer short headings; avoid all-caps for long phrases.
2. Keep line length comfortable on mobile; wrap early rather than shrink text aggressively.
3. Do not use more than **two weights** in a single small component (e.g., card).
4. Button labels stay sentence case or title case consistently app-wide (pick one; recommend **Title Case** for primary CTAs, sentence case for longer actions).

---

# 5. Icon Style

## 5.1 Style Attributes

| Attribute | Guidance |
| --- | --- |
| **Style** | Outlined / duotone-light; consistent stroke |
| **Stroke** | 1.5–2 px optical weight at 24 px |
| **Corner** | Rounded joins, friendly but professional |
| **Grid** | Align to 24×24 px base; 20 px for dense UI if needed |
| **Color** | Default `#6B7280`; active/accent `#0E339D`; on-primary `#FFFFFF` |

## 5.2 Usage

Use icons to:

- Support navigation recognition (Home, Services, Store, Cart, Account)
- Clarify actions (search, filter, add to cart, upload image, calendar)
- Indicate status (success/warning/error) alongside text—not icon-only for critical states

Avoid:

- Cartoon / sticker icons
- Over-detailed filled illustrations competing with photography
- Mixing multiple icon families in one release

## 5.3 Icon + Label

Critical actions and bottom navigation should pair icons with labels for clarity and accessibility.

---

# 6. Buttons

Buttons are the primary expression of brand action. Keep shapes, heights, and labeling consistent.

## 6.1 Common Button Specs

| Property | Guidance |
| --- | --- |
| Height | 48 px (comfortable touch) |
| Horizontal padding | 16–24 px |
| Radius | Match global control radius (see Border Radius) |
| Label | Plus Jakarta Sans SemiBold, 14–16 sp |
| Min width | Enough for label + 8 px internal breathing room |

## 6.2 Variants

### Primary

- Background: `#0E339D`
- Text/icon: `#FFFFFF`
- Use for the single most important action on a screen (Book, Checkout, Pay, Submit)

### Secondary

- Background: `#0694AC` **or** soft primary-tint surface with `#0E339D` text (choose one secondary pattern and keep it consistent)
- Preferred clean approach: background `#0694AC`, text `#FFFFFF` for strong secondary actions that are not the page’s top CTA
- Use for supporting actions (Continue Shopping, View Details)

### Outlined

- Background: transparent / `#FFFFFF`
- Border: `#E5E7EB` or `#0E339D` for brand outline
- Text: `#0E339D` (brand outline) or `#1F2937` (neutral outline)
- Use for alternative actions (Request Quotation as secondary on a priced product, Cancel, Not now)

### Disabled

- Background: `#E5E7EB` (or muted primary at reduced emphasis)
- Text: `#6B7280`
- No press feedback; communicate why disabled when helpful (helper caption)

### Loading

- Keep button dimensions stable
- Replace label with progress indicator in white (on primary/secondary) or primary color (on outlined)
- Disable additional taps while loading to prevent duplicate bookings/payments

## 6.3 Button Hierarchy Rules

1. One Primary per focused view.
2. Quotation on priced products remains visually secondary to Add to Cart / Checkout.
3. Destructive actions use Error emphasis only when confirming irreversible outcomes.

---

# 7. Cards

Cards organize services and products on Home and browse surfaces. Prefer white cards on `#F8FAFC` with light borders—not heavy shadows.

## 7.1 Shared Card Principles

| Property | Guidance |
| --- | --- |
| Surface | `#FFFFFF` |
| Border | `#E5E7EB` (subtle) |
| Radius | Consistent card radius (see Border Radius) |
| Padding | 12–16 px |
| Image | High-quality photo on top or leading edge; never clipart |
| Title | Primary text, SemiBold |
| Meta | Secondary text |
| CTA | Clear, compact; primary or text-button style |

## 7.2 Service Cards

Should communicate:

- Service image
- Service name
- Short value line
- Pricing model cue (fixed price and/or “Quotation” label)
- Path into Service Details

Tone: professional service offering, not a coupon tile.

## 7.3 Product Cards

Should communicate:

- Product image
- Product name
- **Visible price** (mandatory)
- Availability cue when relevant
- Path into Product Details / Add to Cart

Tone: clean retail clarity; price must never be hidden or styled as an afterthought.

## 7.4 Card Don’ts

- No dense multi-badge stacks
- No neon gradients or sticker overlays on imagery
- No dark scrims that crush photo quality

---

# 8. Image Style

Imagery is a primary trust vehicle for a cleaning brand and for Fayadhowr’s Home proof content (hero, galleries, before & after).

## 8.1 Use

- **Real cleaning images** (real spaces, real work contexts)
- **High quality** resolution suitable for modern phone screens
- **Bright environments** with natural or clean artificial light
- **Professional uniforms** for staff visible in brand photography
- Consistent white-balance; tidy compositions; visible cleanliness outcomes

## 8.2 Before & After

- Same angle/framing when possible
- Honest representation of results
- Prefer paired layouts that make the improvement obvious without sensational filters

## 8.3 Avoid

- Low quality / blurry / heavily compressed photos
- Dark, gloomy, or muddy images
- Clipart
- Cartoon graphics
- Stock photos that feel unrelated to cleaning or the local service context
- Over-processed filters that look artificial

## 8.4 Image Treatment in UI

- Favor full-bleed hero photography with restrained overlays only when text requires contrast
- Keep overlays light; prefer placing text on solid brand surfaces rather than heavy image darkening
- Quotation upload previews should remain faithful to the customer’s original photo

---

# 9. Spacing System

Fayadhowr uses an **8px grid**.

## 9.1 Spacing Scale

| Token | Value | Typical use |
| --- | --- | --- |
| `space.1` | **4 px** | Hairline adjustments, icon-text tight gaps (use sparingly) |
| `space.2` | **8 px** | Base unit; inline gaps, compact lists |
| `space.3` | **16 px** | Default screen padding; card internal spacing |
| `space.4` | **24 px** | Section spacing within a screen |
| `space.5` | **32 px** | Major section breaks on Home |
| `space.6` | **40 px** | Large promotional breathing room |
| `space.7` | **48 px** | Between major Home blocks when extra air is needed |

## 9.2 Layout Rules

1. Default horizontal page inset: **16 px**.
2. Space between Home blocks: **24–32 px**.
3. Related items inside a component: **8–16 px**.
4. Avoid arbitrary values not on the 4/8 px scale.

---

# 10. Border Radius

Consistent corner radius reinforces a modern, friendly-professional feel—softened, not pill-heavy everywhere.

## 10.1 Radius Scale

| Token | Value | Use |
| --- | --- | --- |
| `radius.sm` | **8 px** | Inputs, small chips, tight controls |
| `radius.md` | **12 px** | Buttons, cards, list tiles |
| `radius.lg` | **16 px** | Hero containers, large image frames, sheets |
| `radius.xl` | **24 px** | Optional marketing containers (use rarely) |
| `radius.full` | **999 px** | Avatars, circular icon buttons only |

## 10.2 Rules

1. Default interactive controls: **12 px**.
2. Cards and product/service images: **12–16 px**.
3. Avoid mixing many radii on one screen.
4. Do not default every action to pill shape; reserve full radius for true circular elements.

---

# 11. Shadows

Elevation should be subtle. Cleaning-brand UI stays mostly flat with light depth.

## 11.1 Elevation Levels

| Level | Use | Guidance |
| --- | --- | --- |
| **Level 0** | Flat on background | Border only (`#E5E7EB`), no shadow |
| **Level 1** | Cards resting on `#F8FAFC` | Soft shadow, low opacity, small Y-offset |
| **Level 2** | Floating panels / dropdown menus | Slightly stronger soft shadow |
| **Level 3** | Modal sheets / important overlays | Deeper soft shadow; still diffused, not harsh |

## 11.2 Shadow Character

- Soft, diffused, neutral (not colored neon glow)
- Prefer border + Level 1 over heavy drop shadows
- No multi-layer dramatic glows
- Dark mode is not part of this brand baseline

---

# 12. Form Elements

Forms appear in booking, quotation (including image upload), checkout, auth, and profile flows. They must feel precise and calm.

## 12.1 Inputs

| Property | Guidance |
| --- | --- |
| Background | `#FFFFFF` |
| Border | `#E5E7EB` default; `#0E339D` on focus; `#EF4444` on error |
| Text | `#1F2937` |
| Placeholder | `#6B7280` |
| Height | 48 px |
| Radius | `radius.sm` / `radius.md` (8–12 px) |
| Label | Above field, Medium/Regular, clear |

Helper and error text sit below the field in caption size.

## 12.2 Dropdowns

- Same height and radius language as inputs
- Clear closed state and open list on white surface
- Selected value in primary text color
- Use for bounded choices (slots, fulfillment type, address pickers)

## 12.3 Checkboxes

- Unchecked: border `#E5E7EB` on white
- Checked: fill/accent `#0E339D` with white check
- Label in primary/secondary text as appropriate
- Minimum touch target 44×44 px including label hit area

## 12.4 Radio Buttons

- Same brand accent `#0E339D` when selected
- Exclusive selection within a group
- Prefer radios for mutually exclusive booking/payment choices
- Always pair with visible text labels

## 12.5 Form Rules

1. Validate inline after submit attempt or on blur for critical fields.
2. Never rely on color alone for errors—use text.
3. Image upload controls for quotations should show format guidance (JPG, JPEG, PNG, WebP) near the control.

---

# 13. Navigation Style

Navigation must support the approved UX Flow: value-first Home, easy Services/Store browse, and Account for authenticated personal areas.

## 13.1 Bottom Navigation

| Property | Guidance |
| --- | --- |
| Surface | `#FFFFFF` |
| Top border / separator | `#E5E7EB` optional subtle |
| Inactive icon/label | `#6B7280` |
| Active icon/label | `#0E339D` |
| Labels | Always visible (icon + text) |
| Elevation | Level 1 or flat with border |

Keep destinations aligned with UX Flow (Home, Services, Store, Cart/Account structure as specified in UX navigation). Do not invent extra root tabs that dilute simplicity.

## 13.2 App Bar

| Property | Guidance |
| --- | --- |
| Surface | `#FFFFFF` (default) |
| Title | `#1F2937`, SemiBold |
| Icons | `#1F2937` / `#6B7280` |
| Border | Optional bottom `#E5E7EB` |
| Behavior | Clean, minimal; search may appear on Home below Hero per UX Flow |

Avoid large colored app bars that compete with Hero photography.

## 13.3 Floating Buttons (If Used)

Floating action buttons are **optional** and should be rare.

If used:

- Background `#0E339D`, icon `#FFFFFF`
- One floating action maximum per screen
- Do not obscure primary bottom navigation or critical CTAs
- Prefer in-page primary buttons for Book / Checkout / Pay over FABs

Default recommendation for Fayadhowr v1: **minimize FABs**; use standard primary buttons in content and checkout flows.

---

# 14. Accessibility

Accessibility supports Trust and Simplicity: every customer should be able to read, tap, and recover from errors.

## 14.1 Contrast

| Pairing | Requirement |
| --- | --- |
| `#1F2937` on `#FFFFFF` / `#F8FAFC` | Primary text — pass strong contrast |
| `#6B7280` on `#FFFFFF` | Secondary text — keep for supporting copy only |
| `#FFFFFF` on `#0E339D` | Primary buttons — required pairing |
| `#FFFFFF` on `#0694AC` | Secondary filled buttons |
| Error/warning text | Do not place low-contrast status text on vivid fills without sufficient contrast treatment |

Never present critical information as color-only.

## 14.2 Touch Targets

| Element | Minimum |
| --- | --- |
| Buttons, nav items, icon buttons | **44×44 px** (prefer 48 px height for full-width buttons) |
| Checkbox/radio hit area | **44×44 px** including label |
| Spacing between adjacent tap targets | At least **8 px** |

## 14.3 Font Sizes

| Content | Minimum guidance |
| --- | --- |
| Body | ≥ 14 sp |
| Captions | ≥ 12 sp |
| Buttons | ≥ 14 sp |
| Critical errors | ≥ 13–14 sp, not tiny footnotes only |

Respect dynamic text scaling where the platform allows without breaking primary CTAs.

## 14.4 Additional Accessibility Rules

1. Provide text labels for icons in navigation and critical actions.
2. Maintain visible focus/pressed states for interactive elements.
3. Error messages must be textual and adjacent to the problem field.
4. Success confirmations include reference numbers readable as text (not image-only).

---

# 15. Design Principles

The Fayadhowr application should feel:

| Principle | Practical meaning |
| --- | --- |
| **Modern** | Contemporary type, clean geometry, current mobile patterns |
| **Premium** | High-quality photography, restrained color, polished states |
| **Clean** | `#F8FAFC` canvas, white cards, ample 8 px-grid spacing |
| **Minimal** | One job per section; limited CTAs; no decorative noise |
| **Professional** | Business-grade tone and imagery aligned with real cleaning work |
| **Easy to use** | Value before login, obvious search under Hero, clear Book vs Quote vs Buy paths |

## 15.1 Experience Checklist

Before approving any visual direction, confirm:

1. Brand blues `#0E339D` and `#0694AC` are used as specified—no alternate brand palette.
2. Store prices remain visible and prominent.
3. Home order respects UX Flow (Hero → Search → Categories → …).
4. Imagery looks bright, real, and professional.
5. Primary actions are unmistakable; quotation remains appropriately secondary on priced products.
6. Errors and successes are calm, readable, and recoverable.

---

## Brand Token Summary (Quick Reference)

| Token | HEX |
| --- | --- |
| Primary | `#0E339D` |
| Secondary | `#0694AC` |
| White | `#FFFFFF` |
| Background | `#F8FAFC` |
| Primary Text | `#1F2937` |
| Secondary Text | `#6B7280` |
| Border | `#E5E7EB` |
| Success | `#22C55E` |
| Warning | `#F59E0B` |
| Error | `#EF4444` |
| Font | Plus Jakarta Sans (Google Fonts) |
| Spacing base | 8 px |
| Default control radius | 12 px |

---

# 16. Logo Usage

## Approved Usage

- Use the official Fayadhowr logo in its original proportions.
- Use the full-color logo on light backgrounds.
- Use the white version of the logo on dark brand backgrounds when needed.
- The icon-only version may be used where space is limited (such as the mobile app icon).

## Incorrect Usage

- Do not stretch or compress the logo.
- Do not rotate the logo.
- Do not change the logo colors.
- Do not add shadows or visual effects.
- Do not place the logo on busy or low-contrast backgrounds.
- Always maintain sufficient clear space around the logo.

# 17. Mobile App Icon Guidelines

The Fayadhowr mobile application icon shall follow these rules:

- Use only the official Fayadhowr house symbol.
- Do not include the company name as text.
- Maintain generous padding around the icon.
- Use the approved Primary Brand Color (#0E339D) as the background.
- The icon must remain simple, recognizable, and readable at small sizes.
- Do not add gradients, shadows, or unnecessary decorative elements.

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/01_Brand_Design_Guide.md` |
| **Color policy** | Fixed — do not change or replace |
| **Excludes** | UI screens, Flutter code, CSS, Figma layouts |
| **Aligns with** | SRS, Database Design, UX Flow |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| Brand / Design Lead |  |  |  |
| Engineering Lead |  |  |  |

---

*End of Document — Fayadhowr Brand Design Guide v1.0*
