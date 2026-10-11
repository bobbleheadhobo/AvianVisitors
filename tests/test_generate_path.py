import pathlib
import re
import shutil
import subprocess
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


@unittest.skipUnless(shutil.which("php"), "php CLI not installed")
class GeneratePathTests(unittest.TestCase):
    def test_finds_nohup_with_an_empty_environment(self):
        """PHP-FPM clears the environment, so PATH is empty in generate.php.
        Without a fallback nohup is never found and every Atlas generate
        fails as "generator unavailable" (the CLI test server keeps PATH,
        which is how tests/smoke_generate_api.sh missed it)."""
        src = (ROOT / "avian" / "api" / "generate.php").read_text()
        funcs = "".join(
            re.search(rf"^function {name}\(.*?^}}\n", src, re.S | re.M).group(0)
            for name in ("search_path", "find_executable"))
        out = subprocess.run(
            ["env", "-i", shutil.which("php"), "-r", funcs + 'echo find_executable("nohup") ?? "none";'],
            capture_output=True, text=True, check=True).stdout
        self.assertTrue(out.endswith("/nohup"), out)


if __name__ == "__main__":
    unittest.main()
