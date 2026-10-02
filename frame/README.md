# AvianVisitors e-ink frame

*The last 24h of birds, framed on the wall by your window.*

## 7.3" Spectra 6 on a XIAO EE04 (ESPHome)

This fork adds a second way to build the frame: a small 7.3" panel driven by
an ESP32 instead of a Raspberry Pi. The BirdNET server renders the frame and
the ESP32 just downloads and draws it.

```
BirdNET server (birdframe-server.timer, every 3 min, renders only when the birds change)
  screenshot collage → 480×800 portrait layout → dither to the 6 inks
  → ~/BirdSongs/Extracted/frame/{frame.png, frame-names.png, frame.json, preview*.png}
EE04 (ESPHome): fetch /frame/frame.json → new sig? → download PNG → draw → (sleep)
```

### Hardware

| Qty | Part |
|-----|------|
| 1 | Seeed 7.3" Spectra 6 ePaper, 800×480 (SKU 100064541) |
| 1 | Seeed XIAO ePaper Display Board EE04, ESP32-S3 Plus (SKU 100075670) |
| 1 | USB-C cable, or a 3.7 V LiPo on the JST 2.0 mm connector |

The panel uses the **50-pin** connector: set the EE04 jumper to 50-pin before
plugging it in.

### 1. Server

On the BirdNET machine:

```bash
cd ~/BirdNET-Pi/frame
./install-server.sh
```

It installs Chromium in `frame/.venv`, writes `~/.birdframe/config.toml`
(`output = "esphome"`, 480×800 portrait), and enables `birdframe-server.timer`.
Check the look at `http://<server>/frame/preview.png` (names off) and
`/frame/preview-names.png` (names on). Layout knobs are in
[`config.example.toml`](config.example.toml); after editing the config, force
a re-render with `frame/.venv/bin/python frame/display.py --config ~/.birdframe/config.toml --force`.

### 2. ESP32

Needs [ESPHome](https://esphome.io/guides/installing_esphome/) 2026.6 or newer on your computer.

```bash
cd frame/esphome
cp secrets.example.yaml secrets.yaml   # Wi-Fi and an OTA password
# set `server:` in the YAML to your BirdNET server's IP
esphome run birdframe-usb.yaml         # first time over USB
```

Use the server's IP rather than `birdnet.local`, and give the server a DHCP
reservation so the address doesn't change.

#### Building with Home Assistant's ESPHome add-on instead

The firmware is four files in [`frame/esphome/`](esphome/). Copy them into the
add-on's config folder, `/config/esphome/`, with the File editor or Studio
Code Server add-on (paste each one), or the Samba share add-on (drag them
into `config/esphome`):

| File | Needed |
|---|---|
| `birdframe-usb.yaml` | the device (or `birdframe-battery.yaml`; both use the name `birdframe`, so copy only one) |
| `common.yaml` | yes, pulled in by the device file |
| `birdframe.h` | yes, pulled in by `common.yaml` |
| `secrets.example.yaml` | no; add its three keys to the add-on's own `secrets.yaml` instead |

Keep them side by side in `/config/esphome/`, not in a subfolder; the includes
are relative. `common.yaml` shows up as an extra card in the dashboard;
ignore it.

1. Update the add-on to ESPHome 2026.6 or newer.
2. In the dashboard's **Secrets** editor (top right), add `wifi_ssid`,
   `wifi_password` and `ota_password` (any password you like). If your
   existing secrets use other names, change the `!secret` lines in the
   device YAML to match.
3. Check `server:` in the device YAML is your BirdNET server's IP.
4. Compile: on the `birdframe` card, **⋮ → Install → Manual download →
   Factory format**. This builds the firmware without the board attached,
   so you can do it before the hardware arrives. The first build downloads
   the ESP-IDF toolchain and takes a while; a host with 2 GB of RAM may be
   too small.
5. First flash, with the board on USB and its jumper on 50-pin:
   **Install → Plug into this computer**. That needs Chrome or Edge and
   Home Assistant opened over HTTPS. Over plain HTTP, open
   [web.esphome.io](https://web.esphome.io), **Connect**, and **Install** the
   factory `.bin` from step 4.
6. Later updates of the USB build: **Install → Wirelessly**. The battery
   build accepts them only for 5 minutes after KEY2; otherwise use USB.

| | `birdframe-usb.yaml` | `birdframe-battery.yaml` |
|---|---|---|
| Checks | every 3 min, stays connected | wakes every 30 min (2 h when the battery is low), deep sleeps between |
| Redraws | only when the server's image changes | same, plus a small empty-battery mark below ~3.5 V |
| KEY0 | redraw now | wake and redraw |
| KEY1 | toggle bird names | wake and toggle bird names |
| KEY2 | — | stay awake 5 min, to flash an update over Wi-Fi |
| Updates | over Wi-Fi any time | press KEY2 first, or use USB |

Bird names are on by default. KEY1's setting survives reboots and sleep.
The server renders both versions each time the birds change, so switching
takes a single redraw (~20 s).

A full Spectra 6 refresh takes about 20 seconds and the panel flashes while it
redraws; that only happens when the birds change.

**Troubleshooting:** a corrupted or striped image → uncomment
`data_rate: 10MHz` in `common.yaml`. Nothing ever draws and the logs show
the display stuck busy → check the 50-pin jumper and the ribbon cable. The
logs (`esphome logs birdframe-usb.yaml`) print the server's and the shown sig
on every check.

---

## Raspberry Pi + 13.3" Inky Impression (upstream)

A [Pimoroni Inky Impression 13.3"](https://amzn.to/4xlAWr3) (Spectra 6) mirroring the live collage. A Pi screenshots the site, mats it onto an A5 opening, and pushes to the panel, refreshing only when the birds change. Build one of your own at [theodore.net/projects/AvianVisitors#frame-ous](https://theodore.net/projects/AvianVisitors/#frame-ous).

![](https://theodore.net/assets/images/AvianVisitors/final.jpg)

---

### BOM

| Qty | Description | Price | Link |
|-----|-------------|-------|------|
| 1 | Raspberry Pi 3 A+ or Zero 2 W | ~$25-35 | [Amazon](https://amzn.to/49Xp58I) |
| 1 | 13.3" E Ink Display     | $299.99 | [Amazon](https://amzn.to/4xlAWr3) |
| 1 | A4 Wood Photo Frame    | $21.99 | [Amazon](https://amzn.to/3RWFbJE) |
| 1 | Long, Flat Micro USB Cable    | $7.99 | [Amazon](https://a.co/d/0a59rKSk) |
| 1 | Flat USB Brick    | $7.59 | [Amazon](https://amzn.to/3S4CtSs) |
| | **Total** | **~$365** | | |

The 3 A+ and Zero 2 W are both tested and set up identically; any Pi with the 40-pin header that runs 64-bit Raspberry Pi OS works. The printed backing pressure-fits either board.

CAD + 3d print files can be found in [`hardware/`](hardware/).

### Kits

I offer the frame and the bird mic as separate electronics kits. I put up a store for some of my open-source projects and will soon be able to offer kits cheaper than buying all the components individually, once I start buying in bulk.

- [Frame kit](https://theodore.net/store/avian-visitors/)
- [Bird mic kit](https://theodore.net/store/avian-mic/)

---

## 1. Flash the SD card

Flash an sd card with Raspberry Pi OS Lite (64-bit) via [Raspberry Pi Imager](https://www.raspberrypi.com/software/). In the customisation dialog set:

- Username
- WiFi SSID + password
- Hostname: `birdpic`
- Enable SSH with password auth

Then install in Pi and power up.

## 2. Run the installer

```bash
ssh <your-username>@birdpic.local
sudo apt update && sudo apt install -y git
git clone https://github.com/Twarner491/AvianVisitors
cd AvianVisitors/frame
```

Pick how the frame gets its birds:

```bash
# Pair with your bird mic on the same network (birdnet.local). The default.
./install.sh

# No microphone: draw the collage from BirdWeather for any ZIP code.
./install.sh --bird-weather --zip 94107

# No microphone: follow one public BirdWeather station exactly.
./install.sh --station-id 12345

# Bird mic hosted at a public URL: point the frame straight at it.
./install.sh --image-url https://bird.onethreenine.net/frame.png?k=YOUR_FRAME_KEY
```

Each one enables SPI + I2C, installs the deps and a systemd timer, writes `~/.birdframe/config.toml`, and reboots once to bring SPI up. Full options live in [`config.example.toml`](config.example.toml). The station ID is the public number at the end of a BirdWeather station-page URL, not its upload token. ZIP mode summarizes nearby stations and can use fallbacks; station mode shows only that station and fails rather than substituting another source.

The default layout matches the A5 opening in the frame listed above. If you use a different mat or a bare panel, set `opening` in `~/.birdframe/config.toml`; `0.7071` preserves the current A5 dimensions, while values up to about `0.98` use more of the panel. This one setting scales a fixed 1:sqrt(2) opening, not width and height independently. For a B5 opening, `0.84` is a useful starting point, but check it against your physical mat.

Bird names are off on the frame by default. Turn them on or off at any time; the command saves the preference and requests an immediate refresh:

```bash
birdframe-names on
birdframe-names off
```

Set `shoot_title = ""` in `~/.birdframe/config.toml` if you want to hide only the frame title.

For an `--image-url` frame, the command adds `labels=1` or `labels=0` to the source URL. The source must honor that setting; otherwise its image will not change.

BirdWeather mode renders on the Pi from this repo's illustrations on GitHub, so there is no image set to copy over. In ZIP mode, postal codes with no station nearby fall back to the closest ones. If you are far from any BirdWeather station, add `--ebird-key <key>` (a free key from [ebird.org/api/keygen](https://ebird.org/api/keygen)) and the frame fills from eBird sightings instead. Exact station mode has no geographic or eBird fallback.

The bundled illustrations center on the western U.S. If birds for your ZIP or station aren't in the set you cloned, the installer flags them and the frame skips them until they exist. To generate them, run [`generate_illustrations.py`](generate_illustrations.py) on a laptop or workstation (it uses the same rembg cutout as the rest of the pipeline, which the Pi can't fit in memory), passing your source and a paid Google Gemini key, then commit the new cutouts or copy them to the Pi:

```bash
python3 generate_illustrations.py --zip 10001 --gemini-key YOUR_GEMINI_KEY
# or for one station
python3 generate_illustrations.py --station-id 12345 --gemini-key YOUR_GEMINI_KEY
```

It generates only the species you're missing. `--country` supports non-US postcodes, and `--sample` controls how many top species are checked.
