# Zokeibi (造形美 — "Beauty of Form")

[![Discord](https://img.shields.io/badge/Discord-Join%20Community-5865F2?logo=discord&logoColor=white)](https://discord.gg/SXWjgBGwrZ)
![PHP 8.5.13+](https://img.shields.io/badge/PHP-8.5.13%2B-777BB4?logo=php&logoColor=white)
![Zero npm](https://img.shields.io/badge/npm-0%20deps-16a34a)
![License MIT](https://img.shields.io/badge/License-MIT-0284c7)

Modern, zero-npm, strict-CSP, AI-native UI component framework and pure PHP 8.5 OKLCH CSS compiler for **Kongono** (or any HTML stack).

- **Live 62-Component & 8-Block Playground:** https://zokeibi.com/playground/
- **Live Browser OKLCH Theme Studio:** https://zokeibi.com/studio/
- **Zero-Install Single-File CDN & Starter HTML:** https://zokeibi.com/dist/v1.3/starter.html ([SRI Manifest](https://zokeibi.com/dist/manifest.json))

## Why Zokeibi?

- **Pure 2026 Web Standards First**: Native CSS `@layer`, perceptual OKLCH 1-knob theming (`--z-hue`, `--z-chroma`, `--z-radius`), `color-mix(in oklch, ...)`, CSS Nesting, `@container` queries, `:has()` form & switch states, native HTML `<details name="...">` exclusive accordions, and Top-Layer `[popover]` + `@starting-style` transitions.
- **Zero Node, Zero npm, Zero Vite Required**: A pure PHP 8.5 CSS Tree-Shaker (`Zokeibi\Compiler\CssTreeShaker`) scans your `views/**/*.kgn.html` files for `<x-ui.*>` tags, resolves transitive CSS dependencies, and writes a tiny minified `zokeibi.min.css` (`11.75 KB` gzip for all 62 components).
- **Shadcn Ownership Model**: `zokeibi add button card modal` copies clean `<x-ui.*>` templates straight into your app's `views/components/ui/` directory — you own every line of markup.
- **Strict CSP (`script-src 'self'` & `style-src 'self'`)**: Zero inline `<script>`, zero `on*=` attributes, zero inline `style="..."` attributes across all **62** primitives (composable `<x-ui.filter-bar>` with Saved Views & URL query sync, accessible `<x-ui.compare-slider>`, declarative `data-z-dirty-guard="true"` unsaved-changes form bar, mobile bottom sheets `<x-ui.drawer position="bottom">`, interactive hover/focus pure-SVG `<x-ui.chart>` tooltips, two-gear `data-z-density="calm|default|compact"` switching, integer-cents `<x-ui.money-input>`, hardened `<x-ui.alert-dialog>` with typed confirmation gates, stacked programmatic & grace-window undoable toasts, DataTable row selection + bulk action bar + Popover column toggle + RFC 4180 CSV export, composite `<x-ui.skeleton>` presets, human-in-the-loop `<x-ui.ai-review>` & `<x-ui.approval-card>` gates, ESIGN/UETA `signature-pad`, date-range calendars, multi-select comboboxes, XSS-safe Markdown editor, 6-digit OTP inputs, Cmd+K palette, zero-code `data-z-cycle-theme` OKLCH theme switching, `@media print` defaults, and more) and **8** full-page SaaS blocks.
- **DTO-to-UI Reflection Generator**: `zokeibi form` and `zokeibi table` reflect PHP 8.5 readonly DTOs (`#[Required]`, `#[Email]`, `#[Url]`, `#[Date]`, `#[In]`, `BackedEnum`, `array`) with zero runtime Composer deps and emit accessible `.kgn.html` views (sortable & searchable tables out of the box).
- **Executable UI Proof Gate (`zokeibi prove`)**: Proves WCAG 2.1 AA contrast across active + every saved custom theme, static ARIA/schema/CSP audit (`100/100`), and gzipped CSS KB budget (`--max-gzip-kb=12.5`) in one deterministic Readiness Certificate.
- **Live Studio (Web + Local Hot-Swap) & AI-Native Registry**: Try the zero-backend browser OKLCH Studio at [zokeibi.com/studio](https://zokeibi.com/studio/), or launch local `zokeibi studio` / `zokeibi watch` hot-swap sync, machine-readable `resources/registry.json` (`zokeibi schema`), and Kongono MCP tool `kongono_zokeibi_registry`.

## Quick Start

```bash
composer require kongono/zokeibi

# Initialize project config and directories
php vendor/bin/zokeibi init --theme=sumi

# Copy any (or --all) of the 62 <x-ui.*> components into views/components/ui/
php vendor/bin/zokeibi add button card filter-bar compare-slider money-input alert-dialog ai-review approval-card table chart drawer

# Install full-page strict-CSP SaaS blocks (saas-shell, auth-login, settings-billing, pricing-grid, empty-onboarding, error-page, analytics-dashboard, api-keys-audit)
php vendor/bin/zokeibi block list
php vendor/bin/zokeibi block add saas-shell analytics-dashboard api-keys-audit

# Generate accessible forms & DataTables directly from a PHP 8.5 DTO
php vendor/bin/zokeibi form App\\Dto\\CreateNoteInput --action=/notes --out=views/notes/form.kgn.html
php vendor/bin/zokeibi table App\\Dto\\NoteRow --items=notes --base-url=/notes?page= --out=views/notes/table.kgn.html

# Tree-shake & compile CSS purely in PHP (or watch for zero-reload CSS hot-swap)
php vendor/bin/zokeibi build --views=views --out=public/assets/zokeibi.min.css
php vendor/bin/zokeibi watch --dir=. --views=views

# Run the 3-pillar UI, WCAG 2.1 AA & CSS KB budget proof gate
php vendor/bin/zokeibi prove --dir=. --views=views --strict

# Launch local Design Token & Theme Studio GUI (with Live Hot-Swap Sync)
php vendor/bin/zokeibi studio --dir=. --port=8101
```

## Bring Your Own Stack (Zero Lock-In)

Zokeibi has **zero runtime Composer dependencies** (`php >= 8.5.13` only) and works cleanly outside Kongono too:

- **Zero-Install CDN (HTMX / Plain HTML / CodePen):**
  ```html
  <link rel="stylesheet" href="https://zokeibi.com/dist/v1.3/zokeibi.min.css">
  <script src="https://zokeibi.com/dist/v1.3/zokeibi-runtime.js" defer></script>
  ```
  Verified SHA-384 SRI hashes are published at `https://zokeibi.com/dist/manifest.json`, and a ready-to-save single-file starter lives at `https://zokeibi.com/dist/v1.3/starter.html`.
- **Laravel (Blade) / Symfony (Twig) / React / Vue / Svelte Tree-Shaking:** `zokeibi build` scans both `<x-ui.*>` tags AND `.z-*` CSS classes (`z-btn`, `z-card`, `z-modal`, etc.) across `.kgn.html`, `.html`, `.php`, `.twig`, `.blade.php`, `.jsx`, `.tsx`, `.vue`, and `.svelte` (automatically skipping `vendor/` and `node_modules/`). Add `"safelist": ["modal", "toast"]` in `zokeibi.json` for dynamic class names.
- **Plain HTML / HTMX / Non-Live Interactivity:** Install the optional `~1.5 KB` zero-dependency strict-CSP runtime (`data-z-open`, `data-z-close`, `data-z-toggle`, `data-z-tabs`):
  ```bash
  php vendor/bin/zokeibi runtime --out=public/assets/zokeibi-runtime.js
  ```
- **Universal Token Exports (Figma / React / Tailwind v4):**
  ```bash
  php vendor/bin/zokeibi export --format=w3c-tokens --out=tokens.json
  php vendor/bin/zokeibi export --format=tailwind --out=resources/css/zokeibi-theme.css
  ```

## 5 Built-In OKLCH Studio Themes

Switch themes dynamically with a single HTML attribute (`data-zokeibi-theme="..."`) and zero CSS rebuild:

- `sumi` — Sumi Crimson (Default Dark)
- `washi` — Washi Paper (Warm Light Editorial)
- `indigo` — Ai Indigo (Deep Indigo Dark)
- `matcha` — Matcha Jade (Calm Emerald Dark)
- `kintsugi` — Kintsugi Gold (Warm Amber Dark)

Radius presets (`data-zokeibi-radius="..."`): `sharp` (`4px`), `default` (`12px`), `rounded` (`18px`).

## Run the Interactive Playground

```bash
composer install
composer build-playground-css
composer playground
# Open http://127.0.0.1:8101
```

## Quality Checks

```bash
composer analyse           # PHPStan Level 9 + strict rules
composer test              # PHPUnit 13
composer audit-playground  # Zokeibi UI Schema & CSP Audit
```
