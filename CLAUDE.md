# Notes for agents

A personal fork of AvianVisitors (an overlay on BirdNET-Pi). Read `PRODUCT.md`
(who it's for, scope, principles) and `DESIGN.md` (visual system) before any UI
work; `README.md` is the user-facing manual. Upstream is
`Twarner491/AvianVisitors` and keeps changing, so keep diffs mergeable.

## Workflow

- Commit and push straight to `avian-visitors` (the fork's default branch). No
  feature branches or PRs, and there is no CI on the fork. Ask before pushing
  unless the user said to.
- git has no identity on the station: commit with
  `git -c user.name=bobbleheadhobo -c user.email=supersoup4@gmail.com commit ...`.
- The station serves straight from this checkout (the webroot
  `~/BirdSongs/Extracted` is symlinks into it), so a saved edit is live at once.
  Don't switch branches casually.
- Update `README.md` for anything a user would notice, and `DESIGN.md` for new
  UI patterns.

## Checking your work

- `birdnet/bin/python -m pytest -q tests`. One failure is expected on the
  station: `test_diagnostic_redaction.py::...stale_logs` (the user can't read
  `/etc/caddy/Caddyfile`).
- PHP: `php -l <file>`. Bash: `bash -n <file>`.
- There is no Node and no browser on the station. To syntax-check `apt.js`,
  make a venv in your scratchpad, `pip install esprima`, and
  `esprima.parseScript(open('avian/frontend/apt.js').read())`. You can't render
  the page, so say so and ask the user to look.
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

**After editing a helper, the installed copy is stale.** Install it (`sudo
install -o root -g root -m 0755 scripts/admin_control.sh
/usr/local/sbin/avian-admin-control`) or use Tools → Reinstall services. The
same applies to `update_caddyfile.sh` → `avian-caddy-refresh`: install the repo
copy **before** running `sudo /usr/local/sbin/avian-caddy-refresh`, because a
stale installed copy rewrites the Caddyfile from its old route list and drops
routes. Check with `sudo diff /etc/caddy/Caddyfile.previous /etc/caddy/Caddyfile`.

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
- To test alert features without waiting for a bird: insert a throwaway row
  into `detections` (copy a real clip to a matching `By_Date` folder; an
  out-of-range species with a bundled illustration, e.g. Mute Swan, works), then
  call `notifications.sendAppriseNotifications(...)` with
  `APPRISE_NOTIFY_EACH_DETECTION` forced to `'1'` in `get_settings()`'s cached
  dict. Delete the row afterwards.

## This station (merlin)

- Proxmox LXC. The user browses at **https://merlin.streamvine.app** (their
  own reverse proxy, so every request counts as remote and needs admin
  unlocked); the LAN is plain http (192.168.0.112). HTTPS-only features must be
  tested through the proxy URL.
- Mic: ALSA dsnoop device `birdmic` (`~/.asoundrc`), `REC_CARD=birdmic`,
  `CHANNELS=1`; the user's PulseAudio units are masked on purpose.
- The `avian` user has passwordless sudo, so you can install helpers and
  regenerate Caddy yourself. Say what you changed outside the repo.
