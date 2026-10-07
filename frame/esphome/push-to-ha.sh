#!/usr/bin/env bash
# Copy the frame firmware to Home Assistant's ESPHome add-on.
#
#   ./push-to-ha.sh --host 192.168.0.20            first time (remembered)
#   ./push-to-ha.sh                                every update after that
#   ./push-to-ha.sh --dry-run                      show what would change
#
# Needs an SSH add-on (Advanced SSH & Web Terminal, or Terminal & SSH) with
# your key or password set in its options; SFTP is not needed, the files go
# as a tar stream over ssh. Copies the device YAML and birdframe/ into the
# ESPHome folder (found on its own: /homeassistant/esphome, or /config/esphome
# on older add-ons), after validating them locally and backing up what is
# there to <folder>/.birdframe-backup/ (the last 5 are kept). It never
# touches the add-on's secrets.yaml; it only says which keys are missing.
# Then build and install from the ESPHome dashboard as usual.
#
# Options:
#   --host H         Home Assistant's address
#   --user U         SSH user (default root)
#   --port P         SSH port (default 22)
#   --build B        usb or battery (default usb); only one can live in HA,
#                    as both are named birdframe
#   --remote-dir D   the ESPHome folder, if it is not found on its own
#   --no-check       skip the local ESPHome validation
#   --dry-run        report only
# Host, user, port, build and folder are saved to ~/.birdframe/ha-push.conf.

set -euo pipefail

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
conf=${BIRDFRAME_HA_CONF:-$HOME/.birdframe/ha-push.conf}

host='' user=root port=22 build=usb remote_dir=''
check=1 dry=0

# Saved settings: plain key=value lines, read without sourcing.
if [ -f "$conf" ]; then
  while IFS='=' read -r key value; do
    case $key in
      host) host=$value ;;
      user) user=$value ;;
      port) port=$value ;;
      build) build=$value ;;
      remote_dir) remote_dir=$value ;;
    esac
  done < "$conf"
fi

usage() { sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; }
# An option's value may not be missing or look like another option.
need() { [ $# -ge 2 ] && [ "${2#-}" = "$2" ] || { echo "$1 needs a value" >&2; exit 2; }; }
while [ $# -gt 0 ]; do
  case $1 in
    --host) need "$@"; host=$2; shift 2 ;;
    --user) need "$@"; user=$2; shift 2 ;;
    --port) need "$@"; port=$2; shift 2 ;;
    --build) need "$@"; build=$2; shift 2 ;;
    --remote-dir) need "$@"; remote_dir=$2; shift 2 ;;
    --no-check) check=0; shift ;;
    --dry-run) dry=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown option: $1 (see --help)" >&2; exit 2 ;;
  esac
done

# These end up in ssh arguments and a remote shell: keep them plain.
[ -n "$host" ] || { echo "no host yet: run with --host <Home Assistant address>" >&2; exit 2; }
# No leading "-", so ssh can never read them as options.
[[ $host =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ ]] || { echo "bad --host: $host" >&2; exit 2; }
[[ $user =~ ^[A-Za-z0-9_][A-Za-z0-9._-]*$ ]] || { echo "bad --user: $user" >&2; exit 2; }
[[ $port =~ ^[0-9]{1,5}$ ]] || { echo "bad --port: $port" >&2; exit 2; }
[ -z "$remote_dir" ] || [[ $remote_dir =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "bad --remote-dir: $remote_dir" >&2; exit 2; }
case $build in
  usb) device=birdframe-usb.yaml other=birdframe-battery.yaml ;;
  battery) device=birdframe-battery.yaml other=birdframe-usb.yaml ;;
  *) echo "--build is usb or battery" >&2; exit 2 ;;
esac
for f in "$device" birdframe/common.yaml birdframe/birdframe.h; do
  [ -f "$here/$f" ] || { echo "missing $here/$f" >&2; exit 1; }
done

work=$(mktemp -d)
cleanup() {
  ssh -o ControlPath="$work/cm" -O exit "$user@$host" 2>/dev/null || true
  rm -rf "$work"
}
trap cleanup EXIT

# 1. Validate locally, with stand-in secrets.
if [ "$check" = 1 ]; then
  esphome=${ESPHOME:-}
  if [ -z "$esphome" ]; then
    if [ -x "$HOME/.cache/esphome-venv/bin/esphome" ]; then
      esphome=$HOME/.cache/esphome-venv/bin/esphome
    else
      esphome=$(command -v esphome || true)
    fi
  fi
  if [ -z "$esphome" ]; then
    echo "note: ESPHome not found here, skipping validation (Home Assistant will validate)"
  else
    mkdir -p "$work/check"
    cp -r "$here/birdframe" "$here/$device" "$work/check/"
    # A stand-in for every !secret the files use; API keys must be base64.
    grep -ho '!secret [A-Za-z0-9_]*' "$here/$device" "$here/birdframe/common.yaml" \
      | awk '{print $2}' | sort -u | while read -r name; do
        case $name in
          *api*key*|*encryption*) printf '%s: "%s"\n' "$name" "$(head -c 32 /dev/urandom | base64)" ;;
          *) printf '%s: "check-%s"\n' "$name" "$name" ;;
        esac
      done > "$work/check/secrets.yaml"
    version=$("$esphome" version 2>/dev/null | sed -n 's/^Version: //p')
    printf 'validating %s with ESPHome %s here (Home Assistant has its own)... ' "$device" "${version:-?}"
    if "$esphome" config "$work/check/$device" > "$work/check.log" 2>&1; then
      echo ok
    else
      echo failed
      grep -v '^INFO' "$work/check.log" | tail -25 >&2
      exit 1
    fi
  fi
fi

# One SSH connection for every step, so a password is asked for once.
ssh_opts=(-p "$port" -o ControlMaster=auto -o ControlPath="$work/cm" -o ControlPersist=60)
remote() { ssh "${ssh_opts[@]}" "$user@$host" "$@"; }

# 2. Find the folder, and how to write to it: directly, or through sudo
#    (Advanced SSH & Web Terminal with a non-root user: /homeassistant is
#    root's, and the add-on gives that user sudo).
echo "connecting to $user@$host:$port..."
probe=$(remote sh -s -- "${remote_dir:-auto}" <<'EOF'
dir=$1
if [ "$dir" = auto ]; then
  dir=
  for d in /homeassistant/esphome /config/esphome; do
    [ -d "$d" ] && { dir=$d; break; }
  done
fi
[ -n "$dir" ] && [ -d "$dir" ] || { echo "NODIR"; exit 0; }
echo "DIR $dir"
if [ -w "$dir" ]; then
  echo "ACCESS direct"
elif command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
  echo "ACCESS sudo"
else
  echo "ACCESS none"
fi
EOF
)

if grep -qx NODIR <<<"$probe"; then
  if [ -n "$remote_dir" ]; then
    echo "$remote_dir does not exist on $host." >&2
  else
    echo "no ESPHome folder on $host (looked for /homeassistant/esphome and /config/esphome). Is the ESPHome add-on installed? Pass --remote-dir if it lives elsewhere." >&2
  fi
  exit 1
fi
remote_dir=$(sed -n 's/^DIR //p' <<<"$probe")
case $(sed -n 's/^ACCESS //p' <<<"$probe") in
  direct) as_root=() ;;
  sudo) as_root=(sudo -n); echo "$user cannot write to $remote_dir itself; using sudo" ;;
  *)
    echo "$user cannot write to $remote_dir on $host, and has no password-free sudo. Nothing was changed." >&2
    echo "In the SSH add-on's options, set the username to root, or run with --user root." >&2
    exit 1 ;;
esac
# Runs a remote shell script (stdin) with the access found above.
remote_sh() { remote ${as_root[@]+"${as_root[@]}"} sh -s -- "$@"; }

report=$(remote_sh "$remote_dir" "$device" "$other" <<'EOF'
dir=$1 device=$2 other=$3
for t in tar sha256sum mkdir cp rm mv date; do
  command -v "$t" >/dev/null 2>&1 || echo "NOTOOL $t"
done
[ -f "$dir/$device" ] && echo "HAS_DEVICE"
[ -f "$dir/$other" ] && echo "HAS_OTHER"
for k in wifi_ssid wifi_password ota_password birdframe_api_key; do
  grep -q "^$k:" "$dir/secrets.yaml" 2>/dev/null || echo "MISSING_SECRET $k"
done
# The flat layout of earlier versions, recognised by their own text.
grep -q 'Shared by birdframe-usb.yaml' "$dir/common.yaml" 2>/dev/null && echo "LEGACY common.yaml"
grep -q 'namespace birdframe' "$dir/birdframe.h" 2>/dev/null && echo "LEGACY birdframe.h"
# The Device Builder keeps the folder in git; keep backups out of it.
if [ -d "$dir/.git" ] && ! grep -qx '/.birdframe-backup/' "$dir/.gitignore" 2>/dev/null; then
  echo "NEEDS_GITIGNORE"
fi
true
EOF
)

notool=$(sed -n 's/^NOTOOL //p' <<<"$report")
if [ -n "$notool" ]; then
  echo "the SSH add-on on $host lacks: $(echo $notool | sed 's/ /, /g'). Nothing was changed." >&2
  exit 1
fi
legacy=$(sed -n 's/^LEGACY //p' <<<"$report")
missing=$(sed -n 's/^MISSING_SECRET //p' <<<"$report")

echo "will copy: $device, birdframe/common.yaml, birdframe/birdframe.h -> $host:$remote_dir/"
grep -qx HAS_DEVICE <<<"$report" && echo "  replacing the $device there (backed up first)"
[ -n "$legacy" ] && echo "  removing the old flat layout's $(echo $legacy | sed 's/ /, /g') (backed up first)"
grep -qx NEEDS_GITIGNORE <<<"$report" && echo "  adding /.birdframe-backup/ to the folder's .gitignore (it is a git repository)"
if grep -qx HAS_OTHER <<<"$report"; then
  echo "warning: $other is also in $remote_dir. Both builds are named birdframe; remove the one you do not use." >&2
fi

if [ "$dry" = 1 ]; then
  [ -n "$missing" ] && echo "secrets.yaml is missing: $(echo $missing | sed 's/ /, /g')"
  echo "dry run: nothing changed"
  exit 0
fi

files=("$device" birdframe/common.yaml birdframe/birdframe.h)
sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi; }

# 3. Stage the new files in a hidden folder beside the live ones and check
#    them there, so a failed copy leaves the working config untouched.
stage=.birdframe-incoming-$$
remote_sh "$remote_dir" "$stage" <<'EOF'
rm -rf "$1"/.birdframe-incoming-*
mkdir "$1/$2"
EOF
# COPYFILE_DISABLE keeps macOS tar from adding ._* files; -o on the far side
# does not restore this machine's owner (GNU and BusyBox tar alike).
COPYFILE_DISABLE=1 tar -C "$here" -cf - "${files[@]}" | remote ${as_root[@]+"${as_root[@]}"} tar -C "$remote_dir/$stage" -xof -
local_sums=$(cd "$here" && sha "${files[@]}")
sums_in() { remote_sh "$1" "${files[@]}" <<'EOF'
cd "$1" && shift && sha256sum "$@"
EOF
}
remote_sums=$(sums_in "$remote_dir/$stage")
if [ "$local_sums" != "$remote_sums" ]; then
  remote ${as_root[@]+"${as_root[@]}"} rm -rf "$remote_dir/$stage" || true
  echo "the copy on $host did not match; nothing was changed:" >&2
  diff <(echo "$local_sums") <(echo "$remote_sums") >&2 || true
  exit 1
fi

# 4. Back up what is there, then swap the staged files in.
remote_sh "$remote_dir" "$device" "$stage" $legacy <<'EOF'
set -e
dir=$1 device=$2 stage=$3; shift 3
backup="$dir/.birdframe-backup/$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$backup"
[ -f "$dir/$device" ] && cp -p "$dir/$device" "$backup/"
[ -d "$dir/birdframe" ] && cp -rp "$dir/birdframe" "$backup/"
for f in "$@"; do
  cp -p "$dir/$f" "$backup/" && rm -f "$dir/$f"
done
rmdir "$backup" 2>/dev/null || true
# Keep the last five backups (no head -n -5: BusyBox may lack it).
n=$(ls -1d "$dir/.birdframe-backup/"*/ 2>/dev/null | wc -l)
if [ "$n" -gt 5 ]; then
  ls -1d "$dir/.birdframe-backup/"*/ | sort | head -n "$((n - 5))" | while read -r old; do rm -rf "$old"; done
fi
rm -rf "$dir/birdframe"
mv "$dir/$stage/birdframe" "$dir/birdframe"
mv "$dir/$stage/$device" "$dir/$device"
rmdir "$dir/$stage"
if [ -d "$dir/.git" ] && ! grep -qx '/.birdframe-backup/' "$dir/.gitignore" 2>/dev/null; then
  # Start on a new line if the file does not end with one.
  [ -s "$dir/.gitignore" ] && [ -n "$(tail -c 1 "$dir/.gitignore")" ] && echo >> "$dir/.gitignore"
  echo '/.birdframe-backup/' >> "$dir/.gitignore"
fi
EOF
if [ "$(sums_in "$remote_dir")" != "$local_sums" ]; then
  echo "the files in $remote_dir do not match after the swap; the previous ones are in .birdframe-backup/" >&2
  exit 1
fi
echo "copied and verified"

mkdir -p "$(dirname "$conf")"
printf 'host=%s\nuser=%s\nport=%s\nbuild=%s\nremote_dir=%s\n' "$host" "$user" "$port" "$build" "$remote_dir" > "$conf"

if [ -n "$missing" ]; then
  echo
  echo "Add these to the ESPHome dashboard's Secrets (top right) before installing:"
  for k in $missing; do
    case $k in
      birdframe_api_key) echo "  $k: \"<output of: openssl rand -base64 32>\"" ;;
      ota_password) echo "  $k: \"<the frame's current OTA password, or a new one for a USB flash>\"" ;;
      *) echo "  $k: \"...\"" ;;
    esac
  done
fi
echo
echo "Next: in the ESPHome dashboard, birdframe -> Install -> Wirelessly"
echo "(or Plug into this computer for the first flash)."
