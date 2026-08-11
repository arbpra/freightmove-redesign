# UI/UX Plan and Wireframes

## 1. Design Philosophy

The redesign should feel premium, calm, and operationally credible. The experience should be modern enough for a SaaS platform while remaining practical for freight operations.

### Core Design Principles
- Minimal but confident visual language
- Clear hierarchy and reduced cognitive load
- Spacious layout with rounded surfaces and soft shadows
- Strong contrast for actionable elements and important states
- Fast, responsive interactions with subtle motion

## 2. Visual System

> The palette below supersedes the blue/green scheme originally drafted here
> (`#0057FF` / `#00B894` / `#F4B400`). The approved homepage template is navy
> and red, and that is what is built. Tokens live in `web/src/styles.scss`.

### Colour palette

| Token | Value | Use |
| --- | --- | --- |
| `--fm-navy` | `#0a1c38` | Dark bands, footer, stats strip |
| `--fm-navy-deep` | `#050f1f` | Gradient ends, deepest surfaces |
| `--fm-navy-soft` | `#14294a` | Gradient starts, plates |
| `--fm-red` | `#e11d26` | Primary action, accent phrases |
| `--fm-red-bright` | `#f5323b` | Hover, on-dark accents |
| `--fm-red-dark` | `#b8151d` | Text on light red tints (AA) |
| `--fm-blue-bright` | `#2f6fd0` | Shipper side of the hero only |
| `--fm-ink` | `#0b1220` | Body text |
| `--fm-ink-soft` | `#55637a` | Secondary text |
| `--fm-ink-faint` | `#64748b` | Tertiary text — lightest that clears AA on white |
| `--fm-paper` | `#ffffff` | Cards |
| `--fm-paper-alt` | `#f7f9fc` | Alternating section bands |

Brand tokens are fixed in both colour schemes. Only the app-chrome tokens
(`--fm-bg`, `--fm-fg`, …) respond to `prefers-color-scheme`, so the dashboard
keeps its dark mode while marketing pages stay on-brand.

### Typography

Two families, never mixed within one element:

- **Manrope** — display type (`h1`–`h4`, `.fm-display`). Geometric and open;
  reads as considered at large sizes.
- **Inter** — body and UI. Tighter, better at 13–16px.

Sizes are fluid tokens (`--fm-display-lg` … `--fm-text-2xs`) interpolating from
360px to 1440px. Display sizes carry negative tracking; body sizes stay neutral.

### Motion

- `--fm-ease-expo` — decelerate, used for almost everything
- `--fm-ease-spring` — subtle overshoot, hover affordances only
- `--fm-dur-fast` 200ms / `--fm-dur` 320ms / `--fm-dur-slow` 720ms
- Everything is disabled under `prefers-reduced-motion`

Scroll reveals run through the `fmReveal` directive, which shares **one**
IntersectionObserver across every revealed node and only hides elements once it
has confirmed observer support — so content can never be stranded invisible.

### UI patterns
- Rounded cards (14px controls, 20px cards, 28px hero panels) with layered,
  low-opacity shadows that read as ambient light rather than a drop shadow
- Glass surfaces for the sticky header and the quote-search card
- Floating-label form fields (`.fm-field`) with focus glow
- Clear badges for status, verification, and match quality

### Iconography

Two systems, deliberately:

- **Inline SVG** (`shared/icons.ts`) — UI glyphs, stroked, inherit `currentColor`
- **3D badge artwork** (`web/public/*.webp`) — the How It Works steps, Why
  FreightMove benefits, and Industries. These are transparent PNG-style badges
  carrying their own gold rim, so containers must add **no** plate of their own;
  depth comes from a warm halo plus a `drop-shadow` filter, which traces the
  badge silhouette instead of boxing it.

## 3. Key Screen Concepts

### Home Page
- Hero section with clear value proposition and primary CTA
- Search freight card for immediate action
- Key stats strip
- How It Works section
- Why Choose Us section
- Industries and popular routes cards
- Latest jobs carousel or cards
- Testimonials and partners
- Final CTA and footer

### Shipper Job Creation Experience
- Multi-step form with progress indicator
- Clear route and cargo fields
- Upload area for documents and photos
- Smart defaults and saved drafts
- Quote comparison screen with carrier cards and score indicators

### Carrier Dashboard
- Personalized load board with filters
- Match score and route fit visible immediately
- Quote submission with prefilled templates
- Fleet and vehicle management in a dedicated module

### Admin Console
- Analytics overview cards
- Queue-based management panels for users, jobs, quotes, documents, support, and payments

## 4. Wireframe Summary

### 1. Public Landing Page

**The quote form was replaced by a live load board.** A guest cannot post a
load, so "Get quotes in minutes" sent them to registration — and dropped the
origin, destination and weight they had just typed, because registration only
reads the role. Asking for details and discarding them is worse than not asking.

Real freight makes the better argument in that slot: a carrier sees loads on
their lane, a shipper sees the marketplace is busy, and both are statements of
fact rather than a promise. The band renders nothing at all when the board is
empty or the API is unreachable — an empty marketplace advertised on the home
page is worse than no section.

`quote-search` is kept in `home/sections` for the signed-in post-a-load flow,
where the same fields do work.
- Sticky top navigation
- Hero with headline, subheading, CTA, and right-side illustration/mock dashboard
- Stats row
- Feature cards
- Section for industries and jobs
- Footer

### 2. Dashboard Shell ✅ built
- Left navigation rail on desktop (from 62rem), sticky under the top bar
- Mobile gets **both**: a bottom tab bar for the two or three main
  destinations, and the same rail as a drawer behind the hamburger
- Header with the wordmark, current page, role chip, avatar and sign out
- Main content in spacious cards

Two deliberate departures from the wireframe:

**The notification bell is in**, with an unread badge that polls a count-only
endpoint once a minute and pauses while the tab is hidden. The panel loads on
open rather than on page load — most sessions never open it, and the badge
already answers the only question most people have. Unread rows carry a red
spine as well as a tint, so the state does not rest on a faint tint alone.

**Still no search box.** It is in the sketch and has no endpoint behind it.
Chrome that looks functional and does nothing costs more trust than the empty
space costs polish.

**One palette.** The shell previously used a separate blue set (`--fm-accent`,
`--fm-surface`) with a half-built dark mode, which is precisely why the
dashboards read as a different product from the marketing site. That set is
deleted and the shell is on the brand tokens. The app is now light-only, as the
brand system is — a real dark mode needs a designed dark palette for the brand,
not a neutral one bolted underneath it.

The active rail item carries a red spine as well as a tint, so the state does
not rest on colour alone.

### 3. Job Detail / Quote Comparison
- Left column: job summary and requirements
- Right column: quote cards with comparison metrics
- Sticky action footer for accepting a quote

### 4. Messaging Center ✅ built
- Conversation list beside the active thread on desktop; on mobile the two
  swap, list until a thread is chosen, back arrow to return
- Read receipts: "Read" appears under a sent message once the other side opens
  the thread
- Own messages sit right in navy, theirs left in paper — the **side** carries
  the distinction, so it survives a monochrome screen rather than resting on
  colour
- Enter sends, Shift+Enter starts a new line

**File attachments are not built.** The column exists in the schema, and the
upload hardening from the verification work would carry over, but an attachment
channel between two parties who have not yet transacted is a different risk
surface from a carrier uploading their own ABN — it wants its own thought.

## 5. Responsive Behavior

- Mobile-first layout with simplified cards and stacked sections
- Desktop layout uses two-column dashboards and wider content density
- Tablet uses hybrid layouts for overview + list views
- Touch-friendly buttons and large tap targets

## 6. Accessibility and Motion

- WCAG-friendly contrast and focus states
- Respect reduced-motion preferences
- Use subtle transitions for hover, selection, loading, and toast notifications
