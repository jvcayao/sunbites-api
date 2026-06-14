# Email Template Redesign — Sunbites API

**Date:** 2026-06-14
**Scope:** All transactional email Blade templates in `resources/views/emails/`

---

## Goal

Redesign the six existing email templates to properly represent the Sunbites brand. Currently the templates are unstyled or inconsistently styled, use two different structural approaches, and do not include the logo. The result should feel warm, on-brand, and consistent across all six email types.

---

## Design Decisions

| Decision | Choice | Reason |
|---|---|---|
| Primary brand color | Red `#dc2626` | Matches the Sunbites logo (red & black) |
| Template architecture | Shared Blade anonymous component | Single source of truth for header/footer; each template just provides content |
| Tone | Warm & friendly | Audience is parents of school canteen students |
| Markdown mail (`x-mail::message`) | Removed from all templates | Replaced by custom layout for full branding control |

---

## Layout Structure

All emails share a single outer layout:

```
Page background: #f3f4f6 (light grey)
  └── 600px max-width centered wrapper
        ├── HEADER — full-width red (#dc2626)
        │     └── Sunbites logo, centered, height: 48px
        │         (absolute URL via asset('images/sunbites.png'))
        ├── CARD BODY — white (#ffffff), 32px padding
        │     └── {{ $slot }} — unique content per email
        └── FOOTER — light grey (#f9fafb), 12px muted text
              └── "Sunbites School Canteen Management System"
                  "© [year] All rights reserved."
```

---

## Color Palette

| Token | Hex | Usage |
|---|---|---|
| Brand red | `#dc2626` | Header background, CTA buttons, accent borders |
| Page background | `#f3f4f6` | Outer wrapper |
| Card background | `#ffffff` | Content card |
| Info tint | `#fef2f2` | Info boxes and detail tables |
| Body text | `#1a1a1a` | Main paragraphs |
| Muted / label | `#6b7280` | Secondary labels, table row labels |
| Danger text | `#dc2626` | Low wallet balance amounts |
| Footer text | `#9ca3af` | Footer and fine print |

---

## Typography

- Font stack: system sans-serif — `Arial, Helvetica, sans-serif` (no web fonts; email clients do not load them)
- Body size: 15px, line-height 1.6
- Headings: 20–22px, bold, `#1a1a1a`
- Footer / fine print: 12px, `#9ca3af`

---

## Shared Inner Components

These are recurring HTML patterns used inside individual templates (not separate Blade components — inline styles only for email compatibility):

### Info Box — Key-Value
A tinted container for structured data with a label column and a value column (wallet balance, enrollment details).
- Background: `#fef2f2`
- Left border: `4px solid #dc2626`
- Border-radius: `0 6px 6px 0`
- Padding: `12–16px`
- Implemented as two `<div>` rows each with a flex/inline layout: label (muted, `#6b7280`) on the left, value (dark or red for danger) on the right

### Info Box — Text Block
A tinted container for a single block of text (rejection reason, feedback quote).
- Same background and border as key-value variant
- No columns — paragraph text flows naturally inside

### CTA Button
A full-width or inline action button.
- Background: `#dc2626`
- Text: `#ffffff`, bold
- Border-radius: `6px`
- Padding: `12px 28px`
- `text-decoration: none`, `display: inline-block`

### Blockquote
Used in feedback-reply for quoted admin reply text.
- Same visual as info box (red left border, tinted background)

---

## File Structure

```
resources/views/
  components/
    emails/
      layout.blade.php          ← NEW: shared anonymous Blade component
  emails/
    parent-welcome.blade.php    ← updated: extend layout
    wallet-alert.blade.php      ← updated: extend layout
    feedback-reply.blade.php    ← updated: extend layout
    pre-registration/
      received.blade.php        ← updated: replace x-mail::message, extend layout
      approved.blade.php        ← updated: replace x-mail::message, extend layout
      rejected.blade.php        ← updated: replace x-mail::message, extend layout
```

---

## Per-Email Content Spec

### 1. `parent-welcome`
- **Heading:** "Welcome to Sunbites, {{ $parent->first_name }}!"
- Body: intro paragraph explaining the Parent Portal
- CTA button: "Activate My Account" → `$activationUrl`
- Muted note: "This link will expire in 60 minutes."

### 2. `wallet-alert`
- **Heading:** "Low Wallet Balance Alert"
- Greeting: "Hi {{ $parent->first_name }},"
- Body: "{{ $student->full_name }}'s canteen wallet balance has dropped below your alert threshold."
- Info box (two rows):
  - Current Balance — value in red, bold (danger text)
  - Alert Threshold — value in muted text
- CTA button: "View Parent Portal" → `$portalUrl`
- Closing note to arrange a top-up

### 3. `feedback-reply`
- **Heading:** "A Reply to Your Feedback"
- Greeting: "Hi {{ $feedback->parent->first_name }},"
- Short intro: "The canteen team has replied to your feedback:"
- Blockquote: `{{ $feedback->admin_reply }}`

### 4. `pre-registration/received`
- **Heading:** "Pre-Registration Received!"
- Confirmation paragraph: thank you, staff will review shortly
- Info box:
  - Student Name
  - Grade Level
  - Enrollment Type (formatted)
- Closing: "No further action is needed at this time."

### 5. `pre-registration/approved`
- **Heading:** "Enrollment Approved!"
- Celebratory paragraph
- Info box:
  - Student Name
  - Student Number (or "To be assigned")
  - Grade Level
  - Enrollment Type (formatted)
- Note: if parent portal account was created, a separate activation email will follow
- Warm closing: "We look forward to serving {{ $preRegistration->first_name }}!"

### 6. `pre-registration/rejected`
- **Heading:** "Update on Your Pre-Registration"
- Empathetic opening: unable to process at this time
- Info box containing the rejection reason: `{{ $preRegistration->rejection_reason }}`
- Encouragement to visit in person or contact the canteen

---

## Logo Embedding

The Sunbites logo is referenced via `asset('images/sunbites.png')` which generates an absolute URL using `APP_URL`. This is the standard Laravel approach for email assets. The logo must be publicly accessible on the server.

- Height: 48px (width auto)
- Alt text: "Sunbites"
- Centered in the red header

---

## Email Client Compatibility Requirements

- All styles must be **inline** — no `<style>` blocks, no external CSS (Gmail strips them)
- No web fonts — use system font stacks only
- No `border-radius` on table cells (Outlook ignores it) — only on `<div>` and `<a>` elements
- Use `max-width: 600px` wrapper for consistent rendering across clients
- Images must use absolute URLs
- All layout via `<div>` with inline styles — no `<table>` layout required given target clients (Gmail, Outlook, Apple Mail)

---

## What Is Not Changing

- The Mail classes (`app/Mail/*.php`) and Notification classes (`app/Notifications/*.php`) — no PHP changes needed
- The variables passed into each template — all existing `$parent`, `$student`, `$preRegistration`, `$feedback` variables remain as-is
- Email subjects — unchanged
- The number of email templates — six, same as before
