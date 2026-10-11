import pathlib
import sys
import tempfile
import unittest

import numpy as np
from PIL import Image, ImageDraw

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "avian" / "scripts"))
import generate_one  # noqa: E402

PAPER = (249, 238, 208)
PALE = (247, 229, 192)   # a cream throat, ~19 from the paper (the woodpecker's)


def render(outline_gap):
    """A cream-ground 'bird': an inked oval with a pale patch on its left
    edge. With outline_gap the patch meets the ground with no ink line."""
    rng = np.random.default_rng(1)
    img = Image.new("RGB", (400, 400), PAPER)
    d = ImageDraw.Draw(img)
    d.ellipse((100, 100, 300, 300), fill=(120, 80, 50), outline=(20, 20, 20), width=4)
    d.ellipse((96, 150, 190, 250), fill=PALE)  # pale patch over the left edge
    if not outline_gap:
        d.arc((96, 150, 190, 250), 90, 270, fill=(20, 20, 20), width=4)
    arr = np.asarray(img).astype(np.int16) + rng.integers(-6, 7, (400, 400, 3))  # paper grain
    return Image.fromarray(np.clip(arr, 0, 255).astype(np.uint8))


def bird_area(img):
    with tempfile.TemporaryDirectory() as tmp:
        src, dst = pathlib.Path(tmp, "raw.png"), pathlib.Path(tmp, "cut.png")
        img.save(src)
        generate_one.chroma_cut(src, dst)
        return int((np.asarray(Image.open(dst))[:, :, 3] > 127).sum())


class ChromaCutTests(unittest.TestCase):
    def test_pale_patch_without_outline_is_kept(self):
        """The perched Ladder-backed Woodpecker lost its cream throat and
        chest: the adaptive tolerance let the flood run straight in."""
        outlined = bird_area(render(outline_gap=False))
        open_edge = bird_area(render(outline_gap=True))
        self.assertGreater(open_edge, 0.95 * outlined)


if __name__ == "__main__":
    unittest.main()
