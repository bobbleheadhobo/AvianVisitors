#!/usr/bin/env bash
# Make this checkout's privileged pieces live after an edit, and catch the
# usual "it works for me but not on the phone" leftovers.
#
#   dev/sync-live.sh          install changed helpers, refresh Caddy if needed
#   dev/sync-live.sh --check  report only, change nothing
#
# The web files are served straight from the checkout, but root-owned copies
# are not: each scripts/*_control.sh (and the Caddy refresher) runs from
# /usr/local/sbin, and a stale copy there silently keeps old behaviour. This
# installs only the ones that differ, using the same name map as
# install_avian_controls in scripts/install_services.sh. When the Caddy
# refresher changes it regenerates the Caddyfile and prints the diff, so a
# dropped route shows up here instead of as a 404 later.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")/.."
check=0
[ "${1-}" = --check ] && check=1
warn=0
note() { printf '%s\n' "$*"; }
problem() { printf 'WARN: %s\n' "$*"; warn=1; }

# --- root helpers ---------------------------------------------------------
map=$(awk '/^install_avian_controls\(\)/ {f=1} f && /<<.EOF.$/ {r=1; next} r && /^EOF$/ {exit} r {print}' scripts/install_services.sh)
[ -n "$map" ] || { echo "could not read the helper map from scripts/install_services.sh" >&2; exit 2; }
caddy_changed=0
while read -r source target; do
  [ -f "scripts/$source" ] || continue
  installed=/usr/local/sbin/$target
  if sudo cmp -s "scripts/$source" "$installed" 2>/dev/null; then continue; fi
  if [ "$check" = 1 ]; then
    problem "$installed differs from scripts/$source"
  else
    bash -n "scripts/$source" || { echo "syntax error in scripts/$source; not installing" >&2; exit 1; }
    sudo install -o root -g root -m 0755 "scripts/$source" "$installed"
    note "installed $installed"
  fi
  [ "$target" = avian-caddy-refresh ] && caddy_changed=1
done <<<"$map"

# --- Caddy ------------------------------------------------------------------
if [ "$caddy_changed" = 1 ] && [ "$check" = 0 ]; then
  note "regenerating the Caddyfile..."
  sudo /usr/local/sbin/avian-caddy-refresh >/dev/null 2>&1 || { echo "avian-caddy-refresh failed" >&2; exit 1; }
  sudo diff /etc/caddy/Caddyfile.previous /etc/caddy/Caddyfile && note "Caddyfile unchanged" || true
fi
# Every endpoint in avian/api that the frontend can call must be on the
# allow-list, or Caddy answers 404. Files that only get require'd are fine.
allowed=$(sudo grep -o '/avian/api/[a-z-]*\.php' /etc/caddy/Caddyfile 2>/dev/null | sort -u)
for f in avian/api/*.php; do
  route=/$f
  grep -qxF "$route" <<<"$allowed" && continue
  grep -rqF "api/$(basename "$f")" avian/frontend/apt.js avian/frontend/index.html frame 2>/dev/null \
    && problem "$route is used by the frontend but the live Caddyfile does not allow it (add it in scripts/update_caddyfile.sh)"
done

# --- browser cache ----------------------------------------------------------
# A changed apt.js / styles.css needs a new ?v= in index.html, or phones keep
# the old copy. Compare with what is already pushed (or HEAD).
base=$(git rev-parse -q --verify '@{u}' 2>/dev/null || git rev-parse HEAD)
for asset in apt.js styles.css; do
  git diff --quiet "$base" -- "avian/frontend/$asset" && continue
  old=$(git show "$base:avian/frontend/index.html" | grep -o "$asset?v=[a-z0-9]*" || true)
  new=$(grep -o "$asset?v=[a-z0-9]*" avian/frontend/index.html || true)
  [ "$old" != "$new" ] || problem "avian/frontend/$asset changed but index.html still loads $new; bump the ?v="
done

# --- birdnet.conf ------------------------------------------------------------
[ -L /etc/birdnet/birdnet.conf ] || problem "/etc/birdnet/birdnet.conf is no longer a symlink to the repo copy"

# --- test data -----------------------------------------------------------------
fakes=$(sqlite3 scripts/birds.db "SELECT COUNT(*) FROM detections WHERE File_Name LIKE 'FAKE-%'" 2>/dev/null || echo 0)
[ "$fakes" = 0 ] || problem "$fakes fake detection(s) still in birds.db; remove with dev/fake-detection.py clean"

[ "$warn" = 0 ] && note "live copy matches the checkout"
exit "$warn"
