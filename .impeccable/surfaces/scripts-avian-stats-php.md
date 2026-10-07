---
version: 1
slug: "scripts-avian-stats-php"
primary_target: "scripts/avian_stats.php"
related_targets: ["homepage/static/avian-stats.css","homepage/views.php"]
---

# Surface brief: classic Stats page

Scope: a new native stats view on the classic BirdNET-Pi side (views.php?view=Streamlit, nav label "Species Stats"), replacing the Streamlit iframe. Mode: Operate (exploring the household's own detection data). Audience: the owner, who loves stats; phone first, 1080p desktop second. Must cover trends over time, time of day, records and milestones, and a species deep dive. Constraints: Avian Visitors world (DESIGN.md), no fills behind reading text, 11px floor, server-rendered from birds.db (new PHP endpoints are not routed by Caddy), stock markup elsewhere untouched.

## Direction contract

THESIS: The calendar is the filter. A year of the window as ink squares leads the page; choosing a day, month or preset re-answers every panel for that span. It refuses the KPI-tile dashboard and Streamlit's sidebar-of-widgets.

OWN-WORLD: Paper and ink only; data marks are hard-edged ink squares and bars seamed by paper; serif for bird names and prose, tracked uppercase mono for labels and every figure; hairline ledgers; track-and-thumb presets; the bird's own Avian illustration is the only color.

STORY: The visitor sees the year at a glance, reads one sentence summarising the chosen span, sees how it trends and when the birds sing against real sunrise and sunset, checks the all-time records, then opens any species for its full story and best recording.

FIRST VIEWPORT: Title row with range readout and presets (7d, 30d, 90d, year, all). Below, the full-width 53-week calendar, selected span in full ink and the rest receding, month labels as links. Under it, the summary sentence with mono figures. On phones the calendar scrolls sideways, pinned to today.

FORM: Calendar spine, position 3 on the ordered list (dealt lead), seed key 18250e2e; folds in the dawn-chorus clock's sunrise/sunset framing for time of day.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
