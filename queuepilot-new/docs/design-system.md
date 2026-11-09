# QueuePilot — Design system

**Status:** Design stage · derived from the 10 approved reference screens.
**Companion file:** `resources/design-system/tokens.css` (the machine-readable tokens).

---

## 0. How this was derived

The ten reference images (`image-9.png` … `image-18.png`) were measured rather than
eyeballed. Every value below marked **MEASURED** comes from sampling those files;
the rest are deliberate, documented extrapolations that keep the system coherent.

| Measurement | Result across all ten screens |
|---|---|
| Canvas size | `1536 × 1024` (3:2), identical in all ten |
| Left rail | occupies `x = 0 … 383` → **384px** (exactly 25% of 1536) |
| Top band inside content | ends at `y ≈ 92 … 101` → **~92px** |
| Dominant surface | `#fefefe` — 39.7% of all pixels |
| Dominant canvas tint | `#f2f5f9` / `#f3f5f9` / `#f6f7f9` |
| Primary accent | hue 218°, canonical **`#0555da`** (cluster `#0454d9`–`#0556da`) |
| Success accent | hue 150°, canonical **`#218e5a`** (cluster `#178b59`–`#229561`) |
| Muted text / slate | **`#5c6288`** |
| Light blue tint | `#ddf1fb`, `#a2bcef` |
| Text | near-black navy-charcoal, luminance < 60 |

Two structural readings matter for every screen that follows:

1. **The rail is white, the page behind it is grey.** Cards are white on a
   `#f2f5f9` canvas. Depth comes from a hairline border, not from shadow.
2. **Nothing is a vivid startup blue.** The measured blue is desaturated enough to
   sit next to clinical green without vibrating, which is exactly the "calm and
   professional" brief.

---

## 1. Colour tokens

### 1.1 Structure

Four hue families — primary, success, warning, danger — each with a 50→900 ramp,
plus a neutral scale. Full values live in `tokens.css`; the working rules are here.

### 1.2 The palette

| Role | Token | Hex | Used for |
|---|---|---|---|
| Primary | `--qp-primary-600` | `#0555da` | Primary buttons, links, active nav, focus rings |
| Primary hover | `--qp-primary-700` | `#0446b4` | Hover only |
| Primary tint | `--qp-primary-50` | `#eef4fe` | Selected rows, "called" status chip |
| Success | `--qp-success-600` | `#218e5a` | Completed, confirmations, active session |
| Success tint | `--qp-success-50` | `#eefaf3` | Completed status chip, success banner |
| Warning | `--qp-warning-600` | `#c96a10` | Waiting, on-hold, stale-data flag |
| Danger | `--qp-danger-600` | `#d33232` | Destructive actions only |
| Canvas | `--qp-canvas` | `#f2f5f9` | Page background |
| Surface | `--qp-surface` | `#fefefe` | Cards, panels, sidebar, modals |
| Border | `--qp-border` | `#e2e5ee` | Card and control borders |
| Border strong | `--qp-border-strong` | `#c3c7d8` | Input borders, table rules |
| Ink 900 | `--qp-ink-900` | `#14182b` | Headings, primary text |
| Ink 500 | `--qp-ink-500` | `#5c6288` | Secondary text, muted labels |
| Ink 300 | `--qp-ink-300` | `#9aa0bd` | Placeholders, disabled |

### 1.3 Rules

- **One accent per screen region.** A screen may use primary + one status colour.
  A screen using all four hues is a screen that has lost its hierarchy.
- **Colour is never the only carrier of meaning.** Every status chip has text.
  This is a clinic: colour-blind staff must not be blocked by a red/green badge.
- **Danger is for destructive actions only** — cancel, delete, suspend. Never for
  "attention" (that is warning) and never decorative.
- **No gradients on functional surfaces.** The reference screens are flat.

---

## 2. Typography

### 2.1 Family

System font stack, one family, no webfont:

```
--qp-font-sans: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
                Roboto, "Helvetica Neue", Arial, sans-serif;
```

Rationale: nothing to download, so the app is fast on clinic broadband and works
fully offline from the service worker. Inter is used when present.

### 2.2 Scale

| Token | Size | Use |
|---|---|---|
| `--qp-text-xs` | 11px | Meta, timestamps, badges, table headers |
| `--qp-text-sm` | 12px | Table cells, secondary labels |
| `--qp-text-base` | **13px** | **Body default** |
| `--qp-text-md` | 15px | Card titles |
| `--qp-text-lg` | 18px | Section titles |
| `--qp-text-xl` | 22px | Page titles |
| `--qp-text-2xl` | 30px | Stat values |
| `--qp-text-3xl` | 44px | Waiting-room TV tokens only |

**Body default is 13px, not 16px.** QueuePilot is a dense operational tool — a
receptionist needs to see a whole waiting list without scrolling. 13px with
1.5 line-height is the measured density of the reference screens and remains
readable at arm's length.

### 2.3 Rules

- **No page title above 22px.** The brief explicitly forbids huge headings.
- Weights used: 400 (body), 500 (labels, table headers), 600 (card titles,
  buttons), 700 (stat values, tokens). Nothing else.
- **One font family.** No serif accents, no monospace outside the four
  genuinely-monospaced cases: token numbers, times, patient codes, invoice IDs.
- Line height 1.5 for body, 1.25 for headings and stat values.

---

## 3. Spacing

4px base, 16 steps (4px → 64px). See `tokens.css` §8.

| Context | Value |
|---|---|
| Inside a card | `24px` (`--qp-space-6`) |
| Between stacked cards | `20px` (`--qp-space-5`) |
| Content area padding | `32px` (`--qp-space-4` × 2) |
| Between form fields | `16px` (`--qp-space-4`) |
| Label → input | `6px` |
| Icon → text | `8px` (`--qp-space-2`) |
| Table cell padding | `12px 16px` |

Everything is a multiple of 4. Nothing is `13px` or `18px`.

---

## 4. Border radius

| Token | Value | Use |
|---|---|---|
| `--qp-radius-sm` | 4px | Badges, tags, status chips |
| `--qp-radius-md` | 6px | Inputs, buttons, dropdowns |
| `--qp-radius-lg` | 8px | Cards, panels, tables |
| `--qp-radius-xl` | 12px | Modals |
| `--qp-radius-full` | 9999px | Avatars, pills, status dots |

Small and consistent. Not pill-shaped buttons everywhere, not sharp rectangles.

---

## 5. Shadows

Barely there. **Depth comes from borders, not shadow** — this is what makes the
reference screens feel calm rather than stacked.

| Token | Value | Use |
|---|---|---|
| `--qp-shadow-xs` | `0 1px 2px rgba(20,24,43,.04)` | Cards at rest |
| `--qp-shadow-sm` | `0 1px 3px rgba(20,24,43,.06)` | Raised cards |
| `--qp-shadow-md` | `0 4px 12px rgba(20,24,43,.07)` | Dropdowns, popovers |
| `--qp-shadow-lg` | `0 12px 32px rgba(20,24,43,.12)` | Modals |
| `--qp-shadow-focus` | `0 0 0 3px rgba(5,85,218,.16)` | Focus ring |

Every focusable control gets `--qp-shadow-focus` plus a `2px` primary border on
`:focus-visible`. Focus is never removed — the receptionist workflow is
keyboard-driven (type name, Enter, type phone, Enter).

---

## 6. Buttons

### 6.1 Variants

| Variant | Background | Border | Text | Use |
|---|---|---|---|---|
| **Primary** | `primary-600` | none | white | One per screen. The main action. |
| **Secondary** | `surface` | `border-strong` | `ink-700` | Alternative actions |
| **Ghost** | transparent | none | `ink-600` | Tertiary, toolbar, table rows |
| **Danger** | `danger-600` | none | white | Destructive, always confirmed |
| **Danger subtle** | `danger-50` | `danger-200` | `danger-700` | Destructive inside a menu |

### 6.2 Sizes

| Size | Height | Padding | Use |
|---|---|---|---|
| `sm` | 32px | `0 12px` | Table rows, dense toolbars |
| `md` | 40px | `0 16px` | **Default** — forms, cards |
| `lg` | 48px | `0 20px` | Primary actions, doctor console |
| `xl` | 56px | `0 28px` | Kiosk, TV-touch |

### 6.3 Rules

- **One primary button per screen region.** The brief is explicit. On the
  reception board that is *Issue token*; on the doctor console it is *Call next*.
- Icon + label, icon at `--qp-icon` (18px), gap 8px.
- Loading state swaps the label for a spinner and **keeps the button width** —
  a resizing button makes people click twice.
- Disabled state: `opacity .5`, `cursor: not-allowed`. Never `display: none` —
  a button that vanishes teaches the user the wrong layout.
- Min touch target 44×44px on any control a doctor uses on a tablet.

---

## 7. Inputs

- Height 40px default (`--qp-control-h`). Radius 6px. Border `border-strong`.
- Label above, 12px/500/`ink-700`, 6px gap. Never a floating label — they break
  with autofill and on slow devices.
- Placeholder `ink-300`, never a substitute for a label.
- **Validation sits beside the field**, 11px `danger-700`, and the field border
  turns `danger-500`. No validation-summary dumps at the top of forms.
- Focus: primary border + focus ring.
- Required fields marked with a `*` and `aria-required`, never colour alone.
- Search inputs get a leading icon and a clear (×) button when non-empty.
- Selects use the native control styled to match — native gives free keyboard
  support and the correct mobile wheel. Custom dropdowns only where options need
  rich content (patient search results, doctor switcher with waiting counts).

---

## 8. Dropdowns / menus

- Surface white, `shadow-md`, `radius-md`, 4px padding, 1px border.
- Item height **36px**, hover `canvas`, active/selected `primary-50` + `primary-700` text.
- Separator `border` 1px, 4px vertical margin.
- Destructive items at the bottom, above a separator, `danger-subtle` styling.
- **The overflow menu (`⋯`) is where uncommon queue actions live.** The brief's
  progressive-disclosure rule: transfer, hold and cancel are never inline when
  the patient is merely waiting.
- Closes on Escape, on outside click, and on scroll-out of the trigger.

---

## 9. Tables

The reference screens keep tables simple. Rules:

- Header row 11px/600/`ink-500`, uppercase, letter-spacing 0.04em, no background
  fill — separated from the body by a 1px `border-strong`.
- Row height 44px minimum. Cell padding `12px 16px`.
- Row separator `border` 1px. Zebra striping **off** — it adds noise at 13px.
- Row hover `canvas-alt`.
- Numeric columns right-aligned and tabular-nums. Token, time and amount columns
  use `--qp-font-mono`.
- Row action buttons appear on hover on desktop, always visible on touch.
- **No sticky multi-level headers, no nested tables, no horizontal scroll on
  desktop.** On mobile a table becomes a stacked card list, never a scroller.

---

## 10. Cards

- Surface white, 1px `border`, `radius-lg` (8px), `shadow-xs`.
- Padding 24px (`--qp-space-6`).
- Optional header: `section-head` with a 15px/600 title and an 11px `ink-500`
  subtitle, divided from the body by a 1px border.
- **No card may contain more than one primary action.**
- **Stat cards** are the exception to "avoid excessive cards": the reference
  screens use a stat strip. Each stat card is: label 11px `ink-500`, value
  30px/700 `ink-900`, optional delta or hint 11px `ink-500`, and a 32px
  tinted icon square at `--qp-radius-md`.

---

## 11. Modals

- Backdrop `rgba(20,24,43,.45)`, no blur (blur is expensive on clinic hardware).
- Panel: white, `radius-xl` (12px), `shadow-lg`, max-width 560px, full-width on
  mobile with a bottom-sheet slide-up.
- Header: 15px/600 title + close ×. Body: 24px padding, 13px text.
- Footer: right-aligned actions, **Cancel then Confirm**, primary on the right.
- **Escape closes without submitting.** Focus is trapped while open and returned
  to the trigger on close.
- **No native `confirm()` / `prompt()` / `alert()` anywhere.** They cannot be
  styled, are silently blocked in embedded contexts, and break keyboard flow.
  Every confirmation and every free-text prompt (hold reason, cancel reason,
  transfer destination) is a real dialog.

---

## 12. Navigation

### 12.1 Desktop (≥1024px) — MEASURED

- **Left rail, 384px, white, full height**, fixed. 25% of the 1536 reference
  canvas. Contains: clinic identity/branch switcher at top, primary nav groups,
  and the user menu pinned at the bottom.
- Nav item: 40px tall, 10px radius, 16px horizontal padding, 18px icon + 13px
  label. Active state: `primary-50` background, `primary-700` text and icon —
  **not** a left border bar, which is the generic-dashboard tell.
- Group labels: 11px uppercase `ink-400`, 24px top margin.
- **Content area** to the right, `canvas` background, 32px padding.
- **Top band ~92px** inside the content area holds the page title, breadcrumb or
  back link, and the page-level primary action — matching the measured band.

### 12.2 Tablet (768–1023px)

Rail collapses to a **72px icon rail** (`--qp-sidebar-w-mini`) with tooltips.
Content takes the rest.

### 12.3 Mobile (<768px)

- No rail. **56px top bar** with a menu button, page title and the one context
  action.
- **60px bottom tab bar** with the three or four items that matter to the role —
  for a receptionist: Overview, Queue, Appointments, Patients.
- Full-screen nav drawer from the top bar for everything else.

### 12.4 Role-shaped navigation

| Role | Navigation |
|---|---|
| Receptionist | Overview · Queue · Appointments · Patients *(nothing else)* |
| Doctor | Today's queue · My schedule · My patients · My profile |
| Clinic owner | Overview · Appointments · Patients · Doctors & rooms · Reports · Team · Clinic settings · Subscription |
| Platform admin | Separate console: Overview · Clinics · Organisations · Plans · Features · Payments · Support · Health |

---

## 13. Status indicators

### 13.1 The nine queue states

Nine states, five colour roles. States sharing a role are separated by their
label, not by inventing nine hues.

| Internal state | User-facing label | Role | Chip |
|---|---|---|---|
| `waiting` | Waiting | warning | amber tint |
| `called` | Called — please proceed | primary | blue tint |
| `in_consultation` | With doctor | active | green tint |
| `completed` | Completed | done | green tint |
| `skipped` | Skipped | blocked | grey tint |
| `no_show` | Not present | blocked | grey tint |
| `on_hold` | On hold | warning | amber tint |
| `transferred` | Moved to another queue | blocked | grey tint |
| `cancelled` | Cancelled | danger | red tint |

Chip spec: 4px radius, `11px/500`, `4px 8px` padding, tint background + 700-weight
text. Always with a text label, never colour-only.

### 13.2 Other badges

- **Priority** — `danger` tint, with a small up-arrow icon. Always accompanied by
  the recorded reason on hover/click.
- **Session state** — running (green), not started (amber), paused/closed (grey).
- **Live / stale** — a small dot + text. Green "Live", amber "Stale — last
  updated 2 min ago". Never claiming stale data is current.
- **Offline** — grey, explicit: "Offline — showing reserved numbers".

---

## 14. Notifications / toasts

- Bottom-right on desktop, top on mobile. Max 3 stacked.
- 12px radius, white surface, `shadow-md`, 16px padding, 40px min-height.
- 4px left accent bar by type: success green, error red, warning amber, info primary.
- Auto-dismiss: success 4s, info 5s, warning 7s, **error never** (user dismisses).
- **Every important action produces one.** The brief lists them explicitly:
  patient registered, token issued, patient called, consultation started, visit
  completed, appointment confirmed, changes saved, notification failed.

### Empty / loading / error states

- **Empty:** centred 18px icon in `ink-300`, 15px/600 title, 12px `ink-500`
  explanation, and — crucially — **the action that fills it**.
- **Loading:** skeleton blocks matching the real layout, never a centred spinner
  for content that has a known shape.
- **Error:** inline, near what failed, with a Retry. Never a bare "Something went
  wrong".
- **Permission denied:** a real page explaining who *can* do it, not a 403 dump.

---

## 15. Icons

- **Lucide**, as specified. Stroke width 1.75 (not 2 — slightly finer reads
  calmer at 18px).
- Sizes: 16px inline, **18px default**, 22px in nav and stat cards.
- Icons never carry meaning alone in a primary action; they are always paired
  with text except in dense toolbars, where they carry `title` + `aria-label`.

---

## 16. Breakpoints

| Token | Value | Layout |
|---|---|---|
| `--qp-bp-sm` | 576px | Phone landscape / large phone. Stat strip goes 2-up. |
| `--qp-bp-md` | 768px | Tablet. Icon rail replaces full rail. |
| `--qp-bp-lg` | 1024px | Desktop. Full 384px rail. |
| `--qp-bp-xl` | 1280px | Wide. Content capped at 1440px, extra space to gutters. |

Mobile-first: base styles are phone, `min-width` queries add up.

### Screen-specific

- **Waiting-room TV** (`/display`): never uses the app shell. Full-bleed canvas,
  token numbers at 44px+, designed to be read from 5 metres.
- **Kiosk**: one form, centred, 56px controls, no navigation at all.
- **Patient tracking**: mobile-first single column, no rail, no bottom bar.

---

## 17. Accessibility

- Contrast: body text ≥4.5:1, large text and UI borders ≥3:1. `#5c6288` on
  `#fefefe` is 5.4:1 — passes. `#9aa0bd` is placeholder-only, never text.
- Focus visible on every interactive element, always.
- Every status has text. Every chart has a table alternative.
- Motion respects `prefers-reduced-motion` — transitions drop to 0ms.
- Touch targets ≥44px for anything a doctor taps.

---

## 18. What this system deliberately refuses

From the brief, and enforced in review:

- No generic hospital-software aesthetics.
- No excessive cards — content may sit on the canvas without a card.
- No unnecessary charts — a number is usually better than a sparkline.
- No huge headings — 22px maximum page title.
- No overcomplicated tables — no nested headers, no frozen panes.
- No excessive animations — 240ms maximum, no springs.
- No decorative gradients.
- No more than one primary button per region.
- No configuration surfaced on everyday screens.
