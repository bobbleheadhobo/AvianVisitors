#!/usr/bin/env python3
"""Frame-Pi client: turn a collage screenshot into Inky panel pixels.

Runs on the frame Pi (a 3 A+ or Zero 2 W) on a systemd timer. Each run it decides whether a
refresh is worth it (the species set or call-count brackets changed, and it
is not quiet hours), then crops the title and collage from the screenshot,
centres and mats them, and pushes the result to the Inky Impression 13.3".
``--preview out.png`` writes an approximate 6-ink dither instead, so the
look can be checked on any machine without the panel.
"""
from __future__ import annotations

import argparse
import fcntl
import base64
import hashlib
import inspect
import io
import json
import os
import re
import statistics
import sys
import time
import urllib.request
from datetime import datetime

from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter

try:
    import tomllib
except ModuleNotFoundError:  # Python < 3.11
    import tomli as tomllib

PANEL_W, PANEL_H = 1200, 1600  # portrait; the panel itself is 1600x1200
SQRT2 = 1.41421

# Approximate Spectra-6 inks, used for --preview and as the dither target for
# the ESPHome export. On an Inky panel the library maps to the real palette.
# Red and yellow were fitted to a photo of the 7.3" panel showing a known
# frame.png (lighting and white balance solved out, paper held fixed): the
# yellow ink is far brighter and purer than the earlier mustard estimate, so
# tans dithered for that estimate came out lemon. Black, blue and green were
# too sparse in that photo to refit.
SPECTRA6 = [(236, 234, 223), (26, 26, 28), (202, 40, 60),
            (255, 215, 0), (49, 71, 130), (58, 110, 72)]
# The same six inks as pure colours, in SPECTRA6 order. ESPHome's Spectra-E6
# driver snaps each pixel to the nearest of these, so an image that already
# uses only them reaches the panel exactly as dithered here.
SPECTRA6_PURE = [(255, 255, 255), (0, 0, 0), (255, 0, 0),
                 (255, 255, 0), (0, 0, 255), (0, 255, 0)]

DEFAULTS = {
    "base_url": "http://birdnet.local",
    "species_source": "",   # "" = the recent API; "birdweather" = one station or a ZIP
    "zip": "",              # BirdWeather ZIP / postal code (use one locator only)
    "bw_station_id": "",    # public BirdWeather station ID (use instead of zip)
    "bw_days": 7,           # BirdWeather lookback window, in days
    "bw_country": "us",     # geocoder country for the ZIP
    "hours": 24,
    "image": "",            # local PNG written by the shooter
    "image_url": "",        # or a published screenshot URL
    "shoot": False,         # or capture inline (needs a browser; the 3 A+ and Zero 2 W both handle it)
    "shoot_title": None, "shoot_subtitle": None,
    "shoot_headline_px": 42, "shoot_eyebrow_px": 18, "shoot_lowercase": False,
    "shoot_mat": 0.04, "shoot_small_floor": 0.04, "shoot_count_exp": 0.65,
    "shoot_label_min_px": 11,  # smallest bird-name font, CSS px
    "bird_names": False,
    "mat": 0.0,             # extra global shrink of the content inside the A5 opening
    "opening": 0.7071,      # opening height as a panel fraction; 0.7071 preserves A5
    "opening_ratio": SQRT2,  # opening height / width; 1.41421 is the A-series mat
    "panel_size": [PANEL_W, PANEL_H],  # portrait pixels: [480, 800] for a 7.3"
    "title_frac": 0.065,    # title height as a fraction of the opening height
    "collage_frac": 0.66,   # max collage width as a fraction of the opening width
    "gap_frac": 0.1,        # title-to-collage gap as a fraction of the opening height
    "rotate": 90,           # 90 or 270 if the frame hangs the other way up
    "saturation": 0.6,
    "paper_warmth": 0.0,    # share of open background dotted yellow (ESPHome/preview), 0 to 1
    "ink_saturation": 1.35,  # colour boost before dithering (ESPHome/preview)
    "ink_contrast": 1.12,    # contrast boost before dithering (ESPHome/preview)
    "web_dir": "/var/lib/avian-visitors/frame",  # settings saved from the website
    "panel": "",            # "el133uf1" forces the 13.3" driver if auto() fails
    "output": "inky",       # "inky" pushes to the panel; "esphome" writes files for an ESP32
    "export_dir": "~/BirdSongs/Extracted/frame",  # where "esphome" output is served from
    "quiet_start": 0, "quiet_end": 0,    # 0/0 = no quiet hours
    "heal_hours": 24,
    "state": "~/.birdframe/state.json",
    "cache": "~/.birdframe",
    "timeout": 180,      # seconds; a Zero 2 W needs ~70-120s to shoot the collage
    "basic_user": None, "basic_pass": None,
}


def _auth(cfg):
    if not cfg.get("basic_user"):
        return None
    raw = f"{cfg['basic_user']}:{cfg.get('basic_pass') or ''}".encode()
    return "Basic " + base64.b64encode(raw).decode()


# --- change detection -------------------------------------------------------
def slugify(sci):
    return re.sub(r"[^a-z0-9]+", "-", sci.lower()).strip("-")


def _bucket(n):
    for i, edge in enumerate((1, 2, 5, 15, 40, 100, 300, 1000)):
        if n <= edge:
            return i
    return 8


def fetch_recent(base, hours, timeout, auth=None):
    url = f"{base.rstrip('/')}/avian/api/birdnet-api.php?action=recent&hours={hours}"
    req = urllib.request.Request(url, headers={"User-Agent": "AvianVisitors-frame/1.0"})
    if auth:
        req.add_header("Authorization", auth)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read(2_000_000)).get("species", [])


def signature(species, scope=""):
    items = sorted((slugify(s["sci"]), _bucket(int(s.get("n") or 1))) for s in species)
    material = [scope, items] if scope else items
    return hashlib.sha256(json.dumps(material).encode()).hexdigest()[:16]


def birdweather_locator(cfg):
    """Return (kind, value) for the one configured BirdWeather source."""
    station = cfg.get("bw_station_id")
    has_station = station not in (None, "", 0)
    zip_code = cfg.get("zip")
    has_zip = isinstance(zip_code, str) and bool(zip_code.strip())
    if has_station and has_zip:
        raise ValueError("BirdWeather config must use either bw_station_id or zip, not both")
    if has_station:
        import birdweather
        return "station", birdweather.station_id(station)
    if has_zip:
        return "zip", zip_code.strip()
    raise ValueError("BirdWeather config needs bw_station_id or zip")


def birdweather_signature_scope(cfg):
    kind, value = birdweather_locator(cfg)
    if kind == "station":
        return f"birdweather:station:{value}:days:{cfg['bw_days']}"
    return f"birdweather:zip:{cfg['bw_country']}:{value}:days:{cfg['bw_days']}"


def fetch_species(cfg, auth=None):
    """The species list the signature is built from: the BirdNET-Pi recent API
    by default, or BirdWeather detections from one station or near a ZIP when
    species_source = "birdweather"."""
    if cfg.get("species_source") == "birdweather":
        import birdweather
        kind, value = birdweather_locator(cfg)
        if kind == "station":
            return birdweather.species_for_station(value, days=cfg["bw_days"])
        return birdweather.species_for_zip(value, country=cfg["bw_country"], days=cfg["bw_days"])
    return fetch_recent(cfg["base_url"], cfg["hours"], cfg["timeout"], auth)


# --- image ------------------------------------------------------------------
def get_image(src, timeout, auth=None):
    if re.match(r"^https?://", src):
        req = urllib.request.Request(src, headers={"User-Agent": "AvianVisitors-frame/1.0"})
        if auth:
            req.add_header("Authorization", auth)
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return Image.open(io.BytesIO(r.read(20_000_000))).convert("RGB")
    return Image.open(os.path.expanduser(src)).convert("RGB")


class Geometry:
    """Panel size and layout fractions. The defaults are the 13.3" A5 frame;
    a bare 7.3" panel sets panel_size = [480, 800] and opens the window up."""

    def __init__(self, panel_size=(PANEL_W, PANEL_H), opening=0.7071, opening_ratio=SQRT2,
                 mat=0.0, title_frac=0.065, collage_frac=0.66, gap_frac=0.1):
        try:
            w, h = (int(v) for v in panel_size)
        except (TypeError, ValueError) as exc:
            raise ValueError("panel_size must be [width, height]") from exc
        if w <= 0 or h <= 0:
            raise ValueError("panel_size must be [width, height]")
        if isinstance(opening_ratio, bool) or not float(opening_ratio) > 0:
            raise ValueError("opening_ratio must be greater than 0")
        self.w, self.h = w, h
        self.opening, self.ratio, self.mat = opening, float(opening_ratio), mat
        self.title_frac, self.collage_frac, self.gap_frac = title_frac, collage_frac, gap_frac

    @classmethod
    def from_cfg(cls, cfg):
        return cls(cfg["panel_size"], cfg["opening"], cfg["opening_ratio"], cfg["mat"],
                   cfg["title_frac"], cfg["collage_frac"], cfg["gap_frac"])

    @property
    def size(self):
        return self.w, self.h

    def opening_size(self):
        return opening_size(self.opening, self.h, self.ratio, self.w)


def fit_panel(img, size=(PANEL_W, PANEL_H)):
    if img.size != tuple(size):
        img = img.resize(tuple(size), Image.LANCZOS)
    return img


def _paper(img):
    """Median of the four corners, robust to a stray inked corner."""
    w, h = img.size
    px = (img.getpixel(p) for p in ((4, 4), (w - 5, 4), (4, h - 5), (w - 5, h - 5)))
    return tuple(int(statistics.median(c)) for c in zip(*px))


# The opening is a centred rectangle, height/width = `ratio` (1:sqrt(2) for the
# A5 mat). `opening` sets how much of the panel height it covers; 0.7071
# preserves the A5 default. A panel too narrow for the requested height
# clamps the opening to the panel width.
def opening_size(opening, panel_h=PANEL_H, ratio=SQRT2, panel_w=None):
    if isinstance(opening, bool):
        raise ValueError("opening must be greater than 0 and at most 1")
    try:
        opening = float(opening)
    except (TypeError, ValueError) as exc:
        raise ValueError("opening must be greater than 0 and at most 1") from exc
    if not 0 < opening <= 1:
        raise ValueError("opening must be greater than 0 and at most 1")
    h = panel_h * opening
    w = h / ratio
    if panel_w is not None and w > panel_w * opening:
        w = panel_w * opening
        h = w * ratio
    return w, h


def _place(content, paper, geo):
    box_w, box_h = geo.opening_size()
    s = min(box_w * (1 - geo.mat) / content.width, box_h * (1 - geo.mat) / content.height)
    nw, nh = max(1, round(content.width * s)), max(1, round(content.height * s))
    content = content.resize((nw, nh), Image.LANCZOS)
    canvas = Image.new("RGB", geo.size, paper)
    canvas.paste(content, ((geo.w - nw) // 2, (geo.h - nh) // 2))
    return canvas


def _region_bbox(img, paper, y0, y1):
    region = img.crop((0, y0, img.width, y1))
    diff = ImageChops.difference(region, Image.new("RGB", region.size, paper))
    bb = diff.convert("L").point(lambda p: 255 if p > 34 else 0).getbbox()
    return None if not bb else (bb[0], y0 + bb[1], bb[2], y0 + bb[3])


def _scale_w(img, target_w):
    s = target_w / img.width
    return img.resize((max(1, round(img.width * s)), max(1, round(img.height * s))), Image.LANCZOS)


def _scale_h(img, target_h):
    s = target_h / img.height
    return img.resize((max(1, round(img.width * s)), max(1, round(img.height * s))), Image.LANCZOS)


def _centroid_x(img, paper):
    """Horizontal centre of ink weight (what the eye reads as centred)."""
    m = ImageChops.difference(img, Image.new("RGB", img.size, paper)).convert("L")
    cols = list(m.resize((img.width, 1), Image.BOX).tobytes())
    total = sum(cols) or 1
    return sum(x * v for x, v in enumerate(cols)) / total


def mat_and_center(img, geo):
    """Crop the title and collage, size each to a fraction of the opening,
    stack with a gap, and centre on the panel. The title and collage are sized
    independently (geo.title_frac of the opening height, geo.collage_frac of
    its width), so tuning one leaves the other untouched."""
    img = img.convert("RGB")
    paper = _paper(img)
    mask = ImageChops.difference(img, Image.new("RGB", img.size, paper))
    mask = mask.convert("L").point(lambda p: 255 if p > 34 else 0)
    full = mask.getbbox()
    if not full:
        return fit_panel(img, geo.size)
    levels = list(mask.resize((1, img.height), Image.BOX).tobytes())  # per-row content
    top, bot = full[1], full[3]
    split, run = None, 0
    for y in range(top, bot):
        if levels[y] <= 2:
            run += 1
            if run >= 60:  # split below the headline; a 60px band clears the ~30px eyebrow/headline gap so the title stays whole
                cy = y
                while cy < bot and levels[cy] <= 2:
                    cy += 1
                split = (y - run + 1, cy)
                break
        else:
            run = 0
    tb = _region_bbox(img, paper, top, split[0]) if split else None
    cb = _region_bbox(img, paper, split[1], bot + 1) if split else None
    ow, oh = geo.opening_size()
    box_w, box_h = ow * (1 - geo.mat), oh * (1 - geo.mat)
    if not (tb and cb):
        return _place(img.crop(full), paper, geo)
    title = _scale_h(img.crop(tb), box_h * geo.title_frac)
    if title.width > box_w:  # a long title on a narrow panel: fit the width instead
        title = _scale_w(title, box_w)
    gap = round(box_h * geo.gap_frac)
    # Size the collage to fill the room left under the fixed-size title,
    # binding on whichever of width or remaining height runs out first, so the
    # title stays a consistent size whether the collage is tall or compact
    # instead of ballooning when the collage happens to be short.
    coll = img.crop(cb)
    cs = min(box_w * geo.collage_frac / coll.width, (box_h - title.height - gap) / coll.height)
    collage = coll.resize((max(1, round(coll.width * cs)), max(1, round(coll.height * cs))), Image.LANCZOS)
    ccx = _centroid_x(collage, paper)  # centre the collage by ink weight, not bbox
    half = max(ccx, collage.width - ccx)
    # A wildly off-centre collage can push the centroid-mirrored width (2*half)
    # past the opening; shrink only the collage, never the fixed-size title,
    # so nothing spills under the physical mat.
    if 2 * half > box_w:
        s = box_w / (2 * half)
        collage = collage.resize((max(1, round(collage.width * s)), max(1, round(collage.height * s))), Image.LANCZOS)
        ccx = round(ccx * s)
        half = max(ccx, collage.width - ccx)
    cw = round(max(title.width, 2 * half))
    comp = Image.new("RGB", (cw, title.height + gap + collage.height), paper)
    comp.paste(title, ((cw - title.width) // 2, 0))
    comp.paste(collage, (round(cw / 2 - ccx), title.height + gap))
    canvas = Image.new("RGB", geo.size, paper)
    canvas.paste(comp, ((geo.w - comp.width) // 2, (geo.h - comp.height) // 2))
    return canvas


def _ink_palette(inks):
    pal = Image.new("P", (1, 1))
    flat = [c for ink in inks for c in ink]
    flat += list(inks[0]) * ((768 - len(flat)) // 3)  # pad the 256-entry palette with paper
    pal.putpalette(flat[:768])
    return pal


NEUTRAL_CHROMA = 28  # max-min channel spread below which a pixel is grey


def tune_for_inks(img, saturation=1.35, contrast=1.12):
    """Push the picture toward what six inks can show before dithering: more
    saturation and contrast so most pixels land near a single ink and need
    less mixing, and a light sharpen to keep feather detail through the grain."""
    img = ImageEnhance.Color(img).enhance(saturation)
    img = ImageEnhance.Contrast(img).enhance(contrast)
    return img.filter(ImageFilter.UnsharpMask(radius=1.2, percent=70, threshold=2))


# (dx, dy) neighbours, each taking 1/8 of the error. Atkinson spreads only 6/8
# of it, so flat areas settle on a single ink instead of a busy dot pattern,
# which reads cleaner on e-paper than Floyd-Steinberg.
_ATKINSON = ((1, 0), (2, 0), (-1, 1), (0, 1), (1, 1), (0, 2))


def _atkinson(rgb, inks):
    """Serpentine Atkinson dither of `rgb` onto `inks`; returns a P image
    indexing `inks` in order. Plain Python, as the server has no numpy."""
    w, h = rgb.size
    data = rgb.tobytes()
    r, g, b = [float(v) for v in data[0::3]], [float(v) for v in data[1::3]], [float(v) for v in data[2::3]]
    out = bytearray(w * h)
    nearest = {}
    for y in range(h):
        step = -1 if y % 2 else 1
        for x in (range(w - 1, -1, -1) if step < 0 else range(w)):
            i = y * w + x
            pr = min(255.0, max(0.0, r[i]))
            pg = min(255.0, max(0.0, g[i]))
            pb = min(255.0, max(0.0, b[i]))
            key = (int(pr) >> 2, int(pg) >> 2, int(pb) >> 2)
            k = nearest.get(key)
            if k is None:
                k = min(range(len(inks)), key=lambda j: (inks[j][0] - pr) ** 2
                        + (inks[j][1] - pg) ** 2 + (inks[j][2] - pb) ** 2)
                nearest[key] = k
            out[i] = k
            ir, ig, ib = inks[k]
            er, eg, eb = (pr - ir) / 8, (pg - ig) / 8, (pb - ib) / 8
            for dx, dy in _ATKINSON:
                xx, yy = x + dx * step, y + dy
                if 0 <= xx < w and yy < h:
                    j = yy * w + xx
                    r[j] += er
                    g[j] += eg
                    b[j] += eb
    img = Image.frombytes("P", (w, h), bytes(out))
    img.putpalette(_ink_palette(inks).getpalette())
    return img


# 8x8 Bayer matrix: thresholds 0-63 spread so any count of them is evenly spaced.
_BAYER8 = ((0, 32, 8, 40, 2, 34, 10, 42), (48, 16, 56, 24, 50, 18, 58, 26),
           (12, 44, 4, 36, 14, 46, 6, 38), (60, 28, 52, 20, 62, 30, 54, 22),
           (3, 35, 11, 43, 1, 33, 9, 41), (51, 19, 59, 27, 49, 17, 57, 25),
           (15, 47, 7, 39, 13, 45, 5, 37), (63, 31, 55, 23, 61, 29, 53, 21))
_YELLOW = 3  # SPECTRA6 index


def warm_paper(dithered, warmth):
    """Dot a `warmth` share of the open paper yellow, in an even ordered
    pattern, so the background reads cream instead of the panel's flat white.
    Pixels within 3 px of any ink stay plain, keeping edges and type clean."""
    level = round(min(1.0, max(0.0, float(warmth))) * 64)
    if not level:
        return dithered
    w, h = dithered.size
    data = dithered.tobytes()
    near_ink = Image.frombytes("L", (w, h), bytes(0 if v == 0 else 255 for v in data))
    near_ink = near_ink.filter(ImageFilter.MaxFilter(7)).tobytes()
    out = bytearray(data)
    for y in range(h):
        row, base = _BAYER8[y % 8], y * w
        for x in range(w):
            if row[x % 8] < level and not near_ink[base + x]:
                out[base + x] = _YELLOW
    img = Image.frombytes("P", (w, h), bytes(out))
    img.putpalette(dithered.getpalette())
    return img


def dither_spectra6(img, warmth=0.0, saturation=1.35, contrast=1.12):
    """Tune, then Atkinson-dither onto the approximate real inks; returns a P
    image whose indexes 0-5 are SPECTRA6 order. Dithering against what the
    panel actually shows, not pure RGB, keeps the paper tone and muted colours
    honest.

    Grey pixels (text, outlines, antialiasing) dither on paper and black only:
    against the full palette a mid grey sits nearer the dark blue ink than
    black, which turns thin type and bird names blue and ragged."""
    rgb = tune_for_inks(img.convert("RGB"), saturation, contrast)
    colour = _atkinson(rgb, SPECTRA6)
    r, g, b = rgb.split()
    hi = ImageChops.lighter(ImageChops.lighter(r, g), b)
    lo = ImageChops.darker(ImageChops.darker(r, g), b)
    grey = ImageChops.subtract(hi, lo).point(lambda p: 255 if p < NEUTRAL_CHROMA else 0)
    if grey.getbbox():
        mono = _atkinson(rgb, SPECTRA6[:2])
        # Both images index paper as 0 and black as 1, so they composite directly.
        colour = Image.composite(mono, colour, grey)
    return warm_paper(colour, warmth)


def quantize_spectra6(img, **look):
    return dither_spectra6(img, **look).convert("RGB")


def ink_look(cfg):
    """dither_spectra6() keyword arguments from a config."""
    return {"warmth": cfg["paper_warmth"], "saturation": cfg["ink_saturation"],
            "contrast": cfg["ink_contrast"]}


def _draw_mat_box(img, geo):
    """Dev aid: outline the configured mat opening."""
    ow, oh = geo.opening_size()
    x0, y0 = round((geo.w - ow) / 2), round((geo.h - oh) / 2)
    ImageDraw.Draw(img).rectangle((x0, y0, geo.w - x0 - 1, geo.h - y0 - 1),
                                  outline=(170, 60, 56), width=2)


# --- ESPHome export ---------------------------------------------------------
def _write_atomic(path, data):
    tmp = path + ".tmp"
    with open(tmp, "wb") as f:
        f.write(data)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)


def _pure_png(dithered):
    """Remap a dither_spectra6() image onto the six pure colours, as PNG bytes."""
    pure = dithered.copy()
    pure.putpalette([c for ink in SPECTRA6_PURE for c in ink] + list(SPECTRA6_PURE[0]) * (256 - len(SPECTRA6_PURE)))
    buf = io.BytesIO()
    pure.convert("RGB").save(buf, "PNG", optimize=True)
    return buf.getvalue()


def _png(img):
    buf = io.BytesIO()
    img.convert("RGB").save(buf, "PNG", optimize=True)
    return buf.getvalue()


# variant -> (panel image, browser preview, frame.json key)
EXPORT_VARIANTS = {"plain": ("frame.png", "preview.png", "sig"),
                   "names": ("frame-names.png", "preview-names.png", "sig_names")}


def export_esphome(images, export_dir, species_sig=None, look=None):
    """Write the frame for an ESP32 running ESPHome to fetch.

    `images` maps "plain" (names off) and "names" (names on) to a laid-out
    panel image, or to None to keep that variant's last export. For each:
    frame[-names].png is pre-dithered to the six pure Spectra-6 colours so
    the ESP32 draws it pixel for pixel; preview[-names].png is the same dither
    in approximate real inks, for a browser. frame.json carries
    {"sig", "sig_names", "species_sig", "updated", "width", "height"}; the
    ESP32 polls it and downloads an image only when that variant's sig moves.
    A sig is a hash of the image itself, so it changes with any visible change
    and nothing else. frame.json is written last, so a reader that sees a new
    sig always finds the matching image already in place."""
    export_dir = os.path.expanduser(export_dir)
    os.makedirs(export_dir, exist_ok=True)
    try:
        with open(os.path.join(export_dir, "frame.json")) as f:
            meta = json.load(f)
    except Exception:
        meta = {}
    for variant, (png_name, preview_name, key) in EXPORT_VARIANTS.items():
        img = images.get(variant)
        if img is None:
            continue
        dithered = dither_spectra6(img, **(look or {}))
        frame_png = _pure_png(dithered)
        _write_atomic(os.path.join(export_dir, png_name), frame_png)
        _write_atomic(os.path.join(export_dir, preview_name), _png(dithered))
        meta[key] = hashlib.sha256(frame_png).hexdigest()[:16]
        meta["width"], meta["height"] = img.width, img.height
    meta["species_sig"] = species_sig
    meta["updated"] = int(time.time())
    _write_atomic(os.path.join(export_dir, "frame.json"), json.dumps(meta, sort_keys=True).encode())
    return meta


# --- hardware ---------------------------------------------------------------
def push_panel(img, rotate, saturation, panel=""):
    """Rotate to the panel's landscape buffer and push. Lazy import so this
    module still loads on a machine without the Inky library."""
    if rotate not in (90, 270):
        print(f"rotate must be 90 or 270, not {rotate}; using 90", file=sys.stderr)
        rotate = 90
    if panel == "el133uf1":
        from inky.inky_el133uf1 import Inky
        dev = Inky(resolution=(1600, 1200))
    else:
        from inky.auto import auto
        dev = auto()
    buf = img.rotate(rotate, expand=True)
    if buf.size != (dev.width, dev.height):
        buf = buf.resize((dev.width, dev.height), Image.LANCZOS)
    kw = {"saturation": saturation} if "saturation" in inspect.signature(dev.set_image).parameters else {}
    dev.set_image(buf, **kw)
    dev.show()


# --- state ------------------------------------------------------------------
def load_state(path):
    try:
        with open(os.path.expanduser(path)) as f:
            return json.load(f)
    except Exception:
        return {"signature": None, "last_refresh": 0}


def save_state(path, sig, when, web=None):
    path = os.path.expanduser(path)
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump({"signature": sig, "last_refresh": when, "web": web}, f)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)  # atomic: a power cut can't leave a half-written file


def in_quiet_hours(cfg, hour):
    s, e = cfg["quiet_start"], cfg["quiet_end"]
    if s == e:
        return False
    return s <= hour < e if s < e else hour >= s or hour < e


def frame_url(url, bird_names):
    """Set the frame's label preference without disturbing other URL state."""
    import urllib.parse
    parts = urllib.parse.urlsplit(url)
    query = [(k, v) for k, v in urllib.parse.parse_qsl(parts.query, keep_blank_values=True)
             if k != "labels"]
    query.append(("labels", "1" if bird_names else "0"))
    return urllib.parse.urlunsplit(parts._replace(query=urllib.parse.urlencode(query)))


# --- run --------------------------------------------------------------------
def shoot_viewport(geo):
    """CSS viewport for the screenshot: 800 CSS px tall at the panel's aspect,
    captured at 2x. The 13.3" panel keeps its original 600x800."""
    return {"vw": max(1, round(800 * geo.w / geo.h)), "vh": 800, "dsf": 2}


def obtain_image(cfg, species=None, bird_names=None):
    if bird_names is None:
        bird_names = cfg["bird_names"]
    look = dict(shoot_viewport(Geometry.from_cfg(cfg)), label_min_px=cfg["shoot_label_min_px"])
    if cfg.get("species_source") == "birdweather":
        from shoot import shoot_birdweather
        if species is None:  # gate skipped (--no-signature): fetch the list to render
            species = fetch_species(cfg, _auth(cfg))
        out = os.path.join(os.path.expanduser(cfg["cache"]), "frame.png")
        os.makedirs(os.path.dirname(out), exist_ok=True)
        shoot_birdweather(out, species, title=cfg["shoot_title"], subtitle=cfg["shoot_subtitle"],
                          timeout_ms=cfg["timeout"] * 1000, bird_names=bird_names, **look)
        return Image.open(out).convert("RGB")
    if cfg["shoot"]:
        from shoot import shoot
        out = os.path.join(os.path.expanduser(cfg["cache"]), "shot.png")
        os.makedirs(os.path.dirname(out), exist_ok=True)
        shoot(cfg["base_url"], out, title=cfg["shoot_title"], subtitle=cfg["shoot_subtitle"],
              headline_px=cfg["shoot_headline_px"], eyebrow_px=cfg["shoot_eyebrow_px"],
              lowercase=cfg["shoot_lowercase"], mat=cfg["shoot_mat"],
              small_floor=cfg["shoot_small_floor"], count_exp=cfg["shoot_count_exp"], timeout_ms=cfg["timeout"] * 1000,
              user=cfg["basic_user"], password=cfg["basic_pass"], window_hours=cfg["hours"],
              bird_names=bird_names, **look)
        return Image.open(out).convert("RGB")
    src = cfg["image_url"] or cfg["image"]
    if not src:
        raise ValueError("set image, image_url, or shoot in config")
    # A pre-rendered frame is still someone's render, so ask it for names the
    # same way this Pi asks its own browser. A source that does not know the
    # parameter ignores it and sends what it always sent, so this is safe
    # against anything. URLs only: a local file path has no query string.
    if cfg["image_url"]:
        src = frame_url(src, bird_names)
    return get_image(src, cfg["timeout"], _auth(cfg))


def render(cfg, geo, species=None, bird_names=None):
    """Obtain the collage and lay it out on the panel. The source is first
    normalised to 1600 px tall at the panel's aspect (a no-op for the 13.3"
    and for a matching screenshot), the scale mat_and_center's split
    heuristic was tuned at."""
    img = obtain_image(cfg, species, bird_names)
    img = fit_panel(img, (max(1, round(1600 * geo.w / geo.h)), 1600))
    return mat_and_center(img, geo)


def _export_both(cfg, geo, species, sig):
    """ESPHome output: render names off and names on. The plain image is the
    one that must exist; a names render that fails keeps its last export."""
    images = {"plain": render(cfg, geo, species, bird_names=False)}
    try:
        images["names"] = render(cfg, geo, species, bird_names=True)
    except Exception as e:
        print(f"names render failed, keeping the last one: {e}", file=sys.stderr)
    meta = export_esphome(images, cfg["export_dir"], sig, ink_look(cfg))
    print(f"exported to {cfg['export_dir']}: sig {meta.get('sig')} names {meta.get('sig_names')}")


def _gate(cfg, state, now, preview, force, use_signature):
    """Decide whether this run refreshes. Returns (go, sig, species)."""
    sig = None
    species = None
    if use_signature:
        try:
            species = fetch_species(cfg, _auth(cfg))
            scope = birdweather_signature_scope(cfg) if cfg.get("species_source") == "birdweather" else ""
            sig = signature(species, scope)
        except Exception as e:
            print(f"signature fetch failed: {e}", file=sys.stderr)  # treat as no change
    heal_due = now - state.get("last_refresh", 0) >= cfg["heal_hours"] * 3600
    changed = (not use_signature) or (sig is not None and sig != state.get("signature"))
    if not force and not preview:
        if in_quiet_hours(cfg, datetime.now().hour):
            print("quiet hours; skip")
            return False, sig, species
        if not changed and not heal_due:
            print("no change; skip")
            return False, sig, species
        print("refresh:", "changed" if changed else "heal")
    return True, sig, species


def run(cfg, preview=None, force=False, use_signature=True, mat_box=False):
    now = time.time()
    state = load_state(cfg["state"])
    web = cfg.get("_web_sig")
    if web is not None and web != state.get("web") and not preview:
        print("website settings changed or a refresh was asked for")
        force = True
    go, sig, species = _gate(cfg, state, now, preview, force, use_signature)
    if not go:
        return
    try:
        geo = Geometry.from_cfg(cfg)
    except ValueError as e:
        print(f"bad panel config: {e}", file=sys.stderr)
        return
    if cfg["output"] == "esphome" and not preview:
        try:
            _export_both(cfg, geo, species, sig)
        except Exception as e:
            print(f"could not render: {e}", file=sys.stderr)  # the ESP32 keeps the last export
            return
        save_state(cfg["state"], sig if sig is not None else state.get("signature"), now, web)
        return
    try:
        img = render(cfg, geo, species)
    except Exception as e:
        print(f"could not get image: {e}", file=sys.stderr)  # keep last panel image
        return
    if preview:
        out = quantize_spectra6(img, **ink_look(cfg))
        if mat_box:
            _draw_mat_box(out, geo)
        out.save(preview)
        print(f"wrote preview {preview}")
        return
    try:
        push_panel(img, cfg["rotate"], cfg["saturation"], cfg.get("panel", ""))
    except Exception as e:
        print(f"panel push failed: {e}", file=sys.stderr)
        return
    save_state(cfg["state"], sig if sig is not None else state.get("signature"), now, web)
    print("panel updated")


def load_config(path):
    cfg = dict(DEFAULTS)
    if path:
        with open(os.path.expanduser(path), "rb") as f:
            cfg.update(tomllib.load(f))
    return cfg


WEB_SCHEMA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "web_settings.json")


def web_settings(web_dir, schema_path=WEB_SCHEMA):
    """Settings saved from the website (avian/api/frame.php), checked against
    web_settings.json, plus a signature over them and the last refresh
    request. Returns ({}, None) when the site has saved nothing."""
    web_dir = os.path.expanduser(web_dir or "")
    try:
        with open(os.path.join(web_dir, "settings.json")) as f:
            saved = json.load(f)
    except (OSError, ValueError):
        saved = None
    try:
        with open(os.path.join(web_dir, "refresh")) as f:
            refresh = f.read(64).strip()
    except OSError:
        refresh = ""
    if not isinstance(saved, dict) and not refresh:
        return {}, None
    with open(schema_path) as f:
        schema = json.load(f)["settings"]
    out = {}
    for key, value in (saved if isinstance(saved, dict) else {}).items():
        spec = schema.get(key)
        if spec is None or isinstance(value, bool):
            continue
        if spec["type"] == "string":
            if isinstance(value, str):
                out[key] = "".join(ch for ch in value if ch.isprintable())[:spec["maxlen"]]
        elif isinstance(value, (int, float)):
            value = min(spec["max"], max(spec["min"], value))
            out[key] = int(value) if spec["type"] == "int" else float(value)
    sig = hashlib.sha256(json.dumps([out, refresh], sort_keys=True).encode()).hexdigest()[:16]
    return out, sig


def main():
    ap = argparse.ArgumentParser(description="Push the collage screenshot to the Inky panel.")
    ap.add_argument("--config")
    ap.add_argument("--base-url")
    ap.add_argument("--image")
    ap.add_argument("--image-url")
    ap.add_argument("--preview", help="write a 6-ink preview PNG instead of pushing")
    ap.add_argument("--rotate", type=int)
    ap.add_argument("--force", action="store_true", help="refresh even if unchanged")
    ap.add_argument("--no-signature", action="store_true", help="skip change detection")
    ap.add_argument("--mat-box", action="store_true", help="dev: outline the mat window on the preview")
    args = ap.parse_args()

    cfg = load_config(args.config)
    for key in ("base_url", "image", "image_url"):
        val = getattr(args, key)
        if val:
            cfg[key] = val
    if args.rotate is not None:
        cfg["rotate"] = args.rotate
    try:
        saved, cfg["_web_sig"] = web_settings(cfg["web_dir"])
        cfg.update(saved)
    except Exception as e:
        print(f"website settings ignored: {e}", file=sys.stderr)
    # One render at a time. A manual --force colliding with the timer's run
    # pushes two refreshes into the panel mid-cycle; on the 13.3" (two
    # half-panel controllers) that shows a split image and can wedge one
    # controller until a full power cycle. The lock lives in the cache dir
    # and is dropped automatically on exit.
    lock_path = os.path.join(os.path.expanduser(cfg["cache"]), ".render.lock")
    os.makedirs(os.path.dirname(lock_path), exist_ok=True)
    lock = open(lock_path, "w")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        print("another render is in progress; skipping")
        return
    run(cfg, preview=args.preview, force=args.force, use_signature=not args.no_signature, mat_box=args.mat_box)


if __name__ == "__main__":
    main()
