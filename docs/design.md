# Design: wp-ai-writer

The plugin has three UI surfaces: the editor sidebar (React, `@wordpress/components`), the
settings page, and the activity log screen (both classic wp-admin). The design principle is to
disappear into WordPress: every control is a core component or core admin markup, so color,
typography, focus, and contrast track the user's admin scheme automatically. No custom design
system, no bundled fonts, no external assets.

## Color and theme

- Sidebar: inherit the editor's palette via `@wordpress/components` and core CSS custom
  properties. Accent = admin theme accent (default `#2271b1`), destructive = `#d63638`, success =
  `#00a32a`, warning = `#dba617`, muted text = `#757575`, borders = `#ddd`. Never hardcode these
  where a component or `var(--wp-admin-theme-color)` provides them.
- Status colors are always paired with text ("Failed", "Estimated") — never color-only signals.
- Settings and log screens use stock wp-admin markup (`.form-table`, `WP_List_Table`) and take
  their colors from core.

## Typography

- System admin font stack inherited from wp-admin/editor; nothing custom.
- Scale (sidebar): panel titles 13px/600 (component default), body and inputs 13px, helper and
  meta text 12px, preview content 13px/1.6. No type sizes outside what core components emit
  except the 12px helper size.

## Spacing, radius, shadows

- 8px base grid with 4px half-steps: panel padding 16px, vertical gap between controls 12px, gap
  between label and control 4px, gap between preview and its action buttons 8px.
- Border radius 2px (WordPress default) on the preview container; components bring their own.
- No shadows; flat admin surfaces. The preview area is delineated by a 1px `#ddd` border and a
  `#f6f7f7` background, not elevation.

## Sidebar layout

- Header: plugin title + the five actions as a compact tab list (icon + label). Disabled actions
  (pre-implementation phases) render dimmed with a "coming soon" tooltip.
- Action body order, top to bottom: inputs (textarea/selects), primary Generate button, result
  area, apply/discard row, usage-error notice slot.
- Preview area: max-height 320px with internal scroll; content wraps; long unbroken strings
  break with `overflow-wrap: anywhere`.

## Component states

- Generate button (`Button variant="primary"`): default, hover/focus (core), disabled (empty or
  over-cap input, no supported block selected, request in flight), busy (in flight — `isBusy` with
  inline `Spinner`, label switches to "Generating").
- Preview area: empty (hidden entirely — no empty box), streaming (`aria-busy="true"`, text
  appending, subtle pulsing caret at the end), complete (apply/discard row appears), error
  (replaced by an error `Notice`).
- Apply buttons ("Insert into post", "Replace block content", "Use as post title", "Apply to
  excerpt", per-image "Apply"): primary variant, explicit verbs — never "OK". Discard is a
  tertiary/link button. Both disabled while a stream is active.
- Notices (`Notice` component): error (friendly server `message` verbatim), warning (budget nearly
  exhausted is out of scope for v1 — budget errors only), info (not-configured guidance),
  dismissible except the not-configured state.
- Alt text list rows: thumbnail (40px), filename, per-row Generate/Apply buttons with the same
  states; loading state per row, not global.
- Copy buttons (SEO): on success flip label to "Copied" for 2s and announce via `speak()`.
- Settings fields: masked key field with description text; inline validation errors via the
  Settings API notice; usage panel shows an empty state ("No usage recorded this month") before
  first use.
- Log screen: standard `WP_List_Table` states — rows, pagination, and an empty state message.

## Loading, empty, and long-content behavior

- Every network interaction has a visible in-flight state (busy button + spinner); the UI is never
  frozen without feedback.
- Empty states are written, not blank: no supported block selected (rewrite), empty post (seo /
  excerpt guidance), all images have alt text, plugin not configured, empty log.
- Streaming text auto-scrolls the preview only while the user is at the bottom; scrolling up
  pauses auto-scroll.

## Accessibility baseline

- Semantic structure: real headings in panels, lists for the image list, buttons are `<button>`
  (guaranteed by core components).
- Every input has a visible label (`TextareaControl` / `SelectControl` `label` prop); helper text
  is associated via the component's description mechanism.
- Fully keyboard-operable: tab order follows the visual order; the action tab list supports arrow
  keys (core `TabPanel` behavior); no keyboard traps; Escape does not silently discard a result
  (Discard is an explicit button).
- Visible focus states everywhere (core focus ring; never disable outlines).
- Streaming announcements: the preview region sets `aria-busy` during the stream — no `aria-live`
  on the rapidly-updating text (which would flood screen readers). Completion, errors, apply
  confirmations, and "Copied" are announced with `speak()` from `@wordpress/a11y`.
- Color contrast meets WCAG 2.1 AA (4.5:1 body text); inherited core colors already comply —
  verify the muted 12px helper text against its background.
- Decorative icons carry `aria-hidden="true"`; the sidebar toolbar button has an accessible label.
- `prefers-reduced-motion`: the streaming caret pulse and any transitions are disabled under the
  media query.
