#!/usr/bin/env bash
# Screenshot pages of the local site in headless Chromium, as a phone and/or
# a desktop, and report page errors. See dev/shot.py --help.
#
# The Python side lives in a venv kept between sessions (~/.cache, not the
# session scratchpad), so the first run installs playwright once.
set -euo pipefail
venv=${AVIAN_DEV_VENV:-$HOME/.cache/avian-dev-venv}
if ! "$venv/bin/python" -c 'import playwright' 2>/dev/null; then
  echo "setting up $venv (one time)..." >&2
  python3 -m venv "$venv"
  "$venv/bin/pip" -q install 'playwright==1.63.0'
fi
exec "$venv/bin/python" "$(dirname "$(readlink -f "$0")")/shot.py" "$@"
