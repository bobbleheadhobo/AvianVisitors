"""The battery frame's plain C++ headers: the sun-based wake schedule
(frame/esphome/birdframe/schedule.h) and the battery level and days left
(birdframe/battery.h).

Builds the header with a host C++ compiler: g++/clang++ if present, else zig
(`pip install ziglang` into ~/.cache/avian-dev-venv). Skipped without one.
"""
import datetime as dt
import math
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
#include <cstring>
#include "schedule.h"
#include "battery.h"
int main(int argc, char **argv) {
  if (argv[1][0] == 'u') {
    printf("%d\n", birdframe::parse_seconds(argv[2]));
    return 0;
  }
  if (argv[1][0] == 'o') {
    printf("%d\n", birdframe::site_or(atoi(argv[2]), atoi(argv[3])));
    return 0;
  }
  if (argv[1][0] == 'p') {
    printf("%.3f\n", birdframe::lipo_percent(atof(argv[2])));
    return 0;
  }
  if (argv[1][0] == 'd') {
    // stdin: "day level" per wake; argv[2]: the level now.
    // The raw words the YAML global holds, all zero to start, as in flash.
    int32_t raw[birdframe::BATTERY_LOG_WORDS] = {0, 0};
    long day;
    float level;
    while (scanf("%ld %f", &day, &level) == 2)
      birdframe::battery_log_add(raw, (int32_t) day, level);
    birdframe::BatteryLog log;
    memcpy(&log, raw, sizeof(log));
    printf("%d %.3f\n", log.n, birdframe::battery_days_left(raw, atof(argv[2])));
    return 0;
  }
  double lat = atof(argv[3]), lon = atof(argv[4]);
  long long x = atoll(argv[2]);
  if (argv[1][0] == 'r') {
    birdframe::SunTimes t = birdframe::sun_times(x, lat, lon);
    printf("%d %lld %lld\n", t.ok, (long long) t.rise, (long long) t.set);
  } else {
    birdframe::Schedule s{atoi(argv[5]), atoi(argv[6]), atoi(argv[7]), atoi(argv[8]), atoi(argv[9]), atoi(argv[10])};
    birdframe::Phase phase;
    int64_t secs = birdframe::next_sleep_s(x, lat, lon, s, &phase);
    if (argv[1][0] == 't') {
      // Check rate's text; argv[11]: the clock is set, argv[12]: low battery
      // stretched the sleep to argv[13] seconds.
      if (argv[12][0] == '1') secs = atoi(argv[13]);
      char text[48];
      birdframe::describe_sleep(text, sizeof(text), secs, s, phase, argv[11][0] == '1', argv[12][0] == '1');
      printf("%s\n", text);
    } else {
      printf("%lld\n", (long long) secs);
    }
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
    units = {"s": 1, "min": 60, "h": 3600}

    def sub(name):
        value = re.search(rf"^\s+{name}: (\d+)(s|min|h)\s*$", text, re.M)
        assert value, f"substitution {name} missing from birdframe-battery.yaml"
        return int(value.group(1)) * units[value.group(2)]

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

    def rate(self, when, clock=True, low_secs=0):
        args = [str(self.exe), "t", str(int(when.timestamp())), str(LAT), str(LON)] + [str(v) for v in self.sched]
        args += ["1" if clock else "0", "1" if low_secs else "0", str(low_secs)]
        return subprocess.run(args, capture_output=True, text=True, check=True).stdout.strip()

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

    def test_time_periods_parse_like_esphome(self):
        for text, secs in [("600s", 600), ("10min", 600), ("2h", 7200), ("1h30min", 5400),
                           ("1500ms", 1), ("45", 45), ("0.5h", 1800)]:
            out = subprocess.run([str(self.exe), "u", text], capture_output=True, text=True, check=True)
            self.assertEqual(int(out.stdout), secs, text)

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

    def test_check_rate_text(self):
        # Home Assistant's Check rate through 11-12 October 2026.
        day = lambda h, m=0, d=11: dt.datetime(2026, 10, d, h, m, tzinfo=MDT)
        self.assertEqual(self.rate(day(6, 30)), "Every 10 min (dawn)")
        self.assertEqual(self.rate(day(13)), "Every 30 min (day)")
        self.assertEqual(self.rate(day(22, 40)), "Every 3 h (night)")
        # The last night sleep ends as the dawn window opens (06:10).
        self.assertEqual(self.rate(day(4, 0, d=12)), "Dawn checks in 2 h 10 min")
        self.assertEqual(self.rate(day(5, 40, d=12)), "Dawn checks in 30 min")
        self.assertEqual(self.rate(day(13), low_secs=7200), "Every 2 h (low battery)")
        self.assertEqual(self.rate(day(13), clock=False), "Every 30 min (no clock yet)")

    def test_site_intervals(self):
        # Settings > Frame's intervals from frame.json; 0 (none sent) keeps the YAML's.
        site_or = lambda site, own: int(subprocess.run([str(self.exe), "o", str(site), str(own)],
                                                       capture_output=True, text=True, check=True).stdout)
        self.assertEqual(site_or(0, 600), 600)
        self.assertEqual(site_or(900, 600), 900)
        self.assertEqual(site_or(10, 600), 60)
        # The site's defaults are the firmware's own.
        import json
        web = json.loads((ROOT / "frame" / "web_settings.json").read_text())["settings"]
        dawn, day, night = self.sched[3], self.sched[4], self.sched[5]
        self.assertEqual([web[k]["default"] * 60 for k in ("check_dawn", "check_day", "check_night")], [dawn, day, night])

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


class FrameBatteryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        FrameScheduleTests.setUpClass.__func__(cls)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def percent(self, volts):
        out = subprocess.run([str(self.exe), "p", str(volts)], capture_output=True, text=True, check=True)
        return float(out.stdout)

    def days_left(self, wakes, now):
        feed = "".join(f"{d} {lvl}\n" for d, lvl in wakes)
        out = subprocess.run([str(self.exe), "d", str(now)], input=feed, capture_output=True, text=True, check=True)
        n, days = out.stdout.split()
        return int(n), float(days)

    def test_lipo_curve(self):
        self.assertEqual(self.percent(4.25), 100)
        self.assertEqual(self.percent(4.20), 100)
        # A full charge on this frame reads 4.14 V (it showed 94% before).
        self.assertEqual(self.percent(4.14), 100)
        self.assertLess(self.percent(4.10), 95)
        self.assertAlmostEqual(self.percent(3.84), 50, places=1)
        self.assertAlmostEqual(self.percent(3.845), 52.5, places=1)
        self.assertEqual(self.percent(3.2), 0)
        # The flat middle: 3.7-3.9 V is most of the charge, not a fifth of it.
        self.assertGreater(self.percent(3.9) - self.percent(3.7), 45)
        self.assertNotEqual(self.percent(1.0), self.percent(1.0), "no cell (USB only) reads as unknown")

    @staticmethod
    def wakes(start_day, days, start_level, per_day, noise=0.0, per_wake=40):
        """A day's worth of noisy wakes for each of `days`, falling `per_day`."""
        out = []
        for d in range(days):
            for w in range(per_wake):
                wobble = noise * (1 if (d * per_wake + w) % 2 else -1)
                out.append((start_day + d, start_level - per_day * (d + w / per_wake) + wobble))
        return out

    def test_days_left_from_a_steady_fall(self):
        # 1 point a day from 80%: after a week, about 73 days left at 73%.
        n, days = self.days_left(self.wakes(20000, 8, 80, 1.0, noise=4), 73)
        self.assertEqual(n, 7)
        self.assertAlmostEqual(days, 73, delta=4)

    def test_days_left_is_unknown_at_first(self):
        n, days = self.days_left(self.wakes(20000, 3, 80, 1.0), 77)
        self.assertEqual(n, 2)
        self.assertTrue(math.isnan(days))

    def test_a_charge_starts_the_trend_over(self):
        wakes = self.wakes(20000, 5, 60, 1.0) + self.wakes(20005, 3, 100, 1.0)
        n, days = self.days_left(wakes, 98)
        # Days 5 and 6 are finished (7 is still being averaged); 0-4 are gone.
        self.assertEqual(n, 2, "the days before the charge are dropped")
        self.assertTrue(math.isnan(days), "and the estimate waits for 3 new days")

    def test_a_level_that_is_not_falling_has_no_estimate(self):
        _, days = self.days_left(self.wakes(20000, 6, 80, 0.0), 80)
        self.assertTrue(math.isnan(days))

    def test_battery_log_global_is_a_plain_array(self):
        # ESPHome declares globals before it includes our headers, so the
        # global cannot be a birdframe:: type (the Home Assistant build failed).
        # And "{}" or "{0}" also fit RestoringGlobalsComponent's scalar
        # constructor (ambiguous); "{0, 0}" fits only the array one.
        text = (ESPHOME / "birdframe-battery.yaml").read_text()
        block = text[text.index("- id: battery_log"):]
        block = block[:block.index("\n  - id:", 1)]
        self.assertIn("type: int32_t[20]", block)
        self.assertIn('initial_value: "{0, 0}"', block)
        header = (ESPHOME / "birdframe" / "battery.h").read_text()
        self.assertIn("BATTERY_LOG_WORDS = 20;", header)
        for path in (ESPHOME / "birdframe-battery.yaml", ESPHOME / "birdframe" / "common.yaml"):
            for line in path.read_text().splitlines():
                if line.strip().startswith("type:"):
                    self.assertNotIn("birdframe::", line, f"{path.name}: {line.strip()}")

    def test_log_keeps_the_last_eight_days(self):
        n, days = self.days_left(self.wakes(20000, 20, 90, 0.5), 80)
        self.assertEqual(n, 8)
        self.assertAlmostEqual(days, 160, delta=10)


if __name__ == "__main__":
    unittest.main()
