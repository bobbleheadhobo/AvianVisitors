# AvianVisitors

*A live bird collage from your window.*

See it running at [bird.onethreenine.net](https://bird.onethreenine.net).

<img alt="avianvisitors collage" src="docs/thumb.png" />

---

## BOM

| Qty | Description | Price | Link | Notes |
|-----|-------------|-------|------| ----- |
| 1 | Raspberry Pi (4B / 5 / 3A+ / Zero 2W) | ~$25-80 | [Amazon](https://amzn.to/43yLDZJ) | [See note for 512 MB Pis](https://github.com/mcguirepr89/BirdNET-Pi/wiki/RPi0W2-Installation-Guide) |
| 1 | Micro SD Card (≥32 GB) | ~$10 | [Amazon](https://amzn.to/4eGy7te) | |
| 1 | USB lavalier microphone | $16.95 | [Amazon](https://amzn.to/4vLSaMK) | |
| 1 | Pi power supply | ~$10 | - | |

Optional: a [Gemini API key](https://aistudio.google.com/apikey) to restyle illustrations, an [eBird API key](https://ebird.org/api/keygen) to filter species by region.

### Kits

I offer the bird mic and the wall frame as separate electronics kits. I put up a store for some of my open-source projects and will soon be able to offer kits cheaper than buying all the components individually, once I start buying in bulk.

- [Bird mic kit](https://theodore.net/store/avian-mic/)
- [Frame kit](https://theodore.net/store/avian-visitors/)

---

## 1. Flash the SD card

Use [Raspberry Pi Imager](https://www.raspberrypi.com/software/). Pick Raspberry Pi OS Lite (64-bit). In the customisation dialog set:

- Username
- WiFi SSID + password
- Hostname: `birdnet`
- Enable SSH with password auth

Plug the USB mic into the Pi. Place the capsule in a window or mount it outside. Boot.

---

## 2. Run the installer

Installer assumes passwordless sudo (Raspberry Pi OS Lite default - if you've tightened it, run `sudo raspi-config` -> *System Options* -> restore the default first).

```bash
ssh <your-username>@birdnet.local
curl -s https://raw.githubusercontent.com/Twarner491/AvianVisitors/avian-visitors/newinstaller.sh | bash
```

Clones this fork, installs BirdNET-Pi, symlinks the AvianVisitors overlay into the Caddy web root. Takes 20-40 minutes. Reboots when done.

Collage: `http://birdnet.local/`. Stock BirdNET-Pi UI: `http://birdnet.local/index.php`. The menu button in the top right opens an admin overlay with Settings, System, Logs, and Tools.

Stock BirdNET-Pi pages still render, but privileged legacy controls are not enabled. Use the Avian Visitors menu for the station controls it exposes, and SSH for remaining maintenance.

Optional Google Drive backups are set up under **Settings → Nightly Drive backup**. Local cleanup stays unavailable until an archive run has been verified.

### Local admin access

Optional password protection for local administrator controls can be enabled in **Settings**. Public bird pages remain available without signing in, while live audio is unavailable when protection is on.

If no password is configured, or the state is missing or invalid, recover it from an SSH session:

```bash
sudo /usr/local/sbin/avian-admin-control password-reset
```

The command prompts privately for a new password. Return to **Settings** after it finishes.

#### Staying signed in

An unlocked admin session normally locks after 30 minutes idle. Tick **keep me signed in on this device** under the password to skip that on a phone or computer you trust; the menu's lock button then reads **sign out this device**.

- The device keeps a random HttpOnly cookie, Secure on any public hostname. The station stores only its hash, one file per device in `/var/lib/avian-visitors/devices`.
- **Settings → Stay signed in for** sets how long a device may go unused before it signs out: 1 day, 7 days, 30 days, 90 days (default), 6 months, or 1 year. Use keeps it alive, but never past a year from the password sign-in.
- **Settings → Signed-in devices → sign out every device** ends every remembered device and every open admin session at once. The device that pressed it stays in for that visit only.
- Changing the admin password, or switching **Require password on local network**, also signs every device out. Backup, restore, and password changes still ask for the password on a remembered device.

### Admin pages

Open **menu** (top right). Away from home you unlock it with the admin password first.

- **Live audio** - listen to the window mic, with a line saying who else is listening. Away from home see [Listening away from home](#listening-away-from-home).
- **Settings** - grouped under headings: this device, station, detection, notifications, frame, access, connected services, recordings & storage. Changes are held until you press **Save** in the bar at the bottom (Discard puts everything back; leaving with unsaved changes asks first). Sliders move only when you drag the thumb, and each shows a `default` link when it is off its default. **Notifications** sets where alerts go (Apprise URLs, e.g. a Discord webhook) and when: first of each species each day, a new species for the station, every detection, the weekly report. A detection that matches several of these sends one alert that names every reason, with the bird's picture attached. Use **send test** to check a target.
- **Settings → detection → Daily reset** makes the 1H/12H/24H views (and the frame's "heard today") start no earlier than the day's reset. **Reset time** picks when the day starts: 12am, 3am, 4am or 5am. A reset before dawn keeps last night's birds up until just before the dawn chorus.
- **System** - health at a glance: a green outline is passing, amber is getting close, red is failing. It flags a mic-less station (`no microphone`) and a recorder writing silence (`silent`), lists every service with what it does and a restart button, and shows the time since the last detection.
- **Logs** - service journals, plus **live listening**: every remote listening session.
- **Tools** - pull the latest code, reinstall services, download detections / recordings, take a **full backup** or **restore** one, edit the **species lists** (never log / only log / always allow), and **reboot** or **shut down** the station. Backup and restore ask for the admin password even on the home network: a backup holds the station's API keys and webhooks, and a restore replaces its config.
- **classic birdnet-pi** - the original BirdNET-Pi pages in a new tab.

### Deleting a wrong detection

When the analyzer names a bird that was never there, open the alert's **See this bird** (or **Listen here**) link. The postcard opens on that recording, and under its spectrogram is **not this bird? delete this detection**. Confirm with **delete**; tick **also stop detecting** first to add the species to the never-log list (undo it in **Tools → species lists**).

- It removes the detection from the database with its recording, spectrogram and any frequency-shifted copy. A bird with no detections left drops out of the collage, Atlas and Stats. A copy already uploaded to BirdWeather stays there.
- It needs admin controls unlocked; away from home, unlock the menu first.
- By default only a recording opened from an alert offers it. **Settings → this device → Delete from any recording** offers it on every recording you open while unlocked, on that phone or computer.
- The web server cannot write the database itself; the delete runs through `avian-admin-control detection-delete`, which checks the file name and acts as the BirdNET-Pi user.

### Classic BirdNET-Pi pages

The classic pages are for viewing (overview, detections, charts, stats, recordings, logs). Their **Settings** and **Advanced** pages cannot save on this fork: stock BirdNET-Pi gives the web server passwordless root for anything, and AvianVisitors removed that, leaving only a few root-owned helpers with fixed actions. Change settings in the app instead.

From the app's menu the classic pages open with a signed two-hour pass (no second login), issued only to an admin session unlocked with the password; locking the admin controls ends it. Opened any other way they ask for user `birdnet` and the admin password. With **Require password on local network** on, they are closed entirely.

### Educators mode

Educators mode is an optional profile for the BirdNET-Pi website. It adds a fifth menu page for starting, pausing, organizing, and reviewing listening periods. Enable it after installation over SSH:

```bash
sudo /usr/local/sbin/avian-educators enable
```

New stations can also install with the profile enabled:

```bash
curl -fsSL https://raw.githubusercontent.com/Twarner491/AvianVisitors/avian-visitors/newinstaller.sh | bash -s -- --educators
```

Listening periods scope the Collage, Stats, Atlas, and available detection clips without copying or protecting audio files from normal retention. Saved period and folder exports require a direct local connection. See [the Educators guide](docs/educators.md) for the full workflow and privacy details.

### Updating an existing station

For the first v1 update, or if an interrupted older update left the admin helper missing and Tools locked, keep the existing checkout and run this verified setup over SSH:

```bash
upgrade=$(mktemp "$HOME/avian-v1-upgrade.XXXXXX")
curl -fsSL https://raw.githubusercontent.com/Twarner491/AvianVisitors/avian-visitors/scripts/bootstrap_v1.sh -o "$upgrade"
sudo bash "$upgrade"
rm -f "$upgrade"
```

After v1, use **Tools → Pull latest** or run:

```bash
cd ~/BirdNET-Pi
./scripts/update_birdnet.sh
```

The updater preserves custom bird artwork, generated mask data, and existing credentials. It stops if other tracked files have local edits. It verifies and stages one official release before changing the checkout. If applying that release is interrupted, keep the new checkout and resume the same prepared installation without fetching another release:

```bash
sudo /usr/local/sbin/avian-service-refresh
```

If the installed refresher itself is missing or unsafe, use the verified setup command above. An already-running older updater cannot gain the new preparation checks until it has finished or been recovered this way. Only use `sudo /usr/local/sbin/avian-admin-control password-reset` for missing credentials after the helper is installed successfully.

---

## 3. Illustration bundles

By default, new stations use the included Japanese Woodblock bundle, which covers Western North America. If you live elsewhere or want a different style, open **Settings → Station → Bird bundle** or browse available bundles at [avianvisitors.com/bundles](https://avianvisitors.com/bundles). On the public site, **Use on my local station** is the main action. Its adjacent command button opens and copies one line. After SSHing into a station or BirdFrame, run:

```bash
sudo avian-bundle use '<BUNDLE_ID>'
```

With a saved location, the device downloads the bundle's birds expected there throughout the year. Coordinates are not sent to the bundle catalog. Without a location, it downloads the full set; add `--all-species` to request the full set explicitly. In Settings, **Refresh local birds** reapplies a changed location to the current downloaded bundle.

For a different look each day, turn on **Settings → Station → New bundle each day**. At the reset time (4am by default) the station switches to a random downloaded bundle, never the same one twice in a row. **Bundles to use** lists the downloaded bundles; untick any to leave out. Bundles you download later are included automatically. Your own generated birds only appear in Japanese Woodblock, and **generate image** only works on days that bundle is active.

To share your own set, open **Tools → Your data → Export bundle**. Review the local illustrations, download the upload-ready ZIP, then sign in at [avianvisitors.com/bundles](https://avianvisitors.com/bundles) and upload it. The station never uploads a bundle automatically.

### Restyle or generate your own

The repo includes 666 illustrations for 333 species, each perched and in flight. To restyle them or generate a set for your own region:

```bash
pip install -r ~/BirdNET-Pi/avian/scripts/requirements.txt
export GEMINI_API_KEY='your-key'  # image generation requires billing enabled

# generate on a cream ground, cut the ground off, rebuild the collage masks
python3 ~/BirdNET-Pi/avian/scripts/pregen.py --labels ~/BirdNET-Pi/model/labels.txt --force
python3 ~/BirdNET-Pi/avian/scripts/cutout.py
python3 ~/BirdNET-Pi/avian/scripts/build_masks.py
```

On an installed station, run all three commands in order. The first changed
PNG temporarily marks the included artwork unavailable; the final
`build_masks.py` call atomically republishes matching geometry and restores it.
Do not copy individual PNGs directly into the live illustration directory.

On a Pi with 4 GB of RAM or less, add `--model u2net` to the `cutout.py` command; the default model may be [OOM-killed](https://github.com/Twarner491/AvianVisitors/issues/17).

Filter to your region with `--ebird-region US-CA` (needs `EBIRD_API_KEY`). The full pipeline, prompt, reference images, and per-species tuning live in [`avian/scripts/README.md`](avian/scripts/README.md). Style lives in [`prompt.template.md`](avian/scripts/prompt.template.md).

**A bird with no illustration.** When the station hears a species outside the bundled set, open it in the Atlas and press **generate image** (admin unlocked, Japanese Woodblock as the active bundle, and a Gemini key in Settings). It draws both poses with Gemini's Nano Banana 2 (`gemini-3.1-flash-image`) in about a minute. The image model has **no free tier**: a key without billing gets the button message *gemini key needs billing for images*; turn billing on for the key's Google Cloud project (roughly 4 cents an image, two per bird; the site caps it at 20 runs an hour). If a species keeps coming out wrong (a yellow bill on a Black-billed Magpie, say), add a line for it to [`species-notes.json`](avian/scripts/species-notes.json) and generate again.

---

## 4. (Optional) Forward off your LAN

### Listening away from home

Behind a reverse proxy (Cloudflare Tunnel, Pangolin, ...) the raw `/stream` stays local-only. To listen remotely, turn on **Settings → Listen away from home**; the menu then offers **listen** once unlocked. Each remote session:

- is relayed only for an unlocked admin session, with a one-use 15-second grant;
- stops itself after 30 minutes (**keep listening** starts another), and is cut when the admin controls lock or the switch goes off;
- is logged (**Logs → live listening**) and announced through your notification targets: when it starts, every 20 minutes it continues, and on the fifth remote session of a day.

At most two people can listen remotely at once (each holds a PHP worker).

See [`avian/forwarding/`](avian/forwarding/) for three independent recipes:

- **Cloudflare Tunnel** for a public HTTPS URL.
- **Home Assistant REST sensor** that exposes the latest detection.
- **MQTT bridge** that publishes every new detection.

---

## Repo layout

```
avian/                  # everything we add to BirdNET-Pi
├── frontend/           # static HTML/JS/CSS for the collage
├── assets/             # 666 bundled illustrations + photo-cutout fallbacks
├── api/                # PHP shims served by BirdNET-Pi's PHP-FPM
├── scripts/            # generate -> cutout -> masks pipeline + prompt
└── forwarding/         # optional HA / MQTT / Cloudflare configs
frame/                  # optional e-ink wall display (Pi + Inky, or ESP32 + ESPHome)
dev/                    # tools for working on a live station (not installed or served)
```

Avian Visitors also maintains the root-level installer, security, test, and
release-documentation files used to ship those two trees. Other inherited
application files remain upstream BirdNET-Pi unless a release note says
otherwise.

On the station, `dev/` has the checks used before a change is called done:
`dev/shot.sh` screenshots pages as a phone or desktop and flags page errors,
`dev/sync-live.sh` installs changed root helpers and flags anything left stale,
and `dev/fake-detection.py` adds and removes marked test detections (with a real
alert, to test notification links). `CLAUDE.md` is the working guide for coding
agents.

---

## Wall frame

An optional e-ink frame puts the bird collage on a panel by your window. Build it from [`frame/`](frame/README.md). It can run off your own BirdNET mic, from BirdWeather around a ZIP code, or from one public BirdWeather station with `frame/install.sh --station-id <ID>`.

This fork also supports a smaller 7.3" Spectra 6 panel on a Seeed XIAO EE04 (ESP32, ESPHome): the BirdNET server renders the frame and the board just downloads and draws it, on USB power or a battery. See [7.3" Spectra 6 on a XIAO EE04](frame/README.md#73-spectra-6-on-a-xiao-ee04-esphome).

A frame that screenshots your station redraws at its next check after you change the station's bird bundle (or generate a new bird), even if the birds heard haven't changed.

---

## License

CC-BY-NC-SA-4.0, inherited from [BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi/blob/main/LICENSE). Non-commercial use only. See the [BirdNET-Pi README](https://github.com/Nachtzuster/BirdNET-Pi/blob/main/README.md) for full Cornell attribution.

---

- [Fork this repository](https://github.com/Twarner491/AvianVisitors/fork)
- [Watch this repo](https://github.com/Twarner491/AvianVisitors/subscription)
- [Create issue](https://github.com/Twarner491/AvianVisitors/issues/new)
