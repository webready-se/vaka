# Brand

Vaka follows the [Webready open source design kit](https://github.com/webready-se/open-source-design-kit): the mark's frame, Figtree, the slate neutrals, what colours mean, the icons, the page order, the footer and the voice are the same in every Webready tool. This page only holds what is Vaka's own. The kit version Vaka copied is in the header of the tokens in `public/app.css`.

Vaka keeps watch over websites the way an owl keeps watch at night: calmly, and wide awake when something moves.

## The idea

- **Calm when all is well.** "All quiet." on the night panel, an owl that watches and blinks now and then.
- **Unmistakable when it is not.** The panel glows red, the owl is wide awake, the browser tab shows the alarm mark and a count: `(2) Vaka`.
- **Plain words first.** What is wrong, since when, and what to look at. Detail is one click away.

## The owl

| Where | File | Source in the kit |
|-------|------|-------------------|
| Favicon and top bar | `public/favicon.svg`, `public/marks/vaka.svg` | `marks/vaka.svg`: the whole owl on deep teal `#0E7490` |
| The tab while a site is down | `public/favicon-alert.svg` | `marks/vaka-alarm.svg`: the same owl on red, eyes wide |
| A phone's home screen | `public/icon-180.png` (iOS), `icon-192.png` and `icon-512.png` (the manifest, `/?manifest`), made from `scripts/app-icon.svg` | `marks/vaka.svg`'s owl, unchanged, on a square tile (the phone rounds it; transparent corners turn black on iOS) and smaller, inside the round safe zone a phone's mask keeps |
| The night panel | inline in `public/app.js` (`OWL`) | `characters/vaka.svg` (watching), `characters/vaka-awake.svg` (wide awake while a site is down) and the talons of `characters/vaka-perched.svg` (sitting on the panel's bottom edge) |
| The footer credit | `public/marks/puls.svg` | The marks of the tools that are released and public, each linked (today Puls); a tool's mark is copied in when it is released |

Change the owl in the kit, then copy it here; never draw a Vaka-only variant. After a change, make the home screen icons again:

```bash
for size in 180 192 512; do rsvg-convert -w $size -h $size scripts/app-icon.svg -o public/icon-$size.png; done
```

## Vaka's colour

Teal: `--accent` `#0E7490` (dark `#22D3EE`) for text, links and focus; the tile `#0E7490`; the owl itself `#19ACCA`. Down red is the kit's `--down`, never Puls's coral.

The 30 day bars use the kit's bar tokens, plus one of Vaka's own: a day that was down only inside a maintenance window is `--quiet` at about half strength. Maintenance and snoozes are quiet everywhere in Vaka, and planned work should read as calm, not as a smaller outage. It differs from green and amber in hue and lightness, and from "no data" in height too.

## The night panel

Vaka's signature surface (the kit's "answer" at the top of the page). It stays night in both themes and says how everything is in one headline, with the numbers beside it; the line under the headline says only what the numbers do not. Its glow follows the state: teal when calm, amber when something needs a look, red when a site is down.

The owl sits on the panel's bottom edge, its talons over it, and tonight's moon is above it, in the phase it has: worked out in the browser from a known new moon and the length of a lunar month, so it needs no service and keeps up by itself. The moon is the panel's, not the owl's, so it is Vaka's own rather than the kit's; a hover says which it is ("Tonight's moon: waning gibbous, 94% lit"). Only a moon, never a sun: the panel is night in both themes. On a phone the owl sits in the corner on its feet, without the moon, since the numbers fill the bottom.

The login is always night too: the first thing anyone sees of Vaka.

## Motion

Little, and only where it means something: the live dot in the top bar pulses while checks run, the owl blinks now and then, the site detail slides in. All of it stops for people who prefer reduced motion.
