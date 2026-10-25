# Brand

Vaka keeps watch over websites the way an owl keeps watch at night: calmly, and wide awake when something moves. Everything visual follows from that, and from the Webready family the tool belongs to (Puls is its sibling).

## The idea

- **Calm when all is well.** "All quiet." on a night-blue panel, a watching owl, nothing shouting.
- **Unmistakable when it is not.** The panel glows red, the owl's eyes go wide, the browser tab shows the alarmed owl and a count: `(2) Vaka`.
- **Plain words first.** What is wrong, since when, and what to look at. Detail is one click away.

## Logo

The mark is the **watching owl**: two round eyes with a brow that meets in the middle like an owl's facial disc, and a small beak. White lines (stroke 2.5, round caps and joins) on a rounded square (36 × 36, radius 8) filled with Vaka teal. This is the Webready family's construction: every tool's tile is filled with its own accent and carries a white line glyph (Puls: coral, three rising bars).

| File | Use |
|------|-----|
| `public/favicon.svg` | Favicon and app icon: teal gradient `#22b8d6` → `#0e8fab` |
| `public/favicon-alert.svg` | The tab while a site is down: red tile, wide eyes, small pupils |

The wordmark is **vaka** in lowercase, Figtree 800, letter-spacing −0.03em, next to the tile. Do not stretch, outline or recolour the owl; on dark backgrounds the tile stays teal.

## Colour

Neutrals are Tailwind slate, shared with Puls, so dark mode looks the same across the tools.

| Token | Light | Dark | Use |
|-------|-------|------|-----|
| Background | `#f8fafc` | `#0f172a` | Page |
| Surface | `#ffffff` | `#1e293b` | Cards |
| Line | `#e2e8f0` | `#334155` | Borders |
| Text | `#1e293b` | `#f1f5f9` | |
| Muted text | `#475569` / `#64748b` | `#cbd5e1` / `#94a3b8` | |
| **Vaka teal** | tile `#19acca`, text `#0e7490` | text `#22d3ee` | Brand, links, focus |
| Night | `#0f172a` → `#172238` | same | The watch panel and the login |

Status colours mean the same in every Webready tool. Never rely on colour alone: every status also has an icon and a word.

| Status | Light | Dark | Icon |
|--------|-------|------|------|
| Up | `#059669` on `#ecfdf5` | `#34d399` | check |
| Down | `#dc2626` on `#fef2f2` | `#f87171` | cross |
| Warning (failing, certificate) | `#b45309`, bright `#f59e0b`, on `#fffbeb` | `#fbbf24` | exclamation, shield |
| Quiet (snoozed, maintenance) | `#4f46e5` on `#eef2ff` | `#a5b4fc` | moon |
| Paused, waiting | `#64748b` | `#94a3b8` | pause, clock |

Down red is deliberately not Webready's coral `#f16272`, which is Puls's brand colour.

## Type

**Figtree** (SIL Open Font License), the closest open relative of Proxima Nova, which webready.se uses. One variable file covers weights 300–900 for Latin (20 KB), self-hosted in `public/fonts/` with its licence, so there is no CDN and the strict Content Security Policy only needs `font-src 'self'`.

| Role | Size and weight |
|------|-----------------|
| Watch headline | 30–44 px, 800, −0.035em |
| Section labels | 12 px, 700, uppercase, +0.09em, muted |
| Card titles | 17 px, 700 |
| Body | 15 px, 400, line height 1.55 |
| Numbers | tabular figures everywhere they line up |

Code and commands use the system monospace.

## Icons

Drawn for Vaka on a 24 px grid: stroke 2, round caps and joins, no fills, `currentColor` (the same rules as Puls). They live as path data in `public/app.js` and are inserted as inline SVG, so the CSP needs nothing extra. Add new ones in the same style rather than mixing in another set.

## Motion

Little, and only where it means something: the live dot in the top bar pulses while checks run, the owl in the watch panel blinks now and then, the site detail slides in. All of it stops for people who prefer reduced motion.

## Voice

- Say what happened and what to do: "No answer in time. The server may be down or overloaded: check it, or ask the host."
- Name things the way a site owner would: "down for 14 min", "checked just now", "certificate expires in 6 days".
- No jargon without an explanation, no exclamation marks, no blame.
- English in the interface, sentence case in headings.
