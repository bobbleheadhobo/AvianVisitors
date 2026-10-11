import io
import json
import pathlib
import sys
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "avian" / "scripts"))
import pregen  # noqa: E402


class FakeResponse(io.BytesIO):
    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class WikipediaReferenceTests(unittest.TestCase):
    def test_image_url_with_tracking_query_is_used(self):
        """Wikipedia appends ?utm_source=... to image URLs; judging the
        whole URL by its ending skipped every reference photo, so Gemini
        drew each new species without one."""
        jpeg = b"\xff\xd8\xff" + b"0" * 2000
        src = ("https://upload.wikimedia.org/wikipedia/commons/4/49/Magpie.jpg"
               "?utm_source=en.wikipedia.org&utm_campaign=api")

        def urlopen(req, timeout=None):
            url = req.full_url
            if "/page/summary/" in url:
                return FakeResponse(json.dumps({"originalimage": {"source": src}}).encode())
            self.assertEqual(url, src)
            return FakeResponse(jpeg)

        with mock.patch.object(pregen.urllib.request, "urlopen", urlopen):
            self.assertEqual(pregen.fetch_wikipedia_thumb("Pica hudsonia", "Black-billed Magpie"),
                             (jpeg, ".jpg"))


if __name__ == "__main__":
    unittest.main()
