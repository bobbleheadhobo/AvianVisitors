import hashlib
import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

from PIL import Image, ImageDraw

ROOT = pathlib.Path(__file__).resolve().parents[1]
FRAME = ROOT / "frame"
PURE = {(255, 255, 255), (0, 0, 0), (255, 0, 0), (255, 255, 0), (0, 0, 255), (0, 255, 0)}


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def collage_shot(size=(960, 1600)):
    """A stand-in screenshot: paper, a dark title band, a gap, a coloured collage."""
    w, h = size
    img = Image.new("RGB", size, (239, 236, 224))
    d = ImageDraw.Draw(img)
    d.rectangle((w * 0.2, h * 0.12, w * 0.8, h * 0.16), fill=(30, 30, 30))
    d.ellipse((w * 0.15, h * 0.3, w * 0.85, h * 0.8), fill=(180, 120, 60))
    d.rectangle((w * 0.4, h * 0.45, w * 0.6, h * 0.6), fill=(60, 90, 160))
    return img


class FrameGeometryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        sys.modules.setdefault("birdweather", load_module("birdweather", FRAME / "birdweather.py"))
        if sys.version_info < (3, 11):
            sys.modules.setdefault("tomli", types.SimpleNamespace(load=lambda _stream: {}))
        cls.display = load_module("frame_display_esphome_test", FRAME / "display.py")

    def seven_inch(self, **updates):
        cfg = dict(self.display.DEFAULTS)
        cfg.update(panel_size=[480, 800], opening=0.96, opening_ratio=1.6667,
                   collage_frac=0.95, gap_frac=0.04, **updates)
        return cfg

    def test_default_geometry_keeps_the_13in_a5_opening(self):
        geo = self.display.Geometry.from_cfg(dict(self.display.DEFAULTS))
        self.assertEqual(geo.size, (1200, 1600))
        w, h = geo.opening_size()
        self.assertAlmostEqual(h, 1600 * 0.7071, places=3)
        self.assertAlmostEqual(w, h / 1.41421, places=3)
        self.assertEqual(self.display.shoot_viewport(geo), {"vw": 600, "vh": 800, "dsf": 2})

    def test_opening_clamps_to_a_narrow_panel(self):
        w, h = self.display.opening_size(0.98, 800, 1.41421, 480)
        self.assertLessEqual(w, 480 * 0.98 + 1e-9)
        self.assertAlmostEqual(h / w, 1.41421, places=4)

    def test_geometry_rejects_bad_values(self):
        for kwargs in ({"panel_size": [0, 800]}, {"panel_size": "480x800"}, {"opening_ratio": 0},
                       {"opening_ratio": True}):
            with self.subTest(kwargs=kwargs), self.assertRaises(ValueError):
                self.display.Geometry(**kwargs)
        with self.assertRaises(ValueError):
            self.display.Geometry(opening=1.5).opening_size()

    def test_seven_inch_layout_fills_the_portrait_panel(self):
        geo = self.display.Geometry.from_cfg(self.seven_inch())
        self.assertEqual(self.display.shoot_viewport(geo), {"vw": 480, "vh": 800, "dsf": 2})
        out = self.display.mat_and_center(collage_shot(), geo)
        self.assertEqual(out.size, (480, 800))
        paper = out.getpixel((2, 2))
        ink = self.display.ImageChops.difference(out, Image.new("RGB", out.size, paper)).convert("L")
        bbox = ink.point(lambda p: 255 if p > 34 else 0).getbbox()
        self.assertIsNotNone(bbox)
        self.assertGreater(bbox[2] - bbox[0], 480 * 0.8)  # the collage uses the panel width

    def test_dither_keeps_grey_text_off_the_colour_inks(self):
        img = Image.new("RGB", (64, 64), (236, 234, 223))
        ImageDraw.Draw(img).rectangle((0, 0, 63, 31), fill=(110, 110, 110))
        out = self.display.dither_spectra6(img).convert("RGB")
        greys = {out.getpixel((x, y)) for x in range(64) for y in range(32)}
        self.assertLessEqual(greys, {self.display.SPECTRA6[0], self.display.SPECTRA6[1]})


class FrameEsphomeExportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        sys.modules.setdefault("birdweather", load_module("birdweather", FRAME / "birdweather.py"))
        if sys.version_info < (3, 11):
            sys.modules.setdefault("tomli", types.SimpleNamespace(load=lambda _stream: {}))
        cls.display = load_module("frame_display_export_test", FRAME / "display.py")

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.dir = pathlib.Path(self.tmp.name)

    def panel(self, colour=(180, 120, 60)):
        img = Image.new("RGB", (480, 800), (236, 234, 223))
        ImageDraw.Draw(img).ellipse((40, 200, 440, 700), fill=colour)
        return img

    def test_export_writes_pure_colour_pngs_and_hash_sigs(self):
        meta = self.display.export_esphome({"plain": self.panel(), "names": self.panel((60, 90, 160))},
                                           str(self.dir), species_sig="abc")
        for name in ("frame.png", "frame-names.png", "preview.png", "preview-names.png", "frame.json"):
            self.assertTrue((self.dir / name).is_file(), name)
        for name, key in (("frame.png", "sig"), ("frame-names.png", "sig_names")):
            data = (self.dir / name).read_bytes()
            self.assertEqual(meta[key], hashlib.sha256(data).hexdigest()[:16])
            img = Image.open(self.dir / name).convert("RGB")
            self.assertEqual(img.size, (480, 800))
            self.assertLessEqual({c for _, c in img.getcolors(16)}, PURE)
        on_disk = json.loads((self.dir / "frame.json").read_text())
        self.assertEqual(on_disk, meta)
        self.assertEqual((on_disk["width"], on_disk["height"], on_disk["species_sig"]), (480, 800, "abc"))
        self.assertFalse(list(self.dir.glob("*.tmp")))

    def test_missing_names_variant_keeps_the_last_one(self):
        first = self.display.export_esphome({"plain": self.panel(), "names": self.panel((60, 90, 160))}, str(self.dir))
        names_png = (self.dir / "frame-names.png").read_bytes()
        second = self.display.export_esphome({"plain": self.panel((58, 110, 72)), "names": None}, str(self.dir))
        self.assertNotEqual(second["sig"], first["sig"])
        self.assertEqual(second["sig_names"], first["sig_names"])
        self.assertEqual((self.dir / "frame-names.png").read_bytes(), names_png)

    def config(self, **updates):
        cfg = dict(self.display.DEFAULTS)
        cfg.update(output="esphome", export_dir=str(self.dir / "web"), state=str(self.dir / "state.json"),
                   cache=str(self.dir / "cache"), panel_size=[480, 800], opening=0.96, opening_ratio=1.6667,
                   collage_frac=0.95, gap_frac=0.04, shoot=True)
        cfg.update(updates)
        return cfg

    def test_run_exports_both_variants_and_saves_state(self):
        calls = []

        def fake_obtain(cfg, species=None, bird_names=None):
            calls.append(bird_names)
            return collage_shot()

        with mock.patch.object(self.display, "fetch_species", return_value=[{"sci": "Passer domesticus", "n": 3}]), \
                mock.patch.object(self.display, "obtain_image", side_effect=fake_obtain):
            self.display.run(self.config())
        self.assertEqual(calls, [False, True])
        meta = json.loads((self.dir / "web" / "frame.json").read_text())
        self.assertTrue(meta["sig"] and meta["sig_names"])
        state = json.loads((self.dir / "state.json").read_text())
        self.assertEqual(state["signature"], meta["species_sig"])

    def test_run_publishes_plain_frame_when_names_render_fails(self):
        def fake_obtain(cfg, species=None, bird_names=None):
            if bird_names:
                raise RuntimeError("frame labels missing for: ?")
            return collage_shot()

        with mock.patch.object(self.display, "fetch_species", return_value=[]), \
                mock.patch.object(self.display, "obtain_image", side_effect=fake_obtain):
            self.display.run(self.config())
        meta = json.loads((self.dir / "web" / "frame.json").read_text())
        self.assertIn("sig", meta)
        self.assertNotIn("sig_names", meta)

    def test_run_skips_when_birds_are_unchanged(self):
        species = [{"sci": "Passer domesticus", "n": 3}]
        self.display.save_state(str(self.dir / "state.json"), self.display.signature(species), 2_000_000_000)
        with mock.patch.object(self.display, "fetch_species", return_value=species), \
                mock.patch.object(self.display, "obtain_image") as obtain, \
                mock.patch.object(self.display.time, "time", return_value=2_000_000_060):
            self.display.run(self.config())
        obtain.assert_not_called()
        self.assertFalse((self.dir / "web").exists())


class FrameServerInstallerTests(unittest.TestCase):
    def test_installer_writes_esphome_config_and_units(self):
        with tempfile.TemporaryDirectory() as temp:
            root = pathlib.Path(temp)
            frame = root / "repo" / "frame"
            (frame / "systemd").mkdir(parents=True)
            for name in ("install-server.sh", "requirements-server.txt"):
                (frame / name).write_bytes((FRAME / name).read_bytes())
            for name in ("birdframe-server.service", "birdframe-server.timer"):
                (frame / "systemd" / name).write_bytes((FRAME / "systemd" / name).read_bytes())
            (frame / "display.py").write_text("")
            home = root / "home"
            home.mkdir()
            bin_dir = root / "bin"
            bin_dir.mkdir()
            log = root / "sudo.log"
            (bin_dir / "sudo").write_text(f'#!/bin/sh\necho "$*" >> "{log}"\ncat >/dev/null 2>&1 || true\n')
            # python3 -m venv .venv -> stub venv whose tools succeed.
            (bin_dir / "python3").write_text(
                "#!/bin/sh\nmkdir -p .venv/bin\n"
                "for t in pip playwright python; do printf '#!/bin/sh\\nexit 0\\n' > .venv/bin/$t; chmod +x .venv/bin/$t; done\n")
            for tool in ("sudo", "python3"):
                (bin_dir / tool).chmod(0o755)
            env = dict(os.environ, HOME=str(home), USER="birder", PATH=f"{bin_dir}:{os.environ['PATH']}")
            result = subprocess.run(["bash", str(frame / "install-server.sh")], cwd=frame, env=env,
                                    capture_output=True, text=True, timeout=60)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)
            config = (home / ".birdframe" / "config.toml").read_text()
            self.assertIn('output = "esphome"', config)
            self.assertIn("panel_size = [480, 800]", config)
            self.assertIn(f'export_dir = "{home}/BirdSongs/Extracted/frame"', config)
            self.assertTrue((home / "BirdSongs" / "Extracted" / "frame").is_dir())
            sudo_calls = log.read_text()
            self.assertIn("tee /etc/systemd/system/birdframe-server.service", sudo_calls)
            self.assertIn("systemctl enable --now birdframe-server.timer", sudo_calls)

            # A second run leaves an existing config alone.
            (home / ".birdframe" / "config.toml").write_text("kept = true\n")
            subprocess.run(["bash", str(frame / "install-server.sh")], cwd=frame, env=env,
                           capture_output=True, text=True, timeout=60, check=True)
            self.assertEqual((home / ".birdframe" / "config.toml").read_text(), "kept = true\n")

    def test_installer_rejects_unsafe_values(self):
        for args in (["--base-url", "ftp://x"], ["--base-url", 'http://x"y'], ["--export-dir", "/tmp/a b"]):
            with self.subTest(args=args):
                result = subprocess.run(["bash", str(FRAME / "install-server.sh"), *args],
                                        capture_output=True, text=True, timeout=30)
                self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
