# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
The owner's own household. They check their home BirdNET station from a phone (mostly) or a computer to see which birds have visited, browse what's been heard, and look up a species. This fork's UI work is for them, not for the wider AvianVisitors community, public visitors, or classrooms.

## Product Purpose
A personal fork (`bobbleheadhobo/avianvisitors`) of AvianVisitors, which is itself an overlay on BirdNET-Pi. A Raspberry Pi with a window microphone identifies birds by sound, and AvianVisitors turns those detections into a live illustrated collage with Stats, Atlas, and per-species postcards. Success means the household can glance at the station and enjoy it, and read it easily on a phone.

## Positioning
Detections show up as a hand-illustrated collage of the birds that actually visited this window, not as a table of detections. The stock BirdNET-Pi UI stays available at `/index.php` as the data-dense alternative.

## Operating Context
- Served from the station over the LAN by Caddy + PHP-FPM; the collage is at `/`.
- Mostly viewed on phones, sometimes on desktop.
- Upstream (`Twarner491/AvianVisitors`) keeps changing, and this fork pulls from it.
- An e-ink wall frame (`frame/`) shows the same collage but is out of scope for UI design work.

## Capabilities and Constraints
- In scope for design work: what you see in a browser on a phone or computer. That means the AvianVisitors home collage, Stats (by hour, most heard, life list, chart), Atlas (by family, alphabetical), postcards, and the surrounding chrome.
- Out of scope: the bird illustrations themselves (`avian/assets/`, generation pipeline), the e-ink frame, and stock BirdNET-Pi PHP pages.
- The frontend is static HTML/CSS/JS in `avian/frontend/` with PHP shims in `avian/api/`, and there's no build step. Keep it that way.

## Brand Commitments
- Keep upstream AvianVisitors' identity: its illustrations, paper/grain feel, stamps, and lowercase voice (e.g. "your birds"). Refine it, don't replace it, so the fork stays close to upstream.
- User preference: don't put a background (fills, pills, or plates) behind text. The owner finds it harder to read. Readability has to come from the type and color, not from a backing shape.

## Evidence on Hand
- Live station data (real detections) and 666 bundled illustrations (333 species, perched and flight).
- Screenshots: `docs/thumb.png`, `docs/overview.png`, `docs/releases/`.
- There are no testimonials, metrics, or third-party claims, and none should be fabricated.

## Product Principles
1. Phone first. Every view has to read well and work by thumb on a phone before desktop gets attention.
2. Make it easy to read. Clear text on the page beats decorative treatment.
3. Stay close to upstream. Keep changes mergeable, and change appearance only where it improves things for this household.
4. The birds are the star. UI chrome supports the illustrations and data, never competes with them.
