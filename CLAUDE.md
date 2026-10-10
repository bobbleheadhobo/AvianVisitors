# Notes for agents

A personal fork of AvianVisitors (an overlay on BirdNET-Pi). Read `PRODUCT.md`
(who it's for, scope, principles) and `DESIGN.md` (visual system) before any UI
work; `README.md` is the user-facing manual. Upstream is
`Twarner491/AvianVisitors` and keeps changing, so keep diffs mergeable.

## Working agreement (standing rule from the owner, 2026-10-09)

When a piece of work is finished and checked (the "done" list below is all
true), **update the docs, commit and push to `avian-visitors` without asking**.
Don't end a turn with "want me to commit?". The owner spent about one message
in five on commit/push/docs before this rule. Then say in one or two lines what
went live and what they should try. Still ask first for anything destructive or
outside the repo that isn't covered here (deleting data, changing the network,
the HA box, or the frame hardware).

"Docs" means both: `README.md` for anything the owner would notice, and this
file for anything a future agent would otherwise rediscover the hard way
(`DESIGN.md` for new UI patterns).

## Definition of done

Before calling something done (the owner found 13 problems in 60 commits
themselves, almost all from the first four gaps here):

1. **Tested the way the owner uses it.** APIs through Caddy as the web user
   (`curl http://127.0.0.1/avian/api/...`), not by running PHP or a helper as
   `avian`. HTTPS-only features through https://merlin.streamvine.app.
2. **Looked at it.** For any UI change, `dev/shot.sh <path>` (phone by
   default, `--device both` for desktop too) and read the PNG. It exits 1 on
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

- Commit and push straight to `avian-visitors` (the fork's default branch). No
  feature branches or PRs, and there is no CI on the fork.
- git has no identity on the station: commit with
  `git -c user.name=bobbleheadhobo -c user.email=supersoup4@gmail.com commit ...`.
- The station serves straight from this checkout (the webroot
  `~/BirdSongs/Extracted` is symlinks into it), so a saved edit is live at once.
  Don't switch branches casually.

## Checking your work

- `birdnet/bin/python -m pytest -q tests`. One failure is expected on the
  station: `test_diagnostic_redaction.py::...stale_logs` (the user can't read
  `/etc/caddy/Caddyfile`).
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

**After editing a helper, the installed copy is stale: run
`dev/sync-live.sh`.** It installs every helper that differs from the repo.
Never run `avian-caddy-refresh` by hand before installing the repo copy: a
stale installed copy rewrites the Caddyfile from its old route list and drops
routes (it dropped `manifest.php` once). On another station the owner's route
is Tools → Reinstall services.

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
- To test alert features without waiting for a bird:
  `birdnet/bin/python dev/fake-detection.py add --notify` adds a FAKE- Mute
  Swan (out of range here, bundled illustration) and sends you a real alert
  for it. `... clean` removes every fake. Never add test rows by hand: unmarked
  fakes once sat in the owner's data for two days.

## This station (merlin)

- Proxmox LXC. The user browses at **https://merlin.streamvine.app** (their
  own reverse proxy, so every request counts as remote and needs admin
  unlocked); the LAN is plain http (192.168.0.112). HTTPS-only features must be
  tested through the proxy URL.
- Mic: ALSA dsnoop device `birdmic` (`~/.asoundrc`), `REC_CARD=birdmic`,
  `CHANNELS=1`; the user's PulseAudio units are masked on purpose.
- The `avian` user has passwordless sudo, so you can install helpers and
  regenerate Caddy yourself. Say what you changed outside the repo.
