# Design system

The interface is built for people timing a race outdoors on a phone: bright daylight, cold hands, a queue of athletes arriving at once. Every choice favours fast, unmistakable reading over decoration.

## Colour

Tokens live in `resources/css/app.css` as CSS variables and are exposed to Tailwind (`bg-surface`, `text-muted`, `bg-buoy` …). Light and dark themes share the same names.

| Token | Light | Dark | Use |
| --- | --- | --- | --- |
| `ink` | `#0B2A3C` | `#020D14` | App bar, race clock panel, primary text |
| `canvas` / `surface` | `#E9EFF0` / `#FFFFFF` | `#061822` / `#0B2331` | Page / cards |
| `buoy` | `#FF5B14` | `#FF6A26` | The one accent: primary actions, focus ring, current tab. Text on it is `ink`. |
| `swim` | `#0B6FB8` | `#3AA0E8` | Swim checkpoints |
| `bike` | `#E8AD00` | `#F5C430` | Bike checkpoints and the offline notice |
| `run` | `#CF2A3C` | `#FF5C6E` | Run checkpoints |
| `finish` | `ink` with a chequered pattern | | Finish checkpoints |
| `success` / `warning` / `error` | `#0B7A43` / `#9A4A03` / `#B4121B` | `#4FD18B` / `#FFC266` / `#FF8A8A` | Status text |

Discipline colours are applied with `data-discipline="swim|bike|run"` or `data-kind="finish"`; components read `--discipline` and `--on-discipline`.

## Type

- **Barlow Condensed** (600–800) for headings, the race clock and bib numbers. Condensed figures fit a full `01:18:55` on a narrow phone.
- **Barlow** (400–600) for body text.
- Times always use tabular figures (`.tabular`, `.race-clock`, `.bib`) so digits do not jump while the clock runs.
- Sentence case everywhere; no all-caps labels.

Fonts are self-hosted through `@fontsource`, so the race-day link works without third-party requests.

## Components

- **TimingPad** (`resources/js/Components/TimingPad.vue`): the tap screen used by every timing page. A band in the checkpoint's discipline colour shows where you are recording and the race clock; search with a number/letter keypad toggle; athletes still to come first; recorded athletes folded away; feedback with undo pinned to the bottom of the screen.
- **Bib** (`Components/Bib.vue`): bib numbers drawn as a race bib: white paper, a strip in the checkpoint colour, pin holes. Used in tap lists, standings and results.
- **Logo** (`Components/Logo.vue`): a stopwatch whose ring is split into the swim, bike and run colours.
- **Icon** (`Components/Icon.vue`): Lucide SVG icons by name, tree-shaken.

## Layout rules

- Touch targets are at least 44 px; tap rows are 72 px.
- Destructive actions are quiet (an icon button with a confirmation) and never sit where a fast tap could hit them.
- Important state is shown with colour and words, never colour alone.
- Respect `prefers-reduced-motion`; the only animation is the confirmation pulse on a recorded athlete.
