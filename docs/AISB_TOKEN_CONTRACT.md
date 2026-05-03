# AISB Token Contract

**Status:** Stable
**Consumer:** Form Runtime Engine
**Producer:** Promptless WP plugin (AI Section Builder Modern)
**Minimum compatible producer version:** Promptless WP `1.2.x`
**Last reconciled against source:** v1.2.5 of this plugin against Promptless WP v1.2.9 (added smart surface border tokens)

---

## Purpose

Form Runtime Engine is designed to **inherit brand styling from Promptless WP when it is active** and to **degrade gracefully to sensible defaults when it is not**. It does this by reading a small set of CSS custom properties (design tokens) emitted by Promptless WP's Global Settings.

This document is the **public contract** between the two plugins. It is the authoritative list of `--aisb-*` tokens that Form Runtime Engine reads. No other tokens are consumed, and every token listed here has a documented fallback so the form engine never breaks when the plugin is absent, deactivated, or an older version that does not yet emit a given token.

Because this relationship is implicit in CSS — there is no PHP-level coupling between the two plugins — renaming, removing, or changing the semantics of any token listed here will silently break form styling on every site that runs both plugins. Maintainers on either side MUST treat this contract as a versioned API.

---

## Contract Terms

**Promptless WP (producer) promises:**

1. Every token listed in the Consumed Tokens table below will continue to be emitted on any page where Promptless WP is active, for as long as the contract version in this document is supported.
2. A token's semantic meaning (what it represents visually) will not change between minor versions. Values may be re-calculated; meanings will not be repurposed.
3. Removal or rename of a listed token will be preceded by at least one minor version's worth of deprecation notice, and the old token will continue to be emitted (as an alias) for the full deprecation window.

**Form Runtime Engine (consumer) promises:**

1. Every `var(--aisb-*, <fallback>)` in `assets/css/frontend.css` and `assets/css/neo-brutalist.css` provides a fallback that produces a usable form when the token is absent.
2. No `--aisb-*` token will be read anywhere else in the form engine (no JS reads, no PHP reads, no additional CSS files) without updating this document first.
3. New token consumption requires: (a) adding the token to the table below, (b) pinning the minimum producer version, (c) supplying a fallback.

---

## Consumed Tokens

Tokens are grouped by functional area. The **Fallback** column is the value used when Promptless WP is inactive or the token is otherwise not defined; changing a fallback is a breaking change for standalone form styling.

### Core Colors (`--aisb-color-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-primary` | Primary brand color; drives button background, focus rings, accents | `#6366f1` |
| `--aisb-color-text` | Default body/field text color (light mode) | `#1f2937` |
| `--aisb-color-text-muted` | Secondary text (help text, descriptions) | `#6b7280` |
| `--aisb-color-background` | Page/form background (light mode) | `#ffffff` |
| `--aisb-color-surface` | Field background, card surfaces (light mode) | `#f9fafb` |
| `--aisb-color-border` | Input borders, dividers (light mode) | `#e5e7eb` |
| `--aisb-color-error` | Validation errors, required markers | `#ef4444` |
| `--aisb-color-success` | Success states, confirmation messages | `#10b981` |
| `--aisb-color-warning` | Warning messages | `#f59e0b` |
| `--aisb-color-info` | Info messages | `#3b82f6` |

### Dark Mode Colors (`--aisb-color-dark-*`)

Used when the form is placed inside `.aisb-section--dark` or when `theme_variant: dark` is set in form settings.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-color-dark-text` | Body/field text color (dark mode) | `#fafafa` |
| `--aisb-color-dark-text-muted` | Secondary text (dark mode) | `#9ca3af` |
| `--aisb-color-dark-background` | Form background (dark mode) | `#1a1a1a` |
| `--aisb-color-dark-surface` | Field background (dark mode) | `#2a2a2a` |
| `--aisb-color-dark-border` | Input borders (dark mode) | `#4b5563` |

### Button Tokens (`--aisb-button-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-button-primary-bg` | Submit button background | `var(--fre-primary-color)` |
| `--aisb-button-primary-text` | Submit button label color | `#ffffff` |
| `--aisb-button-primary-hover-bg` | Submit button hover background | `#4f46e5` |
| `--aisb-button-primary-hover-text` | Submit button hover label color | `var(--fre-button-text)` |
| `--aisb-button-glow-color` | Soft glow halo on hover | `rgba(99, 102, 241, 0.4)` |

### Smart-Color Chain (`--aisb-smart-*`)

Promptless WP calculates these WCAG-compliant values from the primary color and surface context. Form Runtime Engine uses them for ghost/secondary buttons and form field borders so contrast remains correct against any brand color.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-smart-light-ghost-button` | Ghost button color on light surfaces | `var(--aisb-smart-light-section-link, var(--fre-primary-color))` |
| `--aisb-smart-light-section-link` | Intermediate fallback in the chain | `var(--fre-primary-color)` |
| `--aisb-smart-light-surface-border` | Form field border on light surfaces (3.0:1 contrast) | `var(--aisb-color-border, #e5e7eb)` |
| `--aisb-smart-dark-ghost-button` | Ghost button color on dark surfaces | `var(--aisb-smart-dark-section-link, var(--fre-primary-color))` |
| `--aisb-smart-dark-section-link` | Intermediate fallback in the chain | `var(--fre-primary-color)` |
| `--aisb-smart-dark-surface-border` | Form field border on dark surfaces (3.0:1 contrast) | `var(--aisb-color-dark-border, #4b5563)` |

### Typography (`--aisb-section-font-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-font-body` | Body font for labels, descriptions, inputs | System font stack |
| `--aisb-section-font-heading` | Heading font (form title, section headings) | `var(--fre-font-family)` |
| `--aisb-section-font-button` | Submit button font family | `var(--fre-font-family)` |
| `--aisb-section-font-button-weight` | Submit button font weight | `500` |
| `--aisb-section-font-button-text-transform` | Submit button text-transform | `none` |
| `--aisb-section-font-button-letter-spacing` | Submit button letter-spacing | `normal` |

### Layout & Shape (`--aisb-section-*`)

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-section-radius-card` | Form container / card border-radius | `8px` (also `12px` for `-lg` variant) |
| `--aisb-section-radius-button` | Button border-radius | `8px` |
| `--aisb-section-space-xs` | Extra-small spacing scale | `0.5rem` |
| `--aisb-section-space-sm` | Small spacing scale | `1rem` |
| `--aisb-section-space-md` | Medium (default) spacing scale | `1.5rem` |
| `--aisb-section-space-lg` | Large spacing scale | `2rem` |
| `--aisb-section-transition-fast` | Fast transition timing | `150ms ease` |
| `--aisb-section-transition-base` | Default transition timing | `200ms ease` |

### Neo-Brutalist Mode (`--aisb-neo-*`)

Promptless WP emits these only when Neo-Brutalist mode is enabled in Global Settings. Form Runtime Engine's `neo-brutalist.css` consumes them so forms match the site's brutalist treatment automatically.

| Token | Purpose | Fallback |
|-------|---------|----------|
| `--aisb-neo-brutalist-primary-border` | Heavy border color (typically `#000`) | `#000000` |
| `--aisb-neo-border-width` | Card/field border width | `4px` |
| `--aisb-neo-border-width-button` | Button border width | `3px` |
| `--aisb-neo-shadow-offset` | Card/field drop-shadow offset | `8px` |
| `--aisb-neo-shadow-offset-button` | Button drop-shadow offset | `4px` |
| `--aisb-neo-shadow-offset-button-hover` | Button hover drop-shadow offset | `3px` |

---

## Context Hook

In addition to tokens, Form Runtime Engine reacts to one structural selector that Promptless WP controls:

| Selector | Purpose |
|----------|---------|
| `.aisb-section--dark` (ancestor) | Triggers dark-mode variant on nested `.fre-form` |

Renaming this class on the plugin side is equivalent to a breaking token change and follows the same deprecation rules.

---

## How to Change This Contract

**Adding a new token the form engine wants to read:**

1. Confirm the token exists in Promptless WP (check `src/utils/settingsToCss.js` and `src/styles/tokens/` in that repo).
2. Add a row to the appropriate table above with a sensible fallback.
3. Use the token in CSS only via `var(--aisb-thing, <fallback>)` — never bare.
4. Bump the "Minimum compatible producer version" at the top if the token is new on the producer side.

**Retiring a token the form engine no longer needs:**

1. Remove all `var(--aisb-thing, ...)` references from CSS.
2. Delete the row from the table above.
3. Note the change in `CHANGELOG.md`.

**Responding to a producer-side deprecation:**

1. When Promptless WP announces a deprecation, open an issue in this repo referencing the producer version that removes the token.
2. Plan migration before the removal version lands; fallback values alone are not sufficient because the fallback is only used when the token is absent, not when it changes meaning.

---

## Related Documentation

- **Producer side:** `ai-section-builder-modern/docs/standards/CSS_STANDARDIZATION.md` — the plugin's internal CSS token standard (editor vs section token separation).
- **Consumer side:** `form-runtime-engine/CLAUDE.md` → "Design System Integration" — high-level overview.
- **CSS implementation:** `form-runtime-engine/assets/css/frontend.css` and `assets/css/neo-brutalist.css` — every listed token appears here, nowhere else.
