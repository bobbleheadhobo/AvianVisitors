---
target: classic BirdNET-Pi pages
total_score: 22
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
target_identity: "file:/home/avian/BirdNET-Pi/homepage/index.php"
target_fingerprint: "sha256:65b18c71a11d6136d48865622eda35c2757215ad9309fc8d3e56eb07c6571f02"
target_path: /home/avian/BirdNET-Pi/homepage/index.php
timestamp: 2026-10-06T06-20-35Z
slug: homepage-index-php
---
# Critique: classic BirdNET-Pi pages, after the Avian Visitors restyle (2026-10-06)

Method: dual-agent (A: design review · B: detector and browser evidence)

## Design Health Score: 22/40 (Acceptable)

| # | Heuristic | Score | Key issue |
|---|---|---|---|
| 1 | Visibility of System Status | 2 | On Tools sub-pages and Recordings-by-date, no nav item is marked current. Daily Charts shows a chart, then "No Charts For…". |
| 2 | Match System / Real World | 2 | "All 4 Last Updated", "+INF%", "-0%" shown in red, "Legacy view". |
| 3 | User Control and Freedom | 3 | The "collage" pill always gets you home. |
| 4 | Consistency and Standards | 2 | Three type systems on one page. "Confidence:87%" vs "CONFIDENCE: 87%". |
| 5 | Error Prevention | 2 | Destructive System Controls are the same pills as safe ones. Only the red text and confirm() guard them. |
| 6 | Recognition Rather Than Recall | 2 | Sort buttons and row icons have no text labels. |
| 7 | Flexibility and Efficiency | 3 | Search, date picker and deep links. |
| 8 | Aesthetic and Minimalist Design | 3 | Black spectrogram block and the 11-pill Tools grid are heavy. |
| 9 | Error Recovery | 1 | Settings overflow on phones, chart/no-chart contradiction, unlabeled empty bar. |
| 10 | Help and Documentation | 2 | Settings help can't be read on a phone. |

## Design Specificity Verdict
- **Reviewer's take:** the chrome is authored for Avian Visitors; the content is still stock. About 70% is specific to this product.
- **Detector, page files:** 26 findings, all in untouched upstream markup. 11 are broken-image false positives (sources set at runtime); 15 are radius/color advisories from stock inline styles.
- **Detector, new stylesheet:** clean apart from 2 token advisories (6px radius, 30px clamp endpoint).
- **Browser, from the restyle:** `--quiet` on `--paper-2` is 4.3:1 (placeholders, return pill, listen pill); uppercase `th` headers on Weekly Report; Settings `p` lines run about 94 characters.
- **Browser, upstream:** headings skip a level (h1 is an image only).
- **False positives:** the striped-background flag is the stock `dialog::backdrop`.

## Priority Issues
- **[P0] Settings renders at about 43% zoom on a phone.** The table is 909px wide at a 390px viewport. Fix: `table-layout: fixed`, `overflow-wrap: anywhere`, and `max-width: 100%` on inputs. Command: /impeccable adapt
- **[P1] Chart PNG is unreadable on phones, and the grey shades mean nothing.** Fix: one ink grey for all bars in `daily_plot.py`; on phones, scroll the chart sideways at about 760px wide, or link to Stats. Command: /impeccable adapt
- **[P1] The nav loses the current page on sub-pages and phones, and gives no hint that it scrolls.** Fix: `avian-classic.js` marks TOOLS / RECORDINGS as current; add an edge-fade mask. Command: /impeccable layout
- **[P2] Destructive actions aren't separated from safe ones.** System Controls grouping; danger shown as an outline; delete icon right beside open on detection rows. Command: /impeccable harden
- **[P2] Fill behind text and contrast misses.** `.updatenumber` is a filled chip; `--quiet` on `--paper-2` is 4.3:1; Settings line length. Command: /impeccable polish

## Persona Red Flags
- **Household member on a phone:**
  - Overview opens with a stats table, a chart and a backlog warning before any bird.
  - Weekly Report's red for falling counts reads as an error.
  - Best Recordings is a very long scroll of black boxes.
- **Casey:**
  - The nav is at the top and scrolls sideways.
  - Delete sits next to open.
  - Settings needs pinch-zoom.
- **Sam:**
  - Icons have only a title attribute.
  - Tap targets are under 24px.
  - 21 Settings inputs have no label.
  - Danger is shown by color alone.
- **Alex:**
  - 10 + 11 nav pills with no grouping.
  - Sort buttons are bare icons.
  - Settings has no jump links.

## Minor Observations
- **Today's Detections:** about 70px of empty space per row; the search box wraps on phones.
- **Overview:** phone headers wrap and the columns look uneven; on desktop, "Species detected today" wraps onto 3 lines.
- **System Controls:** the full commit hash is shown.
- **Naming:** "Best Recordings" vs "Species Stats" is confusing (stock wording).

## Questions
- Phone nav with about 4 views plus "more"?
- Birds first on the phone Overview?
- Keep the chart PNG, or link to the collage's Stats page?
