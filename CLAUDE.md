# Notes for agents

A personal fork of AvianVisitors (an overlay on BirdNET-Pi). Read `PRODUCT.md`
(who it's for, scope, principles) and `DESIGN.md` (visual system) before any UI
work; `README.md` is the user-facing manual. Upstream is
`Twarner491/AvianVisitors` and keeps changing, so keep diffs mergeable.

## Working agreement (standing rule from the owner, 2026-10-09)

- **Commit often, without asking.** Each finished and checked step (the
  "done" list below is all true) gets its own commit on `avian-visitors`.
  Don't end a turn with "want me to commit?". Before this rule the owner spent
  about one message in five on commit/push/docs requests.
- **Push only when the owner says so.** Commits stay local until then; say in
  a line what is committed and not yet pushed.
- **Before every push, update the docs for agents**, then commit that:
  - this file, for anything a future agent would otherwise rediscover the hard
    way (how something works, a trap you hit, a new tool or check);
  - `README.md` for anything the owner would notice;
  - `DESIGN.md` for new UI patterns.

  Read the unpushed commits (`git log @{u}..`) to see what needs writing up.
- Still ask first for anything destructive or outside the repo that isn't
  covered here (deleting real data, changing the network, the HA box, or the
  frame hardware).

## Definition of done

Before committing a step (the owner found 13 problems in 60 commits
themselves, almost all from the first four gaps here):

1. **Tested the way the owner uses it.** APIs through Caddy as the web user
   (`curl http://127.0.0.1/avian/api/...`), not by running PHP or a helper as
   `avian`. HTTPS-only features through https://merlin.streamvine.app.
2. **Looked at it.** For any UI change, `dev/shot.sh --theme dark <path>`
   (phone by default, `--device both` for desktop too) and read the PNG; zoom
   into edges for anything drawn over a background. The owner uses **dark
   mode only** (the frame is the one light surface): a pale halo round the
   bird art was invisible in light shots and obvious to them. It exits 1 on
   any page or console error.
3. **Made it live.** `dev/sync-live.sh` installs changed root helpers,
   regenerates Caddy when its generator changed (and shows the diff), and
   warns about a missing `?v=` bump, an endpoint missing from the Caddy
   allow-list, a broken `birdnet.conf` symlink, or leftover fake detections.
   It must print "live copy matches the checkout".
4. **Test data gone.** `birdnet/bin/python dev/fake-detection.py clean`.
5. Tests pass (`birdnet/bin/python -m pytest -q tests`), with the one known
   failure noted below.

When the owner reports a bug, the fix includes whatever would have caught it:
a test, a `dev/sync-live.sh` check, or a line in this list.

## Sessions

- One feature or topic per session; the owner can `/clear` between them. This
  file makes a cold start cheap. Very long sessions (25 h, 767 tool calls)
  ended up compacted and lost detail.
- Work handed over from another agent (the HA box, the frame) arrives pasted.
  Anything it teaches about this station belongs in this file, not only in the
  chat.
- `python3 dev/workflow-metrics.py --since <date>` measures how the workflow
  is going against the 2026-10-09 baseline in its docstring; rerun it every
  couple of weeks.

## Workflow

- Work directly on `avian-visitors` (the fork's default branch). No
  feature branches or PRs, and there is no CI on the fork.
- git has no identity on the station: commit with
  `git -c user.name=bobbleheadhobo -c user.email=supersoup4@gmail.com commit ...`.
- The station serves straight from this checkout (the webroot
  `~/BirdSongs/Extracted` is symlinks into it), so a saved edit is live at once.
  Don't switch branches casually.

## Checking your work

- `birdnet/bin/python -m pytest -q tests`. One failure is expected on the
  station: `test_diagnostic_redaction.py::...stale_logs` (the user can't read
  `/etc/caddy/Caddyfile`). The bundle-manager tests point
  `STATION_RUNTIME_PATH` at a missing file, and their job workers start
  through `WORKER_SHIM`, because a provisioned station's root-owned
  `/usr/share/avian-visitors/bundles/station.json` reads as unsafe to them.
- PHP: `php -l <file>`. Bash: `bash -n <file>`.
- Screenshots: `dev/shot.sh` drives the headless Chromium already in
  `~/.cache/ms-playwright` through a playwright venv kept in
  `~/.cache/avian-dev-venv`. Don't rebuild one in the scratchpad, and don't
  download another browser without asking. Options: `--click <selector>`,
  `--scroll-to <selector>`, `--theme dark`, `--full`; shots go to
  `/tmp/avian-shots`.
- There is no Node, so a page error from `dev/shot.sh` is the main JS check.
  For a pure syntax check: `pip install esprima` into the dev venv and
  `esprima.parseScript(open('avian/frontend/apt.js').read())`.
- Never run heavy builds here (4 GB LXC; `/tmp` is RAM). The frame firmware is
  built in Home Assistant via `frame/esphome/push-to-ha.sh`.

## Layout

- `avian/frontend/`: static site, no build step. `index.html`, one large
  `apt.js` (a single IIFE), `styles.css`. **Bump `?v=rNNN` in `index.html`**
  for `apt.js` / `styles.css` whenever you change them, or phones keep the old
  copy.
- Frame layout on the 7.3" panel: `shoot_collage_vh` (75) and
  `shoot_cluster_ybias` (1.6) in `frame/config.station.toml` (symlinked as
  `~/.birdframe/config.toml`) pack the birds into a tall box to fill it.
  `frame/display.py --preview out.png` renders without pushing.
- The battery frame's dawn, day and night check intervals are set on the site
  (Settings > Frame; quiet hours are config.toml only now). They travel in
  `frame.json` as `check.{dawn,day,night}` (seconds), written by `display.py`
  on each render, and the frame keeps them in flash (`check_s`); without them
  it uses the YAML's `sleep_*`. The window offsets are copied into
  `web_settings.json` `device` so `frame.php` can show today's clock times;
  a test keeps both copies equal to the YAML. Low battery still floors
  wakes at 2 h.
- Battery level's curve is fitted at the top to this frame: a full charge
  reads 4.14 V at the next wake (not 4.2), so 4.14 V = 100%.
- The battery frame's wake schedule follows the sun (`frame/esphome/birdframe/schedule.h`)
  and its battery level and days left are in `birdframe/battery.h`; both are
  plain C++ so they can be tested here (`describe_sleep` words Home
  Assistant's Check rate; Next check says when the coming wake is due, so
  "Last check is hours old" at night is the frame asleep, not offline): `tests/test_frame_headers.py` builds it with
  `zig c++` from the `ziglang` wheel in `~/.cache/avian-dev-venv` (skips without a
  compiler). ESPHome lambdas themselves only compile in Home Assistant, and
  `esphome config` doesn't check them, so keep logic in headers. A new file in
  `birdframe/` needs adding to the `files` lists in `push-to-ha.sh`.
  `~/.cache/esphome-venv/bin/esphome compile --only-generate <yaml>` (light: no
  toolchain) writes the C++ to `.esphome/build/birdframe/src/main.cpp` beside the
  YAML; read it to check lambdas and globals. Globals are declared there
  *before* the `includes:` headers, so a global can't have a type from our
  headers (the Home Assistant build failed on that): use a built-in type such
  as `int32_t[20]` and convert in the header (see `battery.h`). An array
  global's `initial_value` must be `"{0, 0}"`: `"{}"` and `"{0}"` also fit the
  scalar constructor and are ambiguous.
- EE04 board (rev 1.2 schematic: files.seeedstudio.com/wiki/Epaper/EE04/
  XIAO_ePaper_Display_Board_EE04_SCH_V1.2.pdf; `pypdf` text extraction works, there's no
  poppler; `pymupdf` renders pages to PNG) charges with an SY6974B, an I2C switching
  charger. Its I2C and /INT lines (SY_SDA/SY_SCL/SY_INT) end at pull-ups on the power
  sheet and never reach the XIAO, STAT only drives the green LED, and D+/D- go to
  unfitted test points: so the firmware can't see charging, and the charger runs on its
  power-on defaults. XIAO pins: GPIO1 BAT_ADC, GPIO2/3/5 keys (printed KEY1/2/3 on the board; Seeed's wiki
  says KEY0-2, and so did these docs until the owner noticed), GPIO4 EDP_BUSY, GPIO6
  ADC_EN, D6 PWR_EN, GPIO38 EDP_RES. Seeed doesn't publish the charge current.
  The owner's first LiPo had its JST plug reversed: Battery voltage read 0 V or the
  charger's ~4.2 V while it neither ran nor charged. Suspect that before firmware.
- `display.py`'s change signature includes the station's art tag: the ETag
  of `bundle-assets.php` (active bundle, its selection, the included art's
  content revision), fetched each poll with `If-None-Match` so an unchanged
  station answers 304. A bundle switch or a newly generated bird therefore
  redraws the frame at the next poll. The last tag is in `state.json` `art`;
  a failed check keeps it. Tests that run `display.run` must mock
  `station_art_tag`, or they wait on DNS for the configured `base_url`.
- The site's day starts at `RESET_HOUR` (4am here, when `RESET_AT_MIDNIGHT=1`): no birds
  are heard here between 20:00 and 05:00, so the frame keeps the night's birds.
- `frame/shoot.py` screenshots the site for the wall frame and rewrites a few
  collage lines in `apt.js` (`xBias`, `yBias`, `countExp`, `pad`,
  `LABEL_MIN_PX`). If those lines change shape it refuses to render and the
  frame quietly goes stale (it did for two days); `tests/test_frame_shoot.py`
  checks them. Frame logs: `journalctl -u birdframe-server`.
- Bird art (`avian/assets/illustrations/<slug>.png`, `-2` = flight) is served
  by `cutout.php` with a day of cache, under a URL that only changes with the
  species' entry in `ART_REVISIONS` in `apt.js`; `dims.json`/`masks.json`
  with `TABLE_VERSION`. Changing art without bumping these leaves browsers on
  the old picture (`dev/sync-live.sh` warns). New art from outside the
  generator: run it through `generate_one.chroma_cut`, then
  `build_masks.py --add <slug>` as `caddy`. Included art keeps its plain
  `cutout.php` URL keyed by `ART_REVISIONS` (no `bundle=`/`content=`):
  binding it to the content revision re-downloads every bird whenever one is
  added and gives open tabs 409s. Only downloaded bundles use revision-bound
  URLs. Since the v1.3 merge build_masks
  takes `/run/lock/avian-generation.lock` itself and commits a new content
  revision (`/var/lib/avian-visitors/included-art.revision`), so don't hold
  the lock around it (it deadlocks). A failed run leaves the revision marked
  invalid and the art unavailable until a build_masks run completes.
- Illustration bundles (upstream v1.3): Settings > Station > Bird bundle, or
  `sudo avian-bundle use '<id>'`. `bundles.php` runs jobs through
  `avian-bundle-control` (`avian/scripts/bundle_manager.py` installed as
  root); downloaded sets live under `/var/lib/avian-visitors`. Our own art
  (generated or hand-made) belongs to the included Japanese Woodblock set,
  and generate refuses while another bundle is active. Upstream's tests
  assume 333 species and their own admin session format; ours has 336 and
  v5 sessions, and those tests are adjusted for it.
- `avian/api/*.php`: JSON endpoints. **A new endpoint must be added to the
  `@unknownAvianApi` allow-list in `scripts/update_caddyfile.sh`**, then
  Caddy regenerated (below), or it returns 404.
- `scripts/*_control.sh`: root-owned helpers, installed to
  `/usr/local/sbin/avian-*` by `install_avian_controls` in
  `scripts/install_services.sh` (the source-to-name map is there).
- `scripts/utils/notifications.py`: Apprise alerts; message template is
  `body.txt`.
- `dev/`: tools for agents and the owner (screenshots, sync, fake detections,
  workflow metrics). Not installed or served anywhere. Don't put dev tools in
  `scripts/`: that folder is symlinked into `/usr/local/bin` and the webroot.

## The hardened web server (read before adding admin features)

PHP runs as `caddy`. It has no general sudo and **cannot write `birds.db`**
(owned by `avian`, 0644) or most of the checkout. That is why the stock
BirdNET-Pi pages ("classic") can't delete, relabel or save settings here.
Privileged work goes through a helper with fixed, validated actions:

- `scripts/admin_control.sh` → `avian-admin-control`. The sudoers entry
  (`security_refresh.sh`) allows **any arguments**, so a new action that takes
  an argument goes here, validated inside the `case "$action"` block. Run
  data work as the BirdNET-Pi user (`runuser -u "$birdnet_user" --`).
- `scripts/station_control.sh` → `avian-station-control`. Takes **exactly one
  fixed action**, and each is listed in sudoers in `security_refresh.sh`; a
  new one needs a sudoers line and a security refresh.
- PHP calls a helper with `sudo -n /usr/local/sbin/avian-...` (see
  `run_admin_control` in `avian/api/config.php`, or `detection-delete.php`).

PHP-FPM clears the environment, so `getenv('PATH')` is empty in endpoints:
use absolute paths or a fallback (see `search_path()` in `generate.php`).
Anything a feature needs in `/run` is gone after a reboot; give it a
`tmpfiles.d` entry (the generation and bundle-export locks are in
`/etc/tmpfiles.d/avian-bundle-locks.conf`, written by `install_avian_controls`).

**After editing a helper, the installed copy is stale: run
`dev/sync-live.sh`.** It installs every helper that differs from the repo,
and the bundle manager's root copies (`avian-bundle-control`,
`/usr/local/bin/avian-bundle`, `bundle_species.py` and `catalog-v1.json` in
`/usr/share/avian-visitors/bundles`).
Never run `avian-caddy-refresh` by hand before installing the repo copy: a
stale installed copy rewrites the Caddyfile from its old route list and drops
routes (it dropped `manifest.php` once). On another station the owner's route
is Tools → Reinstall services.

Tools → Reinstall services (`reinstall_services.sh`) refuses to run here: it
requires `origin` to be Twarner491's repo and verifies against upstream
history. Its root-side setup (bundle sandbox user, `avian-bundle-control`,
`/usr/local/bin/avian-bundle`, tmpfiles locks) lives in
`install_avian_controls` in `install_services.sh`; run just that function as
root by sourcing the functions between `ensure_bundle_sandbox_user()` and
`install_birdnet_analysis()` with `birdnet.conf` loaded, then
`avian-security-refresh` (sudoers, the bundle-export PHP-FPM pool, the art
revision), then `avian-caddy-refresh`, in that order: the Caddy generator
stops if the bundle-export pool is missing. The system `python3` needs
`python3-pil` for the security refresh's art rebuild.

Merging upstream: do it in a worktree (`git worktree add`), not by switching
branches here, since the site serves from this checkout. Upstream also runs
tests under `frame/` and `avian/scripts/`
(`birdnet/bin/python -m pytest -q frame avian/scripts`); the Playwright ones
need `~/.cache/avian-dev-venv/bin/python -m pytest`.

Endpoint pattern: `require_once admin-auth.php`, then `avian_require_admin()`
(open on the direct LAN unless the LAN password gate is on; password-backed
through the public proxy) and, for writes, `avian_require_json_action()` (POST,
JSON, `X-Avian-Action: 1`). The frontend calls these with `adminFetch(...)`,
which handles locked or expired sessions; `adminAccessState === 'unlocked'`
says whether admin is open in this tab.

## Settings

- Station settings live in `birdnet.conf`. A new one needs the whitelist in
  `avian/api/config.php` **and** `valid_config_value` in `admin_control.sh`.
- `/etc/birdnet/birdnet.conf` is a **symlink** to the repo's `birdnet.conf`.
  Never `sed -i` the `/etc` path (it replaces the link with a copy); edit the
  repo file or use `--follow-symlinks`.
- Per-device (browser) preferences use `readLS`/`writeLS` with a
  `bird:<name>:v1` key, apply immediately and never enter the save bar (see
  the Atlas toggles and `deleteEverywhere()` in `apt.js`). Exclude their
  switches from the generic `.switch` wiring in `wireSettingsControls`.
- `renderAdminSettings()` builds the page as groups: `settingsHead('<group>')`
  then a `<section>`. Put a new setting in the group it belongs to (this
  device, station, detection, notifications, frame, access, connected
  services, recordings & storage).

## Alerts, deep links and deleting detections

- An alert's `$birdurl` is `/#sci=<sci>&rec=<file>` and `$friendlyurl` is
  `/?filename=<file>`. Both open the postcard and call
  `openPostcardRecording(file)`, which expands that row and marks it
  `data-alert`.
- On that row `addDeleteControl(row)` offers "not this bird? delete this
  detection" (and, with Settings → Delete from any recording on, on any
  expanded row while unlocked). It POSTs to `avian/api/detection-delete.php`
  → `avian-admin-control detection-delete <file>`, which removes the row, the
  mp3, its `.png`, any `By_Date/shifted/` copy and the empty species folder.
  "Also stop detecting" adds the species through `species-lists.php`
  (exclude list).
- Recordings live at `$EXTRACTED/By_Date/<Date>/<Com_Name with spaces→_ and
  ' removed>/<File_Name>`.
- Alerts are sent from inside `birdnet_analysis` (long-running), so a change
  to `scripts/utils/` is not live until `sudo systemctl restart
  birdnet_analysis`; `dev/sync-live.sh` doesn't do that.
- The alert photo is downloaded by `local_photo()` and kept in
  `~/.cache/avian/notification-photos/`, then attached as a file. Don't hand
  Apprise the Wikimedia URL: it tries once, and Wikimedia answers an uncached
  thumbnail with a 429, so the photo goes missing. Apprise logs any HTTP error
  as "A Connection error occurred retrieving HTTP configuration".
- To test alert features without waiting for a bird:
  `birdnet/bin/python dev/fake-detection.py add --notify` adds a FAKE- Mute
  Swan (out of range here, bundled illustration) and sends you a real alert
  for it. `... clean` removes every fake. Never add test rows by hand: unmarked
  fakes once sat in the owner's data for two days.

## Bird illustrations (Atlas "generate image")

- `avian/api/generate.php` spawns `avian/scripts/generate_one.py` as
  `caddy`: Gemini render on a cream ground (model in `pregen.GEMINI_URL`,
  now `gemini-3.1-flash-image`), `chroma_cut`, then `build_masks.py --add`.
  If Google retires the model (404), list what the key can see with
  `GET https://generativelanguage.googleapis.com/v1beta/models` (free).
- Gemini image models have **no free tier** ("limit: 0" 429); the worker
  says so and the button reads "gemini key needs billing for images".
- Prompt: `prompt.template.md`. Images: IMAGE 1 = Wikipedia photo of the
  target (anatomy), IMAGE 2 = look-alike not to copy (only some genera),
  IMAGE 3 = style (an existing robin illustration). Per-species fixes go in
  `species-notes.json` (appended as "Species-specific note: ...").
- `chroma_cut` floods the cream in from the edges. It needs a closed ink
  outline (the prompt asks for one); if a pale throat leaks anyway, a
  tighter cut that keeps >10% more bird wins. Edges get a colour matte that
  takes the ink colour beside each edge pixel; the old feather left a cream
  rim that showed in dark mode. Tests: `tests/test_chroma_cut.py`.
- The owner sometimes renders by hand in Gemini and pastes the images:
  name `<slug>.png` / `<slug>-2.png` (flight), process as in Layout above,
  then bump `ART_REVISIONS`. Check the species against the real bird
  before installing (wrong colours are common) and say so.

## This station (merlin)

- Proxmox LXC. The user browses at **https://merlin.streamvine.app** (their
  own reverse proxy, so every request counts as remote and needs admin
  unlocked); the LAN is plain http (192.168.0.112). HTTPS-only features must be
  tested through the proxy URL.
- Mic: ALSA dsnoop device `birdmic` (`~/.asoundrc`), `REC_CARD=birdmic`,
  `CHANNELS=1`; the user's PulseAudio units are masked on purpose.
- The `avian` user has passwordless sudo, so you can install helpers and
  regenerate Caddy yourself. Say what you changed outside the repo.
