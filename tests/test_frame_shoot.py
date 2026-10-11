import importlib.util
import pathlib
import re
import sys
import types
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


def load_shoot():
    # shoot.py imports playwright at the top; the test venv may not have it.
    api = types.ModuleType("playwright.sync_api")
    api.TimeoutError = Exception
    api.sync_playwright = None
    sys.modules.setdefault("playwright", types.ModuleType("playwright"))
    sys.modules.setdefault("playwright.sync_api", api)
    spec = importlib.util.spec_from_file_location("frame_shoot", ROOT / "frame" / "shoot.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class TunableRewriteTests(unittest.TestCase):
    def test_every_tunable_matches_apt_js_once(self):
        """A website change to one of these lines stopped every frame render
        for two days ("apt.js tunables not found"); catch it here instead."""
        js = (ROOT / "avian" / "frontend" / "apt.js").read_text()
        for pat, repl in load_shoot()._tunable_rewrites(1.0, 1.2, 0.65, 1, 13):
            with self.subTest(pattern=pat):
                self.assertEqual(len(re.findall(pat, js)), 1)
                self.assertIn(repl, re.sub(pat, repl, js))


if __name__ == "__main__":
    unittest.main()
