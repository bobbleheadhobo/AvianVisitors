#!/usr/bin/python3
"""Switch the station to a different downloaded bird bundle once a day.

Installed as root at /usr/local/sbin/avian-bundle-rotate and run hourly by
avian-bundle-rotate.timer. Settings > Station turns it on (BUNDLE_ROTATE=1 in
birdnet.conf) and unticks bundles to leave out (BUNDLE_ROTATE_SKIP, a comma
list of ids), so a newly downloaded bundle joins the rotation by itself.

The day turns over at the site's reset hour, like the rest of the site. While
rotation is off the script keeps marking today as done, so switching it on
never changes the birds until the next day starts. The switch itself is the
bundle manager's own "activate" job, the same one the Settings sheet queues.
The script waits for that job: systemd kills what a oneshot service leaves
behind, and the job's worker is one of its children.
"""

from __future__ import annotations

import datetime as dt
import json
import os
import random
import re
import subprocess
import sys
import time
from pathlib import Path
from typing import Any

CONF_PATH = Path("/etc/birdnet/birdnet.conf")
STATE_PATH = Path("/var/lib/avian-visitors/bundle-rotate.json")
MANAGER = "/usr/local/sbin/avian-bundle-control"
ID_RE = re.compile(r"^[a-z0-9][a-z0-9._-]{0,79}$")
JOB_WAIT_SECONDS = 20 * 60


def read_conf(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    try:
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return values
    for line in lines:
        match = re.match(r"^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$", line)
        if not match:
            continue
        value = match.group(2).strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        values[match.group(1)] = value
    return values


def site_day(now: dt.datetime, conf: dict[str, str]) -> str:
    """The site's date: before the reset hour it is still yesterday."""
    hour = 0
    if conf.get("RESET_AT_MIDNIGHT") == "1" and re.fullmatch(r"[0-9]|1[0-9]|2[0-3]", conf.get("RESET_HOUR", "")):
        hour = int(conf["RESET_HOUR"])
    return (now - dt.timedelta(hours=hour)).date().isoformat()


def skipped(conf: dict[str, str]) -> set[str]:
    return {item for item in conf.get("BUNDLE_ROTATE_SKIP", "").split(",") if ID_RE.fullmatch(item)}


def choose(installed: list[dict[str, Any]], active_id: str, skip: set[str],
           rng: random.Random) -> dict[str, Any] | None:
    """A random ticked bundle other than today's, or None to stay put."""
    candidates = [pack for pack in installed
                  if pack.get("id") not in skip and pack.get("id") != active_id]
    return rng.choice(candidates) if candidates else None


def manager(*arguments: str) -> dict[str, Any]:
    result = subprocess.run(
        [MANAGER, *arguments, "--json"], capture_output=True, text=True, timeout=120,
        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C", "HOME": "/root"},
    )
    try:
        body = json.loads(result.stdout)
    except ValueError:
        body = None
    if not isinstance(body, dict) or body.get("ok") is not True:
        error = body.get("error") if isinstance(body, dict) else (result.stderr.strip() or "no output")
        raise RuntimeError(f"bundle manager {arguments[0]} failed: {error}")
    return body


def wait_for(job_id: str) -> str:
    """The job's final state as the snapshot reports it ("succeeded"/"failed")."""
    deadline = time.monotonic() + JOB_WAIT_SECONDS
    while time.monotonic() < deadline:
        job = manager("snapshot").get("job")
        if not isinstance(job, dict) or job.get("id") != job_id:
            return "replaced"
        if job.get("state") != "running":
            return str(job.get("state"))
        time.sleep(2)
    return "timed out"


def load_day(path: Path) -> str:
    try:
        return str(json.loads(path.read_text(encoding="utf-8")).get("day", ""))
    except (OSError, ValueError, AttributeError):
        return ""


def save_day(path: Path, day: str) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(json.dumps({"day": day}) + "\n", encoding="utf-8")
    os.chmod(temporary, 0o644)
    os.replace(temporary, path)


def main() -> int:
    conf = read_conf(CONF_PATH)
    today = site_day(dt.datetime.now(), conf)
    if load_day(STATE_PATH) == today:
        return 0
    if conf.get("BUNDLE_ROTATE") != "1":
        save_day(STATE_PATH, today)
        return 0
    snapshot = manager("snapshot")
    job = snapshot.get("job")
    if isinstance(job, dict) and job.get("state") in {"queued", "running"}:
        print("a bundle job is running; trying again next hour")
        return 0
    library = snapshot.get("library") or {}
    active = library.get("active") or {}
    installed = [pack for pack in library.get("installed") or []
                 if isinstance(pack, dict) and ID_RE.fullmatch(str(pack.get("id", "")))]
    pick = choose(installed, str(active.get("id", "")), skipped(conf), random.SystemRandom())
    if pick is None:
        print(f"nothing to switch to; keeping {active.get('name', 'the current bundle')}")
    else:
        print(f"switching from {active.get('name', '?')} to {pick.get('name', pick['id'])}", flush=True)
        queued = manager("enqueue", "--action", "activate", "--id", pick["id"],
                         "--version", str(pick.get("version", "")))
        outcome = wait_for(str((queued.get("job") or {}).get("id", "")))
        if outcome != "succeeded":
            raise RuntimeError(f"switch to {pick['id']} {outcome}")
    save_day(STATE_PATH, today)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        print(error, file=sys.stderr)
        raise SystemExit(1)
