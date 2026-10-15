"""Redraw a bird illustration in the Evolutionary Impressionist style.

A thin, wobbly outline round the silhouette, then one looping marker scribble
per main colour, each steered into where that colour sits. Several scribbles
are tried per colour and the one whose ink best matches the colour's region
is kept.

    ~/.cache/avian-dev-venv/bin/python dev/impressionist.py in.png out.png [--seed N]

Source: our own (Woodblock) illustration of the bird. A prototype: the
output is not installed anywhere yet.
"""
import argparse
import math

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

CANVAS = 2048
PLAN = 512                      # planning resolution
BOX = 0.84                      # bird's longest side as a share of the canvas


# --- small image helpers -----------------------------------------------------
def erode(mask, n):
    im = Image.fromarray((mask * 255).astype(np.uint8))
    for _ in range(n):
        im = im.filter(ImageFilter.MinFilter(3))
    return np.array(im) > 127


def dilate(mask, n):
    im = Image.fromarray((mask * 255).astype(np.uint8))
    for _ in range(n):
        im = im.filter(ImageFilter.MaxFilter(3))
    return np.array(im) > 127


def depth_map(mask, levels=40):
    """Rough distance to the region's edge, in planning pixels."""
    d = np.zeros(mask.shape, np.float32)
    cur = mask.copy()
    for i in range(levels):
        if not cur.any():
            break
        d += cur
        cur = erode(cur, 1)
    return d


def smooth1d(a, k, wrap=True):
    if k <= 1:
        return a
    ker = np.ones(k) / k
    if wrap:
        pad = np.concatenate([a[-k:], a, a[:k]])
        return np.convolve(pad, ker, "same")[k:-k]
    pad = np.concatenate([np.repeat(a[:1], k), a, np.repeat(a[-1:], k)])
    return np.convolve(pad, ker, "same")[k:-k]


def noise1d(n, scale, rng, wrap=True):
    """Smooth random signal of length n, features about `scale` samples long."""
    knots = max(4, int(n / max(scale, 1)) + 1)
    v = rng.normal(0, 1, knots + (0 if wrap else 1))
    if wrap:
        v = np.append(v, v[0])
    x = np.linspace(0, knots if wrap else knots, n, endpoint=False if wrap else True)
    i = np.floor(x).astype(int)
    f = x - i
    f = f * f * (3 - 2 * f)
    return v[i] * (1 - f) + v[np.minimum(i + 1, len(v) - 1)] * f


# --- colour ------------------------------------------------------------------
def to_lab(rgb):
    c = rgb / 255.0
    c = np.where(c > 0.04045, ((c + 0.055) / 1.055) ** 2.4, c / 12.92)
    m = np.array([[0.4124, 0.3576, 0.1805], [0.2126, 0.7152, 0.0722], [0.0193, 0.1192, 0.9505]])
    xyz = c @ m.T / np.array([0.9505, 1.0, 1.089])
    f = np.where(xyz > 0.008856, np.cbrt(xyz), 7.787 * xyz + 16 / 116)
    return np.stack([116 * f[:, 1] - 16, 500 * (f[:, 0] - f[:, 1]), 200 * (f[:, 1] - f[:, 2])], 1)


def kmeans(x, k, rng, iters=25):
    cent = x[rng.choice(len(x), k, replace=False)]
    for _ in range(iters):
        lab = np.argmin(((x[:, None] - cent[None]) ** 2).sum(-1), 1)
        for j in range(k):
            if (lab == j).any():
                cent[j] = x[lab == j].mean(0)
    return cent, lab


def marker(rgb):
    """Push a cluster's average towards a marker colour: cleaner, a little
    more saturated, the darks darker."""
    r, g, b = [v / 255 for v in rgb]
    mx, mn = max(r, g, b), min(r, g, b)
    l = (mx + mn) / 2
    s = 0 if mx == mn else (mx - mn) / (1 - abs(2 * l - 1))
    if l < 0.25:
        l *= 0.55
    s = min(1, s * 1.35)
    # back to rgb keeping hue
    if mx == mn:
        h = 0
    elif mx == r:
        h = ((g - b) / (mx - mn)) % 6
    elif mx == g:
        h = (b - r) / (mx - mn) + 2
    else:
        h = (r - g) / (mx - mn) + 4
    c = (1 - abs(2 * l - 1)) * s
    x = c * (1 - abs(h % 2 - 1))
    m = l - c / 2
    rr, gg, bb = [(c, x, 0), (x, c, 0), (0, c, x), (0, x, c), (x, 0, c), (c, 0, x)][int(h) % 6]
    return tuple(int(round(255 * min(1, max(0, v + m)))) for v in (rr, gg, bb))


# --- geometry ----------------------------------------------------------------
def trace(mask):
    """Moore trace of the biggest piece's outer edge (planning pixels)."""
    h, w = mask.shape
    lab = np.zeros(mask.shape, np.int32)
    best, start, nid = 0, None, 0
    ys, xs = np.nonzero(mask)
    for y0, x0 in zip(ys, xs):
        if lab[y0, x0]:
            continue
        nid += 1
        st = [(y0, x0)]
        lab[y0, x0] = nid
        n = 0
        while st:
            y, x = st.pop()
            n += 1
            for dy in (-1, 0, 1):
                for dx in (-1, 0, 1):
                    yy, xx = y + dy, x + dx
                    if 0 <= yy < h and 0 <= xx < w and mask[yy, xx] and not lab[yy, xx]:
                        lab[yy, xx] = nid
                        st.append((yy, xx))
        if n > best:
            best, start = n, (x0, y0)
    nb = [(-1, 0), (-1, -1), (0, -1), (1, -1), (1, 0), (1, 1), (0, 1), (-1, 1)]
    at = lambda x, y: 0 <= x < w and 0 <= y < h and mask[y, x]
    sx, sy = start
    pts = [(sx, sy)]
    bx, by, cx, cy = sx, sy, sx - 1, sy
    for _ in range(200000):
        k0 = nb.index((cx - bx, cy - by))
        moved = False
        for t in range(1, 9):
            j = (k0 + t) % 8
            nx, ny = bx + nb[j][0], by + nb[j][1]
            if at(nx, ny):
                if (bx, by) == (sx, sy) and len(pts) > 1 and (nx, ny) == pts[1]:
                    break
                cx, cy = bx + nb[(j + 7) % 8][0], by + nb[(j + 7) % 8][1]
                bx, by, moved = nx, ny, True
                break
        if not moved:
            break
        if (bx, by) != (sx, sy):
            pts.append((bx, by))
    return np.array(pts, np.float32)


def resample(pts, step, closed=True):
    if closed:
        pts = np.vstack([pts, pts[:1]])
    seg = np.hypot(*np.diff(pts, axis=0).T)
    s = np.concatenate([[0], np.cumsum(seg)])
    t = np.arange(0, s[-1], step)
    return np.stack([np.interp(t, s, pts[:, 0]), np.interp(t, s, pts[:, 1])], 1)


def outline_pieces(mask, rng, scale):
    """The contour as 1-3 wobbly runs, with a width profile for each."""
    pts = resample(trace(mask), 1.0)
    pts = np.stack([smooth1d(pts[:, 0], 3), smooth1d(pts[:, 1], 3)], 1)
    n = len(pts)
    # normals for the wobble
    d = np.roll(pts, -2, 0) - np.roll(pts, 2, 0)
    nrm = np.stack([-d[:, 1], d[:, 0]], 1)
    nrm /= np.linalg.norm(nrm, axis=1, keepdims=True) + 1e-6
    wob = 1.4 * noise1d(n, 40, rng) + 0.5 * noise1d(n, 6, rng)
    pts = pts + nrm * wob[:, None]
    width = np.clip(0.5 + 0.4 * noise1d(n, 120, rng), 0, 1)   # 0..1 profile
    # break the loop in a couple of places
    gaps = rng.integers(0, 3)
    cuts = sorted(rng.choice(n, gaps, replace=False)) if gaps else []
    pieces = []
    if not cuts:
        pieces.append((np.vstack([pts, pts[:3]]), np.concatenate([width, width[:3]])))
    else:
        cuts = list(cuts)
        for a, b in zip(cuts, cuts[1:] + [cuts[0] + n]):
            idx = np.arange(a + int(rng.integers(4, 14)), b) % n
            if len(idx) > 10:
                pieces.append((pts[idx], width[idx]))
    return pieces


def scribble(region, depth, rng, length, turn, bound, speed=1.0):
    """A steered pen walk inside `region` (planning pixels): head for a target
    chosen among the least-inked parts of the region, with a turn-rate limit,
    so the pen overshoots and loops like a marker scribble."""
    h, w = region.shape
    ys, xs = np.nonzero(region)
    if len(xs) == 0:
        return None
    weight = depth[ys, xs] ** 0.7 + 0.2
    inked = np.zeros(region.shape, np.float32)
    i0 = rng.choice(len(xs), p=weight / weight.sum())
    p = np.array([xs[i0], ys[i0]], np.float32)
    ang = rng.uniform(0, 2 * math.pi)
    omega = 0.0                  # turn rate carries over: the hand swings round
    path = [p.copy()]
    target = None
    steps = int(length / speed)
    # loop size drifts as the hand moves: some tight, some wide and oval
    size = np.clip(1.0 + 0.55 * noise1d(steps + 1, 90, rng, wrap=False), 0.45, 2.2)
    for step in range(steps):
        if target is None or np.hypot(*(target - p)) < 14 or rng.random() < 0.004:
            cand = rng.choice(len(xs), 24, p=weight / weight.sum())
            score = inked[ys[cand], xs[cand]] + rng.random(24) * 0.3
            j = cand[np.argmin(score)]
            target = np.array([xs[j], ys[j]], np.float32)
        want = math.atan2(target[1] - p[1], target[0] - p[0])
        diff = (want - ang + math.pi) % (2 * math.pi) - math.pi
        # steer the turn rate, not the heading, so the pen comes round in
        # arcs and overshoots into loops instead of driving straight there
        omega = 0.93 * omega + 0.012 * diff + rng.normal(0, 0.004)
        lim = turn / size[step]
        omega = max(-lim, min(lim, omega))
        ang += omega
        # the outline is a wall: look a few steps ahead and turn back towards
        # the target, hard, rather than cross it
        for _ in range(12):
            ahead = p + 6 * speed * np.array([math.cos(ang), math.sin(ang)], np.float32)
            ax, ay = int(ahead[0]), int(ahead[1])
            if 0 <= ax < w and 0 <= ay < h and bound[ay, ax]:
                break
            ang += math.copysign(0.25, diff if diff else 1.0)
            omega = 0.0
        p = p + speed * np.array([math.cos(ang), math.sin(ang)], np.float32)
        x, y = int(p[0]), int(p[1])
        if not (0 <= x < w and 0 <= y < h) or depth[y, x] == 0:
            # off the region: swing back harder
            omega = max(-turn, min(turn, omega + 0.02 * diff))
        if 0 <= x < w and 0 <= y < h:
            inked[max(0, y - 3):y + 4, max(0, x - 3):x + 4] += 1
        path.append(p.copy())
    path = np.array(path)
    # a little hand wobble across the line
    d = np.gradient(path, axis=0)
    nrm = np.stack([-d[:, 1], d[:, 0]], 1) / (np.linalg.norm(d, axis=1, keepdims=True) + 1e-6)
    wob = 0.8 * noise1d(len(path), 14, rng, wrap=False) + 0.3 * noise1d(len(path), 4, rng, wrap=False)
    return path + nrm * wob[:, None]


def path_mask(path, shape, width):
    im = Image.new("L", (shape[1], shape[0]), 0)
    ImageDraw.Draw(im).line([tuple(q) for q in path], fill=255, width=max(1, int(round(width))), joint="curve")
    return np.array(im) > 127


# --- main --------------------------------------------------------------------
def render(src, seed=1, n_colours=None, tries=12):
    rng = np.random.default_rng(seed)
    rgba = src.convert("RGBA")
    a = np.array(rgba)[..., 3]
    ys, xs = np.nonzero(a > 128)
    crop = rgba.crop((xs.min(), ys.min(), xs.max() + 1, ys.max() + 1))
    side = max(crop.size)
    s = PLAN * BOX / side
    plan = Image.new("RGBA", (PLAN, PLAN), (0, 0, 0, 0))
    sm = crop.resize((max(1, round(crop.width * s)), max(1, round(crop.height * s))), Image.LANCZOS)
    off = ((PLAN - sm.width) // 2, (PLAN - sm.height) // 2)
    plan.paste(sm, off)
    arr = np.array(plan).astype(np.float32)
    mask = arr[..., 3] > 128
    body = erode(mask, 2)
    core = erode(mask, 9)
    inside = erode(mask, 6)       # where a fill line's centre may go

    # main colours, from the bird minus its drawn outline
    inner = erode(mask, 4)
    pix = arr[..., :3][inner]
    lab = to_lab(pix)
    k = 8
    cent, lbl = kmeans(lab, k, rng)
    full = np.full(mask.shape, -1)
    full[inner] = lbl
    # merge clusters that are close, drop tiny ones
    groups = []
    for j in np.argsort(-np.bincount(lbl, minlength=k)):
        share = (lbl == j).mean()
        chroma = math.hypot(cent[j][1], cent[j][2])
        if share < 0.025 and not (share > 0.0015 and chroma > 35):
            continue
        for g in groups:
            if np.linalg.norm(cent[g[0]] - cent[j]) < 26:
                g.append(j)
                break
        else:
            groups.append([j])
    groups = groups[: n_colours or 3]
    # (region, source pixels) for each colour to scribble
    patches = [(np.isin(full, g), np.isin(lbl, g), False) for g in groups]
    # One vivid accent (a red crown, a yellow rump), found by colour rather
    # than by cluster, since on a small pose it is too few pixels to win one.
    chroma = np.hypot(lab[:, 1], lab[:, 2])
    if not any(np.median(chroma[sel]) > 40 for _, sel, _ in patches):
        vivid = chroma > 45
        if vivid.mean() > 0.0015:
            hue = np.arctan2(lab[vivid, 2], lab[vivid, 1])
            main = np.abs((hue - np.median(hue) + math.pi) % (2 * math.pi) - math.pi) < 0.5
            sel = np.zeros(len(lab), bool)
            sel[np.nonzero(vivid)[0][main]] = True
            reg = np.zeros(mask.shape, bool)
            reg[inner] = sel
            patches.append((reg, sel, True))

    scale = CANVAS / PLAN
    fill_w = 7.0                     # planning px (~28 px on the canvas)
    layers = []
    for region, sel, accent in patches:
        if accent:
            # an accent squiggles a little past its patch, as the red does
            region = dilate(region, 7) & body
        else:
            region = dilate(erode(dilate(region, 2), 3), 4) & core   # tidy speckle, loose placement
        if region.sum() < 25:
            continue
        rgb = np.median(pix[sel], 0)
        colour = marker(tuple(rgb))
        depth = depth_map(region)
        area = region.sum()
        length = min(3400, 12 * math.sqrt(area) + 0.12 * area / fill_w)
        if accent:
            length = max(length, 260)
        best = None
        for t in range(tries):
            # loops about a third of the colour's patch across
            radius = max(8.0, rng.uniform(0.16, 0.26) * math.sqrt(area))
            turn = 1.0 / radius
            path = scribble(region, depth, rng, length, turn, body if accent else inside)
            if path is None:
                continue
            ink = path_mask(path, region.shape, fill_w)
            # coverage of the colour's region, minus ink spilled off the bird
            # and onto other colours
            score = ((ink & region).sum() - 0.3 * (ink & body & ~region).sum()
                     - 3.0 * (ink & ~body).sum()) / area
            if best is None or score > best[0]:
                best = (score, path)
        if best:
            layers.append((area, colour, best[1]))

    # outline colour: the darkest marker colour, pulled to a warm grey-black
    dark = min((c for _, c, _ in layers), key=sum, default=(40, 38, 44))
    ol = tuple(int(0.55 * v + 0.45 * w_) for v, w_ in zip(dark, (52, 48, 54)))

    big = CANVAS * 2
    f = big / PLAN
    out = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    dr = ImageDraw.Draw(out)
    for piece, wprof in outline_pieces(mask, rng, scale):
        # draw in short runs so the width can vary along the line
        for i in range(0, len(piece) - 1, 6):
            seg = piece[i:i + 7] * f
            wdt = (2.4 + 2.0 * float(wprof[i])) * f
            dr.line([tuple(q) for q in seg], fill=ol + (255,), width=int(wdt), joint="curve")
            r = wdt / 2
            for q in (seg[0], seg[-1]):
                dr.ellipse([q[0] - r, q[1] - r, q[0] + r, q[1] + r], fill=ol + (255,))
    # biggest colour first, accents last
    for area, colour, path in sorted(layers, key=lambda l: -l[0]):
        pts = [tuple(q * f) for q in path]
        wdt = int(fill_w * f)
        dr.line(pts, fill=colour + (255,), width=wdt, joint="curve")
        r = wdt / 2
        for q in (pts[0], pts[-1]):
            dr.ellipse([q[0] - r, q[1] - r, q[0] + r, q[1] + r], fill=colour + (255,))
    return out.resize((CANVAS, CANVAS), Image.LANCZOS), [c for _, c, _ in layers]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("out")
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--colours", type=int)
    a = ap.parse_args()
    img, colours = render(Image.open(a.src), a.seed, a.colours)
    img.save(a.out)
    print(a.out, colours)


if __name__ == "__main__":
    main()
