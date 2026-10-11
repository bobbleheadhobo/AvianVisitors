// Battery level and days left for the battery frame (birdframe-battery.yaml).
// Plain C++ with no ESPHome headers, so tests/test_frame_headers.py can
// build and check it on its own.
#pragma once

#include <cmath>
#include <cstdint>

namespace birdframe {

// Charge from the resting voltage of a single LiPo cell, along a typical
// discharge curve. A straight line from 3.3 to 4.2 V was off by 20-30
// points mid-charge, where the curve is flat. Still an estimate (to about
// 10 points): the cell, its age and the temperature all shift the curve.
// NAN with no cell (a reading under 2.5 V: USB only).
inline float lipo_percent(float volts) {
  static const float curve[][2] = {
      {3.27f, 0},  {3.61f, 5},  {3.69f, 10}, {3.71f, 15}, {3.73f, 20}, {3.75f, 25}, {3.77f, 30},
      {3.79f, 35}, {3.80f, 40}, {3.82f, 45}, {3.84f, 50}, {3.85f, 55}, {3.87f, 60}, {3.91f, 65},
      {3.95f, 70}, {3.98f, 75}, {4.02f, 80}, {4.08f, 85}, {4.11f, 90}, {4.15f, 95}, {4.20f, 100},
  };
  const int n = sizeof(curve) / sizeof(curve[0]);
  if (std::isnan(volts) || volts <= 2.5f)
    return NAN;
  if (volts <= curve[0][0])
    return 0;
  for (int i = 1; i < n; i++) {
    if (volts <= curve[i][0]) {
      float f = (volts - curve[i - 1][0]) / (curve[i][0] - curve[i - 1][0]);
      return curve[i - 1][1] + f * (curve[i][1] - curve[i - 1][1]);
    }
  }
  return 100;
}

// Daily mean battery levels, kept in flash across deep sleep. One wake's
// reading jumps by a few points; the mean of a day's 40-odd wakes does not.
struct BatteryLog {
  static const int DAYS = 8;
  int32_t day[DAYS];   // days since 1970 (UTC), oldest first
  float level[DAYS];
  int32_t n;
  int32_t today;       // the day being averaged, and its running sum
  float sum;
  int32_t count;
};

// Add one wake's level. A finished day goes into the log; a day well above
// the one before means the battery was charged, so the trend starts over.
inline void battery_log_add(BatteryLog &log, int32_t day, float level) {
  if (std::isnan(level))
    return;
  if (log.count > 0 && day != log.today) {
    float mean = log.sum / log.count;
    if (log.n > 0 && mean > log.level[log.n - 1] + 3)
      log.n = 0;
    if (log.n == BatteryLog::DAYS) {
      for (int i = 1; i < BatteryLog::DAYS; i++) {
        log.day[i - 1] = log.day[i];
        log.level[i - 1] = log.level[i];
      }
      log.n--;
    }
    log.day[log.n] = log.today;
    log.level[log.n] = mean;
    log.n++;
    log.count = 0;
  }
  if (log.count == 0) {
    log.today = day;
    log.sum = 0;
  }
  log.sum += level;
  log.count++;
}

// Days until empty at the recent rate of fall: a least-squares line through
// the daily means. NAN until there are 3 days to go on, or while the level
// is not falling. Capped at 999.
inline float battery_days_left(const BatteryLog &log, float level_now) {
  if (log.n < 3 || std::isnan(level_now))
    return NAN;
  double mx = 0, my = 0;
  for (int i = 0; i < log.n; i++) {
    mx += log.day[i] - log.day[0];
    my += log.level[i];
  }
  mx /= log.n;
  my /= log.n;
  double sxy = 0, sxx = 0;
  for (int i = 0; i < log.n; i++) {
    double x = log.day[i] - log.day[0] - mx;
    sxy += x * (log.level[i] - my);
    sxx += x * x;
  }
  if (sxx <= 0)
    return NAN;
  double per_day = -sxy / sxx;  // points lost per day
  if (per_day <= 0)
    return NAN;
  double days = level_now / per_day;
  return days > 999 ? 999.0f : (float) days;
}

}  // namespace birdframe
