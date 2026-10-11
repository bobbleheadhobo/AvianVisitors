"""The battery frame's sun-based wake schedule (frame/esphome/birdframe/schedule.h).

Builds the header with a host C++ compiler: g++/clang++ if present, else zig
(`pip install ziglang` into ~/.cache/avian-dev-venv). Skipped without one.
"""
import datetime as dt
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
ESPHOME = ROOT / "frame" / "esphome"
LAT, LON = 35.0813, -106.6212  # merlin, Albuquerque
MDT = dt.timezone(dt.timedelta(hours=-6))
MST = dt.timezone(dt.timedelta(hours=-7))

DRIVER = r"""
#include <cstdio>
#include <cstdlib>
#include "schedule.h"
int main(int argc, char **argv) {
  double lat = atof(argv[3]), lon = atof(argv[4]);
  long long x = atoll(argv[2]);
  if (argv[1][0] == 'r') {
    birdframe::SunTimes t = birdframe::sun_times(x, lat, lon);
    printf("%d %lld %lld\n", t.ok, (long long) t.rise, (long long) t.set);
  } else {
    birdframe::Schedule s{atoi(argv[5]), atoi(argv[6]), atoi(argv[7]), atoi(argv[8]), atoi(argv[9]), atoi(argv[10])};
    printf("%lld\n", (long long) birdframe::next_sleep_s(x, lat, lon, s));
  }
}
"""


def compiler():
    for name in ("g++", "clang++"):
        if shutil.which(name):
            return [name]
    if shutil.which("zig"):
        return ["zig", "c++"]
    for py in (pathlib.Path.home() / ".cache/avian-dev-venv/bin/python", pathlib.Path(sys.executable)):
        if py.exists() and subprocess.run([str(py), "-c", "import ziglang"], capture_output=True).returncode == 0:
            return [str(py), "-m", "ziglang", "c++"]
    return None


def battery_schedule():
    """The Schedule fields as birdframe-battery.yaml sets them, in seconds."""
    text = (ESPHOME / "birdframe-battery.yaml").read_text()

    def sub(name):
        value = re.search(rf"^\s+{name}: (\d+)s\s*$", text, re.M)
        assert value, f"substitution {name} missing from birdframe-battery.yaml, or not in seconds"
        return int(value.group(1))

    return [sub(n) for n in ("dawn_before", "dawn_after", "dusk_after", "sleep_dawn", "sleep_day", "sleep_night_max")]


class FrameScheduleTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cxx = compiler()
        if not cxx:
            raise unittest.SkipTest("no host C++ compiler (pip install ziglang into the dev venv)")
        cls.tmp = tempfile.TemporaryDirectory()
        src = pathlib.Path(cls.tmp.name) / "driver.cpp"
        src.write_text(DRIVER)
        cls.exe = pathlib.Path(cls.tmp.name) / "driver"
        env = dict(os.environ, ZIG_GLOBAL_CACHE_DIR=str(pathlib.Path(cls.tmp.name) / "zig-cache"),
                   ZIG_LOCAL_CACHE_DIR=str(pathlib.Path(cls.tmp.name) / "zig-local"))
        subprocess.run(cxx + ["-std=c++17", "-O1", "-I", str(ESPHOME / "birdframe"), str(src), "-o", str(cls.exe)],
                       check=True, env=env, capture_output=True)
        cls.sched = battery_schedule()

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def sun(self, day, lat=LAT, lon=LON):
        n = (day - dt.date(2000, 1, 1)).days
        out = subprocess.run([str(self.exe), "r", str(n), str(lat), str(lon)], capture_output=True, text=True, check=True)
        ok, rise, sset = map(int, out.stdout.split())
        return bool(ok), rise, sset

    def sleep(self, when):
        out = subprocess.run([str(self.exe), "s", str(int(when.timestamp())), str(LAT), str(LON)] + [str(v) for v in self.sched],
                             capture_output=True, text=True, check=True)
        return int(out.stdout)

    def assertNear(self, epoch, expected, minutes=4):
        got = dt.datetime.fromtimestamp(epoch, expected.tzinfo)
        self.assertLessEqual(abs((got - expected).total_seconds()), minutes * 60, f"{got} vs {expected}")

    def test_sunrise_and_sunset_match_published_times(self):
        # Albuquerque, from published almanac tables.
        for day, rise, sset, tz in [
            (dt.date(2026, 10, 11), (7, 12), (18, 35), MDT),
            (dt.date(2026, 6, 21), (5, 52), (20, 27), MDT),
            (dt.date(2026, 12, 21), (7, 9), (16, 58), MST),
        ]:
            ok, r, s = self.sun(day)
            self.assertTrue(ok)
            self.assertNear(r, dt.datetime(day.year, day.month, day.day, *rise, tzinfo=tz))
            self.assertNear(s, dt.datetime(day.year, day.month, day.day, *sset, tzinfo=tz))

    def test_polar_day_has_no_sunrise(self):
        ok, _, _ = self.sun(dt.date(2026, 6, 21), lat=78.2, lon=15.6)
        self.assertFalse(ok)

    def test_wake_intervals_through_a_day(self):
        before, after, dusk, dawn_every, day_every, night_max = self.sched
        _, rise, sset = self.sun(dt.date(2026, 10, 11))
        at = lambda epoch: dt.datetime.fromtimestamp(epoch, MDT)
        # Dawn window: from an hour before sunrise to two hours after.
        self.assertEqual(self.sleep(at(rise - before + 60)), dawn_every)
        self.assertEqual(self.sleep(at(rise + after - 60)), dawn_every)
        # Day: every half hour until half an hour after sunset.
        self.assertEqual(self.sleep(at(rise + after + 60)), day_every)
        self.assertEqual(self.sleep(at(sset + dusk - 60)), day_every)
        # Evening: the longest night sleep.
        self.assertEqual(self.sleep(at(sset + dusk + 60)), night_max)
        # Late night: wakes exactly when the next dawn window opens.
        _, rise2, _ = self.sun(dt.date(2026, 10, 12))
        three_am = dt.datetime(2026, 10, 12, 3, 30, tzinfo=MDT)
        self.assertEqual(self.sleep(three_am), (rise2 - before) - int(three_am.timestamp()))
        # A night sleep that ends a minute early counts as dawn.
        self.assertEqual(self.sleep(at(rise2 - before - 60)), dawn_every)

    def test_wakes_per_day(self):
        t = int(dt.datetime(2026, 10, 11, 4, 0, tzinfo=MDT).timestamp())
        end = t + 86400
        wakes = 0
        while t < end:
            t += self.sleep(dt.datetime.fromtimestamp(t, MDT))
            wakes += 1
        # About 18 at dawn, 20 by day and a few at night; 48 before.
        self.assertGreater(wakes, 35)
        self.assertLess(wakes, 48)


if __name__ == "__main__":
    unittest.main()
