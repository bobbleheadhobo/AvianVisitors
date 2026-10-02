// Helpers for the AvianVisitors ESPHome frame (common.yaml).
#pragma once

#include "driver/rtc_io.h"
#include "esp_sleep.h"
#include "esphome/components/display/display.h"

namespace birdframe {

// EE04 user keys (active low).
static const gpio_num_t KEY_REFRESH = GPIO_NUM_2;  // KEY0
static const gpio_num_t KEY_NAMES = GPIO_NUM_3;    // KEY1
static const gpio_num_t KEY_AWAKE = GPIO_NUM_5;    // KEY2

enum WakeKey { WAKE_NONE = 0, WAKE_REFRESH = 1, WAKE_NAMES = 2, WAKE_AWAKE = 3 };

// Which key woke the board from deep sleep, if any.
inline int wake_key() {
  if (esp_sleep_get_wakeup_cause() != ESP_SLEEP_WAKEUP_EXT1)
    return WAKE_NONE;
  uint64_t mask = esp_sleep_get_ext1_wakeup_status();
  if (mask & (1ULL << KEY_REFRESH))
    return WAKE_REFRESH;
  if (mask & (1ULL << KEY_NAMES))
    return WAKE_NAMES;
  if (mask & (1ULL << KEY_AWAKE))
    return WAKE_AWAKE;
  return WAKE_NONE;
}

// ESPHome's ext1 wakeup does not set sleep pull-ups. Hold the keys high
// through deep sleep so they read as released until pressed.
inline void hold_key_pullups() {
  for (gpio_num_t pin : {KEY_REFRESH, KEY_NAMES, KEY_AWAKE}) {
    rtc_gpio_pullup_en(pin);
    rtc_gpio_pulldown_dis(pin);
  }
  esp_sleep_pd_config(ESP_PD_DOMAIN_RTC_PERIPH, ESP_PD_OPTION_ON);
}

// A small empty-battery mark in the top-right corner (portrait coordinates).
inline void draw_low_battery(esphome::display::Display &it) {
  const int w = 30, h = 14, x = it.get_width() - w - 10, y = 8;
  it.filled_rectangle(x - 3, y - 3, w + 9, h + 6, esphome::Color(255, 255, 255));
  it.rectangle(x, y, w, h, esphome::Color(0, 0, 0));
  it.rectangle(x + 1, y + 1, w - 2, h - 2, esphome::Color(0, 0, 0));
  it.filled_rectangle(x + w, y + 4, 3, h - 8, esphome::Color(0, 0, 0));
  it.filled_rectangle(x + 4, y + 4, 4, h - 8, esphome::Color(255, 0, 0));
}

}  // namespace birdframe
