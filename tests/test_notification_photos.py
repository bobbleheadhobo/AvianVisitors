import os
import tempfile
import unittest
from unittest.mock import patch, MagicMock

import requests

from scripts.utils import notifications

URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/5/55/Canyon_Wren.jpg/1280px-Canyon_Wren.jpg'


def reply(status, body=b'', ctype='image/jpeg', headers=None):
    r = MagicMock()
    r.status_code = status
    r.content = body
    r.headers = {'Content-Type': ctype, **(headers or {})}
    return r


class TestNotificationPhotos(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.old_cache = notifications.PHOTO_CACHE
        notifications.PHOTO_CACHE = os.path.join(self.tmp.name, 'photos')

    def tearDown(self):
        notifications.PHOTO_CACHE = self.old_cache
        self.tmp.cleanup()

    @patch('scripts.utils.notifications.requests.get')
    def test_downloads_once_and_reuses(self, get):
        get.return_value = reply(200, b'jpeg')
        path = notifications.local_photo(URL)
        self.assertTrue(path.startswith(notifications.PHOTO_CACHE) and path.endswith('.jpg'))
        with open(path, 'rb') as f:
            self.assertEqual(f.read(), b'jpeg')
        self.assertIn('AvianVisitors', get.call_args.kwargs['headers']['User-Agent'])
        self.assertEqual(notifications.local_photo(URL), path)
        self.assertEqual(get.call_count, 1)

    @patch('scripts.utils.notifications.time.sleep')
    @patch('scripts.utils.notifications.requests.get')
    def test_retries_after_429(self, get, sleep):
        # Wikimedia throttles a thumbnail it has not rendered yet (2026-10-09).
        get.side_effect = [reply(429, ctype='text/html', headers={'Retry-After': '10'}), reply(200, b'jpeg')]
        self.assertTrue(os.path.isfile(notifications.local_photo(URL)))
        sleep.assert_called_once_with(10)

    @patch('scripts.utils.notifications.time.sleep')
    @patch('scripts.utils.notifications.requests.get')
    def test_gives_up_without_photo(self, get, sleep):
        get.side_effect = [requests.Timeout('slow'), reply(429, ctype='text/html')]
        self.assertEqual(notifications.local_photo(URL), '')
        get.side_effect = None
        get.return_value = reply(404, ctype='text/html')
        self.assertEqual(notifications.local_photo(URL + '?x'), '')
        self.assertEqual(get.call_count, 3)   # no retry on a 404
        self.assertFalse(os.path.exists(notifications.PHOTO_CACHE))

    def test_local_path_passes_through(self):
        self.assertEqual(notifications.local_photo('/tmp/bird.jpg'), '/tmp/bird.jpg')


if __name__ == '__main__':
    unittest.main()
