---
name: Avian Visitors
description: A live bird collage from your window, kept like a field notebook.
colors:
  paper: "#fcfcfb"
  paper-recess: "#f3f2ee"
  paper-shade: "#e8e6df"
  ink: "#1a1612"
  ink-umber: "#4a3f31"
  ink-faded: "#706556"
  hairline: "rgba(26,22,18,0.14)"
  danger: "#9b3f37"
  card-stock: "#f7f6f2"
  card-ink: "#29251f"
  card-muted: "#77736b"
  charcoal: "#17181c"
  charcoal-recess: "#212329"
  charcoal-shade: "#2c2f36"
  charcoal-pill: "#3a3e48"
  moon-ink: "#ece8e1"
  moon-ink-2: "#b7afa2"
  moon-ink-soft: "#9a9285"
  moon-accent: "#d8d2c6"
  danger-dark: "#dc8b81"
  ok: "#4f7a55"
  caution: "#a0711c"
  bad: "#a3443a"
  ok-dark: "#8ebf95"
  caution-dark: "#d9b36a"
  bad-dark: "#e08f84"
typography:
  display:
    fontFamily: "ui-serif, 'Iowan Old Style', 'Bookman Old Style', Georgia, serif"
    fontSize: "clamp(24px, 3.2vw, 40px)"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.06em"
  display-compact:
    fontFamily: "ui-serif, 'Iowan Old Style', Georgia, serif"
    fontSize: "clamp(19px, 1.85vw, 25px)"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.16em"
  overline:
    fontFamily: "ui-serif, 'Iowan Old Style', Georgia, serif"
    fontSize: "clamp(13px, 1.4vw, 18px)"
    fontWeight: 400
    lineHeight: 1.22
    letterSpacing: "0.06em"
  body:
    fontFamily: "ui-serif, 'Iowan Old Style', 'Apple Garamond', Georgia, serif"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.25
  title:
    fontFamily: "ui-serif, 'Iowan Old Style', Georgia, serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.3
  label:
    fontFamily: "ui-monospace, 'SF Mono', Menlo, monospace"
    fontSize: "12px"
    fontWeight: 700
    lineHeight: 1
    letterSpacing: "0.16em"
  caption:
    fontFamily: "ui-monospace, 'SF Mono', Menlo, monospace"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.35
    letterSpacing: "0.06em"
  body-phone:
    fontFamily: "ui-serif, 'Iowan Old Style', Georgia, serif"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.3
  data:
    fontFamily: "ui-monospace, 'SF Mono', Menlo, monospace"
    fontSize: "11px"
    fontWeight: 400
    lineHeight: 1
    letterSpacing: "0.04em"
  hand:
    fontFamily: "'Hand', Caveat, cursive"
    fontWeight: 400
rounded:
  pill: "999px"
  card: "3px"
  sm: "4px"
  md: "8px"
  sheet: "14px"
spacing:
  control-height: "36px"
  control-height-phone: "27px"
  gutter: "32px"
  gutter-phone: "18px"
components:
  window-pick:
    backgroundColor: "{colors.paper-recess}"
    textColor: "{colors.ink-faded}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    height: "{spacing.control-height}"
    padding: "4px"
  window-pick-active:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
  menu-button:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    height: "{spacing.control-height}"
    padding: "0 14px"
  menu-sheet:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sheet}"
    width: "360px"
  stats-row:
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    padding: "2px 0"
  postcard:
    backgroundColor: "{colors.card-stock}"
    textColor: "{colors.card-ink}"
    rounded: "{rounded.card}"
    padding: "8px"
    width: "min(880px, calc(100vw - 56px))"
---

# Design System: Avian Visitors

## Overview

**Creative North Star: "The Field Notebook at the Window"**

Avian Visitors reads like a naturalist's notebook kept beside one particular window: ink on warm paper, small typewritten annotations in the margins, and the birds as the only real drawings. The stats views extend that into a quiet ledger of who came by and when, and the Atlas collects each species as a stamp or postcard. It's a record you keep, not a dashboard you monitor.

The mood is calm, editorial and tactile. The palette is almost colorless: near-white paper, near-black warm ink, and a single umber in between. All the color comes from the illustrations. Depth is physical rather than digital. Controls sit in recessed paper tracks with a raised paper thumb, sheets lift off the page with a soft drop shadow, and postcards carry a real paper texture. The type pairs a bookish serif for names and titles with tracked, uppercase monospace for the margin notes.

The interface stays out of the way. Chrome is small, pale and pushed to the edges, and the collage fills the viewport. Typography and hairlines carry the hierarchy, not boxes and fills.

**Key Characteristics:**
- Ink-on-paper neutrals with a full charcoal dark theme; the birds supply every saturated color.
- A serif for names and titles, with tracked uppercase monospace for labels, axes and counts.
- Physical depth: recessed tracks, raised paper thumbs, lifted sheets, textured card stock.
- Hairlines and black squares instead of boxes and filled charts.
- Text sits directly on the paper, never on a backing plate.

## Colors

A near-monochrome warm palette: paper, ink and one umber. Color belongs to the birds.

### Primary
- **Field Ink** (`ink`): Titles, species names, active control labels, and the stats timeline squares. It's the only "strong" mark on the page.
- **Umber Ink** (`ink-umber`): The accent: the overline ("your birds"), heading rule marks, cross-highlighted names, and the "on" state of toggles. It used to be red; it's now deliberately the same family as the ink.

### Neutral
- **Notebook Paper** (`paper`): Page background, raised control thumbs, menu button and sheet. Near-white with the faintest warm cast.
- **Recessed Paper** (`paper-recess`): The sunken tracks behind segmented pickers and the bottom view slider, and hover wash in the menu.
- **Shaded Paper** (`paper-shade`): Placeholder fills while illustrations load.
- **Faded Ink** (`ink-faded`): Inactive picker labels, hints, and captions. About 5:1 on Paper Recess and 5.5:1 on paper, so it meets WCAG AA wherever text sits (darkened from #908576 in the 2026-10-08 audit). Moonlit dark mode's `moon-ink-soft` is likewise about 5:1 on its recess.
- **Quiet Ink** (`--stats-quiet`, defined on the Stats view): `color-mix(in srgb, var(--ink-soft) 70%, var(--ink-2))`, about 4.7:1 on paper and higher on charcoal. Used for secondary text that still has to be read: captions, ticks, scientific names, counts. It's the pattern to extend to other views.
- **Hairline** (`hairline`): Dividers and chart gridlines.
- **Card Stock** (`card-stock`), **Card Ink** (`card-ink`), **Card Muted** (`card-muted`): The postcard's own slightly warmer paper and inks, used only inside the postcard sheet.
- **Danger** (`danger`): Destructive admin actions only.

### Dark theme ("Charcoal")
Applied by `data-theme="dark"` on `<html>` (auto by system preference, or chosen in Settings). Every role flips: **Charcoal** (`charcoal`) page, **Charcoal Recess** (`charcoal-recess`) tracks, **Charcoal Pill** (`charcoal-pill`) raised thumbs, **Moonlit Ink** (`moon-ink`) text, `moon-ink-2` secondary, `moon-ink-soft` muted, `moon-accent` accent, `danger-dark` destructive. The bird cutouts sit directly on the charcoal.

### Named Rules
**The Birds Bring the Color Rule.** The interface never adds a hue. Ink, umber and paper only; any saturated color on screen comes from an illustration or a stamp. One exception: **health** on the admin pages. `--ok` (sage `#4f7a55` / `#8ebf95`), `--caution` (ochre `#a0711c` / `#d9b36a`) and `--bad` (brick `#a3443a` / `#e08f84`) mark passing, getting-close and failing states, only as 1px outlines and text, never fills, and never outside System / Tools / service pills.

**The Variable-Only Rule.** Every UI color comes from a custom property on `:root` and its `[data-theme="dark"]` twin, so the theme flips without per-component overrides. Hard-coded `rgba(26,22,18,…)` hairlines are legacy; new work uses `--hairline`.

## Typography

**Display / Body Font:** the system book serif: `ui-serif` → Iowan Old Style → Georgia
**Label Font:** the system monospace: `ui-monospace` → SF Mono → Menlo
**Hand Font:** Caveat (self-hosted as `'Hand'`, loaded lazily), only for handwritten bird labels on the collage
**Stamp faces:** Space Grotesk, Archivo, Anton and others, self-hosted in `fonts/stamp/` and confined to stamp artwork in the Atlas

**Character:** A bookish serif for anything with a name, and a typewriter-like mono for anything that's a measurement or a margin note. The serif never goes uppercase except the page title; the mono is almost always uppercase and widely tracked.

### Hierarchy
- **Display** (`display`): The page title ("HEARD RECENTLY"), uppercase. It shrinks to `display-compact` with wider tracking when a view scrolls and the head folds.
- **Overline** (`overline`): The italic umber line above the title ("your birds"), lowercase. It's a button that opens About.
- **Title** (`title`): Menu links and postcard names.
- **Body** (`body`): Stats rows, species names in lists.
- **Label** (`label`): Section headings in stats ("TOP SPECIES") with a 2px umber left rule, picker and slider buttons, the menu button.
- **Caption** (`caption`): Sub-captions under headings (12px). Axis ticks and chart labels use `data` (11px).
- **Data** (`data`): The size for every mono measure in Stats, on desktop and phone: captions, axis ticks, counts, rotated chart labels, calendar days. 11px is the floor; scrub readouts and counts may step up to 12px.
- **Body (phone)** (`body-phone`): Stats ledger rows on phones (15px, with 4px row padding so rows are comfortable thumb targets). Heatmap names step to 14px and wrap rather than overrun the hour cells.

### Named Rules
**The Serif Names, Mono Measures Rule.** Bird names, titles and prose are serif. Times, counts, axes, controls and section labels are mono. Don't mix them within one role.

**The Readable Floor Rule.** The tracked mono label style is identity, but no interface text renders below **11px** on any screen: Stats, collage chrome, Atlas, postcard and menu. The collage/Atlas/postcard sizes live in the "Readable type floor" block at the end of `styles.css`; stamp artwork keeps its own lettering (owner reads on 1080p monitors at 1x, where smaller mono pixelates). The incumbent shrinks labels on mobile (picker 8–8.5px, many captions 8–9px); new and revised work raises them to the floor instead of shrinking. Desktop may use 9–10px for non-essential captions.

## Layout

The stage is a fixed full-viewport flex column. A centered static head (overline + title) sits above a horizontal slider of views: Collage, Stats, Atlas, and Educators when enabled. The head never moves between views; only its text cross-fades. When a view scrolls, the head folds into a compact frosted row.

Fixed chrome sits on the edges. The time-window picker is centered at the top, the menu pill is top-right (it grows into the menu sheet), the return pill is top-left on admin pages, and the view slider is centered at the bottom. Control height is 36px on desktop and 27px on phones; gutters are 32px on desktop and 16–18px on phones.

Stats pairs a wide chart (timeline or by-hour heatmap) with a narrow side column of tight, content-sized groups (22px apart). The Atlas is a centered grid of stamps, max 1280px wide, with its sort control pinned to the top right.

The main breakpoint is **700px** (phone layout). Secondary adjustments happen at 1050, 900, 860, 560, 520, 420, 384 and 350px. Pointer-specific hover effects are gated behind `(hover: hover) and (pointer: fine)`.

## Elevation & Depth

Depth is physical paper, not material layers. Thin borders are replaced by composited inset/outset hairline shadows, and there are four recipes. Each has a dark-theme twin with faint light edges and deeper drops.

### Shadow Vocabulary
- **Edge** (`--edge`): A hairline lip on flat paper objects.
- **Edge Large** (`--edge-lg`): An open sheet (the menu) lifted off the page with a soft 28px drop.
- **Recess** (`--recess`): Sunken tracks behind segmented pickers and the bottom slider.
- **Raised** (`--raised`): Paper thumbs, pills, and the closed menu button sitting on top of a track or the page.
- **Card Shadow** (`--card-shadow`): The postcard's two-part drop (a tight contact shadow plus a long, soft 68px fall).

### Named Rules
**The Track and Thumb Rule.** Every choice control is a recessed track with a raised paper thumb that slides (320ms, `cubic-bezier(.7,.05,.2,1)`). Toggles, segmented pickers and the view slider all share this one depth language.

## Shapes

Controls are fully round pills (999px): pickers, menu button, return link, view slider, toggles. Sheets that open from a pill keep a soft 14px corner. List rows and small buttons use 4–8px. Paper objects that imitate print are nearly square: the postcard is 3px, and stamps have perforated edges from a mask rather than rounded corners. Timeline marks are hard-edged squares separated by 1px paper-colored seams.

## Components

### Time-Window Picker (signature)
- **Character:** A recessed paper track with a raised paper thumb sliding behind the active option.
- **Shape:** Pill track (999px), 4px inset, height 36px (27px on phones).
- **Labels:** `label` mono, uppercase, 0.18em tracking; inactive `ink-faded`, hover and active `ink`.
- **Motion:** The thumb slides between options (320ms). It's hidden in educator-scoped views.

### Menu Button and Sheet (signature)
- **Character:** One object. A lowercase "menu" pill when closed that grows into a 360px sheet anchored top-right when open (corner 999px → 14px, Raised → Edge Large). Open 320ms, close 380ms, with contents counter-scaled so they never squash.
- **Menu links:** `title` serif, 9px 8px padding, 6px corner, Recessed Paper wash on hover.
- **Sections:** Mono 9px bold uppercase headings, separated by a faint top rule.

### View Slider
- Fixed bottom-center pill track (Recess) with uppercase mono buttons (10px, 0.2em), inactive `ink-faded`, and a raised thumb on the active view.

### Stats Side List
- **Group heading:** `label` with a 2px umber left rule and 10px indent, plus a `caption` sub-line in Faded Ink.
- **Rows:** A three-column grid (44px year/time · name · count), serif name, mono count in tabular numbers, separated by a faint inset hairline.
- **Cross-highlight:** Hovering a timeline square or row highlights its partner; the name turns umber and takes a hairline underline. Rows are never shaded.

### Detection Timeline (signature)
- Each species is a column. A black ink square's vertical position encodes its count, and the species and scientific names are set rotated beneath. Columns are separated by hairline gridlines, and squares are seamed by 1px paper borders so clusters read as crisp blocks. There's no fill area and no color.

### Atlas Stamp
- Each species is a perforated stamp with its own print skin and display face (from `stamps.css` and the stamp batches). The stamps are artwork; the UI only lays them out in the grid and leaves them alone.

### Postcard
- **Character:** A field-guide postcard on textured card stock (`paper-texture-grey.png` blended under a wash), 3px corner, faint 18%-ink border, Card Shadow, with a slight random turn.
- **Layout:** Two columns (illustration .82fr · details 1.18fr) on desktop, fixed to the viewport.
- **Phones (≤860px):** A card floating 10px in from the top and bottom, rounded on all four corners, that scrolls as one page. The picture takes about 40% of the screen height (220–380px); the title card, About and Recordings stack beneath at their natural height, and whichever section is open shows all of its content (no inner scroll boxes). The sheet uses block flow, not grid, so it grows with its content. The home/gesture-bar safe area pads the inside of the card, never the outer gap. The pull handle and swipe-to-close stay at the top, and About/Recordings remain an either/or pair.
- **Backdrop:** The page blurs (7px) through a soft radial mask, rather than going dark.

### Return Pill
- A top-left pill on admin overlays: chevron + lowercase mono label ("collage", "educators").

### Admin Header
- On every admin page the return pill, the serif title and the menu button share one centre line (40px desktop, 26px phone). The title pins there on a paper band with a hairline as the page scrolls.

### Menu Sheet Contents
- **Live audio card:** pulse dot, "Live audio" with a mono hint ("raw microphone stream", "· remote" away from home) and an 11px mono line saying who is listening ("no one listening", "just you listening", "you and 1 other listening · away from home"); bold ink when someone else is on. Remote listening stops itself after 30 minutes: the button becomes "keep listening".
- **Links:** settings / system / logs / tools in a two-up grid, then a full-width "classic birdnet-pi" link (new tab).

### Settings Save Bar
- Station settings are staged, never autosaved. A bar pinned to the bottom of the settings scroll, 44px, paper with a hairline above, appears only while something is staged: bold mono "N unsaved changes", an underlined "discard" and an ink-filled "save". With nothing staged it sits static at the end of the page. Leaving with staged changes asks first.
- **Sliders** move only when dragged from the thumb (±22px grab zone); taps on the track do nothing and vertical swipes scroll. Off its default, a slider shows an underlined mono "default 0.70" link beside its value; "reset detection to defaults" sits under the group.

### Health Cards (System)
- Admin cards carry a 1px outline by state: `ok`, `warn` (caution), `alert` (bad, with the reading in bad too), or `info` (neutral ink-soft, for plain facts like uptime). Service pills take a green rim when active and a bad rim and text when failed.
- **Services list:** one row per unit with what it does in the caption voice; on phones two lines (name and purpose; state pill and restart beside), "since" only on desktop.

### Tools Cards
- Admin action cards (title, mono caption, a pill top-right) in a grid; the caption keeps clear of the pill. "Your data" sits two by two.
- **Species lists:** three cards (never log / only log / always allow), each a hairline ledger of serif names with italic scientific names and an underlined "remove", and a recessed search whose suggestions float on paper (birds heard here first, "heard 54" in ok). "Only log" takes a caution outline while it is filtering.
- **Restore:** a thin ink progress rule while uploading, then underlined "restore now" (bad, bold) and "discard". Password-gated actions show an inline recessed password field with an ink "unlock".

### Classic BirdNET-Pi Pages
- **What:** The stock pages at `/index.php` (Overview, Today's Detections, charts, reports, Recordings, Tools and its sub-pages), restyled in this system by `homepage/static/avian-classic.css`. That stylesheet loads after the stock `style.css` / `dark-style.css` and only overrides them. `avian-classic.js` applies the collage's saved light/dark choice (`bird:theme:v2`) before first paint.
- **Head:** Return pill ("collage") top-left, "live audio" pill top-right, site name as the italic overline, and "BIRDNET-PI" as the display title.
- **View list:** On desktop, one Track-and-Thumb row of tracked mono labels.
- **Narrow screens (≤1100px):** The stock hamburger becomes a "menu · current view" pill that opens a side drawer of serif links. The current view is bold. Escape, a tap outside, or picking a view closes it. Tools sub-pages and Recordings-by-date mark their parent view.
- **Tables:** Hairline ledgers with no fills, mono labels in the header row, and mono figures for counts.
- **Actions:** Raised paper pills in tracked mono. Reboot, shutdown and clear-data are set in Danger ink.
- **Charts:** `scripts/daily_plot.py` draws a printed ledger in one ink: species rows (most heard first) with a serif name, a bar for the day's count, and a 24-hour strip of squares sized by detections per hour. The current hour gets a faint band and a bold tick, and the header is a mono label line. It renders at 200 dpi and shows at 960px. It also draws a stacked phone version (`Combo-phone-*.png`: name and count on one line, the full-width hour strip beneath), which the pages serve under 700px through `<picture>`; only a wide-only chart falls back to 800px with a sideways scroll. Past days say "FULL DAY" where today says "UPDATED HH:MM". The Daily Charts controls are one row (previous day, date picker, next day); on phones the picker sits above the two steps, and the stock day-total table hides once a chart is shown. On the page it's `grayscale` + `multiply` on paper, and inverted + `screen` on charcoal.
- **Risky actions:** System Controls lists the safe actions first. Reboot, Shutdown and Clear All Data sit last, under a hairline, as Danger outlines rather than raised paper. On Today's Detections, delete sits at the bottom of each row, away from "open".
- **Out of reach:** View Log is a separate app inside a frame, so it keeps its own styling. The old Streamlit stats still run at `/stats`, linked from a quiet italic footnote, but they're no longer framed in the pages.

### Species Stats (classic pages)
- **What:** The "Species Stats" view (`view=Streamlit`) is a server-rendered page, `scripts/avian_stats.php`, styled by `homepage/static/avian-stats.css` on top of the classic tokens. It replaces the Streamlit frame. Max width 1180px. The page reads top to bottom: span and presets, year calendar, summary sentence, trend and time of day, who sings when, records, then the species ledger beside the deep dive.
- **The calendar is the filter:** Every panel answers for one span, carried in the URL. Picking a calendar day, a month label or a preset re-answers the whole page. The span is the page title (`display` serif, uppercase, "7 SEP – 6 OCT 2026"). Presets sit beside it as a Track-and-Thumb row of tracked mono links: 7 days, 30 days, 90 days, this year, all time.
- **Year calendar (signature):** 53 week columns × 7 days of hard-edged squares (17px with 3px paper seams; 13px and 2px on phones). Four ink steps are mixed from `ink` into `paper` (26%, 48%, 72%, 100%), so they flip with the theme. Days with no birds are hairline outlines. Days outside the span fall back to 20% opacity. Inside the span, empty days get a stronger 55%-ink outline. A single picked day takes a 2.5px solid ink outline, today gets a 1px dashed outline, and future days are left blank. A mono key underneath reads "fewer ■■■■ more birds · □ in this span". On phones the calendar scrolls sideways and starts pinned to today, with enough end padding that today's outline and the last month label stay in frame.
- **Summary sentence:** One centered serif paragraph (19px, `ink-umber`, max 62ch, balanced; 16px and left-aligned on phones). Its figures are bold mono in Field Ink. It gives species, detections, the daily average, how many birds are new to the list, the busiest day (a link), and the change against the span before.
- **Panel headings:** Same as the Stats group heading: a `label` with a 2px umber left rule, then a 12px mono sub-line in Quiet Ink. Smaller mono headings inside the deep dive skip the rule.
- **Trend:** One ink bar per day, week or month on a hairline baseline, 170px tall (140px on phones). Each bar links to its span. Species counts are 5px ink squares in a strip underneath. When the span is short, the fortnight around it shows too, with out-of-span bars in the lightest ink step.
- **Time of day:** 48 half-hour ink bars, 130px tall. Night is a flat Recessed Paper band behind the bars, from sunset to sunrise on the span's midpoint. Below it is a two-column mono fact list (sunrise, sunset, busiest half hour, dawn chorus relative to sunrise), then a serif "earliest risers" note.
- **Sun handling:** Sun times come from the station's latitude and longitude. If the station clock isn't local time (sunset lands before sunrise), every sun-relative figure and night band is left off. A serif note in Quiet Ink names the clock's zone and says why the times are missing. The page never shows a wrong sun.
- **Who sings when:** A matrix of the top species (rows, serif names that link to the deep dive) × 24 hours. Each cell is a hairline-ruled square holding a centered ink square sized by detections. Night hours sit on Recessed Paper. Hour labels show every 3 hours. At 700px and below, the matrix fits a 390px screen without scrolling: 10.5px cells, labels every 6 hours, and 13px names on one line with an ellipsis.
- **Records:** All time, whatever the span. On the left, the life-list count (large mono figure plus serif sentence) and a growth line, a single ink stepped stroke on a hairline baseline. It stretches so the left column ends level with the ledger. On the right, a hairline ledger: a mono uppercase term in Quiet Ink (130px) and a serif value with mono figures. The rows are newest, busiest day, most species, longest run, most loyal, earliest and latest song, and surest ID. Columns stack at 860px.
- **Species ledger:** A hairline table: serif name (16px) over the scientific name, then mono heard / days / vs-before columns. "Heard" carries a 5px inline ink bar on a hairline track. First-time birds get a bold tracked mono "new". The species open in the deep dive is bold and underlined, never shaded.
- **Deep dive:** The bird's Avian illustration (112px, 88px on phones), which is the only color on the page. Beside it, the name in bold serif with the scientific name in italic. Under them: a tight hairline ledger (first and last heard, all time, this span, longest run, best recording), a 24-hour rhythm in ink bars over the night band, a 53-week season strip in the calendar's four ink steps, and the latest detections as mono rows.

## Do's and Don'ts

### Do:
- **Do** let the birds carry all color; keep UI marks in ink, umber and paper.
- **Do** build every choice control as a recessed track with a raised paper thumb.
- **Do** set names in the serif and measurements, labels and controls in tracked uppercase mono.
- **Do** keep phone text at 11px or larger, even for mono labels and captions.
- **Do** define new colors and shadows as custom properties with a `[data-theme="dark"]` twin.
- **Do** give every motion a `prefers-reduced-motion` alternative that still shows the state change.

### Don't:
- **Don't** put a background behind reading text. Labels, names, headings, captions and counts sit directly on the paper. The owner finds backing plates (fills, frosted bars, highlight washes, chips) harder to read. Use ink weight, umber, or an underline for emphasis and hover instead. Incumbent exceptions to revisit: the frosted compact head bar and the 34% paper postcard backdrop.
- **Don't** introduce a new hue, gradient accent, or colored chart series.
- **Don't** use Faded Ink for anything the user must read to complete a task.
- **Don't** shrink labels on phones below 11px; phones are the primary device.
- **Don't** replace hairline shadows with solid 1px borders, or add drop shadows to flat page content.
- **Don't** use the stamp display faces or Caveat outside stamps and collage labels.
- **Don't** lay a texture or print screen over text. The ink-press screen is for data marks only (timeline squares, heatmap cells); text stays clean.
