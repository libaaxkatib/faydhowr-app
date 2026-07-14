# Figma Design System Specification

## Fayadhowr — Customer Mobile Application

| Field | Value |
| --- | --- |
| **Document ID** | `08_Figma_Design_System` |
| **Product Name** | Fayadhowr |
| **Document Type** | Figma Design System (reusable components & styles only) |
| **Version** | 1.0 |
| **Status** | Ready for Figma library build |
| **Date** | 14 July 2026 |
| **Basis** | `01_Brand_Design_Guide.md`, `05_UI_UX_Design.md` |
| **Font** | Plus Jakarta Sans (Google Fonts — Flutter-friendly) |

---

## Scope Rules

### In scope

- Color styles / variables  
- Typography styles  
- Reusable Figma components and variants listed below  
- Layout tokens (spacing, radius, elevation/shadows)  

### Out of scope (do not create in this library pass)

- Home screen  
- Store screen  
- Services screen  
- Any other application screens or flows  

Build **only** the Design System library. Screens come after this library is published and approved.

### Design principles

- Customer-first  
- Modern, minimal, premium  
- Clean trust-first cleaning brand  
- Follow Brand Guide colors exactly — do **not** invent alternate brand colors  

---

## Recommended Figma File Structure

```text
Fayadhowr Design System
├── 00 Cover
├── 01 Foundations
│   ├── Colors
│   ├── Typography
│   ├── Spacing
│   ├── Radius
│   ├── Shadows / Elevation
│   └── Icons
├── 02 Components
│   ├── Buttons
│   ├── Text Fields
│   ├── Search Bar
│   ├── Cards
│   ├── Navigation (App Bar + Bottom Nav)
│   ├── Chips / Badges / Tags
│   ├── Dividers
│   ├── Feedback (Empty / Loading / Snackbar / Dialog / Sheet)
│   └── Media (Image Placeholder / Avatar)
└── 03 Component Gallery (all variants on one reference board)
```

Publish as a Figma **Team Library** once Foundations + Components are complete.

---

# 1. Color Styles

Create Figma **Color Styles** (and Variables if using Variable collections) using only approved Brand Guide values.

| Style name | HEX | Figma usage |
| --- | --- | --- |
| `Color/Primary` | `#0E339D` | Primary buttons, active nav, key accents |
| `Color/Secondary` | `#0694AC` | Secondary buttons, supporting accents |
| `Color/White` | `#FFFFFF` | Cards, inputs, app bar, sheets |
| `Color/Background` | `#F8FAFC` | Page canvas behind cards |
| `Color/Text/Primary` | `#1F2937` | Headings, body emphasis |
| `Color/Text/Secondary` | `#6B7280` | Captions, helpers, placeholders, inactive icons |
| `Color/Border` | `#E5E7EB` | Input borders, card strokes, dividers |
| `Color/Success` | `#22C55E` | Success chips, positive states |
| `Color/Warning` | `#F59E0B` | Warning chips, pending attention |
| `Color/Error` | `#EF4444` | Error text, error borders, destructive |

### Soft status surfaces (optional local styles)

| Style name | Suggested fill | Use with |
| --- | --- | --- |
| `Color/Success/Soft` | `#22C55E` at ~12% opacity on white | Success chip background |
| `Color/Warning/Soft` | `#F59E0B` at ~12% opacity on white | Warning chip background |
| `Color/Error/Soft` | `#EF4444` at ~12% opacity on white | Error chip background |
| `Color/Primary/Soft` | `#0E339D` at ~10% opacity on white | Selected chip / brand tint |

**Do not** create purple themes, cream themes, or alternate primary blues.

---

# 2. Typography Styles

**Family:** Plus Jakarta Sans (install via Google Fonts in Figma).

Create Text Styles:

| Style name | Size | Weight | Line height | Color default | Use |
| --- | --- | --- | --- | --- | --- |
| `Type/Heading 1` | 32 | SemiBold 600 | 40 | `Text/Primary` | Screen hero / page titles |
| `Type/Heading 2` | 24 | SemiBold 600 | 32 | `Text/Primary` | Section titles |
| `Type/Heading 3` | 18 | SemiBold 600 | 28 | `Text/Primary` | Card titles, sub-sections |
| `Type/Body` | 16 | Regular 400 | 24 | `Text/Primary` | Descriptions, form body |
| `Type/Caption` | 12 | Regular 400 | 16 | `Text/Secondary` | Metadata, helpers, legal |
| `Type/Button` | 16 | SemiBold 600 | 24 | context | Button labels |

### Supporting text styles (recommended)

| Style name | Size | Weight | Color |
| --- | --- | --- | --- |
| `Type/Body Small` | 14 | Regular 400 | `Text/Primary` |
| `Type/Subtitle` | 16 | Medium 500 | `Text/Secondary` |
| `Type/Price` | 16 | SemiBold 600 | `Text/Primary` |

---

# 3. Buttons

**Component name:** `Button`

### Shared specs

| Property | Value |
| --- | --- |
| Height | 48 |
| Min width | hug + 24 horizontal padding |
| Corner radius | 12 |
| Type | `Type/Button` |
| Auto layout | Horizontal, align center, gap 8 |
| Icon slot (optional) | 20×20 |

### Variants (`Property: Variant`)

| Variant | Fill | Text/Icon | Stroke |
| --- | --- | --- | --- |
| **Primary** | `#0E339D` | `#FFFFFF` | none |
| **Secondary** | `#0694AC` | `#FFFFFF` | none |
| **Outline** | `#FFFFFF` / transparent | `#0E339D` | 1 `#0E339D` or `#E5E7EB` (neutral outline optional sub-variant) |
| **Disabled** | `#E5E7EB` | `#6B7280` | none |
| **Loading** | Same as Primary (or Secondary) | white spinner; label hidden or retained | none |

### Additional variant props

- `State`: Default | Pressed | Hover (pressed = 8–12% darker overlay)  
- `Size`: Medium (48) | optional Small (40) later — v1 uses Medium  
- `Icon`: None | Leading | Trailing  

**Boolean:** `Show Icon`

Loading: keep width stable; replace label with 20px circular progress indicator.

---

# 4. Text Fields

**Component name:** `Text Field`

### Structure (Auto layout vertical)

1. Label (`Type/Body Small`, `#1F2937`)  
2. Input container (height 48, radius 12, padding 12–16)  
3. Helper / error caption (`Type/Caption`)  

### Variants (`Property: State`)

| State | Border | Fill | Text | Caption |
| --- | --- | --- | --- | --- |
| **Default** | 1 `#E5E7EB` | `#FFFFFF` | `#1F2937` / placeholder `#6B7280` | helper optional |
| **Focused** | 1.5–2 `#0E339D` | `#FFFFFF` | `#1F2937` | helper |
| **Error** | 1.5 `#EF4444` | `#FFFFFF` | `#1F2937` | error `#EF4444` |
| **Disabled** | 1 `#E5E7EB` | `#F8FAFC` | `#6B7280` | muted |

### Multiline variant

| Property | Value |
| --- | --- |
| `Type` | Single-line \| Multiline |
| Min height (multiline) | 120 |
| Padding | 12–16 |
| Resize | Vertical hug / fixed for forms |
| Label | **Description (Optional)** example usage for quotation notes |

Use multiline for quotation Description and similar long notes.

Optional leading/trailing icon slots (20×20).

---

# 5. Search Bar

**Component name:** `Search Bar`

| Property | Value |
| --- | --- |
| Height | 48 |
| Fill | `#FFFFFF` |
| Stroke | 1 `#E5E7EB` |
| Radius | 12 |
| Padding | 12–16 |
| Leading icon | Search, `#6B7280`, 20–24 |
| Placeholder | “Search services and products” |
| Placeholder color | `#6B7280` |
| Trailing | Clear (X) — hidden when empty (`Show Clear` boolean) |

### Variants

- `State`: Default | Focused | Disabled  
- Focused border: `#0E339D`  

Used on Home immediately below Hero (screen composition later — component only now).

---

# 6. Cards

Shared card base:

| Property | Value |
| --- | --- |
| Fill | `#FFFFFF` |
| Stroke | 1 `#E5E7EB` |
| Radius | 12–16 (use **16** for media-forward cards) |
| Elevation | Level 0 or Level 1 |
| Clip content | On for image corners |

## 6.1 Service Card — `Card/Service`

| Layer | Spec |
| --- | --- |
| Image | 16:10 or 3:2, top, Image Placeholder |
| Favorite | Heart icon **top-right** over image (Favorite component instance) |
| Title | `Type/Heading 3` |
| Subtitle | `Type/Caption` or Subtitle |
| Pricing cue | Tag: Fixed price and/or Quotation |
| Width | Fixed for grid/carousel (e.g. 160–180) or fill |

**Variants:** `Favorite` = On | Off

## 6.2 Product Card — `Card/Product`

| Layer | Spec |
| --- | --- |
| Image | Square or 1:1 preferred |
| Favorite | Heart **top-right** |
| Title | `Type/Heading 3` |
| **Price** | `Type/Price` — **always visible** (`#1F2937`) |
| Stock cue | optional Caption |
| Width | Same system as Service Card |

**Variants:** `Favorite` = On | Off; `Stock` = In stock | Low | Out

## 6.3 Review Card — `Card/Review`

| Layer | Spec |
| --- | --- |
| Rating | Stars (1–5) + optional numeric |
| Title | optional SemiBold |
| Body | `Type/Body` or Body Small, 2–3 line clamp |
| Meta | Caption (date / name initial) |
| Padding | 16 |
| Radius | 12 |

## 6.4 Gallery Card — `Card/Gallery`

| Layer | Spec |
| --- | --- |
| Image | Bright cleaning photo placeholder |
| Optional label | Before / After caption chip |
| Radius | 16 |
| Aspect | 4:3 or 1:1 |

Before & After pair: two Gallery Cards in a horizontal auto-layout frame (`Gallery/BeforeAfterPair`) — pair is a composition component, not a screen.

---

# 7. Icon Style

**Component set:** `Icon/*`

| Attribute | Spec |
| --- | --- |
| Grid | 24×24 (default), 20×20 dense |
| Style | Outline, rounded joins |
| Stroke | 1.5–2 px |
| Color default | `#6B7280` |
| Color active | `#0E339D` |
| Color on primary | `#FFFFFF` |
| Color error | `#EF4444` |

### Required icon instances (minimum set)

Home, Services, Store, Cart, Account, Search, Heart (outline/filled), Close, Back, Check, Warning, Error, Bell, Plus, Minus, Filter, Calendar, Location, Upload, Trash, Chevron

Do not use cartoon sticker icons. Keep one consistent icon family.

**Favorite heart component:** `Icon/Favorite` with variants `Off` (outline `#6B7280` or white on image) and `On` (filled `#EF4444` or brand preference — recommend filled `#EF4444` for clarity on photos, or `#0E339D` for brand consistency; **pick `#0E339D` filled for brand**, outline white with subtle scrim on images).

Recommended: **Off** = white outline + light scrim circle; **On** = `#0E339D` filled.

---

# 8. Bottom Navigation

**Component name:** `Navigation/Bottom Bar`

| Property | Value |
| --- | --- |
| Height | 64 (+ safe area spacer optional) |
| Fill | `#FFFFFF` |
| Top stroke | 1 `#E5E7EB` |
| Elevation | Level 1 optional |
| Items | 5 slots: Home, Services, Store, Cart, Account |
| Item layout | Vertical: icon 24 + label Caption/11–12 SemiBold |
| Inactive | Icon + label `#6B7280` |
| Active | Icon + label `#0E339D` |
| Badge | Optional on Cart / Account |

### Variants

- `Active Tab`: Home | Services | Store | Cart | Account  
- `Cart Badge`: none | number  
- `Notification Badge` on Account: none | number  

Always show labels (accessibility).

---

# 9. App Bar

**Component name:** `Navigation/App Bar`

| Property | Value |
| --- | --- |
| Height | 56 |
| Fill | `#FFFFFF` |
| Bottom stroke | optional 1 `#E5E7EB` |
| Title | `Type/Heading 3` or 18 SemiBold, `#1F2937`, centered or start |
| Leading | Back icon / menu / none |
| Trailing | 1–2 icon buttons (cart, notifications) |

### Variants

- `Type`: Default | With Search | With Tabs (tabs later)  
- `Show Back`: true/false  
- `Show Trailing`: true/false  

Avoid large colored app bars that compete with photography.

---

# 10. Chips

**Component name:** `Chip`

| Property | Value |
| --- | --- |
| Height | 32 |
| Padding | 8–12 horizontal |
| Radius | 8 (or 999 for pill — prefer **8** for premium-minimal consistency) |
| Type | `Type/Caption` or 12 Medium |

### Variants

| Variant | Style |
| --- | --- |
| Default | Fill white, stroke `#E5E7EB`, text `#1F2937` |
| Selected | Fill Primary Soft, text `#0E339D`, stroke Primary Soft |
| Success | Success soft + `#22C55E` text |
| Warning | Warning soft + `#F59E0B` text |
| Error | Error soft + `#EF4444` text |
| Neutral | Border only, `#6B7280` text |

Use for filters and status where compact.

---

# 11. Badges

**Component name:** `Badge`

| Property | Value |
| --- | --- |
| Shape | Circle or capsule |
| Min size | 18 |
| Fill | `#0E339D` (count) or `#EF4444` (alert) |
| Text | 10–11 SemiBold white |
| Placement | Absolute top-right on icon/nav item |

### Variants

- `Type`: Count | Dot  
- `Color`: Brand | Alert  

---

# 12. Tags

**Component name:** `Tag`

Smaller than chips; for pricing model cues and attributes.

| Property | Value |
| --- | --- |
| Height | 24 |
| Radius | 8 |
| Padding | 6–8 |
| Text | 11 Medium |

### Variants

| Tag | Style |
| --- | --- |
| Quotation | Primary soft / `#0E339D` text |
| Fixed Price | Secondary soft / `#0694AC` text |
| Featured | Neutral border |
| Out of Stock | Error soft |

---

# 13. Dividers

**Component name:** `Divider`

| Variant | Spec |
| --- | --- |
| Horizontal Full | 1px `#E5E7EB`, width fill |
| Horizontal Inset | 1px `#E5E7EB`, left/right 16 |
| Vertical | 1px `#E5E7EB`, height fill |

---

# 14. Shadows

Create **Effect Styles**:

| Style name | Spec (approx.) | Use |
| --- | --- | --- |
| `Shadow/None` | — | Flat bordered surfaces |
| `Shadow/Level 1` | Y 2, Blur 8, Spread 0, `#1F2937` @ 6% | Cards on background |
| `Shadow/Level 2` | Y 4, Blur 16, Spread 0, `#1F2937` @ 8% | Dropdowns, floating panels |
| `Shadow/Level 3` | Y 8, Blur 24, Spread 0, `#1F2937` @ 12% | Dialogs, key overlays |

No neon/colored glows. Soft neutral only.

---

# 15. Border Radius

Create Variables or document as styles:

| Token | Value | Apply to |
| --- | --- | --- |
| `Radius/sm` | 8 | Inputs dense, small chips |
| `Radius/md` | 12 | Buttons, text fields, default cards |
| `Radius/lg` | 16 | Media cards, sheets top corners |
| `Radius/xl` | 24 | Rare marketing containers |
| `Radius/full` | 999 | Avatars, circular icon buttons |

Default interactive control radius: **12**.

---

# 16. Spacing System (8px Grid)

Document as Figma Variables (`Spacing/*`) and show a spacing ruler frame.

| Token | Value |
| --- | --- |
| `space/1` | 4 |
| `space/2` | 8 |
| `space/3` | 16 |
| `space/4` | 24 |
| `space/5` | 32 |
| `space/6` | 40 |
| `space/7` | 48 |

### Layout defaults

- Screen horizontal inset: **16**  
- Component internal padding: **12–16**  
- Section gaps: **24–32**  

All auto-layout gaps must snap to this scale.

---

# 17. Elevation Levels

Map elevation to shadow styles + surface:

| Level | Surface | Shadow | Typical components |
| --- | --- | --- | --- |
| 0 | White + border | None | Inputs, flat cards |
| 1 | White | Level 1 | Product/Service cards |
| 2 | White | Level 2 | Menus, search suggestions |
| 3 | White | Level 3 | Dialogs, key sheets |

Bottom navigation and app bar: Level 0 + border preferred; Level 1 optional.

---

# 18. Empty State Components

**Component name:** `Feedback/Empty State`

| Slot | Spec |
| --- | --- |
| Illustration / icon | Simple outline icon 64–80, `#6B7280` (no cartoons) |
| Title | `Type/Heading 3` |
| Description | `Type/Body` / secondary |
| Primary CTA | Button/Primary |
| Secondary CTA | Button/Outline (optional) |

### Variants (`Property: Context`)

- Empty Cart  
- Empty Favorites  
- Empty Notifications  
- Empty Orders  
- Empty Bookings  
- Empty Search Results  
- Generic  

---

# 19. Loading Components

**Component set:** `Feedback/Loading`

| Component | Spec |
| --- | --- |
| `Spinner` | 20 / 24 / 32; color `#0E339D` |
| `Button Spinner` | 20 white (for primary buttons) |
| `Skeleton/Line` | radius 8, fill `#E5E7EB`, subtle shimmer optional |
| `Skeleton/Card` | image block + 2–3 lines |
| `Skeleton/List Item` | avatar/circle + lines |
| `Overlay Progress` | semi-transparent scrim + spinner (use sparingly) |

---

# 20. Snackbar

**Component name:** `Feedback/Snackbar`

| Property | Value |
| --- | --- |
| Min height | 48 |
| Radius | 12 |
| Fill | `#1F2937` (default) or White + border |
| Text | 14 Regular white (on dark) |
| Padding | 12–16 |
| Action | Text button SemiBold (optional) |
| Icon | optional leading status icon |

### Variants

- Default  
- Success (leading success icon)  
- Error  
- With Action (e.g., View Cart)  

Position: bottom above nav / safe area — document in component description only.

---

# 21. Dialog

**Component name:** `Feedback/Dialog`

| Property | Value |
| --- | --- |
| Width | 280–320 (mobile) |
| Fill | `#FFFFFF` |
| Radius | 16 |
| Shadow | Level 3 |
| Padding | 24 |
| Title | `Type/Heading 3` |
| Body | `Type/Body` secondary allowed |
| Actions | Right-aligned or stacked full-width Buttons |

### Variants

- Info  
- Confirm  
- Destructive (primary action uses Error fill or Outline Error for confirm delete)  
- Single action  

Include scrim component `Overlay/Scrim` at `#1F2937` @ 40–50% opacity.

---

# 22. Bottom Sheet

**Component name:** `Feedback/Bottom Sheet`

| Property | Value |
| --- | --- |
| Fill | `#FFFFFF` |
| Top radius | 16 (or 24) |
| Handle | 32×4, `#E5E7EB`, centered |
| Shadow | Level 2–3 |
| Padding | 16–24 |
| Title | optional Heading 3 |
| Content slot | swap instance |
| Sticky footer | optional Button stack |

### Variants

- Default  
- With Primary Action  
- With Grab Handle only  

---

# 23. Image Placeholder

**Component name:** `Media/Image Placeholder`

| Property | Value |
| --- | --- |
| Fill | `#E5E7EB` or `#F8FAFC` |
| Icon | Image outline, `#6B7280` |
| Radius | inherit from parent (12–16) |
| Aspect ratio props | 1:1, 4:3, 16:10, 16:9 |

### Variants

- Empty  
- With Photo (use high-quality cleaning stock **only as placeholder examples**; real brand photography replaces later)  
- Error / broken  

---

# 24. Avatar

**Component name:** `Media/Avatar`

| Property | Value |
| --- | --- |
| Sizes | 32 / 40 / 48 / 64 |
| Shape | Circle (`Radius/full`) |
| Fill fallback | Primary soft + initials `Type/Caption` SemiBold `#0E339D` |
| Image | circular crop |
| Stroke | optional 1 `#E5E7EB` |

### Variants

- Image  
- Initials  
- Icon  

---

## Component Checklist (Build Order in Figma)

1. Variables: Color, Spacing, Radius  
2. Color Styles + Text Styles + Effect Styles  
3. Icon set  
4. Button  
5. Text Field (+ Multiline)  
6. Search Bar  
7. Chip / Badge / Tag / Divider  
8. Cards (Service, Product, Review, Gallery)  
9. App Bar + Bottom Navigation  
10. Empty / Loading / Snackbar / Dialog / Bottom Sheet  
11. Image Placeholder + Avatar  
12. Publish Library  

---

## Acceptance Criteria (Design System Complete)

| # | Criterion |
| --- | --- |
| 1 | All Brand Guide colors exist as styles/variables — no alternate brand palette |
| 2 | Plus Jakarta Sans text styles: H1, H2, H3, Body, Caption, Button |
| 3 | Buttons cover Primary, Secondary, Outline, Disabled, Loading |
| 4 | Text fields cover Default, Focused, Error, Disabled, Multiline |
| 5 | Search Bar component exists with clear/focus states |
| 6 | Service & Product cards include Favorite heart top-right; product shows price |
| 7 | Bottom Nav + App Bar match Brand inactive/active colors |
| 8 | Spacing is 8px-grid only; radius & elevation tokens documented |
| 9 | Feedback components (empty, loading, snackbar, dialog, sheet) exist |
| 10 | **No application screens** (Home/Store/Services/etc.) are included in this deliverable |
| 11 | Library is organized and ready to publish for Phase 2 screen design |

---

## Note on Figma File Creation

This document is the **authoritative Figma Design System specification** for Fayadhowr. Implement it as a Figma Team Library using the structure, styles, and components above.

No Home, Store, Services, or other app screens should be designed until this Design System library is built, reviewed, and approved.

---

## Document Control

| Item | Value |
| --- | --- |
| **This document** | `docs/08_Figma_Design_System.md` |
| **Aligns with** | Brand Guide + UI/UX Design Spec |
| **Next step** | Build & publish Figma library → then screen design |

### Approval

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product Owner |  |  |  |
| UI / Design System Lead |  |  |  |
| Brand Owner |  |  |  |

---

*End of Document — Fayadhowr Figma Design System Specification v1.0*
