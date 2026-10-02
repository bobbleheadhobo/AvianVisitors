#!/usr/bin/env bash
# Install the AvianVisitors frame renderer on the BirdNET server, for an
# ESP32 frame running ESPHome (frame/esphome/). This machine screenshots its
# own collage, lays it out for a 7.3" 480x800 portrait panel, dithers it to
# the six Spectra-6 inks and writes frame.png / frame.json where Caddy serves
# them. No SPI, no panel driver, no reboot.
#
#   ./install-server.sh                         defaults below
#   ./install-server.sh --base-url http://localhost --export-dir ~/BirdSongs/Extracted/frame
set -euo pipefail
cd "$(dirname "$0")"
FRAME="$(pwd)"

BASE_URL="http://localhost"
EXPORT_DIR="$HOME/BirdSongs/Extracted/frame"
while [ $# -gt 0 ]; do
  case "$1" in
    --base-url) [ $# -ge 2 ] || { echo "--base-url needs a URL" >&2; exit 1; }
                BASE_URL="$2"; shift 2 ;;
    --base-url=*) BASE_URL="${1#*=}"; shift ;;
    --export-dir) [ $# -ge 2 ] || { echo "--export-dir needs a path" >&2; exit 1; }
                  EXPORT_DIR="$2"; shift 2 ;;
    --export-dir=*) EXPORT_DIR="${1#*=}"; shift ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

# Both values land in a TOML string verbatim, so keep them to plain characters.
case "$BASE_URL" in
  http://*|https://*) ;;
  *) echo "--base-url must start with http:// or https://" >&2; exit 1 ;;
esac
if printf '%s' "$BASE_URL" | LC_ALL=C grep -q '[^A-Za-z0-9._~:/?#@!$&()*+,;=%-]'; then
  echo "--base-url has characters that are not allowed in a URL" >&2
  exit 1
fi
if printf '%s' "$EXPORT_DIR" | LC_ALL=C grep -q '[^A-Za-z0-9._/~-]'; then
  echo "--export-dir may only contain letters, digits, . _ - / and ~" >&2
  exit 1
fi

CONFIG="$HOME/.birdframe/config.toml"

echo "1/4  Creating venv and installing Python deps..."
python3 -m venv .venv
.venv/bin/pip install -q --upgrade pip
.venv/bin/pip install -q -r requirements-server.txt

echo "2/4  Installing Chromium for the collage screenshot..."
sudo .venv/bin/playwright install-deps chromium
.venv/bin/playwright install chromium

echo "3/4  Writing config and the export folder..."
mkdir -p "$HOME/.birdframe" "${EXPORT_DIR/#\~/$HOME}"
if [ -f "$CONFIG" ]; then
  echo "     $CONFIG already exists, leaving it untouched."
else
  {
    printf '%s\n' '# birdframe-mode: esphome-server'
    printf '%s\n' '# AvianVisitors frame renderer: this server draws the frame for an ESP32'
    printf '%s\n' '# running frame/esphome/. See config.example.toml for every option.'
    printf 'base_url = "%s"\n' "$BASE_URL"
    printf '%s\n' 'shoot = true'
    printf '%s\n' 'shoot_title = "Avian Visitors"'
    printf '%s\n' 'shoot_subtitle = "Heard Today"'
    printf '%s\n' 'output = "esphome"'
    printf 'export_dir = "%s"\n' "$EXPORT_DIR"
    printf '%s\n' ''
    printf '%s\n' '# 7.3" Spectra 6, portrait, whole panel (no mat)'
    printf '%s\n' 'panel_size = [480, 800]'
    printf '%s\n' 'opening = 0.96'
    printf '%s\n' 'opening_ratio = 1.6667'
    printf '%s\n' 'title_frac = 0.065'
    printf '%s\n' 'collage_frac = 0.95'
    printf '%s\n' 'gap_frac = 0.04'
    printf '%s\n' 'shoot_label_min_px = 13'
    printf '%s\n' 'timeout = 180'
  } > "$CONFIG"
fi

echo "4/4  Installing systemd service + timer..."
sed "s|/home/monalisa/AvianVisitors/frame|$FRAME|g; s|/home/monalisa|$HOME|g; s|User=monalisa|User=$USER|" \
  systemd/birdframe-server.service | sudo tee /etc/systemd/system/birdframe-server.service >/dev/null
sudo cp systemd/birdframe-server.timer /etc/systemd/system/birdframe-server.timer
sudo systemctl daemon-reload
sudo systemctl enable --now birdframe-server.timer

# Render once now so the ESP32 has something to fetch on first boot.
.venv/bin/python display.py --config "$CONFIG" --force || true

cat <<DONE

Installed. Every 3 minutes this server checks for new birds and, when they
change, re-renders the frame into
  ${EXPORT_DIR}
served at http://<this-server>/frame/ (frame.json, frame.png, preview.png).
Open /frame/preview.png in a browser to check the look, then flash the ESP32
from frame/esphome/ (see frame/README.md).
DONE
