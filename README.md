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

### Admin pages

Open **menu** (top right). Away from home you unlock it with the admin password first.

- **Live audio** - listen to the window mic, with a line saying who else is listening. Away from home see [Listening away from home](#listening-away-from-home).
- **Settings** - changes are held until you press **Save** in the bar at the bottom (Discard puts everything back; leaving with unsaved changes asks first). Sliders move only when you drag the thumb, and each shows a `default` link when it is off its default. **Notifications** sets where alerts go (Apprise URLs, e.g. a Discord webhook) and when: first of each species each day, a new species for the station, every detection, the weekly report. Use **send test** to check a target.
- **System** - health at a glance: a green outline is passing, amber is getting close, red is failing. It flags a mic-less station (`no microphone`) and a recorder writing silence (`silent`), lists every service with what it does and a restart button, and shows the time since the last detection.
- **Logs** - service journals, plus **live listening**: every remote listening session.
- **Tools** - pull the latest code, reinstall services, download detections / recordings, take a **full backup** or **restore** one, edit the **species lists** (never log / only log / always allow), and **reboot** or **shut down** the station. Backup and restore ask for the admin password even on the home network: a backup holds the station's API keys and webhooks, and a restore replaces its config.
- **classic birdnet-pi** - the original BirdNET-Pi pages in a new tab.

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

For the first v1 update, keep the existing checkout and run:

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

The updater keeps generated mask data and stops if tracked files have local edits. If its service setup needs repair, use **Tools → Reinstall services** or run:

```bash
cd ~/BirdNET-Pi
./scripts/reinstall_services.sh
```

---

## 3. (Optional) Restyle the illustrations

The repo ships with 666 bundled illustrations (333 species, perched + flight). To restyle them or generate a set for your own region:

```bash
pip install -r ~/BirdNET-Pi/avian/scripts/requirements.txt
export GEMINI_API_KEY='your-key'  # image generation requires billing enabled

# generate on a cream ground, cut the ground off, rebuild the collage masks
python3 ~/BirdNET-Pi/avian/scripts/pregen.py --labels ~/BirdNET-Pi/model/labels.txt --force
python3 ~/BirdNET-Pi/avian/scripts/cutout.py
python3 ~/BirdNET-Pi/avian/scripts/build_masks.py
```

On a Pi with 4 GB of RAM or less, add `--model u2net` to the `cutout.py` command; the default model may be [OOM-killed](https://github.com/Twarner491/AvianVisitors/issues/17).

Filter to your region with `--ebird-region US-CA` (needs `EBIRD_API_KEY`). The full pipeline, prompt, reference images, and per-species tuning live in [`avian/scripts/README.md`](avian/scripts/README.md). Style lives in [`prompt.template.md`](avian/scripts/prompt.template.md).

See [illustration bundles](illustration-bundles.md) for pregenerated bundles shared by other folks in the community, or share your own for others to use!

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
```

Everything outside `avian/` and `frame/` is upstream BirdNET-Pi.

---

## Wall frame

An optional e-ink frame puts the bird collage on a panel by your window. Build it from [`frame/`](frame/README.md). It can run off your own BirdNET mic, from BirdWeather around a ZIP code, or from one public BirdWeather station with `frame/install.sh --station-id <ID>`.

This fork also supports a smaller 7.3" Spectra 6 panel on a Seeed XIAO EE04 (ESP32, ESPHome): the BirdNET server renders the frame and the board just downloads and draws it, on USB power or a battery. See [7.3" Spectra 6 on a XIAO EE04](frame/README.md#73-spectra-6-on-a-xiao-ee04-esphome).

---

## License

CC-BY-NC-SA-4.0, inherited from [BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi/blob/main/LICENSE). Non-commercial use only. See the [BirdNET-Pi README](https://github.com/Nachtzuster/BirdNET-Pi/blob/main/README.md) for full Cornell attribution.

---

- [Fork this repository](https://github.com/Twarner491/AvianVisitors/fork)
- [Watch this repo](https://github.com/Twarner491/AvianVisitors/subscription)
- [Create issue](https://github.com/Twarner491/AvianVisitors/issues/new)
