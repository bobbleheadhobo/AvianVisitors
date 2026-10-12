// Wake schedule for the battery frame, from the sun (birdframe-battery.yaml).
// Plain C++ with no ESPHome headers, so tests/test_frame_headers.py can
// build and check it on its own.
//
// Birds here are busiest around sunrise: in the first days of data 60% of
// each day's new species arrived between 6 and 9 am, a trickle followed
// until sunset, and nothing was heard at night. So the frame wakes often
// at dawn, every half hour by day, and seldom at night.
#pragma once

#include <cctype>
#include <cmath>
#include <cstdint>
#include <cstdio>
#include <cstdlib>
#include <cstring>

namespace birdframe {

// Seconds in a time period as ESPHome writes one in a substitution: "600s",
// "10min", "2h", "1h30min", "1500ms" (rounded down), or a bare number of
// seconds. The YAML lambdas get substitutions as text; reading "30min" with
// atoi gave 30 seconds.
inline int parse_seconds(const char *text) {
  double ms = 0;
  const char *t = text;
  while (*t) {
    while (*t == ' ')
      t++;
    if (!std::isdigit((unsigned char) *t) && *t != '.')
      break;
    char *end;
    double n = std::strtod(t, &end);
    t = end;
    if (std::strncmp(t, "ms", 2) == 0) {
      ms += n;
      t += 2;
    } else if (std::strncmp(t, "min", 3) == 0) {
      ms += n * 60000;
      t += 3;
    } else if (*t == 's') {
      ms += n * 1000;
      t += 1;
    } else if (*t == 'h') {
      ms += n * 3600000;
      t += 1;
    } else if (*t == 'd') {
      ms += n * 86400000;
      t += 1;
    } else {
      ms += n * 1000;
    }
  }
  return (int) (ms / 1000);
}

struct SunTimes {
  bool ok;       // false when the sun neither rises nor sets that day
  int64_t rise;  // UTC epoch seconds
  int64_t set;
};

// Sunrise and sunset (the upper limb at the horizon, with refraction) for
// day `n` counted from 2000-01-01, at latitude/longitude in degrees (east
// positive). The NOAA "sunrise equation"; good to about a minute.
inline SunTimes sun_times(int64_t n, double lat, double lon) {
  const double rad = 3.14159265358979323846 / 180.0;
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
// Which part of the schedule a sleep belongs to.
enum Phase { PHASE_NIGHT, PHASE_DAWN, PHASE_DAY };

inline int64_t next_sleep_s(int64_t now, double lat, double lon, const Schedule &s, Phase *phase = nullptr) {
  Phase unused;
  Phase &p = phase ? *phase : unused;
  p = PHASE_DAY;
  int64_t today = (int64_t) std::floor((double) now / 86400.0 + 2440587.5 - 2451545.0);
  for (int64_t n = today - 1; n <= today + 1; n++) {
    SunTimes t = sun_times(n, lat, lon);
    if (!t.ok)
      return s.day_every_s;
    int64_t dawn = t.rise - s.dawn_before_s;
    if (now < dawn - s.dawn_every_s) {
      p = PHASE_NIGHT;
      return dawn - now < s.night_max_s ? dawn - now : s.night_max_s;
    }
    p = PHASE_DAWN;
    if (now < dawn)
      return s.dawn_every_s;
    if (now < t.rise + s.dawn_after_s)
      return s.dawn_every_s;
    p = PHASE_DAY;
    if (now < t.set + s.dusk_after_s)
      return s.day_every_s;
  }
  return s.day_every_s;
}

// A sleep as people say it: "10 min", "3 h", "2 h 50 min".
inline void format_duration(char *out, size_t n, int64_t secs) {
  int64_t mins = (secs + 30) / 60;
  if (mins < 60)
    std::snprintf(out, n, "%d min", (int) mins);
  else if (mins % 60 == 0)
    std::snprintf(out, n, "%d h", (int) (mins / 60));
  else
    std::snprintf(out, n, "%d h %d min", (int) (mins / 60), (int) (mins % 60));
}

// Home Assistant's Check rate: how often the frame is checking now and why,
// e.g. "Every 10 min (dawn)", "Every 3 h (night)", or for the last, shorter
// night sleep "Dawn checks in 2 h 5 min". `low` means a low battery
// stretched this sleep; `clock` is false before SNTP has set the time.
inline void describe_sleep(char *out, size_t n, int64_t secs, const Schedule &s, Phase phase, bool clock,
                           bool low) {
  char d[24];
  format_duration(d, sizeof(d), secs);
  if (!clock)
    std::snprintf(out, n, "Every %s (no clock yet)", d);
  else if (low)
    std::snprintf(out, n, "Every %s (low battery)", d);
  else if (phase == PHASE_DAWN)
    std::snprintf(out, n, "Every %s (dawn)", d);
  else if (phase == PHASE_DAY)
    std::snprintf(out, n, "Every %s (day)", d);
  else if (secs < s.night_max_s)
    std::snprintf(out, n, "Dawn checks in %s", d);
  else
    std::snprintf(out, n, "Every %s (night)", d);
}

// An interval from Settings > Frame (seconds), or the YAML's own when the
// site sent none (0). Never under a minute.
inline int site_or(int32_t site_s, int own_s) {
  if (site_s <= 0)
    return own_s;
  return site_s < 60 ? 60 : (int) site_s;
}

}  // namespace birdframe
