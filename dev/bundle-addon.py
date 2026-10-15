#!/usr/bin/env python3
"""Add drawings to a downloaded bundle for birds it has no drawing for.

Downloaded bundles are hash-checked and can't be changed, so these live beside
them in /var/lib/avian-visitors/bundle-additions/<bundle-id>/ and the site
merges them in (avian/api/bundle-additions.php). A bird the bundle already
draws is refused: the bundle's own art always wins.

    birdnet/bin/python dev/bundle-addon.py list
    birdnet/bin/python dev/bundle-addon.py add <bundle-id> <slug>.png [<slug>-2.png ...]
    birdnet/bin/python dev/bundle-addon.py remove <bundle-id> <slug>

Name the files <slug>.png (perched) and <slug>-2.png (flight), slug as in
dryobates-scalaris. They must be RGBA cutouts with a transparent background,
in the bundle's style; the outline tables are computed exactly as the bundle
manager computes its own. Runs as you and uses sudo only to put the
root-owned files in place, so no image is decoded as root.
AVIAN_BUNDLE_ROOT / AVIAN_BUNDLE_ADDITIONS_ROOT point it elsewhere (tests),
and then it writes without sudo.
"""
import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "avian" / "scripts"))
import bundle_manager  # noqa: E402  (local_mask_geometry, the PNG limits)

BUNDLES_DEFAULT = "/var/lib/avian-visitors/bundles"
ADDITIONS_DEFAULT = "/var/lib/avian-visitors/bundle-additions"
BUNDLE_ID = re.compile(r"\A[a-z0-9][a-z0-9._-]{0,79}\Z")   # as avian_bundle_id_valid
NAME = re.compile(r"\A(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)+?)(?P<flight>-2)?\.png\Z")


def bundles_root():
    return Path(os.environ.get("AVIAN_BUNDLE_ROOT") or BUNDLES_DEFAULT)


def additions_root():
    return Path(os.environ.get("AVIAN_BUNDLE_ADDITIONS_ROOT") or ADDITIONS_DEFAULT)


def production():
    return str(additions_root()) == ADDITIONS_DEFAULT


def privileged(*argv):
    command = (["sudo", "-n"] if production() else []) + list(argv)
    subprocess.run(command, check=True)


def read_text(path):
    """Read a file in the root-only bundle library."""
    if str(bundles_root()) == BUNDLES_DEFAULT:
        return subprocess.run(["sudo", "-n", "cat", str(path)], check=True,
                              capture_output=True, text=True).stdout
    return Path(path).read_text()


def bundle_species(bundle_id):
    """Every slug the bundle's full manifest draws, in any installed version."""
    pack = bundles_root() / "packs" / bundle_id
    if str(bundles_root()) == BUNDLES_DEFAULT:
        found = subprocess.run(["sudo", "-n", "find", str(pack), "-name", "manifest.json"],
                               capture_output=True, text=True).stdout.split()
    else:
        found = [str(p) for p in pack.rglob("manifest.json")]
    if not found:
        sys.exit(f"{bundle_id} is not a downloaded bundle on this station")
    slugs = set()
    for path in found:
        manifest = json.loads(read_text(path))
        if manifest.get("id") != bundle_id:
            sys.exit(f"{path} is not {bundle_id}'s manifest")
        for species in manifest["species"]:
            slugs.add(re.sub(r"[^a-z0-9]+", "-", species["scientific_name"].lower()).strip("-"))
    return slugs


def load(bundle_id):
    path = additions_root() / bundle_id / "additions.json"
    if path.exists():
        record = json.loads(path.read_text())
        if record.get("bundle_id") != bundle_id or record.get("schema_version") != 1:
            sys.exit(f"{path} is not an additions record for {bundle_id}")
        return record
    return {"schema_version": 1, "bundle_id": bundle_id, "assets": {}, "dims": {}, "masks": {}}


def save(bundle_id, record, pngs):
    """Install new PNGs, then the record (the switch), then drop unused PNGs."""
    directory = additions_root() / bundle_id
    owner = ["-o", "root", "-g", "root"] if production() else []
    privileged("install", "-d", "-m", "0755", *owner, str(additions_root()), str(directory))
    with tempfile.TemporaryDirectory() as temp:
        for digest, raw in pngs.items():
            staged = Path(temp) / f"{digest}.png"
            staged.write_bytes(raw)
            privileged("install", "-m", "0644", *owner, str(staged), str(directory / staged.name))
        staged = Path(temp) / "additions.json"
        staged.write_text(json.dumps(record, indent=1, sort_keys=True) + "\n")
        privileged("install", "-m", "0644", *owner, str(staged), str(directory / "additions.json.new"))
        privileged("mv", "-f", str(directory / "additions.json.new"), str(directory / "additions.json"))
    used = {o["sha256"] for poses in record["assets"].values() for o in poses.values()}
    for path in directory.glob("*.png"):
        if path.stem not in used:
            privileged("rm", "-f", str(path))


def cmd_add(args):
    drawn = bundle_species(args.bundle_id)
    record = load(args.bundle_id)
    pngs = {}
    for name in args.pngs:
        match = NAME.match(Path(name).name)
        if not match:
            sys.exit(f"{name}: name it <slug>.png or <slug>-2.png")
        slug, flight = match["slug"], bool(match["flight"])
        if slug in drawn:
            sys.exit(f"{slug}: {args.bundle_id} already draws it, so an addition would never show")
        raw = Path(name).read_bytes()
        if len(raw) > bundle_manager.IMAGE_MAX:
            sys.exit(f"{name}: over {bundle_manager.IMAGE_MAX} bytes")
        try:
            dims, mask = bundle_manager.local_mask_geometry(raw, slug)
        except bundle_manager.BundleError as error:
            sys.exit(f"{name}: {error}")
        digest = hashlib.sha256(raw).hexdigest()
        key = slug + ("-2" if flight else "")
        record["assets"].setdefault(slug, {})["flight" if flight else "perched"] = {
            "sha256": digest, "bytes": len(raw)}
        record["dims"][key] = dims
        record["masks"][key] = mask
        pngs[digest] = raw
        print(f"{args.bundle_id}: {key} {dims}")
    save(args.bundle_id, record, pngs)


def cmd_remove(args):
    record = load(args.bundle_id)
    if args.slug not in record["assets"]:
        sys.exit(f"{args.slug} is not an addition to {args.bundle_id}")
    del record["assets"][args.slug]
    for key in (args.slug, args.slug + "-2"):
        record["dims"].pop(key, None)
        record["masks"].pop(key, None)
    save(args.bundle_id, record, {})
    print(f"removed {args.slug} from {args.bundle_id}")


def cmd_list(_args):
    root = additions_root()
    if not root.exists():
        print("no additions")
        return
    for directory in sorted(p for p in root.iterdir() if p.is_dir()):
        record = load(directory.name)
        for slug, poses in sorted(record["assets"].items()):
            print(f"{directory.name}: {slug} ({', '.join(sorted(poses))})")


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    add = sub.add_parser("add")
    add.add_argument("bundle_id")
    add.add_argument("pngs", nargs="+")
    remove = sub.add_parser("remove")
    remove.add_argument("bundle_id")
    remove.add_argument("slug")
    sub.add_parser("list")
    args = parser.parse_args()
    if hasattr(args, "bundle_id") and not BUNDLE_ID.match(args.bundle_id):
        sys.exit(f"{args.bundle_id} is not a bundle id")
    {"add": cmd_add, "remove": cmd_remove, "list": cmd_list}[args.command](args)


if __name__ == "__main__":
    main()
