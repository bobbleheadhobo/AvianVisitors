// Wake schedule for the battery frame, from the sun (birdframe-battery.yaml).
// Plain C++ with no ESPHome headers, so tests/test_frame_schedule.py can
// build and check it on its own.
//
// Birds here are busiest around sunrise: in the first days of data 60% of
// each day's new species arrived between 6 and 9 am, a trickle followed
// until sunset, and nothing was heard at night. So the frame wakes often
// at dawn, every half hour by day, and seldom at night.
#pragma once

#include <cmath>
#include <cstdint>

namespace birdframe {

struct SunTimes {
  bool ok;       // false when the sun neither rises nor sets that day
  int64_t rise;  // UTC epoch seconds
  int64_t set;
};

// Sunrise and sunset (the upper limb at the horizon, with refraction) for
// day `n` counted from 2000-01-01, at latitude/longitude in degrees (east
// positive). The NOAA "sunrise equation"; good to about a minute.
inline SunTimes sun_times(int64_t n, double lat, double lon) {
  const double rad = M_PI / 180.0;
  double j = (double) n - lon / 360.0;
  double m = std::fmod(357.5291 + 0.98560028 * j, 360.0);
  double c = 1.9148 * std::sin(m * rad) + 0.02 * std::sin(2 * m * rad) + 0.0003 * std::sin(3 * m * rad);
  double l = std::fmod(m + c + 180.0 + 102.9372, 360.0);
  double transit = 2451545.0 + j + 0.0053 * std::sin(m * rad) - 0.0069 * std::sin(2 * l * rad);
  double sin_d = std::sin(l * rad) * std::sin(23.4397 * rad);
  double cos_d = std::cos(std::asin(sin_d));
  double cos_w = (std::sin(-0.833 * rad) - std::sin(lat * rad) * sin_d) / (std::cos(lat * rad) * cos_d);
  if (cos_w < -1.0 || cos_w > 1.0)
    return {false, 0, 0};
  double w = std::acos(cos_w) / rad / 360.0;
  auto epoch = [](double jd) { return (int64_t) std::llround((jd - 2440587.5) * 86400.0); };
  return {true, epoch(transit - w), epoch(transit + w)};
}

struct Schedule {
  int dawn_before_s;   // dawn window opens this long before sunrise
  int dawn_after_s;    // ...and closes this long after it
  int dusk_after_s;    // the day window closes this long after sunset
  int dawn_every_s;    // wake interval in the dawn window
  int day_every_s;     // wake interval by day
  int night_max_s;     // longest night sleep (the sleep clock drifts)
};

// Seconds to sleep from `now` (UTC epoch). Through the night the frame
// sleeps up to night_max_s at a time and lands exactly on the start of the
// next dawn window. Returns day_every_s if the sun gives no answer.
inline int64_t next_sleep_s(int64_t now, double lat, double lon, const Schedule &s) {
  int64_t today = (int64_t) std::floor((double) now / 86400.0 + 2440587.5 - 2451545.0);
  for (int64_t n = today - 1; n <= today + 1; n++) {
    SunTimes t = sun_times(n, lat, lon);
    if (!t.ok)
      return s.day_every_s;
    int64_t dawn = t.rise - s.dawn_before_s;
    // A night sleep that ran a little short counts as dawn already.
    if (now < dawn - 120)
      return dawn - now < s.night_max_s ? dawn - now : s.night_max_s;
    if (now < dawn)
      return s.dawn_every_s;
    if (now < t.rise + s.dawn_after_s)
      return s.dawn_every_s;
    if (now < t.set + s.dusk_after_s)
      return s.day_every_s;
  }
  return s.day_every_s;
}

}  // namespace birdframe
