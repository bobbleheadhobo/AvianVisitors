#!/usr/bin/env bash
# Root-owned control plane for the station actions in Tools: reboot, shut
# down, back up and restore. The web server may run only the fixed actions
# listed in /etc/sudoers.d/020_avian-admin; every path here is fixed too.

set -Eeuo pipefail
IFS=$'\n\t'
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077

readonly CONTROL_HELPER=/usr/local/sbin/avian-station-control
readonly restore_dir=/var/lib/avian-visitors/restore
readonly staged=$restore_dir/upload.tar
readonly restore_log=$restore_dir/restore.log
readonly restore_unit=avian-restore

json_escape() {
  local value=${1-}
  value=${value//\\/\\\\}
  value=${value//\"/\\\"}
  value=${value//$'\n'/\\n}
  value=${value//$'\r'/\\r}
  value=${value//$'\t'/\\t}
  printf '%s' "$value"
}

fail() {
  printf '{"ok":false,"error":"%s"}\n' "$(json_escape "${1:-station control failed}")"
  exit 1
}

[ "${EUID:-$(id -u)}" -eq 0 ] || fail 'station control must run as root'
[ "$(readlink -f "$0")" = "$CONTROL_HELPER" ] || fail "station control must use $CONTROL_HELPER"

conf=/etc/birdnet/birdnet.conf
conf_value() {
  awk -v wanted="$1" '
    $0 ~ "^[[:space:]]*(export[[:space:]]+)?" wanted "[[:space:]]*=" {
      value=$0; sub(/^[^=]*=[[:space:]]*/, "", value)
      gsub(/^[[:space:]"\047]+|[[:space:]"\047]+$/, "", value); found=1
    }
    END { if (found) print value }' "$conf"
}
birdnet_user=$(conf_value BIRDNET_USER)
[[ "$birdnet_user" =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || fail 'BirdNET-Pi user is invalid'
birdnet_home=$(getent passwd "$birdnet_user" | cut -d: -f6)
[[ "$birdnet_home" =~ ^/[A-Za-z0-9._/-]+$ ]] && [[ "$birdnet_home" != *'..'* ]] || fail 'BirdNET-Pi home is invalid'
recs_dir=$(conf_value RECS_DIR)
[[ "$recs_dir" =~ ^/[A-Za-z0-9._/-]+$ ]] && [[ "$recs_dir" != *'..'* ]] || fail 'recordings path is invalid'
backup_script=$birdnet_home/BirdNET-Pi/scripts/backup_data.sh
[ -f "$backup_script" ] && [ -x "$backup_script" ] || fail 'backup script is missing'

as_birdnet() { runuser -u "$birdnet_user" -- "$@"; }

# Power: answer first, act a few seconds later so the page hears back.
power() {
  local verb=$1
  systemctl is-active --quiet avian-station-power.timer 2>/dev/null && fail 'a restart is already on its way'
  systemd-run --quiet --collect --unit=avian-station-power --on-active=3 \
    /usr/bin/systemctl "$verb" >/dev/null || fail "could not schedule $verb"
  printf '{"ok":true,"action":"%s","in_seconds":3}\n' "$verb"
}

restore_status() {
  local active state tail_text=''
  active=$(systemctl is-active "$restore_unit.service" 2>/dev/null || true)
  if [ "$active" = active ] || [ "$active" = activating ]; then
    state=running
  elif [ -f "$restore_log" ]; then
    if grep -q '^Restore done' "$restore_log"; then state=complete; else state=failed; fi
  else
    state=idle
  fi
  [ -f "$restore_log" ] && tail_text=$(tail -n 4 "$restore_log")
  local staged_bytes=0
  [ -f "$staged" ] && staged_bytes=$(stat -c %s "$staged")
  printf '{"ok":true,"state":"%s","staged_bytes":%s,"log":"%s"}\n' \
    "$state" "$staged_bytes" "$(json_escape "$tail_text")"
}

restore_start() {
  local active target
  active=$(systemctl is-active "$restore_unit.service" 2>/dev/null || true)
  [ "$active" = active ] || [ "$active" = activating ] && fail 'a restore is already running'
  [ -f "$staged" ] && [ ! -L "$staged" ] || fail 'no uploaded backup to restore'
  local listing
  listing=$(tar --list -f "$staged" 2>/dev/null) || fail 'the uploaded file is not a backup archive'
  # Match against the whole listing (grep -q on a live pipe would close it
  # early and pipefail would read that as a failure).
  [[ $'\n'"$listing"$'\n' == *$'\n'birds.db$'\n'* ]] || fail 'the uploaded file has no birds.db; is it a station backup?'
  # Hand the archive to the BirdNET-Pi user beside its recordings, where the
  # restore script unpacks.
  target=$recs_dir/restore-upload.tar
  install -o "$birdnet_user" -g "$birdnet_user" -m 0600 "$staged" "$target"
  rm -f "$staged"
  : >"$restore_log"; chmod 0640 "$restore_log"; chgrp caddy "$restore_log" 2>/dev/null || true
  systemctl reset-failed "$restore_unit.service" >/dev/null 2>&1 || true
  systemd-run --quiet --collect --unit="$restore_unit" --property=Type=oneshot \
    /bin/sh -c "runuser -u '$birdnet_user' -- '$backup_script' -a restore -f '$target' >>'$restore_log' 2>&1; rc=\$?; rm -f '$target'; exit \$rc" \
    || fail 'could not start the restore'
  printf '{"ok":true,"state":"running"}\n'
}

action=${1:-}
[ "$#" -eq 1 ] || fail 'one action expected'
case "$action" in
  reboot) power reboot ;;
  poweroff) power poweroff ;;
  backup)
    # The archive goes to stdout; the caller streams it to the browser.
    cd /
    exec runuser -u "$birdnet_user" -- "$backup_script" -a backup -f -
    ;;
  backup-size)
    bytes=$(cd / && as_birdnet "$backup_script" -a size 2>/dev/null | tail -n 1)
    [[ "$bytes" =~ ^[0-9]+$ ]] || fail 'could not size the backup'
    printf '{"ok":true,"bytes":%s}\n' "$bytes"
    ;;
  restore-status) restore_status ;;
  restore-start) restore_start ;;
  restore-clear)
    rm -f "$staged"
    printf '{"ok":true}\n'
    ;;
  *) fail 'unknown action' ;;
esac
